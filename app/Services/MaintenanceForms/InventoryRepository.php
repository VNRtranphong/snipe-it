<?php

namespace App\Services\MaintenanceForms;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use Illuminate\Support\Facades\DB;

/**
 * "Tra cứu thiết bị" on the Báo cáo sự cố page: every asset of a model
 * or category, where it is installed (site / hall) and its serial.
 */
class InventoryRepository
{
    public const MAX_ROWS = 3000;

    /** Serials that mean "no serial" and must not be reported as duplicates. */
    private const PLACEHOLDER_SERIALS = ['', '-', '--', '0', 'N/A', 'NA', 'NONE', 'NULL', 'KHONG', 'KHÔNG', 'KHONG CO', 'KHÔNG CÓ', 'TBD', 'X'];

    /** Label used for assets with no site (in stock, not assigned …). */
    public const NO_SITE = '(Chưa gán vị trí)';

    /**
     * @return array{title: string, total: int, truncated: bool, by_site: list<array<string, mixed>>, rows: list<array<string, mixed>>, duplicates: list<array<string, mixed>>}
     */
    public function lookup(?int $categoryId, ?int $modelId): array
    {
        $query = Asset::query()
            ->with(['model.category', 'location.parent', 'defaultLoc', 'assignedTo', 'status', 'company'])
            ->withCount(['maintenances' => fn ($q) => $q->reorder()]);

        if ($modelId) {
            $query->where('model_id', $modelId);
            $model = AssetModel::find($modelId);
            $title = 'Model: '.($model ? trim($model->name.($model->model_number ? ' ('.$model->model_number.')' : '')) : '#'.$modelId);
        } elseif ($categoryId) {
            $query->whereHas('model', fn ($q) => $q->where('category_id', $categoryId));
            $title = 'Category: '.(Category::find($categoryId)?->name ?? '#'.$categoryId);
        } else {
            return ['title' => '', 'total' => 0, 'truncated' => false, 'by_site' => [], 'rows' => [], 'duplicates' => []];
        }

        $assets = $query->limit(self::MAX_ROWS + 1)->get();
        $truncated = $assets->count() > self::MAX_ROWS;

        $rows = [];
        foreach ($assets->take(self::MAX_ROWS) as $asset) {
            [$site, $hall] = MaintenanceFormExporter::siteAndHall($asset);
            $rows[] = [
                'id' => $asset->id,
                'site' => $site,
                'hall' => $hall,
                'name' => (string) $asset->name,
                'asset_tag' => (string) $asset->asset_tag,
                'serial' => (string) $asset->serial,
                'model' => (string) ($asset->model?->name ?? ''),
                'model_number' => (string) ($asset->model?->model_number ?? ''),
                'category' => (string) ($asset->model?->category?->name ?? ''),
                'status' => (string) ($asset->status?->name ?? ''),
                'incidents' => (int) ($asset->maintenances_count ?? 0),
            ];
        }

        $rows = self::sortRows($rows);
        [$rows, $duplicates] = self::markDuplicates($rows, $this->assetsSharingSerials($rows));

        return [
            'title' => $title,
            'total' => count($rows),
            'truncated' => $truncated,
            'by_site' => self::bySite($rows),
            'rows' => $rows,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * Every asset in the whole system (any model / category) whose serial
     * equals one of the serials in $rows, compared trimmed + upper-case.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: int, name: string, asset_tag: string, serial: string, model: string, site: string, hall: string}>
     */
    private function assetsSharingSerials(array $rows): array
    {
        $keys = array_values(array_unique(array_filter(array_map(
            fn ($r) => self::serialKey((string) $r['serial']),
            $rows
        ))));
        if (! $keys) {
            return [];
        }

        $found = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            $assets = Asset::query()
                ->with(['model', 'location.parent', 'defaultLoc', 'assignedTo'])
                ->whereIn(DB::raw('UPPER(TRIM(serial))'), $chunk)
                ->get(['id', 'name', 'asset_tag', 'serial', 'model_id', 'location_id', 'rtd_location_id', 'assigned_to', 'assigned_type']);

            foreach ($assets as $a) {
                [$site, $hall] = MaintenanceFormExporter::siteAndHall($a);
                $found[] = [
                    'id' => $a->id,
                    'name' => (string) $a->name,
                    'asset_tag' => (string) $a->asset_tag,
                    'serial' => (string) $a->serial,
                    'model' => (string) ($a->model?->name ?? ''),
                    'site' => $site,
                    'hall' => $hall,
                ];
            }
        }

        return $found;
    }

    /**
     * Flag rows whose serial is used by more than one asset.
     *
     * Adds to every row: 'dup' => list of the OTHER assets with that serial.
     * Returns [rows, duplicates] where duplicates is one entry per serial:
     * ['serial' => 'ABC', 'assets' => [...all assets sharing it...]].
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $matches  assets found system-wide (may include the rows themselves)
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    public static function markDuplicates(array $rows, array $matches): array
    {
        // Group everything we know (rows + system-wide matches) by serial key, de-duplicated by asset id.
        $groups = [];
        foreach (array_merge($rows, $matches) as $a) {
            $key = self::serialKey((string) $a['serial']);
            if ($key === '') {
                continue;
            }
            $groups[$key][$a['id']] = [
                'id' => $a['id'],
                'name' => $a['name'],
                'asset_tag' => $a['asset_tag'],
                'serial' => $a['serial'],
                'model' => $a['model'] ?? '',
                'site' => $a['site'] ?? '',
                'hall' => $a['hall'] ?? '',
            ];
        }

        $duplicates = [];
        foreach ($rows as &$row) {
            $key = self::serialKey((string) $row['serial']);
            $row['dup'] = [];
            if ($key !== '' && count($groups[$key] ?? []) > 1) {
                $row['dup'] = array_values(array_filter($groups[$key], fn ($a) => $a['id'] !== $row['id']));
                $duplicates[$key] ??= ['serial' => $row['serial'], 'assets' => array_values($groups[$key])];
            }
        }
        unset($row);

        ksort($duplicates, SORT_NATURAL);

        return [$rows, array_values($duplicates)];
    }

    /** Normalised serial used for comparison; '' for empty / placeholder values. */
    public static function serialKey(string $serial): string
    {
        $key = mb_strtoupper(trim($serial));

        return in_array($key, self::PLACEHOLDER_SERIALS, true) ? '' : $key;
    }

    /**
     * Site first, then hall, then name – natural order ("Hall 2" < "Hall 10").
     * Assets without a site go last.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function sortRows(array $rows): array
    {
        usort($rows, function ($a, $b) {
            if (($a['site'] === '') !== ($b['site'] === '')) {
                return $a['site'] === '' ? 1 : -1;
            }

            return strnatcasecmp($a['site'], $b['site'])
                ?: strnatcasecmp($a['hall'], $b['hall'])
                ?: strnatcasecmp($a['name'], $b['name']);
        });

        return $rows;
    }

    /**
     * Count per site plus the halls it is installed in.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{site: string, count: int, halls: list<string>}>
     */
    public static function bySite(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $site = $r['site'] !== '' ? $r['site'] : self::NO_SITE;
            $out[$site] ??= ['site' => $site, 'count' => 0, 'halls' => []];
            $out[$site]['count']++;
            if ($r['hall'] !== '' && ! in_array($r['hall'], $out[$site]['halls'], true)) {
                $out[$site]['halls'][] = $r['hall'];
            }
        }
        foreach ($out as &$s) {
            natsort($s['halls']);
            $s['halls'] = array_values($s['halls']);
        }
        unset($s);

        // Keep the order of sortRows (already site-sorted, no-site last).
        return array_values($out);
    }

    /**
     * Excel file for one lookup.
     *
     * @param  array{title: string, total: int, by_site: list<array<string, mixed>>, rows: list<array<string, mixed>>}  $result
     */
    public static function export(array $result, string $path): string
    {
        $rows = [];
        $styles = [];
        foreach ($result['rows'] as $i => $r) {
            $rows[] = [
                $i + 1,
                $r['site'] !== '' ? $r['site'] : self::NO_SITE,
                $r['hall'] !== '' ? 'Hall '.$r['hall'] : '',
                $r['name'],
                trim($r['model'].($r['model_number'] && ! str_contains($r['model'], $r['model_number']) ? ' ('.$r['model_number'].')' : '')),
                $r['category'],
                $r['serial'],
                $r['asset_tag'],
                $r['status'],
                $r['incidents'],
                $r['dup'] ? 'Trùng với: '.implode('; ', array_map(fn ($a) => self::assetLabel($a), $r['dup'])) : '',
            ];
            if ($r['dup']) {
                $styles[count($rows) - 1] = 'red';
            }
        }

        $siteRows = [];
        foreach ($result['by_site'] as $s) {
            $siteRows[] = [
                $s['site'],
                $s['count'],
                implode(', ', array_map(fn ($h) => 'Hall '.$h, $s['halls'])),
            ];
        }

        $w = new SimpleXlsxWriter;
        $w->addSheet('Danh sách thiết bị',
            ['STT', 'Rạp', 'Phòng', 'Tên thiết bị', 'Model', 'Category', 'Serial', 'Mã TS', 'Trạng thái', 'Số lần sự cố', 'Serial trùng'],
            $rows,
            [
                'title' => 'DANH SÁCH THIẾT BỊ – '.mb_strtoupper($result['title']),
                'subtitle' => 'Tổng: '.$result['total'].' thiết bị'
                    .(! empty($result['duplicates']) ? '  |  ⚠ '.count($result['duplicates']).' serial bị trùng' : '')
                    .'  |  Xuất lúc '.date('d/m/Y H:i'),
                'widths' => [5, 22, 9, 32, 22, 16, 20, 13, 16, 11, 40],
                'rowStyles' => $styles,
                'totalRow' => $rows ? ['Tổng', null, null, $result['total'].' thiết bị', null, null, null, null, null, array_sum(array_column($result['rows'], 'incidents')), null] : null,
            ]
        );
        $w->addSheet('Theo rạp', ['Rạp', 'Số lượng', 'Phòng'], $siteRows, [
            'title' => 'SỐ LƯỢNG THEO RẠP – '.mb_strtoupper($result['title']),
            'widths' => [28, 10, 50],
            'totalRow' => $siteRows ? ['Tổng', $result['total'], null] : null,
        ]);

        if (! empty($result['duplicates'])) {
            $dupRows = [];
            foreach ($result['duplicates'] as $d) {
                foreach ($d['assets'] as $a) {
                    $dupRows[] = [
                        $d['serial'],
                        $a['asset_tag'],
                        $a['name'],
                        $a['model'],
                        $a['site'] !== '' ? $a['site'] : self::NO_SITE,
                        $a['hall'] !== '' ? 'Hall '.$a['hall'] : '',
                    ];
                }
            }
            $w->addSheet('Serial trùng', ['Serial', 'Mã TS', 'Tên thiết bị', 'Model', 'Rạp', 'Phòng'], $dupRows, [
                'title' => 'SERIAL BỊ TRÙNG – '.mb_strtoupper($result['title']),
                'subtitle' => 'So với toàn bộ thiết bị trong Snipe-IT',
                'widths' => [22, 13, 32, 22, 24, 10],
            ]);
        }

        return $w->save($path);
    }

    /** @param array<string, mixed> $a */
    private static function assetLabel(array $a): string
    {
        $where = trim(($a['site'] ?? '').(($a['hall'] ?? '') !== '' ? ' Hall '.$a['hall'] : ''));

        return trim($a['asset_tag'].' '.$a['name'].($where !== '' ? ' ('.$where.')' : ''));
    }
}

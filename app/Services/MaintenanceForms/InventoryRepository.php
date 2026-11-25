<?php

namespace App\Services\MaintenanceForms;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;

/**
 * "Tra cứu thiết bị" on the Báo cáo sự cố page: every asset of a model
 * or category, where it is installed (site / hall) and its serial.
 */
class InventoryRepository
{
    public const MAX_ROWS = 3000;

    /** Label used for assets with no site (in stock, not assigned …). */
    public const NO_SITE = '(Chưa gán vị trí)';

    /**
     * @return array{title: string, total: int, truncated: bool, by_site: list<array<string, mixed>>, rows: list<array<string, mixed>>}
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
            return ['title' => '', 'total' => 0, 'truncated' => false, 'by_site' => [], 'rows' => []];
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

        return [
            'title' => $title,
            'total' => count($rows),
            'truncated' => $truncated,
            'by_site' => self::bySite($rows),
            'rows' => $rows,
        ];
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
            ];
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
            ['STT', 'Rạp', 'Phòng', 'Tên thiết bị', 'Model', 'Category', 'Serial', 'Mã TS', 'Trạng thái', 'Số lần sự cố'],
            $rows,
            [
                'title' => 'DANH SÁCH THIẾT BỊ – '.mb_strtoupper($result['title']),
                'subtitle' => 'Tổng: '.$result['total'].' thiết bị  |  Xuất lúc '.date('d/m/Y H:i'),
                'widths' => [5, 22, 9, 32, 22, 16, 20, 13, 16, 11],
                'totalRow' => $rows ? ['Tổng', null, null, $result['total'].' thiết bị', null, null, null, null, null, array_sum(array_column($result['rows'], 'incidents'))] : null,
            ]
        );
        $w->addSheet('Theo rạp', ['Rạp', 'Số lượng', 'Phòng'], $siteRows, [
            'title' => 'SỐ LƯỢNG THEO RẠP – '.mb_strtoupper($result['title']),
            'widths' => [28, 10, 50],
            'totalRow' => $siteRows ? ['Tổng', $result['total'], null] : null,
        ]);

        return $w->save($path);
    }
}

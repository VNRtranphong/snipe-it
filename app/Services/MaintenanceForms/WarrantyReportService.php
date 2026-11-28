<?php

namespace App\Services\MaintenanceForms;

use App\Models\Asset;
use App\Models\Location;
use App\Models\Maintenance;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * "Bảo hành thiết bị": warranty status of every asset plus its repair history.
 *
 *  - Warranty end = purchase_date + warranty_months (same rule as Snipe-IT's
 *    own `warranty_expires`). No date or no months => "missing".
 *  - States: active (> SOON_DAYS left), soon (0..SOON_DAYS), expired, missing.
 *  - Repairs = maintenances of the asset. Planned maintenance (bảo trì / bảo
 *    dưỡng, same rule as the incident pages) is listed but not counted as a
 *    repair. A repair done while the asset was still under warranty but not
 *    ticked "Warranty Improvement" is flagged so it can be claimed / checked.
 *  - Assets with a "hư / thanh lý" status are left out unless asked for.
 */
class WarrantyReportService
{
    public const SOON_DAYS = 90;

    public const STATE_ACTIVE = 'active';

    public const STATE_SOON = 'soon';

    public const STATE_EXPIRED = 'expired';

    public const STATE_MISSING = 'missing';

    /** Filter value meaning "active + soon" (default view). */
    public const STATE_VALID = 'valid';

    public const STATE_LABELS = [
        self::STATE_ACTIVE => 'Còn bảo hành',
        self::STATE_SOON => 'Sắp hết bảo hành',
        self::STATE_EXPIRED => 'Hết bảo hành',
        self::STATE_MISSING => 'Chưa nhập bảo hành',
    ];

    /**
     * @param  array<string, mixed>  $query
     * @return array{state: string, site: string, category: string, q: string, broken: bool}
     */
    public static function filters(array $query): array
    {
        $state = (string) ($query['state'] ?? self::STATE_VALID);
        $allowed = [self::STATE_VALID, self::STATE_ACTIVE, self::STATE_SOON, self::STATE_EXPIRED, self::STATE_MISSING, 'all'];

        return [
            'state' => in_array($state, $allowed, true) ? $state : self::STATE_VALID,
            'site' => trim((string) ($query['site'] ?? '')),
            'category' => trim((string) ($query['category'] ?? '')),
            'q' => trim((string) ($query['q'] ?? '')),
            'broken' => ! empty($query['broken']),
        ];
    }

    /**
     * Every asset with its warranty state (no repairs yet: those are loaded
     * only for the rows that end up on screen).
     *
     * @return list<array<string, mixed>>
     */
    public function assets(?Carbon $today = null): array
    {
        $today ??= Carbon::now();
        $rows = [];

        Asset::query()
            ->with([
                'location.parent',
                'defaultLoc',
                'status:id,name',
                'model:id,name,model_number,category_id',
                'model.category:id,name',
                'supplier:id,name',
                'assignedTo' => fn ($morph) => $morph->morphWith([Location::class => ['parent']]),
            ])
            ->select(['id', 'name', 'asset_tag', 'serial', 'status_id', 'model_id', 'location_id', 'rtd_location_id',
                'assigned_to', 'assigned_type', 'company_id', 'supplier_id', 'purchase_date', 'warranty_months'])
            ->orderBy('id')
            ->chunk(1000, function ($assets) use (&$rows, $today) {
                foreach ($assets as $asset) {
                    [$site, $hall] = MaintenanceFormExporter::siteAndHall($asset);
                    $rows[] = self::decorate([
                        'id' => (int) $asset->id,
                        'name' => (string) $asset->name,
                        'asset_tag' => (string) $asset->asset_tag,
                        'serial' => (string) $asset->serial,
                        'model' => (string) ($asset->model?->name ?? ''),
                        'model_number' => (string) ($asset->model?->model_number ?? ''),
                        'category' => (string) ($asset->model?->category?->name ?? ''),
                        'status' => (string) ($asset->status?->name ?? ''),
                        'supplier' => (string) ($asset->supplier?->name ?? ''),
                        'site' => $site,
                        'hall' => $hall,
                        'purchase_date' => $asset->purchase_date ? Carbon::parse($asset->purchase_date) : null,
                        'warranty_months' => $asset->warranty_months !== null && $asset->warranty_months !== '' ? (int) $asset->warranty_months : null,
                    ], $today);
                }
            });

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    public static function decorate(array $r, Carbon $today): array
    {
        $end = null;
        if ($r['purchase_date'] instanceof Carbon && ! empty($r['warranty_months'])) {
            $end = $r['purchase_date']->copy()->addMonthsNoOverflow((int) $r['warranty_months']);
        }

        $days = $end ? (int) floor(($end->copy()->startOfDay()->getTimestamp() - $today->copy()->startOfDay()->getTimestamp()) / 86400) : null;

        $r['expires'] = $end;
        $r['days_left'] = $days;
        $r['state'] = match (true) {
            $days === null => self::STATE_MISSING,
            $days < 0 => self::STATE_EXPIRED,
            $days <= self::SOON_DAYS => self::STATE_SOON,
            default => self::STATE_ACTIVE,
        };
        $r['broken'] = InventoryRepository::isBrokenStatus($r['status']);
        $r['hall_label'] = $r['hall'] !== '' ? 'Hall '.$r['hall'] : '';
        $r['left_label'] = self::leftLabel($days);

        return $r;
    }

    public static function leftLabel(?int $days): string
    {
        if ($days === null) {
            return '';
        }
        if ($days < 0) {
            return 'Hết '.self::span(-$days).' trước';
        }
        if ($days === 0) {
            return 'Hết hôm nay';
        }

        return 'Còn '.self::span($days);
    }

    /** 400 => "1 năm 1 tháng", 45 => "1 tháng 15 ngày", 10 => "10 ngày". */
    private static function span(int $days): string
    {
        $years = intdiv($days, 365);
        $months = intdiv($days % 365, 30);
        $rest = ($days % 365) % 30;

        if ($years > 0) {
            return $years.' năm'.($months ? ' '.$months.' tháng' : '');
        }
        if ($months > 0) {
            return $months.' tháng'.($rest ? ' '.$rest.' ngày' : '');
        }

        return $days.' ngày';
    }

    /**
     * Counts for the cards: every state, ignoring the state filter itself.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public static function counts(array $rows): array
    {
        $out = array_fill_keys(array_keys(self::STATE_LABELS), 0);
        foreach ($rows as $r) {
            $out[$r['state']]++;
        }
        $out[self::STATE_VALID] = $out[self::STATE_ACTIVE] + $out[self::STATE_SOON];

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{state: string, site: string, category: string, q: string, broken: bool}  $f
     * @param  bool  $withState  false: skip the state filter (for the cards)
     * @return list<array<string, mixed>>
     */
    public static function applyFilters(array $rows, array $f, bool $withState = true): array
    {
        $q = $f['q'] !== '' ? self::fold($f['q']) : '';

        $out = array_values(array_filter($rows, function (array $r) use ($f, $q, $withState) {
            if (! $f['broken'] && $r['broken']) {
                return false;
            }
            if ($withState && $f['state'] !== 'all') {
                if ($f['state'] === self::STATE_VALID) {
                    if (! in_array($r['state'], [self::STATE_ACTIVE, self::STATE_SOON], true)) {
                        return false;
                    }
                } elseif ($r['state'] !== $f['state']) {
                    return false;
                }
            }
            if ($f['site'] !== '' && self::fold($r['site']) !== self::fold($f['site'])) {
                return false;
            }
            if ($f['category'] !== '' && $r['category'] !== $f['category']) {
                return false;
            }
            if ($q !== '') {
                $hay = self::fold(implode(' ', [$r['name'], $r['asset_tag'], $r['serial'], $r['model'], $r['model_number'], $r['supplier'], $r['site']]));
                if (! str_contains($hay, $q)) {
                    return false;
                }
            }

            return true;
        }));

        usort($out, function ($a, $b) {
            // Soonest to run out first; "missing" last; then by place.
            $da = $a['days_left'] ?? PHP_INT_MAX;
            $db = $b['days_left'] ?? PHP_INT_MAX;

            return $da <=> $db
                ?: strnatcasecmp($a['site'], $b['site'])
                ?: strnatcasecmp($a['hall'], $b['hall'])
                ?: strnatcasecmp($a['name'], $b['name']);
        });

        return $out;
    }

    /**
     * Repair history for the given assets.
     *
     * @param  list<int>  $assetIds
     * @return array<int, list<array<string, mixed>>>  asset id => repairs, newest first
     */
    public function repairs(array $assetIds): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($assetIds)), 1000) as $chunk) {
            Maintenance::query()
                ->where('item_type', Asset::class)
                ->whereIn('item_id', $chunk)
                ->with(['supplier:id,name', 'maintenanceType:id,name'])
                ->orderByDesc('start_date')
                ->get(['id', 'item_id', 'item_type', 'name', 'url', 'notes', 'start_date', 'completed_at', 'is_warranty', 'cost', 'supplier_id', 'maintenance_type_id'])
                ->each(function (Maintenance $m) use (&$out) {
                    $out[(int) $m->item_id][] = [
                        'id' => (int) $m->id,
                        'name' => (string) $m->name,
                        'description' => trim((string) $m->url),
                        'type' => (string) ($m->maintenanceType?->name ?? ''),
                        'supplier' => (string) ($m->supplier?->name ?? ''),
                        'start_at' => $m->start_date ? Carbon::parse($m->start_date) : null,
                        'completed_at' => $m->completed_at ? Carbon::parse($m->completed_at) : null,
                        'is_warranty' => (bool) $m->is_warranty,
                        'cost' => $m->cost !== null ? (float) $m->cost : null,
                    ];
                });
        }

        return $out;
    }

    /**
     * Attach repairs + per-asset totals to the rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, list<array<string, mixed>>>  $repairs
     * @return list<array<string, mixed>>
     */
    public static function withRepairs(array $rows, array $repairs): array
    {
        foreach ($rows as &$r) {
            $list = $repairs[$r['id']] ?? [];
            $count = 0;
            $underWarranty = 0;
            $claimed = 0;
            $unclaimed = 0;
            $cost = 0.0;
            $last = null;

            foreach ($list as &$m) {
                $m['is_maintenance'] = IncidentStatsService::isMaintenance($m);
                $m['in_warranty'] = $m['start_at'] instanceof Carbon && $r['expires'] instanceof Carbon
                    && $m['start_at']->lte($r['expires'])
                    && (! $r['purchase_date'] instanceof Carbon || $m['start_at']->gte($r['purchase_date']));
                $m['unclaimed'] = ! $m['is_maintenance'] && $m['in_warranty'] && ! $m['is_warranty'];
                $cost += (float) ($m['cost'] ?? 0);

                if ($m['is_maintenance']) {
                    continue;
                }
                $count++;
                $underWarranty += $m['in_warranty'] ? 1 : 0;
                $claimed += $m['is_warranty'] ? 1 : 0;
                $unclaimed += $m['unclaimed'] ? 1 : 0;
                if ($m['start_at'] instanceof Carbon && ($last === null || $m['start_at']->gt($last))) {
                    $last = $m['start_at'];
                }
            }
            unset($m);

            $r['repairs'] = $list;
            $r['repair_count'] = $count;
            $r['repair_in_warranty'] = $underWarranty;
            $r['repair_claimed'] = $claimed;
            $r['repair_unclaimed'] = $unclaimed;
            $r['maintenance_count'] = count($list) - $count;
            $r['repair_cost'] = $cost;
            $r['last_repair'] = $last;
        }
        unset($r);

        return $rows;
    }

    /**
     * Totals for the rows on screen.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{assets: int, repairs: int, claimed: int, unclaimed: int, cost: float, with_repairs: int}
     */
    public static function totals(array $rows): array
    {
        return [
            'assets' => count($rows),
            'repairs' => array_sum(array_map(fn ($r) => $r['repair_count'] ?? 0, $rows)),
            'claimed' => array_sum(array_map(fn ($r) => $r['repair_claimed'] ?? 0, $rows)),
            'unclaimed' => array_sum(array_map(fn ($r) => $r['repair_unclaimed'] ?? 0, $rows)),
            'cost' => (float) array_sum(array_map(fn ($r) => $r['repair_cost'] ?? 0, $rows)),
            'with_repairs' => count(array_filter($rows, fn ($r) => ($r['repair_count'] ?? 0) > 0)),
        ];
    }

    /**
     * Dropdown options from all (non-filtered) rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{sites: list<string>, categories: list<string>}
     */
    public static function options(array $rows): array
    {
        $sites = array_values(array_unique(array_filter(array_column($rows, 'site'), fn ($s) => $s !== '')));
        $cats = array_values(array_unique(array_filter(array_column($rows, 'category'), fn ($s) => $s !== '')));
        natcasesort($sites);
        natcasesort($cats);

        return ['sites' => array_values($sites), 'categories' => array_values($cats)];
    }

    /**
     * Excel: "Thiết bị" (one line per asset) + "Lịch sử sửa chữa".
     *
     * @param  list<array<string, mixed>>  $rows  rows with repairs attached
     */
    public static function export(array $rows, string $subtitle, string $path): string
    {
        $assetRows = [];
        $assetStyles = [];
        $repairRows = [];
        $repairStyles = [];

        foreach ($rows as $i => $r) {
            $assetRows[] = [
                $i + 1,
                $r['site'],
                $r['hall'],
                $r['category'],
                $r['name'],
                MaintenanceFormExporter::modelLabel($r),
                $r['serial'],
                $r['asset_tag'],
                $r['status'],
                $r['supplier'],
                $r['purchase_date'] instanceof Carbon ? $r['purchase_date']->format('d/m/Y') : '',
                $r['warranty_months'],
                $r['expires'] instanceof Carbon ? $r['expires']->format('d/m/Y') : '',
                self::STATE_LABELS[$r['state']] ?? '',
                $r['left_label'],
                $r['repair_count'] ?? 0,
                $r['repair_claimed'] ?? 0,
                $r['repair_unclaimed'] ?? 0,
                ($r['repair_cost'] ?? 0) > 0 ? (int) round($r['repair_cost']) : null,
                ($r['last_repair'] ?? null) instanceof Carbon ? $r['last_repair']->format('d/m/Y') : '',
            ];
            if ($r['state'] === self::STATE_SOON || ($r['repair_unclaimed'] ?? 0) > 0) {
                $assetStyles[$i] = 'red';
            }

            foreach ($r['repairs'] ?? [] as $m) {
                $repairRows[] = [
                    count($repairRows) + 1,
                    $r['site'],
                    $r['hall'],
                    $r['name'],
                    $r['serial'],
                    $m['start_at'] instanceof Carbon ? $m['start_at']->format('d/m/Y H:i') : '',
                    $m['completed_at'] instanceof Carbon ? $m['completed_at']->format('d/m/Y H:i') : '',
                    $m['name'],
                    $m['description'],
                    $m['is_maintenance'] ? 'Bảo trì' : 'Sửa chữa',
                    $m['type'],
                    $m['supplier'],
                    $m['in_warranty'] ? 'Có' : '',
                    $m['is_warranty'] ? 'Có' : '',
                    $m['cost'] !== null ? (int) round($m['cost']) : null,
                    $m['unclaimed'] ? 'Trong hạn BH nhưng chưa ghi Bảo hành' : '',
                ];
                if ($m['unclaimed']) {
                    $repairStyles[count($repairRows) - 1] = 'red';
                }
            }
        }

        $t = self::totals($rows);
        $w = new SimpleXlsxWriter;
        $w->addSheet('Thiết bị', ['STT', 'Site', 'Hall', 'Loại', 'Thiết bị', 'Model', 'Serial', 'Mã TS', 'Trạng thái', 'Nhà cung cấp',
            'Ngày mua', 'BH (tháng)', 'Hết bảo hành', 'Tình trạng BH', 'Thời gian', 'Số lần sửa', 'Sửa theo BH', 'Trong hạn BH chưa ghi BH', 'Chi phí sửa', 'Sửa gần nhất'], $assetRows, [
                'title' => 'BÁO CÁO BẢO HÀNH THIẾT BỊ',
                'subtitle' => $subtitle,
                'widths' => [5, 20, 6, 14, 26, 20, 16, 12, 16, 18, 11, 9, 12, 16, 18, 9, 9, 12, 14, 12],
                'rowStyles' => $assetStyles,
                'totalRow' => $assetRows ? ['Tổng', null, null, null, $t['assets'].' thiết bị', null, null, null, null, null, null, null, null, null, null,
                    $t['repairs'], $t['claimed'], $t['unclaimed'], $t['cost'] > 0 ? (int) round($t['cost']) : null, null] : null,
            ]);
        $w->addSheet('Lịch sử sửa chữa', ['STT', 'Site', 'Hall', 'Thiết bị', 'Serial', 'Bắt đầu', 'Hoàn thành', 'Nội dung', 'Mô tả',
            'Loại phiếu', 'Part / Loại', 'NCC sửa', 'Trong hạn BH', 'Ghi Bảo hành', 'Chi phí', 'Lưu ý'], $repairRows, [
                'title' => 'LỊCH SỬ SỬA CHỮA',
                'subtitle' => $subtitle,
                'widths' => [5, 20, 6, 26, 16, 16, 16, 30, 40, 10, 14, 18, 10, 10, 14, 30],
                'rowStyles' => $repairStyles,
            ]);

        return $w->save($path);
    }

    private static function fold(string $v): string
    {
        return Str::lower(Str::ascii(trim($v)));
    }
}

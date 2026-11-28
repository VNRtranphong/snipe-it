<?php

namespace App\Services\MaintenanceForms;

use App\Models\Location;
use App\Models\Maintenance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Data behind the read-only "Báo cáo sự cố" page and its exports.
 *
 * Every row is a flat array built by MaintenanceFormExporter::collect()
 * plus a few computed fields (duration, status, overdue …). Filtering
 * on site / hall / equipment happens in PHP because site & hall are
 * derived from the asset's location tree; the date range is applied in
 * SQL first so the in-memory set stays small (one month ≈ a few hundred
 * rows).
 */
class IncidentReportRepository
{
    /** Incidents that took (or are taking) longer than this are "quá hạn". */
    public const OVERDUE_HOURS = 24;

    /** Safety cap for a single page / export. */
    public const MAX_ROWS = 2000;

    public const STATUS_OPEN = 'open';

    public const STATUS_DONE = 'done';

    public const STATUS_OVERDUE = 'overdue';

    /** Loại phiếu filter. Default: incidents only. */
    public const KIND_INCIDENT = 'incident';

    public const KIND_MAINTENANCE = 'maintenance';

    public const KIND_ALL = 'all';

    /** Incidents that caused cancelled shows. */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Normalised filters from the request.
     *
     * @param  array<string, mixed>  $input
     * @return array{from: Carbon, to: Carbon, site: string, hall: string, equipment: string, status: string, q: string}
     */
    public static function filters(array $input, ?User $user = null): array
    {
        $from = self::parseDate($input['from'] ?? null) ?? Carbon::now()->startOfMonth();
        $to = self::parseDate($input['to'] ?? null) ?? Carbon::now();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        // No "site" key at all => first visit: default to the user's own site.
        // An explicit empty value ("Tất cả") shows every site.
        $site = array_key_exists('site', $input)
            ? trim((string) $input['site'])
            : self::siteOfUser($user);

        return [
            'from' => $from->copy()->startOfDay(),
            'to' => $to->copy()->endOfDay(),
            'site' => $site,
            'hall' => trim((string) ($input['hall'] ?? '')),
            'equipment' => trim((string) ($input['equipment'] ?? '')),
            'status' => in_array($input['status'] ?? '', [self::STATUS_OPEN, self::STATUS_DONE, self::STATUS_OVERDUE, self::STATUS_CANCELLED], true)
                ? $input['status'] : '',
            'q' => trim((string) ($input['q'] ?? '')),
            'kind' => in_array($input['kind'] ?? '', [self::KIND_MAINTENANCE, self::KIND_ALL], true)
                ? $input['kind'] : self::KIND_INCIDENT,
            // strict=1: only incidents that STARTED in the date range (no older
            // open ones carried over). Used by the clickable heat-map cells so
            // the list matches the number that was clicked.
            'strict' => ! empty($input['strict']),
        ];
    }

    /**
     * All rows in the date range (before site/hall/equipment/status filters).
     *
     * Open incidents that started before the range are included too, so
     * something still broken never drops off the page at month change.
     *
     * @param  array{from: Carbon, to: Carbon}  $f
     * @return list<array<string, mixed>>
     */
    public function rowsInRange(array $f, ?Carbon $now = null, bool $includeOpenBefore = true, ?int $limit = null): array
    {
        $now ??= Carbon::now();

        $maintenances = Maintenance::query()
            ->with([
                'asset.model.category',
                'asset.location.parent',
                'asset.defaultLoc',
                'asset.assignedTo',
                'asset.company',
                'maintenanceType',
                'supplier',
                'responsibleParty',
                'adminuser',
            ])
            ->where(function ($q) use ($f, $includeOpenBefore) {
                $q->whereBetween('start_date', [$f['from'], $f['to']]);
                if ($includeOpenBefore) {
                    $q->orWhere(function ($open) use ($f) {
                        $open->whereNull('completed_at')->where('start_date', '<', $f['from']);
                    });
                }
            })
            ->orderByDesc('start_date')
            ->limit($limit ?? self::MAX_ROWS)
            ->get();

        // Cinema / hall remembered when the BBSC was numbered or locked wins
        // over where the asset is now (it may have been moved to VP HO).
        $places = app(IncidentDocumentService::class)->places($maintenances->pluck('id')->map(fn ($id) => (int) $id)->all());

        $rows = [];
        foreach ($maintenances as $m) {
            $d = MaintenanceFormExporter::withPlace(MaintenanceFormExporter::collect($m), $places[(int) $m->id] ?? null);
            $rows[] = self::decorate($d, $now);
        }

        return $rows;
    }

    /**
     * Add the computed columns used by the page and the summary export.
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    public static function decorate(array $d, Carbon $now): array
    {
        $start = $d['start_at'] ?? null;
        $end = $d['completed_at_dt'] ?? null;

        $hours = null;
        if ($start instanceof Carbon) {
            $hours = max(0, $start->diffInMinutes($end ?? $now, true) / 60);
        }

        $d['is_maintenance'] = IncidentStatsService::isMaintenance($d);
        $d['done'] = $end !== null;
        $d['hours'] = $hours;
        // Planned maintenance is never "quá 24h" – only incidents are.
        $d['overdue'] = ! $d['is_maintenance'] && $hours !== null && $hours > self::OVERDUE_HOURS;
        $d['duration_label'] = self::durationLabel($hours);
        $d['status_label'] = $d['done'] ? 'Hoàn thành' : 'Đang xử lý';
        $d['equipment'] = MaintenanceFormExporter::equipmentLabel($d);
        $d['hall_label'] = $d['hall'] !== '' ? 'Hall '.$d['hall'] : '';
        // Tech = "Số suất hủy tính cho Tech" (all cancelled-show reports use this);
        // Rạp = "Số suất hủy tính cho Rạp", always shown as its own column.
        $d['cancelled_count'] = self::cancelledCount((string) $d['cancelled_shows']);
        $d['cancelled_cinema_count'] = self::cancelledCount((string) ($d['cancelled_cinema'] ?? ''));

        return $d;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{site: string, hall: string, equipment: string, status: string, q: string}  $f
     * @return list<array<string, mixed>>
     */
    public static function applyFilters(array $rows, array $f): array
    {
        $q = $f['q'] !== '' ? self::fold($f['q']) : '';

        $kind = $f['kind'] ?? self::KIND_INCIDENT;

        return array_values(array_filter($rows, function (array $r) use ($f, $q, $kind) {
            if ($kind === self::KIND_INCIDENT && ! empty($r['is_maintenance'])) {
                return false;
            }
            if ($kind === self::KIND_MAINTENANCE && empty($r['is_maintenance'])) {
                return false;
            }
            if ($f['site'] !== '' && self::fold($r['site']) !== self::fold($f['site'])) {
                return false;
            }
            if ($f['hall'] !== '' && self::fold($r['hall']) !== self::fold($f['hall'])) {
                return false;
            }
            if ($f['equipment'] !== '' && $r['equipment'] !== $f['equipment']) {
                return false;
            }
            if ($f['status'] === self::STATUS_OPEN && $r['done']) {
                return false;
            }
            if ($f['status'] === self::STATUS_DONE && ! $r['done']) {
                return false;
            }
            if ($f['status'] === self::STATUS_OVERDUE && ! $r['overdue']) {
                return false;
            }
            if ($f['status'] === self::STATUS_CANCELLED && $r['cancelled_count'] <= 0) {
                return false;
            }
            if ($q !== '') {
                $hay = self::fold(implode(' ', [
                    $r['name'], $r['description'], $r['asset_name'], $r['asset_tag'],
                    $r['serial'], $r['model'], $r['technician'], $r['type'],
                ]));
                if (! str_contains($hay, $q)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Numbers for the cards on top of the page.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{total: int, open: int, overdue: int, overdue_open: int, cancelled: int, cancelled_incidents: int, avg_hours: float|null}
     */
    public static function stats(array $rows): array
    {
        $doneHours = array_filter(array_map(fn ($r) => $r['done'] ? $r['hours'] : null, $rows), fn ($h) => $h !== null);

        return [
            'total' => count($rows),
            'open' => count(array_filter($rows, fn ($r) => ! $r['done'])),
            'overdue' => count(array_filter($rows, fn ($r) => $r['overdue'])),
            'overdue_open' => count(array_filter($rows, fn ($r) => $r['overdue'] && ! $r['done'])),
            'cancelled' => array_sum(array_map(fn ($r) => $r['cancelled_count'], $rows)),
            'cancelled_incidents' => count(array_filter($rows, fn ($r) => $r['cancelled_count'] > 0)),
            'cancelled_cinema' => array_sum(array_map(fn ($r) => (int) ($r['cancelled_cinema_count'] ?? 0), $rows)),
            'avg_hours' => $doneHours ? array_sum($doneHours) / count($doneHours) : null,
        ];
    }

    /**
     * Per-site totals for the summary workbook.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{site: string, total: int, open: int, overdue: int, cancelled: int}>
     */
    public static function bySite(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $key = $r['site'] !== '' ? $r['site'] : '(Chưa rõ site)';
            $out[$key] ??= ['site' => $key, 'total' => 0, 'open' => 0, 'overdue' => 0, 'cancelled' => 0, 'cancelled_cinema' => 0];
            $out[$key]['total']++;
            $out[$key]['open'] += $r['done'] ? 0 : 1;
            $out[$key]['overdue'] += $r['overdue'] ? 1 : 0;
            $out[$key]['cancelled'] += $r['cancelled_count'];
            $out[$key]['cancelled_cinema'] += (int) ($r['cancelled_cinema_count'] ?? 0);
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($out);
    }

    /**
     * Distinct option lists for the dropdowns, from the rows in range.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{sites: list<string>, halls: list<string>, equipment: list<string>}
     */
    public static function options(array $rows, string $site = ''): array
    {
        $sites = $halls = $equipment = [];
        foreach ($rows as $r) {
            if ($r['site'] !== '') {
                $sites[$r['site']] = true;
            }
            if ($r['hall'] !== '' && ($site === '' || self::fold($r['site']) === self::fold($site))) {
                $halls[$r['hall']] = true;
            }
            $equipment[$r['equipment']] = true;
        }

        $sites = array_keys($sites);
        $halls = array_keys($halls);
        natcasesort($sites);
        natsort($halls);
        // Keep equipment in the same order as the paper form.
        $equipment = array_values(array_filter(
            MaintenanceFormExporter::EQUIPMENT_LABELS,
            fn ($label) => isset($equipment[$label])
        ));

        return ['sites' => array_values($sites), 'halls' => array_values(array_map('strval', $halls)), 'equipment' => $equipment];
    }

    /**
     * Month-by-month numbers for the trend chart.
     *
     * @param  list<array<string, mixed>>  $rows  decorated rows (already filtered)
     * @return array{labels: list<string>, keys: list<string>, incidents: list<int>, cancelled: list<int>, overdue: list<int>,
     *               sites: list<array{site: string, counts: list<int>, total: int}>}
     */
    public static function trend(array $rows, Carbon $endMonth, int $months = 12): array
    {
        $keys = [];
        $labels = [];
        $cursor = $endMonth->copy()->startOfMonth();
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = (clone $cursor)->modify('-'.$i.' month');
            $keys[] = $m->format('Y-m');
            $labels[] = $m->format('m/Y');
        }
        $index = array_flip($keys);

        $incidents = array_fill(0, $months, 0);
        $cancelled = array_fill(0, $months, 0);
        $overdue = array_fill(0, $months, 0);
        $sites = [];

        foreach ($rows as $r) {
            $start = $r['start_at'] ?? null;
            if (! $start instanceof Carbon) {
                continue;
            }
            $k = $start->format('Y-m');
            if (! isset($index[$k])) {
                continue;
            }
            $i = $index[$k];
            $incidents[$i]++;
            $cancelled[$i] += (int) $r['cancelled_count'];
            $overdue[$i] += $r['overdue'] ? 1 : 0;

            $site = $r['site'] !== '' ? $r['site'] : '(Chưa rõ site)';
            $sites[$site] ??= ['site' => $site, 'counts' => array_fill(0, $months, 0), 'cancelled' => array_fill(0, $months, 0), 'total' => 0, 'cancelled_total' => 0];
            $sites[$site]['counts'][$i]++;
            $sites[$site]['total']++;
            $sites[$site]['cancelled'][$i] += (int) $r['cancelled_count'];
            $sites[$site]['cancelled_total'] += (int) $r['cancelled_count'];
        }

        // Busiest sites first; ties by name.
        uasort($sites, fn ($a, $b) => $b['total'] <=> $a['total'] ?: strnatcasecmp($a['site'], $b['site']));

        return [
            'labels' => $labels,
            'keys' => $keys,
            'incidents' => $incidents,
            'cancelled' => $cancelled,
            'overdue' => $overdue,
            'sites' => array_values($sites),
        ];
    }

    /** "2 ngày 5 giờ", "5 giờ 30 phút", "45 phút". */
    public static function durationLabel(?float $hours): string
    {
        if ($hours === null) {
            return '';
        }
        $minutes = (int) round($hours * 60);
        $days = intdiv($minutes, 1440);
        $h = intdiv($minutes % 1440, 60);
        $m = $minutes % 60;

        if ($days > 0) {
            return $days.' ngày'.($h ? ' '.$h.' giờ' : '');
        }
        if ($h > 0) {
            return $h.' giờ'.($m ? ' '.$m.' phút' : '');
        }

        return $m.' phút';
    }

    /** "2", "2 suất", "Hủy 3" … => 2 / 3. Non-numeric text counts as 0. */
    public static function cancelledCount(string $value): int
    {
        return preg_match('/\d+/', $value, $m) ? (int) $m[0] : 0;
    }

    /** Site name for a user: their location, or its parent when it is a hall. */
    public static function siteOfUser(?User $user): string
    {
        $loc = $user?->location;
        if (! $loc instanceof Location) {
            return '';
        }
        if (preg_match('/\b(hall|phong chieu)\b/i', Str::ascii($loc->name)) && $loc->parent) {
            return (string) $loc->parent->name;
        }

        return (string) $loc->name;
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function fold(string $value): string
    {
        return Str::lower(Str::ascii(trim($value)));
    }
}

<?php

namespace App\Services\MaintenanceForms;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * "Thống kê sự cố": year-over-year comparison of maintenances.
 *
 * Everything here works on decorated rows (IncidentReportRepository::decorate)
 * so it can be unit tested without a database.
 *
 * Definitions (shown on the page as well):
 *  - maintenance: type or name says "Maintenance / Bảo trì / Bảo dưỡng"
 *    (the create form tells users to name periodic work that way);
 *    everything else is an incident ("sự cố").
 *  - cancelled: shows cancelled for Tech ("Số suất hủy tính cho Tech"), incidents only.
 *  - cancelled_cinema: "Số suất hủy tính cho Rạp" – separate, never added to the Tech numbers.
 *  - overdue: incidents that took > 24h (open ones count up to now).
 *  - cancelled_over24: cancelled shows caused by those overdue incidents.
 *  - Same period ("cùng kỳ"): every year counted from 01/01 to the same
 *    day/month as the cut-off, so a running year compares fairly.
 */
class IncidentStatsService
{
    public const METRICS = [
        'incidents' => 'Số sự cố',
        'maintenance' => 'Số lần maintenance',
        'cancelled' => 'Số suất hủy (Tech)',
        'cancelled_over24' => 'Suất hủy (Tech) do sự cố quá 24h',
        'cancelled_cinema' => 'Số suất hủy (Rạp)',
        'overdue' => 'Sự cố quá 24h',
    ];

    /**
     * Ascii-lowercased patterns that mean planned maintenance, not an incident.
     * Type: any of these words anywhere. Title: "bảo trì" / "bảo dưỡng" anywhere
     * (e.g. "Bảo trì định kỳ 7 phòng KDV"), or starting with maintenance / vệ sinh.
     */
    private const MAINTENANCE_TYPE = '/\b(maintenance|bao tri|bao duong|dinh ky|ve sinh|preventive)\b/';

    private const MAINTENANCE_NAME = '/\b(bao tri|bao duong)\b|^\s*(maintenance|ve sinh)\b/';

    public static function isMaintenance(array $r): bool
    {
        $type = Str::lower(Str::ascii((string) ($r['type'] ?? '')));
        $name = Str::lower(Str::ascii((string) ($r['name'] ?? '')));

        return (bool) (preg_match(self::MAINTENANCE_TYPE, $type) || preg_match(self::MAINTENANCE_NAME, $name));
    }

    /**
     * Value of one metric for one row (0/1 for counts, n for shows).
     *
     * @param  array<string, mixed>  $r
     */
    public static function value(array $r, string $metric): int
    {
        $maint = self::isMaintenance($r);

        return match ($metric) {
            'incidents' => $maint ? 0 : 1,
            'maintenance' => $maint ? 1 : 0,
            'cancelled' => $maint ? 0 : (int) $r['cancelled_count'],
            'cancelled_over24' => (! $maint && $r['overdue']) ? (int) $r['cancelled_count'] : 0,
            'cancelled_cinema' => $maint ? 0 : (int) ($r['cancelled_cinema_count'] ?? 0),
            'overdue' => (! $maint && $r['overdue']) ? 1 : 0,
            default => 0,
        };
    }

    /**
     * Full statistics for the page.
     *
     * @param  list<array<string, mixed>>  $rows  decorated rows covering at least years [$year-2 .. $year]
     * @param  bool  $sameSpan  true: every year cut at the same day/month as $cutoff ("cùng kỳ")
     * @return array<string, mixed>
     */
    public static function build(array $rows, int $year, Carbon $cutoff, bool $sameSpan, string $metric): array
    {
        $metric = array_key_exists($metric, self::METRICS) ? $metric : 'incidents';
        $years = [$year - 2, $year - 1, $year];
        $cutMd = $sameSpan ? $cutoff->format('m-d') : '12-31';
        $currentYear = (int) $cutoff->format('Y');
        $currentMonth = (int) $cutoff->format('n');

        // Bucket rows by year (only rows inside the compared span).
        $byYear = array_fill_keys($years, []);
        $monthly = [];
        foreach ($years as $y) {
            foreach (array_keys(self::METRICS) as $m) {
                $monthly[$y][$m] = array_fill(0, 12, 0);
            }
        }

        foreach ($rows as $r) {
            $start = $r['start_at'] ?? null;
            if (! $start instanceof Carbon) {
                continue;
            }
            $y = (int) $start->format('Y');
            if (! isset($byYear[$y])) {
                continue;
            }

            // Monthly chart always shows whole months (Jan–Dec).
            $mi = (int) $start->format('n') - 1;
            foreach (array_keys(self::METRICS) as $m) {
                $monthly[$y][$m][$mi] += self::value($r, $m);
            }

            if ($start->format('m-d') <= $cutMd) {
                $byYear[$y][] = $r;
            }
        }

        // Months that have not happened yet are null, not 0 (no fake drop on the chart).
        foreach ($years as $y) {
            if ($y > $currentYear) {
                foreach ($monthly[$y] as $m => $vals) {
                    $monthly[$y][$m] = array_fill(0, 12, null);
                }
            } elseif ($y === $currentYear) {
                foreach ($monthly[$y] as $m => $vals) {
                    for ($i = $currentMonth; $i < 12; $i++) {
                        $monthly[$y][$m][$i] = null;
                    }
                }
            }
        }

        // 2. Totals per year + change vs the previous year.
        $totals = [];
        $prev = null;
        foreach ($years as $y) {
            $t = ['year' => $y];
            foreach (array_keys(self::METRICS) as $m) {
                $t[$m] = array_sum(array_map(fn ($r) => self::value($r, $m), $byYear[$y]));
                $t[$m.'_pct'] = $prev ? self::pct($prev[$m], $t[$m]) : null;
            }
            $totals[] = $t;
            $prev = $t;
        }

        // 3. This year vs last year by site and by equipment type.
        $cur = $byYear[$year];
        $last = $byYear[$year - 1];

        return [
            'year' => $year,
            'years' => $years,
            'metric' => $metric,
            'metric_label' => self::METRICS[$metric],
            'same_span' => $sameSpan,
            'span_label' => $sameSpan ? '01/01 – '.$cutoff->format('d/m') : 'cả năm',
            'monthly' => $monthly,
            'totals' => $totals,
            'by_site' => self::compare($cur, $last, $metric, fn ($r) => $r['site'] !== '' ? $r['site'] : '(Chưa rõ site)'),
            'by_equipment' => self::compare($cur, $last, $metric, fn ($r) => $r['equipment'] ?: 'Khác'),
        ];
    }

    /**
     * Group two years of rows by a key and compare one metric.
     * Sorted by the biggest increase first, so problem areas are on top.
     *
     * @param  list<array<string, mixed>>  $cur
     * @param  list<array<string, mixed>>  $last
     * @return list<array{key: string, last: int, cur: int, diff: int, pct: float|null}>
     */
    public static function compare(array $cur, array $last, string $metric, callable $keyOf): array
    {
        $out = [];
        foreach (['cur' => $cur, 'last' => $last] as $side => $rows) {
            foreach ($rows as $r) {
                $k = (string) $keyOf($r);
                $out[$k] ??= ['key' => $k, 'last' => 0, 'cur' => 0];
                $out[$k][$side] += self::value($r, $metric);
            }
        }

        $out = array_filter($out, fn ($o) => $o['cur'] > 0 || $o['last'] > 0);
        foreach ($out as &$o) {
            $o['diff'] = $o['cur'] - $o['last'];
            $o['pct'] = self::pct($o['last'], $o['cur']);
        }
        unset($o);

        usort($out, fn ($a, $b) => $b['diff'] <=> $a['diff'] ?: $b['cur'] <=> $a['cur'] ?: strnatcasecmp($a['key'], $b['key']));

        return array_values($out);
    }

    /** % change from $old to $new; null when $old is 0 (no base to compare). */
    public static function pct(int|float $old, int|float $new): ?float
    {
        if ($old == 0) {
            return null;
        }

        return round(($new - $old) / $old * 100, 1);
    }
}

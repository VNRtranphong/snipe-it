<?php

namespace App\Services\MaintenanceForms;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * "Kế hoạch bảo trì năm": the yearly plan to be signed. One line per
 * planned maintenance (cinema × system × month) with the staff who will
 * do it. Lines are first generated from the schedule ("Lịch năm" of
 * MaintenancePlanService: last done + cycle), then edited freely: move
 * a month, set a date, type the staff names, add or remove lines.
 */
class MaintenanceYearPlanService
{
    public const LINES = 'maintenance_year_plan_lines';

    public const YEARS = 'maintenance_year_plans';

    public const SIGNATURES = [
        'prepared_by' => 'Người lập',
        'checked_by' => 'Trưởng bộ phận Kỹ thuật',
        'approved_by' => 'Phê duyệt',
    ];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasTable(self::LINES) && Schema::hasTable(self::YEARS);
            } catch (\Throwable $e) {
                self::$ready = false;
            }
        }

        return self::$ready;
    }

    /**
     * "Phong Tran, Minh\nAn ; Bình" => ['Phong Tran', 'Minh', 'An', 'Bình'] (unique, trimmed).
     *
     * @return list<string>
     */
    public static function staffList(?string $text): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,;]+/u', (string) $text) ?: [] as $name) {
            $name = trim(preg_replace('/\s+/u', ' ', $name));
            if ($name !== '' && ! in_array(mb_strtolower($name), array_map('mb_strtolower', $out), true)) {
                $out[] = mb_substr($name, 0, 80);
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function lines(int $year): array
    {
        if (! self::ready()) {
            return [];
        }

        return array_map(fn ($l) => self::decorate($l), DB::table(self::LINES)->where('year', $year)->get()->all());
    }

    /** @return array<string, mixed> */
    public static function decorate(object $l): array
    {
        $staff = self::staffList($l->staff);

        return [
            'id' => (int) $l->id,
            'year' => (int) $l->year,
            'site' => (string) $l->site,
            'system' => (string) $l->system,
            'system_label' => MaintenancePlanService::SYSTEMS[$l->system]['label'] ?? (string) $l->system,
            'month' => (int) $l->month,
            'planned_date' => $l->planned_date ? Carbon::parse($l->planned_date) : null,
            'staff' => $staff,
            'staff_count' => count($staff),
            'note' => (string) ($l->note ?? ''),
        ];
    }

    /**
     * Sort: month, date, cinema, system order.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public static function sort(array $lines): array
    {
        $order = array_flip(array_keys(MaintenancePlanService::SYSTEMS));
        usort($lines, fn ($a, $b) => $a['month'] <=> $b['month']
            ?: ($a['planned_date']?->getTimestamp() ?? 0) <=> ($b['planned_date']?->getTimestamp() ?? 0)
            ?: strnatcasecmp($a['site'], $b['site'])
            ?: ($order[$a['system']] ?? 9) <=> ($order[$b['system']] ?? 9));

        return $lines;
    }

    /**
     * Add the lines the schedule projects for the year and that are not in
     * the plan yet (same cinema + system + month). Existing lines are kept.
     *
     * @param  list<array<string, mixed>>  $calendar  MaintenancePlanService::calendar() of that year
     */
    public function generate(int $year, array $calendar, ?int $userId): int
    {
        $this->assertReady();
        $existing = [];
        foreach (DB::table(self::LINES)->where('year', $year)->get(['site', 'system', 'month']) as $l) {
            $existing[$l->site.'|'.$l->system.'|'.$l->month] = true;
        }

        $now = Carbon::now();
        $insert = [];
        foreach ($calendar as $c) {
            for ($m = 1; $m <= 12; $m++) {
                $kinds = $c['months'][$m] ?? [];
                if (! array_intersect($kinds, ['planned', 'overdue'])) {
                    continue;
                }
                $key = $c['site'].'|'.$c['system'].'|'.$m;
                if (isset($existing[$key])) {
                    continue;
                }
                $existing[$key] = true;
                $insert[] = [
                    'year' => $year, 'site' => $c['site'], 'system' => $c['system'], 'month' => $m,
                    'created_by' => $userId, 'updated_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($insert, 200) as $chunk) {
            DB::table(self::LINES)->insert($chunk);
        }

        return count($insert);
    }

    /**
     * Save edited lines. $input: line id => [month, planned_date, staff, note, delete].
     *
     * @param  array<int|string, array<string, mixed>>  $input
     * @return array{saved: int, deleted: int}
     */
    public function save(int $year, array $input, ?int $userId): array
    {
        $this->assertReady();
        $saved = 0;
        $deleted = 0;
        $now = Carbon::now();
        $ids = DB::table(self::LINES)->where('year', $year)->pluck('id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($input, $ids, $year, $userId, $now, &$saved, &$deleted) {
            foreach ($input as $id => $row) {
                $id = (int) $id;
                if (! in_array($id, $ids, true) || ! is_array($row)) {
                    continue;
                }
                if (! empty($row['delete'])) {
                    DB::table(self::LINES)->where('id', $id)->delete();
                    $deleted++;

                    continue;
                }
                $month = max(1, min(12, (int) ($row['month'] ?? 1)));
                $date = null;
                if (! empty($row['planned_date'])) {
                    try {
                        $date = Carbon::parse((string) $row['planned_date']);
                    } catch (\Throwable $e) {
                        $date = null;
                    }
                    if ($date && (int) $date->format('Y') === $year) {
                        // The date wins over the month dropdown.
                        $month = (int) $date->format('n');
                    } elseif ($date) {
                        $date = null;
                    }
                }
                DB::table(self::LINES)->where('id', $id)->update([
                    'month' => $month,
                    'planned_date' => $date?->format('Y-m-d'),
                    'staff' => implode("\n", self::staffList((string) ($row['staff'] ?? ''))) ?: null,
                    'note' => mb_substr(trim((string) ($row['note'] ?? '')), 0, 500) ?: null,
                    'updated_by' => $userId,
                    'updated_at' => $now,
                ]);
                $saved++;
            }
        });

        return ['saved' => $saved, 'deleted' => $deleted];
    }

    public function add(int $year, string $site, string $system, int $month, ?int $userId): void
    {
        $this->assertReady();
        if (! isset(MaintenancePlanService::SYSTEMS[$system]) || $site === '') {
            throw new RuntimeException('Rạp hoặc hệ không hợp lệ.');
        }
        $now = Carbon::now();
        DB::table(self::LINES)->insert([
            'year' => $year, 'site' => $site, 'system' => $system, 'month' => max(1, min(12, $month)),
            'created_by' => $userId, 'updated_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** @return array<string, string> */
    public function meta(int $year): array
    {
        $row = self::ready() ? DB::table(self::YEARS)->where('year', $year)->first() : null;

        return [
            'place' => (string) ($row->place ?? ''),
            'prepared_by' => (string) ($row->prepared_by ?? ''),
            'checked_by' => (string) ($row->checked_by ?? ''),
            'approved_by' => (string) ($row->approved_by ?? ''),
            'note' => (string) ($row->note ?? ''),
        ];
    }

    /** @param array<string, mixed> $input */
    public function saveMeta(int $year, array $input, ?int $userId): void
    {
        $this->assertReady();
        $data = [];
        foreach (['place', 'prepared_by', 'checked_by', 'approved_by'] as $k) {
            $data[$k] = mb_substr(trim((string) ($input[$k] ?? '')), 0, 191) ?: null;
        }
        $data['note'] = mb_substr(trim((string) ($input['note'] ?? '')), 0, 2000) ?: null;
        $data['updated_by'] = $userId;
        $data['updated_at'] = Carbon::now();

        if (DB::table(self::YEARS)->where('year', $year)->exists()) {
            DB::table(self::YEARS)->where('year', $year)->update($data);
        } else {
            DB::table(self::YEARS)->insert($data + ['year' => $year, 'created_at' => Carbon::now()]);
        }
    }

    /**
     * Names used before (any year), for the quick-pick chips.
     *
     * @return list<string>
     */
    public function knownStaff(): array
    {
        if (! self::ready()) {
            return [];
        }
        $names = [];
        foreach (DB::table(self::LINES)->whereNotNull('staff')->pluck('staff') as $text) {
            foreach (self::staffList($text) as $n) {
                $names[mb_strtolower($n)] = $n;
            }
        }
        natcasesort($names);

        return array_values($names);
    }

    /**
     * Totals per month and per person.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{months: array<int, array<string, int>>, people: array<string, array{name: string, count: int, months: list<int>}>, total_lines: int, total_slots: int, missing_staff: int}
     */
    public static function summary(array $lines): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['lines' => 0, 'slots' => 0] + array_fill_keys(array_keys(MaintenancePlanService::SYSTEMS), 0);
        }
        $people = [];
        $slots = 0;
        $missing = 0;
        foreach ($lines as $l) {
            $months[$l['month']]['lines']++;
            $months[$l['month']][$l['system']] = ($months[$l['month']][$l['system']] ?? 0) + 1;
            $months[$l['month']]['slots'] += $l['staff_count'];
            $slots += $l['staff_count'];
            $missing += $l['staff_count'] === 0 ? 1 : 0;
            foreach ($l['staff'] as $name) {
                $key = mb_strtolower($name);
                $people[$key] ??= ['name' => $name, 'count' => 0, 'months' => []];
                $people[$key]['count']++;
                $people[$key]['months'][] = $l['month'];
            }
        }
        uasort($people, fn ($a, $b) => $b['count'] <=> $a['count'] ?: strnatcasecmp($a['name'], $b['name']));

        return ['months' => $months, 'people' => $people, 'total_lines' => count($lines), 'total_slots' => $slots, 'missing_staff' => $missing];
    }

    /**
     * Excel to be signed: "Kế hoạch" (with signatures), "Theo tháng", "Theo nhân viên".
     *
     * @param  list<array<string, mixed>>  $lines  sorted
     * @param  array<string, string>  $meta
     * @param  array<string, array<string, int>>  $halls  site => system => number of halls
     */
    public static function export(int $year, array $lines, array $meta, array $halls, string $path): string
    {
        $rows = [];
        foreach ($lines as $i => $l) {
            $rows[] = [
                $i + 1,
                sprintf('%02d/%d', $l['month'], $year),
                $l['planned_date'] instanceof Carbon ? $l['planned_date']->format('d/m/Y') : '',
                $l['site'],
                $l['system_label'],
                $halls[$l['site']][$l['system']] ?? null,
                implode("\n", $l['staff']),
                $l['staff_count'] ?: null,
                $l['note'],
            ];
        }
        $sum = self::summary($lines);
        $place = $meta['place'] !== '' ? $meta['place'] : '................';

        $w = new SimpleXlsxWriter;
        $w->addSheet('Kế hoạch '.$year, ['STT', 'Tháng', 'Ngày dự kiến', 'Rạp', 'Hệ', 'Số phòng', 'Nhân viên thực hiện', 'Số người', 'Ghi chú'], $rows, [
            'title' => 'KẾ HOẠCH BẢO TRÌ – BẢO DƯỠNG NĂM '.$year,
            'subtitle' => 'Hệ máy chiếu (Projector, Server, 3D): 3 tháng/lần · Hệ âm thanh (Processor): 6 tháng/lần'
                .($meta['note'] !== '' ? '  |  '.$meta['note'] : ''),
            'widths' => [6, 10, 12, 24, 14, 9, 30, 9, 28],
            'totalRow' => $rows ? ['Tổng', null, null, $sum['total_lines'].' lần bảo trì', null, null, null, $sum['total_slots'], null] : null,
            'signatures' => [
                'place' => $place.', ngày ..... tháng ..... năm ........',
                'blocks' => array_map(fn ($key, $title) => ['title' => $title, 'name' => $meta[$key] ?? ''], array_keys(self::SIGNATURES), self::SIGNATURES),
            ],
        ]);

        $mRows = [];
        foreach ($sum['months'] as $m => $v) {
            $line = [sprintf('%02d/%d', $m, $year)];
            foreach (array_keys(MaintenancePlanService::SYSTEMS) as $sys) {
                $line[] = $v[$sys] ?: null;
            }
            $line[] = $v['lines'] ?: null;
            $line[] = $v['slots'] ?: null;
            $mRows[] = $line;
        }
        $mHeaders = ['Tháng'];
        foreach (MaintenancePlanService::SYSTEMS as $sys) {
            $mHeaders[] = $sys['label'];
        }
        $mHeaders[] = 'Tổng số lần';
        $mHeaders[] = 'Lượt nhân viên';
        $w->addSheet('Theo tháng', $mHeaders, $mRows, [
            'title' => 'TỔNG HỢP THEO THÁNG – NĂM '.$year,
            'widths' => [12, 16, 16, 14, 16],
            'totalRow' => array_merge(['Tổng'], array_map(fn ($sys) => array_sum(array_column($sum['months'], $sys)), array_keys(MaintenancePlanService::SYSTEMS)), [$sum['total_lines'], $sum['total_slots']]),
        ]);

        $pRows = [];
        foreach (array_values($sum['people']) as $i => $p) {
            $pRows[] = [$i + 1, $p['name'], $p['count'], implode(', ', array_map(fn ($m) => sprintf('%02d', $m), array_values(array_unique($p['months']))))];
        }
        $w->addSheet('Theo nhân viên', ['STT', 'Nhân viên', 'Số lần tham gia', 'Các tháng'], $pRows, [
            'title' => 'PHÂN CÔNG NHÂN VIÊN – NĂM '.$year,
            'widths' => [6, 28, 14, 40],
        ]);

        return $w->save($path);
    }

    private function assertReady(): void
    {
        if (! self::ready()) {
            throw new RuntimeException('Chưa chạy migrate cho Kế hoạch bảo trì năm trên server.');
        }
    }
}

<?php

namespace App\Services\MaintenanceForms;

use App\Enums\ActionType;
use App\Models\Actionlog;
use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Location;
use App\Models\Maintenance;
use App\Models\MaintenanceType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * "Lịch bảo trì": periodic maintenance planned per cinema and per system
 * instead of per asset.
 *
 *  - Systems are made of device roles, recognised by the asset's Category
 *    name only (same idea as "Tra cứu thiết bị"):
 *      hệ máy chiếu = Projector + Server + 3D, every 3 months, a cycle may be
 *                     skipped when the previous one found the system good;
 *      hệ âm thanh  = Processor (add 'amplifier', 'monitor', 'speaker' to
 *                     its roles when needed), every 6 months.
 *  - One run = one system at one cinema. Starting a run creates ONE
 *    maintenance per hall (item = the hall's representative device, e.g.
 *    the projector), named "Bảo trì định kỳ …" so every report treats it as
 *    maintenance, not as an incident.
 *  - Next due date = last done (or skipped) date + cycle. Before the first
 *    run, the latest existing "bảo trì / bảo dưỡng" maintenance of the
 *    system's devices at that cinema is used.
 *  - Halls are taken from the asset location (same rule as everywhere:
 *    "Hall N" location, else guessed from the asset name). Devices without
 *    a hall are listed but get no maintenance.
 */
class MaintenancePlanService
{
    public const RUNS = 'maintenance_plan_runs';

    public const ITEMS = 'maintenance_plan_items';

    public const STATUS_OPEN = 'open';

    public const STATUS_DONE = 'done';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * Systems. To add amplifier / monitor / speaker to the sound system,
     * add their role keys to 'roles' (patterns are in ROLE_PATTERNS).
     * The first role found in a hall is the "representative" device the
     * maintenance is attached to.
     */
    public const SYSTEMS = [
        'projection' => [
            'label' => 'Hệ máy chiếu',
            'short' => 'Máy chiếu',
            'months' => 3,
            'skippable' => true,
            'roles' => ['projector', 'server', '3d'],
        ],
        'sound' => [
            'label' => 'Hệ âm thanh',
            'short' => 'Âm thanh',
            'months' => 6,
            'skippable' => false,
            'roles' => ['processor'],
        ],
    ];

    /** Ascii-lowercased category name => role. Order matters (first match wins). */
    public const ROLE_PATTERNS = [
        'projector' => '/\b(projector|projectors|may chieu)\b/',
        'server' => '/\b(server|servers|ims|imb)\b/',
        '3d' => '/\b3d\b/',
        'processor' => '/\b(processor|processors|cinema processor|bo xu ly am thanh)\b/',
        'amplifier' => '/\b(amp|amps|ampli|amplifier|amplifiers)\b/',
        'monitor' => '/\b(monitor|monitors)\b/',
        'speaker' => '/\b(speaker|speakers|loa)\b/',
    ];

    public const ROLE_LABELS = [
        'projector' => 'Projector',
        'server' => 'Server',
        '3d' => '3D',
        'processor' => 'Processor',
        'amplifier' => 'Amplifier',
        'monitor' => 'Monitor',
        'speaker' => 'Speaker',
    ];

    public const STATE_LABELS = [
        'overdue' => 'Quá hạn',
        'due' => 'Đến hạn',
        'open' => 'Đang thực hiện',
        'ok' => 'Chưa đến hạn',
        'never' => 'Chưa có lịch sử',
    ];

    /**
     * B-chain sound levels (dB) typed in after a sound maintenance, in the
     * order of the Service Report cells O8…O14.
     */
    public const LEVELS = [
        'left' => 'Left',
        'center' => 'Center',
        'lfe' => 'LFE',
        'surround_l' => 'Surround L',
        'surround_r' => 'Surround R',
        'bsr_l' => 'BSR Left',
        'bsr_r' => 'BSR Right',
    ];

    /**
     * "A: Kiểm tra trước khi thực hiện" of the Service Report.
     * Projection: free text per row for Projector (col C), Server (col E), Ghi chú (col G).
     * Sound: OK / không ổn per row for Processor, Monitor, Amplifier, Speaker (cols K…N).
     */
    public const PROJECTION_CHECKS = [
        'firmware' => ['label' => 'Firmware/Software', 'row' => 8],
        'color' => ['label' => 'Màu sắc', 'row' => 9],
        'image' => ['label' => 'Hình ảnh', 'row' => 10],
        'errors' => ['label' => 'Mã lỗi/Cảnh báo lỗi', 'row' => 11],
    ];

    public const PROJECTION_CHECK_COLUMNS = ['projector' => 'C', 'server' => 'E', 'note' => 'G'];

    public const SOUND_CHECKS = [
        'connection' => ['label' => 'Kết nối', 'row' => 8],
        'power' => ['label' => 'Nguồn', 'row' => 9],
        'errors' => ['label' => 'Báo lỗi', 'row' => 10],
        'signal' => ['label' => 'Tín hiệu', 'row' => 11],
    ];

    public const SOUND_CHECK_COLUMNS = ['processor' => 'K', 'monitor' => 'L', 'amplifier' => 'M', 'speaker' => 'N'];

    public const CHECK_VALUES = ['ok' => 'OK', 'bad' => 'Không ổn'];

    private static ?bool $levelsReady = null;

    private static ?bool $checklistReady = null;

    public static function checklistReady(): bool
    {
        if (self::$checklistReady === null) {
            try {
                self::$checklistReady = self::ready() && Schema::hasColumn(self::ITEMS, 'checklist');
            } catch (\Throwable $e) {
                self::$checklistReady = false;
            }
        }

        return self::$checklistReady;
    }

    /**
     * Keep only known rows / columns. Projection: trimmed text (max 120 chars,
     * light source 250). Sound: 'ok' or 'bad'.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function cleanChecklist(string $system, array $input): array
    {
        $out = [];
        if ($system === 'sound') {
            foreach (array_keys(self::SOUND_CHECKS) as $row) {
                foreach (array_keys(self::SOUND_CHECK_COLUMNS) as $col) {
                    $v = (string) ($input[$row][$col] ?? '');
                    if (isset(self::CHECK_VALUES[$v])) {
                        $out[$row][$col] = $v;
                    }
                }
            }

            return $out;
        }

        foreach (array_keys(self::PROJECTION_CHECKS) as $row) {
            foreach (array_keys(self::PROJECTION_CHECK_COLUMNS) as $col) {
                $v = trim(preg_replace('/\s+/u', ' ', (string) ($input[$row][$col] ?? '')));
                if ($v !== '') {
                    $out[$row][$col] = mb_substr($v, 0, 120);
                }
            }
        }
        $light = trim((string) ($input['light_source'] ?? ''));
        if ($light !== '') {
            $out['light_source'] = mb_substr($light, 0, 250);
        }

        return $out;
    }

    /** True when any sound check is "không ổn". */
    public static function hasBadCheck(array $checklist): bool
    {
        foreach ($checklist as $row) {
            if (is_array($row) && in_array('bad', $row, true)) {
                return true;
            }
        }

        return false;
    }

    public static function levelsReady(): bool
    {
        if (self::$levelsReady === null) {
            try {
                self::$levelsReady = self::ready() && Schema::hasColumn(self::ITEMS, 'levels');
            } catch (\Throwable $e) {
                self::$levelsReady = false;
            }
        }

        return self::$levelsReady;
    }

    /**
     * Keep only known keys with a number (0–150, one decimal). "85,5" is accepted.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, float|int>
     */
    public static function cleanLevels(array $input): array
    {
        $out = [];
        foreach (array_keys(self::LEVELS) as $key) {
            $raw = trim(str_replace(',', '.', (string) ($input[$key] ?? '')));
            if ($raw === '' || ! is_numeric($raw)) {
                continue;
            }
            $value = round((float) $raw, 1);
            if ($value < 0 || $value > 150) {
                continue;
            }
            $out[$key] = $value == (int) $value ? (int) $value : $value;
        }

        return $out;
    }

    /** "Left : 85 dB" (or "Left : " when not measured). */
    public static function levelLine(string $key, array $levels): string
    {
        $label = self::LEVELS[$key] ?? $key;
        if (! isset($levels[$key])) {
            return $label.' : ';
        }

        return $label.' : '.str_replace('.', ',', (string) $levels[$key]).' dB';
    }

    /**
     * Save what was checked / measured for every hall of a run (allowed
     * before and after completion): checklist ("A: Kiểm tra…") and, for
     * the sound system, B-chain levels.
     *
     * @param  array<int|string, array<string, mixed>>  $levelsByItem  item id => [key => value]
     * @param  array<int|string, array<string, mixed>>  $checksByItem  item id => checklist input
     */
    public function saveResults(int $runId, array $levelsByItem, array $checksByItem): int
    {
        $this->assertReady();
        $run = DB::table(self::RUNS)->where('id', $runId)->first();
        if (! $run) {
            throw new RuntimeException('Không tìm thấy đợt bảo trì.');
        }
        if (($levelsByItem && ! self::levelsReady()) || ($checksByItem && ! self::checklistReady())) {
            throw new RuntimeException('Chưa chạy migrate thêm cột kết quả kiểm tra trên server.');
        }

        $saved = 0;
        foreach (DB::table(self::ITEMS)->where('run_id', $runId)->get(['id']) as $item) {
            $update = [];
            $levels = $levelsByItem[$item->id] ?? $levelsByItem[(string) $item->id] ?? null;
            if (is_array($levels) && $run->system === 'sound') {
                $clean = self::cleanLevels($levels);
                $update['levels'] = $clean ? json_encode($clean) : null;
            }
            $checks = $checksByItem[$item->id] ?? $checksByItem[(string) $item->id] ?? null;
            if (is_array($checks)) {
                $clean = self::cleanChecklist((string) $run->system, $checks);
                $update['checklist'] = $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null;
            }
            if (! $update) {
                continue;
            }
            $update['updated_at'] = Carbon::now();
            DB::table(self::ITEMS)->where('id', $item->id)->update($update);
            $saved++;
        }

        return $saved;
    }

    /** "Đến hạn" = due within this many days (or already this month). */
    public const DUE_SOON_DAYS = 14;

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Schema::hasTable(self::RUNS) && Schema::hasTable(self::ITEMS);
            } catch (\Throwable $e) {
                self::$ready = false;
            }
        }

        return self::$ready;
    }

    // ------------------------------------------------------------------
    // Devices
    // ------------------------------------------------------------------

    public static function roleOfCategory(string $categoryName): ?string
    {
        $hay = Str::lower(Str::ascii($categoryName));
        foreach (self::ROLE_PATTERNS as $role => $regex) {
            if (preg_match($regex, $hay)) {
                return $role;
            }
        }

        return null;
    }

    public static function systemOfRole(?string $role): ?string
    {
        foreach (self::SYSTEMS as $key => $sys) {
            if ($role !== null && in_array($role, $sys['roles'], true)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Every device that belongs to a system, with its cinema / hall.
     * Broken / disposed devices and VP HO / MEP / Projection are left out.
     *
     * @return list<array<string, mixed>>
     */
    public function devices(): array
    {
        $categoryRoles = [];
        $categoryNames = [];
        foreach (Category::where('category_type', 'asset')->get(['id', 'name']) as $cat) {
            $role = self::roleOfCategory((string) $cat->name);
            if ($role !== null && self::systemOfRole($role) !== null) {
                $categoryRoles[$cat->id] = $role;
                $categoryNames[$cat->id] = (string) $cat->name;
            }
        }
        if (! $categoryRoles) {
            return [];
        }

        $models = [];
        foreach (AssetModel::whereIn('category_id', array_keys($categoryRoles))->get(['id', 'name', 'model_number', 'category_id']) as $model) {
            $models[$model->id] = $model;
        }
        if (! $models) {
            return [];
        }

        $rows = [];
        Asset::query()
            ->whereIn('model_id', array_keys($models))
            ->with([
                'location.parent',
                'defaultLoc',
                'status:id,name',
                'assignedTo' => fn ($morph) => $morph->morphWith([Location::class => ['parent']]),
            ])
            ->select(['id', 'name', 'asset_tag', 'serial', 'status_id', 'model_id', 'location_id', 'rtd_location_id', 'assigned_to', 'assigned_type', 'company_id'])
            ->orderBy('id')
            ->chunk(1000, function ($assets) use (&$rows, $models, $categoryRoles, $categoryNames) {
                foreach ($assets as $asset) {
                    if (InventoryRepository::isBrokenStatus($asset->status?->name)) {
                        continue;
                    }
                    [$site, $hall] = MaintenanceFormExporter::siteAndHall($asset);
                    if ($site === '' || InventoryRepository::isExcludedSite($site)) {
                        continue;
                    }
                    $model = $models[$asset->model_id];
                    $role = $categoryRoles[$model->category_id];
                    $rows[] = [
                        'id' => (int) $asset->id,
                        'name' => (string) $asset->name,
                        'asset_tag' => (string) $asset->asset_tag,
                        'serial' => (string) $asset->serial,
                        'model' => (string) $model->name,
                        'model_number' => (string) ($model->model_number ?? ''),
                        'category' => $categoryNames[$model->category_id],
                        'role' => $role,
                        'system' => self::systemOfRole($role),
                        'site' => $site,
                        'hall' => (string) $hall,
                    ];
                }
            });

        return $rows;
    }

    /**
     * site => system => hall => devices (sorted by role order, then name).
     *
     * @param  list<array<string, mixed>>  $devices
     * @return array<string, array<string, array<string, list<array<string, mixed>>>>>
     */
    public static function groupDevices(array $devices): array
    {
        $out = [];
        foreach ($devices as $d) {
            $out[$d['site']][$d['system']][$d['hall']][] = $d;
        }
        foreach ($out as &$systems) {
            foreach ($systems as $sys => &$halls) {
                uksort($halls, fn ($a, $b) => ($a === '') <=> ($b === '') ?: strnatcasecmp($a, $b));
                foreach ($halls as &$list) {
                    $order = array_flip(self::SYSTEMS[$sys]['roles']);
                    usort($list, fn ($a, $b) => ($order[$a['role']] ?? 99) <=> ($order[$b['role']] ?? 99) ?: strnatcasecmp($a['name'], $b['name']));
                }
                unset($list);
            }
            unset($halls);
        }
        unset($systems);
        uksort($out, 'strnatcasecmp');

        return $out;
    }

    // ------------------------------------------------------------------
    // Board (status per cinema × system)
    // ------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $devices
     * @param  list<object>  $runs  rows of maintenance_plan_runs (any order)
     * @param  array<string, Carbon>  $history  "site|system" => latest existing bảo trì date
     * @param  array<int, array{items: int, completed: int}>  $openProgress  run id => progress
     * @return array<string, mixed>
     */
    public static function board(array $devices, array $runs, array $history, Carbon $today, array $openProgress = []): array
    {
        $grouped = self::groupDevices($devices);
        $runsByKey = [];
        foreach ($runs as $run) {
            $runsByKey[$run->site.'|'.$run->system][] = $run;
        }

        $sites = [];
        $summary = array_fill_keys(array_keys(self::STATE_LABELS), 0);
        foreach ($grouped as $site => $systems) {
            $row = ['site' => $site, 'systems' => []];
            foreach (self::SYSTEMS as $key => $sys) {
                if (empty($systems[$key])) {
                    continue;
                }
                $cell = self::cell($key, $systems[$key], $runsByKey[$site.'|'.$key] ?? [], $history[$site.'|'.$key] ?? null, $today, $openProgress);
                $row['systems'][$key] = $cell;
                $summary[$cell['state']]++;
            }
            $sites[$site] = $row;
        }

        return ['sites' => $sites, 'summary' => $summary];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $halls
     * @param  list<object>  $runs
     * @param  array<int, array{items: int, completed: int}>  $openProgress
     * @return array<string, mixed>
     */
    public static function cell(string $system, array $halls, array $runs, ?Carbon $historyDate, Carbon $today, array $openProgress = []): array
    {
        $cfg = self::SYSTEMS[$system];
        usort($runs, fn ($a, $b) => $a->id <=> $b->id);

        $open = null;
        $lastClosed = null;
        foreach ($runs as $run) {
            if ($run->status === self::STATUS_OPEN) {
                $open = $run;
            } else {
                $lastClosed = $run;
            }
        }

        // Base date of the cycle.
        $base = null;
        $lastKind = null;
        $lastGood = null;
        if ($lastClosed) {
            if ($lastClosed->status === self::STATUS_DONE) {
                $base = Carbon::parse($lastClosed->completed_at ?? $lastClosed->started_at);
                $lastKind = 'done';
                $lastGood = $lastClosed->result_good === null ? null : (bool) $lastClosed->result_good;
            } else {
                $base = Carbon::parse($lastClosed->due_date ?? $lastClosed->completed_at);
                $lastKind = 'skipped';
            }
        }
        if ($historyDate && (! $base || $historyDate->gt($base)) && ! $lastClosed) {
            $base = $historyDate->copy();
            $lastKind = 'history';
        }

        $due = $base ? $base->copy()->startOfDay()->addMonthsNoOverflow($cfg['months']) : null;
        $days = $due ? (int) floor(($due->getTimestamp() - $today->copy()->startOfDay()->getTimestamp()) / 86400) : null;

        if ($open) {
            $state = 'open';
        } elseif ($due === null) {
            $state = 'never';
        } elseif ($days < 0) {
            $state = 'overdue';
        } elseif ($days <= self::DUE_SOON_DAYS || $due->format('Y-m') === $today->format('Y-m')) {
            $state = 'due';
        } else {
            $state = 'ok';
        }

        $hallNames = array_values(array_filter(array_keys($halls), fn ($h) => $h !== ''));
        $deviceCount = 0;
        foreach ($halls as $list) {
            $deviceCount += count($list);
        }

        return [
            'system' => $system,
            'label' => $cfg['label'],
            'months' => $cfg['months'],
            'halls' => $hallNames,
            'hall_devices' => $halls,
            'device_count' => $deviceCount,
            'no_hall_count' => count($halls[''] ?? []),
            'last_date' => $base,
            'last_kind' => $lastKind,
            'last_good' => $lastGood,
            'last_note' => $lastClosed?->note,
            'due' => $due,
            'days' => $days,
            'state' => $state,
            'open_run' => $open ? [
                'id' => (int) $open->id,
                'started_at' => $open->started_at ? Carbon::parse($open->started_at) : null,
                'items' => $openProgress[(int) $open->id]['items'] ?? 0,
                'completed' => $openProgress[(int) $open->id]['completed'] ?? 0,
            ] : null,
            // Skip only right after a cycle that was done and found good.
            'can_skip' => $cfg['skippable'] && ! $open && $lastKind === 'done' && $lastGood === true
                && in_array($state, ['due', 'overdue'], true),
        ];
    }

    public static function dueLabel(?int $days): string
    {
        if ($days === null) {
            return '';
        }
        if ($days < 0) {
            return 'Trễ '.(-$days).' ngày';
        }
        if ($days === 0) {
            return 'Hôm nay';
        }

        return 'Còn '.$days.' ngày';
    }

    /**
     * Latest "bảo trì / bảo dưỡng" maintenance (by completion, else start)
     * of each cinema × system, from maintenances entered before the plan.
     *
     * @param  list<array<string, mixed>>  $devices
     * @return array<string, Carbon>
     */
    public function history(array $devices): array
    {
        $keyOf = [];
        foreach ($devices as $d) {
            $keyOf[$d['id']] = $d['site'].'|'.$d['system'];
        }
        $out = [];
        foreach (array_chunk(array_keys($keyOf), 1000) as $chunk) {
            Maintenance::query()
                ->where('item_type', Asset::class)
                ->whereIn('item_id', $chunk)
                ->with('maintenanceType:id,name')
                ->get(['id', 'item_id', 'item_type', 'name', 'start_date', 'completed_at', 'maintenance_type_id'])
                ->each(function (Maintenance $m) use (&$out, $keyOf) {
                    if (! IncidentStatsService::isMaintenance(['type' => (string) ($m->maintenanceType?->name ?? ''), 'name' => (string) $m->name])) {
                        return;
                    }
                    $date = $m->completed_at ?? $m->start_date;
                    if (! $date) {
                        return;
                    }
                    $date = Carbon::parse($date);
                    $key = $keyOf[(int) $m->item_id];
                    if (! isset($out[$key]) || $date->gt($out[$key])) {
                        $out[$key] = $date;
                    }
                });
        }

        return $out;
    }

    /** @return list<object> */
    public function runs(): array
    {
        return self::ready() ? DB::table(self::RUNS)->orderBy('id')->get()->all() : [];
    }

    /** @return array<int, array{items: int, completed: int}> */
    public function openProgress(): array
    {
        if (! self::ready()) {
            return [];
        }
        $out = [];
        $rows = DB::table(self::ITEMS.' as i')
            ->join(self::RUNS.' as r', 'r.id', '=', 'i.run_id')
            ->leftJoin('maintenances as m', 'm.id', '=', 'i.maintenance_id')
            ->where('r.status', self::STATUS_OPEN)
            ->get(['i.run_id', 'm.completed_at', 'm.deleted_at']);
        foreach ($rows as $row) {
            $id = (int) $row->run_id;
            $out[$id] ??= ['items' => 0, 'completed' => 0];
            if ($row->deleted_at !== null) {
                continue;
            }
            $out[$id]['items']++;
            $out[$id]['completed'] += $row->completed_at !== null ? 1 : 0;
        }

        return $out;
    }

    /** Everything the page needs. */
    public function load(Carbon $today): array
    {
        $devices = $this->devices();

        return [
            'devices' => $devices,
            'runs' => $runs = $this->runs(),
            'board' => self::board($devices, $runs, $this->history($devices), $today, $this->openProgress()),
        ];
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    /**
     * Start a run: one maintenance per hall of the cinema for this system.
     *
     * @return array{run_id: int, created: int, skipped_no_hall: int}
     */
    public function start(string $site, string $system, Carbon $date, ?int $userId, ?string $note = null): array
    {
        $this->assertReady();
        if (! isset(self::SYSTEMS[$system])) {
            throw new RuntimeException('Hệ không hợp lệ.');
        }
        $cfg = self::SYSTEMS[$system];

        $devices = array_values(array_filter($this->devices(), fn ($d) => $d['site'] === $site && $d['system'] === $system));
        $halls = self::groupDevices($devices)[$site][$system] ?? [];
        $noHall = count($halls[''] ?? []);
        unset($halls['']);
        if (! $halls) {
            throw new RuntimeException("Rạp {$site} chưa có thiết bị {$cfg['label']} nào gán vào Hall.");
        }

        $existing = DB::table(self::RUNS)->where('site', $site)->where('system', $system)->where('status', self::STATUS_OPEN)->first();
        if ($existing) {
            throw new RuntimeException("{$cfg['label']} ở {$site} đang có đợt bảo trì chưa hoàn thành.");
        }

        $cell = self::cell($system, $halls, DB::table(self::RUNS)->where('site', $site)->where('system', $system)->get()->all(),
            $this->history($devices)[$site.'|'.$system] ?? null, $date);
        $typeId = self::maintenanceTypeId();

        return DB::transaction(function () use ($site, $system, $cfg, $halls, $date, $userId, $note, $cell, $typeId, $noHall) {
            $now = Carbon::now();
            $runId = (int) DB::table(self::RUNS)->insertGetId([
                'site' => $site,
                'system' => $system,
                'status' => self::STATUS_OPEN,
                'due_date' => ($cell['due'] ?? $date)->format('Y-m-d'),
                'started_at' => $date->format('Y-m-d H:i:s'),
                'note' => $note,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $created = 0;
            foreach ($halls as $hall => $list) {
                $rep = $list[0];
                $m = new Maintenance;
                $m->asset_id = $rep['id'];
                $m->name = mb_substr(self::maintenanceName($cfg['label'], $site, (string) $hall, $date), 0, 100);
                $m->maintenance_type_id = $typeId;
                $m->start_date = $date->format('Y-m-d H:i:s');
                $m->responsible_party_id = $userId;
                $m->created_by = $userId;
                $m->is_warranty = 0;
                $m->url = self::deviceText($list);
                if (! $m->save()) {
                    throw new RuntimeException('Không tạo được phiếu cho Hall '.$hall.': '.implode(' ', $m->getErrors()->all()));
                }

                DB::table(self::ITEMS)->insert([
                    'run_id' => $runId,
                    'maintenance_id' => (int) $m->id,
                    'hall' => (string) $hall,
                    'devices' => json_encode(array_map(fn ($d) => [
                        'asset_id' => $d['id'],
                        'role' => $d['role'],
                        'name' => $d['name'],
                        'model' => $d['model'],
                        'model_number' => $d['model_number'],
                        'serial' => $d['serial'],
                    ], $list), JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created++;
            }

            return ['run_id' => $runId, 'created' => $created, 'skipped_no_hall' => $noHall];
        });
    }

    /**
     * Finish a run: set completion on every maintenance still open and
     * record which halls were found good.
     *
     * @param  list<int>  $goodItemIds  ids of maintenance_plan_items found good
     */
    public function complete(int $runId, array $goodItemIds, ?string $note, ?int $userId): void
    {
        $this->assertReady();
        $run = DB::table(self::RUNS)->where('id', $runId)->first();
        if (! $run || $run->status !== self::STATUS_OPEN) {
            throw new RuntimeException('Đợt bảo trì không tồn tại hoặc đã đóng.');
        }

        $now = Carbon::now();
        $items = DB::table(self::ITEMS)->where('run_id', $runId)->get();
        $allGood = true;

        DB::transaction(function () use ($items, $goodItemIds, $now, $userId, $runId, $note, &$allGood) {
            foreach ($items as $item) {
                $good = in_array((int) $item->id, $goodItemIds, true);
                $allGood = $allGood && $good;
                DB::table(self::ITEMS)->where('id', $item->id)->update(['good' => $good, 'updated_at' => $now]);

                $m = Maintenance::find($item->maintenance_id);
                if (! $m || $m->completed_at) {
                    continue;
                }
                $start = $m->start_date ? Carbon::parse($m->start_date) : $now;
                $m->completed_at = ($start->gt($now) ? $start : $now)->format('Y-m-d H:i:s');
                $m->completed_by = $userId;
                $m->asset_maintenance_time = (int) $start->diffInDays(Carbon::parse($m->completed_at), true);
                if (! $m->save()) {
                    throw new RuntimeException('Không cập nhật được phiếu #'.$m->id.': '.implode(' ', $m->getErrors()->all()));
                }
                self::logComplete($m, $userId);
            }

            DB::table(self::RUNS)->where('id', $runId)->update([
                'status' => self::STATUS_DONE,
                'completed_at' => $now,
                'completed_by' => $userId,
                'result_good' => $allGood,
                'note' => $note,
                'updated_at' => $now,
            ]);
        });
    }

    /** Skip this cycle (only allowed right after a good one). */
    public function skip(string $site, string $system, ?int $userId, ?string $note, Carbon $today): void
    {
        $this->assertReady();
        $data = $this->load($today);
        $cell = $data['board']['sites'][$site]['systems'][$system] ?? null;
        if (! $cell || ! $cell['can_skip']) {
            throw new RuntimeException('Kỳ này không bỏ qua được (chỉ bỏ qua khi kỳ trước đã làm và hệ thống tốt).');
        }
        $now = Carbon::now();
        DB::table(self::RUNS)->insert([
            'site' => $site,
            'system' => $system,
            'status' => self::STATUS_SKIPPED,
            'due_date' => $cell['due']->format('Y-m-d'),
            'completed_at' => $now,
            'completed_by' => $userId,
            'note' => $note ?: 'Bỏ qua: kỳ trước hệ thống tốt',
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** Cancel an open run started by mistake: deletes its unfinished maintenances. */
    public function cancel(int $runId): void
    {
        $this->assertReady();
        $run = DB::table(self::RUNS)->where('id', $runId)->first();
        if (! $run || $run->status !== self::STATUS_OPEN) {
            throw new RuntimeException('Chỉ hủy được đợt đang thực hiện.');
        }
        $items = DB::table(self::ITEMS)->where('run_id', $runId)->get();
        foreach ($items as $item) {
            $m = Maintenance::find($item->maintenance_id);
            if ($m && $m->completed_at) {
                throw new RuntimeException('Đã có phiếu hoàn thành (#'.$m->id.'), không hủy được. Hãy bấm Hoàn thành.');
            }
        }
        DB::transaction(function () use ($items, $runId) {
            foreach ($items as $item) {
                Maintenance::find($item->maintenance_id)?->delete();
            }
            DB::table(self::ITEMS)->where('run_id', $runId)->delete();
            DB::table(self::RUNS)->where('id', $runId)->delete();
        });
    }

    /**
     * One run with its halls / maintenances (for the run page).
     *
     * @return array<string, mixed>|null
     */
    public function runDetail(int $runId): ?array
    {
        if (! self::ready()) {
            return null;
        }
        $run = DB::table(self::RUNS)->where('id', $runId)->first();
        if (! $run) {
            return null;
        }
        $items = [];
        foreach (DB::table(self::ITEMS)->where('run_id', $runId)->get() as $item) {
            $m = Maintenance::withTrashed()->find($item->maintenance_id);
            $items[] = [
                'id' => (int) $item->id,
                'hall' => (string) $item->hall,
                'good' => $item->good === null ? null : (bool) $item->good,
                'devices' => json_decode((string) $item->devices, true) ?: [],
                'levels' => json_decode((string) ($item->levels ?? ''), true) ?: [],
                'checklist' => json_decode((string) ($item->checklist ?? ''), true) ?: [],
                'maintenance_id' => (int) $item->maintenance_id,
                'maintenance_name' => (string) ($m?->name ?? ''),
                'deleted' => $m === null || $m->trashed(),
                'completed_at' => $m?->completed_at ? Carbon::parse($m->completed_at) : null,
            ];
        }
        usort($items, fn ($a, $b) => strnatcasecmp($a['hall'], $b['hall']));

        return [
            'run' => $run,
            'system' => self::SYSTEMS[$run->system] ?? ['label' => $run->system, 'months' => 0],
            'items' => $items,
            // B-chain inputs only for the sound system.
            'withLevels' => $run->system === 'sound' && self::levelsReady(),
            'withChecklist' => self::checklistReady(),
        ];
    }

    /**
     * Plan info for one maintenance (used by the Service Report), or null
     * when it was not created by the plan.
     *
     * @return array{run: object, item: object, devices: list<array<string, mixed>>, system: array<string, mixed>}|null
     */
    public static function forMaintenance(int $maintenanceId): ?array
    {
        if (! self::ready()) {
            return null;
        }
        try {
            $item = DB::table(self::ITEMS)->where('maintenance_id', $maintenanceId)->first();
            if (! $item) {
                return null;
            }
            $run = DB::table(self::RUNS)->where('id', $item->run_id)->first();
            if (! $run) {
                return null;
            }

            return [
                'run' => $run,
                'item' => $item,
                'devices' => json_decode((string) $item->devices, true) ?: [],
                'levels' => json_decode((string) ($item->levels ?? ''), true) ?: [],
                'checklist' => json_decode((string) ($item->checklist ?? ''), true) ?: [],
                'system' => self::SYSTEMS[$run->system] ?? ['label' => (string) $run->system, 'months' => 0, 'roles' => []],
            ];
        } catch (\Throwable $e) {
            Log::warning('Maintenance plan lookup failed: '.$e->getMessage());

            return null;
        }
    }

    // ------------------------------------------------------------------
    // Year calendar
    // ------------------------------------------------------------------

    /**
     * Per cinema × system × month: what happened / is planned.
     * Cell kinds: done, skipped, open, planned, overdue.
     *
     * @param  array<string, mixed>  $board
     * @param  list<object>  $runs
     * @return list<array{site: string, system: string, label: string, months: array<int, list<string>>}>
     */
    public static function calendar(array $board, array $runs, int $year, Carbon $today): array
    {
        $byKey = [];
        foreach ($runs as $run) {
            $byKey[$run->site.'|'.$run->system][] = $run;
        }

        $out = [];
        foreach ($board['sites'] as $site => $row) {
            foreach ($row['systems'] as $sys => $cell) {
                $months = array_fill(1, 12, []);
                foreach ($byKey[$site.'|'.$sys] ?? [] as $run) {
                    $date = match ($run->status) {
                        self::STATUS_DONE => $run->completed_at,
                        self::STATUS_SKIPPED => $run->due_date ?? $run->completed_at,
                        default => $run->started_at,
                    };
                    if (! $date) {
                        continue;
                    }
                    $d = Carbon::parse($date);
                    if ((int) $d->format('Y') === $year) {
                        $months[(int) $d->format('n')][] = $run->status === self::STATUS_OPEN ? 'open' : $run->status;
                    }
                }

                // Projection of the next cycles.
                $p = $cell['due']?->copy();
                $cycle = self::SYSTEMS[$sys]['months'];
                if ($cell['state'] === 'never') {
                    $p = $today->copy();
                }
                if ($p !== null && $cell['state'] !== 'open') {
                    if ($p->lt($today->copy()->startOfDay())) {
                        if ((int) $today->format('Y') === $year) {
                            $months[(int) $today->format('n')][] = 'overdue';
                        }
                        $p = $today->copy()->addMonthsNoOverflow($cycle);
                    } elseif ($cell['state'] === 'never' && (int) $today->format('Y') === $year) {
                        $months[(int) $today->format('n')][] = 'overdue';
                        $p = $today->copy()->addMonthsNoOverflow($cycle);
                    }
                    for ($i = 0; $i < 12 && (int) $p->format('Y') <= $year; $i++) {
                        if ((int) $p->format('Y') === $year) {
                            $months[(int) $p->format('n')][] = 'planned';
                        }
                        $p = $p->copy()->addMonthsNoOverflow($cycle);
                    }
                } elseif ($cell['state'] === 'open' && $cell['open_run']['started_at']) {
                    $p = $cell['open_run']['started_at']->copy()->addMonthsNoOverflow($cycle);
                    for ($i = 0; $i < 12 && (int) $p->format('Y') <= $year; $i++) {
                        if ((int) $p->format('Y') === $year) {
                            $months[(int) $p->format('n')][] = 'planned';
                        }
                        $p = $p->copy()->addMonthsNoOverflow($cycle);
                    }
                }

                $out[] = ['site' => $site, 'system' => $sys, 'label' => self::SYSTEMS[$sys]['short'], 'months' => $months];
            }
        }

        return $out;
    }

    /**
     * Excel: "Tình trạng" (board) + "Lịch năm".
     *
     * @param  array<string, mixed>  $board
     * @param  list<array<string, mixed>>  $calendar
     */
    public static function export(array $board, array $calendar, int $year, string $path): string
    {
        $rows = [];
        $styles = [];
        foreach ($board['sites'] as $site => $row) {
            foreach ($row['systems'] as $cell) {
                $rows[] = [
                    count($rows) + 1,
                    $site,
                    $cell['label'],
                    count($cell['halls']),
                    $cell['device_count'],
                    $cell['last_date'] instanceof Carbon ? $cell['last_date']->format('d/m/Y') : '',
                    match ($cell['last_kind']) {
                        'done' => $cell['last_good'] === false ? 'Đã làm – có vấn đề' : 'Đã làm – tốt',
                        'skipped' => 'Bỏ qua',
                        'history' => 'Theo phiếu cũ',
                        default => '',
                    },
                    $cell['due'] instanceof Carbon ? $cell['due']->format('d/m/Y') : '',
                    self::STATE_LABELS[$cell['state']] ?? '',
                    self::dueLabel($cell['days']),
                    $cell['can_skip'] ? 'Có thể bỏ qua kỳ này' : '',
                ];
                if ($cell['state'] === 'overdue') {
                    $styles[count($rows) - 1] = 'red';
                }
            }
        }

        $marks = ['done' => '✓ Đã làm', 'skipped' => 'Bỏ qua', 'open' => 'Đang làm', 'planned' => 'Kế hoạch', 'overdue' => 'Quá hạn'];
        $cal = [];
        $calStyles = [];
        foreach ($calendar as $c) {
            $line = [$c['site'], $c['label']];
            $late = false;
            for ($m = 1; $m <= 12; $m++) {
                $line[] = implode(', ', array_map(fn ($k) => $marks[$k] ?? $k, array_unique($c['months'][$m])));
                $late = $late || in_array('overdue', $c['months'][$m], true);
            }
            $cal[] = $line;
            if ($late) {
                $calStyles[count($cal) - 1] = 'red';
            }
        }

        $w = new SimpleXlsxWriter;
        $w->addSheet('Tình trạng', ['STT', 'Rạp', 'Hệ', 'Số phòng', 'Số thiết bị', 'Lần gần nhất', 'Kết quả', 'Đến hạn', 'Tình trạng', 'Thời gian', 'Ghi chú'], $rows, [
            'title' => 'LỊCH BẢO TRÌ – TÌNH TRẠNG THEO RẠP',
            'subtitle' => 'Máy chiếu 3 tháng/lần (được bỏ qua 1 kỳ nếu kỳ trước tốt) · Âm thanh 6 tháng/lần  |  Xuất lúc '.date('d/m/Y H:i'),
            'widths' => [5, 24, 14, 9, 10, 12, 18, 12, 15, 14, 20],
            'rowStyles' => $styles,
        ]);
        $headers = ['Rạp', 'Hệ'];
        for ($m = 1; $m <= 12; $m++) {
            $headers[] = sprintf('%02d/%d', $m, $year);
        }
        $w->addSheet('Lịch năm '.$year, $headers, $cal, [
            'title' => 'LỊCH BẢO TRÌ NĂM '.$year,
            'subtitle' => 'Kế hoạch tính từ lần gần nhất + chu kỳ',
            'widths' => [24, 11, 11, 11, 11, 11, 11, 11, 11, 11, 11, 11, 11, 11],
            'rowStyles' => $calStyles,
        ]);

        return $w->save($path);
    }

    // ------------------------------------------------------------------

    public static function maintenanceName(string $systemLabel, string $site, string $hall, Carbon $date): string
    {
        return 'Bảo trì định kỳ '.mb_strtolower($systemLabel).' – '.$site.' – Hall '.$hall.' – '.$date->format('m/Y');
    }

    /** @param list<array<string, mixed>> $devices */
    public static function deviceText(array $devices): string
    {
        $lines = [];
        foreach ($devices as $d) {
            $label = self::ROLE_LABELS[$d['role']] ?? $d['role'];
            $model = MaintenanceFormExporter::modelLabel($d);
            $lines[] = trim($label.': '.$model.($d['serial'] !== '' ? ' – SN '.$d['serial'] : '').' ('.$d['name'].')');
        }

        return implode("\n", $lines);
    }

    /** Maintenance type for planned work: the one named like maintenance / bảo trì, else the first. */
    public static function maintenanceTypeId(): int
    {
        $types = MaintenanceType::query()->orderBy('id')->get(['id', 'name']);
        foreach ($types as $t) {
            if (preg_match('/\b(maintenance|bao tri|bao duong|dinh ky|preventive)\b/', Str::lower(Str::ascii((string) $t->name)))) {
                return (int) $t->id;
            }
        }
        if ($types->isEmpty()) {
            throw new RuntimeException('Chưa có Maintenance Type nào trong Snipe-IT.');
        }

        return (int) $types->first()->id;
    }

    private static function logComplete(Maintenance $m, ?int $userId): void
    {
        try {
            $log = new Actionlog;
            $log->item_type = Maintenance::class;
            $log->item_id = $m->id;
            $log->target_type = Asset::class;
            $log->target_id = $m->asset_id;
            $log->created_by = $userId;
            $log->logaction(ActionType::MaintenanceComplete);
        } catch (\Throwable $e) {
            Log::warning('Maintenance plan: could not log completion of #'.$m->id.': '.$e->getMessage());
        }
    }

    private function assertReady(): void
    {
        if (! self::ready()) {
            throw new RuntimeException('Chưa chạy migrate cho Lịch bảo trì trên server.');
        }
    }
}

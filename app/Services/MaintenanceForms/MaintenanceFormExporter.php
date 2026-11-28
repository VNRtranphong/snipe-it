<?php

namespace App\Services\MaintenanceForms;

use App\Models\Asset;
use App\Models\Location;
use App\Models\Maintenance;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Builds the two Galaxy Cinema forms from a maintenance record:
 *  - "Biên bản sự cố kỹ thuật" (sheet "Biên bản sự cố")
 *  - "Maintenance/Services Report - Projection" (sheet "Report")
 *
 * Both live in one template workbook: resources/templates/maintenances/Form_BBSC.xlsx.
 * To change the layout, edit that file in Excel – but keep the cell
 * positions used below (or update the constants here).
 */
class MaintenanceFormExporter
{
    public const SHEET_INCIDENT = 'Biên bản sự cố';

    public const SHEET_SERVICE = 'Report';

    /**
     * Equipment checkboxes on the incident sheet, checked in this order.
     * The first pattern that matches the asset's category (then model /
     * asset name) is ticked. Order matters: "máy phát điện" must be
     * tested before "điện", "xử lý nước thải" before "nước".
     *
     * @var array<string, string> cell => regex on ascii-lowercased text
     */
    private const EQUIPMENT_BOXES = [
        'A5' => '/projector|may chieu/',
        'C5' => '/server|\bimb\b|\bims\b/',
        'E5' => '/processor|\bcp\d{3}\b/',
        'G5' => '/amplifier|\bamp\b|\bampli\b/',
        'I5' => '/speaker|\bloa\b/',
        'K5' => '/\btms\b/',
        'A6' => '/may lanh|dieu hoa|air ?con/',
        'C6' => '/may phat|generator/',
        'E6' => '/\bups\b/',
        'G6' => '/cctv|camera/',
        'I6' => '/\btv\b|\bled\b|man hinh/',
        'C7' => '/xlnt|xu ly nuoc thai/',
        'K6' => '/\bdien\b/',
        'A7' => '/\bnuoc\b/',
        'E7' => '/csvc|co so vat chat/',
        'G7' => '/xe bap/',
        'I7' => '/ham bap/',
    ];

    private const BOX_OTHER = 'K7';

    /** Human label for each equipment checkbox (same wording as the form). */
    public const EQUIPMENT_LABELS = [
        'A5' => 'Projector', 'C5' => 'Server', 'E5' => 'Processor',
        'G5' => 'Amplifier', 'I5' => 'Speaker', 'K5' => 'TMS',
        'A6' => 'Máy lạnh', 'C6' => 'Máy phát điện', 'E6' => 'UPS tổng',
        'G6' => 'CCTV', 'I6' => 'TV - LED', 'K6' => 'Điện',
        'A7' => 'Nước', 'C7' => 'XLNT', 'E7' => 'CSVC',
        'G7' => 'Xe bắp', 'I7' => 'Hâm bắp', 'K7' => 'Khác',
    ];

    /** Font size of the "Serial" line in the Report header boxes (template uses 18). */
    private const SERIAL_FONT_SIZE = 12;

    public function __construct(private ?string $templatePath = null)
    {
        $this->templatePath ??= resource_path('templates/maintenances/Form_BBSC.xlsx');
    }

    /**
     * @param  string|null  $number  official number from IncidentDocumentService (e.g. "HTP-03.12/01/2026-BBSC")
     * @return array{0: string, 1: string} [temp file path, download filename]
     */
    public function incidentReport(Maintenance $maintenance, ?string $number = null, ?array $place = null): array
    {
        $data = self::withPlace(self::collect($maintenance), $place);
        $data['doc_number'] = $number;
        $path = $this->tempPath();
        $this->fillIncident($data, $path);

        return [$path, $this->fileName('BBSC', $data)];
    }

    /** @return array{0: string, 1: string} [temp file path, download filename] */
    public function serviceReport(Maintenance $maintenance, ?array $place = null): array
    {
        $data = self::withPlace(self::collect($maintenance), $place);
        $path = $this->tempPath();

        // Periodic maintenance from "Lịch bảo trì": one report for the whole hall system.
        $plan = MaintenancePlanService::forMaintenance((int) $maintenance->id);
        if ($plan !== null) {
            $data['site'] = (string) $plan['run']->site;
            $data['hall'] = (string) $plan['item']->hall;
            $this->fillMaintenanceService($data, $plan, $path);

            return [$path, $this->maintenanceFileName($data, $plan)];
        }

        $this->fillService($data, $path);

        return [$path, $this->fileName('ServiceReport', $data)];
    }

    /**
     * Use the cinema / hall remembered for this incident (see
     * IncidentDocumentService) instead of where the asset is now.
     *
     * @param  array<string, mixed>  $d
     * @param  array{site?: string|null, hall?: string|null}|null  $place
     * @return array<string, mixed>
     */
    public static function withPlace(array $d, ?array $place): array
    {
        if ($place && ($place['site'] ?? '') !== '') {
            $d['site'] = (string) $place['site'];
            $d['hall'] = (string) ($place['hall'] ?? '');
            $d['hall_guessed'] = false;
            $d['place_locked'] = true;
        }

        return $d;
    }

    // ------------------------------------------------------------------
    // Data gathering
    // ------------------------------------------------------------------

    /**
     * Flatten everything the forms need into plain strings.
     *
     * Field meaning in this install (see MaintenancesPresenter):
     *  - `url`   column is labelled "Ghi chú"      => incident description
     *  - `notes` column is labelled "Số suất hủy tính cho Tech" => cancelled shows (Tech)
     *  - `cancelled_shows_cinema` = "Số suất hủy tính cho Rạp"   => kept apart, never in Tech totals
     *
     * @return array<string, mixed>
     */
    /**
     * BBSC "Số xuất chiếu ảnh hưởng/ hủy": the Tech number as before; when the
     * cinema also has cancelled shows, both are written on separate lines.
     *
     * @param  array<string, mixed>  $d
     */
    public static function cancelledText(array $d): string
    {
        $tech = trim((string) ($d['cancelled_shows'] ?? ''));
        $cinema = IncidentReportRepository::cancelledCount((string) ($d['cancelled_cinema'] ?? ''));
        if ($cinema <= 0) {
            return $tech;
        }

        return 'Tech: '.($tech !== '' ? $tech : '0')."\nRạp: ".$cinema;
    }

    public static function collect(Maintenance $maintenance): array
    {
        $asset = $maintenance->asset;
        if (! $asset && $maintenance->item instanceof Asset) {
            $asset = $maintenance->item;
        }

        [$site, $hall, $hallGuessed] = self::siteAndHall($asset);

        $technician = $maintenance->responsibleParty?->display_name
            ?? $maintenance->adminuser?->display_name
            ?? '';

        return [
            'id' => (int) $maintenance->id,
            'name' => (string) $maintenance->name,
            'description' => trim((string) $maintenance->url),
            'cancelled_shows' => trim(strip_tags((string) $maintenance->notes)),
            'cancelled_cinema' => trim((string) ($maintenance->cancelled_shows_cinema ?? '')),
            'type' => (string) ($maintenance->maintenanceType?->name ?? ''),
            'is_warranty' => (bool) $maintenance->is_warranty,
            'supplier' => (string) ($maintenance->supplier?->name ?? ''),
            'start_date' => self::date($maintenance->start_date),
            'expected_date' => self::date($maintenance->expected_completion_date),
            'completed_at' => self::date($maintenance->completed_at),
            'technician' => (string) $technician,
            'asset_name' => (string) ($asset?->name ?? ''),
            'asset_tag' => (string) ($asset?->asset_tag ?? ''),
            'asset_id' => $asset?->id,
            'model_id' => $asset?->model_id,
            'category_id' => $asset?->model?->category_id,
            'serial' => (string) ($asset?->serial ?? ''),
            'model' => (string) ($asset?->model?->name ?? ''),
            'model_number' => (string) ($asset?->model?->model_number ?? ''),
            'category' => (string) ($asset?->model?->category?->name ?? ''),
            'purchase_date' => self::date($asset?->purchase_date),
            'site' => $site,
            'hall' => $hall,
            'hall_guessed' => $hallGuessed,
            // Raw timestamps for duration / filtering (not used by the forms).
            'start_at' => $maintenance->start_date ? Carbon::parse($maintenance->start_date) : null,
            'completed_at_dt' => $maintenance->completed_at ? Carbon::parse($maintenance->completed_at) : null,
        ];
    }

    /**
     * Site = the cinema (e.g. "Huỳnh Tấn Phát"), Hall = the auditorium.
     * Assets are usually checked out to a "Hall N" location whose parent
     * (or the asset's own location) is the site.
     *
     * @return array{0: string, 1: string}
     */
    public static function siteAndHall(?Asset $asset): array
    {
        if (! $asset) {
            return ['', '', false];
        }

        $isHall = fn (?Location $l) => $l && preg_match('/\b(hall|phong chieu)\b/i', Str::ascii($l->name));

        $candidates = array_filter([
            $asset->assignedTo instanceof Location ? $asset->assignedTo : null,
            $asset->location,
            $asset->defaultLoc,
        ]);

        $hallLoc = null;
        $siteLoc = null;
        foreach ($candidates as $loc) {
            if ($isHall($loc)) {
                $hallLoc ??= $loc;
            } else {
                $siteLoc ??= $loc;
            }
        }
        if (! $siteLoc && $hallLoc?->parent) {
            $siteLoc = $hallLoc->parent;
        }

        $hall = $hallLoc ? trim(preg_replace('/^\s*hall\s*/i', '', $hallLoc->name)) : '';
        $site = $siteLoc?->name ?? ($asset->company?->name ?? '');

        // Not in a "Hall" location: guess from the asset name ("HUẾ Projector Hall1").
        $guessed = false;
        if ($hall === '') {
            $fromName = self::hallFromName((string) $asset->name);
            if ($fromName !== null) {
                $hall = $fromName;
                $guessed = true;
            }
        }

        return [(string) $site, $hall, $guessed];
    }

    /**
     * Hall number written in an asset name, or null.
     *   "HUẾ Projector Hall1"   => "1"
     *   "HL PROJECTOR HALL 7"   => "7"
     *   "TK Server Hall_03"     => "3"
     *   "Loa phòng chiếu 2"     => "2"
     *   "Screen 4 Processor"    => "4"
     */
    public static function hallFromName(string $name): ?string
    {
        $ascii = Str::lower(Str::ascii($name));
        if (preg_match('/(?:^|[^a-z])(?:hall|phong chieu|phong|screen|aud(?:itorium)?)\s*[-_#.:]?\s*0*(\d{1,2})(?!\d)/', $ascii, $m)) {
            return $m[1] === '' ? null : $m[1];
        }

        return null;
    }

    private static function date(mixed $value): string
    {
        if (empty($value)) {
            return '';
        }

        try {
            return Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    // ------------------------------------------------------------------
    // Filling the sheets (plain arrays in, so this part is unit-testable)
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $d */
    public function fillIncident(array $d, string $outputPath): string
    {
        $s = self::SHEET_INCIDENT;
        $x = new XlsxTemplateFiller($this->templatePath, $outputPath);

        $x->setCell($s, 'A3', 'Số: '.(! empty($d['doc_number']) ? $d['doc_number'] : self::draftNumber($d)));

        // I. Thiết bị
        $x->tick($s, self::equipmentBox($d));
        $x->setCell($s, 'A9', 'Tên thiết bị: '.$d['asset_name']);
        $x->setCell($s, 'G9', 'Model: '.self::modelLabel($d));
        $x->setCell($s, 'A10', 'Serial No: '.$d['serial']);
        $x->setCell($s, 'G10', 'Thời gian đưa vào sử dụng: '.$d['purchase_date']);
        $x->setCell($s, 'A11', 'Site: '.$d['site']);
        $x->setCell($s, 'G11', 'Hall: '.$d['hall']);

        // II. Thông tin sự cố
        $description = 'Ngày '.$d['start_date'].': '.$d['name'];
        if ($d['description'] !== '') {
            $description .= "\n".$d['description'];
        }
        $x->setCell($s, 'B14', $description);
        $x->setCell($s, 'H14', self::cancelledText($d));
        $x->setCell($s, 'J14', ''); // Ghi chú: để trống cho ghi tay

        // Grow the row when the description is long (template row = 84.6pt ≈ 5 lines).
        $lines = self::estimateLines($description, 60);
        if ($lines > 5) {
            $x->setRowHeight($s, 14, min(409, $lines * 16.5));
        }

        // III. Phương án xử lý – tình trạng
        if ($d['is_warranty']) {
            $x->tick($s, 'A19');
        }
        if ($d['completed_at'] !== '') {
            $x->tick($s, 'F17');
        }
        $x->setCell($s, 'J17', $d['completed_at'] !== '' ? 'Hoàn thành '.$d['completed_at'] : $d['expected_date']);

        $x->showOnlySheet($s);

        return $x->save();
    }

    /** @param array<string, mixed> $d */
    /**
     * Service Report of a periodic maintenance ("Lịch bảo trì"): every device
     * of the hall system in its box (Projector / Server / Sound), extra
     * devices (3D, amplifier …) and the result in "B: Công việc".
     *
     * @param  array<string, mixed>  $d
     * @param  array{run: object, item: object, devices: list<array<string, mixed>>, system: array<string, mixed>}  $plan
     */
    public function fillMaintenanceService(array $d, array $plan, string $outputPath): string
    {
        $s = self::SHEET_SERVICE;
        $x = new XlsxTemplateFiller($this->templatePath, $outputPath);
        $isSound = ($plan['run']->system ?? '') === 'sound';

        $x->setCell($s, 'A1', 'Maintenance/Services Report - '.($isSound ? 'Sound' : 'Projection'));
        $x->setCell($s, 'A2', 'Họ và Tên :  '.$d['technician']);
        $x->setCell($s, 'A3', 'Bộ Phận    :  Kỹ Thuật');
        $x->setCell($s, 'I2', ' Site công tác : '.$d['site']);
        $x->setCell($s, 'I3', ' Ngày công tác : '.$d['start_date']);
        $x->setCell($s, 'G4', 'Hall :  '.$d['hall']);

        $byRole = [];
        foreach ($plan['devices'] as $dev) {
            $dev += ['model' => '', 'model_number' => '', 'serial' => '', 'name' => ''];
            $byRole[$dev['role']][] = $dev;
        }
        $label = fn (array $dev) => self::modelLabel($dev);

        $extra = [];
        foreach ($byRole as $role => $list) {
            foreach ($list as $i => $dev) {
                if ($i === 0 && $role === 'projector') {
                    $x->setRuns($s, 'B6', ['Projector : '.$label($dev), "\nSerial : ".$dev['serial']], [1 => self::SERIAL_FONT_SIZE]);
                } elseif ($i === 0 && $role === 'server') {
                    $x->setRuns($s, 'E6', ['Server: '.$label($dev), "\nSerial: ".$dev['serial']], [1 => self::SERIAL_FONT_SIZE]);
                } elseif ($i === 0 && $role === 'processor') {
                    $x->setCell($s, 'K6', 'Sound : '.$label($dev).' - SN: '.$dev['serial']);
                } else {
                    $extra[] = (MaintenancePlanService::ROLE_LABELS[$role] ?? $role).': '.$label($dev).($dev['serial'] !== '' ? ' - SN: '.$dev['serial'] : '');
                }
            }
        }

        $good = $plan['item']->good;
        $done = $d['completed_at'] !== '';
        $result = ! $done ? 'Đang thực hiện' : ($good === null ? 'Hoàn thành' : ((bool) $good ? 'Hệ thống tốt' : 'Cần theo dõi'));

        $x->setCell($s, 'B17', 'Bảo trì định kỳ '.mb_strtolower((string) $plan['system']['label']));
        $x->setCell($s, 'K17', 'Định kỳ '.($plan['system']['months'] ?? '').' tháng');
        $x->setCell($s, 'M17', $result);
        if (! empty($plan['run']->note) && $done) {
            $x->setCell($s, 'O17', (string) $plan['run']->note);
        }
        foreach (array_slice($extra, 0, 4) as $i => $line) {
            $x->setCell($s, 'B'.(18 + $i), 'Kiểm tra '.$line);
        }

        self::writeLevels($x, $s, $isSound ? (array) ($plan['levels'] ?? []) : []);
        self::writeChecklist($x, $s, $isSound, (array) ($plan['checklist'] ?? []));

        $x->fitToOnePage($s, 'landscape');
        $x->showOnlySheet($s);

        return $x->save();
    }

    /**
     * "B chian" column O8…O14: one channel per row, "Left : 85 dB" when
     * measured, else just the label (also clears the sample in the template).
     *
     * @param  array<string, float|int>  $levels
     */
    private static function writeLevels(XlsxTemplateFiller $x, string $sheet, array $levels): void
    {
        $row = 8;
        foreach (array_keys(MaintenancePlanService::LEVELS) as $key) {
            $x->setCell($sheet, 'O'.$row, MaintenancePlanService::levelLine($key, $levels));
            $row++;
        }
    }

    /**
     * "A: Kiểm tra trước khi thực hiện": projector / server / ghi chú text
     * (rows 8–11, cols C / E / G, light source in B12) or the sound grid
     * OK / Không ổn (rows 8–11, cols K…N).
     *
     * @param  array<string, mixed>  $checklist
     */
    private static function writeChecklist(XlsxTemplateFiller $x, string $sheet, bool $isSound, array $checklist): void
    {
        if ($isSound) {
            foreach (MaintenancePlanService::SOUND_CHECKS as $key => $row) {
                foreach (MaintenancePlanService::SOUND_CHECK_COLUMNS as $col => $letter) {
                    $v = $checklist[$key][$col] ?? null;
                    if ($v !== null && isset(MaintenancePlanService::CHECK_VALUES[$v])) {
                        $x->setCell($sheet, $letter.$row['row'], MaintenancePlanService::CHECK_VALUES[$v]);
                    }
                }
            }

            return;
        }

        foreach (MaintenancePlanService::PROJECTION_CHECKS as $key => $row) {
            foreach (MaintenancePlanService::PROJECTION_CHECK_COLUMNS as $col => $letter) {
                $v = trim((string) ($checklist[$key][$col] ?? ''));
                if ($v !== '') {
                    $x->setCell($sheet, $letter.$row['row'], $v);
                }
            }
        }
        $light = trim((string) ($checklist['light_source'] ?? ''));
        if ($light !== '') {
            $x->setCell($sheet, 'B12', 'Light Source (model, serial, giờ đã sử dụng ….) : '.$light);
        }
    }

    /** @param array{run: object} $plan */
    private function maintenanceFileName(array $d, array $plan): string
    {
        $site = preg_replace('/[^A-Za-z0-9_-]+/', '', Str::ascii(str_replace(' ', '-', $d['site'])));
        $sys = ($plan['run']->system ?? '') === 'sound' ? 'AmThanh' : 'MayChieu';
        $date = $d['start_date'] ? str_replace('/', '-', $d['start_date']) : date('d-m-Y');

        return "BaoTri_{$sys}_{$site}_Hall{$d['hall']}_{$date}.xlsx";
    }

    public function fillService(array $d, string $outputPath): string
    {
        $s = self::SHEET_SERVICE;
        $x = new XlsxTemplateFiller($this->templatePath, $outputPath);

        $x->setCell($s, 'A2', 'Họ và Tên :  '.$d['technician']);
        $x->setCell($s, 'A3', 'Bộ Phận    :  Kỹ Thuật');
        $x->setCell($s, 'I2', ' Site công tác : '.$d['site']);
        $x->setCell($s, 'I3', ' Ngày công tác : '.$d['start_date']);
        $x->setCell($s, 'G4', 'Hall :  '.$d['hall']);

        // A: thiết bị – điền vào đúng khối theo loại thiết bị
        $box = self::equipmentBox($d);
        $model = self::modelLabel($d);
        // B6/E6 are two-run cells (big bold model line + smaller serial
        // line); setRuns keeps each run's font size.
        if ($box === 'A5') {
            $x->setRuns($s, 'B6', ["Projector : {$model}", "\nSerial : {$d['serial']}"], [1 => self::SERIAL_FONT_SIZE]);
        } elseif ($box === 'C5') {
            $x->setRuns($s, 'E6', ["Server: {$model}", "\nSerial: {$d['serial']}"], [1 => self::SERIAL_FONT_SIZE]);
        } elseif (in_array($box, ['E5', 'G5', 'I5'], true)) {
            $x->setCell($s, 'K6', "Sound : {$model} - SN: {$d['serial']}");
        }

        // B: Công việc (row 17 in the B-chain template) – "Xử lí sự cố <tên phiếu bảo trì>"
        $x->setCell($s, 'B17', self::workLine($d['name']));
        self::writeLevels($x, $s, []);

        $x->setCell($s, 'K17', $d['is_warranty'] ? 'Bảo hành' : $d['type']);
        $x->setCell($s, 'M17', $d['completed_at'] !== '' ? 'Hoàn thành' : 'Đang xử lý');
        $tech = IncidentReportRepository::cancelledCount((string) $d['cancelled_shows']);
        $cinema = IncidentReportRepository::cancelledCount((string) ($d['cancelled_cinema'] ?? ''));
        if ($tech > 0 && $cinema > 0) {
            $x->setCell($s, 'O17', "Hủy {$tech} suất (Tech), {$cinema} suất (Rạp)");
        } elseif ($tech > 0) {
            $x->setCell($s, 'O17', 'Hủy '.$tech.' suất');
        } elseif ($cinema > 0) {
            $x->setCell($s, 'O17', 'Hủy '.$cinema.' suất (Rạp)');
        }

        $x->fitToOnePage($s, 'landscape');
        $x->showOnlySheet($s);

        return $x->save();
    }

    // ------------------------------------------------------------------

    /** @param array<string, mixed> $d */
    public static function equipmentBox(array $d): string
    {
        // Category first – it is the most reliable signal – then names.
        foreach ([$d['category'], $d['model'].' '.$d['asset_name']] as $text) {
            $hay = Str::lower(Str::ascii((string) $text));
            if (trim($hay) === '') {
                continue;
            }
            foreach (self::EQUIPMENT_BOXES as $cell => $regex) {
                if (preg_match($regex, $hay)) {
                    return $cell;
                }
            }
        }

        return self::BOX_OTHER;
    }

    /** @param array<string, mixed> $d */
    public static function equipmentLabel(array $d): string
    {
        return self::EQUIPMENT_LABELS[self::equipmentBox($d)];
    }

    /** @param array<string, mixed> $d */
    public static function modelLabel(array $d): string
    {
        $model = (string) $d['model'];
        // "CP 950" + model number "CP950" is the same thing: compare without spaces / dashes / case.
        $squash = fn (string $v): string => strtolower((string) preg_replace('/[\s\-_.\/]+/u', '', $v));
        if ($d['model_number'] !== '' && ! str_contains($squash($model), $squash((string) $d['model_number']))) {
            $model = trim($model.' ('.$d['model_number'].')');
        }

        return $model;
    }

    /**
     * Official number: <site code>-<seq>.<start date>-BBSC, seq padded to 2.
     * e.g. "HTP-03.12/01/2026-BBSC".
     */
    public static function formatNumber(string $siteCode, int $seq, string $date): string
    {
        return sprintf('%s-%02d.%s-BBSC', $siteCode, $seq, $date);
    }

    /** Used only when no official number was given (e.g. unit tests). */
    private static function draftNumber(array $d): string
    {
        $docDate = $d['start_date'] ?: Carbon::now()->format('d/m/Y');

        return self::formatNumber(self::siteCode($d['site']), (int) $d['id'], $docDate);
    }

    /** "Huỳnh Tấn Phát" => "HTP", "Nguyễn Du" => "ND". */
    public static function siteCode(string $site): string
    {
        $words = preg_split('/\s+/', trim(Str::ascii($site))) ?: [];
        $code = '';
        foreach ($words as $w) {
            if ($w !== '' && ctype_alpha($w[0])) {
                $code .= strtoupper($w[0]);
            }
        }

        return $code !== '' ? $code : 'GLX';
    }

    /** "Hư CCB" => "Xử lí sự cố hư CCB" (keeps acronyms like "HDD lỗi"). */
    private static function workLine(string $name): string
    {
        $name = trim($name);
        $first = mb_substr($name, 0, 1);
        $second = mb_substr($name, 1, 1);
        if ($second !== '' && mb_strtolower($second) === $second) {
            $name = mb_strtolower($first).mb_substr($name, 1);
        }

        return trim('Xử lí sự cố '.$name);
    }

    private static function estimateLines(string $text, int $charsPerLine): int
    {
        $n = 0;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $n += max(1, (int) ceil(mb_strlen($line) / $charsPerLine));
        }

        return $n;
    }

    private function tempPath(): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'mtform_'.Str::random(20).'.xlsx';
    }

    /** @param array<string, mixed> $d */
    private function fileName(string $prefix, array $d): string
    {
        $tag = preg_replace('/[^A-Za-z0-9_-]+/', '', Str::ascii($d['asset_tag'] ?: 'maintenance-'.$d['id']));
        $date = $d['start_date'] ? str_replace('/', '-', $d['start_date']) : date('d-m-Y');

        return "{$prefix}_{$tag}_{$date}.xlsx";
    }
}

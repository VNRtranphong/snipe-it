<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Maintenance;
use App\Services\MaintenanceForms\IncidentDocumentService;
use App\Services\MaintenanceForms\MaintenancePlanService;
use App\Services\MaintenanceForms\MaintenanceYearPlanService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * "Lịch bảo trì": periodic maintenance per cinema × system (hệ máy chiếu /
 * hệ âm thanh). Viewing is open to every logged-in user; creating,
 * finishing, skipping or cancelling needs asset edit rights.
 */
class MaintenancePlanController extends Controller
{
    public function __construct(private MaintenancePlanService $plan)
    {
        parent::__construct();
    }

    public function index(Request $request): View
    {
        $today = Carbon::now();
        $year = (int) $request->query('year', $today->format('Y'));
        $year = max(2020, min(2100, $year));
        $system = (string) $request->query('system', '');
        $system = isset(MaintenancePlanService::SYSTEMS[$system]) ? $system : '';
        $site = trim((string) $request->query('site', ''));
        $state = (string) $request->query('state', '');
        $state = isset(MaintenancePlanService::STATE_LABELS[$state]) ? $state : '';

        $data = $this->plan->load($today);
        $board = $data['board'];
        $calendar = MaintenancePlanService::calendar($board, $data['runs'], $year, $today);

        // Filters only narrow what is listed; the cards always count everything.
        $sites = [];
        foreach ($board['sites'] as $name => $row) {
            if ($site !== '' && $name !== $site) {
                continue;
            }
            $systems = array_filter($row['systems'], fn ($cell, $key) => ($system === '' || $key === $system) && ($state === '' || $cell['state'] === $state || ($state === 'overdue' && $cell['state'] === 'never')), ARRAY_FILTER_USE_BOTH);
            if ($systems) {
                $sites[$name] = ['site' => $name, 'systems' => $systems];
            }
        }
        $calendar = array_values(array_filter($calendar, fn ($c) => ($site === '' || $c['site'] === $site) && ($system === '' || $c['system'] === $system)));

        $doneThisYear = 0;
        foreach ($data['runs'] as $run) {
            if ($run->status === MaintenancePlanService::STATUS_DONE && $run->completed_at && Carbon::parse($run->completed_at)->format('Y') === $today->format('Y')) {
                $doneThisYear++;
            }
        }

        return view('maintenance-plan.index', [
            'ready' => MaintenancePlanService::ready(),
            'board' => $board,
            'sites' => $sites,
            'allSites' => array_keys($board['sites']),
            'calendar' => $calendar,
            'year' => $year,
            'filters' => ['system' => $system, 'site' => $site, 'state' => $state],
            'doneThisYear' => $doneThisYear,
            'canEdit' => (bool) $request->user()?->can('update', Asset::class),
            'today' => $today,
        ]);
    }

    public function run(Request $request, int $run): View|RedirectResponse
    {
        $detail = $this->plan->runDetail($run);
        if ($detail === null) {
            return redirect()->route('maintenance-plan.index')->with('error', 'Không tìm thấy đợt bảo trì.');
        }

        return view('maintenance-plan.run', $detail + [
            'canEdit' => (bool) $request->user()?->can('update', Asset::class),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $this->authorize('update', Asset::class);
        $validated = $request->validate([
            'site' => 'required|string|max:191',
            'system' => 'required|string|in:'.implode(',', array_keys(MaintenancePlanService::SYSTEMS)),
            'date' => 'nullable|date|before_or_equal:today',
            'note' => 'nullable|string|max:1000',
        ]);

        $date = ! empty($validated['date']) ? Carbon::parse($validated['date']) : Carbon::now();
        if ($date->format('Y-m-d') === Carbon::now()->format('Y-m-d')) {
            $date = Carbon::now();
        } else {
            $date->setTime(8, 0);
        }

        try {
            $result = $this->plan->start($validated['site'], $validated['system'], $date, $request->user()?->id, $validated['note'] ?? null);
        } catch (\Throwable $e) {
            Log::error('Maintenance plan start failed: '.$e->getMessage());

            return redirect()->back()->with('error', 'Không tạo được phiếu: '.$e->getMessage());
        }

        $msg = 'Đã tạo '.$result['created'].' phiếu bảo trì (mỗi phòng 1 phiếu).';
        if ($result['skipped_no_hall'] > 0) {
            $msg .= ' '.$result['skipped_no_hall'].' thiết bị chưa rõ Hall nên không có phiếu.';
        }

        return redirect()->route('maintenance-plan.run', $result['run_id'])->with('success', $msg);
    }

    public function complete(Request $request, int $run): RedirectResponse
    {
        $this->authorize('update', Asset::class);
        $validated = $request->validate([
            'good' => 'nullable|array',
            'good.*' => 'integer',
            'note' => 'nullable|string|max:1000',
            'levels' => 'nullable|array',
            'checks' => 'nullable|array',
        ]);

        try {
            $levels = MaintenancePlanService::levelsReady() ? ($validated['levels'] ?? []) : [];
            $checks = MaintenancePlanService::checklistReady() ? ($validated['checks'] ?? []) : [];
            if ($levels || $checks) {
                $this->plan->saveResults($run, $levels, $checks);
            }
            $this->plan->complete($run, array_map('intval', $validated['good'] ?? []), $validated['note'] ?? null, $request->user()?->id);
        } catch (\Throwable $e) {
            Log::error('Maintenance plan complete failed: '.$e->getMessage());

            return redirect()->back()->with('error', 'Không hoàn thành được: '.$e->getMessage());
        }

        return redirect()->route('maintenance-plan.run', $run)->with('success', 'Đã hoàn thành đợt bảo trì. Ngày đến hạn kỳ sau đã được tính lại.');
    }

    /** Save the checklist (+ B-chain levels for sound), before or after completion. */
    public function results(Request $request, int $run): RedirectResponse
    {
        $this->authorize('update', Asset::class);
        $validated = $request->validate([
            'levels' => 'nullable|array',
            'checks' => 'nullable|array',
        ]);

        try {
            $this->plan->saveResults($run, $validated['levels'] ?? [], $validated['checks'] ?? []);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('maintenance-plan.run', $run)->with('success', 'Đã lưu kết quả kiểm tra. Service Report tải về sẽ có các nội dung này.');
    }

    public function skip(Request $request): RedirectResponse
    {
        $this->authorize('update', Asset::class);
        $validated = $request->validate([
            'site' => 'required|string|max:191',
            'system' => 'required|string|in:'.implode(',', array_keys(MaintenancePlanService::SYSTEMS)),
            'note' => 'nullable|string|max:1000',
        ]);

        try {
            $this->plan->skip($validated['site'], $validated['system'], $request->user()?->id, $validated['note'] ?? null, Carbon::now());
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Đã bỏ qua kỳ này cho '.$validated['site'].'.');
    }

    public function cancel(Request $request, int $run): RedirectResponse
    {
        $this->authorize('update', Asset::class);
        try {
            $this->plan->cancel($run);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('maintenance-plan.index')->with('success', 'Đã hủy đợt bảo trì và xóa các phiếu chưa hoàn thành.');
    }

    /** Every Service Report of a run, zipped. */
    public function reports(int $run, IncidentDocumentService $documents): BinaryFileResponse|RedirectResponse
    {
        $detail = $this->plan->runDetail($run);
        if ($detail === null) {
            return redirect()->route('maintenance-plan.index')->with('error', 'Không tìm thấy đợt bảo trì.');
        }

        $zipPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'baotri_'.Str::random(16).'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $temps = [];
        foreach ($detail['items'] as $item) {
            $m = Maintenance::find($item['maintenance_id']);
            if (! $m) {
                continue;
            }
            $file = $documents->service($m);
            $zip->addFile($file['path'], $file['name']);
            if ($file['temp']) {
                $temps[] = $file['path'];
            }
        }
        $zip->close();
        foreach ($temps as $t) {
            @unlink($t);
        }

        $site = preg_replace('/[^A-Za-z0-9_-]+/', '', Str::ascii(str_replace(' ', '-', (string) $detail['run']->site)));

        return response()->download($zipPath, 'BaoTri_'.$run.'_'.$site.'.zip')->deleteFileAfterSend(true);
    }

    // ------------------------------------------------------------------
    // Kế hoạch năm (trình ký)
    // ------------------------------------------------------------------

    public function year(Request $request, int $year, MaintenanceYearPlanService $yearPlan): View
    {
        $year = max(2020, min(2100, $year));
        $data = $this->plan->load(Carbon::now());
        $lines = MaintenanceYearPlanService::sort($yearPlan->lines($year));

        return view('maintenance-plan.year', [
            'ready' => MaintenanceYearPlanService::ready(),
            'year' => $year,
            'lines' => $lines,
            'summary' => MaintenanceYearPlanService::summary($lines),
            'meta' => $yearPlan->meta($year),
            'halls' => self::hallCounts($data['board']),
            'sites' => array_keys($data['board']['sites']),
            'knownStaff' => $yearPlan->knownStaff(),
            'canEdit' => (bool) $request->user()?->can('update', Asset::class),
        ]);
    }

    public function yearGenerate(Request $request, int $year, MaintenanceYearPlanService $yearPlan): RedirectResponse
    {
        $this->authorize('update', Asset::class);
        $today = Carbon::now();
        $data = $this->plan->load($today);
        try {
            $added = $yearPlan->generate($year, MaintenancePlanService::calendar($data['board'], $data['runs'], $year, $today), $request->user()?->id);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('maintenance-plan.year', $year)->with('success', $added ? "Đã thêm {$added} lần bảo trì theo lịch vào kế hoạch {$year}." : 'Kế hoạch đã có đủ các lần bảo trì theo lịch.');
    }

    public function yearSave(Request $request, int $year, MaintenanceYearPlanService $yearPlan): RedirectResponse
    {
        $this->authorize('update', Asset::class);
        $validated = $request->validate([
            'lines' => 'nullable|array',
            'meta' => 'nullable|array',
        ]);
        try {
            $result = $yearPlan->save($year, $validated['lines'] ?? [], $request->user()?->id);
            $yearPlan->saveMeta($year, $validated['meta'] ?? [], $request->user()?->id);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('maintenance-plan.year', $year)
            ->with('success', 'Đã lưu kế hoạch '.$year.' ('.$result['saved'].' dòng'.($result['deleted'] ? ', xóa '.$result['deleted'] : '').').');
    }

    public function yearAdd(Request $request, int $year, MaintenanceYearPlanService $yearPlan): RedirectResponse
    {
        $this->authorize('update', Asset::class);
        $validated = $request->validate([
            'site' => 'required|string|max:191',
            'system' => 'required|string|in:'.implode(',', array_keys(MaintenancePlanService::SYSTEMS)),
            'month' => 'required|integer|min:1|max:12',
        ]);
        try {
            $yearPlan->add($year, $validated['site'], $validated['system'], (int) $validated['month'], $request->user()?->id);
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('maintenance-plan.year', $year)->with('success', 'Đã thêm 1 dòng vào kế hoạch.');
    }

    public function yearExport(int $year, MaintenanceYearPlanService $yearPlan): BinaryFileResponse
    {
        $data = $this->plan->load(Carbon::now());
        $lines = MaintenanceYearPlanService::sort($yearPlan->lines($year));
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'kehoach_'.Str::random(16).'.xlsx';
        MaintenanceYearPlanService::export($year, $lines, $yearPlan->meta($year), self::hallCounts($data['board']), $path);

        return response()->download($path, 'Ke_hoach_bao_tri_'.$year.'.xlsx')->deleteFileAfterSend(true);
    }

    /** @return array<string, array<string, int>> site => system => halls */
    private static function hallCounts(array $board): array
    {
        $out = [];
        foreach ($board['sites'] as $site => $row) {
            foreach ($row['systems'] as $sys => $cell) {
                $out[$site][$sys] = count($cell['halls']);
            }
        }

        return $out;
    }

    public function export(Request $request): BinaryFileResponse
    {
        $today = Carbon::now();
        $year = max(2020, min(2100, (int) $request->query('year', $today->format('Y'))));
        $data = $this->plan->load($today);
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lichbaotri_'.Str::random(16).'.xlsx';
        MaintenancePlanService::export($data['board'], MaintenancePlanService::calendar($data['board'], $data['runs'], $year, $today), $year, $path);

        return response()->download($path, 'Lich_bao_tri_'.$year.'.xlsx')->deleteFileAfterSend(true);
    }
}

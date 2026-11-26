<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetModel;
use App\Models\Category;
use App\Models\Maintenance;
use App\Services\MaintenanceForms\IncidentDocumentService;
use App\Services\MaintenanceForms\IncidentReportRepository;
use App\Services\MaintenanceForms\IncidentStatsService;
use App\Services\MaintenanceForms\IncidentSummaryExporter;
use App\Services\MaintenanceForms\InventoryRepository;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Read-only "Báo cáo sự cố" page: list incidents (maintenances) with
 * filters and export BBSC / Service Report forms, in bulk as a zip, or
 * a summary workbook.
 *
 * Open to every logged-in user (route uses only the `auth` middleware).
 * Company scoping of Maintenance still applies when Full Multiple
 * Companies Support is on.
 */
class IncidentReportsController extends Controller
{
    public function __construct(
        private IncidentReportRepository $repository,
        private IncidentDocumentService $documents,
    ) {
        parent::__construct();
    }

    public function index(Request $request): View
    {
        $filters = IncidentReportRepository::filters($request->query(), $request->user());
        $inRange = $this->repository->rowsInRange($filters, null, ! $filters['strict']);
        $rows = IncidentReportRepository::applyFilters($inRange, $filters);

        // Cards count everything except the status filter, so clicking a
        // card (which sets the status) does not change the numbers.
        $forStats = $filters['status'] === ''
            ? $rows
            : IncidentReportRepository::applyFilters($inRange, ['status' => ''] + $filters);

        $user = $request->user();

        return view('incident-reports.index', [
            'filters' => $filters,
            'rows' => $rows,
            'docs' => $this->documents->findMany(array_map(fn ($r) => (int) $r['id'], $rows)),
            'docsReady' => $this->documents->ready(),
            'canLock' => ($user?->can('update', Asset::class) ?? false) && $this->documents->ready(),
            'canUnlock' => (bool) $user?->isSuperUser(),
            'trend' => $this->trendFor($filters),
            'stats' => IncidentReportRepository::stats($forStats),
            'options' => IncidentReportRepository::options($inRange, $filters['site']),
            'truncated' => count($inRange) >= IncidentReportRepository::MAX_ROWS,
            'overdueHours' => IncidentReportRepository::OVERDUE_HOURS,
        ]);
    }

    /**
     * Thống kê sự cố: year-over-year comparison.
     * ?year=2026 &span=same|full &metric=incidents|maintenance|cancelled|cancelled_over24|overdue
     */
    public function stats(Request $request): View
    {
        $now = Carbon::now();
        $currentYear = (int) $now->format('Y');
        $year = (int) $request->query('year', $currentYear);
        if ($year < 2000 || $year > $currentYear) {
            $year = $currentYear;
        }
        $sameSpan = $request->query('span', 'same') !== 'full';
        $metric = (string) $request->query('metric', 'incidents');

        $rows = $this->repository->rowsInRange([
            'from' => Carbon::create($year - 2, 1, 1, 0, 0, 0),
            'to' => Carbon::create($year, 12, 31, 23, 59, 59),
        ], $now, false, 50000);

        return view('incident-reports.stats', [
            'stats' => IncidentStatsService::build($rows, $year, $now, $sameSpan, $metric),
            'metrics' => IncidentStatsService::METRICS,
            'yearOptions' => range($currentYear, $currentYear - 5),
            'cutoff' => $now,
        ]);
    }

    /** Tra cứu thiết bị: separate page (data comes from inventory()). */
    public function inventoryPage(Request $request, InventoryRepository $inventory): View
    {
        return view('incident-reports.inventory', [
            'overview' => $inventory->cachedOverview($request->boolean('refresh')),
            'inventoryCategories' => Category::where('category_type', 'asset')->orderBy('name')->get(['id', 'name']),
            'inventoryModels' => AssetModel::orderBy('name')->get(['id', 'name', 'model_number', 'category_id']),
        ]);
    }

    /**
     * Single BBSC download (no asset permission needed on this page).
     * Issues the official number on first export; returns the locked file
     * once the biên bản has been "chốt".
     */
    public function incident(Maintenance $maintenance): BinaryFileResponse
    {
        return self::send($this->documents->incident($maintenance));
    }

    /** Single Service Report download (locked copy if chốt). */
    public function service(Maintenance $maintenance): BinaryFileResponse
    {
        return self::send($this->documents->service($maintenance));
    }

    /** Chốt biên bản: snapshot BBSC + Service Report. Needs asset edit rights. */
    public function lock(Request $request, Maintenance $maintenance): RedirectResponse
    {
        $this->authorize('update', Asset::class);

        try {
            $doc = $this->documents->lock($maintenance, $request->user()?->id);
        } catch (\Throwable $e) {
            Log::error('Incident lock failed for maintenance '.$maintenance->id.': '.$e->getMessage());

            return redirect()->back()->with('error', 'Không chốt được biên bản: '.$e->getMessage());
        }

        return redirect()->back()->with('success', 'Đã chốt biên bản '.$doc->number.'. Từ giờ tải về sẽ ra đúng bản đã chốt.');
    }

    /** Mở chốt: superusers only. The number is kept. */
    public function unlock(Request $request, Maintenance $maintenance): RedirectResponse
    {
        abort_unless((bool) $request->user()?->isSuperUser(), 403);

        $this->documents->unlock((int) $maintenance->id);

        return redirect()->back()->with('success', 'Đã mở chốt biên bản. Bản đã chốt trước đó được lưu lại trong thư mục _unlocked.');
    }

    /** @param array{path: string, name: string, temp: bool} $file */
    private static function send(array $file): BinaryFileResponse
    {
        return response()->download($file['path'], $file['name'])->deleteFileAfterSend($file['temp']);
    }

    /**
     * 12 months ending with the month of the "to" date, same site / hall /
     * equipment / search filters as the list (status ignored).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function trendFor(array $filters): array
    {
        $end = $filters['to']->copy()->endOfMonth();
        $start = $filters['to']->copy()->startOfMonth()->subMonths(11);

        $rows = $this->repository->rowsInRange(['from' => $start, 'to' => $end] + $filters, null, false, 10000);
        $rows = IncidentReportRepository::applyFilters($rows, ['status' => ''] + $filters);

        return IncidentReportRepository::trend($rows, $end, 12);
    }

    /**
     * Zip of the forms for the ticked rows.
     * type = bbsc | report | both
     */
    public function bulk(Request $request): BinaryFileResponse|RedirectResponse
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])))));
        $type = in_array($request->input('type'), ['bbsc', 'report', 'both'], true) ? $request->input('type') : 'both';

        if (! $ids) {
            return redirect()->back()->with('error', 'Chưa chọn sự cố nào để xuất.');
        }
        if (count($ids) > 200) {
            return redirect()->back()->with('error', 'Mỗi lần xuất tối đa 200 sự cố.');
        }

        $maintenances = Maintenance::with([
            'asset.model.category', 'asset.location.parent', 'asset.defaultLoc', 'asset.assignedTo',
            'asset.company', 'maintenanceType', 'supplier', 'responsibleParty', 'adminuser',
        ])->whereIn('id', $ids)->get();

        if ($maintenances->isEmpty()) {
            return redirect()->back()->with('error', 'Không tìm thấy sự cố đã chọn.');
        }

        $zipPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'incidents_'.Str::random(16).'.zip';
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Không tạo được file zip');
        }

        $temp = [];
        $used = [];
        foreach ($maintenances as $m) {
            $jobs = [];
            if ($type !== 'report') {
                $jobs[] = ['BBSC', fn () => $this->documents->incident($m)];
            }
            if ($type !== 'bbsc') {
                $jobs[] = ['Service Report', fn () => $this->documents->service($m)];
            }

            foreach ($jobs as [$folder, $make]) {
                try {
                    $file = $make();
                } catch (\Throwable $e) {
                    Log::warning('Incident export failed for maintenance '.$m->id.': '.$e->getMessage());

                    continue;
                }
                [$path, $name] = [$file['path'], $file['name']];
                if ($file['temp']) {
                    $temp[] = $path;
                }
                $entry = $folder.'/'.$name;
                if (isset($used[$entry])) {
                    $entry = $folder.'/'.pathinfo($name, PATHINFO_FILENAME).'_'.$m->id.'.xlsx';
                }
                $used[$entry] = true;
                $zip->addFile($path, $entry);
            }
        }
        $zip->close();

        // Files are read on close(), so the temp xlsx can go now.
        foreach ($temp as $p) {
            @unlink($p);
        }

        $label = ['bbsc' => 'BBSC', 'report' => 'ServiceReport', 'both' => 'BBSC_ServiceReport'][$type];

        return response()
            ->download($zipPath, $label.'_'.count($maintenances).'_su-co_'.Carbon::now()->format('d-m-Y').'.zip')
            ->deleteFileAfterSend(true);
    }

    /** Summary workbook for the current filters. */
    public function summary(Request $request): BinaryFileResponse
    {
        $filters = IncidentReportRepository::filters($request->query(), $request->user());
        $rows = IncidentReportRepository::applyFilters($this->repository->rowsInRange($filters, null, ! $filters['strict']), $filters);

        $docs = $this->documents->findMany(array_map(fn ($r) => (int) $r['id'], $rows));
        foreach ($rows as &$r) {
            $doc = $docs[(int) $r['id']] ?? null;
            $r['doc_number'] = $doc?->number ?? '';
            $r['doc_locked'] = (bool) ($doc?->locked_at);
        }
        unset($r);

        // Oldest first reads better in a report.
        usort($rows, fn ($a, $b) => ($a['start_at']?->getTimestamp() ?? 0) <=> ($b['start_at']?->getTimestamp() ?? 0));

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'incident_summary_'.Str::random(16).'.xlsx';
        (new IncidentSummaryExporter)->build($rows, $filters, $path);

        $sitePart = $filters['site'] !== '' ? '_'.Str::slug($filters['site']) : '';
        $name = 'TongHopSuCo'.$sitePart.'_'.$filters['from']->format('d-m-Y').'_'.$filters['to']->format('d-m-Y').'.xlsx';

        return response()->download($path, $name)->deleteFileAfterSend(true);
    }

    /** Tra cứu thiết bị: JSON for the inventory panel. */
    public function inventory(Request $request, InventoryRepository $inventory): JsonResponse
    {
        return response()->json($inventory->lookup(
            $request->integer('category_id') ?: null,
            $request->integer('model_id') ?: null,
        ));
    }

    /** Tra cứu thiết bị: Excel download of the same lookup. */
    public function inventoryExport(Request $request, InventoryRepository $inventory): BinaryFileResponse|RedirectResponse
    {
        $result = $inventory->lookup(
            $request->integer('category_id') ?: null,
            $request->integer('model_id') ?: null,
        );
        if ($result['title'] === '') {
            return redirect()->route('incident-reports.index')->with('error', 'Chưa chọn Category hoặc Model.');
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'inventory_'.Str::random(16).'.xlsx';
        InventoryRepository::export($result, $path);

        return response()
            ->download($path, 'ThietBi_'.Str::slug($result['title']).'_'.Carbon::now()->format('d-m-Y').'.xlsx')
            ->deleteFileAfterSend(true);
    }
}

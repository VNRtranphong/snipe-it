<?php

namespace App\Http\Controllers;

use App\Models\Maintenance;
use App\Services\MaintenanceForms\IncidentReportRepository;
use App\Services\MaintenanceForms\IncidentSummaryExporter;
use App\Services\MaintenanceForms\MaintenanceFormExporter;
use Carbon\Carbon;
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
        private MaintenanceFormExporter $exporter,
    ) {
        parent::__construct();
    }

    public function index(Request $request): View
    {
        $filters = IncidentReportRepository::filters($request->query(), $request->user());
        $inRange = $this->repository->rowsInRange($filters);
        $rows = IncidentReportRepository::applyFilters($inRange, $filters);

        return view('incident-reports.index', [
            'filters' => $filters,
            'rows' => $rows,
            'stats' => IncidentReportRepository::stats($rows),
            'options' => IncidentReportRepository::options($inRange, $filters['site']),
            'truncated' => count($inRange) >= IncidentReportRepository::MAX_ROWS,
            'overdueHours' => IncidentReportRepository::OVERDUE_HOURS,
        ]);
    }

    /** Single BBSC download (no asset permission needed on this page). */
    public function incident(Maintenance $maintenance): BinaryFileResponse
    {
        [$path, $name] = $this->exporter->incidentReport($maintenance);

        return response()->download($path, $name)->deleteFileAfterSend(true);
    }

    /** Single Service Report download. */
    public function service(Maintenance $maintenance): BinaryFileResponse
    {
        [$path, $name] = $this->exporter->serviceReport($maintenance);

        return response()->download($path, $name)->deleteFileAfterSend(true);
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
                $jobs[] = ['BBSC', fn () => $this->exporter->incidentReport($m)];
            }
            if ($type !== 'bbsc') {
                $jobs[] = ['Service Report', fn () => $this->exporter->serviceReport($m)];
            }

            foreach ($jobs as [$folder, $make]) {
                try {
                    [$path, $name] = $make();
                } catch (\Throwable $e) {
                    Log::warning('Incident export failed for maintenance '.$m->id.': '.$e->getMessage());

                    continue;
                }
                $temp[] = $path;
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
        $rows = IncidentReportRepository::applyFilters($this->repository->rowsInRange($filters), $filters);

        // Oldest first reads better in a report.
        usort($rows, fn ($a, $b) => ($a['start_at']?->getTimestamp() ?? 0) <=> ($b['start_at']?->getTimestamp() ?? 0));

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'incident_summary_'.Str::random(16).'.xlsx';
        (new IncidentSummaryExporter)->build($rows, $filters, $path);

        $sitePart = $filters['site'] !== '' ? '_'.Str::slug($filters['site']) : '';
        $name = 'TongHopSuCo'.$sitePart.'_'.$filters['from']->format('d-m-Y').'_'.$filters['to']->format('d-m-Y').'.xlsx';

        return response()->download($path, $name)->deleteFileAfterSend(true);
    }
}

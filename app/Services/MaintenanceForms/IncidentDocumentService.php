<?php

namespace App\Services\MaintenanceForms;

use App\Models\Maintenance;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Official BBSC numbers and "chốt biên bản" snapshots.
 *
 * Numbering: <site code>-<seq>.<start date>-BBSC, e.g. "HTP-03.12/01/2026-BBSC".
 * The sequence restarts every year and is counted separately per site
 * code. A number is issued the first time a BBSC is exported for a
 * maintenance and never changes after that (even if the incident is
 * edited), so a printed paper always matches the register.
 *
 * Locking ("chốt"): stores the BBSC + Service Report files as they are
 * at that moment under storage/app/incident-reports/<maintenance id>/.
 * Later downloads return those stored files instead of regenerating.
 *
 * Uses the query builder on purpose (no Eloquent model).
 */
class IncidentDocumentService
{
    public const TABLE = 'incident_report_documents';

    private ?bool $ready = null;

    private ?bool $placesReady = null;

    public function __construct(private MaintenanceFormExporter $exporter) {}

    /**
     * False until `php artisan migrate` has created the table. Everything
     * below degrades gracefully instead of throwing a 500.
     */
    public function ready(): bool
    {
        if ($this->ready === null) {
            try {
                $this->ready = Schema::hasTable(self::TABLE);
            } catch (\Throwable $e) {
                $this->ready = false;
            }
            if (! $this->ready) {
                Log::warning('Bảng '.self::TABLE.' chưa có – hãy chạy: php artisan migrate');
            }
        }

        return $this->ready;
    }

    /** True once the "site / hall" columns exist (second migration). */
    public function placesReady(): bool
    {
        if ($this->placesReady === null) {
            try {
                $this->placesReady = $this->ready() && Schema::hasColumn(self::TABLE, 'site');
            } catch (\Throwable) {
                $this->placesReady = false;
            }
        }

        return $this->placesReady;
    }

    /**
     * Remembered cinema / hall per maintenance id (only those that have one).
     * Locked biên bản made before this feature are filled in on the fly by
     * reading "Site:" / "Hall:" back from the locked BBSC file.
     *
     * @param  list<int>  $ids
     * @return array<int, array{site: string, hall: string}>
     */
    public function places(array $ids): array
    {
        if (! $ids || ! $this->placesReady()) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $docs = DB::table(self::TABLE)
                ->whereIn('maintenance_id', $chunk)
                ->where(fn ($q) => $q->whereNotNull('site')->orWhereNotNull('locked_at'))
                ->get(['maintenance_id', 'site', 'hall', 'locked_at', 'bbsc_path']);
            foreach ($docs as $doc) {
                if (($doc->site ?? '') === '' && $doc->locked_at) {
                    $doc = $this->backfillFromLockedFile($doc);
                }
                if (($doc->site ?? '') !== '') {
                    $out[(int) $doc->maintenance_id] = ['site' => (string) $doc->site, 'hall' => (string) ($doc->hall ?? '')];
                }
            }
        }

        return $out;
    }

    /**
     * Remember where the incident happened, unless already remembered.
     * Never remembers an office / storage location (VP HO, MEP …): that
     * would mean the asset has already been moved away from the cinema.
     */
    private function rememberPlace(Maintenance $maintenance, string $source, bool $overwrite = false): void
    {
        if (! $this->placesReady()) {
            return;
        }
        $doc = $this->find((int) $maintenance->id);
        if (! $doc || (! $overwrite && ($doc->site ?? '') !== '')) {
            return;
        }

        $d = MaintenanceFormExporter::collect($maintenance);
        if ($d['site'] === '' || InventoryRepository::isExcludedSite($d['site'])) {
            return;
        }

        DB::table(self::TABLE)->where('maintenance_id', $maintenance->id)->update([
            'site' => mb_substr($d['site'], 0, 191),
            'hall' => mb_substr((string) $d['hall'], 0, 50),
            'place_source' => $source,
            'updated_at' => now(),
        ]);
    }

    /** Read "Site: …" (A11) and "Hall: …" (G11) back from a locked BBSC file. */
    private function backfillFromLockedFile(object $doc): object
    {
        $file = $doc->bbsc_path ? $this->absolute($doc->bbsc_path) : null;
        if (! $file || ! is_file($file)) {
            return $doc;
        }

        $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'bbsc_read_'.uniqid().'.xlsx';
        try {
            $x = new XlsxTemplateFiller($file, $tmp);
            $site = trim((string) preg_replace('/^\s*Site\s*:?\s*/iu', '', $x->getCell(MaintenanceFormExporter::SHEET_INCIDENT, 'A11')));
            $hall = trim((string) preg_replace('/^\s*Hall\s*:?\s*/iu', '', $x->getCell(MaintenanceFormExporter::SHEET_INCIDENT, 'G11')));
        } catch (\Throwable $e) {
            Log::warning('Không đọc được vị trí từ BBSC đã chốt '.$file.': '.$e->getMessage());

            return $doc;
        } finally {
            @unlink($tmp);
        }

        if ($site !== '') {
            DB::table(self::TABLE)->where('maintenance_id', $doc->maintenance_id)->update([
                'site' => mb_substr($site, 0, 191),
                'hall' => mb_substr($hall, 0, 50),
                'place_source' => 'file',
                'updated_at' => now(),
            ]);
            $doc->site = $site;
            $doc->hall = $hall;
        }

        return $doc;
    }

    /** @return array{site: string, hall: string}|null */
    private function placeFor(Maintenance $maintenance): ?array
    {
        return $this->places([(int) $maintenance->id])[(int) $maintenance->id] ?? null;
    }

    /** Stored row for one maintenance, or null. */
    public function find(int $maintenanceId): ?object
    {
        if (! $this->ready()) {
            return null;
        }

        return DB::table(self::TABLE)->where('maintenance_id', $maintenanceId)->first();
    }

    /**
     * Stored rows for many maintenances, keyed by maintenance id.
     *
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    public function findMany(array $ids): array
    {
        if (! $ids || ! $this->ready()) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table(self::TABLE)->whereIn('maintenance_id', $chunk)->get() as $row) {
                $out[(int) $row->maintenance_id] = $row;
            }
        }

        return $out;
    }

    /**
     * The official number of a maintenance, issuing the next one if needed.
     */
    public function numberFor(Maintenance $maintenance): ?string
    {
        // Table missing: fall back to the old ID-based draft number.
        if (! $this->ready()) {
            return null;
        }

        if ($existing = $this->find((int) $maintenance->id)) {
            $this->rememberPlace($maintenance, 'number');

            return $existing->number;
        }

        $d = MaintenanceFormExporter::collect($maintenance);
        $siteCode = MaintenanceFormExporter::siteCode($d['site']);
        $start = $d['start_at'] ?? null;
        $year = (int) ($start instanceof Carbon ? $start->format('Y') : date('Y'));
        $date = $d['start_date'] ?: Carbon::now()->format('d/m/Y');

        // Two people exporting at the same second could pick the same seq;
        // the unique index rejects one of them, so retry a few times.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return DB::transaction(function () use ($maintenance, $siteCode, $year, $date) {
                    $again = DB::table(self::TABLE)->where('maintenance_id', $maintenance->id)->lockForUpdate()->first();
                    if ($again) {
                        return $again->number;
                    }

                    $seq = (int) DB::table(self::TABLE)
                        ->where('site_code', $siteCode)
                        ->where('year', $year)
                        ->lockForUpdate()
                        ->max('seq') + 1;

                    $number = MaintenanceFormExporter::formatNumber($siteCode, $seq, $date);

                    DB::table(self::TABLE)->insert([
                        'maintenance_id' => $maintenance->id,
                        'site_code' => $siteCode,
                        'year' => $year,
                        'seq' => $seq,
                        'number' => $number,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return $number;
                });
            } catch (QueryException $e) {
                if ($attempt === 4) {
                    throw $e;
                }
                usleep(100000 * ($attempt + 1));
            }
        }

        throw new RuntimeException('Could not issue a BBSC number');
    }

    /** Issue (or get) the number and remember the incident's place. */
    private function numberWithPlace(Maintenance $maintenance): ?string
    {
        $number = $this->numberFor($maintenance);
        if ($number !== null) {
            $this->rememberPlace($maintenance, 'number');
        }

        return $number;
    }

    /**
     * BBSC file to send: the locked copy, or a freshly generated one.
     *
     * @return array{path: string, name: string, temp: bool}
     */
    public function incident(Maintenance $maintenance): array
    {
        $doc = $this->find((int) $maintenance->id);
        if ($doc && $doc->locked_at && $doc->bbsc_path && is_file($this->absolute($doc->bbsc_path))) {
            return ['path' => $this->absolute($doc->bbsc_path), 'name' => basename($doc->bbsc_path), 'temp' => false];
        }

        $number = $this->numberWithPlace($maintenance);
        [$path, $name] = $this->exporter->incidentReport($maintenance, $number, $this->placeFor($maintenance));

        return ['path' => $path, 'name' => $name, 'temp' => true];
    }

    /**
     * Service Report file to send: the locked copy, or a freshly generated one.
     *
     * @return array{path: string, name: string, temp: bool}
     */
    public function service(Maintenance $maintenance): array
    {
        $doc = $this->find((int) $maintenance->id);
        if ($doc && $doc->locked_at && $doc->report_path && is_file($this->absolute($doc->report_path))) {
            return ['path' => $this->absolute($doc->report_path), 'name' => basename($doc->report_path), 'temp' => false];
        }

        [$path, $name] = $this->exporter->serviceReport($maintenance, $this->placeFor($maintenance));

        return ['path' => $path, 'name' => $name, 'temp' => true];
    }

    /**
     * Chốt biên bản: snapshot both files and mark the record locked.
     */
    public function lock(Maintenance $maintenance, ?int $userId): object
    {
        if (! $this->ready()) {
            throw new RuntimeException('Chưa chạy "php artisan migrate" trên server nên chưa chốt được.');
        }

        $number = $this->numberFor($maintenance);
        $doc = $this->find((int) $maintenance->id);
        if ($doc->locked_at) {
            return $doc;
        }

        $dirRel = 'incident-reports/'.$maintenance->id;
        $dirAbs = $this->absolute($dirRel);
        File::ensureDirectoryExists($dirAbs);

        $this->rememberPlace($maintenance, 'lock');
        $place = $this->placeFor($maintenance);
        [$bbscTmp, $bbscName] = $this->exporter->incidentReport($maintenance, $number, $place);
        [$repTmp, $repName] = $this->exporter->serviceReport($maintenance, $place);

        $stamp = Carbon::now()->format('Ymd-His');
        $bbscRel = $dirRel.'/'.pathinfo($bbscName, PATHINFO_FILENAME).'_chot-'.$stamp.'.xlsx';
        $repRel = $dirRel.'/'.pathinfo($repName, PATHINFO_FILENAME).'_chot-'.$stamp.'.xlsx';

        self::moveFile($bbscTmp, $this->absolute($bbscRel));
        self::moveFile($repTmp, $this->absolute($repRel));

        DB::table(self::TABLE)->where('maintenance_id', $maintenance->id)->update([
            'locked_at' => now(),
            'locked_by' => $userId,
            'bbsc_path' => $bbscRel,
            'report_path' => $repRel,
            'updated_at' => now(),
        ]);

        return $this->find((int) $maintenance->id);
    }

    /**
     * Mở chốt: forget the snapshot (files are kept in an _unlocked folder
     * for reference). The number stays the same.
     */
    public function unlock(int $maintenanceId): void
    {
        $doc = $this->find($maintenanceId);
        if (! $doc || ! $doc->locked_at) {
            return;
        }

        $archive = $this->absolute('incident-reports/'.$maintenanceId.'/_unlocked');
        File::ensureDirectoryExists($archive);
        foreach ([$doc->bbsc_path, $doc->report_path] as $rel) {
            if ($rel && is_file($this->absolute($rel))) {
                @rename($this->absolute($rel), $archive.'/'.basename($rel));
            }
        }

        DB::table(self::TABLE)->where('maintenance_id', $maintenanceId)->update([
            'locked_at' => null,
            'locked_by' => null,
            'bbsc_path' => null,
            'report_path' => null,
            'updated_at' => now(),
        ]);
    }

    /** rename() fails across filesystems (temp dir on another disk), so fall back to copy. */
    private static function moveFile(string $from, string $to): void
    {
        if (@rename($from, $to)) {
            return;
        }
        if (! @copy($from, $to)) {
            throw new RuntimeException('Không lưu được file '.basename($to));
        }
        @unlink($from);
    }

    private function absolute(string $relative): string
    {
        return storage_path('app/'.ltrim($relative, '/'));
    }
}

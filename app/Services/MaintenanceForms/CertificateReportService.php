<?php

namespace App\Services\MaintenanceForms;

use App\Models\Asset;
use App\Models\CustomField;
use App\Models\Location;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * "Certificate sắp hết hạn": reads the asset custom field "Hạn certificate"
 * (server certificate expiry) and warns MONTHS_AHEAD months before it ends.
 *
 * The field is found by name (accent/case-insensitive: contains
 * "certificate"/"cert" and "hạn"/"expir"), so renaming it slightly still
 * works. Encrypted custom fields cannot be read and are skipped.
 */
class CertificateReportService
{
    public const MONTHS_AHEAD = 3;

    /** Red when this close (or already expired). */
    public const URGENT_DAYS = 30;

    /**
     * @return array{fields: list<string>, rows: list<array<string, mixed>>, expired: int, soon: int, urgent: int, total: int}
     */
    public function report(?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::now())->copy()->startOfDay();

        $fields = [];
        foreach (CustomField::all() as $field) {
            if (! self::isCertificateField((string) $field->name)) {
                continue;
            }
            if ($field->field_encrypted) {
                Log::info('Bỏ qua custom field mã hoá: '.$field->name);

                continue;
            }
            $col = $field->db_column_name();
            if ($col && Schema::hasColumn('assets', $col)) {
                $fields[$col] = (string) $field->name;
            }
        }
        if (! $fields) {
            return self::summarize([], [], $today);
        }

        $rows = [];
        $cols = array_keys($fields);
        Asset::query()
            ->where(function ($q) use ($cols) {
                foreach ($cols as $c) {
                    $q->orWhere(fn ($w) => $w->whereNotNull($c)->where($c, '<>', ''));
                }
            })
            ->with([
                'model:id,name',
                'status:id,name',
                'location.parent',
                'defaultLoc',
                'assignedTo' => fn ($morph) => $morph->morphWith([Location::class => ['parent']]),
            ])
            ->select(array_merge(['id', 'name', 'asset_tag', 'serial', 'model_id', 'status_id', 'location_id', 'rtd_location_id', 'assigned_to', 'assigned_type', 'company_id'], $cols))
            ->orderBy('id')
            ->chunk(500, function ($assets) use (&$rows, $fields) {
                foreach ($assets as $asset) {
                    if (InventoryRepository::isBrokenStatus($asset->status?->name)) {
                        continue; // "Hư - không thể sử dụng" / thanh lý: no need to renew
                    }
                    [$site, $hall, $guessed] = MaintenanceFormExporter::siteAndHall($asset);
                    foreach ($fields as $col => $label) {
                        $raw = trim((string) $asset->{$col});
                        if ($raw === '') {
                            continue;
                        }
                        $rows[] = [
                            'id' => $asset->id,
                            'name' => (string) $asset->name,
                            'asset_tag' => (string) $asset->asset_tag,
                            'serial' => (string) $asset->serial,
                            'model' => (string) ($asset->model?->name ?? ''),
                            'status' => (string) ($asset->status?->name ?? ''),
                            'site' => $site,
                            'hall' => $hall,
                            'hall_guessed' => (bool) $guessed,
                            'field' => $label,
                            'raw' => $raw,
                            'expires' => self::parseDate($raw),
                        ];
                    }
                }
            });

        return self::summarize($rows, array_values($fields), $today);
    }

    public static function isCertificateField(string $name): bool
    {
        $n = Str::lower(Str::ascii($name));

        return (bool) (preg_match('/cert/', $n) && preg_match('/\bhan\b|het han|expir|valid/', $n));
    }

    /** Accepts 2027-08-29, 29/08/2027, 29-08-2027 … ; null if unreadable. */
    public static function parseDate(string $raw): ?Carbon
    {
        $raw = trim($raw);
        foreach (['Y-m-d', 'Y-m-d H:i:s', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d'] as $fmt) {
            $d = \DateTime::createFromFormat('!'.$fmt, $raw);
            if ($d && $d->format($fmt) === $raw) {
                return Carbon::parse($d->format('Y-m-d'));
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $fields
     * @return array{fields: list<string>, rows: list<array<string, mixed>>, unreadable: list<array<string, mixed>>, expired: int, soon: int, urgent: int, total: int}
     */
    public static function summarize(array $rows, array $fields, Carbon $today): array
    {
        $limit = $today->copy()->addMonths(self::MONTHS_AHEAD);
        $list = [];
        $unreadable = [];
        foreach ($rows as $r) {
            if (! $r['expires'] instanceof Carbon) {
                $unreadable[] = $r;

                continue;
            }
            if ($r['expires']->gt($limit)) {
                continue;
            }
            $r['days_left'] = (int) floor(($r['expires']->copy()->startOfDay()->getTimestamp() - $today->getTimestamp()) / 86400);
            $r['expires_label'] = $r['expires']->format('d/m/Y');
            $r['state'] = $r['days_left'] < 0 ? 'expired' : ($r['days_left'] <= self::URGENT_DAYS ? 'urgent' : 'soon');
            $list[] = $r;
        }
        usort($list, fn ($a, $b) => $a['days_left'] <=> $b['days_left'] ?: strnatcasecmp($a['site'], $b['site']));

        return [
            'fields' => $fields,
            'rows' => $list,
            'unreadable' => $unreadable,
            'expired' => count(array_filter($list, fn ($r) => $r['state'] === 'expired')),
            'urgent' => count(array_filter($list, fn ($r) => $r['state'] === 'urgent')),
            'soon' => count(array_filter($list, fn ($r) => $r['days_left'] >= 0)),
            'total' => count($list),
        ];
    }

    /** @param array<string, mixed> $report */
    public static function export(array $report, string $path): string
    {
        $rows = [];
        $styles = [];
        foreach ($report['rows'] as $i => $r) {
            $rows[] = [
                $i + 1,
                $r['site'] !== '' ? $r['site'] : InventoryRepository::NO_SITE,
                $r['hall'] !== '' ? 'Hall '.$r['hall'] : '',
                $r['name'],
                $r['model'],
                $r['asset_tag'],
                $r['serial'],
                $r['expires_label'],
                $r['days_left'] < 0 ? 'Đã hết hạn '.abs($r['days_left']).' ngày' : 'Còn '.$r['days_left'].' ngày',
            ];
            if ($r['state'] !== 'soon') {
                $styles[$i] = 'red';
            }
        }

        $w = new SimpleXlsxWriter;
        $w->addSheet('Certificate', ['STT', 'Rạp', 'Phòng', 'Thiết bị', 'Model', 'Mã TS', 'Serial', 'Hạn certificate', 'Tình trạng'], $rows, [
            'title' => 'CERTIFICATE HẾT HẠN TRONG '.self::MONTHS_AHEAD.' THÁNG TỚI',
            'subtitle' => 'Theo custom field: '.implode(', ', $report['fields']).'  |  Xuất lúc '.date('d/m/Y H:i'),
            'widths' => [5, 22, 9, 28, 18, 13, 18, 15, 20],
            'rowStyles' => $styles,
        ]);

        return $w->save($path);
    }
}

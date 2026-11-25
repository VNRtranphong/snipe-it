<?php

namespace App\Services\MaintenanceForms;

use Carbon\Carbon;

/**
 * Monthly / period summary workbook for the "Báo cáo sự cố" page:
 *  - Sheet "Danh sách sự cố": one line per incident, overdue lines in red.
 *  - Sheet "Theo site": totals per site.
 */
class IncidentSummaryExporter
{
    /**
     * @param  list<array<string, mixed>>  $rows  decorated rows (IncidentReportRepository)
     * @param  array{from: Carbon, to: Carbon, site: string, hall: string, equipment: string, status: string}  $f
     */
    public function build(array $rows, array $f, string $path): string
    {
        $period = $f['from']->format('d/m/Y').' – '.$f['to']->format('d/m/Y');
        $scope = array_filter([
            $f['site'] !== '' ? 'Site: '.$f['site'] : 'Tất cả site',
            $f['hall'] !== '' ? 'Hall '.$f['hall'] : null,
            $f['equipment'] !== '' ? $f['equipment'] : null,
            match ($f['status']) {
                IncidentReportRepository::STATUS_OPEN => 'Đang xử lý',
                IncidentReportRepository::STATUS_DONE => 'Hoàn thành',
                IncidentReportRepository::STATUS_OVERDUE => 'Quá '.IncidentReportRepository::OVERDUE_HOURS.'h',
                default => null,
            },
        ]);
        $subtitle = 'Thời gian: '.$period.'  |  '.implode(' · ', $scope).'  |  Xuất lúc '.Carbon::now()->format('d/m/Y H:i');

        $headers = [
            'STT', 'Bắt đầu', 'Hoàn thành', 'Site', 'Hall', 'Loại TB', 'Thiết bị', 'Model', 'Serial', 'Mã TS',
            'Sự cố', 'Mô tả / Ghi chú', 'Part / Loại', 'Bảo hành', 'Người phụ trách',
            'Thời gian xử lý (giờ)', 'Thời gian xử lý', 'Quá 24h', 'Số suất hủy', 'Trạng thái',
        ];

        $data = [];
        $styles = [];
        foreach ($rows as $i => $r) {
            $data[] = [
                $i + 1,
                self::dt($r['start_at'] ?? null),
                self::dt($r['completed_at_dt'] ?? null),
                $r['site'],
                $r['hall'],
                $r['equipment'],
                $r['asset_name'],
                MaintenanceFormExporter::modelLabel($r),
                $r['serial'],
                $r['asset_tag'],
                $r['name'],
                $r['description'],
                $r['type'],
                $r['is_warranty'] ? 'Có' : '',
                $r['technician'],
                $r['hours'] !== null ? round((float) $r['hours'], 1) : null,
                $r['duration_label'],
                $r['overdue'] ? 'Có' : '',
                (int) $r['cancelled_count'],
                $r['status_label'],
            ];
            if ($r['overdue']) {
                $styles[$i] = 'red';
            }
        }

        $stats = IncidentReportRepository::stats($rows);
        $total = array_fill(0, count($headers), null);
        $total[0] = 'Tổng';
        $total[10] = $stats['total'].' sự cố';
        $total[17] = $stats['overdue'];
        $total[18] = $stats['cancelled'];
        $total[19] = $stats['open'].' đang xử lý';

        $writer = new SimpleXlsxWriter;
        $writer->addSheet('Danh sách sự cố', $headers, $data, [
            'title' => 'TỔNG HỢP SỰ CỐ KỸ THUẬT',
            'subtitle' => $subtitle,
            'widths' => [5, 16, 16, 18, 6, 11, 28, 18, 16, 12, 24, 45, 14, 9, 18, 11, 15, 8, 9, 12],
            'rowStyles' => $styles,
            'totalRow' => $rows ? $total : null,
        ]);

        $siteRows = [];
        foreach (IncidentReportRepository::bySite($rows) as $s) {
            $siteRows[] = [$s['site'], $s['total'], $s['open'], $s['overdue'], $s['cancelled']];
        }
        $writer->addSheet('Theo site', ['Site', 'Số sự cố', 'Đang xử lý', 'Quá 24h', 'Số suất hủy'], $siteRows, [
            'title' => 'TỔNG HỢP THEO SITE',
            'subtitle' => 'Thời gian: '.$period,
            'widths' => [30, 12, 12, 12, 12],
            'totalRow' => $siteRows ? ['Tổng', $stats['total'], $stats['open'], $stats['overdue'], $stats['cancelled']] : null,
        ]);

        return $writer->save($path);
    }

    private static function dt(mixed $value): string
    {
        return $value instanceof Carbon ? $value->format('d/m/Y H:i') : '';
    }
}

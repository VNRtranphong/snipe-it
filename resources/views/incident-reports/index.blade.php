@extends('layouts/default')

{{-- Báo cáo sự cố: read-only list of maintenances + BBSC / Service Report exports --}}

@section('title')
    Báo cáo sự cố
    @parent
@stop

@push('css')
<style>
    /* Follow Snipe-IT light/dark mode (AdminLTE hard-codes .info-box to white). */
    .info-box { background-color: var(--box-bg, #ffffff) !important; color: var(--color-fg, #373636) !important; }
    .info-box .info-box-text, .info-box .info-box-number, .info-box .progress-description { color: inherit !important; }
    .ir-stats .info-box { min-height: 74px; margin-bottom: 12px; }
    .ir-stats .info-box-icon { height: 74px; line-height: 74px; width: 74px; font-size: 32px; }
    .ir-stats .info-box-content { margin-left: 74px; }
    .ir-stats .info-box-number { font-size: 22px; }
    .ir-filters .form-group { margin-right: 8px; margin-bottom: 8px; }
    .ir-stats a.ir-card { display: block; color: inherit; text-decoration: none; }
    @media (min-width: 1200px) { .ir-stats .ir-col5 { width: 20%; } .ir-stats .ir-col6 { width: 16.6667%; } }
    .ir-stats a.ir-card .info-box { transition: box-shadow .15s, transform .15s; }
    .ir-stats a.ir-card:hover .info-box { box-shadow: 0 3px 10px rgba(0,0,0,.18); transform: translateY(-1px); }
    .ir-stats a.ir-card.active .info-box { box-shadow: 0 0 0 3px #3c8dbc; }
    .ir-filters label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 2px; }
    .ir-table td { vertical-align: top !important; }
    .ir-table tr.ir-overdue td { background-color: rgba(221, 75, 57, 0.08); }
    .ir-table .ir-desc { max-width: 380px; white-space: pre-line; font-size: 12px; opacity: .85; }
    .ir-table .ir-dur-overdue { color: #dd4b39; font-weight: 700; }
    .ir-table .ir-actions { white-space: nowrap; }
    .ir-table .ir-docno { font-size: 12px; white-space: nowrap; }
    .ir-trend-wrap { position: relative; height: 280px; }
    .ir-heat th, .ir-heat td { text-align: center; font-size: 12px; padding: 4px 6px !important; }
    .ir-heat td.ir-heat-site, .ir-heat th.ir-heat-site { text-align: left; white-space: nowrap; }
    .ir-heat .ir-heat-hidden { display: none; }
    .ir-heat a.ir-heat-link { color: inherit; display: block; text-decoration: none; }
    .ir-heat a.ir-heat-link:hover { text-decoration: underline; }
    .ir-heat td:hover { outline: 2px solid #3c8dbc; outline-offset: -2px; }
    /* Each month = 2 cells: số sự cố (blue, like the chart bars) | suất hủy Tech (purple, like the chart line). */
    .ir-heat td.ir-hi { border-right-style: dashed !important; border-right-color: rgba(128,128,128,.35) !important; }
    .ir-heat td.ir-hs { border-left: 0 !important; }
    .ir-heat th.ir-hsub { font-size: 10px; font-weight: 600; padding: 2px 4px !important; opacity: .85; }
    .ir-heat th.ir-hsub.i { color: #3c8dbc; }
    .ir-heat th.ir-hsub.s { color: #8f8bd6; }
    .ir-heat-legend span { display: inline-block; width: 12px; height: 12px; vertical-align: -2px; margin: 0 4px 0 10px; border-radius: 2px; }
    .ir-bulkbar { margin-bottom: 10px; }
    .ir-bulkbar .btn { margin-right: 4px; margin-bottom: 4px; }
</style>
@endpush

@section('content')
@php
    $fmt = fn ($d) => $d ? $d->format('d/m/Y H:i') : '';
    $q = request()->query();
@endphp

<div class="row">
    <div class="col-md-12">

        @unless ($docsReady)
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                Chưa có bảng số biên bản. Admin cần chạy <code>php artisan migrate</code> trên server.
                Trong lúc chờ, BBSC vẫn xuất được nhưng dùng số tạm theo ID, và chưa chốt được biên bản.
            </div>
        @endunless

        {{-- ====== Số liệu (bấm để lọc danh sách) ====== --}}
        @php
            $cardBase = [
                'from' => $filters['from']->format('Y-m-d'),
                'to' => $filters['to']->format('Y-m-d'),
                'site' => $filters['site'],
                'hall' => $filters['hall'],
                'equipment' => $filters['equipment'],
                'q' => $filters['q'],
                'kind' => $filters['kind'] !== 'incident' ? $filters['kind'] : '',
                'strict' => $filters['strict'] ? '1' : '',
            ];
            $cardBase = array_filter($cardBase, fn ($v) => $v !== '') + ['site' => ''];
            $cards = [
                ['status' => '', 'color' => 'bg-blue', 'icon' => 'fa-list-ul', 'label' => 'Tổng sự cố',
                 'number' => $stats['total'], 'note' => null],
                ['status' => 'open', 'color' => 'bg-yellow', 'icon' => 'fa-tools', 'label' => 'Đang xử lý',
                 'number' => $stats['open'], 'note' => null],
                ['status' => 'overdue', 'color' => 'bg-red', 'icon' => 'fa-exclamation-triangle', 'label' => 'Quá '.$overdueHours.'h',
                 'number' => $stats['overdue'], 'note' => $stats['overdue_open'].' vẫn chưa xong'],
                ['status' => 'cancelled', 'color' => 'bg-purple', 'icon' => 'fa-film', 'label' => 'Suất bị hủy (Tech)',
                 'number' => $stats['cancelled'], 'note' => 'trong '.$stats['cancelled_incidents'].' sự cố · Rạp: '.($stats['cancelled_cinema'] ?? 0).' suất'],
            ];
        @endphp
        @php
            $extraCards = ($licenses !== null ? 1 : 0) + (! empty($certs['fields']) ? 1 : 0);
            $colClass = $extraCards === 2 ? 'ir-col6 col-lg-3' : ($extraCards === 1 ? 'ir-col5 col-lg-3' : 'col-lg-3');
        @endphp
        <div class="row ir-stats">
            @foreach ($cards as $card)
                <div class="{{ $colClass }} col-sm-6 col-xs-6">
                    <a href="{{ route('incident-reports.index', $cardBase + ($card['status'] !== '' ? ['status' => $card['status']] : [])) }}#ir-list"
                       class="ir-card{{ $filters['status'] === $card['status'] ? ' active' : '' }}"
                       title="Xem danh sách: {{ $card['label'] }}">
                        <div class="info-box">
                            <span class="info-box-icon {{ $card['color'] }}"><i class="fas {{ $card['icon'] }}" aria-hidden="true"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">{{ $card['label'] }}</span>
                                <span class="info-box-number">{{ number_format($card['number']) }}</span>
                                @if ($card['note'])
                                    <span class="progress-description" style="font-size:12px">{{ $card['note'] }}</span>
                                @endif
                            </div>
                        </div>
                    </a>
                </div>
            @endforeach
            @if ($licenses !== null)
                <div class="{{ $colClass }} col-sm-6 col-xs-6">
                    <a href="#ir-licenses" class="ir-card" title="Xem báo cáo License">
                        <div class="info-box">
                            <span class="info-box-icon {{ $licenses['expired'] ? 'bg-red' : ($licenses['soon_card'] ? 'bg-orange' : 'bg-green') }}"><i class="far fa-save" aria-hidden="true"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text" title="License sắp hết hạn">License</span>
                                <span class="info-box-number">{{ $licenses['soon_card'] }}</span>
                                <span class="progress-description" style="font-size:12px" title="Sắp hết hạn trong {{ \App\Services\MaintenanceForms\LicenseReportService::CARD_DAYS }} ngày; {{ $licenses['expired'] }} đã hết hạn">≤{{ \App\Services\MaintenanceForms\LicenseReportService::CARD_DAYS }} ngày · {{ $licenses['expired'] }} quá hạn</span>
                            </div>
                        </div>
                    </a>
                </div>
            @endif
            @if (! empty($certs['fields']))
                <div class="{{ $colClass }} col-sm-6 col-xs-6">
                    <a href="#ir-certs" class="ir-card" title="Xem certificate sắp hết hạn">
                        <div class="info-box">
                            <span class="info-box-icon {{ ($certs['expired'] || $certs['urgent']) ? 'bg-red' : ($certs['soon'] ? 'bg-orange' : 'bg-green') }}"><i class="fas fa-certificate" aria-hidden="true"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text" title="Certificate sắp hết hạn">Certificate</span>
                                <span class="info-box-number">{{ $certs['soon'] }}</span>
                                <span class="progress-description" style="font-size:12px" title="Hết hạn trong {{ \App\Services\MaintenanceForms\CertificateReportService::MONTHS_AHEAD }} tháng tới; {{ $certs['expired'] }} đã hết hạn">≤{{ \App\Services\MaintenanceForms\CertificateReportService::MONTHS_AHEAD }} tháng · {{ $certs['expired'] }} quá hạn</span>
                            </div>
                        </div>
                    </a>
                </div>
            @endif
        </div>

        {{-- ====== Bộ lọc ====== --}}
        <div class="box box-default">
            <div class="box-body">
                @if ($filters['strict'])
                    <div class="alert alert-info" style="padding:8px 12px; margin-bottom:10px">
                        <i class="fas fa-info-circle" aria-hidden="true"></i>
                        Đang chỉ hiện sự cố <strong>bắt đầu</strong> trong khoảng ngày đã chọn (không kèm sự cố cũ chưa xong), để khớp với số trong bảng rạp × tháng.
                        <a href="{{ route('incident-reports.index', array_merge(request()->query(), ['strict' => null, 'site' => $filters['site']])) }}" class="btn btn-default btn-xs" style="margin-left:6px">Hiện cả sự cố cũ chưa xong</a>
                    </div>
                @endif
                <form method="GET" action="{{ route('incident-reports.index') }}" class="form-inline ir-filters" id="ir-filter-form">
                    @if ($filters['strict'])<input type="hidden" name="strict" value="1">@endif
                    <div class="form-group">
                        <label for="ir-month">Tháng</label>
                        <input type="month" id="ir-month" class="form-control input-sm"
                               value="{{ $filters['from']->format('Y-m') }}">
                    </div>
                    <div class="form-group">
                        <label for="ir-from">Từ ngày</label>
                        <input type="date" id="ir-from" name="from" class="form-control input-sm" value="{{ $filters['from']->format('Y-m-d') }}">
                    </div>
                    <div class="form-group">
                        <label for="ir-to">Đến ngày</label>
                        <input type="date" id="ir-to" name="to" class="form-control input-sm" value="{{ $filters['to']->format('Y-m-d') }}">
                    </div>
                    <div class="form-group">
                        <label for="ir-site">Site</label>
                        <select id="ir-site" name="site" class="form-control input-sm">
                            <option value="">Tất cả site</option>
                            @foreach ($options['sites'] as $site)
                                <option value="{{ $site }}" @selected(mb_strtolower($site) === mb_strtolower($filters['site']))>{{ $site }}</option>
                            @endforeach
                            @if ($filters['site'] !== '' && ! collect($options['sites'])->contains(fn ($s) => mb_strtolower($s) === mb_strtolower($filters['site'])))
                                <option value="{{ $filters['site'] }}" selected>{{ $filters['site'] }}</option>
                            @endif
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ir-hall">Hall</label>
                        <select id="ir-hall" name="hall" class="form-control input-sm">
                            <option value="">Tất cả</option>
                            @foreach ($options['halls'] as $hall)
                                <option value="{{ $hall }}" @selected($hall === $filters['hall'])>Hall {{ $hall }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ir-equipment">Loại thiết bị</label>
                        <select id="ir-equipment" name="equipment" class="form-control input-sm">
                            <option value="">Tất cả</option>
                            @foreach ($options['equipment'] as $eq)
                                <option value="{{ $eq }}" @selected($eq === $filters['equipment'])>{{ $eq }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ir-kind">Loại phiếu</label>
                        <select id="ir-kind" name="kind" class="form-control input-sm">
                            <option value="incident" @selected($filters['kind'] === 'incident')>Sự cố</option>
                            <option value="maintenance" @selected($filters['kind'] === 'maintenance')>Bảo trì / bảo dưỡng</option>
                            <option value="all" @selected($filters['kind'] === 'all')>Tất cả</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ir-status">Trạng thái</label>
                        <select id="ir-status" name="status" class="form-control input-sm">
                            <option value="">Tất cả</option>
                            <option value="open" @selected($filters['status'] === 'open')>Đang xử lý</option>
                            <option value="done" @selected($filters['status'] === 'done')>Hoàn thành</option>
                            <option value="overdue" @selected($filters['status'] === 'overdue')>Quá {{ $overdueHours }}h</option>
                            <option value="cancelled" @selected($filters['status'] === 'cancelled')>Có hủy suất</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ir-q">Tìm</label>
                        <input type="text" id="ir-q" name="q" class="form-control input-sm" placeholder="Tên, serial, mã TS…" value="{{ $filters['q'] }}">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter" aria-hidden="true"></i> Lọc</button>
                        <a href="{{ route('incident-reports.index', ['site' => '']) }}" class="btn btn-default btn-sm">Xóa lọc</a>
                    </div>
                </form>
                <p class="text-muted" style="margin:6px 0 0; font-size:12px">
                    Lọc theo ngày bắt đầu sự cố. Sự cố chưa xong từ các tháng trước vẫn được hiện để không bị sót.
                    Phiếu có Type hoặc tiêu đề chứa "bảo trì" / "bảo dưỡng" là <strong>bảo trì</strong>, không tính là sự cố (chọn Loại phiếu để xem).
                    Thời gian xử lý tính liên tục 24h, từ Start Date đến Completed At (chưa xong thì tính đến hiện tại).
                </p>
            </div>
        </div>

        {{-- ====== Xu hướng 12 tháng ====== --}}
        @php
            $heatMax = 0;
            $heatCancelMax = 0;
            foreach ($trend['sites'] as $ts) {
                $heatMax = max($heatMax, max($ts['counts'] ?: [0]));
                $heatCancelMax = max($heatCancelMax, max($ts['cancelled'] ?: [0]));
            }
            // Same colours as the trend chart: bars = số sự cố (blue), line = suất bị hủy Tech (purple).
            $heatCell = function (int $value, int $max, string $rgb): string {
                if ($value <= 0 || $max <= 0) {
                    return '';
                }
                $alpha = round(0.15 + 0.7 * $value / $max, 2);

                return 'background-color: rgba('.$rgb.','.$alpha.');'.($alpha > 0.5 ? 'color:#fff;font-weight:600;' : '');
            };
            $heatTop = 15;
        @endphp
        <div class="box box-default" id="ir-trend">
            <div class="box-header with-border">
                <h2 class="box-title"><i class="fas fa-chart-line" aria-hidden="true"></i>
                    Xu hướng 12 tháng ({{ $trend['labels'][0] }} – {{ end($trend['labels']) }})
                </h2>
                <div class="box-tools pull-right">
                    <button type="button" class="btn btn-box-tool" data-widget="collapse" aria-label="Thu gọn"><i class="fas fa-minus" aria-hidden="true"></i></button>
                </div>
            </div>
            <div class="box-body">
                <p class="text-muted" style="font-size:12px; margin-top:0">
                    Theo site / hall / loại thiết bị / ô tìm đang lọc, tính theo tháng của ngày bắt đầu sự cố.
                    Đổi "Đến ngày" để dời mốc 12 tháng.
                </p>
                <div class="ir-trend-wrap"><canvas id="ir-trend-chart" aria-label="Biểu đồ số sự cố và suất hủy theo tháng" role="img"></canvas></div>

                @if (count($trend['sites']))
                    <h4 style="margin-top:18px">Số sự cố &amp; suất hủy theo rạp × tháng
                        <small class="ir-heat-legend"><span style="background:rgba(60,141,188,.85)"></span>Số sự cố<span style="background:rgba(96,92,168,.85)"></span>Suất bị hủy (Tech)</small>
                    </h4>
                    <div class="table-responsive">
                        <table class="table table-bordered table-condensed ir-heat">
                            <thead>
                                <tr>
                                    <th class="ir-heat-site" rowspan="2" style="vertical-align:bottom">Rạp</th>
                                    @foreach ($trend['labels'] as $lbl)
                                        <th colspan="2">{{ $lbl }}</th>
                                    @endforeach
                                    <th colspan="2">Tổng</th>
                                </tr>
                                <tr>
                                    @foreach ($trend['labels'] as $lbl)
                                        <th class="ir-hsub i" title="Số sự cố">SC</th>
                                        <th class="ir-hsub s" title="Suất bị hủy (Tech)">Hủy</th>
                                    @endforeach
                                    <th class="ir-hsub i" title="Số sự cố">SC</th>
                                    <th class="ir-hsub s" title="Suất bị hủy (Tech)">Hủy</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    // Same filters as the chart (except site/dates/status) so the list matches the number.
                                    $heatBase = array_filter([
                                        'hall' => $filters['hall'],
                                        'equipment' => $filters['equipment'],
                                        'q' => $filters['q'],
                                        'kind' => $filters['kind'] !== 'incident' ? $filters['kind'] : '',
                                    ], fn ($v) => $v !== '') + ['strict' => 1];
                                    $heatLink = function (string $site, string $from, string $to) use ($heatBase) {
                                        return route('incident-reports.index', $heatBase + ['site' => $site, 'from' => $from, 'to' => $to]).'#ir-list';
                                    };
                                    $monthFrom = fn ($key) => $key.'-01';
                                    $monthTo = fn ($key) => \Carbon\Carbon::parse($key.'-01')->endOfMonth()->format('Y-m-d');
                                    $firstKey = $trend['keys'][0];
                                    $lastKey = end($trend['keys']);
                                @endphp
                                @foreach ($trend['sites'] as $i => $ts)
                                    @php $linkable = $ts['site'] !== '(Chưa rõ site)'; @endphp
                                    <tr class="{{ $i >= $heatTop ? 'ir-heat-hidden' : '' }}">
                                        <td class="ir-heat-site">
                                            @if ($linkable)
                                                <a href="{{ $heatLink($ts['site'], $monthFrom($firstKey), $monthTo($lastKey)) }}" title="Xem sự cố 12 tháng của {{ $ts['site'] }}">{{ $ts['site'] }}</a>
                                            @else
                                                {{ $ts['site'] }}
                                            @endif
                                        </td>
                                        @foreach ($ts['counts'] as $mi => $c)
                                            @php $sc = (int) ($ts['cancelled'][$mi] ?? 0); @endphp
                                            <td class="ir-hi" style="{{ $heatCell($c, $heatMax, '60,141,188') }}">
                                                @if ($c > 0 && $linkable)
                                                    <a href="{{ $heatLink($ts['site'], $monthFrom($trend['keys'][$mi]), $monthTo($trend['keys'][$mi])) }}" class="ir-heat-link"
                                                       title="Xem {{ $c }} sự cố của {{ $ts['site'] }} tháng {{ $trend['labels'][$mi] }}">{{ $c }}</a>
                                                @else
                                                    {{ $c ?: '' }}
                                                @endif
                                            </td>
                                            <td class="ir-hs" style="{{ $heatCell($sc, $heatCancelMax, '96,92,168') }}" title="{{ $c > 0 ? $sc.' suất bị hủy (Tech)' : '' }}">{{ $c > 0 ? $sc : '' }}</td>
                                        @endforeach
                                        <td class="ir-hi">
                                            @if ($linkable)
                                                <a href="{{ $heatLink($ts['site'], $monthFrom($firstKey), $monthTo($lastKey)) }}" class="ir-heat-link" title="Xem cả {{ $ts['total'] }} sự cố"><strong>{{ $ts['total'] }}</strong></a>
                                            @else
                                                <strong>{{ $ts['total'] }}</strong>
                                            @endif
                                        </td>
                                        <td class="ir-hs"><strong>{{ $ts['cancelled_total'] ?? 0 }}</strong></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if (count($trend['sites']) > $heatTop)
                        <button type="button" class="btn btn-default btn-xs" id="ir-heat-more">Xem tất cả {{ count($trend['sites']) }} rạp</button>
                    @endif
                @else
                    <p class="text-muted">Chưa có sự cố nào trong 12 tháng này.</p>
                @endif
            </div>
        </div>

        {{-- ====== Danh sách ====== --}}
        <div class="box box-default" id="ir-list">
            <div class="box-body">
                <form method="POST" action="{{ route('incident-reports.bulk') }}" id="ir-bulk-form">
                    @csrf
                    <div class="ir-bulkbar">
                        <a href="{{ route('incident-reports.summary', $q + ['site' => $filters['site']]) }}" class="btn btn-success btn-sm">
                            <i class="fas fa-file-excel" aria-hidden="true"></i> Xuất tổng hợp (Excel)
                        </a>
                        <span class="text-muted" style="margin:0 6px">|</span>
                        <span>Đã chọn <strong id="ir-selected-count">0</strong>:</span>
                        <button type="submit" name="type" value="bbsc" class="btn btn-sm bg-orange ir-needs-selection" disabled>
                            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Tải BBSC (zip)
                        </button>
                        <button type="submit" name="type" value="report" class="btn btn-sm btn-info ir-needs-selection" disabled>
                            <i class="fas fa-file-alt" aria-hidden="true"></i> Tải Service Report (zip)
                        </button>
                        <button type="submit" name="type" value="both" class="btn btn-sm btn-default ir-needs-selection" disabled>
                            <i class="fas fa-file-archive" aria-hidden="true"></i> Tải cả hai
                        </button>
                    </div>

                    @if ($truncated)
                        <div class="alert alert-warning">
                            Khoảng thời gian quá rộng, chỉ hiện {{ number_format(\App\Services\MaintenanceForms\IncidentReportRepository::MAX_ROWS) }} sự cố mới nhất. Hãy thu hẹp khoảng ngày.
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-striped table-hover table-condensed ir-table">
                            <thead>
                                <tr>
                                    <th style="width:28px"><input type="checkbox" id="ir-check-all" aria-label="Chọn tất cả"></th>
                                    <th>Bắt đầu</th>
                                    <th>Site / Hall</th>
                                    <th>Thiết bị</th>
                                    <th>Sự cố</th>
                                    <th>Thời gian xử lý</th>
                                    <th class="text-center">Suất hủy<br><small>Tech</small></th>
                                    <th class="text-center">Suất hủy<br><small>Rạp</small></th>
                                    <th>Phụ trách</th>
                                    <th>Trạng thái</th>
                                    <th>Biên bản</th>
                                    <th class="text-right">Xuất</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($rows as $r)
                                <tr class="{{ $r['overdue'] ? 'ir-overdue' : '' }}">
                                    <td><input type="checkbox" name="ids[]" value="{{ $r['id'] }}" class="ir-check" aria-label="Chọn sự cố {{ $r['id'] }}"></td>
                                    <td style="white-space:nowrap">{{ $fmt($r['start_at']) }}</td>
                                    <td>
                                        {{ $r['site'] ?: '—' }}
                                        @if ($r['hall_label'])<br><small class="text-muted">{{ $r['hall_label'] }}@if (! empty($r['hall_guessed']))<span title="Đoán từ tên thiết bị (thiết bị chưa được giao vào Location Hall)">*</span>@endif</small>@endif
                                    </td>
                                    <td>
                                        {{ $r['asset_name'] }}
                                        <br><small class="text-muted">{{ $r['equipment'] }}@if ($r['asset_tag']) · {{ $r['asset_tag'] }}@endif</small>
                                        @if ($r['model_id'])
                                            <br><small><a href="{{ route('equipment-lookup.index', ['model_id' => $r['model_id'], 'category_id' => $r['category_id']]) }}" title="Xem tất cả thiết bị cùng model">
                                                <i class="fas fa-boxes" aria-hidden="true"></i> {{ $r['model'] ?: 'Model' }}
                                            </a></small>
                                        @endif
                                    </td>
                                    <td>
                                        <a href="{{ route('maintenances.show', $r['id']) }}"><strong>{{ $r['name'] }}</strong></a>
                                        @if ($r['is_warranty'])<span class="label label-primary" style="margin-left:4px">Bảo hành</span>@endif
                                        @if ($r['description'] !== '')
                                            <div class="ir-desc">{{ \Illuminate\Support\Str::limit($r['description'], 220) }}</div>
                                        @endif
                                    </td>
                                    <td class="{{ $r['overdue'] ? 'ir-dur-overdue' : '' }}" style="white-space:nowrap">
                                        {{ $r['duration_label'] ?: '—' }}
                                        @if ($r['done'])
                                            <br><small class="text-muted">xong {{ $fmt($r['completed_at_dt']) }}</small>
                                        @endif
                                    </td>
                                    <td class="text-center">{{ $r['cancelled_count'] ?: '' }}</td>
                                    <td class="text-center">{{ ($r['cancelled_cinema_count'] ?? 0) ?: '' }}</td>
                                    <td>{{ $r['technician'] }}</td>
                                    <td>
                                        @if (! empty($r['is_maintenance']))
                                            <span class="label label-info">Bảo trì</span><br>
                                        @endif
                                        @if ($r['done'])
                                            <span class="label label-success">Hoàn thành</span>
                                        @else
                                            <span class="label label-warning">Đang xử lý</span>
                                        @endif
                                        @if ($r['overdue'])
                                            <br><span class="label label-danger">Quá {{ $overdueHours }}h</span>
                                        @endif
                                    </td>
                                    @php $doc = $docs[$r['id']] ?? null; @endphp
                                    <td class="ir-docno">
                                        @if ($doc)
                                            {{ $doc->number }}
                                            @if ($doc->locked_at)
                                                <br><span class="label label-default" title="Tải về sẽ ra đúng bản đã chốt">
                                                    <i class="fas fa-lock" aria-hidden="true"></i> Đã chốt {{ \Carbon\Carbon::parse($doc->locked_at)->format('d/m/Y') }}
                                                </span>
                                            @endif
                                        @else
                                            <span class="text-muted" title="Số được cấp ở lần xuất BBSC đầu tiên">chưa cấp số</span>
                                        @endif
                                    </td>
                                    <td class="text-right ir-actions">
                                        @if ($doc && $doc->locked_at)
                                            @if ($canUnlock)
                                                <button type="submit" formaction="{{ route('incident-reports.unlock', $r['id']) }}" class="btn btn-sm btn-default ir-confirm"
                                                        data-confirm="Mở chốt biên bản {{ $doc->number }}? Lần tải sau sẽ tạo lại từ dữ liệu hiện tại." data-tooltip="true" title="Mở chốt (admin)">
                                                    <i class="fas fa-lock-open fa-fw" aria-hidden="true"></i><span class="sr-only">Mở chốt</span>
                                                </button>
                                            @endif
                                        @elseif ($canLock)
                                            <button type="submit" formaction="{{ route('incident-reports.lock', $r['id']) }}" class="btn btn-sm btn-default ir-confirm"
                                                    data-confirm="Chốt biên bản cho sự cố &quot;{{ $r['name'] }}&quot;? Sau khi chốt, tải về luôn ra bản này dù phiếu có bị sửa." data-tooltip="true" title="Chốt biên bản">
                                                <i class="fas fa-lock fa-fw" aria-hidden="true"></i><span class="sr-only">Chốt</span>
                                            </button>
                                        @endif
                                        <a href="{{ route('incident-reports.incident', $r['id']) }}" class="btn btn-sm bg-orange" data-tooltip="true" title="Xuất biên bản sự cố">
                                            <i class="fas fa-exclamation-triangle fa-fw" aria-hidden="true"></i><span class="sr-only">BBSC</span>
                                        </a>
                                        <a href="{{ route('incident-reports.service', $r['id']) }}" class="btn btn-sm btn-info" data-tooltip="true" title="Xuất Service Report">
                                            <i class="fas fa-file-alt fa-fw" aria-hidden="true"></i><span class="sr-only">Service Report</span>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="12" class="text-center text-muted" style="padding:24px">Không có sự cố nào khớp bộ lọc.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </div>

        {{-- ====== Certificate sắp hết hạn (custom field "Hạn certificate") ====== --}}
        @if (! empty($certs['fields']))
            <div class="box box-default" id="ir-certs">
                <div class="box-header with-border">
                    <h2 class="box-title"><i class="fas fa-certificate" aria-hidden="true"></i>
                        Certificate hết hạn trong {{ \App\Services\MaintenanceForms\CertificateReportService::MONTHS_AHEAD }} tháng tới ({{ $certs['total'] }})
                    </h2>
                    <div class="box-tools pull-right">
                        <a href="{{ route('incident-reports.certificates.export') }}" class="btn btn-success btn-xs"><i class="fas fa-file-excel" aria-hidden="true"></i> Xuất Excel</a>
                    </div>
                </div>
                <div class="box-body">
                    @if (count($certs['rows']))
                        <div class="table-responsive">
                            <table class="table table-striped table-hover table-condensed">
                                <thead>
                                    <tr><th>Rạp</th><th>Phòng</th><th>Thiết bị</th><th>Model</th><th>Hạn certificate</th><th>Còn lại</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($certs['rows'] as $c)
                                        <tr>
                                            <td>{{ $c['site'] ?: \App\Services\MaintenanceForms\InventoryRepository::NO_SITE }}</td>
                                            <td>{{ $c['hall'] !== '' ? 'Hall '.$c['hall'].($c['hall_guessed'] ? '*' : '') : '' }}</td>
                                            <td><a href="{{ url('hardware/'.$c['id']) }}">{{ $c['name'] ?: $c['asset_tag'] }}</a></td>
                                            <td>{{ $c['model'] }}</td>
                                            <td style="white-space:nowrap">{{ $c['expires_label'] }}</td>
                                            <td style="white-space:nowrap">
                                                @if ($c['state'] === 'expired')
                                                    <span class="label label-danger">Đã hết hạn {{ abs($c['days_left']) }} ngày</span>
                                                @elseif ($c['state'] === 'urgent')
                                                    <span class="label label-danger">Còn {{ $c['days_left'] }} ngày</span>
                                                @else
                                                    <span class="label label-warning">Còn {{ $c['days_left'] }} ngày</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-muted" style="margin:0">Không có thiết bị nào hết hạn certificate trong {{ \App\Services\MaintenanceForms\CertificateReportService::MONTHS_AHEAD }} tháng tới.</p>
                    @endif
                    @if (count($certs['unreadable']))
                        <p class="text-danger" style="font-size:12px; margin:8px 0 0">
                            {{ count($certs['unreadable']) }} thiết bị có ô hạn certificate không đọc được ngày:
                            @foreach (array_slice($certs['unreadable'], 0, 8) as $ui => $u)
                                @if ($ui > 0), @endif<a href="{{ url('hardware/'.$u['id']) }}">{{ $u['name'] ?: $u['asset_tag'] }}</a> ("{{ $u['raw'] }}")
                            @endforeach
                        </p>
                    @endif
                    <p class="text-muted" style="font-size:12px; margin:6px 0 0">
                        Lấy từ custom field: {{ implode(', ', $certs['fields']) }}. Đỏ = đã hết hạn hoặc còn ≤ {{ \App\Services\MaintenanceForms\CertificateReportService::URGENT_DAYS }} ngày; cam = còn dưới {{ \App\Services\MaintenanceForms\CertificateReportService::MONTHS_AHEAD }} tháng.
                        Không tính thiết bị "Hư - không thể sử dụng" / đã thanh lý.
                    </p>
                </div>
            </div>
        @endif

        {{-- ====== Báo cáo License ====== --}}
        @if ($licenses !== null)
            <div class="box box-default" id="ir-licenses">
                <div class="box-header with-border">
                    <h2 class="box-title"><i class="far fa-save" aria-hidden="true"></i>
                        Báo cáo License – đã hết hạn &amp; sắp hết hạn trong {{ \App\Services\MaintenanceForms\LicenseReportService::SOON_DAYS }} ngày
                    </h2>
                    <div class="box-tools pull-right">
                        <a href="{{ route('incident-reports.licenses.export') }}" class="btn btn-success btn-xs"><i class="fas fa-file-excel" aria-hidden="true"></i> Xuất danh sách cần mua (Excel)</a>
                        <a href="{{ url('licenses') }}" class="btn btn-default btn-xs">Mở trang Licenses</a>
                    </div>
                </div>
                <div class="box-body">
                    @if (count($licenses['rows']))
                        <div class="table-responsive">
                            <table class="table table-striped table-hover table-condensed">
                                <thead>
                                    <tr>
                                        <th>License</th>
                                        <th>Nhà SX / Loại</th>
                                        <th>Ngày kết thúc</th>
                                        <th>Còn lại</th>
                                        <th>Cần mua tiếp</th>
                                        <th>Đang gắn cho</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($licenses['rows'] as $lic)
                                        <tr>
                                            <td><a href="{{ url('licenses/'.$lic['id']) }}"><strong>{{ $lic['name'] }}</strong></a></td>
                                            <td>{{ $lic['manufacturer'] }}@if ($lic['category'])<br><small class="text-muted">{{ $lic['category'] }}</small>@endif</td>
                                            <td style="white-space:nowrap">{{ $lic['end_label'] }}<br><small class="text-muted">{{ $lic['end_kind'] }}</small></td>
                                            <td style="white-space:nowrap">
                                                @if ($lic['state'] === 'expired')
                                                    <span class="label label-danger">Quá hạn {{ abs($lic['days_left']) }} ngày</span>
                                                @elseif ($lic['days_left'] === 0)
                                                    <span class="label label-danger">Hết hạn hôm nay</span>
                                                @elseif ($lic['state'] === 'soon')
                                                    <span class="label label-warning">Còn {{ $lic['days_left'] }} ngày</span>
                                                @else
                                                    <span class="label label-default">Còn {{ $lic['days_left'] }} ngày</span>
                                                @endif
                                            </td>
                                            <td style="font-size:12px">
                                                @forelse ($lic['buy'] ?? [] as $b)
                                                    <strong>1 license {{ $lic['product'] }}</strong> cho rạp <strong>{{ $b['site'] }}</strong>,
                                                    @if ($b['rooms'] !== null)
                                                        số lượng <strong>{{ $b['rooms'] }} phòng</strong>
                                                    @else
                                                        <span class="text-danger">chưa rõ số phòng</span>
                                                    @endif
                                                    <br>
                                                @empty
                                                    <span class="text-danger">Chưa xác định rạp</span><br>
                                                    <small class="text-muted">Gán seat cho thiết bị ở rạp, hoặc ghi tên rạp vào ô Licensed To / tên license</small>
                                                @endforelse
                                                @if (! empty($lic['source']))
                                                    <small class="text-muted">({{ \App\Services\MaintenanceForms\LicenseReportService::SOURCE_LABELS[$lic['source']] }})</small>
                                                @endif
                                            </td>
                                            <td style="font-size:12px">
                                                @forelse (array_slice($lic['where'], 0, 6) as $w)
                                                    {{ $w }}<br>
                                                @empty
                                                    <span class="text-muted">Chưa gắn cho thiết bị / người nào</span>
                                                @endforelse
                                                @if (count($lic['where']) > 6)
                                                    <span class="text-muted">… và {{ count($lic['where']) - 6 }} nơi khác</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if (count($licenses['products']))
                            <p style="margin:8px 0 0">
                                <strong>Tổng cần mua:</strong>
                                @foreach ($licenses['products'] as $pr)
                                    <span class="label label-primary" style="font-size:12px; margin-right:6px">{{ $pr['product'] }}: {{ $pr['licenses'] }} license / {{ $pr['rooms'] }} phòng</span>
                                @endforeach
                            </p>
                        @endif
                        <p class="text-muted" style="font-size:12px; margin:6px 0 0">
                            Mỗi license mua theo rạp, số lượng = số phòng của rạp (lấy từ trang Tra cứu thiết bị).
                            Rạp của license xác định theo: seat đã gán → ô Licensed To → tên license (tên rạp hoặc mã rạp, vd "AAM ND" = Nguyễn Du).
                            Ngày kết thúc = ngày sớm hơn giữa Expiration Date và Termination Date. Không hiển thị product key.
                        </p>
                    @else
                        <p class="text-muted" style="margin:0">
                            Không có license nào đã hết hạn hoặc sắp hết hạn trong {{ \App\Services\MaintenanceForms\LicenseReportService::SOON_DAYS }} ngày tới
                            (chỉ tính license có nhập Expiration Date / Termination Date).
                        </p>
                    @endif
                </div>
            </div>
        @endif

    </div>
</div>
@stop

@section('moar_scripts')
<script src="{{ url(mix('js/dist/Chart.min.js')) }}"></script>
<script nonce="{{ csrf_token() }}">
    // ====== Chốt / mở chốt: confirm ======
    (function () {
        Array.prototype.forEach.call(document.querySelectorAll('.ir-confirm'), function (btn) {
            btn.addEventListener('click', function (e) {
                if (!window.confirm(btn.getAttribute('data-confirm'))) { e.preventDefault(); }
            });
        });
    })();

    // ====== Xu hướng 12 tháng ======
    (function () {
        var trend = @json($trend);
        var more = document.getElementById('ir-heat-more');
        if (more) {
            more.addEventListener('click', function () {
                Array.prototype.forEach.call(document.querySelectorAll('.ir-heat-hidden'), function (tr) { tr.classList.remove('ir-heat-hidden'); });
                more.style.display = 'none';
            });
        }

        var canvas = document.getElementById('ir-trend-chart');
        if (!canvas || typeof Chart === 'undefined') { return; }
        var dark = (typeof isDark === 'function') && isDark();
        var fg = dark ? '#cccccc' : '#666666';
        var grid = dark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
        Chart.defaults.global.defaultFontColor = fg;

        new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: trend.labels,
                datasets: [
                    { type: 'line', label: 'Suất bị hủy (Tech)', data: trend.cancelled, yAxisID: 'shows',
                      borderColor: '#605ca8', backgroundColor: 'rgba(96,92,168,0.15)', pointBackgroundColor: '#605ca8',
                      borderWidth: 2, fill: false, lineTension: 0.2 },
                    { label: 'Số sự cố', data: trend.incidents, yAxisID: 'count', backgroundColor: 'rgba(60,141,188,0.85)' },
                    { label: 'Trong đó quá 24h', data: trend.overdue, yAxisID: 'count', backgroundColor: 'rgba(221,75,57,0.85)' }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { position: 'bottom' },
                tooltips: { mode: 'index', intersect: false },
                scales: {
                    xAxes: [{ gridLines: { color: grid } }],
                    yAxes: [
                        { id: 'count', position: 'left', ticks: { beginAtZero: true, precision: 0 },
                          scaleLabel: { display: true, labelString: 'Số sự cố' }, gridLines: { color: grid } },
                        { id: 'shows', position: 'right', ticks: { beginAtZero: true, precision: 0 },
                          scaleLabel: { display: true, labelString: 'Suất bị hủy' }, gridLines: { drawOnChartArea: false } }
                    ]
                }
            }
        });
    })();

    (function () {
        // Month picker fills the from/to dates and submits.
        var month = document.getElementById('ir-month');
        var form = document.getElementById('ir-filter-form');
        if (month) {
            month.addEventListener('change', function () {
                if (!month.value) { return; }
                var parts = month.value.split('-');
                var y = parseInt(parts[0], 10), m = parseInt(parts[1], 10);
                var last = new Date(y, m, 0).getDate();
                document.getElementById('ir-from').value = month.value + '-01';
                document.getElementById('ir-to').value = month.value + '-' + (last < 10 ? '0' : '') + last;
                form.submit();
            });
        }

        // Changing site reloads hall options; changing other selects applies at once.
        ['ir-site', 'ir-hall', 'ir-equipment', 'ir-kind', 'ir-status'].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) { return; }
            el.addEventListener('change', function () {
                if (id === 'ir-site') { document.getElementById('ir-hall').value = ''; }
                form.submit();
            });
        });

        // Bulk selection.
        var all = document.getElementById('ir-check-all');
        var boxes = Array.prototype.slice.call(document.querySelectorAll('.ir-check'));
        var buttons = document.querySelectorAll('.ir-needs-selection');
        var counter = document.getElementById('ir-selected-count');

        function refresh() {
            var n = boxes.filter(function (b) { return b.checked; }).length;
            counter.textContent = n;
            Array.prototype.forEach.call(buttons, function (b) { b.disabled = n === 0; });
            if (all) {
                all.checked = n > 0 && n === boxes.length;
                all.indeterminate = n > 0 && n < boxes.length;
            }
        }
        if (all) {
            all.addEventListener('change', function () {
                boxes.forEach(function (b) { b.checked = all.checked; });
                refresh();
            });
        }
        boxes.forEach(function (b) { b.addEventListener('change', refresh); });
        refresh();
    })();

</script>
@stop

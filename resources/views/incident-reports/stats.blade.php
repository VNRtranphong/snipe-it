@extends('layouts/default')

{{-- Thống kê sự cố: so sánh theo năm --}}

@section('title')
    Thống kê sự cố
    @parent
@stop

@push('css')
<style>
    .is-controls .form-group { margin-right: 10px; margin-bottom: 8px; }
    .is-controls label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 2px; }
    .is-chart-wrap { position: relative; height: 320px; }
    .is-totals th, .is-totals td { text-align: center; vertical-align: middle !important; }
    .is-totals td.is-year, .is-totals th.is-year { text-align: left; font-weight: 700; }
    .is-totals .is-val { font-size: 18px; font-weight: 600; display: block; }
    .is-chg { font-size: 12px; font-weight: 600; white-space: nowrap; }
    .is-up { color: #dd4b39; }
    .is-down { color: #00a65a; }
    .is-flat { color: #888; }
    .is-neutral-up { color: #3c8dbc; }
    .is-months th, .is-months td { text-align: center; font-size: 12px; padding: 4px 6px !important; }
    .is-months td.is-worse { background-color: rgba(221, 75, 57, 0.18); font-weight: 700; }
    .is-months td.is-better { background-color: rgba(0, 166, 90, 0.12); }
    .is-cmp { max-height: 520px; overflow-y: auto; }
    .is-cmp td, .is-cmp th { vertical-align: middle !important; }
    .is-cmp td.num, .is-cmp th.num { text-align: center; }
    .is-cmp tr.is-row-up td { background-color: rgba(221, 75, 57, 0.06); }
</style>
@endpush

@section('content')
@php
    $years = $stats['years'];
    $y = $stats['year'];
    $metric = $stats['metric'];
    // Higher is worse for every metric except planned maintenance.
    $chg = function ($pct, $old, $new, $m) {
        if ($old == 0 && $new == 0) {
            return '<span class="is-chg is-flat">–</span>';
        }
        if ($pct === null) {
            return '<span class="is-chg '.($m === 'maintenance' ? 'is-neutral-up' : 'is-up').'">mới</span>';
        }
        if ($pct == 0) {
            return '<span class="is-chg is-flat">0%</span>';
        }
        $up = $pct > 0;
        $cls = $m === 'maintenance' ? ($up ? 'is-neutral-up' : 'is-flat') : ($up ? 'is-up' : 'is-down');

        return '<span class="is-chg '.$cls.'">'.($up ? '▲ +' : '▼ ').e(number_format($pct, 1)).'%</span>';
    };
    $monthNames = ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9', 'T10', 'T11', 'T12'];
    $baseLink = fn (array $extra) => route('incident-stats.index', array_merge([
        'year' => $y, 'span' => $stats['same_span'] ? 'same' : 'full', 'metric' => $metric,
    ], $extra));
    $incidentLink = function (string $site) use ($y, $stats, $cutoff) {
        $to = $stats['same_span'] ? $y.'-'.$cutoff->format('m-d') : $y.'-12-31';
        return route('incident-reports.index', ['site' => $site, 'from' => $y.'-01-01', 'to' => $to]);
    };
@endphp

<div class="row">
    <div class="col-md-12">

        {{-- ====== Điều khiển ====== --}}
        <div class="box box-default">
            <div class="box-body">
                <form method="GET" action="{{ route('incident-stats.index') }}" class="form-inline is-controls" id="is-form">
                    <div class="form-group">
                        <label for="is-year">Năm</label>
                        <select id="is-year" name="year" class="form-control input-sm">
                            @foreach ($yearOptions as $opt)
                                <option value="{{ $opt }}" @selected($opt === $y)>{{ $opt }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="is-span">Kỳ so sánh</label>
                        <select id="is-span" name="span" class="form-control input-sm">
                            <option value="same" @selected($stats['same_span'])>Cùng kỳ (01/01 – {{ $cutoff->format('d/m') }} mỗi năm)</option>
                            <option value="full" @selected(! $stats['same_span'])>Cả năm</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="is-metric">Chỉ số cho biểu đồ &amp; bảng so sánh</label>
                        <select id="is-metric" name="metric" class="form-control input-sm">
                            @foreach ($metrics as $key => $label)
                                <option value="{{ $key }}" @selected($key === $metric)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <noscript><button type="submit" class="btn btn-primary btn-sm">Xem</button></noscript>
                </form>
                <p class="text-muted" style="font-size:12px; margin:6px 0 0">
                    So sánh {{ $years[0] }}, {{ $years[1] }}, {{ $years[2] }} – {{ $stats['same_span'] ? 'mỗi năm tính từ 01/01 đến '.$cutoff->format('d/m').' để so cho công bằng' : 'trọn năm' }}.
                    <strong>Maintenance</strong> là phiếu có Type "Maintenance / Bảo trì / Bảo dưỡng" hoặc tên bắt đầu bằng "Bảo trì"; còn lại là <strong>sự cố</strong>.
                    "Quá 24h" tính liên tục từ Start Date đến Completed At (chưa xong thì tính đến hiện tại).
                </p>
            </div>
        </div>

        {{-- ====== 2. Tổng theo năm ====== --}}
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title"><i class="fas fa-table" aria-hidden="true"></i> Tổng theo năm ({{ $stats['span_label'] }})</h2>
            </div>
            <div class="box-body">
                <div class="table-responsive">
                    <table class="table table-bordered is-totals">
                        <thead>
                            <tr>
                                <th class="is-year">Năm</th>
                                @foreach ($metrics as $key => $label)
                                    <th>{{ $label }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($stats['totals'] as $i => $t)
                                @php $prevT = $i > 0 ? $stats['totals'][$i - 1] : null; @endphp
                                <tr @if ($t['year'] === $y) style="background-color: rgba(60,141,188,0.07)" @endif>
                                    <td class="is-year">{{ $t['year'] }}@if ($t['year'] === $y) <small class="text-muted">(đang xem)</small>@endif</td>
                                    @foreach ($metrics as $key => $label)
                                        <td>
                                            <span class="is-val">{{ number_format($t[$key]) }}</span>
                                            @if ($prevT)
                                                {!! $chg($t[$key.'_pct'], $prevT[$key], $t[$key], $key) !!}
                                                <small class="text-muted">so với {{ $prevT['year'] }}</small>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-muted" style="font-size:12px; margin:0">
                    ▲ đỏ = tăng (xấu đi), ▼ xanh = giảm (tốt lên). Với Maintenance, tăng là màu xanh dương vì bảo trì nhiều hơn không phải là xấu.
                    "mới" = năm trước bằng 0 nên không tính được %.
                </p>
            </div>
        </div>

        {{-- ====== 1. Biểu đồ theo tháng ====== --}}
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title"><i class="fas fa-chart-line" aria-hidden="true"></i> {{ $stats['metric_label'] }} theo tháng – mỗi năm một đường</h2>
            </div>
            <div class="box-body">
                <div class="is-chart-wrap"><canvas id="is-chart" role="img" aria-label="{{ $stats['metric_label'] }} theo tháng, so sánh {{ implode(', ', $years) }}"></canvas></div>

                <div class="table-responsive" style="margin-top:14px">
                    <table class="table table-bordered table-condensed is-months">
                        <thead>
                            <tr>
                                <th style="text-align:left">Năm</th>
                                @foreach ($monthNames as $mn)
                                    <th>{{ $mn }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($years as $yy)
                                <tr>
                                    <td style="text-align:left; font-weight:700">{{ $yy }}</td>
                                    @foreach ($stats['monthly'][$yy][$metric] as $mi => $v)
                                        @php
                                            $prevV = $stats['monthly'][$yy - 1][$metric][$mi] ?? null;
                                            $cls = '';
                                            if ($yy === $y && $v !== null && $prevV !== null && $metric !== 'maintenance') {
                                                $cls = $v > $prevV ? 'is-worse' : ($v < $prevV ? 'is-better' : '');
                                            }
                                        @endphp
                                        <td class="{{ $cls }}" @if ($cls === 'is-worse') title="Tệ hơn {{ $yy - 1 }} ({{ $prevV }})" @endif>{{ $v === null ? '' : $v }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-muted" style="font-size:12px; margin:0">
                    Dòng {{ $y }}: ô đỏ = tháng đó tệ hơn {{ $y - 1 }}, ô xanh = tốt hơn. Biểu đồ và bảng này luôn tính trọn từng tháng; tháng chưa tới để trống.
                </p>
            </div>
        </div>

        {{-- ====== 3. So theo rạp / loại thiết bị ====== --}}
        <div class="row">
            @php
                $sections = [
                    ['key' => 'by_site', 'title' => 'Theo rạp', 'icon' => 'fa-building', 'col' => 'Rạp'],
                    ['key' => 'by_equipment', 'title' => 'Theo loại thiết bị', 'icon' => 'fa-server', 'col' => 'Loại thiết bị'],
                ];
            @endphp
            @foreach ($sections as $sec)
                @php [$key, $title, $icon, $col] = [$sec['key'], $sec['title'], $sec['icon'], $sec['col']]; @endphp
                <div class="col-md-6">
                    <div class="box box-default">
                        <div class="box-header with-border">
                            <h2 class="box-title"><i class="fas {{ $icon }}" aria-hidden="true"></i> {{ $title }}: {{ $stats['metric_label'] }} {{ $y }} so với {{ $y - 1 }}</h2>
                        </div>
                        <div class="box-body">
                            @if (count($stats[$key]))
                                <div class="is-cmp table-responsive">
                                    <table class="table table-condensed table-hover">
                                        <thead>
                                            <tr>
                                                <th>{{ $col }}</th>
                                                <th class="num">{{ $y - 1 }}</th>
                                                <th class="num">{{ $y }}</th>
                                                <th class="num">Chênh lệch</th>
                                                <th class="num">%</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($stats[$key] as $c)
                                                @php
                                                    $bad = $metric !== 'maintenance';
                                                    $diffCls = $c['diff'] > 0 ? ($bad ? 'is-up' : 'is-neutral-up') : ($c['diff'] < 0 ? ($bad ? 'is-down' : 'is-flat') : 'is-flat');
                                                @endphp
                                                <tr class="{{ $c['diff'] > 0 && $bad ? 'is-row-up' : '' }}">
                                                    <td>
                                                        @if ($key === 'by_site' && $c['key'] !== '(Chưa rõ site)')
                                                            <a href="{{ $incidentLink($c['key']) }}" title="Xem danh sách sự cố {{ $y }} của rạp này">{{ $c['key'] }}</a>
                                                        @else
                                                            {{ $c['key'] }}
                                                        @endif
                                                    </td>
                                                    <td class="num">{{ number_format($c['last']) }}</td>
                                                    <td class="num"><strong>{{ number_format($c['cur']) }}</strong></td>
                                                    <td class="num"><span class="is-chg {{ $diffCls }}">{{ $c['diff'] > 0 ? '▲ +' : ($c['diff'] < 0 ? '▼ ' : '') }}{{ $c['diff'] }}</span></td>
                                                    <td class="num">{!! $chg($c['pct'], $c['last'], $c['cur'], $metric) !!}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                                <p class="text-muted" style="font-size:12px; margin:6px 0 0">Xếp theo mức tăng nhiều nhất lên đầu. Kỳ: {{ $stats['span_label'] }}.</p>
                            @else
                                <p class="text-muted">Không có dữ liệu trong 2 năm này.</p>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

    </div>
</div>
@stop

@section('moar_scripts')
<script src="{{ url(mix('js/dist/Chart.min.js')) }}"></script>
<script nonce="{{ csrf_token() }}">
    (function () {
        // Any control change reloads the page with the new settings.
        var form = document.getElementById('is-form');
        ['is-year', 'is-span', 'is-metric'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) { el.addEventListener('change', function () { form.submit(); }); }
        });

        var canvas = document.getElementById('is-chart');
        if (!canvas || typeof Chart === 'undefined') { return; }

        var years = @json($stats['years']);
        var monthly = @json($stats['monthly']);
        var metric = @json($metric);
        var label = @json($stats['metric_label']);

        var dark = (typeof isDark === 'function') && isDark();
        var grid = dark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
        Chart.defaults.global.defaultFontColor = dark ? '#cccccc' : '#666666';

        // Oldest year faint & dashed, last year blue, selected year bold red.
        var styles = [
            { color: '#9e9e9e', width: 2, dash: [6, 4] },
            { color: '#3c8dbc', width: 2, dash: [] },
            { color: '#dd4b39', width: 4, dash: [] }
        ];

        new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: ['T1', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'T8', 'T9', 'T10', 'T11', 'T12'],
                datasets: years.map(function (y, i) {
                    var s = styles[i];
                    return {
                        label: String(y),
                        data: monthly[y][metric],
                        borderColor: s.color,
                        backgroundColor: s.color,
                        pointBackgroundColor: s.color,
                        pointRadius: i === 2 ? 4 : 3,
                        borderWidth: s.width,
                        borderDash: s.dash,
                        fill: false,
                        lineTension: 0.15,
                        spanGaps: false
                    };
                })
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                legend: { position: 'bottom' },
                tooltips: { mode: 'index', intersect: false },
                hover: { mode: 'index', intersect: false },
                scales: {
                    xAxes: [{ gridLines: { color: grid } }],
                    yAxes: [{ ticks: { beginAtZero: true, precision: 0 }, gridLines: { color: grid },
                              scaleLabel: { display: true, labelString: label } }]
                }
            }
        });
    })();
</script>
@stop

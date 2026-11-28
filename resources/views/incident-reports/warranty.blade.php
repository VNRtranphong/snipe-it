@extends('layouts/default')

{{-- Bảo hành thiết bị: warranty end date of every asset + its repair history --}}

@section('title')
    Bảo hành thiết bị
    @parent
@stop

@push('css')
<style>
    /* Follow Snipe-IT light/dark mode (AdminLTE hard-codes .info-box to white). */
    .info-box { background-color: var(--box-bg, #ffffff) !important; color: var(--color-fg, #373636) !important; }
    .info-box .info-box-text, .info-box .info-box-number, .info-box .progress-description { color: inherit !important; }
    .wr-stats .info-box { min-height: 74px; margin-bottom: 12px; }
    .wr-stats .info-box-icon { height: 74px; line-height: 74px; width: 74px; font-size: 32px; }
    .wr-stats .info-box-content { margin-left: 74px; }
    .wr-stats .info-box-number { font-size: 22px; }
    .wr-stats a.wr-card { display: block; color: inherit; text-decoration: none; }
    .wr-stats a.wr-card .info-box { transition: box-shadow .15s, transform .15s; }
    .wr-stats a.wr-card:hover .info-box { box-shadow: 0 3px 10px rgba(0,0,0,.18); transform: translateY(-1px); }
    .wr-stats a.wr-card.active .info-box { box-shadow: 0 0 0 3px #3c8dbc; }
    .wr-filters .form-group { margin-right: 8px; margin-bottom: 8px; vertical-align: bottom; }
    .wr-filters label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 2px; }
    .wr-filters .checkbox label { display: inline; font-weight: normal; }
    .wr-table td { vertical-align: top !important; }
    .wr-table .wr-sub { display: block; font-size: 11px; opacity: .75; }
    .wr-badge { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .wr-badge.active { background: rgba(0,166,90,.18); color: #00a65a; }
    .wr-badge.soon { background: rgba(243,156,18,.22); color: #e08e0b; }
    .wr-badge.expired { background: rgba(128,128,128,.2); }
    .wr-badge.missing { background: rgba(221,75,57,.15); color: #dd4b39; }
    .wr-bar { height: 4px; background: rgba(128,128,128,.25); border-radius: 2px; margin-top: 4px; width: 110px; }
    .wr-bar span { display: block; height: 4px; border-radius: 2px; background: #00a65a; }
    .wr-bar.soon span { background: #f39c12; }
    .wr-toggle { cursor: pointer; white-space: nowrap; }
    tr.wr-detail > td { background: rgba(60,141,188,.06) !important; padding: 8px 12px 12px 40px !important; }
    tr.wr-detail table { margin-bottom: 0; font-size: 12px; }
    tr.wr-hidden { display: none; }
    .wr-warn { color: #dd4b39; font-weight: 700; }
    tr.wr-unclaimed td { background: rgba(221,75,57,.08) !important; }
</style>
@endpush

@section('content')
@php
    $base = array_filter([
        'site' => $filters['site'],
        'category' => $filters['category'],
        'q' => $filters['q'],
        'broken' => $filters['broken'] ? 1 : null,
    ], fn ($v) => $v !== null && $v !== '');
    $cards = [
        ['state' => 'valid', 'color' => 'bg-green', 'icon' => 'fa-shield-alt', 'label' => 'Còn bảo hành', 'number' => $counts['valid'],
         'note' => 'gồm '.$counts['soon'].' sắp hết'],
        ['state' => 'soon', 'color' => 'bg-orange', 'icon' => 'fa-hourglass-half', 'label' => 'Sắp hết (≤ '.$soonDays.' ngày)', 'number' => $counts['soon'],
         'note' => null],
        ['state' => 'expired', 'color' => 'bg-gray', 'icon' => 'fa-calendar-times', 'label' => 'Hết bảo hành', 'number' => $counts['expired'],
         'note' => null],
        ['state' => 'missing', 'color' => 'bg-red', 'icon' => 'fa-question-circle', 'label' => 'Chưa nhập bảo hành', 'number' => $counts['missing'],
         'note' => 'thiếu ngày mua hoặc số tháng BH'],
    ];
    $fmtDate = fn ($d) => $d instanceof \Carbon\Carbon ? $d->format('d/m/Y') : '';
    $fmtMoney = fn ($v) => $v ? number_format((float) $v, 0, ',', '.') : '';
    $stateLabels = \App\Services\MaintenanceForms\WarrantyReportService::STATE_LABELS;
@endphp
<div class="row">
    <div class="col-md-12">

        <div class="row wr-stats">
            @foreach ($cards as $card)
                <div class="col-lg-3 col-sm-6 col-xs-12">
                    <a href="{{ route('warranty-report.index', $base + ['state' => $card['state']]) }}#wr-list"
                       class="wr-card{{ $filters['state'] === $card['state'] ? ' active' : '' }}">
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
        </div>

        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title"><i class="fas fa-filter" aria-hidden="true"></i> Bộ lọc</h2>
            </div>
            <div class="box-body">
                <form method="GET" action="{{ route('warranty-report.index') }}" class="form-inline wr-filters">
                    <div class="form-group">
                        <label for="wr-state">Tình trạng bảo hành</label>
                        <select id="wr-state" name="state" class="form-control input-sm">
                            <option value="valid" @selected($filters['state'] === 'valid')>Còn bảo hành (gồm sắp hết)</option>
                            <option value="active" @selected($filters['state'] === 'active')>Còn bảo hành (trên {{ $soonDays }} ngày)</option>
                            <option value="soon" @selected($filters['state'] === 'soon')>Sắp hết (≤ {{ $soonDays }} ngày)</option>
                            <option value="expired" @selected($filters['state'] === 'expired')>Hết bảo hành</option>
                            <option value="missing" @selected($filters['state'] === 'missing')>Chưa nhập bảo hành</option>
                            <option value="all" @selected($filters['state'] === 'all')>Tất cả</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="wr-site">Site</label>
                        <select id="wr-site" name="site" class="form-control input-sm" style="min-width:180px">
                            <option value="">-- Tất cả site --</option>
                            @foreach ($options['sites'] as $s)
                                <option value="{{ $s }}" @selected($filters['site'] === $s)>{{ $s }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="wr-category">Loại thiết bị (Category)</label>
                        <select id="wr-category" name="category" class="form-control input-sm" style="min-width:180px">
                            <option value="">-- Tất cả --</option>
                            @foreach ($options['categories'] as $c)
                                <option value="{{ $c }}" @selected($filters['category'] === $c)>{{ $c }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="wr-q">Tìm (tên, serial, model, NCC)</label>
                        <input type="text" id="wr-q" name="q" value="{{ $filters['q'] }}" class="form-control input-sm" style="min-width:200px">
                    </div>
                    <div class="form-group">
                        <div class="checkbox">
                            <label><input type="checkbox" name="broken" value="1" @checked($filters['broken'])> Gồm cả thiết bị hư / thanh lý</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search" aria-hidden="true"></i> Lọc</button>
                        <a href="{{ route('warranty-report.index') }}" class="btn btn-default btn-sm">Xóa lọc</a>
                    </div>
                </form>
                <p class="text-muted" style="margin:4px 0 0; font-size:12px">
                    Hết bảo hành = Purchase Date + Warranty (tháng) của tài sản. Thiết bị có trạng thái hư / thanh lý mặc định không tính.
                </p>
            </div>
        </div>

        <div class="box box-default" id="wr-list">
            <div class="box-header with-border">
                <h2 class="box-title">
                    <i class="fas fa-shield-alt" aria-hidden="true"></i>
                    {{ $filters['state'] === 'all' ? 'Tất cả thiết bị' : ($filters['state'] === 'valid' ? 'Thiết bị còn bảo hành' : ($stateLabels[$filters['state']] ?? '')) }}
                    <small>{{ number_format($totals['assets']) }} thiết bị · {{ $totals['repairs'] }} lần sửa
                        ({{ $totals['claimed'] }} theo BH){{ $totals['cost'] > 0 ? ' · chi phí '.$fmtMoney($totals['cost']) : '' }}</small>
                </h2>
                <div class="box-tools pull-right">
                    <a href="{{ route('warranty-report.export', $base + ['state' => $filters['state']]) }}" class="btn btn-success btn-sm">
                        <i class="fas fa-file-excel" aria-hidden="true"></i> Xuất Excel (kèm lịch sử sửa chữa)
                    </a>
                </div>
            </div>
            <div class="box-body">
                @if ($totals['unclaimed'] > 0)
                    <div class="alert alert-warning" style="padding:8px 12px">
                        <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                        Có <strong>{{ $totals['unclaimed'] }}</strong> lần sửa chữa xảy ra <strong>trong hạn bảo hành</strong> nhưng phiếu chưa tick "Warranty Improvement" – nên kiểm tra lại có claim bảo hành được không (dòng tô đỏ).
                    </div>
                @endif
                @if ($truncated)
                    <div class="alert alert-info" style="padding:8px 12px">
                        Chỉ hiển thị {{ number_format(count($rows)) }} thiết bị đầu tiên. Thu hẹp bộ lọc hoặc bấm <strong>Xuất Excel</strong> để lấy đủ.
                    </div>
                @endif

                <div class="table-responsive">
                    <table class="table table-striped table-condensed wr-table">
                        <thead>
                            <tr>
                                <th>Site / Hall</th>
                                <th>Thiết bị</th>
                                <th>Nhà cung cấp</th>
                                <th>Ngày mua</th>
                                <th class="text-center">BH (tháng)</th>
                                <th>Hết bảo hành</th>
                                <th class="text-center">Sửa chữa</th>
                                <th>Sửa gần nhất</th>
                                <th class="text-right">Chi phí</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $r)
                                @php
                                    $pct = null;
                                    if ($r['purchase_date'] && $r['expires'] && in_array($r['state'], ['active', 'soon'], true)) {
                                        $total = max(1, $r['purchase_date']->diffInDays($r['expires'], true));
                                        $pct = max(0, min(100, round(100 * $r['days_left'] / $total)));
                                    }
                                    $hasHistory = count($r['repairs']) > 0;
                                @endphp
                                <tr class="{{ $r['repair_unclaimed'] > 0 ? 'wr-unclaimed' : '' }}">
                                    <td>
                                        {{ $r['site'] }}
                                        @if ($r['hall_label'] !== '')<span class="wr-sub">{{ $r['hall_label'] }}</span>@endif
                                    </td>
                                    <td>
                                        <a href="{{ route('hardware.show', $r['id']) }}"><strong>{{ $r['name'] !== '' ? $r['name'] : $r['asset_tag'] }}</strong></a>
                                        <span class="wr-sub">{{ $r['category'] }}{{ $r['model'] !== '' ? ' · '.$r['model'] : '' }}</span>
                                        @if ($r['serial'] !== '')<span class="wr-sub">SN: {{ $r['serial'] }}</span>@endif
                                        @if ($r['broken'])<span class="wr-sub wr-warn">{{ $r['status'] }}</span>@endif
                                    </td>
                                    <td>{{ $r['supplier'] }}</td>
                                    <td>{{ $fmtDate($r['purchase_date']) }}</td>
                                    <td class="text-center">{{ $r['warranty_months'] }}</td>
                                    <td>
                                        @if ($r['expires'])
                                            {{ $fmtDate($r['expires']) }}<br>
                                        @endif
                                        <span class="wr-badge {{ $r['state'] }}">{{ $r['state'] === 'missing' ? 'Chưa nhập' : $r['left_label'] }}</span>
                                        @if ($pct !== null)
                                            <div class="wr-bar {{ $r['state'] }}"><span style="width: {{ $pct }}%"></span></div>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if ($hasHistory)
                                            <a class="wr-toggle" data-target="wr-d-{{ $r['id'] }}" role="button" aria-expanded="false">
                                                <strong>{{ $r['repair_count'] }}</strong>
                                                @if ($r['repair_claimed'])<small>({{ $r['repair_claimed'] }} BH)</small>@endif
                                                @if ($r['maintenance_count'])<small class="text-muted">+{{ $r['maintenance_count'] }} bảo trì</small>@endif
                                                <i class="fas fa-chevron-down fa-fw" aria-hidden="true"></i>
                                            </a>
                                            @if ($r['repair_unclaimed'])
                                                <span class="wr-sub wr-warn">{{ $r['repair_unclaimed'] }} chưa ghi BH</span>
                                            @endif
                                        @else
                                            <span class="text-muted">0</span>
                                        @endif
                                    </td>
                                    <td>{{ $fmtDate($r['last_repair']) }}</td>
                                    <td class="text-right">{{ $fmtMoney($r['repair_cost']) }}</td>
                                </tr>
                                @if ($hasHistory)
                                    <tr class="wr-detail wr-hidden" id="wr-d-{{ $r['id'] }}">
                                        <td colspan="9">
                                            <table class="table table-condensed">
                                                <thead>
                                                    <tr>
                                                        <th>Bắt đầu</th>
                                                        <th>Hoàn thành</th>
                                                        <th>Nội dung</th>
                                                        <th>Loại</th>
                                                        <th>NCC sửa</th>
                                                        <th class="text-center">Trong hạn BH</th>
                                                        <th class="text-center">Ghi Bảo hành</th>
                                                        <th class="text-right">Chi phí</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($r['repairs'] as $m)
                                                        <tr class="{{ $m['unclaimed'] ? 'wr-unclaimed' : '' }}">
                                                            <td>{{ $m['start_at'] ? $m['start_at']->format('d/m/Y H:i') : '' }}</td>
                                                            <td>{{ $m['completed_at'] ? $m['completed_at']->format('d/m/Y H:i') : 'Đang xử lý' }}</td>
                                                            <td>
                                                                <a href="{{ route('maintenances.show', $m['id']) }}">{{ $m['name'] }}</a>
                                                                @if ($m['description'] !== '')<span class="wr-sub">{{ \Illuminate\Support\Str::limit($m['description'], 160) }}</span>@endif
                                                            </td>
                                                            <td>{{ $m['is_maintenance'] ? 'Bảo trì' : 'Sửa chữa' }}{{ $m['type'] !== '' ? ' · '.$m['type'] : '' }}</td>
                                                            <td>{{ $m['supplier'] }}</td>
                                                            <td class="text-center">{!! $m['in_warranty'] ? '<i class="fas fa-check text-success" aria-label="Có"></i>' : '' !!}</td>
                                                            <td class="text-center">
                                                                @if ($m['is_warranty'])
                                                                    <i class="fas fa-check text-success" aria-label="Có"></i>
                                                                @elseif ($m['unclaimed'])
                                                                    <span class="wr-warn">Chưa ghi</span>
                                                                @endif
                                                            </td>
                                                            <td class="text-right">{{ $fmtMoney($m['cost']) }}</td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </td>
                                    </tr>
                                @endif
                            @empty
                                <tr><td colspan="9" class="text-center text-muted" style="padding:24px">Không có thiết bị nào khớp bộ lọc.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>
@stop

@section('moar_scripts')
<script nonce="{{ csrf_token() }}">
    (function () {
        document.querySelectorAll('.wr-toggle').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var row = document.getElementById(link.getAttribute('data-target'));
                if (!row) { return; }
                var open = row.classList.toggle('wr-hidden') === false;
                link.setAttribute('aria-expanded', open ? 'true' : 'false');
                var icon = link.querySelector('.fa-chevron-down, .fa-chevron-up');
                if (icon) { icon.classList.toggle('fa-chevron-down', !open); icon.classList.toggle('fa-chevron-up', open); }
            });
        });
    })();
</script>
@stop

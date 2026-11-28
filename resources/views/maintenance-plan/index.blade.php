@extends('layouts/default')

{{-- Lịch bảo trì: periodic maintenance per cinema × system (hệ máy chiếu / hệ âm thanh) --}}

@section('title')
    Lịch bảo trì
    @parent
@stop

@push('css')
<style>
    /* Follow Snipe-IT light/dark mode (AdminLTE hard-codes .info-box to white). */
    .info-box { background-color: var(--box-bg, #ffffff) !important; color: var(--color-fg, #373636) !important; }
    .info-box .info-box-text, .info-box .info-box-number, .info-box .progress-description { color: inherit !important; }
    .mp-stats .info-box { min-height: 74px; margin-bottom: 12px; }
    .mp-stats .info-box-icon { height: 74px; line-height: 74px; width: 74px; font-size: 32px; }
    .mp-stats .info-box-content { margin-left: 74px; }
    .mp-stats .info-box-number { font-size: 22px; }
    .mp-stats a.mp-card { display: block; color: inherit; text-decoration: none; }
    .mp-stats a.mp-card:hover .info-box { box-shadow: 0 3px 10px rgba(0,0,0,.18); }
    .mp-stats a.mp-card.active .info-box { box-shadow: 0 0 0 3px #3c8dbc; }
    .mp-filters .form-group { margin-right: 8px; margin-bottom: 8px; vertical-align: bottom; }
    .mp-filters label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 2px; }
    .mp-board td { vertical-align: top !important; }
    .mp-cell { border-left: 4px solid rgba(128,128,128,.4); padding: 2px 0 2px 10px; }
    .mp-cell.overdue { border-left-color: #dd4b39; }
    .mp-cell.due { border-left-color: #f39c12; }
    .mp-cell.open { border-left-color: #3c8dbc; }
    .mp-cell.ok { border-left-color: #00a65a; }
    .mp-cell.never { border-left-color: #dd4b39; }
    .mp-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .mp-badge.overdue, .mp-badge.never { background: rgba(221,75,57,.18); color: #dd4b39; }
    .mp-badge.due { background: rgba(243,156,18,.2); color: #d68910; }
    .mp-badge.open { background: rgba(60,141,188,.2); color: #3c8dbc; }
    .mp-badge.ok { background: rgba(0,166,90,.18); color: #00a65a; }
    .mp-sub { display: block; font-size: 12px; opacity: .8; margin-top: 2px; }
    .mp-actions { margin-top: 6px; }
    .mp-actions form { display: inline-block; margin: 0 4px 4px 0; }
    .mp-actions input[type=date] { height: 24px; padding: 0 4px; font-size: 12px; width: 128px; display: inline-block; }
    .mp-cal th, .mp-cal td { text-align: center; font-size: 12px; padding: 4px !important; }
    .mp-cal td.mp-cal-site, .mp-cal th.mp-cal-site { text-align: left; white-space: nowrap; }
    .mp-cal td.mp-now { box-shadow: inset 0 0 0 2px rgba(60,141,188,.6); }
    .mp-dot { display: inline-block; min-width: 22px; padding: 1px 5px; border-radius: 3px; font-size: 11px; font-weight: 700; margin: 1px; }
    .mp-dot.done { background: #00a65a; color: #fff; }
    .mp-dot.skipped { background: rgba(128,128,128,.35); }
    .mp-dot.open { background: #3c8dbc; color: #fff; }
    .mp-dot.planned { border: 1px dashed rgba(128,128,128,.8); }
    .mp-dot.overdue { background: #dd4b39; color: #fff; }
    .mp-legend .mp-dot { margin-left: 12px; }
    .mp-legend .mp-dot:first-child { margin-left: 0; }
</style>
@endpush

@section('content')
@php
    $systems = \App\Services\MaintenanceForms\MaintenancePlanService::SYSTEMS;
    $stateLabels = \App\Services\MaintenanceForms\MaintenancePlanService::STATE_LABELS;
    $roleLabels = \App\Services\MaintenanceForms\MaintenancePlanService::ROLE_LABELS;
    $sum = $board['summary'];
    $base = array_filter(['system' => $filters['system'], 'site' => $filters['site'], 'year' => $year != $today->format('Y') ? $year : null]);
    $cards = [
        ['state' => 'overdue', 'color' => 'bg-red', 'icon' => 'fa-exclamation-triangle', 'label' => 'Quá hạn', 'number' => $sum['overdue'] + $sum['never'],
         'note' => $sum['never'] ? 'gồm '.$sum['never'].' chưa có lịch sử' : null],
        ['state' => 'due', 'color' => 'bg-orange', 'icon' => 'fa-bell', 'label' => 'Đến hạn (≤ '.\App\Services\MaintenanceForms\MaintenancePlanService::DUE_SOON_DAYS.' ngày)', 'number' => $sum['due'], 'note' => null],
        ['state' => 'open', 'color' => 'bg-aqua', 'icon' => 'fa-tools', 'label' => 'Đang thực hiện', 'number' => $sum['open'], 'note' => null],
        ['state' => 'ok', 'color' => 'bg-green', 'icon' => 'fa-check-circle', 'label' => 'Chưa đến hạn', 'number' => $sum['ok'], 'note' => $doneThisYear.' đợt đã xong năm nay'],
    ];
    $fmt = fn ($d) => $d instanceof \Carbon\Carbon ? $d->format('d/m/Y') : '';
    $marks = ['done' => '✓', 'skipped' => 'Bỏ', 'open' => '…', 'planned' => 'KH', 'overdue' => '!'];
    $markTitles = ['done' => 'Đã làm', 'skipped' => 'Bỏ qua (kỳ trước tốt)', 'open' => 'Đang thực hiện', 'planned' => 'Kế hoạch', 'overdue' => 'Quá hạn'];
@endphp
<div class="row">
    <div class="col-md-12">

        @if (! $ready)
            <div class="alert alert-warning">
                Chưa chạy migrate cho Lịch bảo trì. Trên server chạy:
                <code>sudo -u www-data php artisan migrate --path=database/migrations/2026_11_28_180000_create_maintenance_plan_tables.php --force</code>
            </div>
        @endif

        <div class="row mp-stats">
            @foreach ($cards as $card)
                <div class="col-lg-3 col-sm-6 col-xs-12">
                    <a href="{{ route('maintenance-plan.index', $base + ($filters['state'] === $card['state'] ? [] : ['state' => $card['state']])) }}#mp-board"
                       class="mp-card{{ $filters['state'] === $card['state'] ? ' active' : '' }}">
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
            <div class="box-body">
                <form method="GET" action="{{ route('maintenance-plan.index') }}" class="form-inline mp-filters">
                    <div class="form-group">
                        <label for="mp-system">Hệ</label>
                        <select id="mp-system" name="system" class="form-control input-sm">
                            <option value="">-- Cả 2 hệ --</option>
                            @foreach ($systems as $key => $sys)
                                <option value="{{ $key }}" @selected($filters['system'] === $key)>{{ $sys['label'] }} ({{ $sys['months'] }} tháng/lần)</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="mp-site">Rạp</label>
                        <select id="mp-site" name="site" class="form-control input-sm" style="min-width:180px">
                            <option value="">-- Tất cả rạp --</option>
                            @foreach ($allSites as $s)
                                <option value="{{ $s }}" @selected($filters['site'] === $s)>{{ $s }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="mp-state">Tình trạng</label>
                        <select id="mp-state" name="state" class="form-control input-sm">
                            <option value="">-- Tất cả --</option>
                            @foreach ($stateLabels as $key => $lbl)
                                <option value="{{ $key }}" @selected($filters['state'] === $key)>{{ $lbl }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="mp-year">Năm (lịch)</label>
                        <input type="number" id="mp-year" name="year" value="{{ $year }}" class="form-control input-sm" style="width:90px">
                    </div>
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search" aria-hidden="true"></i> Lọc</button>
                        <a href="{{ route('maintenance-plan.index') }}" class="btn btn-default btn-sm">Xóa lọc</a>
                        <a href="{{ route('maintenance-plan.export', ['year' => $year]) }}" class="btn btn-success btn-sm"><i class="fas fa-file-excel" aria-hidden="true"></i> Xuất Excel</a>
                    </div>
                </form>
                <p class="text-muted" style="margin:4px 0 0; font-size:12px">
                    @foreach ($systems as $sys)
                        <strong>{{ $sys['label'] }}</strong>: {{ implode(', ', array_map(fn ($r) => $roleLabels[$r] ?? $r, $sys['roles'])) }} – {{ $sys['months'] }} tháng/lần{{ $sys['skippable'] ? ', được bỏ qua 1 kỳ nếu kỳ trước hệ thống tốt' : '' }}.
                    @endforeach
                    Thiết bị lấy theo Category; mỗi phòng 1 phiếu cho mỗi hệ. Thiết bị hư / thanh lý và VP HO, MEP, Projection không tính.
                </p>
            </div>
        </div>

        {{-- ====== Tình trạng theo rạp ====== --}}
        <div class="box box-default" id="mp-board">
            <div class="box-header with-border">
                <h2 class="box-title"><i class="fas fa-calendar-check" aria-hidden="true"></i> Tình trạng theo rạp</h2>
            </div>
            <div class="box-body">
                <div class="table-responsive">
                    <table class="table table-striped mp-board">
                        <thead>
                            <tr>
                                <th style="width:18%">Rạp</th>
                                @foreach ($systems as $key => $sys)
                                    @if ($filters['system'] === '' || $filters['system'] === $key)
                                        <th>{{ $sys['label'] }} <small class="text-muted">({{ $sys['months'] }} tháng)</small></th>
                                    @endif
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sites as $siteName => $row)
                                <tr>
                                    <td><strong>{{ $siteName }}</strong></td>
                                    @foreach ($systems as $key => $sys)
                                        @if ($filters['system'] === '' || $filters['system'] === $key)
                                            <td>
                                                @php $cell = $row['systems'][$key] ?? null; @endphp
                                                @if ($cell)
                                                    <div class="mp-cell {{ $cell['state'] }}">
                                                        <span class="mp-badge {{ $cell['state'] }}">{{ $stateLabels[$cell['state']] }}</span>
                                                        @if ($cell['due'])
                                                            <strong style="margin-left:6px">Hạn {{ $fmt($cell['due']) }}</strong>
                                                            <small>({{ \App\Services\MaintenanceForms\MaintenancePlanService::dueLabel($cell['days']) }})</small>
                                                        @endif
                                                        <span class="mp-sub">
                                                            {{ count($cell['halls']) }} phòng · {{ $cell['device_count'] }} thiết bị
                                                            @if ($cell['no_hall_count'])
                                                                · <span class="text-danger" title="Thiết bị không gán vào Hall nào sẽ không có phiếu">{{ $cell['no_hall_count'] }} chưa rõ Hall</span>
                                                            @endif
                                                        </span>
                                                        <span class="mp-sub">
                                                            @if ($cell['last_kind'] === 'done')
                                                                Lần gần nhất {{ $fmt($cell['last_date']) }} –
                                                                {!! $cell['last_good'] === false ? '<span class="text-danger">có vấn đề</span>' : '<span class="text-success">hệ thống tốt</span>' !!}
                                                            @elseif ($cell['last_kind'] === 'skipped')
                                                                Kỳ {{ $fmt($cell['last_date']) }} đã bỏ qua
                                                            @elseif ($cell['last_kind'] === 'history')
                                                                Lần gần nhất {{ $fmt($cell['last_date']) }} (theo phiếu bảo trì cũ)
                                                            @else
                                                                Chưa có lần bảo trì nào
                                                            @endif
                                                        </span>

                                                        @if ($cell['open_run'])
                                                            <div class="mp-actions">
                                                                <a href="{{ route('maintenance-plan.run', $cell['open_run']['id']) }}" class="btn btn-info btn-xs">
                                                                    <i class="fas fa-tools" aria-hidden="true"></i> Đang làm từ {{ $fmt($cell['open_run']['started_at']) }}
                                                                    ({{ $cell['open_run']['completed'] }}/{{ $cell['open_run']['items'] }} phiếu xong)
                                                                </a>
                                                            </div>
                                                        @elseif ($canEdit && $ready && count($cell['halls']))
                                                            <div class="mp-actions">
                                                                <form method="POST" action="{{ route('maintenance-plan.start') }}">
                                                                    @csrf
                                                                    <input type="hidden" name="site" value="{{ $siteName }}">
                                                                    <input type="hidden" name="system" value="{{ $key }}">
                                                                    <input type="date" name="date" value="{{ $today->format('Y-m-d') }}" max="{{ $today->format('Y-m-d') }}" class="form-control" aria-label="Ngày bảo trì">
                                                                    <button type="submit" class="btn btn-primary btn-xs mp-confirm"
                                                                            data-confirm="Tạo {{ count($cell['halls']) }} phiếu bảo trì {{ mb_strtolower($sys['label']) }} cho {{ $siteName }} (mỗi phòng 1 phiếu)?">
                                                                        <i class="fas fa-plus" aria-hidden="true"></i> Tạo phiếu ({{ count($cell['halls']) }} phòng)
                                                                    </button>
                                                                </form>
                                                                @if ($cell['can_skip'])
                                                                    <form method="POST" action="{{ route('maintenance-plan.skip') }}">
                                                                        @csrf
                                                                        <input type="hidden" name="site" value="{{ $siteName }}">
                                                                        <input type="hidden" name="system" value="{{ $key }}">
                                                                        <button type="submit" class="btn btn-default btn-xs mp-confirm"
                                                                                data-confirm="Bỏ qua kỳ này cho {{ $siteName }}? Kỳ sau sẽ tính từ {{ $fmt($cell['due']) }}.">
                                                                            <i class="fas fa-forward" aria-hidden="true"></i> Bỏ qua kỳ này
                                                                        </button>
                                                                    </form>
                                                                @endif
                                                            </div>
                                                        @endif
                                                    </div>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                        @endif
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted" style="padding:24px">Không có rạp nào khớp bộ lọc. Kiểm tra lại Category của Projector / Server / 3D / Processor.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ====== Lịch năm ====== --}}
        <div class="box box-default" id="mp-calendar">
            <div class="box-header with-border">
                <h2 class="box-title"><i class="far fa-calendar-alt" aria-hidden="true"></i> Lịch năm {{ $year }}</h2>
                <div class="box-tools pull-right">
                    <a href="{{ route('maintenance-plan.year', $year) }}" class="btn btn-primary btn-xs" style="margin-right:6px">
                        <i class="fas fa-file-signature" aria-hidden="true"></i> Kế hoạch năm {{ $year }} (phân công, trình ký)
                    </a>
                    <a href="{{ route('maintenance-plan.index', array_merge($base, ['year' => $year - 1])) }}#mp-calendar" class="btn btn-default btn-xs">&laquo; {{ $year - 1 }}</a>
                    <a href="{{ route('maintenance-plan.index', array_merge($base, ['year' => $year + 1])) }}#mp-calendar" class="btn btn-default btn-xs">{{ $year + 1 }} &raquo;</a>
                </div>
            </div>
            <div class="box-body">
                <p class="mp-legend" style="font-size:12px; margin:0 0 8px">
                    @foreach ($markTitles as $k => $t)
                        <span class="mp-dot {{ $k }}">{{ $marks[$k] }}</span> {{ $t }}
                    @endforeach
                </p>
                <div class="table-responsive">
                    <table class="table table-bordered table-condensed mp-cal">
                        <thead>
                            <tr>
                                <th class="mp-cal-site">Rạp</th>
                                <th>Hệ</th>
                                @for ($m = 1; $m <= 12; $m++)
                                    <th>{{ sprintf('%02d', $m) }}</th>
                                @endfor
                            </tr>
                        </thead>
                        <tbody>
                            @php $prevSite = null; @endphp
                            @forelse ($calendar as $c)
                                <tr>
                                    <td class="mp-cal-site">{!! $prevSite === $c['site'] ? '' : '<strong>'.e($c['site']).'</strong>' !!}</td>
                                    <td>{{ $c['label'] }}</td>
                                    @for ($m = 1; $m <= 12; $m++)
                                        <td class="{{ $year == $today->format('Y') && $m == $today->format('n') ? 'mp-now' : '' }}">
                                            @foreach (array_unique($c['months'][$m]) as $k)
                                                <span class="mp-dot {{ $k }}" title="{{ $markTitles[$k] ?? $k }}">{{ $marks[$k] ?? $k }}</span>
                                            @endforeach
                                        </td>
                                    @endfor
                                </tr>
                                @php $prevSite = $c['site']; @endphp
                            @empty
                                <tr><td colspan="14" class="text-center text-muted">Không có dữ liệu.</td></tr>
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
        document.querySelectorAll('.mp-confirm').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                if (!window.confirm(btn.getAttribute('data-confirm'))) { e.preventDefault(); }
            });
        });
    })();
</script>
@stop

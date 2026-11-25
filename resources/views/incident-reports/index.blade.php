@extends('layouts/default')

{{-- Báo cáo sự cố: read-only list of maintenances + BBSC / Service Report exports --}}

@section('title')
    Báo cáo sự cố
    @parent
@stop

@push('css')
<style>
    .ir-stats .info-box { min-height: 74px; margin-bottom: 12px; }
    .ir-stats .info-box-icon { height: 74px; line-height: 74px; width: 74px; font-size: 32px; }
    .ir-stats .info-box-content { margin-left: 74px; }
    .ir-stats .info-box-number { font-size: 22px; }
    .ir-filters .form-group { margin-right: 8px; margin-bottom: 8px; }
    .ir-filters label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 2px; }
    .ir-table td { vertical-align: top !important; }
    .ir-table tr.ir-overdue td { background-color: rgba(221, 75, 57, 0.08); }
    .ir-table .ir-desc { max-width: 380px; white-space: pre-line; font-size: 12px; opacity: .85; }
    .ir-table .ir-dur-overdue { color: #dd4b39; font-weight: 700; }
    .ir-table .ir-actions { white-space: nowrap; }
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

        {{-- ====== Số liệu ====== --}}
        <div class="row ir-stats">
            <div class="col-lg-2 col-sm-4 col-xs-6">
                <div class="info-box">
                    <span class="info-box-icon bg-blue"><i class="fas fa-list-ul" aria-hidden="true"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Tổng sự cố</span>
                        <span class="info-box-number">{{ number_format($stats['total']) }}</span>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-sm-4 col-xs-6">
                <div class="info-box">
                    <span class="info-box-icon bg-yellow"><i class="fas fa-tools" aria-hidden="true"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Đang xử lý</span>
                        <span class="info-box-number">{{ number_format($stats['open']) }}</span>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-sm-4 col-xs-6">
                <div class="info-box">
                    <span class="info-box-icon bg-red"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Quá {{ $overdueHours }}h</span>
                        <span class="info-box-number">{{ number_format($stats['overdue']) }}</span>
                        <span class="progress-description" style="font-size:12px">{{ $stats['overdue_open'] }} vẫn chưa xong</span>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-sm-6 col-xs-6">
                <div class="info-box">
                    <span class="info-box-icon bg-purple"><i class="fas fa-film" aria-hidden="true"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Suất bị hủy</span>
                        <span class="info-box-number">{{ number_format($stats['cancelled']) }}</span>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-sm-6 col-xs-12">
                <div class="info-box">
                    <span class="info-box-icon bg-green"><i class="fas fa-stopwatch" aria-hidden="true"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">TB thời gian xử lý</span>
                        <span class="info-box-number">
                            {{ $stats['avg_hours'] !== null ? \App\Services\MaintenanceForms\IncidentReportRepository::durationLabel($stats['avg_hours']) : '—' }}
                        </span>
                        <span class="progress-description" style="font-size:12px">tính trên sự cố đã xong</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- ====== Bộ lọc ====== --}}
        <div class="box box-default">
            <div class="box-body">
                <form method="GET" action="{{ route('incident-reports.index') }}" class="form-inline ir-filters" id="ir-filter-form">
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
                        <label for="ir-status">Trạng thái</label>
                        <select id="ir-status" name="status" class="form-control input-sm">
                            <option value="">Tất cả</option>
                            <option value="open" @selected($filters['status'] === 'open')>Đang xử lý</option>
                            <option value="done" @selected($filters['status'] === 'done')>Hoàn thành</option>
                            <option value="overdue" @selected($filters['status'] === 'overdue')>Quá {{ $overdueHours }}h</option>
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
                    Thời gian xử lý tính liên tục 24h, từ Start Date đến Completed At (chưa xong thì tính đến hiện tại).
                </p>
            </div>
        </div>

        {{-- ====== Danh sách ====== --}}
        <div class="box box-default">
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
                                    <th class="text-center">Suất hủy</th>
                                    <th>Phụ trách</th>
                                    <th>Trạng thái</th>
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
                                        @if ($r['hall_label'])<br><small class="text-muted">{{ $r['hall_label'] }}</small>@endif
                                    </td>
                                    <td>
                                        {{ $r['asset_name'] }}
                                        <br><small class="text-muted">{{ $r['equipment'] }}@if ($r['asset_tag']) · {{ $r['asset_tag'] }}@endif</small>
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
                                    <td>{{ $r['technician'] }}</td>
                                    <td>
                                        @if ($r['done'])
                                            <span class="label label-success">Hoàn thành</span>
                                        @else
                                            <span class="label label-warning">Đang xử lý</span>
                                        @endif
                                        @if ($r['overdue'])
                                            <br><span class="label label-danger">Quá {{ $overdueHours }}h</span>
                                        @endif
                                    </td>
                                    <td class="text-right ir-actions">
                                        <a href="{{ route('incident-reports.incident', $r['id']) }}" class="btn btn-sm bg-orange" data-tooltip="true" title="Xuất biên bản sự cố">
                                            <i class="fas fa-exclamation-triangle fa-fw" aria-hidden="true"></i><span class="sr-only">BBSC</span>
                                        </a>
                                        <a href="{{ route('incident-reports.service', $r['id']) }}" class="btn btn-sm btn-info" data-tooltip="true" title="Xuất Service Report">
                                            <i class="fas fa-file-alt fa-fw" aria-hidden="true"></i><span class="sr-only">Service Report</span>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="10" class="text-center text-muted" style="padding:24px">Không có sự cố nào khớp bộ lọc.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>
@stop

@section('moar_scripts')
<script nonce="{{ csrf_token() }}">
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
        ['ir-site', 'ir-hall', 'ir-equipment', 'ir-status'].forEach(function (id) {
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

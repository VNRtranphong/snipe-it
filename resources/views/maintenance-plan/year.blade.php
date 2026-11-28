@extends('layouts/default')

{{-- Kế hoạch bảo trì năm: lines (cinema × system × month) + staff, exported for signing --}}

@section('title')
    Kế hoạch bảo trì năm {{ $year }}
    @parent
@stop

@push('css')
<style>
    .yp-table td, .yp-table th { vertical-align: middle !important; }
    .yp-table tr.yp-month > td { background: rgba(60,141,188,.12) !important; font-weight: 700; }
    .yp-table input.form-control, .yp-table select.form-control { height: 28px; padding: 2px 6px; font-size: 12px; }
    .yp-table .yp-staff { min-width: 240px; }
    .yp-count { display: inline-block; min-width: 26px; padding: 2px 6px; border-radius: 10px; font-weight: 700; text-align: center; background: rgba(0,166,90,.18); color: #00a65a; }
    .yp-count.zero { background: rgba(221,75,57,.15); color: #dd4b39; }
    .yp-chips .btn { margin: 0 4px 4px 0; }
    tr.yp-del td { opacity: .45; text-decoration: line-through; }
    .yp-meta .form-group { margin-bottom: 8px; }
    .yp-sum th, .yp-sum td { text-align: center; font-size: 12px; padding: 4px !important; }
</style>
@endpush

@section('content')
@php
    $systems = \App\Services\MaintenanceForms\MaintenancePlanService::SYSTEMS;
    $signatures = \App\Services\MaintenanceForms\MaintenanceYearPlanService::SIGNATURES;
    $prevMonth = null;
@endphp
<div class="row">
    <div class="col-md-12">

        @if (! $ready)
            <div class="alert alert-warning">
                Chưa chạy migrate cho Kế hoạch năm. Trên server chạy:
                <code>sudo -u www-data php artisan migrate --path=database/migrations/2026_11_28_210000_create_maintenance_year_plan_tables.php --force</code>
            </div>
        @endif

        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title"><i class="fas fa-file-signature" aria-hidden="true"></i> Kế hoạch bảo trì – bảo dưỡng năm {{ $year }}</h2>
                <div class="box-tools pull-right">
                    <a href="{{ route('maintenance-plan.year', $year - 1) }}" class="btn btn-default btn-sm">&laquo; {{ $year - 1 }}</a>
                    <a href="{{ route('maintenance-plan.year', $year + 1) }}" class="btn btn-default btn-sm">{{ $year + 1 }} &raquo;</a>
                    <a href="{{ route('maintenance-plan.index', ['year' => $year]) }}" class="btn btn-default btn-sm">Lịch bảo trì</a>
                    <a href="{{ route('maintenance-plan.year.export', $year) }}" class="btn btn-success btn-sm">
                        <i class="fas fa-file-excel" aria-hidden="true"></i> Xuất Excel trình ký
                    </a>
                </div>
            </div>
            <div class="box-body">
                <p style="margin-bottom:8px">
                    <strong>{{ $summary['total_lines'] }}</strong> lần bảo trì ·
                    <strong>{{ $summary['total_slots'] }}</strong> lượt nhân viên ·
                    <strong>{{ count($summary['people']) }}</strong> nhân viên
                    @if ($summary['missing_staff'])
                        · <span class="text-danger"><strong>{{ $summary['missing_staff'] }}</strong> lần chưa phân công người</span>
                    @endif
                </p>
                @if ($canEdit && $ready)
                    <form method="POST" action="{{ route('maintenance-plan.year.generate', $year) }}" style="display:inline-block; margin-right:8px">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-magic" aria-hidden="true"></i> {{ count($lines) ? 'Bổ sung theo lịch' : 'Tạo kế hoạch từ lịch' }}
                        </button>
                    </form>
                    <span class="text-muted" style="font-size:12px">
                        Tự thêm các lần bảo trì của năm {{ $year }} theo chu kỳ (lần gần nhất + 3 / 6 tháng). Dòng đã có thì giữ nguyên; sau đó sửa tháng, ngày, nhân viên tùy ý.
                    </span>
                @endif

                {{-- Theo tháng --}}
                <div class="table-responsive" style="margin-top:10px">
                    <table class="table table-bordered table-condensed yp-sum">
                        <thead>
                            <tr>
                                <th style="text-align:left">Tháng</th>
                                @for ($m = 1; $m <= 12; $m++)
                                    <th>{{ sprintf('%02d', $m) }}</th>
                                @endfor
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($systems as $key => $sys)
                                <tr>
                                    <td style="text-align:left">{{ $sys['label'] }}</td>
                                    @for ($m = 1; $m <= 12; $m++)
                                        <td>{{ $summary['months'][$m][$key] ?: '' }}</td>
                                    @endfor
                                </tr>
                            @endforeach
                            <tr>
                                <td style="text-align:left"><strong>Lượt nhân viên</strong></td>
                                @for ($m = 1; $m <= 12; $m++)
                                    <td><strong>{{ $summary['months'][$m]['slots'] ?: '' }}</strong></td>
                                @endfor
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <form method="POST" action="{{ route('maintenance-plan.year.save', $year) }}">
            @csrf
            <div class="box box-default">
                <div class="box-header with-border">
                    <h2 class="box-title">Chi tiết từng lần bảo trì</h2>
                </div>
                <div class="box-body">
                    @if ($canEdit && count($knownStaff))
                        <div class="yp-chips" style="margin-bottom:8px">
                            <small class="text-muted">Bấm vào ô Nhân viên rồi bấm tên để thêm nhanh:</small><br>
                            @foreach ($knownStaff as $name)
                                <button type="button" class="btn btn-default btn-xs yp-chip" data-name="{{ $name }}">{{ $name }}</button>
                            @endforeach
                        </div>
                    @endif

                    <div class="table-responsive">
                        <table class="table table-condensed yp-table">
                            <thead>
                                <tr>
                                    <th style="width:90px">Tháng</th>
                                    <th style="width:140px">Ngày dự kiến</th>
                                    <th>Rạp</th>
                                    <th>Hệ</th>
                                    <th class="text-center">Số phòng</th>
                                    <th>Nhân viên thực hiện <small class="text-muted">(cách nhau dấu phẩy)</small></th>
                                    <th class="text-center">Số người</th>
                                    <th>Ghi chú</th>
                                    @if ($canEdit)<th class="text-center">Xóa</th>@endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($lines as $l)
                                    @if ($prevMonth !== $l['month'])
                                        <tr class="yp-month"><td colspan="{{ $canEdit ? 9 : 8 }}">Tháng {{ sprintf('%02d', $l['month']) }}/{{ $year }}</td></tr>
                                        @php $prevMonth = $l['month']; @endphp
                                    @endif
                                    <tr>
                                        <td>
                                            @if ($canEdit)
                                                <select name="lines[{{ $l['id'] }}][month]" class="form-control" aria-label="Tháng">
                                                    @for ($m = 1; $m <= 12; $m++)
                                                        <option value="{{ $m }}" @selected($l['month'] === $m)>{{ sprintf('%02d', $m) }}</option>
                                                    @endfor
                                                </select>
                                            @else
                                                {{ sprintf('%02d', $l['month']) }}
                                            @endif
                                        </td>
                                        <td>
                                            @if ($canEdit)
                                                <input type="date" name="lines[{{ $l['id'] }}][planned_date]" class="form-control" aria-label="Ngày dự kiến"
                                                       min="{{ $year }}-01-01" max="{{ $year }}-12-31" value="{{ $l['planned_date'] ? $l['planned_date']->format('Y-m-d') : '' }}">
                                            @else
                                                {{ $l['planned_date'] ? $l['planned_date']->format('d/m/Y') : '' }}
                                            @endif
                                        </td>
                                        <td><strong>{{ $l['site'] }}</strong></td>
                                        <td>{{ $l['system_label'] }}</td>
                                        <td class="text-center">{{ $halls[$l['site']][$l['system']] ?? '' }}</td>
                                        <td>
                                            @if ($canEdit)
                                                <input type="text" name="lines[{{ $l['id'] }}][staff]" class="form-control yp-staff" aria-label="Nhân viên"
                                                       value="{{ implode(', ', $l['staff']) }}" placeholder="Vd: Phong Tran, Minh Nguyen">
                                            @else
                                                {{ implode(', ', $l['staff']) }}
                                            @endif
                                        </td>
                                        <td class="text-center"><span class="yp-count{{ $l['staff_count'] ? '' : ' zero' }}">{{ $l['staff_count'] }}</span></td>
                                        <td>
                                            @if ($canEdit)
                                                <input type="text" name="lines[{{ $l['id'] }}][note]" class="form-control" maxlength="500" aria-label="Ghi chú" value="{{ $l['note'] }}">
                                            @else
                                                {{ $l['note'] }}
                                            @endif
                                        </td>
                                        @if ($canEdit)
                                            <td class="text-center"><input type="checkbox" class="yp-delete" name="lines[{{ $l['id'] }}][delete]" value="1" aria-label="Xóa dòng"></td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr><td colspan="9" class="text-center text-muted" style="padding:20px">
                                        Chưa có kế hoạch năm {{ $year }}. Bấm <strong>Tạo kế hoạch từ lịch</strong> để hệ thống tự điền các lần bảo trì theo chu kỳ.
                                    </td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="box box-default yp-meta">
                <div class="box-header with-border">
                    <h2 class="box-title"><i class="fas fa-signature" aria-hidden="true"></i> Thông tin trình ký</h2>
                </div>
                <div class="box-body">
                    <div class="row">
                        <div class="col-md-3 form-group">
                            <label for="yp-place">Nơi lập (vd TP.HCM)</label>
                            <input type="text" id="yp-place" name="meta[place]" class="form-control" value="{{ $meta['place'] }}" @disabled(! $canEdit)>
                        </div>
                        @foreach ($signatures as $key => $title)
                            <div class="col-md-3 form-group">
                                <label for="yp-{{ $key }}">{{ $title }}</label>
                                <input type="text" id="yp-{{ $key }}" name="meta[{{ $key }}]" class="form-control" value="{{ $meta[$key] }}" placeholder="Họ tên" @disabled(! $canEdit)>
                            </div>
                        @endforeach
                    </div>
                    <div class="form-group">
                        <label for="yp-note">Ghi chú chung (in dưới tiêu đề)</label>
                        <input type="text" id="yp-note" name="meta[note]" class="form-control" value="{{ $meta['note'] }}" @disabled(! $canEdit)>
                    </div>
                    @if ($canEdit && $ready)
                        <button type="submit" class="btn btn-success"><i class="fas fa-save" aria-hidden="true"></i> Lưu kế hoạch</button>
                        <span class="text-muted" style="font-size:12px; margin-left:6px">Nhập ngày dự kiến thì tháng tự theo ngày. Tick "Xóa" để bỏ dòng khi lưu.</span>
                    @endif
                </div>
            </div>
        </form>

        @if ($canEdit && $ready)
            <div class="box box-default">
                <div class="box-body">
                    <form method="POST" action="{{ route('maintenance-plan.year.add', $year) }}" class="form-inline">
                        @csrf
                        <strong style="margin-right:8px">Thêm 1 lần bảo trì:</strong>
                        <select name="site" class="form-control input-sm" aria-label="Rạp" required>
                            <option value="">-- Rạp --</option>
                            @foreach ($sites as $s)
                                <option value="{{ $s }}">{{ $s }}</option>
                            @endforeach
                        </select>
                        <select name="system" class="form-control input-sm" aria-label="Hệ">
                            @foreach ($systems as $key => $sys)
                                <option value="{{ $key }}">{{ $sys['label'] }}</option>
                            @endforeach
                        </select>
                        <select name="month" class="form-control input-sm" aria-label="Tháng">
                            @for ($m = 1; $m <= 12; $m++)
                                <option value="{{ $m }}">Tháng {{ sprintf('%02d', $m) }}</option>
                            @endfor
                        </select>
                        <button type="submit" class="btn btn-default btn-sm"><i class="fas fa-plus" aria-hidden="true"></i> Thêm</button>
                        <small class="text-muted" style="margin-left:6px">Lưu kế hoạch trước khi thêm nếu đang sửa dở.</small>
                    </form>
                </div>
            </div>
        @endif

    </div>
</div>
@stop

@section('moar_scripts')
<script nonce="{{ csrf_token() }}">
    (function () {
        function names(v) {
            var seen = {};
            return v.split(/[,;\n]+/).map(function (s) { return s.trim(); }).filter(function (s) {
                var k = s.toLowerCase();
                if (!s || seen[k]) { return false; }
                seen[k] = true;
                return true;
            });
        }
        function recount(input) {
            var badge = input.closest('tr').querySelector('.yp-count');
            if (!badge) { return; }
            var n = names(input.value).length;
            badge.textContent = n;
            badge.classList.toggle('zero', n === 0);
        }
        var last = null;
        document.querySelectorAll('.yp-staff').forEach(function (input) {
            input.addEventListener('focus', function () { last = input; });
            input.addEventListener('input', function () { recount(input); });
        });
        document.querySelectorAll('.yp-chip').forEach(function (chip) {
            chip.addEventListener('mousedown', function (e) { e.preventDefault(); });
            chip.addEventListener('click', function () {
                if (!last) { return; }
                var list = names(last.value);
                var name = chip.getAttribute('data-name');
                if (list.map(function (s) { return s.toLowerCase(); }).indexOf(name.toLowerCase()) === -1) { list.push(name); }
                last.value = list.join(', ');
                recount(last);
                last.focus();
            });
        });
        document.querySelectorAll('.yp-delete').forEach(function (box) {
            box.addEventListener('change', function () { box.closest('tr').classList.toggle('yp-del', box.checked); });
        });
    })();
</script>
@stop

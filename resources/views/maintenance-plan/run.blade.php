@extends('layouts/default')

{{-- Lịch bảo trì: one run (one system at one cinema), one maintenance per hall --}}

@section('title')
    Đợt bảo trì #{{ $run->id }}
    @parent
@stop

@push('css')
<style>
    .mp-run td { vertical-align: top !important; }
    .mp-run .mp-dev { display: block; font-size: 12px; }
    .mp-run .mp-dev b { display: inline-block; min-width: 72px; }
    .mp-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; }
    .mp-badge.open { background: rgba(60,141,188,.2); color: #3c8dbc; }
    .mp-badge.done { background: rgba(0,166,90,.18); color: #00a65a; }
    .mp-badge.skipped { background: rgba(128,128,128,.25); }
    tr.mp-levels > td { border-top: 0 !important; padding-top: 0 !important; }
    .mp-lv { display: inline-block; margin: 0 10px 6px 0; vertical-align: top; }
    .mp-lv label { display: block; font-size: 11px; font-weight: 600; margin-bottom: 1px; opacity: .85; }
    .mp-lv .input-group { width: 104px; }
    .mp-lv input { height: 28px; padding: 2px 6px; text-align: right; }
    .mp-lv .input-group-addon { padding: 2px 6px; font-size: 11px; }
    .mp-lv-title { font-size: 12px; font-weight: 700; margin: 4px 0; }
    .mp-checks { margin-bottom: 8px; font-size: 12px; }
    .mp-checks th, .mp-checks td { padding: 3px 6px !important; vertical-align: middle !important; }
    .mp-seg { display: inline-flex; border: 1px solid rgba(128,128,128,.45); border-radius: 4px; overflow: hidden; }
    .mp-seg label { margin: 0; padding: 2px 8px; font-size: 11px; font-weight: 600; cursor: pointer; white-space: nowrap; }
    .mp-seg label + label { border-left: 1px solid rgba(128,128,128,.45); }
    .mp-seg input { position: absolute; opacity: 0; width: 0; height: 0; }
    .mp-seg label.mp-ok.on { background: #00a65a; color: #fff; }
    .mp-seg label.mp-bad.on { background: #dd4b39; color: #fff; }
    .mp-seg label:focus-within { outline: 2px solid #3c8dbc; }
</style>
@endpush

@section('content')
@php
    $roleLabels = \App\Services\MaintenanceForms\MaintenancePlanService::ROLE_LABELS;
    $statusLabels = ['open' => 'Đang thực hiện', 'done' => 'Đã hoàn thành', 'skipped' => 'Đã bỏ qua'];
    $isOpen = $run->status === 'open';
    $fmt = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y H:i') : '';
    $withLevels = $withLevels ?? false;
    $withChecklist = $withChecklist ?? false;
    $isSound = $run->system === 'sound';
    $levelLabels = \App\Services\MaintenanceForms\MaintenancePlanService::LEVELS;
    $soundRows = \App\Services\MaintenanceForms\MaintenancePlanService::SOUND_CHECKS;
    $soundCols = \App\Services\MaintenanceForms\MaintenancePlanService::SOUND_CHECK_COLUMNS;
    $projRows = \App\Services\MaintenanceForms\MaintenancePlanService::PROJECTION_CHECKS;
    $projCols = \App\Services\MaintenanceForms\MaintenancePlanService::PROJECTION_CHECK_COLUMNS;
    $checkValues = \App\Services\MaintenanceForms\MaintenancePlanService::CHECK_VALUES;
    $editResults = ($withLevels || $withChecklist) && $canEdit;
@endphp
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h2 class="box-title">
                    <i class="fas fa-tools" aria-hidden="true"></i>
                    {{ $system['label'] }} – {{ $run->site }}
                    <span class="mp-badge {{ $run->status }}" style="margin-left:8px">{{ $statusLabels[$run->status] ?? $run->status }}</span>
                </h2>
                <div class="box-tools pull-right">
                    <a href="{{ route('maintenance-plan.index') }}" class="btn btn-default btn-sm">&laquo; Lịch bảo trì</a>
                    @if (count($items))
                        <a href="{{ route('maintenance-plan.reports', $run->id) }}" class="btn btn-info btn-sm">
                            <i class="fas fa-file-excel" aria-hidden="true"></i> Tải tất cả Service Report (zip)
                        </a>
                    @endif
                </div>
            </div>
            <div class="box-body">
                <p>
                    Bắt đầu: <strong>{{ $fmt($run->started_at) }}</strong>
                    · Hạn của kỳ: <strong>{{ $run->due_date ? \Carbon\Carbon::parse($run->due_date)->format('d/m/Y') : '' }}</strong>
                    @if ($run->completed_at)
                        · Hoàn thành: <strong>{{ $fmt($run->completed_at) }}</strong>
                        @if ($run->status === 'done')
                            – {!! $run->result_good ? '<span class="text-success">hệ thống tốt</span>' : '<span class="text-danger">có phòng cần theo dõi</span>' !!}
                        @endif
                    @endif
                    · Chu kỳ {{ $system['months'] }} tháng
                </p>
                @if ($run->note)
                    <p class="text-muted">Ghi chú: {{ $run->note }}</p>
                @endif

                <form method="POST" action="{{ $isOpen ? route('maintenance-plan.complete', $run->id) : route('maintenance-plan.results', $run->id) }}" id="mp-complete-form">
                    @csrf
                    <div class="table-responsive">
                        <table class="table table-striped mp-run">
                            <thead>
                                <tr>
                                    <th>Phòng</th>
                                    <th>Thiết bị</th>
                                    <th>Phiếu bảo trì</th>
                                    <th class="text-center">Hệ thống tốt</th>
                                    <th class="text-right">Service Report</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($items as $item)
                                    <tr>
                                        <td><strong>Hall {{ $item['hall'] }}</strong></td>
                                        <td>
                                            @foreach ($item['devices'] as $dev)
                                                <span class="mp-dev"><b>{{ $roleLabels[$dev['role']] ?? $dev['role'] }}</b> {{ $dev['model'] }}{{ ($dev['serial'] ?? '') !== '' ? ' – SN '.$dev['serial'] : '' }}</span>
                                            @endforeach
                                        </td>
                                        <td>
                                            @if ($item['deleted'])
                                                <span class="text-muted">Phiếu #{{ $item['maintenance_id'] }} đã bị xóa</span>
                                            @else
                                                <a href="{{ route('maintenances.show', $item['maintenance_id']) }}">{{ $item['maintenance_name'] }}</a>
                                                <br><small class="{{ $item['completed_at'] ? 'text-success' : 'text-muted' }}">
                                                    {{ $item['completed_at'] ? 'Xong '.$item['completed_at']->format('d/m/Y H:i') : 'Chưa hoàn thành' }}
                                                </small>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if ($isOpen && $canEdit)
                                                <input type="checkbox" name="good[]" value="{{ $item['id'] }}" checked aria-label="Hall {{ $item['hall'] }} hệ thống tốt">
                                            @elseif ($item['good'] === true)
                                                <i class="fas fa-check text-success" aria-label="Tốt"></i>
                                            @elseif ($item['good'] === false)
                                                <span class="text-danger">Cần theo dõi</span>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            @if (! $item['deleted'])
                                                <a href="{{ route('incident-reports.service', $item['maintenance_id']) }}" class="btn btn-sm btn-info" title="Tải Service Report Hall {{ $item['hall'] }}">
                                                    <i class="fas fa-file-excel" aria-hidden="true"></i> Report
                                                </a>
                                            @endif
                                        </td>
                                    </tr>
                                    @if (($withLevels || $withChecklist) && ! $item['deleted'])
                                        @php $ck = $item['checklist'] ?? []; @endphp
                                        <tr class="mp-levels">
                                            <td></td>
                                            <td colspan="4">
                                                @if ($withChecklist && $isSound)
                                                    <div class="mp-lv-title">A: Kiểm tra – Hall {{ $item['hall'] }}
                                                        @if ($editResults)
                                                            <button type="button" class="btn btn-default btn-xs mp-all-ok" data-item="{{ $item['id'] }}" style="margin-left:8px">Tất cả OK</button>
                                                        @endif
                                                    </div>
                                                    <table class="table table-condensed table-bordered mp-checks" data-item="{{ $item['id'] }}">
                                                        <thead>
                                                            <tr>
                                                                <th></th>
                                                                @foreach ($soundCols as $col => $letter)
                                                                    <th class="text-center">{{ ucfirst($col) }}</th>
                                                                @endforeach
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach ($soundRows as $rk => $row)
                                                                <tr>
                                                                    <th>{{ $row['label'] }}</th>
                                                                    @foreach ($soundCols as $col => $letter)
                                                                        @php $v = $ck[$rk][$col] ?? ''; @endphp
                                                                        <td class="text-center">
                                                                            @if ($editResults)
                                                                                <span class="mp-seg">
                                                                                    <label class="mp-ok{{ $v === 'ok' ? ' on' : '' }}"><input type="radio" name="checks[{{ $item['id'] }}][{{ $rk }}][{{ $col }}]" value="ok" @checked($v === 'ok')> OK</label>
                                                                                    <label class="mp-bad{{ $v === 'bad' ? ' on' : '' }}"><input type="radio" name="checks[{{ $item['id'] }}][{{ $rk }}][{{ $col }}]" value="bad" @checked($v === 'bad')> Không ổn</label>
                                                                                </span>
                                                                            @elseif ($v !== '')
                                                                                <span class="{{ $v === 'bad' ? 'text-danger' : 'text-success' }}"><strong>{{ $checkValues[$v] }}</strong></span>
                                                                            @endif
                                                                        </td>
                                                                    @endforeach
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                @elseif ($withChecklist)
                                                    <div class="mp-lv-title">A: Kiểm tra – Hall {{ $item['hall'] }}</div>
                                                    <table class="table table-condensed table-bordered mp-checks">
                                                        <thead>
                                                            <tr>
                                                                <th style="width:170px"></th>
                                                                <th>Projector</th>
                                                                <th>Server</th>
                                                                <th>Ghi chú</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach ($projRows as $rk => $row)
                                                                <tr>
                                                                    <th>{{ $row['label'] }}</th>
                                                                    @foreach ($projCols as $col => $letter)
                                                                        @php $v = $ck[$rk][$col] ?? ''; @endphp
                                                                        <td>
                                                                            @if ($editResults)
                                                                                <input type="text" class="form-control input-sm" maxlength="120" aria-label="{{ $row['label'] }} {{ $col }}"
                                                                                       name="checks[{{ $item['id'] }}][{{ $rk }}][{{ $col }}]" value="{{ $v }}">
                                                                            @else
                                                                                {{ $v }}
                                                                            @endif
                                                                        </td>
                                                                    @endforeach
                                                                </tr>
                                                            @endforeach
                                                            <tr>
                                                                <th>Light Source<br><small>(model, serial, giờ đã sử dụng…)</small></th>
                                                                <td colspan="3">
                                                                    @if ($editResults)
                                                                        <input type="text" class="form-control input-sm" maxlength="250" aria-label="Light Source"
                                                                               name="checks[{{ $item['id'] }}][light_source]" value="{{ $ck['light_source'] ?? '' }}">
                                                                    @else
                                                                        {{ $ck['light_source'] ?? '' }}
                                                                    @endif
                                                                </td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                @endif

                                                @if ($withLevels)
                                                    <div class="mp-lv-title">B-chain (dB) – Hall {{ $item['hall'] }}</div>
                                                    @foreach ($levelLabels as $key => $lbl)
                                                        <div class="mp-lv">
                                                            <label for="lv-{{ $item['id'] }}-{{ $key }}">{{ $lbl }}</label>
                                                            @if ($editResults)
                                                                <div class="input-group">
                                                                    <input type="text" inputmode="decimal" pattern="[0-9]{1,3}([.,][0-9])?" class="form-control"
                                                                           id="lv-{{ $item['id'] }}-{{ $key }}" name="levels[{{ $item['id'] }}][{{ $key }}]"
                                                                           value="{{ isset($item['levels'][$key]) ? str_replace('.', ',', (string) $item['levels'][$key]) : '' }}">
                                                                    <span class="input-group-addon">dB</span>
                                                                </div>
                                                            @else
                                                                <span id="lv-{{ $item['id'] }}-{{ $key }}">{{ isset($item['levels'][$key]) ? str_replace('.', ',', (string) $item['levels'][$key]).' dB' : '–' }}</span>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                @endif
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($isOpen && $canEdit)
                        <div class="form-group">
                            <label for="mp-note">Ghi chú chung (ghi vào Service Report)</label>
                            <input type="text" id="mp-note" name="note" class="form-control" maxlength="1000" placeholder="Vd: Hall 3 lens bẩn, đã vệ sinh; theo dõi thêm">
                        </div>
                        <p class="text-muted" style="font-size:12px">
                            Bỏ tick phòng nào có vấn đề. Nếu tất cả đều tốt thì kỳ bảo trì sau của hệ máy chiếu có thể bỏ qua.
                            Bấm Hoàn thành sẽ ghi ngày hoàn thành cho mọi phiếu chưa xong.
                        </p>
                        <button type="submit" class="btn btn-success mp-confirm" data-confirm="Hoàn thành đợt bảo trì này?">
                            <i class="fas fa-check" aria-hidden="true"></i> Hoàn thành
                        </button>
                    @endif
                    @if ($editResults)
                        <button type="submit" formaction="{{ route('maintenance-plan.results', $run->id) }}" class="btn btn-primary" style="margin-left:6px">
                            <i class="fas fa-save" aria-hidden="true"></i> Lưu kết quả kiểm tra
                        </button>
                        <span class="text-muted" style="font-size:12px; margin-left:6px">
                            {{ $isSound ? 'Chọn OK / Không ổn cho từng mục và nhập số dB B-chain (vd 85 hoặc 84,5).' : 'Gõ nội dung kiểm tra Projector / Server / Light Source.' }}
                            Service Report tải về sẽ in đúng các ô này.
                        </span>
                    @endif
                </form>

                @if ($isOpen && $canEdit)
                    <form method="POST" action="{{ route('maintenance-plan.cancel', $run->id) }}" style="margin-top:12px">
                        @csrf
                        <button type="submit" class="btn btn-default btn-sm mp-confirm"
                                data-confirm="Hủy đợt này? Các phiếu bảo trì chưa hoàn thành của đợt sẽ bị xóa.">
                            <i class="fas fa-times" aria-hidden="true"></i> Hủy đợt (tạo nhầm)
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@stop

@section('moar_scripts')
<script nonce="{{ csrf_token() }}">
    (function () {
        // OK / Không ổn segments: highlight the chosen one; any "Không ổn" unticks "Hệ thống tốt" of that hall.
        function refresh(seg) {
            seg.querySelectorAll('label').forEach(function (l) { l.classList.toggle('on', l.querySelector('input').checked); });
        }
        function syncGood(table) {
            var id = table.getAttribute('data-item');
            var good = document.querySelector('input[name="good[]"][value="' + id + '"]');
            if (good && table.querySelector('input[value="bad"]:checked')) { good.checked = false; }
        }
        document.querySelectorAll('.mp-checks[data-item]').forEach(function (table) {
            table.addEventListener('change', function (e) {
                if (e.target && e.target.type === 'radio') { refresh(e.target.closest('.mp-seg')); syncGood(table); }
            });
        });
        document.querySelectorAll('.mp-all-ok').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var table = document.querySelector('.mp-checks[data-item="' + btn.getAttribute('data-item') + '"]');
                if (!table) { return; }
                table.querySelectorAll('input[value="ok"]').forEach(function (r) { r.checked = true; });
                table.querySelectorAll('.mp-seg').forEach(refresh);
            });
        });

        document.querySelectorAll('.mp-confirm').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                if (!window.confirm(btn.getAttribute('data-confirm'))) { e.preventDefault(); }
            });
        });
    })();
</script>
@stop

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
    .mp-lv-title { font-size: 12px; font-weight: 700; margin-bottom: 4px; }
</style>
@endpush

@section('content')
@php
    $roleLabels = \App\Services\MaintenanceForms\MaintenancePlanService::ROLE_LABELS;
    $statusLabels = ['open' => 'Đang thực hiện', 'done' => 'Đã hoàn thành', 'skipped' => 'Đã bỏ qua'];
    $isOpen = $run->status === 'open';
    $fmt = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y H:i') : '';
    $withLevels = $withLevels ?? false;
    $levelLabels = \App\Services\MaintenanceForms\MaintenancePlanService::LEVELS;
    $editLevels = $withLevels && $canEdit;
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

                <form method="POST" action="{{ $isOpen ? route('maintenance-plan.complete', $run->id) : route('maintenance-plan.levels', $run->id) }}" id="mp-complete-form">
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
                                    @if ($withLevels && ! $item['deleted'])
                                        <tr class="mp-levels">
                                            <td></td>
                                            <td colspan="4">
                                                <div class="mp-lv-title">B-chain (dB) – Hall {{ $item['hall'] }}</div>
                                                @foreach ($levelLabels as $key => $lbl)
                                                    <div class="mp-lv">
                                                        <label for="lv-{{ $item['id'] }}-{{ $key }}">{{ $lbl }}</label>
                                                        @if ($editLevels)
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
                    @if ($editLevels)
                        <button type="submit" formaction="{{ route('maintenance-plan.levels', $run->id) }}" class="btn btn-primary" style="margin-left:6px">
                            <i class="fas fa-save" aria-hidden="true"></i> Lưu số đo B-chain
                        </button>
                        <span class="text-muted" style="font-size:12px; margin-left:6px">Nhập số dB đo được sau bảo dưỡng (vd 85 hoặc 84,5); Service Report sẽ in vào cột "B chian".</span>
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
        document.querySelectorAll('.mp-confirm').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                if (!window.confirm(btn.getAttribute('data-confirm'))) { e.preventDefault(); }
            });
        });
    })();
</script>
@stop

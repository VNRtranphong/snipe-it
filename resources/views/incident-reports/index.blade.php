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
    .ir-stats a.ir-card { display: block; color: inherit; text-decoration: none; }
    .ir-stats a.ir-card .info-box { transition: box-shadow .15s, transform .15s; }
    .ir-stats a.ir-card:hover .info-box { box-shadow: 0 3px 10px rgba(0,0,0,.18); transform: translateY(-1px); }
    .ir-stats a.ir-card.active .info-box { box-shadow: 0 0 0 3px #3c8dbc; }
    .ir-filters label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 2px; }
    .ir-table td { vertical-align: top !important; }
    .ir-table tr.ir-overdue td { background-color: rgba(221, 75, 57, 0.08); }
    .ir-table .ir-desc { max-width: 380px; white-space: pre-line; font-size: 12px; opacity: .85; }
    .ir-table .ir-dur-overdue { color: #dd4b39; font-weight: 700; }
    .ir-table .ir-actions { white-space: nowrap; }
    .ir-bulkbar { margin-bottom: 10px; }
    .ir-bulkbar .btn { margin-right: 4px; margin-bottom: 4px; }
    #ir-inventory .ir-inv-sites { margin: 8px 0 12px; }
    #ir-inventory .ir-inv-site { display: inline-block; border: 1px solid rgba(128,128,128,.35); border-radius: 4px; padding: 6px 10px; margin: 0 6px 6px 0; cursor: pointer; }
    #ir-inventory .ir-inv-site.active { border-color: #3c8dbc; box-shadow: 0 0 0 1px #3c8dbc inset; }
    #ir-inventory .ir-inv-site strong { font-size: 16px; margin-right: 4px; }
    #ir-inventory .ir-inv-site small { display: block; opacity: .75; }
    #ir-inventory .ir-inv-total { font-size: 18px; margin-right: 10px; }
    .ir-open-model { cursor: pointer; }
    #ir-inventory tr.ir-dup td { background-color: rgba(221, 75, 57, 0.12) !important; }
    #ir-inventory .ir-dup-note { display: block; color: #dd4b39; font-size: 11px; margin-top: 2px; }
    #ir-inv-dups ul { margin: 6px 0 0; padding-left: 18px; }
    #ir-inv-dups li { margin-bottom: 4px; }
</style>
@endpush

@section('content')
@php
    $fmt = fn ($d) => $d ? $d->format('d/m/Y H:i') : '';
    $q = request()->query();
@endphp

<div class="row">
    <div class="col-md-12">

        {{-- ====== Số liệu (bấm để lọc danh sách) ====== --}}
        @php
            $cardBase = [
                'from' => $filters['from']->format('Y-m-d'),
                'to' => $filters['to']->format('Y-m-d'),
                'site' => $filters['site'],
                'hall' => $filters['hall'],
                'equipment' => $filters['equipment'],
                'q' => $filters['q'],
            ];
            $cardBase = array_filter($cardBase, fn ($v) => $v !== '') + ['site' => ''];
            $cards = [
                ['status' => '', 'color' => 'bg-blue', 'icon' => 'fa-list-ul', 'label' => 'Tổng sự cố',
                 'number' => $stats['total'], 'note' => null],
                ['status' => 'open', 'color' => 'bg-yellow', 'icon' => 'fa-tools', 'label' => 'Đang xử lý',
                 'number' => $stats['open'], 'note' => null],
                ['status' => 'overdue', 'color' => 'bg-red', 'icon' => 'fa-exclamation-triangle', 'label' => 'Quá '.$overdueHours.'h',
                 'number' => $stats['overdue'], 'note' => $stats['overdue_open'].' vẫn chưa xong'],
                ['status' => 'cancelled', 'color' => 'bg-purple', 'icon' => 'fa-film', 'label' => 'Suất bị hủy',
                 'number' => $stats['cancelled'], 'note' => 'trong '.$stats['cancelled_incidents'].' sự cố'],
            ];
        @endphp
        <div class="row ir-stats">
            @foreach ($cards as $card)
                <div class="col-lg-3 col-sm-6 col-xs-6">
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
                    Thời gian xử lý tính liên tục 24h, từ Start Date đến Completed At (chưa xong thì tính đến hiện tại).
                </p>
            </div>
        </div>

        {{-- ====== Tra cứu thiết bị ====== --}}
        <div class="box box-default" id="ir-inventory">
            <div class="box-header with-border">
                <h2 class="box-title"><i class="fas fa-boxes" aria-hidden="true"></i> Tra cứu thiết bị theo Model / Category</h2>
            </div>
            <div class="box-body">
                <div class="form-inline ir-filters">
                    <div class="form-group">
                        <label for="ir-inv-category">Category</label>
                        <select id="ir-inv-category" class="form-control input-sm" style="min-width:200px">
                            <option value="">-- Chọn category --</option>
                            @foreach ($inventoryCategories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ir-inv-model">Model</label>
                        <select id="ir-inv-model" class="form-control input-sm" style="min-width:260px">
                            <option value="">-- Tất cả model của category --</option>
                            @foreach ($inventoryModels as $mdl)
                                <option value="{{ $mdl->id }}" data-category="{{ $mdl->category_id }}">{{ $mdl->name }}@if ($mdl->model_number && ! str_contains($mdl->name, $mdl->model_number)) ({{ $mdl->model_number }})@endif</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="ir-inv-search">Lọc trong kết quả</label>
                        <input type="text" id="ir-inv-search" class="form-control input-sm" placeholder="Rạp, phòng, serial…" disabled>
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <a href="#" id="ir-inv-export" class="btn btn-success btn-sm disabled" aria-disabled="true">
                            <i class="fas fa-file-excel" aria-hidden="true"></i> Xuất Excel
                        </a>
                        <button type="button" id="ir-inv-close" class="btn btn-default btn-sm" style="display:none">Đóng</button>
                    </div>
                </div>

                <div id="ir-inv-result" style="display:none">
                    <p id="ir-inv-heading" style="margin:10px 0 0"></p>
                    <div id="ir-inv-dups" class="alert alert-danger" style="display:none; margin:10px 0 0" role="alert"></div>
                    <div class="ir-inv-sites" id="ir-inv-sites"></div>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover table-condensed ir-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Rạp</th>
                                    <th>Phòng</th>
                                    <th>Tên thiết bị</th>
                                    <th>Model</th>
                                    <th>Serial</th>
                                    <th>Mã TS</th>
                                    <th>Trạng thái</th>
                                    <th class="text-center">Số lần sự cố</th>
                                </tr>
                            </thead>
                            <tbody id="ir-inv-body"></tbody>
                        </table>
                    </div>
                </div>
                <p id="ir-inv-message" class="text-muted" style="margin:8px 0 0; font-size:12px">
                    Chọn Category hoặc Model để xem có bao nhiêu thiết bị, đang lắp ở rạp nào, phòng nào và serial.
                    Có thể bấm vào tên model trong bảng sự cố bên dưới.
                </p>
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
                                        @if ($r['model_id'])
                                            <br><small><a href="#ir-inventory" class="ir-open-model" data-model-id="{{ $r['model_id'] }}" data-category-id="{{ $r['category_id'] }}" title="Xem tất cả thiết bị cùng model">
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

    // ====== Tra cứu thiết bị ======
    (function () {
        var dataUrl = @json(route('incident-reports.inventory'));
        var exportUrl = @json(route('incident-reports.inventory.export'));
        var assetUrl = @json(url('hardware')) + '/';

        var catSel = document.getElementById('ir-inv-category');
        var modelSel = document.getElementById('ir-inv-model');
        var search = document.getElementById('ir-inv-search');
        var exportBtn = document.getElementById('ir-inv-export');
        var closeBtn = document.getElementById('ir-inv-close');
        var result = document.getElementById('ir-inv-result');
        var heading = document.getElementById('ir-inv-heading');
        var sitesBox = document.getElementById('ir-inv-sites');
        var body = document.getElementById('ir-inv-body');
        var message = document.getElementById('ir-inv-message');
        var defaultMessage = message.innerHTML;
        var modelOptions = Array.prototype.slice.call(modelSel.options).slice(1);
        var dupsBox = document.getElementById('ir-inv-dups');
        var noSite = @json(\App\Services\MaintenanceForms\InventoryRepository::NO_SITE);
        var current = null, activeSite = '', onlyDups = false, request = 0;

        function esc(v) {
            return String(v === null || v === undefined ? '' : v)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        function fold(v) {
            return String(v || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .replace(/đ/g, 'd').replace(/Đ/g, 'D').toLowerCase();
        }

        // Only show models of the chosen category.
        function filterModels() {
            var cat = catSel.value;
            modelOptions.forEach(function (o) {
                var show = !cat || o.getAttribute('data-category') === cat;
                o.hidden = !show;
                o.disabled = !show;
            });
            var sel = modelSel.options[modelSel.selectedIndex];
            if (sel && sel.disabled) { modelSel.value = ''; }
        }

        function params() {
            var p = [];
            if (modelSel.value) { p.push('model_id=' + encodeURIComponent(modelSel.value)); }
            else if (catSel.value) { p.push('category_id=' + encodeURIComponent(catSel.value)); }
            return p.join('&');
        }

        function reset() {
            current = null; activeSite = ''; onlyDups = false;
            result.style.display = 'none';
            dupsBox.style.display = 'none';
            closeBtn.style.display = 'none';
            search.value = ''; search.disabled = true;
            exportBtn.classList.add('disabled'); exportBtn.setAttribute('aria-disabled', 'true'); exportBtn.href = '#';
            message.innerHTML = defaultMessage; message.style.display = '';
        }

        function load() {
            var p = params();
            if (!p) { reset(); return; }
            var mine = ++request;
            message.style.display = '';
            message.textContent = 'Đang tải…';
            fetch(dataUrl + '?' + p, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
                .then(function (data) {
                    if (mine !== request) { return; }
                    current = data; activeSite = ''; onlyDups = false; search.value = '';
                    search.disabled = false;
                    exportBtn.href = exportUrl + '?' + p;
                    exportBtn.classList.remove('disabled'); exportBtn.removeAttribute('aria-disabled');
                    closeBtn.style.display = '';
                    render();
                })
                .catch(function (e) {
                    if (mine !== request) { return; }
                    message.style.display = '';
                    message.textContent = 'Không tải được dữ liệu (' + e.message + ').';
                });
        }

        function render() {
            if (!current) { return; }
            result.style.display = '';
            message.style.display = 'none';

            heading.innerHTML = '<span class="ir-inv-total"><strong>' + esc(current.total) + '</strong> thiết bị</span>'
                + '<span class="text-muted">' + esc(current.title) + '</span>'
                + (current.truncated ? ' <span class="label label-warning">chỉ hiện ' + esc(current.rows.length) + ' thiết bị đầu</span>' : '')
                + ((current.duplicates || []).length ? ' <span class="label label-danger">' + current.duplicates.length + ' serial trùng</span>' : '');

            sitesBox.innerHTML = current.by_site.map(function (s) {
                var halls = s.halls.length ? 'Phòng ' + s.halls.map(esc).join(', ') : '';
                return '<span class="ir-inv-site' + (activeSite === s.site ? ' active' : '') + '" data-site="' + esc(s.site) + '" title="Bấm để lọc theo rạp">'
                    + '<strong>' + esc(s.count) + '</strong>' + esc(s.site)
                    + (halls ? '<small>' + halls + '</small>' : '') + '</span>';
            }).join('');

            // Duplicate-serial warning (compared with every asset in Snipe-IT).
            var dups = current.duplicates || [];
            if (dups.length) {
                dupsBox.style.display = '';
                dupsBox.innerHTML = '<strong><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> '
                    + dups.length + ' serial bị trùng</strong> (so với toàn bộ thiết bị trong Snipe-IT) '
                    + '<button type="button" class="btn btn-xs btn-default" id="ir-inv-only-dups" style="margin-left:6px">'
                    + (onlyDups ? 'Hiện tất cả' : 'Chỉ xem dòng trùng') + '</button>'
                    + '<ul>' + dups.map(function (d) {
                        return '<li><code>' + esc(d.serial) + '</code>: ' + d.assets.map(function (a) {
                            var where = (a.site || noSite) + (a.hall ? ' Hall ' + a.hall : '');
                            return '<a href="' + assetUrl + encodeURIComponent(a.id) + '" style="color:inherit;text-decoration:underline">'
                                + esc(a.asset_tag || a.name) + '</a> ' + esc(a.name) + ' <small>(' + esc(where) + ')</small>';
                        }).join(' &nbsp;↔&nbsp; ') + '</li>';
                    }).join('') + '</ul>';
            } else {
                dupsBox.style.display = 'none';
                dupsBox.innerHTML = '';
            }

            var q = fold(search.value.trim());
            var rows = current.rows.filter(function (r) {
                var site = r.site || noSite;
                if (activeSite && site !== activeSite) { return false; }
                if (onlyDups && !(r.dup && r.dup.length)) { return false; }
                if (!q) { return true; }
                return fold([site, r.hall ? 'hall ' + r.hall : '', r.name, r.serial, r.asset_tag, r.model, r.status].join(' ')).indexOf(q) !== -1;
            });

            body.innerHTML = rows.length ? rows.map(function (r, i) {
                var model = r.model + (r.model_number && r.model.indexOf(r.model_number) === -1 ? ' (' + r.model_number + ')' : '');
                var dup = r.dup && r.dup.length;
                var dupNote = dup ? '<span class="ir-dup-note"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Trùng với '
                    + r.dup.map(function (a) { return esc(a.asset_tag || a.name); }).join(', ') + '</span>' : '';
                return '<tr' + (dup ? ' class="ir-dup"' : '') + '>'
                    + '<td>' + (i + 1) + '</td>'
                    + '<td>' + (r.site ? esc(r.site) : '<span class="text-muted">' + esc(noSite) + '</span>') + '</td>'
                    + '<td>' + (r.hall ? 'Hall ' + esc(r.hall) : '') + '</td>'
                    + '<td><a href="' + assetUrl + encodeURIComponent(r.id) + '">' + esc(r.name || r.asset_tag) + '</a></td>'
                    + '<td>' + esc(model) + '</td>'
                    + '<td><code>' + esc(r.serial) + '</code>' + dupNote + '</td>'
                    + '<td>' + esc(r.asset_tag) + '</td>'
                    + '<td>' + esc(r.status) + '</td>'
                    + '<td class="text-center">' + (r.incidents ? esc(r.incidents) : '') + '</td>'
                    + '</tr>';
            }).join('') : '<tr><td colspan="9" class="text-center text-muted">Không có thiết bị nào.</td></tr>';
        }

        catSel.addEventListener('change', function () { filterModels(); modelSel.value = ''; load(); });
        modelSel.addEventListener('change', load);
        search.addEventListener('input', render);
        closeBtn.addEventListener('click', function () { catSel.value = ''; modelSel.value = ''; filterModels(); reset(); });
        dupsBox.addEventListener('click', function (e) {
            if (e.target.closest('#ir-inv-only-dups')) {
                onlyDups = !onlyDups;
                render();
            }
        });
        sitesBox.addEventListener('click', function (e) {
            var el = e.target.closest('.ir-inv-site');
            if (!el) { return; }
            var site = el.getAttribute('data-site');
            activeSite = activeSite === site ? '' : site;
            render();
        });
        exportBtn.addEventListener('click', function (e) { if (exportBtn.classList.contains('disabled')) { e.preventDefault(); } });

        // "Xem tất cả thiết bị cùng model" links in the incident table.
        Array.prototype.forEach.call(document.querySelectorAll('.ir-open-model'), function (a) {
            a.addEventListener('click', function (e) {
                e.preventDefault();
                catSel.value = a.getAttribute('data-category-id') || '';
                filterModels();
                modelSel.value = a.getAttribute('data-model-id');
                load();
                document.getElementById('ir-inventory').scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        filterModels();
    })();
</script>
@stop

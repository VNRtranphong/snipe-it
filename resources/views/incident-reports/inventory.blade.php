@extends('layouts/default')

{{-- Tra cứu thiết bị: every asset of a model / category, where it is installed, serial, duplicate serials --}}

@section('title')
    Tra cứu thiết bị
    @parent
@stop

@push('css')
<style>
    .ir-filters .form-group { margin-right: 8px; margin-bottom: 8px; }
    .ir-filters label { display: block; font-weight: 600; font-size: 12px; margin-bottom: 2px; }
    .ir-table td { vertical-align: top !important; }
    #ir-inventory .ir-inv-sites { margin: 8px 0 12px; }
    #ir-inventory .ir-inv-site { display: inline-block; border: 1px solid rgba(128,128,128,.35); border-radius: 4px; padding: 6px 10px; margin: 0 6px 6px 0; cursor: pointer; }
    #ir-inventory .ir-inv-site.active { border-color: #3c8dbc; box-shadow: 0 0 0 1px #3c8dbc inset; }
    #ir-inventory .ir-inv-site strong { font-size: 16px; margin-right: 4px; }
    #ir-inventory .ir-inv-site small { display: block; opacity: .75; }
    #ir-inventory .ir-inv-total { font-size: 18px; margin-right: 10px; }
    #ir-inventory tr.ir-dup td { background-color: rgba(221, 75, 57, 0.12) !important; }
    #ir-inventory .ir-dup-note { display: block; color: #dd4b39; font-size: 11px; margin-top: 2px; }
    #ir-inv-dups ul { margin: 6px 0 0; padding-left: 18px; }
    #ir-inv-dups li { margin-bottom: 4px; }
</style>
@endpush

@section('content')
<div class="row">
    <div class="col-md-12">

        {{-- ====== Tra cứu thiết bị ====== --}}
        <div class="box box-default" id="ir-inventory">
            <div class="box-header with-border">
                <h2 class="box-title"><i class="fas fa-boxes" aria-hidden="true"></i> Chọn Model / Category</h2>
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
                <p class="text-muted" style="margin:6px 0 0; font-size:12px">
                    Phòng có dấu <strong>*</strong> là đoán từ tên thiết bị, vì thiết bị chưa được giao (checkout) vào Location Hall.
                </p>
                <p id="ir-inv-message" class="text-muted" style="margin:8px 0 0; font-size:12px">
                    Chọn Category hoặc Model để xem có bao nhiêu thiết bị, đang lắp ở rạp nào, phòng nào và serial.
                    Có thể mở nhanh bằng cách bấm tên model trong trang Báo cáo sự cố.
                </p>
            </div>
        </div>

    </div>
</div>
@stop

@section('moar_scripts')
<script nonce="{{ csrf_token() }}">
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
                    + '<td>' + (r.hall ? 'Hall ' + esc(r.hall) + (r.hall_guessed ? ' <span class="text-muted" title="Đoán từ tên thiết bị (chưa được giao vào Location Hall)">*</span>' : '') : '') + '</td>'
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

        // Open directly from a link: /equipment-lookup?model_id=..&category_id=..
        var qs = new URLSearchParams(window.location.search);
        if (qs.get('category_id')) { catSel.value = qs.get('category_id'); }
        filterModels();
        if (qs.get('model_id')) { modelSel.value = qs.get('model_id'); }
        if (catSel.value || modelSel.value) { load(); }

        // Keep the URL in sync so the page can be bookmarked / shared.
        function syncUrl() {
            if (!(window.history && window.history.replaceState)) { return; }
            var parts = [];
            if (catSel.value) { parts.push('category_id=' + encodeURIComponent(catSel.value)); }
            if (modelSel.value) { parts.push('model_id=' + encodeURIComponent(modelSel.value)); }
            window.history.replaceState(null, '', window.location.pathname + (parts.length ? '?' + parts.join('&') : ''));
        }
        catSel.addEventListener('change', syncUrl);
        modelSel.addEventListener('change', syncUrl);
        closeBtn.addEventListener('click', syncUrl);
    })();
</script>
@stop

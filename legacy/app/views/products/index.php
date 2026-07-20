<?php
ob_start();
$title = $title ?? 'Product Management';
$showDeleted = !empty($showDeleted);
$categories = $categories ?? [];
$groups = $groups ?? [];
$units = $units ?? [];
$stats = $stats ?? ['active' => 0, 'inactive' => 0, 'categories' => 0, 'groups' => 0, 'with_stock' => 0];
$publicUrl = $publicUrl ?? BASE_URL;
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/product-theme.css">

<div class="branch-hub product-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-boxes-stacked me-2"></i><?= $showDeleted ? 'Inactive products' : 'Product catalog' ?></h1>
            <p>Master SKUs for purchase, sales, godown, challan &amp; stock — price ranges via price history.</p>
            <span class="hero-badge"><i class="fas fa-database"></i> warehouse_stock SSOT</span>
        </div>
        <div class="branch-hub-actions">
            <?php if ($showDeleted): ?>
                <a href="<?= BASE_URL ?>product" class="btn btn-light btn-sm"><i class="fas fa-boxes me-1"></i> Active</a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>product?deleted=1" class="btn btn-outline-light btn-sm"><i class="fas fa-box-archive me-1"></i> Inactive (<?= (int)$stats['inactive'] ?>)</a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>product/audit" class="btn btn-outline-light btn-sm"><i class="fas fa-clock-rotate-left me-1"></i> Audit</a>
            <a href="<?= BASE_URL ?>product/groups" class="btn btn-outline-light btn-sm"><i class="fas fa-globe me-1"></i> Groups</a>
            <a href="<?= BASE_URL ?>product/categories" class="btn btn-outline-light btn-sm"><i class="fas fa-tags me-1"></i> Categories</a>
            <a href="<?= BASE_URL ?>product/create" class="btn btn-light btn-sm"><i class="fas fa-plus me-1"></i> New product</a>
        </div>
    </header>

    <?php if (!$showDeleted): ?>
    <div class="branch-hub-stats">
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-box"></i></div>
            <div><div class="stat-value"><?= (int)$stats['active'] ?></div><div class="stat-label">Active SKUs</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-moon"></i></div>
            <div><div class="stat-value"><?= (int)$stats['inactive'] ?></div><div class="stat-label">Inactive</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-tags"></i></div>
            <div><div class="stat-value"><?= (int)$stats['categories'] ?></div><div class="stat-label">Categories</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon amber"><i class="fas fa-globe"></i></div>
            <div><div class="stat-value"><?= (int)$stats['groups'] ?></div><div class="stat-label">Groups</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon amber"><i class="fas fa-cubes"></i></div>
            <div><div class="stat-value"><?= (int)$stats['with_stock'] ?></div><div class="stat-label">With stock</div></div>
        </div>
    </div>
    <nav class="branch-hub-quick">
        <a href="<?= BASE_URL ?>PurchaseAudit/checklist"><i class="fas fa-clipboard-check"></i> Purchase audit</a>
        <a href="<?= BASE_URL ?>SalesAudit/checklist"><i class="fas fa-clipboard-list"></i> Sales audit</a>
        <a href="<?= BASE_URL ?>warehouse"><i class="fas fa-warehouse"></i> Warehouses</a>
    </nav>
    <?php endif; ?>

    <div class="branch-hub-panel">
        <?php if (!$showDeleted): ?>
        <div class="branch-hub-filters">
            <div class="row g-3 align-items-end">
                <div class="col-sm-4 col-md-3">
                    <div class="filter-label">Category</div>
                    <select id="filterCategory" class="form-select form-select-sm">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['category_name'] ?? '', ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-4 col-md-2">
                    <div class="filter-label">Group</div>
                    <select id="filterGroup" class="form-select form-select-sm">
                        <option value="">All groups</option>
                        <?php foreach ($groups as $grp): ?>
                            <option value="<?= (int)$grp['id'] ?>"><?= htmlspecialchars($grp['group_name'] ?? '', ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-4 col-md-2">
                    <div class="filter-label">Unit</div>
                    <select id="filterUnit" class="form-select form-select-sm">
                        <option value="">All units</option>
                        <?php foreach ($units as $unit): ?>
                            <option value="<?= htmlspecialchars($unit, ENT_QUOTES) ?>"><?= htmlspecialchars($unit, ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-auto">
                    <button type="button" id="clearFilters" class="btn btn-outline-secondary btn-sm btn-clear"><i class="fas fa-rotate-left me-1"></i> Reset</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div id="bulkToolbar" class="product-bulk-bar d-none">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="bulk-count" id="selectedCount">0 selected</span>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="bulkAction('deactivate')"><i class="fas fa-ban me-1"></i> Deactivate</button>
                <button type="button" class="btn btn-sm btn-outline-success" onclick="bulkAction('restore')"><i class="fas fa-rotate-left me-1"></i> Restore</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearSelection()">Clear</button>
            </div>
        </div>

        <div class="branch-hub-table-wrap d-none d-md-block">
            <table class="table table-borderless mb-0 w-100" id="productTable">
                <thead>
                    <tr>
                        <th width="36"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                        <th width="52"></th>
                        <th>Code</th>
                        <th>Product</th>
                        <th>Group</th>
                        <th>Category</th>
                        <th>Unit</th>
                        <th class="text-end">Stock</th>
                        <th class="text-end">Price range</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div id="productCards" class="d-md-none"></div>
    </div>
</div>

<script>
const PR_BASE = "<?= BASE_URL ?>product";
const PR_PUBLIC = "<?= htmlspecialchars($publicUrl, ENT_QUOTES) ?>";
const PR_DELETED = <?= $showDeleted ? 'true' : 'false' ?>;
const PR_CSRF = "<?= $csrfToken ?>";

function prEsc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function prImg(row) {
    if (row.image) {
        return '<img src="'+PR_PUBLIC+prEsc(row.image)+'" class="product-img-thumb" onerror="this.outerHTML=\'<span class=product-img-placeholder><i class=fas fa-image></i></span>\'">';
    }
    return '<span class="product-img-placeholder"><i class="fas fa-image"></i></span>';
}
function prPriceCell(row) {
    const min = parseFloat(row.min_rate) || 0;
    const max = parseFloat(row.max_rate) || 0;
    const def = parseFloat(row.current_price) || 0;
    if (def <= 0) return '<span class="text-muted">—</span>';
    if (min > 0 && max > 0 && (min !== max || min !== def)) {
        return '<span class="product-price-tag">Tk '+def.toFixed(2)+'</span><br><small class="text-muted">'+min.toFixed(0)+'–'+max.toFixed(0)+'</small>';
    }
    return '<span class="product-price-tag">Tk '+def.toFixed(2)+'</span>';
}
function prAttrEsc(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;');
}
function prActions(id, row) {
    const name = prAttrEsc(row.product_name || '');
    let h = '<div class="branch-action-bar flex-wrap">';
    h += '<a href="'+PR_BASE+'/edit/'+id+'" class="btn-action edit" title="Edit"><i class="fas fa-pen"></i></a>';
    h += '<a href="'+PR_BASE+'/price_history/'+id+'" class="btn-action toggle-on" title="Prices"><i class="fas fa-tag"></i></a>';
    if (PR_DELETED) {
        h += '<button type="button" class="btn-action restore js-pr-restore" data-id="'+id+'"><i class="fas fa-rotate-left"></i></button>';
    } else {
        h += '<button type="button" class="btn-action toggle-off js-pr-delete" data-id="'+id+'" data-name="'+name+'"><i class="fas fa-trash"></i></button>';
    }
    return h + '</div>';
}

let selectedIds = new Set();
function updateBulkToolbar() {
    const t = document.getElementById('bulkToolbar');
    const c = document.getElementById('selectedCount');
    if (!t || !c) return;
    if (selectedIds.size > 0) { t.classList.remove('d-none'); c.textContent = selectedIds.size + ' selected'; }
    else t.classList.add('d-none');
}
function clearSelection() {
    selectedIds.clear();
    document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
    const sa = document.getElementById('selectAll');
    if (sa) sa.checked = false;
    updateBulkToolbar();
}

$(document).ready(function() {
    const table = $('#productTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: PR_BASE + (PR_DELETED ? '?deleted=1' : ''),
            data: d => {
                d.filterCategory = $('#filterCategory').val();
                d.filterGroup = $('#filterGroup').val();
                d.filterUnit = $('#filterUnit').val();
                if (PR_DELETED) d.includeDeleted = 1;
            }
        },
        pageLength: 25,
        order: [[3, 'asc']],
        dom: '<"branch-dt-toolbar"lf>Brt<"branch-dt-footer"ip>',
        buttons: ['copy', 'excel', 'pdf'],
        drawCallback: function() { renderProductCards(this.api()); },
        columns: [
            { data: 'id', orderable: false, render: d => '<input type="checkbox" class="row-checkbox form-check-input" value="'+d+'">' },
            { data: 'image', orderable: false, render: (d,t,r) => prImg(r) },
            { data: 'product_code', render: d => '<span class="branch-code-pill">'+prEsc(d)+'</span>' },
            { data: 'product_name', render: (d,t,r) => '<div class="fw-semibold">'+prEsc(d)+'</div><small class="text-muted">'+prEsc(r.product_code)+'</small>' },
            { data: 'group_name', render: d => d ? '<span class="product-category-pill"><i class="fas fa-globe"></i> '+prEsc(d)+'</span>' : '<span class="text-muted">—</span>' },
            { data: 'category_name', render: d => d ? '<span class="product-category-pill"><i class="fas fa-tag"></i> '+prEsc(d)+'</span>' : '<span class="text-muted">—</span>' },
            { data: 'unit' },
            { data: 'total_stock', className: 'text-end', render: d => { const v=parseFloat(d)||0; return '<span class="'+(v>0?'product-stock-ok':'text-muted')+'">'+v.toLocaleString()+'</span>'; }},
            { data: null, className: 'text-end', orderable: false, render: (d,t,r) => prPriceCell(r) },
            { data: 'id', orderable: false, className: 'text-center', render: (d,t,r) => prActions(d,r) }
        ]
    });

    $('#filterCategory, #filterGroup, #filterUnit').on('change', () => table.ajax.reload());
    $('#clearFilters').on('click', () => { $('#filterCategory,#filterGroup,#filterUnit').val(''); clearSelection(); table.ajax.reload(); });

    $(document).on('change', '#selectAll', function() {
        const on = this.checked;
        document.querySelectorAll('.row-checkbox').forEach(cb => {
            cb.checked = on;
            const id = parseInt(cb.value, 10);
            if (on) selectedIds.add(id); else selectedIds.delete(id);
        });
        updateBulkToolbar();
    });
    $(document).on('change', '.row-checkbox', function() {
        const id = parseInt(this.value, 10);
        if (this.checked) selectedIds.add(id); else selectedIds.delete(id);
        updateBulkToolbar();
    });

    window.productTable = table;
    $(window).on('resize', () => { clearTimeout(window._prRt); window._prRt = setTimeout(() => renderProductCards(table), 200); });

    $(document).on('click', '.js-pr-delete', function() {
        deleteProduct(parseInt(this.getAttribute('data-id'), 10), this.getAttribute('data-name') || '');
    });
    $(document).on('click', '.js-pr-restore', function() {
        restoreProduct(parseInt(this.getAttribute('data-id'), 10));
    });
});

function renderProductCards(table) {
    const el = document.getElementById('productCards');
    if (!el || window.innerWidth >= 768) { if (el) el.innerHTML = ''; return; }
    const data = table.rows({ page: 'current' }).data();
    let html = data.length ? '' : '<div class="text-center text-muted py-4">No products found.</div>';
    data.each(function(row) {
        html += '<article class="product-mobile-card"><div class="card-head">'+prImg(row)+'<div class="flex-grow-1"><div class="fw-semibold">'+prEsc(row.product_name)+'</div><div class="card-meta"><span class="branch-code-pill">'+prEsc(row.product_code)+'</span></div></div></div>';
        html += '<div class="stock-price-row"><span>Stock: <strong>'+(parseFloat(row.total_stock)||0).toLocaleString()+'</strong></span><span>'+prPriceCell(row)+'</span></div>';
        html += '<div class="card-actions">'+prActions(row.id, row)+'</div></article>';
    });
    el.innerHTML = html;
}

function reloadProductTable() {
    if (window.productTable) { window.productTable.ajax.reload(null, false); return; }
    location.reload();
}

function prParseJsonResponse(res) {
    return res.text().then(text => {
        if (!text) {
            throw new Error('Empty response from server.');
        }
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error('Invalid server response.');
        }
    });
}

function deleteProduct(id, name) {
    const safeName = prEsc(name);
    Swal.fire({ title: 'Deactivate product?', html: 'Deactivate <strong>'+safeName+'</strong>? Can restore later.', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc2626', confirmButtonText: 'Deactivate' })
    .then(r => {
        if (!r.isConfirmed) return;
        fetch(PR_BASE+'/delete/'+id, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: new URLSearchParams({csrf_token:PR_CSRF}) })
        .then(prParseJsonResponse)
        .then(d => {
            if (d.status==='success') Swal.fire('Done', d.message, 'success').then(reloadProductTable);
            else Swal.fire('Blocked', d.message||'Failed', 'error');
        })
        .catch(err => Swal.fire('Error', err.message || 'Request failed', 'error'));
    });
}
function restoreProduct(id) {
    Swal.fire({ title: 'Restore product?', icon: 'question', showCancelButton: true, confirmButtonColor: '#059669' })
    .then(r => {
        if (!r.isConfirmed) return;
        fetch(PR_BASE+'/restore/'+id, { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: new URLSearchParams({csrf_token:PR_CSRF}) })
        .then(prParseJsonResponse)
        .then(d => {
            if (d.status==='success') Swal.fire('Restored', d.message, 'success').then(reloadProductTable);
            else Swal.fire('Error', d.message, 'error');
        })
        .catch(err => Swal.fire('Error', err.message || 'Request failed', 'error'));
    });
}
function bulkAction(action) {
    if (!selectedIds.size) return;
    const ids = Array.from(selectedIds);
    Swal.fire({ title: 'Bulk '+action+'?', text: ids.length+' product(s)', icon: 'warning', showCancelButton: true })
    .then(r => {
        if (!r.isConfirmed) return;
        const fd = new FormData();
        fd.append('action', action);
        fd.append('csrf_token', PR_CSRF);
        ids.forEach(id => fd.append('ids[]', id));
        fetch(PR_BASE+'/bulkAction', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
        .then(res => res.json()).then(d => Swal.fire(d.status==='success'?'Done':'Notice', d.message, d.status).then(reloadProductTable));
    });
}
</script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';

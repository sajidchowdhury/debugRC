<?php
ob_start();
$title = $title ?? 'Warehouse Management';
$showDeleted = !empty($showDeleted);
$branches = $branches ?? [];
$stats = $stats ?? ['active' => 0, 'inactive' => 0, 'branches' => 0, 'stock_qty' => 0];
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
$canManage = Auth::isAdmin();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/warehouse-theme.css">

<div class="branch-hub warehouse-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1>
                <i class="fas fa-warehouse me-2"></i>
                <?= $showDeleted ? 'Inactive warehouses' : 'Warehouse network' ?>
            </h1>
            <p>
                <?= $showDeleted
                    ? 'Restore locations when ready — ensure stock is cleared before deactivating active sites.'
                    : 'Stock SSOT locations tied to branches — godown, challan, transfers, and adjustments.' ?>
            </p>
            <span class="hero-badge">
                <i class="fas fa-boxes-stacked"></i>
                warehouse_stock · Stock transactions
            </span>
        </div>
        <div class="branch-hub-actions">
            <?php if ($showDeleted): ?>
                <a href="<?= BASE_URL ?>warehouse" class="btn btn-light btn-sm">
                    <i class="fas fa-warehouse me-1"></i> Active list
                </a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>warehouse?deleted=1" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-box-archive me-1"></i> Inactive (<?= (int)($stats['inactive'] ?? 0) ?>)
                </a>
            <?php endif; ?>
            <?php if ($canManage): ?>
            <a href="<?= BASE_URL ?>warehouse/audit" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
            <a href="<?= BASE_URL ?>warehouse/create" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New warehouse
            </a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$showDeleted): ?>
    <div class="branch-hub-stats">
        <div class="branch-stat-card">
            <div class="branch-stat-icon amber"><i class="fas fa-warehouse"></i></div>
            <div>
                <div class="stat-value"><?= (int)($stats['active'] ?? 0) ?></div>
                <div class="stat-label">Active sites</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-moon"></i></div>
            <div>
                <div class="stat-value"><?= (int)($stats['inactive'] ?? 0) ?></div>
                <div class="stat-label">Inactive</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-building"></i></div>
            <div>
                <div class="stat-value"><?= (int)($stats['branches'] ?? 0) ?></div>
                <div class="stat-label">Branches linked</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-cubes"></i></div>
            <div>
                <div class="stat-value"><?= number_format((float)($stats['stock_qty'] ?? 0), 0) ?></div>
                <div class="stat-label">Units on hand</div>
            </div>
        </div>
    </div>


    <?php endif; ?>

    <div class="branch-hub-panel">
        <?php if (!$showDeleted): ?>
        <div class="branch-hub-filters">
            <div class="row g-3 align-items-end">
                <div class="col-sm-4 col-md-3">
                    <div class="filter-label">Branch</div>
                    <select id="filterBranch" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int)$branch['id'] ?>">
                                <?= htmlspecialchars($branch['branch_name'] ?? '', ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-auto">
                    <button type="button" id="clearFilters" class="btn btn-outline-secondary btn-sm btn-clear">
                        <i class="fas fa-rotate-left me-1"></i> Reset
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="branch-hub-table-wrap d-none d-md-block">
            <table class="table table-borderless mb-0 align-middle w-100" id="warehouseTable">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Warehouse</th>
                        <th>Branch</th>
                        <th class="d-none d-lg-table-cell">Address</th>
                        <th>Stock</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <div id="warehouseCards" class="d-md-none" aria-live="polite"></div>
    </div>
</div>

<script>
const WH_BASE = "<?= BASE_URL ?>warehouse";
const BRANCH_BASE = "<?= BASE_URL ?>branch";
const WH_SHOW_DELETED = <?= $showDeleted ? 'true' : 'false' ?>;
const WH_CSRF = "<?= $csrfToken ?>";
const WH_CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;

function whEscape(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function whInitial(name) {
    const n = (name || '').trim();
    return n ? n.charAt(0).toUpperCase() : '?';
}

function whStatusPill(isActive) {
    const on = parseInt(isActive, 10) === 1;
    return on
        ? '<span class="branch-status-pill active"><span class="dot"></span> Active</span>'
        : '<span class="branch-status-pill inactive"><span class="dot"></span> Inactive</span>';
}

function whStockCell(row) {
    const qty = parseFloat(row.stock_qty) || 0;
    const lines = parseInt(row.product_lines, 10) || 0;
    if (qty <= 0.0001) {
        return '<span class="text-muted small">Empty</span>';
    }
    return `<div class="branch-mini-stats">
        <span class="branch-mini-stat" title="Total quantity"><i class="fas fa-cubes"></i> ${qty.toLocaleString(undefined, {maximumFractionDigits: 2})}</span>
        <span class="branch-mini-stat" title="SKU lines"><i class="fas fa-barcode"></i> ${lines}</span>
    </div>`;
}

function whBranchPill(name, branchId) {
    if (!name) return '<span class="text-muted">—</span>';
    const label = `<i class="fas fa-building"></i> ${whEscape(name)}`;
    if (branchId) {
        return `<a href="${BRANCH_BASE}/show/${branchId}" class="branch-branch-pill text-decoration-none">${label}</a>`;
    }
    return `<span class="branch-branch-pill">${label}</span>`;
}

function whNameCell(row) {
    const addr = row.address
        ? `<div class="branch-contact d-lg-none"><i class="fas fa-location-dot"></i> ${whEscape(row.address)}</div>`
        : '';
    return `<div class="branch-name-cell">
        <div class="branch-avatar">${whInitial(row.warehouse_name)}</div>
        <div>
            <div class="name"><a href="${WH_BASE}/show/${row.id}" class="text-decoration-none text-dark">${whEscape(row.warehouse_name)}</a></div>
            ${addr}
        </div>
    </div>`;
}

function whActionHtml(id, isActive) {
    let html = '<div class="branch-action-bar">';
    html += `<a href="${WH_BASE}/show/${id}" class="btn-action view" title="Warehouse hub"><i class="fas fa-warehouse"></i></a>`;
    if (!WH_CAN_MANAGE) {
        html += '</div>';
        return html;
    }
    html += `<a href="${WH_BASE}/edit/${id}" class="btn-action edit" title="Edit"><i class="fas fa-pen"></i></a>`;
    if (WH_SHOW_DELETED) {
        html += `<button type="button" class="btn-action restore" title="Restore" onclick="restoreWarehouse(${id})"><i class="fas fa-rotate-left"></i></button>`;
    } else {
        const on = parseInt(isActive, 10) === 1;
        const cls = on ? 'toggle-off' : 'toggle-on';
        const icon = on ? 'fa-power-off' : 'fa-check';
        html += `<button type="button" class="btn-action ${cls}" title="${on ? 'Deactivate' : 'Activate'}" onclick="toggleWarehouseStatus(${id}, ${on ? 1 : 0})"><i class="fas ${icon}"></i></button>`;
    }
    html += '</div>';
    return html;
}

$(document).ready(function() {
    const urlBranch = new URLSearchParams(window.location.search).get('branch');
    if (urlBranch) {
        $('#filterBranch').val(urlBranch);
    }

    const table = $('#warehouseTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: WH_BASE + (WH_SHOW_DELETED ? '?deleted=1' : ''),
            data: function(d) {
                d.filterBranch = $('#filterBranch').val();
                if (WH_SHOW_DELETED) d.includeDeleted = 1;
            }
        },
        pageLength: 25,
        order: [[1, 'asc']],
        dom: '<"branch-dt-toolbar"lf>Brt<"branch-dt-footer"ip>',
        buttons: ['copy', 'excel', 'pdf'],
        language: {
            processing: '<i class="fas fa-circle-notch fa-spin me-1"></i> Loading warehouses…',
            emptyTable: 'No warehouses found',
            zeroRecords: 'No matching warehouses'
        },
        drawCallback: function() {
            renderWarehouseCards(this.api());
        },
        columns: [
            {
                data: 'warehouse_code',
                render: function(data) {
                    return `<span class="branch-code-pill">${whEscape(data)}</span>`;
                }
            },
            {
                data: 'warehouse_name',
                render: function(data, type, row) {
                    return whNameCell(row);
                }
            },
            {
                data: 'branch_name',
                render: function(data, type, row) {
                    return whBranchPill(data, row.branch_id);
                }
            },
            {
                data: 'address',
                className: 'd-none d-lg-table-cell',
                defaultContent: '-',
                render: function(data) {
                    return data
                        ? `<span class="branch-contact"><i class="fas fa-location-dot"></i> ${whEscape(data)}</span>`
                        : '<span class="text-muted">—</span>';
                }
            },
            {
                data: null,
                orderable: false,
                render: function(data, type, row) {
                    return whStockCell(row);
                }
            },
            {
                data: 'is_active',
                render: function(data) {
                    return whStatusPill(data);
                }
            },
            {
                data: 'id',
                orderable: false,
                className: 'text-center',
                render: function(data, type, row) {
                    return whActionHtml(data, row.is_active);
                }
            }
        ]
    });

    $('#filterBranch').on('change', function() {
        table.ajax.reload();
    });

    $('#clearFilters').on('click', function() {
        $('#filterBranch').val('');
        table.ajax.reload();
    });

    let resizeTimer;
    $(window).on('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            renderWarehouseCards(table);
        }, 200);
    });

    window.warehouseTable = table;
});

function renderWarehouseCards(table) {
    const container = document.getElementById('warehouseCards');
    if (!container || window.innerWidth >= 768) {
        if (container) container.innerHTML = '';
        return;
    }

    const data = table.rows({ page: 'current' }).data();
    let html = '';

    if (data.length === 0) {
        html = '<div class="text-center text-muted py-4"><i class="fas fa-warehouse fa-2x mb-2 opacity-50"></i><br>No warehouses found.</div>';
    } else {
        data.each(function(row) {
            html += `
                <article class="warehouse-mobile-card">
                    <div class="card-head">
                        <div class="branch-avatar">${whInitial(row.warehouse_name)}</div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><a href="${WH_BASE}/show/${row.id}" class="text-decoration-none text-dark">${whEscape(row.warehouse_name)}</a></div>
                            <div class="card-meta"><span class="branch-code-pill">${whEscape(row.warehouse_code)}</span></div>
                            <div class="card-meta">${whBranchPill(row.branch_name, row.branch_id)}</div>
                        </div>
                        ${whStatusPill(row.is_active)}
                    </div>
                    ${row.address ? `<div class="card-meta"><i class="fas fa-location-dot me-1"></i>${whEscape(row.address)}</div>` : ''}
                    ${whStockCell(row)}
                    <div class="card-actions">${whActionHtml(row.id, row.is_active)}</div>
                </article>
            `;
        });
    }

    container.innerHTML = html;
}

function reloadWarehouseTable() {
    if (window.warehouseTable) {
        window.warehouseTable.ajax.reload(null, false);
        return;
    }
    location.reload();
}

function toggleWarehouseStatus(id, isActive) {
    const title = isActive ? 'Deactivate this warehouse?' : 'Activate this warehouse?';
    const confirmText = isActive ? 'Yes, deactivate' : 'Yes, activate';

    Swal.fire({
        title: title,
        html: isActive
            ? 'Stock must be moved or adjusted before deactivation.<br><strong>warehouse_stock</strong> must be zero.'
            : 'This warehouse will be available for stock movements again.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: confirmText,
        confirmButtonColor: isActive ? '#dc2626' : '#d97706'
    }).then((result) => {
        if (!result.isConfirmed) return;

        fetch(WH_BASE + '/toggle/' + id, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ csrf_token: WH_CSRF })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                Swal.fire({
                    title: 'Done',
                    text: data.message,
                    icon: 'success',
                    timer: 1400,
                    showConfirmButton: false
                }).then(reloadWarehouseTable);
            } else {
                Swal.fire('Action blocked', data.message || 'Failed to update status.', 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Failed to update warehouse status.', 'error'));
    });
}

function restoreWarehouse(id) {
    Swal.fire({
        title: 'Restore this warehouse?',
        text: 'The warehouse will become active again.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, restore',
        confirmButtonColor: '#d97706'
    }).then((result) => {
        if (!result.isConfirmed) return;

        fetch(WH_BASE + '/toggle/' + id, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ csrf_token: WH_CSRF })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                Swal.fire('Restored', data.message || 'Warehouse restored.', 'success')
                    .then(reloadWarehouseTable);
            } else {
                Swal.fire('Error', data.message || 'Failed to restore warehouse.', 'error');
            }
        });
    });
}
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
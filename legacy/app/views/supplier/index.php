<?php
ob_start();
$title = $title ?? 'Supplier directory';
$showDeleted = !empty($showDeleted);
$stats = $stats ?? ['active' => 0, 'inactive' => 0, 'with_payable' => 0, 'total_payable' => 0];
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/supplier-theme.css">

<div class="branch-hub supplier-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1>
                <i class="fas fa-truck me-2"></i>
                <?= $showDeleted ? 'Inactive suppliers' : 'Supplier directory' ?>
            </h1>
            <p>
                <?= $showDeleted
                    ? 'Restore vendors when needed — deactivation stays blocked while AP or purchase history exists.'
                    : 'Master data for purchase orders, GRN/receive, payments, and supplier_ledger AP.' ?>
            </p>
            <span class="hero-badge">
                <i class="fas fa-book"></i>
                supplier_ledger · Tk
            </span>
        </div>
        <div class="branch-hub-actions">
            <?php if ($showDeleted): ?>
                <a href="<?= BASE_URL ?>supplier" class="btn btn-light btn-sm">
                    <i class="fas fa-truck me-1"></i> Active list
                </a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>supplier?deleted=1" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-box-archive me-1"></i> Inactive (<?= (int)($stats['inactive'] ?? 0) ?>)
                </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>supplier/audit" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
            <a href="<?= BASE_URL ?>supplier/create" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New supplier
            </a>
        </div>
    </header>

    <?php if (!$showDeleted): ?>
    <div class="branch-hub-stats">
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-truck"></i></div>
            <div>
                <div class="stat-value"><?= (int)($stats['active'] ?? 0) ?></div>
                <div class="stat-label">Active vendors</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-box-archive"></i></div>
            <div>
                <div class="stat-value"><?= (int)($stats['inactive'] ?? 0) ?></div>
                <div class="stat-label">Inactive</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon amber"><i class="fas fa-file-invoice-dollar"></i></div>
            <div>
                <div class="stat-value"><?= (int)($stats['with_payable'] ?? 0) ?></div>
                <div class="stat-label">With payable</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-coins"></i></div>
            <div>
                <div class="stat-value">Tk <?= number_format((float)($stats['total_payable'] ?? 0), 0) ?></div>
                <div class="stat-label">Total payable</div>
            </div>
        </div>
    </div>

    <nav class="branch-hub-quick">
        <a href="<?= BASE_URL ?>SupplierTransaction"><i class="fas fa-money-bill-wave"></i> Payments</a>
        <a href="<?= BASE_URL ?>PurchaseOrder/create"><i class="fas fa-cart-plus"></i> New PO</a>
        <a href="<?= BASE_URL ?>PurchaseAudit/checklist"><i class="fas fa-clipboard-check"></i> Purchase audit</a>
    </nav>
    <?php endif; ?>

    <div class="branch-hub-panel">
        <?php if (!$showDeleted): ?>
        <div class="branch-hub-filters">
            <div class="row g-3 align-items-end">
                <div class="col-sm-4 col-md-2">
                    <div class="filter-label">Status</div>
                    <select id="filterStatus" class="form-select form-select-sm">
                        <option value="">All active</option>
                        <option value="active">Active only</option>
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
            <table class="table table-borderless mb-0 align-middle w-100" id="supplierTable">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Supplier</th>
                        <th class="d-none d-lg-table-cell">Mobile</th>
                        <th>Payable</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <div id="supplierCards" class="d-md-none" aria-live="polite"></div>
    </div>
</div>

<script>
const SUP_BASE = "<?= BASE_URL ?>supplier";
const SUP_SHOW_DELETED = <?= $showDeleted ? 'true' : 'false' ?>;
const SUP_CSRF = "<?= $csrfToken ?>";

function supEscape(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function supInitial(name) {
    const n = (name || '').trim();
    return n ? n.charAt(0).toUpperCase() : '?';
}

function supStatusPill(isActive) {
    const on = parseInt(isActive, 10) === 1;
    return on
        ? '<span class="branch-status-pill active"><span class="dot"></span> Active</span>'
        : '<span class="branch-status-pill inactive"><span class="dot"></span> Inactive</span>';
}

function supFormatTk(amount) {
    const n = parseFloat(amount) || 0;
    return 'Tk ' + n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function supBalanceCell(row) {
    const due = parseFloat(row.balance_due) || 0;
    if (due <= 0.009) {
        return '<span class="supplier-ap-clear">Clear</span>';
    }
    return `<span class="supplier-ap-due" title="Outstanding payable">${supFormatTk(due)}</span>`;
}

function supNameCell(row) {
    const addr = row.address
        ? `<div class="branch-contact d-lg-none"><i class="fas fa-location-dot"></i> ${supEscape(row.address)}</div>`
        : '';
    const mobile = row.mobile
        ? `<div class="branch-contact d-lg-none"><i class="fas fa-phone"></i> ${supEscape(row.mobile)}</div>`
        : '';
    return `<div class="branch-name-cell">
        <div class="branch-avatar">${supInitial(row.supplier_name)}</div>
        <div>
            <div class="name"><a href="${SUP_BASE}/show/${row.id}" class="text-decoration-none text-reset">${supEscape(row.supplier_name)}</a></div>
            ${mobile}
            ${addr}
        </div>
    </div>`;
}

function supActionHtml(row) {
    const id = row.id;
    const name = (row.supplier_name || 'this supplier').replace(/'/g, "\\'");
    let html = '<div class="branch-action-bar">';
    html += `<a href="${SUP_BASE}/show/${id}" class="btn-action view" title="Hub"><i class="fas fa-circle-info"></i></a>`;
    html += `<a href="${SUP_BASE}/edit/${id}" class="btn-action edit" title="Edit"><i class="fas fa-pen"></i></a>`;
    if (SUP_SHOW_DELETED) {
        html += `<button type="button" class="btn-action restore" title="Restore" onclick="restoreSupplier(${id})"><i class="fas fa-rotate-left"></i></button>`;
    } else {
        html += `<button type="button" class="btn-action toggle-off" title="Deactivate" onclick="deleteSupplier(${id}, '${name}')"><i class="fas fa-power-off"></i></button>`;
    }
    html += '</div>';
    return html;
}

$(document).ready(function() {
    const table = $('#supplierTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: SUP_BASE + (SUP_SHOW_DELETED ? '?deleted=1' : ''),
            data: function(d) {
                d.filterStatus = $('#filterStatus').val();
                if (SUP_SHOW_DELETED) d.includeDeleted = 1;
            }
        },
        pageLength: 25,
        order: [[1, 'asc']],
        dom: '<"branch-dt-toolbar"lf>Brt<"branch-dt-footer"ip>',
        buttons: ['copy', 'excel', 'pdf'],
        language: {
            processing: '<i class="fas fa-circle-notch fa-spin me-1"></i> Loading suppliers…',
            emptyTable: 'No suppliers found',
            zeroRecords: 'No matching suppliers'
        },
        drawCallback: function() {
            renderSupplierCards(this.api());
        },
        columns: [
            {
                data: 'supplier_code',
                render: function(data) {
                    return `<span class="branch-code-pill">${supEscape(data)}</span>`;
                }
            },
            {
                data: 'supplier_name',
                render: function(data, type, row) {
                    return supNameCell(row);
                }
            },
            {
                data: 'mobile',
                className: 'd-none d-lg-table-cell',
                render: function(data) {
                    return data
                        ? `<a href="tel:${supEscape(data)}" class="branch-contact"><i class="fas fa-phone"></i> ${supEscape(data)}</a>`
                        : '<span class="text-muted">—</span>';
                }
            },
            {
                data: 'balance_due',
                render: function(data, type, row) {
                    return supBalanceCell(row);
                }
            },
            {
                data: 'is_active',
                render: function(data) {
                    return supStatusPill(data);
                }
            },
            {
                data: 'id',
                orderable: false,
                className: 'text-center',
                render: function(data, type, row) {
                    return supActionHtml(row);
                }
            }
        ]
    });

    $('#filterStatus').on('change', function() {
        table.ajax.reload();
    });

    $('#clearFilters').on('click', function() {
        $('#filterStatus').val('');
        table.ajax.reload();
    });

    let resizeTimer;
    $(window).on('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            renderSupplierCards(table);
        }, 200);
    });

    window.supplierTable = table;
});

function deleteSupplier(id, name) {
    Swal.fire({
        title: 'Deactivate this supplier?',
        html: `This will <strong>deactivate</strong> <strong>"${name}"</strong>.<br>
               Hidden from active lists but can be restored later.<br><br>
               <small class="text-muted">Blocked if payable balance or purchase receive history exists.</small>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, deactivate'
    }).then((result) => {
        if (!result.isConfirmed) return;
        fetch(SUP_BASE + '/delete/' + id, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ csrf_token: SUP_CSRF })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                Swal.fire({ title: 'Deactivated', text: data.message, icon: 'success', timer: 1400, showConfirmButton: false });
                window.supplierTable?.ajax.reload(null, false);
            } else {
                Swal.fire('Error', data.message || 'Failed to deactivate supplier.', 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Failed to deactivate supplier.', 'error'));
    });
}

function restoreSupplier(id) {
    Swal.fire({
        title: 'Restore this supplier?',
        text: 'This supplier will become active again.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, restore'
    }).then((result) => {
        if (!result.isConfirmed) return;
        fetch(SUP_BASE + '/restore/' + id, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ csrf_token: SUP_CSRF })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                Swal.fire({ title: 'Restored', text: data.message, icon: 'success', timer: 1400, showConfirmButton: false });
                window.supplierTable?.ajax.reload(null, false);
            } else {
                Swal.fire('Error', data.message || 'Failed to restore supplier.', 'error');
            }
        });
    });
}

function renderSupplierCards(table) {
    const container = document.getElementById('supplierCards');
    if (!container || window.innerWidth >= 768) {
        if (container) container.innerHTML = '';
        return;
    }

    const data = table.rows({ page: 'current' }).data();
    let html = '';

    if (data.length === 0) {
        html = '<div class="text-center text-muted py-4"><i class="fas fa-truck fa-2x mb-2 opacity-50"></i><br>No suppliers found.</div>';
    } else {
        data.each(function(row) {
            const due = parseFloat(row.balance_due) || 0;
            const dueHtml = due > 0.009
                ? `<div class="supplier-ap-due">${supFormatTk(due)} payable</div>`
                : '<div class="supplier-ap-clear">AP clear</div>';
            const name = (row.supplier_name || 'this supplier').replace(/'/g, "\\'");

            html += `
                <article class="supplier-mobile-card">
                    <div class="card-head">
                        <div class="branch-avatar">${supInitial(row.supplier_name)}</div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><a href="${SUP_BASE}/show/${row.id}" class="text-decoration-none text-reset">${supEscape(row.supplier_name)}</a></div>
                            <div class="card-meta"><span class="branch-code-pill">${supEscape(row.supplier_code)}</span></div>
                            ${row.mobile ? `<div class="card-meta"><a href="tel:${supEscape(row.mobile)}">${supEscape(row.mobile)}</a></div>` : ''}
                            ${dueHtml}
                            <div class="mt-2">${supStatusPill(row.is_active)}</div>
                        </div>
                    </div>
                    <div class="card-actions branch-action-bar">
                        <a href="${SUP_BASE}/show/${row.id}" class="btn-action view"><i class="fas fa-circle-info"></i></a>
                        <a href="${SUP_BASE}/edit/${row.id}" class="btn-action edit"><i class="fas fa-pen"></i></a>
                        ${SUP_SHOW_DELETED
                            ? `<button type="button" class="btn-action restore" onclick="restoreSupplier(${row.id})"><i class="fas fa-rotate-left"></i></button>`
                            : `<button type="button" class="btn-action toggle-off" onclick="deleteSupplier(${row.id}, '${name}')"><i class="fas fa-power-off"></i></button>`}
                    </div>
                </article>
            `;
        });
    }

    container.innerHTML = html;
}
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
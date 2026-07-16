<?php
ob_start();
$title = $title ?? 'Customer directory';
$showDeleted = !empty($showDeleted);
$salesPersons = $salesPersons ?? [];
$stats = $stats ?? ['active' => 0, 'inactive' => 0, 'with_due' => 0, 'total_receivable' => 0];
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/customer-theme.css">

<div class="branch-hub customer-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1>
                <i class="fas fa-users me-2"></i>
                <?= $showDeleted ? 'Inactive customers' : 'Customer directory' ?>
            </h1>
            <p>
                <?= $showDeleted
                    ? 'Restore accounts when needed — deactivation stays blocked while AR or invoice history exists.'
                    : 'Master data for sales invoices, challan, payments, and customer_ledger AR.' ?>
            </p>
            <span class="hero-badge">
                <i class="fas fa-book"></i>
                customer_ledger · Tk
            </span>
        </div>
        <div class="branch-hub-actions">
            <?php if ($showDeleted): ?>
                <a href="<?= BASE_URL ?>customer" class="btn btn-light btn-sm">
                    <i class="fas fa-users me-1"></i> Active list
                </a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>customer?deleted=1" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-user-slash me-1"></i> Inactive (<?= (int)($stats['inactive'] ?? 0) ?>)
                </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>customer/audit" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
            <a href="<?= BASE_URL ?>customer/create" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New customer
            </a>
        </div>
    </header>

    <?php if (!$showDeleted): ?>
    <div class="branch-hub-stats">
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-user-check"></i></div>
            <div>
                <div class="stat-value"><?= (int)($stats['active'] ?? 0) ?></div>
                <div class="stat-label">Active accounts</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-user-slash"></i></div>
            <div>
                <div class="stat-value"><?= (int)($stats['inactive'] ?? 0) ?></div>
                <div class="stat-label">Inactive</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon amber"><i class="fas fa-hand-holding-dollar"></i></div>
            <div>
                <div class="stat-value"><?= (int)($stats['with_due'] ?? 0) ?></div>
                <div class="stat-label">With balance due</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-coins"></i></div>
            <div>
                <div class="stat-value">Tk <?= number_format((float)($stats['total_receivable'] ?? 0), 0) ?></div>
                <div class="stat-label">Total receivable</div>
            </div>
        </div>
    </div>

    <nav class="branch-hub-quick">
        <a href="<?= BASE_URL ?>CustomerTransaction"><i class="fas fa-money-bill-wave"></i> Payments</a>
        <a href="<?= BASE_URL ?>sales/create"><i class="fas fa-file-invoice"></i> New invoice</a>
        <a href="<?= BASE_URL ?>SalesAudit/checklist"><i class="fas fa-clipboard-list"></i> Sales audit</a>
        <a href="<?= BASE_URL ?>product"><i class="fas fa-boxes-stacked"></i> Products</a>
    </nav>
    <?php endif; ?>

    <div class="branch-hub-panel">
        <?php if (!$showDeleted): ?>
        <div class="branch-hub-filters">
            <div class="row g-3 align-items-end">
                <div class="col-sm-4 col-md-3">
                    <div class="filter-label">Sales person</div>
                    <select id="filterSalesPerson" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($salesPersons as $emp): ?>
                            <option value="<?= (int)$emp['id'] ?>">
                                <?= htmlspecialchars($emp['name'] ?? $emp['employee_name'] ?? '', ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
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
            <table class="table table-borderless mb-0 align-middle w-100" id="customerTable">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Shop / contact</th>
                        <th class="d-none d-lg-table-cell">Mobile</th>
                        <th>AR balance</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <div id="customerCards" class="d-md-none" aria-live="polite"></div>
    </div>
</div>

<script>
const CUST_BASE = "<?= BASE_URL ?>customer";
const CUST_SHOW_DELETED = <?= $showDeleted ? 'true' : 'false' ?>;
const CUST_CSRF = "<?= $csrfToken ?>";

function custEscape(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function custInitial(name) {
    const n = (name || '').trim();
    return n ? n.charAt(0).toUpperCase() : '?';
}

function custStatusPill(isActive) {
    const on = parseInt(isActive, 10) === 1;
    return on
        ? '<span class="branch-status-pill active"><span class="dot"></span> Active</span>'
        : '<span class="branch-status-pill inactive"><span class="dot"></span> Inactive</span>';
}

function custFormatTk(amount) {
    const n = parseFloat(amount) || 0;
    return 'Tk ' + n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function custBalanceCell(row) {
    const due = parseFloat(row.balance_due) || 0;
    if (due <= 0.009) {
        return '<span class="customer-ar-clear">Clear</span>';
    }
    return `<span class="customer-ar-due" title="Outstanding AR">${custFormatTk(due)}</span>`;
}

function custNameCell(row) {
    const shop = row.shop_name || '—';
    const person = row.customer_name
        ? `<div class="branch-contact"><i class="fas fa-user"></i> ${custEscape(row.customer_name)}</div>`
        : '';
    const sales = row.sales_person_name
        ? `<span class="customer-sales-pill"><i class="fas fa-user-tie"></i> ${custEscape(row.sales_person_name)}</span>`
        : '';
    const mobile = row.mobile
        ? `<div class="branch-contact d-lg-none"><i class="fas fa-phone"></i> ${custEscape(row.mobile)}</div>`
        : '';
    return `<div class="branch-name-cell">
        <div class="branch-avatar">${custInitial(shop)}</div>
        <div>
            <div class="name"><a href="${CUST_BASE}/show/${row.id}" class="text-decoration-none text-reset">${custEscape(shop)}</a></div>
            ${person}
            ${mobile}
            ${sales ? '<div class="mt-1">' + sales + '</div>' : ''}
        </div>
    </div>`;
}

function custActionHtml(row) {
    const id = row.id;
    const name = (row.shop_name || row.customer_name || 'this customer').replace(/'/g, "\\'");
    let html = '<div class="branch-action-bar">';
    html += `<a href="${CUST_BASE}/show/${id}" class="btn-action view" title="Hub"><i class="fas fa-circle-info"></i></a>`;
    html += `<a href="${CUST_BASE}/edit/${id}" class="btn-action edit" title="Edit"><i class="fas fa-pen"></i></a>`;
    if (CUST_SHOW_DELETED) {
        html += `<button type="button" class="btn-action restore" title="Restore" onclick="restoreCustomer(${id})"><i class="fas fa-rotate-left"></i></button>`;
    } else {
        html += `<button type="button" class="btn-action toggle-off" title="Deactivate" onclick="deleteCustomer(${id}, '${name}')"><i class="fas fa-power-off"></i></button>`;
    }
    html += '</div>';
    return html;
}

$(document).ready(function() {
    const table = $('#customerTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: CUST_BASE + (CUST_SHOW_DELETED ? '?deleted=1' : ''),
            data: function(d) {
                d.filterSalesPerson = $('#filterSalesPerson').val();
                d.filterStatus = $('#filterStatus').val();
                if (CUST_SHOW_DELETED) d.includeDeleted = 1;
            }
        },
        pageLength: 25,
        order: [[1, 'asc']],
        dom: '<"branch-dt-toolbar"lf>Brt<"branch-dt-footer"ip>',
        buttons: ['copy', 'excel', 'pdf'],
        language: {
            processing: '<i class="fas fa-circle-notch fa-spin me-1"></i> Loading customers…',
            emptyTable: 'No customers found',
            zeroRecords: 'No matching customers'
        },
        drawCallback: function() {
            renderCustomerCards(this.api());
        },
        columns: [
            {
                data: 'customer_code',
                render: function(data) {
                    return `<span class="branch-code-pill">${custEscape(data)}</span>`;
                }
            },
            {
                data: 'shop_name',
                render: function(data, type, row) {
                    return custNameCell(row);
                }
            },
            {
                data: 'mobile',
                className: 'd-none d-lg-table-cell',
                render: function(data) {
                    return data
                        ? `<a href="tel:${custEscape(data)}" class="branch-contact"><i class="fas fa-phone"></i> ${custEscape(data)}</a>`
                        : '<span class="text-muted">—</span>';
                }
            },
            {
                data: 'balance_due',
                render: function(data, type, row) {
                    return custBalanceCell(row);
                }
            },
            {
                data: 'is_active',
                render: function(data) {
                    return custStatusPill(data);
                }
            },
            {
                data: 'id',
                orderable: false,
                className: 'text-center',
                render: function(data, type, row) {
                    return custActionHtml(row);
                }
            }
        ]
    });

    $('#filterSalesPerson, #filterStatus').on('change', function() {
        table.ajax.reload();
    });

    $('#clearFilters').on('click', function() {
        $('#filterSalesPerson').val('');
        $('#filterStatus').val('');
        table.ajax.reload();
    });

    let resizeTimer;
    $(window).on('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            renderCustomerCards(table);
        }, 200);
    });

    window.customerTable = table;
});

function deleteCustomer(id, name) {
    Swal.fire({
        title: 'Deactivate this customer?',
        html: `This will <strong>deactivate</strong> <strong>"${name}"</strong>.<br>
               Hidden from active lists but can be restored later.<br><br>
               <small class="text-muted">Blocked if outstanding AR or sales invoice history exists.</small>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, deactivate'
    }).then((result) => {
        if (!result.isConfirmed) return;
        fetch(CUST_BASE + '/delete/' + id, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ csrf_token: CUST_CSRF })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                Swal.fire({ title: 'Deactivated', text: data.message, icon: 'success', timer: 1400, showConfirmButton: false });
                window.customerTable?.ajax.reload(null, false);
            } else {
                Swal.fire('Error', data.message || 'Failed to deactivate customer.', 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Failed to deactivate customer.', 'error'));
    });
}

function restoreCustomer(id) {
    Swal.fire({
        title: 'Restore this customer?',
        text: 'This customer will become active again.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, restore'
    }).then((result) => {
        if (!result.isConfirmed) return;
        fetch(CUST_BASE + '/restore/' + id, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ csrf_token: CUST_CSRF })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                Swal.fire({ title: 'Restored', text: data.message, icon: 'success', timer: 1400, showConfirmButton: false });
                window.customerTable?.ajax.reload(null, false);
            } else {
                Swal.fire('Error', data.message || 'Failed to restore customer.', 'error');
            }
        });
    });
}

function renderCustomerCards(table) {
    const container = document.getElementById('customerCards');
    if (!container || window.innerWidth >= 768) {
        if (container) container.innerHTML = '';
        return;
    }

    const data = table.rows({ page: 'current' }).data();
    let html = '';

    if (data.length === 0) {
        html = '<div class="text-center text-muted py-4"><i class="fas fa-users fa-2x mb-2 opacity-50"></i><br>No customers found.</div>';
    } else {
        data.each(function(row) {
            const due = parseFloat(row.balance_due) || 0;
            const dueHtml = due > 0.009
                ? `<div class="customer-ar-due">${custFormatTk(due)} due</div>`
                : '<div class="customer-ar-clear">AR clear</div>';
            const name = (row.shop_name || row.customer_name || 'this customer').replace(/'/g, "\\'");

            html += `
                <article class="customer-mobile-card">
                    <div class="card-head">
                        <div class="branch-avatar">${custInitial(row.shop_name)}</div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><a href="${CUST_BASE}/show/${row.id}" class="text-decoration-none text-reset">${custEscape(row.shop_name)}</a></div>
                            <div class="card-meta"><span class="branch-code-pill">${custEscape(row.customer_code)}</span></div>
                            ${row.customer_name ? `<div class="card-meta">${custEscape(row.customer_name)}</div>` : ''}
                            ${row.mobile ? `<div class="card-meta"><a href="tel:${custEscape(row.mobile)}">${custEscape(row.mobile)}</a></div>` : ''}
                            ${dueHtml}
                            <div class="mt-2">${custStatusPill(row.is_active)}</div>
                        </div>
                    </div>
                    <div class="card-actions branch-action-bar">
                        <a href="${CUST_BASE}/show/${row.id}" class="btn-action view"><i class="fas fa-circle-info"></i></a>
                        <a href="${CUST_BASE}/edit/${row.id}" class="btn-action edit"><i class="fas fa-pen"></i></a>
                        ${CUST_SHOW_DELETED
                            ? `<button type="button" class="btn-action restore" onclick="restoreCustomer(${row.id})"><i class="fas fa-rotate-left"></i></button>`
                            : `<button type="button" class="btn-action toggle-off" onclick="deleteCustomer(${row.id}, '${name}')"><i class="fas fa-power-off"></i></button>`}
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
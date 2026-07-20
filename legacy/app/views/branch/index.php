<?php
ob_start();
$title = $title ?? 'Branch Management';
$showDeleted = !empty($showDeleted);
$stats = $stats ?? ['active' => 0, 'inactive' => 0, 'warehouses' => 0, 'employees' => 0];
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
$canManage = Auth::isAdmin();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">

<div class="branch-hub container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1>
                <i class="fas fa-sitemap me-2"></i>
                <?= $showDeleted ? 'Inactive branches' : 'Branch network' ?>
            </h1>
            <p>
                <?= $showDeleted
                    ? 'Review deactivated locations. Restore when ready to operate again.'
                    : 'Organize locations, warehouses, and teams across your Remote Center ERP footprint.' ?>
            </p>
            <span class="hero-badge">
                <i class="fas fa-shield-halved"></i>
                Master data · Sales · Stock · HR
            </span>
        </div>
        <div class="branch-hub-actions">
            <?php if ($showDeleted): ?>
                <a href="<?= BASE_URL ?>branch" class="btn btn-light btn-sm">
                    <i class="fas fa-building me-1"></i> Active branches
                </a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>branch?deleted=1" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-box-archive me-1"></i> Inactive (<?= (int)($stats['inactive'] ?? 0) ?>)
                </a>
            <?php endif; ?>
            <?php if ($canManage): ?>
            <a href="<?= BASE_URL ?>branch/audit" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
            <a href="<?= BASE_URL ?>branch/create" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New branch
            </a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$showDeleted): ?>
    <div class="branch-hub-stats">
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-building"></i></div>
            <div>
                <div class="stat-value"><?= (int)($stats['active'] ?? 0) ?></div>
                <div class="stat-label">Active branches</div>
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
            <div class="branch-stat-icon amber"><i class="fas fa-warehouse"></i></div>
            <div>
                <div class="stat-value"><?= (int)($stats['warehouses'] ?? 0) ?></div>
                <div class="stat-label">Warehouses</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-users"></i></div>
            <div>
                <div class="stat-value"><?= (int)($stats['employees'] ?? 0) ?></div>
                <div class="stat-label">Team members</div>
            </div>
        </div>
    </div>


    <?php endif; ?>

    <div class="branch-hub-panel">
        <?php if (!$showDeleted): ?>
        <div class="branch-hub-filters">
            <div class="row g-3 align-items-end">
                <div class="col-sm-4 col-md-3">
                    <div class="filter-label">Status</div>
                    <select id="filterStatus" class="form-select form-select-sm">
                        <option value="">All active</option>
                        <option value="active">Active only</option>
                        <option value="inactive">Inactive only</option>
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
            <table class="table table-borderless mb-0 align-middle w-100" id="branchTable">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Branch</th>
                        <th class="d-none d-lg-table-cell">Contact</th>
                        <th>Linked</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <div id="branchCards" class="d-md-none" aria-live="polite"></div>
    </div>
</div>

<script>
const BRANCH_BASE = "<?= BASE_URL ?>branch";
const WH_BASE = "<?= BASE_URL ?>warehouse";
const BRANCH_SHOW_DELETED = <?= $showDeleted ? 'true' : 'false' ?>;
const BRANCH_CSRF = "<?= $csrfToken ?>";
const BRANCH_CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;

function branchInitial(name) {
    const n = (name || '').trim();
    return n ? n.charAt(0).toUpperCase() : '?';
}

function branchStatusPill(isActive) {
    const on = parseInt(isActive, 10) === 1;
    return on
        ? '<span class="branch-status-pill active"><span class="dot"></span> Active</span>'
        : '<span class="branch-status-pill inactive"><span class="dot"></span> Inactive</span>';
}

function branchMiniStats(row) {
    const wh = parseInt(row.warehouse_count, 10) || 0;
    const em = parseInt(row.employee_count, 10) || 0;
    const whStat = wh > 0
        ? `<a href="${WH_BASE}?branch=${row.id}" class="branch-mini-stat text-decoration-none" title="View warehouses for this branch"><i class="fas fa-warehouse"></i> ${wh}</a>`
        : `<span class="branch-mini-stat text-muted" title="No warehouses"><i class="fas fa-warehouse"></i> ${wh}</span>`;
    return `<div class="branch-mini-stats">
        ${whStat}
        <span class="branch-mini-stat" title="Active employees"><i class="fas fa-users"></i> ${em}</span>
    </div>`;
}

function branchActionHtml(id, isActive) {
    let html = '<div class="branch-action-bar">';
    html += `<a href="${BRANCH_BASE}/show/${id}" class="btn-action view" title="Branch hub"><i class="fas fa-sitemap"></i></a>`;
    if (!BRANCH_CAN_MANAGE) {
        html += '</div>';
        return html;
    }
    html += `<a href="${BRANCH_BASE}/edit/${id}" class="btn-action edit" title="Edit"><i class="fas fa-pen"></i></a>`;
    if (BRANCH_SHOW_DELETED) {
        html += `<button type="button" class="btn-action restore" title="Restore" onclick="restoreBranch(${id})"><i class="fas fa-rotate-left"></i></button>`;
    } else {
        const on = parseInt(isActive, 10) === 1;
        const cls = on ? 'toggle-off' : 'toggle-on';
        const icon = on ? 'fa-power-off' : 'fa-check';
        const title = on ? 'Deactivate' : 'Activate';
        html += `<button type="button" class="btn-action ${cls}" title="${title}" onclick="toggleBranchStatus(${id}, ${on ? 1 : 0})"><i class="fas ${icon}"></i></button>`;
    }
    html += '</div>';
    return html;
}

function renderBranchNameCell(row) {
    const addr = row.address ? `<div class="branch-contact d-lg-none"><i class="fas fa-location-dot"></i> ${escapeHtml(row.address)}</div>` : '';
    return `<div class="branch-name-cell">
        <div class="branch-avatar">${branchInitial(row.branch_name)}</div>
        <div>
            <div class="name"><a href="${BRANCH_BASE}/show/${row.id}" class="text-decoration-none text-dark">${escapeHtml(row.branch_name)}</a></div>
            ${addr}
        </div>
    </div>`;
}

function renderBranchContactCell(row) {
    const phone = row.phone ? `<div class="branch-contact"><i class="fas fa-phone"></i> ${escapeHtml(row.phone)}</div>` : '';
    const email = row.email ? `<div class="branch-contact"><i class="fas fa-envelope"></i> ${escapeHtml(row.email)}</div>` : '';
    const addr = row.address ? `<div class="branch-contact"><i class="fas fa-location-dot"></i> ${escapeHtml(row.address)}</div>` : '';
    if (!phone && !email && !addr) {
        return '<span class="text-muted">—</span>';
    }
    return phone + email + addr;
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

$(document).ready(function() {
    const table = $('#branchTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: BRANCH_BASE + (BRANCH_SHOW_DELETED ? '?deleted=1' : ''),
            data: function(d) {
                d.filterStatus = $('#filterStatus').val();
                if (BRANCH_SHOW_DELETED) {
                    d.includeDeleted = 1;
                }
            }
        },
        pageLength: 25,
        order: [[1, 'asc']],
        dom: '<"branch-dt-toolbar"lf>Brt<"branch-dt-footer"ip>',
        buttons: ['copy', 'excel', 'pdf'],
        language: {
            processing: '<i class="fas fa-circle-notch fa-spin me-1"></i> Loading branches…',
            emptyTable: 'No branches found',
            zeroRecords: 'No matching branches'
        },
        drawCallback: function() {
            renderBranchCards(this.api());
        },
        columns: [
            {
                data: 'branch_code',
                render: function(data) {
                    return `<span class="branch-code-pill">${escapeHtml(data)}</span>`;
                }
            },
            {
                data: 'branch_name',
                render: function(data, type, row) {
                    return renderBranchNameCell(row);
                }
            },
            {
                data: null,
                orderable: false,
                className: 'd-none d-lg-table-cell',
                render: function(data, type, row) {
                    return renderBranchContactCell(row);
                }
            },
            {
                data: null,
                orderable: false,
                render: function(data, type, row) {
                    return branchMiniStats(row);
                }
            },
            {
                data: 'is_active',
                render: function(data) {
                    return branchStatusPill(data);
                }
            },
            {
                data: 'id',
                orderable: false,
                className: 'text-center',
                render: function(data, type, row) {
                    return branchActionHtml(data, row.is_active);
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
            renderBranchCards(table);
        }, 200);
    });

    window.branchTable = table;
});

function renderBranchCards(table) {
    const container = document.getElementById('branchCards');
    if (!container || window.innerWidth >= 768) {
        if (container) container.innerHTML = '';
        return;
    }

    const data = table.rows({ page: 'current' }).data();
    let html = '';

    if (data.length === 0) {
        html = '<div class="text-center text-muted py-4"><i class="fas fa-building fa-2x mb-2 opacity-50"></i><br>No branches found.</div>';
    } else {
        data.each(function(row) {
            const contact = [row.phone, row.email].filter(Boolean).join(' · ') || 'No contact on file';
            html += `
                <article class="branch-mobile-card">
                    <div class="card-head">
                        <div class="branch-avatar">${branchInitial(row.branch_name)}</div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><a href="${BRANCH_BASE}/show/${row.id}" class="text-decoration-none text-dark">${escapeHtml(row.branch_name)}</a></div>
                            <div class="card-meta"><span class="branch-code-pill">${escapeHtml(row.branch_code)}</span></div>
                            <div class="card-meta">${escapeHtml(contact)}</div>
                        </div>
                        ${branchStatusPill(row.is_active)}
                    </div>
                    ${row.address ? `<div class="card-meta"><i class="fas fa-location-dot me-1"></i>${escapeHtml(row.address)}</div>` : ''}
                    ${branchMiniStats(row)}
                    <div class="card-actions">${branchActionHtml(row.id, row.is_active)}</div>
                </article>
            `;
        });
    }

    container.innerHTML = html;
}

function reloadBranchTable() {
    if (window.branchTable) {
        window.branchTable.ajax.reload(null, false);
        return;
    }
    location.reload();
}

function toggleBranchStatus(id, isActive) {
    const title = isActive ? 'Deactivate this branch?' : 'Activate this branch?';
    const confirmText = isActive ? 'Yes, deactivate' : 'Yes, activate';

    Swal.fire({
        title: title,
        html: isActive
            ? 'This will deactivate the branch.<br>Reassign or deactivate linked <strong>warehouses</strong> and <strong>employees</strong> first.'
            : 'This branch will be available for operations again.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: confirmText,
        confirmButtonColor: isActive ? '#dc2626' : '#0f766e'
    }).then((result) => {
        if (!result.isConfirmed) return;

        fetch(BRANCH_BASE + '/toggle/' + id, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ csrf_token: BRANCH_CSRF })
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
                }).then(reloadBranchTable);
            } else {
                Swal.fire('Action blocked', data.message || 'Failed to update status.', 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Something went wrong while updating the branch.', 'error'));
    });
}

function restoreBranch(id) {
    Swal.fire({
        title: 'Restore this branch?',
        text: 'The branch will become active again.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, restore',
        confirmButtonColor: '#0f766e'
    }).then((result) => {
        if (!result.isConfirmed) return;

        fetch(BRANCH_BASE + '/toggle/' + id, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ csrf_token: BRANCH_CSRF })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                Swal.fire('Restored', data.message || 'Branch has been restored.', 'success')
                    .then(reloadBranchTable);
            } else {
                Swal.fire('Error', data.message || 'Failed to restore branch.', 'error');
            }
        });
    });
}
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
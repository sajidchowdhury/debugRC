<?php
ob_start();
require_once __DIR__ . '/../../../core/Auth.php';
$title = $title ?? 'Workforce directory';
$showDeleted = !empty($showDeleted);
$branches = $branches ?? [];
$roles = $roles ?? [];
$stats = $stats ?? ['active' => 0, 'inactive' => 0, 'with_user' => 0, 'no_user' => 0];
$isAdmin = Auth::isAdmin();
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
$publicUrl = defined('PUBLIC_URL') ? rtrim(PUBLIC_URL, '/') : rtrim(BASE_URL, '/');
$ajaxUrl = BASE_URL . 'employee' . ($showDeleted ? '?deleted=1' : '');
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/employee-theme.css">

<div class="branch-hub employee-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-id-badge me-2"></i><?= $showDeleted ? 'Deleted employees' : 'Workforce directory' ?></h1>
            <p><?= $showDeleted ? 'Soft-deleted records — restore when appropriate.' : 'Staff master data, branches, roles, and linked system users.' ?></p>
        </div>
        <div class="branch-hub-actions">
            <?php if ($showDeleted): ?>
                <a href="<?= BASE_URL ?>employee" class="btn btn-light btn-sm"><i class="fas fa-users me-1"></i> Active</a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>employee?deleted=1" class="btn btn-outline-light btn-sm"><i class="fas fa-trash me-1"></i> Deleted</a>
            <?php endif; ?>
            <?php if ($isAdmin): ?>
            <a href="<?= BASE_URL ?>user/security_audit" class="btn btn-outline-light btn-sm"><i class="fas fa-shield-halved me-1"></i> Security audit</a>
            <?php if (!$showDeleted): ?>
            <a href="<?= BASE_URL ?>employee/create" class="btn btn-light btn-sm"><i class="fas fa-plus me-1"></i> New employee</a>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!$showDeleted): ?>
    <div class="branch-hub-stats">
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-user-check"></i></div>
            <div><div class="stat-value"><?= (int)$stats['active'] ?></div><div class="stat-label">Active</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-user-slash"></i></div>
            <div><div class="stat-value"><?= (int)$stats['inactive'] ?></div><div class="stat-label">Inactive</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-user-shield"></i></div>
            <div><div class="stat-value"><?= (int)$stats['with_user'] ?></div><div class="stat-label">With login</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon amber"><i class="fas fa-user-plus"></i></div>
            <div><div class="stat-value"><?= (int)$stats['no_user'] ?></div><div class="stat-label">No user yet</div></div>
        </div>
    </div>
    <nav class="branch-hub-quick">
        <a href="<?= BASE_URL ?>user"><i class="fas fa-users-cog"></i> System users</a>
        <a href="<?= BASE_URL ?>user/security_audit"><i class="fas fa-shield-halved"></i> Security audit</a>
        <a href="<?= BASE_URL ?>branch"><i class="fas fa-sitemap"></i> Branches</a>
        <a href="<?= BASE_URL ?>customer"><i class="fas fa-store"></i> Customers</a>
    </nav>
    <?php endif; ?>

    <div class="branch-hub-panel">
        <?php if (!$showDeleted): ?>
        <div class="branch-hub-filters">
            <div class="row g-3 align-items-end">
                <div class="col-sm-6 col-md-3">
                    <div class="filter-label">Branch</div>
                    <select id="filterBranch" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?= htmlspecialchars($b['branch_name'] ?? '', ENT_QUOTES) ?>"><?= htmlspecialchars($b['branch_name'] ?? '', ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-6 col-md-2">
                    <div class="filter-label">Role</div>
                    <select id="filterRole" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= htmlspecialchars($role, ENT_QUOTES) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $role)), ENT_QUOTES) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-4 col-md-2">
                    <div class="filter-label">Status</div>
                    <select id="filterStatus" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <div class="col-sm-4 col-md-2">
                    <div class="filter-label">User account</div>
                    <select id="filterUser" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="active_user">Active user</option>
                        <option value="inactive_user">Inactive user</option>
                        <option value="no_user">No user</option>
                    </select>
                </div>
                <div class="col-sm-auto">
                    <button type="button" id="clearFilters" class="btn btn-outline-secondary btn-sm btn-clear"><i class="fas fa-rotate-left me-1"></i> Reset</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="branch-hub-table-wrap d-none d-md-block">
            <table class="table table-borderless mb-0 w-100" id="employeeTable">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th width="48"></th>
                        <th>Name</th>
                        <th class="d-none d-lg-table-cell">Branch</th>
                        <th class="d-none d-xl-table-cell">Role</th>
                        <th>Status</th>
                        <th class="d-none d-md-table-cell">User</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div id="employeeCards" class="d-md-none"></div>
    </div>
</div>

<div class="modal fade" id="employeeQuickViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Employee details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="quickViewModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
const EMP_BASE = "<?= BASE_URL ?>employee";
const EMP_CSRF = "<?= $csrfToken ?>";
const EMP_IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
const EMP_PUBLIC = "<?= htmlspecialchars($publicUrl, ENT_QUOTES) ?>";
const EMP_SHOW_DELETED = <?= $showDeleted ? 'true' : 'false' ?>;

function empEsc(s) { return s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') : ''; }
function empPhoto(data) {
    if (data) return '<img src="'+EMP_PUBLIC+'/'+data.replace(/^\//,'')+'" class="employee-photo-thumb" alt="">';
    return '<span class="employee-photo-placeholder"><i class="fas fa-user"></i></span>';
}
function empStatusPill(v) {
    return parseInt(v,10)===1 ? '<span class="branch-status-pill active"><span class="dot"></span> Active</span>' : '<span class="branch-status-pill inactive"><span class="dot"></span> Inactive</span>';
}
function empUserPill(row) {
    if (parseInt(row.has_active_user,10)>0) return '<span class="employee-user-pill active"><i class="fas fa-check"></i> Active</span>';
    if (parseInt(row.has_user_account,10)>0) return '<span class="employee-user-pill inactive"><i class="fas fa-minus"></i> Inactive</span>';
    return '<span class="employee-user-pill none">No login</span>';
}
function empNameCell(row) {
    const role = row.role ? '<span class="employee-role-pill">'+empEsc(row.role.replace(/_/g,' '))+'</span>' : '';
    const name = empEsc(row.name);
    const nameInner = EMP_IS_ADMIN
        ? '<a href="'+EMP_BASE+'/account/'+row.id+'" class="emp-hub-name-link">'+name+'</a>'
        : name;
    return '<div class="branch-name-cell"><div>'+nameInner+'</div><div class="branch-contact d-lg-none">'+empEsc(row.mobile)+'</div><div class="mt-1">'+role+'</div></div>';
}
function empActions(row) {
    const id = row.id, name = (row.name||'').replace(/'/g,"\\'");
    let h = '<div class="branch-action-bar">';
    h += '<button type="button" class="btn-action" title="Quick view" onclick="viewEmployee('+id+')"><i class="fas fa-eye"></i></button>';
    if (!EMP_IS_ADMIN) return h + '</div>';
    h += '<a href="'+EMP_BASE+'/account/'+id+'" class="btn-action" title="Employee &amp; account hub"><i class="fas fa-id-card-clip"></i></a>';
    h += '<a href="'+EMP_BASE+'/edit/'+id+'" class="btn-action edit" title="Edit profile"><i class="fas fa-pen"></i></a>';
    if (parseInt(row.has_user_account,10)<1) {
        h += '<a href="<?= BASE_URL ?>user/create?employee_id='+id+'" class="btn-action" title="Create user"><i class="fas fa-user-plus"></i></a>';
    }
    if (EMP_SHOW_DELETED) {
        h += '<button type="button" class="btn-action restore" onclick="restoreEmployee('+id+')" title="Restore"><i class="fas fa-rotate-left"></i></button>';
    } else {
        h += '<button type="button" class="btn-action toggle-off" onclick="toggleEmpStatus('+id+','+row.is_active+')"><i class="fas fa-power-off"></i></button>';
        h += '<button type="button" class="btn-action toggle-off" onclick="deleteEmployee('+id+',\''+name+'\')"><i class="fas fa-trash"></i></button>';
    }
    return h + '</div>';
}

$(function() {
    const table = $('#employeeTable').DataTable({
        processing: true, serverSide: true,
        ajax: { url: "<?= $ajaxUrl ?>", data: d => {
            d.filterBranch = $('#filterBranch').val();
            d.filterRole = $('#filterRole').val();
            d.filterStatus = $('#filterStatus').val();
            d.filterUser = $('#filterUser').val();
            if (EMP_SHOW_DELETED) d.includeDeleted = 1;
        }},
        pageLength: 25, order: [[2,'asc']],
        dom: '<"branch-dt-toolbar"lf>Brt<"branch-dt-footer"ip>',
        buttons: ['copy','excel','pdf'],
        drawCallback: () => renderEmpCards(table),
        columns: [
            { data: 'employee_code', render: d => '<span class="branch-code-pill">'+empEsc(d)+'</span>' },
            { data: 'photo', orderable: false, render: empPhoto },
            { data: 'name', render: (d,t,r) => empNameCell(r) },
            { data: 'branch_name', className: 'd-none d-lg-table-cell', defaultContent: '—' },
            { data: 'role', className: 'd-none d-xl-table-cell', render: d => d ? '<span class="employee-role-pill">'+empEsc(String(d).replace(/_/g,' '))+'</span>' : '—' },
            { data: 'is_active', render: empStatusPill },
            { data: null, className: 'd-none d-md-table-cell', orderable: false, render: (d,t,r) => empUserPill(r) },
            { data: 'id', orderable: false, className: 'text-center', render: (d,t,r) => empActions(r) }
        ]
    });
    $('#filterBranch,#filterRole,#filterStatus,#filterUser').on('change', () => table.ajax.reload());
    $('#clearFilters').on('click', () => { $('#filterBranch,#filterRole,#filterStatus,#filterUser').val(''); table.ajax.reload(); });
    window.employeeTable = table;
});

function reloadEmployeeTable() {
    if (window.employeeTable) {
        window.employeeTable.ajax.reload(null, false);
        return;
    }
    location.reload();
}
function postEmployeeAction(url, successTitle) {
    return fetch(url, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ csrf_token: EMP_CSRF })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            Swal.fire({
                title: successTitle || 'Done',
                text: data.message,
                icon: 'success',
                timer: 1400,
                showConfirmButton: false
            }).then(reloadEmployeeTable);
        } else {
            Swal.fire('Action blocked', data.message || 'Request failed.', 'error');
        }
    })
    .catch(() => Swal.fire('Error', 'Something went wrong. Please refresh and try again.', 'error'));
}
function toggleEmpStatus(id, isActive) {
    Swal.fire({ title: isActive ? 'Deactivate?' : 'Activate?', icon: 'warning', showCancelButton: true }).then(r => {
        if (r.isConfirmed) postEmployeeAction(EMP_BASE + '/toggle/' + id, 'Status updated');
    });
}
function deleteEmployee(id, name) {
    Swal.fire({ title: 'Delete employee?', html: 'Soft delete <strong>'+name+'</strong>', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33' }).then(r => {
        if (r.isConfirmed) postEmployeeAction(EMP_BASE + '/delete/' + id, 'Employee deleted');
    });
}
function restoreEmployee(id) {
    Swal.fire({ title: 'Restore this employee?', icon: 'question', showCancelButton: true, confirmButtonText: 'Yes, restore' }).then(r => {
        if (r.isConfirmed) postEmployeeAction(EMP_BASE + '/restore/' + id, 'Employee restored');
    });
}
function viewEmployee(id) {
    const modal = new bootstrap.Modal(document.getElementById('employeeQuickViewModal'));
    const body = document.getElementById('quickViewModalBody');
    body.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
    modal.show();
    fetch(EMP_BASE + '/quickView/' + id).then(r => r.ok ? r.text() : Promise.reject()).then(html => { body.innerHTML = html; }).catch(() => {
        body.innerHTML = '<p class="text-danger text-center">Failed to load.</p>';
    });
}
function renderEmpCards(table) {
    const c = document.getElementById('employeeCards');
    if (!c || window.innerWidth >= 768) { if (c) c.innerHTML = ''; return; }
    let html = '';
    table.rows({page:'current'}).data().each(row => {
        html += '<article class="employee-mobile-card"><div class="d-flex gap-2">'+empPhoto(row.photo)+'<div><strong>'+empEsc(row.name)+'</strong><div class="small">'+empEsc(row.employee_code)+'</div>'+empUserPill(row)+'</div></div><div class="mt-2">'+empActions(row)+'</div></article>';
    });
    c.innerHTML = html || '<div class="text-muted text-center py-4">No employees</div>';
}
</script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
<?php
ob_start();
$title = $title ?? 'Chart of Accounts';
$showInactive = !empty($showInactive);
$stats = $stats ?? ['active' => 0, 'inactive' => 0, 'system' => 0, 'control' => 0];
$natureGroups = $natureGroups ?? [];
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/ledger-theme.css">

<div class="branch-hub ledger-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-book me-2"></i><?= $showInactive ? 'Inactive ledgers' : 'Chart of Accounts' ?></h1>
            <p><?= $showInactive
                ? 'Deactivated accounts — restore with Activate when safe (no journal conflicts).'
                : 'General ledger heads for double-entry posting, trial balance, and automated sales/purchase journals.' ?></p>
            <span class="hero-badge"><i class="fas fa-scale-balanced"></i> GL · Tk</span>
        </div>
        <div class="branch-hub-actions">
            <?php if ($showInactive): ?>
                <a href="<?= BASE_URL ?>ledger" class="btn btn-light btn-sm"><i class="fas fa-book me-1"></i> Active</a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>ledger?inactive=1" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-box-archive me-1"></i> Inactive (<?= (int)($stats['inactive'] ?? 0) ?>)
                </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>ledger/audit" class="btn btn-outline-light btn-sm"><i class="fas fa-clock-rotate-left me-1"></i> Audit</a>
            <a href="<?= BASE_URL ?>ledger/create" class="btn btn-light btn-sm"><i class="fas fa-plus me-1"></i> New ledger</a>
        </div>
    </header>

    <?php if (!$showInactive): ?>
    <div class="branch-hub-stats">
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-check-circle"></i></div>
            <div><div class="stat-value"><?= (int)($stats['active'] ?? 0) ?></div><div class="stat-label">Active accounts</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-moon"></i></div>
            <div><div class="stat-value"><?= (int)($stats['inactive'] ?? 0) ?></div><div class="stat-label">Inactive</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon amber"><i class="fas fa-shield-halved"></i></div>
            <div><div class="stat-value"><?= (int)($stats['system'] ?? 0) ?></div><div class="stat-label">System (protected)</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-sitemap"></i></div>
            <div><div class="stat-value"><?= (int)($stats['control'] ?? 0) ?></div><div class="stat-label">Control accounts</div></div>
        </div>
    </div>
    <?php include __DIR__ . '/../partials/accounting_quick_nav.php'; ?>
    <?php endif; ?>

    <div class="branch-hub-panel">
        <?php if (!$showInactive): ?>
        <div class="branch-hub-filters">
            <div class="row g-3 align-items-end">
                <div class="col-sm-6 col-md-2">
                    <div class="filter-label">Account type</div>
                    <select id="filterAccountType" class="form-select form-select-sm">
                        <option value="">All types</option>
                        <option value="Asset">Asset</option>
                        <option value="Liability">Liability</option>
                        <option value="Equity">Equity</option>
                        <option value="Income">Income</option>
                        <option value="Expense">Expense</option>
                    </select>
                </div>
                <div class="col-sm-6 col-md-2">
                    <div class="filter-label">Nature</div>
                    <select id="filterLedgerNature" class="form-select form-select-sm">
                        <option value="">All natures</option>
                        <?php foreach ($natureGroups as $groupLabel => $options): ?>
                        <optgroup label="<?= htmlspecialchars($groupLabel, ENT_QUOTES) ?>">
                            <?php foreach ($options as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-sm-4 col-md-2">
                    <div class="filter-label">Control</div>
                    <select id="filterIsControl" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <option value="1">Control only</option>
                        <option value="0">Non-control</option>
                    </select>
                </div>
                <div class="col-sm-4 col-md-2">
                    <div class="filter-label">System</div>
                    <select id="filterIsSystem" class="form-select form-select-sm">
                        <option value="">Any</option>
                        <option value="1">System only</option>
                        <option value="0">User-defined</option>
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
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" id="filterViewHierarchy" value="1">
                        <label class="form-check-label small" for="filterViewHierarchy">Hierarchy view</label>
                    </div>
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
            <table class="table table-borderless mb-0 w-100" id="ledgerTable">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th class="d-none d-lg-table-cell">Parent</th>
                        <th>Type</th>
                        <th class="d-none d-xl-table-cell">Nature</th>
                        <th>Normal</th>
                        <th class="d-none d-lg-table-cell">Flags</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div id="ledgerCards" class="d-md-none" aria-live="polite"></div>
    </div>
</div>

<script>
const LEDGER_BASE = "<?= BASE_URL ?>ledger";
const LEDGER_SHOW_INACTIVE = <?= $showInactive ? 'true' : 'false' ?>;
const LEDGER_CSRF = "<?= $csrfToken ?>";

function ledgerEsc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function ledgerInitial(name) {
    const n = (name || '').trim();
    return n ? n.charAt(0).toUpperCase() : '?';
}
function ledgerTypePill(type) {
    const t = (type || '').toLowerCase();
    const cls = ['asset','liability','equity','income','expense'].includes(t) ? t : 'expense';
    return '<span class="ledger-type-pill '+cls+'">'+ledgerEsc(type || '—')+'</span>';
}
function ledgerNbPill(nb) {
    if (!nb) return '—';
    const c = nb === 'credit' ? 'credit' : 'debit';
    return '<span class="ledger-nb-pill '+c+'">'+ledgerEsc(nb)+'</span>';
}
function ledgerNatureLabel(n) {
    if (!n) return '—';
    return ledgerEsc(String(n).replace(/_/g, ' '));
}
function ledgerStatusPill(v) {
    return parseInt(v,10)===1
        ? '<span class="branch-status-pill active"><span class="dot"></span> Active</span>'
        : '<span class="branch-status-pill inactive"><span class="dot"></span> Inactive</span>';
}
function ledgerFlagsCell(row) {
    let h = '';
    if (parseInt(row.is_control_account,10)===1) h += '<span class="badge bg-success-subtle text-success border me-1">Control</span>';
    if (parseInt(row.is_system,10)===1) h += '<span class="ledger-system-badge">System</span>';
    return h || '<span class="text-muted small">—</span>';
}
function ledgerNameCell(row) {
    const depth = parseInt(row.hierarchy_depth, 10) || 0;
    const indentPx = depth * 14;
    const indent = depth > 0 ? '<span class="ledger-tree-indent" style="padding-left:'+indentPx+'px"></span>' : '';
    const parent = row.parent_name
        ? '<div class="branch-contact d-lg-none"><i class="fas fa-level-up-alt"></i> '+ledgerEsc(row.parent_name)+'</div>'
        : '';
    return '<div class="branch-name-cell"><div class="branch-avatar">'+ledgerInitial(row.ledger_name)+'</div><div>'+indent+
        '<div class="name"><a href="'+LEDGER_BASE+'/show/'+row.id+'" class="text-decoration-none text-dark">'+ledgerEsc(row.ledger_name)+'</a></div>'+
        '<span class="branch-code-pill">'+ledgerEsc(row.ledger_code)+'</span>'+parent+'</div></div>';
}
function ledgerActions(row) {
    const id = row.id, isSystem = parseInt(row.is_system,10)===1;
    let h = '<div class="branch-action-bar">';
    h += '<a href="'+LEDGER_BASE+'/show/'+id+'" class="btn-action view" title="Account hub"><i class="fas fa-book-open"></i></a>';
    h += '<a href="'+LEDGER_BASE+'/edit/'+id+'" class="btn-action '+(isSystem?'view':'edit')+'" title="'+(isSystem?'View protected':'Edit')+'">'+
        '<i class="fas fa-'+(isSystem?'eye':'pen')+'"></i></a>';
    if (!isSystem) {
        const active = parseInt(row.is_active,10)===1;
        h += '<button type="button" class="btn-action '+(active?'toggle-off':'restore')+'" onclick="toggleLedgerStatus('+id+','+row.is_active+')" title="'+(active?'Deactivate':'Activate')+'">'+
            '<i class="fas fa-'+(active?'power-off':'rotate-left')+'"></i></button>';
    }
    return h + '</div>';
}

$(function() {
    const table = $('#ledgerTable').DataTable({
        processing: true, serverSide: true,
        ajax: {
            url: LEDGER_BASE + (LEDGER_SHOW_INACTIVE ? '?inactive=1' : ''),
            data: d => {
                d.filterAccountType  = $('#filterAccountType').val();
                d.filterLedgerNature = $('#filterLedgerNature').val();
                d.filterIsControl    = $('#filterIsControl').val();
                d.filterIsSystem     = $('#filterIsSystem').val();
                d.filterStatus       = $('#filterStatus').val();
                d.viewHierarchy      = $('#filterViewHierarchy').is(':checked') ? '1' : '';
            }
        },
        pageLength: 25, order: [[0,'asc']],
        dom: '<"branch-dt-toolbar"lf>Brt<"branch-dt-footer"ip>',
        buttons: ['copy','excel','pdf'],
        drawCallback: () => renderLedgerCards(table),
        columns: [
            { data: 'ledger_name', render: (d,t,r) => ledgerNameCell(r) },
            { data: 'parent_name', className: 'd-none d-lg-table-cell', defaultContent: '—' },
            { data: 'account_type', render: d => ledgerTypePill(d) },
            { data: 'ledger_nature', className: 'd-none d-xl-table-cell', render: d => ledgerNatureLabel(d) },
            { data: 'normal_balance', render: d => ledgerNbPill(d) },
            { data: 'is_system', className: 'd-none d-lg-table-cell', render: (d,t,r) => ledgerFlagsCell(r) },
            { data: 'is_active', render: ledgerStatusPill },
            { data: 'id', orderable: false, className: 'text-center', render: (d,t,r) => ledgerActions(r) }
        ]
    });
    $('#filterAccountType, #filterLedgerNature, #filterIsControl, #filterIsSystem, #filterStatus, #filterViewHierarchy').on('change', () => table.ajax.reload());
    $('#clearFilters').on('click', () => {
        $('#filterAccountType, #filterLedgerNature, #filterIsControl, #filterIsSystem, #filterStatus').val('');
        $('#filterViewHierarchy').prop('checked', false);
        table.ajax.reload();
    });
    window.ledgerTable = table;
});

function toggleLedgerStatus(id, isActive) {
    const active = parseInt(isActive,10)===1;
    Swal.fire({
        title: active ? 'Deactivate this ledger?' : 'Activate this ledger?',
        html: active
            ? 'Hidden from new postings; blocked if journal history or sole critical nature exists.'
            : 'Account will appear in dropdowns again.',
        icon: 'warning', showCancelButton: true,
        confirmButtonText: active ? 'Deactivate' : 'Activate',
        confirmButtonColor: active ? '#d33' : '#0d9488'
    }).then(r => {
        if (!r.isConfirmed) return;

        fetch(LEDGER_BASE + '/toggle/' + id, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ csrf_token: LEDGER_CSRF })
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
                }).then(() => {
                    if (window.ledgerTable) {
                        window.ledgerTable.ajax.reload(null, false);
                        return;
                    }
                    window.location.reload();
                });
            } else {
                Swal.fire('Action blocked', data.message || 'Failed to update status.', 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Something went wrong while updating the ledger.', 'error'));
    });
}

function renderLedgerCards(table) {
    const c = document.getElementById('ledgerCards');
    if (!c || window.innerWidth >= 768) { if (c) c.innerHTML = ''; return; }
    let html = '';
    table.rows({ page: 'current' }).data().each(row => {
        html += '<article class="ledger-mobile-card">'+
            ledgerNameCell(row)+
            '<div class="small mt-1">'+ledgerTypePill(row.account_type)+' · '+ledgerNatureLabel(row.ledger_nature)+'</div>'+
            '<div class="mt-2">'+ledgerStatusPill(row.is_active)+' '+ledgerFlagsCell(row)+'</div>'+
            '<div class="card-actions">'+ledgerActions(row)+'</div></article>';
    });
    c.innerHTML = html || '<div class="text-center text-muted py-4">No ledgers found.</div>';
}
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
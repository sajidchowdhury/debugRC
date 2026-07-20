<?php
ob_start();
$title = $title ?? 'Bank accounts';
$showDeleted = !empty($showDeleted);
$stats = $stats ?? ['active' => 0, 'inactive' => 0, 'total_balance' => 0];
$csrfToken = htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bank-theme.css">

<div class="branch-hub bank-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-building-columns me-2"></i><?= $showDeleted ? 'Inactive bank accounts' : 'Bank accounts' ?></h1>
            <p><?= $showDeleted
                ? 'Restore accounts for customer payments, transfers, and other income/expense.'
                : 'Cash book bank accounts — balances updated by receipts, transfers, and accounting entries.' ?></p>
            <span class="hero-badge"><i class="fas fa-coins"></i> Tk · GL cash/bank</span>
        </div>
        <div class="branch-hub-actions">
            <?php if ($showDeleted): ?>
                <a href="<?= BASE_URL ?>bank" class="btn btn-light btn-sm"><i class="fas fa-university me-1"></i> Active</a>
            <?php else: ?>
                <a href="<?= BASE_URL ?>bank?deleted=1" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-box-archive me-1"></i> Inactive (<?= (int)($stats['inactive'] ?? 0) ?>)
                </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>bank/audit" class="btn btn-outline-light btn-sm"><i class="fas fa-clock-rotate-left me-1"></i> Audit</a>
            <a href="<?= BASE_URL ?>bank/create" class="btn btn-light btn-sm"><i class="fas fa-plus me-1"></i> New account</a>
        </div>
    </header>

    <?php if (!$showDeleted): ?>
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
            <div class="branch-stat-icon amber"><i class="fas fa-wallet"></i></div>
            <div>
                <div class="stat-value">Tk <?= number_format((float)($stats['total_balance'] ?? 0), 0) ?></div>
                <div class="stat-label">Total balance (active)</div>
            </div>
        </div>
    </div>
    <?php include __DIR__ . '/../partials/accounting_quick_nav.php'; ?>
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
            <table class="table table-borderless mb-0 w-100" id="bankTable">
                <thead>
                    <tr>
                        <th>Bank</th>
                        <th>Account</th>
                        <th class="d-none d-lg-table-cell">Branch</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div id="bankCards" class="d-md-none" aria-live="polite"></div>
    </div>
</div>

<script>
const BANK_BASE = "<?= BASE_URL ?>bank";
const BANK_SHOW_DELETED = <?= $showDeleted ? 'true' : 'false' ?>;
const BANK_CSRF = "<?= $csrfToken ?>";

function bankEsc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function bankInitial(name) {
    const n = (name || '').trim();
    return n ? n.charAt(0).toUpperCase() : '?';
}
function bankFormatTk(n) {
    const v = parseFloat(n) || 0;
    return 'Tk ' + v.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}
function bankStatusPill(v) {
    return parseInt(v,10)===1
        ? '<span class="branch-status-pill active"><span class="dot"></span> Active</span>'
        : '<span class="branch-status-pill inactive"><span class="dot"></span> Inactive</span>';
}
function bankNameCell(row) {
    const branch = row.branch_name
        ? '<div class="branch-contact d-lg-none"><i class="fas fa-location-dot"></i> '+bankEsc(row.branch_name)+'</div>'
        : '';
    const acct = row.account_number
        ? '<div class="branch-contact"><i class="fas fa-hashtag"></i> '+bankEsc(row.account_number)+'</div>'
        : '';
    return '<div class="branch-name-cell"><div class="branch-avatar">'+bankInitial(row.bank_name)+'</div><div><div class="name"><a href="'+BANK_BASE+'/show/'+row.id+'" class="text-decoration-none text-reset">'+bankEsc(row.bank_name)+'</a></div>'+acct+branch+'</div></div>';
}
function bankBalanceCell(row) {
    const bal = parseFloat(row.balance) || 0;
    if (bal <= 0.009) return '<span class="bank-balance zero">Tk 0</span>';
    return '<span class="bank-balance">'+bankFormatTk(bal)+'</span>';
}
function bankActions(row) {
    const id = row.id, name = (row.bank_name||'').replace(/'/g,"\\'");
    let h = '<div class="branch-action-bar">';
    h += '<a href="'+BANK_BASE+'/show/'+id+'" class="btn-action view" title="Hub"><i class="fas fa-circle-info"></i></a>';
    h += '<a href="'+BANK_BASE+'/edit/'+id+'" class="btn-action edit"><i class="fas fa-pen"></i></a>';
    if (BANK_SHOW_DELETED) {
        h += '<button type="button" class="btn-action restore" onclick="restoreBank('+id+')"><i class="fas fa-rotate-left"></i></button>';
    } else {
        h += '<button type="button" class="btn-action toggle-off" onclick="deleteBank('+id+',\''+name+'\')"><i class="fas fa-power-off"></i></button>';
    }
    return h + '</div>';
}

$(function() {
    const table = $('#bankTable').DataTable({
        processing: true, serverSide: true,
        ajax: {
            url: BANK_BASE + (BANK_SHOW_DELETED ? '?deleted=1' : ''),
            data: d => {
                d.filterStatus = $('#filterStatus').val();
                if (BANK_SHOW_DELETED) d.includeDeleted = 1;
            }
        },
        pageLength: 25, order: [[0,'asc']],
        dom: '<"branch-dt-toolbar"lf>Brt<"branch-dt-footer"ip>',
        buttons: ['copy','excel','pdf'],
        drawCallback: () => renderBankCards(table),
        columns: [
            { data: 'bank_name', render: (d,t,r) => bankNameCell(r) },
            { data: 'account_number', className: 'd-none d-md-table-cell', render: d => '<span class="branch-code-pill">'+bankEsc(d)+'</span>' },
            { data: 'branch_name', className: 'd-none d-lg-table-cell', defaultContent: '—' },
            { data: 'balance', render: (d,t,r) => bankBalanceCell(r) },
            { data: 'is_active', render: bankStatusPill },
            { data: 'id', orderable: false, className: 'text-center', render: (d,t,r) => bankActions(r) }
        ]
    });
    $('#filterStatus').on('change', () => table.ajax.reload());
    $('#clearFilters').on('click', () => { $('#filterStatus').val(''); table.ajax.reload(); });
    window.bankTable = table;
});

function deleteBank(id, name) {
    Swal.fire({
        title: 'Deactivate this bank?',
        html: 'Deactivate <strong>"'+name+'"</strong>. Hidden from payment lists; can be restored.<br><br><small class="text-muted">Blocked if balance is non-zero or linked transactions exist.</small>',
        icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Deactivate'
    }).then(r => {
        if (!r.isConfirmed) return;
        fetch(BANK_BASE + '/delete/' + id, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ csrf_token: BANK_CSRF })
        }).then(res => res.json()).then(data => {
            if (data.status === 'success') {
                Swal.fire({ title: 'Deactivated', text: data.message, icon: 'success', timer: 1400, showConfirmButton: false });
                window.bankTable?.ajax.reload(null, false);
            } else Swal.fire('Error', data.message || 'Failed.', 'error');
        }).catch(() => Swal.fire('Error', 'Failed to deactivate bank.', 'error'));
    });
}
function restoreBank(id) {
    Swal.fire({ title: 'Restore bank?', icon: 'question', showCancelButton: true, confirmButtonText: 'Restore' }).then(r => {
        if (!r.isConfirmed) return;
        fetch(BANK_BASE + '/restore/' + id, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({ csrf_token: BANK_CSRF })
        }).then(res => res.json()).then(data => {
            if (data.status === 'success') {
                Swal.fire({ title: 'Restored', icon: 'success', timer: 1400, showConfirmButton: false });
                window.bankTable?.ajax.reload(null, false);
            } else Swal.fire('Error', data.message || 'Failed.', 'error');
        });
    });
}
function renderBankCards(table) {
    const c = document.getElementById('bankCards');
    if (!c || window.innerWidth >= 768) { if (c) c.innerHTML = ''; return; }
    let html = '';
    table.rows({ page: 'current' }).data().each(row => {
        const name = (row.bank_name||'').replace(/'/g,"\\'");
        html += '<article class="bank-mobile-card"><div class="fw-semibold"><a href="'+BANK_BASE+'/show/'+row.id+'" class="text-decoration-none text-reset">'+bankEsc(row.bank_name)+'</a></div><div class="small text-muted">'+bankEsc(row.account_number)+'</div>'+bankBalanceCell(row)+'<div class="mt-2">'+bankStatusPill(row.is_active)+'</div><div class="card-actions">'+bankActions(row)+'</div></article>';
    });
    c.innerHTML = html || '<div class="text-center text-muted py-4">No banks found.</div>';
}
</script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
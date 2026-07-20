<?php
ob_start();
$title = 'Other Expenses';
$showReversed = $showReversed ?? false;
$stats = $stats ?? ['total' => 0, 'active' => 0, 'reversed' => 0, 'today' => 0, 'this_month' => 0];
$branch_name = $branch_name ?? 'Branch';
$ledgers = $ledgers ?? [];
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/other-expense-theme.css">
<input type="hidden" id="base_url" value="<?= BASE_URL ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

<div class="branch-hub other-expense-theme acct-money-app container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-arrow-up me-2"></i>Other Expenses</h1>
            <p>Non-operational payments with full GL and cash/bank tracking</p>
            <span class="hero-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($branch_name, ENT_QUOTES) ?></span>
        </div>
        <div class="branch-hub-actions">
            <?php if (!$showReversed): ?>
            <a href="<?= BASE_URL ?>OtherExpense/create" class="btn btn-light btn-sm"><i class="fas fa-plus me-1"></i> New Expense</a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>OtherExpense/audit" class="btn btn-outline-dark btn-sm"><i class="fas fa-history me-1"></i> Audit</a>
            <?php if ($showReversed): ?>
            <a href="<?= BASE_URL ?>OtherExpense" class="btn btn-outline-light btn-sm">Show Active</a>
            <?php else: ?>
            <a href="<?= BASE_URL ?>OtherExpense?reversed=1" class="btn btn-outline-light btn-sm"><i class="fas fa-undo me-1"></i> Reversed</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="branch-hub-stats">
        <div class="branch-stat-card"><div class="branch-stat-icon red"><i class="fas fa-receipt"></i></div><div><div class="stat-value"><?= (int)$stats['total'] ?></div><div class="stat-label">Total</div></div></div>
        <div class="branch-stat-card"><div class="branch-stat-icon teal"><i class="fas fa-check"></i></div><div><div class="stat-value"><?= (int)$stats['active'] ?></div><div class="stat-label">Active</div></div></div>
        <div class="branch-stat-card"><div class="branch-stat-icon slate"><i class="fas fa-rotate-left"></i></div><div><div class="stat-value"><?= (int)$stats['reversed'] ?></div><div class="stat-label">Reversed</div></div></div>
        <div class="branch-stat-card"><div class="branch-stat-icon indigo"><i class="fas fa-calendar-day"></i></div><div><div class="stat-value">Tk <?= number_format((float)$stats['today'], 0) ?></div><div class="stat-label">Today</div></div></div>
    </div>

    <?php include __DIR__ . '/../../partials/accounting_quick_nav.php'; ?>

    <div class="branch-hub-panel acct-has-mobile-cards">
        <div class="branch-hub-filters acct-touch-filters" role="search" aria-label="Filter other expense vouchers">
            <details class="acct-filter-drawer" open>
                <summary><i class="fas fa-filter"></i> Filters</summary>
                <div class="row g-3 align-items-end">
                <div class="col-6 col-md-2"><label class="filter-label" for="fromDate">From</label><input type="date" id="fromDate" class="form-control form-control-sm" value="<?= htmlspecialchars($fromDate ?? '', ENT_QUOTES) ?>"></div>
                <div class="col-6 col-md-2"><label class="filter-label" for="toDate">To</label><input type="date" id="toDate" class="form-control form-control-sm" value="<?= htmlspecialchars($toDate ?? '', ENT_QUOTES) ?>"></div>
                <div class="col-6 col-md-2"><label class="filter-label" for="filterLedger">Expense head</label><select id="filterLedger" class="form-select form-select-sm"><option value="">All</option><?php foreach ($ledgers as $l): ?><option value="<?= (int)$l['id'] ?>"><?= htmlspecialchars($l['ledger_name'], ENT_QUOTES) ?></option><?php endforeach; ?></select></div>
                <div class="col-6 col-md-2"><label class="filter-label" for="filterPaymentMode">Mode</label><select id="filterPaymentMode" class="form-select form-select-sm"><option value="">All</option><option value="cash">Cash</option><option value="bank">Bank</option></select></div>
                <div class="col-6 col-md-2"><label class="filter-label" for="filterStatus">Status</label><select id="filterStatus" class="form-select form-select-sm"><option value="">All</option><option value="active">Active</option><option value="reversed">Reversed</option></select></div>
                <div class="col-12 col-md-auto"><button type="button" id="clearFilters" class="btn btn-outline-secondary btn-sm w-100" aria-label="Reset filters"><i class="fas fa-rotate-left me-1"></i> Reset</button></div>
                </div>
            </details>
        </div>
        <div class="branch-hub-table-wrap acct-desktop-table">
            <table class="table table-borderless mb-0 w-100" id="expenseTable">
                <thead><tr><th>Date</th><th>Voucher</th><th>Head</th><th class="text-end">Amount</th><th>Paid from</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <div id="expenseCards" class="acct-mobile-only acct-mobile-list" aria-live="polite" aria-label="Other expense vouchers"></div>
    </div>
</div>
<script>window.showReversed = <?= !empty($showReversed) ? 'true' : 'false' ?>;</script>
<script src="<?= BASE_URL ?>assets/js/OtherExpense.js"></script>
<?php $content = ob_get_clean(); require_once '../app/views/layouts/main.php'; ?>
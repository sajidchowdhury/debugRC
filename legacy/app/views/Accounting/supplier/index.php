<?php
ob_start();
$title = $title ?? 'Supplier payments';
$filters = $filters ?? [];
$filterSupplier = $filterSupplier ?? null;
$showReversed = $showReversed ?? false;
$stats = $stats ?? ['total' => 0, 'active' => 0, 'reversed' => 0, 'paid_today' => 0, 'paid_month' => 0];
$branch_name = $branch_name ?? 'Head Office';
$filterSupplierId = (int)($filters['supplier_id'] ?? 0);
$filterSupplierLabel = $filterSupplier
    ? trim((string)($filterSupplier['supplier_name'] ?? ''))
    : '';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/supplier-transaction-theme.css">
<input type="hidden" id="base_url" value="<?= BASE_URL ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

<div class="branch-hub supp-txn-theme acct-money-app container-fluid py-2" id="supplierTransactionIndex">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-handshake me-2"></i>Supplier payments</h1>
            <p>Payment, advance, and receive — supplier ledger, GL, and bank book (real money flow).</p>
            <span class="hero-badge"><i class="fas fa-building"></i> <?= htmlspecialchars($branch_name, ENT_QUOTES) ?> · Tk</span>
            <?php
            $todayYmd = date('Y-m-d');
            $showingToday = ($filters['date_from'] ?? '') === $todayYmd && ($filters['date_to'] ?? '') === $todayYmd;
            if ($showingToday): ?>
            <span class="hero-badge ms-1"><i class="fas fa-calendar-day"></i> Today</span>
            <?php endif; ?>
        </div>
        <div class="branch-hub-actions">
            <?php if (!$showReversed): ?>
            <a href="<?= BASE_URL ?>SupplierTransaction/create" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New payment
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>SupplierTransaction/audit" class="btn btn-outline-dark btn-sm">
                <i class="fas fa-history me-1"></i> Audit
            </a>
            <?php if ($showReversed): ?>
            <a href="<?= BASE_URL ?>SupplierTransaction" class="btn btn-outline-light btn-sm">Show active</a>
            <?php else: ?>
            <a href="<?= BASE_URL ?>SupplierTransaction?reversed=1" class="btn btn-outline-light btn-sm">
                <i class="fas fa-undo me-1"></i> Reversed
            </a>
            <?php endif; ?>
        </div>
    </header>

    <div class="branch-hub-stats">
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-receipt"></i></div>
            <div><div class="stat-value"><?= (int)($stats['total'] ?? 0) ?></div><div class="stat-label">Total vouchers</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-check"></i></div>
            <div><div class="stat-value"><?= (int)($stats['active'] ?? 0) ?></div><div class="stat-label">Active</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon amber"><i class="fas fa-sun"></i></div>
            <div>
                <div class="stat-value">Tk <?= number_format((float)($stats['paid_today'] ?? 0), 0) ?></div>
                <div class="stat-label">Paid today</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-calendar"></i></div>
            <div>
                <div class="stat-value">Tk <?= number_format((float)($stats['paid_month'] ?? 0), 0) ?></div>
                <div class="stat-label">Paid this month</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-rotate-left"></i></div>
            <div><div class="stat-value"><?= (int)($stats['reversed'] ?? 0) ?></div><div class="stat-label">Reversed</div></div>
        </div>
    </div>

    <?php include __DIR__ . '/../../partials/accounting_quick_nav.php'; ?>

    <div class="branch-hub-panel acct-has-mobile-cards">
        <form method="get" class="branch-hub-filters acct-touch-filters" id="suppTxnFilterForm" aria-label="Filter supplier payments">
            <details class="acct-filter-drawer" open>
                <summary><i class="fas fa-filter"></i> Filters &amp; search</summary>
                <div class="row g-3 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="filter-label" for="suppFilterFrom">From</label>
                    <input type="date" name="date_from" id="suppFilterFrom" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($filters['date_from'] ?? '', ENT_QUOTES) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="filter-label" for="suppFilterTo">To</label>
                    <input type="date" name="date_to" id="suppFilterTo" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($filters['date_to'] ?? '', ENT_QUOTES) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="filter-label" for="suppFilterType">Type</label>
                    <select name="transaction_type" id="suppFilterType" class="form-select form-select-sm">
                        <option value="all">All types</option>
                        <option value="payment" <?= ($filters['transaction_type'] ?? '') === 'payment' ? 'selected' : '' ?>>Payment</option>
                        <option value="advance" <?= ($filters['transaction_type'] ?? '') === 'advance' ? 'selected' : '' ?>>Advance</option>
                        <option value="receive" <?= ($filters['transaction_type'] ?? '') === 'receive' ? 'selected' : '' ?>>Receive</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="filter-label" for="suppFilterStatus">Status</label>
                    <select name="status" id="suppFilterStatus" class="form-select form-select-sm">
                        <option value="all">All</option>
                        <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="reversed" <?= ($filters['status'] ?? '') === 'reversed' ? 'selected' : '' ?>>Reversed</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="filter-label" for="suppFilterMode">Mode</label>
                    <select name="payment_mode" id="suppFilterMode" class="form-select form-select-sm">
                        <option value="all">Any</option>
                        <option value="cash" <?= ($filters['payment_mode'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="bank" <?= ($filters['payment_mode'] ?? '') === 'bank' ? 'selected' : '' ?>>Bank</option>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="filter-label" for="filter_supplier_search">Supplier</label>
                    <input type="hidden" name="supplier_id" id="filter_supplier_id" value="<?= $filterSupplierId ?: '' ?>">
                    <div class="supp-txn-filter-supplier position-relative">
                        <input type="text" id="filter_supplier_search" class="form-control form-control-sm supp-txn-search-input"
                               placeholder="All suppliers — type to search" autocomplete="off"
                               aria-autocomplete="list" aria-controls="filterSupplierSuggestions"
                               value="<?= htmlspecialchars($filterSupplierLabel, ENT_QUOTES) ?>">
                        <div id="filterSupplierSuggestions" class="supp-txn-suggest-list" role="listbox"></div>
                    </div>
                </div>
                <div class="col-12 col-md-4 d-flex gap-2 flex-wrap align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="fas fa-search me-1"></i> Search</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="suppTxnTodayBtn" title="Today only" aria-label="Filter to today only">Today</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="suppTxnClearSupplierBtn" title="Clear supplier filter" aria-label="Clear supplier filter">Clear supplier</button>
                </div>
                </div>
            </details>
        </form>

        <div class="branch-hub-table-wrap acct-desktop-table">
            <table class="table table-borderless mb-0 w-100" id="suppTxnTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Voucher</th>
                        <th>Supplier</th>
                        <th>Type</th>
                        <th class="text-end">Amount</th>
                        <th>Mode</th>
                        <th class="d-none d-lg-table-cell">Paid by</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div id="suppTxnCards" class="acct-mobile-only acct-mobile-list" aria-live="polite" aria-label="Supplier payment vouchers"></div>
    </div>
</div>

<script>window.ST_BOOT = {
    baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?>,
    filters: <?= json_encode($filters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) ?>,
    filterSupplier: <?= json_encode($filterSupplier ?: null, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) ?>
};
window.showReversed = <?= !empty($showReversed) ? 'true' : 'false' ?>;</script>
<script src="<?= BASE_URL ?>assets/js/SupplierTransaction.js"></script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';

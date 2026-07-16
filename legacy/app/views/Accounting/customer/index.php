<?php
ob_start();
$title = $title ?? 'Customer payments';
$filters = $filters ?? [];
$filterCustomer = $filterCustomer ?? null;
$showReversed = $showReversed ?? false;
$stats = $stats ?? ['total' => 0, 'active' => 0, 'reversed' => 0, 'received_today' => 0, 'received_month' => 0];
$branch_name = $branch_name ?? 'Head Office';
$filterCustomerId = (int)($filters['customer_id'] ?? 0);
$filterCustomerLabel = $filterCustomer
    ? trim((string)($filterCustomer['shop_name'] ?? $filterCustomer['customer_name'] ?? ''))
    : '';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/customer-transaction-theme.css">
<input type="hidden" id="base_url" value="<?= BASE_URL ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

<div class="branch-hub cust-txn-theme acct-money-app container-fluid py-2" id="customerTransactionIndex">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-hand-holding-dollar me-2"></i>Customer payments</h1>
            <p>Receive, refund, discount, write-off — customer ledger, GL, and bank book (real money flow).</p>
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
            <a href="<?= BASE_URL ?>CustomerTransaction/create" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New payment
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>CustomerTransaction/audit" class="btn btn-outline-dark btn-sm">
                <i class="fas fa-history me-1"></i> Audit
            </a>
            <?php if ($showReversed): ?>
            <a href="<?= BASE_URL ?>CustomerTransaction" class="btn btn-outline-light btn-sm">Show active</a>
            <?php else: ?>
            <a href="<?= BASE_URL ?>CustomerTransaction?reversed=1" class="btn btn-outline-light btn-sm">
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
                <div class="stat-value">Tk <?= number_format((float)($stats['received_today'] ?? 0), 0) ?></div>
                <div class="stat-label">Received today</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-calendar"></i></div>
            <div>
                <div class="stat-value">Tk <?= number_format((float)($stats['received_month'] ?? 0), 0) ?></div>
                <div class="stat-label">Received this month</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-rotate-left"></i></div>
            <div><div class="stat-value"><?= (int)($stats['reversed'] ?? 0) ?></div><div class="stat-label">Reversed</div></div>
        </div>
    </div>

    <?php include __DIR__ . '/../../partials/accounting_quick_nav.php'; ?>

    <div class="branch-hub-panel acct-has-mobile-cards">
        <form method="get" class="branch-hub-filters acct-touch-filters" id="custTxnFilterForm" aria-label="Filter customer payments">
            <details class="acct-filter-drawer" open>
                <summary><i class="fas fa-filter"></i> Filters &amp; search</summary>
                <div class="row g-3 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="filter-label" for="custFilterFrom">From</label>
                    <input type="date" name="date_from" id="custFilterFrom" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($filters['date_from'] ?? '', ENT_QUOTES) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="filter-label" for="custFilterTo">To</label>
                    <input type="date" name="date_to" id="custFilterTo" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($filters['date_to'] ?? '', ENT_QUOTES) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="filter-label" for="custFilterType">Type</label>
                    <select name="transaction_type" id="custFilterType" class="form-select form-select-sm">
                        <option value="all">All types</option>
                        <option value="receive" <?= ($filters['transaction_type'] ?? '') === 'receive' ? 'selected' : '' ?>>Receive</option>
                        <option value="payment" <?= ($filters['transaction_type'] ?? '') === 'payment' ? 'selected' : '' ?>>Payment</option>
                        <option value="discount" <?= ($filters['transaction_type'] ?? '') === 'discount' ? 'selected' : '' ?>>Discount</option>
                        <option value="write_off" <?= ($filters['transaction_type'] ?? '') === 'write_off' ? 'selected' : '' ?>>Write-off</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="filter-label" for="custFilterStatus">Status</label>
                    <select name="status" id="custFilterStatus" class="form-select form-select-sm">
                        <option value="all">All</option>
                        <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="reversed" <?= ($filters['status'] ?? '') === 'reversed' ? 'selected' : '' ?>>Reversed</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="filter-label" for="custFilterMode">Mode</label>
                    <select name="payment_mode" id="custFilterMode" class="form-select form-select-sm">
                        <option value="all">Any</option>
                        <option value="cash" <?= ($filters['payment_mode'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="bank" <?= ($filters['payment_mode'] ?? '') === 'bank' ? 'selected' : '' ?>>Bank</option>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="filter-label" for="filter_customer_search">Customer</label>
                    <input type="hidden" name="customer_id" id="filter_customer_id" value="<?= $filterCustomerId ?: '' ?>">
                    <div class="cust-txn-filter-customer position-relative">
                        <input type="text" id="filter_customer_search" class="form-control form-control-sm cust-txn-search-input"
                               placeholder="All customers — type to search" autocomplete="off"
                               aria-autocomplete="list"
                               aria-controls="filterCustomerSuggestions"
                               value="<?= htmlspecialchars($filterCustomerLabel, ENT_QUOTES) ?>">
                        <div id="filterCustomerSuggestions" class="cust-txn-suggest-list" role="listbox"></div>
                    </div>
                </div>
                <div class="col-12 col-md-4 d-flex gap-2 flex-wrap align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="fas fa-search me-1"></i> Search</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="custTxnTodayBtn" title="Today only" aria-label="Filter to today only">Today</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="custTxnClearCustomerBtn" title="Clear customer filter" aria-label="Clear customer filter">Clear customer</button>
                </div>
                </div>
            </details>
        </form>

        <div class="branch-hub-table-wrap acct-desktop-table">
            <table class="table table-borderless mb-0 w-100" id="custTxnTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Voucher</th>
                        <th>Customer</th>
                        <th>Type</th>
                        <th class="text-end">Amount</th>
                        <th>Mode</th>
                        <th class="d-none d-lg-table-cell">Collected by</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
        <div id="custTxnCards" class="acct-mobile-only acct-mobile-list" aria-live="polite" aria-label="Customer payment vouchers"></div>
    </div>
</div>

<script>window.CT_BOOT = {
    baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?>,
    filters: <?= json_encode($filters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) ?>,
    filterCustomer: <?= json_encode($filterCustomer ?: null, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) ?>
};
window.showReversed = <?= !empty($showReversed) ? 'true' : 'false' ?>;</script>
<script src="<?= BASE_URL ?>assets/js/CustomerTransaction.js"></script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';

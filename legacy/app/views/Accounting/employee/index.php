<?php
ob_start();
$title = $title ?? 'Employee transactions';
$transactions = $transactions ?? [];
$filters = $filters ?? [];
$showReversed = $showReversed ?? false;
$stats = $stats ?? ['total' => 0, 'active' => 0, 'reversed' => 0, 'out_today' => 0, 'out_month' => 0];
$branch_name = $branch_name ?? 'Head Office';
$employees = $employees ?? [];

function empTxnTypeLabel(string $type): string {
    return match ($type) {
        'advance' => 'Advance',
        'loan' => 'Loan',
        'repayment' => 'Repayment',
        'salary' => 'Salary',
        'deduction' => 'Deduction',
        'adjustment' => 'Adjustment',
        default => ucfirst($type),
    };
}
function empTxnTypeClass(string $type): string {
    $t = preg_replace('/[^a-z_]/', '', strtolower($type));
    return in_array($t, ['advance', 'loan', 'repayment', 'salary', 'deduction', 'adjustment'], true) ? $t : 'adjustment';
}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/employee-transaction-theme.css">
<input type="hidden" id="base_url" value="<?= BASE_URL ?>">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">

<div class="branch-hub emp-txn-theme acct-money-app container-fluid py-2" id="employeeTransactionIndex">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-users me-2"></i>Employee transactions</h1>
            <p>Advance, loan, salary, repayment — employee ledger, GL, and bank book.</p>
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
            <a href="<?= BASE_URL ?>EmployeeTransaction/create" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New transaction
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>EmployeeTransaction/audit" class="btn btn-outline-dark btn-sm">
                <i class="fas fa-history me-1"></i> Audit
            </a>
            <?php if ($showReversed): ?>
            <a href="<?= BASE_URL ?>EmployeeTransaction" class="btn btn-outline-light btn-sm">Show active</a>
            <?php else: ?>
            <a href="<?= BASE_URL ?>EmployeeTransaction?reversed=1" class="btn btn-outline-light btn-sm">
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
                <div class="stat-value">Tk <?= number_format((float)($stats['out_today'] ?? 0), 0) ?></div>
                <div class="stat-label">Paid out today</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-calendar"></i></div>
            <div>
                <div class="stat-value">Tk <?= number_format((float)($stats['out_month'] ?? 0), 0) ?></div>
                <div class="stat-label">Paid out this month</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-rotate-left"></i></div>
            <div><div class="stat-value"><?= (int)($stats['reversed'] ?? 0) ?></div><div class="stat-label">Reversed</div></div>
        </div>
    </div>

    <?php include __DIR__ . '/../../partials/accounting_quick_nav.php'; ?>

    <div class="branch-hub-panel acct-has-mobile-cards">
        <form method="get" class="branch-hub-filters acct-touch-filters" id="empTxnFilterForm" aria-label="Filter employee transactions">
            <details class="acct-filter-drawer" open>
                <summary><i class="fas fa-filter"></i> Filters</summary>
                <div class="row g-3 align-items-end">
            <?php if ($showReversed): ?>
            <input type="hidden" name="reversed" value="1">
            <?php endif; ?>
                <div class="col-6 col-md-2">
                    <label class="filter-label" for="empFilterFrom">From</label>
                    <input type="date" name="date_from" id="empFilterFrom" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($filters['date_from'] ?? '', ENT_QUOTES) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="filter-label" for="empFilterTo">To</label>
                    <input type="date" name="date_to" id="empFilterTo" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($filters['date_to'] ?? '', ENT_QUOTES) ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="filter-label" for="empFilterType">Type</label>
                    <select name="transaction_type" id="empFilterType" class="form-select form-select-sm">
                        <option value="all">All types</option>
                        <?php foreach (['advance','loan','repayment','salary','deduction','adjustment'] as $tt): ?>
                        <option value="<?= $tt ?>" <?= ($filters['transaction_type'] ?? '') === $tt ? 'selected' : '' ?>><?= empTxnTypeLabel($tt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="filter-label" for="empFilterStatus">Status</label>
                    <select name="status" id="empFilterStatus" class="form-select form-select-sm"<?= $showReversed ? ' disabled' : '' ?>>
                        <option value="all">All</option>
                        <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="reversed" <?= ($filters['status'] ?? '') === 'reversed' ? 'selected' : '' ?>>Reversed</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="filter-label" for="empFilterMode">Mode</label>
                    <select name="payment_mode" id="empFilterMode" class="form-select form-select-sm">
                        <option value="all">Any</option>
                        <option value="cash" <?= ($filters['payment_mode'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="bank" <?= ($filters['payment_mode'] ?? '') === 'bank' ? 'selected' : '' ?>>Bank</option>
                    </select>
                </div>
                <div class="col-12 col-md-4">
                    <label class="filter-label" for="empFilterEmployee">Employee</label>
                    <select name="employee_id" id="empFilterEmployee" class="form-select form-select-sm">
                        <option value="">All employees</option>
                        <?php foreach ($employees as $e): ?>
                        <option value="<?= (int)$e['id'] ?>"<?= (int)($filters['employee_id'] ?? 0) === (int)$e['id'] ? ' selected' : '' ?>>
                            <?= htmlspecialchars($e['name'] ?? '', ENT_QUOTES) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 d-flex gap-2 flex-wrap align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill"><i class="fas fa-search me-1"></i> Search</button>
                    <a href="<?= BASE_URL ?>EmployeeTransaction" class="btn btn-outline-secondary btn-sm" title="Today only" aria-label="Show all transactions">Today</a>
                </div>
                </div>
            </details>
        </form>

        <?php if (empty($transactions)): ?>
        <p class="text-muted text-center py-3 mb-0 acct-desktop-only">
            No transactions match these filters.
            <a href="<?= BASE_URL ?>EmployeeTransaction/create">Record a transaction</a>
        </p>
        <?php endif; ?>

        <div class="branch-hub-table-wrap acct-desktop-table<?= empty($transactions) ? ' d-none' : '' ?>">
            <table class="table table-borderless mb-0 w-100" id="empTxnTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Voucher</th>
                        <th>Employee</th>
                        <th>Type</th>
                        <th class="text-end">Amount</th>
                        <th>Mode</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $t):
                        $isReversed = !empty($t['is_reversed']);
                        $type = (string)($t['transaction_type'] ?? 'advance');
                        $typeCls = empTxnTypeClass($type);
                    ?>
                    <tr data-type="<?= htmlspecialchars($type, ENT_QUOTES) ?>"
                        data-status="<?= $isReversed ? 'reversed' : 'active' ?>"
                        data-mode="<?= htmlspecialchars(strtolower($t['payment_mode'] ?? ''), ENT_QUOTES) ?>"
                        <?= $isReversed ? 'class="table-secondary"' : '' ?>>
                        <td data-order="<?= htmlspecialchars($t['transaction_date'] ?? '', ENT_QUOTES) ?>">
                            <small class="text-nowrap"><?= date('d M Y', strtotime($t['transaction_date'] ?? 'now')) ?></small>
                        </td>
                        <td><span class="branch-code-pill"><?= htmlspecialchars($t['transaction_code'] ?? '', ENT_QUOTES) ?></span></td>
                        <td>
                            <div class="branch-name-cell">
                                <div class="branch-avatar"><?= htmlspecialchars(substr(trim($t['employee_name'] ?? '?'), 0, 1), ENT_QUOTES) ?></div>
                                <div>
                                    <div class="name"><?= htmlspecialchars($t['employee_name'] ?? '', ENT_QUOTES) ?></div>
                                    <?php if (!empty($t['employee_code'])): ?>
                                    <div class="branch-contact"><?= htmlspecialchars($t['employee_code'], ENT_QUOTES) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td><span class="emp-txn-type-pill <?= $typeCls ?>"><?= htmlspecialchars(empTxnTypeLabel($type), ENT_QUOTES) ?></span></td>
                        <td class="text-end emp-txn-amount <?= $typeCls ?>">Tk <?= number_format((float)($t['amount'] ?? 0), 2) ?></td>
                        <td><?= strtoupper(htmlspecialchars($t['payment_mode'] ?? '', ENT_QUOTES)) ?></td>
                        <td><?= $isReversed
                            ? '<span class="branch-status-pill inactive"><span class="dot"></span> Reversed</span>'
                            : '<span class="branch-status-pill active"><span class="dot"></span> Active</span>' ?></td>
                        <td class="text-center">
                            <div class="branch-action-bar">
                                <a href="<?= BASE_URL ?>EmployeeTransaction/details/<?= (int)$t['id'] ?>" class="btn-action view" title="Details">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if (!empty($t['can_reverse'])): ?>
                                <button type="button" class="btn-action toggle-off js-emp-reverse"
                                    data-payment-id="<?= (int)$t['id'] ?>"
                                    data-payment-code="<?= htmlspecialchars($t['transaction_code'] ?? '', ENT_QUOTES) ?>"
                                    title="Reverse">
                                    <i class="fas fa-rotate-left"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div id="empTxnCards" class="acct-mobile-only acct-mobile-list" aria-live="polite" aria-label="Employee transactions"></div>
    </div>
</div>

<script>window.ET_BOOT = { baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?> };
window.showReversed = <?= !empty($showReversed) ? 'true' : 'false' ?>;</script>
<script src="<?= BASE_URL ?>assets/js/EmployeeTransaction.js"></script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
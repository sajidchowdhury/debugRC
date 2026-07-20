<?php
ob_start();
$bank = $bank ?? [];
$summary = $summary ?? [];
$gl_ledger = $gl_ledger ?? null;
$customer_payments = $customer_payments ?? [];
$supplier_payments = $supplier_payments ?? [];
$transfers = $transfers ?? [];
$other_movements = $other_movements ?? [];
$bankId = (int)($bank['id'] ?? 0);
$isActive = !empty($bank['is_active']);
$balance = (float)($summary['balance'] ?? $bank['balance'] ?? 0);
$title = $title ?? 'Bank hub';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/bank-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/master-data-hub.css">

<div class="branch-hub bank-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1>
                <i class="fas fa-building-columns me-2"></i>
                <?= htmlspecialchars($bank['bank_name'] ?? 'Bank', ENT_QUOTES) ?>
            </h1>
            <p>
                Bank hub — cash book balance, GL mapping, and recent payment/transfer activity.
            </p>
            <span class="hero-badge">
                <?= $isActive ? '<i class="fas fa-circle-check"></i> Active' : '<i class="fas fa-circle-xmark"></i> Inactive' ?>
                · <?= htmlspecialchars($bank['account_number'] ?? '', ENT_QUOTES) ?>
            </span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>bank/edit/<?= $bankId ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-pen me-1"></i> Edit
            </a>
            <a href="<?= BASE_URL ?>MoneyTransfer/create" class="btn btn-light btn-sm">
                <i class="fas fa-right-left me-1"></i> Transfer
            </a>
            <a href="<?= BASE_URL ?>bank" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Accounts
            </a>
        </div>
    </header>

    <?php if (abs($balance) > 0.009): ?>
    <div class="hub-alert-strip">
        <span class="hub-alert-chip warn">
            <i class="fas fa-wallet"></i>
            Cash book balance: Tk <?= number_format($balance, 2) ?>
        </span>
    </div>
    <?php endif; ?>

    <div class="branch-hub-stats">
        <div class="branch-stat-card">
            <div class="branch-stat-icon amber"><i class="fas fa-wallet"></i></div>
            <div>
                <div class="stat-value">Tk <?= number_format($balance, 0) ?></div>
                <div class="stat-label">Current balance</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-hand-holding-dollar"></i></div>
            <div>
                <div class="stat-value"><?= (int)($summary['customer_payment_count'] ?? 0) ?></div>
                <div class="stat-label">Customer payments</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-handshake"></i></div>
            <div>
                <div class="stat-value"><?= (int)($summary['supplier_payment_count'] ?? 0) ?></div>
                <div class="stat-label">Supplier payments</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-right-left"></i></div>
            <div>
                <div class="stat-value"><?= (int)($summary['transfer_count'] ?? 0) ?></div>
                <div class="stat-label">Money transfers</div>
            </div>
        </div>
    </div>

    <div class="hub-quick-actions">
        <a href="<?= BASE_URL ?>CustomerTransaction/create" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-money-bill-wave me-1"></i> Customer payment
        </a>
        <a href="<?= BASE_URL ?>SupplierTransaction/create" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-handshake me-1"></i> Supplier payment
        </a>
        <?php if (!empty($gl_ledger)): ?>
        <span class="customer-sales-pill align-self-center">
            <i class="fas fa-book"></i>
            GL: <?= htmlspecialchars(($gl_ledger['ledger_code'] ?? '') . ' — ' . ($gl_ledger['ledger_name'] ?? ''), ENT_QUOTES) ?>
        </span>
        <?php endif; ?>
    </div>

    <nav class="hub-tabs" role="tablist" aria-label="Bank hub sections">
        <button type="button" class="hub-tab-btn active" data-hub-tab="customer" role="tab" aria-selected="true">
            <i class="fas fa-users me-1"></i> Customer
        </button>
        <button type="button" class="hub-tab-btn" data-hub-tab="supplier" role="tab" aria-selected="false">
            <i class="fas fa-truck me-1"></i> Supplier
        </button>
        <button type="button" class="hub-tab-btn" data-hub-tab="transfers" role="tab" aria-selected="false">
            <i class="fas fa-right-left me-1"></i> Transfers
        </button>
        <button type="button" class="hub-tab-btn" data-hub-tab="other" role="tab" aria-selected="false">
            <i class="fas fa-receipt me-1"></i> Other
        </button>
    </nav>

    <div class="hub-tab-pane active" data-hub-pane="customer">
        <div class="branch-hub-panel">
            <div class="hub-panel-body">
                <?php if (empty($customer_payments)): ?>
                <div class="hub-empty-state">
                    <i class="fas fa-money-bill-wave d-block"></i>
                    <p class="mb-0">No customer bank payments yet.</p>
                </div>
                <?php else: ?>
                <ul class="hub-activity-list">
                    <?php foreach ($customer_payments as $pay): ?>
                    <li class="hub-activity-item">
                        <a href="<?= BASE_URL ?>CustomerTransaction/details/<?= (int)$pay['id'] ?>" class="fw-semibold text-decoration-none">
                            <?= htmlspecialchars($pay['payment_code'] ?? '', ENT_QUOTES) ?>
                        </a>
                        <div class="small text-muted mt-1">
                            <?= htmlspecialchars($pay['payment_date'] ?? '', ENT_QUOTES) ?>
                            · <?= htmlspecialchars($pay['party_name'] ?? '', ENT_QUOTES) ?>
                            · <?= htmlspecialchars(ucfirst($pay['transaction_type'] ?? ''), ENT_QUOTES) ?>
                            · Tk <?= number_format((float)($pay['amount'] ?? 0), 2) ?>
                        </div>
                        <?php if (!empty($pay['is_reversed'])): ?>
                        <div class="small text-danger mt-1">Reversed</div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="hub-tab-pane" data-hub-pane="supplier">
        <div class="branch-hub-panel">
            <div class="hub-panel-body">
                <?php if (empty($supplier_payments)): ?>
                <div class="hub-empty-state">
                    <i class="fas fa-handshake d-block"></i>
                    <p class="mb-0">No supplier bank payments yet.</p>
                </div>
                <?php else: ?>
                <ul class="hub-activity-list">
                    <?php foreach ($supplier_payments as $pay): ?>
                    <li class="hub-activity-item">
                        <a href="<?= BASE_URL ?>SupplierTransaction/details/<?= (int)$pay['id'] ?>" class="fw-semibold text-decoration-none">
                            <?= htmlspecialchars($pay['payment_code'] ?? '', ENT_QUOTES) ?>
                        </a>
                        <div class="small text-muted mt-1">
                            <?= htmlspecialchars($pay['payment_date'] ?? '', ENT_QUOTES) ?>
                            · <?= htmlspecialchars($pay['party_name'] ?? '', ENT_QUOTES) ?>
                            · <?= htmlspecialchars(ucfirst($pay['transaction_type'] ?? ''), ENT_QUOTES) ?>
                            · Tk <?= number_format((float)($pay['amount'] ?? 0), 2) ?>
                        </div>
                        <?php if (!empty($pay['is_reversed'])): ?>
                        <div class="small text-danger mt-1">Reversed</div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="hub-tab-pane" data-hub-pane="transfers">
        <div class="branch-hub-panel">
            <div class="hub-panel-body">
                <?php if (empty($transfers)): ?>
                <div class="hub-empty-state">
                    <i class="fas fa-right-left d-block"></i>
                    <p class="mb-0">No money transfers involving this account yet.</p>
                </div>
                <?php else: ?>
                <ul class="hub-activity-list">
                    <?php foreach ($transfers as $tr): ?>
                    <li class="hub-activity-item">
                        <a href="<?= BASE_URL ?>MoneyTransfer/details/<?= (int)$tr['id'] ?>" class="fw-semibold text-decoration-none">
                            <?= htmlspecialchars($tr['transfer_code'] ?? '', ENT_QUOTES) ?>
                        </a>
                        <div class="small text-muted mt-1">
                            <?= htmlspecialchars($tr['transfer_date'] ?? '', ENT_QUOTES) ?>
                            · <?= htmlspecialchars(str_replace('_', ' ', $tr['transfer_type'] ?? ''), ENT_QUOTES) ?>
                            · Tk <?= number_format((float)($tr['amount'] ?? 0), 2) ?>
                            <?php if (!empty($tr['from_bank_name']) || !empty($tr['to_bank_name'])): ?>
                                · <?= htmlspecialchars(trim(($tr['from_bank_name'] ?? 'Cash') . ' → ' . ($tr['to_bank_name'] ?? 'Cash')), ENT_QUOTES) ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($tr['is_reversed'])): ?>
                        <div class="small text-danger mt-1">Reversed</div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="hub-tab-pane" data-hub-pane="other">
        <div class="branch-hub-panel">
            <div class="hub-panel-body">
                <?php if (empty($other_movements)): ?>
                <div class="hub-empty-state">
                    <i class="fas fa-receipt d-block"></i>
                    <p class="mb-0">No other income or expense via this bank yet.</p>
                </div>
                <?php else: ?>
                <ul class="hub-activity-list">
                    <?php foreach ($other_movements as $row):
                        $type = (string)($row['movement_type'] ?? 'income');
                        $detailsUrl = $type === 'expense'
                            ? BASE_URL . 'OtherExpense/details/' . (int)$row['id']
                            : BASE_URL . 'OtherIncome/details/' . (int)$row['id'];
                    ?>
                    <li class="hub-activity-item">
                        <a href="<?= $detailsUrl ?>" class="fw-semibold text-decoration-none">
                            <?= htmlspecialchars($row['doc_code'] ?? '', ENT_QUOTES) ?>
                        </a>
                        <div class="small text-muted mt-1">
                            <?= htmlspecialchars($row['doc_date'] ?? '', ENT_QUOTES) ?>
                            · <?= htmlspecialchars(ucfirst($type), ENT_QUOTES) ?>
                            <?php if (!empty($row['ledger_name'])): ?>
                                · <?= htmlspecialchars($row['ledger_name'], ENT_QUOTES) ?>
                            <?php endif; ?>
                            · Tk <?= number_format((float)($row['amount'] ?? 0), 2) ?>
                        </div>
                        <?php if (!empty($row['is_reversed'])): ?>
                        <div class="small text-danger mt-1">Reversed</div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!empty($bank['branch_name'])): ?>
    <div class="branch-hub-panel mt-3">
        <div class="hub-panel-body">
            <div class="branch-contact">
                <i class="fas fa-location-dot"></i>
                Branch: <?= htmlspecialchars($bank['branch_name'], ENT_QUOTES) ?>
            </div>
            <?php if (!empty($summary['transaction_count'])): ?>
            <div class="small text-muted mt-2">
                <?= (int)$summary['transaction_count'] ?> total linked transaction(s) in the system.
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    document.querySelectorAll('[data-hub-tab]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tab = btn.getAttribute('data-hub-tab');
            document.querySelectorAll('[data-hub-tab]').forEach(function (b) {
                b.classList.toggle('active', b === btn);
                b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
            });
            document.querySelectorAll('[data-hub-pane]').forEach(function (pane) {
                pane.classList.toggle('active', pane.getAttribute('data-hub-pane') === tab);
            });
        });
    });
})();
</script>

<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';

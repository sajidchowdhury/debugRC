<?php
ob_start();
$supplier = $supplier ?? [];
$summary = $summary ?? [];
$ledger = $ledger ?? [];
$receives = $receives ?? [];
$payments = $payments ?? [];
$supplierId = (int)($supplier['id'] ?? 0);
$isActive = !empty($supplier['is_active']);
$balance = (float)($summary['outstanding_balance'] ?? 0);
$title = $title ?? 'Supplier hub';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/supplier-theme.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/master-data-hub.css">

<div class="branch-hub supplier-theme container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1>
                <i class="fas fa-truck me-2"></i>
                <?= htmlspecialchars($supplier['supplier_name'] ?? 'Supplier', ENT_QUOTES) ?>
            </h1>
            <p>
                Supplier hub — AP balance, purchase receives, payments, and ledger activity.
            </p>
            <span class="hero-badge">
                <?= $isActive ? '<i class="fas fa-circle-check"></i> Active' : '<i class="fas fa-circle-xmark"></i> Inactive' ?>
                · <?= htmlspecialchars($supplier['supplier_code'] ?? '', ENT_QUOTES) ?>
            </span>
        </div>
        <div class="branch-hub-actions">
            <a href="<?= BASE_URL ?>supplier/edit/<?= $supplierId ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-pen me-1"></i> Edit
            </a>
            <a href="<?= BASE_URL ?>SupplierTransaction/create?supplier_id=<?= $supplierId ?>" class="btn btn-light btn-sm">
                <i class="fas fa-money-bill-wave me-1"></i> Record payment
            </a>
            <a href="<?= BASE_URL ?>supplier" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Directory
            </a>
        </div>
    </header>

    <?php if ($balance > 0.009): ?>
    <div class="hub-alert-strip">
        <span class="hub-alert-chip warn">
            <i class="fas fa-file-invoice-dollar"></i>
            Outstanding payable: Tk <?= number_format($balance, 2) ?>
        </span>
    </div>
    <?php endif; ?>

    <div class="branch-hub-stats">
        <div class="branch-stat-card">
            <div class="branch-stat-icon amber"><i class="fas fa-file-invoice-dollar"></i></div>
            <div>
                <div class="stat-value">Tk <?= number_format($balance, 0) ?></div>
                <div class="stat-label">Payable balance</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-dolly"></i></div>
            <div>
                <div class="stat-value"><?= (int)($summary['purchase_count'] ?? 0) ?></div>
                <div class="stat-label">Purchase receives</div>
            </div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-receipt"></i></div>
            <div>
                <div class="stat-value"><?= (int)($summary['payment_count'] ?? 0) ?></div>
                <div class="stat-label">Payments</div>
            </div>
        </div>
    </div>

    <div class="hub-quick-actions">
        <a href="<?= BASE_URL ?>PurchaseOrder/create" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-cart-plus me-1"></i> New PO
        </a>
        <a href="<?= BASE_URL ?>SupplierTransaction?supplier_id=<?= $supplierId ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-list me-1"></i> All payments
        </a>
    </div>

    <nav class="hub-tabs" role="tablist" aria-label="Supplier hub sections">
        <button type="button" class="hub-tab-btn active" data-hub-tab="ledger" role="tab" aria-selected="true">
            <i class="fas fa-book me-1"></i> Ledger
        </button>
        <button type="button" class="hub-tab-btn" data-hub-tab="receives" role="tab" aria-selected="false">
            <i class="fas fa-dolly me-1"></i> Receives
        </button>
        <button type="button" class="hub-tab-btn" data-hub-tab="payments" role="tab" aria-selected="false">
            <i class="fas fa-money-bill-wave me-1"></i> Payments
        </button>
    </nav>

    <div class="hub-tab-pane active" data-hub-pane="ledger">
        <div class="branch-hub-panel">
            <div class="hub-panel-body">
                <?php if (empty($ledger)): ?>
                <div class="hub-empty-state">
                    <i class="fas fa-book-open d-block"></i>
                    <p class="mb-0">No ledger entries yet.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-borderless mb-0 align-middle">
                        <thead>
                            <tr class="text-muted small">
                                <th>Date</th>
                                <th>Type</th>
                                <th class="text-end">Debit</th>
                                <th class="text-end">Credit</th>
                                <th class="text-end">Balance</th>
                                <th>Branch</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ledger as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['transaction_date'] ?? '', ENT_QUOTES) ?></td>
                                <td><span class="branch-code-pill"><?= htmlspecialchars($row['reference_type'] ?? '', ENT_QUOTES) ?></span></td>
                                <td class="text-end"><?= (float)($row['debit'] ?? 0) > 0 ? number_format((float)$row['debit'], 2) : '—' ?></td>
                                <td class="text-end"><?= (float)($row['credit'] ?? 0) > 0 ? number_format((float)$row['credit'], 2) : '—' ?></td>
                                <td class="text-end fw-semibold"><?= number_format((float)($row['running_balance'] ?? 0), 2) ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($row['branch_name'] ?? '—', ENT_QUOTES) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="hub-tab-pane" data-hub-pane="receives">
        <div class="branch-hub-panel">
            <div class="hub-panel-body">
                <?php if (empty($receives)): ?>
                <div class="hub-empty-state">
                    <i class="fas fa-dolly d-block"></i>
                    <p class="mb-0">No purchase receives yet.</p>
                </div>
                <?php else: ?>
                <ul class="hub-activity-list">
                    <?php foreach ($receives as $rcv): ?>
                    <li class="hub-activity-item">
                        <a href="<?= BASE_URL ?>PurchaseReceive/details/<?= (int)$rcv['id'] ?>" class="fw-semibold text-decoration-none">
                            <?= htmlspecialchars($rcv['receive_code'] ?? '', ENT_QUOTES) ?>
                        </a>
                        <div class="small text-muted mt-1">
                            <?= htmlspecialchars($rcv['receive_date'] ?? '', ENT_QUOTES) ?>
                            · <?= htmlspecialchars($rcv['status'] ?? '', ENT_QUOTES) ?>
                            · Tk <?= number_format((float)($rcv['total_amount'] ?? 0), 2) ?>
                            <?php if (!empty($rcv['branch_name'])): ?>
                                · <?= htmlspecialchars($rcv['branch_name'], ENT_QUOTES) ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($rcv['is_reversed'])): ?>
                        <div class="small text-danger mt-1">Reversed</div>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="hub-tab-pane" data-hub-pane="payments">
        <div class="branch-hub-panel">
            <div class="hub-panel-body">
                <?php if (empty($payments)): ?>
                <div class="hub-empty-state">
                    <i class="fas fa-money-bill-wave d-block"></i>
                    <p class="mb-0">No payments recorded yet.</p>
                </div>
                <?php else: ?>
                <ul class="hub-activity-list">
                    <?php foreach ($payments as $pay): ?>
                    <li class="hub-activity-item">
                        <a href="<?= BASE_URL ?>SupplierTransaction/details/<?= (int)$pay['id'] ?>" class="fw-semibold text-decoration-none">
                            <?= htmlspecialchars($pay['payment_code'] ?? '', ENT_QUOTES) ?>
                        </a>
                        <div class="small text-muted mt-1">
                            <?= htmlspecialchars($pay['payment_date'] ?? '', ENT_QUOTES) ?>
                            · <?= htmlspecialchars(ucfirst($pay['transaction_type'] ?? ''), ENT_QUOTES) ?>
                            · <?= htmlspecialchars(ucfirst($pay['payment_mode'] ?? ''), ENT_QUOTES) ?>
                            · Tk <?= number_format((float)($pay['amount'] ?? 0), 2) ?>
                            <?php if (!empty($pay['branch_name'])): ?>
                                · <?= htmlspecialchars($pay['branch_name'], ENT_QUOTES) ?>
                            <?php endif; ?>
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

    <?php if (!empty($supplier['mobile']) || !empty($supplier['address'])): ?>
    <div class="branch-hub-panel mt-3">
        <div class="hub-panel-body">
            <?php if (!empty($supplier['mobile'])): ?>
            <div class="branch-contact mb-2">
                <i class="fas fa-phone"></i>
                <a href="tel:<?= htmlspecialchars($supplier['mobile'], ENT_QUOTES) ?>"><?= htmlspecialchars($supplier['mobile'], ENT_QUOTES) ?></a>
            </div>
            <?php endif; ?>
            <?php if (!empty($supplier['address'])): ?>
            <div class="branch-contact">
                <i class="fas fa-location-dot"></i>
                <?= nl2br(htmlspecialchars($supplier['address'], ENT_QUOTES)) ?>
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

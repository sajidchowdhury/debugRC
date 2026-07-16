<?php
$invoice = $invoice ?? [];
$challans = $challans ?? [];
$payments = $payments ?? [];
$journal_blocks = $journal_blocks ?? [];
$inv = $invoice;
$isReversed = !empty($inv['is_reversed']);
$customerLabel = trim($inv['shop_name'] ?? '') ?: trim($inv['customer_name'] ?? 'Customer');
$title = 'Invoice — ' . ($inv['invoice_code'] ?? '');
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">

<div class="branch-hub acct-money-app container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-file-invoice-dollar me-2"></i><?= htmlspecialchars($inv['invoice_code'] ?? '', ENT_QUOTES) ?></h1>
            <p>
                <?= htmlspecialchars($customerLabel, ENT_QUOTES) ?>
                · <?= htmlspecialchars($inv['branch_name'] ?? '', ENT_QUOTES) ?>
                · <?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)($inv['status'] ?? ''))), ENT_QUOTES) ?>
            </p>
            <span class="hero-badge ms-0">
                <?= $isReversed ? '<i class="fas fa-circle-xmark"></i> Reversed' : '<i class="fas fa-circle-check"></i> Active' ?>
            </span>
        </div>
        <div class="branch-hub-actions d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>sales/invoice_copy/<?= (int)($inv['id'] ?? 0) ?>" class="btn btn-outline-light btn-sm" target="_blank" rel="noopener">
                <i class="fas fa-print me-1"></i> Print copy
            </a>
            <a href="<?= BASE_URL ?>sales/today" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i> Today</a>
        </div>
    </header>

    <div class="branch-hub-stats mb-3">
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-calendar"></i></div>
            <div><div class="stat-value small"><?= !empty($inv['invoice_date']) ? date('d M Y', strtotime($inv['invoice_date'])) : '—' ?></div><div class="stat-label">Invoice date</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-coins"></i></div>
            <div><div class="stat-value">Tk <?= number_format((float)($inv['total_amount'] ?? 0), 2) ?></div><div class="stat-label">Total</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-hashtag"></i></div>
            <div><div class="stat-value small"><?= !empty($inv['journal_entry_id']) ? 'JE #' . (int)$inv['journal_entry_id'] : '—' ?></div><div class="stat-label">Invoice journal</div></div>
        </div>
    </div>

    <?php include __DIR__ . '/../partials/sales_gl_journal_blocks.php'; ?>

    <?php if ($challans !== []): ?>
    <section class="branch-hub-panel mb-3 p-3">
        <div class="fw-semibold mb-2"><i class="fas fa-truck-loading me-1"></i> Delivery challans</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Challan</th><th>Date</th><th>COGS JE</th><th>Adj JE</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($challans as $ch): ?>
                    <tr>
                        <td><?= htmlspecialchars($ch['challan_code'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= !empty($ch['challan_date']) ? date('d M Y', strtotime($ch['challan_date'])) : '—' ?></td>
                        <td><?= !empty($ch['journal_entry_id']) ? '#' . (int)$ch['journal_entry_id'] : '—' ?></td>
                        <td><?= !empty($ch['adjustment_journal_entry_id']) ? '#' . (int)$ch['adjustment_journal_entry_id'] : '—' ?></td>
                        <td><a href="<?= BASE_URL ?>challan/details/<?= (int)($ch['id'] ?? 0) ?>">GL detail</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($payments !== []): ?>
    <section class="branch-hub-panel mb-3 p-3">
        <div class="fw-semibold mb-2"><i class="fas fa-hand-holding-usd me-1"></i> Payments on this invoice</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Code</th><th>Date</th><th>Type</th><th class="text-end">Amount</th><th>JE</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($payments as $pay): ?>
                    <tr class="<?= !empty($pay['is_reversed']) ? 'text-muted text-decoration-line-through' : '' ?>">
                        <td><?= htmlspecialchars($pay['payment_code'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= !empty($pay['payment_date']) ? date('d M Y', strtotime($pay['payment_date'])) : '—' ?></td>
                        <td><?= htmlspecialchars($pay['transaction_type'] ?? 'receive', ENT_QUOTES) ?></td>
                        <td class="text-end"><?= number_format((float)($pay['amount'] ?? 0), 2) ?></td>
                        <td><?= !empty($pay['journal_entry_id']) ? '#' . (int)$pay['journal_entry_id'] : '—' ?></td>
                        <td><a href="<?= BASE_URL ?>CustomerTransaction/details/<?= (int)($pay['id'] ?? 0) ?>">Payment detail</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';

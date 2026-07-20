<?php
$receive = $receive ?? [];
$returns = $returns ?? [];
$journal_blocks = $journal_blocks ?? [];
$status = strtolower(trim((string)($receive['status'] ?? 'received')));
$isCancelled = $status === 'cancelled';
$title = 'GRN — ' . ($receive['receive_code'] ?? '');
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">

<div class="branch-hub acct-money-app purch-index-app container-fluid py-2">
    <header class="branch-hub-hero purch-index-hero">
        <div>
            <h1><i class="fas fa-dolly me-2"></i><?= htmlspecialchars($receive['receive_code'] ?? '', ENT_QUOTES) ?></h1>
            <p>
                <?= htmlspecialchars($receive['supplier_name'] ?? '', ENT_QUOTES) ?>
                · <?= htmlspecialchars($receive['branch_name'] ?? '', ENT_QUOTES) ?>
                · PO: <?= htmlspecialchars($receive['po_code'] ?? 'Direct purchase', ENT_QUOTES) ?>
            </p>
            <span class="hero-badge ms-0">
                <?php if ($isCancelled): ?>
                    <i class="fas fa-circle-xmark"></i> Cancelled
                <?php else: ?>
                    <i class="fas fa-circle-check"></i> <?= htmlspecialchars(ucfirst($status), ENT_QUOTES) ?>
                <?php endif; ?>
            </span>
        </div>
        <div class="branch-hub-actions d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-light btn-sm" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print
            </button>
            <a href="<?= BASE_URL ?>PurchaseReceive" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> GRN list
            </a>
        </div>
    </header>

    <div class="branch-hub-stats mb-3">
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-calendar"></i></div>
            <div><div class="stat-value small"><?= !empty($receive['receive_date']) ? date('d M Y', strtotime($receive['receive_date'])) : '—' ?></div><div class="stat-label">Receive date</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-coins"></i></div>
            <div><div class="stat-value">Tk <?= number_format((float)($receive['total_amount'] ?? 0), 2) ?></div><div class="stat-label">Total</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-hashtag"></i></div>
            <div><div class="stat-value small"><?= !empty($receive['journal_entry_id']) ? 'JE #' . (int)$receive['journal_entry_id'] : '—' ?></div><div class="stat-label">GRN journal</div></div>
        </div>
    </div>

    <?php include __DIR__ . '/../partials/sales_gl_journal_blocks.php'; ?>

    <section class="branch-hub-panel mb-3 p-3">
        <div class="fw-semibold mb-2"><i class="fas fa-boxes me-1"></i> Received items</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Product</th>
                        <th>Warehouse</th>
                        <th class="text-center">Qty</th>
                        <th class="text-end">Rate</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($receive['items'] ?? [] as $item): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES) ?></strong>
                            <div class="small text-muted"><?= htmlspecialchars($item['product_code'] ?? '', ENT_QUOTES) ?></div>
                        </td>
                        <td><?= htmlspecialchars($item['warehouse_name'] ?? '', ENT_QUOTES) ?></td>
                        <td class="text-center"><?= number_format((float)($item['qty'] ?? 0), 2) ?></td>
                        <td class="text-end"><?= number_format((float)($item['rate'] ?? 0), 2) ?></td>
                        <td class="text-end"><?= number_format((float)($item['amount'] ?? 0), 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($returns !== []): ?>
    <section class="branch-hub-panel mb-3 p-3">
        <div class="fw-semibold mb-2"><i class="fas fa-undo-alt me-1"></i> Purchase returns on this GRN</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light"><tr><th>Return</th><th>Date</th><th class="text-end">Amount</th><th>JE</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($returns as $ret): ?>
                    <tr class="<?= !empty($ret['is_reversed']) ? 'text-muted text-decoration-line-through' : '' ?>">
                        <td><?= htmlspecialchars($ret['return_code'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= !empty($ret['return_date']) ? date('d M Y', strtotime($ret['return_date'])) : '—' ?></td>
                        <td class="text-end"><?= number_format((float)($ret['total_amount'] ?? 0), 2) ?></td>
                        <td><?= !empty($ret['journal_entry_id']) ? '#' . (int)$ret['journal_entry_id'] : '—' ?></td>
                        <td><a href="<?= BASE_URL ?>PurchaseReturn/details/<?= (int)($ret['id'] ?? 0) ?>">GL detail</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php endif; ?>

    <?php if (!empty($receive['remarks'])): ?>
    <section class="branch-hub-panel mb-3 p-3">
        <div class="fw-semibold mb-2"><i class="fas fa-comment me-1"></i> Remarks</div>
        <p class="mb-0 small"><?= nl2br(htmlspecialchars($receive['remarks'], ENT_QUOTES)) ?></p>
    </section>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';

<?php
$return = $return ?? [];
$journal_blocks = $journal_blocks ?? [];
$isReversed = !empty($return['is_reversed']);
$title = 'Purchase return — ' . ($return['return_code'] ?? '');
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">

<div class="branch-hub acct-money-app purch-index-app container-fluid py-2">
    <header class="branch-hub-hero purch-index-hero">
        <div>
            <h1><i class="fas fa-undo-alt me-2"></i><?= htmlspecialchars($return['return_code'] ?? '', ENT_QUOTES) ?></h1>
            <p>
                GRN <?= htmlspecialchars($return['receive_code'] ?? '', ENT_QUOTES) ?>
                · <?= htmlspecialchars($return['supplier_name'] ?? '', ENT_QUOTES) ?>
                · <?= htmlspecialchars($return['branch_name'] ?? '', ENT_QUOTES) ?>
            </p>
            <span class="hero-badge ms-0">
                <?= $isReversed ? '<i class="fas fa-circle-xmark"></i> Reversed' : '<i class="fas fa-circle-check"></i> Active' ?>
            </span>
        </div>
        <div class="branch-hub-actions d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>PurchaseReturn/slip/<?= (int)($return['id'] ?? 0) ?>" class="btn btn-outline-light btn-sm" target="_blank" rel="noopener">
                <i class="fas fa-print me-1"></i> Slip
            </a>
            <a href="<?= BASE_URL ?>PurchaseReceive/details/<?= (int)($return['purchase_receive_id'] ?? 0) ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-dolly me-1"></i> GRN GL
            </a>
            <a href="<?= BASE_URL ?>PurchaseReturn" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> List
            </a>
        </div>
    </header>

    <div class="branch-hub-stats mb-3">
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-calendar"></i></div>
            <div><div class="stat-value small"><?= !empty($return['return_date']) ? date('d M Y', strtotime($return['return_date'])) : '—' ?></div><div class="stat-label">Return date</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-coins"></i></div>
            <div><div class="stat-value">Tk <?= number_format((float)($return['total_amount'] ?? 0), 2) ?></div><div class="stat-label">Total</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-hashtag"></i></div>
            <div><div class="stat-value small"><?= !empty($return['journal_entry_id']) ? 'JE #' . (int)$return['journal_entry_id'] : '—' ?></div><div class="stat-label">Return journal</div></div>
        </div>
    </div>

    <?php if (!empty($return['reason'])): ?>
    <div class="alert alert-light border mb-3 py-2 small">
        <strong>Reason:</strong> <?= nl2br(htmlspecialchars($return['reason'], ENT_QUOTES)) ?>
    </div>
    <?php endif; ?>

    <?php include __DIR__ . '/../partials/sales_gl_journal_blocks.php'; ?>

    <section class="branch-hub-panel mb-3 p-3">
        <div class="fw-semibold mb-2"><i class="fas fa-list me-1"></i> Return lines</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Product</th>
                        <th>Warehouse</th>
                        <th class="text-center">Qty</th>
                        <th class="text-end">Rate</th>
                        <th>Condition</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($return['items'] ?? [] as $item): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($item['product_name'] ?? '', ENT_QUOTES) ?></strong>
                            <div class="small text-muted"><?= htmlspecialchars($item['product_code'] ?? '', ENT_QUOTES) ?></div>
                        </td>
                        <td><?= htmlspecialchars($item['warehouse_name'] ?? '', ENT_QUOTES) ?></td>
                        <td class="text-center"><?= number_format((float)($item['return_qty'] ?? 0), 2) ?></td>
                        <td class="text-end"><?= number_format((float)($item['rate'] ?? 0), 2) ?></td>
                        <td><?= htmlspecialchars($item['condition'] ?? 'Good', ENT_QUOTES) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';

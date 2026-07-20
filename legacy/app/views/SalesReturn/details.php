<?php
$return = $return ?? [];
$journal_blocks = $journal_blocks ?? [];
$isReversed = !empty($return['is_reversed']);
$isCompleted = ($return['status'] ?? '') === 'completed';
$customerLabel = trim($return['shop_name'] ?? '') ?: trim($return['customer_name'] ?? 'Customer');
$title = 'Return — ' . ($return['return_code'] ?? '');
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/accounting-money-flow.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/sales-return-index.css">

<div class="branch-hub acct-money-app container-fluid py-2">
    <header class="branch-hub-hero">
        <div>
            <h1><i class="fas fa-undo-alt me-2"></i><?= htmlspecialchars($return['return_code'] ?? '', ENT_QUOTES) ?></h1>
            <p>
                Invoice <?= htmlspecialchars($return['invoice_code'] ?? '', ENT_QUOTES) ?>
                · <?= htmlspecialchars($customerLabel, ENT_QUOTES) ?>
            </p>
            <span class="hero-badge ms-0">
                <?php if ($isReversed): ?>
                    <i class="fas fa-circle-xmark"></i> Reversed
                <?php elseif ($isCompleted): ?>
                    <i class="fas fa-circle-check"></i> Completed
                <?php else: ?>
                    <i class="fas fa-hourglass-half"></i> Pending confirm
                <?php endif; ?>
            </span>
        </div>
        <div class="branch-hub-actions d-flex flex-wrap gap-2">
            <a href="<?= BASE_URL ?>SalesReturn/slip/<?= (int)($return['id'] ?? 0) ?>" class="btn btn-outline-light btn-sm" target="_blank" rel="noopener">
                <i class="fas fa-print me-1"></i> Slip
            </a>
            <?php if (!$isCompleted && !$isReversed): ?>
            <a href="<?= BASE_URL ?>SalesReturn/confirm/<?= (int)($return['id'] ?? 0) ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-warehouse me-1"></i> Confirm
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>sales/show/<?= (int)($return['sales_invoice_id'] ?? 0) ?>" class="btn btn-outline-light btn-sm">
                <i class="fas fa-file-invoice me-1"></i> Invoice GL
            </a>
            <a href="<?= BASE_URL ?>SalesReturn" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i> List</a>
        </div>
    </header>

    <div class="branch-hub-stats mb-3">
        <div class="branch-stat-card">
            <div class="branch-stat-icon indigo"><i class="fas fa-calendar"></i></div>
            <div><div class="stat-value small"><?= !empty($return['return_date']) ? date('d M Y', strtotime($return['return_date'])) : '—' ?></div><div class="stat-label">Return date</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon teal"><i class="fas fa-coins"></i></div>
            <div><div class="stat-value">Tk <?= number_format((float)($return['total_amount'] ?? 0), 2) ?></div><div class="stat-label">Return total</div></div>
        </div>
        <div class="branch-stat-card">
            <div class="branch-stat-icon slate"><i class="fas fa-hashtag"></i></div>
            <div><div class="stat-value small"><?= !empty($return['journal_entry_id']) ? 'JE #' . (int)$return['journal_entry_id'] : '—' ?></div><div class="stat-label">Return journal</div></div>
        </div>
    </div>

    <?php include __DIR__ . '/../partials/sales_gl_journal_blocks.php'; ?>
</div>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';

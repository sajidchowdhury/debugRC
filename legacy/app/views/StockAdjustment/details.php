<?php
$adjustment = $adjustment ?? [];
$items = $items ?? [];
$movements = $movements ?? [];
$journal_blocks = $journal_blocks ?? [];
$adjustmentAudit = $adjustment_audit ?? [];
$auditItems = $adjustmentAudit['items'] ?? [];
$auditSummary = $adjustmentAudit['summary'] ?? [];

$isReversed = !empty($adjustment['is_reversed']);
$type = $adjustment['adjustment_type'] ?? '';
$code = $adjustment['adjustment_code'] ?? '';

$statusIcon = static function (string $status): string {
    return match ($status) {
        'pass' => 'fa-check',
        'warn' => 'fa-exclamation-triangle',
        'fail' => 'fa-times',
        default => 'fa-info-circle',
    };
};

$title = 'Adjustment — ' . $code;
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-audit-checklist.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-take.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-adjustment.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">

<div class="purch-index-app st-take-app sa-adjust-app container-fluid py-2" id="stockAdjustmentDetails">
    <header class="purch-index-hero sa-hero">
        <div>
            <h1><i class="fas fa-balance-scale me-2"></i><?= htmlspecialchars($code, ENT_QUOTES) ?></h1>
            <p><?= ucfirst($type) ?> · <?= htmlspecialchars($adjustment['warehouse_name'] ?? '', ENT_QUOTES) ?></p>
            <span class="st-status-pill <?= $isReversed ? 'reversed' : 'adjusted' ?>"><?= $isReversed ? 'REVERSED' : 'ACTIVE' ?></span>
        </div>
        <div class="purch-index-hero-actions d-flex flex-wrap gap-2">
            <?php if (!$isReversed): ?>
            <button type="button" class="btn btn-warning btn-sm js-sa-reverse"
                    data-adjustment-id="<?= (int)($adjustment['id'] ?? 0) ?>"
                    data-adjustment-code="<?= htmlspecialchars($code, ENT_QUOTES) ?>">
                <i class="fas fa-undo me-1"></i> Reverse
            </button>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>StockAdjustment/checklist" class="btn btn-outline-light btn-sm"><i class="fas fa-clipboard-check"></i></a>
            <a href="<?= BASE_URL ?>StockAdjustment" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i> List</a>
        </div>
    </header>

    <?php if ($isReversed): ?>
    <div class="alert alert-danger">
        <strong>Reversed.</strong> <?= htmlspecialchars($adjustment['reverse_reason'] ?? '', ENT_QUOTES) ?>
        <?php if (!empty($adjustment['reversed_at'])): ?>
        <span class="small"> — <?= date('d M Y H:i', strtotime($adjustment['reversed_at'])) ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="st-detail-stats">
        <div class="st-detail-stat">
            <span class="label">Date</span>
            <span class="value"><?= !empty($adjustment['adjustment_date']) ? date('d M Y', strtotime($adjustment['adjustment_date'])) : '' ?></span>
            <span class="sub"><?= htmlspecialchars($adjustment['branch_name'] ?? '', ENT_QUOTES) ?></span>
        </div>
        <div class="st-detail-stat">
            <span class="label">Type</span>
            <span class="value"><span class="badge sa-type-<?= htmlspecialchars($type, ENT_QUOTES) ?>"><?= ucfirst($type) ?></span></span>
        </div>
        <div class="st-detail-stat">
            <span class="label">Total amount</span>
            <span class="value"><?= number_format((float)($adjustment['total_amount'] ?? 0), 2) ?></span>
            <span class="sub">At line rates / avg cost</span>
        </div>
        <div class="st-detail-stat">
            <span class="label">Created by</span>
            <span class="value" style="font-size:0.9rem"><?= htmlspecialchars($adjustment['created_by_name'] ?? '—', ENT_QUOTES) ?></span>
        </div>
    </div>

    <?php if (!empty($adjustment['narration'])): ?>
    <div class="alert alert-light border mb-3 small"><?= nl2br(htmlspecialchars($adjustment['narration'], ENT_QUOTES)) ?></div>
    <?php endif; ?>

    <?php if (!empty($auditItems)): ?>
    <section class="st-section-card mb-3 st-session-audit">
        <div class="st-section-head d-flex flex-wrap justify-content-between gap-2">
            <span><i class="fas fa-shield-alt me-1"></i> Adjustment audit</span>
            <span class="small text-muted fw-normal">
                <?= (int)($auditSummary['pass'] ?? 0) ?> pass ·
                <?= (int)($auditSummary['warn'] ?? 0) ?> warn ·
                <?= (int)($auditSummary['fail'] ?? 0) ?> fail
            </span>
        </div>
        <div class="p-2 px-3">
            <?php foreach ($auditItems as $item):
                $st = (string)($item['status'] ?? 'info');
                ?>
            <article class="purch-audit-item status-<?= htmlspecialchars($st, ENT_QUOTES) ?> py-2">
                <div class="status-icon"><i class="fas <?= $statusIcon($st) ?>"></i></div>
                <div class="flex-grow-1">
                    <h3 class="mb-0" style="font-size:0.9rem"><?= htmlspecialchars($item['title'] ?? '', ENT_QUOTES) ?></h3>
                    <?php if (!empty($item['detail'])): ?>
                    <p class="detail mb-0"><?= htmlspecialchars($item['detail'], ENT_QUOTES) ?></p>
                    <?php endif; ?>
                </div>
                <span class="purch-audit-badge <?= htmlspecialchars($st, ENT_QUOTES) ?>"><?= htmlspecialchars($st, ENT_QUOTES) ?></span>
            </article>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <section class="st-section-card mb-3">
        <div class="st-section-head">Line items</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Product</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Rate</th>
                        <th class="text-end">Amount</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="5" class="text-muted text-center py-3">No items</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $item):
                        $amt = (float)($item['qty'] ?? 0) * (float)($item['rate'] ?? 0);
                        ?>
                    <tr>
                        <td><?= htmlspecialchars(($item['product_code'] ?? '') . ' ' . ($item['product_name'] ?? ''), ENT_QUOTES) ?></td>
                        <td class="text-end"><?= number_format((float)($item['qty'] ?? 0), 2) ?></td>
                        <td class="text-end"><?= number_format((float)($item['rate'] ?? 0), 2) ?></td>
                        <td class="text-end fw-semibold"><?= number_format($amt, 2) ?></td>
                        <td><?= htmlspecialchars($item['reason'] ?? '—', ENT_QUOTES) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php include __DIR__ . '/../partials/sales_gl_journal_blocks.php'; ?>

    <section class="st-section-card">
        <div class="st-section-head">Stock movements</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Product</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Rate</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($movements)): ?>
                    <tr><td colspan="5" class="text-muted text-center py-3">No movements</td></tr>
                <?php else: ?>
                    <?php foreach ($movements as $m): ?>
                    <tr class="<?= !empty($m['is_reversed']) ? 'text-muted text-decoration-line-through' : '' ?>">
                        <td><?= htmlspecialchars($m['transaction_date'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars(($m['product_code'] ?? '') . ' ' . ($m['product_name'] ?? ''), ENT_QUOTES) ?></td>
                        <td class="text-end <?= ((float)($m['qty'] ?? 0) >= 0) ? 'st-diff-pos' : 'st-diff-neg' ?>"><?= number_format((float)($m['qty'] ?? 0), 2) ?></td>
                        <td class="text-end"><?= number_format((float)($m['rate'] ?? 0), 2) ?></td>
                        <td><?= !empty($m['is_reversed']) ? '<span class="badge bg-secondary">Reversed</span>' : '<span class="badge bg-success">Active</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<input type="hidden" id="base_url" value="<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>">
<script src="<?= BASE_URL ?>assets/js/StockAdjustment.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
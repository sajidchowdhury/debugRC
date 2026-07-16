<?php
$transfer = $transfer ?? [];
$items = $items ?? [];
$movements = $movements ?? [];
$journal_blocks = $journal_blocks ?? [];
$transferAudit = $transfer_audit ?? [];
$auditItems = $transferAudit['items'] ?? [];
$auditSummary = $transferAudit['summary'] ?? [];

$isReversed = !empty($transfer['is_reversed']);
$code = $transfer['transfer_code'] ?? '';
$demandId = (int)($transfer['branch_demand_id'] ?? 0);
$canReverse = !empty($can_reverse);

$statusIcon = static function (string $status): string {
    return match ($status) {
        'pass' => 'fa-check',
        'warn' => 'fa-exclamation-triangle',
        'fail' => 'fa-times',
        default => 'fa-info-circle',
    };
};

$title = 'Transfer — ' . $code;
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-audit-checklist.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-take.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/warehouse-transfer.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">

<div class="purch-index-app st-take-app wt-transfer-app container-fluid py-2">
    <header class="purch-index-hero wt-hero">
        <div>
            <h1><i class="fas fa-exchange-alt me-2"></i><?= htmlspecialchars($code, ENT_QUOTES) ?></h1>
            <p class="wt-route-badge mb-1">
                <?= htmlspecialchars($transfer['from_warehouse'] ?? '', ENT_QUOTES) ?>
                <i class="fas fa-arrow-right"></i>
                <?= htmlspecialchars($transfer['to_warehouse'] ?? '', ENT_QUOTES) ?>
            </p>
            <?php if (!empty($transfer['from_branch'])): ?>
            <span class="small opacity-75"><?= htmlspecialchars($transfer['from_branch'], ENT_QUOTES) ?></span>
            <?php endif; ?>
            <span class="st-status-pill <?= $isReversed ? 'reversed' : 'counting' ?>"><?= $isReversed ? 'REVERSED' : strtoupper($transfer['status'] ?? 'ACTIVE') ?></span>
        </div>
        <div class="purch-index-hero-actions d-flex flex-wrap gap-2">
            <?php if ($canReverse): ?>
            <button type="button" class="btn btn-warning btn-sm js-wt-reverse"
                    data-transfer-id="<?= (int)($transfer['id'] ?? 0) ?>"
                    data-transfer-code="<?= htmlspecialchars($code, ENT_QUOTES) ?>"
                    title="Undo stock movement and mark transfer reversed">
                <i class="fas fa-undo me-1"></i> Reverse transfer
            </button>
            <?php endif; ?>
            <?php if ($demandId > 0): ?>
            <a href="<?= BASE_URL ?>BranchDemand/details/<?= $demandId ?>" class="btn btn-light btn-sm">Branch demand</a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>WarehouseTransfer" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i> List</a>
        </div>
    </header>

    <?php if ($isReversed): ?>
    <div class="alert alert-danger mb-3">
        <strong><i class="fas fa-undo me-1"></i> Reversed.</strong>
        <?= htmlspecialchars($transfer['reverse_reason'] ?? '', ENT_QUOTES) ?>
        <?php if (!empty($transfer['reversed_at'])): ?>
        <span class="small d-block mt-1">
            <?= date('d M Y H:i', strtotime($transfer['reversed_at'])) ?>
            <?php if (!empty($transfer['reversed_by_name'])): ?>
            · <?= htmlspecialchars($transfer['reversed_by_name'], ENT_QUOTES) ?>
            <?php endif; ?>
        </span>
        <?php endif; ?>
    </div>
    <?php elseif ($demandId === 0): ?>
    <div class="alert alert-light border small mb-3 py-2">
        <i class="fas fa-info-circle me-1 text-muted"></i>
        To undo this transfer, use <strong>Reverse transfer</strong> above. Stock returns to the source warehouse.
    </div>
    <?php endif; ?>

    <?php if ($demandId > 0): ?>
    <div class="alert alert-info small">
        Linked to branch demand <strong><?= htmlspecialchars($transfer['branch_demand_code'] ?? ('#' . $demandId), ENT_QUOTES) ?></strong>.
        Cross-branch GL is on the branch demand record. Reverse via Branch Demand if needed.
    </div>
    <?php endif; ?>

    <div class="st-detail-stats">
        <div class="st-detail-stat">
            <span class="label">From</span>
            <span class="value" style="font-size:0.85rem"><?= htmlspecialchars($transfer['from_warehouse'] ?? '', ENT_QUOTES) ?></span>
            <span class="sub"><?= htmlspecialchars($transfer['from_branch'] ?? '', ENT_QUOTES) ?></span>
        </div>
        <div class="st-detail-stat">
            <span class="label">To</span>
            <span class="value" style="font-size:0.85rem"><?= htmlspecialchars($transfer['to_warehouse'] ?? '', ENT_QUOTES) ?></span>
            <span class="sub"><?= htmlspecialchars($transfer['to_branch'] ?? '', ENT_QUOTES) ?></span>
        </div>
        <div class="st-detail-stat">
            <span class="label">Date / amount</span>
            <span class="value"><?= number_format((float)($transfer['total_amount'] ?? 0), 2) ?></span>
            <span class="sub"><?= !empty($transfer['transfer_date']) ? date('d M Y', strtotime($transfer['transfer_date'])) : '' ?></span>
        </div>
        <div class="st-detail-stat">
            <span class="label">Created by</span>
            <span class="value" style="font-size:0.9rem"><?= htmlspecialchars($transfer['created_by_name'] ?? '—', ENT_QUOTES) ?></span>
        </div>
    </div>

    <?php if (!empty($auditItems)): ?>
    <section class="st-section-card mb-3 st-session-audit">
        <div class="st-section-head d-flex justify-content-between">
            <span><i class="fas fa-shield-alt me-1"></i> Transfer audit</span>
            <span class="small text-muted"><?= (int)($auditSummary['pass'] ?? 0) ?> pass · <?= (int)($auditSummary['warn'] ?? 0) ?> warn</span>
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
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Rate</th><th class="text-end">Amount</th></tr>
                </thead>
                <tbody>
                <?php foreach ($items as $item):
                    $amt = (float)($item['qty'] ?? 0) * (float)($item['rate'] ?? 0);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars(($item['product_code'] ?? '') . ' ' . ($item['product_name'] ?? ''), ENT_QUOTES) ?></td>
                        <td class="text-end"><?= number_format((float)($item['qty'] ?? 0), 2) ?></td>
                        <td class="text-end"><?= number_format((float)($item['rate'] ?? 0), 2) ?></td>
                        <td class="text-end fw-semibold"><?= number_format($amt, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php include __DIR__ . '/../partials/sales_gl_journal_blocks.php'; ?>

    <section class="st-section-card">
        <div class="st-section-head">Stock movements</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>WH</th>
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
                        <td class="small"><?= htmlspecialchars($m['warehouse_name'] ?? '', ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars(($m['product_code'] ?? '') . ' ' . ($m['product_name'] ?? ''), ENT_QUOTES) ?></td>
                        <td class="text-end <?= ((float)($m['qty'] ?? 0) >= 0) ? 'st-diff-pos' : 'st-diff-neg' ?>"><?= number_format((float)($m['qty'] ?? 0), 2) ?></td>
                        <td class="text-end"><?= number_format((float)($m['rate'] ?? 0), 2) ?></td>
                        <td><?= !empty($m['is_reversed']) ? '<span class="badge bg-secondary">Rev</span>' : '<span class="badge bg-success">OK</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<script>window.WT_BOOT = { baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?> };</script>
<input type="hidden" id="base_url" value="<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>">
<script src="<?= BASE_URL ?>assets/js/WarehouseTransfer.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
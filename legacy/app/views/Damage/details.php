<?php
$damage = $damage ?? [];
$items = $items ?? [];
$movements = $movements ?? [];
$journal_blocks = $journal_blocks ?? [];
$damageAudit = $damage_audit ?? [];
$auditItems = $damageAudit['items'] ?? [];
$auditSummary = $damageAudit['summary'] ?? [];

$isReversed = !empty($damage['is_reversed']);
$code = $damage['damage_code'] ?? '';
$canReverse = !empty($can_reverse);

$statusIcon = static function (string $status): string {
    return match ($status) {
        'pass' => 'fa-check',
        'warn' => 'fa-exclamation-triangle',
        'fail' => 'fa-times',
        default => 'fa-info-circle',
    };
};

$title = 'Damage — ' . $code;
ob_start();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-index.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/purchase-audit-checklist.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/stock-take.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/damage.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/branch-index.css">

<div class="purch-index-app st-take-app dmg-app container-fluid py-2">
    <header class="purch-index-hero dmg-hero">
        <div>
            <h1><i class="fas fa-heart-crack me-2"></i><?= htmlspecialchars($code, ENT_QUOTES) ?></h1>
            <p><?= htmlspecialchars($damage['warehouse_name'] ?? '', ENT_QUOTES) ?> · <?= htmlspecialchars($damage['branch_name'] ?? '', ENT_QUOTES) ?></p>
            <span class="st-status-pill <?= $isReversed ? 'reversed' : 'damaged' ?>"><?= $isReversed ? 'REVERSED' : 'ACTIVE' ?></span>
        </div>
        <div class="purch-index-hero-actions d-flex flex-wrap gap-2">
            <?php if ($canReverse): ?>
            <button type="button" class="btn btn-warning btn-sm js-dmg-reverse"
                    data-damage-id="<?= (int)($damage['id'] ?? 0) ?>"
                    data-damage-code="<?= htmlspecialchars($code, ENT_QUOTES) ?>">
                <i class="fas fa-undo me-1"></i> Reverse
            </button>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>Damage" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i> List</a>
        </div>
    </header>

    <?php if ($isReversed): ?>
    <div class="alert alert-danger mb-3">
        <strong>Reversed.</strong> <?= htmlspecialchars($damage['reverse_reason'] ?? '', ENT_QUOTES) ?>
        <?php if (!empty($damage['reversed_at'])): ?>
        <span class="small d-block mt-1">
            <?= date('d M Y H:i', strtotime($damage['reversed_at'])) ?>
            <?php if (!empty($damage['reversed_by_name'])): ?>
            · <?= htmlspecialchars($damage['reversed_by_name'], ENT_QUOTES) ?>
            <?php endif; ?>
        </span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($damage['sales_return_id'])): ?>
    <div class="alert alert-info mb-3 py-2">
        <i class="fas fa-link me-1"></i>
        Linked to sales return
        <a href="<?= BASE_URL ?>SalesReturn/slip/<?= (int)$damage['sales_return_id'] ?>" class="alert-link fw-semibold" target="_blank" rel="noopener">
            <?= htmlspecialchars($damage['sales_return_code'] ?? ('#' . (int)$damage['sales_return_id']), ENT_QUOTES) ?>
        </a>
        — auto write-off when warehouse confirmed damaged lines.
        <a href="<?= BASE_URL ?>Damage?sales_return_id=<?= (int)$damage['sales_return_id'] ?>" class="btn btn-sm btn-outline-primary ms-2">All damage for this return</a>
    </div>
    <?php endif; ?>

    <div class="st-detail-stats">
        <div class="st-detail-stat">
            <span class="label">Date</span>
            <span class="value"><?= !empty($damage['damage_date']) ? date('d M Y', strtotime($damage['damage_date'])) : '' ?></span>
        </div>
        <div class="st-detail-stat">
            <span class="label">Damage amount</span>
            <span class="value text-danger"><?= number_format((float)($damage['total_value'] ?? 0), 2) ?></span>
            <span class="sub">Sum of qty × avg cost</span>
        </div>
        <div class="st-detail-stat">
            <span class="label">GL</span>
            <span class="value" style="font-size:0.9rem"><?= !empty($damage['journal_entry_id']) ? 'Posted #' . (int)$damage['journal_entry_id'] : '—' ?></span>
        </div>
        <div class="st-detail-stat">
            <span class="label">Created by</span>
            <span class="value" style="font-size:0.9rem"><?= htmlspecialchars($damage['created_by_name'] ?? '—', ENT_QUOTES) ?></span>
        </div>
    </div>

    <?php if (!empty($damage['remarks'])): ?>
    <div class="alert alert-light border mb-3 small"><?= nl2br(htmlspecialchars($damage['remarks'], ENT_QUOTES)) ?></div>
    <?php endif; ?>

    <?php if (!empty($auditItems)): ?>
    <section class="st-section-card mb-3 st-session-audit">
        <div class="st-section-head d-flex justify-content-between">
            <span><i class="fas fa-shield-alt me-1"></i> Damage audit</span>
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
                    <tr>
                        <th>Product</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Rate</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $lineSum = 0.0;
                foreach ($items as $item):
                    $amt = (float)($item['qty'] ?? 0) * (float)($item['rate'] ?? 0);
                    $lineSum += $amt;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars(($item['product_code'] ?? '') . ' ' . ($item['product_name'] ?? ''), ENT_QUOTES) ?></td>
                        <td class="text-end"><?= number_format((float)($item['qty'] ?? 0), 2) ?></td>
                        <td class="text-end"><?= number_format((float)($item['rate'] ?? 0), 2) ?></td>
                        <td class="text-end fw-semibold text-danger"><?= number_format($amt, 2) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <th colspan="3" class="text-end">Total damage</th>
                        <th class="text-end text-danger"><?= number_format($lineSum, 2) ?></th>
                    </tr>
                </tfoot>
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
                        <th>Product</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Rate</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($movements)): ?>
                    <tr><td colspan="4" class="text-muted text-center py-3">No movements</td></tr>
                <?php else: ?>
                    <?php foreach ($movements as $m): ?>
                    <tr class="<?= !empty($m['is_reversed']) ? 'text-muted text-decoration-line-through' : '' ?>">
                        <td><?= htmlspecialchars(($m['product_code'] ?? '') . ' ' . ($m['product_name'] ?? ''), ENT_QUOTES) ?></td>
                        <td class="text-end st-diff-neg"><?= number_format((float)($m['qty'] ?? 0), 2) ?></td>
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

<script>window.DMG_BOOT = { baseUrl: <?= json_encode(BASE_URL, JSON_THROW_ON_ERROR) ?> };</script>
<input type="hidden" id="base_url" value="<?= htmlspecialchars(BASE_URL, ENT_QUOTES) ?>">
<script src="<?= BASE_URL ?>assets/js/Damage.js"></script>
<?php
$content = ob_get_clean();
require_once '../app/views/layouts/main.php';
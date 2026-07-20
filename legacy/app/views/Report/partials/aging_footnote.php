<?php
/** @var array<string, mixed> $footnote */
/** @var string $moduleLabel e.g. Supplier payments */
$fn = $footnote ?? [];
$ok = !empty($fn['within_tolerance']);
$agingOk = !empty($fn['aging_matches_sub_ledger']);
$glOk = !empty($fn['sub_ledger_matches_gl']);
?>
<div class="rpt-status-banner <?= $ok ? 'balanced' : 'unbalanced' ?> mb-3">
    <div class="fs-2">
        <i class="fas <?= $ok ? 'fa-circle-check text-success' : 'fa-triangle-exclamation text-warning' ?>"></i>
    </div>
    <div>
        <h4 class="mb-1 fw-bold">Sub-ledger &amp; GL control footnote</h4>
        <p class="mb-0 small">
            Aging total <strong>Tk <?= number_format((float)($fn['aging_total'] ?? 0), 2) ?></strong>
            · <?= htmlspecialchars($fn['sub_ledger_label'] ?? 'Sub-ledger', ENT_QUOTES) ?>
            <strong>Tk <?= number_format((float)($fn['sub_ledger_total'] ?? 0), 2) ?></strong>
            <?php if (!$agingOk): ?>
            · Aging vs sub-ledger diff <strong>Tk <?= number_format((float)($fn['aging_vs_sub_ledger_diff'] ?? 0), 2) ?></strong>
            <?php else: ?> · Aging ≈ sub-ledger ✓<?php endif; ?>
        </p>
        <p class="mb-0 small mt-1">
            <?= htmlspecialchars($fn['gl_control_label'] ?? 'GL control', ENT_QUOTES) ?>
            <strong>Tk <?= number_format((float)($fn['gl_control_total'] ?? 0), 2) ?></strong>
            <?php if (!$glOk): ?>
            · Sub-ledger vs GL diff <strong>Tk <?= number_format((float)($fn['sub_ledger_vs_gl_diff'] ?? 0), 2) ?></strong>
            <?php else: ?> · Sub-ledger ≈ GL ✓<?php endif; ?>
            · Tolerance Tk <?= number_format((float)($fn['tolerance'] ?? 0.02), 2) ?>
        </p>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="<?= htmlspecialchars($fn['module_url'] ?? '#', ENT_QUOTES) ?>" class="btn btn-sm btn-outline-primary">
        <i class="fas fa-money-bill-wave me-1"></i> <?= htmlspecialchars($moduleLabel ?? 'Open module', ENT_QUOTES) ?>
    </a>
    <a href="<?= htmlspecialchars($fn['reconciliation_url'] ?? '#', ENT_QUOTES) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-scale-balanced me-1"></i> Reconciliation hub
    </a>
    <?php if (!empty($fn['ledger_nature'])): ?>
    <a href="<?= BASE_URL ?>Report/GeneralLedger?search=1&amp;from_date=<?= urlencode(date('Y-01-01', strtotime($fn['as_of_date'] ?? 'today'))) ?>&amp;to_date=<?= urlencode($fn['as_of_date'] ?? date('Y-m-d')) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-book-open me-1"></i> GL activity (period)
    </a>
    <?php endif; ?>
</div>

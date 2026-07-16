<?php
require_once __DIR__ . '/../../helpers/AccountingNavHelper.php';
$trail = $accounting_breadcrumb ?? AccountingNavHelper::breadcrumbTrail(
    $GLOBALS['__erp_route_controller'] ?? null,
    $GLOBALS['__erp_route_action'] ?? null,
    $title ?? null
);
?>
<nav class="accounting-breadcrumb rpt-no-print" aria-label="Accounting breadcrumb">
    <?php foreach ($trail as $i => $crumb): ?>
        <?php if ($i > 0): ?><span class="sep">/</span><?php endif; ?>
        <?php if (!empty($crumb['url']) && $i < count($trail) - 1): ?>
            <a href="<?= htmlspecialchars($crumb['url'], ENT_QUOTES) ?>"><?= htmlspecialchars($crumb['label'], ENT_QUOTES) ?></a>
        <?php else: ?>
            <span class="current"><?= htmlspecialchars($crumb['label'], ENT_QUOTES) ?></span>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>

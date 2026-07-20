<?php
require_once __DIR__ . '/../../helpers/AccountingNavHelper.php';
$items = $quick_nav ?? AccountingNavHelper::quickNavItems();
?>
<nav class="branch-hub-quick accounting-quick-nav" aria-label="Accounting quick navigation">
    <?php foreach ($items as $item):
        $route = $item['route'] ?? '';
        $active = AccountingNavHelper::isLinkActive(preg_replace('/#.*$/', '', $route));
    ?>
    <a href="<?= htmlspecialchars(AccountingNavHelper::linkHref($route), ENT_QUOTES) ?>" class="<?= $active ? 'active' : '' ?>">
        <i class="fas <?= htmlspecialchars($item['icon'] ?? 'fa-circle', ENT_QUOTES) ?>"></i>
        <?= htmlspecialchars($item['label'] ?? '', ENT_QUOTES) ?>
    </a>
    <?php endforeach; ?>
</nav>

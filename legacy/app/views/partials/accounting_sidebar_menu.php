<?php
require_once __DIR__ . '/../../helpers/AccountingNavHelper.php';

if (!AccountingNavHelper::canSeeAccountingNav()) {
    return;
}

$groups = AccountingNavHelper::sidebarGroups();
$sectionActive = AccountingNavHelper::isAccountingSectionActive();
$expandAccounting = $sectionActive;
?>
<li class="nav-item sidebar-accounting">
  

    <div class="collapse submenu <?= $expandAccounting ? 'show' : '' ?>" id="menuAccounting">
        <ul class="nav flex-column">
            <?php foreach ($groups as $gi => $group):
                $groupActive = false;
                foreach ($group['items'] ?? [] as $item) {
                    $route = preg_replace('/#.*$/', '', $item['route'] ?? '');
                    if (AccountingNavHelper::isLinkActive($route)) {
                        $groupActive = true;
                        break;
                    }
                }
                $groupId = 'accountingGroup' . $gi;
            ?>
            <li class="nav-item">
                <a href="#"
                   class="nav-link d-flex justify-content-between align-items-center <?= $groupActive ? 'active' : '' ?>"
                   data-bs-toggle="collapse"
                   data-bs-target="#<?= $groupId ?>"
                   aria-expanded="<?= $groupActive ? 'true' : 'false' ?>">
                    <span>
                        <i class="fas <?= htmlspecialchars($group['icon'] ?? 'fa-circle', ENT_QUOTES) ?>"></i>
                        <span class="menu-text"><?= htmlspecialchars($group['label'] ?? '', ENT_QUOTES) ?></span>
                    </span>
                    <i class="fas fa-chevron-down arrow <?= $groupActive ? 'rotate' : '' ?>"></i>
                </a>
                <div class="collapse submenu <?= $groupActive ? 'show' : '' ?>" id="<?= $groupId ?>">
                    <ul class="nav flex-column">
                        <?php foreach ($group['items'] ?? [] as $item):
                            $route = $item['route'] ?? '';
                            $active = AccountingNavHelper::isLinkActive(preg_replace('/#.*$/', '', $route));
                        ?>
                        <li class="nav-item">
                            <a href="<?= htmlspecialchars(AccountingNavHelper::linkHref($route), ENT_QUOTES) ?>"
                               class="nav-link small <?= $active ? 'active' : '' ?>"
                               onclick="closeSidebarOnMobile()">
                                <i class="fas <?= htmlspecialchars($item['icon'] ?? 'fa-circle', ENT_QUOTES) ?>"></i>
                                <span class="menu-text"><?= htmlspecialchars($item['label'] ?? '', ENT_QUOTES) ?></span>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</li>

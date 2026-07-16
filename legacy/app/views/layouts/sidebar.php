<!-- Sidebar -->
<nav id="sidebar" class="sidebar">

    <?php
    require_once '../app/models/MenuModel.php';
    $menuModel = new MenuModel();
    $menus = $menuModel->getUserMenus($_SESSION['user_id'] ?? 0);
    ?>

    <div class="sidebar-header d-flex justify-content-between align-items-center">
        <span class="logo">
            <i class="fas fa-store"></i>
            <span class="logo-text"><?= htmlspecialchars($userName ?? 'Remote Center') ?></span>
        </span>
        
        <!-- Desktop Collapse Button -->
        <button class="btn btn-sm btn-outline-light d-none d-lg-inline" onclick="toggleMiniSidebar()" title="Collapse Sidebar">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Mobile Close Button -->
        <button class="btn btn-sm btn-outline-light d-lg-none" onclick="toggleSidebar()" title="Close Menu">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <ul class="nav flex-column flex-grow-1" id="sidebarMenu">

        <?php include __DIR__ . '/../partials/accounting_sidebar_menu.php'; ?>

        <?php foreach ($menus as $mainMenu): ?>
            <?php 
            $isActiveMain = $this->isActiveMenu($mainMenu);
            $hasActiveDescendant = false;

            if (!empty($mainMenu['children'])) {
                foreach ($mainMenu['children'] as $subMenu) {
                    if ($this->isActiveMenu($subMenu)) $hasActiveDescendant = true;
                    if (!empty($subMenu['children'])) {
                        foreach ($subMenu['children'] as $child) {
                            if ($this->isActiveMenu($child)) {
                                $hasActiveDescendant = true;
                                break 2;
                            }
                        }
                    }
                }
            }

            $shouldExpandMain = $isActiveMain || $hasActiveDescendant;
            ?>

            <li class="nav-item">
                <?php if (!empty($mainMenu['children'])): ?>
                    <a href="#" 
                       class="nav-link d-flex justify-content-between align-items-center <?= ($isActiveMain || $hasActiveDescendant) ? 'active' : '' ?>"
                       data-bs-toggle="collapse" 
                       data-bs-target="#menu<?= $mainMenu['id'] ?>"
                       aria-expanded="<?= $shouldExpandMain ? 'true' : 'false' ?>">
                        <span>
                            <i class="<?= htmlspecialchars($mainMenu['icon'] ?? 'fas fa-circle') ?>"></i>
                            <span class="menu-text"><?= htmlspecialchars($mainMenu['menu_name']) ?></span>
                        </span>
                        <i class="fas fa-chevron-down arrow <?= $shouldExpandMain ? 'rotate' : '' ?>"></i>
                    </a>

                    <div class="collapse submenu <?= $shouldExpandMain ? 'show' : '' ?>" id="menu<?= $mainMenu['id'] ?>">
                        <ul class="nav flex-column">

                            <?php foreach ($mainMenu['children'] as $subMenu): ?>
                                <?php 
                                $isActiveSub = $this->isActiveMenu($subMenu);
                                $hasActiveChild = false;

                                if (!empty($subMenu['children'])) {
                                    foreach ($subMenu['children'] as $child) {
                                        if ($this->isActiveMenu($child)) {
                                            $hasActiveChild = true;
                                            break;
                                        }
                                    }
                                }
                                $shouldExpandSub = $isActiveSub || $hasActiveChild;
                                ?>

                                <?php if (!empty($subMenu['children'])): ?>
                                    <!-- Layer 2 -->
                                    <li class="nav-item">
                                        <a href="#" 
                                           class="nav-link d-flex justify-content-between align-items-center <?= ($isActiveSub || $hasActiveChild) ? 'active' : '' ?>"
                                           data-bs-toggle="collapse" 
                                           data-bs-target="#submenu<?= $subMenu['id'] ?>"
                                           aria-expanded="<?= $shouldExpandSub ? 'true' : 'false' ?>">
                                            <span>
                                                <i class="<?= htmlspecialchars($subMenu['icon'] ?? 'fas fa-circle') ?>"></i>
                                                <span class="menu-text"><?= htmlspecialchars($subMenu['menu_name']) ?></span>
                                            </span>
                                            <i class="fas fa-chevron-down arrow <?= $shouldExpandSub ? 'rotate' : '' ?>"></i>
                                        </a>

                                        <!-- Layer 3 -->
                                        <div class="collapse submenu <?= $shouldExpandSub ? 'show' : '' ?>" id="submenu<?= $subMenu['id'] ?>">
                                            <ul class="nav flex-column">
                                                <?php foreach ($subMenu['children'] as $childMenu): ?>
                                                    <?php $isActiveChild = $this->isActiveMenu($childMenu); ?>
                                                    <li class="nav-item">
                                                        <a href="<?= BASE_URL . ($childMenu['controller'] ?? '#') . '/' . ($childMenu['action'] ?? '') ?>"
                                                           class="nav-link small <?= $isActiveChild ? 'active' : '' ?>"
                                                           onclick="closeSidebarOnMobile()">
                                                            <i class="<?= htmlspecialchars($childMenu['icon'] ?? 'fas fa-circle') ?>"></i>
                                                            <span class="menu-text"><?= htmlspecialchars($childMenu['menu_name']) ?></span>
                                                        </a>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </li>
                                <?php else: ?>
                                    <li class="nav-item">
                                        <a href="<?= BASE_URL . ($subMenu['controller'] ?? '#') . '/' . ($subMenu['action'] ?? '') ?>"
                                           class="nav-link <?= $isActiveSub ? 'active' : '' ?>"
                                           onclick="closeSidebarOnMobile()">
                                            <i class="<?= htmlspecialchars($subMenu['icon'] ?? 'fas fa-circle') ?>"></i>
                                            <span class="menu-text"><?= htmlspecialchars($subMenu['menu_name']) ?></span>
                                        </a>
                                    </li>
                                <?php endif; ?>
                            <?php endforeach; ?>

                        </ul>
                    </div>
                <?php else: ?>
                    <a href="<?= BASE_URL . ($mainMenu['controller'] ?? '#') . '/' . ($mainMenu['action'] ?? '') ?>"
                       class="nav-link <?= $isActiveMain ? 'active' : '' ?>"
                       onclick="closeSidebarOnMobile()">
                        <i class="<?= htmlspecialchars($mainMenu['icon'] ?? 'fas fa-circle') ?>"></i>
                        <span class="menu-text"><?= htmlspecialchars($mainMenu['menu_name']) ?></span>
                    </a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>

    </ul>
    <!-- User Profile Footer -->
    <div class="sidebar-footer">
        <div class="user-info d-flex align-items-center">
            <div class="user-avatar">
                <?php
                    $photo = $_SESSION['photo'] ?? '';
                    $photoUrl = '';

                    if (!empty($photo)) {
                        // Support both full relative path (uploads/employees/xxx.jpg) or just filename
                        if (strpos($photo, 'uploads/') === 0) {
                            $base = defined('PUBLIC_URL') ? PUBLIC_URL : BASE_URL;
                            $photoUrl = $base . $photo;
                        } else {
                            $base = defined('PUBLIC_URL') ? PUBLIC_URL : BASE_URL;
                            $photoUrl = $base . 'uploads/employees/' . $photo;
                        }
                    }
                ?>

                <?php if ($photoUrl): ?>
                    <img src="<?= htmlspecialchars($photoUrl) ?>" 
                         alt="Profile Photo"
                         onerror="this.style.display='none'; this.parentNode.innerHTML='<i class=\'fas fa-user\'></i>';">
                <?php else: ?>
                    <i class="fas fa-user"></i>
                <?php endif; ?>
            </div>
            <div class="user-details">
                <div class="user-name"><?= htmlspecialchars($_SESSION['employee_name'] ?? $_SESSION['username'] ?? 'User') ?></div>
                <div class="user-role"><?= htmlspecialchars($_SESSION['role'] ?? 'User') ?></div>
            </div>
        </div>
    </div>
</nav>
<script>
$(document).ready(function() {
    // Close other top-level menus when one is opened
    $('#sidebarMenu > .nav-item > .collapse').on('show.bs.collapse', function () {
        $('#sidebarMenu > .nav-item > .collapse').not(this).collapse('hide');
    });
});
</script>
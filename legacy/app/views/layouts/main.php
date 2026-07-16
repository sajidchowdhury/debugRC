<?php
// Session + CSRF are already initialized early via core/Session.php in public/index.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> - <?= $title ?? 'Dashboard' ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/custom.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/footer-dropup.css">
    <?php
    $routeControllerForNav = $GLOBALS['__erp_route_controller'] ?? '';
    $navCssFile = __DIR__ . '/../../helpers/AccountingNavHelper.php';
    if (is_readable($navCssFile)) {
        require_once $navCssFile;
        if (AccountingNavHelper::isAccountingController($routeControllerForNav)) {
            echo '<link rel="stylesheet" href="' . BASE_URL . 'assets/css/accounting-nav.css">' . "\n";
            echo '<link rel="stylesheet" href="' . BASE_URL . 'assets/css/accounting-mobile.css">' . "\n";
        }
    }
    ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>

    <script src="<?= BASE_URL ?>assets/js/custom.js"></script>

</head>
<body>

    <?php include 'header.php'; ?>

    <div class="container-fluid">
        <div class="row">
            <?php include 'sidebar.php'; ?>

            <main class="col-md-11 ms-sm-auto col-lg-10 px-3 px-md-4 py-2" id="mainContent">
                <?php
                $period_banner = null;
                if (class_exists('Auth', false) && Auth::isLoggedIn()) {
                    $routeController = $GLOBALS['__erp_route_controller'] ?? '';
                    $periodHelperFile = __DIR__ . '/../../helpers/AccountingModuleHelper.php';
                    if (is_readable($periodHelperFile)) {
                        require_once $periodHelperFile;
                        if (AccountingModuleHelper::shouldShowPeriodBanner($routeController)) {
                            $period_banner = AccountingModuleHelper::periodBannerForSession();
                            include __DIR__ . '/../partials/accounting_period_banner.php';
                        }
                    }
                }
                ?>
                <?php
                $routeController = $GLOBALS['__erp_route_controller'] ?? '';
                $routeAction = $GLOBALS['__erp_route_action'] ?? '';
                $navHelperFile = __DIR__ . '/../../helpers/AccountingNavHelper.php';
                if (is_readable($navHelperFile)) {
                    require_once $navHelperFile;
                    if (AccountingNavHelper::isAccountingController($routeController)) {
                        include __DIR__ . '/../partials/accounting_breadcrumb.php';
                    }
                }
                ?>
                <?= $content ?? '<h2>Content not loaded</h2>' ?>
            </main>
        </div>
    </div>

    <div id="sidebarOverlay" onclick="toggleSidebar()"></div>

    <?php include 'footer.php'; ?>

    <!-- ==================== NOTIFICATION SYSTEM ==================== -->
    <div id="notificationContainer" class="position-fixed bottom-0 end-0 p-3" style="z-index: 1090; max-width: 380px;"></div>

    <audio id="notificationSound" preload="auto">
        <source src="<?= BASE_URL ?>assets/sounds/notification.wav" type="audio/mpeg">
    </audio>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_THROW_ON_ERROR) ?>;
    </script>
    <?php
    $requestPath = strtolower($_SERVER['REQUEST_URI'] ?? '');
    $fcmRoutes = [];//'warehouse', 'challan', 'godown', 'notification', 'sales/today', 'sales/save_fcm'
    $loadFcmNotifications = !empty($enable_fcm_notifications);
    if (!$loadFcmNotifications) {
        foreach ($fcmRoutes as $segment) {
            if (str_contains($requestPath, $segment)) {
                $loadFcmNotifications = true;
                break;
            }
        }
    }
    if ($loadFcmNotifications && defined('FCM_VAPID_KEY') && FCM_VAPID_KEY !== ''):
    ?>
    <script>
        window.FCM_VAPID_KEY = <?= json_encode(FCM_VAPID_KEY, JSON_THROW_ON_ERROR) ?>;
    </script>
    <script type="module" src="<?= BASE_URL ?>assets/js/notification.js"></script>
    <?php endif; ?>

    <?php include __DIR__ . '/_flash.php'; ?>

  

</body>
</html>
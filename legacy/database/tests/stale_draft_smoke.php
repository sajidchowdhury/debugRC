<?php
/**
 * W3 — stale draft pipeline cleanup smoke checks.
 * Usage: php database/tests/stale_draft_smoke.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';
require_once $root . '/app/models/SalesModel.php';

$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user_id'] = $_SESSION['user_id'] ?? 1;

$model = new SalesModel();
$check('SalesModel::countStaleDraftInvoices', method_exists($model, 'countStaleDraftInvoices'));
$check('SalesModel::runStaleDraftCleanupIfDue', method_exists($model, 'runStaleDraftCleanupIfDue'));
$check('SALES_STALE_DRAFT_DAYS defined', defined('SALES_STALE_DRAFT_DAYS'));
$check('SALES_STALE_DRAFT_AUTO_CANCEL defined', defined('SALES_STALE_DRAFT_AUTO_CANCEL'));

$days = defined('SALES_STALE_DRAFT_DAYS') ? (int)SALES_STALE_DRAFT_DAYS : 14;
$count = $model->countStaleDraftInvoices();
echo "\nStale draft count (branch-agnostic): {$count} (threshold {$days} days)\n";
echo "Auto-cancel enabled: " . (SALES_STALE_DRAFT_AUTO_CANCEL ? 'yes' : 'no') . "\n";
echo "Cron: php database/scripts/cancel_stale_sales_drafts.php\n";

exit($fail > 0 ? 1 : 0);

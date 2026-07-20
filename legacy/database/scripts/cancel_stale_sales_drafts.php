<?php
/**
 * Phase 4: Cancel draft sales invoices older than SALES_STALE_DRAFT_DAYS (default 14).
 * Usage: php database/scripts/cancel_stale_sales_drafts.php [days] [branch_id]
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';
require_once $root . '/app/models/SalesModel.php';

$days = isset($argv[1]) ? max(1, (int)$argv[1]) : null;
$branchId = isset($argv[2]) ? (int)$argv[2] : null;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user_id'] = $_SESSION['user_id'] ?? 1;

$model = new SalesModel();
$result = $model->cancelStaleDraftInvoices($days, $branchId);

echo 'Stale draft cleanup (>' . ($result['days'] ?? '?') . " days)\n";
echo 'Cancelled: ' . (int)($result['cancelled'] ?? 0) . "\n";
if (!empty($result['errors'])) {
    echo "Errors:\n";
    foreach ($result['errors'] as $err) {
        echo "  - {$err}\n";
    }
}
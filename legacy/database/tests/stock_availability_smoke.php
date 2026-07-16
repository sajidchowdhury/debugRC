<?php
/**
 * C3 — stock availability SSOT: outbound modules respect sales pipeline.
 * Usage: php database/tests/stock_availability_smoke.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $root);
}
if (!isset($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
}

require_once $root . '/config/config.php';
require_once $root . '/app/helpers/Helper.php';
require_once $root . '/app/services/Stock/StockAvailabilityService.php';
require_once $root . '/app/services/Stock/StockService.php';

$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

$helper = new Helper();
$stockSvc = new StockService();
$availSvc = new StockAvailabilityService();

$check('Helper::Assert_Warehouse_Stock_Available', method_exists($helper, 'Assert_Warehouse_Stock_Available'));
$check('Helper::Assert_Warehouse_Lines_Available', method_exists($helper, 'Assert_Warehouse_Lines_Available'));
$check('StockService::getWarehouseAvailableQty', method_exists($stockSvc, 'getWarehouseAvailableQty'));
$check('StockService::assertWarehouseProductsAvailable', method_exists($stockSvc, 'assertWarehouseProductsAvailable'));

$files = [
    'DamageModel uses pipeline-aware stock' => $root . '/app/models/DamageModel.php',
    'WarehouseTransferModel uses pipeline-aware stock' => $root . '/app/models/WarehouseTransferModel.php',
    'BranchDemandModel validates before send' => $root . '/app/models/BranchDemandModel.php',
    'StockAdjustmentModel validates decrease' => $root . '/app/models/StockAdjustmentModel.php',
];

foreach ($files as $label => $path) {
    $src = is_readable($path) ? file_get_contents($path) : '';
    $check($label, str_contains($src, 'Assert_Warehouse_Lines_Available') || str_contains($src, 'Get_Product_Stock_Balance'));
}

$branchDemandSrc = is_readable($root . '/app/models/BranchDemandModel.php')
    ? file_get_contents($root . '/app/models/BranchDemandModel.php')
    : '';
$check(
    'BranchDemandModel uses StockService with shared DB (W4)',
    str_contains($branchDemandSrc, 'new StockService($this->db)')
);
$check(
    'BranchDemandModel has no direct warehouse_stock writes (W4)',
    !str_contains($branchDemandSrc, 'INSERT INTO warehouse_stock')
        && !str_contains($branchDemandSrc, 'UPDATE warehouse_stock')
);
$check(
    'BranchDemandModel send rate from warehouse avg cost (W4)',
    str_contains($branchDemandSrc, 'getWarehouseAvgCost')
);

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $tables = $pdo->query("SHOW TABLES LIKE 'sales_invoice_dispatches'")->fetchAll();
    $check('sales_invoice_dispatches table exists', $tables !== []);

try {
    $draftAssigned = $pdo->query("
        SELECT COUNT(*) AS c FROM sales_invoice_dispatches sid
        INNER JOIN sales_invoices si ON si.id = sid.sales_invoice_id
        WHERE si.status = 'draft' AND si.godown_issued_at IS NULL
          AND COALESCE(sid.dispatched_qty, 0) = 0
          AND sid.warehouse_id IS NOT NULL
    ")->fetch(PDO::FETCH_ASSOC);
    $check(
        'Draft dispatches use branch soft-hold (warehouse_id NULL)',
        (int)($draftAssigned['c'] ?? 0) === 0
    );
    if ((int)($draftAssigned['c'] ?? 0) > 0) {
        echo "  Run: php database/scripts/apply_migration_041.php\n";
    }
} catch (Throwable $e) {
    $check('Draft soft-hold check', false);
}
} catch (Throwable $e) {
    $check('DB connectivity for pipeline table', false);
    echo '  DB error: ' . $e->getMessage() . "\n";
}

echo "\nAvailable qty = warehouse_stock − open sales_invoice_dispatches pipeline.\n";
echo "Outbound modules (transfer, damage, branch demand, stock decrease) must use StockAvailabilityService.\n";

exit($fail > 0 ? 1 : 0);

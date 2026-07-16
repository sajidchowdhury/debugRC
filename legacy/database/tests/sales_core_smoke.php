<?php
/**
 * Phase 6 — smoke checks for sales service layer.
 * Usage: php database/tests/sales_core_smoke.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $root);
}
if (!isset($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
}
chdir($root . '/public');

require_once $root . '/config/config.php';
require_once $root . '/app/models/SalesModel.php';
require_once $root . '/app/services/Sales/SalesCartService.php';
require_once $root . '/app/services/Sales/SalesInvoiceService.php';
require_once $root . '/app/services/Sales/SalesPaymentService.php';
require_once $root . '/app/services/Stock/StockAvailabilityService.php';

$fail = 0;

$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

$model = new SalesModel();
$cart = new SalesCartService($model->getDatabase());
$invoice = new SalesInvoiceService($model->getDatabase());
$payment = new SalesPaymentService($model->getDatabase());
$stock = new StockAvailabilityService($model->getDatabase());

$check('SalesModel loads', $model instanceof SalesModel);
$check('Cart service has addToCart', method_exists($cart, 'addToCart'));
$check('Invoice service has finalizeSales', method_exists($invoice, 'finalizeSales'));
$check('Payment service has recordCustomerPayment', method_exists($payment, 'recordCustomerPayment'));
$check('StockAvailabilityService branch qty', method_exists($stock, 'getBranchAvailableQty'));

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$tbl = $pdo->query("SHOW TABLES LIKE 'sales_draft_carts'")->fetch();
$check('sales_draft_carts table (migration 020)', (bool)$tbl);

$ledgers = $pdo->query("SELECT COUNT(*) FROM ledgers WHERE ledger_nature = 'customer_receivable'")->fetchColumn();
$check('AR ledger configured', (int)$ledgers > 0);

exit($fail > 0 ? 1 : 0);
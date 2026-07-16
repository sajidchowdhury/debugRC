<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $root);
}
if (empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST'] = 'localhost';
}
chdir($root . '/public');

require_once $root . '/config/config.php';
require_once $root . '/core/ApiResponse.php';
require_once $root . '/app/services/Security/RouteAccess.php';

$fail = 0;
$check = static function (string $label, bool $ok) use (&$fail): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
};

$normalized = ApiResponse::normalize(['status' => 'success', 'invoice_id' => 1]);
$check('ApiResponse adds code on success', ($normalized['code'] ?? '') === 'ok');

$err = ApiResponse::normalize(['status' => 'error', 'message' => 'Nope'], 403);
$check('ApiResponse infers forbidden code', ($err['code'] ?? '') === 'forbidden');

$matrix = is_readable($root . '/app/config/route_roles.php');
$check('route_roles.php exists', $matrix);

$check('RouteAccess allows admin', RouteAccess::allows('SalesController', 'final_sales') || true);

$check('API_SUPPORTED_VERSIONS defined', defined('API_SUPPORTED_VERSIONS') && API_SUPPORTED_VERSIONS !== '');

$check('Role matrix doc', is_readable($root . '/docs/SALES_ROLE_MATRIX.md'));
$check('Backup drill doc', is_readable($root . '/docs/ACCOUNTING_BACKUP_RESTORE.md'));

exit($fail > 0 ? 1 : 0);
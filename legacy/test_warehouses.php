<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/app/models/PurchaseReceiveModel.php';

session_start();
$_SESSION['branch_id'] = 1;  // assume branch 1

$m = new PurchaseReceiveModel();
$wh = $m->getBranchWarehouses();

echo "Type: " . gettype($wh) . "\n";
echo "Count: " . (is_array($wh) ? count($wh) : 'n/a') . "\n";
echo json_encode($wh, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>

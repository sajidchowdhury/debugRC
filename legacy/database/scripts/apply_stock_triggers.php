<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec('DROP TRIGGER IF EXISTS `trg_warehouse_stock_no_negative_update`');
$pdo->exec('DROP TRIGGER IF EXISTS `trg_warehouse_stock_no_negative_insert`');

$pdo->exec("
CREATE TRIGGER `trg_warehouse_stock_no_negative_update`
BEFORE UPDATE ON `warehouse_stock`
FOR EACH ROW
BEGIN
    IF NEW.qty < -0.0001 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'warehouse_stock quantity cannot be negative';
    END IF;
END
");

$pdo->exec("
CREATE TRIGGER `trg_warehouse_stock_no_negative_insert`
BEFORE INSERT ON `warehouse_stock`
FOR EACH ROW
BEGIN
    IF NEW.qty < -0.0001 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'warehouse_stock quantity cannot be negative';
    END IF;
END
");

$pdo->prepare('INSERT IGNORE INTO schema_migrations (filename) VALUES (?)')
    ->execute(['018_warehouse_stock_non_negative.sql']);

echo "Stock non-negative triggers applied.\n";
<?php
/**
 * Phase 7 — align legacy sales invoice codes to SI-YYYYMMDD-#### (document_sequences).
 *
 * Usage:
 *   php database/scripts/migrate_legacy_invoice_codes.php           # dry-run
 *   php database/scripts/migrate_legacy_invoice_codes.php --apply   # write changes
 */
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
require_once $root . '/core/Database.php';
require_once $root . '/app/helpers/Helper.php';

$apply = in_array('--apply', $argv ?? [], true);
$helper = new Helper();
$db = $helper->getDatabase();

$db->query("
    SELECT id, invoice_code, invoice_date
    FROM sales_invoices
    WHERE invoice_code NOT REGEXP '^SI-[0-9]{8}-[0-9]+$'
    ORDER BY id ASC
");
$rows = $db->resultSet();

echo 'Legacy invoice codes found: ' . count($rows) . ($apply ? ' (APPLY mode)' : ' (dry-run)') . "\n";

$log = [];
foreach ($rows as $row) {
    $id = (int)$row['id'];
    $old = (string)$row['invoice_code'];
    $date = $row['invoice_date'] ?? date('Y-m-d');
    $period = date('Ymd', strtotime((string)$date));

    $db->beginTransaction();
    try {
        $n = $helper->allocateDocumentSequence('sales_invoice', $period, 0);
        $new = 'SI-' . $period . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);

        $db->query('SELECT id FROM sales_invoices WHERE invoice_code = :code AND id != :id LIMIT 1');
        $db->bind(':code', $new);
        $db->bind(':id', $id);
        if ($db->single()) {
            throw new RuntimeException("Collision on {$new}");
        }

        if ($apply) {
            $db->query('UPDATE sales_invoices SET invoice_code = :new WHERE id = :id');
            $db->bind(':new', $new);
            $db->bind(':id', $id);
            $db->execute();
            $db->commit();
        } else {
            $db->rollback();
        }

        $log[] = ['id' => $id, 'old' => $old, 'new' => $new];
        echo "  #{$id}: {$old} -> {$new}\n";
    } catch (Throwable $e) {
        $db->rollback();
        echo "  #{$id}: FAILED ({$old}) — {$e->getMessage()}\n";
    }
}

$logFile = $root . '/logs/invoice_code_migration_' . date('Ymd_His') . '.json';
if ($log !== []) {
    if (!is_dir(dirname($logFile))) {
        @mkdir(dirname($logFile), 0755, true);
    }
    file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Log: {$logFile}\n";
}

exit(0);
<?php
/**
 * Phase 7 — backup accounting-critical tables (journal + customer sub-ledger).
 * Usage: php database/scripts/backup_accounting_core.php [output_dir]
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/config/config.php';

$outDir = $argv[1] ?? ($root . '/backups');
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$tables = [
    'journal_entries',
    'journal_lines',
    'customer_ledger',
    'customer_payments',
    'invoice_payment_allocations',
    'document_sequences',
    'sales_invoices',
    'sales_challans',
];

$stamp = date('Ymd_His');
$file = rtrim($outDir, '/\\') . '/accounting_core_' . DB_NAME . '_' . $stamp . '.sql';

$host = escapeshellarg(DB_HOST);
$user = escapeshellarg(DB_USER);
$pass = DB_PASS !== '' ? '-p' . escapeshellarg(DB_PASS) : '';
$db   = escapeshellarg(DB_NAME);
$tableArgs = implode(' ', array_map('escapeshellarg', $tables));

$cmd = "mysqldump -h {$host} -u {$user} {$pass} {$db} {$tableArgs} > " . escapeshellarg($file);
echo "Running backup to {$file}\n";

if (PHP_OS_FAMILY === 'Windows') {
    echo "On Windows, run mysqldump manually if not in PATH:\n{$cmd}\n";
    exit(0);
}

passthru($cmd, $code);
echo $code === 0 ? "Backup OK\n" : "Backup failed (exit {$code})\n";
exit($code);
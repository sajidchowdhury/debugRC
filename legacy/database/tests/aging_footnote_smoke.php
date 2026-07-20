<?php
// DEPRECATED (Phase 2): This script uses MySQL-specific SQL and will NOT run on PostgreSQL.
// It is retained for historical reference only. The Laravel migration system (Phase 2.2+)
// replaces database/run_migrations.php. Utility scripts will be rewritten as Laravel artisan
// commands. Test scripts will be rewritten as PHPUnit tests in Phase 3+.
// Do NOT run this script against PostgreSQL.

/**
 * Smoke test: Aging totals match sub-ledger totals.
 * Run: php database/tests/aging_footnote_smoke.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../app/models/Reports/PayableAgingReport.php';
require_once __DIR__ . '/../../app/models/Reports/ReceivableAgingReport.php';

$asOf = date('Y-m-d');
$tolerance = defined('GL_RECONCILIATION_TOLERANCE') ? (float)GL_RECONCILIATION_TOLERANCE : 0.02;

$ap = (new PayableAgingReport())->getPayableAging($asOf, null);
$ar = (new ReceivableAgingReport())->getReceivableAging($asOf, null);

$checks = [
    'Payable aging vs sub-ledger' => abs(
        (float)($ap['grand_total'] ?? 0) - (float)($ap['footnote']['sub_ledger_total'] ?? 0)
    ),
    'Receivable aging vs sub-ledger' => abs(
        (float)($ar['grand_total'] ?? 0) - (float)($ar['footnote']['sub_ledger_total'] ?? 0)
    ),
];

echo "As of: {$asOf}\n";
$failed = false;
foreach ($checks as $label => $diff) {
    echo "{$label}: diff {$diff}\n";
    if ($diff > $tolerance) {
        $failed = true;
    }
}

if ($failed) {
    echo "FAIL: Aging total differs from sub-ledger beyond tolerance.\n";
    exit(1);
}

echo "PASS: Aging totals match sub-ledger totals.\n";
exit(0);

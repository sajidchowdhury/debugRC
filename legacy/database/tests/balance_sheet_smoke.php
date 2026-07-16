<?php
/**
 * Smoke test: Balance sheet equation when TB is balanced.
 * Run: php database/tests/balance_sheet_smoke.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../app/models/Reports/BalanceSheetReport.php';
require_once __DIR__ . '/../../app/models/Reports/TrialBalanceReport.php';

$asOf = date('Y-m-d');
$tb = (new TrialBalanceReport())->getTrialBalance('1970-01-01', $asOf, null, true);
$bs = (new BalanceSheetReport())->getBalanceSheet($asOf, null, true);

echo "As of: {$asOf}\n";
echo 'TB cumulative balanced: ' . (!empty($tb['is_balanced']) ? 'yes' : 'no') . "\n";
echo 'TB difference: ' . ($tb['difference'] ?? 0) . "\n";
echo 'BS equation balanced: ' . (!empty($bs['is_balanced']) ? 'yes' : 'no') . "\n";
echo 'BS difference: ' . ($bs['difference'] ?? 0) . "\n";
echo 'Assets: ' . ($bs['totals']['assets'] ?? 0) . "\n";
echo 'Liabilities + Equity: ' . ($bs['totals']['liabilities_plus_equity'] ?? 0) . "\n";

if (!empty($tb['is_balanced']) && !empty($bs['is_balanced'])) {
    echo "PASS: BS equation holds when TB is balanced.\n";
    exit(0);
}

if (!empty($tb['is_balanced']) && empty($bs['is_balanced'])) {
    echo "FAIL: TB balanced but BS equation does not hold.\n";
    exit(1);
}

echo "SKIP: TB not balanced — cannot verify BS equation tie.\n";
exit(0);

<?php
/**
 * Smoke test: P&L internal consistency.
 * Run: php database/tests/profit_and_loss_smoke.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../app/models/Reports/ProfitAndLossReport.php';

$from = date('Y-01-01');
$to = date('Y-m-d');

$pl = (new ProfitAndLossReport())->getProfitAndLoss($from, $to, null, true);
$summary = $pl['summary'] ?? [];

$net = (float)($summary['net_profit'] ?? 0);
$income = (float)($summary['total_income'] ?? 0);
$expense = (float)($summary['total_expense'] ?? 0);
$gross = (float)($summary['gross_profit'] ?? 0);
$revenue = (float)($summary['total_revenue'] ?? 0);
$cogs = (float)($summary['total_cogs'] ?? 0);

echo "Period: {$from} to {$to}\n";
echo "Revenue: {$revenue}\n";
echo "COGS: {$cogs}\n";
echo "Gross profit: {$gross}\n";
echo "Total income: {$income}\n";
echo "Total expense: {$expense}\n";
echo "Net profit: {$net}\n";

$grossCheck = abs($gross - ($revenue - $cogs)) < 0.02;
$netCheck = abs($net - ($income - $expense)) < 0.02;

if ($grossCheck && $netCheck) {
    echo "PASS: Gross profit and net profit formulas hold.\n";
    exit(0);
}

echo "FAIL:\n";
if (!$grossCheck) {
    echo "  Gross profit != revenue - COGS\n";
}
if (!$netCheck) {
    echo "  Net profit != total income - total expense\n";
}
exit(1);

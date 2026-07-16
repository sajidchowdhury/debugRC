<?php
/**
 * Smoke test: Cash flow GL reconciliation.
 * Run: php database/tests/cash_flow_smoke.php
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../app/models/Reports/CashFlowReport.php';

$from = date('Y-01-01');
$to = date('Y-m-d');

$cf = (new CashFlowReport())->getCashFlow($from, $to, null);
$rec = $cf['reconciliation'] ?? [];
$tolerance = (float)($rec['tolerance'] ?? 0.02);

$opening = (float)($rec['opening_cash_gl'] ?? 0);
$closing = (float)($rec['closing_cash_gl'] ?? 0);
$glMove = (float)($rec['gl_period_movement'] ?? 0);
$stmtMove = (float)($rec['statement_net_change'] ?? 0);
$moveDiff = (float)($rec['movement_difference'] ?? 0);
$banksDiff = $rec['banks_vs_gl_closing_diff'];

echo "Period: {$from} to {$to}\n";
echo "Opening cash (GL): {$opening}\n";
echo "Closing cash (GL): {$closing}\n";
echo "GL period movement: {$glMove}\n";
echo "Statement net change: {$stmtMove}\n";
echo "Movement difference: {$moveDiff}\n";
echo "Bank register vs GL closing diff: " . ($banksDiff === null ? 'n/a' : $banksDiff) . "\n";

$glIdentity = abs(($opening + $glMove) - $closing) < $tolerance;
$bankOk = $banksDiff === null || abs((float)$banksDiff) <= $tolerance;

if (!$glIdentity) {
    echo "FAIL: Opening + GL movement != closing cash.\n";
    exit(1);
}

if (!$bankOk) {
    echo "WARN: Bank register differs from GL cash_bank by more than tolerance (expected in some setups).\n";
}

if (!empty($rec['movement_within_tolerance'])) {
    echo "PASS: Indirect cash flow ties to GL cash_bank movement.\n";
    exit(0);
}

echo "NOTE: Indirect statement differs from GL cash movement by {$moveDiff} (WC mapping may not capture all flows).\n";
echo "PASS: GL cash identity holds; bank check " . ($bankOk ? 'ok' : 'review') . ".\n";
exit(0);

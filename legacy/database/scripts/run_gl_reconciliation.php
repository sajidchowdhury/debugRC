<?php
/**
 * Phase 4A/5 — scheduled GL reconciliation (AR, AP, employee, cash/bank, inventory, COGS).
 * Usage: php database/scripts/run_gl_reconciliation.php [--branch=ID] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]
 * Cron (daily): php database/scripts/run_gl_reconciliation.php
 * Exit code 1 when any branch report has_issues.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $root);
}
require_once $root . '/config/config.php';
require_once $root . '/app/services/Accounting/ReconciliationService.php';
require_once $root . '/app/services/Notification/AccountingTelegramNotifier.php';

$opts = getopt('', ['branch::', 'from::', 'to::']);
$branchFilter = isset($opts['branch']) ? max(0, (int)$opts['branch']) : null;
$fromDate = $opts['from'] ?? date('Y-m-01');
$toDate = $opts['to'] ?? date('Y-m-d');

$service = new ReconciliationService();
$reports = $service->runScheduledReconciliation($branchFilter, $fromDate, $toDate);

$anyIssues = false;
foreach ($reports as $report) {
    $label = $report['branch_name'] ?? ('Branch ' . ($report['branch_id'] ?? 'all'));
    $status = !empty($report['has_issues']) ? 'ISSUES' : 'OK';
    if (!empty($report['has_issues'])) {
        $anyIssues = true;
    }

    echo "[{$status}] {$label}\n";
    if (!empty($report['issues'])) {
        foreach ($report['issues'] as $issue) {
            echo "  - {$issue}\n";
        }
    }
    $ar = $report['ar'] ?? [];
    echo sprintf(
        "  AR: ledger=%.2f GL=%.2f diff=%.2f\n",
        (float)($ar['customer_ledger_net'] ?? 0),
        (float)($ar['gl_ar_net'] ?? 0),
        (float)($ar['difference'] ?? 0)
    );
    $ap = $report['ap'] ?? [];
    echo sprintf(
        "  AP: ledger=%.2f GL=%.2f diff=%.2f\n",
        (float)($ap['supplier_ledger_net'] ?? 0),
        (float)($ap['gl_ap_net'] ?? 0),
        (float)($ap['difference'] ?? 0)
    );
    $emp = $report['employee'] ?? [];
    echo sprintf(
        "  Employee: ledger=%.2f GL=%.2f diff=%.2f\n",
        (float)($emp['employee_ledger_net'] ?? 0),
        (float)($emp['gl_employee_net'] ?? 0),
        (float)($emp['difference'] ?? 0)
    );
    $cash = $report['cash_bank'] ?? [];
    echo sprintf(
        "  Cash/Bank: register=%.2f GL=%.2f diff=%.2f%s\n",
        (float)($cash['banks_total_balance'] ?? 0),
        (float)($cash['gl_cash_bank_net'] ?? 0),
        (float)($cash['difference'] ?? 0),
        !empty($cash['branch_scoped_note']) ? ' (branch-scoped GL only)' : ''
    );
    if (!empty($cash['mapping_mismatch_count'])) {
        echo "  Cash/Bank mapping mismatches: " . (int)$cash['mapping_mismatch_count'] . "\n";
    }
    $inv = $report['inventory'] ?? [];
    echo sprintf(
        "  Inventory: stock=%.2f GL=%.2f diff=%.2f\n",
        (float)($inv['warehouse_stock_value'] ?? 0),
        (float)($inv['gl_inventory_net'] ?? 0),
        (float)($inv['difference'] ?? 0)
    );
    $cogs = $report['cogs'] ?? [];
    echo sprintf(
        "  COGS (%s..%s): stock=%.2f GL=%.2f diff=%.2f\n",
        $cogs['from_date'] ?? $fromDate,
        $cogs['to_date'] ?? $toDate,
        (float)($cogs['stock_cogs_amount'] ?? 0),
        (float)($cogs['gl_cogs_amount'] ?? 0),
        (float)($cogs['difference'] ?? 0)
    );
    echo "\n";
}

$telegramReports = ReconciliationService::filterBranchIssueReports($reports);
if ($telegramReports !== []) {
    AccountingTelegramNotifier::safe(function () use ($telegramReports): void {
        (new AccountingTelegramNotifier())->notifyReconciliationIssues($telegramReports);
    });
    echo "Telegram: reconciliation alert queued for " . count($telegramReports) . " branch(es).\n";
}

exit($anyIssues ? 1 : 0);
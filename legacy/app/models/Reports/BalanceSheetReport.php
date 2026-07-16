<?php
// app/models/Reports/BalanceSheetReport.php — Phase 7B balance sheet as of date

require_once __DIR__ . '/../../helpers/JournalReportHelper.php';

class BalanceSheetReport
{
    protected $db;

    public function __construct()
    {
        require_once '../core/Database.php';
        $this->db = new Database();
    }

    /**
     * Balance sheet as of date with Assets = Liabilities + Equity check.
     *
     * Income and Expense balances are rolled into equity as unclosed current-period result.
     *
     * @return array<string, mixed>
     */
    public function getBalanceSheet(
        ?string $asOfDate = null,
        ?int $branchId = null,
        bool $includeZero = false
    ): array {
        $asOfDate = $asOfDate ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOfDate)) {
            $asOfDate = date('Y-m-d');
        }

        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND je.branch_id = ' . (int)$branchId;
        }

        $sql = "
            SELECT
                l.id AS ledger_id,
                l.ledger_code,
                l.ledger_name,
                l.account_type,
                l.normal_balance,
                l.ledger_nature,
                COALESCE(SUM(jl.debit), 0) AS total_debit,
                COALESCE(SUM(jl.credit), 0) AS total_credit
            FROM ledgers l
            LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
            LEFT JOIN journal_entries je
                ON je.id = jl.journal_entry_id
               AND COALESCE(je.is_reversed, 0) = 0
               AND je.entry_date <= :as_of_date
               {$branchSql}
            WHERE l.is_active = 1
              AND l.account_type IN ('Asset', 'Liability', 'Equity', 'Income', 'Expense')
            GROUP BY l.id, l.ledger_code, l.ledger_name, l.account_type, l.normal_balance, l.ledger_nature
            ORDER BY FIELD(l.account_type, 'Asset', 'Liability', 'Equity', 'Income', 'Expense'),
                     l.ledger_name ASC
        ";

        $this->db->query($sql);
        $this->db->bind(':as_of_date', $asOfDate);
        $rows = $this->db->resultSet() ?: [];

        $sections = [
            'assets'      => ['label' => 'Assets', 'rows' => [], 'total' => 0.0],
            'liabilities' => ['label' => 'Liabilities', 'rows' => [], 'total' => 0.0],
            'equity'      => [
                'label'       => 'Equity',
                'rows'        => [],
                'net_income'  => 0.0,
                'net_income_label' => 'Current period result (unclosed Income − Expense)',
                'total'       => 0.0,
            ],
        ];

        $incomeTotal = 0.0;
        $expenseTotal = 0.0;

        foreach ($rows as $row) {
            $normalBalance = $row['normal_balance'] ?? 'debit';
            $debit = (float)$row['total_debit'];
            $credit = (float)$row['total_credit'];
            $computed = JournalReportHelper::computeBalance($debit, $credit, $normalBalance);
            $accountType = (string)($row['account_type'] ?? '');
            $bsAmount = $this->balanceSheetAmount($accountType, $debit, $credit);

            if (!$includeZero && abs($bsAmount) < 0.005) {
                continue;
            }

            $line = [
                'ledger_id'      => (int)$row['ledger_id'],
                'ledger_code'    => $row['ledger_code'],
                'ledger_name'    => $row['ledger_name'],
                'account_type'   => $accountType,
                'ledger_nature'  => $row['ledger_nature'] ?? '',
                'normal_balance' => $normalBalance,
                'balance'        => (float)$computed['balance'],
                'balance_side'   => $computed['balance_side'],
                'signed_balance' => $bsAmount,
            ];

            switch ($accountType) {
                case 'Asset':
                    $sections['assets']['rows'][] = $line;
                    $sections['assets']['total'] += $bsAmount;
                    break;
                case 'Liability':
                    $sections['liabilities']['rows'][] = $line;
                    $sections['liabilities']['total'] += $bsAmount;
                    break;
                case 'Equity':
                    $sections['equity']['rows'][] = $line;
                    break;
                case 'Income':
                    $incomeTotal += $bsAmount;
                    break;
                case 'Expense':
                    $expenseTotal += $bsAmount;
                    break;
            }
        }

        $netIncome = round($incomeTotal - $expenseTotal, 2);
        $equityLedgerTotal = 0.0;
        foreach ($sections['equity']['rows'] as $line) {
            $equityLedgerTotal += (float)$line['signed_balance'];
        }

        $sections['equity']['net_income'] = $netIncome;
        $sections['equity']['equity_ledgers_total'] = round($equityLedgerTotal, 2);
        $sections['equity']['total'] = round($equityLedgerTotal + $netIncome, 2);

        foreach (['assets', 'liabilities'] as $key) {
            $sections[$key]['total'] = round($sections[$key]['total'], 2);
        }

        $totalAssets = $sections['assets']['total'];
        $totalLiabilities = $sections['liabilities']['total'];
        $totalEquity = $sections['equity']['total'];
        $liabilitiesPlusEquity = round($totalLiabilities + $totalEquity, 2);
        $difference = round($totalAssets - $liabilitiesPlusEquity, 2);
        $isBalanced = abs($difference) < 0.02;

        $tbCheck = $this->checkTrialBalanceThroughDate($asOfDate, $branchId);

        return [
            'as_of_date'              => $asOfDate,
            'branch_id'               => $branchId,
            'include_zero'            => $includeZero,
            'sections'                => $sections,
            'totals'                  => [
                'assets'                  => $totalAssets,
                'liabilities'             => $totalLiabilities,
                'equity'                  => $totalEquity,
                'liabilities_plus_equity' => $liabilitiesPlusEquity,
            ],
            'is_balanced'             => $isBalanced,
            'difference'              => $difference,
            'income_total'            => round($incomeTotal, 2),
            'expense_total'           => round($expenseTotal, 2),
            'tb_activity_balanced'    => $tbCheck['is_balanced'],
            'tb_period_difference'    => $tbCheck['difference'],
        ];
    }

    /**
     * Cumulative journal activity through as-of should balance when books are clean.
     *
     * @return array{is_balanced: bool, difference: float}
     */
    private function checkTrialBalanceThroughDate(string $asOfDate, ?int $branchId): array
    {
        require_once __DIR__ . '/TrialBalanceReport.php';
        $from = '1970-01-01';
        $tb = (new TrialBalanceReport())->getTrialBalance($from, $asOfDate, null, true);

        return [
            'is_balanced' => !empty($tb['is_balanced']),
            'difference'  => (float)($tb['difference'] ?? 0),
        ];
    }

    public function exportToCsv(array $reportData, string $filename = 'Balance_Sheet'): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '_' . ($reportData['as_of_date'] ?? date('Y-m-d')) . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Balance Sheet']);
        fputcsv($out, ['As of', $reportData['as_of_date'] ?? '']);
        fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
        fputcsv($out, []);

        foreach (['assets', 'liabilities'] as $key) {
            $section = $reportData['sections'][$key] ?? [];
            fputcsv($out, [$section['label'] ?? ucfirst($key)]);
            fputcsv($out, ['Code', 'Ledger', 'Balance', 'Side']);
            foreach ($section['rows'] ?? [] as $row) {
                fputcsv($out, [
                    $row['ledger_code'],
                    $row['ledger_name'],
                    number_format((float)$row['balance'], 2),
                    $row['balance_side'],
                ]);
            }
            fputcsv($out, ['Section total', '', number_format((float)($section['total'] ?? 0), 2), '']);
            fputcsv($out, []);
        }

        $equity = $reportData['sections']['equity'] ?? [];
        fputcsv($out, [$equity['label'] ?? 'Equity']);
        fputcsv($out, ['Code', 'Ledger', 'Balance', 'Side']);
        foreach ($equity['rows'] ?? [] as $row) {
            fputcsv($out, [
                $row['ledger_code'],
                $row['ledger_name'],
                number_format((float)$row['balance'], 2),
                $row['balance_side'],
            ]);
        }
        if ((float)($equity['net_income'] ?? 0) !== 0.0) {
            fputcsv($out, ['', $equity['net_income_label'] ?? 'Net income', number_format((float)$equity['net_income'], 2), 'Cr']);
        }
        fputcsv($out, ['Section total', '', number_format((float)($equity['total'] ?? 0), 2), '']);
        fputcsv($out, []);

        $totals = $reportData['totals'] ?? [];
        fputcsv($out, ['Total assets', number_format((float)($totals['assets'] ?? 0), 2)]);
        fputcsv($out, ['Total liabilities + equity', number_format((float)($totals['liabilities_plus_equity'] ?? 0), 2)]);
        fputcsv($out, ['Difference', number_format((float)($reportData['difference'] ?? 0), 2)]);
        fputcsv($out, ['Equation', !empty($reportData['is_balanced']) ? 'BALANCED' : 'OUT OF BALANCE']);

        fclose($out);
        exit;
    }

    /**
     * Statement amount for equation totals (contra-assets reduce assets via debit−credit net).
     * Display columns still use JournalReportHelper::computeBalance for Dr/Cr presentation.
     */
    private function balanceSheetAmount(string $accountType, float $debit, float $credit): float
    {
        $net = round($debit - $credit, 2);

        return match ($accountType) {
            'Asset'                 => $net,
            'Liability', 'Equity' => -$net,
            'Income'                => -$net,
            'Expense'               => $net,
            default                 => 0.0,
        };
    }
}

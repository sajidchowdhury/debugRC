<?php
// app/models/Reports/ProfitAndLossReport.php — Phase 7A income statement

require_once __DIR__ . '/../../helpers/JournalReportHelper.php';
require_once __DIR__ . '/../LedgerModel.php';

class ProfitAndLossReport
{
    protected $db;

    /** @var array<string, string> */
    private static ?array $natureLabels = null;

    public function __construct()
    {
        require_once '../core/Database.php';
        $this->db = new Database();
    }

    /**
     * P&L statement sections keyed by ledger_nature groups.
     *
     * @return array<string, array{label: string, natures: string[], sort: int}>
     */
    public static function sectionDefinitions(): array
    {
        return [
            'revenue' => [
                'label'  => 'Revenue',
                'natures' => ['sales_revenue', 'other_income', 'inventory_surplus', 'sales_return', 'sales_discount'],
                'sort'   => 10,
            ],
            'cost_of_sales' => [
                'label'  => 'Cost of Goods Sold',
                'natures' => ['cogs'],
                'sort'   => 20,
            ],
            'operating_expenses' => [
                'label'  => 'Operating Expenses',
                'natures' => ['operating_expense', 'inventory_shrinkage', 'manual_adjustment'],
                'sort'   => 30,
            ],
            'payroll' => [
                'label'  => 'Payroll & Salaries',
                'natures' => ['payroll_expense'],
                'sort'   => 40,
            ],
            'depreciation' => [
                'label'  => 'Depreciation & Amortization',
                'natures' => ['depreciation'],
                'sort'   => 50,
            ],
            'financial' => [
                'label'  => 'Financial Expenses',
                'natures' => ['financial_expense'],
                'sort'   => 60,
            ],
            'other_pl' => [
                'label'  => 'Other Income & Expense',
                'natures' => [],
                'sort'   => 70,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getProfitAndLoss(
        ?string $fromDate = null,
        ?string $toDate = null,
        ?int $branchId = null,
        bool $includeZero = false,
        ?string $compareFromDate = null,
        ?string $compareToDate = null
    ): array {
        $fromDate = $this->normalizeDate($fromDate ?: date('Y-m-01'));
        $toDate = $this->normalizeDate($toDate ?: date('Y-m-d'));

        $primaryRows = $this->fetchPeriodRows($fromDate, $toDate, $branchId);
        $report = $this->buildReportPayload($fromDate, $toDate, $branchId, $primaryRows, $includeZero);

        if ($compareFromDate && $compareToDate) {
            $compareFromDate = $this->normalizeDate($compareFromDate);
            $compareToDate = $this->normalizeDate($compareToDate);
            $compareRows = $this->fetchPeriodRows($compareFromDate, $compareToDate, $branchId);
            $compareReport = $this->buildReportPayload($compareFromDate, $compareToDate, $branchId, $compareRows, $includeZero);
            $report = $this->mergeComparative($report, $compareReport, $compareFromDate, $compareToDate);
        }

        return $report;
    }

    /**
     * Prior period of equal length ending the day before $fromDate.
     *
     * @return array{from: string, to: string}|null
     */
    public static function resolvePriorPeriod(string $fromDate, string $toDate): ?array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            return null;
        }
        $fromTs = strtotime($fromDate);
        $toTs = strtotime($toDate);
        if ($fromTs === false || $toTs === false || $toTs < $fromTs) {
            return null;
        }
        $days = (int)floor(($toTs - $fromTs) / 86400) + 1;
        $compareTo = date('Y-m-d', strtotime($fromDate . ' -1 day'));
        $compareFrom = date('Y-m-d', strtotime($compareTo . ' -' . ($days - 1) . ' days'));

        return ['from' => $compareFrom, 'to' => $compareTo];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchPeriodRows(string $fromDate, string $toDate, ?int $branchId): array
    {
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
                COALESCE(SUM(CASE WHEN je.entry_date BETWEEN :from_date AND :to_date THEN jl.debit ELSE 0 END), 0) AS period_debit,
                COALESCE(SUM(CASE WHEN je.entry_date BETWEEN :from_date AND :to_date THEN jl.credit ELSE 0 END), 0) AS period_credit
            FROM ledgers l
            LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
            LEFT JOIN journal_entries je
                ON je.id = jl.journal_entry_id
               AND COALESCE(je.is_reversed, 0) = 0
               {$branchSql}
            WHERE l.is_active = 1
              AND l.account_type IN ('Income', 'Expense')
            GROUP BY l.id, l.ledger_code, l.ledger_name, l.account_type, l.normal_balance, l.ledger_nature
            ORDER BY l.account_type DESC, l.ledger_name ASC
        ";

        $this->db->query($sql);
        $this->db->bind(':from_date', $fromDate);
        $this->db->bind(':to_date', $toDate);

        return $this->db->resultSet() ?: [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildReportPayload(
        string $fromDate,
        string $toDate,
        ?int $branchId,
        array $rows,
        bool $includeZero
    ): array {
        $sections = [];
        $natureToSection = $this->natureToSectionMap();
        $definitions = self::sectionDefinitions();

        foreach ($definitions as $key => $def) {
            $sections[$key] = [
                'key'   => $key,
                'label' => $def['label'],
                'sort'  => $def['sort'],
                'rows'  => [],
                'total' => 0.0,
            ];
        }

        $totalIncome = 0.0;
        $totalExpense = 0.0;

        foreach ($rows as $row) {
            $accountType = (string)($row['account_type'] ?? '');
            $debit = (float)$row['period_debit'];
            $credit = (float)$row['period_credit'];
            $amount = $this->plAmount($accountType, $debit, $credit);

            if (!$includeZero && abs($amount) < 0.005) {
                continue;
            }

            $nature = (string)($row['ledger_nature'] ?? '');
            $sectionKey = $natureToSection[$nature] ?? 'other_pl';

            $normalBalance = $row['normal_balance'] ?? 'debit';
            $computed = JournalReportHelper::computeBalance($debit, $credit, $normalBalance);

            $line = [
                'ledger_id'      => (int)$row['ledger_id'],
                'ledger_code'    => $row['ledger_code'],
                'ledger_name'    => $row['ledger_name'],
                'ledger_nature'  => $nature,
                'nature_label'   => self::natureLabel($nature),
                'account_type'   => $accountType,
                'normal_balance' => $normalBalance,
                'period_debit'   => $debit,
                'period_credit'  => $credit,
                'amount'         => round($amount, 2),
                'balance'        => (float)$computed['balance'],
                'balance_side'   => $computed['balance_side'],
            ];

            $sections[$sectionKey]['rows'][] = $line;
            $sections[$sectionKey]['total'] += $amount;

            if ($accountType === 'Income') {
                $totalIncome += $amount;
            } else {
                $totalExpense += $amount;
            }
        }

        foreach ($sections as $key => $section) {
            $sections[$key]['total'] = round($section['total'], 2);
            usort($sections[$key]['rows'], static fn(array $a, array $b): int => strcmp($a['ledger_name'], $b['ledger_name']));
        }

        $totalRevenue = $sections['revenue']['total'];
        $totalCogs = $sections['cost_of_sales']['total'];
        $grossProfit = round($totalRevenue - $totalCogs, 2);
        $netProfit = round($totalIncome - $totalExpense, 2);

        $operatingExpenseTotal = round(
            $sections['operating_expenses']['total']
            + $sections['payroll']['total']
            + $sections['depreciation']['total']
            + $sections['financial']['total']
            + $this->otherExpensePortion($sections['other_pl']),
            2
        );

        uasort($sections, static fn(array $a, array $b): int => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));

        return [
            'from_date'  => $fromDate,
            'to_date'    => $toDate,
            'branch_id'  => $branchId,
            'include_zero' => $includeZero,
            'sections'   => array_values(array_filter($sections, static fn(array $s): bool => !empty($s['rows']) || abs($s['total']) >= 0.005)),
            'summary'    => [
                'total_income'           => round($totalIncome, 2),
                'total_expense'          => round($totalExpense, 2),
                'total_revenue'          => $totalRevenue,
                'total_cogs'             => $totalCogs,
                'gross_profit'           => $grossProfit,
                'operating_expenses'     => $operatingExpenseTotal,
                'net_profit'             => $netProfit,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $compare
     * @return array<string, mixed>
     */
    private function mergeComparative(array $primary, array $compare, string $compareFrom, string $compareTo): array
    {
        $compareByLedger = [];
        foreach ($compare['sections'] ?? [] as $section) {
            foreach ($section['rows'] ?? [] as $row) {
                $compareByLedger[(int)$row['ledger_id']] = (float)$row['amount'];
            }
        }

        $compareSectionTotals = [];
        foreach ($compare['sections'] ?? [] as $section) {
            $compareSectionTotals[$section['key'] ?? ''] = (float)($section['total'] ?? 0);
        }

        foreach ($primary['sections'] as &$section) {
            $key = $section['key'] ?? '';
            $section['compare_total'] = round($compareSectionTotals[$key] ?? 0, 2);
            $section['variance'] = round((float)$section['total'] - (float)$section['compare_total'], 2);

            foreach ($section['rows'] as &$row) {
                $cmp = $compareByLedger[(int)$row['ledger_id']] ?? 0.0;
                $row['compare_amount'] = round($cmp, 2);
                $row['variance'] = round((float)$row['amount'] - $cmp, 2);
            }
            unset($row);
        }
        unset($section);

        $primarySummary = $primary['summary'] ?? [];
        $compareSummary = $compare['summary'] ?? [];
        $primary['compare'] = [
            'from_date' => $compareFrom,
            'to_date'   => $compareTo,
            'summary'   => $compareSummary,
        ];
        $primary['summary']['compare_net_profit'] = (float)($compareSummary['net_profit'] ?? 0);
        $primary['summary']['net_profit_variance'] = round(
            (float)($primarySummary['net_profit'] ?? 0) - (float)($compareSummary['net_profit'] ?? 0),
            2
        );
        $primary['summary']['compare_gross_profit'] = (float)($compareSummary['gross_profit'] ?? 0);
        $primary['summary']['compare_total_revenue'] = (float)($compareSummary['total_revenue'] ?? 0);

        return $primary;
    }

    private function otherExpensePortion(array $otherSection): float
    {
        $total = 0.0;
        foreach ($otherSection['rows'] ?? [] as $row) {
            if (($row['account_type'] ?? '') === 'Expense') {
                $total += (float)($row['amount'] ?? 0);
            }
        }
        return $total;
    }

    private function plAmount(string $accountType, float $debit, float $credit): float
    {
        $net = round($debit - $credit, 2);

        return match ($accountType) {
            'Income'  => -$net,
            'Expense' => $net,
            default   => 0.0,
        };
    }

    /**
     * @return array<string, string>
     */
    private function natureToSectionMap(): array
    {
        $map = [];
        foreach (self::sectionDefinitions() as $key => $def) {
            foreach ($def['natures'] as $nature) {
                $map[$nature] = $key;
            }
        }
        return $map;
    }

    private static function natureLabel(string $nature): string
    {
        if (self::$natureLabels === null) {
            self::$natureLabels = [];
            foreach (LedgerModel::getNatureOptionGroups() as $group) {
                foreach ($group as $code => $label) {
                    self::$natureLabels[$code] = $label;
                }
            }
        }

        return self::$natureLabels[$nature] ?? ucwords(str_replace('_', ' ', $nature));
    }

    private function normalizeDate(string $date): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    }

    public function exportToCsv(array $reportData, string $filename = 'Profit_And_Loss'): void
    {
        $hasCompare = !empty($reportData['compare']);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '_' . ($reportData['to_date'] ?? date('Y-m-d')) . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Profit & Loss Statement']);
        fputcsv($out, ['Period', ($reportData['from_date'] ?? '') . ' to ' . ($reportData['to_date'] ?? '')]);
        if ($hasCompare) {
            fputcsv($out, ['Compare', ($reportData['compare']['from_date'] ?? '') . ' to ' . ($reportData['compare']['to_date'] ?? '')]);
        }
        fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
        fputcsv($out, []);

        $headers = ['Section', 'Code', 'Ledger', 'Nature', 'Amount'];
        if ($hasCompare) {
            $headers[] = 'Compare amount';
            $headers[] = 'Variance';
        }
        fputcsv($out, $headers);

        foreach ($reportData['sections'] ?? [] as $section) {
            foreach ($section['rows'] ?? [] as $row) {
                $line = [
                    $section['label'] ?? '',
                    $row['ledger_code'] ?? '',
                    $row['ledger_name'] ?? '',
                    $row['nature_label'] ?? '',
                    number_format((float)($row['amount'] ?? 0), 2, '.', ''),
                ];
                if ($hasCompare) {
                    $line[] = number_format((float)($row['compare_amount'] ?? 0), 2, '.', '');
                    $line[] = number_format((float)($row['variance'] ?? 0), 2, '.', '');
                }
                fputcsv($out, $line);
            }
            $totalLine = [$section['label'] . ' total', '', '', '', number_format((float)($section['total'] ?? 0), 2, '.', '')];
            if ($hasCompare) {
                $totalLine[] = number_format((float)($section['compare_total'] ?? 0), 2, '.', '');
                $totalLine[] = number_format((float)($section['variance'] ?? 0), 2, '.', '');
            }
            fputcsv($out, $totalLine);
            fputcsv($out, []);
        }

        $summary = $reportData['summary'] ?? [];
        fputcsv($out, ['Gross profit', number_format((float)($summary['gross_profit'] ?? 0), 2, '.', '')]);
        fputcsv($out, ['Net profit', number_format((float)($summary['net_profit'] ?? 0), 2, '.', '')]);

        fclose($out);
        exit;
    }

    /**
     * Print-ready HTML (browser Save as PDF).
     */
    public function exportToPdfHtml(array $reportData): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $reportData['_auto_print'] = true;
        require __DIR__ . '/../../views/Report/ProfitAndLoss_print.php';
        exit;
    }
}

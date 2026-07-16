<?php
// app/models/Reports/CashFlowReport.php — Phase 7C indirect cash flow

require_once __DIR__ . '/../../helpers/JournalReportHelper.php';
require_once __DIR__ . '/../LedgerModel.php';
require_once __DIR__ . '/ProfitAndLossReport.php';

class CashFlowReport
{
    protected $db;
    private float $tolerance;

    /** @var array<string, array{label: string, account_type: string, wc: bool}> */
    private const NATURE_META = [
        'customer_receivable'  => ['label' => 'Change in accounts receivable', 'account_type' => 'Asset', 'wc' => true],
        'supplier_payable'   => ['label' => 'Change in accounts payable', 'account_type' => 'Liability', 'wc' => true],
        'employee_payable'   => ['label' => 'Change in employee balances', 'account_type' => 'Asset', 'wc' => true],
        'inventory'            => ['label' => 'Change in inventory', 'account_type' => 'Asset', 'wc' => true],
        'prepaid_expense'      => ['label' => 'Change in prepaid expenses', 'account_type' => 'Asset', 'wc' => true],
        'accrued_expense'      => ['label' => 'Change in accrued liabilities', 'account_type' => 'Liability', 'wc' => true],
        'tax_payable'          => ['label' => 'Change in tax payable', 'account_type' => 'Liability', 'wc' => true],
        'tax_receivable'       => ['label' => 'Change in tax receivable', 'account_type' => 'Asset', 'wc' => true],
        'fixed_asset'          => ['label' => 'Purchase / disposal of fixed assets', 'account_type' => 'Asset', 'wc' => false],
        'owner_equity'         => ['label' => 'Owner capital / contributions', 'account_type' => 'Equity', 'wc' => false],
        'drawings'             => ['label' => "Owner's drawings", 'account_type' => 'Equity', 'wc' => false],
        'long_term_liability'  => ['label' => 'Long-term borrowings', 'account_type' => 'Liability', 'wc' => false],
        'interbranch_receivable' => ['label' => 'Change in inter-branch receivable', 'account_type' => 'Asset', 'wc' => true],
        'interbranch_payable'  => ['label' => 'Change in inter-branch payable', 'account_type' => 'Liability', 'wc' => true],
    ];

    public function __construct()
    {
        require_once '../core/Database.php';
        $this->db = new Database();
        $this->tolerance = defined('GL_RECONCILIATION_TOLERANCE') ? (float)GL_RECONCILIATION_TOLERANCE : 0.02;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCashFlow(?string $fromDate = null, ?string $toDate = null, ?int $branchId = null): array
    {
        $fromDate = $this->normalizeDate($fromDate ?: date('Y-m-01'));
        $toDate = $this->normalizeDate($toDate ?: date('Y-m-d'));
        $openingDate = date('Y-m-d', strtotime($fromDate . ' -1 day'));

        $pl = (new ProfitAndLossReport())->getProfitAndLoss($fromDate, $toDate, $branchId, true);
        $netProfit = (float)($pl['summary']['net_profit'] ?? 0);

        $depreciationAddBack = $this->periodNatureTotal(
            'depreciation',
            'Expense',
            $fromDate,
            $toDate,
            $branchId
        );

        $operatingLines = [
            $this->line('net_profit', 'Net profit (from P&L)', $netProfit, 'reference'),
            $this->line('depreciation_addback', 'Depreciation & amortization (add back)', $depreciationAddBack, 'adjustment'),
        ];

        $wcTotal = 0.0;
        foreach (self::NATURE_META as $nature => $meta) {
            if (empty($meta['wc'])) {
                continue;
            }
            $effect = $this->natureCashEffect($nature, $openingDate, $toDate, $branchId);
            if (abs($effect) < 0.005) {
                continue;
            }
            $operatingLines[] = $this->line('wc_' . $nature, $meta['label'], $effect, 'working_capital');
            $wcTotal += $effect;
        }

        $netCashOperatingPartial = round($netProfit + $depreciationAddBack + $wcTotal, 2);

        $investingLines = [];
        $investingTotal = 0.0;
        $effect = $this->natureCashEffect('fixed_asset', $openingDate, $toDate, $branchId);
        if (abs($effect) >= 0.005) {
            $investingLines[] = $this->line('fixed_asset', self::NATURE_META['fixed_asset']['label'], $effect, 'investing');
            $investingTotal += $effect;
        }
        $investingLines[] = $this->line('investing_total', 'Net cash from investing activities', round($investingTotal, 2), 'subtotal');

        $financingLines = [];
        $financingTotal = 0.0;
        foreach (['owner_equity', 'drawings', 'long_term_liability'] as $nature) {
            $effect = $this->natureCashEffect($nature, $openingDate, $toDate, $branchId);
            if (abs($effect) < 0.005) {
                continue;
            }
            $financingLines[] = $this->line($nature, self::NATURE_META[$nature]['label'], $effect, 'financing');
            $financingTotal += $effect;
        }
        $financingLines[] = $this->line('financing_total', 'Net cash from financing activities', round($financingTotal, 2), 'subtotal');

        $openingCashGl = $this->cashBankBalanceAt($openingDate, $branchId);
        $closingCashGl = $this->cashBankBalanceAt($toDate, $branchId);
        $glPeriodMovement = round($closingCashGl - $openingCashGl, 2);

        $plugAmount = 0.0;
        $identifiedBeforePlug = round($netCashOperatingPartial + $investingTotal + $financingTotal, 2);
        $plugAmount = round($glPeriodMovement - $identifiedBeforePlug, 2);
        if (abs($plugAmount) >= 0.005) {
            $operatingLines[] = $this->line(
                'other_wc_plug',
                'Other working capital & unclassified (balances to GL cash)',
                $plugAmount,
                'plug'
            );
        }
        $netCashOperating = round($netCashOperatingPartial + $plugAmount, 2);
        $operatingLines[] = $this->line('operating_total', 'Net cash from operating activities', $netCashOperating, 'subtotal');

        $netChangeInCash = round($netCashOperating + $investingTotal + $financingTotal, 2);

        $movementDiff = round($netChangeInCash - $glPeriodMovement, 2);

        $banksTotal = $this->sumBankRegisterBalances();
        $banksVsGlDiff = round($banksTotal - $closingCashGl, 2);
        $branchScoped = $branchId !== null && $branchId > 0;

        return [
            'from_date'  => $fromDate,
            'to_date'    => $toDate,
            'branch_id'  => $branchId,
            'sections'   => [
                ['key' => 'operating', 'label' => 'Cash flows from operating activities', 'lines' => $operatingLines],
                ['key' => 'investing', 'label' => 'Cash flows from investing activities', 'lines' => $investingLines],
                ['key' => 'financing', 'label' => 'Cash flows from financing activities', 'lines' => $financingLines],
            ],
            'summary' => [
                'net_profit'            => $netProfit,
                'depreciation_addback'  => $depreciationAddBack,
                'working_capital_change'=> round($wcTotal, 2),
                'other_plug'            => abs($plugAmount) >= 0.005 ? $plugAmount : 0.0,
                'net_cash_operating'    => $netCashOperating,
                'net_cash_investing'    => round($investingTotal, 2),
                'net_cash_financing'    => round($financingTotal, 2),
                'net_change_in_cash'    => $netChangeInCash,
            ],
            'reconciliation' => [
                'opening_cash_gl'         => $openingCashGl,
                'closing_cash_gl'         => $closingCashGl,
                'gl_period_movement'      => $glPeriodMovement,
                'statement_net_change'    => $netChangeInCash,
                'movement_difference'     => $movementDiff,
                'movement_within_tolerance' => abs($movementDiff) <= $this->tolerance,
                'banks_register_total'    => $banksTotal,
                'banks_vs_gl_closing_diff'=> $branchScoped ? null : $banksVsGlDiff,
                'banks_within_tolerance'  => $branchScoped ? null : (abs($banksVsGlDiff) <= $this->tolerance),
                'branch_scoped_note'      => $branchScoped
                    ? 'Bank register is company-wide; GL cash is branch-scoped — compare on all-branches view.'
                    : null,
                'tolerance'               => $this->tolerance,
            ],
        ];
    }

    public function exportToCsv(array $report, string $filename = 'Cash_Flow'): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '_' . ($report['to_date'] ?? date('Y-m-d')) . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Cash Flow Statement (Indirect Method)']);
        fputcsv($out, ['Period', ($report['from_date'] ?? '') . ' to ' . ($report['to_date'] ?? '')]);
        fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
        fputcsv($out, []);

        foreach ($report['sections'] ?? [] as $section) {
            fputcsv($out, [$section['label'] ?? '']);
            foreach ($section['lines'] ?? [] as $line) {
                fputcsv($out, ['', $line['label'] ?? '', number_format((float)($line['amount'] ?? 0), 2, '.', '')]);
            }
            fputcsv($out, []);
        }

        $rec = $report['reconciliation'] ?? [];
        fputcsv($out, ['Reconciliation']);
        fputcsv($out, ['Opening cash (GL cash_bank)', number_format((float)($rec['opening_cash_gl'] ?? 0), 2, '.', '')]);
        fputcsv($out, ['Net change (statement)', number_format((float)($rec['statement_net_change'] ?? 0), 2, '.', '')]);
        fputcsv($out, ['Closing cash (GL cash_bank)', number_format((float)($rec['closing_cash_gl'] ?? 0), 2, '.', '')]);
        fputcsv($out, ['GL period movement', number_format((float)($rec['gl_period_movement'] ?? 0), 2, '.', '')]);
        fputcsv($out, ['Movement difference', number_format((float)($rec['movement_difference'] ?? 0), 2, '.', '')]);
        fputcsv($out, ['Bank register total', number_format((float)($rec['banks_register_total'] ?? 0), 2, '.', '')]);

        fclose($out);
        exit;
    }

    /**
     * Cash effect of balance change for a ledger nature (indirect method).
     */
    private function natureCashEffect(string $nature, string $openingDate, string $closingDate, ?int $branchId): float
    {
        $meta = self::NATURE_META[$nature] ?? null;
        if ($meta === null) {
            return 0.0;
        }

        $opening = $this->natureBalanceAt($nature, $openingDate, $branchId);
        $closing = $this->natureBalanceAt($nature, $closingDate, $branchId);
        $change = round($closing - $opening, 2);

        return match ($meta['account_type']) {
            'Asset'     => -$change,
            'Liability' => $change,
            'Equity'    => $nature === 'drawings' ? -$change : $change,
            default     => 0.0,
        };
    }

    private function natureBalanceAt(string $nature, string $asOfDate, ?int $branchId): float
    {
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND je.branch_id = ' . (int)$branchId;
        }

        $this->db->query("
            SELECT
                COALESCE(SUM(jl.debit), 0) AS total_debit,
                COALESCE(SUM(jl.credit), 0) AS total_credit,
                MAX(l.account_type) AS account_type,
                MAX(l.normal_balance) AS normal_balance
            FROM ledgers l
            LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
            LEFT JOIN journal_entries je
                ON je.id = jl.journal_entry_id
               AND COALESCE(je.is_reversed, 0) = 0
               AND je.entry_date <= :as_of
               {$branchSql}
            WHERE l.is_active = 1
              AND l.ledger_nature = :nature
        ");
        $this->db->bind(':as_of', $asOfDate);
        $this->db->bind(':nature', $nature);
        $row = $this->db->single() ?: [];

        return $this->signedBalance(
            (string)($row['account_type'] ?? 'Asset'),
            (float)($row['total_debit'] ?? 0),
            (float)($row['total_credit'] ?? 0)
        );
    }

    private function periodNatureTotal(
        string $nature,
        string $accountType,
        string $fromDate,
        string $toDate,
        ?int $branchId
    ): float {
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND je.branch_id = ' . (int)$branchId;
        }

        $this->db->query("
            SELECT
                COALESCE(SUM(jl.debit), 0) AS period_debit,
                COALESCE(SUM(jl.credit), 0) AS period_credit
            FROM ledgers l
            INNER JOIN journal_lines jl ON jl.ledger_id = l.id
            INNER JOIN journal_entries je
                ON je.id = jl.journal_entry_id
               AND COALESCE(je.is_reversed, 0) = 0
               AND je.entry_date BETWEEN :from_date AND :to_date
               {$branchSql}
            WHERE l.is_active = 1
              AND l.ledger_nature = :nature
              AND l.account_type = :account_type
        ");
        $this->db->bind(':from_date', $fromDate);
        $this->db->bind(':to_date', $toDate);
        $this->db->bind(':nature', $nature);
        $this->db->bind(':account_type', $accountType);
        $row = $this->db->single() ?: [];

        if ($accountType === 'Expense') {
            return round((float)($row['period_debit'] ?? 0) - (float)($row['period_credit'] ?? 0), 2);
        }

        return round((float)($row['period_credit'] ?? 0) - (float)($row['period_debit'] ?? 0), 2);
    }

    private function cashBankBalanceAt(string $asOfDate, ?int $branchId): float
    {
        return $this->natureBalanceAt('cash_bank', $asOfDate, $branchId);
    }

    private function sumBankRegisterBalances(): float
    {
        $this->db->query('
            SELECT COALESCE(SUM(balance), 0) AS total
            FROM banks
            WHERE COALESCE(is_active, 1) = 1
        ');

        return round((float)($this->db->single()['total'] ?? 0), 2);
    }

    private function signedBalance(string $accountType, float $debit, float $credit): float
    {
        $net = round($debit - $credit, 2);

        return match ($accountType) {
            'Asset', 'Expense' => $net,
            'Liability', 'Equity', 'Income' => -$net,
            default => $net,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $id, string $label, float $amount, string $kind): array
    {
        return [
            'id'     => $id,
            'label'  => $label,
            'amount' => round($amount, 2),
            'kind'   => $kind,
        ];
    }

    private function normalizeDate(string $date): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    }
}

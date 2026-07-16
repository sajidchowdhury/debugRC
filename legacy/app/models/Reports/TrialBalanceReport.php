<?php
// app/models/Reports/TrialBalanceReport.php

require_once __DIR__ . '/../../helpers/JournalReportHelper.php';

class TrialBalanceReport
{
    protected $db;

    public function __construct()
    {
        require_once '../core/Database.php';
        $this->db = new Database();
    }

    /**
     * Trial balance with opening, period, and closing columns.
     */
    public function getTrialBalance(
        $fromDate,
        $toDate,
        $accountType = null,
        bool $includeZero = false
    ) {
        $fromDate = $fromDate ?: date('Y-m-01');
        $toDate   = $toDate   ?: date('Y-m-d');

        $sql = "
            SELECT
                l.id AS ledger_id,
                l.ledger_code,
                l.ledger_name,
                l.account_type,
                l.normal_balance,
                l.ledger_nature,
                COALESCE(SUM(CASE WHEN je.entry_date < :from_date THEN jl.debit ELSE 0 END), 0) AS opening_debit,
                COALESCE(SUM(CASE WHEN je.entry_date < :from_date THEN jl.credit ELSE 0 END), 0) AS opening_credit,
                COALESCE(SUM(CASE WHEN je.entry_date BETWEEN :from_date AND :to_date THEN jl.debit ELSE 0 END), 0) AS period_debit,
                COALESCE(SUM(CASE WHEN je.entry_date BETWEEN :from_date AND :to_date THEN jl.credit ELSE 0 END), 0) AS period_credit,
                COALESCE(SUM(CASE WHEN je.entry_date <= :to_date THEN jl.debit ELSE 0 END), 0) AS closing_debit,
                COALESCE(SUM(CASE WHEN je.entry_date <= :to_date THEN jl.credit ELSE 0 END), 0) AS closing_credit
            FROM ledgers l
            LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
            LEFT JOIN journal_entries je
                ON je.id = jl.journal_entry_id
               AND COALESCE(je.is_reversed, 0) = 0
            WHERE l.is_active = 1
        ";

        if ($accountType) {
            $sql .= " AND l.account_type = :account_type";
        }

        $sql .= "
            GROUP BY l.id, l.ledger_code, l.ledger_name, l.account_type, l.normal_balance, l.ledger_nature
            ORDER BY FIELD(l.account_type, 'Asset', 'Liability', 'Equity', 'Income', 'Expense'),
                     l.ledger_name ASC
        ";

        $this->db->query($sql);
        $this->db->bind(':from_date', $fromDate);
        $this->db->bind(':to_date', $toDate);

        if ($accountType) {
            $this->db->bind(':account_type', $accountType);
        }

        $rows = $this->db->resultSet();

        $data = [];
        $grandPeriodDebit  = 0;
        $grandPeriodCredit = 0;

        foreach ($rows as $row) {
            $normalBalance = $row['normal_balance'] ?? 'debit';

            $openingDebit  = (float)$row['opening_debit'];
            $openingCredit = (float)$row['opening_credit'];
            $periodDebit   = (float)$row['period_debit'];
            $periodCredit  = (float)$row['period_credit'];
            $closingDebit  = (float)$row['closing_debit'];
            $closingCredit = (float)$row['closing_credit'];

            $opening = JournalReportHelper::computeBalance($openingDebit, $openingCredit, $normalBalance);
            $closing = JournalReportHelper::computeBalance($closingDebit, $closingCredit, $normalBalance);

            $hasActivity = $periodDebit != 0 || $periodCredit != 0;
            $hasBalance  = $opening['balance'] != 0 || $closing['balance'] != 0;

            if (!$includeZero && !$hasActivity && !$hasBalance) {
                continue;
            }

            $data[] = [
                'ledger_id'       => (int)$row['ledger_id'],
                'ledger_code'     => $row['ledger_code'],
                'ledger_name'     => $row['ledger_name'],
                'account_type'    => $row['account_type'],
                'normal_balance'  => $normalBalance,
                'ledger_nature'   => $row['ledger_nature'],
                'opening_balance' => $opening['balance'],
                'opening_side'    => $opening['balance_side'],
                'debit'           => $periodDebit,
                'credit'          => $periodCredit,
                'closing_balance' => $closing['balance'],
                'closing_side'    => $closing['balance_side'],
                'balance'         => $closing['balance'],
                'balance_side'    => $closing['balance_side'],
                'has_activity'    => $hasActivity,
            ];

            $grandPeriodDebit  += $periodDebit;
            $grandPeriodCredit += $periodCredit;
        }

        return [
            'from_date'         => $fromDate,
            'to_date'           => $toDate,
            'include_zero'      => $includeZero,
            'data'              => $data,
            'grand_debit'       => round($grandPeriodDebit, 2),
            'grand_credit'      => round($grandPeriodCredit, 2),
            'is_balanced'       => round($grandPeriodDebit, 2) === round($grandPeriodCredit, 2),
            'difference'        => round($grandPeriodDebit - $grandPeriodCredit, 2),
        ];
    }

    public function exportToCsv($reportData, $filename = 'Trial_Balance')
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');

        fputcsv($output, ['Trial Balance Report']);
        fputcsv($output, ['Period: ' . $reportData['from_date'] . ' to ' . $reportData['to_date']]);
        fputcsv($output, ['Generated: ' . date('Y-m-d H:i:s')]);
        fputcsv($output, []);

        fputcsv($output, [
            'Code', 'Ledger Name', 'Type',
            'Opening', 'Opening Side',
            'Period Debit', 'Period Credit',
            'Closing', 'Closing Side',
        ]);

        foreach ($reportData['data'] as $row) {
            fputcsv($output, [
                $row['ledger_code'],
                $row['ledger_name'],
                $row['account_type'],
                number_format($row['opening_balance'], 2),
                $row['opening_side'],
                number_format($row['debit'], 2),
                number_format($row['credit'], 2),
                number_format($row['closing_balance'], 2),
                $row['closing_side'],
            ]);
        }

        fputcsv($output, []);
        fputcsv($output, [
            'PERIOD TOTALS', '', '',
            '', '',
            number_format($reportData['grand_debit'], 2),
            number_format($reportData['grand_credit'], 2),
            '',
            $reportData['is_balanced'] ? 'BALANCED' : 'NOT BALANCED',
        ]);

        fclose($output);
        exit;
    }
}

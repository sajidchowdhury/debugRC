<?php
// app/models/Reports/DailyCashBookReport.php

require_once __DIR__ . '/../ReportModel.php';

class DailyCashBookReport extends ReportModel {

    public function getDayBook($from_date, $to_date, $branch_id = null) {
        
        $sql = "
            SELECT 
                section,
                transaction_date,
                reference_code,
                particulars,
                debit,
                credit,
                cash_or_bank
            FROM (
                -- Invoice Wise Payment
                SELECT 
                    'Invoice Wise Payment' as section,
                    si.invoice_date as transaction_date,
                    cp.payment_code as reference_code,
                    CONCAT(c.shop_name, ' - ', si.invoice_code) as particulars,
                    ipa.allocated_amount as debit,
                    0 as credit,
                    cp.payment_mode as cash_or_bank
                FROM invoice_payment_allocations ipa
                JOIN customer_payments cp ON ipa.payment_id = cp.id
                JOIN sales_invoices si ON ipa.invoice_id = si.id
                JOIN customers c ON si.customer_id = c.id
                WHERE si.invoice_date BETWEEN :from_date AND :to_date
                  AND (si.branch_id = :branch_id OR :branch_id IS NULL)

                UNION ALL

                -- Customer Transaction
                SELECT 
                    'Customer Transaction' as section,
                    cp.payment_date,
                    cp.payment_code,
                    CONCAT(c.shop_name, ' - ', COALESCE(cp.remarks, '')) as particulars,
                    cp.amount as debit,
                    0 as credit,
                    cp.payment_mode as cash_or_bank
                FROM customer_payments cp
                JOIN customers c ON cp.customer_id = c.id
                WHERE cp.payment_date BETWEEN :from_date AND :to_date
                  AND cp.is_reversed = 0
                  AND (cp.branch_id = :branch_id OR :branch_id IS NULL)

                UNION ALL

                -- Add Income
                SELECT 
                    'Add Income' as section,
                    oi.income_date,
                    oi.income_code,
                    COALESCE(oi.remarks, 'Other Income') as particulars,
                    oi.amount as debit,
                    0 as credit,
                    oi.payment_mode as cash_or_bank
                FROM other_incomes oi
                WHERE oi.income_date BETWEEN :from_date AND :to_date
                  AND COALESCE(oi.is_reversed, 0) = 0
                  AND (oi.branch_id = :branch_id OR :branch_id IS NULL)

                UNION ALL

                -- Money Transfer
                SELECT 
                    'Money Transfer' as section,
                    mt.transfer_date,
                    mt.transfer_code,
                    CONCAT('Received from ', fb.branch_name) as particulars,
                    mt.amount as debit,
                    0 as credit,
                    mt.transfer_type as cash_or_bank
                FROM money_transfers mt
                JOIN branches fb ON mt.from_branch_id = fb.id
                WHERE mt.transfer_date BETWEEN :from_date AND :to_date
                  AND (mt.to_branch_id = :branch_id OR mt.from_branch_id = :branch_id)

                UNION ALL

                -- Add Expense
                SELECT 
                    'Add Expense' as section,
                    oe.expense_date,
                    CONCAT('OE-', oe.id) as reference_code,
                    CONCAT(COALESCE(l.ledger_name, ''), ' - ', COALESCE(oe.remarks, '')) as particulars,
                    0 as debit,
                    oe.amount as credit,
                    oe.payment_mode as cash_or_bank
                FROM other_expenses oe
                LEFT JOIN ledgers l ON oe.ledger_id = l.id
                WHERE oe.expense_date BETWEEN :from_date AND :to_date
                  AND oe.is_reversed = 0
                  AND (oe.branch_id = :branch_id OR :branch_id IS NULL)

                UNION ALL

                -- Supplier Transaction
                SELECT 
                    'Supplier Transaction' as section,
                    sp.payment_date,
                    sp.payment_code,
                    CONCAT(s.supplier_name, ' - ', COALESCE(sp.remarks, '')) as particulars,
                    0 as debit,
                    sp.amount as credit,
                    sp.payment_mode as cash_or_bank
                FROM supplier_payments sp
                JOIN suppliers s ON sp.supplier_id = s.id
                WHERE sp.payment_date BETWEEN :from_date AND :to_date
                  AND sp.is_reversed = 0
                  AND (sp.branch_id = :branch_id OR :branch_id IS NULL)

            ) AS daybook
            ORDER BY section, transaction_date ASC
        ";

        $this->db->query($sql);
        $this->db->bind(':from_date', $from_date);
        $this->db->bind(':to_date', $to_date);
        if ($branch_id) {
            $this->db->bind(':branch_id', $branch_id);
        }

        $rows = $this->db->resultSet();

        // Calculate Running Balance
        $cashBalance = 0;
        $bankBalance = 0;

        foreach ($rows as &$row) {
            $key = in_array(strtolower($row['cash_or_bank'] ?? ''), ['cash', 'main_cash']) ? 'Cash' : 'Bank';
            if ($key === 'Cash') {
                $cashBalance += ($row['debit'] - $row['credit']);
                $row['running_balance'] = $cashBalance;
            } else {
                $bankBalance += ($row['debit'] - $row['credit']);
                $row['running_balance'] = $bankBalance;
            }
        }

        return [
            'opening_cash' => 0,
            'opening_bank' => 0,
            'transactions' => $rows
        ];
    }
}
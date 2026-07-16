<?php
// app/models/Reports/BranchWiseLedgerReport.php

require_once __DIR__ . '/../ReportModel.php';

class BranchWiseLedgerReport extends ReportModel {

    public function getBranchLedger($from_date, $to_date, $branch_id) {
        
        $sql = "
            SELECT 
                bl.transaction_date,
                bl.reference_type,
                bl.reference_id,
                bl.debit,
                bl.credit,
                bl.running_balance,
                bl.remarks,
                b.branch_name,
                CASE 
                    WHEN bl.to_branch_id = :bid THEN 'Incoming'
                    ELSE 'Outgoing'
                END as direction
            FROM branch_ledger bl
            LEFT JOIN branches b ON b.id = COALESCE(bl.from_branch_id, bl.to_branch_id)
            WHERE bl.transaction_date BETWEEN :from_date AND :to_date
              AND (bl.from_branch_id = :bid OR bl.to_branch_id = :bid)
            ORDER BY bl.transaction_date ASC, bl.id ASC
        ";

        $this->db->query($sql);
        $this->db->bind(':from_date', $from_date);
        $this->db->bind(':to_date',   $to_date);
        $this->db->bind(':bid',       $branch_id);

        return $this->db->resultSet();
    }

  
}
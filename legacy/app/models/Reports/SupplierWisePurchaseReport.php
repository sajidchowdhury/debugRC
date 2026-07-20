<?php
// app/models/Reports/SupplierWisePurchaseReport.php

require_once __DIR__ . '/../ReportModel.php';

class SupplierWisePurchaseReport extends ReportModel {

    public function getSupplierWisePurchase($from_date, $to_date, $branch_ids = [], $supplier_id = null) {
        
        $sql = "
            SELECT 
                s.supplier_code,
                s.supplier_name,
                COUNT(DISTINCT pr.id) as total_grn,
                SUM(pri.qty * pri.rate) as total_purchase_value,
                MAX(pr.receive_date) as last_purchase_date
            FROM purchase_receives pr
            JOIN suppliers s ON s.id = pr.supplier_id
            JOIN purchase_receive_items pri ON pri.purchase_receive_id = pr.id
            WHERE pr.receive_date BETWEEN :from_date AND :to_date
        ";

        $params = [':from_date' => $from_date, ':to_date' => $to_date];

        if (!empty($branch_ids)) {
            $placeholders = [];
            $counter = 1;
            foreach ($branch_ids as $id) {
                $placeholders[] = ":b_$counter";
                $params[":b_$counter"] = $id;
                $counter++;
            }
            $sql .= " AND pr.branch_id IN (" . implode(',', $placeholders) . ")";
        }

        if ($supplier_id) {
            $sql .= " AND pr.supplier_id = :supplier_id";
            $params[':supplier_id'] = $supplier_id;
        }

        $sql .= "
            GROUP BY s.id, s.supplier_code, s.supplier_name
            ORDER BY total_purchase_value DESC
        ";

        $this->db->query($sql);

        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }

        return $this->db->resultSet();
    }

    public function exportSupplierWisePurchase($data) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="Supplier_Wise_Purchase_' . date('Ymd_His') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Supplier Code', 'Supplier Name', 'Total GRN', 'Total Purchase Value', 'Last Purchase']);

        foreach ($data as $row) {
            fputcsv($output, [
                $row['supplier_code'],
                $row['supplier_name'],
                $row['total_grn'],
                $row['total_purchase_value'],
                $row['last_purchase_date']
            ]);
        }
        fclose($output);
        exit;
    }
}
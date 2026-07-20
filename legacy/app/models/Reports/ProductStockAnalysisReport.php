<?php
// app/models/Reports/ProductStockAnalysisReport.php

require_once __DIR__ . '/../ReportModel.php';

class ProductStockAnalysisReport extends ReportModel {

    public function __construct() {
        parent::__construct();
    }

    /**
     * Get Stock Analysis - Optimized for flexible multi-filters + period-consistent closing
     * Supports: categories (multi), products (multi), branches (multi), warehouses (multi), date range
     * Excludes reversed stock tx for accuracy
     * closing_qty calculated from tx log for the selected period (consistent for historical)
     */
    public function getStockAnalysis(
        $from_date, 
        $to_date, 
        $branch_ids = [], 
        $warehouse_ids = [], 
        $category_ids = [], 
        $product_ids = []
    ) {
    
        $params = [
            ':from_date' => $from_date,
            ':to_date'   => $to_date
        ];
        $bindCounter = 1;

        // Build filter clauses for main query and subqueries
        $mainFilters = [];
        $subFilters  = [];  // additional ANDs for stock_transactions subs (qualified)

        // Branch filter (on warehouse via main, for sub use warehouse or skip for perf)
        if (!empty($branch_ids)) {
            $ph = [];
            foreach ($branch_ids as $id) {
                $ph[] = ":branch_$bindCounter";
                $params[":branch_$bindCounter"] = (int)$id;
                $bindCounter++;
            }
            $mainFilters[] = "w.branch_id IN (" . implode(',', $ph) . ")";
            // For sub, we'll add warehouse filter if wh list, else broad but joined
        }

        // Warehouse filter - push to subs for speed
        if (!empty($warehouse_ids)) {
            $ph = [];
            foreach ($warehouse_ids as $id) {
                $ph[] = ":wh_$bindCounter";
                $params[":wh_$bindCounter"] = (int)$id;
                $bindCounter++;
            }
            $whIn = implode(',', $ph);
            $mainFilters[] = "w.id IN ($whIn)";
            $subFilters[]  = "st.warehouse_id IN ($whIn)";
        }

        // Category filter (via product)
        if (!empty($category_ids)) {
            $ph = [];
            foreach ($category_ids as $id) {
                $ph[] = ":cat_$bindCounter";
                $params[":cat_$bindCounter"] = (int)$id;
                $bindCounter++;
            }
            $catIn = implode(',', $ph);
            $mainFilters[] = "p.category_id IN ($catIn)";
            $subFilters[]  = "p.category_id IN ($catIn)";
        }

        // Product filter (multi)
        if (!empty($product_ids)) {
            $ph = [];
            foreach ($product_ids as $id) {
                $ph[] = ":prod_$bindCounter";
                $params[":prod_$bindCounter"] = (int)$id;
                $bindCounter++;
            }
            $prodIn = implode(',', $ph);
            $mainFilters[] = "p.id IN ($prodIn)";
            $subFilters[]  = "p.id IN ($prodIn)";
        }

        $mainWhere = $mainFilters ? ' AND ' . implode(' AND ', $mainFilters) : '';
        $subWhere  = $subFilters  ? ' AND ' . implode(' AND ', $subFilters)  : '';

        $sql = "
            SELECT 
                b.branch_name,
                w.warehouse_name,
                p.product_code,
                p.product_name,
                COALESCE(c.category_name, 'Uncategorized') as category_name,

                COALESCE(opening.opening_qty, 0) as opening_qty,
                COALESCE(movement.receipt_qty, 0) as receipt_qty,
                COALESCE(movement.issue_qty, 0)   as issue_qty,

                -- Period-consistent closing from transaction log (best for date-range analysis)
                (COALESCE(opening.opening_qty, 0) 
                 + COALESCE(movement.receipt_qty, 0) 
                 - COALESCE(movement.issue_qty, 0)) as closing_qty,

                COALESCE(ws.avg_cost, 0) as avg_cost,
                ROUND( 
                    (COALESCE(opening.opening_qty, 0) 
                     + COALESCE(movement.receipt_qty, 0) 
                     - COALESCE(movement.issue_qty, 0)) 
                    * COALESCE(ws.avg_cost, 0) , 2
                ) as closing_value,

                COALESCE(ws.qty, 0) as current_stock_qty   -- for reference / variance detection

            FROM warehouse_stock ws
            JOIN warehouses w ON w.id = ws.warehouse_id
            JOIN branches b ON b.id = w.branch_id
            JOIN products p ON p.id = ws.product_id
            LEFT JOIN product_categories c ON c.id = p.category_id

            -- Opening (pre-period, non-reversed)
            LEFT JOIN (
                SELECT st.product_id, st.warehouse_id, SUM(st.qty) as opening_qty 
                FROM stock_transactions st
                JOIN products p ON p.id = st.product_id
                WHERE st.transaction_date < :from_date 
                  AND COALESCE(st.is_reversed, 0) = 0
                  $subWhere
                GROUP BY st.product_id, st.warehouse_id
            ) opening ON opening.product_id = ws.product_id 
                     AND opening.warehouse_id = ws.warehouse_id

            -- Period movement (non-reversed)
            LEFT JOIN (
                SELECT st.product_id, st.warehouse_id,
                       SUM(CASE WHEN st.qty > 0 THEN st.qty ELSE 0 END) as receipt_qty,
                       SUM(CASE WHEN st.qty < 0 THEN ABS(st.qty) ELSE 0 END) as issue_qty
                FROM stock_transactions st
                JOIN products p ON p.id = st.product_id
                WHERE st.transaction_date BETWEEN :from_date AND :to_date
                  AND COALESCE(st.is_reversed, 0) = 0
                  $subWhere
                GROUP BY st.product_id, st.warehouse_id
            ) movement ON movement.product_id = ws.product_id 
                      AND movement.warehouse_id = ws.warehouse_id

            WHERE 1=1 
              $mainWhere
            ORDER BY b.branch_name, w.warehouse_name, c.category_name, p.product_code
        ";

        $this->db->query($sql);

        foreach ($params as $key => $value) {
            $this->db->bind($key, $value);
        }

        return $this->db->resultSet();
    }

   
    /**
     * Export to CSV (Premium reports usually offer Excel/PDF too)
     */
    public function exportStockAnalysis($data, $from_date, $to_date) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="Product_Stock_Analysis_' . date('Ymd_His') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        fputcsv($output, [
            'Branch', 'Warehouse', 'Category', 'Product Code', 'Product Name', 
            'Opening Qty', 'Receipt Qty', 'Issue Qty', 'Closing Qty (Period)', 
            'Avg Cost', 'Closing Value', 'Current Stock (Now)'
        ]);

        foreach ($data as $row) {
            fputcsv($output, [
                $row['branch_name'] ?? '',
                $row['warehouse_name'] ?? '',
                $row['category_name'] ?? '',
                $row['product_code'] ?? '',
                $row['product_name'] ?? '',
                $row['opening_qty'] ?? 0,
                $row['receipt_qty'] ?? 0,
                $row['issue_qty'] ?? 0,
                $row['closing_qty'] ?? 0,
                $row['avg_cost'] ?? 0,
                $row['closing_value'] ?? 0,
                $row['current_stock_qty'] ?? 0
            ]);
        }
        fclose($output);
        exit;
    }
}
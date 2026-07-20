<?php
// app/models/Reports/ProductMovementReport.php

require_once __DIR__ . '/../../helpers/Helper.php';

class ProductMovementReport extends Helper {

    /**
     * Product Movement Report - built from canonical stock_transactions (which records every movement
     * from purchase_items, purchase_return_items, sales/challans/dispatches, sales_return_items,
     * damage_invoice_items, stock_adjustment_items, stock_take_items (variances), warehouse_transfer_items,
     * branch_demand_items, and all reversals).
     *
     * Supports multi-product, multi-category, multi-branch, multi-warehouse + date range.
     * Enriched with document codes by joining source headers (purchase_receives, sales_challans, etc.)
     * so you see "in which invoice / document".
     * Proper opening (pre-period from log), IN/OUT, running balance per (product+warehouse).
     * Closing balance rows at end of list + reconciliation section to prove final balance == current stock.
     *
     * The "explanation" (why it matches or not) can be the reconciliation at bottom or a future "Explain" button.
     */
    public function getProductMovementWithBalance(
        $from_date,
        $to_date,
        $product_ids = [],
        $branch_ids = [],
        $warehouse_ids = [],
        $category_ids = [],
        $include_recon = true   // set false for main report load when data is large; explanation loads on button click
    ) {
        $product_ids = array_filter(array_map('intval', (array)$product_ids));
        $category_ids = array_filter(array_map('intval', (array)$category_ids));
        $branch_ids = array_filter(array_map('intval', (array)$branch_ids));
        $warehouse_ids = array_filter(array_map('intval', (array)$warehouse_ids));

        $hasProductFilter = !empty($product_ids);
        $hasCategoryFilter = !empty($category_ids);

        if (!$hasProductFilter && !$hasCategoryFilter) {
            // To avoid huge report, require at least product or category filter
            return ['rows' => [], 'reconciliation' => [], 'totals' => [], 'products' => []];
        }

        $params = [
            ':from' => $from_date,
            ':to'   => $to_date
        ];
        $bindIdx = 0;

        // Product filter
        $prodSql = '';
        $prodSubSql = ''; // for sub queries
        if ($hasProductFilter) {
            $ph = [];
            foreach ($product_ids as $pid) {
                $k = ':p' . $bindIdx++;
                $ph[] = $k;
                $params[$k] = $pid;
            }
            $prodIn = implode(',', $ph);
            $prodSql = " AND st.product_id IN ($prodIn)";
            $prodSubSql = " AND p.id IN ($prodIn)";  // when joined as p
        }

        // Category filter (join products)
        $catSql = '';
        $catSubSql = '';
        if ($hasCategoryFilter) {
            $ph = [];
            foreach ($category_ids as $cid) {
                $k = ':c' . $bindIdx++;
                $ph[] = $k;
                $params[$k] = $cid;
            }
            $catIn = implode(',', $ph);
            $catSql = " AND p.category_id IN ($catIn)";
            $catSubSql = " AND p.category_id IN ($catIn)";
        }

        // Branch / warehouse (same as before)
        $branchSql = '';
        if (!empty($branch_ids)) {
            $ph = [];
            foreach ($branch_ids as $bid) {
                $k = ':b' . $bindIdx++;
                $ph[] = $k;
                $params[$k] = (int)$bid;
            }
            $branchSql = ' AND w.branch_id IN (' . implode(',', $ph) . ')';
        }

        $whSql = '';
        if (!empty($warehouse_ids)) {
            $ph = [];
            foreach ($warehouse_ids as $wid) {
                $k = ':w' . $bindIdx++;
                $ph[] = $k;
                $params[$k] = (int)$wid;
            }
            $whSql = ' AND st.warehouse_id IN (' . implode(',', $ph) . ')';
        }

        // For opening we need slightly different because no st. prefix sometimes
        $openingProdSql = str_replace('st.product_id', 'st.product_id', $prodSql);
        $openingCatJoinAnd = $hasCategoryFilter ? " JOIN products p ON p.id = st.product_id AND p.category_id IN (SELECT id FROM product_categories WHERE id IN (" . implode(',', array_map('intval', $category_ids)) . ")) " : '';

        // 1. Opening balance per (product, warehouse)
        $openingQuery = "
            SELECT st.product_id, st.warehouse_id, SUM(st.qty) as opening_qty
            FROM stock_transactions st
            JOIN warehouses w ON w.id = st.warehouse_id
            $openingCatJoinAnd
            WHERE 1=1
              AND DATE(st.created_at) < :from
              AND COALESCE(st.is_reversed, 0) = 0
              $openingProdSql
              $branchSql
              $whSql
              $catSql
            GROUP BY st.product_id, st.warehouse_id
        ";
        $this->db->query($openingQuery);
        $this->db->bind(':from', $from_date);
        foreach ($params as $k => $v) {
            if (strpos($openingQuery, $k) !== false) $this->db->bind($k, $v);
        }
        $openingRows = $this->db->resultSet() ?: [];
        $openings = []; // key "prod|wh" => qty
        foreach ($openingRows as $o) {
            $key = (int)$o['product_id'] . '|' . (int)$o['warehouse_id'];
            $openings[$key] = (float)$o['opening_qty'];
        }

        // 2. Movements in period - enriched with source data for rich descriptions (premium report)
        $moveQuery = "
            SELECT 
                st.id,
                st.transaction_date,
                st.created_at,
                st.reference_type,
                st.reference_id,
                st.qty,
                st.remarks,
                st.rate,
                st.warehouse_id,
                st.product_id,
                p.product_code,
                p.product_name,
                COALESCE(pc.category_name, 'Uncategorized') as category_name,
                w.warehouse_name,
                b.branch_name,
                COALESCE(u.username, 'System') as created_by_name,
                CASE WHEN st.qty > 0 THEN 'IN' ELSE 'OUT' END as direction,
                ABS(st.qty) as abs_qty,
                -- Enriched document codes 
                COALESCE(
                    pr.receive_code,
                    prt.return_code,
                    sc.challan_code,
                    sr.return_code,
                    di.damage_code,
                    sa.adjustment_code,
                    sts.session_code,
                    wt.transfer_code,
                    bd.demand_code,
                    st.reference_type
                ) as document_code,
                CASE 
                    WHEN st.reference_type = 'purchase_receive' THEN 'Purchase Receive'
                    WHEN st.reference_type = 'purchase_return' THEN 'Purchase Return'
                    WHEN st.reference_type LIKE '%sales_challan%' THEN 'Sales Challan/Invoice'
                    WHEN st.reference_type LIKE '%sales_return%' THEN 'Sales Return'
                    WHEN st.reference_type = 'damage' THEN 'Damage'
                    WHEN st.reference_type IN ('adjustment','stock_adjustment') THEN 'Stock Adjustment'
                    WHEN st.reference_type = 'stock_take' THEN 'Physical Stock Take (Variance)'
                    WHEN st.reference_type = 'warehouse_transfer' THEN 'Warehouse Transfer'
                    WHEN st.reference_type LIKE '%branch_demand%' THEN 'Branch/Inter-branch Movement'
                    WHEN st.reference_type = 'reversal' OR st.reference_type LIKE '%reversal%' THEN 'Reversal'
                    ELSE st.reference_type
                END as movement_label,
                COALESCE(cust.customer_name, '') as customer_name,
                COALESCE(sup_receive.supplier_name, sup_return.supplier_name, '') as supplier_name
            FROM stock_transactions st
            JOIN products p ON st.product_id = p.id
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            JOIN warehouses w ON st.warehouse_id = w.id
            JOIN branches b ON w.branch_id = b.id
            LEFT JOIN users u ON st.created_by = u.id

            -- Source enrichment for rich descriptions (purchase items, sales, etc.)
            LEFT JOIN purchase_receives pr 
                ON st.reference_type = 'purchase_receive' AND pr.id = st.reference_id
            LEFT JOIN purchase_returns prt 
                ON st.reference_type = 'purchase_return' AND prt.id = st.reference_id
            LEFT JOIN sales_challans sc 
                ON st.reference_type LIKE '%sales_challan%' AND sc.id = st.reference_id
            LEFT JOIN sales_returns sr 
                ON st.reference_type LIKE '%sales_return%' AND sr.id = st.reference_id
            LEFT JOIN damage_invoices di 
                ON st.reference_type = 'damage' AND di.id = st.reference_id
            LEFT JOIN stock_adjustments sa 
                ON (st.reference_type IN ('adjustment','stock_adjustment')) AND sa.id = st.reference_id
            LEFT JOIN stock_take_sessions sts 
                ON st.reference_type = 'stock_take' AND sts.id = st.reference_id
            LEFT JOIN warehouse_transfers wt 
                ON st.reference_type = 'warehouse_transfer' AND wt.id = st.reference_id
            LEFT JOIN branch_demands bd 
                ON (st.reference_type LIKE '%branch_demand%' OR st.reference_type = 'branch_demand') AND bd.id = st.reference_id

            -- Additional for rich desc
            LEFT JOIN sales_invoices si ON si.id = sc.sales_invoice_id
            LEFT JOIN customers cust ON cust.id = si.customer_id
            LEFT JOIN suppliers sup_receive ON st.reference_type = 'purchase_receive' AND sup_receive.id = pr.supplier_id
            LEFT JOIN suppliers sup_return ON st.reference_type = 'purchase_return' AND sup_return.id = prt.supplier_id

            WHERE 1=1
              AND DATE(st.created_at) BETWEEN :from AND :to
              AND COALESCE(st.is_reversed, 0) = 0
              $prodSql
              $catSql
              $branchSql
              $whSql
            ORDER BY st.created_at ASC, st.id ASC
        ";
        $this->db->query($moveQuery);
        $this->db->bind(':from', $from_date);
        $this->db->bind(':to', $to_date);
        foreach ($params as $k => $v) {
            if (strpos($moveQuery, $k) !== false) $this->db->bind($k, $v);
        }
        $movements = $this->db->resultSet() ?: [];

        // Enrich with rich, informative descriptions for premium report (like old Excel style)
        foreach ($movements as &$m) {
            $m['description'] = $this->buildRichDescription($m);
            $m['display_date'] = !empty($m['created_at']) ? date('Y-m-d H:i', strtotime($m['created_at'])) : ($m['transaction_date'] ?? '');
        }
        unset($m);

        // 3. Build result set. Multi-product aware: running keys are "product_id|warehouse_id"
        $result = [];
        $currentBal = $openings; // keys are "pid|wid"

        $whDetails = [];
        $productDetails = [];
        foreach ($movements as $m) {
            $pid = (int)($m['product_id'] ?? 0);
            $wid = (int)$m['warehouse_id'];
            if (!isset($whDetails[$wid])) {
                $whDetails[$wid] = ['branch_name' => $m['branch_name'], 'warehouse_name' => $m['warehouse_name']];
            }
            if ($pid && !isset($productDetails[$pid])) {
                $productDetails[$pid] = [
                    'product_code' => $m['product_code'],
                    'product_name' => $m['product_name'],
                    'category_name' => $m['category_name'] ?? ''
                ];
            }
        }
        foreach (array_keys($openings) as $key) {
            list($pid, $wid) = array_pad(explode('|', $key), 2, 0);
            $pid = (int)$pid; $wid = (int)$wid;
            if ($wid && !isset($whDetails[$wid])) {
                $whDetails[$wid] = ['branch_name' => 'N/A', 'warehouse_name' => 'Wh#' . $wid];
            }
            if ($pid && !isset($productDetails[$pid])) {
                $this->db->query("SELECT p.product_code, p.product_name, COALESCE(pc.category_name,'Uncategorized') as category_name FROM products p LEFT JOIN product_categories pc ON pc.id=p.category_id WHERE p.id=:pid");
                $this->db->bind(':pid', $pid);
                if ($pp = $this->db->single()) $productDetails[$pid] = $pp;
            }
        }

        // Period sums
        $periodIn = 0.0; $periodOut = 0.0;
        foreach ($movements as $m) {
            $q = (float)$m['qty'];
            if ($q > 0) $periodIn += $q; else $periodOut += abs($q);
        }

        // OPENING rows
        foreach ($openings as $key => $opQty) {
            if (abs($opQty) < 0.0001) continue;
            list($pid, $wid) = array_pad(explode('|', $key), 2, 0);
            $pid = (int)$pid; $wid = (int)$wid;
            $pdet = $productDetails[$pid] ?? ['product_code'=>'', 'product_name'=>'', 'category_name'=>''];
            $wdet = $whDetails[$wid] ?? ['branch_name'=>'', 'warehouse_name'=>''];
            $result[] = [
                'id' => 0, 'transaction_date' => $from_date, 'created_at' => null, 'display_date' => $from_date,
                'reference_type' => 'OPENING BALANCE',
                'reference_id' => '', 'qty' => 0, 'abs_qty' => 0,
                'remarks' => 'Balance carried forward as of ' . $from_date,
                'warehouse_id' => $wid, 'branch_name' => $wdet['branch_name'], 'warehouse_name' => $wdet['warehouse_name'],
                'product_id' => $pid, 'product_code' => $pdet['product_code'], 'product_name' => $pdet['product_name'],
                'category_name' => $pdet['category_name'], 'created_by_name' => '',
                'direction' => ($opQty >= 0 ? 'IN' : 'OUT'), 'running_balance' => $opQty,
                'is_opening' => true, 'is_closing' => false, 'document_code' => '', 'movement_label' => 'Opening',
                'description' => 'Opening Balance as of ' . $from_date . ' for ' . $pdet['product_name'] . ' in ' . $wdet['warehouse_name']
            ];
        }

        // Period movements + running (composite)
        foreach ($movements as $m) {
            $pid = (int)($m['product_id'] ?? 0);
            $wid = (int)$m['warehouse_id'];
            $key = $pid . '|' . $wid;
            $currentBal[$key] = ($currentBal[$key] ?? 0) + (float)$m['qty'];
            $m['running_balance'] = $currentBal[$key];
            $m['is_opening'] = false;
            $m['is_closing'] = false;
            $m['product_id'] = $pid;
            $m['category_name'] = $m['category_name'] ?? '';
            $result[] = $m;
        }

        // NOTE: We intentionally do NOT add per-warehouse "CLOSING BALANCE" rows here anymore.
        // The main table will show Opening(s) + transactions only.
        // A single "Final Closing Balance" summary will be added in the VIEW at the very bottom of the table.
        // This avoids repeated green closing rows. The recon/explain section (via button) will still show detailed per-wh computed vs live.

        // Current stock + Reconciliation ONLY if $include_recon (lazy loaded via button at bottom for large reports)
        $reconciliation = [];
        $currentTotal = 0;
        $endComp = 0; foreach ($currentBal as $v) $endComp += $v;

        if ($include_recon) {
            $cidx = 0;
            $curParams = [];
            $curProdSql = $hasProductFilter ? (' AND ws.product_id IN (' . implode(',', array_map(function($pid) use (&$cidx, &$curParams) { $k=':cpr'.$cidx++; $curParams[$k]=$pid; return $k; }, $product_ids)) . ')') : '';
            $curCatSql = '';
            if ($hasCategoryFilter) {
                $ph = [];
                foreach ($category_ids as $cid) { $k = ':ccat' . $cidx++; $ph[] = $k; $curParams[$k] = $cid; }
                $curCatSql = ' AND p.category_id IN (' . implode(',', $ph) . ')';
            }
            $curB = $curW = '';
            if (!empty($branch_ids)) {
                $ph = [];
                foreach ($branch_ids as $bid) { $k=':cbr'.$cidx++; $ph[]=$k; $curParams[$k]=$bid; }
                $curB = ' AND w.branch_id IN (' . implode(',', $ph) . ')';
            }
            if (!empty($warehouse_ids)) {
                $ph = [];
                foreach ($warehouse_ids as $wid) { $k=':cwh'.$cidx++; $ph[]=$k; $curParams[$k]=$wid; }
                $curW = ' AND ws.warehouse_id IN (' . implode(',', $ph) . ')';
            }

            $currentQuery = "
                SELECT ws.product_id, ws.warehouse_id, COALESCE(ws.qty,0) as current_qty,
                       w.warehouse_name, b.branch_name, p.product_code, p.product_name
                FROM warehouse_stock ws
                JOIN warehouses w ON w.id = ws.warehouse_id
                JOIN branches b ON b.id = w.branch_id
                JOIN products p ON p.id = ws.product_id
                WHERE 1=1 $curProdSql $curCatSql $curB $curW
                ORDER BY b.branch_name, w.warehouse_name, p.product_code
            ";
            $this->db->query($currentQuery);
            foreach ($curParams as $k => $v) { if (strpos($currentQuery, $k) !== false) $this->db->bind($k, $v); }
            $currentRows = $this->db->resultSet() ?: [];
            $currentStocks = [];
            foreach ($currentRows as $r) {
                $key = (int)$r['product_id'] . '|' . (int)$r['warehouse_id'];
                $currentStocks[$key] = (float)$r['current_qty'];
                $wid = (int)$r['warehouse_id'];
                if (!isset($whDetails[$wid])) $whDetails[$wid] = ['branch_name'=>$r['branch_name'], 'warehouse_name'=>$r['warehouse_name']];
                $pidr = (int)$r['product_id'];
                if (!isset($productDetails[$pidr])) {
                    $productDetails[$pidr] = ['product_code'=>$r['product_code'], 'product_name'=>$r['product_name'], 'category_name'=>''];
                }
            }

            $allKeys = array_unique(array_merge(array_keys($currentBal), array_keys($currentStocks)));
            foreach ($allKeys as $key) {
                $computed = $currentBal[$key] ?? 0.0;
                $curv = $currentStocks[$key] ?? 0.0;
                list($pid, $wid) = array_pad(explode('|', $key), 2, 0);
                $pid = (int)$pid; $wid = (int)$wid;
                $pdet = $productDetails[$pid] ?? ['product_code'=>'P'.$pid,'product_name'=>'','category_name'=>''];
                $wdet = $whDetails[$wid] ?? ['branch_name'=>'N/A','warehouse_name'=>'Wh#'.$wid];
                $reconciliation[] = [
                    'product_id' => $pid, 'product_code' => $pdet['product_code'], 'product_name' => $pdet['product_name'],
                    'warehouse_id' => $wid, 'branch_name' => $wdet['branch_name'], 'warehouse_name' => $wdet['warehouse_name'],
                    'computed_ending' => $computed, 'current_stock' => $curv,
                    'diff' => $curv - $computed, 'matches' => (abs($curv - $computed) < 0.01)
                ];
            }
            foreach ($currentStocks as $v) $currentTotal += $v;
        }

        return [
            'rows' => $result,
            'reconciliation' => $reconciliation,
            'products' => $productDetails,
            'from_date' => $from_date,
            'to_date' => $to_date,
            'totals' => [
                'period_in' => $periodIn, 'period_out' => $periodOut, 'period_net' => $periodIn - $periodOut,
                'ending_computed' => $endComp, 'current_total' => $currentTotal,
                'current_vs_computed_diff' => $currentTotal - $endComp, 'row_count' => count($result)
            ]
        ];
    }

    /**
     * Build rich, user-friendly description for each movement row, matching old software style.
     */
    private function buildRichDescription($row)
    {
        $type = $row['reference_type'] ?? '';
        $doc = $row['document_code'] ?? ($type . ' #' . ($row['reference_id'] ?? ''));
        $remarks = trim($row['remarks'] ?? '');
        $supplier = $row['supplier_name'] ?? '';
        $customer = $row['customer_name'] ?? '';
        $rate = isset($row['rate']) && $row['rate'] > 0 ? number_format($row['rate'], 2) : '';
        $wh = $row['warehouse_name'] ?? '';
        $branch = $row['branch_name'] ?? '';
        $prod = ($row['product_code'] ?? '') . ' - ' . ($row['product_name'] ?? '');

        if (strpos($type, 'purchase_receive') !== false || $type === 'purchase_receive') {
            $desc = "Purchase Receive >> " . $doc;
            if ($supplier) $desc .= " from " . $supplier;
            if ($rate) $desc .= " @ Rate " . $rate;
            if ($remarks) $desc .= " (" . $remarks . ")";
            return $desc;
        }
        if (strpos($type, 'purchase_return') !== false || $type === 'purchase_return') {
            $desc = "Purchase Return >> " . $doc;
            if ($supplier) $desc .= " to " . $supplier;
            if ($remarks) $desc .= " (" . $remarks . ")";
            return $desc;
        }
        if (strpos($type, 'sales_challan') !== false || $type === 'sales_challan') {
            $desc = "Sales >> ";
            if ($customer) $desc .= $customer . " >> ";
            if ($branch) $desc .= $branch . " >> ";
            if ($rate) $desc .= "S.Price " . $rate;
            if ($remarks) $desc .= " - " . $remarks;
            return $desc;
        }
        if (strpos($type, 'sales_return') !== false || $type === 'sales_return') {
            $desc = "Sales Return >> " . $doc;
            if ($customer) $desc .= " from " . $customer;
            if ($remarks) $desc .= " (" . $remarks . ")";
            return $desc;
        }
        if ($type === 'damage') {
            $desc = "Damage / Loss on " . $wh;
            if ($doc) $desc .= " >> " . $doc;
            if ($remarks) $desc .= " - " . $remarks;
            return $desc;
        }
        if ($type === 'stock_adjustment' || $type === 'adjustment') {
            $desc = "Stock Adjustment on " . $wh . " >> " . $doc;
            if ($remarks) $desc .= " (" . $remarks . ")";
            return $desc;
        }
        if ($type === 'stock_take') {
            return "Physical Stock Count / Reconciliation >> " . $doc . " @ " . $wh . ($remarks ? " - " . $remarks : "");
        }
        if ($type === 'warehouse_transfer') {
            $dir = ($row['qty'] > 0 ? "In from" : "Out to");
            return "Warehouse Transfer " . $doc . " " . $dir . " " . $wh . ($remarks ? " (" . $remarks . ")" : "");
        }
        if (strpos($type, 'branch_demand') !== false || $type === 'demand_send' || $type === 'demand_receive') {
            $desc = "Branch-to-Branch Transfer / Demand " . $doc;
            if ($branch) $desc .= " >> " . $branch;
            if ($remarks) $desc .= " - " . $remarks;
            return $desc;
        }
        if (strpos($type, 'reversal') !== false || $type === 'reversal') {
            return "Reversal Entry >> " . $doc . ($remarks ? " Reason: " . $remarks : "");
        }
        // default
        $label = $row['movement_label'] ?? ucfirst(str_replace('_', ' ', $type));
        $desc = $label . " >> " . $doc . " @ " . $wh;
        if ($remarks) $desc .= " (" . $remarks . ")";
        return $desc;
    }

    // Export - supports multi-product, enriched document_code, CLOSING rows + recon
    public function exportProductMovement($data, $from_date, $to_date)
    {
        $rows = is_array($data) && isset($data['rows']) ? $data['rows'] : (is_array($data) ? $data : []);
        $recon = is_array($data) && isset($data['reconciliation']) ? $data['reconciliation'] : [];
        $totals = is_array($data) && isset($data['totals']) ? $data['totals'] : [];
        $prods = is_array($data) && isset($data['products']) ? $data['products'] : [];

        if (empty($rows) && empty($recon)) {
            $_SESSION['error'] = "No movement or stock data found for the selected filters!";
            header("Location: " . (defined('BASE_URL') ? BASE_URL : '') . "Report/ProductMovement?from_date=$from_date&to_date=$to_date");
            exit;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Stock_Movement_Report_' . date('Y-m-d_H-i') . '.csv"');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Premium header like Excel
        fputcsv($output, ['STOCK MOVEMENT REPORT (Stock Ledger)']);
        fputcsv($output, ['Generated: ' . date('Y-m-d H:i:s')]);
        fputcsv($output, ['From: ' . $from_date . ' To: ' . $to_date]);
        fputcsv($output, []); // blank

        if (!empty($rows)) {
            fputcsv($output, ['SI', 'Date & Time', 'User', 'Description (Rich Source Details)', 'In Qty', 'Out Qty', 'Closing Stock', 'Reference', 'Product']);

            $si = 1;
            foreach ($rows as $row) {
                $isOpen = !empty($row['is_opening']);
                $isClose = !empty($row['is_closing']);
                $qty = (float)($row['qty'] ?? 0);
                $abs = (float)($row['abs_qty'] ?? abs($qty));
                $run = (float)($row['running_balance'] ?? 0);

                if ($isOpen) {
                    $in = ($run > 0 ? number_format($run, 2) : '');
                    $out = ($run < 0 ? number_format(abs($run), 2) : '');
                } elseif ($isClose) {
                    $in = ''; $out = '';
                } else {
                    $in = ($qty > 0 ? number_format($abs, 2) : '');
                    $out = ($qty < 0 ? number_format($abs, 2) : '');
                }

                $desc = $row['description'] ?? ($row['document_code'] ?? '');
                $prodLabel = ($row['product_code'] ?? '') . ' - ' . ($row['product_name'] ?? '');
                $ref = $row['document_code'] ?? (($row['reference_type'] ?? '') . ' #' . ($row['reference_id'] ?? ''));
                $dtime = $row['display_date'] ?? $row['transaction_date'] ?? '';

                fputcsv($output, [
                    $si++,
                    $dtime,
                    $row['created_by_name'] ?? 'System',
                    $desc,
                    $in,
                    $out,
                    number_format($run, 2),
                    $ref,
                    $prodLabel
                ]);
            }
        }

        // Reconciliation (the explanation part) in CSV
        if (!empty($recon)) {
            fputcsv($output, []);
            fputcsv($output, ['=== STOCK VALIDITY RECONCILIATION (end of list balance must match live stock) ===']);
            fputcsv($output, ['Product', 'Branch', 'Warehouse', 'Computed Ending (movements)', 'Current Stock (live)', 'Diff', 'Valid?']);
            foreach ($recon as $r) {
                $pl = ($r['product_code'] ?? '') . ' - ' . ($r['product_name'] ?? '');
                fputcsv($output, [
                    $pl,
                    $r['branch_name'] ?? '',
                    $r['warehouse_name'] ?? '',
                    number_format($r['computed_ending'] ?? 0, 2),
                    number_format($r['current_stock'] ?? 0, 2),
                    number_format($r['diff'] ?? 0, 2),
                    !empty($r['matches']) ? 'YES - STOCK VALID' : 'NO - MISMATCH (see explanation)'
                ]);
            }
            if (!empty($totals)) {
                fputcsv($output, []);
                fputcsv($output, ['SUMMARY', 'Period In', number_format($totals['period_in'] ?? 0, 2)]);
                fputcsv($output, ['', 'Period Out', number_format($totals['period_out'] ?? 0, 2)]);
                fputcsv($output, ['', 'Net Movement', number_format($totals['period_net'] ?? 0, 2)]);
                fputcsv($output, ['', 'Final Computed (from movements)', number_format($totals['ending_computed'] ?? 0, 2)]);
                fputcsv($output, ['', 'Live Current Stock Total', number_format($totals['current_total'] ?? 0, 2)]);
                fputcsv($output, ['', 'Overall Diff', number_format($totals['current_vs_computed_diff'] ?? 0, 2)]);
            }
        }

        fclose($output);
        exit;
    }
}
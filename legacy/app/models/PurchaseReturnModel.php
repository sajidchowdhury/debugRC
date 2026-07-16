<?php
// app/models/PurchaseReturnModel.php

require_once '../core/Database.php';
require_once 'StockTransactionModel.php';
require_once __DIR__ . '/../helpers/Helper.php';
require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';

class PurchaseReturnModel {

    protected $db;
    protected $stockTransaction;
    protected $helper;

    public function __construct() {
        $this->db = new Database();
        $this->stockTransaction = new StockTransactionModel($this->db);
        $this->helper = new Helper();
    }

    // ===================== Existing Methods (Unchanged) =====================

   public function searchReceives($term) {
        $branchId = Helper::sessionBranchId();
        $this->db->query("
            SELECT pr.id, pr.receive_code, pr.total_amount, s.supplier_name
            FROM purchase_receives pr
            JOIN suppliers s ON pr.supplier_id = s.id
            WHERE (pr.receive_code LIKE :term OR s.supplier_name LIKE :term)
              AND pr.branch_id = :branch_id
              AND pr.status = 'received'
              AND COALESCE(pr.is_reversed, 0) = 0
              AND EXISTS (
                  SELECT 1
                  FROM purchase_receive_items pri
                  WHERE pri.purchase_receive_id = pr.id
                    AND pri.qty > COALESCE((
                        SELECT SUM(pri2.return_qty)
                        FROM purchase_return_items pri2
                        WHERE pri2.purchase_receive_item_id = pri.id
                    ), 0)
              )
            ORDER BY pr.receive_date DESC
            LIMIT 10
        ");
        $this->db->bind(':term', "%$term%");
        $this->db->bind(':branch_id', $branchId);
        return $this->db->resultSet();
    }
    
    /**
     * Load GRN for purchase return workspace.
     *
     * Stock SSOT (read): warehouse_stock via Helper::Get_Warehouse_Wise_Product_Stock()
     *   → available_qty for warehouse picker (physical qty minus sales dispatch soft-holds).
     *
     * GRN returnable_qty is NOT on-hand stock — it is received_qty minus already returned
     * to supplier on that GRN line. Return qty must respect both limits on Good lines.
     */
    public function getReceiveForReturn($receive_code) {
    $this->db->query("
        SELECT pr.*, s.supplier_name, s.mobile, b.id as branch_id
        FROM purchase_receives pr
        JOIN suppliers s ON pr.supplier_id = s.id
        JOIN branches b ON pr.branch_id = b.id
        WHERE pr.receive_code = :code
          AND pr.branch_id = :branch_id
          AND pr.status = 'received'
          AND COALESCE(pr.is_reversed, 0) = 0
    ");
    $this->db->bind(':code', $receive_code);
    $this->db->bind(':branch_id', Helper::sessionBranchId());
    $receive = $this->db->single();

    if ($receive) {
        $this->db->query("
            SELECT pri.id as purchase_receive_item_id, 
                   pri.product_id, 
                   pri.warehouse_id as original_warehouse_id,
                   pri.qty as received_qty, 
                   pri.rate,
                   p.product_name, 
                   p.product_code, 
                   p.unit,
                   COALESCE(pri.qty - (SELECT COALESCE(SUM(return_qty),0) 
                                      FROM purchase_return_items 
                                      WHERE purchase_receive_item_id = pri.id), 0) as returnable_qty
            FROM purchase_receive_items pri
            JOIN products p ON pri.product_id = p.id
            WHERE pri.purchase_receive_id = :rid
        ");
        $this->db->bind(':rid', $receive['id']);
        $receive['items'] = $this->db->resultSet();
        
        // === Get Warehouse-wise Stock for each product ===
        foreach ($receive['items'] as &$item) {
            $stockData =  $this->helper->Get_Warehouse_Wise_Product_Stock(
                $item['product_id'], 
                $receive['branch_id']
            );
            $item['warehouses'] = $stockData;
        }

        // Also keep all warehouses for the branch (backup)
        $this->db->query("
            SELECT id, warehouse_name 
            FROM warehouses 
            WHERE branch_id = :bid AND is_active = 1
            ORDER BY warehouse_name
        ");
        $this->db->bind(':bid', $receive['branch_id']);
        $receive['all_warehouses'] = $this->db->resultSet();
    }

    return $receive;
}
    
    public function getAllReturns() {
        $this->db->query("
            SELECT prt.*, pr.receive_code, s.supplier_name, u.username as created_by_name
            FROM purchase_returns prt
            JOIN purchase_receives pr ON prt.purchase_receive_id = pr.id
            JOIN suppliers s ON prt.supplier_id = s.id
            LEFT JOIN users u ON prt.created_by = u.id
            ORDER BY prt.id DESC
        ");
        return $this->db->resultSet();
    }
    
    
public function getReturnForSlip($id) {
        $this->db->query("
            SELECT prt.*, pr.receive_code, s.supplier_name, s.mobile,
                   b.branch_name, u.username as created_by_name
            FROM purchase_returns prt
            JOIN purchase_receives pr ON prt.purchase_receive_id = pr.id
            JOIN suppliers s ON prt.supplier_id = s.id
            JOIN branches b ON prt.branch_id = b.id
            LEFT JOIN users u ON prt.created_by = u.id
            WHERE prt.id = :id
        ");
        $this->db->bind(':id', $id);
        $return = $this->db->single();

        if ($return) {
            $this->db->query("
                SELECT pri.*, p.product_name, p.product_code, w.warehouse_name
                FROM purchase_return_items pri
                JOIN products p ON pri.product_id = p.id
                JOIN warehouses w ON pri.warehouse_id = w.id
                WHERE pri.purchase_return_id = :id
            ");
            $this->db->bind(':id', $id);
            $return['items'] = $this->db->resultSet();
        }
        return $return;
    }
   
   /**
     * ACCOUNTING DECISION RECORD (June 2026)
     *
     * On Purchase Return, we will reverse the AP liability.
     * Future posting:
     *   Dr Supplier Payable (control account)
     *   Cr Inventory (at current moving average cost)
     */
    public function createReturn($data, $items) {
    $this->db->beginTransaction();
    try {
        $return_code = "PR-" . date('Ymd') . "-" . str_pad(rand(1000,9999), 4, '0', STR_PAD_LEFT);

        // Insert Return Header
        $this->db->query("
            INSERT INTO purchase_returns 
            (return_code, purchase_receive_id, supplier_id, branch_id, return_date, 
             total_amount, reason, created_by, journal_entry_id)
            VALUES (:code, :receive_id, :supplier_id, :branch_id, :return_date, 
                    :total_amount, :reason, :created_by, :journal_entry_id)
        ");

        $this->db->bind(':code', $return_code);
        $this->db->bind(':receive_id', $data['purchase_receive_id']);
        $this->db->bind(':supplier_id', $data['supplier_id']);
        $this->db->bind(':branch_id', $_SESSION['branch_id'] ?? 1);
        $this->db->bind(':return_date', $data['return_date'] ?? date('Y-m-d'));
        $this->db->bind(':total_amount', $data['total_amount'] ?? 0);
        $this->db->bind(':reason', $data['reason'] ?? '');
        $this->db->bind(':created_by', $_SESSION['user_id'] ?? 1);
        $this->db->bind(':journal_entry_id', $data['journal_entry_id'] ?? null);

        $this->db->execute();
        $return_id = $this->db->lastInsertId();

        foreach ($items as $item) {
            $return_qty = (float)($item['return_qty'] ?? 0);
            if ($return_qty <= 0) continue;

            // Prevent over-returning (Phase 2 improvement) - use stored returned_qty (added via migration 004 + runtime fix)
            $this->db->query("
                SELECT (qty - COALESCE(returned_qty, 0)) as max_returnable 
                FROM purchase_receive_items 
                WHERE id = :item_id
            ");
            $this->db->bind(':item_id', $item['purchase_receive_item_id']);
            $row = $this->db->single();
            $maxReturnable = $row ? (float)$row['max_returnable'] : 0;

            if ($return_qty > $maxReturnable + 0.0001) {
                throw new Exception(
                    "Return qty exceeds GRN returnable ({$maxReturnable} remaining to supplier on this line)."
                );
            }

            $warehouse_id = (int)$item['warehouse_id'];
            $product_id = (int)$item['product_id'];
            $condition = strtolower($item['condition'] ?? 'good');

            if ($condition === 'good') {
                $this->assertWarehouseAvailableForReturn(
                    $warehouse_id,
                    $product_id,
                    $return_qty,
                    (int)($data['branch_id'] ?? $_SESSION['branch_id'] ?? 0)
                );
            }

            // Insert Return Item
            $this->db->query("
                INSERT INTO purchase_return_items 
                (purchase_return_id, purchase_receive_item_id, product_id, warehouse_id, 
                 return_qty, rate, `condition`)
                VALUES (:rid, :pri, :pid, :wid, :rqty, :rate, :cond)
            ");

            $this->db->bind(':rid', $return_id);
            $this->db->bind(':pri', $item['purchase_receive_item_id']);
            $this->db->bind(':pid', $item['product_id']);
            $this->db->bind(':wid', $warehouse_id);
            $this->db->bind(':rqty', $return_qty);
            $this->db->bind(':rate', $item['rate']);
            $this->db->bind(':cond', $item['condition'] ?? 'Good');
            $this->db->execute();

            // Update cumulative returned quantity on the original receive item (Phase 2)
            // Use returned_qty column (not the original qty)
            $this->db->query("
                UPDATE purchase_receive_items 
                SET returned_qty = COALESCE(returned_qty, 0) + :rqty 
                WHERE id = :item_id
            ");
            $this->db->bind(':rqty', $return_qty);
            $this->db->bind(':item_id', $item['purchase_receive_item_id']);
            $this->db->execute();

            // === STOCK DEDUCTION (Only for Good condition) ===
            if ($condition === 'good') {
                // Get current average cost before reducing stock (important for accurate valuation)
                $currentAvgCost = $this->stockTransaction->getWarehouseAvgCost($warehouse_id, $item['product_id']);
                if ($currentAvgCost <= 0) {
                    $currentAvgCost = (float)($item['rate'] ?? 0);
                }

                // Deduct stock using Moving Average method
                $this->stockTransaction->updateWarehouseStock(
                    $warehouse_id, 
                    $item['product_id'], 
                    -$return_qty           // Negative = Out (rate is ignored for reductions)
                );

                // Log Movement with the cost at time of return
                $this->stockTransaction->logMovement([
                    'product_id'     => $item['product_id'],
                    'warehouse_id'   => $warehouse_id,
                    'qty'            => -$return_qty,
                    'rate'           => $currentAvgCost,   // Use current avg cost for proper valuation
                    'reference_type' => 'purchase_return',
                    'reference_id'   => $return_id,
                    'remarks'        => "Purchase Return #" . $return_code
                ]);
            }
        }

        // === PROPER DOUBLE-ENTRY ACCOUNTING (Phase 5) ===
        // Dr Supplier Payable, Cr Inventory (reverses GRN AP + inventory impact)
        $journalService = new JournalPostingService();
        $journalResult = $journalService->postPurchaseReturn($return_id, [
            'return_code'   => $return_code,
            'return_date'   => $data['return_date'] ?? date('Y-m-d'),
            'total_amount'  => $data['total_amount'] ?? 0,
            'supplier_id'   => $data['supplier_id'] ?? null,
            'branch_id'     => $data['branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
            'reason'        => $data['reason'] ?? '',
        ]);

        if (isset($journalResult['status']) && $journalResult['status'] === 'error') {
            throw new Exception('Journal posting failed for Return: ' . ($journalResult['message'] ?? 'unknown error'));
        }

        if (!empty($journalResult['journal_entry_id'])) {
            $this->db->query("UPDATE purchase_returns SET journal_entry_id = :jeid WHERE id = :rid");
            $this->db->bind(':jeid', $journalResult['journal_entry_id']);
            $this->db->bind(':rid', $return_id);
            $this->db->execute();
        }

        // === SUPPLIER LEDGER (return reduces what we owe) ===
        $suppId = (int)($data['supplier_id'] ?? 0);
        if ($suppId > 0) {
            $this->db->query("
                SELECT COALESCE(running_balance, 0) as balance 
                FROM supplier_ledger 
                WHERE supplier_id = :sid 
                ORDER BY id DESC LIMIT 1
            ");
            $this->db->bind(':sid', $suppId);
            $row = $this->db->single();
            $current = (float)($row['balance'] ?? 0);
            $debit = (float)($data['total_amount'] ?? 0);
            $newBal = $current - $debit + 0;

            $this->db->query("
                INSERT INTO supplier_ledger 
                (transaction_date, supplier_id, reference_type, reference_id, 
                 debit, credit, running_balance, remarks, created_by, branch_id)
                VALUES (:date, :sid, 'return', :ref_id, :debit, 0, :bal, :rem, :uid, :bid)
            ");
            $this->db->bind(':date', $data['return_date'] ?? date('Y-m-d'));
            $this->db->bind(':sid', $suppId);
            $this->db->bind(':ref_id', $return_id);
            $this->db->bind(':debit', $debit);
            $this->db->bind(':bal', $newBal);
            $this->db->bind(':rem', $data['reason'] ?? 'From return');
            $this->db->bind(':uid', $_SESSION['user_id'] ?? 1);
            $this->db->bind(':bid', $data['branch_id'] ?? ($_SESSION['branch_id'] ?? 1));
            $this->db->execute();
        }

        $this->db->commit();
        return $return_id;

    } catch (Exception $e) {
        $this->db->rollback();
        error_log("Purchase Return Error: " . $e->getMessage());
        return false;
    }
}

    public function getFilteredReturns($filters = []) {
    $sql = "
        SELECT prt.*, 
               pr.receive_code, 
               s.supplier_name, 
               b.branch_name, 
               u.username as created_by_name
        FROM purchase_returns prt
        JOIN purchase_receives pr ON prt.purchase_receive_id = pr.id
        JOIN suppliers s ON prt.supplier_id = s.id
        JOIN branches b ON prt.branch_id = b.id
        LEFT JOIN users u ON prt.created_by = u.id
    ";

    $where = [];
    $bindings = [];

    // ==================== DATE FILTER (Safe for Big Data) ====================
    $hasDateFilter = false;

    if (!empty($filters['date_from'])) {
        $where[] = "prt.return_date >= :date_from";
        $bindings[':date_from'] = $filters['date_from'];
        $hasDateFilter = true;
    }
    if (!empty($filters['date_to'])) {
        $where[] = "prt.return_date <= :date_to";
        $bindings[':date_to'] = $filters['date_to'];
        $hasDateFilter = true;
    }

    // Default: Today only
    if (!$hasDateFilter) {
        $where[] = "prt.return_date = CURDATE()";
    }

    // Global Search
    if (!empty($filters['search'])) {
        $term = '%' . $filters['search'] . '%';
        $where[] = "(
            prt.return_code LIKE :term1 
            OR pr.receive_code LIKE :term2
            OR s.supplier_name LIKE :term3 
            OR b.branch_name LIKE :term4
            OR u.username LIKE :term5
            OR EXISTS (
                SELECT 1 FROM purchase_return_items pri
                JOIN products p ON pri.product_id = p.id
                WHERE pri.purchase_return_id = prt.id
                AND (p.product_name LIKE :term6 OR p.product_code LIKE :term7)
            )
        )";
        $bindings[':term1'] = $term;
        $bindings[':term2'] = $term;
        $bindings[':term3'] = $term;
        $bindings[':term4'] = $term;
        $bindings[':term5'] = $term;
        $bindings[':term6'] = $term;
        $bindings[':term7'] = $term;
    }

    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY prt.id DESC";

    $this->db->query($sql);

    foreach ($bindings as $param => $value) {
        $this->db->bind($param, $value);
    }

    return $this->db->resultSet();
}

    /**
     * Server-side DataTables for Purchase Returns
     */
    public function getPurchaseReturnsForDataTable(array $params): array
    {
        $start       = (int)($params['start'] ?? 0);
        $length      = (int)($params['length'] ?? 25);
        $searchValue = trim($params['search']['value'] ?? '');
        $orderColumn = (int)($params['order'][0]['column'] ?? 0);
        $orderDir    = $params['order'][0]['dir'] ?? 'desc';

        $columns = [
            'prt.return_date',
            'prt.return_code',
            'pr.receive_code',
            's.supplier_name',
            'prt.total_amount',
            'u.username',
            'prt.id'
        ];

        $baseQuery = "
            FROM purchase_returns prt
            JOIN purchase_receives pr ON prt.purchase_receive_id = pr.id
            JOIN suppliers s ON prt.supplier_id = s.id
            JOIN branches b ON prt.branch_id = b.id
            LEFT JOIN users u ON prt.created_by = u.id
        ";

        $where = [];
        $bindParams = [];

        if (!empty($params['date_from'])) {
            $where[] = "prt.return_date >= :date_from";
            $bindParams[':date_from'] = $params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $where[] = "prt.return_date <= :date_to";
            $bindParams[':date_to'] = $params['date_to'];
        }

        $status = $params['filterStatus'] ?? $params['status'] ?? '';
        if (!empty($params['showReversed'])) {
            $status = 'reversed';
        }

        if ($status === 'reversed') {
            $where[] = "prt.is_reversed = 1";
        } elseif ($status === 'all') {
            // no is_reversed filter
        } else {
            $where[] = "prt.is_reversed = 0";
        }

        if ($searchValue !== '') {
            $where[] = "(prt.return_code LIKE :search OR pr.receive_code LIKE :search OR s.supplier_name LIKE :search)";
            $bindParams[':search'] = "%{$searchValue}%";
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Totals
        $totalQuery = "SELECT COUNT(prt.id) as total {$baseQuery} {$whereSql}";
        $this->db->query($totalQuery);
        foreach ($bindParams as $key => $val) { $this->db->bind($key, $val); }
        $recordsTotal = $this->db->single()['total'] ?? 0;

        $filteredQuery = "SELECT COUNT(prt.id) as total {$baseQuery} {$whereSql}";
        $this->db->query($filteredQuery);
        foreach ($bindParams as $key => $val) { $this->db->bind($key, $val); }
        $recordsFiltered = $this->db->single()['total'] ?? 0;

        $smartSort = ($params['smart_sort'] ?? '1') !== '0' && ($params['smart_sort'] ?? '1') !== 0;
        if ($smartSort) {
            $orderBy = 'prt.is_reversed ASC, prt.return_date DESC, prt.id DESC';
            $orderDir = '';
        } else {
            $orderBy = $columns[$orderColumn] ?? 'prt.return_date';
            $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';
            $orderBy = "{$orderBy} {$orderDir}";
        }

        $dataQuery = "
            SELECT prt.*, pr.receive_code, s.supplier_name, b.branch_name, u.username as created_by_name
            {$baseQuery}
            {$whereSql}
            ORDER BY {$orderBy}
            LIMIT {$start}, {$length}
        ";

        $this->db->query($dataQuery);
        foreach ($bindParams as $key => $val) { $this->db->bind($key, $val); }
        $data = $this->db->resultSet();

        return [
            'draw'            => (int)($params['draw'] ?? 1),
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data
        ];
    }

    /**
     * Live counts for index status chips (date range + search).
     */
    public function getReturnFilterSummary(array $filters): array
    {
        $where = [];
        $bindParams = [];

        if (!empty($filters['date_from'])) {
            $where[] = 'prt.return_date >= :date_from';
            $bindParams[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'prt.return_date <= :date_to';
            $bindParams[':date_to'] = $filters['date_to'];
        }

        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $where[] = '(prt.return_code LIKE :search OR pr.receive_code LIKE :search OR s.supplier_name LIKE :search)';
            $bindParams[':search'] = "%{$search}%";
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $this->db->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN prt.is_reversed = 0 THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN prt.is_reversed = 1 THEN 1 ELSE 0 END) AS reversed
            FROM purchase_returns prt
            JOIN purchase_receives pr ON prt.purchase_receive_id = pr.id
            JOIN suppliers s ON prt.supplier_id = s.id
            {$whereSql}
        ");
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $row = $this->db->single() ?: [];

        $active = (int)($row['active'] ?? 0);
        $reversed = (int)($row['reversed'] ?? 0);

        return [
            'total'    => (int)($row['total'] ?? 0),
            'active'   => $active,
            'reversed' => $reversed,
            'all'      => (int)($row['total'] ?? 0),
        ];
    }

    // =========================================================================
    // PHASE 4: REVERSIBILITY — Reverse a Purchase Return (undo stock deduction + returned_qty)
    // =========================================================================
    /**
     * Reverse a Purchase Return.
     * - Validates not already reversed
     * - Rolls back stock for "Good" items (using StockTransactionModel for avg cost integrity)
     * - Reverts the cumulative returned_qty on the linked purchase_receive_items
     * - Marks header with full audit metadata (requires migration 005)
     * - Returns data for audit logging + future reversing journal entry
     *
     * Future Phase 5: If journal_entry_id exists on the return, caller should create
     * a reversing journal entry (Dr Inventory / Cr AP at the time of original return).
     */
    public function reversePurchaseReturn(int $returnId, string $reason): array
    {
        $this->db->beginTransaction();
        try {
            $userId = $_SESSION['user_id'] ?? 1;

            // 1. Load original return header (must not be reversed)
            $this->db->query("
                SELECT prt.*, s.supplier_name, pr.receive_code
                FROM purchase_returns prt
                JOIN suppliers s ON prt.supplier_id = s.id
                JOIN purchase_receives pr ON prt.purchase_receive_id = pr.id
                WHERE prt.id = :id AND prt.is_reversed = 0
            ");
            $this->db->bind(':id', $returnId);
            $return = $this->db->single();

            if (!$return) {
                throw new Exception("Purchase Return not found or has already been reversed.");
            }

            // 2. Load the return items
            $this->db->query("
                SELECT * FROM purchase_return_items 
                WHERE purchase_return_id = :rid
            ");
            $this->db->bind(':rid', $returnId);
            $items = $this->db->resultSet();

            if (empty($items)) {
                throw new Exception("No items found for this return.");
            }

            $totalReversedQty = 0;

            // 3. Reverse each line item
            $this->reversePurchaseReturnStock($returnId, (string)$return['return_code'], $reason);

            foreach ($items as $item) {
                $rqty       = (float)$item['return_qty'];
                $recvItemId = (int)$item['purchase_receive_item_id'];
                if ($rqty <= 0) continue;

                $this->db->query("
                    UPDATE purchase_receive_items 
                    SET returned_qty = GREATEST(0, COALESCE(returned_qty, 0) - :rqty)
                    WHERE id = :iid
                ");
                $this->db->bind(':rqty', $rqty);
                $this->db->bind(':iid', $recvItemId);
                $this->db->execute();

                $totalReversedQty += $rqty;
            }

            // 5. Mark header as reversed with full metadata (Phase 4 columns from migration 005)
            $this->db->query("
                UPDATE purchase_returns 
                SET is_reversed    = 1,
                    reversed_at    = NOW(),
                    reversed_by    = :uid,
                    reverse_reason = :reason
                WHERE id = :id
            ");
            $this->db->bind(':uid', $userId);
            $this->db->bind(':reason', $reason);
            $this->db->bind(':id', $returnId);
            $this->db->execute();

            if (!empty($return['journal_entry_id'])) {
                $journalService = new JournalPostingService();
                $rev = $journalService->reverseLinkedJournal((int)$return['journal_entry_id'], $reason);
                if (($rev['status'] ?? '') === 'error') {
                    throw new Exception('Failed to reverse return journal: ' . ($rev['message'] ?? ''));
                }
            }

            $supplierId = (int)($return['supplier_id'] ?? 0);
            $restoreCredit = (float)($return['total_amount'] ?? 0);
            if ($supplierId > 0 && $restoreCredit > 0.0001) {
                $this->db->query("
                    UPDATE supplier_ledger
                    SET is_reversed = 1
                    WHERE reference_type = 'return'
                      AND reference_id = :rid
                      AND COALESCE(is_reversed, 0) = 0
                ");
                $this->db->bind(':rid', $returnId);
                $this->db->execute();

                $this->db->query("
                    SELECT COALESCE(running_balance, 0) AS balance
                    FROM supplier_ledger
                    WHERE supplier_id = :sid
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $this->db->bind(':sid', $supplierId);
                $row = $this->db->single();
                $current = (float)($row['balance'] ?? 0);
                $newBal = $current + $restoreCredit;

                $this->db->query("
                    INSERT INTO supplier_ledger
                    (transaction_date, supplier_id, reference_type, reference_id,
                     debit, credit, running_balance, remarks, created_by, is_reversed, branch_id)
                    VALUES (CURDATE(), :sid, 'reversal', :ref_id, 0, :credit, :bal, :rem, :uid, 0, :bid)
                ");
                $this->db->bind(':sid', $supplierId);
                $this->db->bind(':ref_id', $returnId);
                $this->db->bind(':credit', $restoreCredit);
                $this->db->bind(':bal', $newBal);
                $this->db->bind(':rem', 'Reversal of purchase return #' . ($return['return_code'] ?? $returnId) . ' — ' . $reason);
                $this->db->bind(':uid', $userId);
                $this->db->bind(':bid', (int)($return['branch_id'] ?? ($_SESSION['branch_id'] ?? 1)));
                $this->db->execute();
            }

            $this->db->commit();

            return [
                'status'             => 'success',
                'message'            => 'Purchase Return reversed successfully. Stock, supplier ledger, and GL restored.',
                'return_id'          => $returnId,
                'return_code'        => $return['return_code'],
                'receive_code'       => $return['receive_code'],
                'supplier_name'      => $return['supplier_name'],
                'total_amount'       => $return['total_amount'],
                'items_reversed'     => count($items),
                'total_qty_restored' => $totalReversedQty,
                'journal_entry_id'   => $return['journal_entry_id'] ?? null,
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            error_log("PurchaseReturn Reverse Error (id=$returnId): " . $e->getMessage());
            return [
                'status'  => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Good-condition returns: return qty must not exceed warehouse_stock-based availability
     * (same calculation as Helper::Get_Warehouse_Wise_Product_Stock — not GRN returnable_qty).
     */
    private function assertWarehouseAvailableForReturn(
        int $warehouseId,
        int $productId,
        float $returnQty,
        int $branchId
    ): void {
        if ($warehouseId <= 0 || $productId <= 0) {
            throw new Exception('Warehouse and product are required for Good returns.');
        }

        $rows = $this->helper->Get_Warehouse_Wise_Product_Stock($productId, $branchId > 0 ? $branchId : null);
        $available = 0.0;
        foreach ($rows as $row) {
            if ((int)($row['id'] ?? 0) === $warehouseId) {
                $available = (float)($row['available_qty'] ?? 0);
                break;
            }
        }

        if ($returnQty > $available + 0.0001) {
            throw new Exception(
                'Return qty exceeds warehouse available ('
                . number_format($available, 2, '.', '')
                . ' from warehouse_stock for this warehouse).'
            );
        }
    }

    /**
     * Undo stock removed on return create — driven by stock_transactions (SSOT audit trail).
     */
    private function reversePurchaseReturnStock(int $returnId, string $returnCode, string $reason): void
    {
        $movements = $this->stockTransaction->getByReference('purchase_return', $returnId);
        $reversedFromLog = false;

        foreach ($movements as $movement) {
            $qty = (float)($movement['qty'] ?? 0);
            if ($qty >= -0.0001) {
                continue;
            }

            $warehouse_id = (int)($movement['warehouse_id'] ?? 0);
            $product_id   = (int)($movement['product_id'] ?? 0);
            if ($warehouse_id <= 0 || $product_id <= 0) {
                continue;
            }

            $restoreQty = abs($qty);
            $rate = (float)($movement['rate'] ?? 0);
            if ($rate <= 0) {
                $rate = $this->stockTransaction->getWarehouseAvgCost($warehouse_id, $product_id);
            }

            $this->stockTransaction->updateWarehouseStock(
                $warehouse_id,
                $product_id,
                $restoreQty,
                $rate
            );

            $this->stockTransaction->logMovement([
                'product_id'     => $product_id,
                'warehouse_id'   => $warehouse_id,
                'qty'            => $restoreQty,
                'rate'           => $rate,
                'reference_type' => 'purchase_return_reversal',
                'reference_id'   => $returnId,
                'remarks'        => "Reversal of Return #{$returnCode}" . ($reason !== '' ? " — {$reason}" : ''),
            ]);

            $reversedFromLog = true;
        }

        if ($reversedFromLog) {
            return;
        }

        $this->db->query("
            SELECT * FROM purchase_return_items
            WHERE purchase_return_id = :rid
              AND LOWER(COALESCE(`condition`, 'good')) = 'good'
              AND COALESCE(return_qty, 0) > 0
        ");
        $this->db->bind(':rid', $returnId);
        $goodItems = $this->db->resultSet();

        foreach ($goodItems as $item) {
            $warehouse_id = (int)$item['warehouse_id'];
            $product_id   = (int)$item['product_id'];
            $return_qty   = (float)$item['return_qty'];
            $rate = (float)($item['rate'] ?? 0);
            if ($rate <= 0) {
                $rate = $this->stockTransaction->getWarehouseAvgCost($warehouse_id, $product_id);
            }

            $this->stockTransaction->updateWarehouseStock(
                $warehouse_id,
                $product_id,
                $return_qty,
                $rate
            );

            $this->stockTransaction->logMovement([
                'product_id'     => $product_id,
                'warehouse_id'   => $warehouse_id,
                'qty'            => $return_qty,
                'rate'           => $rate,
                'reference_type' => 'purchase_return_reversal',
                'reference_id'   => $returnId,
                'remarks'        => "Reversal of Return #{$returnCode} (legacy line)",
            ]);
        }
    }

}
<?php
// app/models/PurchaseReceiveModel.php

require_once '../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';
require_once 'StockTransactionModel.php';
require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';


class PurchaseReceiveModel extends Helper{


    protected $db;
    protected $stockTransaction;

    public function __construct() {
        $this->db = new Database();
        $this->stockTransaction = new StockTransactionModel($this->db);
    }

    public function getPendingPOs() {
        $this->db->query("
            SELECT po.id, po.po_code, s.supplier_name, po.status
            FROM purchase_orders po
            JOIN suppliers s ON po.supplier_id = s.id
            WHERE po.status IN ('draft', 'pending', 'partially_received')
              AND po.branch_id = :branch_id
              AND EXISTS (
                  SELECT 1 FROM purchase_order_items poi
                  WHERE poi.purchase_order_id = po.id
                    AND (poi.qty - COALESCE(poi.received_qty, 0)) > 0
              )
            ORDER BY po.po_date DESC
        ");
        $this->db->bind(':branch_id', $_SESSION['branch_id'] ?? 1);
        return $this->db->resultSet();
    }

    public function getBranchWarehouses() {
        return $this-> Get_Warehouse_By_Branch($_SESSION['branch_id']);
    }

    public function getActiveSuppliers() {
        $this->db->query("
            SELECT id, supplier_name, supplier_code 
            FROM suppliers 
            WHERE is_active = 1 
            ORDER BY supplier_name
        ");
        return $this->db->resultSet();
    }

    public function getPODetailsForReceive($po_id) {
        $this->db->query("
            SELECT po.*, s.supplier_name 
            FROM purchase_orders po
            JOIN suppliers s ON po.supplier_id = s.id
            WHERE po.id = :po_id
        ");
        $this->db->bind(':po_id', $po_id);
        $po = $this->db->single();

        if (!$po) return null;

        $this->db->query("
            SELECT poi.id as purchase_order_item_id, poi.product_id, poi.qty, poi.rate, COALESCE(poi.received_qty, 0) as received_qty,
                   (poi.qty - COALESCE(poi.received_qty, 0)) as remaining_qty,
                   p.product_name, p.product_code, p.unit
            FROM purchase_order_items poi
            JOIN products p ON poi.product_id = p.id
            WHERE poi.purchase_order_id = :po_id 
              AND (poi.qty - COALESCE(poi.received_qty, 0)) > 0
            ORDER BY poi.id
        ");
        $this->db->bind(':po_id', $po_id);
        $po['items'] = $this->db->resultSet();

        return $po;
    }

 /**
     * ACCOUNTING DECISION RECORD (June 2026)
     *
     * When creating a Purchase Receive, the financial liability (Accounts Payable)
     * will be recognized at this point in the new double-entry system.
     *
     * Decision: Credit Supplier Payable Control Account on Receive (GRN date), not on Invoice.
     * See docs/PURCHASE_MODULE_MODERNIZATION_PLAN.md → "Accounting Decision: When to Credit Accounts Payable"
     *
     * Future posting (when GL integration is done):
     *   Dr Inventory (at moving average cost)
     *   Cr Supplier Payable (control account with nature = supplier_payable)
     */
    public function createReceive($data, $items) {
        $this->db->beginTransaction();

        try {
            // Generate Receive Code
            $this->db->query("SELECT COUNT(*) as cnt FROM purchase_receives WHERE DATE(created_at) = CURDATE()");
            $row = $this->db->single();
            $receive_code = "GRN-" . date('Ymd') . "-" . str_pad(($row['cnt'] + 1), 4, '0', STR_PAD_LEFT);

            $isDirect = empty($data['purchase_order_id']);

            if ($isDirect) {
                // === DIRECT PURCHASE (No PO) ===
                $this->db->query("
                    INSERT INTO purchase_receives 
                    (receive_code, purchase_order_id, supplier_id, branch_id, receive_date, 
                     total_amount, remarks, created_by, status, journal_entry_id)
                    VALUES 
                    (:code, NULL, :supplier_id, :branch_id, :receive_date, 
                     :total_amount, :remarks, :created_by, 'received', :journal_entry_id)
                ");

                $this->db->bind(':code', $receive_code);
                $this->db->bind(':supplier_id', $data['supplier_id']);
                $this->db->bind(':branch_id', $data['branch_id'] ?? ($_SESSION['branch_id'] ?? 1));
                $this->db->bind(':receive_date', $data['receive_date'] ?? date('Y-m-d'));
                $this->db->bind(':total_amount', $data['total_amount'] ?? 0);
                $this->db->bind(':remarks', $data['remarks'] ?? '');
                $this->db->bind(':created_by', $_SESSION['user_id'] ?? 1);
                $this->db->bind(':journal_entry_id', $data['journal_entry_id'] ?? null);

            } else {
                // === RECEIVE AGAINST PO (Existing Logic) ===
                $this->db->query("
                    INSERT INTO purchase_receives 
                    (receive_code, purchase_order_id, supplier_id, branch_id, receive_date, 
                     total_amount, remarks, created_by, status, journal_entry_id)
                    SELECT :code, :po_id, supplier_id, branch_id, :receive_date, 
                           :total_amount, :remarks, :created_by, 'received', :journal_entry_id
                    FROM purchase_orders WHERE id = :po_id
                ");

                $this->db->bind(':code', $receive_code);
                $this->db->bind(':po_id', $data['purchase_order_id']);
                $this->db->bind(':receive_date', $data['receive_date'] ?? date('Y-m-d'));
                $this->db->bind(':total_amount', $data['total_amount'] ?? 0);
                $this->db->bind(':remarks', $data['remarks'] ?? '');
                $this->db->bind(':created_by', $_SESSION['user_id'] ?? 1);
                $this->db->bind(':journal_entry_id', $data['journal_entry_id'] ?? null);
            }

            if (!$this->db->execute()) throw new Exception("Failed to insert header");

            $receive_id = $this->db->lastInsertId();

            
            foreach ($items as $idx => $item) {
    if ((float)($item['qty'] ?? 0) <= 0) continue;

    $qty = (float)$item['qty'];
    $rate = (float)$item['rate'];
    $product_id = (int)$item['product_id'];
    $warehouse_id = (int)$item['warehouse_id'];
    if ($warehouse_id <= 0) {
        throw new Exception("Warehouse is required for each received item.");
    }

    // Insert receive item
    $this->db->query("INSERT INTO purchase_receive_items 
        (purchase_receive_id, purchase_order_item_id, product_id, warehouse_id, qty, rate, `condition`)
        VALUES (:rid, :poi, :pid, :wid, :qty, :rate, 'Good')");

    $this->db->bind(':rid', $receive_id);
    $this->db->bind(':poi', $item['purchase_order_item_id'] ?? null);
    $this->db->bind(':pid', $product_id);
    $this->db->bind(':wid', $warehouse_id);
    $this->db->bind(':qty', $qty);
    $this->db->bind(':rate', $rate);
    $this->db->execute();


     // Stock Update
               $this->stockTransaction->updateWarehouseStock($warehouse_id, $product_id, $qty,  $rate);

                $this->stockTransaction->logMovement([
                    'product_id'     => $product_id,
                    'warehouse_id'   => $warehouse_id,
                    'qty'            => $qty,
                    'rate'           => $rate,
                    'reference_type' => 'purchase_receive',
                    'reference_id'   => $receive_id,
                    'remarks'        => "Purchase Received"
                ]);
    
}


            // Only update PO status if this receive was against a PO
            if (!$isDirect && !empty($data['purchase_order_id'])) {
                $this->updatePOStatus($data['purchase_order_id']);
            }

            // === PROPER DOUBLE-ENTRY ACCOUNTING (Phase 5) ===
            // Dr Inventory, Cr Supplier Payable on GRN (AP recognized here per documented decision)
            $journalService = new JournalPostingService();
            $journalResult = $journalService->postPurchaseReceive($receive_id, [
                'receive_code'  => $receive_code,
                'receive_date'  => $data['receive_date'] ?? date('Y-m-d'),
                'total_amount'  => $data['total_amount'] ?? 0,
                'supplier_id'   => $data['supplier_id'] ?? null,
                'branch_id'     => $data['branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
                'remarks'       => $data['remarks'] ?? '',
            ]);

            if (isset($journalResult['status']) && $journalResult['status'] === 'error') {
                throw new Exception('Journal posting failed for GRN: ' . ($journalResult['message'] ?? 'unknown error'));
            }

            if (!empty($journalResult['journal_entry_id'])) {
                $this->db->query("UPDATE purchase_receives SET journal_entry_id = :jeid WHERE id = :rid");
                $this->db->bind(':jeid', $journalResult['journal_entry_id']);
                $this->db->bind(':rid', $receive_id);
                $this->db->execute();
            }

            // === SUPPLIER LEDGER for due (sub-ledger) ===
            // GRN: credit to increase what we owe (matches convention where payments debit to reduce running)
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
                $credit = (float)($data['total_amount'] ?? 0);
                $newBal = $current + $credit;

                $this->db->query("
                    INSERT INTO supplier_ledger 
                    (transaction_date, supplier_id, reference_type, reference_id, 
                     debit, credit, running_balance, remarks, created_by, branch_id)
                    VALUES (:date, :sid, 'purchase', :ref_id, 0, :credit, :bal, :rem, :uid, :bid)
                ");
                $this->db->bind(':date', $data['receive_date'] ?? date('Y-m-d'));
                $this->db->bind(':sid', $suppId);
                $this->db->bind(':ref_id', $receive_id);
                $this->db->bind(':credit', $credit);
                $this->db->bind(':bal', $newBal);
                $this->db->bind(':rem', $data['remarks'] ?? 'From GRN');
                $this->db->bind(':uid', $_SESSION['user_id'] ?? 1);
                $this->db->bind(':bid', $data['branch_id'] ?? ($_SESSION['branch_id'] ?? 1));
                $this->db->execute();
            }

            $this->db->commit();
            return $receive_id;

        } catch (Exception $e) {
            $this->db->rollback();
            error_log("=== PURCHASE RECEIVE ERROR === " . $e->getMessage());
            return false;
        }
    }



    private function updatePOStatus($po_id) {
        // Update received_qty from active (non-cancelled) GRNs only
        $this->db->query("
            UPDATE purchase_order_items poi
            SET poi.received_qty = (
                SELECT COALESCE(SUM(pri.qty), 0)
                FROM purchase_receive_items pri
                INNER JOIN purchase_receives pr ON pr.id = pri.purchase_receive_id
                WHERE pri.purchase_order_item_id = poi.id
                  AND pr.status = 'received'
            )
            WHERE poi.purchase_order_id = :po_id
        ");
        $this->db->bind(':po_id', $po_id);
        $this->db->execute();

        // Update PO Header Status
        $this->db->query("
            UPDATE purchase_orders po
            SET po.status = CASE 
                WHEN (
                    SELECT SUM(COALESCE(poi.received_qty, 0)) 
                    FROM purchase_order_items poi 
                    WHERE poi.purchase_order_id = po.id
                ) >= (
                    SELECT SUM(poi.qty) 
                    FROM purchase_order_items poi 
                    WHERE poi.purchase_order_id = po.id
                )
                THEN 'received'
                WHEN (
                    SELECT SUM(COALESCE(poi.received_qty, 0)) 
                    FROM purchase_order_items poi 
                    WHERE poi.purchase_order_id = po.id
                ) > 0
                THEN 'partially_received'
                ELSE 'pending'
            END
            WHERE po.id = :po_id
        ");
        $this->db->bind(':po_id', $po_id);
        $this->db->execute();
    }

    public function getAllReceives() {
        $this->db->query("
            SELECT pr.*, po.po_code, s.supplier_name, b.branch_name
            FROM purchase_receives pr
            LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.id
            JOIN suppliers s ON pr.supplier_id = s.id
            JOIN branches b ON pr.branch_id = b.id
            ORDER BY pr.id DESC
        ");
        return $this->db->resultSet();
    }

    public function getReceiveDetails($id) {
    // Header
    $this->db->query("
        SELECT pr.*, po.po_code, s.supplier_name, s.mobile, s.address,
               b.branch_name, u.username as created_by_name
        FROM purchase_receives pr
        LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.id
        JOIN suppliers s ON pr.supplier_id = s.id
        JOIN branches b ON pr.branch_id = b.id
        LEFT JOIN users u ON pr.created_by = u.id
        WHERE pr.id = :id
    ");
    $this->db->bind(':id', $id);
    $receive = $this->db->single();

    if (!$receive) return null;

    // Items
    $this->db->query("
        SELECT pri.*, p.product_name, p.product_code, p.unit, w.warehouse_name
        FROM purchase_receive_items pri
        JOIN products p ON pri.product_id = p.id
        JOIN warehouses w ON pri.warehouse_id = w.id
        WHERE pri.purchase_receive_id = :id
        ORDER BY pri.id
    ");
    $this->db->bind(':id', $id);
    $receive['items'] = $this->db->resultSet();

    return $receive;
}

public function getFilteredReceives($filters = []) {
    $sql = "
        SELECT pr.*, 
               po.po_code, 
               s.supplier_name, 
               b.branch_name, 
               u.username as created_by_name
        FROM purchase_receives pr
        LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.id
        JOIN suppliers s ON pr.supplier_id = s.id
        JOIN branches b ON pr.branch_id = b.id
        LEFT JOIN users u ON pr.created_by = u.id
    ";

    $where = [];
    $bindings = [];

    // ==================== DATE FILTER (Mandatory - Safe for Big Data) ====================
    $hasDateFilter = false;

    if (!empty($filters['date_from'])) {
        $where[] = "pr.receive_date >= :date_from";
        $bindings[':date_from'] = $filters['date_from'];
        $hasDateFilter = true;
    }
    if (!empty($filters['date_to'])) {
        $where[] = "pr.receive_date <= :date_to";
        $bindings[':date_to'] = $filters['date_to'];
        $hasDateFilter = true;
    }

    // Default: Today only (if no date selected)
    if (!$hasDateFilter) {
        $where[] = "pr.receive_date = CURDATE()";
    }

    // Global Search
    if (!empty($filters['search'])) {
        $term = '%' . $filters['search'] . '%';
        $where[] = "(
            pr.receive_code LIKE :term1 
            OR po.po_code LIKE :term2
            OR s.supplier_name LIKE :term3 
            OR b.branch_name LIKE :term4
            OR u.username LIKE :term5
            OR EXISTS (
                SELECT 1 FROM purchase_receive_items pri
                JOIN products p ON pri.product_id = p.id
                WHERE pri.purchase_receive_id = pr.id
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

    $sql .= " ORDER BY pr.id DESC";

    $this->db->query($sql);

    foreach ($bindings as $param => $value) {
        $this->db->bind($param, $value);
    }

    return $this->db->resultSet();
}

/**
 * Server-side DataTables for Purchase Receives
 */
public function getPurchaseReceivesForDataTable(array $params): array
{
    $start       = (int)($params['start'] ?? 0);
    $length      = (int)($params['length'] ?? 25);
    $searchValue = trim($params['search']['value'] ?? '');
    $orderColumn = (int)($params['order'][0]['column'] ?? 0);
    $orderDir    = $params['order'][0]['dir'] ?? 'desc';

    $filterStatus = $params['filterStatus'] ?? '';

    $columns = [
        'pr.receive_date',
        'pr.receive_code',
        'po.po_code',
        's.supplier_name',
        'pr.total_amount',
        'pr.status',
        'u.username',
        'pr.id'
    ];

    $baseQuery = "
        FROM purchase_receives pr
        LEFT JOIN purchase_orders po ON pr.purchase_order_id = po.id
        JOIN suppliers s ON pr.supplier_id = s.id
        JOIN branches b ON pr.branch_id = b.id
        LEFT JOIN users u ON pr.created_by = u.id
    ";

    $where = [];
    $bindParams = [];

    // Date filters
    if (!empty($params['date_from'])) {
        $where[] = "pr.receive_date >= :date_from";
        $bindParams[':date_from'] = $params['date_from'];
    }
    if (!empty($params['date_to'])) {
        $where[] = "pr.receive_date <= :date_to";
        $bindParams[':date_to'] = $params['date_to'];
    }

    // Status filter
    if (!empty($filterStatus) && $filterStatus !== 'all') {
        $where[] = "pr.status = :status";
        $bindParams[':status'] = $filterStatus;
    }

    $showReturned = !empty($params['showReturned']);

    if (!$showReturned) {
        // Default: exclude returned/cancelled
        $where[] = "pr.status NOT IN ('returned', 'cancelled')";
    } else {
        $where[] = "pr.status IN ('returned', 'cancelled')";
    }

    // Global search
    if ($searchValue !== '') {
        $where[] = "(pr.receive_code LIKE :search OR po.po_code LIKE :search OR s.supplier_name LIKE :search)";
        $bindParams[':search'] = "%{$searchValue}%";
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Total records
    $totalQuery = "SELECT COUNT(pr.id) as total {$baseQuery} {$whereSql}";
    $this->db->query($totalQuery);
    foreach ($bindParams as $key => $val) {
        $this->db->bind($key, $val);
    }
    $totalResult = $this->db->single();
    $recordsTotal = $totalResult['total'] ?? 0;

    // Filtered count
    $filteredQuery = "SELECT COUNT(pr.id) as total {$baseQuery} {$whereSql}";
    $this->db->query($filteredQuery);
    foreach ($bindParams as $key => $val) {
        $this->db->bind($key, $val);
    }
    $filteredResult = $this->db->single();
    $recordsFiltered = $filteredResult['total'] ?? 0;

    // Data query
    $orderBy = $columns[$orderColumn] ?? 'pr.receive_date';
    $dataQuery = "
        SELECT 
            pr.*,
            po.po_code,
            s.supplier_name,
            b.branch_name,
            u.username as created_by_name
        {$baseQuery}
        {$whereSql}
        ORDER BY {$orderBy} {$orderDir}
        LIMIT {$start}, {$length}
    ";

    $this->db->query($dataQuery);
    foreach ($bindParams as $key => $val) {
        $this->db->bind($key, $val);
    }
    $data = $this->db->resultSet();

    return [
        'draw'            => (int)($params['draw'] ?? 1),
        'recordsTotal'    => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data'            => $data
    ];
}

    // =========================================================================
    // PHASE 4: Basic cancellation for a Receive (GRN) with audit trail
    // =========================================================================
    /**
     * Cancel a Purchase Receive (if it has no returns against it).
     * For full reversal use the Return reversal flow. This is for erroneous GRNs.
     * Updates status and appends to remarks (no new columns needed).
     */
    public function cancelReceive(int $receiveId, string $reason): array
    {
        $this->db->beginTransaction();
        try {
            $userId = $_SESSION['user_id'] ?? 1;

            $this->db->query("
                SELECT pr.*
                FROM purchase_receives pr 
                WHERE pr.id = :id AND pr.status NOT IN ('cancelled', 'returned')
            ");
            $this->db->bind(':id', $receiveId);
            $rec = $this->db->single();

            if (!$rec) {
                throw new Exception("Receive not found or already cancelled/returned.");
            }

            $this->db->query("
                SELECT COUNT(*) AS cnt FROM purchase_returns
                WHERE purchase_receive_id = :rid AND COALESCE(is_reversed, 0) = 0
            ");
            $this->db->bind(':rid', $receiveId);
            $activeReturns = (int)($this->db->single()['cnt'] ?? 0);
            if ($activeReturns > 0) {
                throw new Exception("Cannot cancel: active purchase returns exist. Reverse those returns first.");
            }

            // Load items to reverse stock
            $this->db->query("SELECT * FROM purchase_receive_items WHERE purchase_receive_id = :rid");
            $this->db->bind(':rid', $receiveId);
            $items = $this->db->resultSet();

            foreach ($items as $it) {
                $qty = (float)$it['qty'];
                if ($qty <= 0.0001) {
                    continue;
                }
                $wh  = (int)$it['warehouse_id'];
                $pid = (int)$it['product_id'];
                $rate = (float)($it['rate'] ?? 0);

                $this->db->query("
                    SELECT COALESCE(qty, 0) AS qty
                    FROM warehouse_stock
                    WHERE warehouse_id = :wid AND product_id = :pid
                ");
                $this->db->bind(':wid', $wh);
                $this->db->bind(':pid', $pid);
                $physical = (float)($this->db->single()['qty'] ?? 0);
                if ($qty > $physical + 0.0001) {
                    throw new Exception(
                        'Cannot cancel GRN: insufficient stock in warehouse to remove '
                        . number_format($qty, 2) . ' units (on hand ' . number_format($physical, 2) . ').'
                    );
                }

                $this->stockTransaction->updateWarehouseStock($wh, $pid, -$qty);

                $this->stockTransaction->logMovement([
                    'product_id'     => $pid,
                    'warehouse_id'   => $wh,
                    'qty'            => -$qty,
                    'rate'           => $rate,
                    'reference_type' => 'purchase_receive_cancel',
                    'reference_id'   => $receiveId,
                    'remarks'        => 'GRN cancelled: ' . $reason
                ]);
            }

            $supplierId = (int)($rec['supplier_id'] ?? 0);
            $grnTotal = (float)($rec['total_amount'] ?? 0);
            if ($supplierId > 0 && $grnTotal > 0.0001) {
                $this->db->query("
                    UPDATE supplier_ledger
                    SET is_reversed = 1
                    WHERE reference_type = 'purchase'
                      AND reference_id = :rid
                      AND COALESCE(is_reversed, 0) = 0
                ");
                $this->db->bind(':rid', $receiveId);
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
                $newBal = $current - $grnTotal;

                $this->db->query("
                    INSERT INTO supplier_ledger
                    (transaction_date, supplier_id, reference_type, reference_id,
                     debit, credit, running_balance, remarks, created_by, is_reversed, branch_id)
                    VALUES (:date, :sid, 'reversal', :ref_id, :debit, 0, :bal, :rem, :uid, 0, :bid)
                ");
                $this->db->bind(':date', $rec['receive_date'] ?? date('Y-m-d'));
                $this->db->bind(':sid', $supplierId);
                $this->db->bind(':ref_id', $receiveId);
                $this->db->bind(':debit', $grnTotal);
                $this->db->bind(':bal', $newBal);
                $this->db->bind(':rem', 'GRN cancelled — ' . $reason);
                $this->db->bind(':uid', $userId);
                $this->db->bind(':bid', (int)($rec['branch_id'] ?? ($_SESSION['branch_id'] ?? 1)));
                $this->db->execute();
            }

            if (!empty($rec['journal_entry_id'])) {
                $journalService = new JournalPostingService();
                $rev = $journalService->reverseLinkedJournal((int)$rec['journal_entry_id'], $reason);
                if (($rev['status'] ?? '') === 'error') {
                    throw new Exception('Failed to reverse GRN journal: ' . ($rev['message'] ?? ''));
                }
            }

            // Update header status + reason in remarks
            $this->db->query("
                UPDATE purchase_receives 
                SET status = 'cancelled',
                    remarks = CONCAT(IFNULL(remarks, ''), '\n\n[CANCELLED ', NOW(), '] ', :reason)
                WHERE id = :id
            ");
            $this->db->bind(':reason', $reason);
            $this->db->bind(':id', $receiveId);
            $this->db->execute();

            $poId = (int)($rec['purchase_order_id'] ?? 0);
            if ($poId > 0) {
                $this->updatePOStatus($poId);
            }

            $this->db->commit();

            return [
                'status' => 'success',
                'message' => 'Purchase Receive cancelled. Stock, supplier ledger, and GL reversed.',
                'receive_id' => $receiveId,
                'purchase_order_id' => $poId > 0 ? $poId : null,
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Cancel Receive Error: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

}
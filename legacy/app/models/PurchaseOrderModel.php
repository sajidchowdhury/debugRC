<?php
// app/models/PurchaseOrderModel.php

require_once '../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';


class PurchaseOrderModel extends Helper{


    protected $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function getActiveSuppliers() {
        return $this->Get_All_Active_Supplier();
    }

    public function getActiveProducts() {
        return $this->Get_All_Active_Product();
    }

    public function getAllPOs() {
        $this->db->query("
            SELECT po.*, s.supplier_name, b.branch_name, u.username as created_by_name
            FROM purchase_orders po
            JOIN suppliers s ON po.supplier_id = s.id
            JOIN branches b ON po.branch_id = b.id
            LEFT JOIN users u ON po.created_by = u.id
            ORDER BY po.id DESC
        ");
        return $this->db->resultSet();
    }

    public function createPO($data, $items) {
        $this->db->beginTransaction();

        try {
            // Generate PO Code
            $this->db->query("SELECT COUNT(*) as cnt FROM purchase_orders WHERE DATE(created_at) = CURDATE()");
            $row = $this->db->single();
            $po_code = "PO-" . date('Ymd') . "-" . str_pad(($row['cnt'] + 1), 4, '0', STR_PAD_LEFT);

            // Insert PO Header
            $this->db->query("
                INSERT INTO purchase_orders 
                (po_code, supplier_id, branch_id, po_date, expected_date, total_amount, remarks, created_by, status, journal_entry_id)
                VALUES 
                (:po_code, :supplier_id, :branch_id, :po_date, :expected_date, :total_amount, :remarks, :created_by, 'draft', :journal_entry_id)
            ");

            $this->db->bind(':po_code', $po_code);
            $this->db->bind(':supplier_id', $data['supplier_id']);
            $this->db->bind(':branch_id', $data['branch_id']);
            $this->db->bind(':po_date', $data['po_date']);
            $this->db->bind(':expected_date', $data['expected_date']);
            $this->db->bind(':total_amount', $data['total_amount']);
            $this->db->bind(':remarks', $data['remarks']);
            $this->db->bind(':created_by', $_SESSION['user_id'] ?? 1);
            $this->db->bind(':journal_entry_id', $data['journal_entry_id'] ?? null);

            if (!$this->db->execute()) {
                throw new Exception("Failed to insert PO header");
            }

            $po_id = $this->db->lastInsertId();

            // Insert Items
            foreach ($items as $item) {
                $this->db->query("
                    INSERT INTO purchase_order_items 
                    (purchase_order_id, product_id, qty, rate, received_qty)
                    VALUES 
                    (:po_id, :product_id, :qty, :rate, 0)
                ");

                $this->db->bind(':po_id', $po_id);
                $this->db->bind(':product_id', $item['product_id']);
                $this->db->bind(':qty', $item['qty']);
                $this->db->bind(':rate', $item['rate']);

                if (!$this->db->execute()) {
                    throw new Exception("Failed to insert PO item");
                }
            }

            $this->db->commit();
            return $po_id;

        } catch (Exception $e) {
            $this->db->rollback();
            error_log("PO Create Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Cancel a Purchase Order (improved for Phase 4 - Auditability)
     * Only draft or pending POs can be cancelled.
     */
    public function cancelPO($id, $reason = '') {
        $this->db->query("SELECT status FROM purchase_orders WHERE id = :id");
        $this->db->bind(':id', $id);
        $po = $this->db->single();

        if (!$po || !in_array($po['status'], ['draft', 'pending'])) {
            return ['status' => 'error', 'message' => 'Only draft or pending POs can be cancelled'];
        }

        $this->db->query("
            UPDATE purchase_orders 
            SET status = 'cancelled', 
                remarks = CONCAT(IFNULL(remarks, ''), '\n\n[Cancelled] Reason: ', :reason),
                cancelled_at = NOW(),
                cancelled_by = :uid,
                cancel_reason = :reason,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $this->db->bind(':id', $id);
        $this->db->bind(':uid', $_SESSION['user_id'] ?? 1);
        $this->db->bind(':reason', $reason ?: 'No reason provided');

        if (!$this->db->execute()) {
            return ['status' => 'error', 'message' => 'Failed to cancel Purchase Order'];
        }

        return ['status' => 'success', 'message' => 'Purchase Order cancelled successfully'];
    }

    // Legacy delete kept for now (only for very early drafts if needed)
    public function deletePO($id) {
        $this->db->query("SELECT status FROM purchase_orders WHERE id = :id");
        $this->db->bind(':id', $id);
        $po = $this->db->single();

        if (!$po || $po['status'] !== 'draft') {
            return ['status' => 'error', 'message' => 'Only draft POs can be hard deleted'];
        }

        $this->db->query("DELETE FROM purchase_orders WHERE id = :id");
        $this->db->bind(':id', $id);

        return $this->db->execute() 
            ? ['status' => 'success', 'message' => 'Purchase Order deleted successfully']
            : ['status' => 'error', 'message' => 'Delete failed'];
    }

        public function searchProducts($term) {
        $this->db->query("
            SELECT id, product_code, product_name, unit 
            FROM products 
            WHERE is_active = 1 
              AND (product_name LIKE :term 
               OR product_code LIKE :term)
            ORDER BY product_name 
            LIMIT 20
        ");
        $this->db->bind(':term', "%$term%");
        return $this->db->resultSet();
    }


    public function getPOForEdit($id) {
    $this->db->query("
        SELECT po.*, s.supplier_name 
        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.id
        WHERE po.id = :id
    ");
    $this->db->bind(':id', $id);
    $po = $this->db->single();

    if ($po) {
        $this->db->query("
            SELECT poi.*, p.product_name, p.product_code 
            FROM purchase_order_items poi
            JOIN products p ON poi.product_id = p.id
            WHERE poi.purchase_order_id = :po_id
        ");
        $this->db->bind(':po_id', $id);
        $po['items'] = $this->db->resultSet();
    }

    return $po;
}

public function updatePO($id, $data, $items) {
    $this->db->beginTransaction();

    try {
        // Check if still draft
        $this->db->query("SELECT status FROM purchase_orders WHERE id = :id");
        $this->db->bind(':id', $id);
        $current = $this->db->single();
        if ($current['status'] !== 'draft') {
            throw new Exception("Only draft POs can be edited");
        }

        // Update Header
        $this->db->query("
            UPDATE purchase_orders 
            SET supplier_id = :supplier_id,
                po_date = :po_date,
                expected_date = :expected_date,
                remarks = :remarks,
                total_amount = :total_amount,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $this->db->bind(':supplier_id', $data['supplier_id']);
        $this->db->bind(':po_date', $data['po_date']);
        $this->db->bind(':expected_date', $data['expected_date']);
        $this->db->bind(':remarks', $data['remarks']);
        $this->db->bind(':total_amount', $data['total_amount']);
        $this->db->bind(':id', $id);
        $this->db->execute();

        // Delete old items
        $this->db->query("DELETE FROM purchase_order_items WHERE purchase_order_id = :id");
        $this->db->bind(':id', $id);
        $this->db->execute();

        // Insert new items
        foreach ($items as $item) {
            $this->db->query("
                INSERT INTO purchase_order_items 
                (purchase_order_id, product_id, qty, rate, received_qty)
                VALUES (:po_id, :product_id, :qty, :rate, 0)
            ");
            $this->db->bind(':po_id', $id);
            $this->db->bind(':product_id', $item['product_id']);
            $this->db->bind(':qty', $item['qty']);
            $this->db->bind(':rate', $item['rate']);
            $this->db->execute();
        }

        $this->db->commit();
        return true;

    } catch (Exception $e) {
        $this->db->rollback();
        error_log("PO Update Error: " . $e->getMessage());
        return false;
    }
}

// ADD THIS METHOD at the end of PurchaseOrderModel.php (before the closing })
public function getFilteredPOs($filters = []) {
    $sql = "
        SELECT po.*, 
               s.supplier_name, 
               b.branch_name, 
               u.username as created_by_name
        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.id
        JOIN branches b ON po.branch_id = b.id
        LEFT JOIN users u ON po.created_by = u.id
    ";

    $where = [];
    $bindings = [];

    // ==================== DATE FILTER - MADE MANDATORY ====================
    
    $hasDateFilter = false;

    if (!empty($filters['date_from'])) {
        $where[] = "po.po_date >= :date_from";
        $bindings[':date_from'] = $filters['date_from'];
        $hasDateFilter = true;
    }

    if (!empty($filters['date_to'])) {
        $where[] = "po.po_date <= :date_to";
        $bindings[':date_to'] = $filters['date_to'];
        $hasDateFilter = true;
    }

    // If no date filter provided → Default to last 30 days (Safe for big data)
    if (!$hasDateFilter) {
        $where[] = "po.po_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $where[] = "po.po_date <= CURDATE()";
        
        // Also update filters array so view shows correct dates
        $filters['date_from'] = date('Y-m-d', strtotime('-30 days'));
        $filters['date_to']   = date('Y-m-d');
    }

    // Optional: Restrict maximum range to 90 days to prevent system hang
    if (!empty($filters['date_from']) && !empty($filters['date_to'])) {
        $from = strtotime($filters['date_from']);
        $to   = strtotime($filters['date_to']);
        $days = ($to - $from) / (60 * 60 * 24);
        
        if ($days > 90) {
            // Force max 90 days
            $filters['date_from'] = date('Y-m-d', strtotime($filters['date_to'] . ' -90 days'));
            $where = array_filter($where, fn($w) => strpos($w, 'po.po_date >=') === false);
            $where[] = "po.po_date >= :date_from";
            $bindings[':date_from'] = $filters['date_from'];
        }
    }

    // Global Search
    if (!empty($filters['search'])) {
        $term = '%' . $filters['search'] . '%';
        $where[] = "(
            po.po_code LIKE :term1 
            OR s.supplier_name LIKE :term2 
            OR u.username LIKE :term3 
            OR po.remarks LIKE :term4
            OR EXISTS (
                SELECT 1 FROM purchase_order_items poi 
                JOIN products p ON poi.product_id = p.id 
                WHERE poi.purchase_order_id = po.id 
                AND (p.product_name LIKE :term5 OR p.product_code LIKE :term6)
            )
        )";
        $bindings[':term1'] = $term;
        $bindings[':term2'] = $term;
        $bindings[':term3'] = $term;
        $bindings[':term4'] = $term;
        $bindings[':term5'] = $term;
        $bindings[':term6'] = $term;
    }

    // Status filter
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $where[] = "po.status = :status";
        $bindings[':status'] = $filters['status'];
    }

    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY po.po_date DESC, po.id DESC";   // Better sorting

    $this->db->query($sql);

    foreach ($bindings as $param => $value) {
        $this->db->bind($param, $value);
    }

    return $this->db->resultSet();
}

public function getPOForView($id) {
    // Get PO Header
    $this->db->query("
        SELECT po.*, 
               s.supplier_name, 
               s.supplier_code,
               b.branch_name,
               u.username as created_by_name

        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.id
        JOIN branches b ON po.branch_id = b.id
        LEFT JOIN users u ON po.created_by = u.id

        WHERE po.id = :id
    ");
    $this->db->bind(':id', $id);
    $po = $this->db->single();

    if ($po) {
        // Get Items
        $this->db->query("
            SELECT poi.*, 
                   p.product_code, 
                   p.product_name,
                   p.unit
            FROM purchase_order_items poi
            JOIN products p ON poi.product_id = p.id
            WHERE poi.purchase_order_id = :po_id
            ORDER BY poi.id ASC
        ");
        $this->db->bind(':po_id', $id);
        $po['items'] = $this->db->resultSet();

        // Calculate total from items (for verification)
        $total = 0;
        foreach ($po['items'] as &$item) {
            $item['amount'] = $item['qty'] * $item['rate'];
            $total += $item['amount'];
        }
        $po['calculated_total'] = $total;
    }

    return $po;
}

/**
 * Server-side DataTables for Purchase Orders
 * Follows the same pattern as LedgerModel and OtherExpenseModel
 */
public function getPurchaseOrdersForDataTable(array $params): array
{
    $start       = (int)($params['start'] ?? 0);
    $length      = (int)($params['length'] ?? 25);
    $searchValue = trim($params['search']['value'] ?? '');
    $orderColumn = (int)($params['order'][0]['column'] ?? 0);
    $orderDir    = $params['order'][0]['dir'] ?? 'desc';

    $filterStatus = $params['filterStatus'] ?? '';
    $filterSupplier = $params['filterSupplier'] ?? '';

    $columns = [
        'po.po_date',
        'po.po_code',
        's.supplier_name',
        'po.total_amount',
        'po.status',
        'u.username',
        'po.id'
    ];

    $baseQuery = "
        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.id
        JOIN branches b ON po.branch_id = b.id
        LEFT JOIN users u ON po.created_by = u.id
    ";

    $where = [];
    $bindParams = [];

    // Date filters (default to recent if not provided)
    if (!empty($params['date_from'])) {
        $where[] = "po.po_date >= :date_from";
        $bindParams[':date_from'] = $params['date_from'];
    }
    if (!empty($params['date_to'])) {
        $where[] = "po.po_date <= :date_to";
        $bindParams[':date_to'] = $params['date_to'];
    }

    // Status filter
    if (!empty($filterStatus) && $filterStatus !== 'all') {
        $where[] = "po.status = :status";
        $bindParams[':status'] = $filterStatus;
    }

    $showCancelled = !empty($params['showCancelled']);

    if (!$showCancelled) {
        // Default: exclude cancelled unless explicitly showing them
        $where[] = "po.status != 'cancelled'";
    } else {
        // When showing cancelled, only show cancelled ones
        $where[] = "po.status = 'cancelled'";
    }

    // Supplier filter
    if (!empty($filterSupplier)) {
        $where[] = "po.supplier_id = :supplier_id";
        $bindParams[':supplier_id'] = $filterSupplier;
    }

    // Global search
    if ($searchValue !== '') {
        $where[] = "(po.po_code LIKE :search OR s.supplier_name LIKE :search OR b.branch_name LIKE :search)";
        $bindParams[':search'] = "%{$searchValue}%";
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // Total records
    $totalQuery = "SELECT COUNT(po.id) as total {$baseQuery} {$whereSql}";
    $this->db->query($totalQuery);
    foreach ($bindParams as $key => $val) {
        $this->db->bind($key, $val);
    }
    $totalResult = $this->db->single();
    $recordsTotal = $totalResult['total'] ?? 0;

    // Filtered count
    $filteredQuery = "SELECT COUNT(po.id) as total {$baseQuery} {$whereSql}";
    $this->db->query($filteredQuery);
    foreach ($bindParams as $key => $val) {
        $this->db->bind($key, $val);
    }
    $filteredResult = $this->db->single();
    $recordsFiltered = $filteredResult['total'] ?? 0;

    // Data query
    $orderBy = $columns[$orderColumn] ?? 'po.po_date';
    $dataQuery = "
        SELECT 
            po.*,
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

}
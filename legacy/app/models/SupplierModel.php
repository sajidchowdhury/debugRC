<?php
// app/models/SupplierModel.php

require_once '../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';


class SupplierModel extends Helper{

    protected $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function getAllSuppliers() {
       
        return $this->Get_All_Supplier();
    }

    public function getSupplierById($id) {
        
        return $this->Get_Supplier_By_Id($id);
    }

    // Auto generate supplier code (sequential from latest code, not row count)
    private function generateSupplierCode(): string
    {
        $this->db->query("SELECT supplier_code FROM suppliers ORDER BY id DESC LIMIT 1");
        $row = $this->db->single();
        $nextNum = 1;
        if (!empty($row['supplier_code']) && preg_match('/S-(\d+)/', (string)$row['supplier_code'], $m)) {
            $nextNum = (int)$m[1] + 1;
        }
        return 'S-' . str_pad((string)$nextNum, 4, '0', STR_PAD_LEFT);
    }

    // Check mobile uniqueness
    public function mobileExists($mobile, $exclude_id = null) {
        $sql = "SELECT id FROM suppliers WHERE mobile = :mobile";
        if ($exclude_id) {
            $sql .= " AND id != :exclude_id";
        }
        $this->db->query($sql);
        $this->db->bind(':mobile', $mobile);
        if ($exclude_id) $this->db->bind(':exclude_id', $exclude_id);
        return $this->db->single() ? true : false;
    }

    /**
     * Validate supplier form input for create/update.
     *
     * @return array{ok: bool, error?: string, data?: array<string, mixed>}
     */
    public function validateSupplierPayload(array $input, ?int $excludeId = null): array
    {
        $supplierName = trim((string)($input['supplier_name'] ?? ''));
        $mobile = trim((string)($input['mobile'] ?? ''));
        $address = trim((string)($input['address'] ?? ''));

        if ($supplierName === '') {
            return ['ok' => false, 'error' => 'Supplier name is required.'];
        }
        if ($mobile === '') {
            return ['ok' => false, 'error' => 'Mobile number is required.'];
        }
        if ($this->mobileExists($mobile, $excludeId)) {
            return ['ok' => false, 'error' => 'This mobile number already exists!'];
        }

        $payload = [
            'supplier_name' => $supplierName,
            'mobile'        => $mobile,
            'address'       => $address,
        ];

        if ($excludeId !== null) {
            $existing = $this->getSupplierById($excludeId);
            if (!$existing) {
                return ['ok' => false, 'error' => 'Supplier not found.'];
            }

            $newActive = (int)($input['is_active'] ?? 1) === 1 ? 1 : 0;
            $wasActive = !empty($existing['is_active']);

            if ($wasActive && !$newActive) {
                $safety = $this->getDeactivationSafetyStatus($excludeId);
                if (!$safety['can_deactivate']) {
                    return ['ok' => false, 'error' => $this->getDeactivationMessage($excludeId)];
                }
            }

            $payload['is_active'] = $newActive;
        }

        return ['ok' => true, 'data' => $payload];
    }

    public function getDeactivationMessage(int $supplierId): string
    {
        $safety = $this->getDeactivationSafetyStatus($supplierId);
        $msg = 'Cannot deactivate this supplier.';
        if (!empty($safety['has_outstanding'])) {
            $msg .= ' Outstanding balance: ' . number_format((float)$safety['outstanding_balance'], 2);
        }
        if (!empty($safety['has_purchase_history'])) {
            $msg .= (!empty($safety['has_outstanding']) ? '. ' : ' ')
                . 'Has ' . number_format((int)$safety['purchase_count']) . ' purchase record(s).';
        }
        $msg .= ' Clear dues before changing status.';

        return $msg;
    }

    public function createSupplier(array $data): array
    {
        $validated = $this->validateSupplierPayload($data);
        if (!$validated['ok']) {
            return ['status' => 'error', 'message' => $validated['error']];
        }

        $payload = $validated['data'];
        $supplier_code = $this->generateSupplierCode();

        $this->db->query("
            INSERT INTO suppliers 
            (supplier_code, supplier_name, mobile, address, created_by)
            VALUES 
            (:supplier_code, :supplier_name, :mobile, :address, :created_by)
        ");

        $this->db->bind(':supplier_code', $supplier_code);
        $this->db->bind(':supplier_name', $payload['supplier_name']);
        $this->db->bind(':mobile', $payload['mobile']);
        $this->db->bind(':address', $payload['address']);
        $this->db->bind(':created_by', $_SESSION['user_id'] ?? 1);

        if ($this->db->execute()) {
            return [
                'status'        => 'success',
                'message'       => 'Supplier created successfully!',
                'id'            => (int)$this->db->lastInsertId(),
                'supplier_code' => $supplier_code,
            ];
        }

        return ['status' => 'error', 'message' => 'Failed to create supplier!'];
    }

    public function updateSupplier($id, array $data): array
    {
        $supplierId = (int)$id;
        $validated = $this->validateSupplierPayload($data, $supplierId);
        if (!$validated['ok']) {
            return ['status' => 'error', 'message' => $validated['error']];
        }

        $payload = $validated['data'];

        $this->db->query("
            UPDATE suppliers SET 
                supplier_name = :supplier_name,
                mobile = :mobile,
                address = :address,
                is_active = :is_active,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $this->db->bind(':supplier_name', $payload['supplier_name']);
        $this->db->bind(':mobile', $payload['mobile']);
        $this->db->bind(':address', $payload['address']);
        $this->db->bind(':is_active', $payload['is_active']);
        $this->db->bind(':id', $supplierId);

        if ($this->db->execute()) {
            return ['status' => 'success', 'message' => 'Supplier updated successfully!'];
        }

        return ['status' => 'error', 'message' => 'Failed to update supplier!'];
    }

    public function toggleStatus($id) {
        $this->db->query("UPDATE suppliers SET is_active = NOT is_active WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    /**
     * Soft delete (deactivate) a supplier
     */
    public function softDeleteSupplier($id) {
        $this->db->query("UPDATE suppliers SET is_active = 0 WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    /**
     * Restore a soft-deleted supplier
     */
    public function restoreSupplier($id) {
        $this->db->query("UPDATE suppliers SET is_active = 1 WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    // ============================================================
    // DELETE SAFETY (Phase 1 - Due Balance + Purchase History)
    // ============================================================

    /**
     * Returns current outstanding payable to the supplier (latest running_balance from ledger).
     * Positive value means we owe the supplier money.
     */
    public function getOutstandingBalance($supplierId): float
    {
        if (!$supplierId) return 0.0;
        // Use shared helper for consistency with customer + trans
        return (float) $this->Get_Supplier_Now_Due($supplierId);
    }

    /**
     * Returns number of purchase receives (primary purchase history indicator).
     */
    public function getPurchaseHistoryCount($supplierId): int
    {
        if (!$supplierId) return 0;

        $this->db->query("SELECT COUNT(*) as cnt FROM purchase_receives WHERE supplier_id = :sid");
        $this->db->bind(':sid', $supplierId);
        $row = $this->db->single();

        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Summary metrics for supplier index hero.
     */
    public function getSupplierIndexStats(): array
    {
        $stats = [
            'active'         => 0,
            'inactive'       => 0,
            'with_payable'   => 0,
            'total_payable'  => 0.0,
        ];

        $this->db->query('SELECT COUNT(*) AS c FROM suppliers WHERE is_active = 1');
        $stats['active'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COUNT(*) AS c FROM suppliers WHERE is_active = 0');
        $stats['inactive'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('
            SELECT COUNT(*) AS c,
                   COALESCE(SUM(sl.running_balance), 0) AS total_due
            FROM supplier_ledger sl
            INNER JOIN (
                SELECT supplier_id, MAX(id) AS max_id
                FROM supplier_ledger
                GROUP BY supplier_id
            ) latest ON sl.id = latest.max_id
            INNER JOIN suppliers s ON s.id = sl.supplier_id AND s.is_active = 1
            WHERE sl.running_balance > 0.009
        ');
        $row = $this->db->single() ?: [];
        $stats['with_payable'] = (int)($row['c'] ?? 0);
        $stats['total_payable'] = (float)($row['total_due'] ?? 0);

        return $stats;
    }

    /**
     * Payable and purchase snapshot for edit sidebar.
     */
    public function getSupplierUsage(int $supplierId): array
    {
        $safety = $this->getDeactivationSafetyStatus($supplierId);

        return [
            'outstanding_balance'  => (float)($safety['outstanding_balance'] ?? 0),
            'purchase_count'       => (int)($safety['purchase_count'] ?? 0),
            'can_deactivate'       => !empty($safety['can_deactivate']),
            'has_outstanding'      => !empty($safety['has_outstanding']),
            'has_purchase_history' => !empty($safety['has_purchase_history']),
        ];
    }

    /**
     * Safety check before deactivating a supplier.
     */
    public function getDeactivationSafetyStatus($supplierId): array
    {
        $balance = $this->getOutstandingBalance($supplierId);
        $purchaseCount = $this->getPurchaseHistoryCount($supplierId);

        $hasOutstanding = $balance > 0.009;
        $hasHistory = $purchaseCount > 0;

        return [
            'can_deactivate'       => !$hasOutstanding && !$hasHistory,
            'outstanding_balance'  => $balance,
            'purchase_count'       => $purchaseCount,
            'has_outstanding'      => $hasOutstanding,
            'has_purchase_history' => $hasHistory,
        ];
    }

    /**
     * Server-side DataTables for Suppliers
     */
    public function getSuppliersForDataTable(array $params): array
    {
        $start          = (int)($params['start'] ?? 0);
        $length         = (int)($params['length'] ?? 25);
        $searchValue    = trim($params['search']['value'] ?? '');
        $orderColumn    = (int)($params['order'][0]['column'] ?? 0);
        $orderDir       = $params['order'][0]['dir'] ?? 'asc';

        // Custom filters
        $filterStatus   = $params['filterStatus'] ?? ''; // 'active', 'inactive'
        $includeDeleted = !empty($params['includeDeleted']);

        $columns = [
            'supplier_code',
            'supplier_name',
            'mobile',
            'balance_due',
            'is_active',
        ];

        $baseQuery = " FROM suppliers ";

        $where = [];
        $bindParams = [];

        if (!$includeDeleted) {
            $where[] = "is_active = 1";
        } else {
            $where[] = "is_active = 0";
        }

        // Global search
        if ($searchValue !== '') {
            $where[] = "(supplier_name LIKE :search 
                      OR supplier_code LIKE :search 
                      OR mobile LIKE :search 
                      OR address LIKE :search)";
            $bindParams[':search'] = "%{$searchValue}%";
        }

        // Status filter
        if ($filterStatus === 'active' && !$includeDeleted) {
            $where[] = "is_active = 1";
        } elseif ($filterStatus === 'inactive') {
            $where[] = "is_active = 0";
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total records (unfiltered for this mode)
        $totalQuery = "SELECT COUNT(id) as total {$baseQuery}";
        if (!$includeDeleted) {
            $totalQuery .= " WHERE is_active = 1";
        } else {
            $totalQuery .= " WHERE is_active = 0";
        }
        $this->db->query($totalQuery);
        $totalResult = $this->db->single();
        $recordsTotal = $totalResult['total'] ?? 0;

        // Filtered records
        $filteredQuery = "SELECT COUNT(id) as total {$baseQuery} {$whereSql}";
        $this->db->query($filteredQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $filteredResult = $this->db->single();
        $recordsFiltered = $filteredResult['total'] ?? 0;

        // Data query
        $orderBy = $columns[$orderColumn] ?? 'supplier_name';
        $dataQuery = "
            SELECT id, supplier_code, supplier_name, mobile, address, is_active,
                (
                    SELECT COALESCE(running_balance, 0)
                    FROM supplier_ledger
                    WHERE supplier_id = suppliers.id
                    ORDER BY id DESC
                    LIMIT 1
                ) AS balance_due
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

    /**
     * Hub summary for supplier show page.
     */
    public function getSupplierHubSummary(int $supplierId): array
    {
        $usage = $this->getSupplierUsage($supplierId);

        $this->db->query('
            SELECT COUNT(*) AS c FROM supplier_payments
            WHERE supplier_id = :sid AND COALESCE(is_reversed, 0) = 0
        ');
        $this->db->bind(':sid', $supplierId);
        $paymentCount = (int)($this->db->single()['c'] ?? 0);

        return array_merge($usage, ['payment_count' => $paymentCount]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentLedgerEntries(int $supplierId, int $limit = 15): array
    {
        if ($supplierId <= 0) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $this->db->query("
            SELECT sl.id, sl.transaction_date, sl.reference_type, sl.reference_id,
                   sl.debit, sl.credit, sl.running_balance, sl.remarks,
                   b.branch_name
            FROM supplier_ledger sl
            LEFT JOIN branches b ON b.id = sl.branch_id
            WHERE sl.supplier_id = :sid
            ORDER BY sl.id DESC
            LIMIT {$limit}
        ");
        $this->db->bind(':sid', $supplierId);

        return $this->db->resultSet() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentPurchaseReceives(int $supplierId, int $limit = 10): array
    {
        if ($supplierId <= 0) {
            return [];
        }

        $limit = max(1, min(30, $limit));
        $this->db->query("
            SELECT pr.id, pr.receive_code, pr.receive_date, pr.total_amount, pr.status,
                   pr.is_reversed, br.branch_name
            FROM purchase_receives pr
            LEFT JOIN branches br ON br.id = pr.branch_id
            WHERE pr.supplier_id = :sid
            ORDER BY pr.receive_date DESC, pr.id DESC
            LIMIT {$limit}
        ");
        $this->db->bind(':sid', $supplierId);

        return $this->db->resultSet() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentPayments(int $supplierId, int $limit = 10): array
    {
        if ($supplierId <= 0) {
            return [];
        }

        $limit = max(1, min(30, $limit));
        $this->db->query("
            SELECT sp.id, sp.payment_code, sp.payment_date, sp.amount, sp.transaction_type,
                   sp.payment_mode, sp.is_reversed, br.branch_name
            FROM supplier_payments sp
            LEFT JOIN branches br ON br.id = sp.branch_id
            WHERE sp.supplier_id = :sid
            ORDER BY sp.payment_date DESC, sp.id DESC
            LIMIT {$limit}
        ");
        $this->db->bind(':sid', $supplierId);

        return $this->db->resultSet() ?: [];
    }
}
<?php
// app/models/WarehouseModel.php

require_once '../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';

class WarehouseModel extends Helper{

    protected $db;

    public const MAX_CODE_LENGTH = 20;
    public const MAX_NAME_LENGTH = 100;
    private const CODE_PATTERN = '/^[A-Za-z0-9\-_.]+$/';

    public function __construct() {
        $this->db = new Database();
    }

    // Get all warehouses with branch name
    public function getAllWarehouses() {
      
        return $this->Get_All_Warehouses();
    }

    // Get warehouses by branch (useful for dropdowns)
    public function getWarehousesByBranch($branch_id) {
        return $this->Get_Warehouse_By_Branch($branch_id) ;
    }

    public function belongsToBranch(int $warehouseId, int $branchId): bool
    {
        return $this->warehouseBelongsToBranch($warehouseId, $branchId);
    }

    public function getWarehouseById($id) {
        
        return $this->Get_Warehouse_By_Id($id);
    }

    public function createWarehouse($data) {
        $this->db->query("
            INSERT INTO warehouses 
            (warehouse_code, warehouse_name, branch_id, address, created_by)
            VALUES 
            (:warehouse_code, :warehouse_name, :branch_id, :address, :created_by)
        ");

        $this->db->bind(':warehouse_code', $data['warehouse_code']);
        $this->db->bind(':warehouse_name', $data['warehouse_name']);
        $this->db->bind(':branch_id', (int)$data['branch_id']);
        $this->db->bind(':address', $data['address'] ?? '');
        $this->db->bind(':created_by', $_SESSION['user_id'] ?? 1);

        if (!$this->db->execute()) {
            return null;
        }

        return (int)$this->db->lastInsertId();
    }

    public function updateWarehouse($id, $data) {
        $this->db->query("
            UPDATE warehouses SET 
                warehouse_code = :warehouse_code,
                warehouse_name = :warehouse_name,
                branch_id = :branch_id,
                address = :address,
                is_active = :is_active,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $this->db->bind(':warehouse_code', $data['warehouse_code']);
        $this->db->bind(':warehouse_name', $data['warehouse_name']);
        $this->db->bind(':branch_id', (int)$data['branch_id']);
        $this->db->bind(':address', $data['address'] ?? '');
        $this->db->bind(':is_active', (int)($data['is_active'] ?? 1));
        $this->db->bind(':id', $id);

        return $this->db->execute();
    }

    /**
     * @return array{ok: bool, error?: string, data?: array<string, mixed>}
     */
    public function validateWarehousePayload(array $input, ?int $excludeId = null): array
    {
        $code = strtoupper(trim((string)($input['warehouse_code'] ?? '')));
        $name = trim((string)($input['warehouse_name'] ?? ''));
        $branchId = (int)($input['branch_id'] ?? 0);
        $address = trim((string)($input['address'] ?? ''));

        if ($code === '') {
            return ['ok' => false, 'error' => 'Warehouse code is required.'];
        }
        if (strlen($code) > self::MAX_CODE_LENGTH) {
            return ['ok' => false, 'error' => 'Warehouse code must be at most ' . self::MAX_CODE_LENGTH . ' characters.'];
        }
        if (!preg_match(self::CODE_PATTERN, $code)) {
            return ['ok' => false, 'error' => 'Warehouse code may only contain letters, numbers, dash, dot, or underscore.'];
        }
        if ($this->warehouseCodeExists($code, $excludeId)) {
            return ['ok' => false, 'error' => 'Warehouse code already exists. Choose a unique code.'];
        }

        if ($name === '') {
            return ['ok' => false, 'error' => 'Warehouse name is required.'];
        }
        if (strlen($name) > self::MAX_NAME_LENGTH) {
            return ['ok' => false, 'error' => 'Warehouse name must be at most ' . self::MAX_NAME_LENGTH . ' characters.'];
        }

        if ($branchId <= 0 || !$this->isActiveBranch($branchId)) {
            return ['ok' => false, 'error' => 'Select a valid active branch.'];
        }

        $payload = [
            'warehouse_code' => $code,
            'warehouse_name' => $name,
            'branch_id'      => $branchId,
            'address'        => $address,
        ];

        if ($excludeId !== null) {
            $existing = $this->getWarehouseById($excludeId);
            if (!$existing) {
                return ['ok' => false, 'error' => 'Warehouse not found.'];
            }

            $newBranchId = $branchId;
            $oldBranchId = (int)($existing['branch_id'] ?? 0);
            if ($newBranchId !== $oldBranchId) {
                $branchChange = $this->canChangeBranch((int)$excludeId, $newBranchId);
                if (!$branchChange['ok']) {
                    return ['ok' => false, 'error' => $branchChange['error']];
                }
            }

            $newActive = (int)($input['is_active'] ?? 1) === 1 ? 1 : 0;
            $wasActive = !empty($existing['is_active']);

            if ($wasActive && !$newActive && !$this->canDeactivateWarehouse((int)$excludeId)) {
                return ['ok' => false, 'error' => $this->getDeactivationMessage((int)$excludeId)];
            }

            $payload['is_active'] = $newActive;
        }

        return ['ok' => true, 'data' => $payload];
    }

    public function warehouseCodeExists(string $code, ?int $excludeId = null): bool
    {
        $sql = 'SELECT id FROM warehouses WHERE warehouse_code = :code';
        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude';
        }
        $sql .= ' LIMIT 1';

        $this->db->query($sql);
        $this->db->bind(':code', $code);
        if ($excludeId !== null) {
            $this->db->bind(':exclude', $excludeId);
        }

        return (bool)$this->db->single();
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function canChangeBranch(int $warehouseId, int $newBranchId): array
    {
        $warehouse = $this->getWarehouseById($warehouseId);
        if (!$warehouse) {
            return ['ok' => false, 'error' => 'Warehouse not found.'];
        }

        if ((int)($warehouse['branch_id'] ?? 0) === $newBranchId) {
            return ['ok' => true];
        }

        if ($this->hasStock($warehouseId)) {
            return [
                'ok'    => false,
                'error' => 'Cannot change branch while stock remains in this warehouse. Transfer or adjust stock first.',
            ];
        }

        if ($this->hasPendingDispatches($warehouseId)) {
            return [
                'ok'    => false,
                'error' => 'Cannot change branch while pending dispatch lines exist on open invoices.',
            ];
        }

        return ['ok' => true];
    }

    public function toggleStatus($id) {
        $this->db->query("UPDATE warehouses SET is_active = NOT is_active WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    /**
     * Check if warehouse has any stock
     */
    public function hasStock($warehouseId) {
        $this->db->query("SELECT COUNT(*) as total FROM warehouse_stock WHERE warehouse_id = :wid AND qty > 0.0001");
        $this->db->bind(':wid', $warehouseId);
        $result = $this->db->single();
        return ($result['total'] ?? 0) > 0;
    }

    public function hasPendingDispatches(int $warehouseId): bool
    {
        $this->db->query("
            SELECT COUNT(*) AS total
            FROM sales_invoice_dispatches sid
            INNER JOIN sales_invoices si ON si.id = sid.sales_invoice_id
            WHERE sid.warehouse_id = :wid
              AND sid.ordered_qty > sid.dispatched_qty
              AND si.status NOT IN ('challan_completed', 'reversed')
              AND COALESCE(si.is_reversed, 0) = 0
        ");
        $this->db->bind(':wid', $warehouseId);
        return ((int)($this->db->single()['total'] ?? 0)) > 0;
    }

    public function hasActiveStockTake(int $warehouseId): bool
    {
        $this->db->query("
            SELECT COUNT(*) AS total
            FROM stock_take_warehouses stw
            INNER JOIN stock_take_sessions sts ON sts.id = stw.stock_take_session_id
            WHERE stw.warehouse_id = :wid
              AND sts.status IN ('draft', 'counting')
        ");
        $this->db->bind(':wid', $warehouseId);
        return ((int)($this->db->single()['total'] ?? 0)) > 0;
    }

    public function canDeactivateWarehouse(int $warehouseId): bool
    {
        return !$this->hasStock($warehouseId)
            && !$this->hasPendingDispatches($warehouseId)
            && !$this->hasActiveStockTake($warehouseId);
    }

    public function getDeactivationMessage(int $warehouseId): string
    {
        if ($this->hasStock($warehouseId)) {
            $stockCount = (float)$this->getWarehouseStockCount($warehouseId);
            return 'Cannot deactivate this warehouse. It still has '
                . number_format($stockCount, 2)
                . ' units of stock. Please move or adjust the stock first.';
        }

        if ($this->hasPendingDispatches($warehouseId)) {
            return 'Cannot deactivate this warehouse. Pending dispatch lines exist on open invoices.';
        }

        if ($this->hasActiveStockTake($warehouseId)) {
            return 'Cannot deactivate this warehouse while it is part of an active stock take session.';
        }

        return 'Cannot deactivate this warehouse.';
    }

    /**
     * Stock breakdown by product category (warehouse hub).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getWarehouseStockByCategory(int $warehouseId): array
    {
        $this->db->query('
            SELECT
                COALESCE(c.id, 0) AS category_id,
                COALESCE(c.category_name, "Uncategorized") AS label,
                COALESCE(SUM(ws.qty), 0) AS total_qty,
                COUNT(DISTINCT ws.product_id) AS product_lines
            FROM warehouse_stock ws
            INNER JOIN products p ON p.id = ws.product_id
            LEFT JOIN product_categories c ON c.id = p.category_id
            WHERE ws.warehouse_id = :id AND ws.qty > 0.0001
            GROUP BY c.id, c.category_name
            ORDER BY total_qty DESC, label ASC
        ');
        $this->db->bind(':id', $warehouseId);

        return $this->db->resultSet() ?: [];
    }

    /**
     * Stock breakdown by product group (warehouse hub).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getWarehouseStockByGroup(int $warehouseId): array
    {
        $this->db->query('
            SELECT
                COALESCE(g.id, 0) AS group_id,
                COALESCE(g.group_name, "Unassigned") AS label,
                COALESCE(SUM(ws.qty), 0) AS total_qty,
                COUNT(DISTINCT ws.product_id) AS product_lines
            FROM warehouse_stock ws
            INNER JOIN products p ON p.id = ws.product_id
            LEFT JOIN product_groups g ON g.id = p.group_id
            WHERE ws.warehouse_id = :id AND ws.qty > 0.0001
            GROUP BY g.id, g.group_name
            ORDER BY total_qty DESC, label ASC
        ');
        $this->db->bind(':id', $warehouseId);

        return $this->db->resultSet() ?: [];
    }

    /**
     * Paginated product stock search (warehouse hub — min 2 chars).
     *
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function searchWarehouseStock(int $warehouseId, string $search, int $page = 1, int $perPage = 20): array
    {
        $search = trim($search);
        $perPage = max(5, min(50, $perPage));
        $page = max(1, $page);

        if (strlen($search) < 2) {
            return [
                'rows'     => [],
                'total'    => 0,
                'page'     => $page,
                'pages'    => 0,
                'per_page' => $perPage,
            ];
        }

        $like = '%' . $search . '%';
        $offset = ($page - 1) * $perPage;

        $this->db->query('
            SELECT COUNT(*) AS c
            FROM warehouse_stock ws
            INNER JOIN products p ON p.id = ws.product_id
            LEFT JOIN product_categories c ON c.id = p.category_id
            LEFT JOIN product_groups g ON g.id = p.group_id
            WHERE ws.warehouse_id = :id AND ws.qty > 0.0001
              AND (
                  p.product_name LIKE :s1 OR p.product_code LIKE :s2
                  OR c.category_name LIKE :s3 OR g.group_name LIKE :s4
              )
        ');
        $this->db->bind(':id', $warehouseId);
        $this->db->bind(':s1', $like);
        $this->db->bind(':s2', $like);
        $this->db->bind(':s3', $like);
        $this->db->bind(':s4', $like);
        $total = (int)($this->db->single()['c'] ?? 0);
        $pages = $total > 0 ? (int)ceil($total / $perPage) : 0;

        if ($total === 0) {
            return [
                'rows'     => [],
                'total'    => 0,
                'page'     => $page,
                'pages'    => 0,
                'per_page' => $perPage,
            ];
        }

        $this->db->query("
            SELECT
                p.id AS product_id,
                p.product_code,
                p.product_name,
                p.unit,
                ws.qty,
                COALESCE(c.category_name, 'Uncategorized') AS category_name,
                COALESCE(g.group_name, 'Unassigned') AS group_name
            FROM warehouse_stock ws
            INNER JOIN products p ON p.id = ws.product_id
            LEFT JOIN product_categories c ON c.id = p.category_id
            LEFT JOIN product_groups g ON g.id = p.group_id
            WHERE ws.warehouse_id = :id AND ws.qty > 0.0001
              AND (
                  p.product_name LIKE :s1 OR p.product_code LIKE :s2
                  OR c.category_name LIKE :s3 OR g.group_name LIKE :s4
              )
            ORDER BY ws.qty DESC, p.product_name ASC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $this->db->bind(':id', $warehouseId);
        $this->db->bind(':s1', $like);
        $this->db->bind(':s2', $like);
        $this->db->bind(':s3', $like);
        $this->db->bind(':s4', $like);

        return [
            'rows'     => $this->db->resultSet() ?: [],
            'total'    => $total,
            'page'     => min($page, max(1, $pages)),
            'pages'    => $pages,
            'per_page' => $perPage,
        ];
    }

    /**
     * On-hand stock lines for warehouse hub (legacy — prefer search/breakdown).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getWarehouseStockLines(int $warehouseId, int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));

        $this->db->query("
            SELECT
                p.id AS product_id,
                p.product_code,
                p.product_name,
                p.unit,
                ws.qty
            FROM warehouse_stock ws
            INNER JOIN products p ON p.id = ws.product_id
            WHERE ws.warehouse_id = :id AND ws.qty > 0.0001
            ORDER BY ws.qty DESC, p.product_name ASC
            LIMIT {$limit}
        ");
        $this->db->bind(':id', $warehouseId);

        return $this->db->resultSet() ?: [];
    }

    /**
     * Recent inter-warehouse transfers involving this site.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentTransfersForWarehouse(int $warehouseId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        $this->db->query("
            SELECT
                wt.id,
                wt.transfer_code,
                wt.transfer_date,
                wt.status,
                wt.total_amount,
                wt.is_reversed,
                fw.warehouse_name AS from_warehouse,
                tw.warehouse_name AS to_warehouse,
                CASE
                    WHEN wt.from_warehouse_id = :wid THEN 'out'
                    ELSE 'in'
                END AS direction
            FROM warehouse_transfers wt
            INNER JOIN warehouses fw ON fw.id = wt.from_warehouse_id
            INNER JOIN warehouses tw ON tw.id = wt.to_warehouse_id
            WHERE wt.from_warehouse_id = :wid2 OR wt.to_warehouse_id = :wid3
            ORDER BY wt.transfer_date DESC, wt.id DESC
            LIMIT {$limit}
        ");
        $this->db->bind(':wid', $warehouseId);
        $this->db->bind(':wid2', $warehouseId);
        $this->db->bind(':wid3', $warehouseId);

        return $this->db->resultSet() ?: [];
    }

    /**
     * Recent stock adjustments for warehouse hub.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentAdjustmentsForWarehouse(int $warehouseId, int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));

        $this->db->query("
            SELECT
                sa.id,
                sa.adjustment_code,
                sa.adjustment_date,
                sa.adjustment_type,
                sa.total_amount,
                sa.is_reversed
            FROM stock_adjustments sa
            WHERE sa.warehouse_id = :id
            ORDER BY sa.adjustment_date DESC, sa.id DESC
            LIMIT {$limit}
        ");
        $this->db->bind(':id', $warehouseId);

        return $this->db->resultSet() ?: [];
    }

    /**
     * Get total stock quantity in a warehouse
     */
    public function getWarehouseStockCount($warehouseId) {
        $this->db->query("SELECT COALESCE(SUM(qty), 0) as total_qty FROM warehouse_stock WHERE warehouse_id = :wid");
        $this->db->bind(':wid', $warehouseId);
        $result = $this->db->single();
        return $result['total_qty'] ?? 0;
    }


    /**
     * Summary metrics for warehouse index hero.
     */
    public function getWarehouseIndexStats(): array
    {
        $stats = [
            'active'    => 0,
            'inactive'  => 0,
            'branches'  => 0,
            'stock_qty' => 0.0,
        ];

        $this->db->query('SELECT COUNT(*) AS c FROM warehouses WHERE is_active = 1');
        $stats['active'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COUNT(*) AS c FROM warehouses WHERE is_active = 0');
        $stats['inactive'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COUNT(DISTINCT branch_id) AS c FROM warehouses WHERE is_active = 1 AND branch_id IS NOT NULL');
        $stats['branches'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COALESCE(SUM(qty), 0) AS total FROM warehouse_stock WHERE qty > 0');
        $stats['stock_qty'] = (float)($this->db->single()['total'] ?? 0);

        return $stats;
    }

    /**
     * Stock snapshot for edit sidebar.
     */
    public function getWarehouseUsage(int $warehouseId): array
    {
        $this->db->query('
            SELECT COALESCE(SUM(qty), 0) AS total_qty,
                   COUNT(DISTINCT product_id) AS product_lines
            FROM warehouse_stock
            WHERE warehouse_id = :id AND qty > 0.0001
        ');
        $this->db->bind(':id', $warehouseId);
        $row = $this->db->single() ?: [];

        $pendingDispatches = 0;
        $this->db->query("
            SELECT COUNT(*) AS total
            FROM sales_invoice_dispatches sid
            INNER JOIN sales_invoices si ON si.id = sid.sales_invoice_id
            WHERE sid.warehouse_id = :id
              AND sid.ordered_qty > sid.dispatched_qty
              AND si.status NOT IN ('challan_completed', 'reversed')
              AND COALESCE(si.is_reversed, 0) = 0
        ");
        $this->db->bind(':id', $warehouseId);
        $pendingDispatches = (int)($this->db->single()['total'] ?? 0);

        $activeStockTake = 0;
        $this->db->query("
            SELECT COUNT(*) AS total
            FROM stock_take_warehouses stw
            INNER JOIN stock_take_sessions sts ON sts.id = stw.stock_take_session_id
            WHERE stw.warehouse_id = :id
              AND sts.status IN ('draft', 'counting')
        ");
        $this->db->bind(':id', $warehouseId);
        $activeStockTake = (int)($this->db->single()['total'] ?? 0);

        return [
            'total_qty'            => (float)($row['total_qty'] ?? 0),
            'product_lines'        => (int)($row['product_lines'] ?? 0),
            'has_stock'            => ((float)($row['total_qty'] ?? 0)) > 0.0001,
            'pending_dispatches'   => $pendingDispatches,
            'active_stock_takes'   => $activeStockTake,
        ];
    }

    /**
     * Server-side DataTables for Warehouses
     */
    public function getWarehousesForDataTable(array $params): array
    {
        $start          = (int)($params['start'] ?? 0);
        $length         = (int)($params['length'] ?? 25);
        $searchValue    = trim($params['search']['value'] ?? '');
        $orderColumn    = (int)($params['order'][0]['column'] ?? 0);
        $orderDir       = $params['order'][0]['dir'] ?? 'asc';

        // Custom filters
        $filterBranch   = $params['filterBranch'] ?? '';
        $includeDeleted = !empty($params['includeDeleted']);

        $columns = ['w.warehouse_code', 'w.warehouse_name', 'b.branch_name', 'w.address'];

        $baseQuery = "
            FROM warehouses w
            LEFT JOIN branches b ON w.branch_id = b.id
        ";

        $where = [];
        $bindParams = [];

        if (!$includeDeleted) {
            $where[] = "w.is_active = 1";
        } else {
            $where[] = "w.is_active = 0";
        }

        // Global search
        if ($searchValue !== '') {
            $where[] = "(w.warehouse_name LIKE :search 
                      OR w.warehouse_code LIKE :search 
                      OR w.address LIKE :search 
                      OR b.branch_name LIKE :search)";
            $bindParams[':search'] = "%{$searchValue}%";
        }

        // Branch filter
        if ($filterBranch) {
            $where[] = "w.branch_id = :branch";
            $bindParams[':branch'] = $filterBranch;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total records
        $totalQuery = "SELECT COUNT(w.id) as total {$baseQuery}";
        if (!$includeDeleted) {
            $totalQuery .= " WHERE w.is_active = 1";
        }
        $this->db->query($totalQuery);
        $totalResult = $this->db->single();
        $recordsTotal = $totalResult['total'] ?? 0;

        // Filtered records
        $filteredQuery = "SELECT COUNT(w.id) as total {$baseQuery} {$whereSql}";
        $this->db->query($filteredQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $filteredResult = $this->db->single();
        $recordsFiltered = $filteredResult['total'] ?? 0;

        // Data query
        $orderBy = $columns[$orderColumn] ?? 'w.warehouse_name';
        $dataQuery = "
            SELECT 
                w.id,
                w.warehouse_code,
                w.warehouse_name,
                w.address,
                w.is_active,
                b.branch_name,
                b.id AS branch_id,
                (
                    SELECT COALESCE(SUM(ws.qty), 0)
                    FROM warehouse_stock ws
                    WHERE ws.warehouse_id = w.id AND ws.qty > 0.0001
                ) AS stock_qty,
                (
                    SELECT COUNT(DISTINCT ws.product_id)
                    FROM warehouse_stock ws
                    WHERE ws.warehouse_id = w.id AND ws.qty > 0.0001
                ) AS product_lines
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
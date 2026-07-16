<?php
// app/models/BranchModel.php

require_once '../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';


class BranchModel extends Helper{

    protected $db;

    public const MAX_CODE_LENGTH = 20;
    public const MAX_NAME_LENGTH = 100;
    public const MAX_PHONE_LENGTH = 20;
    public const MAX_EMAIL_LENGTH = 100;
    private const CODE_PATTERN = '/^[A-Za-z0-9\-_.]+$/';

    public function __construct() {
        $this->db = new Database();
    }

       public function getAllBranches() {
   
        return $this->Get_All_Branches();
    }

    
    // Get all active branches
public function getAllActiveBranches() {
  
    return $this->Get_All_Active_Branches();
}
    // Get single branch
    public function getBranchById($id) {
        return $this->Get_Branch_By_Id($id);
    }

      public function createBranch($data) {
        $this->db->query("
            INSERT INTO branches 
            (branch_code, branch_name, address, phone, email, created_by)
            VALUES 
            (:branch_code, :branch_name, :address, :phone, :email, :created_by)
        ");

        $this->db->bind(':branch_code', $data['branch_code']);
        $this->db->bind(':branch_name', $data['branch_name']);
        $this->db->bind(':address', $data['address'] ?? '');
        $this->db->bind(':phone', $data['phone'] ?? '');
        $this->db->bind(':email', $data['email'] ?? '');
        $this->db->bind(':created_by', $_SESSION['user_id'] ?? 1);

        if (!$this->db->execute()) {
            return null;
        }

        return (int)$this->db->lastInsertId();
    }

    public function updateBranch($id, $data) {
        $this->db->query("
            UPDATE branches SET 
                branch_code = :branch_code,
                branch_name = :branch_name,
                address = :address,
                phone = :phone,
                email = :email,
                is_active = :is_active,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $this->db->bind(':branch_code', $data['branch_code']);
        $this->db->bind(':branch_name', $data['branch_name']);
        $this->db->bind(':address', $data['address'] ?? '');
        $this->db->bind(':phone', $data['phone'] ?? '');
        $this->db->bind(':email', $data['email'] ?? '');
        $this->db->bind(':is_active', (int)($data['is_active'] ?? 1));
        $this->db->bind(':id', $id);

        return $this->db->execute();
    }

    /**
     * Validate branch form input for create/update.
     *
     * @return array{ok: bool, error?: string, data?: array<string, mixed>}
     */
    public function validateBranchPayload(array $input, ?int $excludeId = null): array
    {
        $code = strtoupper(trim((string)($input['branch_code'] ?? '')));
        $name = trim((string)($input['branch_name'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $address = trim((string)($input['address'] ?? ''));

        if ($code === '') {
            return ['ok' => false, 'error' => 'Branch code is required.'];
        }
        if (strlen($code) > self::MAX_CODE_LENGTH) {
            return ['ok' => false, 'error' => 'Branch code must be at most ' . self::MAX_CODE_LENGTH . ' characters.'];
        }
        if (!preg_match(self::CODE_PATTERN, $code)) {
            return ['ok' => false, 'error' => 'Branch code may only contain letters, numbers, dash, dot, or underscore.'];
        }
        if ($this->branchCodeExists($code, $excludeId)) {
            return ['ok' => false, 'error' => 'Branch code already exists. Choose a unique code.'];
        }

        if ($name === '') {
            return ['ok' => false, 'error' => 'Branch name is required.'];
        }
        if (strlen($name) > self::MAX_NAME_LENGTH) {
            return ['ok' => false, 'error' => 'Branch name must be at most ' . self::MAX_NAME_LENGTH . ' characters.'];
        }

        if ($phone !== '' && strlen($phone) > self::MAX_PHONE_LENGTH) {
            return ['ok' => false, 'error' => 'Phone must be at most ' . self::MAX_PHONE_LENGTH . ' characters.'];
        }

        if ($email !== '') {
            if (strlen($email) > self::MAX_EMAIL_LENGTH) {
                return ['ok' => false, 'error' => 'Email must be at most ' . self::MAX_EMAIL_LENGTH . ' characters.'];
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'error' => 'Enter a valid email address or leave it blank.'];
            }
        }

        $payload = [
            'branch_code' => $code,
            'branch_name' => $name,
            'phone'       => $phone,
            'email'       => $email,
            'address'     => $address,
        ];

        if ($excludeId !== null) {
            $existing = $this->getBranchById($excludeId);
            if (!$existing) {
                return ['ok' => false, 'error' => 'Branch not found.'];
            }

            $newActive = (int)($input['is_active'] ?? 1) === 1 ? 1 : 0;
            $wasActive = !empty($existing['is_active']);

            if ($wasActive && !$newActive && !$this->canDeactivateBranch($excludeId)) {
                return ['ok' => false, 'error' => $this->getDeactivationMessage($excludeId)];
            }

            $payload['is_active'] = $newActive;
        }

        return ['ok' => true, 'data' => $payload];
    }

    public function branchCodeExists(string $code, ?int $excludeId = null): bool
    {
        $sql = 'SELECT id FROM branches WHERE branch_code = :code';
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

  
    public function toggleStatus($id) {
        $this->db->query("UPDATE branches SET is_active = NOT is_active WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    /**
     * Get counts of related active records for a branch
     */
    public function getBranchUsage($id) {
        $id = (int)$id;
        $usage = [
            'warehouses'      => 0,
            'employees'       => 0,
            'open_invoices'   => 0,
            'pending_demands' => 0,
            'active_users'    => 0,
        ];

        $this->db->query('SELECT COUNT(*) AS total FROM warehouses WHERE branch_id = :id AND is_active = 1');
        $this->db->bind(':id', $id);
        $usage['warehouses'] = (int)($this->db->single()['total'] ?? 0);

        $this->db->query('SELECT COUNT(*) AS total FROM employees WHERE branch_id = :id AND is_active = 1');
        $this->db->bind(':id', $id);
        $usage['employees'] = (int)($this->db->single()['total'] ?? 0);

        $this->db->query("
            SELECT COUNT(*) AS total
            FROM sales_invoices
            WHERE branch_id = :id
              AND status NOT IN ('challan_completed', 'reversed')
              AND COALESCE(is_reversed, 0) = 0
        ");
        $this->db->bind(':id', $id);
        $usage['open_invoices'] = (int)($this->db->single()['total'] ?? 0);

        $this->db->query("
            SELECT COUNT(*) AS total
            FROM branch_demands
            WHERE status = 'pending'
              AND (from_branch_id = :id OR to_branch_id = :id2)
        ");
        $this->db->bind(':id', $id);
        $this->db->bind(':id2', $id);
        $usage['pending_demands'] = (int)($this->db->single()['total'] ?? 0);

        $this->db->query("
            SELECT COUNT(*) AS total
            FROM users u
            INNER JOIN employees e ON e.id = u.employee_id
            WHERE e.branch_id = :id
              AND u.is_active = 1
              AND e.is_active = 1
              AND u.deleted_at IS NULL
        ");
        $this->db->bind(':id', $id);
        $usage['active_users'] = (int)($this->db->single()['total'] ?? 0);

        return $usage;
    }

    /**
     * Check if branch can be safely deactivated.
     */
    public function canDeactivateBranch($id) {
        $usage = $this->getBranchUsage((int)$id);

        return ($usage['warehouses'] === 0
            && $usage['employees'] === 0
            && $usage['open_invoices'] === 0
            && $usage['pending_demands'] === 0
            && $usage['active_users'] === 0);
    }

    public function getDeactivationMessage(int $id): string
    {
        $usage = $this->getBranchUsage($id);
        $parts = [];

        if ($usage['warehouses'] > 0) {
            $parts[] = $usage['warehouses'] . ' active warehouse(s)';
        }
        if ($usage['employees'] > 0) {
            $parts[] = $usage['employees'] . ' active employee(s)';
        }
        if ($usage['open_invoices'] > 0) {
            $parts[] = $usage['open_invoices'] . ' open sales invoice(s)';
        }
        if ($usage['pending_demands'] > 0) {
            $parts[] = $usage['pending_demands'] . ' pending branch demand(s)';
        }
        if ($usage['active_users'] > 0) {
            $parts[] = $usage['active_users'] . ' active user account(s)';
        }

        if ($parts === []) {
            return 'Cannot deactivate this branch.';
        }

        return 'Cannot deactivate this branch. It has ' . implode(', ', $parts) . '. Please resolve them first.';
    }

    /**
     * Branch hub: stock rollup across active warehouses.
     */
    public function getBranchStockSummary(int $branchId): array
    {
        $this->db->query('
            SELECT
                COALESCE(SUM(ws.qty), 0) AS total_qty,
                COUNT(DISTINCT CASE WHEN ws.qty > 0.0001 THEN ws.product_id END) AS product_lines
            FROM warehouses w
            LEFT JOIN warehouse_stock ws ON ws.warehouse_id = w.id
            WHERE w.branch_id = :id AND w.is_active = 1
        ');
        $this->db->bind(':id', $branchId);
        $row = $this->db->single() ?: [];

        return [
            'total_qty'     => (float)($row['total_qty'] ?? 0),
            'product_lines' => (int)($row['product_lines'] ?? 0),
        ];
    }

    /**
     * Warehouses linked to a branch (hub page).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBranchWarehouses(int $branchId, bool $activeOnly = true): array
    {
        $sql = '
            SELECT
                w.id,
                w.warehouse_code,
                w.warehouse_name,
                w.address,
                w.is_active,
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
            FROM warehouses w
            WHERE w.branch_id = :id
        ';
        if ($activeOnly) {
            $sql .= ' AND w.is_active = 1';
        }
        $sql .= ' ORDER BY w.warehouse_name ASC';

        $this->db->query($sql);
        $this->db->bind(':id', $branchId);

        return $this->db->resultSet() ?: [];
    }

    /**
     * Branch-wide stock by category (all active warehouses).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBranchStockByCategory(int $branchId): array
    {
        $this->db->query('
            SELECT
                COALESCE(c.id, 0) AS category_id,
                COALESCE(c.category_name, "Uncategorized") AS label,
                COALESCE(SUM(ws.qty), 0) AS total_qty,
                COUNT(DISTINCT ws.product_id) AS product_lines
            FROM warehouses w
            INNER JOIN warehouse_stock ws ON ws.warehouse_id = w.id AND ws.qty > 0.0001
            INNER JOIN products p ON p.id = ws.product_id
            LEFT JOIN product_categories c ON c.id = p.category_id
            WHERE w.branch_id = :id AND w.is_active = 1
            GROUP BY c.id, c.category_name
            ORDER BY total_qty DESC, label ASC
        ');
        $this->db->bind(':id', $branchId);

        return $this->db->resultSet() ?: [];
    }

    /**
     * Branch-wide stock by product group.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBranchStockByGroup(int $branchId): array
    {
        $this->db->query('
            SELECT
                COALESCE(g.id, 0) AS group_id,
                COALESCE(g.group_name, "Unassigned") AS label,
                COALESCE(SUM(ws.qty), 0) AS total_qty,
                COUNT(DISTINCT ws.product_id) AS product_lines
            FROM warehouses w
            INNER JOIN warehouse_stock ws ON ws.warehouse_id = w.id AND ws.qty > 0.0001
            INNER JOIN products p ON p.id = ws.product_id
            LEFT JOIN product_groups g ON g.id = p.group_id
            WHERE w.branch_id = :id AND w.is_active = 1
            GROUP BY g.id, g.group_name
            ORDER BY total_qty DESC, label ASC
        ');
        $this->db->bind(':id', $branchId);

        return $this->db->resultSet() ?: [];
    }

    /**
     * Active employees for branch hub (limited preview).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getBranchEmployeesPreview(int $branchId, int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));

        $this->db->query("
            SELECT id, employee_code, name, designation, role
            FROM employees
            WHERE branch_id = :id AND is_active = 1
            ORDER BY name ASC
            LIMIT {$limit}
        ");
        $this->db->bind(':id', $branchId);

        return $this->db->resultSet() ?: [];
    }

    /**
     * Summary metrics for branch index hero.
     */
    public function getBranchIndexStats(): array
    {
        $stats = [
            'active'     => 0,
            'inactive'   => 0,
            'warehouses' => 0,
            'employees'  => 0,
        ];

        $this->db->query('SELECT COUNT(*) AS c FROM branches WHERE is_active = 1');
        $stats['active'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COUNT(*) AS c FROM branches WHERE is_active = 0');
        $stats['inactive'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COUNT(*) AS c FROM warehouses WHERE is_active = 1');
        $stats['warehouses'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COUNT(*) AS c FROM employees WHERE is_active = 1');
        $stats['employees'] = (int)($this->db->single()['c'] ?? 0);

        return $stats;
    }

    /**
     * Server-side DataTables for Branches
     */
    public function getBranchesForDataTable(array $params): array
    {
        $start          = (int)($params['start'] ?? 0);
        $length         = (int)($params['length'] ?? 25);
        $searchValue    = trim($params['search']['value'] ?? '');
        $orderColumn    = (int)($params['order'][0]['column'] ?? 0);
        $orderDir       = $params['order'][0]['dir'] ?? 'asc';

        // Custom filter: status + deleted mode
        $filterStatus   = $params['filterStatus'] ?? '';
        $includeDeleted = !empty($params['includeDeleted']); // for "Show Deleted" mode

        $columns = ['b.branch_code', 'b.branch_name', 'b.address', 'b.phone', 'b.email', 'b.is_active'];

        $baseQuery = "FROM branches b";

        $where = [];
        $bindParams = [];

        if (!$includeDeleted) {
            $where[] = "b.is_active = 1";
        } else {
            $where[] = "b.is_active = 0";
        }

        // Additional status filter (can be used together with deleted mode)
        if ($filterStatus === 'active' && !$includeDeleted) {
            $where[] = "b.is_active = 1";
        } elseif ($filterStatus === 'inactive' && !$includeDeleted) {
            $where[] = "b.is_active = 0";
        }

        // Global search
        if ($searchValue !== '') {
            $where[] = "(b.branch_name LIKE :search 
                      OR b.branch_code LIKE :search 
                      OR b.address LIKE :search 
                      OR b.phone LIKE :search 
                      OR b.email LIKE :search)";
            $bindParams[':search'] = "%{$searchValue}%";
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total records (without filters)
        $totalQuery = "SELECT COUNT(b.id) as total {$baseQuery}";
        $this->db->query($totalQuery);
        $totalResult = $this->db->single();
        $recordsTotal = $totalResult['total'] ?? 0;

        // Filtered records
        $filteredQuery = "SELECT COUNT(b.id) as total {$baseQuery} {$whereSql}";
        $this->db->query($filteredQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $filteredResult = $this->db->single();
        $recordsFiltered = $filteredResult['total'] ?? 0;

        // Data query
        $orderBy = $columns[$orderColumn] ?? 'b.branch_name';
        $dataQuery = "
            SELECT 
                b.id,
                b.branch_code,
                b.branch_name,
                b.address,
                b.phone,
                b.email,
                b.is_active,
                (
                    SELECT COUNT(*)
                    FROM warehouses w
                    WHERE w.branch_id = b.id AND w.is_active = 1
                ) AS warehouse_count,
                (
                    SELECT COUNT(*)
                    FROM employees e
                    WHERE e.branch_id = b.id AND e.is_active = 1
                ) AS employee_count
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
<?php
// app/models/EmployeeModel.php

require_once '../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';
require_once __DIR__ . '/../../core/RoleRegistry.php';


class EmployeeModel extends Helper {

    protected $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function getAllEmployees(bool $includeDeleted = false) {
        $employees = $this->All_Employees($includeDeleted);

        // Enrich with user account info for safety indicators
        foreach ($employees as &$emp) {
            $emp['has_user_account'] = $this->hasUserAccount($emp['id']);
            $emp['has_active_user']  = $this->hasActiveUserAccount($emp['id']);
        }

        return $employees;
    }

    /**
     * Active employees that do not yet have a linked system user account.
     */
    public function getEmployeesWithoutUserAccount(): array
    {
        $this->db->query("
            SELECT e.id, e.name, e.employee_code, e.role, b.branch_name
            FROM employees e
            LEFT JOIN users u ON u.employee_id = e.id AND u.deleted_at IS NULL
            LEFT JOIN branches b ON b.id = e.branch_id
            WHERE u.id IS NULL
              AND e.deleted_at IS NULL
            ORDER BY e.name ASC
        ");

        return $this->db->resultSet();
    }

    /**
     * Summary metrics for employee index hero.
     */
    public function getEmployeeIndexStats(): array
    {
        $empNotDeleted = "e.deleted_at IS NULL";
        $stats = [
            'active'    => 0,
            'inactive'  => 0,
            'with_user' => 0,
            'no_user'   => 0,
        ];

        $this->db->query("SELECT COUNT(*) AS c FROM employees e WHERE e.is_active = 1 AND {$empNotDeleted}");
        $stats['active'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query("SELECT COUNT(*) AS c FROM employees e WHERE e.is_active = 0 AND {$empNotDeleted}");
        $stats['inactive'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query("
            SELECT COUNT(DISTINCT e.id) AS c
            FROM employees e
            INNER JOIN users u ON u.employee_id = e.id
            WHERE {$empNotDeleted}
        ");
        $stats['with_user'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query("
            SELECT COUNT(*) AS c
            FROM employees e
            LEFT JOIN users u ON u.employee_id = e.id
            WHERE u.id IS NULL AND {$empNotDeleted}
        ");
        $stats['no_user'] = (int)($this->db->single()['c'] ?? 0);

        return $stats;
    }

    /**
     * Linked user and reference snapshot for edit sidebar.
     */
    public function getEmployeeUsage(int $employeeId): array
    {
        $refs = $this->getReferenceCounts($employeeId);

        $userId = null;
        $this->db->query('SELECT id FROM users WHERE employee_id = :id ORDER BY id DESC LIMIT 1');
        $this->db->bind(':id', $employeeId);
        $userRow = $this->db->single();
        if ($userRow) {
            $userId = (int)$userRow['id'];
        }

        return [
            'has_user_account'  => $this->hasUserAccount($employeeId),
            'has_active_user'   => $this->hasActiveUserAccount($employeeId),
            'user_id'           => $userId,
            'users'             => (int)($refs['users'] ?? 0),
            'customers'         => (int)($refs['customers'] ?? 0),
            'sales_invoices'    => (int)($refs['sales_invoices'] ?? 0),
        ];
    }

    /**
     * Server-side data for DataTables (pagination, search, filters)
     */
    public function getEmployeesForDataTable(array $params): array
    {
        $start          = (int)($params['start'] ?? 0);
        $length         = (int)($params['length'] ?? 25);
        $searchValue    = trim($params['search']['value'] ?? '');
        $orderColumn    = (int)($params['order'][0]['column'] ?? 0);
        $orderDir       = strtolower((string)($params['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        // Custom filters
        $filterBranch   = $params['filterBranch'] ?? '';
        $filterRole     = $params['filterRole'] ?? '';
        $filterStatus   = $params['filterStatus'] ?? '';
        $filterUser     = $params['filterUser'] ?? '';
        $includeDeleted = !empty($params['includeDeleted']);

        $columns = ['e.employee_code', 'e.name', 'e.mobile', 'b.branch_name', 'e.designation', 'e.role', 'e.is_active', 'e.photo'];
        // Note: photo column is not orderable in UI but included for safety in switch

        // Base query
        $baseQuery = "
            FROM employees e
            LEFT JOIN branches b ON e.branch_id = b.id
            LEFT JOIN users u ON u.employee_id = e.id
        ";

        $where = [];
        $bindParams = [];

        if (!$includeDeleted) {
            $where[] = "e.deleted_at IS NULL";
        }

        // Global search
        if ($searchValue !== '') {
            $where[] = "(e.name LIKE :search OR e.employee_code LIKE :search OR e.mobile LIKE :search)";
            $bindParams[':search'] = "%{$searchValue}%";
        }

        // Custom filters
        if ($filterBranch) {
            $where[] = "b.branch_name = :branch";
            $bindParams[':branch'] = $filterBranch;
        }

        if ($filterRole) {
            $where[] = "e.role = :role";
            $bindParams[':role'] = $filterRole;
        }

        if ($filterStatus === 'active') {
            $where[] = "e.is_active = 1";
        } elseif ($filterStatus === 'inactive') {
            $where[] = "e.is_active = 0";
        }

        if ($filterUser === 'active_user') {
            $where[] = "u.is_active = 1";
        } elseif ($filterUser === 'inactive_user') {
            $where[] = "(u.is_active = 0 AND u.id IS NOT NULL)";
        } elseif ($filterUser === 'no_user') {
            $where[] = "u.id IS NULL";
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total records (without filters)
        $totalQuery = "SELECT COUNT(DISTINCT e.id) as total {$baseQuery}";
        if (!$includeDeleted) {
            $totalQuery .= " WHERE e.deleted_at IS NULL";
        }
        $this->db->query($totalQuery);
        $totalResult = $this->db->single();
        $recordsTotal = $totalResult['total'] ?? 0;

        // Filtered records
        $filteredQuery = "SELECT COUNT(DISTINCT e.id) as total {$baseQuery} {$whereSql}";
        $this->db->query($filteredQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $filteredResult = $this->db->single();
        $recordsFiltered = $filteredResult['total'] ?? 0;

        // Data query with ordering and pagination
        $orderBy = $columns[$orderColumn] ?? 'e.name';
        $dataQuery = "
            SELECT 
                e.id, e.employee_code, e.name, e.mobile, e.designation, e.role, 
                e.is_active, e.joining_date, e.salary, e.photo,
                b.branch_name,
                (SELECT COUNT(*) FROM users WHERE employee_id = e.id AND is_active = 1) AS has_active_user,
                (SELECT COUNT(*) FROM users WHERE employee_id = e.id) AS has_user_account,
                (SELECT id FROM users WHERE employee_id = e.id ORDER BY id DESC LIMIT 1) AS user_id
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

     public function getEmployeeById($id) {
    return $this->Get_Employee_By_Id($id);    
    }

    /**
     * Validate and normalize employee create/update payload.
     *
     * @return array{status: string, message?: string, data?: array<string, mixed>}
     */
    public function validateEmployeePayload(array $data, ?int $excludeEmployeeId = null): array
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            return ['status' => 'error', 'message' => 'Full name is required.'];
        }
        if (mb_strlen($name) > 100) {
            return ['status' => 'error', 'message' => 'Full name must be 100 characters or fewer.'];
        }

        $mobile = preg_replace('/\s+/', '', trim((string)($data['mobile'] ?? '')));
        if ($mobile === '') {
            return ['status' => 'error', 'message' => 'Mobile number is required.'];
        }
        if (!preg_match('/^[0-9+\-()]{6,20}$/', $mobile)) {
            return ['status' => 'error', 'message' => 'Enter a valid mobile number.'];
        }

        $email = strtolower(trim((string)($data['email'] ?? '')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'error', 'message' => 'Enter a valid email address.'];
        }
        if ($email !== '' && mb_strlen($email) > 100) {
            return ['status' => 'error', 'message' => 'Email must be 100 characters or fewer.'];
        }

        $branchId = (int)($data['branch_id'] ?? 0);
        if ($branchId <= 0) {
            return ['status' => 'error', 'message' => 'Please select a branch.'];
        }

        require_once __DIR__ . '/BranchModel.php';
        $branchModel = new BranchModel();
        $branch = $branchModel->getBranchById($branchId);
        if (!$branch || empty($branch['is_active'])) {
            return ['status' => 'error', 'message' => 'Selected branch is invalid or inactive.'];
        }

        $role = RoleRegistry::normalize((string)($data['role'] ?? ''));
        if ($role === '' || !RoleRegistry::isValid($role)) {
            return ['status' => 'error', 'message' => 'Please select a valid role.'];
        }

        if ($this->mobileExists($mobile, $excludeEmployeeId)) {
            return ['status' => 'error', 'message' => 'This mobile number is already assigned to another employee.'];
        }

        if ($email !== '' && $this->emailExists($email, $excludeEmployeeId)) {
            return ['status' => 'error', 'message' => 'This email is already assigned to another employee.'];
        }

        $salary = $data['salary'] ?? 0;
        if ($salary !== '' && $salary !== null && !is_numeric($salary)) {
            return ['status' => 'error', 'message' => 'Salary must be a valid number.'];
        }
        $salary = round((float)$salary, 2);
        if ($salary < 0) {
            return ['status' => 'error', 'message' => 'Salary cannot be negative.'];
        }

        foreach (['date_of_birth' => 'date of birth', 'joining_date' => 'joining date'] as $field => $label) {
            $value = trim((string)($data[$field] ?? ''));
            if ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                return ['status' => 'error', 'message' => "Invalid {$label}."];
            }
        }

        $normalized = $data;
        $normalized['name'] = $name;
        $normalized['mobile'] = $mobile;
        $normalized['email'] = $email !== '' ? $email : null;
        $normalized['branch_id'] = $branchId;
        $normalized['role'] = $role;
        $normalized['salary'] = $salary;
        $normalized['father_name'] = $this->nullableString($data['father_name'] ?? null, 100);
        $normalized['mother_name'] = $this->nullableString($data['mother_name'] ?? null, 100);
        $normalized['nid'] = $this->nullableString($data['nid'] ?? null, 30);
        $normalized['address'] = $this->nullableString($data['address'] ?? null, 65535);
        $normalized['designation'] = $this->nullableString($data['designation'] ?? null, 100);
        $normalized['department'] = $this->nullableString($data['department'] ?? null, 100);
        $normalized['bank_account'] = $this->nullableString($data['bank_account'] ?? null, 50);
        $normalized['blood_group'] = $this->nullableString($data['blood_group'] ?? null, 10);
        $normalized['date_of_birth'] = $this->nullableString($data['date_of_birth'] ?? null, 10);
        $normalized['joining_date'] = $this->nullableString($data['joining_date'] ?? null, 10);
        $normalized['is_active'] = (int)($data['is_active'] ?? 1) === 1 ? 1 : 0;

        return ['status' => 'success', 'data' => $normalized];
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    private function mobileExists(string $mobile, ?int $excludeEmployeeId = null): bool
    {
        $sql = 'SELECT id FROM employees WHERE mobile = :mobile AND deleted_at IS NULL';
        if ($excludeEmployeeId) {
            $sql .= ' AND id != :exclude_id';
        }
        $sql .= ' LIMIT 1';

        $this->db->query($sql);
        $this->db->bind(':mobile', $mobile);
        if ($excludeEmployeeId) {
            $this->db->bind(':exclude_id', $excludeEmployeeId);
        }

        return (bool)$this->db->single();
    }

    private function emailExists(string $email, ?int $excludeEmployeeId = null): bool
    {
        $sql = 'SELECT id FROM employees WHERE LOWER(email) = :email AND deleted_at IS NULL';
        if ($excludeEmployeeId) {
            $sql .= ' AND id != :exclude_id';
        }
        $sql .= ' LIMIT 1';

        $this->db->query($sql);
        $this->db->bind(':email', strtolower($email));
        if ($excludeEmployeeId) {
            $this->db->bind(':exclude_id', $excludeEmployeeId);
        }

        return (bool)$this->db->single();
    }

    /**
     * Bump linked user credential stamp so other sessions must re-login.
     */
    public function touchLinkedUserCredential(int $employeeId): void
    {
        require_once __DIR__ . '/../../core/CredentialVersion.php';
        CredentialVersion::bumpForEmployee($employeeId);
    }

    /**
     * Refresh session credential stamp for the logged-in user linked to an employee.
     */
    public function refreshSessionCredentialForEmployee(int $employeeId): void
    {
        if ($employeeId !== (int)($_SESSION['employee_id'] ?? 0)) {
            return;
        }

        require_once __DIR__ . '/../../core/CredentialVersion.php';
        CredentialVersion::syncSession((int)($_SESSION['user_id'] ?? 0));

        $this->db->query('
            SELECT u.username
            FROM users u
            WHERE u.employee_id = :employee_id
              AND u.deleted_at IS NULL
            ORDER BY u.id DESC
            LIMIT 1
        ');
        $this->db->bind(':employee_id', $employeeId);
        $row = $this->db->single();

        if (!$row) {
            return;
        }

        $_SESSION['username'] = (string)($row['username'] ?? $_SESSION['username'] ?? '');
    }


    public function generateEmployeeCode()
    {
    $this->db->query("SELECT MAX(CAST(employee_code AS UNSIGNED)) as max_code FROM employees");
    $row = $this->db->single();

    $next = ($row['max_code'] ?? 0) + 1;

    return str_pad($next, 4, '0', STR_PAD_LEFT); // 0001, 0002
    }


   public function createEmployee($data) {

    $code = $this->generateEmployeeCode();

    $this->db->query("
        INSERT INTO employees 
        (employee_code, name, father_name, mother_name, date_of_birth, nid, mobile, email,
         address, branch_id, designation, role, 
         joining_date, department, salary, bank_account, blood_group, created_by)
        VALUES 
        (:code, :name, :father, :mother, :dob, :nid, :mobile, :email,
         :address, :branch, :desig, :role,
         :joining, :dept, :salary, :bank, :blood, :created_by)
    ");

    $this->db->bind(':code', $code);
    $this->db->bind(':name', $data['name']);
    $this->db->bind(':father', $data['father_name'] ?? null);
    $this->db->bind(':mother', $data['mother_name'] ?? null);
    $this->db->bind(':dob', $data['date_of_birth'] ?? null);
    $this->db->bind(':nid', $data['nid'] ?? null);
    $this->db->bind(':mobile', $data['mobile'] ?? null);
    $this->db->bind(':email', $data['email'] ?? null);
    $this->db->bind(':address', $data['address'] ?? null);
    $this->db->bind(':branch', $data['branch_id']);
    $this->db->bind(':desig', $data['designation'] ?? null);
    $this->db->bind(':role', $data['role']);
    $this->db->bind(':joining', $data['joining_date'] ?? null);
    $this->db->bind(':dept', $data['department'] ?? null);
    $this->db->bind(':salary', $data['salary'] ?? 0.00);
    $this->db->bind(':bank', $data['bank_account'] ?? null);
    $this->db->bind(':blood', $data['blood_group'] ?? null);
    $createdBy = (int)($_SESSION['user_id'] ?? 0);
    if ($createdBy <= 0) {
        return false;
    }
    $this->db->bind(':created_by', $createdBy);

    if ($this->db->execute()) {
        return $this->db->lastInsertId();
    }

    return false;
}

    // Update employee
    public function updateEmployee($id, $data) {
        $sql = "
            UPDATE employees SET 
                name = :name,
                father_name = :father,
                mother_name = :mother,
                date_of_birth = :dob,
                nid = :nid,
                mobile = :mobile,
                email = :email,
                address = :address,
                branch_id = :branch,
                department = :dept,
                designation = :desig,
                role = :role,
                joining_date = :joining,
                salary = :salary,
                bank_account = :bank,
                blood_group = :blood,
                is_active = :active
        ";

        // Update photo if key present (supports setting to empty string to clear it)
        $hasPhotoKey = array_key_exists('photo', $data);
        if ($hasPhotoKey) {
            $sql .= ", photo = :photo";
        }

        $sql .= " WHERE id = :id";

        $this->db->query($sql);

        $this->db->bind(':name', $data['name']);
        $this->db->bind(':father', $data['father_name'] ?? null);
        $this->db->bind(':mother', $data['mother_name'] ?? null);
        $this->db->bind(':dob', $data['date_of_birth'] ?? null);
        $this->db->bind(':nid', $data['nid'] ?? null);
        $this->db->bind(':mobile', $data['mobile'] ?? null);
        $this->db->bind(':email', $data['email'] ?? null);
        $this->db->bind(':address', $data['address'] ?? null);
        $this->db->bind(':branch', $data['branch_id']);
        $this->db->bind(':dept', $data['department'] ?? null);
        $this->db->bind(':desig', $data['designation'] ?? null);
        $this->db->bind(':role', $data['role']);
        $this->db->bind(':joining', $data['joining_date'] ?? null);
        $this->db->bind(':salary', $data['salary'] ?? 0.00);
        $this->db->bind(':bank', $data['bank_account'] ?? null);
        $this->db->bind(':blood', $data['blood_group'] ?? null);
        $this->db->bind(':active', $data['is_active'] ?? 1);
        $this->db->bind(':id', $id);

        if ($hasPhotoKey) {
            $photoVal = $data['photo'];
            $this->db->bind(':photo', ($photoVal === '' ? null : $photoVal));
        }

        return $this->db->execute();
    }

    // Toggle Active / Inactive
    public function toggleStatus($id) {
        // Check for active user account
        if ($this->hasActiveUserAccount($id)) {
            return false;
        }

        // Optional: You can also block deactivation if there are historical references
        // For now we only block on active user accounts for safety

        $this->db->query("UPDATE employees SET is_active = NOT is_active WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    /**
     * Check if employee has any user account (active or inactive)
     */
    public function hasUserAccount($employeeId) {
        $this->db->query("SELECT id FROM users WHERE employee_id = :emp_id LIMIT 1");
        $this->db->bind(':emp_id', $employeeId);
        return $this->db->single() ? true : false;
    }

    /**
     * Soft delete an employee (recommended)
     * Also cleans up photo file to save disk space and avoid orphaned files.
     */
    public function softDeleteEmployee(int $id, int $deletedBy): bool
    {
        // Strong safety: Block if has active user OR significant historical references
        if ($this->hasActiveUserAccount($id)) {
            return false;
        }

        $references = $this->getReferenceCounts($id);
        if ($references['sales_invoices'] > 0 || $references['customers'] > 0) {
            // Has important historical data — block soft delete for now
            return false;
        }

        // Fetch current photo before deleting record data
        $employee = $this->getEmployeeById($id);
        if (!empty($employee['photo'])) {
            $this->deleteEmployeePhoto($employee['photo']);
        }

        $this->db->query("
            UPDATE employees 
            SET deleted_at = NOW(), 
                is_active = 0,
                deleted_by = :deleted_by,
                photo = NULL
            WHERE id = :id
        ");
        $this->db->bind(':id', $id);
        $this->db->bind(':deleted_by', $deletedBy);

        return $this->db->execute();
    }

    public function restoreEmployee(int $id): bool
    {
        $this->db->query("
            UPDATE employees 
            SET deleted_at = NULL, 
                deleted_by = NULL,
                is_active = 1
            WHERE id = :id
        ");
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    private function hasActiveUserAccount($employeeId): bool
    {
        $this->db->query("
            SELECT id FROM users 
            WHERE employee_id = :emp_id AND is_active = 1 
            LIMIT 1
        ");
        $this->db->bind(':emp_id', $employeeId);
        return $this->db->single() ? true : false;
    }

    /**
     * Check if employee is referenced in other important tables (sales, customers, etc.)
     */
    public function hasHistoricalReferences($employeeId): bool
    {
        // Check customers as sales person
        $this->db->query("SELECT id FROM customers WHERE sales_person_id = :id LIMIT 1");
        $this->db->bind(':id', $employeeId);
        if ($this->db->single()) return true;

        // Check sales invoices as salesman
        $this->db->query("SELECT id FROM sales_invoices WHERE salesman_id = :id LIMIT 1");
        $this->db->bind(':id', $employeeId);
        if ($this->db->single()) return true;

        // Check employee ledger
        $this->db->query("SELECT id FROM employee_ledger WHERE employee_id = :id LIMIT 1");
        $this->db->bind(':id', $employeeId);
        if ($this->db->single()) return true;

        // Check customer payments collected by
        $this->db->query("SELECT id FROM customer_payments WHERE collected_by = :id LIMIT 1");
        $this->db->bind(':id', $employeeId);
        if ($this->db->single()) return true;

        return false;
    }

    /**
     * Get count of important references for better error messages
     */
    public function getReferenceCounts($employeeId): array
    {
        $counts = [
            'users' => 0,
            'customers' => 0,
            'sales_invoices' => 0,
            'employee_ledger' => 0,
        ];

        $this->db->query("SELECT COUNT(*) as cnt FROM users WHERE employee_id = :id");
        $this->db->bind(':id', $employeeId);
        $counts['users'] = (int)($this->db->single()['cnt'] ?? 0);

        $this->db->query("SELECT COUNT(*) as cnt FROM customers WHERE sales_person_id = :id");
        $this->db->bind(':id', $employeeId);
        $counts['customers'] = (int)($this->db->single()['cnt'] ?? 0);

        $this->db->query("SELECT COUNT(*) as cnt FROM sales_invoices WHERE salesman_id = :id");
        $this->db->bind(':id', $employeeId);
        $counts['sales_invoices'] = (int)($this->db->single()['cnt'] ?? 0);

        return $counts;
    }

    /**
     * Handle photo upload for employee
     * Returns the relative path on success, null on failure or no upload
     * Uses real MIME detection (not client-reported) for security
     */
    public function uploadEmployeePhoto(array $file, string $employeeCode): ?string
    {
        if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $maxSize = 2 * 1024 * 1024; // 2MB
        if ($file['size'] > $maxSize) {
            return null; // Too large
        }

        // Real MIME type detection (secure against spoofing)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp'
        ];

        if (!isset($allowedMimes[$mime])) {
            return null; // Invalid image type
        }

        $uploadDir = __DIR__ . '/../../public/uploads/employees/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Safe filename
        $safeCode = preg_replace('/[^a-zA-Z0-9]/', '', $employeeCode);
        $extension = $allowedMimes[$mime];
        $filename = 'emp_' . ($safeCode ?: 'unk') . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $destination = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            // Verify it's a real image after move
            $imgInfo = @getimagesize($destination);
            if ($imgInfo === false) {
                @unlink($destination);
                return null;
            }
            return 'uploads/employees/' . $filename; // Relative path for DB + browser
        }

        return null;
    }

    /**
     * Delete old photo if exists
     */
    public function deleteEmployeePhoto(?string $photoPath): void
    {
        if (!$photoPath) return;

        $fullPath = __DIR__ . '/../../public/' . $photoPath;
        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
    }

    /**
     * Update only the photo path for an employee
     */
    public function updateEmployeePhoto(int $id, string $photoPath): bool
    {
        $this->db->query("UPDATE employees SET photo = :photo WHERE id = :id");
        $this->db->bind(':photo', $photoPath);
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }
}
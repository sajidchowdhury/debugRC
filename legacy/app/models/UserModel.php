<?php
// app/models/UserModel.php

require_once '../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';
require_once __DIR__ . '/../../core/PasswordPolicy.php';
require_once __DIR__ . '/../../core/AccountLockout.php';

class UserModel extends Helper{

    protected $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function getAllUsers(bool $includeDeleted = false) {
        return $this->get_All_Users($includeDeleted);
    }

    /**
     * Summary metrics for user index hero.
     */
    public function getUserIndexStats(): array
    {
        $notDeleted = "deleted_at IS NULL";
        $stats = [
            'active'   => 0,
            'inactive' => 0,
            'total'    => 0,
        ];

        $this->db->query("SELECT COUNT(*) AS c FROM users WHERE is_active = 1 AND {$notDeleted}");
        $stats['active'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query("SELECT COUNT(*) AS c FROM users WHERE is_active = 0 AND {$notDeleted}");
        $stats['inactive'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query("SELECT COUNT(*) AS c FROM users WHERE {$notDeleted}");
        $stats['total'] = (int)($this->db->single()['c'] ?? 0);

        return $stats;
    }

    /**
     * Server-side data for DataTables (Users list with pagination, search, filters)
     */
    public function getUsersForDataTable(array $params): array
    {
        $start          = (int)($params['start'] ?? 0);
        $length         = (int)($params['length'] ?? 25);
        $searchValue    = trim($params['search']['value'] ?? '');
        $orderColumn    = (int)($params['order'][0]['column'] ?? 0);
        $orderDir       = $params['order'][0]['dir'] ?? 'asc';

        // Custom filters
        $filterBranch   = $params['filterBranch'] ?? '';
        $filterStatus   = $params['filterStatus'] ?? '';
        $includeDeleted = !empty($params['includeDeleted']);
        $deletedOnly    = !empty($params['deletedOnly']);

        // Columns for ordering (must match the order in the view)
        $columns = [
            0 => 'e.name',           // Employee
            1 => 'u.username',       // Username
            2 => 'b.branch_name',    // Branch
            3 => 'u.is_active',      // Status
            4 => 'u.last_login'      // Last Login
        ];

        $baseQuery = "
            FROM users u
            JOIN employees e ON u.employee_id = e.id
            LEFT JOIN branches b ON e.branch_id = b.id
        ";

        $where = [];
        $bindParams = [];

        if ($deletedOnly) {
            $where[] = 'u.deleted_at IS NOT NULL';
        } elseif (!$includeDeleted) {
            $where[] = 'u.deleted_at IS NULL';
        }

        // Global search
        if ($searchValue !== '') {
            $where[] = "(u.username LIKE :search OR e.name LIKE :search OR e.employee_code LIKE :search)";
            $bindParams[':search'] = "%{$searchValue}%";
        }

        // Custom filters
        if ($filterBranch) {
            $where[] = "b.branch_name = :branch";
            $bindParams[':branch'] = $filterBranch;
        }

        if ($filterStatus === 'active') {
            $where[] = "u.is_active = 1";
        } elseif ($filterStatus === 'inactive') {
            $where[] = "u.is_active = 0";
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Total records
        $totalSql = "SELECT COUNT(DISTINCT u.id) as total {$baseQuery}";
        if (!$includeDeleted) {
            $totalSql .= " WHERE u.deleted_at IS NULL";
        }
        $this->db->query($totalSql);
        $totalResult = $this->db->single();
        $recordsTotal = $totalResult['total'] ?? 0;

        // Filtered records
        $filteredSql = "SELECT COUNT(DISTINCT u.id) as total {$baseQuery} {$whereSql}";
        $this->db->query($filteredSql);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $filteredResult = $this->db->single();
        $recordsFiltered = $filteredResult['total'] ?? 0;

        // Data with ordering and limit
        $orderBy = $columns[$orderColumn] ?? 'e.name';
        $dataSql = "
            SELECT 
                u.id, u.username, u.is_active, u.last_login, u.last_login_ip,
                u.last_login_user_agent, u.deleted_at, u.locked_until, u.failed_login_count,
                e.id AS employee_id, e.name as employee_name, e.employee_code,
                b.branch_name
            {$baseQuery}
            {$whereSql}
            ORDER BY {$orderBy} {$orderDir}
            LIMIT {$length} OFFSET {$start}
        ";

        $this->db->query($dataSql);
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

    public function getUserById($id) {
      
        return $this->get_User_By_Id($id);
    }

    // Check if employee already has a user account
    public function employeeHasUser($employee_id) {
        $this->db->query("SELECT id FROM users WHERE employee_id = :employee_id AND deleted_at IS NULL LIMIT 1");
        $this->db->bind(':employee_id', $employee_id);
        return $this->db->single() ? true : false;
    }

    // Check username exists
    public function usernameExists($username, $exclude_id = null) {
        $sql = "SELECT id FROM users WHERE username = :username";
        if ($exclude_id) {
            $sql .= " AND id != :exclude_id";
        }
        $this->db->query($sql);
        $this->db->bind(':username', $username);
        if ($exclude_id) $this->db->bind(':exclude_id', $exclude_id);
        return $this->db->single() ? true : false;
    }

    // Create User
    public function createUser($data) {
        $usernameCheck = $this->validateUsername((string)($data['username'] ?? ''));
        if ($usernameCheck !== true) {
            return ['status' => 'error', 'message' => $usernameCheck];
        }

        if ($this->usernameExists($data['username'])) {
            return ['status' => 'error', 'message' => 'Username already exists!'];
        }

        if ($this->employeeHasUser($data['employee_id'])) {
            return ['status' => 'error', 'message' => 'This employee already has a user account!'];
        }

        // Password validation
        $password = $data['password'] ?? '';
        $confirm  = $data['confirm_password'] ?? $password;

        if ($password !== $confirm) {
            return ['status' => 'error', 'message' => 'Password and Confirm Password do not match!'];
        }

        $validation = $this->validatePasswordStrength($password);
        if ($validation !== true) {
            return ['status' => 'error', 'message' => $validation];
        }

        $createdBy = (int)($_SESSION['user_id'] ?? 0);
        if ($createdBy <= 0) {
            return ['status' => 'error', 'message' => 'You must be logged in to create a user.'];
        }

        $this->db->query("
            INSERT INTO users (employee_id, username, password_hash, created_by)
            VALUES (:employee_id, :username, :password_hash, :created_by)
        ");

        $this->db->bind(':employee_id', $data['employee_id']);
        $this->db->bind(':username', trim($data['username']));
        $this->db->bind(':password_hash', password_hash($password, PASSWORD_DEFAULT));
        $this->db->bind(':created_by', $createdBy);

        return $this->db->execute()
            ? [
                'status'  => 'success',
                'message' => 'User created successfully!',
                'user_id' => (int)$this->db->lastInsertId(),
            ]
            : ['status' => 'error', 'message' => 'Failed to create user!'];
    }

    // Update User
    public function updateUser($id, $data) {
        $usernameCheck = $this->validateUsername((string)($data['username'] ?? ''));
        if ($usernameCheck !== true) {
            return ['status' => 'error', 'message' => $usernameCheck];
        }

        if ($this->usernameExists($data['username'], $id)) {
            return ['status' => 'error', 'message' => 'Username already exists!'];
        }

        $this->db->query("SELECT is_active, username FROM users WHERE id = :id");
        $this->db->bind(':id', $id);
        $current = $this->db->single();
        if (!$current) {
            return ['status' => 'error', 'message' => 'User not found!'];
        }

        $newIsActive = (int)($data['is_active'] ?? 1);
        $isCurrentlyActive = (bool)$current['is_active'];
        $usernameChanged = strtolower(trim($data['username'] ?? ''))
            !== strtolower(trim((string)($current['username'] ?? '')));

        if ($isCurrentlyActive && $newIsActive === 0) {
            $blockReason = $this->getDeactivationBlockReason((int)$id);
            if ($blockReason !== null) {
                return ['status' => 'error', 'message' => $blockReason];
            }
        }

        $sql = "UPDATE users SET username = :username, is_active = :is_active";
        $password = $data['password'] ?? '';

        if ($this->usersColumnExists('telegram_user_id') && array_key_exists('telegram_user_id', $data)) {
            $telegramRaw = $data['telegram_user_id'];
            if ($telegramRaw !== null && trim((string)$telegramRaw) !== '') {
                $telegramId = $this->normalizeTelegramUserId($telegramRaw);
                if ($telegramId === null) {
                    return ['status' => 'error', 'message' => 'Telegram User ID must be a positive numeric chat id.'];
                }
                $sql .= ", telegram_user_id = :telegram_user_id";
            } else {
                $sql .= ", telegram_user_id = NULL";
            }
        }

        if (!empty($password)) {
            $sql .= ", password_hash = :password_hash";
        }
        $sql .= " WHERE id = :id";

        $this->db->query($sql);
        $this->db->bind(':username', trim($data['username']));
        $this->db->bind(':is_active', $newIsActive);
        $this->db->bind(':id', $id);

        if ($this->usersColumnExists('telegram_user_id') && array_key_exists('telegram_user_id', $data)) {
            $telegramRaw = $data['telegram_user_id'];
            if ($telegramRaw !== null && trim((string)$telegramRaw) !== '') {
                $this->db->bind(':telegram_user_id', $this->normalizeTelegramUserId($telegramRaw), PDO::PARAM_INT);
            }
        }

        if (!empty($password)) {
            $validation = $this->validatePasswordStrength($password);
            if ($validation !== true) {
                return ['status' => 'error', 'message' => $validation];
            }
            $this->db->bind(':password_hash', password_hash($password, PASSWORD_DEFAULT));
        }

        if (!$this->db->execute()) {
            return ['status' => 'error', 'message' => 'Failed to update user!'];
        }

        if (!empty($password)) {
            require_once __DIR__ . '/../../core/RememberMe.php';
            RememberMe::revokeAllForUser((int)$id);
        }

        if (!empty($password) || $usernameChanged) {
            require_once __DIR__ . '/../../core/CredentialVersion.php';
            CredentialVersion::bump((int)$id);
        }

        $this->refreshSessionCredentialIfSelf((int)$id);

        return ['status' => 'success', 'message' => 'User updated successfully!'];
    }

    private function refreshSessionCredentialIfSelf(int $userId): void
    {
        require_once __DIR__ . '/../../core/CredentialVersion.php';
        CredentialVersion::syncSession($userId);

        if ($userId !== (int)($_SESSION['user_id'] ?? 0)) {
            return;
        }

        $this->db->query('SELECT username FROM users WHERE id = :id LIMIT 1');
        $this->db->bind(':id', $userId);
        $row = $this->db->single();

        if (!$row) {
            return;
        }

        $_SESSION['username'] = (string)($row['username'] ?? $_SESSION['username'] ?? '');
    }

    public function toggleStatus($id) {
        // First, check current status
        $this->db->query("SELECT is_active FROM users WHERE id = :id");
        $this->db->bind(':id', $id);
        $current = $this->db->single();

        if (!$current) {
            return false;
        }

        $isCurrentlyActive = (bool)$current['is_active'];

        // If we are trying to deactivate this user, make sure they are not the last active user
        if ($isCurrentlyActive) {
            $blockReason = $this->getDeactivationBlockReason((int)$id);
            if ($blockReason !== null) {
                return false;
            }
        }

        $this->db->query("UPDATE users SET is_active = NOT is_active WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    /**
     * Soft delete a user (recommended over hard delete)
     */
    public function softDeleteUser(int $id, int $deletedBy): bool
    {
        $this->db->query("SELECT is_active FROM users WHERE id = :id");
        $this->db->bind(':id', $id);
        $current = $this->db->single();
        if (!$current) {
            return false;
        }

        if ((bool)$current['is_active']) {
            $blockReason = $this->getDeactivationBlockReason($id);
            if ($blockReason !== null) {
                return false;
            }
        }

        $this->db->query("
            UPDATE users 
            SET deleted_at = NOW(), 
                is_active = 0,
                deleted_by = :deleted_by
            WHERE id = :id
        ");
        $this->db->bind(':id', $id);
        $this->db->bind(':deleted_by', $deletedBy);

        return $this->db->execute();
    }

    /**
     * Restore a soft-deleted user.
     */
    public function restoreUser(int $id): array
    {
        $this->db->query('SELECT id, deleted_at FROM users WHERE id = :id LIMIT 1');
        $this->db->bind(':id', $id);
        $user = $this->db->single();

        if (!$user) {
            return ['status' => 'error', 'message' => 'User not found.'];
        }

        if ($user['deleted_at'] === null || $user['deleted_at'] === '') {
            return ['status' => 'error', 'message' => 'This user is not deleted.'];
        }

        $this->db->query('
            UPDATE users 
            SET deleted_at = NULL, 
                deleted_by = NULL,
                is_active = 1
            WHERE id = :id
        ');
        $this->db->bind(':id', $id);

        return $this->db->execute()
            ? ['status' => 'success', 'message' => 'User restored successfully.']
            : ['status' => 'error', 'message' => 'Failed to restore user.'];
    }

    public function unlockUser(int $id): array
    {
        if (!AccountLockout::unlock($id)) {
            return ['status' => 'error', 'message' => 'Failed to unlock account.'];
        }

        return ['status' => 'success', 'message' => 'Account lockout cleared.'];
    }

    /**
     * Login summary for the unified employee + account hub.
     */
    public function getUserAccountSummaryByEmployeeId(int $employeeId): ?array
    {
        $this->db->query('
            SELECT u.id, u.username, u.is_active, u.last_login, u.last_login_ip,
                   u.locked_until, u.failed_login_count, u.deleted_at,
                   u.employee_id
            FROM users u
            WHERE u.employee_id = :employee_id AND u.deleted_at IS NULL
            ORDER BY u.id DESC
            LIMIT 1
        ');
        $this->db->bind(':employee_id', $employeeId);
        $row = $this->db->single();

        return $row ?: null;
    }

    /**
     * Menu permission counts for hub summary chips.
     */
    public function getPermissionStats(int $userId): array
    {
        $this->db->query('
            SELECT
                COALESCE(SUM(CASE WHEN can_view = 1 THEN 1 ELSE 0 END), 0) AS view_count,
                COALESCE(SUM(CASE WHEN can_edit = 1 THEN 1 ELSE 0 END), 0) AS edit_count,
                COUNT(*) AS menu_count
            FROM user_menu_permissions
            WHERE user_id = :user_id
        ');
        $this->db->bind(':user_id', $userId);
        $row = $this->db->single() ?: [];

        return [
            'view_count'  => (int)($row['view_count'] ?? 0),
            'edit_count'  => (int)($row['edit_count'] ?? 0),
            'menu_count'  => (int)($row['menu_count'] ?? 0),
        ];
    }

    // Get current permissions for a user
    public function getUserPermissions($user_id) {
        $this->db->query("
            SELECT menu_id, can_view, can_edit 
            FROM user_menu_permissions 
            WHERE user_id = :user_id
        ");
        $this->db->bind(':user_id', $user_id);
        $result = $this->db->resultSet();

        $permissions = [];
        foreach ($result as $row) {
            $permissions[$row['menu_id']] = [
                'view' => (int)$row['can_view'],
                'edit' => (int)$row['can_edit']
            ];
        }
        return $permissions;
    }

    /**
     * Active users available as a permission copy source (excludes target).
     */
    public function getUsersForPermissionCopy(int $excludeUserId): array
    {
        $this->db->query('
            SELECT u.id, u.username, e.name AS employee_name, e.employee_code
            FROM users u
            JOIN employees e ON e.id = u.employee_id
            WHERE u.deleted_at IS NULL
              AND u.is_active = 1
              AND u.id != :exclude
            ORDER BY e.name ASC, u.username ASC
        ');
        $this->db->bind(':exclude', $excludeUserId);

        return $this->db->resultSet();
    }

    // Save single permission
    public function savePermission($user_id, $menu_id, $can_view, $can_edit) {
        $this->db->query("
            INSERT INTO user_menu_permissions 
            (user_id, menu_id, can_view, can_edit) 
            VALUES (:user_id, :menu_id, :can_view, :can_edit)
        ");
        $this->db->bind(':user_id', $user_id);
        $this->db->bind(':menu_id', $menu_id);
        $this->db->bind(':can_view', $can_view);
        $this->db->bind(':can_edit', $can_edit);
        return $this->db->execute();
    }

    // Delete all permissions for a user
    public function deleteAllPermissions($user_id) {
        $this->db->query("DELETE FROM user_menu_permissions WHERE user_id = :user_id");
        $this->db->bind(':user_id', $user_id);
        return $this->db->execute();
    }

    /**
     * Safely save user permissions using database transaction
     * Includes protection against self-locking (removing own critical access)
     */
    public function saveUserPermissions(int $userId, array $permissionsData): array
    {
        $isSelfEdit = ($userId === ($_SESSION['user_id'] ?? 0));

        // If editing own permissions, pre-check for critical access
        if ($isSelfEdit) {
            $hasUserManagementAccess = false;

            if (!empty($permissionsData)) {
                // We need to know which menu_id corresponds to User Management
                // For now, we'll check after building the new permission set
                // A better long-term solution is to mark critical menus in DB
            }
        }

        $this->db->beginTransaction();

        try {
            // Step 1: Remove all existing permissions for this user
            $this->deleteAllPermissions($userId);

            $newPermissions = [];

            // Step 2: Insert new permissions
            if (!empty($permissionsData) && is_array($permissionsData)) {
                foreach ($permissionsData as $menuId => $perm) {
                    $canView = isset($perm['view']) ? 1 : 0;
                    $canEdit = isset($perm['edit']) ? 1 : 0;

                    if ($canView || $canEdit) {
                        $this->savePermission($userId, (int)$menuId, $canView, $canEdit);
                        $newPermissions[(int)$menuId] = ['view' => $canView, 'edit' => $canEdit];
                    }
                }
            }

            // === SELF-LOCKING PROTECTION ===
            if ($isSelfEdit) {
                // Fetch menu IDs for critical sections (User Management)
                $this->db->query("SELECT id FROM menus WHERE controller = 'user' LIMIT 1");
                $userMenu = $this->db->single();

                if ($userMenu) {
                    $userMenuId = (int)$userMenu['id'];
                    $hasAccess = isset($newPermissions[$userMenuId]['view']) && $newPermissions[$userMenuId]['view'] == 1;

                    if (!$hasAccess) {
                        $this->db->rollback();
                        return [
                            'status' => 'error',
                            'message' => 'You cannot remove your own access to User Management. This would lock you out of the system.'
                        ];
                    }
                }
            }

            $this->db->commit();
            return ['status' => 'success', 'message' => 'Menu permissions updated successfully!'];

        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Permission Save Error for User {$userId}: " . $e->getMessage());
            return ['status' => 'error', 'message' => 'Failed to save permissions. Changes were rolled back.'];
        }
    }

    /**
     * Count non-deleted active users.
     */
    private function countActiveUsers(): int
    {
        $this->db->query("
            SELECT COUNT(*) AS active_count
            FROM users
            WHERE is_active = 1
              AND deleted_at IS NULL
        ");
        $count = $this->db->single();
        return (int)($count['active_count'] ?? 0);
    }

    /**
     * Return an error message when deactivation must be blocked, or null when allowed.
     */
    private function getDeactivationBlockReason(int $userId): ?string
    {
        if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
            return 'You cannot deactivate your own account while logged in.';
        }

        if ($this->countActiveUsers() <= 1) {
            return 'Cannot deactivate this user. This is the last active user in the system.';
        }

        return null;
    }

    /**
     * Professional password strength policy
     * Returns true on success, or error message string on failure.
     */
    private function validatePasswordStrength(string $password): true|string
    {
        return PasswordPolicy::validate($password);
    }

    /**
     * Username policy (Phase 0): minimum 4 chars, alphanumeric + underscore only.
     * Returns true on success, or error message string on failure.
     * Prevents trivially-weak usernames like '123', '222', '333' that were in the leaked dump.
     */
    private function validateUsername(string $username): true|string
    {
        $username = trim($username);
        if ($username === '') {
            return 'Username is required.';
        }
        if (strlen($username) < 4) {
            return 'Username must be at least 4 characters long.';
        }
        if (strlen($username) > 50) {
            return 'Username must not exceed 50 characters.';
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $username)) {
            return 'Username may only contain letters, numbers, and underscores.';
        }
        return true;
    }

    /**
     * Allow a logged-in user to change their own password
     */
    public function changeUserPassword(int $userId, string $currentPassword, string $newPassword, string $confirmPassword)
    {
        if ($newPassword !== $confirmPassword) {
            return ['status' => 'error', 'message' => 'New password and confirmation do not match.'];
        }

        $validation = $this->validatePasswordStrength($newPassword);
        if ($validation !== true) {
            return ['status' => 'error', 'message' => $validation];
        }

        // Fetch current hash
        $this->db->query("SELECT password_hash FROM users WHERE id = :id");
        $this->db->bind(':id', $userId);
        $user = $this->db->single();

        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return ['status' => 'error', 'message' => 'Current password is incorrect.'];
        }

        // Update password
        $this->db->query("UPDATE users SET password_hash = :hash WHERE id = :id");
        $this->db->bind(':hash', password_hash($newPassword, PASSWORD_DEFAULT));
        $this->db->bind(':id', $userId);

        if ($this->db->execute()) {
            require_once __DIR__ . '/../../core/RememberMe.php';
            RememberMe::revokeAllForUser($userId);
            require_once __DIR__ . '/../../core/CredentialVersion.php';
            CredentialVersion::bump($userId);
            $this->refreshSessionCredentialIfSelf($userId);
            return ['status' => 'success', 'message' => 'Password changed successfully!'];
        }

        return ['status' => 'error', 'message' => 'Failed to update password.'];
    }

    public function usersColumnExists(string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }

        $this->db->query("
            SELECT COUNT(*) AS c
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = 'users'
              AND column_name = :col
        ");
        $this->db->bind(':col', $column);
        $cache[$column] = ((int)($this->db->single()['c'] ?? 0)) > 0;

        return $cache[$column];
    }

    /**
     * @param mixed $value
     */
    public function normalizeTelegramUserId($value): ?int
    {
        if ($value === null) {
            return null;
        }

        $raw = preg_replace('/\s+/', '', (string)$value);
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $id = (int)$raw;

        return $id > 0 ? $id : null;
    }

    /**
     * Save Telegram chat id for alerts (self-service or admin).
     *
     * @param mixed $telegramUserId
     */
    public function updateTelegramUserId(int $userId, $telegramUserId): array
    {
        if ($userId <= 0) {
            return ['status' => 'error', 'message' => 'Invalid user.'];
        }

        if (!$this->usersColumnExists('telegram_user_id')) {
            return [
                'status'  => 'error',
                'message' => 'Telegram alerts are not available yet. Ask admin to run migration 043_users_telegram_user_id.sql.',
            ];
        }

        $normalized = null;
        if ($telegramUserId !== null && trim((string)$telegramUserId) !== '') {
            $normalized = $this->normalizeTelegramUserId($telegramUserId);
            if ($normalized === null) {
                return ['status' => 'error', 'message' => 'Telegram User ID must be a positive number (from @userinfobot).'];
            }
        }

        $this->db->query('UPDATE users SET telegram_user_id = :tid WHERE id = :id');
        if ($normalized === null) {
            $this->db->bind(':tid', null, PDO::PARAM_NULL);
        } else {
            $this->db->bind(':tid', $normalized, PDO::PARAM_INT);
        }
        $this->db->bind(':id', $userId, PDO::PARAM_INT);

        if (!$this->db->execute()) {
            return ['status' => 'error', 'message' => 'Could not save Telegram User ID.'];
        }

        return [
            'status'           => 'success',
            'message'          => $normalized === null
                ? 'Telegram alerts disabled for your account.'
                : 'Telegram User ID saved. You will receive bot alerts in your personal chat.',
            'telegram_user_id' => $normalized,
        ];
    }
}
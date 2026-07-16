<?php
// app/models/Notification.php

require_once '../core/Database.php';

class NotificationModel {

    protected $db;

    public function __construct() {
        $this->db = new Database();
    }

    public function getLastError() {
        return $this->db->getLastError();
    }

    
    /**
     * Get specific users for notification (e.g., warehouse_manager).
     */
    public function getUsersForNotification(string $role = 'warehouse_manager'): array {
        $telegramSelect = $this->usersColumnExists('telegram_user_id') ? ', u.telegram_user_id' : '';
        $sql = "SELECT u.id, u.username, e.role, e.branch_id, e.name AS employee_name{$telegramSelect}
                FROM users u
                JOIN employees e ON u.employee_id = e.id
                WHERE u.is_active = 1
                  AND u.deleted_at IS NULL
                  AND e.is_active = 1
                  AND e.role = :role";

        $this->db->query($sql);
        $this->db->bind(':role', $role);

        return $this->db->resultSet() ?: [];
    }

    /**
     * Branch-scoped users for Telegram alerts (must have telegram_user_id when sending).
     */
    public function getBranchUsersForTelegram(string $role, int $branchId): array
    {
        if (!$this->usersColumnExists('telegram_user_id')) {
            return [];
        }

        $sql = "SELECT u.id, u.username, u.telegram_user_id, e.name AS employee_name, e.branch_id, e.role
                FROM users u
                JOIN employees e ON u.employee_id = e.id
                WHERE u.is_active = 1
                  AND u.deleted_at IS NULL
                  AND e.is_active = 1
                  AND e.role = :role
                  AND e.branch_id = :branch_id";

        $this->db->query($sql);
        $this->db->bind(':role', $role);
        $this->db->bind(':branch_id', $branchId, PDO::PARAM_INT);

        return $this->db->resultSet() ?: [];
    }

    /**
     * @param int[] $userIds
     */
    public function getUsersTelegramProfilesByIds(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($id) => $id > 0)));
        if ($userIds === [] || !$this->usersColumnExists('telegram_user_id')) {
            return [];
        }

        $placeholders = [];
        $bind = [];
        foreach ($userIds as $i => $uid) {
            $key = ':uid' . $i;
            $placeholders[] = $key;
            $bind[$key] = $uid;
        }

        $sql = "SELECT u.id, u.username, u.telegram_user_id, e.name AS employee_name, e.branch_id, e.role
                FROM users u
                JOIN employees e ON u.employee_id = e.id
                WHERE u.deleted_at IS NULL AND u.id IN (" . implode(',', $placeholders) . ")";

        $this->db->query($sql);
        foreach ($bind as $key => $uid) {
            $this->db->bind($key, $uid, PDO::PARAM_INT);
        }

        return $this->db->resultSet() ?: [];
    }

    /**
     * Active users with any of the given roles (optionally scoped to one branch).
     *
     * @param string[] $roles
     */
    public function getUsersForTelegramByRoles(array $roles, ?int $branchId = null): array
    {
        if ($roles === [] || !$this->usersColumnExists('telegram_user_id')) {
            return [];
        }

        $placeholders = [];
        $bind = [];
        foreach (array_values($roles) as $i => $role) {
            $key = ':role' . $i;
            $placeholders[] = $key;
            $bind[$key] = $role;
        }

        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND e.branch_id = :branch_id';
        }

        $sql = "SELECT u.id, u.username, u.telegram_user_id, e.name AS employee_name, e.branch_id, e.role
                FROM users u
                JOIN employees e ON u.employee_id = e.id
                WHERE u.is_active = 1
                  AND u.deleted_at IS NULL
                  AND e.is_active = 1
                  AND e.role IN (" . implode(',', $placeholders) . ")
                  {$branchSql}";

        $this->db->query($sql);
        foreach ($bind as $key => $value) {
            $this->db->bind($key, $value);
        }
        if ($branchId !== null && $branchId > 0) {
            $this->db->bind(':branch_id', $branchId, PDO::PARAM_INT);
        }

        return $this->db->resultSet() ?: [];
    }

    /**
     * Resolve login users linked to employee master records.
     *
     * @param int[] $employeeIds
     */
    public function getUsersByEmployeeIds(array $employeeIds): array
    {
        $employeeIds = array_values(array_unique(array_filter(array_map('intval', $employeeIds), static fn($id) => $id > 0)));
        if ($employeeIds === [] || !$this->usersColumnExists('telegram_user_id')) {
            return [];
        }

        $placeholders = [];
        $bind = [];
        foreach ($employeeIds as $i => $eid) {
            $key = ':eid' . $i;
            $placeholders[] = $key;
            $bind[$key] = $eid;
        }

        $sql = "SELECT u.id, u.username, u.telegram_user_id, e.name AS employee_name, e.branch_id, e.role, u.employee_id
                FROM users u
                JOIN employees e ON u.employee_id = e.id
                WHERE u.is_active = 1
                  AND u.deleted_at IS NULL
                  AND e.is_active = 1
                  AND u.employee_id IN (" . implode(',', $placeholders) . ")";

        $this->db->query($sql);
        foreach ($bind as $key => $eid) {
            $this->db->bind($key, $eid, PDO::PARAM_INT);
        }

        return $this->db->resultSet() ?: [];
    }

    /**
     * @param array<int, array<string, mixed>> ...$lists
     * @return array<int, array<string, mixed>>
     */
    public function mergeTelegramRecipients(array ...$lists): array
    {
        $merged = [];
        foreach ($lists as $list) {
            foreach ($list as $row) {
                $uid = (int)($row['id'] ?? 0);
                if ($uid > 0) {
                    $merged[$uid] = $row;
                }
            }
        }

        return array_values($merged);
    }

    public function usersColumnExists(string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }

        $this->db->query("
            SELECT COUNT(*) AS c
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND COLUMN_NAME = :col
        ");
        $this->db->bind(':col', $column);
        $cache[$column] = ((int)($this->db->single()['c'] ?? 0)) > 0;

        return $cache[$column];
    }



    /**
     * Get all FCM tokens for a specific user
     */
    public function getUserFCMTokens(int $user_id): array {
        $sql = "SELECT fcm_token FROM fcm_tokens 
                WHERE user_id = ? 
                ORDER BY id DESC";

        $this->db->query($sql);
        $this->db->bind(1, $user_id, PDO::PARAM_INT);
        return $this->db->resultSet();
    }

    /**
     * Save notification in database for in-app notification center
     */
    public function saveNotification(
        int $user_id, 
        string $title, 
        string $message, 
        string $type, 
        ?int $reference_id = null
    ): bool {
        $sql = "INSERT INTO notifications 
                (user_id, title, message, type, reference_id, created_at, is_read) 
                VALUES (?, ?, ?, ?, ?, NOW(), 0)";

        $this->db->query($sql);
        $this->db->bind(1, $user_id, PDO::PARAM_INT);
        $this->db->bind(2, $title);
        $this->db->bind(3, $message);
        $this->db->bind(4, $type);
        $this->db->bind(5, $reference_id, PDO::PARAM_INT);

        return $this->db->execute();
    }

    /**
     * Optional: Get unread notifications for a user
     */
    public function getUnreadNotifications(int $user_id): array {
        $sql = "SELECT * FROM notifications 
                WHERE user_id = ? AND is_read = 0 
                ORDER BY created_at DESC LIMIT 20";

        $this->db->query($sql);
        $this->db->bind(1, $user_id, PDO::PARAM_INT);
        return $this->db->resultSet();
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(int $notification_id, int $user_id): bool {
        $sql = "UPDATE notifications 
                SET is_read = 1 
                WHERE id = ? AND user_id = ?";

        $this->db->query($sql);
        $this->db->bind(1, $notification_id, PDO::PARAM_INT);
        $this->db->bind(2, $user_id, PDO::PARAM_INT);
        return $this->db->execute();
    }
}
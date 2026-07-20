<?php
// core/UserAudit.php
// Append-only JSON Lines audit logger used across the ERP.

require_once __DIR__ . '/Database.php';
// Used for User mgmt + all transactional modules (Purchase, OtherExpense, Ledger, MoneyTransfer, etc.)
// Phase 4 modernization: rich details + branch context for full auditability before GL integration.

class UserAudit
{
    private string $logFile;

    public function __construct()
    {
        $logsDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0755, true);
        }
        $this->logFile = $logsDir . '/user_audit.log';
    }

    /**
     * Log a user-related action.
     *
     * For non-user entities (Purchase, Ledger, etc.), the $targetUserId param is overloaded
     * to hold the entity ID (e.g. purchase_receive.id, journal_entry.id). This is historical
     * but works with the action prefix filter in getRecentLogs().
     *
     * Phase 4+: Always include rich details + 'branch_id' for traceability before GL integration.
     */
    public function log(
        int $performedByUserId,
        string $action,
        ?int $targetUserId = null,
        array $details = []
    ): void {
        // Auto-enrich with branch context (critical for multi-branch audit)
        if (!isset($details['branch_id'])) {
            $details['branch_id'] = $_SESSION['branch_id'] ?? null;
        }

        $entry = [
            'timestamp'     => date('Y-m-d H:i:s'),
            'performed_by'  => $performedByUserId,
            'action'        => $action,
            'target_user_id'=> $targetUserId,   // overloaded: entity id for purchase_* actions
            'ip'            => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'details'       => $details,
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL;

        $this->writeToDatabase($entry);
        $this->writeToFile($line);
    }

    private function writeToFile(string $line): void
    {
        $fp = @fopen($this->logFile, 'a');
        if ($fp) {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, $line);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
            fclose($fp);
        }
    }

    private function writeToDatabase(array $entry): void
    {
        try {
            $db = new Database();
            $db->query('
                INSERT INTO user_audit_log (logged_at, performed_by, action, target_id, ip, branch_id, details)
                VALUES (:logged_at, :performed_by, :action, :target_id, :ip, :branch_id, :details)
            ');
            $db->bind(':logged_at', $entry['timestamp'] ?? date('Y-m-d H:i:s'));
            $db->bind(':performed_by', (int)($entry['performed_by'] ?? 0));
            $db->bind(':action', (string)($entry['action'] ?? ''));
            $targetId = $entry['target_user_id'] ?? null;
            $db->bind(':target_id', $targetId !== null ? (int)$targetId : null);
            $db->bind(':ip', $entry['ip'] ?? null);
            $details = $entry['details'] ?? [];
            $db->bind(':branch_id', is_array($details) ? ($details['branch_id'] ?? null) : null);
            $db->bind(':details', json_encode(is_array($details) ? $details : [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $db->execute();
        } catch (Throwable $e) {
            error_log('UserAudit DB write failed: ' . $e->getMessage());
        }
    }

    public function logUserCreated(int $performedBy, int $newUserId, string $username): void
    {
        $this->log($performedBy, 'user_created', $newUserId, ['username' => $username]);
    }

    public function logUserUpdated(int $performedBy, int $targetUserId, array $details = []): void
    {
        $this->log($performedBy, 'user_updated', $targetUserId, $details);
    }

    public function logUserStatusChanged(int $performedBy, int $targetUserId, bool $newStatus): void
    {
        $this->log($performedBy, 'user_status_changed', $targetUserId, [
            'new_status' => $newStatus ? 'active' : 'inactive'
        ]);
    }

    public function logPermissionsUpdated(int $performedBy, int $targetUserId): void
    {
        $this->log($performedBy, 'permissions_updated', $targetUserId);
    }

    /**
     * Get recent audit log entries (simple file-based reader)
     */
    public function getRecentLogs(int $limit = 200, ?string $actionContains = null): array
    {
        $dbLogs = $this->getRecentLogsFromDatabase($limit, $actionContains);
        if ($dbLogs !== []) {
            return $dbLogs;
        }

        return $this->getRecentLogsFromFile($limit, $actionContains);
    }

    private function getRecentLogsFromDatabase(int $limit, ?string $actionContains): array
    {
        try {
            $db = new Database();
            $sql = '
                SELECT logged_at AS timestamp, performed_by, action, target_id AS target_user_id, ip, details
                FROM user_audit_log
            ';
            if ($actionContains) {
                $sql .= ' WHERE action LIKE :action_filter';
            }
            $sql .= ' ORDER BY logged_at DESC, id DESC LIMIT :lim';

            $db->query($sql);
            if ($actionContains) {
                $db->bind(':action_filter', '%' . $actionContains . '%');
            }
            $db->bind(':lim', $limit);

            $logs = [];
            foreach ($db->resultSet() as $row) {
                $details = $row['details'] ?? null;
                if (is_string($details) && $details !== '') {
                    $decoded = json_decode($details, true);
                    $row['details'] = is_array($decoded) ? $decoded : [];
                } else {
                    $row['details'] = [];
                }
                $logs[] = $row;
            }

            return $logs;
        } catch (Throwable $e) {
            return [];
        }
    }

    private function getRecentLogsFromFile(int $limit, ?string $actionContains): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return [];
        }

        $lines = array_reverse($lines);
        $logs = [];

        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }

            if ($actionContains && stripos($entry['action'] ?? '', $actionContains) === false) {
                continue;
            }

            $logs[] = $entry;

            if (count($logs) >= $limit) {
                break;
            }
        }

        return $logs;
    }

    /**
     * Attach performer display names to audit rows.
     */
    public function enrichWithPerformerNames(array $logs): array
    {
        if ($logs === []) {
            return $logs;
        }

        $ids = [];
        foreach ($logs as $log) {
            $id = (int)($log['performed_by'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return $logs;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db = new Database();
        $db->query("
            SELECT u.id, u.username, e.name AS employee_name
            FROM users u
            LEFT JOIN employees e ON e.id = u.employee_id
            WHERE u.id IN ({$placeholders})
        ");

        $i = 1;
        foreach ($ids as $userId) {
            $db->bind($i, $userId);
            $i++;
        }

        $lookup = [];
        foreach ($db->resultSet() as $row) {
            $lookup[(int)$row['id']] = $row;
        }

        foreach ($logs as &$log) {
            $performerId = (int)($log['performed_by'] ?? 0);
            $row = $lookup[$performerId] ?? null;
            $employeeName = trim((string)($row['employee_name'] ?? ''));
            $username = trim((string)($row['username'] ?? ''));

            if ($employeeName !== '') {
                $log['performed_by_label'] = $username !== ''
                    ? "{$employeeName} ({$username})"
                    : $employeeName;
            } elseif ($username !== '') {
                $log['performed_by_label'] = $username;
            } elseif ($performerId > 0) {
                $log['performed_by_label'] = 'User #' . $performerId;
            } else {
                $log['performed_by_label'] = 'System';
            }
        }
        unset($log);

        return $logs;
    }

    public static function performerLabel(array $log): string
    {
        return (string)($log['performed_by_label'] ?? ('#' . (int)($log['performed_by'] ?? 0)));
    }
}
?>
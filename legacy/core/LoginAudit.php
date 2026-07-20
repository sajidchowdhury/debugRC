<?php
// core/LoginAudit.php
// Lightweight audit logger for authentication events
// Logs to file (logs/login_audit.log) using JSON lines format.
// No database changes required. Easy to query or ship to SIEM later.

class LoginAudit
{
    private string $logFile;

    public function __construct()
    {
        $logsDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0755, true);
        }

        $this->logFile = $logsDir . '/login_audit.log';
    }

    /**
     * Record a login attempt (success or failure)
     */
    public function log(
        string $username,
        bool $success,
        bool $rateLimited = false,
        ?string $reason = null
    ): void {
        $entry = [
            'timestamp'    => date('Y-m-d H:i:s'),
            'ip'           => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent'   => $this->sanitizeUserAgent($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'username'     => $this->sanitizeUsername($username),
            'success'      => $success,
            'rate_limited' => $rateLimited,
            'reason'       => $reason,
            'session_id'   => session_id() ?: null,
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL;

        // Append with file locking for safety
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

    /**
     * Log a successful login
     */
    public function logSuccess(string $username): void
    {
        $this->log($username, true, false, 'success');
    }

    /**
     * Log a failed login attempt
     */
    public function logFailure(string $username, bool $rateLimited = false, ?string $reason = 'invalid_credentials'): void
    {
        $this->log($username, false, $rateLimited, $reason);
    }

    /**
     * Get recent login attempts (useful for admin dashboards later)
     */
    public function getRecentAttempts(int $limit = 50): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }

        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return [];
        }

        $lines = array_slice(array_reverse($lines), 0, $limit);

        $results = [];
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (is_array($data)) {
                $results[] = $data;
            }
        }

        return $results;
    }

    /**
     * Get failed attempts for a specific username in the last X minutes
     */
    public function getRecentFailuresForUser(string $username, int $minutes = 60): int
    {
        if (!file_exists($this->logFile)) {
            return 0;
        }

        $since = time() - ($minutes * 60);
        $count = 0;

        $handle = @fopen($this->logFile, 'r');
        if (!$handle) return 0;

        while (($line = fgets($handle)) !== false) {
            $data = json_decode($line, true);
            if (!$data) continue;

            $ts = strtotime($data['timestamp'] ?? '');
            if ($ts < $since) continue;

            if (strtolower($data['username'] ?? '') === strtolower($username) && empty($data['success'])) {
                $count++;
            }
        }
        fclose($handle);

        return $count;
    }

    // ==================== PRIVATE HELPERS ====================

    private function sanitizeUserAgent(string $ua): string
    {
        // Truncate and remove newlines/tabs for safety
        $ua = preg_replace('/[\r\n\t]/', ' ', $ua);
        return mb_substr(trim($ua), 0, 200);
    }

    private function sanitizeUsername(string $username): string
    {
        return preg_replace('/[^a-zA-Z0-9._@-]/', '', trim($username));
    }
}
?>
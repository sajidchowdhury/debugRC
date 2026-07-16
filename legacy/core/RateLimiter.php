<?php
// core/RateLimiter.php — login limits in DB (IP + username); JSON API limits in session.

require_once __DIR__ . '/Database.php';

class RateLimiter
{
    private int $maxAttempts;
    private int $windowSeconds;
    private string $prefix;
    private ?Database $db = null;

    public function __construct(int $maxAttempts = 5, int $windowSeconds = 900, string $prefix = 'login:')
    {
        $this->maxAttempts = max(1, $maxAttempts);
        $this->windowSeconds = max(1, $windowSeconds);
        $this->prefix = $prefix;
    }

    /**
     * Check whether the identifier is blocked (does not increment the counter).
     * Login buckets are keyed on username + client IP in the database.
     */
    public function isLimited(string $identifier): bool
    {
        $bucket = $this->readDbBucket($this->bucketKey($identifier));
        if ($bucket === null) {
            return false;
        }

        return (int)($bucket['attempt_count'] ?? 0) >= $this->maxAttempts;
    }

    /**
     * Record a failed attempt (e.g. wrong password).
     */
    public function recordFailure(string $identifier): void
    {
        $this->incrementDbBucket($this->bucketKey($identifier));
    }

    /**
     * Clear attempts after successful authentication.
     */
    public function clear(string $identifier): void
    {
        try {
            $db = $this->db();
            $db->query('DELETE FROM login_rate_limits WHERE bucket_key = :key');
            $db->bind(':key', $this->bucketKey($identifier));
            $db->execute();
        } catch (Throwable $e) {
            error_log('RateLimiter::clear failed: ' . $e->getMessage());
        }
    }

    /**
     * JSON API guard: increment and return whether the request is allowed.
     * Uses session storage (per authenticated user within a browser session).
     *
     * @return array{allowed: bool, retry_after: int}
     */
    public static function attempt(string $key, int $maxAttempts, int $windowSeconds): array
    {
        if ($maxAttempts < 1 || $windowSeconds < 1) {
            return ['allowed' => true, 'retry_after' => 0];
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return ['allowed' => true, 'retry_after' => 0];
        }

        $bucket = self::incrementSessionBucket($key, $windowSeconds);
        if ((int)($bucket['count'] ?? 0) > $maxAttempts) {
            return [
                'allowed'     => false,
                'retry_after' => max(1, (int)($bucket['reset_at'] ?? time()) - time()),
            ];
        }

        return ['allowed' => true, 'retry_after' => 0];
    }

    private function db(): Database
    {
        if ($this->db === null) {
            $this->db = new Database();
        }

        return $this->db;
    }

    /**
     * Composite key: prefix + hash(username|IP).
     */
    private function bucketKey(string $identifier): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        return $this->prefix . hash('sha256', strtolower(trim($identifier)) . '|' . $ip);
    }

    /**
     * @return array{attempt_count: int, reset_at: string}|null
     */
    private function readDbBucket(string $key): ?array
    {
        try {
            $db = $this->db();
            $db->query('
                SELECT attempt_count, reset_at
                FROM login_rate_limits
                WHERE bucket_key = :key
                  AND reset_at > NOW()
                LIMIT 1
            ');
            $db->bind(':key', $key);
            $row = $db->single();

            return $row ?: null;
        } catch (Throwable $e) {
            error_log('RateLimiter::readDbBucket failed: ' . $e->getMessage());
            return null;
        }
    }

    private function incrementDbBucket(string $key): void
    {
        try {
            $db = $this->db();
            $db->query('
                UPDATE login_rate_limits
                SET attempt_count = attempt_count + 1
                WHERE bucket_key = :key
                  AND reset_at > NOW()
            ');
            $db->bind(':key', $key);
            $db->execute();

            if ($db->rowCount() > 0) {
                return;
            }

            $resetAt = date('Y-m-d H:i:s', time() + $this->windowSeconds);
            $db->query('
                INSERT INTO login_rate_limits (bucket_key, attempt_count, reset_at)
                VALUES (:key, 1, :reset_at)
                ON DUPLICATE KEY UPDATE
                    attempt_count = 1,
                    reset_at = VALUES(reset_at)
            ');
            $db->bind(':key', $key);
            $db->bind(':reset_at', $resetAt);
            $db->execute();
        } catch (Throwable $e) {
            error_log('RateLimiter::incrementDbBucket failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array{count: int, reset_at: int}
     */
    private static function incrementSessionBucket(string $key, int $windowSeconds): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return ['count' => 0, 'reset_at' => time()];
        }

        $store = &$_SESSION['_rate_limits'];
        if (!is_array($store)) {
            $store = [];
        }

        $now = time();
        $bucket = $store[$key] ?? ['count' => 0, 'reset_at' => $now + $windowSeconds];
        if ($now >= (int)($bucket['reset_at'] ?? 0)) {
            $bucket = ['count' => 0, 'reset_at' => $now + $windowSeconds];
        }

        $bucket['count'] = (int)($bucket['count'] ?? 0) + 1;
        $store[$key] = $bucket;

        return $bucket;
    }
}

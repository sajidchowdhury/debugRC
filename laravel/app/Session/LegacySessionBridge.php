<?php

namespace App\Session;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

/**
 * Legacy PHP Session Bridge — RC_ERP Phase 3.
 *
 * The legacy PHP app uses PHP native sessions. On the VPS, we configure:
 *   session.save_handler = redis
 *   session.save_path = "tcp://127.0.0.1:6379?database=1"
 *
 * PHP stores each session in Redis with:
 *   Key:   PHPREDIS_SESSION:<session_id>
 *   Value: PHP-serialized $_SESSION array (using session_serialize format)
 *
 * This class reads/writes those same keys so that:
 *   1. When a user logs in via legacy PHP → Laravel sees them as logged in.
 *   2. When a user logs in via Laravel → legacy PHP sees them as logged in.
 *   3. Logout on either side logs out on both sides.
 *
 * CRITICAL: The serialization format is PHP's session_encode(), which uses
 * a pipe-delimited format: key|serialized_value;key|serialized_value;...
 * We must use session_decode() to read and a manual encoder to write.
 */
class LegacySessionBridge
{
    private string $redisConnection = 'legacy';
    private string $keyPrefix = 'PHPREDIS_SESSION:';

    public function __construct()
    {
        $db = (int) config('session.legacy.redis_db', 1);
        $this->keyPrefix = config('session.legacy.redis_prefix', 'PHPREDIS_SESSION:');
        $this->redisConnection = 'legacy';
    }

    /**
     * Read the legacy session data for a given session ID.
     *
     * @param string $sessionId The PHP session ID (from the PHPSESSID cookie).
     * @return array<string, mixed> The session data, or empty array if not found.
     */
    public function read(string $sessionId): array
    {
        if ($sessionId === '') {
            return [];
        }

        try {
            $raw = Redis::connection($this->redisConnection)->get($this->keyPrefix . $sessionId);
            if ($raw === null || $raw === false || $raw === '') {
                return [];
            }

            return $this->decodeSessionData((string) $raw);
        } catch (\Throwable $e) {
            Log::warning('LegacySessionBridge::read failed', [
                'session_id' => substr($sessionId, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Write session data to the legacy Redis session store.
     * Used when Laravel logs a user in — so legacy PHP sees them as logged in.
     *
     * @param string $sessionId The PHP session ID.
     * @param array<string, mixed> $data The session data to write.
     * @param int $ttl Session TTL in seconds (default 8 hours = 28800, matching legacy gc_maxlifetime).
     */
    public function write(string $sessionId, array $data, int $ttl = 28800): void
    {
        if ($sessionId === '') {
            return;
        }

        try {
            $encoded = $this->encodeSessionData($data);
            Redis::connection($this->redisConnection)->setex(
                $this->keyPrefix . $sessionId,
                $ttl,
                $encoded
            );
        } catch (\Throwable $e) {
            Log::warning('LegacySessionBridge::write failed', [
                'session_id' => substr($sessionId, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Destroy a legacy session (logout).
     */
    public function destroy(string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }

        try {
            Redis::connection($this->redisConnection)->del($this->keyPrefix . $sessionId);
        } catch (\Throwable $e) {
            Log::warning('LegacySessionBridge::destroy failed', [
                'session_id' => substr($sessionId, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Decode PHP session-serialized data into an array.
     *
     * PHP session format: key|serialized_value;key|serialized_value;...
     * We use session_decode() which populates $_SESSION.
     * This must be done in a clean scope to avoid clobbering the real $_SESSION.
     *
     * @param string $raw The raw session data from Redis.
     * @return array<string, mixed>
     */
    private function decodeSessionData(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        // PHP's session_decode() writes into $_SESSION. We save the current
        // $_SESSION, decode, extract, then restore. This is safe in a CLI/web
        // context because Laravel doesn't use $_SESSION directly.
        $oldSession = $_SESSION ?? [];
        $_SESSION = [];

        try {
            session_decode($raw);
            $data = $_SESSION;
        } catch (\Throwable $e) {
            $data = [];
            Log::warning('LegacySessionBridge: session_decode failed', [
                'error' => $e->getMessage(),
            ]);
        } finally {
            $_SESSION = $oldSession;
        }

        return $data;
    }

    /**
     * Encode an array into PHP session-serialized format.
     *
     * @param array<string, mixed> $data
     * @return string
     */
    private function encodeSessionData(array $data): string
    {
        $oldSession = $_SESSION ?? [];
        $_SESSION = $data;

        try {
            $encoded = session_encode();
            return $encoded !== false ? $encoded : '';
        } catch (\Throwable $e) {
            Log::warning('LegacySessionBridge: session_encode failed', [
                'error' => $e->getMessage(),
            ]);
            return '';
        } finally {
            $_SESSION = $oldSession;
        }
    }

    /**
     * Get the legacy session ID from the request cookie.
     * The cookie name is configured in session.legacy.cookie (default PHPSESSID).
     */
    public static function getSessionIdFromRequest(\Illuminate\Http\Request $request): string
    {
        $cookieName = config('session.legacy.cookie', 'PHPSESSID');
        return (string) $request->cookie($cookieName, '');
    }
}

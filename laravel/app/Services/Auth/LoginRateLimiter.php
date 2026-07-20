<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Login Rate Limiter — Phase 3.
 * Replicates legacy core/RateLimiter.php behavior.
 * Uses Redis (via Laravel Cache) for distributed rate-limiting.
 *
 * Two modes:
 * 1. Per-username: limits login attempts for a specific username.
 * 2. Per-IP: limits login attempts from a specific IP (brute-force protection).
 */
class LoginRateLimiter
{
    private int $maxAttempts;
    private int $decaySeconds;

    public function __construct()
    {
        $this->maxAttempts = config('auth.max_failed_attempts', 5);
        $this->decaySeconds = 900; // 15 minutes
    }

    /**
     * Check if the given key (username or IP) is rate-limited.
     */
    public function isLimited(string $key): bool
    {
        $cacheKey = $this->cacheKey($key);
        $count = (int) Cache::store('redis')->get($cacheKey, 0);
        return $count >= $this->maxAttempts;
    }

    /**
     * Record a failed attempt for the given key.
     */
    public function recordFailure(string $key): void
    {
        $cacheKey = $this->cacheKey($key);
        $count = (int) Cache::store('redis')->get($cacheKey, 0);
        Cache::store('redis')->put($cacheKey, $count + 1, $this->decaySeconds);
    }

    /**
     * Clear the rate-limit counter for the given key (on successful login).
     */
    public function clear(string $key): void
    {
        Cache::store('redis')->forget($this->cacheKey($key));
    }

    /**
     * Get remaining attempts before lockout.
     */
    public function remainingAttempts(string $key): int
    {
        $count = (int) Cache::store('redis')->get($this->cacheKey($key), 0);
        return max(0, $this->maxAttempts - $count);
    }

    /**
     * Get seconds until the rate limit expires.
     */
    public function availableIn(string $key): int
    {
        // Laravel's Cache doesn't expose TTL directly for Redis; approximate.
        return $this->decaySeconds;
    }

    private function cacheKey(string $key): string
    {
        // Hash the key to avoid special characters in Redis key names.
        return 'login_rate:' . hash('sha256', $key);
    }
}

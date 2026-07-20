<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 19 — API rate-limiting middleware (Task 19-RATELIMIT-PRINT).
 *
 * Throttles REST API traffic per API token + client IP to prevent abuse
 * (runaway mobile clients, leaked tokens hammering endpoints, etc.).
 *
 * Behaviour:
 *   - Default limit: 60 requests per minute per (token, ip) bucket.
 *   - Limit is configurable via the middleware parameter:
 *       ->middleware('api.rate:120')   // 120 req/min
 *   - When the limit is exceeded, returns HTTP 429 with JSON:
 *       { "message": "Rate limit exceeded. Maximum 60 requests per minute.",
 *         "retry_after": 45 }
 *   - Always sets the standard rate-limit headers on every response:
 *       X-RateLimit-Limit
 *       X-RateLimit-Remaining
 *       X-RateLimit-Reset
 *       Retry-After (only when limit is hit)
 *
 * Implementation:
 *   - Primary store: Redis via INCR + EXPIRE (atomic). The bucket key is
 *     `api_rate:{token_hash}:{ip}` and lives for 60 seconds.
 *   - Fallback: if Redis is unavailable (connection error, predis missing,
 *     etc.), the limiter degrades gracefully to Laravel's default cache store
 *     (file / array). The semantics are slightly weaker (no atomic INCR)
 *     but a best-effort counter is still maintained so the abuse vector is
 *     bounded.
 *
 * Auth note: this middleware runs AFTER api.auth so the Auth::user() is
 * already populated. We derive the "token" identity from the bearer header
 * (hashed) so anonymous-but-tokenized requests still bucket correctly even
 * if api.auth was bypassed for any reason. We also mix in the client IP so
 * that two clients sharing one token don't trip each other's limits.
 */
class ApiRateLimit
{
    /** @var int Default window length in seconds (1 minute). */
    private const WINDOW_SECONDS = 60;

    /** @var string Redis key namespace. */
    private const KEY_NAMESPACE = 'api_rate:';

    /** @var bool Tracks whether Redis is available for this request. */
    private bool $redisAvailable = true;

    /**
     * Handle an incoming request.
     *
     * @param  Request   $request
     * @param  Closure   $next
     * @param  int|null  $maxAttempts  Maximum requests per minute (default 60).
     */
    public function handle(Request $request, Closure $next, ?string $maxAttempts = null): Response
    {
        $limit = $this->resolveLimit($maxAttempts);
        $key   = $this->bucketKey($request);

        [$count, $ttl] = $this->increment($key);

        $remaining = max(0, $limit - $count);
        $retryAfter = $count > $limit ? max(1, $ttl) : 0;

        if ($count > $limit) {
            return $this->tooManyRequestsResponse($limit, $retryAfter);
        }

        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
        $response->headers->set('X-RateLimit-Reset', (string) $ttl);

        return $response;
    }

    /**
     * Resolve the effective rate-limit from the middleware parameter,
     * falling back to the config default, then to the hardcoded default.
     */
    private function resolveLimit(?string $maxAttempts): int
    {
        if ($maxAttempts !== null && $maxAttempts !== '') {
            $parsed = (int) $maxAttempts;
            if ($parsed > 0) {
                return $parsed;
            }
        }

        $config = (int) config('api.rate_limit.default', 60);

        return $config > 0 ? $config : 60;
    }

    /**
     * Build the per-(token, ip) bucket key.
     *
     * The token is hashed (SHA-256) so the raw bearer token never appears
     * in Redis keys (defense in depth, in case Redis is ever logged or
     * inspected). When no token is present we hash the IP-only identity so
     * anonymous abuse is still bucketed.
     */
    private function bucketKey(Request $request): string
    {
        $token = $this->bearerToken($request);
        $ip    = $request->ip() ?? 'unknown';

        $identity = $token !== ''
            ? hash('sha256', $token) . ':' . $ip
            : 'anonymous:' . hash('sha256', $ip);

        return self::KEY_NAMESPACE . $identity;
    }

    /**
     * Atomically increment the counter for the bucket key.
     *
     * Returns [$count, $ttl]:
     *   - $count = current count (1-indexed, post-increment)
     *   - $ttl   = seconds remaining in the window (0..60)
     *
     * Two paths:
     *   1. Redis (preferred): INCR + EXPIRE on first sighting. Atomic.
     *   2. Fallback cache: Cache::add() to seed TTL, then increment. Not
     *      strictly atomic but bounded — the worst case is a one-off
     *      under-count of a few requests, which is acceptable degradation.
     *
     * @return array{0:int, 1:int}
     */
    private function increment(string $key): array
    {
        // Try Redis first.
        if ($this->redisAvailable) {
            try {
                $redis = Redis::connection();
                $count = (int) $redis->incr($key);

                // Only set TTL on the first sighting of the key.
                if ($count === 1) {
                    $redis->expire($key, self::WINDOW_SECONDS);
                }

                $ttl = (int) $redis->ttl($key);
                if ($ttl < 0) {
                    // No expiry — fix that (Redis returns -1 when no TTL, -2 when key missing).
                    $redis->expire($key, self::WINDOW_SECONDS);
                    $ttl = self::WINDOW_SECONDS;
                }

                return [$count, $ttl];
            } catch (\Throwable $e) {
                // Mark Redis as unavailable for the rest of this request
                // so we don't pay the connection-exception cost on every
                // subsequent request cycle (e.g. if Redis is down). The
                // flag is per-request, not global — the next request will
                // try Redis again.
                $this->redisAvailable = false;
                Log::warning('ApiRateLimit: Redis unavailable, falling back to cache store.', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: Laravel default cache (array in tests, file in prod).
        $count = (int) Cache::get($key, 0) + 1;
        // Cache::add() only sets if not present; we use put() because we just read.
        $ttl = self::WINDOW_SECONDS;

        // Seed TTL on first sighting.
        if ($count === 1) {
            Cache::put($key, 1, self::WINDOW_SECONDS);
        } else {
            // We don't know the remaining TTL on the cache store — best effort.
            Cache::put($key, $count, self::WINDOW_SECONDS);
        }

        return [$count, $ttl];
    }

    /**
     * Build the 429 Too Many Requests JSON response with the rate-limit headers.
     */
    private function tooManyRequestsResponse(int $limit, int $retryAfter): Response
    {
        return response()->json([
            'message'     => "Rate limit exceeded. Maximum {$limit} requests per minute.",
            'retry_after' => $retryAfter,
        ], 429)
            ->withHeaders([
                'X-RateLimit-Limit'     => (string) $limit,
                'X-RateLimit-Remaining'  => '0',
                'X-RateLimit-Reset'      => (string) $retryAfter,
                'Retry-After'            => (string) $retryAfter,
            ]);
    }

    /**
     * Extract the bearer token from the Authorization header (mirrors ApiAuth).
     */
    private function bearerToken(Request $request): string
    {
        $header = $request->header('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            return '';
        }

        return trim(substr($header, 7));
    }
}

<?php

namespace Tests\Feature\Realtime;

use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * G-217 (MEDIUM-WAVE-3) — SSE `/sse/status` endpoint tests.
 *
 * Covers `SseController::status()` (laravel/app/Http/Controllers/SseController.php L223-298):
 *   - The endpoint returns 200 even when no worker heartbeat exists in Redis
 *     (worker_running = false, heartbeat_stale = false, worker_heartbeat = null).
 *   - When Redis holds a fresh heartbeat JSON (timestamp ≤ TTL), worker_running = true.
 *   - When Redis holds a STALE heartbeat (timestamp > TTL, default 90s), worker_running = false
 *     AND heartbeat_stale = true (the G-211 / REALTIME-3 enhancement).
 *   - Unauthenticated requests are rejected by the `auth` middleware wrapping
 *     the /sse/* route group (web.php L90 + L1924-1927) — JSON requests get 401.
 *
 * Redis is mocked via the Laravel Redis facade (`Redis::shouldReceive`) because
 * the test environment runs with `PREDIS_DISABLED=true` (no live Redis). The
 * status endpoint swallows Redis errors gracefully (try/catch at L232 + L266),
 * but for deterministic "fresh heartbeat" / "stale heartbeat" assertions we
 * inject controlled JSON via the facade spy.
 *
 * `ListenNotifyService::isAvailable()` + `getActiveChannels()` are NOT mocked
 * — they hit the real test DB (which is a primary, so isAvailable() = true,
 * and no worker is LISTENing so getActiveChannels() = []). This matches the
 * production "worker not running" reality we want to assert.
 */
class SseStatusTest extends TestCase
{
    use BuildsRoleUsers;

    /**
     * Heartbeat key the worker writes (must match ListenNotifyWorker::sendHeartbeat).
     */
    private const HEARTBEAT_KEY = 'rcerp:listen_notify:heartbeat';

    protected function setUp(): void
    {
        parent::setUp();
        // Auth as admin for all authenticated scenarios. The
        // `test_sse_status_requires_authentication` test calls auth()->logout()
        // to flip back to guest before issuing the request.
        $this->actingAsRole('admin');
    }

    /**
     * Test 1: when no heartbeat exists in Redis, the status endpoint returns
     * 200 with `worker_running: false`.
     *
     * Basis: SseController::status() L241-268 — Redis::get() returns null (or
     * throws and is caught), $workerHeartbeat stays null, and L283 computes
     * `worker_running = !empty($channels) || ($workerHeartbeat !== null && ...)`
     * → `!empty([]) || false` → false.
     */
    public function test_sse_status_returns_ok_without_worker(): void
    {
        $this->mockRedisHeartbeat(null);

        $response = $this->getJson(route('sse.status'));

        $response->assertOk();
        $response->assertJsonPath('worker_running', false);
        $response->assertJsonPath('worker_heartbeat', null);
        $response->assertJsonPath('heartbeat_stale', false);
        $response->assertJsonPath('worker_pdo_healthy', null);
    }

    /**
     * Test 2: when Redis has a fresh heartbeat JSON (timestamp = now),
     * `worker_running: true`.
     *
     * Basis: SseController::status() L241-265 — Redis::get() returns a JSON
     * string with `timestamp` set to now. L250-257 parses the timestamp and
     * computes `$heartbeatAgeSeconds` ≈ 0. L274-275 sets `$heartbeatStale =
     * $heartbeatAgeSeconds > 90` → false. L283 computes `worker_running =
     * !empty([]) || (true && !false)` → true.
     *
     * Also asserts `worker_pdo_healthy: true` (the G-216 enhancement — when
     * `pdo_last_success_at` is present in the heartbeat JSON, the worker's PDO
     * is healthy).
     */
    public function test_sse_status_returns_worker_healthy_when_heartbeat_fresh(): void
    {
        $freshHeartbeat = json_encode([
            'timestamp' => now()->toISOString(),
            'processed_count' => 42,
            'last_notification_at' => now()->subSeconds(5)->toISOString(),
            'pid' => 12345,
            'pdo_last_success_at' => now()->toISOString(),
        ], JSON_UNESCAPED_UNICODE);

        $this->mockRedisHeartbeat($freshHeartbeat);

        $response = $this->getJson(route('sse.status'));

        $response->assertOk();
        $response->assertJsonPath('worker_running', true);
        $response->assertJsonPath('heartbeat_stale', false);
        $response->assertJsonPath('worker_pdo_healthy', true);
        $response->assertJsonPath('worker_heartbeat.processed_count', 42);
        $response->assertJsonPath('worker_heartbeat.pid', 12345);
    }

    /**
     * Test 3: when the heartbeat age exceeds the TTL (default 90s),
     * `worker_running: false` AND `heartbeat_stale: true`.
     *
     * Basis: SseController::status() L274-275 — `$heartbeatTtl = (int)
     * config('realtime.listen_notify.heartbeat_ttl', 90)` and
     * `$heartbeatStale = $heartbeatAgeSeconds !== null && $heartbeatAgeSeconds
     * > $heartbeatTtl`. With a heartbeat 120s old, $heartbeatStale = true and
     * worker_running = !empty([]) || (true && !true) → false.
     *
     * This is the G-211 (REALTIME-3) stale-heartbeat detection — surfaces a
     * dead/hung worker BEFORE the TTL expires.
     */
    public function test_sse_status_reports_stale_heartbeat(): void
    {
        $staleHeartbeat = json_encode([
            'timestamp' => now()->subSeconds(120)->toISOString(),
            'processed_count' => 5,
            'last_notification_at' => now()->subSeconds(180)->toISOString(),
            'pid' => 12345,
            'pdo_last_success_at' => now()->subSeconds(120)->toISOString(),
        ], JSON_UNESCAPED_UNICODE);

        $this->mockRedisHeartbeat($staleHeartbeat);

        $response = $this->getJson(route('sse.status'));

        $response->assertOk();
        $response->assertJsonPath('worker_running', false);
        $response->assertJsonPath('heartbeat_stale', true);
        // last_heartbeat_age_seconds should be ~120 (allow some slack for
        // test execution time — assert >= 100 to avoid timing flakiness).
        $age = $response->json('last_heartbeat_age_seconds');
        $this->assertNotNull($age, 'last_heartbeat_age_seconds should be set when a heartbeat exists.');
        $this->assertGreaterThanOrEqual(100, $age, 'Stale heartbeat age should be ≥ 100s (close to 120s).');
    }

    /**
     * Test 4: unauthenticated requests are rejected. JSON requests get 401
     * (via Laravel's `auth` middleware exception handler); web requests would
     * get 302 redirect to login.
     *
     * Basis: routes/web.php L90 `Route::middleware('auth')->group(...)` wraps
     * the entire authenticated route group including the /sse/* prefix at
     * L1924-1927. The `auth` middleware rejects unauthenticated requests.
     *
     * Note: SseController::status() does NOT have its own `abort(401)` guard
     * (unlike SseController::events() L59-61 which has an explicit guard) —
     * it relies entirely on the route-level `auth` middleware.
     */
    public function test_sse_status_requires_authentication(): void
    {
        // Flip back to guest (setUp authenticated as admin).
        auth()->logout();
        // Clear the session credential_version so the request looks truly
        // unauthenticated (otherwise the session array driver may retain it).
        $this->withSession([]);

        // JSON request → 401 (Laravel's auth middleware renders 401 for
        // expectsJson requests instead of the 302 login redirect).
        $response = $this->getJson(route('sse.status'));
        $response->assertUnauthorized();
    }

    /**
     * Mock the Redis facade so that `Redis::connection('default')->ping()`
     * succeeds AND `Redis::get(HEARTBEAT_KEY)` returns the provided payload.
     *
     * @param string|null $heartbeatJson The JSON string to return for the
     *                                   heartbeat key, or null to simulate
     *                                   "key does not exist" (Redis::get
     *                                   returns null).
     */
    private function mockRedisHeartbeat(?string $heartbeatJson): void
    {
        // Mock the underlying Predis\Client returned by Redis::connection().
        // We don't need to assert ping() was called — only that it returns
        // truthy so $redisStatus = 'connected' (the assertion target is
        // worker_running / heartbeat_stale, not redis_status).
        $redisMock = Mockery::mock('Predis\\Client');
        $redisMock->shouldReceive('ping')->andReturn(true);

        Redis::shouldReceive('connection')->andReturn($redisMock);

        if ($heartbeatJson !== null) {
            Redis::shouldReceive('get')
                ->with(self::HEARTBEAT_KEY)
                ->andReturn($heartbeatJson);
        } else {
            Redis::shouldReceive('get')
                ->with(self::HEARTBEAT_KEY)
                ->andReturn(null);
        }
    }
}

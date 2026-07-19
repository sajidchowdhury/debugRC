<?php

namespace Tests\Feature\Api;

use App\Models\Branch;
use App\Models\User;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\IssuesApiTokens;
use Tests\TestCase;

/**
 * Phase 19 (Task 19-RATELIMIT-PRINT) — API rate-limiting tests.
 *
 * Covers the ApiRateLimit middleware applied to every /api/v1/* route.
 *
 *   - Allows requests under the configured limit (60 req/min default).
 *   - Blocks the 61st request with HTTP 429 + JSON body.
 *   - Returns X-RateLimit-Limit / -Remaining / -Reset headers on every
 *     response.
 *   - Returns Retry-After header (and JSON retry_after field) when limited.
 *   - Custom limit via the middleware parameter (api.rate:5).
 *   - Per-token bucket isolation (different tokens have separate counts).
 *   - /api/docs is NOT rate-limited (always 200).
 */
class ApiRateLimitTest extends TestCase
{
    use BuildsRoleUsers, IssuesApiTokens;

    /**
     * Sentinel limit used by the over-limit tests — small enough that the
     * test isn't noisy (61 vs N) but large enough that we exercise the
     * increment path several times before crossing the line.
     */
    private const TEST_LIMIT_SMALL = 5;

    // ====================================================================
    // UNDER-LIMIT (default 60 req/min)
    // ====================================================================

    public function test_rate_limit_allows_requests_under_limit(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        $headers = ['Authorization' => $this->bearerHeader($token)];

        // Default limit is 60. Send 5 requests — all must succeed.
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($headers)
                ->getJson('/api/v1/branches')
                ->assertOk();
        }
    }

    // ====================================================================
    // OVER-LIMIT (custom small limit via middleware parameter)
    // ====================================================================

    public function test_rate_limit_blocks_requests_over_limit(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        $headers = ['Authorization' => $this->bearerHeader($token)];

        // Use the dashboard route which has api.rate:120 — but to verify the
        // blocking path we use the branches route (api.rate:60) and hammer it.
        // Send 61 requests — the 61st must return 429.
        for ($i = 0; $i < 60; $i++) {
            $response = $this->withHeaders($headers)->getJson('/api/v1/branches');
            $this->assertSame(200, $response->status(), "Request #{$i} should succeed.");
        }

        // 61st request — over the 60/min default cap.
        $over = $this->withHeaders($headers)->getJson('/api/v1/branches');
        $over->assertStatus(429);
        $over->assertJson(['message' => 'Rate limit exceeded. Maximum 60 requests per minute.']);
        $over->assertJsonStructure(['retry_after']);
    }

    // ====================================================================
    // HEADERS
    // ====================================================================

    public function test_rate_limit_returns_correct_headers(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/branches');

        $response->assertOk();
        $response->assertHeader('X-RateLimit-Limit');
        $response->assertHeader('X-RateLimit-Remaining');
        $response->assertHeader('X-RateLimit-Reset');

        // Limit header should be the default 60.
        $this->assertSame('60', $response->headers->get('X-RateLimit-Limit'));
        // After 1 request, 59 remaining.
        $this->assertSame('59', $response->headers->get('X-RateLimit-Remaining'));
    }

    // ====================================================================
    // RETRY-AFTER
    // ====================================================================

    public function test_rate_limit_returns_retry_after(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        $headers = ['Authorization' => $this->bearerHeader($token)];

        // Exhaust the 60/min cap.
        for ($i = 0; $i < 60; $i++) {
            $this->withHeaders($headers)->getJson('/api/v1/branches');
        }

        // 61st — should be 429 with Retry-After header.
        $over = $this->withHeaders($headers)->getJson('/api/v1/branches');
        $over->assertStatus(429);
        $over->assertHeader('Retry-After');

        $retryAfter = (int) $over->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);

        // JSON body should carry the same retry_after value.
        $this->assertSame($retryAfter, (int) $over->json('retry_after'));
    }

    // ====================================================================
    // CUSTOM LIMIT VIA MIDDLEWARE PARAMETER
    // ====================================================================

    public function test_rate_limit_custom_limit_via_middleware_parameter(): void
    {
        $user  = $this->makeRoleUser('admin');
        $token = $this->apiTokenForUser($user);

        $headers = ['Authorization' => $this->bearerHeader($token)];

        // Dashboard endpoints have api.rate:120 — verify the limit header reflects 120.
        $response = $this->withHeaders($headers)->getJson('/api/v1/dashboard');
        $response->assertOk();
        $this->assertSame('120', $response->headers->get('X-RateLimit-Limit'));
    }

    // ====================================================================
    // PER-TOKEN ISOLATION
    // ====================================================================

    public function test_rate_limit_separate_per_token(): void
    {
        $userA  = $this->makeRoleUser('admin');
        $tokenA = $this->apiTokenForUser($userA);

        $userB  = $this->makeRoleUser('manager');
        $tokenB = $this->apiTokenForUser($userB);

        $headersA = ['Authorization' => $this->bearerHeader($tokenA)];
        $headersB = ['Authorization' => $this->bearerHeader($tokenB)];

        // Burn through the entire budget for token A.
        for ($i = 0; $i < 60; $i++) {
            $this->withHeaders($headersA)->getJson('/api/v1/branches');
        }

        // Token A is now throttled.
        $this->withHeaders($headersA)->getJson('/api/v1/branches')->assertStatus(429);

        // Token B has its own bucket — must still succeed.
        $this->withHeaders($headersB)->getJson('/api/v1/branches')->assertOk();
    }

    // ====================================================================
    // DOCS PAGE NOT RATE-LIMITED
    // ====================================================================

    public function test_rate_limit_not_applied_to_docs_page(): void
    {
        // Send many requests to the docs page — all must succeed (no 429).
        for ($i = 0; $i < 70; $i++) {
            $response = $this->get('/api/docs');
            $this->assertSame(200, $response->status(), "Docs request #{$i} should always succeed (not rate-limited).");
        }
    }
}

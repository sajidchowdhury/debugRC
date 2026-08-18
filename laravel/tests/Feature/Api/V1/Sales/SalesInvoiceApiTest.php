<?php

namespace Tests\Feature\Api\V1\Sales;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\IssuesApiTokens;
use Tests\TestCase;

/**
 * Sales Invoice API — idempotency replay tests (G11).
 *
 * POST /api/v1/sales/invoices implements idempotency via Cache::get/put
 * keyed on `api:finalize:{idempotency_token}` with a 5-minute TTL. The
 * replay path (Cache::get hit → return cached response with
 * `idempotent_replay: true`) was previously unexercised by tests.
 *
 * This test pre-populates the cache with a synthetic "previous response"
 * and verifies the controller returns it verbatim (status 200, NOT 201)
 * with the `idempotent_replay` flag appended, instead of invoking
 * SalesInvoiceService::finalizeFromCart() a second time.
 *
 * Test strategy: focus on the replay code path in isolation. A full
 * happy-path test (real cart → finalize → 201) requires extensive sales
 * fixture setup (cart + items + customer + branch + stock) which is out
 * of scope for the G11 quick fix and is tracked separately as part of
 * the broader SalesInvoiceApiTest coverage gap (G3).
 */
class SalesInvoiceApiTest extends TestCase
{
    use BuildsRoleUsers;
    use IssuesApiTokens;
    use InsertsBranchDependencies;

    private User $adminUser;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->makeRoleUser('admin');
        $this->branchId  = $this->adminUser->getBranchId();
    }

    /**
     * G11 — Same idempotency_token returns the cached response with
     * `idempotent_replay: true` instead of creating a second invoice.
     *
     * Mirrors the contract in SalesInvoiceApiController::store() at
     * app/Http/Controllers/Api/V1/Sales/SalesInvoiceApiController.php:103-110:
     *
     *   $cacheKey = 'api:finalize:' . $validated['idempotency_token'];
     *   $cached = Cache::get($cacheKey);
     *   if ($cached !== null) {
     *       return response()->json(array_merge($cached, [
     *           'idempotent_replay' => true,
     *       ]));
     *   }
     *
     * Note: the invoice replay path PRESERVES the original `message`
     * (unlike the payment + challan replays which overwrite it).
     */
    public function test_finalize_with_same_idempotency_token_returns_idempotent_replay(): void
    {
        $token       = $this->apiTokenForUser($this->adminUser);
        $customerId  = $this->insertCustomer($this->branchId);
        $idemToken   = (string) Str::uuid();
        $cacheKey    = 'api:finalize:' . $idemToken;

        // Synthetic "previous response" cached by a prior first-call.
        $cachedResult = [
            'message' => 'Invoice created successfully',
            'data'    => [
                'id'           => 99999,
                'invoice_code' => 'INV-IDEM-001',
                'total'        => 1500.00,
            ],
        ];
        Cache::put($cacheKey, $cachedResult, now()->addMinutes(5));

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/invoices', [
                'customer_id'       => $customerId,
                'branch_id'         => $this->branchId,
                'invoice_date'      => now()->toDateString(),
                'idempotency_token' => $idemToken,
            ]);

        // Replay path returns 200 (default), NOT 201 (created).
        $response->assertStatus(200);
        $response->assertJson(['idempotent_replay' => true]);

        // Original message preserved (NOT overwritten with a "duplicate" message).
        $response->assertJsonPath('message', 'Invoice created successfully');

        // Cached payload returned verbatim.
        $response->assertJsonPath('data.id', 99999);
        $response->assertJsonPath('data.invoice_code', 'INV-IDEM-001');
        // Use assertEquals (loose) instead of assertJsonPath (strict same)
        // because JSON round-trip converts 1500.0 → 1500 (int).
        $this->assertEquals(1500.00, $response->json('data.total'));

        // Cache entry must still exist (replay does not evict).
        $this->assertNotNull(Cache::get($cacheKey));
    }

    /**
     * Sanity check — a fresh idempotency_token (no cached entry) must NOT
     * be treated as a replay. It will proceed to the service layer (and
     * likely 422/409 on missing cart context), but the response must NOT
     * contain `idempotent_replay: true`. This guards against a regression
     * where the cache-key prefix collides or the null-check is inverted.
     */
    public function test_finalize_with_fresh_token_is_not_treated_as_replay(): void
    {
        $token      = $this->apiTokenForUser($this->adminUser);
        $customerId = $this->insertCustomer($this->branchId);
        $idemToken  = (string) Str::uuid();

        // Ensure no stale cache entry.
        Cache::forget('api:finalize:' . $idemToken);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/invoices', [
                'customer_id'       => $customerId,
                'branch_id'         => $this->branchId,
                'invoice_date'      => now()->toDateString(),
                'idempotency_token' => $idemToken,
            ]);

        // Without a cart, finalizeFromCart() throws — caught as 422 or 409.
        // The exact status is environment-dependent; what matters is that
        // the response is NOT flagged as an idempotent replay.
        $this->assertContains($response->status(), [201, 409, 422]);
        $response->assertJsonMissing(['idempotent_replay' => true]);
    }
}

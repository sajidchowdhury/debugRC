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
 * Customer Payment API — idempotency replay tests (G11).
 *
 * POST /api/v1/sales/payments implements idempotency via Cache::get/put
 * keyed on `api:payment:{idempotency_token}` with a 5-minute TTL. The
 * replay path was previously unexercised by tests.
 *
 * Replay contract (CustomerPaymentApiController::store at
 * app/Http/Controllers/Api/V1/Sales/CustomerPaymentApiController.php:127-133):
 *
 *   return response()->json(array_merge($cached, [
 *       'idempotent_replay' => true,
 *       'message' => 'Duplicate submission detected — returning the
 *                     original result. No new payment was created.',
 *   ]));
 *
 * Unlike the invoice replay, the payment replay OVERWRITES the original
 * `message` field with the duplicate-submission notice.
 */
class CustomerPaymentApiTest extends TestCase
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
     * `idempotent_replay: true` and the duplicate-submission message,
     * instead of creating a second payment.
     */
    public function test_create_payment_with_same_idempotency_token_returns_idempotent_replay(): void
    {
        $token      = $this->apiTokenForUser($this->adminUser);
        $customerId = $this->insertCustomer($this->branchId);
        $idemToken  = (string) Str::uuid();
        $cacheKey   = 'api:payment:' . $idemToken;

        // Synthetic "previous response" cached by a prior first-call.
        $cachedResult = [
            'message'    => 'Draft payment created successfully',
            'data'       => [
                'id'            => 88888,
                'payment_code'  => 'PAY-IDEM-001',
                'amount'        => 5000.00,
            ],
            'confirmed'  => false,
        ];
        Cache::put($cacheKey, $cachedResult, now()->addMinutes(5));

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/payments', [
                'customer_id'       => $customerId,
                'branch_id'         => $this->branchId,
                'payment_mode'      => 'cash',
                'transaction_type'  => 'receive',
                'amount'            => 5000.00,
                'payment_date'      => now()->toDateString(),
                'idempotency_token' => $idemToken,
            ]);

        // Replay path returns 200 (default), NOT 201 (created).
        $response->assertStatus(200);
        $response->assertJson(['idempotent_replay' => true]);

        // Message OVERWRITTEN with the duplicate-submission notice.
        $response->assertJsonPath(
            'message',
            'Duplicate submission detected — returning the original result. No new payment was created.'
        );

        // Cached payload returned verbatim.
        $response->assertJsonPath('data.id', 88888);
        $response->assertJsonPath('data.payment_code', 'PAY-IDEM-001');
        // Use assertEquals (loose) instead of assertJsonPath (strict same)
        // because JSON round-trip converts 5000.0 → 5000 (int).
        $this->assertEquals(5000.00, $response->json('data.amount'));
        $response->assertJsonPath('confirmed', false);

        // Cache entry must still exist (replay does not evict).
        $this->assertNotNull(Cache::get($cacheKey));
    }

    /**
     * Sanity check — a fresh idempotency_token must NOT be treated as a
     * replay. The request will proceed past the cache check (and likely
     * fail downstream on customer-ledger/bank validation), but the
     * response must NOT contain `idempotent_replay: true`.
     */
    public function test_create_payment_with_fresh_token_is_not_treated_as_replay(): void
    {
        $token      = $this->apiTokenForUser($this->adminUser);
        $customerId = $this->insertCustomer($this->branchId);
        $idemToken  = (string) Str::uuid();

        Cache::forget('api:payment:' . $idemToken);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/payments', [
                'customer_id'       => $customerId,
                'branch_id'         => $this->branchId,
                'payment_mode'      => 'cash',
                'transaction_type'  => 'receive',
                'amount'            => 100.00,
                'payment_date'      => now()->toDateString(),
                'idempotency_token' => $idemToken,
            ]);

        // Downstream behaviour is environment-dependent (201 on success,
        // 422/409 on validation/runtime errors). What matters here is
        // that the response is NOT flagged as a replay.
        $this->assertContains($response->status(), [201, 409, 422]);
        $response->assertJsonMissing(['idempotent_replay' => true]);
    }
}

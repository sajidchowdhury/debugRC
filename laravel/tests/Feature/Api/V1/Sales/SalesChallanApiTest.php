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
 * Sales Challan API — idempotency replay tests (G11).
 *
 * POST /api/v1/sales/challans/issue implements idempotency via
 * Cache::get/put keyed on `api:challan:{idempotency_token}` with a
 * 5-minute TTL. The replay path was previously unexercised by tests.
 *
 * Replay contract (SalesChallanApiController::issue at
 * app/Http/Controllers/Api/V1/Sales/SalesChallanApiController.php:158-164):
 *
 *   return response()->json(array_merge($cached, [
 *       'idempotent_replay' => true,
 *       'message' => 'Duplicate submission detected — returning the
 *                     original result. No new challan was created.',
 *   ]));
 *
 * Like the payment replay (and unlike the invoice replay), the challan
 * replay OVERWRITES the original `message` field with the
 * duplicate-submission notice.
 *
 * The challan endpoint requires `api.auth:warehouse_manager,dispatcher,
 * manager,admin` — admin satisfies the role gate.
 */
class SalesChallanApiTest extends TestCase
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
     * instead of issuing a second challan.
     */
    public function test_issue_challan_with_same_idempotency_token_returns_idempotent_replay(): void
    {
        $token       = $this->apiTokenForUser($this->adminUser);
        $invoiceId   = $this->insertSalesInvoice($this->branchId, 'confirmed');
        $idemToken   = (string) Str::uuid();
        $cacheKey    = 'api:challan:' . $idemToken;

        // Synthetic "previous response" cached by a prior first-call.
        $cachedResult = [
            'message' => 'Challan issued successfully',
            'data'    => [
                'id'           => 77777,
                'challan_code' => 'CHL-IDEM-001',
                'sales_invoice_id' => $invoiceId,
            ],
        ];
        Cache::put($cacheKey, $cachedResult, now()->addMinutes(5));

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/challans/issue', [
                'sales_invoice_id'  => $invoiceId,
                'idempotency_token' => $idemToken,
            ]);

        // Replay path returns 200 (default), NOT 201 (created).
        $response->assertStatus(200);
        $response->assertJson(['idempotent_replay' => true]);

        // Message OVERWRITTEN with the duplicate-submission notice.
        $response->assertJsonPath(
            'message',
            'Duplicate submission detected — returning the original result. No new challan was created.'
        );

        // Cached payload returned verbatim.
        $response->assertJsonPath('data.id', 77777);
        $response->assertJsonPath('data.challan_code', 'CHL-IDEM-001');
        $response->assertJsonPath('data.sales_invoice_id', $invoiceId);

        // Cache entry must still exist (replay does not evict).
        $this->assertNotNull(Cache::get($cacheKey));
    }

    /**
     * Sanity check — a fresh idempotency_token must NOT be treated as a
     * replay. The request will proceed past the cache check (and likely
     * fail downstream on invoice-status validation), but the response
     * must NOT contain `idempotent_replay: true`.
     */
    public function test_issue_challan_with_fresh_token_is_not_treated_as_replay(): void
    {
        $token     = $this->apiTokenForUser($this->adminUser);
        $invoiceId = $this->insertSalesInvoice($this->branchId, 'confirmed');
        $idemToken = (string) Str::uuid();

        Cache::forget('api:challan:' . $idemToken);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/sales/challans/issue', [
                'sales_invoice_id'  => $invoiceId,
                'idempotency_token' => $idemToken,
            ]);

        // Downstream behaviour is environment-dependent (201 on success,
        // 409 on invoice-status guard). What matters here is that the
        // response is NOT flagged as a replay.
        $this->assertContains($response->status(), [201, 409, 422]);
        $response->assertJsonMissing(['idempotent_replay' => true]);
    }
}

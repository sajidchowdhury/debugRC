<?php

namespace Tests\Feature\Api\V1\StockAdjustment;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsProductDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\Helpers\IssuesApiTokens;
use Tests\TestCase;

/**
 * Stock Adjustment API Feature Tests — Task 37 / API-4 (G3 backfill).
 *
 * Tests the StockAdjustmentApiController's REST API endpoints:
 *   GET    /api/v1/stock-adjustments                List (paginated + filtered)
 *   POST   /api/v1/stock-adjustments                Create draft (admin/manager/accountant)
 *   GET    /api/v1/stock-adjustments/{id}           Show detail
 *   POST   /api/v1/stock-adjustments/{id}/submit    Submit draft (admin/manager/accountant)
 *   POST   /api/v1/stock-adjustments/{id}/approve   Approve (admin/manager)
 *   POST   /api/v1/stock-adjustments/{id}/reject    Reject → draft (admin/manager)
 *   POST   /api/v1/stock-adjustments/{id}/confirm   Confirm = apply stock + GL (admin/accountant)
 *   POST   /api/v1/stock-adjustments/{id}/cancel    Cancel = reverse stock + GL (admin/accountant)
 *
 * Auth coverage:
 *   - missing Authorization header → 401
 *   - invalid token → 401
 *   - salesman token on POST store → 403 (api.auth:admin,manager,accountant)
 *   - accountant token on POST approve → 403 (api.auth:admin,manager)
 *
 * State-machine coverage:
 *   - draft created via POST store → status='draft' persisted
 *   - cancel on a draft → status='cancelled' persisted (no stock/GL to reverse)
 *
 * Uses BuildsRoleUsers + IssuesApiTokens (G5 consistency).
 *
 * NOTE: the full submit→approve→confirm→cancel lifecycle requires journal
 * + stock_transaction fixtures + the auto-approve threshold policy. The
 * confirm path posts GL via JournalPostingService which depends on ledger
 * accounts — out of scope for this backfill. The state-machine test
 * covers the draft → cancelled transition (the simplest legal path that
 * persists a status change without invoking GL).
 */
class StockAdjustmentApiTest extends TestCase
{
    use BuildsRoleUsers, IssuesApiTokens;
    use InsertsBranchDependencies, InsertsWarehouseDependencies, InsertsProductDependencies;

    private User $adminUser;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->makeRoleUser('admin');
        $this->branchId  = $this->adminUser->getBranchId();
    }

    // ====================================================================
    // AUTH
    // ====================================================================

    public function test_list_returns_401_when_no_token(): void
    {
        $this->getJson('/api/v1/stock-adjustments/')->assertUnauthorized();
    }

    public function test_list_returns_401_when_token_is_invalid(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
            ->getJson('/api/v1/stock-adjustments/')
            ->assertUnauthorized();
    }

    public function test_store_returns_403_for_salesman(): void
    {
        // 'salesman' is NOT in api.auth:admin,manager,accountant on store.
        $salesman = $this->makeRoleUser('salesman');
        $token    = $this->apiTokenForUser($salesman);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/stock-adjustments/', [])
            ->assertForbidden();
    }

    public function test_approve_returns_403_for_accountant(): void
    {
        // 'accountant' is NOT in api.auth:admin,manager on approve.
        $accountant = $this->makeRoleUser('accountant');
        $token      = $this->apiTokenForUser($accountant);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/stock-adjustments/1/approve', ['comment' => 'try approve'])
            ->assertForbidden();
    }

    // ====================================================================
    // LIST (index)
    // ====================================================================

    public function test_list_returns_paginated_json_with_valid_token(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/stock-adjustments/');

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
    }

    public function test_list_clamps_per_page_to_100(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/stock-adjustments/?per_page=999');

        $response->assertOk();
        $this->assertLessThanOrEqual(100, $response->json('meta.per_page'));
    }

    // ====================================================================
    // SHOW
    // ====================================================================

    public function test_show_returns_adjustment_detail_with_valid_token(): void
    {
        $token        = $this->apiTokenForUser($this->adminUser);
        $warehouseId  = $this->insertWarehouse($this->branchId);
        $adjustmentId = $this->insertStockAdjustment($warehouseId, $this->branchId);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/stock-adjustments/{$adjustmentId}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $adjustmentId);
    }

    public function test_show_returns_404_for_unknown_id(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/stock-adjustments/9999999')
            ->assertNotFound();
    }

    // ====================================================================
    // STORE (create draft)
    // ====================================================================

    public function test_store_creates_draft_with_admin_token(): void
    {
        $token       = $this->apiTokenForUser($this->adminUser);
        $warehouseId = $this->insertWarehouse($this->branchId);
        $productId   = $this->insertProduct();

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/stock-adjustments/', [
                'warehouse_id'        => $warehouseId,
                'adjustment_type'     => 'increase',
                'adjustment_category' => 'opening_balance',
                'adjustment_date'     => now()->toDateString(),
                'reason'              => 'Initial stock setup',
                'items'               => [
                    [
                        'product_id' => $productId,
                        'qty'        => 5,
                        'rate'       => 10.00,
                    ],
                ],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonStructure(['data' => ['id', 'adjustment_code', 'status'], 'message']);

        $this->assertDatabaseHas('stock_adjustments', [
            'id'              => $response->json('data.id'),
            'warehouse_id'    => $warehouseId,
            'adjustment_type' => 'increase',
            'status'          => 'draft',
            'branch_id'       => $this->branchId,
        ]);
    }

    public function test_store_returns_422_when_warehouse_id_missing(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/stock-adjustments/', [
                'adjustment_type'     => 'increase',
                'adjustment_category' => 'other',
                'adjustment_date'     => now()->toDateString(),
                'items'               => [['product_id' => 1, 'qty' => 1]],
                // warehouse_id missing
            ])
            ->assertStatus(422);
    }

    public function test_store_returns_422_for_invalid_adjustment_type(): void
    {
        $token       = $this->apiTokenForUser($this->adminUser);
        $warehouseId = $this->insertWarehouse($this->branchId);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/stock-adjustments/', [
                'warehouse_id'        => $warehouseId,
                'adjustment_type'     => 'invalid_type',     // not increase|decrease
                'adjustment_category' => 'other',
                'adjustment_date'     => now()->toDateString(),
                'items'               => [['product_id' => 1, 'qty' => 1]],
            ])
            ->assertStatus(422);
    }

    public function test_store_returns_422_for_invalid_adjustment_category(): void
    {
        $token       = $this->apiTokenForUser($this->adminUser);
        $warehouseId = $this->insertWarehouse($this->branchId);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/stock-adjustments/', [
                'warehouse_id'        => $warehouseId,
                'adjustment_type'     => 'increase',
                'adjustment_category' => 'not_a_real_category', // not in ADJUSTMENT_CATEGORIES
                'adjustment_date'     => now()->toDateString(),
                'items'               => [['product_id' => 1, 'qty' => 1]],
            ])
            ->assertStatus(422);
    }

    // ====================================================================
    // CANCEL (state transition — draft → cancelled persists)
    // ====================================================================

    public function test_cancel_transitions_draft_to_cancelled_with_admin_token(): void
    {
        $token        = $this->apiTokenForUser($this->adminUser);
        $warehouseId  = $this->insertWarehouse($this->branchId);
        $adjustmentId = $this->insertStockAdjustment($warehouseId, $this->branchId, 'draft');

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/stock-adjustments/{$adjustmentId}/cancel", [
                'cancel_reason' => 'Draft abandoned — superseded by adjustment #99.',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'cancelled');

        // State-machine persistence: the status column transitioned.
        $this->assertDatabaseHas('stock_adjustments', [
            'id'     => $adjustmentId,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancel_returns_422_when_reason_missing(): void
    {
        $token        = $this->apiTokenForUser($this->adminUser);
        $warehouseId  = $this->insertWarehouse($this->branchId);
        $adjustmentId = $this->insertStockAdjustment($warehouseId, $this->branchId, 'draft');

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/stock-adjustments/{$adjustmentId}/cancel", [
                // cancel_reason missing
            ])
            ->assertStatus(422);
    }

    /**
     * Insert a stock_adjustments row with the minimum required columns.
     *
     * Bypasses StockAdjustmentService::createAdjustment so the read +
     * cancel tests don't need a product + items fixture. Mirrors the
     * InsertsBranchDependencies direct-DB::table pattern.
     */
    private function insertStockAdjustment(int $warehouseId, int $branchId, string $status = 'draft'): int
    {
        return DB::table('stock_adjustments')->insertGetId([
            'adjustment_code'     => 'ADJ-' . substr(uniqid(), -8),
            'adjustment_date'     => now()->toDateString(),
            'warehouse_id'        => $warehouseId,
            'branch_id'           => $branchId,
            'adjustment_type'     => 'increase',
            'adjustment_category' => 'other',
            'total_amount'        => 0,
            'reason'              => 'Test fixture',
            'status'              => $status,
            'is_reversed'         => false,
            'fiscal_year_id'      => $this->resolveActiveFiscalYearId(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }
}

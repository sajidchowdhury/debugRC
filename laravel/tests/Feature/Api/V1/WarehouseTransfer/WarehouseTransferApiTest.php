<?php

namespace Tests\Feature\Api\V1\WarehouseTransfer;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsProductDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\Helpers\IssuesApiTokens;
use Tests\TestCase;

/**
 * Warehouse Transfer API Feature Tests — Task 37 / API-4 (G3 backfill).
 *
 * Tests the WarehouseTransferApiController's REST API endpoints:
 *   GET    /api/v1/warehouse-transfers                List (paginated + filtered)
 *   POST   /api/v1/warehouse-transfers                Create draft (any auth)
 *   GET    /api/v1/warehouse-transfers/{id}           Show detail
 *   POST   /api/v1/warehouse-transfers/{id}/confirm   Confirm (manager/admin)
 *   POST   /api/v1/warehouse-transfers/{id}/cancel    Cancel/reverse (manager/admin)
 *   GET    /api/v1/warehouse-transfers/product-stock  Pipeline-aware availability
 *
 * Auth coverage:
 *   - missing Authorization header → 401
 *   - invalid token → 401
 *   - salesman token on POST confirm → 403 (api.auth:manager,admin)
 *   - salesman token on POST cancel → 403 (api.auth:manager,admin)
 *
 * State-machine coverage:
 *   - draft created via POST store → status='draft' persisted
 *   - cancel on a draft → status='cancelled' persisted (no stock to reverse)
 *
 * Uses BuildsRoleUsers + IssuesApiTokens (G5 consistency).
 *
 * NOTE: the confirm path applies stock movements + posts GL via
 * WarehouseTransferService::confirmTransfer — out of scope for this
 * backfill (requires journal-entry + stock_transaction fixtures). The
 * state-machine test covers draft → cancelled (the simplest legal path
 * that persists a status change without invoking GL).
 */
class WarehouseTransferApiTest extends TestCase
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
        $this->getJson('/api/v1/warehouse-transfers/')->assertUnauthorized();
    }

    public function test_list_returns_401_when_token_is_invalid(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
            ->getJson('/api/v1/warehouse-transfers/')
            ->assertUnauthorized();
    }

    public function test_confirm_returns_403_for_salesman(): void
    {
        // 'salesman' is NOT in api.auth:manager,admin on confirm.
        $salesman = $this->makeRoleUser('salesman');
        $token    = $this->apiTokenForUser($salesman);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/warehouse-transfers/1/confirm')
            ->assertForbidden();
    }

    public function test_cancel_returns_403_for_salesman(): void
    {
        // 'salesman' is NOT in api.auth:manager,admin on cancel.
        $salesman = $this->makeRoleUser('salesman');
        $token    = $this->apiTokenForUser($salesman);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/warehouse-transfers/1/cancel', [
                'cancel_reason' => 'should be blocked by role gate',
            ])
            ->assertForbidden();
    }

    // ====================================================================
    // LIST (index)
    // ====================================================================

    public function test_list_returns_paginated_json_with_valid_token(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/warehouse-transfers/');

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
            ->getJson('/api/v1/warehouse-transfers/?per_page=999');

        $response->assertOk();
        $this->assertLessThanOrEqual(100, $response->json('meta.per_page'));
    }

    // ====================================================================
    // SHOW
    // ====================================================================

    public function test_show_returns_transfer_detail_with_valid_token(): void
    {
        $token        = $this->apiTokenForUser($this->adminUser);
        $warehouseId1 = $this->insertWarehouse($this->branchId);
        $warehouseId2 = $this->insertWarehouse($this->branchId);
        $transferId   = $this->insertDraftTransfer($warehouseId1, $warehouseId2, $this->branchId);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/warehouse-transfers/{$transferId}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $transferId);
    }

    public function test_show_returns_404_for_unknown_id(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/warehouse-transfers/9999999')
            ->assertNotFound();
    }

    // ====================================================================
    // PRODUCT-STOCK (pipeline-aware availability)
    // ====================================================================

    public function test_product_stock_returns_200_with_stock_info(): void
    {
        $token       = $this->apiTokenForUser($this->adminUser);
        $warehouseId = $this->insertWarehouse($this->branchId);
        $productId   = $this->insertProduct();
        $this->insertWarehouseStock($warehouseId, $productId, 50.0);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/warehouse-transfers/product-stock?product_id={$productId}&warehouse_id={$warehouseId}");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => ['product_id', 'warehouse_id', 'rate', 'physical_qty', 'available_qty', 'pipeline_qty'],
        ]);
        $this->assertSame($productId, $response->json('data.product_id'));
        $this->assertSame($warehouseId, $response->json('data.warehouse_id'));
        $this->assertSame(50.0, (float) $response->json('data.physical_qty'));
    }

    public function test_product_stock_returns_422_when_product_id_missing(): void
    {
        $token       = $this->apiTokenForUser($this->adminUser);
        $warehouseId = $this->insertWarehouse($this->branchId);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/warehouse-transfers/product-stock?warehouse_id={$warehouseId}")
            ->assertStatus(422);
    }

    // ====================================================================
    // STORE (create draft)
    // ====================================================================

    public function test_store_creates_draft_with_admin_token(): void
    {
        $token        = $this->apiTokenForUser($this->adminUser);
        $warehouseId1 = $this->insertWarehouse($this->branchId);
        $warehouseId2 = $this->insertWarehouse($this->branchId);
        $productId    = $this->insertProduct();
        $this->insertWarehouseStock($warehouseId1, $productId, 100.0);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/warehouse-transfers/', [
                'from_warehouse_id' => $warehouseId1,
                'to_warehouse_id'   => $warehouseId2,
                'transfer_date'     => now()->toDateString(),
                'notes'             => 'Restock from main to sub warehouse.',
                'items'             => [
                    [
                        'product_id' => $productId,
                        'qty'        => 5,
                        'rate'       => 10.00,
                    ],
                ],
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonStructure(['data' => ['id', 'transfer_code', 'status'], 'message']);

        $this->assertDatabaseHas('warehouse_transfers', [
            'id'                => $response->json('data.id'),
            'from_warehouse_id' => $warehouseId1,
            'to_warehouse_id'   => $warehouseId2,
            'status'            => 'draft',
        ]);
    }

    public function test_store_returns_422_when_from_warehouse_id_missing(): void
    {
        $token        = $this->apiTokenForUser($this->adminUser);
        $warehouseId2 = $this->insertWarehouse($this->branchId);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/warehouse-transfers/', [
                // from_warehouse_id missing
                'to_warehouse_id' => $warehouseId2,
                'transfer_date'   => now()->toDateString(),
                'items'           => [['product_id' => 1, 'qty' => 1]],
            ])
            ->assertStatus(422);
    }

    public function test_store_returns_422_when_items_array_missing(): void
    {
        $token        = $this->apiTokenForUser($this->adminUser);
        $warehouseId1 = $this->insertWarehouse($this->branchId);
        $warehouseId2 = $this->insertWarehouse($this->branchId);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson('/api/v1/warehouse-transfers/', [
                'from_warehouse_id' => $warehouseId1,
                'to_warehouse_id'   => $warehouseId2,
                'transfer_date'     => now()->toDateString(),
                // items missing
            ])
            ->assertStatus(422);
    }

    // ====================================================================
    // CANCEL (state transition — draft → cancelled persists)
    // ====================================================================

    public function test_cancel_transitions_draft_to_cancelled_with_admin_token(): void
    {
        $token        = $this->apiTokenForUser($this->adminUser);
        $warehouseId1 = $this->insertWarehouse($this->branchId);
        $warehouseId2 = $this->insertWarehouse($this->branchId);
        $transferId   = $this->insertDraftTransfer($warehouseId1, $warehouseId2, $this->branchId);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/warehouse-transfers/{$transferId}/cancel", [
                'cancel_reason' => 'Draft abandoned — destination warehouse closing for stock take.',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'cancelled');

        // State-machine persistence: the status column transitioned.
        $this->assertDatabaseHas('warehouse_transfers', [
            'id'     => $transferId,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancel_returns_422_when_reason_missing(): void
    {
        $token        = $this->apiTokenForUser($this->adminUser);
        $warehouseId1 = $this->insertWarehouse($this->branchId);
        $warehouseId2 = $this->insertWarehouse($this->branchId);
        $transferId   = $this->insertDraftTransfer($warehouseId1, $warehouseId2, $this->branchId);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->postJson("/api/v1/warehouse-transfers/{$transferId}/cancel", [
                // cancel_reason missing
            ])
            ->assertStatus(422);
    }

    /**
     * Insert a warehouse_transfers draft row with the minimum required columns.
     *
     * Bypasses WarehouseTransferService::createTransfer so the read + cancel
     * tests don't need a product + items + stock fixture. Mirrors the
     * InsertsBranchDependencies direct-DB::table pattern.
     */
    private function insertDraftTransfer(int $fromWh, int $toWh, int $branchId): int
    {
        return DB::table('warehouse_transfers')->insertGetId([
            'transfer_code'      => 'WT-' . substr(uniqid(), -8),
            'transfer_date'      => now()->toDateString(),
            'from_warehouse_id'  => $fromWh,
            'to_warehouse_id'    => $toWh,
            'from_branch_id'     => $branchId,
            'to_branch_id'       => $branchId,
            'is_interbranch'     => false,
            'status'             => 'draft',
            'is_reversed'        => false,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }
}

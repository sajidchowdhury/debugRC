<?php

namespace Tests\Feature\Api\V1\StockTake;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsProductDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\Helpers\IssuesApiTokens;
use Tests\Helpers\ResolvesActiveFiscalYear;
use Tests\TestCase;

/**
 * Stock Take Item API Feature Tests — Task 37 / API-4 (G3 backfill).
 *
 * Tests the StockTakeItemApiController's REST API endpoints (all under
 * /api/v1/stock-take/sessions/{id}):
 *   GET    /items                 List items (filtered by warehouse_id / variance_only)
 *   GET    /items/{itemId}        Show a single item line
 *   PUT    /items/{itemId}        Autosave one count (physical_qty + optional reason)
 *   GET    /variance              Variance report (items with non-zero difference)
 *
 * Auth coverage:
 *   - missing Authorization header → 401
 *   - invalid token → 401
 *
 * Uses BuildsRoleUsers + IssuesApiTokens (G5 consistency).
 *
 * NOTE: the PUT update happy path routes through StockTakeService::saveCounts
 * which requires the session to be in 'counting' state + the product to be
 * in the warehouse's item set (the full setup→save→post chain is covered by
 * tests/Feature/StockTake/* — out of scope here). This backfill covers AUTH
 * + read-side HAPPY paths + the VALIDATION 422 path on the PUT endpoint.
 */
class StockTakeItemApiTest extends TestCase
{
    use BuildsRoleUsers, IssuesApiTokens;
    use InsertsBranchDependencies, InsertsWarehouseDependencies, InsertsProductDependencies;
    use ResolvesActiveFiscalYear;

    private User $adminUser;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = $this->makeRoleUser('admin');
        $this->branchId  = $this->adminUser->getBranchId();
        $this->resolveActiveFiscalYearId();
    }

    // ====================================================================
    // AUTH
    // ====================================================================

    public function test_list_items_returns_401_when_no_token(): void
    {
        $this->getJson('/api/v1/stock-take/sessions/1/items')->assertUnauthorized();
    }

    public function test_list_items_returns_401_when_token_is_invalid(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer not-a-real-token'])
            ->getJson('/api/v1/stock-take/sessions/1/items')
            ->assertUnauthorized();
    }

    // ====================================================================
    // LIST ITEMS
    // ====================================================================

    public function test_list_items_returns_paginated_json_with_valid_token(): void
    {
        $token       = $this->apiTokenForUser($this->adminUser);
        $warehouseId = $this->insertWarehouse($this->branchId);
        $productId   = $this->insertProduct();
        $sessionId   = $this->insertActiveStockTake($warehouseId, $this->branchId, 'counting');

        $this->insertStockTakeItem($sessionId, $warehouseId, $productId, $this->branchId);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/stock-take/sessions/{$sessionId}/items");

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_list_items_supports_warehouse_filter(): void
    {
        $token        = $this->apiTokenForUser($this->adminUser);
        $warehouseId1 = $this->insertWarehouse($this->branchId);
        $warehouseId2 = $this->insertWarehouse($this->branchId);
        $productId1   = $this->insertProduct();
        $productId2   = $this->insertProduct();
        $sessionId    = $this->insertActiveStockTake($warehouseId1, $this->branchId, 'counting');

        // Link the second warehouse to the same session so both items belong
        // to the same session but different warehouses.
        DB::table('stock_take_warehouses')->insert([
            'stock_take_session_id' => $sessionId,
            'warehouse_id'          => $warehouseId2,
            'branch_id'             => $this->branchId,
            'fiscal_year_id'        => $this->resolveActiveFiscalYearId(),
        ]);

        $this->insertStockTakeItem($sessionId, $warehouseId1, $productId1, $this->branchId);
        $this->insertStockTakeItem($sessionId, $warehouseId2, $productId2, $this->branchId);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/stock-take/sessions/{$sessionId}/items?warehouse_id={$warehouseId1}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        collect($data)->each(fn ($row) => $this->assertSame($warehouseId1, $row['warehouse_id']));
    }

    public function test_list_items_returns_404_for_unknown_session(): void
    {
        $token = $this->apiTokenForUser($this->adminUser);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson('/api/v1/stock-take/sessions/9999999/items')
            ->assertNotFound();
    }

    // ====================================================================
    // SHOW ITEM
    // ====================================================================

    public function test_show_item_returns_detail_with_valid_token(): void
    {
        $token       = $this->apiTokenForUser($this->adminUser);
        $warehouseId = $this->insertWarehouse($this->branchId);
        $productId   = $this->insertProduct();
        $sessionId   = $this->insertActiveStockTake($warehouseId, $this->branchId, 'counting');
        $itemId      = $this->insertStockTakeItem($sessionId, $warehouseId, $productId, $this->branchId);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/stock-take/sessions/{$sessionId}/items/{$itemId}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $itemId);
        $response->assertJsonPath('data.product_id', $productId);
        $response->assertJsonPath('data.warehouse_id', $warehouseId);
    }

    public function test_show_item_returns_404_for_unknown_item(): void
    {
        $token       = $this->apiTokenForUser($this->adminUser);
        $warehouseId = $this->insertWarehouse($this->branchId);
        $sessionId   = $this->insertActiveStockTake($warehouseId, $this->branchId, 'counting');

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/stock-take/sessions/{$sessionId}/items/9999999")
            ->assertNotFound();
    }

    // ====================================================================
    // VARIANCE REPORT
    // ====================================================================

    public function test_variance_report_returns_200_with_summary_meta(): void
    {
        $token       = $this->apiTokenForUser($this->adminUser);
        $warehouseId = $this->insertWarehouse($this->branchId);
        $productId   = $this->insertProduct();
        $sessionId   = $this->insertActiveStockTake($warehouseId, $this->branchId, 'counting');

        // Insert one item WITH variance (physical_qty=12, system_qty=10 → +2 gain).
        $this->insertStockTakeItem($sessionId, $warehouseId, $productId, $this->branchId, 10.0, 12.0);

        $response = $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->getJson("/api/v1/stock-take/sessions/{$sessionId}/variance");

        $response->assertOk();
        $response->assertJsonStructure([
            'data',
            'meta' => ['session_id', 'session_code', 'status', 'variance_lines', 'total_gain', 'total_loss', 'net_value'],
        ]);
        $this->assertSame($sessionId, $response->json('meta.session_id'));
        $this->assertGreaterThanOrEqual(1, $response->json('meta.variance_lines'));
    }

    // ====================================================================
    // UPDATE (validation path — heavy happy-path fixtures out of scope)
    // ====================================================================

    public function test_update_item_returns_422_when_physical_qty_missing(): void
    {
        $token       = $this->apiTokenForUser($this->adminUser);
        $warehouseId = $this->insertWarehouse($this->branchId);
        $productId   = $this->insertProduct();
        $sessionId   = $this->insertActiveStockTake($warehouseId, $this->branchId, 'counting');
        $itemId      = $this->insertStockTakeItem($sessionId, $warehouseId, $productId, $this->branchId);

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->putJson("/api/v1/stock-take/sessions/{$sessionId}/items/{$itemId}", [
                // physical_qty missing
                'reason' => 'variance note',
            ])
            ->assertStatus(422);
    }

    public function test_update_item_returns_404_for_unknown_item(): void
    {
        $token       = $this->apiTokenForUser($this->adminUser);
        $warehouseId = $this->insertWarehouse($this->branchId);
        $sessionId   = $this->insertActiveStockTake($warehouseId, $this->branchId, 'counting');

        $this->withHeaders(['Authorization' => $this->bearerHeader($token)])
            ->putJson("/api/v1/stock-take/sessions/{$sessionId}/items/9999999", [
                'physical_qty' => 48,
            ])
            ->assertNotFound();
    }

    /**
     * Insert a stock_take_items row with the minimum required columns.
     *
     * `difference` is a GENERATED column (physical_qty − system_qty) so we
     * must NOT set it directly. The schema requires branch_id (Phase 8
     * denormalized, NOT NULL). The `rate` column is required (used for
     * variance valuation).
     */
    private function insertStockTakeItem(
        int $sessionId,
        int $warehouseId,
        int $productId,
        int $branchId,
        float $systemQty = 10.0,
        float $physicalQty = 10.0,
    ): int {
        return DB::table('stock_take_items')->insertGetId([
            'stock_take_session_id' => $sessionId,
            'warehouse_id'          => $warehouseId,
            'product_id'            => $productId,
            'branch_id'             => $branchId,
            'system_qty'            => $systemQty,
            'physical_qty'          => $physicalQty,
            'rate'                  => 10.00,
            'system_rate'           => 10.00,
            'post_rate'             => null,
            'revaluation_amount'    => 0,
            'is_applied'            => false,
            'reason'                => null,
            'journal_line_id'       => null,
            'revaluation_line_id'   => null,
        ]);
    }
}

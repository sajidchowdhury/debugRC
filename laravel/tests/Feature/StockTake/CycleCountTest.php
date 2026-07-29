<?php

namespace Tests\Feature\StockTake;

use App\Models\Branch;
use App\Services\Stock\StockTakeService;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Phase 12 — special-features feature tests for the cycle-count scope
 * feature (StockTakeService::validateCountScope + setupWarehouseCounts
 * scope filtering + describeScope).
 *
 * Scope (Task 2-c):
 *   - validateCountScope pure-function behaviour: 'full' returns [], bogus
 *     scope throws, category/abc/group/ad_hoc each have their own validation
 *     rules (missing-list / inactive-id / invalid-class paths).
 *   - setupWarehouseCounts actually filters the product set by the scope:
 *       * category → only products in the chosen categories
 *       * ad_hoc   → exactly the requested products
 *       * zero_only → only products with qty ≈ 0 (or no warehouse_stock row)
 *   - describeScope returns a human-readable string per scope (used by the
 *     show page + audit timeline).
 *
 * The 'abc' scope's setup behaviour is NOT tested here — it joins the
 * mv_product_abc_classification materialized view, which is empty unless
 * the AbcClassificationService::refresh() has been run. The previous
 * Task 2-a entry already covered negative_only; we cover zero_only here
 * (its sibling dead-stock scope).
 *
 * The service is resolved from the container in setUp(). Every test runs
 * inside DatabaseTransactions and rolls back on tearDown.
 */
class CycleCountTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected StockTakeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->service = app(StockTakeService::class);
    }

    /**
     * Helper: create a session for a single warehouse + branch.
     */
    private function makeSession(int $branchId, int $warehouseId, array $overrides = []): int
    {
        $session = $this->service->createSession(array_merge([
            'branch_id'     => $branchId,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$warehouseId],
            'created_by'    => auth()->id(),
        ], $overrides));

        return $session->id;
    }

    /**
     * Helper: insert a product_category row + return its id.
     */
    private function insertCategory(string $name): int
    {
        return DB::table('product_categories')->insertGetId([
            'category_name' => $name,
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    /**
     * Helper: insert a product_group row + return its id.
     */
    private function insertGroup(string $name): int
    {
        return DB::table('product_groups')->insertGetId([
            'group_name' => $name,
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Helper: insert a product tied to a category (and optionally a group).
     */
    private function insertProductInCategory(?int $categoryId = null, ?int $groupId = null): int
    {
        $code = 'PROD-' . substr(uniqid(), -6);
        return DB::table('products')->insertGetId([
            'product_code' => $code,
            'product_name' => 'Test Product ' . $code,
            'category_id'  => $categoryId,
            'group_id'     => $groupId,
            'unit'         => 'Pcs',
            'is_active'    => true,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    // ========================================================================
    // validateCountScope — pure-function behaviour (no setup needed).
    // ========================================================================

    public function test_validate_count_scope_full_returns_empty_payload(): void
    {
        $payload = $this->service->validateCountScope('full', null);

        $this->assertSame([], $payload);
    }

    public function test_validate_count_scope_invalid_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported count_scope');
        $this->service->validateCountScope('bogus', null);
    }

    public function test_validate_count_scope_category_requires_category_ids(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one category_id');
        $this->service->validateCountScope('category', []);
    }

    public function test_validate_count_scope_category_validates_active_categories(): void
    {
        // Pass a category_id that doesn't exist — the service must reject it
        // rather than silently dropping it.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown/inactive category_ids');
        $this->service->validateCountScope('category', ['category_ids' => [99999999]]);
    }

    public function test_validate_count_scope_abc_requires_classes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one abc_class');
        $this->service->validateCountScope('abc', []);
    }

    public function test_validate_count_scope_abc_rejects_invalid_classes(): void
    {
        // 'D' is not in {A, B, C} — must be rejected before the empty-list
        // check (the service checks the subset first, then non-empty).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a subset of A, B, C');
        $this->service->validateCountScope('abc', ['abc_classes' => ['A', 'D']]);
    }

    public function test_validate_count_scope_ad_hoc_requires_product_ids(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires at least one product_id');
        $this->service->validateCountScope('ad_hoc', []);
    }

    public function test_validate_count_scope_ad_hoc_validates_active_products(): void
    {
        // Pass a product_id that doesn't exist — the service must reject it.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown/inactive/deleted product_ids');
        $this->service->validateCountScope('ad_hoc', ['product_ids' => [99999999]]);
    }

    // ========================================================================
    // setupWarehouseCounts — scope filters actually narrow the product set.
    // ========================================================================

    public function test_setup_with_category_scope_only_loads_products_in_those_categories(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $catX = $this->insertCategory('Cycle Cat X ' . uniqid());
        $catY = $this->insertCategory('Cycle Cat Y ' . uniqid());

        // 2 products in category X, 1 product in category Y.
        $pidX1 = $this->insertProductInCategory($catX);
        $pidX2 = $this->insertProductInCategory($catX);
        $pidY1 = $this->insertProductInCategory($catY);
        $this->insertWarehouseStock($wid, $pidX1, 10);
        $this->insertWarehouseStock($wid, $pidX2, 10);
        $this->insertWarehouseStock($wid, $pidY1, 10);

        $sid = $this->makeSession($branch->id, $wid, [
            'count_scope'         => 'category',
            'count_scope_payload' => ['category_ids' => [$catX]],
        ]);

        $created = $this->service->setupWarehouseCounts($sid, $wid);

        // Only the 2 products in category X are loaded.
        $this->assertSame(2, $created);
        $loadedPids = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sid)
            ->where('warehouse_id', $wid)
            ->pluck('product_id')
            ->all();
        sort($loadedPids);
        $expected = [$pidX1, $pidX2];
        sort($expected);
        $this->assertSame($expected, $loadedPids);
    }

    public function test_setup_with_ad_hoc_scope_loads_exactly_the_specified_products(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $pid1 = $this->insertProduct();
        $pid2 = $this->insertProduct();
        $pid3 = $this->insertProduct();
        // Even with warehouse_stock rows for all 3, ad_hoc loads only p1 + p3.
        $this->insertWarehouseStock($wid, $pid1, 10);
        $this->insertWarehouseStock($wid, $pid2, 10);
        $this->insertWarehouseStock($wid, $pid3, 10);

        $sid = $this->makeSession($branch->id, $wid, [
            'count_scope'         => 'ad_hoc',
            'count_scope_payload' => ['product_ids' => [$pid1, $pid3]],
        ]);

        $created = $this->service->setupWarehouseCounts($sid, $wid);

        $this->assertSame(2, $created);
        $loadedPids = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sid)
            ->where('warehouse_id', $wid)
            ->pluck('product_id')
            ->all();
        sort($loadedPids);
        $expected = [$pid1, $pid3];
        sort($expected);
        $this->assertSame($expected, $loadedPids);
    }

    /**
     * zero_only scope filters products with ABS(COALESCE(ws.qty, 0)) < 0.0001
     * — dead stock: either qty=0 in warehouse_stock OR no warehouse_stock
     * row at all. The buildScopedProductsQuery LEFT JOINs warehouse_stock,
     * so products with no row appear with system_qty=0.
     */
    public function test_setup_with_zero_only_scope_loads_only_zero_stock_products(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        // 3 products: one with qty=5 (excluded), one with qty=0 (included),
        // one with NO warehouse_stock row (included — COALESCE makes it 0).
        $pidPositive = $this->insertProduct();
        $pidZero = $this->insertProduct();
        $pidNoRow = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pidPositive, 5);
        $this->insertWarehouseStock($wid, $pidZero, 0);

        $sid = $this->makeSession($branch->id, $wid, [
            'count_scope' => 'zero_only',
        ]);

        $created = $this->service->setupWarehouseCounts($sid, $wid);

        // 2 zero-stock products loaded (the qty=0 row + the no-row product).
        $this->assertSame(2, $created);
        $loadedPids = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sid)
            ->where('warehouse_id', $wid)
            ->pluck('product_id')
            ->all();
        sort($loadedPids);
        $expected = [$pidZero, $pidNoRow];
        sort($expected);
        $this->assertSame($expected, $loadedPids);

        // The no-row product's system_qty snapshot is 0 (COALESCE'd).
        $noRowItem = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sid)
            ->where('product_id', $pidNoRow)
            ->first();
        $this->assertEqualsWithDelta(0, (float) $noRowItem->system_qty, 0.0001);
    }

    // ========================================================================
    // describeScope — human-readable string per scope.
    // ========================================================================

    /**
     * For each scope, create a real session + call describeScope($session).
     * The describeScope method reads $session->count_scope and
     * $session->count_scope_payload (cast to array by the model), so we use
     * the actual StockTakeSession row returned by createSession.
     *
     * The 'group' scope requires a real product_groups row (validated by
     * validateCountScope at create time). 'abc' just validates the classes
     * are subset of A,B,C — no MV lookup at create time. 'negative_only' /
     * 'zero_only' / 'full' carry no payload.
     */
    public function test_describe_scope_returns_human_readable_string_for_each_scope(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        // ---- full ----
        $sFull = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'count_scope'   => 'full',
            'created_by'    => auth()->id(),
        ]);
        $this->assertStringContainsString('Full', $this->service->describeScope($sFull));

        // ---- category ----
        $catId = $this->insertCategory('Describe Cat ' . uniqid());
        $sCat = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'count_scope'         => 'category',
            'count_scope_payload' => ['category_ids' => [$catId]],
            'created_by'    => auth()->id(),
        ]);
        $this->assertStringContainsString('Category', $this->service->describeScope($sCat));

        // ---- abc ----
        $sAbc = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'count_scope'         => 'abc',
            'count_scope_payload' => ['abc_classes' => ['A', 'B']],
            'created_by'    => auth()->id(),
        ]);
        $this->assertStringContainsString('ABC', $this->service->describeScope($sAbc));

        // ---- group ----
        $groupId = $this->insertGroup('Describe Group ' . uniqid());
        $sGroup = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'count_scope'         => 'group',
            'count_scope_payload' => ['group_ids' => [$groupId]],
            'created_by'    => auth()->id(),
        ]);
        // describeScope returns 'Product group: ...' — 'group' lowercase.
        $this->assertStringContainsString('group', $this->service->describeScope($sGroup));

        // ---- ad_hoc ----
        $pid = $this->insertProduct();
        $sAdHoc = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'count_scope'         => 'ad_hoc',
            'count_scope_payload' => ['product_ids' => [$pid]],
            'created_by'    => auth()->id(),
        ]);
        $this->assertStringContainsString('Ad-hoc', $this->service->describeScope($sAdHoc));

        // ---- negative_only ----
        $sNeg = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'count_scope'   => 'negative_only',
            'created_by'    => auth()->id(),
        ]);
        $this->assertStringContainsString('Negative', $this->service->describeScope($sNeg));

        // ---- zero_only ----
        $sZero = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'count_scope'   => 'zero_only',
            'created_by'    => auth()->id(),
        ]);
        $this->assertStringContainsString('Zero', $this->service->describeScope($sZero));
    }
}

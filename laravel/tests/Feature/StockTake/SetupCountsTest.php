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
 * Phase 12 — happy-path feature tests for StockTakeService::setupWarehouseCounts().
 *
 * Covers:
 *   - Loading all active products for a warehouse into stock_take_items with
 *     the system_qty snapshot + Phase 9 system_rate (setup-time avg cost).
 *   - Skipping soft-deleted products (deleted_at IS NULL filter).
 *   - Skipping inactive products (is_active=true filter).
 *   - Audit-log row (action='setup', stock_take_warehouse_id set, payload
 *     contains products_loaded count).
 *   - Idempotency: re-running setup on the same warehouse does NOT produce
 *     duplicate items (the service deletes existing items first).
 *   - Warehouse-not-in-session guard throws RuntimeException.
 *   - negative_only count_scope only loads products with negative on-hand qty
 *     (requires disabling the ws_qty_nonnegative trigger to seed a negative
 *     row — the trigger + CHECK constraint otherwise forbid it).
 *
 * NOTES on divergences from the Phase 12 task brief:
 *   - The brief said setup writes physical_qty=0; the service actually
 *     initializes physical_qty=system_qty (a "no variance until user enters"
 *     default). We assert the actual behaviour.
 *   - The brief said the setup audit payload contains lines_created=N; the
 *     service actually uses products_loaded=N. We assert the actual key.
 *   - The brief said "verify which by reading the service" for the idempotency
 *     mechanism — the service deletes existing items first, so re-setup
 *     produces exactly the same row count (no duplicates, no exception).
 */
class SetupCountsTest extends TestCase
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

    public function test_setup_loads_all_active_products_into_items(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $pid1 = $this->insertProduct();
        $pid2 = $this->insertProduct();
        $pid3 = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid1, 10);
        $this->insertWarehouseStock($wid, $pid2, 25);
        $this->insertWarehouseStock($wid, $pid3, 7);

        $sid = $this->makeSession($branch->id, $wid);

        $created = $this->service->setupWarehouseCounts($sid, $wid);

        $this->assertSame(3, $created);
        $items = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sid)
            ->where('warehouse_id', $wid)
            ->get();
        $this->assertCount(3, $items);

        // Per-row: system_qty matches warehouse_stock.qty, physical_qty
        // initialized to system_qty (no variance until user enters), rate +
        // system_rate = warehouse_stock.avg_cost (10.00 from the helper),
        // is_applied=false, branch_id denormalized from the session.
        $byProduct = $items->keyBy('product_id');
        foreach ([[$pid1, 10], [$pid2, 25], [$pid3, 7]] as [$pid, $qty]) {
            $row = $byProduct->get($pid);
            $this->assertNotNull($row, "Missing item for product {$pid}");
            $this->assertEqualsWithDelta($qty, (float) $row->system_qty, 0.0001);
            $this->assertEqualsWithDelta($qty, (float) $row->physical_qty, 0.0001);
            $this->assertFalse((bool) $row->is_applied);
            $this->assertSame($branch->id, (int) $row->branch_id);
            $this->assertEqualsWithDelta(10.00, (float) $row->system_rate, 0.0001);
            $this->assertEqualsWithDelta(10.00, (float) $row->rate, 0.0001);
        }
    }

    public function test_setup_skips_soft_deleted_products(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $pid1 = $this->insertProduct();
        $pid2 = $this->insertProduct();
        $pid3 = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid1, 10);
        $this->insertWarehouseStock($wid, $pid2, 10);
        $this->insertWarehouseStock($wid, $pid3, 10);

        // Soft-delete pid3.
        DB::table('products')->where('id', $pid3)->update(['deleted_at' => now()]);

        $sid = $this->makeSession($branch->id, $wid);
        $created = $this->service->setupWarehouseCounts($sid, $wid);

        $this->assertSame(2, $created);
        $this->assertSame(2, DB::table('stock_take_items')->where('stock_take_session_id', $sid)->count());
    }

    public function test_setup_skips_inactive_products(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $pid1 = $this->insertProduct();
        $pid2 = $this->insertProduct();
        $pid3 = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid1, 10);
        $this->insertWarehouseStock($wid, $pid2, 10);
        $this->insertWarehouseStock($wid, $pid3, 10);

        // Deactivate pid3.
        DB::table('products')->where('id', $pid3)->update(['is_active' => false]);

        $sid = $this->makeSession($branch->id, $wid);
        $created = $this->service->setupWarehouseCounts($sid, $wid);

        $this->assertSame(2, $created);
        $this->assertSame(2, DB::table('stock_take_items')->where('stock_take_session_id', $sid)->count());
    }

    public function test_setup_writes_audit_log_with_action_setup(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $pid1 = $this->insertProduct();
        $pid2 = $this->insertProduct();
        $pid3 = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid1, 10);
        $this->insertWarehouseStock($wid, $pid2, 10);
        $this->insertWarehouseStock($wid, $pid3, 10);

        $sid = $this->makeSession($branch->id, $wid);
        $this->service->setupWarehouseCounts($sid, $wid);

        $auditRow = DB::table('stock_take_audit_log')
            ->where('stock_take_session_id', $sid)
            ->where('action', 'setup')
            ->first();

        $this->assertNotNull($auditRow);
        // The audit logger stores the warehouse_id in the stock_take_warehouse_id
        // column (FK references warehouses.id — the logger's parameter name is
        // a legacy misnomer but the column is correctly typed).
        $this->assertSame($wid, (int) $auditRow->stock_take_warehouse_id);

        $payload = json_decode($auditRow->payload, true);
        $this->assertSame(3, $payload['products_loaded']);
        $this->assertSame($wid, (int) $payload['warehouse_id']);
    }

    public function test_setup_is_idempotent_within_session(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $pid1 = $this->insertProduct();
        $pid2 = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid1, 10);
        $this->insertWarehouseStock($wid, $pid2, 10);

        $sid = $this->makeSession($branch->id, $wid);

        $first = $this->service->setupWarehouseCounts($sid, $wid);
        $second = $this->service->setupWarehouseCounts($sid, $wid);

        $this->assertSame(2, $first);
        $this->assertSame(2, $second);

        // No duplicate items — the service deletes existing items first.
        $count = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sid)
            ->where('warehouse_id', $wid)
            ->count();
        $this->assertSame(2, $count);

        // Two setup audit rows (one per call) — idempotency at the data layer
        // does not suppress the audit trail.
        $setupAuditCount = DB::table('stock_take_audit_log')
            ->where('stock_take_session_id', $sid)
            ->where('action', 'setup')
            ->count();
        $this->assertSame(2, $setupAuditCount);
    }

    public function test_setup_rejects_warehouse_not_in_session(): void
    {
        $branch = Branch::factory()->create();
        $widInSession = $this->insertWarehouse($branch->id);
        $widNotInSession = $this->insertWarehouse($branch->id);

        $sid = $this->makeSession($branch->id, $widInSession);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is not part of session');
        $this->service->setupWarehouseCounts($sid, $widNotInSession);
    }

    /**
     * negative_only scope filters products with qty < -0.0001. The warehouse_stock
     * CHECK + trigger normally forbid such rows, so we disable the trigger
     * transactionally to seed a negative row, then re-enable. ALTER TABLE
     * DISABLE TRIGGER is transactional in PG, so the rollback restores it.
     */
    public function test_setup_with_negative_only_scope_only_loads_negative_stock_products(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $pidPositive = $this->insertProduct();
        $pidNegative = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pidPositive, 5);

        // Disable the negative-stock trigger + CHECK enforcement so we can
        // seed a negative-qty row (the trigger would otherwise RAISE EXCEPTION).
        DB::statement('ALTER TABLE warehouse_stock DISABLE TRIGGER trg_warehouse_stock_no_negative_insert');
        try {
            DB::table('warehouse_stock')->insert([
                'warehouse_id' => $wid,
                'product_id'   => $pidNegative,
                'qty'          => -3,
                'avg_cost'     => 10.00,
                'total_qty'    => -3,
                'total_value'  => -30,
                'updated_at'   => now(),
            ]);
        } finally {
            DB::statement('ALTER TABLE warehouse_stock ENABLE TRIGGER trg_warehouse_stock_no_negative_insert');
        }

        $sid = $this->makeSession($branch->id, $wid, [
            'count_scope' => 'negative_only',
        ]);

        $created = $this->service->setupWarehouseCounts($sid, $wid);

        // Only the negative-qty product is loaded.
        $this->assertSame(1, $created);
        $item = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sid)
            ->where('warehouse_id', $wid)
            ->first();
        $this->assertNotNull($item);
        $this->assertSame($pidNegative, (int) $item->product_id);
        $this->assertEqualsWithDelta(-3, (float) $item->system_qty, 0.0001);
    }
}

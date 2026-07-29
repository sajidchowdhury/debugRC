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
 * Phase 12 — happy-path feature tests for StockTakeService::saveCounts().
 *
 * Covers:
 *   - Saving physical_qty per product_id and marking the warehouse completed.
 *   - The GENERATED difference column = physical_qty - system_qty (computed
 *     by PostgreSQL, no app logic).
 *   - Audit-log row (action='save_count', payload contains lines_saved +
 *     warehouse_id).
 *   - Per-line reasons: saveCounts() does NOT take reasons (the $counts map
 *     is product_id => physical_qty only). The bulkUpsertCounts() method is
 *     the reason-aware path — it accepts [{code, qty, reason?}] and persists
 *     the reason on the item row. We use bulkUpsertCounts for the reason test.
 *   - The service runs inside DB::transaction (we can't easily test two-PC
 *     concurrency here, but we assert the session-row guard: calling
 *     saveCounts on a non-existent session throws RuntimeException).
 *   - Zero-line save is a no-op on items but STILL marks the warehouse
 *     completed (the service unconditionally writes status='completed').
 *
 * The service is resolved from the container in setUp() so all constructor
 * dependencies wire up automatically. DatabaseTransactions rolls back every
 * test, leaving the rcerp_test DB pristine.
 */
class SaveCountsTest extends TestCase
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
     * Helper: create a session + setup counts for a single warehouse with N
     * products (qty=10 each). Returns [sessionId, warehouseId, productIds].
     */
    private function makeSessionWithSetup(int $branchId, int $warehouseId, int $productCount): array
    {
        $productIds = [];
        for ($i = 0; $i < $productCount; $i++) {
            $pid = $this->insertProduct();
            $this->insertWarehouseStock($warehouseId, $pid, 10);
            $productIds[] = $pid;
        }

        $session = $this->service->createSession([
            'branch_id'     => $branchId,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$warehouseId],
            'created_by'    => auth()->id(),
        ]);
        $this->service->setupWarehouseCounts($session->id, $warehouseId);

        return [$session->id, $warehouseId, $productIds];
    }

    public function test_save_counts_updates_physical_qty_and_marks_warehouse_completed(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pids] = $this->makeSessionWithSetup($branch->id, $wid, 3);

        $updated = $this->service->saveCounts($sid, $wid, [
            $pids[0] => 10,
            $pids[1] => 5,
            $pids[2] => 8,
        ]);

        $this->assertSame(3, $updated);

        $items = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sid)
            ->where('warehouse_id', $wid)
            ->get()
            ->keyBy('product_id');

        $this->assertEqualsWithDelta(10, (float) $items->get($pids[0])->physical_qty, 0.0001);
        $this->assertEqualsWithDelta(5, (float) $items->get($pids[1])->physical_qty, 0.0001);
        $this->assertEqualsWithDelta(8, (float) $items->get($pids[2])->physical_qty, 0.0001);

        $this->assertDatabaseHas('stock_take_warehouses', [
            'stock_take_session_id' => $sid,
            'warehouse_id'          => $wid,
            'status'                => 'completed',
        ]);
    }

    public function test_save_counts_generates_difference_column(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pids] = $this->makeSessionWithSetup($branch->id, $wid, 1);

        // system_qty=10 (from the helper's qty=10), save physical_qty=7 → diff = -3.
        $this->service->saveCounts($sid, $wid, [$pids[0] => 7]);

        $item = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sid)
            ->where('warehouse_id', $wid)
            ->where('product_id', $pids[0])
            ->first();

        // The difference column is GENERATED ALWAYS AS (physical_qty - system_qty) STORED.
        $this->assertEqualsWithDelta(-3, (float) $item->difference, 0.0001);
    }

    public function test_save_counts_writes_audit_log_with_action_save_count(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pids] = $this->makeSessionWithSetup($branch->id, $wid, 3);

        $this->service->saveCounts($sid, $wid, [
            $pids[0] => 10,
            $pids[1] => 5,
            $pids[2] => 8,
        ]);

        $auditRow = DB::table('stock_take_audit_log')
            ->where('stock_take_session_id', $sid)
            ->where('action', 'save_count')
            ->first();

        $this->assertNotNull($auditRow);
        $this->assertSame($wid, (int) $auditRow->stock_take_warehouse_id);

        $payload = json_decode($auditRow->payload, true);
        $this->assertSame(3, $payload['lines_saved']);
        $this->assertSame($wid, (int) $payload['warehouse_id']);
    }

    /**
     * saveCounts() does NOT support per-line reasons (the $counts map is
     * product_id => physical_qty only). The reason-aware path is
     * bulkUpsertCounts(), which accepts [{code, qty, reason?}] and persists
     * the reason on the item row. We exercise that here.
     */
    public function test_save_counts_with_reason_attaches_to_item(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        // Insert products with KNOWN codes so bulkUpsertCounts can resolve them.
        $code1 = 'BULK-' . substr(uniqid(), -6);
        $code2 = 'BULK-' . substr(uniqid(), -6);
        $pid1 = $this->insertProduct($code1);
        $pid2 = $this->insertProduct($code2);
        $this->insertWarehouseStock($wid, $pid1, 10);
        $this->insertWarehouseStock($wid, $pid2, 10);

        $session = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'created_by'    => auth()->id(),
        ]);
        $this->service->setupWarehouseCounts($session->id, $wid);

        $result = $this->service->bulkUpsertCounts($session->id, $wid, [
            ['code' => $code1, 'qty' => 12, 'reason' => 'Found extra stock'],
            ['code' => $code2, 'qty' => 8,  'reason' => 'Damaged in storage'],
        ]);

        $this->assertSame(2, $result['updated']);
        $this->assertSame(0, $result['skipped']);

        $item1 = DB::table('stock_take_items')->where('product_id', $pid1)->first();
        $item2 = DB::table('stock_take_items')->where('product_id', $pid2)->first();
        $this->assertSame('Found extra stock', $item1->reason);
        $this->assertSame('Damaged in storage', $item2->reason);
    }

    /**
     * We can't easily test two-PC concurrency here, but we can verify the
     * session-row guard: calling saveCounts on a non-existent session throws
     * RuntimeException with a clear "Session {N} not found." message. This is
     * the same lockForUpdate-based guard that serializes concurrent counters.
     */
    public function test_save_counts_locks_session_row(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        // Use a session id that does not exist (high number to avoid collision).
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');
        $this->service->saveCounts(99999999, $wid, [1 => 5]);
    }

    public function test_save_counts_zero_lines_is_a_noop(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        [$sid, $wid, $pids] = $this->makeSessionWithSetup($branch->id, $wid, 2);

        // Capture the pre-save physical_qty (= system_qty from setup).
        $preSave = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sid)
            ->where('warehouse_id', $wid)
            ->pluck('physical_qty', 'product_id')
            ->all();

        $updated = $this->service->saveCounts($sid, $wid, []);

        // No items updated.
        $this->assertSame(0, $updated);

        // The physical_qty values are unchanged.
        $postSave = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sid)
            ->where('warehouse_id', $wid)
            ->pluck('physical_qty', 'product_id')
            ->all();
        $this->assertSame($preSave, $postSave);

        // The service ALWAYS marks the warehouse completed — even on empty counts.
        $this->assertDatabaseHas('stock_take_warehouses', [
            'stock_take_session_id' => $sid,
            'warehouse_id'          => $wid,
            'status'                => 'completed',
        ]);
    }
}

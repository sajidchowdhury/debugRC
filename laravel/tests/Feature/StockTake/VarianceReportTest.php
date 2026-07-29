<?php

namespace Tests\Feature\StockTake;

use App\Models\Branch;
use App\Services\Stock\StockTakePolicyService;
use App\Services\Stock\StockTakeService;
use App\Services\Stock\StockTakeVarianceReport;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\TestCase;

/**
 * Phase 12 — special-features feature tests for StockTakeVarianceReport.
 *
 * Scope (Task 2-c):
 *   - getVarianceLines filters: nonzero difference (default WHERE), by
 *     session_id, by branch_id, by date range.
 *   - summarize: totals from a synthetic row set (gain + loss + mixed),
 *     plus the empty-array edge case (all-zero totals).
 *   - getSessionsList: recent sessions with branch_name, ordered by date
 *     desc.
 *   - exportCsv: StreamedResponse + text/csv + the Stock_Take_Variance_
 *     attachment filename (UTF-8 BOM is written into the stream body — we
 *     don't read the body, just assert the response type + headers per the
 *     task brief).
 *   - Phase 9 costing columns: post a session where post_rate drifted from
 *     system_rate (avg cost changed between setup and post); assert each
 *     variance row has system_rate, post_rate, revaluation_amount set.
 *   - GL drill-down: each variance row from a posted session has
 *     journal_line_id set (the per-line back-link to the journal_lines row
 *     that recorded its GL impact).
 *
 * The service is resolved from the container in setUp(). Every test runs
 * inside DatabaseTransactions and rolls back on tearDown.
 */
class VarianceReportTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies;

    protected StockTakeService $service;
    protected StockTakeVarianceReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsRole('admin');
        $this->service = app(StockTakeService::class);
        $this->report = app(StockTakeVarianceReport::class);

        // The policy service caches all policies in memory. DatabaseTransactions
        // rolls back DB writes but NOT the cache — flush here so every test
        // starts with a fresh read of the (rolled-back) seeded defaults.
        // (Same defensive pattern as PostSessionTest::setUp.)
        app(StockTakePolicyService::class)->flushCache();
    }

    /**
     * Build a session + setup + save + post chain for one warehouse with N
     * products. $counts is [productId => physicalQty]. system_qty defaults
     * to 10 per product (from the helper). Returns the session id.
     */
    private function postSessionWithCounts(
        int $branchId,
        int $warehouseId,
        array $counts,
        array $sessionOverrides = [],
    ): int {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        foreach ($counts as $pid => $_) {
            // Ensure each product has warehouse_stock at qty=10 (the helper's
            // default) so setupWarehouseCounts snapshots system_qty=10.
            $existing = DB::table('warehouse_stock')
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $pid)
                ->exists();
            if (!$existing) {
                $this->insertWarehouseStock($warehouseId, $pid, 10);
            }
        }

        $session = $this->service->createSession(array_merge([
            'branch_id'     => $branchId,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$warehouseId],
            'created_by'    => $admin->id,
        ], $sessionOverrides));
        $this->service->setupWarehouseCounts($session->id, $warehouseId);
        $this->service->saveCounts($session->id, $warehouseId, $counts);
        $this->service->postSession($session->id, $admin->id);

        return $session->id;
    }

    public function test_get_variance_lines_returns_only_items_with_nonzero_difference(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        // 3 products: one gain (+2), one loss (-2), one no-variance (0).
        $pidGain = $this->insertProduct();
        $pidLoss = $this->insertProduct();
        $pidNone = $this->insertProduct();

        $sid = $this->postSessionWithCounts($branch->id, $wid, [
            $pidGain => 12, // +2 variance
            $pidLoss => 8,  // -2 variance
            $pidNone => 10, // 0 variance
        ]);

        $lines = $this->report->getVarianceLines([]);

        // Only the 2 variance rows are returned — the no-variance item is
        // excluded by the WHERE sti.difference <> 0 clause.
        $this->assertCount(2, $lines);

        $productIds = array_map(fn($r) => (int) $r->product_id, $lines);
        $this->assertContains($pidGain, $productIds);
        $this->assertContains($pidLoss, $productIds);
        $this->assertNotContains($pidNone, $productIds);

        // Each row is from the just-posted session.
        foreach ($lines as $row) {
            $this->assertSame($sid, (int) $row->session_id);
        }
    }

    public function test_get_variance_lines_filters_by_session_id(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $pid1 = $this->insertProduct();
        $pid2 = $this->insertProduct();

        $sidA = $this->postSessionWithCounts($branch->id, $wid, [$pid1 => 12]);
        $sidB = $this->postSessionWithCounts($branch->id, $wid, [$pid2 => 9]);

        // Filter to session A only.
        $lines = $this->report->getVarianceLines(['session_id' => $sidA]);

        $this->assertNotEmpty($lines);
        foreach ($lines as $row) {
            $this->assertSame($sidA, (int) $row->session_id);
        }
        // The session B variance row is excluded.
        $productIds = array_map(fn($r) => (int) $r->product_id, $lines);
        $this->assertContains($pid1, $productIds);
        $this->assertNotContains($pid2, $productIds);
    }

    public function test_get_variance_lines_filters_by_branch_id(): void
    {
        $branch1 = Branch::factory()->create();
        $branch2 = Branch::factory()->create();
        $wid1 = $this->insertWarehouse($branch1->id);
        $wid2 = $this->insertWarehouse($branch2->id);

        $pid1 = $this->insertProduct();
        $pid2 = $this->insertProduct();

        $this->postSessionWithCounts($branch1->id, $wid1, [$pid1 => 12]);
        $this->postSessionWithCounts($branch2->id, $wid2, [$pid2 => 8]);

        // Filter to branch 1 only.
        $lines = $this->report->getVarianceLines(['branch_id' => $branch1->id]);

        $this->assertNotEmpty($lines);
        foreach ($lines as $row) {
            $this->assertSame($branch1->id, (int) $row->branch_id);
        }
        // Branch 2's variance row is excluded.
        $productIds = array_map(fn($r) => (int) $r->product_id, $lines);
        $this->assertContains($pid1, $productIds);
        $this->assertNotContains($pid2, $productIds);
    }

    public function test_get_variance_lines_filters_by_date_range(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $pidIn = $this->insertProduct();
        $pidOut = $this->insertProduct();

        // Session 1: session_date in January 2025 (in range).
        $this->postSessionWithCounts($branch->id, $wid, [$pidIn => 12], [
            'session_date' => '2025-01-15',
        ]);
        // Session 2: session_date in December 2024 (out of range).
        $this->postSessionWithCounts($branch->id, $wid, [$pidOut => 8], [
            'session_date' => '2024-12-15',
        ]);

        // Filter to January 2025 only.
        $lines = $this->report->getVarianceLines([
            'from' => '2025-01-01',
            'to'   => '2025-01-31',
        ]);

        $this->assertNotEmpty($lines);
        $productIds = array_map(fn($r) => (int) $r->product_id, $lines);
        $this->assertContains($pidIn, $productIds);
        $this->assertNotContains($pidOut, $productIds);
    }

    /**
     * Build a synthetic row with the exact properties summarize() reads:
     * variance_qty, value_diff, revaluation_amount. The other columns
     * (session_code, etc.) are not read by summarize() — omitted.
     */
    private function makeVarianceRow(float $qty, float $valueDiff, float $reval = 0.0): \stdClass
    {
        $r = new \stdClass();
        $r->variance_qty = $qty;
        $r->value_diff = $valueDiff;
        $r->revaluation_amount = $reval;
        return $r;
    }

    public function test_summarize_computes_totals_correctly(): void
    {
        // 3 synthetic rows: gain +3 (val 30), loss -2 (val -20), gain +1 (val 10).
        $rows = [
            $this->makeVarianceRow(3, 30),
            $this->makeVarianceRow(-2, -20),
            $this->makeVarianceRow(1, 10),
        ];

        $totals = $this->report->summarize($rows);

        // total_items = 3 (count of rows).
        $this->assertSame(3, $totals['total_items']);
        // total_variance = 3 + (-2) + 1 = 2.
        $this->assertEqualsWithDelta(2, $totals['total_variance'], 0.0001);
        // total_value_diff = 30 + (-20) + 10 = 20.
        $this->assertEqualsWithDelta(20, $totals['total_value_diff'], 0.01);
        // gain_lines = 2 (qty > 0 for the +3 and +1 rows).
        $this->assertSame(2, $totals['gain_lines']);
        // loss_lines = 1 (qty < 0 for the -2 row).
        $this->assertSame(1, $totals['loss_lines']);
        // gain_value = 30 + 10 = 40 (sum of value_diff where qty > 0).
        $this->assertEqualsWithDelta(40, $totals['gain_value'], 0.01);
        // loss_value = abs(-20) = 20 (sum of |value_diff| where qty < 0).
        $this->assertEqualsWithDelta(20, $totals['loss_value'], 0.01);
    }

    public function test_summarize_handles_empty_array(): void
    {
        $totals = $this->report->summarize([]);

        $this->assertSame(0, $totals['total_items']);
        $this->assertEqualsWithDelta(0, $totals['total_variance'], 0.0001);
        $this->assertEqualsWithDelta(0, $totals['total_value_diff'], 0.01);
        $this->assertSame(0, $totals['gain_lines']);
        $this->assertSame(0, $totals['loss_lines']);
        $this->assertEqualsWithDelta(0, $totals['gain_value'], 0.01);
        $this->assertEqualsWithDelta(0, $totals['loss_value'], 0.01);
        // Phase 9 revaluation totals also zero.
        $this->assertEqualsWithDelta(0, $totals['total_revaluation'], 0.000001);
        $this->assertSame(0, $totals['reval_lines']);
    }

    public function test_get_sessions_list_returns_recent_sessions_with_branch_names(): void
    {
        $branch1 = Branch::factory()->create();
        $branch2 = Branch::factory()->create();
        $wid1 = $this->insertWarehouse($branch1->id);
        $wid2 = $this->insertWarehouse($branch2->id);

        // Two sessions with different dates (so the order-by-date is
        // observable). Session B's date is LATER → it must come first.
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $sA = $this->service->createSession([
            'branch_id'     => $branch1->id,
            'session_date'  => '2025-01-10',
            'warehouse_ids' => [$wid1],
            'created_by'    => $admin->id,
        ]);
        $sB = $this->service->createSession([
            'branch_id'     => $branch2->id,
            'session_date'  => '2025-02-10',
            'warehouse_ids' => [$wid2],
            'created_by'    => $admin->id,
        ]);

        $list = $this->report->getSessionsList();

        // Both sessions returned.
        $ids = array_map(fn($r) => (int) $r->id, $list);
        $this->assertContains($sA->id, $ids);
        $this->assertContains($sB->id, $ids);

        // Each row carries a non-empty branch_name (the JOIN to branches).
        foreach ($list as $row) {
            $this->assertNotEmpty($row->branch_name);
        }

        // Ordered by session_date DESC: sB (2025-02-10) precedes sA (2025-01-10).
        $idxA = array_search($sA->id, $ids, true);
        $idxB = array_search($sB->id, $ids, true);
        $this->assertNotFalse($idxA);
        $this->assertNotFalse($idxB);
        $this->assertLessThan($idxA, $idxB, 'Session B (later date) must come before Session A.');
    }

    public function test_export_csv_returns_streamed_response_with_utf8_bom(): void
    {
        // Use synthetic rows — the CSV exporter iterates them and writes to
        // the stream. We don't read the streamed body (per the task brief),
        // just assert the response type + headers.
        $rows = [
            $this->makeVarianceRow(3, 30),
        ];

        $response = $this->report->exportCsv($rows);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'attachment; filename="Stock_Take_Variance_',
            $response->headers->get('Content-Disposition')
        );
    }

    /**
     * Phase 9 costing columns: post a session where the avg cost drifted
     * between setup (system_rate=10) and post (post_rate=15). The drift
     * exceeds the revaluation_epsilon (default 0.01), so revaluation_amount
     * is non-zero. getVarianceLines must surface system_rate, post_rate,
     * revaluation_amount on every variance row.
     */
    public function test_variance_lines_include_phase9_costing_columns(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $pid = $this->insertProduct();
        $this->insertWarehouseStock($wid, $pid, 10); // system_qty=10, avg_cost=10.

        $session = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'created_by'    => $admin->id,
        ]);
        $this->service->setupWarehouseCounts($session->id, $wid);
        $this->service->saveCounts($session->id, $wid, [$pid => 12]); // +2 variance

        // Induce cost drift: change warehouse_stock.avg_cost from 10 to 15
        // BETWEEN setup and post. At post, getWarehouseAvgCost() returns 15,
        // so post_rate=15, system_rate=10 (the immutable snapshot), drift=5 >
        // epsilon → revaluation_amount = (15 - 10) * 12 = 60.
        DB::table('warehouse_stock')
            ->where('warehouse_id', $wid)
            ->where('product_id', $pid)
            ->update(['avg_cost' => 15.00, 'updated_at' => now()]);

        $this->service->postSession($session->id, $admin->id);

        $lines = $this->report->getVarianceLines([]);

        $this->assertNotEmpty($lines);
        foreach ($lines as $row) {
            // The three Phase 9 columns must be present (selected by the
            // report) and non-null after a post that captured them.
            $this->assertNotNull($row->system_rate);
            $this->assertNotNull($row->post_rate);
            $this->assertNotNull($row->revaluation_amount);

            // The drift was induced: system_rate=10, post_rate=15.
            $this->assertEqualsWithDelta(10, (float) $row->system_rate, 0.0001);
            $this->assertEqualsWithDelta(15, (float) $row->post_rate, 0.0001);
            // revaluation_amount = (15 - 10) * 12 = 60.
            $this->assertEqualsWithDelta(60, (float) $row->revaluation_amount, 0.01);
        }
    }

    public function test_variance_lines_include_journal_line_id_for_gl_drill_down(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        $pidGain = $this->insertProduct();
        $pidLoss = $this->insertProduct();

        $sid = $this->postSessionWithCounts($branch->id, $wid, [
            $pidGain => 12, // +2 variance
            $pidLoss => 8,  // -2 variance
        ]);

        $lines = $this->report->getVarianceLines([]);

        $this->assertNotEmpty($lines);
        // Every variance row from a posted session has journal_line_id set
        // (the per-line back-link to the journal_lines row that recorded
        // its GL impact — the Inventory-side line of its gain/loss bucket).
        foreach ($lines as $row) {
            $this->assertSame($sid, (int) $row->session_id);
            $this->assertNotNull($row->journal_line_id, "Variance row for product {$row->product_id} is missing journal_line_id.");
            $this->assertTrue((bool) $row->is_applied);
        }
    }
}

<?php

namespace Tests\Feature\StockTake;

use App\Models\Branch;
use App\Services\Stock\StockTakePolicyService;
use App\Services\Stock\StockTakeService;
use App\Services\Stock\StockTakeWeeklyReport;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsWarehouseDependencies;
use Tests\Helpers\ResolvesActiveFiscalYear;
use Tests\TestCase;

/**
 * Phase 12 — feature tests for StockTakeWeeklyReport (the Phase 6 weekly
 * control report).
 *
 * Covers:
 *   - getWeekly() with zero sessions in the range → zeroed totals + empty
 *     sessions + empty top_products.
 *   - getWeekly() aggregates posted sessions' variance values + counts.
 *   - getWeekly() excludes sessions whose session_date falls outside the
 *     requested range.
 *   - getWeekly() filters by branch_id when provided.
 *   - getWeekly() excludes cancelled + draft sessions (the underlying query
 *     filters sts.status IN ('posted','reversed','counting','submitted',
 *     'approved') — cancelled/draft are dropped by construction).
 *   - getTopVarianceProducts() returns a ranked list (top N by absolute
 *     value variance), ordered by |difference * rate| desc.
 *   - exportCsv() returns a StreamedResponse with Content-Type text/csv.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * DIVERGENCE NOTE on the return shape
 * ──────────────────────────────────────────────────────────────────────────
 * The task brief hypothesised the return shape as
 *   ['summary' => [...], 'sessions' => [...], 'top_variances' => [...]].
 * Reading the service: the actual shape is
 *   ['date_from' => ..., 'date_to' => ..., 'branch_id' => ...,
 *    'totals'    => [...], 'sessions' => [...], 'top_products' => [...]]
 * where `totals` (NOT `summary`) holds the aggregate counts + values and
 * `top_products` (NOT `top_variances`) holds the ranked products list.
 *
 * We assert the ACTUAL shape — the test would fail if we used the brief's
 * hypothesised keys.
 *
 * The `totals` keys are: sessions, posted, reversed, open, variance_lines,
 * gain_value, loss_value, net_value.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * Status filter
 * ──────────────────────────────────────────────────────────────────────────
 * getWeekly() includes sts.status IN ('posted','reversed','counting',
 * 'submitted','approved'). This means:
 *   - 'cancelled' sessions are EXCLUDED (good — they're abandoned).
 *   - 'draft' sessions are EXCLUDED (good — they haven't been counted yet).
 *   - 'posted' sessions are INCLUDED (the primary use case).
 *   - 'counting/submitted/approved/reversed' are INCLUDED so managers can
 *     see in-flight counts in the weekly control view (matches legacy).
 */
class WeeklyReportTest extends TestCase
{
    use BuildsRoleUsers, InsertsBranchDependencies, InsertsWarehouseDependencies, ResolvesActiveFiscalYear;

    protected StockTakeService $service;
    protected StockTakeWeeklyReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolveActiveFiscalYearId();
        $this->actingAsRole('admin');
        $this->service = app(StockTakeService::class);
        $this->report = app(StockTakeWeeklyReport::class);

        // Flush the policy cache so each test starts from a fresh read of
        // the rolled-back DB defaults.
        app(StockTakePolicyService::class)->flushCache();
    }

    /**
     * Build a posted session with a single variance product. Returns the
     * session id.
     *
     * @param float $systemQty    warehouse_stock.qty at setup (= system_qty snapshot)
     * @param float $physicalQty  counted qty (drives the variance)
     * @param string $sessionDate Y-m-D — defaults to today
     */
    private function makePostedSession(
        int $branchId,
        int $warehouseId,
        float $systemQty,
        float $physicalQty,
        ?string $sessionDate = null
    ): int {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $pid = $this->insertProduct();
        $this->insertWarehouseStock($warehouseId, $pid, $systemQty);

        $session = $this->service->createSession([
            'branch_id'     => $branchId,
            'session_date'  => $sessionDate ?? now()->format('Y-m-d'),
            'warehouse_ids' => [$warehouseId],
            'created_by'    => $admin->id,
        ]);
        $this->service->setupWarehouseCounts($session->id, $warehouseId);
        $this->service->saveCounts($session->id, $warehouseId, [$pid => $physicalQty]);
        $this->service->postSession($session->id, $admin->id);

        return $session->id;
    }

    // ========================================================================
    // getWeekly — shape + zero-case
    // ========================================================================

    public function test_get_weekly_returns_summary_with_zero_sessions_in_range(): void
    {
        $result = $this->report->getWeekly('2025-01-01', '2025-01-31', null);

        // Actual shape: date_from, date_to, branch_id, totals, sessions, top_products.
        $this->assertIsArray($result);
        $this->assertSame('2025-01-01', $result['date_from']);
        $this->assertSame('2025-01-31', $result['date_to']);
        $this->assertNull($result['branch_id']);

        $this->assertIsArray($result['totals']);
        $this->assertIsArray($result['sessions']);
        $this->assertIsArray($result['top_products']);

        // Zero sessions in range → zeroed totals.
        $totals = $result['totals'];
        $this->assertSame(0, $totals['sessions']);
        $this->assertSame(0, $totals['posted']);
        $this->assertSame(0, $totals['reversed']);
        $this->assertSame(0, $totals['open']);
        $this->assertSame(0, $totals['variance_lines']);
        $this->assertEqualsWithDelta(0.0, (float) $totals['gain_value'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $totals['loss_value'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $totals['net_value'], 0.01);

        $this->assertEmpty($result['sessions']);
        $this->assertEmpty($result['top_products']);
    }

    // ========================================================================
    // getWeekly — aggregates posted sessions in range
    // ========================================================================

    public function test_get_weekly_aggregates_posted_sessions_in_range(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        // Two posted sessions: +2 variance (gain=20) and -2 variance (loss=20).
        $this->makePostedSession($branch->id, $wid, 10, 12); // gain 2*10 = 20
        $this->makePostedSession($branch->id, $wid, 10, 8);  // loss 2*10 = 20

        $result = $this->report->getWeekly(
            now()->copy()->subDay()->toDateString(),
            now()->copy()->addDay()->toDateString(),
            null
        );

        $this->assertCount(2, $result['sessions']);

        $totals = $result['totals'];
        $this->assertSame(2, $totals['sessions']);
        $this->assertSame(2, $totals['posted']);
        $this->assertSame(2, $totals['variance_lines']);
        $this->assertEqualsWithDelta(20.0, (float) $totals['gain_value'], 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $totals['loss_value'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $totals['net_value'], 0.01);
    }

    // ========================================================================
    // getWeekly — excludes sessions outside the range
    // ========================================================================

    public function test_get_weekly_excludes_sessions_outside_range(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        // One session INSIDE the range (today) + one OUTSIDE (a week ago).
        $this->makePostedSession($branch->id, $wid, 10, 12, now()->format('Y-m-d'));
        $this->makePostedSession($branch->id, $wid, 10, 8, now()->copy()->subWeek()->format('Y-m-d'));

        $result = $this->report->getWeekly(
            now()->copy()->subDay()->toDateString(),
            now()->copy()->addDay()->toDateString(),
            null
        );

        // Only today's session should be in the result.
        $this->assertCount(1, $result['sessions']);
        $this->assertSame(1, $result['totals']['sessions']);
    }

    // ========================================================================
    // getWeekly — filters by branch
    // ========================================================================

    public function test_get_weekly_filters_by_branch_when_provided(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $widA = $this->insertWarehouse($branchA->id);
        $widB = $this->insertWarehouse($branchB->id);

        $this->makePostedSession($branchA->id, $widA, 10, 12);
        $this->makePostedSession($branchB->id, $widB, 10, 8);

        $result = $this->report->getWeekly(
            now()->copy()->subDay()->toDateString(),
            now()->copy()->addDay()->toDateString(),
            $branchA->id
        );

        $this->assertSame($branchA->id, $result['branch_id']);
        $this->assertCount(1, $result['sessions']);
        foreach ($result['sessions'] as $s) {
            // Each returned session's branch_name matches branch A (the
            // joined branch_name is exposed on every row).
            $this->assertSame($branchA->branch_name, $s->branch_name);
        }
    }

    // ========================================================================
    // getWeekly — excludes cancelled + draft sessions
    // ========================================================================

    public function test_get_weekly_excludes_cancelled_and_draft_sessions(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        // 1 posted session.
        $this->makePostedSession($branch->id, $wid, 10, 12);

        // 1 cancelled session (created via the service, then cancelled).
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);
        $cancelledSession = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'created_by'    => $admin->id,
        ]);
        $this->service->cancelSession($cancelledSession->id, $admin->id, 'Cancelled for weekly-report test.');

        // 1 draft session (created via the service, no further transitions).
        $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'created_by'    => $admin->id,
        ]);

        $result = $this->report->getWeekly(
            now()->copy()->subDay()->toDateString(),
            now()->copy()->addDay()->toDateString(),
            null
        );

        // Only the posted session should be in the result.
        $this->assertCount(1, $result['sessions']);
        $this->assertSame('posted', $result['sessions'][0]->status);
    }

    // ========================================================================
    // getTopVarianceProducts — ranked list
    // ========================================================================

    /**
     * DIVERGENCE: getTopVarianceProducts groups by product (summing
     * abs_qty_variance + abs_value_variance across multiple sessions), so to
     * get 5 distinct ranked rows we need 5 distinct products in a single
     * session. The brief said "5 variance products" — we read that as 5
     * distinct products, all with variance, posted in one session.
     */
    public function test_get_top_variance_products_returns_ranked_list(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);

        // 5 products with escalating abs-value variances:
        //   pid1: diff -1 * rate 10 = 10
        //   pid2: diff +2 * rate 10 = 20
        //   pid3: diff -3 * rate 10 = 30
        //   pid4: diff +4 * rate 10 = 40
        //   pid5: diff -5 * rate 10 = 50
        $cases = [
            [10, 11], // system 10, physical 11 → diff +1 → value 10
            [10, 12], // diff +2 → value 20
            [10, 7],  // diff -3 → value 30
            [10, 14], // diff +4 → value 40
            [10, 5],  // diff -5 → value 50
        ];

        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $pids = [];
        foreach ($cases as [$sys, $phys]) {
            $pid = $this->insertProduct();
            $this->insertWarehouseStock($wid, $pid, $sys);
            $pids[] = $pid;
        }

        $session = $this->service->createSession([
            'branch_id'     => $branch->id,
            'session_date'  => now()->format('Y-m-d'),
            'warehouse_ids' => [$wid],
            'created_by'    => $admin->id,
        ]);
        $this->service->setupWarehouseCounts($session->id, $wid);

        $counts = [];
        foreach ($cases as $i => [$sys, $phys]) {
            $counts[$pids[$i]] = $phys;
        }
        $this->service->saveCounts($session->id, $wid, $counts);
        $this->service->postSession($session->id, $admin->id);

        $top = $this->report->getTopVarianceProducts(
            now()->copy()->subDay()->toDateString(),
            now()->copy()->addDay()->toDateString(),
            null,
            3
        );

        $this->assertCount(3, $top, 'Should return the top-3 variance products.');

        // Verify descending order by abs_value_variance.
        $prev = INF;
        foreach ($top as $row) {
            $absValue = (float) $row->abs_value_variance;
            $this->assertLessThanOrEqual($prev, $absValue, 'Rows should be ordered by abs_value_variance DESC.');
            $prev = $absValue;
        }

        // The top row should be the product with diff=5 (abs value 50).
        $this->assertEqualsWithDelta(50.0, (float) $top[0]->abs_value_variance, 0.01);
        // The 2nd row should be diff=4 (abs value 40).
        $this->assertEqualsWithDelta(40.0, (float) $top[1]->abs_value_variance, 0.01);
        // The 3rd row should be diff=3 (abs value 30).
        $this->assertEqualsWithDelta(30.0, (float) $top[2]->abs_value_variance, 0.01);
    }

    // ========================================================================
    // exportCsv — streamed response
    // ========================================================================

    public function test_export_csv_returns_streamed_response(): void
    {
        $branch = Branch::factory()->create();
        $wid = $this->insertWarehouse($branch->id);
        $this->makePostedSession($branch->id, $wid, 10, 12);

        $report = $this->report->getWeekly(
            now()->copy()->subDay()->toDateString(),
            now()->copy()->addDay()->toDateString(),
            null
        );

        $response = $this->report->exportCsv($report);

        $this->assertInstanceOf(StreamedResponse::class, $response);
        $contentType = $response->headers->get('Content-Type');
        $this->assertNotNull($contentType, 'StreamedResponse must carry a Content-Type header.');
        $this->assertStringContainsString('text/csv', $contentType, 'Content-Type must be text/csv.');
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition', ''));
    }
}

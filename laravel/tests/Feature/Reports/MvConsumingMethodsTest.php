<?php

namespace Tests\Feature\Reports;

use App\Services\Reports\ReportService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsLedgerDependencies;
use Tests\TestCase;

/**
 * MV-Consuming Methods Test — CRITICAL-WAVE-1-B (G-145).
 *
 * Backfills the ZERO-test gap for the 5 `ReportService` methods that
 * read from materialized views (per G9 row in
 * `AI_CONTEXT/reports/materialized-views.md` §14):
 *
 *   1. `receivableAging(Carbon $asOfDate, ?int $branchId = null)`
 *      → reads `mv_ar_aging` when `$asOfDate->isToday()` is true;
 *        falls back to a direct customer_ledger query for historical
 *        dates. Sets `meta.source = 'materialized_view'|'direct_query'`.
 *
 *   2. `payableAging(Carbon $asOfDate, ?int $branchId = null)`
 *      → reads `mv_ap_aging` when today; same direct-query fallback.
 *        Same `meta.source` shape.
 *
 *   3. `journalEntries(Carbon $fromDate, Carbon $toDate, ?int $branchId, ?string $referenceType)`
 *      → reads `mv_journal_entry_summary` always (no today-vs-historical
 *        branch). Sets top-level `source = 'materialized_view'` (NOT
 *        under `meta` — this is the legacy shape; the receivableAging/
 *        payableAging shape is the newer one with `meta.source`).
 *
 *   4. `stockValuation(?int $branchId = null, ?int $warehouseId = null)`
 *      → reads `mv_stock_valuation` always. Returns `meta` + `data` +
 *        `totals` (total_qty + total_value). Does NOT expose a `source`
 *        key — the method is MV-only by design (no fallback path).
 *
 *   5. `branchIntercompany(?int $branchId = null)`
 *      → reads `mv_branch_intercompany` always. Returns `meta` + `data`
 *        + `totals` + `checks.zero_sum` (intercompany should net to
 *        zero across all branch pairs). Does NOT expose a `source` key.
 *
 * Coverage shape (per method):
 *   - HAPPY PATH: method executes + returns the expected array shape.
 *   - SOURCE ASSERTION: where the method exposes a `source` key
 *     (receivableAging, payableAging, journalEntries), assert it equals
 *     `'materialized_view'` (today / always-on-MV path) or
 *     `'direct_query'` (historical-date fallback path).
 *   - FALLBACK PATH: for receivableAging + payableAging, add 1 test
 *     calling with a non-today date to verify the `direct_query` branch.
 *
 * Per the G9 row, this closes gap G-145 (HIGH — 5 untested MV-consuming
 * methods in ReportService).
 *
 * Style: matches JournalPostingServiceTest.php (service-test pattern —
 * instantiate the service via `app(...)`, call methods directly, assert
 * on the returned array shape).
 */
class MvConsumingMethodsTest extends TestCase
{
    use BuildsRoleUsers;
    use InsertsLedgerDependencies;

    private ReportService $reportService;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reportService = app(ReportService::class);

        $admin = $this->makeRoleUser('admin');
        $this->branchId = (int) $admin->getBranchId();

        // Seed a balanced journal pair so the MVs have data after refresh.
        $dr = $this->insertLedger([
            'ledger_code'   => 'MVC-DR-' . substr(uniqid(), -6),
            'ledger_name'   => 'MV-Consumer Dr Ledger',
            'account_type'  => 'Asset',
            'ledger_nature' => 'cash_bank',
        ]);
        $cr = $this->insertLedger([
            'ledger_code'   => 'MVC-CR-' . substr(uniqid(), -6),
            'ledger_name'   => 'MV-Consumer Cr Ledger',
            'account_type'  => 'Liability',
            'ledger_nature' => 'ap',
        ]);
        $this->insertBalancedJournalPair($dr, $cr, 1250.00, $this->branchId);

        // Refresh MVs so the seeded journal pair is reflected in
        // mv_ledger_balances + mv_journal_entry_summary before the
        // tests query them.
        $this->reportService->refreshMaterializedViews();
    }

    // ====================================================================
    // 1. receivableAging → mv_ar_aging (today) / direct query (historical)
    // ====================================================================

    /**
     * G-145 (a1): receivableAging() with today's date reads from
     * mv_ar_aging + sets `meta.source = 'materialized_view'`.
     */
    public function test_receivable_aging_uses_materialized_view_when_today(): void
    {
        $result = $this->reportService->receivableAging(Carbon::today(), $this->branchId);

        $this->assertSame('materialized_view', $result['meta']['source']);
        $this->assertSame(Carbon::today()->format('Y-m-d'), $result['meta']['as_of_date']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('totals', $result);
        $this->assertArrayHasKey('checks', $result);

        // Totals shape — the 4 aging buckets + total_receivable + gl_ar_control.
        $this->assertArrayHasKey('bucket_0_30', $result['totals']);
        $this->assertArrayHasKey('bucket_31_60', $result['totals']);
        $this->assertArrayHasKey('bucket_61_90', $result['totals']);
        $this->assertArrayHasKey('bucket_90_plus', $result['totals']);
        $this->assertArrayHasKey('total_receivable', $result['totals']);
        $this->assertArrayHasKey('gl_ar_control', $result['totals']);

        // Checks shape — the matches_gl reconciliation flag.
        $this->assertArrayHasKey('matches_gl', $result['checks']);
    }

    /**
     * G-145 (a2): receivableAging() with a historical (non-today) date
     * falls back to the direct customer_ledger query + sets
     * `meta.source = 'direct_query'`.
     */
    public function test_receivable_aging_falls_back_to_direct_query_for_historical_date(): void
    {
        $historical = Carbon::yesterday();

        $result = $this->reportService->receivableAging($historical, $this->branchId);

        $this->assertSame('direct_query', $result['meta']['source']);
        $this->assertSame($historical->format('Y-m-d'), $result['meta']['as_of_date']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('totals', $result);
    }

    // ====================================================================
    // 2. payableAging → mv_ap_aging (today) / direct query (historical)
    // ====================================================================

    /**
     * G-145 (b1): payableAging() with today's date reads from
     * mv_ap_aging + sets `meta.source = 'materialized_view'`.
     */
    public function test_payable_aging_uses_materialized_view_when_today(): void
    {
        $result = $this->reportService->payableAging(Carbon::today(), $this->branchId);

        $this->assertSame('materialized_view', $result['meta']['source']);
        $this->assertSame(Carbon::today()->format('Y-m-d'), $result['meta']['as_of_date']);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('totals', $result);

        // Totals shape — the 4 aging buckets + total_payable + gl_ap_control.
        $this->assertArrayHasKey('bucket_0_30', $result['totals']);
        $this->assertArrayHasKey('bucket_31_60', $result['totals']);
        $this->assertArrayHasKey('bucket_61_90', $result['totals']);
        $this->assertArrayHasKey('bucket_90_plus', $result['totals']);
        $this->assertArrayHasKey('total_payable', $result['totals']);
        $this->assertArrayHasKey('gl_ap_control', $result['totals']);

        // Checks shape.
        $this->assertArrayHasKey('matches_gl', $result['checks']);
    }

    /**
     * G-145 (b2): payableAging() with a historical date falls back to
     * the direct supplier_ledger query + sets `meta.source = 'direct_query'`.
     */
    public function test_payable_aging_falls_back_to_direct_query_for_historical_date(): void
    {
        $historical = Carbon::yesterday();

        $result = $this->reportService->payableAging($historical, $this->branchId);

        $this->assertSame('direct_query', $result['meta']['source']);
        $this->assertSame($historical->format('Y-m-d'), $result['meta']['as_of_date']);
        $this->assertArrayHasKey('data', $result);
    }

    // ====================================================================
    // 3. journalEntries → mv_journal_entry_summary (always)
    // ====================================================================

    /**
     * G-145 (c): journalEntries() reads from mv_journal_entry_summary
     * always (no today-vs-historical branch) + sets top-level
     * `source = 'materialized_view'`.
     *
     * Note: the `source` key is at the TOP LEVEL of the return array
     * (not nested under `meta`) — this is the legacy shape preserved
     * for backward compatibility. The newer receivableAging/payableAging
     * shape uses `meta.source`.
     */
    public function test_journal_entries_returns_mv_rows(): void
    {
        $from = Carbon::now()->startOfMonth();
        $to   = Carbon::now();

        $result = $this->reportService->journalEntries($from, $to, $this->branchId);

        $this->assertSame('materialized_view', $result['source']);
        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('data', $result);

        // data is a LengthAwarePaginator (->paginate(50) in the service).
        $this->assertInstanceOf(LengthAwarePaginator::class, $result['data']);

        // Meta shape.
        $this->assertSame($from->format('Y-m-d'), $result['meta']['from_date']);
        $this->assertSame($to->format('Y-m-d'), $result['meta']['to_date']);
        $this->assertSame($this->branchId, $result['meta']['branch_id']);
    }

    // ====================================================================
    // 4. stockValuation → mv_stock_valuation (always — no source key)
    // ====================================================================

    /**
     * G-145 (d): stockValuation() reads from mv_stock_valuation always.
     * The method does NOT expose a `source` key (it's MV-only by design
     * — no fallback path). We assert the `meta` + `data` + `totals`
     * shape + the totals keys (total_qty + total_value).
     */
    public function test_stock_valuation_returns_mv_rows(): void
    {
        $result = $this->reportService->stockValuation($this->branchId);

        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('totals', $result);

        // Totals shape — on_hand_qty sum + stock_value sum.
        $this->assertArrayHasKey('total_qty', $result['totals']);
        $this->assertArrayHasKey('total_value', $result['totals']);

        // The data is a Collection of MV rows (may be empty if no
        // warehouse_stock seeded — that's fine; the assertion is that
        // the query executed + returned a Collection, not that rows
        // exist).
        $this->assertIsIterable($result['data']);
    }

    // ====================================================================
    // 5. branchIntercompany → mv_branch_intercompany (always — no source key)
    // ====================================================================

    /**
     * G-145 (e): branchIntercompany() reads from mv_branch_intercompany
     * always. The method does NOT expose a `source` key (MV-only). We
     * assert the `meta` + `data` + `totals` + `checks` shape, with the
     * `checks.zero_sum` flag (intercompany should net to zero across
     * all branch pairs — a core accounting invariant).
     */
    public function test_branch_intercompany_returns_mv_rows(): void
    {
        $result = $this->reportService->branchIntercompany($this->branchId);

        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('totals', $result);
        $this->assertArrayHasKey('checks', $result);

        // Totals shape — total_debit + total_credit + net_balance +
        // total_outstanding (intercompany sums).
        $this->assertArrayHasKey('total_debit', $result['totals']);
        $this->assertArrayHasKey('total_credit', $result['totals']);
        $this->assertArrayHasKey('net_balance', $result['totals']);
        $this->assertArrayHasKey('total_outstanding', $result['totals']);

        // Checks shape — the zero_sum invariant (intercompany nets to
        // zero across all branch pairs).
        $this->assertArrayHasKey('zero_sum', $result['checks']);
        $this->assertIsBool($result['checks']['zero_sum']);

        // Data is iterable (Collection of branch-pair rows).
        $this->assertIsIterable($result['data']);
    }
}

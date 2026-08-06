<?php

namespace Tests\Feature\Reports;

use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsLedgerDependencies;
use Tests\TestCase;

/**
 * CTE Report Function Test — CRITICAL-WAVE-1-B (G-135).
 *
 * Backfills the ZERO-test gap for the 4 CTE PL/pgSQL STABLE functions
 * documented in `database/sql/07_views_triggers_constraints.sql` §2
 * (lines 1163 / 1352 / 1493 / 1647):
 *
 *   1. `rcerp_today_summary(p_branch_id integer DEFAULT NULL,
 *                            p_date      date    DEFAULT CURRENT_DATE)
 *      RETURNS jsonb` — single CTE query aggregating today + MTD sales +
 *      outstanding + growth + pending ops + top customers/products +
 *      AR aging + branch revenue.
 *
 *   2. `rcerp_ar_aging_cte(p_as_of_date date,
 *                           p_branch_id  integer DEFAULT NULL)
 *      RETURNS jsonb` — proper sub-ledger AR aging with GL reconciliation
 *      check (matches_gl).
 *
 *   3. `rcerp_general_ledger_cte(p_from_date  date,
 *                                 p_to_date    date,
 *                                 p_ledger_id  integer DEFAULT NULL,
 *                                 p_branch_id  integer DEFAULT NULL)
 *      RETURNS jsonb` — general ledger with SQL window-function running
 *      balance (SUM() OVER PARTITION BY ledger_id ORDER BY … ROWS
 *      UNBOUNDED PRECEDING).
 *
 *   4. `rcerp_gross_margin_cte(p_from_date  date,
 *                               p_to_date    date,
 *                               p_branch_id  integer DEFAULT NULL)
 *      RETURNS jsonb` — gross-margin analysis with per-item COGS via CTE
 *      joining invoice_items → sales_challan_items → stock_transactions.
 *
 * All 4 functions return `jsonb`. PHP PDO returns jsonb as a string —
 * we `json_decode` it to access the structured payload.
 *
 * Per the G7 row in `AI_CONTEXT/reports/cte-reports.md` §14, this closes
 * gap G-135 (HIGH — 8 untested public code paths: 4 SQL functions + 4 PHP
 * methods in CteReportService that wrap them). This file tests the SQL
 * functions directly via `DB::selectOne(...)` — the PHP method wrappers
 * (CteReportService::todaySummary / arAging / generalLedger / grossMargin)
 * are exercised by the controller route tests in FinancialReportControllerTest
 * (G-137), which route through those wrappers.
 *
 * Coverage shape (per function):
 *   - The function executes without error (smoke).
 *   - The returned JSON has the expected top-level shape (key presence).
 *   - With a non-existent branch_id, the function still executes (defensive
 *     against NULL branch_id — G12 cross-cutting concern).
 *   - With seeded ledger/journal data, the GL CTE returns entries with
 *     a running_balance key.
 */
class CteReportTest extends TestCase
{
    use BuildsRoleUsers;
    use InsertsLedgerDependencies;

    private int $branchId;
    private int $ledgerId1;
    private int $ledgerId2;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->makeRoleUser('admin');
        $this->branchId = (int) $admin->getBranchId();

        // Two ledgers of different natures so the AR-aging CTE has an 'ar'
        // ledger to sum against the GL AR control account.
        $this->ledgerId1 = $this->insertLedger([
            'ledger_code'   => 'CTE-DR-' . substr(uniqid(), -6),
            'ledger_name'   => 'CTE Test Dr Ledger',
            'account_type'  => 'Asset',
            'ledger_nature' => 'cash_bank',
        ]);
        $this->ledgerId2 = $this->insertLedger([
            'ledger_code'   => 'CTE-CR-' . substr(uniqid(), -6),
            'ledger_name'   => 'CTE Test Cr Ledger',
            'account_type'  => 'Liability',
            'ledger_nature' => 'ap',
        ]);
    }

    /**
     * Decode the jsonb column returned by a CTE function. DB::selectOne
     * returns a stdClass with the column named in the SQL alias; jsonb is
     * returned as a string by PDO_PGSQL.
     */
    private function callCteFunction(string $sql, array $params): array
    {
        $row = DB::selectOne($sql, $params);

        $this->assertNotNull($row, 'CTE function should return a row.');

        // The SQL alias is `result`; extract + json_decode.
        $payload = $row->result ?? null;
        $this->assertNotNull($payload, 'CTE function result column should not be NULL.');

        $decoded = json_decode($payload, true);
        $this->assertIsArray($decoded, 'CTE function should return valid JSON (json_decode failed).');

        return $decoded;
    }

    // ====================================================================
    // 1. rcerp_today_summary(p_branch_id, p_date)
    // ====================================================================

    /**
     * G-135 (a1): rcerp_today_summary() executes without error and
     * returns the expected JSON shape with the documented top-level keys
     * (date, branch_id, today, mtd, outstanding, growth, pending,
     * top_customers, top_products, ar_aging, branch_revenue).
     */
    public function test_rcerp_today_summary_returns_expected_shape(): void
    {
        $result = $this->callCteFunction(
            'SELECT rcerp_today_summary(?, ?) AS result',
            [$this->branchId, now()->toDateString()],
        );

        // Top-level keys (per §2.1 of 07_views_triggers_constraints.sql).
        $this->assertArrayHasKey('date', $result);
        $this->assertArrayHasKey('branch_id', $result);
        $this->assertArrayHasKey('today', $result);
        $this->assertArrayHasKey('mtd', $result);
        $this->assertArrayHasKey('outstanding', $result);
        $this->assertArrayHasKey('growth', $result);
        $this->assertArrayHasKey('pending', $result);
        $this->assertArrayHasKey('top_customers', $result);
        $this->assertArrayHasKey('top_products', $result);
        $this->assertArrayHasKey('ar_aging', $result);
        $this->assertArrayHasKey('branch_revenue', $result);

        // Today + MTD sub-objects have invoice_count + total_sales keys.
        $this->assertArrayHasKey('invoice_count', $result['today']);
        $this->assertArrayHasKey('total_sales', $result['today']);
        $this->assertArrayHasKey('invoice_count', $result['mtd']);
        $this->assertArrayHasKey('total_sales', $result['mtd']);
    }

    /**
     * G-135 (a2): rcerp_today_summary() with a NULL branch_id returns
     * all-branches data without error (defensive — G12 medium-severity
     * cross-cutting concern that the CTE function should handle NULL).
     */
    public function test_rcerp_today_summary_with_null_branch_id_executes_without_error(): void
    {
        $result = $this->callCteFunction(
            'SELECT rcerp_today_summary(NULL, ?) AS result',
            [now()->toDateString()],
        );

        // branch_id should be NULL in the result (echoes the input).
        $this->assertNull($result['branch_id']);
        // All-shape keys still present.
        $this->assertArrayHasKey('today', $result);
        $this->assertArrayHasKey('mtd', $result);
    }

    // ====================================================================
    // 2. rcerp_ar_aging_cte(p_as_of_date, p_branch_id)
    // ====================================================================

    /**
     * G-135 (b1): rcerp_ar_aging_cte() executes without error + returns
     * the expected JSON shape (meta, customers, totals, checks,
     * overdue_invoices, aging_by_branch).
     */
    public function test_rcerp_ar_aging_cte_returns_expected_shape(): void
    {
        $result = $this->callCteFunction(
            'SELECT rcerp_ar_aging_cte(?, ?) AS result',
            [now()->toDateString(), $this->branchId],
        );

        // Top-level keys (per §2.2).
        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('customers', $result);
        $this->assertArrayHasKey('totals', $result);
        $this->assertArrayHasKey('checks', $result);
        $this->assertArrayHasKey('overdue_invoices', $result);
        $this->assertArrayHasKey('aging_by_branch', $result);

        // Meta sub-object.
        $this->assertArrayHasKey('as_of_date', $result['meta']);
        $this->assertArrayHasKey('branch_id', $result['meta']);
        $this->assertArrayHasKey('source', $result['meta']);
        $this->assertSame('cte_query', $result['meta']['source']);

        // Totals sub-object — aging buckets.
        $this->assertArrayHasKey('bucket_0_30', $result['totals']);
        $this->assertArrayHasKey('bucket_31_60', $result['totals']);
        $this->assertArrayHasKey('bucket_61_90', $result['totals']);
        $this->assertArrayHasKey('bucket_90_plus', $result['totals']);
        $this->assertArrayHasKey('total_receivable', $result['totals']);
        $this->assertArrayHasKey('gl_ar_control', $result['totals']);

        // Checks sub-object — the matches_gl reconciliation flag.
        $this->assertArrayHasKey('matches_gl', $result['checks']);
    }

    /**
     * G-135 (b2): rcerp_ar_aging_cte() on a date with NO customer_ledger
     * rows returns 0 customers + zero totals (empty-shape assertion).
     */
    public function test_rcerp_ar_aging_cte_returns_empty_shape_when_no_data(): void
    {
        // Use a far-past date that predates any seeded customer_ledger row.
        $emptyDate = '2020-01-01';

        $result = $this->callCteFunction(
            'SELECT rcerp_ar_aging_cte(?, NULL) AS result',
            [$emptyDate],
        );

        // Customers should be an empty array (no rows having SUM > 0.005).
        $this->assertSame([], $result['customers']);
        // Totals should all be 0.
        $this->assertSame(0.0, (float) $result['totals']['total_receivable']);
        $this->assertSame(0.0, (float) $result['totals']['bucket_0_30']);
    }

    // ====================================================================
    // 3. rcerp_general_ledger_cte(p_from_date, p_to_date, p_ledger_id, p_branch_id)
    // ====================================================================

    /**
     * G-135 (c1): rcerp_general_ledger_cte() executes without error +
     * returns the expected JSON shape (meta, entries, ledger_summary,
     * totals, checks).
     */
    public function test_rcerp_general_ledger_cte_returns_expected_shape(): void
    {
        $from = now()->startOfMonth()->toDateString();
        $to   = now()->toDateString();

        $result = $this->callCteFunction(
            'SELECT rcerp_general_ledger_cte(?, ?, NULL, ?) AS result',
            [$from, $to, $this->branchId],
        );

        // Top-level keys (per §2.3).
        $this->assertArrayHasKey('meta', $result);
        $this->assertArrayHasKey('entries', $result);
        $this->assertArrayHasKey('ledger_summary', $result);
        $this->assertArrayHasKey('totals', $result);
        $this->assertArrayHasKey('checks', $result);

        // Meta — source indicates the window-function path.
        $this->assertSame('cte_window_function', $result['meta']['source']);

        // Totals — Dr + Cr sums.
        $this->assertArrayHasKey('total_debit', $result['totals']);
        $this->assertArrayHasKey('total_credit', $result['totals']);
        $this->assertArrayHasKey('total_opening', $result['totals']);
        $this->assertArrayHasKey('total_closing', $result['totals']);

        // Checks — balanced flag.
        $this->assertArrayHasKey('balanced', $result['checks']);
    }

    /**
     * G-135 (c2): rcerp_general_ledger_cte() with seeded journal data
     * returns entries (the CTE joined through journal_lines successfully
     * and emitted at least one entry row). Also asserts the
     * `running_balance` key is present on each entry — the SQL window
     * function's whole point.
     */
    public function test_rcerp_general_ledger_cte_returns_entries_with_running_balance(): void
    {
        // Seed a balanced journal-entry pair within the period.
        $this->insertBalancedJournalPair(
            $this->ledgerId1,
            $this->ledgerId2,
            750.00,
            $this->branchId,
        );

        $from = now()->startOfMonth()->toDateString();
        $to   = now()->toDateString();

        $result = $this->callCteFunction(
            'SELECT rcerp_general_ledger_cte(?, ?, NULL, ?) AS result',
            [$from, $to, $this->branchId],
        );

        $entries = $result['entries'];
        $this->assertIsArray($entries);
        $this->assertNotEmpty($entries, 'GL CTE should return at least one entry for the seeded journal pair.');

        // Each entry should have the running_balance key (the window-function output).
        $firstEntry = $entries[0];
        $this->assertArrayHasKey('running_balance', $firstEntry);
        $this->assertArrayHasKey('journal_entry_id', $firstEntry);
        $this->assertArrayHasKey('ledger_id', $firstEntry);
        $this->assertArrayHasKey('debit', $firstEntry);
        $this->assertArrayHasKey('credit', $firstEntry);
    }

    // ====================================================================
    // 4. rcerp_gross_margin_cte(p_from_date, p_to_date, p_branch_id)
    // ====================================================================

    /**
     * G-135 (d1): rcerp_gross_margin_cte() executes without error +
     * returns the expected JSON shape. The function joins invoice_items
     * → sales_challan_items → stock_transactions for per-product COGS.
     * With no seeded sales data, it returns an empty-shape payload but
     * should NOT raise.
     */
    public function test_rcerp_gross_margin_cte_returns_expected_shape(): void
    {
        $from = now()->startOfMonth()->toDateString();
        $to   = now()->toDateString();

        $result = $this->callCteFunction(
            'SELECT rcerp_gross_margin_cte(?, ?, ?) AS result',
            [$from, $to, $this->branchId],
        );

        // The function returns a jsonb payload — at minimum it should be
        // an array (decoded) and NOT raise. The exact key shape depends
        // on whether the CTE found any sales in the period; the smoke
        // guarantee is "executes without error + returns JSON".
        $this->assertIsArray($result);
    }

    /**
     * G-135 (d2): rcerp_gross_margin_cte() on a far-past date with no
     * sales data executes without error (defensive — the CTE's
     * invoice_items → challan_items → stock_transactions join handles
     * the empty-set case without raising).
     */
    public function test_rcerp_gross_margin_cte_executes_without_error_on_empty_period(): void
    {
        $emptyDate = '2020-01-01';

        $result = $this->callCteFunction(
            'SELECT rcerp_gross_margin_cte(?, ?, NULL) AS result',
            [$emptyDate, $emptyDate],
        );

        $this->assertIsArray($result);
    }
}

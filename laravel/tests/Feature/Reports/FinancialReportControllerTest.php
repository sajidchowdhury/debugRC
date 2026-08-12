<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsLedgerDependencies;
use Tests\TestCase;

/**
 * Financial Report Controller Test — CRITICAL-WAVE-1-B (G-137).
 *
 * Backfills the ZERO-test gap for the 7 financial report controller
 * methods in `app/Http/Controllers/Admin/ReportController.php`:
 *
 *   1. `trialBalance`     (L59)  → admin.reports.trialBalance     (ReportRangeRequest)
 *   2. `profitAndLoss`    (L264) → admin.reports.profitAndLoss    (ReportRangeRequest)
 *   3. `balanceSheet`     (L401) → admin.reports.balanceSheet     (ReportAsOfRequest)
 *   4. `cashFlow`         (L535) → admin.reports.cashFlow         (ReportRangeRequest)
 *   5. `generalLedger`    (L684) → admin.reports.generalLedger    (ReportRangeRequest)
 *   6. `receivableAging`  (L763) → admin.reports.receivableAging  (ReportAsOfRequest)
 *   7. `payableAging`     (L914) → admin.reports.payableAging     (ReportAsOfRequest)
 *
 * All 7 routes are registered under `Route::prefix('admin/reports')->name('admin.reports.')`
 * with middleware `role:accountant,manager,admin` (see `routes/web.php` L389-410).
 * These are WEB admin routes (not API routes), so:
 *
 *   - The auth failure is a 302 redirect to /login (NOT a 401 JSON).
 *   - The success response is an HTML view (NOT JSON). Tests use
 *     `assertViewIs(...)` rather than `assertJsonPath(...)`.
 *   - Auth uses the `web` guard via `$this->actingAs($admin)`.
 *
 * Per the G7 row in `AI_CONTEXT/reports/reports-catalog.md` §14, this
 * closes gap G-137 (HIGH — 7 of the 57 untested report methods, the
 * highest-traffic financial reports: TB / P&L / BS / CF / GL / AR / AP).
 *
 * Coverage shape (per method):
 *   - HAPPY PATH: 200 + correct view rendered.
 *   - CSV EXPORT: 200 + text/csv Content-Type + UTF-8 BOM (where the
 *     method exposes `?export=csv` — TB, P&L, BS, CF, AR aging have it;
 *     GL + AP aging do NOT have CSV export in this wave).
 *
 * Plus 2 cross-cutting tests:
 *   - VALIDATION: invalid `from_date=not-a-date` → 422 (FormRequest
 *     validation runs BEFORE the controller body).
 *   - AUTH: unauthenticated → 302 redirect to login.
 *
 * Style: matches CsvExportTest.php (admin web Feature test pattern).
 */
class FinancialReportControllerTest extends TestCase
{
    use BuildsRoleUsers;
    use InsertsLedgerDependencies;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin user — passes the `role:accountant,manager,admin` gate.
        $this->admin = $this->makeRoleUser('admin');

        // Seed a balanced journal pair so the report SQL has at least
        // one row to aggregate. The views render fine on empty data,
        // but a populated row makes the smoke test more meaningful.
        $branchId = (int) $this->admin->getBranchId();

        $dr = $this->insertLedger([
            'ledger_code'   => 'RPT-DR-' . substr(uniqid(), -6),
            'ledger_name'   => 'Report Dr Ledger',
            'account_type'  => 'Asset',
            'ledger_nature' => 'cash_bank',
        ]);
        $cr = $this->insertLedger([
            'ledger_code'   => 'RPT-CR-' . substr(uniqid(), -6),
            'ledger_name'   => 'Report Cr Ledger',
            'account_type'  => 'Liability',
            'ledger_nature' => 'ap',
        ]);
        $this->insertBalancedJournalPair($dr, $cr, 1000.00, $branchId);
    }

    // ====================================================================
    // SHARED ASSERTION
    // ====================================================================

    /**
     * Assert the response is a successful CSV download (200, text/csv
     * Content-Type, attachment Content-Disposition, UTF-8 BOM at start).
     * Mirrors CsvExportTest::assertCsvResponse.
     */
    private function assertCsvResponse($response): string
    {
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.csv', $disposition);

        $content = $response->streamedContent();
        $this->assertSame(
            "\xEF\xBB\xBF",
            substr($content, 0, 3),
            'CSV should start with UTF-8 BOM (per CsvExporter convention).',
        );

        return $content;
    }

    // ====================================================================
    // 1. TRIAL BALANCE
    // ====================================================================

    public function test_trial_balance_returns_200_with_expected_view(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.trialBalance'))
            ->assertOk()
            ->assertViewIs('admin.reports.trial_balance');
    }

    public function test_trial_balance_csv_export_returns_csv_download(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.reports.trialBalance', ['export' => 'csv']));

        $content = $this->assertCsvResponse($response);
        $this->assertNotEmpty($content, 'Trial-balance CSV body should not be empty.');
    }

    // ====================================================================
    // 2. PROFIT & LOSS
    // ====================================================================

    public function test_profit_and_loss_returns_200_with_expected_view(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.profitAndLoss'))
            ->assertOk()
            ->assertViewIs('admin.reports.profit_and_loss');
    }

    public function test_profit_and_loss_csv_export_returns_csv_download(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.reports.profitAndLoss', ['export' => 'csv']));

        $content = $this->assertCsvResponse($response);
        $this->assertNotEmpty($content, 'P&L CSV body should not be empty.');
    }

    // ====================================================================
    // 3. BALANCE SHEET
    // ====================================================================

    public function test_balance_sheet_returns_200_with_expected_view(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.balanceSheet'))
            ->assertOk()
            ->assertViewIs('admin.reports.balance_sheet');
    }

    public function test_balance_sheet_csv_export_returns_csv_download(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.reports.balanceSheet', ['export' => 'csv']));

        $content = $this->assertCsvResponse($response);
        $this->assertNotEmpty($content, 'Balance-sheet CSV body should not be empty.');
    }

    // ====================================================================
    // 4. CASH FLOW
    // ====================================================================

    public function test_cash_flow_returns_200_with_expected_view(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.cashFlow'))
            ->assertOk()
            ->assertViewIs('admin.reports.cash_flow');
    }

    public function test_cash_flow_csv_export_returns_csv_download(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.reports.cashFlow', ['export' => 'csv']));

        $content = $this->assertCsvResponse($response);
        $this->assertNotEmpty($content, 'Cash-flow CSV body should not be empty.');
    }

    // ====================================================================
    // 5. GENERAL LEDGER
    // ====================================================================

    public function test_general_ledger_returns_200_with_expected_view(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.generalLedger'))
            ->assertOk()
            ->assertViewIs('admin.reports.general_ledger');
    }

    // ====================================================================
    // 6. RECEIVABLE AGING
    // ====================================================================

    public function test_receivable_aging_returns_200_with_expected_view(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.receivableAging'))
            ->assertOk()
            ->assertViewIs('admin.reports.receivable_aging');
    }

    public function test_receivable_aging_csv_export_returns_csv_download(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.reports.receivableAging', ['export' => 'csv']));

        $content = $this->assertCsvResponse($response);
        $this->assertNotEmpty($content, 'AR-aging CSV body should not be empty.');
    }

    // ====================================================================
    // 7. PAYABLE AGING
    // ====================================================================

    public function test_payable_aging_returns_200_with_expected_view(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.payableAging'))
            ->assertOk()
            ->assertViewIs('admin.reports.payable_aging');
    }

    // ====================================================================
    // VALIDATION (FormRequest — runs BEFORE controller body)
    // ====================================================================

    /**
     * G-137 validation: invalid `from_date=not-a-date` triggers the
     * `date` rule on ReportRangeRequest → 422 (NOT a 500 from
     * Carbon::parse()). Verified on the trialBalance route (uses
     * ReportRangeRequest); the same FormRequest gates profitAndLoss,
     * cashFlow, generalLedger so the validation behavior is identical.
     */
    public function test_report_range_request_rejects_invalid_from_date(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.trialBalance', ['from_date' => 'not-a-date']))
            ->assertStatus(422);
    }

    /**
     * G-137 validation: invalid `as_of_date=not-a-date` triggers the
     * `date` rule on ReportAsOfRequest → 422. Verified on the balanceSheet
     * route (uses ReportAsOfRequest); the same FormRequest gates
     * receivableAging + payableAging.
     */
    public function test_report_as_of_request_rejects_invalid_as_of_date(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.balanceSheet', ['as_of_date' => 'not-a-date']))
            ->assertStatus(422);
    }

    /**
     * G-137 validation: invalid `format=xml` triggers the `in:csv,json,html`
     * rule on ReportRangeRequest → 422.
     */
    public function test_report_range_request_rejects_invalid_format_value(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.reports.profitAndLoss', ['format' => 'xml']))
            ->assertStatus(422);
    }

    // ====================================================================
    // AUTH (web guard — 302 redirect to login on unauthenticated)
    // ====================================================================

    /**
     * G-137 auth: unauthenticated GET on a report route redirects to
     * the login page (302) — these are web admin routes, NOT API routes
     * (so the failure is a redirect, not a 401 JSON response).
     */
    public function test_report_routes_require_authentication(): void
    {
        // No actingAs — should redirect to login.
        $this->get(route('admin.reports.trialBalance'))
            ->assertRedirect(route('login'));
    }
}

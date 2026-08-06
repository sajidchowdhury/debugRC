<?php

namespace Tests\Feature\Dashboard;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * User Performance Dashboard feature test — LOW-WAVE-2-B2 (G-297 / dashboards.md G18).
 *
 * Backfills the ZERO-test gap for the 3 web routes served by
 * `App\Http\Controllers\UserPerformanceDashboardController`:
 *
 *   GET /dashboard             → index()         (name: dashboard)
 *   GET /dashboard/sales-trend → salesTrendAjax() (name: dashboard.salesTrend)
 *   GET /dashboard/fragment    → fragmentAjax()   (name: dashboard.fragment)
 *
 * All 3 routes are registered under `Route::middleware('auth')` + a
 * `role:admin,manager,accountant,salesman,warehouse_manager,dispatcher,
 * hr,user,other` defense-in-depth gate (see `routes/web.php:108-120`).
 * The controller was refactored in HIGH-WAVE-3 (G-144) from a 2273-line
 * god-class into a ~700L thin orchestrator delegating to 6 metric services
 * in `App\Services\Dashboard\`. This test class locks in that refactor's
 * public contract so a future regression is caught.
 *
 * Coverage shape (per the G18 gap row):
 *   - AUTH: unauthenticated GET on each route → 302 redirect to /login
 *     (web guard, NOT 401 JSON — these are HTML routes, not API routes).
 *   - INDEX SMOKE: GET /dashboard as 3 roles (salesman, manager, superadmin)
 *     → 200 + view `dashboard.performance` + view-has the canonical data
 *     keys (targetEmployee, period, periodLabel, range, roleSections, …).
 *   - AJAX ENDPOINTS: GET /dashboard/fragment + /dashboard/sales-trend →
 *     200 + JSON structure (html/period/periodLabel/range/employeeId for
 *     fragment; data/days/phase for sales-trend).
 *   - PERIOD RESOLUTION: ?period=today / last30 / custom → resolvePeriod()
 *     returns the expected [start, end] range in the view data.
 *   - EMPLOYEE SWITCHING: superadmin's ?employee_id=X switches target;
 *     salesman's ?employee_id=X is silently ignored (sees own metrics).
 *   - SEEDED-DATA SMOKE: a SalesInvoice created via factory (with
 *     `created_by = $user->id`) does not crash any of the 3 endpoints.
 *
 * Style: matches `Tests\Feature\Reports\FinancialReportControllerTest`
 * (web guard, 302-on-unauth, assertViewIs/assertViewHas for HTML routes,
 * `BuildsRoleUsers` trait for authenticated role-based users). Uses
 * `DatabaseTransactions` (inherited from `Tests\TestCase`) so seeded
 * invoices are rolled back after each test.
 */
class UserPerformanceDashboardTest extends TestCase
{
    use BuildsRoleUsers;

    /**
     * Flush the per-user metric cache before each test so cached values
     * from a previous test never leak. The controller's `cached()` wrapper
     * keys metrics as `perf:user:{id}:{metric}:{period}:{rangeHash}` —
     * a stale entry would make the seeded-invoice smoke tests non-
     * deterministic.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ====================================================================
    // AUTH — unauthenticated GET → 302 redirect to /login
    // (web guard, NOT 401 JSON — these are HTML routes, not API routes)
    // ====================================================================

    public function test_dashboard_index_requires_authentication(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_dashboard_sales_trend_requires_authentication(): void
    {
        $this->get(route('dashboard.salesTrend'))
            ->assertRedirect(route('login'));
    }

    public function test_dashboard_fragment_requires_authentication(): void
    {
        $this->get(route('dashboard.fragment'))
            ->assertRedirect(route('login'));
    }

    // ====================================================================
    // INDEX SMOKE — GET /dashboard as 3 roles
    // ====================================================================

    public function test_index_returns_ok_for_salesman(): void
    {
        $user = $this->makeRoleUser('salesman');

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewIs('dashboard.performance');
        $response->assertViewHasAll([
            'title', 'user', 'isSuperadmin', 'targetEmployee', 'targetUser',
            'employeeOptions', 'period', 'periodLabel', 'range',
            'scaffoldingOnly', 'customerPaymentsTxnType', 'roleSections',
            'salesKpis', 'salesTrend', 'salesByProductGroup',
            'topCustomers', 'customerAcquisition',
            'collectionKpis', 'receivableAging', 'returnKpis', 'paymentModeMix',
            'velocityKpis', 'pipelineSnapshot', 'workPattern',
            'activitySummary', 'notificationEngagement',
            'commissionSummary', 'stockDiscipline', 'accuracyKpis',
            'approvalWorkload', 'fragmentMode',
        ]);

        // Salesman should NOT see approval_workload section (Phase 5 role map).
        $sections = $this->viewData($response, 'roleSections');
        $this->assertFalse($sections['approval_workload']);
        // Salesman is not superadmin → no employee options in the select.
        $this->assertFalse($this->viewData($response, 'isSuperadmin'));
    }

    public function test_index_returns_ok_for_manager(): void
    {
        $user = $this->makeRoleUser('manager');

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewIs('dashboard.performance');

        // Manager → all sections + approval_workload enabled (Phase 5 role map).
        $sections = $this->viewData($response, 'roleSections');
        $this->assertTrue($sections['sales']);
        $this->assertTrue($sections['approval_workload']);
    }

    public function test_index_returns_ok_for_superadmin(): void
    {
        $user = $this->makeRoleUser('superadmin');

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewIs('dashboard.performance');

        // Superadmin sees the employee <select> populated (Phase 0 design).
        $this->assertTrue($this->viewData($response, 'isSuperadmin'));
        $options = $this->viewData($response, 'employeeOptions');
        $this->assertGreaterThanOrEqual(
            1,
            $options->count(),
            'Superadmin should see at least themselves in the employee options.',
        );
    }

    // ====================================================================
    // AJAX ENDPOINTS — JSON structure
    // ====================================================================

    public function test_fragment_ajax_returns_json_structure(): void
    {
        $user = $this->makeRoleUser('manager');

        $response = $this->actingAs($user)->get(route('dashboard.fragment'));

        $response->assertOk();
        $response->assertJsonStructure([
            'html', 'period', 'periodLabel', 'range', 'employeeId', 'employeeName',
        ]);

        // The fragment must render SOME html (the #perf-dashboard container).
        $this->assertNotEmpty(
            $response->json('html'),
            'Fragment AJAX must return non-empty HTML for the #perf-dashboard container.',
        );
    }

    public function test_sales_trend_ajax_returns_json_structure(): void
    {
        $user = $this->makeRoleUser('salesman');

        $response = $this->actingAs($user)->get(route('dashboard.salesTrend'));

        $response->assertOk();
        $response->assertJsonStructure(['data', 'days', 'phase']);

        // Default days = 7 → 7 daily entries.
        $this->assertSame(7, $response->json('days'));
        $this->assertCount(7, $response->json('data'));
    }

    public function test_sales_trend_ajax_days_parameter_is_clamped_to_7_90(): void
    {
        $user = $this->makeRoleUser('salesman');

        // days=3 → clamped UP to 7 (min).
        $r1 = $this->actingAs($user)->get(route('dashboard.salesTrend', ['days' => 3]));
        $r1->assertOk();
        $this->assertSame(7, $r1->json('days'));

        // days=999 → clamped DOWN to 90 (max).
        $r2 = $this->actingAs($user)->get(route('dashboard.salesTrend', ['days' => 999]));
        $r2->assertOk();
        $this->assertSame(90, $r2->json('days'));
    }

    // ====================================================================
    // PERIOD RESOLUTION — ?period=today / last30 / custom
    // (resolvePeriod() returns [period, label, [start, end]]; the view
    // receives the range as the `range` key.)
    // ====================================================================

    public function test_period_today_resolves_correctly(): void
    {
        $user = $this->makeRoleUser('manager');

        $response = $this->actingAs($user)
            ->get(route('dashboard', ['period' => 'today']));
        $response->assertOk();

        $range = $this->viewData($response, 'range');
        $this->assertSame(now()->toDateString(), $range['start']);
        $this->assertSame(now()->toDateString(), $range['end']);
        $this->assertSame('today', $this->viewData($response, 'period'));
    }

    public function test_period_last30_resolves_correctly(): void
    {
        $user = $this->makeRoleUser('manager');

        $response = $this->actingAs($user)
            ->get(route('dashboard', ['period' => 'last30']));
        $response->assertOk();

        $range = $this->viewData($response, 'range');
        $this->assertSame(now()->subDays(29)->toDateString(), $range['start']);
        $this->assertSame(now()->toDateString(), $range['end']);
        $this->assertSame('last30', $this->viewData($response, 'period'));
    }

    public function test_period_custom_resolves_correctly(): void
    {
        $user = $this->makeRoleUser('manager');

        // Use a fixed historical range so the test is deterministic. The
        // FormRequest validates from <= to <= today, so we use last month.
        $from = now()->subMonth()->startOfMonth()->toDateString();
        $to   = now()->subMonth()->endOfMonth()->toDateString();

        $response = $this->actingAs($user)
            ->get(route('dashboard', ['period' => 'custom', 'from' => $from, 'to' => $to]));
        $response->assertOk();

        $range = $this->viewData($response, 'range');
        $this->assertSame($from, $range['start']);
        $this->assertSame($to, $range['end']);
        $this->assertSame('custom', $this->viewData($response, 'period'));
    }

    // ====================================================================
    // EMPLOYEE SWITCHING — superadmin vs salesman ?employee_id behavior
    // ====================================================================

    public function test_superadmin_can_switch_employee_via_employee_id(): void
    {
        // Create the superadmin + a separate employee (with linked user) for
        // the superadmin to switch to. Both must be in the same DB so the
        // superadmin's employeeOptions includes the target employee.
        $superadmin = $this->makeRoleUser('superadmin');

        $branch    = Branch::factory()->create();
        $targetEmp = Employee::factory()->forBranch($branch->id)->withRole('salesman')->create();
        // The controller's resolveContext() resolves the targetUser via
        // User::where('employee_id', $targetEmployeeId). Seed one.
        User::factory()->forEmployee($targetEmp->id)->create([
            'username' => 'target_' . substr(uniqid(), -6),
        ]);

        $response = $this->actingAs($superadmin)
            ->get(route('dashboard', ['employee_id' => $targetEmp->id]));
        $response->assertOk();

        $targetEmployee = $this->viewData($response, 'targetEmployee');
        $this->assertNotNull($targetEmployee, 'Superadmin ?employee_id=X should switch the target employee.');
        $this->assertSame($targetEmp->id, $targetEmployee->id);
    }

    public function test_salesman_employee_id_is_ignored(): void
    {
        // Salesman tries to view another employee's dashboard via ?employee_id.
        // resolveContext() only honors ?employee_id when isSuperadmin() is true.
        $salesman = $this->makeRoleUser('salesman');

        $branch   = Branch::factory()->create();
        $otherEmp = Employee::factory()->forBranch($branch->id)->withRole('salesman')->create();

        $response = $this->actingAs($salesman)
            ->get(route('dashboard', ['employee_id' => $otherEmp->id]));
        $response->assertOk();

        $targetEmployee = $this->viewData($response, 'targetEmployee');
        $this->assertNotNull($targetEmployee, 'Salesman should still see a target employee (their own).');
        $this->assertNotSame(
            $otherEmp->id,
            $targetEmployee->id,
            'Salesman ?employee_id=X for another employee should be IGNORED — they see their own metrics.',
        );
        $this->assertSame(
            $salesman->employee->id,
            $targetEmployee->id,
            'Salesman should always see their own employee record.',
        );
    }

    // ====================================================================
    // SEEDED-DATA SMOKE — verify endpoints don't crash with a real invoice
    // ====================================================================

    public function test_index_with_seeded_invoice_does_not_crash(): void
    {
        $user = $this->makeRoleUser('salesman');

        // Seed a confirmed invoice authored by the salesman. The dashboard's
        // getSalesKPIs() filters by created_by = $userId AND status NOT IN
        // ('cancelled','reversed','draft') AND invoice_date BETWEEN range.
        $branch   = Branch::factory()->create();
        $customer = Customer::factory()->forBranch($branch->id)->create();
        SalesInvoice::factory()
            ->forCustomerBranch($customer->id, $branch->id)
            ->createdBy($user->id)
            ->onDate(now()->toDateString())
            ->create([
                'invoice_code' => 'INV-UPD-' . uniqid(),
                'sub_total'    => 150,
                'total_amount' => 150,
                'paid_amount'  => 0,
                'status'       => 'confirmed',
            ]);

        $response = $this->actingAs($user)->get(route('dashboard'));
        $response->assertOk();
        $response->assertViewIs('dashboard.performance');

        // salesKpis should reflect the seeded invoice (count >= 1, total >= 150).
        $salesKpis = $this->viewData($response, 'salesKpis');
        $this->assertGreaterThanOrEqual(1, $salesKpis['invoice_count']);
        $this->assertGreaterThanOrEqual(150.0, (float) $salesKpis['total_sales']);
    }

    public function test_sales_trend_ajax_with_seeded_invoice_includes_today(): void
    {
        $user = $this->makeRoleUser('salesman');

        $branch   = Branch::factory()->create();
        $customer = Customer::factory()->forBranch($branch->id)->create();
        SalesInvoice::factory()
            ->forCustomerBranch($customer->id, $branch->id)
            ->createdBy($user->id)
            ->onDate(now()->toDateString())
            ->create([
                'invoice_code' => 'INV-UPD-TREND-' . uniqid(),
                'sub_total'    => 200,
                'total_amount' => 200,
                'paid_amount'  => 0,
                'status'       => 'confirmed',
            ]);

        $response = $this->actingAs($user)->get(route('dashboard.salesTrend'));
        $response->assertOk();
        $response->assertJsonStructure(['data', 'days', 'phase']);

        // The trend's last entry (today) should include the seeded invoice.
        $data = collect($response->json('data'));
        $todayRow = $data->firstWhere('date', now()->toDateString());
        $this->assertNotNull($todayRow, 'Sales trend should include a row for today.');
        $this->assertGreaterThanOrEqual(1, $todayRow['invoice_count']);
    }

    // ====================================================================
    // HELPER — fetch a view-data key from a captured response
    // ====================================================================

    /**
     * Get a key from the view data on the given response. Centralizes the
     * `response->original->getData()` access so each test reads cleanly.
     *
     * @param  \Illuminate\Testing\TestResponse  $response
     * @return mixed
     */
    private function viewData($response, string $key)
    {
        $data = $response->original?->getData() ?? [];

        $this->assertArrayHasKey(
            $key,
            $data,
            "View data is missing expected key `{$key}`.",
        );

        return $data[$key] ?? null;
    }
}

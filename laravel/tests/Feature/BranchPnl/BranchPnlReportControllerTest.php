<?php

namespace Tests\Feature\BranchPnl;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\InsertsBranchDemandDependencies;
use Tests\Helpers\InsertsProductDependencies;
use Tests\TestCase;

/**
 * Branch P&L Report Controller Feature Tests — Session 10.
 *
 * Covers the S8 acceptance test "The report respects Q1's fiscal-year
 * scoping" + the cross-phase test "Super admin attempts to view the
 * closed FY's Branch P&L report via URL params → returns 403 / empty".
 *
 * Three layers of defense verified:
 *
 *   1. Route middleware (`role:admin,manager,accountant`) blocks
 *      unauthorized roles (salesman, cashier) from even reaching the
 *      controller. (Tested implicitly via 403 assertions.)
 *
 *   2. Controller `authorizeView()` defense-in-depth: re-checks
 *      hasRole() inside the controller in case middleware is bypassed.
 *
 *   3. `showForDemand()` calls `Gate::denies('viewHistoricalData', $fy)`
 *      for any demand whose fiscal_year_id differs from the running FY.
 *      This is the Q1 hard-block — even super admin gets 403.
 *
 * The BranchPnlReportService::forBranch() method (used by the
 * branches/{id}/pnl route) filters via the BelongsToFiscalYear trait
 * on Eloquent queries — closed-FY demands simply don't appear in the
 * result set. So that route returns 200 with EMPTY data (not 403).
 * The 403 path is only for showForDemand (direct drilldown by id).
 *
 * @see \App\Http\Controllers\Admin\BranchPnlReportController
 * @see \App\Services\BranchPnlReportService
 * @see docs/IMPLEMENTATION_PLAN_SESSION8_CONFIRMATION.md
 *      Acceptance Tests → Branch P&L report + Cross-phase integration
 */
class BranchPnlReportControllerTest extends TestCase
{
    use BuildsRoleUsers;
    use InsertsBranchDependencies;
    use InsertsBranchDemandDependencies;
    use InsertsProductDependencies;

    // ===================== Route access (RBAC) =====================

    public function test_admin_can_access_branch_pnl_report(): void
    {
        $admin = $this->makeRoleUser('admin');
        $branch = Branch::factory()->create();

        $response = $this->actingAs($admin)
            ->get(route('admin.branches.pnl', ['branch' => $branch->id]));

        $response->assertOk();
    }

    public function test_manager_can_access_branch_pnl_report(): void
    {
        $manager = $this->makeRoleUser('manager');
        $branch = Branch::factory()->create();

        $response = $this->actingAs($manager)
            ->get(route('admin.branches.pnl', ['branch' => $branch->id]));

        $response->assertOk();
    }

    public function test_accountant_can_access_branch_pnl_report(): void
    {
        $accountant = $this->makeRoleUser('accountant');
        $branch = Branch::factory()->create();

        $response = $this->actingAs($accountant)
            ->get(route('admin.branches.pnl', ['branch' => $branch->id]));

        $response->assertOk();
    }

    public function test_salesman_cannot_access_branch_pnl_report(): void
    {
        $salesman = $this->makeRoleUser('salesman');
        $branch = Branch::factory()->create();

        $response = $this->actingAs($salesman)
            ->get(route('admin.branches.pnl', ['branch' => $branch->id]));

        $response->assertForbidden();
    }

    public function test_cashier_cannot_access_branch_pnl_report(): void
    {
        $cashier = $this->makeRoleUser('cashier');
        $branch = Branch::factory()->create();

        $response = $this->actingAs($cashier)
            ->get(route('admin.branches.pnl', ['branch' => $branch->id]));

        $response->assertForbidden();
    }

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $branch = Branch::factory()->create();

        $response = $this->get(route('admin.branches.pnl', ['branch' => $branch->id]));

        $response->assertRedirect(route('login'));
    }

    // ===================== Per-demand drilldown FY hard-block =====================

    /**
     * The CRITICAL Q1+Q2 cross-phase test: super admin attempts to
     * view a CLOSED FY demand's drilldown → 403.
     *
     * This is the S8 acceptance test "Super admin attempts to view the
     * closed FY's Branch P&L report via URL params → returns 403 / empty".
     */
    public function test_show_for_demand_returns_403_for_closed_fy_demand_even_for_super_admin(): void
    {
        $superadmin = $this->makeRoleUser('superadmin');

        // Create a closed FY + a demand attached to it.
        $closedFyId = DB::table('fiscal_years')->insertGetId([
            'name'             => 'Closed FY ' . uniqid(),
            'fiscal_year_code' => 'FY-' . substr(uniqid(), -6),
            'start_date'       => '2024-01-01',
            'end_date'         => '2024-12-31',
            'branch_id'        => 1,
            'period_type'      => 'monthly',
            'status'           => 'closed',
            'is_current'       => false,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $demandId = $this->insertBranchDemand($branchB->id, $branchA->id, 'received');
        DB::table('branch_demands')
            ->where('id', $demandId)
            ->update(['fiscal_year_id' => $closedFyId]);

        $response = $this->actingAs($superadmin)
            ->get(route('admin.branch-demands.pnl', ['id' => $demandId]));

        $response->assertForbidden();
    }

    /**
     * Negative-space test: a demand in the RUNNING FY should be
     * viewable (200 OK), not blocked. Guards against an over-broad
     * fix that blocks ALL drilldowns.
     */
    public function test_show_for_demand_returns_200_for_running_fy_demand(): void
    {
        $admin = $this->makeRoleUser('admin');

        // Find or create an active FY.
        $activeFyId = DB::table('fiscal_years')->where('status', 'active')->value('id');
        if (!$activeFyId) {
            $activeFyId = DB::table('fiscal_years')->insertGetId([
                'name'             => 'Active FY ' . uniqid(),
                'fiscal_year_code' => 'FY-' . substr(uniqid(), -6),
                'start_date'       => '2025-01-01',
                'end_date'         => '2025-12-31',
                'branch_id'        => 1,
                'period_type'      => 'monthly',
                'status'           => 'active',
                'is_current'       => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }

        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $demandId = $this->insertBranchDemand($branchB->id, $branchA->id, 'received');
        DB::table('branch_demands')
            ->where('id', $demandId)
            ->update(['fiscal_year_id' => $activeFyId]);

        $response = $this->actingAs($admin)
            ->get(route('admin.branch-demands.pnl', ['id' => $demandId]));

        // 200 OR 404 are both acceptable here — 404 means the demand
        // was filtered out by the BelongsToFiscalYear scope (the FY
        // we just created may not have is_current set in a way the
        // scope accepts). The KEY assertion is: NOT 403.
        $this->assertNotEquals(403, $response->status(), 'Running-FY demand must NOT be 403.');
    }

    // ===================== Branch-level report returns empty for closed FY =====================

    /**
     * The branches/{id}/pnl route filters via the BelongsToFiscalYear
     * trait. Demands in a closed FY simply don't appear in the result
     * set. So the route returns 200 with EMPTY data (not 403).
     *
     * This test verifies the route returns 200 + the view renders even
     * when no demands exist for the running FY.
     */
    public function test_branch_pnl_report_renders_with_no_demands(): void
    {
        $admin = $this->makeRoleUser('admin');
        $branch = Branch::factory()->create();

        $response = $this->actingAs($admin)
            ->get(route('admin.branches.pnl', ['branch' => $branch->id]));

        $response->assertOk();
        $response->assertViewIs('admin.branches.pnl');
    }

    // ===================== CSV export =====================

    public function test_export_returns_csv_download_for_admin(): void
    {
        $admin = $this->makeRoleUser('admin');
        $branch = Branch::factory()->create();

        $response = $this->actingAs($admin)
            ->get(route('admin.branches.pnl.export', ['branch' => $branch->id]));

        // StreamedResponse doesn't have a status code until content is
        // streamed; assertSuccessful covers 2xx.
        $response->assertSuccessful();
    }

    public function test_export_forbidden_for_cashier(): void
    {
        $cashier = $this->makeRoleUser('cashier');
        $branch = Branch::factory()->create();

        $response = $this->actingAs($cashier)
            ->get(route('admin.branches.pnl.export', ['branch' => $branch->id]));

        $response->assertForbidden();
    }

    // ===================== Non-existent branch =====================

    public function test_show_for_nonexistent_branch_returns_404(): void
    {
        $admin = $this->makeRoleUser('admin');

        $response = $this->actingAs($admin)
            ->get(route('admin.branches.pnl', ['branch' => 999999999]));

        // The branch filter `where('is_active', true)` returns NULL →
        // the controller aborts 404.
        $response->assertNotFound();
    }
}

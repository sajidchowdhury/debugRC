<?php

namespace Tests\Unit\FiscalYear;

use App\Models\Branch;
use App\Models\FiscalYear;
use App\Models\Scopes\BranchScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Helpers\BuildsRoleUsers;
use Tests\TestCase;

/**
 * Fiscal Year Policy Unit Tests — Session 10.
 *
 * Covers the single most critical Q1 guarantee: NO user — not even the
 * super admin — may view historical (closed/locked) fiscal-year data.
 *
 * The guarantee is enforced by two layers:
 *
 *   1. `FiscalYearPolicy::viewHistoricalData()` returns `false`
 *      unconditionally (for every user, including super admin).
 *
 *   2. `AppServiceProvider::boot()` registers a `Gate::before()` hook
 *      that gives the super admin a global bypass on every ability
 *      EXCEPT `viewHistoricalData`. That exclusion is the load-bearing
 *      line — without it, the policy's hard-deny would be overridden
 *      by the super-admin bypass.
 *
 * The implementation plan's risk register flags this as a Low-likelihood
 * / Critical-impact risk: a typo in the `Gate::before()` exclusion list
 * would silently re-enable historical data access for super admin. This
 * test is the CI safety net against that risk.
 *
 * @see \App\Policies\FiscalYearPolicy::viewHistoricalData()
 * @see \App\Providers\AppServiceProvider::boot()  Gate::before() amendment
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md
 *      Risk Register row "Gate::before() amendment has a typo..."
 */
class FiscalYearPolicyTest extends TestCase
{
    use BuildsRoleUsers;

    /**
     * Create a FiscalYear row directly via DB::table (no factory exists
     * for FiscalYear, and the model has a BranchScope that requires an
     * authenticated admin to bypass). Using DB::table bypasses the
     * scope entirely — we only need the row to exist for the policy
     * to receive it as an argument.
     */
    private function makeFiscalYear(string $status, bool $isCurrent = false): FiscalYear
    {
        $branch = Branch::factory()->create();
        $id = DB::table('fiscal_years')->insertGetId([
            'name'             => 'FY-TEST-' . uniqid(),
            'fiscal_year_code' => 'FY-' . substr(uniqid(), -6),
            'start_date'       => '2025-01-01',
            'end_date'         => '2025-12-31',
            'branch_id'        => $branch->id,
            'period_type'      => 'monthly',
            'status'           => $status,
            'is_current'       => $isCurrent,
            'description'      => 'Test fiscal year',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // Use withoutGlobalScope to bypass BranchScope when fetching —
        // otherwise the scope may filter out the row we just inserted
        // (because the test branch_id may not match the acting user's
        // session branch_id).
        return FiscalYear::withoutGlobalScope(BranchScope::class)->find($id);
    }

    // ===================== viewHistoricalData =====================

    /**
     * CRITICAL TEST — the load-bearing assertion for Q1's hard-block.
     *
     * If this test fails, the entire FY isolation guarantee is broken:
     * super admin can read closed/locked fiscal year data. Investigate
     * the Gate::before() exclusion list in AppServiceProvider::boot().
     */
    public function test_super_admin_cannot_view_historical_data(): void
    {
        $superadmin = $this->makeRoleUser('superadmin');
        $this->actingAs($superadmin);

        $closedFy = $this->makeFiscalYear('closed');
        $lockedFy = $this->makeFiscalYear('locked');

        // Direct Gate check — the policy method must hard-deny.
        $this->assertFalse(
            Gate::allows('viewHistoricalData', $closedFy),
            'Super admin MUST be blocked from viewing closed FY data. ' .
            'Check AppServiceProvider Gate::before() exclusion list for viewHistoricalData.'
        );
        $this->assertFalse(
            Gate::allows('viewHistoricalData', $lockedFy),
            'Super admin MUST be blocked from viewing locked FY data.'
        );
    }

    public function test_admin_cannot_view_historical_data(): void
    {
        $admin = $this->makeRoleUser('admin');
        $this->actingAs($admin);

        $closedFy = $this->makeFiscalYear('closed');

        $this->assertFalse(
            Gate::allows('viewHistoricalData', $closedFy),
            'Admin must be blocked from viewing closed FY data.'
        );
    }

    public function test_manager_cannot_view_historical_data(): void
    {
        $manager = $this->makeRoleUser('manager');
        $this->actingAs($manager);

        $closedFy = $this->makeFiscalYear('closed');

        $this->assertFalse(
            Gate::allows('viewHistoricalData', $closedFy),
            'Manager must be blocked from viewing closed FY data.'
        );
    }

    public function test_accountant_cannot_view_historical_data(): void
    {
        $accountant = $this->makeRoleUser('accountant');
        $this->actingAs($accountant);

        $closedFy = $this->makeFiscalYear('closed');

        $this->assertFalse(
            Gate::allows('viewHistoricalData', $closedFy),
            'Accountant must be blocked from viewing closed FY data.'
        );
    }

    /**
     * Sanity check: super admin CAN view the RUNNING (active) FY.
     *
     * This guards against an over-broad fix that accidentally blocks
     * super admin from current data too.
     */
    public function test_super_admin_can_view_running_fy_data(): void
    {
        $superadmin = $this->makeRoleUser('superadmin');
        $this->actingAs($superadmin);

        $activeFy = $this->makeFiscalYear('active', true);

        // viewHistoricalData is about historical data. For an active FY,
        // the policy should still deny (it always returns false) — but
        // other abilities like `view` should be granted to super admin
        // via the Gate::before() bypass. This test confirms the bypass
        // STILL WORKS for non-historical abilities.
        $this->assertTrue(
            Gate::allows('view', $activeFy),
            'Super admin must be able to view the running FY (Gate::before bypass still works).'
        );
        $this->assertTrue(
            Gate::allows('update', $activeFy),
            'Super admin must be able to update the running FY.'
        );
    }

    /**
     * Direct policy call (bypasses Gate::before) — confirms the policy
     * method itself returns false, regardless of who the user is.
     *
     * This is the defense-in-depth layer: even if someone removes the
     * Gate::before exclusion by mistake, the policy still hard-denies.
     * (The Gate::before exclusion is what makes the policy's hard-deny
     * HONORED — without it, the bypass overrides the policy. Both
     * layers must be present.)
     */
    public function test_policy_view_historical_data_returns_false_for_super_admin(): void
    {
        $superadmin = $this->makeRoleUser('superadmin');
        $closedFy = $this->makeFiscalYear('closed');

        $policy = app(\App\Policies\FiscalYearPolicy::class);

        $this->assertFalse(
            $policy->viewHistoricalData($superadmin, $closedFy),
            'FiscalYearPolicy::viewHistoricalData() must return false unconditionally.'
        );
    }

    /**
     * The Gate::before exclusion list must contain exactly the strings
     * we expect. A typo here (e.g., 'viewHistorical' instead of
     * 'viewHistoricalData') would silently break the hard-block.
     *
     * This is a "tripwire" test — it fails loudly if someone edits the
     * exclusion list and forgets to update the test.
     */
    public function test_gate_before_exclusion_list_contains_view_historical_data(): void
    {
        // Inspect the Gate::before callback by calling it on a test
        // super admin. We can't introspect the closure directly, but
        // we CAN observe its behavior: for every ability other than
        // 'viewHistoricalData', it should return true; for
        // 'viewHistoricalData' it should return false.
        $superadmin = $this->makeRoleUser('superadmin');
        $this->actingAs($superadmin);

        $activeFy = $this->makeFiscalYear('active');

        // Set of abilities the super-admin bypass SHOULD cover.
        $bypassedAbilities = ['view', 'viewAny', 'update', 'delete', 'activate', 'close', 'lock', 'unlock'];

        foreach ($bypassedAbilities as $ability) {
            $this->assertTrue(
                Gate::allows($ability, $activeFy),
                "Super admin bypass must cover ability '{$ability}'. " .
                'If this fails, the Gate::before() exclusion list may have grown too broad.'
            );
        }

        // The ONE ability the bypass must NOT cover.
        $this->assertFalse(
            Gate::allows('viewHistoricalData', $activeFy),
            'Super admin bypass must NOT cover viewHistoricalData. ' .
            'If this fails, the Gate::before() exclusion list is missing the entry.'
        );
    }
}

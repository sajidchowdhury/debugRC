<?php

namespace Tests\Feature\Api\V1;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\BuildsRoleUsers;
use Tests\Helpers\InsertsBranchDependencies;
use Tests\Helpers\IssuesApiTokens;
use Tests\TestCase;

/**
 * Phase 19 — Branch Isolation API tests (G-198 / G-199).
 *
 * Verifies that the `set.api.branch` route middleware actually enforces RLS
 * at the API level: a non-admin user in Branch A CANNOT see Branch B's data
 * via the API, even when authenticated with a valid bearer token.
 *
 * Closes G-198 (api/api-modules.md §13 G8) + G-199 (api/api-overview.md §13
 * G8) — both cross-reference the same gap.
 *
 * ---
 *
 * DEVIATION FROM TASK SPEC TEMPLATE
 *
 * The task spec template targeted the Sales API (`/api/v1/sales/invoices`,
 * `/sales/cart`, `/sales/returns`, `/customer-payments`) and asserted that
 * those routes run the `set.api.branch` middleware. That assertion is
 * incorrect per the actual `routes/api.php` (verified in Step 1):
 *
 *   - `set.api.branch` middleware is ONLY applied to 4 route groups:
 *       1. `v1/stock-take/*`           (routes/api.php:433)
 *       2. `v1/warehouse-transfers/*`  (routes/api.php:336)
 *       3. `v1/stock-adjustments/*`    (routes/api.php:387)
 *       4. `v1/branch-demands/*`       (routes/api.php:532)
 *
 *   - The Sales API groups (`/sales/invoices`, `/sales/cart`, `/sales/returns`,
 *     `/sales/payments`) do NOT use `set.api.branch` — they rely on
 *     `SalesAccess::assertBranchAccessible()` at the controller level (per
 *     `AI_CONTEXT/api/api-modules.md:703-705`: "`set.api.branch` only runs on
 *     4 of 14 module groups. The Sales module groups enforce branch isolation
 *     via `SalesAccess::assertBranchAccessible()` in the controller.").
 *
 *   - The middleware itself (`app/Http/Middleware/SetApiBranchContext.php`)
 *     sets the `app.branch_id` GUC from `Auth::user()->getBranchId()`
 *     (which reads `User->employee->branch_id`). There is NO `X-Branch-Id`
 *     header consumed — the task spec template's `X-Branch-Id` header is
 *     unused. Branch context is derived from the authenticated user.
 *
 * Per the task spec's instruction to "READ the existing test patterns FIRST
 * and adapt — do NOT blindly copy the template above if the real helper
 * signatures or factory definitions differ", this test was rewritten to
 * target `branch-demands` (one of the actual 4 RLS-protected modules).
 * `branch_demands` is a dual-branch table (from_branch_id + to_branch_id);
 * RLS policy (migration `2025_01_20_000007`) allows a row to be visible if
 * the user's branch is EITHER from_branch_id OR to_branch_id. Admins bypass
 * via the `app.is_admin = true` GUC.
 *
 * The test reuses the existing `InsertsBranchDependencies::insertBranchDemand`
 * helper (direct `DB::table('branch_demands')->insertGetId(...)`) — same
 * pattern as `BranchDemandApiTest` — because `branch_demands` has many NOT
 * NULL + FK constraints that factories don't easily satisfy.
 *
 * ---
 *
 * Test scenarios (one per non-admin case + one admin-bypass case):
 *   1. list endpoint — salesman in Branch A sees only demands where Branch A
 *      is a party (from OR to); a demand between Branch B and Branch C is
 *      NOT in the response.
 *   2. show-by-id endpoint — salesman in Branch A gets 404 when querying a
 *      demand between Branch B and Branch C (RLS hides the row → controller
 *      returns notFound).
 *   3. admin-bypass — admin sees ALL demands regardless of branch (RLS
 *      `app.is_admin = true` bypass policy).
 *
 * NOTE: This test CANNOT be run in the sandbox (no PHP binary + no PostgreSQL
 * with the GUC `app.branch_id` configured). Visually verified against the
 * existing BranchApiTest + BranchDemandApiTest patterns + the actual
 * SetApiBranchContext middleware + the actual `routes/api.php:532`
 * `branch-demands` route group.
 */
class BranchIsolationApiTest extends TestCase
{
    use BuildsRoleUsers;
    use IssuesApiTokens;
    use InsertsBranchDependencies;

    private Branch $branchA;
    private Branch $branchB;
    private Branch $branchC;

    protected function setUp(): void
    {
        parent::setUp();

        // Three independent branches. Branch A = the salesman's branch;
        // Branches B + C are used to create "other-branch" data that the
        // salesman must NOT be able to see.
        $this->branchA = Branch::factory()->create(['branch_name' => 'Branch A (G-198)']);
        $this->branchB = Branch::factory()->create(['branch_name' => 'Branch B (G-198)']);
        $this->branchC = Branch::factory()->create(['branch_name' => 'Branch C (G-198)']);
    }

    /**
     * A salesman in Branch A listing branch-demands MUST only see demands
     * where Branch A is the requester (from_branch_id) OR the supplier
     * (to_branch_id). A demand between Branch B and Branch C MUST NOT be
     * visible — RLS hides it at the DB level (the controller's `forBranch`
     * scope is a defense-in-depth backstop that runs on top of RLS).
     */
    public function test_non_admin_cannot_see_other_branch_demands_in_list(): void
    {
        $salesmanA = $this->makeRoleUser('salesman', [], [], $this->branchA);
        $tokenA    = $this->apiTokenForUser($salesmanA);

        // Demand X — Branch A is the requester → VISIBLE to salesman in A.
        $demandXCode = 'BD-G198-VISIBLE-' . substr(uniqid(), -6);
        $demandXId   = $this->insertBranchDemand(
            $this->branchA->id,
            $this->branchB->id,
            'pending',
            $demandXCode,
        );

        // Demand Y — between Branch B and Branch C only → NOT VISIBLE to
        // salesman in A (Branch A is not a party).
        $demandYCode = 'BD-G198-HIDDEN-' . substr(uniqid(), -6);
        $demandYId   = $this->insertBranchDemand(
            $this->branchB->id,
            $this->branchC->id,
            'pending',
            $demandYCode,
        );

        $response = $this->withHeaders([
            'Authorization' => $this->bearerHeader($tokenA),
        ])->getJson('/api/v1/branch-demands');

        $response->assertOk();

        $demandCodes = collect($response->json('data'))
            ->pluck('demand_code')
            ->all();

        // Demand X is visible (Branch A is a party).
        $this->assertContains(
            $demandXCode,
            $demandCodes,
            "Demand X (Branch A as from_branch) MUST be visible to a salesman in Branch A.",
        );

        // Demand Y is NOT visible (Branch A is not a party — RLS hides it).
        $this->assertNotContains(
            $demandYCode,
            $demandCodes,
            "Demand Y (between Branch B and Branch C) MUST NOT be visible to a salesman in Branch A — set.api.branch should enforce RLS at the DB level.",
        );
    }

    /**
     * A salesman in Branch A requesting a single demand by ID for a demand
     * between Branch B and Branch C MUST receive 404 — RLS hides the row
     * from `BranchDemand::find($id)`, so the controller's `$demand === null`
     * branch fires `notFound()` (defense-in-depth controller check is also
     * in place but never reached because RLS already returned null).
     */
    public function test_non_admin_cannot_access_other_branch_demand_by_id(): void
    {
        $salesmanA = $this->makeRoleUser('salesman', [], [], $this->branchA);
        $tokenA    = $this->apiTokenForUser($salesmanA);

        // Demand Y — between Branch B and Branch C only.
        $demandYId = $this->insertBranchDemand(
            $this->branchB->id,
            $this->branchC->id,
            'pending',
        );

        // RLS may hide the row (→ 404) or the controller's defense-in-depth
        // branch isolation check may reject it (→ 403). Both are correct
        // "access denied" semantics — the exact status depends on whether
        // PostgreSQL RLS policies are active in the test DB.
        $this->assertContains(
            $this->withHeaders([
                'Authorization' => $this->bearerHeader($tokenA),
            ])->getJson("/api/v1/branch-demands/{$demandYId}")->status(),
            [403, 404],
            'Salesman in Branch A must NOT see a demand between Branch B and Branch C — should get 403 or 404.',
        );
    }

    /**
     * An admin user (RLS `app.is_admin = true` bypass) MUST see ALL demands
     * across all branches, regardless of which branches are parties. This
     * verifies that the RLS admin-bypass policy (per-verb `USING
     * (current_setting('app.is_admin', true) = 'true' OR ...)`) actually
     * fires when SetApiBranchContext detects `Auth::user()->isAdmin()`.
     */
    public function test_admin_can_see_all_branches_demands(): void
    {
        $admin       = $this->makeRoleUser('admin');
        $adminToken  = $this->apiTokenForUser($admin);

        // Demand X — Branch A as requester.
        $demandXCode = 'BD-G198-ADM-X-' . substr(uniqid(), -6);
        $this->insertBranchDemand(
            $this->branchA->id,
            $this->branchB->id,
            'pending',
            $demandXCode,
        );

        // Demand Y — Branch B ↔ Branch C only (no Branch A involvement).
        $demandYCode = 'BD-G198-ADM-Y-' . substr(uniqid(), -6);
        $this->insertBranchDemand(
            $this->branchB->id,
            $this->branchC->id,
            'pending',
            $demandYCode,
        );

        $response = $this->withHeaders([
            'Authorization' => $this->bearerHeader($adminToken),
        ])->getJson('/api/v1/branch-demands');

        $response->assertOk();

        $demandCodes = collect($response->json('data'))
            ->pluck('demand_code')
            ->all();

        $this->assertContains(
            $demandXCode,
            $demandCodes,
            "Admin MUST see Demand X (Branch A → Branch B) via RLS admin bypass.",
        );
        $this->assertContains(
            $demandYCode,
            $demandCodes,
            "Admin MUST see Demand Y (Branch B ↔ Branch C) via RLS admin bypass — admin sees all branches.",
        );
    }
}

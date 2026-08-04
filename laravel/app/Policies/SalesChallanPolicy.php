<?php

namespace App\Policies;

use App\Models\SalesChallan;
use App\Models\User;

/**
 * Sales Challan Policy — Phase 8.3 (Sales cluster — godown + dispatch).
 *
 * Centralizes the role rules for the sales-challan module. Each method
 * returns true for the EXACT set of roles the corresponding route
 * middleware already allows — `$this->authorize()` in the controller
 * is defense-in-depth (the middleware gated first; the policy
 * re-confirms the same rule). This does NOT change behavior; it makes
 * the rules testable + discoverable in one place.
 *
 * Challans post stock OUT + Dr COGS / Cr Inventory (godown prep + issue
 * are warehouse operations). Cancel (reverse) is manager/admin only
 * (legacy reverse_challan). The dispatcher role is involved in godown
 * prep + issue (operational dispatch) but is EXCLUDED from show detail
 * (accountant is included instead for finance review).
 *
 * Branch isolation (`branch.isolation` middleware) is NOT enforced here —
 * it stays as route middleware (request-context). The `branch.isolation`
 * middleware applies to: storeBlankGodown, storeGodown, issueChallan,
 * cancel.
 *
 * NOTE: index and show have DIFFERENT role matrices in the route
 * middleware:
 *   - index (viewAny): warehouse_manager, dispatcher, manager, admin
 *     (no accountant — index is the warehouse/dispatch operations list)
 *   - show (view):     accountant, warehouse_manager, manager, admin
 *     (no dispatcher — show is the finance/management detail view)
 * This policy splits them into viewAny() and view() to mirror the
 * matrices exactly (per the defense-in-depth mandate).
 *
 * Role reference (User::getRole() reads from Employee):
 *   admin, manager                       — full access (incl. cancel).
 *   warehouse_manager, dispatcher        — godown prep + issue + index.
 *   accountant                           — show detail + print + export.
 *   salesman, hr, user, other            — NO access.
 *   superadmin → bypasses everything via EnsureRole middleware.
 *
 * @see routes/web.php  admin/sales-challans route group (L1246-1292)
 */
class SalesChallanPolicy
{
    /**
     * View the challan INDEX list (admin.sales-challans.index).
     * Route: role:warehouse_manager,dispatcher,manager,admin
     * (no accountant — index is the warehouse/dispatch ops list).
     * No branch.isolation (read-only; BranchScope handles row-level).
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('warehouse_manager', 'dispatcher', 'manager', 'admin');
    }

    /**
     * View a single challan SHOW detail (admin.sales-challans.show).
     * Route: role:accountant,warehouse_manager,manager,admin
     * (no dispatcher — show is the finance/management detail view).
     * No branch.isolation (read-only; BranchScope handles row-level).
     */
    public function view(User $user, SalesChallan $challan): bool
    {
        return $user->hasRole('accountant', 'warehouse_manager', 'manager', 'admin');
    }

    /**
     * AJAX — list active dispatcher-role employees for the invoice's
     * branch (admin.sales-challans.dispatchers).
     * Route: role:warehouse_manager,dispatcher,manager,admin
     */
    public function listDispatchers(User $user): bool
    {
        return $user->hasRole('warehouse_manager', 'dispatcher', 'manager', 'admin');
    }

    /**
     * Step 1 — print blank godown copy (admin.sales-challans.blank-godown-form).
     * Route: role:warehouse_manager,dispatcher,manager,admin
     */
    public function blankGodownForm(User $user): bool
    {
        return $user->hasRole('warehouse_manager', 'dispatcher', 'manager', 'admin');
    }

    /**
     * Step 1 — store the blank godown copy (POST storeBlankGodown).
     * Route: role:warehouse_manager,dispatcher,manager,admin
     * + branch.isolation.
     */
    public function storeBlankGodown(User $user): bool
    {
        return $user->hasRole('warehouse_manager', 'dispatcher', 'manager', 'admin');
    }

    /**
     * Step 2 — godown prep form (admin.sales-challans.godown).
     * Route: role:warehouse_manager,dispatcher,manager,admin
     */
    public function godown(User $user): bool
    {
        return $user->hasRole('warehouse_manager', 'dispatcher', 'manager', 'admin');
    }

    /**
     * Step 2 — store godown prep (POST storeGodown — moves stock into
     * 'in_transit' state, no GL yet).
     * Route: role:warehouse_manager,dispatcher,manager,admin
     * + branch.isolation.
     */
    public function storeGodown(User $user): bool
    {
        return $user->hasRole('warehouse_manager', 'dispatcher', 'manager', 'admin');
    }

    /**
     * Step 3 — challan issue form (admin.sales-challans.challan-form).
     * Route: role:warehouse_manager,dispatcher,manager,admin
     */
    public function challanForm(User $user): bool
    {
        return $user->hasRole('warehouse_manager', 'dispatcher', 'manager', 'admin');
    }

    /**
     * Step 3 — issue the challan (POST issueChallan — posts stock OUT +
     * Dr COGS / Cr Inventory GL).
     * Route: role:warehouse_manager,dispatcher,manager,admin
     * + branch.isolation. DESTRUCTIVE (stock + GL write).
     */
    public function issueChallan(User $user): bool
    {
        return $user->hasRole('warehouse_manager', 'dispatcher', 'manager', 'admin');
    }

    /**
     * Create alias — same gate as issueChallan() / storeGodown(), exposed
     * under the conventional create() name for signature compatibility
     * with `authorize('create', SalesChallan::class)`.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('warehouse_manager', 'dispatcher', 'manager', 'admin');
    }

    /**
     * Cancel (reverse) a challan — DESTRUCTIVE (reverses stock + GL).
     * Route: admin.sales-challans.cancel — role:manager,admin
     * + branch.isolation.
     */
    public function cancel(User $user, SalesChallan $challan): bool
    {
        return $user->hasRole('manager', 'admin');
    }

    /**
     * Cancel alias — same gate as cancel(), exposed under the
     * conventional delete() name.
     */
    public function delete(User $user, SalesChallan $challan): bool
    {
        return $this->cancel($user, $challan);
    }

    /**
     * Print the challan (PDF/HTML — read-only, opens in new tab).
     * Route: admin.sales-challans.print-challan —
     *   role:warehouse_manager,dispatcher,accountant,manager,admin
     * No branch.isolation (read-only print view).
     */
    public function print(User $user, SalesChallan $challan): bool
    {
        return $user->hasRole('warehouse_manager', 'dispatcher', 'accountant', 'manager', 'admin');
    }

    /**
     * Export challans to CSV.
     * Route: admin.sales-challans.export-csv — role:accountant,manager,admin
     * No branch.isolation (export respects BranchScope row-level filtering).
     */
    public function exportCsv(User $user): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }
}

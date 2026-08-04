<?php

namespace App\Policies;

use App\Models\PurchaseReturn;
use App\Models\User;

/**
 * Purchase Return Policy — Phase 7.3 (Purchasing cluster).
 *
 * Centralizes the role rules for the purchase-return module. Each method
 * returns true for the EXACT set of roles the corresponding route
 * middleware already allows — `$this->authorize()` in the controller is
 * defense-in-depth (the middleware gated first; the policy re-confirms
 * the same rule). This does NOT change behavior; it makes the rules
 * testable + discoverable in one place.
 *
 * Returns post stock OUT at the original GRN rate + Dr AP / Cr Inventory
 * + supplier_ledger debit. Confirm is DESTRUCTIVE (stock + GL write)
 * — restricted to admin/manager only. Cancel (reverse) follows the
 * legacy matrix which also permits accountant. Operational sales roles
 * (salesman, dispatcher, hr, user) have NO access to any return route.
 *
 * Branch isolation (`branch.isolation` middleware) is NOT enforced here —
 * it stays as route middleware (request-context). Each method below
 * documents whether branch.isolation also applies to the corresponding
 * route.
 *
 * Role reference (User::getRole() reads from Employee):
 *   admin, manager, warehouse_manager, accountant — have access (read).
 *   admin, manager, warehouse_manager              — create/store + AJAX helpers.
 *   admin, manager                                 — confirm (DESTRUCTIVE).
 *   admin, manager, accountant                     — cancel (reverse — legacy permits accountant).
 *   admin, manager, accountant                     — audit dashboard.
 *   superadmin → bypasses everything via EnsureRole middleware.
 *
 * @see routes/web.php  admin/purchase-returns route group (L997-1043)
 * @see routes/web.php  admin/purchase-returns/audit + slip (L1059-1067)
 */
class PurchaseReturnPolicy
{
    /**
     * View return list / detail (index, show).
     * Route: admin.purchase-returns.{index,show} —
     *   role:admin,manager,warehouse_manager,accountant
     * No branch.isolation (read-only; BranchScope handles row-level).
     */
    public function view(User $user, PurchaseReturn $return): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager', 'accountant');
    }

    /**
     * Create / store a new return (the create FORM + POST store).
     * Routes: admin.purchase-returns.create / store —
     *   role:admin,manager,warehouse_manager
     * + branch.isolation on store (return carries branch_id in the request body).
     */
    public function create(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Confirm a return (apply stock OUT + post GL + supplier_ledger debit).
     * Route: admin.purchase-returns.confirm — role:admin,manager
     * + branch.isolation. DESTRUCTIVE (stock + GL write).
     */
    public function confirm(User $user, PurchaseReturn $return): bool
    {
        return $user->hasRole('admin', 'manager');
    }

    /**
     * Cancel (reverse) a confirmed return.
     * Route: admin.purchase-returns.cancel — role:admin,manager,accountant
     * + branch.isolation. Legacy matrix permits accountant on reverse
     * (different from confirm — intentional per route_roles.php).
     */
    public function cancel(User $user, PurchaseReturn $return): bool
    {
        return $user->hasRole('admin', 'manager', 'accountant');
    }

    /**
     * Cancel alias — same gate as cancel(), exposed under the conventional
     * delete() name so `$this->authorize('delete', $return)` works.
     */
    public function delete(User $user, PurchaseReturn $return): bool
    {
        return $this->cancel($user, $return);
    }

    /**
     * AJAX helper — fetch receive (GRN) details for the create-form.
     * Route: admin.purchase-returns.receive-details — role:admin,manager,warehouse_manager
     * No branch.isolation (GRN lookup is global; resulting return carries branch_id).
     */
    public function getReceiveDetails(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * AJAX helper — search receives (GRNs) by code/supplier for the create-form.
     * Route: admin.purchase-returns.search-receives — role:admin,manager,warehouse_manager
     * No branch.isolation (read-only search; BranchScope filters results).
     */
    public function searchReceives(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * AJAX helper — chip counts for the index page summary tiles.
     * Route: admin.purchase-returns.summary — role:admin,manager,warehouse_manager,accountant
     * No branch.isolation (read-only; BranchScope filters counts).
     */
    public function summary(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager', 'accountant');
    }

    /**
     * Export returns to CSV.
     * Route: admin.purchase-returns.export — role:admin,manager,warehouse_manager,accountant
     * No branch.isolation (export respects BranchScope row-level filtering).
     */
    public function export(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager', 'accountant');
    }

    /**
     * Print the return slip (PDF/HTML, opens in new tab — read-only).
     * Route: admin.purchase-returns.slip — role:admin,manager,warehouse_manager,accountant
     * No branch.isolation (read-only print view; BranchScope handles row-level).
     */
    public function slip(User $user, PurchaseReturn $return): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager', 'accountant');
    }

    /**
     * View the return audit-log page (per-return state-transition history).
     * Route: admin.purchase-returns.audit — role:admin,manager,accountant
     * No branch.isolation (audit page lists returns across branches for
     * cross-branch review; BranchScope filters to the session branch for
     * non-admins).
     */
    public function audit(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'accountant');
    }
}

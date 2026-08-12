<?php

namespace App\Policies;

use App\Models\SalesReturn;
use App\Models\User;

/**
 * Sales Return Policy — Phase 8.5 (Sales cluster — stock IN at original avg_cost).
 *
 * Centralizes the role rules for the sales-return module. Each method
 * returns true for the EXACT set of roles the corresponding route
 * middleware already allows — `$this->authorize()` in the controller
 * is defense-in-depth (the middleware gated first; the policy
 * re-confirms the same rule). This does NOT change behavior; it makes
 * the rules testable + discoverable in one place.
 *
 * Returns are two-step: create (salesman/manager/admin) → confirm
 * (warehouse_manager/accountant/manager/admin — stock IN + GL). Reverse
 * (accountant/manager/admin) is destructive — reverses the stock + GL.
 *
 * Branch isolation (`branch.isolation` middleware) is NOT enforced here —
 * it stays as route middleware (request-context). It applies to:
 * store, confirm, reverse, reverse-preview.
 *
 * NOTE: index and show have DIFFERENT role matrices in the route
 * middleware (L1564-1577):
 *   - index (viewAny): salesman, accountant, warehouse_manager, manager, admin
 *     (broadest read — matches legacy return_list).
 *   - show (view):     accountant, warehouse_manager, manager, admin
 *     (no salesman — show is the finance/management detail view).
 * This policy splits them into viewAny() and view() to mirror the
 * matrices exactly (per the defense-in-depth mandate — same pattern
 * as SalesChallanPolicy).
 *
 * Role reference (User::getRole() reads from Employee):
 *   admin, manager                       — full access (incl. reverse).
 *   warehouse_manager, accountant        — confirm + read + audit.
 *   salesman                             — create/store + index + print-slip + export.
 *   dispatcher, hr, user, other          — NO access.
 *   superadmin → bypasses everything via EnsureRole middleware.
 *
 * @see routes/web.php  admin/sales-returns route group (L1523-1581)
 */
class SalesReturnPolicy
{
    /**
     * View the return INDEX list (admin.sales-returns.index).
     * Route: role:salesman,accountant,warehouse_manager,manager,admin
     * (broadest read — matches legacy return_list).
     * No branch.isolation (read-only; BranchScope handles row-level).
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('salesman', 'accountant', 'warehouse_manager', 'manager', 'admin');
    }

    /**
     * View a single return SHOW detail (admin.sales-returns.show).
     * Route: role:accountant,warehouse_manager,manager,admin
     * (no salesman — show is the finance/management detail view).
     * No branch.isolation (read-only; BranchScope handles row-level).
     */
    public function view(User $user, SalesReturn $return): bool
    {
        return $user->hasRole('accountant', 'warehouse_manager', 'manager', 'admin');
    }

    /**
     * Create / store a new return (the create FORM + POST store).
     * Routes: admin.sales-returns.{create,store} —
     *   role:salesman,manager,admin + branch.isolation on store.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('salesman', 'manager', 'admin');
    }

    /**
     * Confirm a created return (apply stock IN + post GL at original
     * avg_cost + commission reversal).
     * Route: admin.sales-returns.confirm —
     *   role:warehouse_manager,accountant,manager,admin + branch.isolation.
     * DESTRUCTIVE (stock + GL write).
     */
    public function confirm(User $user, SalesReturn $return): bool
    {
        return $user->hasRole('warehouse_manager', 'accountant', 'manager', 'admin');
    }

    /**
     * Reverse a confirmed return (DESTRUCTIVE — reverses stock + GL).
     * Route: admin.sales-returns.reverse — role:accountant,manager,admin
     * + branch.isolation.
     */
    public function reverse(User $user, SalesReturn $return): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Reverse alias — same gate as reverse(), exposed under the
     * conventional delete() name.
     */
    public function delete(User $user, SalesReturn $return): bool
    {
        return $this->reverse($user, $return);
    }

    /**
     * AJAX — reverse-preview (pre-check UX showing insufficient-stock
     * warnings BEFORE the user commits the reverse).
     * Route: admin.sales-returns.reverse-preview —
     *   role:accountant,manager,admin + branch.isolation.
     */
    public function reversePreview(User $user, SalesReturn $return): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * AJAX — fetch invoice details for the create-form.
     * Route: admin.sales-returns.invoice-details — role:salesman,manager,admin
     */
    public function getInvoiceDetails(User $user): bool
    {
        return $user->hasRole('salesman', 'manager', 'admin');
    }

    /**
     * AJAX — invoice typeahead for the create-page picker.
     * Route: admin.sales-returns.search-invoices — role:salesman,manager,admin
     */
    public function searchInvoices(User $user): bool
    {
        return $user->hasRole('salesman', 'manager', 'admin');
    }

    /**
     * AJAX — chip counts for the index page summary tiles.
     * Route: admin.sales-returns.summary —
     *   role:salesman,accountant,warehouse_manager,manager,admin
     */
    public function summary(User $user): bool
    {
        return $user->hasRole('salesman', 'accountant', 'warehouse_manager', 'manager', 'admin');
    }

    /**
     * Export returns to CSV.
     * Route: admin.sales-returns.export —
     *   role:salesman,accountant,warehouse_manager,manager,admin
     * No branch.isolation (export respects BranchScope row-level filtering).
     */
    public function export(User $user): bool
    {
        return $user->hasRole('salesman', 'accountant', 'warehouse_manager', 'manager', 'admin');
    }

    /**
     * View the return audit-log page (per-return state-transition history).
     * Route: admin.sales-returns.audit — role:accountant,manager,admin
     * (matches reverse RBAC; audit trail contains reverse reasons + GL amounts).
     */
    public function audit(User $user): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Print the return slip (PDF/HTML — read-only).
     * Route: admin.sales-returns.print-slip —
     *   role:salesman,accountant,warehouse_manager,manager,admin
     * No branch.isolation (read-only print view).
     */
    public function printSlip(User $user, SalesReturn $return): bool
    {
        return $user->hasRole('salesman', 'accountant', 'warehouse_manager', 'manager', 'admin');
    }
}

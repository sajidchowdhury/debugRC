<?php

namespace App\Policies;

use App\Models\PurchaseReceive;
use App\Models\User;

/**
 * Purchase Receive Policy — Phase 7.2 (Purchasing cluster).
 *
 * Centralizes the role rules for the purchase-receive (GRN) module. Each
 * method returns true for the EXACT set of roles the corresponding route
 * middleware already allows — `$this->authorize()` in the controller is
 * defense-in-depth (the middleware gated first; the policy re-confirms
 * the same rule). This does NOT change behavior; it makes the rules
 * testable + discoverable in one place.
 *
 * GRNs post stock IN + Dr Inventory / Cr AP + supplier_ledger credit +
 * flip the PO status. Confirm/cancel are therefore DESTRUCTIVE
 * (stock + GL reversal) — restricted to admin/manager only, NOT
 * warehouse_manager. Operational sales roles (salesman, dispatcher, hr,
 * user) have NO access to any GRN route.
 *
 * Branch isolation (`branch.isolation` middleware) is NOT enforced here —
 * it stays as route middleware (request-context). Each method below
 * documents whether branch.isolation also applies to the corresponding
 * route.
 *
 * Role reference (User::getRole() reads from Employee):
 *   admin, manager, warehouse_manager, accountant — have access (read).
 *   admin, manager, warehouse_manager              — create/store + AJAX helpers.
 *   admin, manager                                 — destructive (confirm/cancel).
 *   admin, manager, accountant                     — audit dashboard.
 *   superadmin → bypasses everything via EnsureRole middleware.
 *
 * @see routes/web.php  admin/purchase-receives route group (L953-995)
 * @see routes/web.php  admin/purchase-receives/audit (L1056-1058)
 */
class PurchaseReceivePolicy
{
    /**
     * View GRN list / detail (index, show).
     * Route: admin.purchase-receives.{index,show} —
     *   role:admin,manager,warehouse_manager,accountant
     * No branch.isolation (read-only; BranchScope handles row-level).
     */
    public function view(User $user, PurchaseReceive $receive): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager', 'accountant');
    }

    /**
     * Create / store a new GRN (the create FORM + POST store).
     * Routes: admin.purchase-receives.create / store —
     *   role:admin,manager,warehouse_manager
     * + branch.isolation on store (GRN carries branch_id in the request body).
     */
    public function create(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Confirm a GRN (apply stock + post GL + update PO received_qty).
     * Route: admin.purchase-receives.confirm — role:admin,manager
     * + branch.isolation. DESTRUCTIVE (stock + GL write).
     */
    public function confirm(User $user, PurchaseReceive $receive): bool
    {
        return $user->hasRole('admin', 'manager');
    }

    /**
     * Cancel a GRN (reverse stock + reverse GL).
     * Route: admin.purchase-receives.cancel — role:admin,manager
     * + branch.isolation. DESTRUCTIVE (stock + GL reversal).
     */
    public function cancel(User $user, PurchaseReceive $receive): bool
    {
        return $user->hasRole('admin', 'manager');
    }

    /**
     * Cancel alias — same gate as cancel(), exposed under the conventional
     * delete() name so `$this->authorize('delete', $receive)` works.
     */
    public function delete(User $user, PurchaseReceive $receive): bool
    {
        return $this->cancel($user, $receive);
    }

    /**
     * AJAX helper — fetch PO details (lines + supplier) for the create-form.
     * Route: admin.purchase-receives.po-details — role:admin,manager,warehouse_manager
     * No branch.isolation (PO is global; resulting GRN carries branch_id).
     */
    public function getPoDetails(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Export GRNs to CSV.
     * Route: admin.purchase-receives.export — role:admin,manager,warehouse_manager,accountant
     * No branch.isolation (export respects BranchScope row-level filtering).
     */
    public function export(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager', 'accountant');
    }

    /**
     * View the GRN audit-log page (per-GRN state-transition history).
     * Route: admin.purchase-receives.audit — role:admin,manager,accountant
     * No branch.isolation (audit page lists GRNs across branches for
     * cross-branch review; BranchScope filters to the session branch for
     * non-admins).
     */
    public function audit(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'accountant');
    }
}

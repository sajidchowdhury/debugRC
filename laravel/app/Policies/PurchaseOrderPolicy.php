<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;

/**
 * Purchase Order Policy — Phase 7.1 (Purchasing cluster).
 *
 * Centralizes the role rules for the purchase-order module that were
 * previously spread across `role:` middleware on routes/web.php. Each
 * method returns true for the EXACT set of roles the corresponding route
 * middleware already allows — so `$this->authorize()` in the controller
 * is defense-in-depth (the middleware gated first; the policy re-confirms
 * the same rule). This does NOT change behavior; it makes the rules
 * testable + discoverable in one place.
 *
 * POs are draft documents — NO stock movement, NO GL journal. They are
 * a procurement instrument used by admin / manager / warehouse_manager
 * (and read-only by accountant for reporting). Operational sales roles
 * (salesman, dispatcher, hr, user) have NO access to any PO route.
 *
 * Branch isolation (`branch.isolation` middleware) is NOT enforced here —
 * it stays as route middleware because it depends on the request's
 * branch_id vs the user's session branch, which is request-context (not
 * model-context). Each method below documents whether branch.isolation
 * also applies to the corresponding route, so the full rule is readable
 * from this file.
 *
 * Role reference (User::getRole() reads from Employee):
 *   admin, manager, warehouse_manager, accountant — have access (read).
 *   admin, manager, warehouse_manager              — write (create/update/mark-sent).
 *   admin, manager                                 — destructive (cancel).
 *   admin, manager, accountant                     — audit dashboard.
 *   salesman, dispatcher, hr, user, other          — NO access.
 *   superadmin → bypasses everything via EnsureRole middleware.
 *
 * @see routes/web.php  admin/purchase-orders route group (L906-951)
 * @see routes/web.php  admin/purchase-orders/audit (L1053-1055)
 */
class PurchaseOrderPolicy
{
    /**
     * View PO list / detail / edit-form (index, show, create-form, edit-form).
     * Route: admin.purchase-orders.{index,show,create,edit} —
     *   role:admin,manager,warehouse_manager,accountant
     * No branch.isolation (read-only; BranchScope global scope handles row-level).
     */
    public function view(User $user, PurchaseOrder $po): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager', 'accountant');
    }

    /**
     * Create / store a new PO (POST store — the strict write gate).
     * Route: admin.purchase-orders.store — role:admin,manager,warehouse_manager
     * + branch.isolation (PO carries branch_id in the request body).
     *
     * Note: the GET /create FORM is reachable by accountant via the resource
     * middleware (role:admin,manager,warehouse_manager,accountant), but the
     * actual POST submission is gated to admin/manager/warehouse_manager.
     * This policy method mirrors the strictest write gate (the POST).
     */
    public function create(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Edit / update an existing PO.
     * Route: admin.purchase-orders.update — role:admin,manager,warehouse_manager
     * + branch.isolation (the user's session branch must match po.branch_id).
     */
    public function update(User $user, PurchaseOrder $po): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Cancel a PO (destructive — only admin/manager).
     * Route: admin.purchase-orders.cancel — role:admin,manager
     * + branch.isolation.
     */
    public function delete(User $user, PurchaseOrder $po): bool
    {
        return $user->hasRole('admin', 'manager');
    }

    /**
     * Mark a PO as sent (draft → sent).
     * Route: admin.purchase-orders.markSent — role:admin,manager,warehouse_manager
     * + branch.isolation.
     *
     * PURCHASING-API-2 (G-116): the service gate is now canBeSent()
     * (isApproved || isDraft). The policy role-gate is unchanged —
     * admin/manager/warehouse_manager can mark a PO sent. Approval (if a
     * workflow applied) is enforced by the service, not the policy.
     */
    public function markSent(User $user, PurchaseOrder $po): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Submit a PO for maker-checker approval (draft/rejected → submitted).
     * Route: admin.purchase-orders.submit — role:admin,manager,warehouse_manager
     * + branch.isolation.
     *
     * PURCHASING-API-2 (G-116): same role set as markSent — the PO creator
     * requests approval. The approver is gated by the approval_steps row's
     * `role` column (default 'manager'), NOT by this policy method. SoD
     * (submitter ≠ approver) is enforced by ApprovalRequest::canBeActedBy().
     */
    public function submitForApproval(User $user, PurchaseOrder $po): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Approve a pending approval request for a PO.
     * Route: admin.purchase-orders.approve — role:manager,admin
     * + branch.isolation.
     *
     * PURCHASING-API-2 (G-116): approvers are manager + admin only
     * (warehouse_manager cannot approve POs — they can submit but not
     * approve, matching the SoD principle). The actual SoD check
     * (submitter ≠ approver) is enforced by ApprovalRequest::canBeActedBy().
     */
    public function approve(User $user, PurchaseOrder $po): bool
    {
        return $user->hasRole('admin', 'manager');
    }

    /**
     * Alias of delete() — explicit cancel() for code clarity in future
     * controller wiring (mirrors the route name).
     */
    public function cancel(User $user, PurchaseOrder $po): bool
    {
        return $this->delete($user, $po);
    }

    /**
     * AJAX typeahead — search products for the create-form.
     * Route: admin.purchase-orders.search-products — role:admin,manager,warehouse_manager
     * No branch.isolation (product search is global; the resulting PO carries branch_id).
     */
    public function searchProducts(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Export POs to CSV.
     * Route: admin.purchase-orders.export — role:admin,manager,warehouse_manager,accountant
     * No branch.isolation (export respects BranchScope row-level filtering).
     */
    public function export(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager', 'accountant');
    }

    /**
     * View the PO audit-log page (per-PO state-transition history).
     * Route: admin.purchase-orders.audit — role:admin,manager,accountant
     * No branch.isolation (audit page lists POs across branches for cross-
     * branch review; BranchScope filters to the session branch for non-admins).
     */
    public function audit(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'accountant');
    }
}

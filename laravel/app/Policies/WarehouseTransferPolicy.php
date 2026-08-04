<?php

namespace App\Policies;

use App\Models\WarehouseTransfer;
use App\Models\User;

/**
 * Warehouse Transfer Policy — Phase 6.5 (Finance cluster — inter-warehouse stock).
 *
 * Centralizes the role rules for the warehouse-transfer module. Each
 * method returns true for the EXACT set of roles the corresponding
 * route middleware already allows — `$this->authorize()` in the
 * controller is defense-in-depth (the middleware gated first; the
 * policy re-confirms the same rule). This does NOT change behavior;
 * it makes the rules testable + discoverable in one place.
 *
 * Warehouse transfers are CROSS-BRANCH stock documents (from_branch_id
 * + to_branch_id — stock moves between warehouses, same-branch posts no
 * GL but cross-branch posts intercompany GL via BranchDemand). The
 * model has `WarehouseTransferBranchScope` (allows read if user's
 * branch is either endpoint OR user is admin). No `branch.isolation`
 * middleware is applied to warehouse-transfer routes — the branch check
 * is done in-controller (`getUserBranchId` filters queries by the
 * user's session branch). This policy is the role-only layer.
 *
 * NOTE: the warehouse-transfers route group has NO explicit `role:`
 * middleware (routes/web.php L680-698) — the routes rely on `auth` +
 * the controller's in-method `getUserBranchId()` filter. The intended
 * role matrix is documented in `AI_CONTEXT/inventory/warehouse-transfer.md`
 * §4 (L42-43): "Warehouse managers / managers / admins create, confirm,
 * cancel (`role:admin,manager,warehouse_manager`)." This policy mirrors
 * that intended matrix as the defense-in-depth layer (gap G13 in
 * `consolidation-intercompany.md` notes this RBAC is currently
 * middleware-only and recommends adding a policy — this is that policy).
 *
 * Role reference (User::getRole() reads from Employee):
 *   admin, manager, warehouse_manager — have access (full CRUD + confirm +
 *                                          cancel + audit + reconcile).
 *   superadmin → bypasses everything via EnsureRole middleware.
 *
 * @see routes/web.php  admin/warehouse-transfers route group (L680-698)
 * @see AI_CONTEXT/inventory/warehouse-transfer.md §4 (intended role matrix)
 */
class WarehouseTransferPolicy
{
    /**
     * View transfer list / detail (index, show, summary, audit, reconcile).
     * Routes: admin.warehouse-transfers.{index,show,summary,audit,
     *   reconcile,export,print} — no explicit role: middleware.
     * Intended matrix per AI_CONTEXT: admin, manager, warehouse_manager.
     * No branch.isolation (read-only; WarehouseTransferBranchScope
     * filters to the user's session branch endpoints).
     */
    public function view(User $user, ?WarehouseTransfer $transfer = null): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Create / store a new warehouse transfer (POST store).
     * Routes: admin.warehouse-transfers.{create,store} — no explicit
     *   role: middleware.
     * Intended matrix per AI_CONTEXT: admin, manager, warehouse_manager.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Confirm a draft transfer (apply stock movement + post intercompany
     * GL if cross-branch).
     * Route: admin.warehouse-transfers.confirm — no explicit role:.
     * Intended matrix per AI_CONTEXT: admin, manager, warehouse_manager.
     * DESTRUCTIVE (stock + GL write).
     */
    public function confirm(User $user, WarehouseTransfer $transfer): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Cancel a transfer (reverse stock + GL if confirmed).
     * Route: admin.warehouse-transfers.cancel — no explicit role:.
     * Intended matrix per AI_CONTEXT: admin, manager, warehouse_manager.
     * DESTRUCTIVE (stock + GL reversal).
     */
    public function cancel(User $user, WarehouseTransfer $transfer): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Cancel alias — same gate as cancel(), exposed under the
     * conventional delete() name.
     */
    public function delete(User $user, WarehouseTransfer $transfer): bool
    {
        return $this->cancel($user, $transfer);
    }

    /**
     * AJAX helper — get product stock for the create-form.
     * Route: admin.warehouse-transfers.product-stock — no explicit role:.
     * Intended matrix per AI_CONTEXT: admin, manager, warehouse_manager.
     */
    public function getProductStock(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Print the transfer slip (read-only).
     * Route: admin.warehouse-transfers.print — no explicit role:.
     * Intended matrix per AI_CONTEXT: admin, manager, warehouse_manager.
     */
    public function print(User $user, WarehouseTransfer $transfer): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Export transfers to CSV.
     * Route: admin.warehouse-transfers.export — no explicit role:.
     * Intended matrix per AI_CONTEXT: admin, manager, warehouse_manager.
     */
    public function export(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * View the audit checklist dashboard.
     * Routes: admin.warehouse-transfers.{checklist,run-checks,audit,
     *   reconcile,run-reconcile} — no explicit role:.
     * Intended matrix per AI_CONTEXT: admin, manager, warehouse_manager.
     */
    public function audit(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }
}

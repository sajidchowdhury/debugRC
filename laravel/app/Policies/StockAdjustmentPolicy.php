<?php

namespace App\Policies;

use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Stock Adjustment Policy — Phase 1 (Stock Adjustment plan).
 *
 * Centralizes the role + branch rules for the Stock Adjustment module.
 * Stock Adjustment is a BOOKKEEPING CORRECTION TOOL (opening balances,
 * data migration, UOM fixes, post-conversion fixes, legacy cleanup) used
 * infrequently by an Accountant / system administrator — NOT an operational
 * warehouse tool. Therefore operational roles (salesman, dispatcher, hr,
 * warehouse_manager) have NO access at all.
 *
 * Each method returns true for the EXACT set of roles the corresponding
 * route middleware (`role:` in routes/web.php) already allows — so
 * `$this->authorize()` in the controller is defense-in-depth (the
 * middleware gates first; the policy re-confirms the same rule). This
 * does not change behavior; it makes the rules testable + discoverable
 * in one place and survives any future route loosening.
 *
 * Defense-in-depth layers for Stock Adjustment:
 *   1. `role:` middleware on the route group (PRIMARY gate)
 *   2. This Policy via $this->authorize() in the controller
 *   3. `branch.isolation` middleware on POST writes (resolves {id} →
 *      stock_adjustments.branch_id via EnforceBranchIsolation)
 *   4. PostgreSQL RLS on stock_adjustments (DB-enforced branch filter)
 *
 * Branch isolation is enforced BOTH here (model-context: adjustment's
 * branch_id vs user's session branch) AND by the `branch.isolation`
 * route middleware (request-context). RLS provides the final DB-level
 * guarantee.
 *
 * Role reference (User::getRole() reads from Employee):
 *   superadmin → bypasses everything (EnsureRole middleware)
 *   admin      → full access, cross-branch override (logged)
 *   manager    → read-only access (cannot create/confirm/cancel)
 *   accountant → full write access (the primary user of this tool)
 *   salesman, dispatcher, hr, warehouse_manager, user, other → NO access
 *
 * @see routes/web.php  admin/stock-adjustments route groups (L411-434)
 * @see STOCK_ADJUSTMENT_IMPLEMENTATION_PLAN.md  Phase 1
 */
class StockAdjustmentPolicy
{
    use HandlesAuthorization;

    /**
     * View the index list or a single adjustment detail (index, show, audit).
     * Route middleware: role:admin,manager,accountant
     * + branch.isolation on show (the {id} resolves to stock_adjustments.branch_id).
     *
     * A non-admin can only view adjustments in their own session branch.
     */
    public function view(User $user, StockAdjustment $adjustment): bool
    {
        if (!$user->hasRole('admin', 'manager', 'accountant')) {
            return false;
        }

        return $this->sameBranch($user, $adjustment);
    }

    /**
     * View the audit (health-check) screen.
     * Route: admin.stock-adjustments.audit — role:admin,manager,accountant
     * No model binding (global health check), so no branch check here.
     */
    public function audit(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'accountant');
    }

    /**
     * Create / store a draft adjustment (no stock movement yet).
     * Route: admin.stock-adjustments.create / store — role:admin,accountant
     * No branch.isolation on store (branch_id is derived from the warehouse
     * inside StockAdjustmentService, NOT taken from the request body, so it
     * cannot be forged).
     */
    public function create(User $user): bool
    {
        return $user->hasRole('admin', 'accountant');
    }

    /**
     * AJAX: get product avg cost + qty for a warehouse (create-form helper).
     * Route: admin.stock-adjustments.product-rate — role:admin,accountant
     *
     * Branch check is enforced INSIDE the controller's getProductRate()
     * method (it needs the request's warehouse_id, which the policy does
     * not receive). The controller asserts the warehouse belongs to the
     * user's session branch before returning data (G16 fix). This method
     * only re-confirms the role.
     */
    public function viewProductRate(User $user): bool
    {
        return $user->hasRole('admin', 'accountant');
    }

    /**
     * Confirm a draft adjustment (apply stock + post GL).
     * Route: admin.stock-adjustments.{id}.confirm — role:admin,accountant
     * + branch.isolation.
     *
     * This is the most sensitive action — it posts to stock and GL. Only
     * admin + accountant may perform it. Phase 3 (Approval Workflow) will
     * further require an approved state before confirm can run.
     */
    public function confirm(User $user, StockAdjustment $adjustment): bool
    {
        if (!$user->hasRole('admin', 'accountant')) {
            return false;
        }

        return $this->sameBranch($user, $adjustment);
    }

    /**
     * Cancel an adjustment (reverse stock + GL if confirmed, or mark draft cancelled).
     * Route: admin.stock-adjustments.{id}.cancel — role:admin,accountant
     * + branch.isolation.
     */
    public function cancel(User $user, StockAdjustment $adjustment): bool
    {
        if (!$user->hasRole('admin', 'accountant')) {
            return false;
        }

        return $this->sameBranch($user, $adjustment);
    }

    /**
     * Branch check: admin/superadmin may operate on any branch (the
     * cross-branch override is logged by EnforceBranchIsolation). All
     * other roles must match the adjustment's branch_id to their
     * session branch.
     */
    private function sameBranch(User $user, StockAdjustment $adjustment): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $sessionBranchId = (int) (session('branch_id') ?? $user->getBranchId() ?? 0);

        return $sessionBranchId > 0
            && (int) $adjustment->branch_id === $sessionBranchId;
    }
}

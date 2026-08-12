<?php

namespace App\Policies;

use App\Models\MoneyTransfer;
use App\Models\User;

/**
 * Money Transfer Policy — Phase 4 (Finance cluster — accounts sub-ledger).
 *
 * Centralizes the role rules for the money-transfer module. Each method
 * returns true for the EXACT set of roles the corresponding route
 * middleware already allows — `$this->authorize()` in the controller is
 * defense-in-depth (the middleware gated first; the policy re-confirms
 * the same rule). This does NOT change behavior; it makes the rules
 * testable + discoverable in one place.
 *
 * Money transfers are CROSS-BRANCH GL documents (from_branch_id +
 * to_branch_id — money moves between branches). The model has
 * `MoneyTransferBranchScope` (allows read if user's branch is either
 * endpoint OR user is admin). `branch.isolation` middleware on writes
 * (store + reverse) ensures the user's session branch is involved in
 * the transfer. This policy is the role-only layer; the branch check
 * stays as route middleware (request-context, not model-context).
 *
 * All money-transfer routes carry `role:accountant,manager,admin`
 * (some at the prefix-group level via the resource middleware,
 * some inline on the named routes). Operational roles (salesman,
 * dispatcher, hr, warehouse_manager, user, other) have NO access.
 *
 * Role reference (User::getRole() reads from Employee):
 *   admin, manager, accountant — have access (full CRUD + reverse + audit).
 *   superadmin → bypasses everything via EnsureRole middleware.
 *
 * @see routes/web.php  admin/money-transfers route group (L1403-1422)
 */
class MoneyTransferPolicy
{
    /**
     * View transfer list / detail (index, show, audit, slip).
     * Routes: admin.money-transfers.{index,show,audit,slip} —
     *   role:accountant,manager,admin
     * No branch.isolation (read-only; MoneyTransferBranchScope handles
     * row-level — non-admin sees only transfers involving their branch).
     */
    public function view(User $user, ?MoneyTransfer $transfer = null): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Create / store a new money transfer.
     * Route: admin.money-transfers.store — role:accountant,manager,admin
     * + branch.isolation (transfer carries from_branch_id + to_branch_id
     * in the request body; EnforceBranchIsolation checks the user's
     * session branch is one of the two).
     */
    public function create(User $user): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Reverse a posted money transfer (DESTRUCTIVE — reverses GL +
     * branch_ledger entries).
     * Route: admin.money-transfers.reverse — role:accountant,manager,admin
     * + branch.isolation.
     */
    public function reverse(User $user, MoneyTransfer $transfer): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Reverse alias — same gate as reverse(), exposed under the
     * conventional delete() name.
     */
    public function delete(User $user, MoneyTransfer $transfer): bool
    {
        return $this->reverse($user, $transfer);
    }

    /**
     * View the per-transfer audit-log page.
     * Route: admin.money-transfers.audit — role:accountant,manager,admin
     * No branch.isolation (audit page lists transfers across branches;
     * MoneyTransferBranchScope filters to the session branch for non-admins).
     */
    public function audit(User $user): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Print the transfer slip (PDF/HTML — read-only).
     * Route: admin.money-transfers.slip — role:accountant,manager,admin
     * No branch.isolation (read-only print view).
     */
    public function slip(User $user, MoneyTransfer $transfer): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }
}

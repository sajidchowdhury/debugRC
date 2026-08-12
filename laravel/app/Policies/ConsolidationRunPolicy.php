<?php

namespace App\Policies;

use App\Models\ConsolidationRun;
use App\Models\User;

/**
 * Consolidation Run Policy — Phase 8 (Finance cluster — group consolidation).
 *
 * Centralizes the role rules for the consolidation-run module. Each method
 * returns true for the EXACT set of roles the corresponding route
 * middleware already allows — `$this->authorize()` in the controller is
 * defense-in-depth (the middleware gated first; the policy re-confirms
 * the same rule). This does NOT change behavior; it makes the rules
 * testable + discoverable in one place.
 *
 * Consolidation runs are GROUP-LEVEL entities (no branch_id — they
 * consolidate across all branches/companies). They are therefore NOT
 * subject to `branch.isolation` or `BranchScope`. RBAC is role-only.
 *
 * All `admin/consolidation` routes carry `role:accountant,manager,admin`
 * at the prefix-group level (routes/web.php L1733). Every action in
 * this module is therefore accountant/manager/admin. Operational roles
 * (salesman, dispatcher, hr, warehouse_manager, user, other) have NO
 * access.
 *
 * Role reference (User::getRole() reads from Employee):
 *   admin, manager, accountant — have access (full CRUD + post + reverse).
 *   superadmin → bypasses everything via EnsureRole middleware.
 *
 * @see routes/web.php  admin/consolidation route group (L1733-1766)
 */
class ConsolidationRunPolicy
{
    /**
     * View consolidation run list / detail (index, show, consolidated
     * financial statements, intercompany reconciliation).
     * Route middleware: role:accountant,manager,admin (group-level).
     * No branch.isolation (group-level entity, no branch_id).
     */
    public function view(User $user, ?ConsolidationRun $run = null): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Create / store a new consolidation run (draft).
     * Routes: admin.consolidation.{create,store} —
     *   role:accountant,manager,admin (group-level).
     */
    public function create(User $user): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Post a draft consolidation run (apply eliminations + post GL).
     * Route: admin.consolidation.post — role:accountant,manager,admin
     * DESTRUCTIVE (GL write).
     */
    public function post(User $user, ConsolidationRun $run): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Reverse a posted consolidation run (reverse GL + eliminations).
     * Route: admin.consolidation.reverse — role:accountant,manager,admin
     * DESTRUCTIVE (GL reversal).
     */
    public function reverse(User $user, ConsolidationRun $run): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Delete a consolidation run (only draft runs can be deleted —
     * enforced inside ConsolidationController::destroy).
     * Route: admin.consolidation.destroy — role:accountant,manager,admin
     */
    public function delete(User $user, ConsolidationRun $run): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }
}

<?php

namespace App\Policies;

use App\Models\EliminationRule;
use App\Models\User;

/**
 * Elimination Rule Policy — Phase 8 (Finance cluster — consolidation config).
 *
 * Centralizes the role rules for the consolidation elimination-rule
 * module. Each method returns true for the EXACT set of roles the
 * corresponding route middleware already allows — `$this->authorize()`
 * in the controller is defense-in-depth (the middleware gated first;
 * the policy re-confirms the same rule). This does NOT change behavior;
 * it makes the rules testable + discoverable in one place.
 *
 * Elimination rules are GROUP-LEVEL configuration entities (no branch_id
 * — they define which intercompany ledger pairs eliminate at
 * consolidation). NOT subject to `branch.isolation` or `BranchScope`.
 *
 * All `admin/consolidation/rules*` routes carry `role:accountant,manager,admin`
 * via the prefix-group middleware (routes/web.php L1733). Operational
 * roles have NO access.
 *
 * Role reference (User::getRole() reads from Employee):
 *   admin, manager, accountant — have access.
 *   superadmin → bypasses everything via EnsureRole middleware.
 *
 * @see routes/web.php  admin/consolidation/rules* routes (L1750-1752)
 */
class EliminationRulePolicy
{
    /**
     * View the elimination-rules index (admin.consolidation.rules).
     * Route middleware: role:accountant,manager,admin (group-level).
     * No branch.isolation (group-level config, no branch_id).
     */
    public function view(User $user, ?EliminationRule $rule = null): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Create / store a new elimination rule (admin.consolidation.rules.store).
     * Route middleware: role:accountant,manager,admin (group-level).
     */
    public function create(User $user): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Toggle an elimination rule's `is_active` flag (admin.consolidation.rules.toggle).
     * Route middleware: role:accountant,manager,admin (group-level).
     */
    public function toggle(User $user, EliminationRule $rule): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Update alias — same gate as toggle(), exposed under the
     * conventional update() name.
     */
    public function update(User $user, EliminationRule $rule): bool
    {
        return $this->toggle($user, $rule);
    }
}

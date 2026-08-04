<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

/**
 * Company Policy — Phase 8 (Finance cluster — consolidation master data).
 *
 * Centralizes the role rules for the consolidation `companies` master
 * data module. Each method returns true for the EXACT set of roles the
 * corresponding route middleware already allows — `$this->authorize()`
 * in the controller is defense-in-depth (the middleware gated first;
 * the policy re-confirms the same rule). This does NOT change behavior;
 * it makes the rules testable + discoverable in one place.
 *
 * Companies are GROUP-LEVEL master-data entities (no branch_id — each
 * company owns multiple branches). NOT subject to `branch.isolation`
 * or `BranchScope`.
 *
 * The `admin/consolidation/companies*` routes carry
 * `role:accountant,manager,admin` via the prefix-group middleware
 * (routes/web.php L1733). Operational roles have NO access. (Note:
 * `companies` here is the consolidation-master-data table — separate
 * from `branches`. There is no separate CompanyController; CRUD lives
 * in ConsolidationController::companiesIndex / companiesStore.)
 *
 * Role reference (User::getRole() reads from Employee):
 *   admin, manager, accountant — have access.
 *   superadmin → bypasses everything via EnsureRole middleware.
 *
 * @see routes/web.php  admin/consolidation/companies* routes (L1755-1756)
 */
class CompanyPolicy
{
    /**
     * View the companies index (admin.consolidation.companies).
     * Route middleware: role:accountant,manager,admin (group-level).
     * No branch.isolation (group-level entity, no branch_id).
     */
    public function view(User $user, ?Company $company = null): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Create / store a new company (admin.consolidation.companies.store).
     * Route middleware: role:accountant,manager,admin (group-level).
     */
    public function create(User $user): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Update / delete — NO dedicated routes exist (companies master data
     * is append-only via the consolidation UI; edits go through manual
     * SQL by an admin if ever needed). Exposed here for signature
     * completeness so `$this->authorize('update', $company)` and
     * `$this->authorize('delete', $company)` resolve to the same role
     * matrix if ever wired in future.
     */
    public function update(User $user, Company $company): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    public function delete(User $user, Company $company): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }
}

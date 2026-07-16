<?php

namespace App\Policies;

use App\Models\User;

/**
 * System Policy Gate — Phase 11.
 *
 * Defines who can activate/deactivate system policies.
 * Only superadmin can manage system policies.
 *
 * Registered in AppServiceProvider::boot() via Gate::define().
 */
class SystemPolicyPolicy
{
    /**
     * Determine if the user can manage system policies.
     * Only superadmin.
     */
    public function manage(User $user): bool
    {
        return $user->isSuperadmin();
    }
}

<?php

namespace App\Policies;

use App\Models\ManualJournal;
use App\Models\User;

/**
 * Manual Journal Policy — Phase 6 (Accounts Sub-Ledger).
 *
 * Defense-in-depth behind the role: middleware on admin/manual-journals
 * routes. Mirrors the role matrix: accountant/manager/admin for all
 * actions. Branch isolation stays as route middleware + BranchScope.
 *
 * @see routes/web.php  admin/manual-journals route group
 */
class ManualJournalPolicy
{
    public function view(User $user, ManualJournal $journal): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    public function viewAudit(User $user): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    public function delete(User $user, ManualJournal $journal): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    public function post(User $user, ManualJournal $journal): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }
}

<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Warehouse Transfer Branch Scope — Phase 1 enforcement.
 *
 * Specialized Eloquent global scope for WarehouseTransfer model.
 * Unlike BranchScope which filters by a single `branch_id` column,
 * this scope filters by BOTH `from_branch_id` AND `to_branch_id`,
 * since a transfer involves two branches (source and destination).
 *
 * Non-admin users see transfers where their branch is EITHER the
 * source OR the destination:
 *   WHERE from_branch_id = ? OR to_branch_id = ?
 *
 * Admin/superadmin bypass: they see all branches (but cross-branch
 * transfers are still blocked at the service/controller/DB level).
 *
 * This is a defense-in-depth layer on top of PostgreSQL RLS policies.
 */
class WarehouseTransferBranchScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // No filtering if no authenticated user (console, unauthenticated).
        if (!Auth::check()) {
            return;
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        // Admin/superadmin bypass — they see all branches.
        if ($user->isAdmin()) {
            return;
        }

        // Non-admin: filter by session branch_id — user can see transfers
        // where their branch is involved as either source or destination.
        $branchId = (int) (session('branch_id') ?? $user->getBranchId() ?? 0);
        if ($branchId > 0) {
            $table = $model->getTable();
            $builder->where(function ($query) use ($table, $branchId) {
                $query->where($table . '.from_branch_id', '=', $branchId)
                      ->orWhere($table . '.to_branch_id', '=', $branchId);
            });
        }
    }
}

<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Branch Scope — P0-8 Branch Isolation.
 *
 * Global Eloquent scope that auto-filters queries by the authenticated
 * user's branch_id. Non-admin users only see records from their own
 * branch; admin/superadmin bypass (see all branches).
 *
 * Applied to: SalesInvoice, SalesChallan, SalesReturn, CustomerPayment.
 * (CustomerLedger is also branch-scoped but filtered via the same scope
 *  if added to that model — see roadmap P0-8.)
 *
 * The scope reads `session('branch_id')` (populated by SyncLegacySession
 * middleware from the legacy PHP session). If no session branch is set
 * (e.g., during a console command or unauthenticated request), the scope
 * is a no-op (no filtering).
 *
 * Admin/superadmin bypass: `User::isAdmin()` returns true for roles
 * 'admin' and 'superadmin' — these users see ALL branches (but cross-
 * branch writes are still audited via the EnforceBranchIsolation
 * middleware + SalesAccess::assertInvoiceAccessible).
 *
 * Usage in a model:
 *   protected static function booted(): void {
 *       static::addGlobalScope(new BranchScope);
 *   }
 *
 * To bypass the scope in a query (admin-only contexts):
 *   SalesInvoice::withoutGlobalScope(BranchScope::class)->get();
 */
class BranchScope implements Scope
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

        // Non-admin: filter by session branch_id.
        $branchId = (int) (session('branch_id') ?? $user->getBranchId() ?? 0);
        if ($branchId > 0) {
            $builder->where($model->getTable() . '.branch_id', '=', $branchId);
        }
    }
}

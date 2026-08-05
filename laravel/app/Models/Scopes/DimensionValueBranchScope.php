<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Dimension Value Branch Scope — FINANCE-3 (G-319).
 *
 * Variant of {@see BranchScope} for the `dimension_values` table, where a
 * NULL `branch_id` means "applies to ALL branches" (per BR7/BR10/BR11 in
 * `AI_CONTEXT/finance/dimensions-cost-centers.md`).
 *
 * The generic {@see BranchScope} uses hard equality
 * (`WHERE branch_id = ?`), which excludes NULL-branch rows. That is correct
 * for transactional tables (SalesInvoice, SalesChallan, etc.) where every
 * row MUST belong to a concrete branch. But for `dimension_values`, the
 * NULL-branch rows are the company-wide defaults that EVERY non-admin user
 * must be able to see (so they can tag journal lines, run segment reports,
 * etc.). Excluding them caused non-admin Managers/Accountants to 404 on
 * NULL-branch values via route-model-binding — defeating the "all branches"
 * semantic documented in BR7.
 *
 * This scope mirrors the RLS policy on `dimension_values` (migration
 * `2026_08_10_000002_create_budgeting_and_cost_centers` L182-188):
 *   USING (branch_id IS NULL
 *          OR branch_id = current_setting('app.branch_id', true)::int
 *          OR current_setting('app.is_admin', true) = 'true')
 *
 * Applied to: DimensionValue only. All other branch-scoped models continue
 * to use the generic BranchScope (which is correct for them).
 *
 * Usage in a model:
 *   protected static function booted(): void {
 *       static::addGlobalScope(new DimensionValueBranchScope);
 *   }
 */
class DimensionValueBranchScope implements Scope
{
    /**
     * Apply the scope: non-admin users see their own branch's rows PLUS
     * all NULL-branch (company-wide) rows. Admin/superadmin bypass.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // No filtering if no authenticated user (console, unauthenticated).
        if (!Auth::check()) {
            return;
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        // Admin/superadmin bypass — they see all branches AND all NULL-branch rows.
        if ($user->isAdmin()) {
            return;
        }

        // Non-admin: filter by session branch_id, BUT include NULL-branch rows
        // (the company-wide defaults that every branch can see).
        $branchId = (int) (session('branch_id') ?? $user->getBranchId() ?? 0);
        if ($branchId > 0) {
            $builder->whereNull($model->getTable() . '.branch_id')
                ->orWhere($model->getTable() . '.branch_id', '=', $branchId);
        }
    }
}

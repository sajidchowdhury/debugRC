<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FINANCE-3 (G-322) — Add a partial UNIQUE index on `budgets` to backstop
 * the application-level duplicate-active-budget check.
 *
 * The application-level check in `BudgetService::activateBudget` was buggy
 * (the `->when($budget->branch_id, ...)` clause did not actually filter
 * correctly) and was also racy (TOCTOU between the SELECT and the UPDATE).
 * The check is now rewritten in FINANCE-3 to block ANY coexistence of
 * active budgets for the same fiscal year when one is company-wide, and
 * to block same-scope duplicates. It is also wrapped in a DB::transaction
 * with `lockForUpdate`.
 *
 * This migration adds the DB-level backstop: a partial UNIQUE index that
 * prevents two active budgets from sharing the same `(fiscal_year, branch)`
 * slot (where NULL-branch is coalesced to 0 so that two company-wide
 * budgets for the same year are also blocked). The index is partial to
 * `status = 'active' AND deleted_at IS NULL` so that draft/closed/
 * cancelled budgets do not consume a slot.
 *
 * NOTE: this index does NOT prevent a company-wide budget from coexisting
 * with a branch-specific one (different COALESCE values: 0 vs branch_id).
 * The application-level check still enforces that stricter rule. The index
 * is a defense-in-depth backstop for the same-scope duplicate case.
 *
 * Down: drops the index. Existing active budgets that violate the index
 * (should be none after the application-level fix is deployed) would need
 * to be reconciled before re-applying.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_budgets_active_per_year_branch
            ON budgets (fiscal_year, COALESCE(branch_id, 0))
            WHERE status = 'active' AND deleted_at IS NULL
        ");
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS uq_budgets_active_per_year_branch");
    }
};

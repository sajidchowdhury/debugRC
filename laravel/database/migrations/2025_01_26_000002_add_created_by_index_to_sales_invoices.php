<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — F-6: Index sales_invoices.created_by for smart-search.
 *
 * The F-6 smart-search extension adds whereHas('creator') which emits an
 * EXISTS subquery joining sales_invoices.created_by → users.id. Without an
 * index on created_by, a search that narrows primarily by creator (e.g. a
 * future "my invoices" view, or a username search when no other filter is
 * active) would seq-scan sales_invoices.
 *
 * The existing schema indexes salesman_id, customer_id, branch_id,
 * invoice_date, status, journal_entry_id — but NOT created_by. This adds a
 * plain B-tree index on created_by so the EXISTS subquery + any "invoices
 * created by user X" report can use an index lookup.
 *
 * Note: the F-6 search is an OR across 6 clauses (invoice_code, customer,
 * branch, salesman, creator, items.product). In practice the user also has
 * a date range + call_a_day filter active, so the planner picks the most
 * selective index (usually invoice_date) for the outer scan and uses the
 * EXISTS subqueries only to filter. This index makes the rare creator-only
 * search path efficient too.
 *
 * Idempotent via IF NOT EXISTS.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "CREATE INDEX IF NOT EXISTS idx_si_created_by
             ON sales_invoices (created_by)"
        );
        // Refresh planner statistics for the new index.
        DB::statement('ANALYZE sales_invoices');
    }

    public function down(): void
    {
        DB::statement("DROP INDEX IF EXISTS idx_si_created_by");
    }
};

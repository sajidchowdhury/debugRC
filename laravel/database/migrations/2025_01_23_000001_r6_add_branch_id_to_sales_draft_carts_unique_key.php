<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * R6 — Add branch_id to sales_draft_carts unique key.
 *
 * Audit risks fixed: V11 (Laravel), C7 (Both systems).
 *
 * Background
 * ----------
 * The original `sales_draft_carts` unique constraint was
 * `UNIQUE (user_id, customer_id)` — `branch_id` was stored on the row
 * but NOT part of the key. This meant a salesman switching branches
 * with the same customer would share the SAME cart row, causing
 * cross-branch stock reservation contamination (the cart's items
 * could reference stock from the wrong branch).
 *
 * This migration tightens the key to
 * `UNIQUE (user_id, customer_id, branch_id)`, so each (user, customer,
 * branch) tuple gets its own cart. A salesman switching branches now
 * gets a fresh cart per branch — matching Legacy semantics (Legacy's
 * `020_sales_draft_carts.sql` declares `branch_id INT NOT NULL
 * DEFAULT 0` and the application code keys carts by user+customer+branch
 * in `$_SESSION`).
 *
 * Schema fix included
 * -------------------
 * The Laravel `04_sales.sql` declared `branch_id integer REFERENCES
 * branches(id)` (nullable, with FK). The Legacy schema declares it
 * `NOT NULL DEFAULT 0` (no FK — Legacy doesn't enforce FKs). This
 * migration aligns Laravel with Legacy:
 *
 *   1. Drop the FK on (branch_id) — needed so the `branch_id = 0`
 *      sentinel (Legacy "no specific branch") can be stored without
 *      requiring a branches(0) row.
 *   2. Backfill NULL branch_id → 0.
 *   3. ALTER COLUMN branch_id SET NOT NULL.
 *   4. ALTER COLUMN branch_id SET DEFAULT 0.
 *
 * `branch_id = 0` is the pre-existing Legacy convention for "no
 * specific branch" — the Legacy `branch_id INT NOT NULL DEFAULT 0`
 * schema plus the `idx_sales_draft_branch` index both treat 0 as a
 * first-class value.
 *
 * Migration safety
 * ----------------
 * - All DDL is wrapped in a single transaction so a failure at any
 *   step rolls back the entire migration (no half-applied state).
 * - `DROP CONSTRAINT IF EXISTS` is used so the migration is idempotent.
 * - The FK name is looked up dynamically from `pg_constraint` because
 *   PostgreSQL auto-generates the name (typically
 *   `sales_draft_carts_branch_id_foreign`) and we don't want to hardcode it.
 * - The backfill UPDATE only touches rows where branch_id IS NULL —
 *   in practice the Laravel app has always passed a non-null
 *   branch_id via `session('branch_id', 0)`, so the backfill should
 *   be a no-op on production data. It exists as a defensive measure.
 *
 * After this migration
 * --------------------
 * `SalesDraftCart::getOrCreate()` was updated (same commit) to include
 * `branch_id` in its `firstOrCreate` search attributes and to normalize
 * `null` → `0`. All `clearCart()` and `setSoftHold()` callers in the
 * controllers and `SalesInvoiceService::finalizeFromCart` were updated
 * to pass `branch_id` explicitly, so the right cart is targeted.
 *
 * Down migration
 * --------------
 * The down migration reverts the unique constraint to the old
 * 2-column form. It also reverts the NOT NULL + DEFAULT 0 changes
 * (restoring nullable branch_id) and re-creates the FK to branches(id).
 * This is for rollback parity; we do not expect anyone to actually
 * roll back, since reverting would re-introduce the V11/C7
 * cross-branch cart contamination risk.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // ──────────────────────────────────────────────
            // 1. Drop the FK on branch_id (if present)
            // ──────────────────────────────────────────────
            // The original Laravel schema declared `branch_id REFERENCES branches(id)`,
            // but Legacy semantics use branch_id = 0 as a "no specific branch"
            // sentinel — there is no branches(0) row. Keeping the FK would
            // block the backfill and break the sentinel convention.
            $fk = DB::selectOne(<<<SQL
                SELECT c.conname
                FROM pg_constraint c
                JOIN pg_class t ON t.oid = c.conrelid
                JOIN pg_namespace n ON n.oid = t.relnamespace
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(c.conkey)
                WHERE t.relname = 'sales_draft_carts'
                  AND n.nspname = 'public'
                  AND c.contype = 'f'
                  AND a.attname = 'branch_id'
                LIMIT 1
            SQL);

            if ($fk) {
                DB::statement("ALTER TABLE sales_draft_carts DROP CONSTRAINT IF EXISTS {$fk->conname}");
                Log::info("R6 migration: dropped FK {$fk->conname} on sales_draft_carts.branch_id");
            }

            // ──────────────────────────────────────────────
            // 2. Backfill NULL branch_id → 0
            // ──────────────────────────────────────────────
            // Legacy semantics: branch_id = 0 means "no specific branch".
            // In practice the Laravel app has always passed a non-null
            // branch_id, so this should be a no-op — but it's a defensive
            // measure in case any old rows exist with NULL.
            $backfilled = DB::table('sales_draft_carts')
                ->whereNull('branch_id')
                ->update(['branch_id' => 0]);

            if ($backfilled > 0) {
                Log::info("R6 migration: backfilled {$backfilled} sales_draft_carts rows with NULL branch_id → 0");
            }

            // ──────────────────────────────────────────────
            // 3. Align column with Legacy: NOT NULL DEFAULT 0
            // ──────────────────────────────────────────────
            // The Legacy `020_sales_draft_carts.sql` declares
            // `branch_id INT(11) NOT NULL DEFAULT 0`. The Laravel
            // `04_sales.sql` declared it nullable (an oversight).
            // This fixes the oversight so the unique constraint
            // (which can't tolerate NULLs anyway — PG treats NULL
            // as distinct in a UNIQUE constraint, which would
            // defeat the purpose) works correctly.
            DB::statement('ALTER TABLE sales_draft_carts ALTER COLUMN branch_id SET NOT NULL');
            DB::statement('ALTER TABLE sales_draft_carts ALTER COLUMN branch_id SET DEFAULT 0');

            // ──────────────────────────────────────────────
            // 4. Drop the old 2-column unique constraint
            // ──────────────────────────────────────────────
            DB::statement('ALTER TABLE sales_draft_carts DROP CONSTRAINT IF EXISTS uq_sales_draft_user_customer');

            // ──────────────────────────────────────────────
            // 5. Add the new 3-column unique constraint
            // ──────────────────────────────────────────────
            // One cart per (user, customer, branch). A salesman
            // switching branches with the same customer now gets
            // a fresh cart per branch — no cross-branch contamination.
            DB::statement(
                'ALTER TABLE sales_draft_carts
                 ADD CONSTRAINT uq_sales_draft_user_customer_branch
                 UNIQUE (user_id, customer_id, branch_id)'
            );
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            // Drop the new 3-column unique constraint.
            DB::statement('ALTER TABLE sales_draft_carts DROP CONSTRAINT IF EXISTS uq_sales_draft_user_customer_branch');

            // Restore the old 2-column unique constraint.
            DB::statement('ALTER TABLE sales_draft_carts ADD CONSTRAINT uq_sales_draft_user_customer UNIQUE (user_id, customer_id)');

            // Restore the nullable branch_id (revert the NOT NULL + DEFAULT 0
            // changes) so the schema matches the pre-R6 state.
            // NOTE: we do NOT re-introduce NULL values — existing rows keep
            // their branch_id. Only the column constraint is reverted.
            DB::statement('ALTER TABLE sales_draft_carts ALTER COLUMN branch_id DROP NOT NULL');
            DB::statement('ALTER TABLE sales_draft_carts ALTER COLUMN branch_id DROP DEFAULT');

            // Re-create the FK on branch_id → branches(id) (best-effort —
            // if branches(0) exists as a sentinel row, the FK will fail to
            // re-create because of branch_id=0 rows; in that case we log
            // a warning and move on, since rollback is a recovery path,
            // not a normal operation).
            try {
                DB::statement(
                    'ALTER TABLE sales_draft_carts
                     ADD CONSTRAINT sales_draft_carts_branch_id_foreign
                     FOREIGN KEY (branch_id) REFERENCES branches(id)'
                );
            } catch (\Throwable $e) {
                Log::warning("R6 rollback: could not re-create FK on sales_draft_carts.branch_id (likely because branch_id=0 sentinel rows exist). Manual cleanup required: {$e->getMessage()}");
            }
        });
    }
};

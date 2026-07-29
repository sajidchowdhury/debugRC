<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix #2: actually drop the orphaned strict CHECK copies on partitions.
 *
 * SYMPTOM (still present after 2026_07_29_000001 ran):
 *   SQLSTATE[23514]: Check violation: 7 ERROR: new row for relation
 *   "stock_transactions_default" violates check constraint
 *   "stock_transactions_reference_type_check1"
 *   DETAIL: Failing row contains (..., reversal, 12, ...).
 *
 * WHY 2026_07_29_000001 DIDN'T FIX IT:
 *   That migration's step 2 dropped LOCAL copies on partitions but filtered
 *   by `con.conislocal = true`. The stale strict copy
 *   `stock_transactions_reference_type_check1` on `stock_transactions_default`
 *   is an ORPHANED INHERITED copy — `conislocal = false` but its `conparentid`
 *   points to a parent constraint that was dropped by a previous migration
 *   (so the link is broken and PG no longer cascades to it). The conislocal
 *   filter EXCLUDED it, so step 2 never tried to drop it.
 *
 *   Then step 3 added a FRESH loose `stock_transactions_reference_type_check`
 *   on the parent, which PG propagated to the partition as a NEW inherited
 *   copy (named `stock_transactions_reference_type_check` or `_check2`).
 *   The partition now has BOTH:
 *     - `stock_transactions_reference_type_check1` (stale STRICT, 10 values,
 *        rejects 'reversal') — orphaned, conislocal=false
 *     - `stock_transactions_reference_type_check`  (new LOOSE, 11 values,
 *        accepts 'reversal') — inherited from the fresh parent constraint
 *   An INSERT must satisfy BOTH, so the strict copy still fires on 'reversal'.
 *
 * THE REAL FIX (this migration) — 3 steps, NO conislocal filter:
 *   1. DO block: drop EVERY CHECK matching `^stock_transactions_reference_type_check`
 *      on the PARENT. This catches the exact name AND any suffixed parent
 *      copies. Cascade removes all properly-linked inherited copies on
 *      partitions (the loose `_check` / `_check2` that step 3 of the previous
 *      migration created).
 *   2. DO block: drop EVERY CHECK matching `^stock_transactions_reference_type_check`
 *      on ALL partitions, with NO conislocal filter. After step 1, every
 *      remaining copy on a partition is either:
 *        - LOCAL (conislocal = true) — always droppable directly. ✓
 *        - ORPHANED INHERITED (conislocal = false, conparentid = 0 because
 *          the parent was just dropped in step 1) — droppable directly
 *          because PG only forbids dropping inherited copies whose parent
 *          constraint STILL EXISTS (42P16). Once the parent is gone, the
 *          orphaned copy is treated as standalone and can be dropped. ✓
 *      This is where `stock_transactions_reference_type_check1` finally gets
 *      dropped. The conislocal filter that caused the previous miss is GONE.
 *   3. Add a single fresh `stock_transactions_reference_type_check` on the
 *      PARENT with the full 11-value set (incl. 'reversal'). PG propagates
 *      inherited copies to all partitions. With all stale copies gone,
 *      every partition now has exactly ONE reference_type CHECK, and it
 *      accepts 'reversal'.
 *
 * WHY STEP 2 WON'T HIT 42P16 THIS TIME:
 *   The 42P16 "cannot drop inherited constraint" error only fires when the
 *   partition copy's `conparentid` points to a LIVING parent constraint.
 *   Step 1 drops ALL matching parent constraints first, so every partition
 *   copy's parent link is either already broken (orphaned from a previous
 *   migration) or freshly broken (by step 1). Either way, PG allows the
 *   direct drop. The previous migration's conislocal=true filter was a
 *   misguided attempt to avoid 42P16 that ended up skipping the very
 *   constraints we needed to drop.
 *
 *   The app layer is correct and unchanged — `'reversal'` is the intended
 *   reference_type value (StockTransaction::REFERENCE_TYPES,
 *   StockService::reverseTransaction, database/sql/03_stock.sql).
 *
 *   Idempotent: safe to run multiple times.
 */
return new class extends Migration
{
    private const ALLOWED_WITH_REVERSAL = [
        'purchase_receive',
        'purchase_return',
        'sales_challan',
        'sales_return',
        'stock_adjustment',
        'stock_take',
        'warehouse_transfer',
        'damage',
        'branch_demand',
        'opening_balance',
        'reversal',
    ];

    public function up(): void
    {
        $values = implode(',', array_map(
            fn (string $v): string => "'" . $v . "'",
            self::ALLOWED_WITH_REVERSAL,
        ));

        // Step 1 — drop EVERY matching CHECK on the PARENT (exact name AND
        // suffixed copies). PG cascades this to remove all properly-linked
        // inherited copies on partitions (the loose ones created by the
        // previous migration's step 3). Uses a DO block so we catch any
        // suffixed parent constraint too (defense-in-depth).
        DB::statement(<<<SQL
            DO $$
            DECLARE
                r RECORD;
            BEGIN
                FOR r IN
                    SELECT con.conname
                    FROM pg_constraint con
                    JOIN pg_class c ON c.oid = con.conrelid
                    WHERE con.contype = 'c'
                      AND con.conname ~ '^stock_transactions_reference_type_check'
                      AND c.relname = 'stock_transactions'
                LOOP
                    EXECUTE format(
                        'ALTER TABLE stock_transactions DROP CONSTRAINT IF EXISTS %I',
                        r.conname
                    );
                END LOOP;
            END;
            $$;
        SQL);

        // Step 2 — drop EVERY matching CHECK on ALL partitions, with NO
        // conislocal filter. After step 1, every remaining copy on a
        // partition is either LOCAL (conislocal=true) or ORPHANED INHERITED
        // (conislocal=false, conparentid=0 because the parent was just
        // dropped). BOTH are droppable directly — PG only forbids dropping
        // inherited copies whose parent STILL EXISTS (42P16), and step 1
        // ensured no parent match remains.
        //
        // This is where `stock_transactions_reference_type_check1` (the
        // stale strict orphan that caused the 23514 violation) finally
        // gets dropped.
        DB::statement(<<<SQL
            DO $$
            DECLARE
                r RECORD;
            BEGIN
                FOR r IN
                    SELECT n.nspname AS schemaname,
                           c.relname AS tablename,
                           con.conname AS constraintname
                    FROM pg_constraint con
                    JOIN pg_class c     ON c.oid = con.conrelid
                    JOIN pg_namespace n ON n.oid = c.relnamespace
                    WHERE con.contype = 'c'
                      AND con.conname ~ '^stock_transactions_reference_type_check'
                      AND c.oid IN (
                          SELECT inhrelid
                          FROM pg_inherits
                          WHERE inhparent = 'stock_transactions'::regclass
                      )
                LOOP
                    EXECUTE format(
                        'ALTER TABLE %I.%I DROP CONSTRAINT IF EXISTS %I',
                        r.schemaname, r.tablename, r.constraintname
                    );
                END LOOP;
            END;
            $$;
        SQL);

        // Step 3 — add a single fresh constraint on the PARENT. PG
        // propagates inherited copies to every existing partition AND
        // every future PARTITION OF. With all stale copies gone, every
        // partition now has exactly ONE reference_type CHECK (loose, 11
        // values, accepts 'reversal').
        DB::statement(<<<SQL
            ALTER TABLE stock_transactions
            ADD CONSTRAINT stock_transactions_reference_type_check
            CHECK (reference_type IN ({$values}))
        SQL);
    }

    public function down(): void
    {
        // No-op: the previous migration (2026_07_29_000001) handles the
        // "without reversal" restoration via its own down(). This migration
        // only exists to clean up orphaned copies that the previous one
        // missed; rolling it back would re-break reversals.
    }
};

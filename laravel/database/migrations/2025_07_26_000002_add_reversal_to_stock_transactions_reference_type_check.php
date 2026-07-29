<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0.1 — Fix the stock_transactions.reference_type CHECK constraint.
 *
 * BUG: `StockService::reverseTransaction()` (and `JournalPostingService`)
 * write `reference_type='reversal'` when recording a reversal row. The app
 * layer — the `StockTransaction::REFERENCE_TYPES` constant, the
 * `StockTransactionController` queries (`where('reference_type', 'reversal')`),
 * and the model PHPDoc — all treat `'reversal'` as a first-class reference
 * type. BUT the DB-level CHECK constraint on `stock_transactions.reference_type`
 * — defined inline in both `database/sql/03_stock.sql` and the partitioning
 * migration `2025_01_21_000004_set_up_table_partitioning.php` — lists only 10
 * values and OMITS `'reversal'`.
 *
 * Impact: EVERY reversal that flows through `StockService::reverseTransaction`
 * (sales-return reversal, purchase-return reversal, damage reversal, etc.)
 * fails at the DB level with a CHECK violation. This is a critical blocker
 * for all reversal flows.
 *
 * Fix (Plan "Fix A" from docs/SALES_RETURN_REWRITE_PLAN.md §0.1): add
 * `'reversal'` to the CHECK constraint. This is the minimal change — the app
 * layer already writes and queries `'reversal'`, so changing the DB to accept
 * it aligns the two layers. The alternative (changing `reverseTransaction` to
 * write a more specific type like `'sales_return_reversal'`) would require
 * also changing the model constant, the controller queries, and the PHPDoc —
 * far more invasive for zero benefit.
 *
 * Because `'reversal'` is being ADDED to the allowed set (the constraint is
 * being loosened), no existing row can possibly violate the new constraint —
 * every row that satisfied the old (stricter) constraint also satisfies the
 * new (looser) one. So `ADD CONSTRAINT` is instant, no full table scan.
 *
 * The table is `PARTITION BY RANGE (transaction_date)`. CHECK constraints on
 * the parent partitioned table are automatically inherited by all partitions;
 * `DROP`/`ADD` on the parent propagates to every partition.
 *
 * Idempotent: checks `pg_constraint` before dropping.
 */
return new class extends Migration
{
    private const CONSTRAINT_NAME = 'stock_transactions_reference_type_check';

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

    private const ALLOWED_WITHOUT_REVERSAL = [
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
    ];

    public function up(): void
    {
        $this->replaceConstraint(self::ALLOWED_WITH_REVERSAL);
    }

    public function down(): void
    {
        // NOTE: rolling back will FAIL if any 'reversal' rows exist, because
        // they would violate the restored (stricter) constraint. This is the
        // expected behavior — you cannot un-loosen a constraint when data
        // exists that depends on the looser definition. Rollback is only safe
        // before any reversal has been recorded.
        $this->replaceConstraint(self::ALLOWED_WITHOUT_REVERSAL);
    }

    /**
     * Replace the reference_type CHECK across the partitioned
     * stock_transactions table and ALL its partitions.
     *
     * 3-step algorithm (see 2026_07_29_000001 + 2026_07_29_000002 for the
     * full root-cause analysis of why each step is necessary):
     *   1. DO block: drop EVERY CHECK matching `^stock_transactions_reference_type_check`
     *      on the PARENT (exact name + suffixed). Cascade removes all
     *      properly-linked inherited copies on partitions.
     *   2. DO block: drop EVERY remaining matching CHECK on ALL partitions,
     *      with NO conislocal filter. After step 1, every remaining copy
     *      is LOCAL (conislocal=true) or ORPHANED INHERITED (conislocal=
     *      false, conparentid=0). BOTH are droppable directly — PG only
     *      forbids dropping inherited copies whose parent STILL EXISTS,
     *      and step 1 ensured no parent match remains.
     *   3. Add a single fresh constraint on the PARENT → PG propagates
     *      inherited copies to every partition.
     *
     * NOTE: the original version of this migration only matched the EXACT
     * constraint name on the parent, which left stale strict copies on
     * partitions. The conislocal=true filter added in a later revision
     * caused orphaned inherited copies (`_check1`) to be skipped.
     * This version removes the filter entirely so ALL copies are dropped.
     */
    private function replaceConstraint(array $allowed): void
    {
        $values = implode(',', array_map(
            fn (string $v): string => "'" . $v . "'",
            $allowed,
        ));

        // Step 1 — drop EVERY matching CHECK on the PARENT.
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

        // Step 2 — drop EVERY remaining matching CHECK on ALL partitions.
        // NO conislocal filter — catches orphaned inherited copies (`_check1`)
        // that step 1's cascade doesn't remove.
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

        $name = self::CONSTRAINT_NAME;

        // Step 3 — add a single fresh constraint on the PARENT.
        DB::statement(<<<SQL
            ALTER TABLE stock_transactions
            ADD CONSTRAINT {$name}
            CHECK (reference_type IN ({$values}))
        SQL);
    }
};

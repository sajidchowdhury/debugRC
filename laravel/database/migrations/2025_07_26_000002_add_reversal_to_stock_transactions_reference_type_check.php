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
     * PostgreSQL partitioning rule:
     *   When a CHECK is added to a partitioned parent, each partition gets an
     *   INHERITED copy (pg_constraint.conislocal = false). PostgreSQL FORBIDS
     *   dropping an inherited constraint directly on a partition —
     *   `ALTER TABLE <partition> DROP CONSTRAINT <inherited>` fails with
     *   SQLSTATE[42P16]: "cannot drop inherited constraint ... of relation
     *   <partition>". Inherited constraints can ONLY be dropped via the
     *   parent, which cascades.
     *
     *   A partition MAY also carry a LOCAL constraint (conislocal = true)
     *   with the same / a suffixed name (e.g. `_check1` from PG's name-
     *   collision avoidance). Local copies are NOT removed by dropping the
     *   parent — they must be dropped directly on the partition (allowed
     *   for conislocal=true).
     *
     * 3-step algorithm:
     *   1. Drop the constraint on the PARENT (IF EXISTS) → cascades to
     *      remove ALL inherited copies on partitions.
     *   2. Drop remaining LOCAL copies on partitions matching the pattern,
     *      filtered by conislocal=true (so we never attempt an inherited
     *      drop, which PG rejects with 42P16).
     *   3. Add a single fresh constraint on the PARENT → PG propagates
     *      inherited copies to every partition.
     *
     * NOTE: the original version of this migration only matched the EXACT
     * constraint name on the parent, which left stale strict copies on
     * partitions. See the follow-up migration
     * `2026_07_29_000001_fix_stock_transactions_reversal_check_on_all_partitions.php`
     * for the full root-cause analysis. This method is now aligned with
     * that fix so fresh installs (`migrate:fresh`) are correct too.
     */
    private function replaceConstraint(array $allowed): void
    {
        $values = implode(',', array_map(
            fn (string $v): string => "'" . $v . "'",
            $allowed,
        ));

        // Step 1 — drop the constraint on the PARENT. PG cascades this to
        // every partition, removing ALL inherited copies (conislocal=false)
        // regardless of name. IF EXISTS is safe when the parent has none.
        DB::statement(
            'ALTER TABLE stock_transactions '
            . 'DROP CONSTRAINT IF EXISTS ' . self::CONSTRAINT_NAME
        );

        // Step 2 — drop any REMAINING LOCAL copies on partitions.
        // conislocal=true guarantees we never attempt to drop an inherited
        // copy (which PG would reject with 42P16 "cannot drop inherited
        // constraint").
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
                      AND con.conislocal = true
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

        // Step 3 — add a single fresh constraint on the PARENT. PG propagates
        // inherited copies to every existing partition AND every future
        // PARTITION OF.
        DB::statement(<<<SQL
            ALTER TABLE stock_transactions
            ADD CONSTRAINT {$name}
            CHECK (reference_type IN ({$values}))
        SQL);
    }
};

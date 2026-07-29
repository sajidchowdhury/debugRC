<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: stock_transactions.reference_type CHECK must accept 'reversal' on
 * EVERY partition, not just the parent.
 *
 * SYMPTOM (damage cancel flow, but affects ALL reversal flows):
 *   SQLSTATE[23514]: Check violation: 7 ERROR: new row for relation
 *   "stock_transactions_default" violates check constraint
 *   "stock_transactions_reference_type_check1"
 *   DETAIL: Failing row contains (..., reversal, 6, ...).
 *
 * ROOT CAUSE:
 *   `stock_transactions` is `PARTITION BY RANGE (transaction_date)`. The
 *   earlier migration `2025_07_26_000002_add_reversal_to_stock_transactions_
 *   reference_type_check.php` intended to add `'reversal'` to the allowed
 *   set, but it only:
 *     1. queried `pg_constraint` for the EXACT name
 *        `stock_transactions_reference_type_check` (so it never saw the
 *        suffixed copies on partitions), and
 *     2. ran `ALTER TABLE stock_transactions DROP/ADD CONSTRAINT` on the
 *        PARENT only.
 *
 *   PostgreSQL, when a partitioned parent already has partitions that carry
 *   their own (inherited or standalone) CHECK with the same base name,
 *   creates the propagated copy on each partition with a numeric suffix
 *   (`_check1`, `_check2`, ...) to avoid a name collision. The parent's
 *   `DROP CONSTRAINT` does NOT reliably remove these partition-local copies
 *   in all PG versions/scenarios, and the parent's `ADD CONSTRAINT` creates
 *   fresh LOOSE copies on partitions while the old STRICT copies remain —
 *   so an INSERT into a partition must satisfy BOTH, and the stale strict
 *   copy still rejects `'reversal'`.
 *
 *   The failing row was dated 2026-07-29, which falls into the DEFAULT
 *   partition `stock_transactions_default` (no monthly partition exists for
 *   2026-07 — the partitioning migration only created 2025-01..2025-12).
 *   That partition carried the stale strict constraint named
 *   `stock_transactions_reference_type_check1` (the `1` suffix is the
 *   collision-avoidance rename), so every reversal routed to the default
 *   partition blew up.
 *
 *   The APPLICATION layer is correct and must NOT be changed:
 *     - `StockTransaction::REFERENCE_TYPES` includes `'reversal'`.
 *     - `StockService::reverseTransaction()` writes `reference_type =>
 *       'reversal'` by design (used by sales-return, purchase-return,
 *       damage, stock-adjustment, and warehouse-transfer reversals).
 *     - `StockTransactionController` queries `where('reference_type',
 *       'reversal')`.
 *     - `database/sql/03_stock.sql` lists `'reversal'` in the base schema.
 *
 * FIX (this migration):
 *   1. Drop EVERY CHECK constraint whose name matches
 *      `^stock_transactions_reference_type_check` on the parent AND on
 *      every partition (found via `pg_inherits`). This covers the exact
 *      name AND every suffixed copy (`_check1`, `_check2`, ...).
 *   2. Add a single fresh `stock_transactions_reference_type_check` on the
 *      parent with the full 11-value allowed set (including `'reversal'`).
 *      With no stale copies left, PG propagates the new constraint cleanly
 *      to every existing partition (and every future `PARTITION OF`).
 *
 *   Because the constraint is being LOOSENED (adding `'reversal'` to the
 *   allowed IN-list), no existing row can violate the new constraint —
 *   every row that satisfied the old stricter set also satisfies the new
 *   looser one. So `ADD CONSTRAINT` is instant (no full table scan), even
 *   on large partitions.
 *
 *   Idempotent: safe to run multiple times (the DO block drops whatever
 *   matches, the ADD re-creates one).
 *
 *   This is the REAL fix — not a workaround. The app writes `'reversal'`
 *   by design; the DB must accept it on every partition.
 */
return new class extends Migration
{
    /**
     * The 11 allowed reference_type values. Kept in sync with
     * `StockTransaction::REFERENCE_TYPES` and the base schema in
     * `database/sql/03_stock.sql`.
     */
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

    /**
     * The original 10-value set (without 'reversal'). Used only by `down()`
     * to restore the pre-fix strict constraint, mirroring the contract of
     * the original `2025_07_26_000002` migration.
     *
     * WARNING: rolling back will FAIL if any `reference_type='reversal'`
     * row exists, because they would violate the restored strict
     * constraint. This is the expected behavior — you cannot un-loosen a
     * constraint when data exists that depends on the looser definition.
     */
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
        $this->replaceConstraintOnAllPartitions(self::ALLOWED_WITH_REVERSAL);
    }

    public function down(): void
    {
        $this->replaceConstraintOnAllPartitions(self::ALLOWED_WITHOUT_REVERSAL);
    }

    /**
     * Drop every CHECK constraint matching
     * `^stock_transactions_reference_type_check` on the parent AND every
     * partition, then add a single fresh constraint on the parent (PG
     * propagates it to all partitions).
     */
    private function replaceConstraintOnAllPartitions(array $allowed): void
    {
        $values = implode(',', array_map(
            fn (string $v): string => "'" . $v . "'",
            $allowed,
        ));

        // Step 1 — drop every matching CHECK on the parent + all partitions.
        // Matches the base name AND every suffixed copy (_check1, _check2, ...).
        // `~` is PostgreSQL regex match; `^` anchors to start so we only touch
        // constraints whose name STARTS with the base name.
        //
        // pg_inherits gives us every partition (monthly + default) of the
        // partitioned parent. We also include the parent itself.
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
                      AND (
                          c.relname = 'stock_transactions'
                          OR c.oid IN (
                              SELECT inhrelid
                              FROM pg_inherits
                              WHERE inhparent = 'stock_transactions'::regclass
                          )
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

        // Step 2 — add a single fresh constraint on the parent. PG propagates
        // it to every existing partition (and every future PARTITION OF).
        // With no stale copies remaining, there is no name collision, so the
        // partition copies inherit the base name (PG may still suffix them,
        // but they all carry the new looser allowed set).
        DB::statement(<<<SQL
            ALTER TABLE stock_transactions
            ADD CONSTRAINT stock_transactions_reference_type_check
            CHECK (reference_type IN ({$values}))
        SQL);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R7-bugfix (2026-07-22): SoftDeletes vs schema mismatch.
 *
 * Audit found 13 Eloquent models that declare `use SoftDeletes` but whose
 * underlying PostgreSQL tables were created WITHOUT a `deleted_at` column.
 * The first symptom was `SQLSTATE[42703]: Undefined column: 7 ERROR:
 * column customer_payments.deleted_at does not exist` while creating a
 * new customer (the CustomerController runs an aggregate over
 * customer_payments to compute the opening balance).
 *
 * Affected models / tables:
 *   - CustomerPayment      -> customer_payments
 *   - SalesChallan         -> sales_challans
 *   - SalesReturn          -> sales_returns
 *   - PurchaseOrder        -> purchase_orders
 *   - PurchaseReceive      -> purchase_receives
 *   - PurchaseReturn       -> purchase_returns
 *   - StockTakeSession     -> stock_take_sessions
 *   - StockAdjustment      -> stock_adjustments
 *   - DamageInvoice        -> damage_invoices
 *   - WarehouseTransfer    -> warehouse_transfers
 *   - CommissionRule       -> commission_rules
 *   - CommissionEntry      -> commission_entries
 *   - NotificationRule     -> notification_rules
 *
 * Fix: add `deleted_at timestamp(0) NULL` to each. This mirrors what the
 * project already did for `banks` in `2025_01_13_000001_add_soft_deletes_to_banks.php`.
 *
 * Why add the column rather than remove SoftDeletes from the models?
 *   1. Schema-only fix — no PHP code changes, no risk to existing controller
 *      callsites that may call `->withTrashed()` / `->onlyTrashed()` /
 *      `->restore()` in future code paths.
 *   2. Matches the project's existing convention (banks migration).
 *   3. Does NOT conflict with the `is_reversed` boolean used by some of
 *      these tables (customer_payments, supplier_payments) — `deleted_at`
 *      is for "row was soft-deleted by a user", `is_reversed` is for
 *      "transaction was cancelled/reversed"; they serve different purposes
 *      and can coexist.
 *
 * Idempotent: every ALTER TABLE is guarded by Schema::hasColumn().
 */
return new class extends Migration
{
    /**
     * Tables that need the deleted_at column added.
     * Keyed by table name for clarity; value is the corresponding model.
     */
    private const TABLES = [
        'customer_payments'    => 'CustomerPayment',
        'sales_challans'       => 'SalesChallan',
        'sales_returns'        => 'SalesReturn',
        'purchase_orders'      => 'PurchaseOrder',
        'purchase_receives'    => 'PurchaseReceive',
        'purchase_returns'     => 'PurchaseReturn',
        'stock_take_sessions'  => 'StockTakeSession',
        'stock_adjustments'    => 'StockAdjustment',
        'damage_invoices'      => 'DamageInvoice',
        'warehouse_transfers'  => 'WarehouseTransfer',
        'commission_rules'     => 'CommissionRule',
        'commission_entries'   => 'CommissionEntry',
        'notification_rules'   => 'NotificationRule',
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            // Schema::hasColumn would throw if the table itself doesn't exist,
            // so guard with hasTable first — defensive against partial migrations.
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (!Schema::hasColumn($table, 'deleted_at')) {
                DB::statement(
                    'ALTER TABLE "' . $table . '" '
                    . 'ADD COLUMN deleted_at timestamp(0) without time zone NULL'
                );
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'deleted_at')) {
                DB::statement(
                    'ALTER TABLE "' . $table . '" DROP COLUMN deleted_at'
                );
            }
        }
    }
};

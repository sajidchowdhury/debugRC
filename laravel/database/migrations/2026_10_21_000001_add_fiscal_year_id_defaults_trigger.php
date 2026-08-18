<?php

/**
 * Add DB-level safety net: BEFORE INSERT triggers on fiscal-scoped tables
 * that auto-populate fiscal_year_id from the currently-active fiscal year
 * when the column is NULL.
 *
 * This is a DEFENSE-IN-DEPTH measure. The primary mechanism is:
 *   1. BelongsToFiscalYear trait's creating() event (for Eloquent inserts)
 *   2. FiscalYearResolver::activeId() in every service's DB::table() inserts
 *
 * This trigger catches any edge case that bypasses both — e.g., raw SQL
 * from console commands, data repair scripts, or future code that forgets
 * to set the column.
 *
 * The trigger function is a single shared function that:
 *   1. Checks if NEW.fiscal_year_id IS NULL
 *   2. Resolves the active FY from fiscal_years (status='active' AND is_current=true)
 *   3. Sets NEW.fiscal_year_id to the resolved value
 *   4. Raises an exception if no active FY exists (fail-closed)
 *
 * Implemented after Session 2 fiscal_year_id NOT NULL violations were
 * discovered in test runs (2059 tests, 192 errors, 64 failures).
 */
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * All tables that carry fiscal_year_id NOT NULL.
     * Sourced from config/fiscal.php 'tables'.
     */
    private const FISCAL_TABLES = [
        'sales_invoices',
        'sales_invoice_items',
        'sales_challans',
        'sales_challan_items',
        'sales_returns',
        'sales_return_items',
        'customer_payments',
        'customer_ledger',
        'purchase_orders',
        'purchase_order_items',
        'purchase_receives',
        'purchase_receive_items',
        'purchase_returns',
        'purchase_return_items',
        'supplier_payments',
        'supplier_ledger',
        'stock_transactions',
        'stock_adjustments',
        'stock_adjustment_items',
        'stock_take_sessions',
        'stock_take_warehouses',
        // Note: stock_take_items does NOT have fiscal_year_id column
        // (inherits FY scope via parent stock_take_sessions)
        'warehouse_transfers',
        'warehouse_transfer_items',
        'damage_invoices',
        'damage_invoice_items',
        'branch_demands',
        'branch_demand_items',
        'branch_demand_repricing',
        'branch_ledger',
        'journal_entries',
        'journal_lines',
        'manual_journals',
        'other_incomes',
        'other_expenses',
        'money_transfers',
        'employee_transactions',
    ];

    public function up(): void
    {
        // Create the shared trigger function
        DB::unprepared("
            CREATE OR REPLACE FUNCTION fn_set_fiscal_year_id_default()
            RETURNS TRIGGER AS \$\$
            DECLARE
                active_fy_id INTEGER;
            BEGIN
                IF NEW.fiscal_year_id IS NULL THEN
                    -- Resolve active FY: prefer status='active' AND is_current=true
                    SELECT id INTO active_fy_id
                    FROM fiscal_years
                    WHERE status = 'active' AND is_current = true
                    LIMIT 1;

                    -- Fallback: any active FY
                    IF active_fy_id IS NULL THEN
                        SELECT id INTO active_fy_id
                        FROM fiscal_years
                        WHERE status = 'active'
                        LIMIT 1;
                    END IF;

                    -- Fallback: any is_current=true FY
                    IF active_fy_id IS NULL THEN
                        SELECT id INTO active_fy_id
                        FROM fiscal_years
                        WHERE is_current = true
                        LIMIT 1;
                    END IF;

                    IF active_fy_id IS NULL THEN
                        RAISE EXCEPTION 'Cannot insert into % without fiscal_year_id: no active fiscal year found.',
                            TG_TABLE_NAME;
                    END IF;

                    NEW.fiscal_year_id := active_fy_id;
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        // Safety: drop any stale trigger on tables that do NOT have fiscal_year_id.
        // stock_take_items inherits FY scope via parent stock_take_sessions
        // and does NOT carry the fiscal_year_id column.
        DB::unprepared("DROP TRIGGER IF EXISTS trg_fy_default_stock_take_items ON stock_take_items;");

        // Create triggers on each fiscal-scoped table
        foreach (self::FISCAL_TABLES as $table) {
            $triggerName = "trg_fy_default_{$table}";

            DB::unprepared("
                DROP TRIGGER IF EXISTS {$triggerName} ON {$table};
                CREATE TRIGGER {$triggerName}
                    BEFORE INSERT ON {$table}
                    FOR EACH ROW
                    EXECUTE FUNCTION fn_set_fiscal_year_id_default();
            ");
        }
    }

    public function down(): void
    {
        // Drop all triggers
        foreach (self::FISCAL_TABLES as $table) {
            $triggerName = "trg_fy_default_{$table}";
            DB::unprepared("DROP TRIGGER IF EXISTS {$triggerName} ON {$table};");
        }

        // Drop the shared function
        DB::unprepared("DROP FUNCTION IF EXISTS fn_set_fiscal_year_id_default();");
    }
};

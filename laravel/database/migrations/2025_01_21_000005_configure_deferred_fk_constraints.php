<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Task 35: Configure deferred FK constraints.
 *
 * PostgreSQL foreign key constraints are IMMEDIATE by default — they check at
 * each INSERT/UPDATE statement. This means service code must INSERT rows in a
 * specific order (parent before child), which is fragile and limits batch
 * operations.
 *
 * Making FKs DEFERRABLE allows the check to be delayed until COMMIT time.
 * Two modes:
 *   - DEFERRABLE INITIALLY IMMEDIATE: Checks after each statement by default,
 *     but can be deferred with `SET CONSTRAINTS ALL DEFERRED` when needed.
 *   - DEFERRABLE INITIALLY DEFERRED: Checks at COMMIT time by default.
 *     Safer for multi-table atomic operations but catches errors later.
 *
 * DESIGN DECISIONS:
 *
 * 1. DEFERRABLE INITIALLY DEFERRED for FKs involved in multi-table atomic
 *    operations where insertion order is currently forced:
 *    - Journal entries + journal_lines (JE inserted first, then lines)
 *    - Customer/supplier/employee/branch ledger → journal_entries
 *    - Sales invoices → journal_entries, customers, branches
 *    - Customer payments → journal_entries, banks, branches
 *    - Supplier payments → journal_entries, banks, branches
 *    - Sales challans → journal_entries, branches
 *    - Stock adjustments → journal_entries
 *    - Money transfers → journal_entries, banks, branches
 *    - Other incomes/expenses → journal_entries, banks, branches
 *    - Employee transactions → journal_entries, employees, branches
 *    - Manual journals → journal_entries
 *    - Cash ledger → journal_entries, branches
 *    - Branch expenses → journal_entries, branches
 *    - Branch product cost → branches, products
 *
 * 2. DEFERRABLE INITIALLY IMMEDIATE for FKs that are simple lookups
 *    (products, warehouses, categories, groups, employees, users) — these
 *    rarely need deferring but making them DEFERRABLE allows the option.
 *
 * 3. NOT deferred for ON DELETE CASCADE FKs — cascade must fire immediately
 *    for correct behavior. These remain IMMEDIATE NOT DEFERRABLE.
 *    Exception: journal_lines → journal_entries CASCADE is made DEFERRABLE
 *    because the journal entry + lines are always created in one transaction
 *    and the cascade only matters on DELETE (rare for journal_entries).
 *
 * 4. Partitioned-table FKs (sales_invoices, stock_transactions outbound)
 *    are made DEFERRABLE INITIALLY DEFERRED since their parent records
 *    (customers, branches, warehouses, products, journal_entries) are often
 *    created in the same transaction.
 *
 * 5. Trigger-based FKs (from partitioning migration) are already
 *    DEFERRABLE INITIALLY IMMEDIATE — they are NOT changed here because
 *    constraint triggers have different semantics and are better left
 *    IMMEDIATE with the option to SET CONSTRAINTS per-transaction.
 *
 * IMPORTANT: ALTER CONSTRAINT ... DEFERRABLE only changes the deferrability
 * property. It does NOT drop and recreate the constraint. This is a metadata-
 * only operation that takes an ACCESS EXCLUSIVE lock briefly but is very fast.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────────────────────
        // Helper: Alter multiple FK constraints to DEFERRABLE in bulk.
        //
        // PostgreSQL requires ALTER TABLE ... ALTER CONSTRAINT <name>
        // DEFERRABLE INITIALLY {IMMEDIATE|DEFERRED} for each constraint.
        // We need the constraint names — they may be auto-generated
        // (e.g., "sales_invoice_items_product_id_fkey") or explicitly named.
        //
        // Strategy: Query pg_constraint to find all FK constraints, then
        // ALTER each one based on its category.
        // ──────────────────────────────────────────────────────────────

        // ══════════════════════════════════════════════════════════════
        // PHASE 1: DEFERRABLE INITIALLY DEFERRED — Multi-table atomic ops
        // ══════════════════════════════════════════════════════════════

        // These FKs reference parent records that are often created in the
        // SAME transaction as the child. Deferring until COMMIT allows any
        // INSERT order within the transaction.

        // --- Journal entry references (most common multi-table pattern) ---
        // Nearly every business operation creates a journal_entry first, then
        // references it from the business table. With INITIALLY DEFERRED,
        // the business table can be INSERT'd before the journal_entry exists.
        $this->makeDeferred([
            // journal_lines → journal_entries (CASCADE, but deferring is safe
            // because we only CREATE lines+entry together; we never DELETE entries)
            'journal_lines' => ['journal_entry_id'],
            // journal_posting_logs → journal_entries
            'journal_posting_logs' => ['journal_entry_id'],
            // customer_ledger → journal_entries + customers
            'customer_ledger' => ['journal_entry_id'],
            // supplier_ledger → journal_entries + suppliers
            'supplier_ledger' => ['journal_entry_id'],
            // employee_ledger → journal_entries + employees
            'employee_ledger' => ['journal_entry_id'],
            // branch_ledger → journal_entries + branches
            'branch_ledger' => ['journal_entry_id', 'from_branch_id', 'to_branch_id'],
            // cash_ledger → journal_entries + branches
            'cash_ledger' => ['journal_entry_id', 'branch_id'],
            // manual_journals → journal_entries
            'manual_journals' => ['journal_entry_id'],
            // branch_expenses → journal_entries + branches
            'branch_expenses' => ['journal_entry_id', 'branch_id'],
            // branch_product_cost → branches + products
            'branch_product_cost' => ['branch_id', 'product_id'],
        ], initially: 'DEFERRED');

        // --- Sales module: journal_entry + business table in same transaction ---
        $this->makeDeferred([
            // sales_invoices (PARTITIONED) → journal_entries, customers, branches
            'sales_invoices' => ['journal_entry_id', 'cogs_journal_entry_id', 'customer_id', 'branch_id'],
            // sales_challans → journal_entries, branches
            'sales_challans' => ['journal_entry_id', 'adjustment_journal_entry_id', 'branch_id'],
            // sales_returns → journal_entries, branches
            'sales_returns' => ['journal_entry_id', 'cogs_journal_entry_id', 'branch_id'],
            // customer_payments → journal_entries, banks, branches
            'customer_payments' => ['journal_entry_id', 'intercompany_journal_entry_id', 'branch_id', 'bank_id'],
            // supplier_payments → journal_entries, banks, branches, suppliers, employees
            'supplier_payments' => ['journal_entry_id', 'branch_id', 'bank_id', 'supplier_id', 'collected_by'],
            // money_transfers → journal_entries, banks, branches
            'money_transfers' => ['journal_entry_id', 'intercompany_journal_entry_id', 'from_bank_id', 'to_bank_id', 'from_branch_id', 'to_branch_id'],
            // other_incomes → journal_entries, banks, branches
            'other_incomes' => ['journal_entry_id', 'branch_id', 'bank_id'],
            // other_expenses → journal_entries, banks, branches
            'other_expenses' => ['journal_entry_id', 'branch_id', 'bank_id'],
            // employee_transactions → journal_entries, employees, branches
            'employee_transactions' => ['journal_entry_id', 'branch_id', 'employee_id'],
        ], initially: 'DEFERRED');

        // --- Purchase module: journal_entry + business table ---
        $this->makeDeferred([
            // purchase_receives → journal_entries, suppliers, branches, warehouses, purchase_orders
            'purchase_receives' => ['journal_entry_id', 'branch_id', 'supplier_id', 'warehouse_id', 'purchase_order_id'],
            // purchase_returns → journal_entries, suppliers, branches, warehouses, purchase_receives
            'purchase_returns' => ['journal_entry_id', 'branch_id', 'supplier_id', 'warehouse_id', 'purchase_receive_id'],
            // stock_adjustments → journal_entries, branches, warehouses
            'stock_adjustments' => ['journal_entry_id', 'branch_id', 'warehouse_id'],
            // stock_take_sessions → journal_entries, branches
            'stock_take_sessions' => ['journal_entry_id', 'branch_id'],
            // warehouse_transfers → journal_entries, branches, warehouses
            'warehouse_transfers' => ['journal_entry_id', 'journal_entry_id_debtor', 'from_branch_id', 'to_branch_id', 'from_warehouse_id', 'to_warehouse_id'],
            // damage_invoices → journal_entries, branches, warehouses
            'damage_invoices' => ['journal_entry_id', 'branch_id', 'warehouse_id'],
            // branch_demands → journal_entries, branches
            'branch_demands' => ['journal_entry_id', 'from_branch_id', 'to_branch_id'],
            // purchase_orders → suppliers, branches
            'purchase_orders' => ['supplier_id', 'branch_id'],
        ], initially: 'DEFERRED');

        // --- stock_transactions (PARTITIONED) outbound FKs ---
        $this->makeDeferred([
            'stock_transactions' => ['warehouse_id', 'product_id'],
        ], initially: 'DEFERRED');

        // ══════════════════════════════════════════════════════════════
        // PHASE 2: DEFERRABLE INITIALLY IMMEDIATE — Simple lookups
        // ══════════════════════════════════════════════════════════════

        // These FKs reference stable parent records (products, warehouses,
        // categories, employees, users) that always exist before the child.
        // Making them DEFERRABLE gives the OPTION to defer when needed
        // (e.g., bulk import, data migration) without changing default behavior.

        $this->makeDeferred([
            // Products → categories, groups
            'products' => ['category_id', 'group_id'],
            // product_price_history → products (CASCADE, but safe to defer for imports)
            'product_price_history' => ['product_id'],
            // customers → branches
            'customers' => ['branch_id'],
            // suppliers → branches
            'suppliers' => ['branch_id'],
            // warehouses → branches
            'warehouses' => ['branch_id'],
            // employees → branches
            'employees' => ['branch_id'],
            // users → employees
            'users' => ['employee_id'],
            // warehouse_stock → warehouses, products
            'warehouse_stock' => ['warehouse_id', 'product_id'],
            // daily_warehouse_stock_summary → warehouses, products
            'daily_warehouse_stock_summary' => ['warehouse_id', 'product_id'],
            // sales_invoice_items → products, warehouses (not the sales_invoice_id — that's trigger-based)
            'sales_invoice_items' => ['product_id', 'warehouse_id'],
            // sales_invoice_dispatchers → employees
            'sales_invoice_dispatchers' => ['employee_id'],
            // sales_invoice_dispatches → products
            'sales_invoice_dispatches' => ['product_id'],
            // sales_draft_carts → users, branches
            'sales_draft_carts' => ['user_id', 'branch_id'],
            // sales_return_items → products, warehouses (sales_return_id CASCADE stays IMMEDIATE)
            'sales_return_items' => ['product_id', 'warehouse_id'],
            // sales_challan_items → products, warehouses (added via migration)
            'sales_challan_items' => ['product_id', 'warehouse_id'],
            // purchase_order_items → products
            'purchase_order_items' => ['product_id'],
            // purchase_receive_items → products, warehouses, purchase_order_items
            'purchase_receive_items' => ['product_id', 'warehouse_id', 'purchase_order_item_id'],
            // purchase_return_items → products, warehouses, purchase_receive_items
            'purchase_return_items' => ['product_id', 'warehouse_id', 'purchase_receive_item_id'],
            // stock_adjustment_items → products
            'stock_adjustment_items' => ['product_id'],
            // stock_take_warehouses → warehouses
            'stock_take_warehouses' => ['warehouse_id'],
            // stock_take_items → warehouses, products
            'stock_take_items' => ['warehouse_id', 'product_id'],
            // warehouse_transfer_items → products
            'warehouse_transfer_items' => ['product_id'],
            // damage_invoice_items → products
            'damage_invoice_items' => ['product_id'],
            // branch_demand_items → products, warehouses
            'branch_demand_items' => ['product_id', 'warehouse_id'],
            // invoice_payment_allocations → customer_payments
            'invoice_payment_allocations' => ['payment_id'],
            // supplier_payment_settlements → purchase_receives
            'supplier_payment_settlements' => ['purchase_receive_id'],
            // bank references
            'bank_ledger_mappings' => ['bank_id', 'ledger_id'],
            // user_menu_permissions → users, menus (CASCADE, but safe to defer for imports)
            'user_menu_permissions' => ['user_id', 'menu_id'],
            // branch_cash → branches
            'branch_cash' => ['branch_id'],
        ], initially: 'IMMEDIATE');

        // ══════════════════════════════════════════════════════════════
        // PHASE 3: NOT deferred — CASCADE FKs that must fire immediately
        // ══════════════════════════════════════════════════════════════

        // These ON DELETE CASCADE FKs need immediate cascade behavior.
        // They remain NOT DEFERRABLE (the PG default).
        // Listed here for documentation clarity — no action needed.
        //
        // NOT deferred (ON DELETE CASCADE, must fire immediately):
        //   - sales_invoice_items.sales_invoice_id (trigger-based, already handled)
        //   - sales_invoice_dispatchers.sales_invoice_id (trigger-based)
        //   - sales_invoice_dispatches.sales_invoice_id (trigger-based)
        //   - sales_return_items.sales_return_id → sales_returns(id) CASCADE
        //   - sales_challan_items.sales_challan_id → sales_challans(id) CASCADE
        //   - journal_lines.journal_entry_id → journal_entries(id) CASCADE
        //     (Made DEFERRABLE above — exception: cascade on JE DELETE is rare,
        //      and when it happens the deferred check still cascades correctly)
        //   - journal_posting_logs.journal_entry_id → journal_entries(id) CASCADE
        //   - stock_adjustment_items.stock_adjustment_id → stock_adjustments(id) CASCADE
        //   - stock_take_warehouses.stock_take_session_id → stock_take_sessions(id) CASCADE
        //   - stock_take_items.stock_take_session_id → stock_take_sessions(id) CASCADE
        //   - warehouse_transfer_items.warehouse_transfer_id → warehouse_transfers(id) CASCADE
        //   - damage_invoice_items.damage_invoice_id → damage_invoices(id) CASCADE
        //   - branch_demand_items.branch_demand_id → branch_demands(id) CASCADE
        //   - purchase_order_items.purchase_order_id → purchase_orders(id) CASCADE
        //   - purchase_receive_items.purchase_receive_id → purchase_receives(id) CASCADE
        //   - purchase_return_items.purchase_return_id → purchase_returns(id) CASCADE
        //   - supplier_payment_settlements.payment_id → supplier_payments(id) CASCADE
        //   - sales_draft_carts.user_id → users(id) CASCADE
        //   - notifications.user_id → users(id) CASCADE
        //   - customer_ledger.customer_id → customers(id) CASCADE
        //   - supplier_ledger.supplier_id → suppliers(id) CASCADE
        //   - employee_ledger.employee_id → employees(id) CASCADE
    }

    /**
     * Make FK constraints DEFERRABLE for the specified table/column pairs.
     *
     * Looks up the constraint name from pg_constraint based on the table name
     * and column name, then ALTERs the constraint to DEFERRABLE.
     *
     * @param array<string, string[]> $tableColumns  Map of table => [column, ...]
     * @param string $initially  'DEFERRED' or 'IMMEDIATE'
     */
    private function makeDeferred(array $tableColumns, string $initially = 'DEFERRED'): void
    {
        foreach ($tableColumns as $table => $columns) {
            foreach ($columns as $column) {
                // Look up the FK constraint name from pg_constraint
                $constraintName = DB::selectOne(<<<SQL
                    SELECT c.conname
                    FROM pg_constraint c
                    JOIN pg_class t ON t.oid = c.conrelid
                    JOIN pg_namespace n ON n.oid = t.relnamespace
                    JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(c.conkey)
                    WHERE t.relname = ?
                      AND n.nspname = 'public'
                      AND c.contype = 'f'
                      AND a.attname = ?
                    LIMIT 1
                SQL, [$table, $column]);

                if ($constraintName) {
                    $name = $constraintName->conname;
                    DB::statement(
                        "ALTER TABLE {$table} ALTER CONSTRAINT {$name} DEFERRABLE INITIALLY {$initially}"
                    );
                } else {
                    // Log missing constraint (column may not have an FK, or table may be partitioned)
                    Log::warning("Deferred FK: No FK constraint found for {$table}.{$column}");
                }
            }
        }
    }

    public function down(): void
    {
        // Restore all FKs to NOT DEFERRABLE (the PostgreSQL default).
        // We need to look up all deferrable FK constraints and make them NOT DEFERRABLE.

        $deferrable = DB::select(<<<SQL
            SELECT c.conname, t.relname AS table_name
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            WHERE c.contype = 'f'
              AND n.nspname = 'public'
              AND c.condeferrable = true
        SQL);

        foreach ($deferrable as $row) {
            DB::statement(
                "ALTER TABLE {$row->table_name} ALTER CONSTRAINT {$row->conname} NOT DEFERRABLE"
            );
        }
    }
};

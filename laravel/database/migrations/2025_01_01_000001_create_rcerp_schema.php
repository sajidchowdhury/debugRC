<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2.2 — Baseline schema migration: Auth + Master Data.
 *
 * This migration creates the PostgreSQL schema by executing raw SQL files.
 * It is the baseline (not incremental) — it represents the full converted schema
 * from the MySQL dump osudlagb_remotecenter.sql.
 *
 * Run on the VPS after: composer create-project laravel/laravel, then php artisan migrate.
 */
return new class extends Migration
{
    private function sqlFile(string $name): string
    {
        return base_path("database/sql/{$name}.sql");
    }

    private function executeSqlFile(string $name): void
    {
        $path = $this->sqlFile($name);
        if (!file_exists($path)) {
            throw new RuntimeException("SQL file not found: {$path}");
        }
        $sql = file_get_contents($path);
        // Split on semicolons that end a statement (naive but works for our DDL — no functions/procedures with internal semicolons except $$ blocks which are handled by PG).
        // For $$ blocks (functions/triggers), we use a smarter split: split on ";\n" but not inside $$ ... $$.
        $statements = $this->splitSql($sql);
        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }
            // Only skip statements that contain NOTHING but comments/whitespace.
            // PostgreSQL handles leading -- comments natively, so a statement
            // like "-- header\nCREATE TABLE branches (...);" is perfectly valid.
            //
            // CRITICAL: We must NOT use str_starts_with($stmt, '--') here, because
            // splitSql bundles the file header comments together with the first
            // CREATE TABLE into a single statement. Skipping such a statement
            // would silently drop the first table of every SQL file, causing
            // "relation X does not exist" errors on the next table that
            // references it via a foreign key.
            $withoutComments = preg_replace('/^[ \t]*--[^\n]*$/m', '', $stmt);
            if (trim($withoutComments) === '') {
                continue;
            }
            DB::statement($stmt);
        }
    }

    /**
     * Split SQL into individual statements, respecting $$ ... $$ dollar-quoted blocks.
     */
    private function splitSql(string $sql): array
    {
        $statements = [];
        $current = '';
        $inDollarQuote = false;
        $dollarTag = '';
        $lines = preg_split('/\r\n|\r|\n/', $sql);

        foreach ($lines as $line) {
            // Track dollar-quoted strings (function bodies)
            if (!$inDollarQuote) {
                if (preg_match('/\$\$(.*)$/', $line, $m)) {
                    $inDollarQuote = true;
                    // Check if the $$ is closed on the same line
                    $afterTag = $m[1];
                    if (str_contains($afterTag, '$$')) {
                        $inDollarQuote = false;
                    }
                }
            } else {
                if (str_contains($line, '$$')) {
                    $inDollarQuote = false;
                }
            }

            $current .= $line . "\n";

            // End of statement: line ends with ; and we're not inside a dollar-quoted block
            if (!$inDollarQuote && preg_match('/;\s*$/', $line)) {
                $statements[] = $current;
                $current = '';
            }
        }
        if (trim($current) !== '') {
            $statements[] = $current;
        }
        return $statements;
    }

    public function up(): void
    {
        $this->executeSqlFile('01_auth_and_master');
        $this->executeSqlFile('02_accounting');
        $this->executeSqlFile('03_stock');
        $this->executeSqlFile('04_sales');
        $this->executeSqlFile('05_purchase');
        $this->executeSqlFile('06_payment_and_misc');
        $this->executeSqlFile('07_views_triggers_constraints');
    }

    public function down(): void
    {
        // Drop in reverse dependency order.
        DB::statement('DROP VIEW IF EXISTS v_journal_entries_with_lines CASCADE');

        $tables = [
            'user_audit_log', 'login_rate_limits', 'investigation_activators',
            'notifications', 'employee_transactions', 'other_expenses', 'other_incomes',
            'money_transfers', 'supplier_payment_settlements', 'supplier_payments',
            'customer_payment_settlements', 'customer_payments',
            'invoice_payment_allocations',
            'purchase_return_items', 'purchase_returns',
            'purchase_receive_items', 'purchase_receives',
            'purchase_order_items', 'purchase_orders',
            'sales_return_items', 'sales_returns',
            'sales_draft_carts', 'sales_challans',
            'sales_invoice_dispatches', 'sales_invoice_dispatchers', 'sales_invoice_items',
            'sales_invoices',
            'branch_demand_items', 'branch_demands',
            'daily_warehouse_stock_summary',
            'damage_invoice_items', 'damage_invoices',
            'warehouse_transfer_items', 'warehouse_transfers',
            'stock_take_items', 'stock_take_warehouses', 'stock_take_sessions',
            'stock_adjustment_items', 'stock_adjustments',
            'warehouse_stock', 'stock_transactions',
            'manual_journals', 'accounting_periods', 'schema_migrations',
            'cash_ledger', 'branch_product_cost', 'branch_expenses', 'branch_cash',
            'branch_ledger',
            'employee_ledger', 'supplier_ledger', 'customer_ledger',
            'document_sequences', 'journal_posting_logs',
            'journal_lines', 'journal_entries', 'ledgers',
            'warehouses', 'bank_ledger_mappings', 'banks',
            'suppliers', 'customers',
            'product_price_history', 'product_groups', 'product_categories', 'products',
            'user_menu_permissions', 'menus', 'users', 'employees', 'branches',
        ];

        foreach ($tables as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table} CASCADE");
        }

        // Drop trigger functions
        DB::statement("DROP FUNCTION IF EXISTS enforce_balanced_journal_entry() CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS prevent_negative_stock() CASCADE");
        DB::statement("DROP FUNCTION IF EXISTS update_updated_at_column() CASCADE");
    }
};

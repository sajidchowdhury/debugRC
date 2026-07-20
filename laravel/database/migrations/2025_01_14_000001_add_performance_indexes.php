<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 14: Add missing performance indexes.
 *
 * The original SQL schema files were missing several indexes that are
 * critical for admin page performance. Without these indexes, the
 * BaseMasterDataController::indexStats() queries do full table scans
 * on every page load, causing slow page loading times.
 *
 * This migration adds partial indexes on is_active for all master-data
 * tables, plus FK indexes for common join queries.
 *
 * Idempotent: uses CREATE INDEX IF NOT EXISTS.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Partial indexes on is_active (most admin pages query active records)
        $partialIndexes = [
            'branches'   => 'idx_branches_active',
            'warehouses' => 'idx_warehouses_active',
            'employees'  => 'idx_employees_active',
            'products'   => 'idx_products_active',
            'customers'  => 'idx_customers_active',
            'suppliers'  => 'idx_suppliers_active',
            'banks'      => 'idx_banks_active',
            'users'      => 'idx_users_active',
        ];

        foreach ($partialIndexes as $table => $indexName) {
            DB::statement(
                "CREATE INDEX IF NOT EXISTS {$indexName} ON {$table} (is_active) WHERE is_active = true"
            );
        }

        // FK indexes for common join queries
        $fkIndexes = [
            ['warehouses', 'branch_id', 'idx_warehouses_branch'],
            ['employees', 'branch_id', 'idx_employees_branch'],
            ['customers', 'branch_id', 'idx_customers_branch'],
            ['users', 'employee_id', 'idx_users_employee'],
            ['products', 'category_id', 'idx_products_category'],
            ['products', 'group_id', 'idx_products_group'],
            ['banks', 'ledger_id', 'idx_banks_ledger'],
            ['bank_ledger_mappings', 'bank_id', 'idx_blm_bank'],
            ['bank_ledger_mappings', 'ledger_id', 'idx_blm_ledger'],
        ];

        foreach ($fkIndexes as [$table, $column, $indexName]) {
            DB::statement(
                "CREATE INDEX IF NOT EXISTS {$indexName} ON {$table} ({$column})"
            );
        }

        // Analyze tables for query planner statistics
        DB::statement('ANALYZE');
    }

    public function down(): void
    {
        $indexes = [
            'idx_branches_active', 'idx_warehouses_active', 'idx_employees_active',
            'idx_products_active', 'idx_customers_active', 'idx_suppliers_active',
            'idx_banks_active', 'idx_users_active',
            'idx_warehouses_branch', 'idx_employees_branch', 'idx_customers_branch',
            'idx_users_employee', 'idx_products_category', 'idx_products_group',
            'idx_banks_ledger', 'idx_blm_bank', 'idx_blm_ledger',
        ];

        foreach ($indexes as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }
    }
};

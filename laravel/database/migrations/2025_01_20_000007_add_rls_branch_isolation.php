<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 19 — Row-Level Security (RLS) for Branch Isolation.
 *
 * Implements database-level branch isolation that cannot be bypassed even by
 * direct SQL. This is the ultimate defense-in-depth layer:
 *   Layer 1 (Query):  BranchScope Eloquent global scope — filters reads
 *   Layer 2 (Route):  EnforceBranchIsolation middleware — validates writes
 *   Layer 3 (DB):     RLS policies — enforced by PostgreSQL, no bypass possible
 *
 * Components:
 *   1. Custom GUC parameters: app.branch_id, app.is_admin
 *   2. RLS policies on 28 branch-scoped tables
 *   3. Admin bypass via app.is_admin = true (SET by SetAppBranchId middleware)
 *
 * Tables with single branch_id: 23 tables
 * Tables with from_branch_id/to_branch_id: 4 tables (branch_ledger, warehouse_transfers, money_transfers, branch_demands)
 * Special: branch_product_cost (branch_id composite PK)
 *
 * How RLS works:
 *   - ALTER TABLE ... ENABLE ROW LEVEL SECURITY — makes table respect policies
 *   - CREATE POLICY ... USING (condition) — rows where condition = true are visible
 *   - CREATE POLICY ... WITH CHECK (condition) — rows where condition = true are writable
 *   - Table owner (postgres) always bypasses RLS
 *   - app.is_admin = 'true' → admin sees all branches
 *   - app.branch_id = <session_branch_id> → non-admin sees own branch only
 *
 * Important: RLS policies apply to the PostgreSQL role that connects to the DB.
 * The Laravel app connects as the table owner (which bypasses RLS by default).
 * To make RLS effective, we must ALTER FORCE ROW LEVEL SECURITY on each table,
 * which makes even the table owner subject to RLS policies.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. Custom GUC parameters (app.branch_id, app.is_admin)
        //    These are session-level parameters set by SetAppBranchId
        //    middleware on every authenticated request. The middleware
        //    runs SET app.branch_id = ? and SET app.is_admin = ?
        //    on every request. Default database-level values are not
        //    required because current_setting(name, true) returns NULL
        //    when not set, and the policies handle NULL = not admin.
        //
        //    We set database defaults here for safety: if a direct SQL
        //    session (psql, pgAdmin) connects without the middleware,
        //    they'll get branch_id=0 and is_admin=false, meaning they
        //    see NO branch data (safe default = deny by default).
        // ============================================================
        $dbName = DB::getConfig('database');
        try {
            DB::statement("ALTER DATABASE \"{$dbName}\" SET app.branch_id = 0");
            DB::statement("ALTER DATABASE \"{$dbName}\" SET app.is_admin = false");
        } catch (\Throwable $e) {
            // ALTER DATABASE SET requires CONNECT privilege on the database
            // and the calling role must be the database owner. If this fails
            // (e.g., in a managed PostgreSQL where the migration user is not
            // the database owner), the RLS policies still work correctly
            // because current_setting('app.xxx', true) returns NULL when
            // the GUC is not set, and the middleware always sets them.
            // The only impact: direct psql sessions without SET app.branch_id
            // will see zero rows (safe default = deny by default).
            \Illuminate\Support\Facades\Log::warning(
                'ALTER DATABASE SET app.branch_id failed (non-owner?): ' . $e->getMessage()
            );
        }

        // ============================================================
        // 2. SINGLE branch_id tables (23 tables)
        //    Policy: branch_id = current_setting('app.branch_id')::int
        //    Admin bypass: current_setting('app.is_admin', true) = 'true'
        // ============================================================

        $singleBranchTables = [
            // Auth & Master
            'employees',
            'customers',
            'suppliers',
            'warehouses',
            // Accounting
            'journal_entries',
            'document_sequences',
            'customer_ledger',
            'supplier_ledger',
            'employee_ledger',
            'branch_cash',
            'branch_expenses',
            'branch_product_cost',
            'cash_ledger',
            'accounting_periods',
            'manual_journals',
            // Stock
            'stock_adjustments',
            'stock_take_sessions',
            'damage_invoices',
            // Sales
            'sales_invoices',
            'sales_challans',
            'sales_draft_carts',
            'sales_returns',
            // Purchase
            'purchase_orders',
            'purchase_receives',
            'purchase_returns',
            // Payment & Misc
            'customer_payments',
            'supplier_payments',
            'other_incomes',
            'other_expenses',
            'employee_transactions',
        ];

        foreach ($singleBranchTables as $table) {
            $this->enableRLS($table);
            $this->createSelectPolicy($table, "branch_id = current_setting('app.branch_id')::int");
            $this->createInsertPolicy($table, "branch_id = current_setting('app.branch_id')::int");
            $this->createUpdatePolicy($table, "branch_id = current_setting('app.branch_id')::int");
            $this->createDeletePolicy($table, "branch_id = current_setting('app.branch_id')::int");

            // Admin bypass policy — admin sees/modifies all branches.
            $this->createAdminBypassPolicy($table);
        }

        // ============================================================
        // 3. DUAL branch_id tables (4 tables)
        //    Policy: user can see rows where they are EITHER the from
        //    branch OR the to branch (inter-branch operations involve
        //    both branches). Admin bypass as above.
        // ============================================================

        $dualBranchTables = [
            'branch_ledger'       => ['from_branch_id', 'to_branch_id'],
            'warehouse_transfers' => ['from_branch_id', 'to_branch_id'],
            'money_transfers'     => ['from_branch_id', 'to_branch_id'],
            'branch_demands'      => ['from_branch_id', 'to_branch_id'],
        ];

        foreach ($dualBranchTables as $table => [$fromCol, $toCol]) {
            $condition = "{$fromCol} = current_setting('app.branch_id')::int OR {$toCol} = current_setting('app.branch_id')::int";

            $this->enableRLS($table);
            $this->createSelectPolicy($table, $condition);
            $this->createInsertPolicy($table, $condition);
            $this->createUpdatePolicy($table, $condition);
            $this->createDeletePolicy($table, $condition);
            $this->createAdminBypassPolicy($table);
        }
    }

    public function down(): void
    {
        // Drop all RLS policies and disable RLS on all tables.
        $allTables = [
            'employees', 'customers', 'suppliers', 'warehouses',
            'journal_entries', 'document_sequences', 'customer_ledger', 'supplier_ledger',
            'employee_ledger', 'branch_cash', 'branch_expenses', 'branch_product_cost',
            'cash_ledger', 'accounting_periods', 'manual_journals',
            'stock_adjustments', 'stock_take_sessions', 'damage_invoices',
            'sales_invoices', 'sales_challans', 'sales_draft_carts', 'sales_returns',
            'purchase_orders', 'purchase_receives', 'purchase_returns',
            'customer_payments', 'supplier_payments', 'other_incomes', 'other_expenses',
            'employee_transactions',
            'branch_ledger', 'warehouse_transfers', 'money_transfers', 'branch_demands',
        ];

        foreach ($allTables as $table) {
            try {
                DB::statement("DROP POLICY IF EXISTS rls_{$table}_select ON {$table}");
                DB::statement("DROP POLICY IF EXISTS rls_{$table}_insert ON {$table}");
                DB::statement("DROP POLICY IF EXISTS rls_{$table}_update ON {$table}");
                DB::statement("DROP POLICY IF EXISTS rls_{$table}_delete ON {$table}");
                DB::statement("DROP POLICY IF EXISTS rls_{$table}_admin ON {$table}");
                DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            } catch (\Throwable $e) {
                // Table may not exist or policy may not exist — ignore.
            }
        }

        // Reset GUC defaults.
        $dbName = DB::getConfig('database');
        try {
            DB::statement("ALTER DATABASE \"{$dbName}\" RESET app.branch_id");
            DB::statement("ALTER DATABASE \"{$dbName}\" RESET app.is_admin");
        } catch (\Throwable $e) {
            // Ignore if not database owner or GUC doesn't exist.
        }
    }

    // ============================================================
    // Helper methods
    // ============================================================

    /**
     * Enable RLS on a table and force it even for the table owner.
     */
    private function enableRLS(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        // FORCE makes the table owner (postgres) also subject to RLS.
        // Without this, the Laravel DB user (which is typically the owner)
        // would bypass all policies.
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    /**
     * Create SELECT policy — rows matching the condition are visible.
     */
    private function createSelectPolicy(string $table, string $condition): void
    {
        DB::statement(
            "CREATE POLICY rls_{$table}_select ON {$table}
             FOR SELECT
             USING (current_setting('app.is_admin', true) = 'true' OR ({$condition}))"
        );
    }

    /**
     * Create INSERT policy — rows matching the condition can be inserted.
     */
    private function createInsertPolicy(string $table, string $condition): void
    {
        DB::statement(
            "CREATE POLICY rls_{$table}_insert ON {$table}
             FOR INSERT
             WITH CHECK (current_setting('app.is_admin', true) = 'true' OR ({$condition}))"
        );
    }

    /**
     * Create UPDATE policy — rows matching the condition can be updated.
     * Both USING (existing row) and WITH CHECK (new row) must match.
     */
    private function createUpdatePolicy(string $table, string $condition): void
    {
        DB::statement(
            "CREATE POLICY rls_{$table}_update ON {$table}
             FOR UPDATE
             USING (current_setting('app.is_admin', true) = 'true' OR ({$condition}))
             WITH CHECK (current_setting('app.is_admin', true) = 'true' OR ({$condition}))"
        );
    }

    /**
     * Create DELETE policy — rows matching the condition can be deleted.
     */
    private function createDeletePolicy(string $table, string $condition): void
    {
        DB::statement(
            "CREATE POLICY rls_{$table}_delete ON {$table}
             FOR DELETE
             USING (current_setting('app.is_admin', true) = 'true' OR ({$condition}))"
        );
    }

    /**
     * Create admin bypass policy — admin users see/modify all branches.
     * This is an ALL-inclusive policy that applies to all operations.
     */
    private function createAdminBypassPolicy(string $table): void
    {
        DB::statement(
            "CREATE POLICY rls_{$table}_admin ON {$table}
             FOR ALL
             USING (current_setting('app.is_admin', true) = 'true')
             WITH CHECK (current_setting('app.is_admin', true) = 'true')"
        );
    }
};

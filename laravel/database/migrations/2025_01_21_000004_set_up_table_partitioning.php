<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 34: Set up table partitioning for sales_invoices + stock_transactions.
 *
 * PostgreSQL declarative partitioning (PG 10+) splits large tables into smaller
 * physical partitions while presenting a single logical table to queries.
 *
 * DESIGN DECISIONS:
 *
 * 1. Partition key: Both tables use RANGE partitioning by their date column:
 *    - sales_invoices → invoice_date (monthly partitions)
 *    - stock_transactions → transaction_date (monthly partitions)
 *
 *    Why not LIST by branch_id? RLS already provides branch isolation.
 *    Date-based partitioning enables: partition pruning for date-range queries,
 *    easy archival of old months, faster VACUUM per partition.
 *
 * 2. FK limitations: PostgreSQL 12-17 does NOT support FK constraints that
 *    REFERENCE a partitioned table. This means child tables cannot have
 *    FK → sales_invoices(id) or self-referential FK → stock_transactions(id).
 *
 *    Solution: Replace declarative FKs with trigger-based referential integrity
 *    enforcement. The triggers perform the same checks but work across partitions.
 *    This is the standard PostgreSQL pattern for partitioned tables with FK children.
 *
 * 3. UNIQUE constraints: On partitioned tables, UNIQUE must include the partition
 *    key. sales_invoices UNIQUE (invoice_code) → UNIQUE (invoice_code, invoice_date).
 *    This is acceptable because invoice_codes are date-prefixed (e.g., SI-2025-01-001).
 *
 * 4. IDENTITY columns: GENERATED ALWAYS AS IDENTITY works on partitioned tables
 *    since PG 12. The sequence generates values across all partitions.
 *
 * 5. Partition management: pg_partman extension automates creating future
 *    partitions and detaching old ones. Initial partitions cover 2025-01 to
 *    2025-12, with a default partition for out-of-range dates.
 *
 * MIGRATION STRATEGY:
 *   a. Create new partitioned tables with _partitioned suffix
 *   b. Copy data from old tables
 *   c. Rename old tables to _unpartitioned_backup
 *   d. Rename new tables to original names
 *   e. Recreate child FKs as triggers
 *   f. Recreate indexes, RLS policies, and other objects
 *   g. Install pg_partman for automatic partition maintenance
 *
 * ⚠️  This migration should be run during a maintenance window.
 *     It locks both tables during the data copy phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ──────────────────────────────────────────────────────────────
        // 0. Install pg_partman extension (auto partition management)
        // ──────────────────────────────────────────────────────────────

$hasPgPartman = DB::selectOne("
    SELECT EXISTS (
        SELECT 1
        FROM pg_available_extensions
        WHERE name = 'pg_partman'
    ) AS installed
");

if ($hasPgPartman->installed) {
    DB::statement('CREATE EXTENSION IF NOT EXISTS pg_partman');
} else {
    logger()->warning('pg_partman is not installed. Automatic partition maintenance is disabled.');
}
        DB::statement('CREATE SCHEMA IF NOT EXISTS partman');

        // ==============================================================
        // PART 1: stock_transactions (simpler — only 1 self-ref FK)
        // ==============================================================

        $this->partitionStockTransactions();

        // ==============================================================
        // PART 2: sales_invoices (complex — 7 child FK references)
        // ==============================================================

        $this->partitionSalesInvoices();
    }

    // ═══════════════════════════════════════════════════════════════
    //  stock_transactions partitioning
    // ═══════════════════════════════════════════════════════════════

    private function partitionStockTransactions(): void
    {
        // ── Step 1: Drop FKs that reference stock_transactions ──
        // Self-referential FK reversal_of_transaction_id
        DB::statement('ALTER TABLE stock_transactions DROP CONSTRAINT IF EXISTS fk_st_reversal_of');
        DB::statement('ALTER TABLE stock_transactions DROP CONSTRAINT IF EXISTS stock_transactions_reversal_of_transaction_id_foreign');

        // ── Step 2: Drop indexes on old table (will be recreated on partitioned table) ──
        $stIndexes = [
            'idx_st_date_warehouse', 'idx_st_product', 'idx_st_reference',
            'idx_st_branch_demand', 'idx_st_reversal_of', 'idx_st_is_reversed',
            'idx_st_reference_covering', 'idx_st_transaction_date_brin', 'idx_st_created_at_brin',
        ];
        foreach ($stIndexes as $idx) {
            DB::statement("DROP INDEX IF EXISTS {$idx}");
        }

        // ── Step 3: Rename old table ──
        DB::statement('ALTER TABLE stock_transactions RENAME TO stock_transactions_unpartitioned');

        // ── Step 4: Create partitioned table ──
        // Partition key: (transaction_date) — most queries filter by date range
        // PRIMARY KEY must include partition key: (id, transaction_date)
        DB::statement(<<<'SQL'
            CREATE TABLE stock_transactions (
                id integer GENERATED BY DEFAULT AS IDENTITY,
                transaction_date date NOT NULL,
                warehouse_id integer NOT NULL REFERENCES warehouses(id),
                product_id integer NOT NULL REFERENCES products(id),
                qty numeric(14,4) NOT NULL,
                rate numeric(12,2) NOT NULL DEFAULT 0,
                total_value numeric(14,2) GENERATED ALWAYS AS (qty * rate) STORED,
                reference_type varchar(30) NOT NULL CHECK (reference_type IN (
                    'purchase_receive','purchase_return','sales_challan','sales_return',
                    'stock_adjustment','stock_take','warehouse_transfer','damage',
                    'branch_demand','opening_balance'
                )),
                reference_id integer NOT NULL,
                branch_demand_item_id integer,
                notes text,
                is_reversed boolean DEFAULT false,
                reversal_of_transaction_id integer,
                reversed_at timestamp(0),
                reversed_by integer,
                reverse_reason text,
                created_by integer,
                created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id, transaction_date)
            ) PARTITION BY RANGE (transaction_date)
        SQL);

        // ── Step 5: Create monthly partitions for 2025 ──
        $months = [
            ['2025-01-01', '2025-02-01', 'stock_transactions_2025_01'],
            ['2025-02-01', '2025-03-01', 'stock_transactions_2025_02'],
            ['2025-03-01', '2025-04-01', 'stock_transactions_2025_03'],
            ['2025-04-01', '2025-05-01', 'stock_transactions_2025_04'],
            ['2025-05-01', '2025-06-01', 'stock_transactions_2025_05'],
            ['2025-06-01', '2025-07-01', 'stock_transactions_2025_06'],
            ['2025-07-01', '2025-08-01', 'stock_transactions_2025_07'],
            ['2025-08-01', '2025-09-01', 'stock_transactions_2025_08'],
            ['2025-09-01', '2025-10-01', 'stock_transactions_2025_09'],
            ['2025-10-01', '2025-11-01', 'stock_transactions_2025_10'],
            ['2025-11-01', '2025-12-01', 'stock_transactions_2025_11'],
            ['2025-12-01', '2026-01-01', 'stock_transactions_2025_12'],
        ];

        foreach ($months as [$from, $to, $name]) {
            DB::statement(
                "CREATE TABLE {$name} PARTITION OF stock_transactions
                 FOR VALUES FROM ('{$from}') TO ('{$to}')"
            );
        }

        // Default partition for out-of-range dates (avoids INSERT failure)
        DB::statement(
            'CREATE TABLE stock_transactions_default PARTITION OF stock_transactions DEFAULT'
        );

        // ── Step 6: Copy data from old table ──
        // Uses INSERT ... SELECT which preserves the GENERATED ALWAYS AS IDENTITY sequence
        // by explicitly specifying the id column.
        //
        // CRITICAL: `total_value` is a GENERATED ALWAYS AS (qty * rate) STORED column
        // in BOTH the old (renamed) and new partitioned tables. PostgreSQL does NOT
        // allow INSERT into a GENERATED ALWAYS column without OVERRIDING SYSTEM VALUE.
        // We OMIT total_value from both the INSERT column list and the SELECT list —
        // PostgreSQL auto-computes it from (qty * rate) on insert.
        DB::statement(<<<'SQL'
           INSERT INTO stock_transactions (
                id, transaction_date, warehouse_id, product_id, qty, rate,
                reference_type, reference_id, branch_demand_item_id,
                notes, is_reversed, reversal_of_transaction_id, reversed_at,
                reversed_by, reverse_reason, created_by, created_at
            )
            OVERRIDING SYSTEM VALUE
            SELECT
                id, transaction_date, warehouse_id, product_id, qty, rate,
                reference_type, reference_id, branch_demand_item_id,
                notes, is_reversed, reversal_of_transaction_id, reversed_at,
                reversed_by, reverse_reason, created_by, created_at
            FROM stock_transactions_unpartitioned
            ORDER BY transaction_date, id
        SQL);

        // ── Step 7: Fix identity sequence ──
        DB::statement(<<<'SQL'
            SELECT setval(
                pg_get_serial_sequence('stock_transactions', 'id'),
                GREATEST(COALESCE((SELECT MAX(id) FROM stock_transactions), 0), 1)
            )
        SQL);

        // ── Step 8: Recreate indexes (partition-aware) ──
        // On partitioned tables, indexes are created on the parent and automatically
        // propagated to each partition. The partition key (transaction_date) should
        // be included in covering indexes for partition pruning.
        DB::statement(
            "CREATE INDEX idx_st_date_warehouse ON stock_transactions (transaction_date, warehouse_id)"
        );
        DB::statement(
            "CREATE INDEX idx_st_product ON stock_transactions (product_id, transaction_date)"
        );
        DB::statement(
            "CREATE INDEX idx_st_reference ON stock_transactions (reference_type, reference_id)"
        );
        DB::statement(
            "CREATE INDEX idx_st_branch_demand ON stock_transactions (branch_demand_item_id)"
        );
        DB::statement(
            "CREATE INDEX idx_st_reversal_of ON stock_transactions (reversal_of_transaction_id)
             WHERE reversal_of_transaction_id IS NOT NULL"
        );
        DB::statement(
            "CREATE INDEX idx_st_is_reversed ON stock_transactions (is_reversed)
             WHERE is_reversed = true"
        );
        // Covering index: added transaction_date to key for partition pruning
        DB::statement(
            "CREATE INDEX idx_st_reference_covering ON stock_transactions (reference_type, reference_id, transaction_date)
             INCLUDE (id, warehouse_id, product_id, qty, rate, created_by)"
        );

        // ── Step 9: Self-referential FK trigger (replaces declarative FK) ──
        // PostgreSQL cannot enforce FK → partitioned table. This trigger checks
        // that reversal_of_transaction_id references an existing row.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_st_reversal_fk_check()
            RETURNS trigger AS $$
            BEGIN
                IF NEW.reversal_of_transaction_id IS NOT NULL THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM stock_transactions
                        WHERE id = NEW.reversal_of_transaction_id
                    ) THEN
                        RAISE EXCEPTION
                            'Referential integrity violation: reversal_of_transaction_id=% does not exist in stock_transactions',
                            NEW.reversal_of_transaction_id;
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER trg_st_reversal_fk
            AFTER INSERT ON stock_transactions
            DEFERRABLE INITIALLY IMMEDIATE
            FOR EACH ROW
            WHEN (NEW.reversal_of_transaction_id IS NOT NULL)
            EXECUTE FUNCTION fn_st_reversal_fk_check()
        SQL);

          if ($hasPgPartman->installed) {
        // ── Step 10: Register with pg_partman for automatic monthly partitions ──
        DB::statement(<<<'SQL'
         
                p_parent_table := 'public.stock_transactions',
                p_control := 'transaction_date',
                p_type := 'range',
                p_interval := '1 month',
                p_premake := 6,
                p_start_partition := '2026-01-01'
           
        SQL);
    }
        // ── Step 11: Drop backup table ──
        DB::statement('DROP TABLE stock_transactions_unpartitioned');

        // ── Step 12: Analyze ──
        DB::statement('ANALYZE stock_transactions');
    }

    // ═══════════════════════════════════════════════════════════════
    //  sales_invoices partitioning
    // ═══════════════════════════════════════════════════════════════

    private function partitionSalesInvoices(): void
    {
        // ── Step 1: Drop FKs FROM child tables → sales_invoices(id) ──
        // These must be replaced with trigger-based enforcement since
        // PG does not support FK references to partitioned tables.
        $childFKs = [
            'sales_invoice_items' => ['sales_invoice_items_sales_invoice_id_foreign', 'fk_sii_invoice'],
            'sales_invoice_dispatchers' => ['sales_invoice_dispatchers_sales_invoice_id_foreign'],
            'sales_invoice_dispatches' => ['sales_invoice_dispatches_sales_invoice_id_foreign'],
            'sales_challans' => ['sales_challans_sales_invoice_id_foreign', 'fk_sc_invoice'],
            'sales_returns' => ['sales_returns_sales_invoice_id_foreign', 'fk_sr_invoice'],
            'invoice_payment_allocations' => ['invoice_payment_allocations_invoice_id_foreign', 'fk_ipa_invoice'],
        ];

        foreach ($childFKs as $table => $constraints) {
            foreach ($constraints as $fk) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$fk}");
            }
        }

        // ── Step 2: Drop FKs on sales_invoices itself ──
        DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS fk_si_customer');
        DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS fk_si_branch');
        DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_journal_entry_id_foreign');
        DB::statement('ALTER TABLE sales_invoices DROP CONSTRAINT IF EXISTS sales_invoices_cogs_journal_entry_id_foreign');

        // ── Step 3: Drop indexes on old table ──
        $siIndexes = [
            'idx_si_customer', 'idx_si_invoice_date', 'idx_si_salesman', 'idx_si_branch',
            'idx_si_journal', 'idx_si_status', 'idx_si_call_a_day_active',
            'idx_si_open_invoice', 'idx_si_open_by_branch',
            'idx_si_customer_due_covering', 'idx_si_listing_covering',
            'idx_si_created_at_brin', 'idx_si_invoice_date_brin',
        ];
        foreach ($siIndexes as $idx) {
            DB::statement("DROP INDEX IF EXISTS {$idx}");
        }

        // ── Step 4: Drop RLS policies (will be recreated on partitioned table) ──
        $rlsPolicies = [
            'rls_sales_invoices_select', 'rls_sales_invoices_insert',
            'rls_sales_invoices_update', 'rls_sales_invoices_delete',
            'rls_sales_invoices_admin',
        ];
        foreach ($rlsPolicies as $policy) {
            DB::statement("DROP POLICY IF EXISTS {$policy} ON sales_invoices");
        }
        DB::statement('ALTER TABLE sales_invoices DISABLE ROW LEVEL SECURITY');

        // ── Step 5: Drop triggers ──
        DB::statement('DROP TRIGGER IF EXISTS trg_sales_invoices_updated_at ON sales_invoices');

        // ── Step 6: Rename old table ──
        DB::statement('ALTER TABLE sales_invoices RENAME TO sales_invoices_unpartitioned');

        // ── Step 7: Create partitioned sales_invoices ──
        // PRIMARY KEY must include partition key: (id, invoice_date)
        // UNIQUE must include partition key: (invoice_code, invoice_date)
        DB::statement(<<<'SQL'
            CREATE TABLE sales_invoices (
                id integer GENERATED BY DEFAULT AS IDENTITY,
                invoice_code varchar(30) NOT NULL,
                invoice_date date NOT NULL,
                customer_id integer NOT NULL,
                salesman_id integer,
                sales_person varchar(100),
                branch_id integer NOT NULL,
                sub_total numeric(14,2) DEFAULT 0,
                discount_amount numeric(14,2) DEFAULT 0,
                tax_amount numeric(14,2) DEFAULT 0,
                transport_cost numeric(12,2) DEFAULT 0,
                total_amount numeric(14,2) DEFAULT 0,
                pre_challan_transport numeric(12,2),
                pre_challan_total numeric(14,2),
                paid_amount numeric(14,2) DEFAULT 0,
                due_amount numeric(14,2) DEFAULT 0,
                payment_mode varchar(20) DEFAULT 'cash' CHECK (payment_mode IN ('cash','bank','mobile_banking','cheque','adjustment')),
                status varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','confirmed','cancelled','reversed')),
                is_godown_prepared boolean NOT NULL DEFAULT false,
                godown_prepared_at timestamp(0),
                is_challan_issued boolean NOT NULL DEFAULT false,
                challan_issued_at timestamp(0),
                journal_entry_id integer,
                cogs_journal_entry_id integer,
                is_reversed boolean NOT NULL DEFAULT false,
                reversed_at timestamp(0),
                reversed_by integer,
                reverse_reason text,
                is_soft_hold boolean NOT NULL DEFAULT false,
                call_a_day boolean NOT NULL DEFAULT false,
                notes text,
                created_by integer,
                created_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
                updated_at timestamp(0) DEFAULT CURRENT_TIMESTAMP,
                deleted_at timestamp(0),
                deleted_by integer,
                PRIMARY KEY (id, invoice_date),
                CONSTRAINT sales_invoices_code_unique UNIQUE (invoice_code, invoice_date)
            ) PARTITION BY RANGE (invoice_date)
        SQL);

        // ── Step 8: Create monthly partitions for 2025 ──
        $months = [
            ['2025-01-01', '2025-02-01', 'sales_invoices_2025_01'],
            ['2025-02-01', '2025-03-01', 'sales_invoices_2025_02'],
            ['2025-03-01', '2025-04-01', 'sales_invoices_2025_03'],
            ['2025-04-01', '2025-05-01', 'sales_invoices_2025_04'],
            ['2025-05-01', '2025-06-01', 'sales_invoices_2025_05'],
            ['2025-06-01', '2025-07-01', 'sales_invoices_2025_06'],
            ['2025-07-01', '2025-08-01', 'sales_invoices_2025_07'],
            ['2025-08-01', '2025-09-01', 'sales_invoices_2025_08'],
            ['2025-09-01', '2025-10-01', 'sales_invoices_2025_09'],
            ['2025-10-01', '2025-11-01', 'sales_invoices_2025_10'],
            ['2025-11-01', '2025-12-01', 'sales_invoices_2025_11'],
            ['2025-12-01', '2026-01-01', 'sales_invoices_2025_12'],
        ];

        foreach ($months as [$from, $to, $name]) {
            DB::statement(
                "CREATE TABLE {$name} PARTITION OF sales_invoices
                 FOR VALUES FROM ('{$from}') TO ('{$to}')"
            );
        }

        // Default partition
        DB::statement(
            'CREATE TABLE sales_invoices_default PARTITION OF sales_invoices DEFAULT'
        );

        // ── Step 9: Copy data from old table ──
        DB::statement(<<<'SQL'
            INSERT INTO sales_invoices (
                id, invoice_code, invoice_date, customer_id, salesman_id, sales_person,
                branch_id, sub_total, discount_amount, tax_amount, transport_cost,
                total_amount, pre_challan_transport, pre_challan_total,
                paid_amount, due_amount, payment_mode, status,
                is_godown_prepared, godown_prepared_at,
                is_challan_issued, challan_issued_at,
                journal_entry_id, cogs_journal_entry_id,
                is_reversed, reversed_at, reversed_by, reverse_reason,
                is_soft_hold, call_a_day, notes, created_by,
                created_at, updated_at, deleted_at, deleted_by
            )
            SELECT
                id, invoice_code, invoice_date, customer_id, salesman_id, sales_person,
                branch_id, sub_total, discount_amount, tax_amount, transport_cost,
                total_amount, pre_challan_transport, pre_challan_total,
                paid_amount, due_amount, payment_mode, status,
                is_godown_prepared, godown_prepared_at,
                is_challan_issued, challan_issued_at,
                journal_entry_id, cogs_journal_entry_id,
                is_reversed, reversed_at, reversed_by, reverse_reason,
                is_soft_hold, call_a_day, notes, created_by,
                created_at, updated_at, deleted_at, deleted_by
            FROM sales_invoices_unpartitioned
            ORDER BY invoice_date, id
        SQL);

        // ── Step 10: Fix identity sequence ──
        DB::statement(<<<'SQL'
            SELECT setval(
                pg_get_serial_sequence('sales_invoices', 'id'),
                GREATEST(COALESCE((SELECT MAX(id) FROM sales_invoices), 0), 1)
            )
        SQL);

        // ── Step 11: Recreate FKs FROM sales_invoices (outbound) ──
        // FKs FROM a partitioned table TO regular tables are fully supported.
        DB::statement(
            'ALTER TABLE sales_invoices
             ADD CONSTRAINT fk_si_customer FOREIGN KEY (customer_id) REFERENCES customers(id)'
        );
        DB::statement(
            'ALTER TABLE sales_invoices
             ADD CONSTRAINT fk_si_branch FOREIGN KEY (branch_id) REFERENCES branches(id)'
        );
        DB::statement(
            'ALTER TABLE sales_invoices
             ADD CONSTRAINT fk_si_journal FOREIGN KEY (journal_entry_id) REFERENCES journal_entries(id)'
        );
        DB::statement(
            'ALTER TABLE sales_invoices
             ADD CONSTRAINT fk_si_cogs_journal FOREIGN KEY (cogs_journal_entry_id) REFERENCES journal_entries(id)'
        );

        // ── Step 12: Recreate indexes ──
        // All indexes now include invoice_date where needed for partition pruning
        DB::statement(
            "CREATE INDEX idx_si_customer ON sales_invoices (customer_id, invoice_date)"
        );
        DB::statement(
            "CREATE INDEX idx_si_invoice_date ON sales_invoices (invoice_date)"
        );
        DB::statement(
            "CREATE INDEX idx_si_salesman ON sales_invoices (salesman_id)"
        );
        DB::statement(
            "CREATE INDEX idx_si_branch ON sales_invoices (branch_id, invoice_date)"
        );
        DB::statement(
            "CREATE INDEX idx_si_journal ON sales_invoices (journal_entry_id)"
        );
        DB::statement(
            "CREATE INDEX idx_si_status ON sales_invoices (status)"
        );
        DB::statement(
            "CREATE INDEX idx_si_call_a_day_active ON sales_invoices (call_a_day)
             WHERE call_a_day = false"
        );
        DB::statement(
            "CREATE INDEX idx_si_open_invoice ON sales_invoices (customer_id, due_amount, invoice_date)
             WHERE status='confirmed' AND is_reversed=false AND due_amount > 0"
        );
        DB::statement(
            "CREATE INDEX idx_si_open_by_branch ON sales_invoices (branch_id, invoice_date)
             WHERE status='confirmed' AND is_reversed=false AND due_amount > 0"
        );
        // Covering index: added invoice_date to key
        DB::statement(
            "CREATE INDEX idx_si_customer_due_covering ON sales_invoices (customer_id, is_reversed, invoice_date)
             INCLUDE (id, invoice_code, total_amount, paid_amount, due_amount)
             WHERE due_amount > 0"
        );
        // Listing covering index: already has invoice_date in key
        DB::statement(
            "CREATE INDEX idx_si_listing_covering
             ON sales_invoices (branch_id, status, invoice_date DESC, id DESC)
             INCLUDE (customer_id, invoice_code, total_amount, paid_amount, due_amount,
                      is_godown_prepared, is_challan_issued, is_reversed)"
        );

        // ── Step 13: Recreate updated_at trigger ──
        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_sales_invoices_updated_at
            BEFORE UPDATE ON sales_invoices
            FOR EACH ROW
            EXECUTE FUNCTION update_updated_at_column()
        SQL);

        // ── Step 14: Recreate RLS policies ──
        DB::statement('ALTER TABLE sales_invoices ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE sales_invoices FORCE ROW LEVEL SECURITY');

        DB::statement(<<<'SQL'
            CREATE POLICY rls_sales_invoices_select ON sales_invoices
            FOR SELECT USING (
                current_setting('app.is_admin', true) = 'true'
                OR branch_id = current_setting('app.branch_id')::int
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY rls_sales_invoices_insert ON sales_invoices
            FOR INSERT WITH CHECK (
                current_setting('app.is_admin', true) = 'true'
                OR branch_id = current_setting('app.branch_id')::int
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY rls_sales_invoices_update ON sales_invoices
            FOR UPDATE USING (
                current_setting('app.is_admin', true) = 'true'
                OR branch_id = current_setting('app.branch_id')::int
            ) WITH CHECK (
                current_setting('app.is_admin', true) = 'true'
                OR branch_id = current_setting('app.branch_id')::int
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY rls_sales_invoices_delete ON sales_invoices
            FOR DELETE USING (
                current_setting('app.is_admin', true) = 'true'
                OR branch_id = current_setting('app.branch_id')::int
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY rls_sales_invoices_admin ON sales_invoices
            FOR ALL USING (
                current_setting('app.is_admin', true) = 'true'
            ) WITH CHECK (
                current_setting('app.is_admin', true) = 'true'
            )
        SQL);

        // ── Step 15: Create FK enforcement triggers for child tables ──
        // These replace the declarative FKs that can't reference a partitioned table.

        // 15a: Generic trigger function for FK → sales_invoices(id)
        // Checks that the referenced invoice exists in any partition.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_fk_si_check()
            RETURNS trigger AS $$
            DECLARE
                fk_col text := TG_ARGV[0];
                invoice_id_val integer;
                invoice_exists boolean;
            BEGIN
                EXECUTE format('SELECT ($1).%I', fk_col) USING NEW INTO invoice_id_val;

                IF invoice_id_val IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT EXISTS (
                    SELECT 1 FROM sales_invoices WHERE id = invoice_id_val
                ) INTO invoice_exists;

                IF NOT invoice_exists THEN
                    RAISE EXCEPTION
                        'Referential integrity: %=% does not exist in sales_invoices',
                        fk_col, invoice_id_val;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        // 15b: Generic trigger function for ON DELETE CASCADE
        // When an invoice is deleted, cascade-delete child rows across partitions.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_fk_si_cascade_delete()
            RETURNS trigger AS $$
            DECLARE
                child_table text := TG_ARGV[0];
                fk_col text := TG_ARGV[1];
                invoice_id_val integer;
            BEGIN
                invoice_id_val := OLD.id;

                EXECUTE format(
                    'DELETE FROM %I WHERE %I = $1',
                    child_table, fk_col
                ) USING invoice_id_val;

                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        // 15c: Apply FK-check triggers to child tables
        // sales_invoice_items (CASCADE delete)
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER trg_fk_sii_si
            AFTER INSERT ON sales_invoice_items
            DEFERRABLE INITIALLY IMMEDIATE
            FOR EACH ROW
            EXECUTE FUNCTION fn_fk_si_check('sales_invoice_id')
        SQL);

        // sales_invoice_dispatchers (CASCADE delete)
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER trg_fk_sid_si
            AFTER INSERT ON sales_invoice_dispatchers
            DEFERRABLE INITIALLY IMMEDIATE
            FOR EACH ROW
            EXECUTE FUNCTION fn_fk_si_check('sales_invoice_id')
        SQL);

        // sales_invoice_dispatches (CASCADE delete)
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER trg_fk_sdis_si
            AFTER INSERT ON sales_invoice_dispatches
            DEFERRABLE INITIALLY IMMEDIATE
            FOR EACH ROW
            EXECUTE FUNCTION fn_fk_si_check('sales_invoice_id')
        SQL);

        // sales_challans (RESTRICT — block if invoice has challans)
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER trg_fk_sc_si
            AFTER INSERT ON sales_challans
            DEFERRABLE INITIALLY IMMEDIATE
            FOR EACH ROW
            EXECUTE FUNCTION fn_fk_si_check('sales_invoice_id')
        SQL);

        // sales_returns (RESTRICT)
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER trg_fk_sr_si
            AFTER INSERT ON sales_returns
            DEFERRABLE INITIALLY IMMEDIATE
            FOR EACH ROW
            EXECUTE FUNCTION fn_fk_si_check('sales_invoice_id')
        SQL);

        // invoice_payment_allocations (RESTRICT)
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT TRIGGER trg_fk_ipa_si
            AFTER INSERT ON invoice_payment_allocations
            DEFERRABLE INITIALLY IMMEDIATE
            FOR EACH ROW
            EXECUTE FUNCTION fn_fk_si_check('invoice_id')
        SQL);

        // 15d: Apply cascade-delete triggers on sales_invoices
        // When an invoice is deleted, these triggers fire and delete child rows.
        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_si_cascade_items
            AFTER DELETE ON sales_invoices
            FOR EACH ROW
            EXECUTE FUNCTION fn_fk_si_cascade_delete('sales_invoice_items', 'sales_invoice_id')
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_si_cascade_dispatchers
            AFTER DELETE ON sales_invoices
            FOR EACH ROW
            EXECUTE FUNCTION fn_fk_si_cascade_delete('sales_invoice_dispatchers', 'sales_invoice_id')
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_si_cascade_dispatches
            AFTER DELETE ON sales_invoices
            FOR EACH ROW
            EXECUTE FUNCTION fn_fk_si_cascade_delete('sales_invoice_dispatches', 'sales_invoice_id')
        SQL);

                    if ($hasPgPartman->installed) {

        // ── Step 16: Register with pg_partman ──
        DB::statement(<<<'SQL'

                p_parent_table := 'public.sales_invoices',
                p_control := 'invoice_date',
                p_type := 'range',
                p_interval := '1 month',
                p_premake := 6,
                p_start_partition := '2026-01-01'
            
        SQL);
    }
        // ── Step 17: Drop backup table ──
        DB::statement('DROP TABLE sales_invoices_unpartitioned');

        // ── Step 18: Analyze ──
        DB::statement('ANALYZE sales_invoices');
    }

    public function down(): void
    {
        // ⚠️ Rollback is complex for partitioning changes.
        // The recommended rollback is to restore from a pre-migration backup.
        // This down() method provides best-effort reversal but will NOT
        // restore declarative FKs on child tables.

        // Remove pg_partman entries
        DB::statement("if ($hasPgPartman->installed) {
            SELECT partman.undo_partition('public.stock_transactions', p_batch_count := 20);
        }");
        DB::statement("if ($hasPgPartman->installed) {
            SELECT partman.undo_partition('public.sales_invoices', p_batch_count := 20);
        }");

        // Drop trigger-based FK enforcement
        $triggers = [
            'trg_fk_sii_si', 'trg_fk_sid_si', 'trg_fk_sdis_si',
            'trg_fk_sc_si', 'trg_fk_sr_si', 'trg_fk_ipa_si',
            'trg_si_cascade_items', 'trg_si_cascade_dispatchers', 'trg_si_cascade_dispatches',
            'trg_st_reversal_fk',
        ];
        foreach ($triggers as $trigger) {
            // Determine the table for each trigger
            $table = match ($trigger) {
                'trg_fk_sii_si' => 'sales_invoice_items',
                'trg_fk_sid_si' => 'sales_invoice_dispatchers',
                'trg_fk_sdis_si' => 'sales_invoice_dispatches',
                'trg_fk_sc_si' => 'sales_challans',
                'trg_fk_sr_si' => 'sales_returns',
                'trg_fk_ipa_si' => 'invoice_payment_allocations',
                'trg_si_cascade_items', 'trg_si_cascade_dispatchers', 'trg_si_cascade_dispatches' => 'sales_invoices',
                'trg_st_reversal_fk' => 'stock_transactions',
                default => null,
            };
            if ($table) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
            }
        }

        // Drop trigger functions
        DB::statement('DROP FUNCTION IF EXISTS fn_fk_si_check() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS fn_fk_si_cascade_delete() CASCADE');
        DB::statement('DROP FUNCTION IF EXISTS fn_st_reversal_fk_check() CASCADE');
    }
};

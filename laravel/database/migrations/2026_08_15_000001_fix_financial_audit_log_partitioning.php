<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10.1 — Phase 0.1: Re-partition financial_audit_log.
 *
 * REGRESSION FIX (Audit finding B1).
 *
 * Migration 2026_08_02_000001 (Phase 1) correctly partitioned financial_audit_log
 * by created_at with monthly partitions, BRIN index, pg_partman registration,
 * and 84-month retention.
 *
 * Six days later, migration 2026_08_08_000002 (Phase 1.3 — Immutable Financial
 * Audit Trail) DROPPED the partitioned table and recreated it as a FLAT
 * non-partitioned table to rework the hash-chain trigger. The pg_partman
 * registration from Phase 1 became orphaned (points to a flat table).
 *
 * This migration re-applies the Phase 1 partitioning to the current flat table:
 *   - Disables audit triggers on 10 financial tables (prevents hash-chain
 *     continuity issues during data copy).
 *   - Drops the verification view + indexes on the flat table.
 *   - Cleans up the orphaned pg_partman row.
 *   - Renames flat table → _unpartitioned.
 *   - Creates a new partitioned parent (PK(id, created_at), PARTITION BY RANGE(created_at)).
 *   - Creates pre2026 catch-all + 2026 monthly + default partitions.
 *   - Copies data with OVERRIDING SYSTEM VALUE, ORDER BY created_at, id
 *     (preserves hash-chain ordering).
 *   - Recreates indexes (B-tree + BRIN with pages_per_range=32).
 *   - Re-applies REVOKE UPDATE/DELETE (immutable).
 *   - Drops old flat table.
 *   - Recreates the verification view.
 *   - Re-enables audit triggers.
 *   - Re-registers with pg_partman + configures 84-month retention.
 *
 * The existing fn_financial_audit_trigger() function continues to work
 * unchanged — it inserts by name into financial_audit_log, which PostgreSQL
 * routes to the correct partition via partition pruning.
 *
 * Idempotent — uses isAlreadyPartitioned() guard. Re-running is a no-op
 * if the table is already partitioned.
 *
 * ⚠️  This migration should be run during a maintenance window.
 *     It locks financial_audit_log during the data copy phase.
 */
return new class extends Migration
{
    private ?bool $hasPgPartman = null;

    // ── Helper methods ──────────────────────────────────────────────

    private function hasPgPartman(): bool
    {
        if ($this->hasPgPartman !== null) {
            return $this->hasPgPartman;
        }
        $row = DB::selectOne("
            SELECT EXISTS (
                SELECT 1 FROM pg_extension WHERE name = 'pg_partman'
            ) AS installed
        ");
        $this->hasPgPartman = (bool) ($row->installed ?? false);
        return $this->hasPgPartman;
    }

    private function isAlreadyPartitioned(string $tableName): bool
    {
        $row = DB::selectOne("
            SELECT 1
            FROM pg_partitioned_table pt
            JOIN pg_class c ON c.oid = pt.partrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relname = ? AND n.nspname = 'public'
            LIMIT 1
        ", [$tableName]);
        return $row !== null;
    }

    private function relationExists(string $name): bool
    {
        $row = DB::selectOne("
            SELECT 1
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relname = ? AND n.nspname = 'public'
            LIMIT 1
        ", [$name]);
        return $row !== null;
    }

    private function createMonthlyPartitions(string $parent, string $year): void
    {
        for ($m = 1; $m <= 12; $m++) {
            $from = sprintf('%s-%02d-01', $year, $m);
            $to = $m < 12
                ? sprintf('%s-%02d-01', $year, $m + 1)
                : sprintf('%d-01-01', (int) $year + 1);
            $name = sprintf('%s_%s_%02d', $parent, $year, $m);

            if (!$this->relationExists($name)) {
                DB::statement(
                    "CREATE TABLE {$name} PARTITION OF {$parent}
                     FOR VALUES FROM ('{$from}') TO ('{$to}')"
                );
            }
        }

        $defaultName = "{$parent}_default";
        if (!$this->relationExists($defaultName)) {
            DB::statement(
                "CREATE TABLE {$defaultName} PARTITION OF {$parent} DEFAULT"
            );
        }
    }

    private function createPreYearPartition(string $parent, string $preYear, string $startYear): void
    {
        $name = "{$parent}_{$preYear}";
        if (!$this->relationExists($name)) {
            DB::statement(
                "CREATE TABLE {$name} PARTITION OF {$parent}
                 FOR VALUES FROM ('2020-01-01') TO ('{$startYear}-01-01')"
            );
        }
    }

    private function fixSequence(string $table): void
    {
        DB::statement(<<<SQL
            SELECT setval(
                pg_get_serial_sequence('{$table}', 'id'),
                GREATEST(COALESCE((SELECT MAX(id) FROM {$table}), 0), 1)
            )
        SQL);
    }

    // ── Main up() ───────────────────────────────────────────────────

    public function up(): void
    {
        if ($this->isAlreadyPartitioned('financial_audit_log')) {
            Log::info('financial_audit_log is already partitioned — skipping re-partition.');
            return;
        }

        // Verify the flat table exists.
        if (!$this->relationExists('financial_audit_log')) {
            Log::warning('financial_audit_log table does not exist — nothing to partition.');
            return;
        }

        // ============================================================
        // 1. Disable audit triggers on the 10 financial tables.
        //    Prevents hash-chain continuity issues during the data copy:
        //    if a trigger fires mid-copy, it would compute the hash from
        //    the partially-copied table, breaking the chain.
        // ============================================================
        $auditTables = [
            'journal_entries', 'journal_lines', 'manual_journals',
            'manual_journal_lines', 'customer_payments', 'supplier_payments',
            'money_transfers', 'other_incomes', 'other_expenses',
            'employee_transactions',
        ];
        foreach ($auditTables as $table) {
            // Use DISABLE TRIGGER (session_replication_role would also work
            // but requires superuser; DISABLE TRIGGER just needs table owner).
            try {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER trg_audit_{$table}");
            } catch (\Throwable $e) {
                // Trigger may not exist if the table was created differently.
                Log::info("Could not disable trg_audit_{$table} on {$table}: {$e->getMessage()}");
            }
        }

        // ============================================================
        // 2. Drop the verification view (references the flat table).
        // ============================================================
        DB::statement('DROP VIEW IF EXISTS v_financial_audit_chain_verification');

        // ============================================================
        // 3. Drop indexes on the flat table.
        //    (They will be recreated on the partitioned parent.)
        // ============================================================
        $falIndexes = [
            'idx_fal_table_record', 'idx_fal_operation', 'idx_fal_performed_by',
            'idx_fal_branch', 'idx_fal_created_at', 'idx_fal_table_op',
            'idx_fal_created_at_brin',
        ];
        foreach ($falIndexes as $idx) {
            DB::statement("DROP INDEX IF EXISTS {$idx}");
        }

        // ============================================================
        // 4. Clean up the orphaned pg_partman row.
        //    The Phase 1 migration registered financial_audit_log with
        //    pg_partman, but 2026_08_08_000002 dropped the partitioned
        //    table without cleaning up part_config. The orphaned row
        //    points to a flat table — remove it so we can re-register
        //    fresh after re-partitioning.
        // ============================================================
        if ($this->hasPgPartman()) {
            DB::statement(<<<'SQL'
                DELETE FROM partman.part_config
                WHERE parent_table = 'public.financial_audit_log'
            SQL);
            Log::info('Cleaned up orphaned pg_partman row for public.financial_audit_log');
        }

        // ============================================================
        // 5. Rename flat table → _unpartitioned.
        // ============================================================
        DB::statement('ALTER TABLE financial_audit_log RENAME TO financial_audit_log_unpartitioned');

        // ============================================================
        // 6. Create the partitioned parent.
        //    PK: (id, created_at) — composite PK includes partition key.
        //    GENERATED BY DEFAULT AS IDENTITY (allows OVERRIDING SYSTEM VALUE).
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE TABLE financial_audit_log (
                id              BIGINT GENERATED BY DEFAULT AS IDENTITY,
                table_name      VARCHAR(64) NOT NULL,
                operation       VARCHAR(6)  NOT NULL CHECK (operation IN ('INSERT','UPDATE','DELETE')),
                record_id       BIGINT NOT NULL,
                before_data     JSONB,
                after_data      JSONB,
                changed_columns TEXT[],
                performed_by    VARCHAR(100),
                db_session_user VARCHAR(100),
                branch_id       INTEGER,
                transaction_id  XID,
                request_path    VARCHAR(500),
                request_ip      VARCHAR(45),
                request_id      VARCHAR(100),
                prev_hash       VARCHAR(64),
                row_hash        VARCHAR(64),
                created_at      TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at)
        SQL);

        // ============================================================
        // 7. Create partitions.
        //    pre2026: catch-all for historical data (2020-01-01 → 2026-01-01)
        //    2026 monthly: Jan–Dec 2026
        //    default: catch-all for dates outside defined ranges
        // ============================================================
        $this->createPreYearPartition('financial_audit_log', 'pre2026', '2026');
        $this->createMonthlyPartitions('financial_audit_log', '2026');

        // ============================================================
        // 8. Copy data from the flat table to the partitioned parent.
        //    ORDER BY created_at, id — preserves hash-chain ordering so
        //    the chain remains valid after the copy.
        //    OVERRIDING SYSTEM VALUE — preserves original id values.
        // ============================================================
        DB::statement(<<<'SQL'
            INSERT INTO financial_audit_log (
                id, table_name, operation, record_id, before_data, after_data,
                changed_columns, performed_by, db_session_user, branch_id,
                transaction_id, request_path, request_ip, request_id,
                prev_hash, row_hash, created_at
            )
            OVERRIDING SYSTEM VALUE
            SELECT
                id, table_name, operation, record_id, before_data, after_data,
                changed_columns, performed_by, db_session_user, branch_id,
                transaction_id, request_path, request_ip, request_id,
                prev_hash, row_hash, created_at
            FROM financial_audit_log_unpartitioned
            ORDER BY created_at, id
        SQL);

        // ============================================================
        // 9. Fix the identity sequence.
        // ============================================================
        $this->fixSequence('financial_audit_log');

        // ============================================================
        // 10. Recreate indexes on the parent (propagated to all partitions).
        //     B-tree indexes for point lookups + BRIN for range queries.
        // ============================================================
        DB::statement('CREATE INDEX idx_fal_table_record ON financial_audit_log (table_name, record_id)');
        DB::statement('CREATE INDEX idx_fal_operation ON financial_audit_log (operation)');
        DB::statement('CREATE INDEX idx_fal_performed_by ON financial_audit_log (performed_by)');
        DB::statement('CREATE INDEX idx_fal_branch ON financial_audit_log (branch_id)');
        DB::statement('CREATE INDEX idx_fal_table_op ON financial_audit_log (table_name, operation)');

        // BRIN index on partition key — 100-300x smaller than B-tree for append-only
        DB::statement(<<<'SQL'
            CREATE INDEX idx_fal_created_at_brin
                ON financial_audit_log USING BRIN (created_at)
                WITH (pages_per_range = 32)
        SQL);

        // ============================================================
        // 11. Re-apply REVOKE UPDATE/DELETE (immutable audit trail).
        //     Uses DO block to skip roles that don't exist.
        // ============================================================
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                EXECUTE 'REVOKE UPDATE, DELETE ON financial_audit_log FROM PUBLIC';

                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'postgres') THEN
                    EXECUTE 'REVOKE UPDATE, DELETE ON financial_audit_log FROM postgres';
                END IF;

                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'remote_center') THEN
                    EXECUTE 'REVOKE UPDATE, DELETE ON financial_audit_log FROM remote_center';
                END IF;
            END
            $$
        SQL);

        // ============================================================
        // 12. Drop the old flat table.
        // ============================================================
        DB::statement('DROP TABLE financial_audit_log_unpartitioned');

        // ============================================================
        // 13. Recreate the verification view.
        //     Queries the partitioned parent — PostgreSQL handles
        //     cross-partition queries transparently.
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_financial_audit_chain_verification AS
            SELECT
                id,
                table_name,
                operation,
                record_id,
                prev_hash,
                row_hash,
                CASE
                    WHEN id = 1 THEN
                        prev_hash = '0000000000000000000000000000000000000000000000000000000000000000'
                    ELSE
                        prev_hash = LAG(row_hash) OVER (ORDER BY id)
                END AS chain_valid,
                created_at
            FROM financial_audit_log
            ORDER BY id
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON VIEW v_financial_audit_chain_verification IS
                'Phase 1.3: Verification view for the cryptographic hash chain. If chain_valid is FALSE, the audit trail has been tampered with.'
        SQL);

        // ============================================================
        // 14. Re-enable audit triggers on the 10 financial tables.
        // ============================================================
        foreach ($auditTables as $table) {
            try {
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER trg_audit_{$table}");
            } catch (\Throwable $e) {
                Log::info("Could not re-enable trg_audit_{$table} on {$table}: {$e->getMessage()}");
            }
        }

        // ============================================================
        // 15. Register with pg_partman for auto-creation of future partitions.
        // ============================================================
        if ($this->hasPgPartman()) {
            try {
                DB::statement(<<<'SQL'
                    SELECT partman.create_parent(
                        p_parent_table    := 'public.financial_audit_log',
                        p_control         := 'created_at',
                        p_type            := 'range',
                        p_interval        := '1 month',
                        p_premake         := 6,
                        p_start_partition := '2027-01-01'
                    )
                SQL);
            } catch (\Throwable $e) {
                // May fail if template table conflicts — try without start_partition.
                try {
                    DB::statement(<<<'SQL'
                        SELECT partman.create_parent(
                            p_parent_table := 'public.financial_audit_log',
                            p_control      := 'created_at',
                            p_type         := 'range',
                            p_interval     := '1 month',
                            p_premake      := 6
                        )
                    SQL);
                } catch (\Throwable $e2) {
                    Log::warning("Could not register financial_audit_log with pg_partman: {$e2->getMessage()}");
                }
            }

            // Configure retention: 84 months (7 years, compliance).
            DB::statement(<<<'SQL'
                UPDATE partman.part_config
                SET retention = '84 months',
                    retention_keep_table = true,
                    retention_schema = 'archive'
                WHERE parent_table = 'public.financial_audit_log'
            SQL);
        }

        // ============================================================
        // 16. Analyze for fresh planner statistics.
        // ============================================================
        DB::statement('ANALYZE financial_audit_log');

        Log::info('financial_audit_log re-partitioned successfully: RANGE(created_at) monthly, BRIN index, pg_partman registered, 84-month retention.');
    }

    public function down(): void
    {
        // Rollback: convert back to a flat table.
        // This is the inverse of up() — detach all partitions, copy data
        // into a flat table, drop the partitioned parent, rename flat table.

        if (!$this->isAlreadyPartitioned('financial_audit_log')) {
            return;
        }

        // Disable audit triggers during rollback too.
        $auditTables = [
            'journal_entries', 'journal_lines', 'manual_journals',
            'manual_journal_lines', 'customer_payments', 'supplier_payments',
            'money_transfers', 'other_incomes', 'other_expenses',
            'employee_transactions',
        ];
        foreach ($auditTables as $table) {
            try {
                DB::statement("ALTER TABLE {$table} DISABLE TRIGGER trg_audit_{$table}");
            } catch (\Throwable $e) {}
        }

        // Drop verification view + indexes.
        DB::statement('DROP VIEW IF EXISTS v_financial_audit_chain_verification');
        $indexes = [
            'idx_fal_table_record', 'idx_fal_operation', 'idx_fal_performed_by',
            'idx_fal_branch', 'idx_fal_table_op', 'idx_fal_created_at_brin',
        ];
        foreach ($indexes as $idx) {
            DB::statement("DROP INDEX IF EXISTS {$idx}");
        }

        // Create a flat rollback table.
        DB::statement(<<<'SQL'
            CREATE TABLE financial_audit_log_flat (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                table_name      VARCHAR(64) NOT NULL,
                operation       VARCHAR(6)  NOT NULL CHECK (operation IN ('INSERT','UPDATE','DELETE')),
                record_id       BIGINT NOT NULL,
                before_data     JSONB,
                after_data      JSONB,
                changed_columns TEXT[],
                performed_by    VARCHAR(100),
                db_session_user VARCHAR(100),
                branch_id       INTEGER,
                transaction_id  XID,
                request_path    VARCHAR(500),
                request_ip      VARCHAR(45),
                request_id      VARCHAR(100),
                prev_hash       VARCHAR(64),
                row_hash        VARCHAR(64),
                created_at      TIMESTAMP(0) NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        SQL);

        // Copy all data from the partitioned parent.
        DB::statement(<<<'SQL'
            INSERT INTO financial_audit_log_flat (
                id, table_name, operation, record_id, before_data, after_data,
                changed_columns, performed_by, db_session_user, branch_id,
                transaction_id, request_path, request_ip, request_id,
                prev_hash, row_hash, created_at
            )
            OVERRIDING SYSTEM VALUE
            SELECT
                id, table_name, operation, record_id, before_data, after_data,
                changed_columns, performed_by, db_session_user, branch_id,
                transaction_id, request_path, request_ip, request_id,
                prev_hash, row_hash, created_at
            FROM financial_audit_log
            ORDER BY created_at, id
        SQL);

        // Fix sequence.
        DB::statement(<<<'SQL'
            SELECT setval(
                pg_get_serial_sequence('financial_audit_log_flat', 'id'),
                GREATEST(COALESCE((SELECT MAX(id) FROM financial_audit_log_flat), 0), 1)
            )
        SQL);

        // Drop the partitioned parent (cascades to all child partitions).
        DB::statement('DROP TABLE financial_audit_log');

        // Rename flat table.
        DB::statement('ALTER TABLE financial_audit_log_flat RENAME TO financial_audit_log');

        // Recreate indexes.
        DB::statement('CREATE INDEX idx_fal_table_record ON financial_audit_log (table_name, record_id)');
        DB::statement('CREATE INDEX idx_fal_operation ON financial_audit_log (operation)');
        DB::statement('CREATE INDEX idx_fal_performed_by ON financial_audit_log (performed_by)');
        DB::statement('CREATE INDEX idx_fal_branch ON financial_audit_log (branch_id)');
        DB::statement('CREATE INDEX idx_fal_created_at ON financial_audit_log (created_at)');
        DB::statement('CREATE INDEX idx_fal_table_op ON financial_audit_log (table_name, operation)');

        // Re-apply REVOKE.
        DB::statement('REVOKE UPDATE, DELETE ON financial_audit_log FROM PUBLIC');

        // Recreate verification view.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_financial_audit_chain_verification AS
            SELECT
                id, table_name, operation, record_id, prev_hash, row_hash,
                CASE
                    WHEN id = 1 THEN
                        prev_hash = '0000000000000000000000000000000000000000000000000000000000000000'
                    ELSE
                        prev_hash = LAG(row_hash) OVER (ORDER BY id)
                END AS chain_valid,
                created_at
            FROM financial_audit_log
            ORDER BY id
        SQL);

        // Re-enable audit triggers.
        foreach ($auditTables as $table) {
            try {
                DB::statement("ALTER TABLE {$table} ENABLE TRIGGER trg_audit_{$table}");
            } catch (\Throwable $e) {}
        }

        // Clean up pg_partman registration.
        if ($this->hasPgPartman()) {
            try {
                DB::statement(<<<'SQL'
                    DELETE FROM partman.part_config
                    WHERE parent_table = 'public.financial_audit_log'
                SQL);
            } catch (\Throwable $e) {}
        }

        Log::warning('financial_audit_log rolled back to flat table. pg_partman registration removed.');
    }
};

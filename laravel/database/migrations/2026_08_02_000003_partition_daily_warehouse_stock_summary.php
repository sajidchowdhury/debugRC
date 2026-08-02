<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10.1 — Phase 3: Time-Series Summary Partitioning (Lowest Risk).
 *
 * Partitions daily_warehouse_stock_summary by summary_date (RANGE, monthly).
 * This is the simplest partitioning target:
 *   - No RLS policies
 *   - No materialized views depend on it
 *   - No triggers
 *   - No application code references it (currently unused)
 *   - PK already includes the partition key
 *
 * DESIGN DECISIONS:
 *
 * 1. Partition key: RANGE by summary_date with monthly partitions. This enables:
 *    - Partition pruning for date-range queries (e.g., dashboard showing
 *      last 30 days of stock movement)
 *    - Easy archival of old months (DETACH PARTITION)
 *    - Faster VACUUM per partition
 *    - 24-month retention policy (old data auto-detached to archive schema)
 *
 * 2. Primary Key: (warehouse_id, product_id, summary_date) — UNCHANGED.
 *    The partition key (summary_date) is already part of the composite PK,
 *    so no PK restructuring is needed. This is the ideal case for partitioning.
 *
 * 3. No IDENTITY column: Unlike the audit logs and sub-ledgers, this table
 *    has no serial/id column. The PK is a natural composite key. This means
 *    no OVERRIDING SYSTEM VALUE is needed during data copy.
 *
 * 4. BRIN indexes: Replace B-tree idx_dwss_date with BRIN. This table is
 *    append-only and naturally ordered by summary_date, making BRIN much
 *    smaller and equally effective.
 *
 * 5. Outbound FKs: warehouse_id → warehouses(id), product_id → products(id)
 *    are standard outbound FKs from a partitioned table to non-partitioned
 *    tables — fully supported. Both are DEFERRABLE INITIALLY IMMEDIATE.
 *
 * 6. No RLS: This table has no branch_id column and no RLS policies.
 *    Access is controlled via the warehouse relationship (warehouses.branch_id).
 *
 * MIGRATION STRATEGY:
 *   a. Drop indexes on old table
 *   b. Rename old table to _unpartitioned
 *   c. Create new partitioned table with same PK + outbound FKs
 *   d. Create monthly partitions (2026-01 to 2026-12) + default partition
 *   e. Copy data from old table
 *   f. Recreate indexes on parent (propagated to partitions)
 *   g. Drop old table
 *   h. Register with pg_partman
 *   i. Analyze
 *
 * ⚠️  This migration should be run during a maintenance window.
 *     It locks the table during the data copy phase.
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
                SELECT 1 FROM pg_available_extensions WHERE name = 'pg_partman'
            ) AS installed
        ");
        $this->hasPgPartman = (bool) ($row->installed ?? false);
        if (! $this->hasPgPartman) {
            logger()->warning('pg_partman is not installed. Automatic partition maintenance is disabled.');
        }
        return $this->hasPgPartman;
    }

    private function isAlreadyPartitioned(string $tableName): bool
    {
        $row = DB::selectOne("
            SELECT 1
            FROM pg_partitioned_table pt
            JOIN pg_class c ON c.oid = pt.partrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relname = ? AND n.nspname = current_schema()
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
            WHERE c.relname = ? AND n.nspname = current_schema()
            LIMIT 1
        ", [$name]);
        return $row !== null;
    }

    /**
     * Create 12 monthly partitions for a given year + a default partition.
     * Idempotent — skips partitions that already exist.
     */
    private function createMonthlyPartitions(string $parent, string $year): void
    {
        for ($m = 1; $m <= 12; $m++) {
            $from = sprintf('%s-%02d-01', $year, $m);
            $to   = $m < 12
                ? sprintf('%s-%02d-01', $year, $m + 1)
                : sprintf('%d-01-01', (int) $year + 1);
            $name = sprintf('%s_%s_%02d', $parent, $year, $m);

            if (! $this->relationExists($name)) {
                DB::statement(
                    "CREATE TABLE {$name} PARTITION OF {$parent}
                     FOR VALUES FROM ('{$from}') TO ('{$to}')"
                );
            }
        }

        $defaultName = "{$parent}_default";
        if (! $this->relationExists($defaultName)) {
            DB::statement(
                "CREATE TABLE {$defaultName} PARTITION OF {$parent} DEFAULT"
            );
        }
    }

    /**
     * Create a pre-year catch-all partition for historical data.
     * Idempotent — skips if it already exists.
     */
    private function createPreYearPartition(string $parent, string $preYear, string $startYear): void
    {
        $name = "{$parent}_{$preYear}";
        if (! $this->relationExists($name)) {
            DB::statement(
                "CREATE TABLE {$name} PARTITION OF {$parent}
                 FOR VALUES FROM ('2020-01-01') TO ('{$startYear}-01-01')"
            );
        }
    }

    /**
     * Register a table with pg_partman for automatic monthly partition creation.
     */
    private function registerPartman(string $parent, string $control, string $startPartition): void
    {
        if (! $this->hasPgPartman()) {
            return;
        }
        try {
            DB::statement(<<<SQL
                SELECT partman.create_parent(
                    p_parent_table    := 'public.{$parent}',
                    p_control         := '{$control}',
                    p_type            := 'range',
                    p_interval        := '1 month',
                    p_premake         := 6,
                    p_start_partition := '{$startPartition}'
                )
            SQL);
        } catch (\Throwable $e) {
            // Already registered with pg_partman — safe to ignore.
        }
    }

    // ── Main up() ───────────────────────────────────────────────────

    public function up(): void
    {
        // Install pg_partman if available
        if ($this->hasPgPartman()) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_partman');
            DB::statement('CREATE SCHEMA IF NOT EXISTS partman');
        }

        if ($this->isAlreadyPartitioned('daily_warehouse_stock_summary')) {
            return;
        }

        // ── Drop indexes on old table ──
        DB::statement('DROP INDEX IF EXISTS idx_dwss_date');
        DB::statement('DROP INDEX IF EXISTS idx_dwss_summary_date_brin');

        // ── Rename old table ──
        DB::statement(
            'ALTER TABLE daily_warehouse_stock_summary RENAME TO daily_warehouse_stock_summary_unpartitioned'
        );

        // ── Create partitioned table ──
        // PK is (warehouse_id, product_id, summary_date) — UNCHANGED from original.
        // summary_date is already in the PK, so no PK restructuring needed.
        // FKs: warehouse_id → warehouses(id), product_id → products(id)
        //      DEFERRABLE INITIALLY IMMEDIATE (from migration 2025_01_21_000005)
        DB::statement(<<<'SQL'
            CREATE TABLE daily_warehouse_stock_summary (
                warehouse_id  INTEGER NOT NULL REFERENCES warehouses(id) DEFERRABLE INITIALLY IMMEDIATE,
                product_id    INTEGER NOT NULL REFERENCES products(id) DEFERRABLE INITIALLY IMMEDIATE,
                summary_date  DATE NOT NULL,
                opening_qty   NUMERIC(14,4) DEFAULT 0,
                in_qty        NUMERIC(14,4) DEFAULT 0,
                out_qty       NUMERIC(14,4) DEFAULT 0,
                closing_qty   NUMERIC(14,4) DEFAULT 0,
                avg_cost      NUMERIC(12,2) DEFAULT 0,
                PRIMARY KEY (warehouse_id, product_id, summary_date)
            ) PARTITION BY RANGE (summary_date)
        SQL);

        // ── Create partitions ──
        $this->createPreYearPartition('daily_warehouse_stock_summary', 'pre2026', '2026');
        $this->createMonthlyPartitions('daily_warehouse_stock_summary', '2026');

        // ── Copy data ──
        // No IDENTITY column, no OVERRIDING SYSTEM VALUE needed
        DB::statement(<<<'SQL'
            INSERT INTO daily_warehouse_stock_summary (
                warehouse_id, product_id, summary_date,
                opening_qty, in_qty, out_qty, closing_qty, avg_cost
            )
            SELECT
                warehouse_id, product_id, summary_date,
                opening_qty, in_qty, out_qty, closing_qty, avg_cost
            FROM daily_warehouse_stock_summary_unpartitioned
            ORDER BY summary_date, warehouse_id, product_id
        SQL);

        // ── Recreate indexes on parent (propagated to partitions) ──
        // Replace B-tree idx_dwss_date with BRIN — append-only time-series
        DB::statement(<<<'SQL'
            CREATE INDEX idx_dwss_summary_date_brin
                ON daily_warehouse_stock_summary USING BRIN (summary_date)
                WITH (pages_per_range = 32)
        SQL);

        // ── Drop old table ──
        DB::statement('DROP TABLE daily_warehouse_stock_summary_unpartitioned');

        // ── Register with pg_partman ──
        $this->registerPartman('daily_warehouse_stock_summary', 'summary_date', '2027-01-01');

        // ── Configure retention (24 months — older data auto-detached to archive) ──
        if ($this->hasPgPartman()) {
            DB::statement(<<<'SQL'
                UPDATE partman.part_config
                SET retention = '24 months',
                    retention_keep_table = true,
                    retention_schema = 'archive'
                WHERE parent_table = 'public.daily_warehouse_stock_summary'
            SQL);
        }

        // ── Analyze ──
        DB::statement('ANALYZE daily_warehouse_stock_summary');
    }

    // ── Rollback ──────────────────────────────────────────────────

    /**
     * Rollback: detach all partitions, create a flat table, copy data,
     * drop partitioned parent, rename flat table.
     */
    public function down(): void
    {
        if (! $this->isAlreadyPartitioned('daily_warehouse_stock_summary')) {
            return;
        }

        // ── Detach all partitions ──
        $partitions = DB::select("
            SELECT c.relname
            FROM pg_inherits i
            JOIN pg_class c ON c.oid = i.inhrelid
            JOIN pg_class p ON p.oid = i.inhparent
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE p.relname = 'daily_warehouse_stock_summary' AND n.nspname = current_schema()
        ");

        foreach ($partitions as $part) {
            DB::statement("ALTER TABLE daily_warehouse_stock_summary DETACH PARTITION {$part->relname}");
        }

        // ── Create flat table ──
        DB::statement(<<<'SQL'
            CREATE TABLE daily_warehouse_stock_summary_flat (
                warehouse_id  INTEGER NOT NULL REFERENCES warehouses(id) DEFERRABLE INITIALLY IMMEDIATE,
                product_id    INTEGER NOT NULL REFERENCES products(id) DEFERRABLE INITIALLY IMMEDIATE,
                summary_date  DATE NOT NULL,
                opening_qty   NUMERIC(14,4) DEFAULT 0,
                in_qty        NUMERIC(14,4) DEFAULT 0,
                out_qty       NUMERIC(14,4) DEFAULT 0,
                closing_qty   NUMERIC(14,4) DEFAULT 0,
                avg_cost      NUMERIC(12,2) DEFAULT 0,
                PRIMARY KEY (warehouse_id, product_id, summary_date)
            )
        SQL);

        // ── Copy data from each partition ──
        $cols = 'warehouse_id, product_id, summary_date, opening_qty, in_qty, out_qty, closing_qty, avg_cost';
        foreach ($partitions as $part) {
            DB::statement(<<<SQL
                INSERT INTO daily_warehouse_stock_summary_flat ({$cols})
                SELECT {$cols} FROM {$part->relname}
                ORDER BY summary_date, warehouse_id, product_id
            SQL);
        }
        // Also copy from the parent (in case data remains there)
        $parentCount = DB::selectOne("SELECT count(*) AS cnt FROM ONLY daily_warehouse_stock_summary")->cnt;
        if ($parentCount > 0) {
            DB::statement(<<<SQL
                INSERT INTO daily_warehouse_stock_summary_flat ({$cols})
                SELECT {$cols} FROM ONLY daily_warehouse_stock_summary
                ORDER BY summary_date, warehouse_id, product_id
            SQL);
        }

        // ── Recreate indexes ──
        DB::statement('CREATE INDEX idx_dwss_date ON daily_warehouse_stock_summary_flat (summary_date)');

        // ── Drop old partitioned parent and its detached partitions ──
        DB::statement('DROP TABLE daily_warehouse_stock_summary');
        foreach ($partitions as $part) {
            DB::statement("DROP TABLE IF EXISTS {$part->relname}");
        }

        // ── Rename flat table to original name ──
        DB::statement('ALTER TABLE daily_warehouse_stock_summary_flat RENAME TO daily_warehouse_stock_summary');

        // ── Analyze ──
        DB::statement('ANALYZE daily_warehouse_stock_summary');
    }
};

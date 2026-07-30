<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Snapshot the current "basic data" state to a SQL file.
 *
 * Usage:
 *   php artisan db:snapshot-basic
 *
 * What it does:
 *   - Reads each table in BASIC_DATA_TABLES (master + config tables only).
 *   - For each table, generates `INSERT INTO ... OVERRIDING SYSTEM VALUE (...) VALUES (...);`
 *     statements that preserve the original id (and any other columns).
 *   - Saves the output to database/sql/basic_data_snapshot.sql.
 *
 * Why snapshot instead of just re-running migrations?
 *   - The user wants to capture the EXACT current state (with manually-created
 *     rows, edited names, custom permission grants, etc.) and be able to
 *     restore to that state later in one command. Re-running every legacy
 *     migration would re-parse the original SQL dumps and lose any manual
 *     edits made through the UI.
 *
 * Snapshot file format:
 *   -- header comment
 *   SET session_replication_role = 'replica';   -- bypass FK + triggers during restore
 *
 *   -- Table: branches (4 rows)
 *   INSERT INTO "branches" OVERRIDING SYSTEM VALUE ("id", ...) VALUES (1, ...);
 *   ...
 *
 *   SET session_replication_role = 'origin';    -- re-enable FK + triggers
 *
 * Usage pattern:
 *   1. Run `php artisan db:snapshot-basic` once when DB is in desired state.
 *   2. Commit database/sql/basic_data_snapshot.sql to the repo.
 *   3. Any time later: run `php artisan db:make-empty` then `php artisan db:restore-basic`
 *      to reset back to the snapshot state.
 *
 * Re-running this command OVERWRITES the snapshot file. Use --dry-run to
 * preview without writing.
 */
class DbSnapshotBasic extends Command
{
    protected $signature = 'db:snapshot-basic
                            {--dry-run : Print to stdout instead of writing file}
                            {--table= : Snapshot only this table (for testing)}';

    protected $description = 'Snapshot basic-data tables (master + config) to database/sql/basic_data_snapshot.sql';

    /**
     * The curated list of "basic data" tables — master + config only.
     * Transactional/audit/log tables are excluded (they get wiped by
     * db:make-empty but are NOT restored by db:restore-basic).
     *
     * Order matters: parents come before children so the snapshot file can
     * be replayed top-to-bottom without FK violations (we also use
     * SET session_replication_role = 'replica' as a belt-and-suspenders).
     */
    private const BASIC_DATA_TABLES = [
        // Branches / warehouses (root-level)
        'branches',
        'warehouses',

        // Product taxonomy
        'product_groups',
        'product_categories',
        'units_of_measure',
        'product_uom_conversions',
        'products',

        // People / orgs
        'employees',
        'users',
        'customers',
        'suppliers',
        'banks',
        'bank_ledger_mappings',

        // Menu + permissions (depends on users + menus)
        'menus',
        'user_menu_permissions',

        // Chart of accounts (financial master)
        'ledgers',

        // Config / settings tables
        'system_policies',
        'notification_rules',
        'stock_take_policies',
        'accounting_periods',
        'document_sequences',
    ];

    public function handle(): int
    {
        $this->info('=== DB SNAPSHOT (BASIC DATA) ===');
        $this->newLine();

        $onlyTable = $this->option('table');
        $tables = $onlyTable ? [$onlyTable] : self::BASIC_DATA_TABLES;

        $output = $this->buildHeader();

        $stats = [];
        $totalRows = 0;

        foreach ($tables as $table) {
            if (!$this->tableExists($table)) {
                $this->warn("  ⚠ SKIP {$table} — table does not exist");
                continue;
            }

            $this->line("  Snapshotting {$table}...");

            $sql = $this->snapshotTable($table);
            if ($sql === null) {
                $this->warn("  ⚠ SKIP {$table} — could not read");
                continue;
            }

            $output .= $sql['block'];
            $stats[$table] = $sql['row_count'];
            $totalRows += $sql['row_count'];
        }

        $output .= $this->buildFooter();

        // ---- Save or print ----
        if ($this->option('dry-run')) {
            $this->info('--- DRY RUN OUTPUT ---');
            $this->line($output);
            $this->info('--- END DRY RUN (nothing written) ---');
        } else {
            $path = database_path('sql/basic_data_snapshot.sql');

            // Make sure the directory exists.
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($path, $output);
            $size = strlen($output);
            $this->newLine();
            $this->info("✓ Snapshot saved to: {$path}");
            $this->info("  Size: " . $this->formatBytes($size));
        }

        // ---- Summary ----
        $this->newLine();
        $this->info('Snapshot summary:');
        foreach ($stats as $table => $count) {
            $this->line("  {$table}: {$count} row(s)");
        }
        $this->newLine();
        $this->info("Total: {$totalRows} row(s) across " . count($stats) . " table(s).");

        return self::SUCCESS;
    }

    private function buildHeader(): string
    {
        $now = now()->format('Y-m-d H:i:s');
        return <<<SQL
-- ============================================================
-- Basic data snapshot — generated by `php artisan db:snapshot-basic`
-- Generated: {$now}
--
-- This file is consumed by `php artisan db:restore-basic`.
-- It contains INSERT statements for master + config tables only.
-- Transactional/audit/log tables are NOT included.
--
-- Replay order is FK-safe (parents before children). We also use
-- SET session_replication_role = 'replica' to bypass FK checks during
-- restore as a belt-and-suspenders measure.
-- ============================================================

SET session_replication_role = 'replica';

SQL;
    }

    private function buildFooter(): string
    {
        return <<<SQL

SET session_replication_role = 'origin';

-- End of snapshot.
SQL;
    }

    /**
     * Check if a table exists in the public schema.
     */
    private function tableExists(string $table): bool
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', 'public')
            ->where('table_name', $table)
            ->exists();
    }

    /**
     * Snapshot a single table to INSERT statements.
     *
     * @return array{block: string, row_count: int}|null
     */
    private function snapshotTable(string $table): ?array
    {
        try {
            $columns = $this->getColumns($table);
            if (empty($columns)) {
                return null;
            }

            $rows = DB::table($table)->get();
            $rowCount = $rows->count();

            $block = "\n-- Table: {$table} ({$rowCount} row" . ($rowCount === 1 ? '' : 's') . ")\n";
            $block .= "DELETE FROM \"{$table}\";\n";

            if ($rowCount === 0) {
                return ['block' => $block, 'row_count' => 0];
            }

            $quotedCols = implode(', ', array_map(fn($c) => "\"{$c}\"", $columns));

            foreach ($rows as $row) {
                $vals = [];
                foreach ($columns as $col) {
                    $vals[] = $this->formatValue($row->{$col} ?? null);
                }
                $valsStr = implode(', ', $vals);
                $block .= "INSERT INTO \"{$table}\" OVERRIDING SYSTEM VALUE ({$quotedCols}) VALUES ({$valsStr});\n";
            }

            return ['block' => $block, 'row_count' => $rowCount];
        } catch (\Throwable $e) {
            $this->error("  Error snapshotting {$table}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get column names for a table in natural order.
     *
     * @return string[]
     */
    private function getColumns(string $table): array
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', $table)
            ->orderBy('ordinal_position')
            ->pluck('column_name')
            ->all();
    }

    /**
     * Format a PHP value as a SQL literal.
     */
    private function formatValue($val): string
    {
        if ($val === null) {
            return 'NULL';
        }
        if (is_bool($val)) {
            return $val ? 'TRUE' : 'FALSE';
        }
        if (is_int($val)) {
            return (string) $val;
        }
        if (is_float($val)) {
            // Avoid locale issues; use scientific notation if extremely large.
            if (is_finite($val)) {
                return sprintf('%.15g', $val);
            }
            return 'NULL';
        }
        // String (includes dates, timestamps, UUIDs, JSON, etc.)
        // Escape backslash first (PG standard_conforming_strings = on by default,
        // so backslash is literal — but we still escape single quotes by doubling).
        $escaped = str_replace("'", "''", (string) $val);
        return "'{$escaped}'";
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        return number_format($bytes / (1024 * 1024), 2) . ' MB';
    }
}

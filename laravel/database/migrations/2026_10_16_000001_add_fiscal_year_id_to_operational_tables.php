<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Session 1 — Migration A: Add fiscal_year_id to operational tables.
 *
 * Adds a nullable `fiscal_year_id` column + B-tree index + declarative FK
 * to every table listed in config/fiscal.php.
 *
 * The column is added as NULLABLE first (metadata-only operation on PG 11+,
 * no table rewrite) and is set NOT NULL by the companion backfill migration
 * 2026_10_16_000002_backfill_fiscal_year_id.php after data is populated.
 *
 * Design notes:
 *
 * 1. Column type: `bigint` (Laravel `unsignedBigInteger`) to match
 *    fiscal_years.id which is `$table->id()` = bigint.
 *
 * 2. FK strategy: declarative FK from each operational table to fiscal_years.
 *    FKs FROM partitioned tables TO non-partitioned tables are supported
 *    natively in PostgreSQL 12+ (fiscal_years is not partitioned). The FK
 *    is created on the partitioned parent and propagates to all child
 *    partitions automatically.
 *
 * 3. Index strategy: B-tree index on (fiscal_year_id). On partitioned tables
 *    the index is created on the parent and propagated to partitions. This
 *    index is critical for Session 2's global scope filter
 *    `WHERE fiscal_year_id = ?` which will hit every operational query.
 *
 * 4. FK constraint naming: `fk_<table>_fyid` — stays well under PG's 63-char
 *    identifier limit even for the longest table name.
 *
 * 5. This migration is IDEMPOTENT-ish: it uses `ifNotExists` guards on columns
 *    and indexes, and wraps FK creation in a guard check, so partial-failure
 *    re-runs are safe. The `down()` method reverses each step.
 *
 * 6. NO application behaviour change: the column is added but no model,
 *    controller, or query references it yet. Session 2 wires the global scope.
 *
 * @see config/fiscal.php
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 1
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = config('fiscal.tables');

        foreach ($tables as $entry) {
            $table = $entry['table'];

            // ── Add column (nullable — set NOT NULL in backfill migration) ──
            if (! Schema::hasColumn($table, 'fiscal_year_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('fiscal_year_id')
                      ->nullable()
                      ->after('id');
                });
            }

            // ── B-tree index for the global scope filter ──
            $indexName = "idx_{$table}_fyid";
            $indexExists = DB::table('pg_indexes')
                ->where('tablename', $table)
                ->where('indexname', $indexName)
                ->exists();

            if (! $indexExists) {
                DB::statement("CREATE INDEX {$indexName} ON {$table} (fiscal_year_id)");
            }

            // ── Declarative FK to fiscal_years ──
            $fkName = "fk_{$table}_fyid";
            $fkExists = DB::table('pg_constraint')
                ->where('conname', $fkName)
                ->where('contype', 'f')
                ->exists();

            if (! $fkExists) {
                DB::statement(
                    "ALTER TABLE {$table} ".
                    "ADD CONSTRAINT {$fkName} ".
                    "FOREIGN KEY (fiscal_year_id) REFERENCES fiscal_years(id) ".
                    "ON DELETE RESTRICT"
                );
            }
        }
    }

    public function down(): void
    {
        $tables = config('fiscal.tables');

        // Reverse order so child tables are cleaned before parents (cosmetic;
        // not strictly required since the FKs point to fiscal_years, not to
        // each other).
        foreach (array_reverse($tables) as $entry) {
            $table = $entry['table'];
            $fkName = "fk_{$table}_fyid";
            $indexName = "idx_{$table}_fyid";

            // Drop FK
            $fkExists = DB::table('pg_constraint')
                ->where('conname', $fkName)
                ->where('contype', 'f')
                ->exists();
            if ($fkExists) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$fkName}");
            }

            // Drop index
            $indexExists = DB::table('pg_indexes')
                ->where('tablename', $table)
                ->where('indexname', $indexName)
                ->exists();
            if ($indexExists) {
                DB::statement("DROP INDEX {$indexName}");
            }

            // Drop column
            if (Schema::hasColumn($table, 'fiscal_year_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('fiscal_year_id');
                });
            }
        }
    }
};

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 10.1 — Phase 7.3: Export archived partitions to Parquet cold storage.
 *
 * After pg_partman's nightly `run_maintenance_proc()` detaches an expired
 * partition and moves it to the `archive` schema (configured by the Phase 7.1
 * retention matrix), this command exports the partition's data to a Parquet
 * file in cold storage. Once the export is verified, the archived table is
 * dropped (unless `--keep` is passed) so the archive schema doesn't grow
 * forever.
 *
 * Parquet is preferred because:
 *   - Columnar storage is ~5–10× smaller than CSV for ledger-style tables.
 *   - ZSTD compression preserves types (dates, numerics, bigints) losslessly.
 *   - Supports predicate pushdown — historical audit queries against cold
 *     storage are fast for filtered scans.
 *
 * DuckDB is used as the conversion engine because it can read PostgreSQL's
 * CSV COPY stream directly and write Parquet in a single pass. If DuckDB is
 * not installed on PATH, the command falls back to a plain CSV export so the
 * pipeline never blocks — operators can convert CSV→Parquet later on a
 * separate host.
 *
 * G-046 (CRITICAL, REPORTS-2): the CSV fallback is dangerous because the
 * command DROPs the archive table after a successful (CSV) export — the
 * original typed data is irretrievably lost and only the type-less CSV
 * remains. The Dockerfile now installs the DuckDB CLI binary (v1.1.0) so
 * the fallback path should never trigger in production. As defense-in-depth,
 * pass `--require-parquet` to ABORT (return FAILURE) instead of falling back
 * to CSV when DuckDB is missing. The quarterly schedule in routes/console.php
 * passes `--require-parquet` so a misconfigured image fails loud rather than
 * silently degrading archival fidelity.
 *
 * Lifecycle:
 *
 *   archive.<partition>              ┐
 *                                    │  this command
 *                                    │  COPY → DuckDB → Parquet
 *                                    ▼
 *   storage/app/partition-exports/   ┐
 *     <parent>_<partition>.parquet   │  cold storage (S3-synced by infra)
 *                                    │  this command, after successful export
 *                                    ▼
 *   DROP TABLE archive.<partition>   (unless --keep)
 *
 * Usage:
 *   php artisan partition:export-parquet              # export + drop
 *   php artisan partition:export-parquet --dry-run    # list only, no export
 *   php artisan partition:export-parquet --keep       # export, keep archive table
 *   php artisan partition:export-parquet --force      # overwrite existing parquet
 *
 * Scheduled quarterly at 04:30 on the 1st of Jan/Apr/Jul/Oct in
 * routes/console.php (offset from the 04:00 partition-consolidation cron so
 * exports operate on already-consolidated partitions).
 *
 * TODO (Phase 8): persist a row to a `partition_exports` table recording the
 * parent, partition, row count, byte size, parquet path, export timestamp,
 * and duckdb version. The `partition_exports` table is a Phase 8 concern;
 * for now we log to the Laravel `Log` facade and to console output.
 */
class ExportArchivedPartitionsToParquet extends Command
{
    protected $signature = 'partition:export-parquet
                            {--dry-run : List what would be exported without doing it}
                            {--keep : Do not drop the archive table after a successful export}
                            {--force : Overwrite an existing Parquet file}
                            {--require-parquet : Abort (return FAILURE) if DuckDB is not available — do not fall back to CSV}';

    protected $description = 'Export archived partitions to Parquet cold storage (quarterly)';

    /** @var string Laravel disk name for partition exports. */
    private const DISK = 'local';

    /** @var string Subdirectory under the disk root. */
    private const SUBDIR = 'partition-exports';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $keep   = (bool) $this->option('keep');
        $force  = (bool) $this->option('force');

        // Ensure the export directory exists on the local disk. The Laravel
        // `Storage` facade handles recursive creation.
        $this->ensureExportDirectory();

        // Detect DuckDB once. If absent, we fall back to CSV — unless the
        // caller passed --require-parquet, in which case we ABORT so the
        // quarterly schedule fails loud rather than silently degrading to a
        // type-less CSV export (and then DROPping the typed archive table).
        $requireParquet = (bool) $this->option('require-parquet');
        $duckdbPath = $this->findDuckdb();
        $useParquet = $duckdbPath !== null;
        if (! $useParquet) {
            if ($requireParquet) {
                $this->error('DuckDB not found on PATH and --require-parquet was passed. Aborting to avoid the CSV-fallback path that DROPs the typed archive table. Install DuckDB (see Dockerfile) or drop --require-parquet to allow CSV fallback.');
                Log::error('partition:export-parquet: aborted — DuckDB unavailable and --require-parquet set.');
                return self::FAILURE;
            }
            $this->warn('DuckDB not found on PATH — falling back to CSV export. Install DuckDB for native Parquet output, or pass --require-parquet to abort.');
            Log::warning('partition:export-parquet: DuckDB not available; falling back to CSV export.');
        }

        // List every table currently resident in the `archive` schema.
        $archivedTables = $this->listArchivedTables();

        if ($archivedTables->isEmpty()) {
            $this->info('No archived partitions to export.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Found %d archived table(s)%s.',
            $archivedTables->count(),
            $dryRun ? ' [DRY RUN]' : ''
        ));

        $exported = 0;
        $skipped  = 0;
        $failed   = 0;

        foreach ($archivedTables as $row) {
            $table = $row->table_name;

            $extension  = $useParquet ? 'parquet' : 'csv';
            $exportName = "{$table}.{$extension}";
            $relative   = self::SUBDIR . '/' . $exportName;

            $this->line("  → {$table}");

            if ($dryRun) {
                $this->line("      would export → {$relative}");
                continue;
            }

            // Skip if the output file already exists unless --force was passed.
            if (! $force && Storage::disk(self::DISK)->exists($relative)) {
                $this->line("      already exported — skipping (use --force to overwrite).");
                $skipped++;
                continue;
            }

            try {
                $bytes = $useParquet
                    ? $this->exportParquet($table, $relative, $duckdbPath)
                    : $this->exportCsv($table, $relative);

                $this->info(sprintf('      exported %s (%s)', $exportName, $this->formatBytes($bytes)));

                // REPORTS-AUDIT-7 (G-228 + G-233 / csv-export.md G13/G14):
                // persist a row to partition_exports (replaces the prior
                // TODO that only wrote to Log::info). The manifest table
                // lets operators answer "when was table X archived and how
                // big was it?" without grepping logs, and the sha256 column
                // lets downstream integrity checks detect silent corruption
                // of cold-storage files.
                $rowCount = $this->countArchivedRows($table);
                $sha256 = $this->computeFileSha256($relative);
                $duckdbVersion = $useParquet ? $this->getDuckdbVersion($duckdbPath) : null;

                try {
                    DB::table('partition_exports')->insert([
                        'parent_table'    => $table,
                        'partition_name'  => $table,
                        'parquet_path'    => $relative,
                        'byte_size'       => $bytes,
                        'row_count'       => $rowCount,
                        'sha256'          => $sha256,
                        'duckdb_version'  => $duckdbVersion,
                        'format'          => $useParquet ? 'parquet' : 'csv',
                        'exported_at'     => now(),
                    ]);
                } catch (\Throwable $e) {
                    // Manifest write failure is non-fatal — the export
                    // itself succeeded. Log + continue so the DROP below
                    // still runs (we do NOT want to leave the typed archive
                    // table hanging if the manifest insert failed).
                    $this->warn('      partition_exports insert failed: ' . $e->getMessage());
                    Log::warning('partition:export-parquet: manifest insert failed', [
                        'table' => $table,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Drop the archived table after a successful export — unless
                // the operator passed --keep. Use CASCADE so any dependent
                // views (unlikely, but possible) go quietly.
                if (! $keep) {
                    DB::statement("DROP TABLE IF EXISTS archive.{$table} CASCADE");
                    $this->line("      dropped archive.{$table}");
                }

                Log::info('partition:export-parquet: exported', [
                    'table'  => $table,
                    'path'   => $relative,
                    'bytes'  => $bytes,
                    'format' => $useParquet ? 'parquet' : 'csv',
                    'kept'   => $keep,
                ]);

                $exported++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error("      FAILED: {$e->getMessage()}");
                Log::error('partition:export-parquet: export failed', [
                    'table' => $table,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                // Continue to the next table — one bad partition shouldn't
                // block the rest of the quarterly run.
            }
        }

        if ($dryRun) {
            $this->info(sprintf('[DRY RUN] Would have exported %d table(s).', $archivedTables->count()));
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Done. Exported %d, skipped %d, failed %d.',
            $exported, $skipped, $failed
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Ensure the partition-exports directory exists on the local disk.
     */
    private function ensureExportDirectory(): void
    {
        $disk = Storage::disk(self::DISK);
        if (! $disk->exists(self::SUBDIR)) {
            $disk->makeDirectory(self::SUBDIR);
        }
    }

    /**
     * Find the `duckdb` binary on PATH. Returns the absolute path or null.
     */
    private function findDuckdb(): ?string
    {
        $which = @exec('which duckdb 2>/dev/null', $output, $resultCode);
        if ($resultCode === 0 && $which !== '' && is_executable($which)) {
            return trim($which);
        }
        return null;
    }

    /**
     * Get the DuckDB CLI version string (for the partition_exports manifest).
     *
     * REPORTS-AUDIT-7 (G-228 + G-233): recorded so future readers of the
     * manifest can detect format-compatibility drift (a Parquet file
     * produced by DuckDB v1.0 may not be readable by DuckDB v2.0+).
     */
    private function getDuckdbVersion(string $duckdbPath): ?string
    {
        $version = @exec(escapeshellarg($duckdbPath) . ' --version 2>/dev/null', $output, $resultCode);
        if ($resultCode === 0 && $version !== '') {
            return trim($version);
        }
        return null;
    }

    /**
     * Count rows in an archived partition table (for the manifest).
     *
     * REPORTS-AUDIT-7 (G-228 + G-233): runs BEFORE the DROP TABLE so the
     * row count is captured even if the archive table is dropped after
     * export. Uses COUNT(*) which is fast on archive tables (they are
     * typically < 1M rows per monthly partition).
     */
    private function countArchivedRows(string $table): int
    {
        try {
            $result = DB::selectOne("SELECT COUNT(*) AS cnt FROM archive.\"{$table}\"");
            return (int) ($result->cnt ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Compute the SHA-256 hash of an exported file (for integrity checking).
     *
     * REPORTS-AUDIT-7 (G-228 + G-233): stored in partition_exports.sha256
     * so downstream integrity checks can detect silent corruption of
     * cold-storage Parquet/CSV files (bit rot, accidental truncation,
     * storage-layer degradation). Returns null if the file is unreadable
     * or the hash function is unavailable.
     */
    private function computeFileSha256(string $relative): ?string
    {
        try {
            $disk = Storage::disk(self::DISK);
            if (!$disk->exists($relative)) {
                return null;
            }
            $absolutePath = $disk->path($relative);
            if (!is_readable($absolutePath)) {
                return null;
            }
            $hash = hash_file('sha256', $absolutePath);
            return $hash !== false ? $hash : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * List all tables in the `archive` schema, ordered by name.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function listArchivedTables()
    {
        return DB::table('information_schema.tables')
            ->where('table_schema', 'archive')
            ->where('table_type', 'BASE TABLE')
            ->orderBy('table_name')
            ->get(['table_name']);
    }

    /**
     * Export an archived table to Parquet via DuckDB.
     *
     * Strategy: pipe PostgreSQL's COPY-to-STDOUT CSV stream directly into
     * DuckDB, which reads it via `read_csv()` and writes Parquet via
     * `COPY ... TO ... (FORMAT PARQUET, COMPRESSION ZSTD)`. This avoids
     * materializing an intermediate CSV file on disk.
     *
     * Returns the byte size of the produced Parquet file.
     */
    private function exportParquet(string $table, string $relative, string $duckdbPath): int
    {
        $disk = Storage::disk(self::DISK);
        $absolutePath = $disk->path($relative);
        $tempCsv = $disk->path(self::SUBDIR . "/.{$table}.tmp.csv");

        // Step 1: PostgreSQL COPY to a temp CSV file (with HEADER for DuckDB
        // type inference). We use a temp file rather than a STDIN pipe
        // because pg_cron / DB::statement don't easily expose a streaming
        // PDO COPY handle from inside a Laravel migration-style call.
        DB::statement(<<<SQL
            COPY (SELECT * FROM archive."{$table}") TO '{$this->escapeSqlPath($tempCsv)}'
            WITH (FORMAT CSV, HEADER true)
        SQL);

        // Step 2: DuckDB reads the temp CSV and writes Parquet with ZSTD.
        $duckSql = sprintf(
            "COPY (SELECT * FROM read_csv('%s', header=true)) TO '%s' (FORMAT PARQUET, COMPRESSION ZSTD);",
            $this->escapeCliArg($tempCsv),
            $this->escapeCliArg($absolutePath)
        );

        $cmd = sprintf(
            '%s -c %s',
            escapeshellarg($duckdbPath),
            escapeshellarg($duckSql)
        );

        @exec($cmd . ' 2>&1', $out, $rc);

        // Always remove the temp CSV (even on failure) so we don't leak disk.
        @unlink($tempCsv);

        if ($rc !== 0) {
            throw new \RuntimeException(
                'DuckDB export failed (rc=' . $rc . '): ' . implode("\n", $out)
            );
        }

        $size = $disk->size($relative);
        if ($size <= 0) {
            throw new \RuntimeException("Parquet file is empty or missing: {$absolutePath}");
        }

        return $size;
    }

    /**
     * Fallback: export an archived table to a plain CSV file.
     * Used when DuckDB is not available. Returns the byte size of the CSV.
     */
    private function exportCsv(string $table, string $relative): int
    {
        $disk = Storage::disk(self::DISK);
        $absolutePath = $disk->path($relative);

        DB::statement(<<<SQL
            COPY (SELECT * FROM archive."{$table}") TO '{$this->escapeSqlPath($absolutePath)}'
            WITH (FORMAT CSV, HEADER true)
        SQL);

        $size = $disk->size($relative);
        if ($size <= 0) {
            throw new \RuntimeException("CSV file is empty or missing: {$absolutePath}");
        }

        return $size;
    }

    /**
     * Escape a file path for use inside a PostgreSQL single-quoted string
     * literal (COPY TO '<path>'). Doubles any single quotes/backslashes.
     */
    private function escapeSqlPath(string $path): string
    {
        return str_replace(['\\', "'"], ['\\\\', "''"], $path);
    }

    /**
     * Escape a file path for inclusion in a DuckDB SQL string passed via the
     * shell. Doubles single quotes (DuckDB string-literal rules).
     */
    private function escapeCliArg(string $path): string
    {
        return str_replace("'", "''", $path);
    }

    /**
     * Human-readable byte size formatter.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1) . ' MB';
        }
        return number_format($bytes / (1024 * 1024 * 1024), 2) . ' GB';
    }
}

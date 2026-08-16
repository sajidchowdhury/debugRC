<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DatabaseBackup;
use App\Models\FiscalYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * DatabaseBackupService — produces and verifies pg_dump -Fc backups.
 *
 * Created in Session 3 (Q1, Gap 3). Implements the client's
 * "auto-backup DB file to PC on FY close" requirement.
 *
 * The service is the single entry point for year-end database backups.
 * It is invoked by:
 *   - `php artisan db:backup-year-end` (manual / cron)
 *   - The year-end checklist UI (informational — shows backup status)
 *
 * The yearEndClose() gate calls isBackupFresh() to decide whether
 * close can proceed. If no fresh verified backup exists, the close
 * throws YearEndCloseException.
 *
 * pg_dump invocation
 * ------------------
 * The service builds a pg_dump command line from config/database.php
 * connection params + config/backup.php options. It uses
 * Symfony\Component\Process\Process to invoke pg_dump with the
 * PGPASSWORD environment variable set (never on the command line —
 * that would leak the password in `ps` output).
 *
 * Output format: -Fc (custom compressed). This is the format required
 * by pg_restore and supports selective table restore, parallel
 * restore, and compression. The .dump file extension is conventional.
 *
 * SHA-256 verification
 * --------------------
 * After pg_dump completes, the service computes the SHA-256 hash of
 * the output file and stores it in database_backups.sha256_hash.
 * verifyBackup() re-reads the file and recomputes the hash — if it
 * differs from the stored value, the file has been corrupted or
 * tampered with, and the backup is marked 'failed'.
 *
 * Retention
 * ---------
 * When a new backup is created and marked 'verified', all older
 * 'verified' backups for the same FY (beyond the retention_count
 * threshold) are marked 'superseded'. The files are NOT deleted —
 * they remain on disk for manual recovery. Disk cleanup is a manual
 * ops task (documented in the Session 3 confirmation doc).
 *
 * @see \App\Console\Commands\BackupDatabaseYearEnd
 * @see \App\Services\Accounting\AccountingPeriodService::yearEndClose()
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 3
 */
class DatabaseBackupService
{
    /**
     * Create a new pg_dump -Fc backup for the given fiscal year.
     *
     * @param  int      $fiscalYearId  The fiscal year being backed up.
     * @param  int|null $userId       The user triggering the backup (null for system/cron).
     * @return array{
     *     'backup_id': int,
     *     'file_path': string,
     *     'sha256': string,
     *     'size_bytes': int,
     *     'pg_dump_version': string|null,
     * }
     *
     * @throws \RuntimeException  If pg_dump fails, the output directory
     *                            cannot be created, or the fiscal year
     *                            does not exist.
     */
    public function backupFiscalYear(int $fiscalYearId, ?int $userId = null): array
    {
        $fy = FiscalYear::find($fiscalYearId);
        if (!$fy) {
            throw new \RuntimeException("Fiscal year #{$fiscalYearId} not found.");
        }

        // Resolve config.
        $pgDumpBinary = (string) config('backup.pg_dump_binary');
        $backupPath   = (string) config('backup.backup_path');
        $connection   = (string) config('backup.connection');
        $timeout      = (int) config('backup.process_timeout', 600);
        $options      = (array) config('backup.pg_dump_options', []);

        // Ensure the backup directory exists (0700 — sensitive data).
        if (!is_dir($backupPath) && !@mkdir($backupPath, 0700, true) && !is_dir($backupPath)) {
            throw new \RuntimeException("Cannot create backup directory: {$backupPath}");
        }

        // Build the output filename: FY{code}_{YYYYMMDD_HHMMSS}.dump
        $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '_', $fy->fiscal_year_code ?? "FY{$fy->id}");
        $timestamp = now()->format('Ymd_His');
        $filename = "{$safeCode}_{$timestamp}.dump";
        $filePath = $backupPath . DIRECTORY_SEPARATOR . $filename;

        // Build pg_dump argument list.
        $dbConfig = config("database.connections.{$connection}");
        if (!$dbConfig || ($dbConfig['driver'] ?? '') !== 'pgsql') {
            throw new \RuntimeException(
                "Backup requires a 'pgsql' DB connection. Connection '{$connection}' is "
                . ($dbConfig ? "'" . ($dbConfig['driver'] ?? 'unknown') . "'" : 'not configured') . '.'
            );
        }

        $args = array_merge(
            [$pgDumpBinary],
            $options,
            [
                '--host=' . ($dbConfig['host'] ?? '127.0.0.1'),
                '--port=' . ($dbConfig['port'] ?? '5432'),
                '--username=' . ($dbConfig['username'] ?? ''),
                '--dbname=' . ($dbConfig['database'] ?? ''),
                '--file=' . $filePath,
            ]
        );

        // Run pg_dump with PGPASSWORD set in the env (never on the CLI).
        $process = new Process($args);
        $process->setTimeout($timeout);
        $process->setEnv(array_merge($_ENV, [
            'PGPASSWORD' => (string) ($dbConfig['password'] ?? ''),
        ]));

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            // Record a failed backup row, then rethrow with stderr context.
            $errorMessage = $process->getErrorOutput() ?: $e->getMessage();
            $this->recordFailedBackup($fiscalYearId, $filePath, $userId, $errorMessage);
            throw new \RuntimeException(
                "pg_dump failed for fiscal year #{$fiscalYearId}: " . trim($errorMessage)
            );
        }

        // File should now exist and be non-empty.
        if (!file_exists($filePath) || filesize($filePath) === 0) {
            $this->recordFailedBackup($fiscalYearId, $filePath, $userId, 'pg_dump produced no output file');
            throw new \RuntimeException("pg_dump produced no output file at {$filePath}");
        }

        // Compute SHA-256 + capture file size.
        $sha256    = hash_file('sha256', $filePath);
        $sizeBytes = (int) filesize($filePath);

        // Capture pg_dump version (best-effort — don't fail if this errors).
        $pgDumpVersion = $this->capturePgDumpVersion($pgDumpBinary);

        // Insert the database_backups row.
        $backupId = DB::table('database_backups')->insertGetId([
            'fiscal_year_id'     => $fiscalYearId,
            'file_path'          => $filePath,
            'file_size_bytes'    => $sizeBytes,
            'sha256_hash'        => $sha256,
            'pg_dump_version'    => $pgDumpVersion,
            'created_by_user_id' => $userId,
            'status'             => 'verified',
            'error_message'      => null,
            'created_at'         => now(),
        ]);

        // Mark older verified backups as superseded (retention).
        $this->applyRetention($fiscalYearId);

        Log::info('Database backup created', [
            'backup_id'      => $backupId,
            'fiscal_year_id' => $fiscalYearId,
            'file_path'      => $filePath,
            'sha256'         => $sha256,
            'size_bytes'     => $sizeBytes,
            'user_id'        => $userId,
        ]);

        return [
            'backup_id'       => $backupId,
            'file_path'       => $filePath,
            'sha256'          => $sha256,
            'size_bytes'      => $sizeBytes,
            'pg_dump_version' => $pgDumpVersion,
        ];
    }

    /**
     * Re-read the backup file and verify its SHA-256 matches the stored value.
     *
     * @param  int $backupId
     * @return bool  True if the file exists and the hash matches.
     *               False (and the row is marked 'failed') if the file
     *               is missing or the hash differs.
     */
    public function verifyBackup(int $backupId): bool
    {
        $backup = DatabaseBackup::find($backupId);
        if (!$backup) {
            return false;
        }

        if (!file_exists($backup->file_path)) {
            $this->markFailed($backup, 'File not found on disk');
            return false;
        }

        $currentHash = hash_file('sha256', $backup->file_path);
        if ($currentHash !== $backup->sha256_hash) {
            $this->markFailed($backup, "SHA-256 mismatch: stored={$backup->sha256_hash}, actual={$currentHash}");
            return false;
        }

        return true;
    }

    /**
     * Return the most recent 'verified' backup for the given fiscal year.
     *
     * @param  int $fiscalYearId
     * @return DatabaseBackup|null
     */
    public function latestBackupForFiscalYear(int $fiscalYearId): ?DatabaseBackup
    {
        return DatabaseBackup::forFiscalYear($fiscalYearId)
            ->verified()
            ->latest('id')
            ->first();
    }

    /**
     * Check whether a fresh, verified backup exists for the given FY.
     *
     * "Fresh" = created within the last `config('backup.freshness_hours')` hours
     * (default 24). Used by the yearEndClose() gate.
     *
     * @param  int $fiscalYearId
     * @param  int $maxAgeHours  Override the config value (used in tests).
     * @return bool
     */
    public function isBackupFresh(int $fiscalYearId, ?int $maxAgeHours = null): bool
    {
        $maxAgeHours = $maxAgeHours ?? (int) config('backup.freshness_hours', 24);
        $latest = $this->latestBackupForFiscalYear($fiscalYearId);

        if (!$latest) {
            return false;
        }

        // Verify the file still exists and the hash matches (cheap
        // integrity check — re-hashes the file). If verification
        // fails, the row is marked 'failed' and we return false.
        if (!$this->verifyBackup($latest->id)) {
            return false;
        }

        return $latest->created_at->greaterThan(now()->subHours($maxAgeHours));
    }

    /**
     * Mark older 'verified' backups for this FY as 'superseded', keeping
     * only the most recent `retention_count`.
     */
    private function applyRetention(int $fiscalYearId): void
    {
        $retention = (int) config('backup.retention_count', 5);
        if ($retention <= 0) {
            return; // 0 = keep all
        }

        // Find all 'verified' backups for this FY, newest first.
        $keepIds = DatabaseBackup::forFiscalYear($fiscalYearId)
            ->verified()
            ->latest('id')
            ->take($retention)
            ->pluck('id')
            ->all();

        if (empty($keepIds)) {
            return;
        }

        // Mark all 'verified' backups NOT in the keep list as 'superseded'.
        DatabaseBackup::forFiscalYear($fiscalYearId)
            ->verified()
            ->whereNotIn('id', $keepIds)
            ->update([
                'status'       => 'superseded',
                'error_message' => 'Superseded by a newer verified backup',
            ]);
    }

    /**
     * Capture the pg_dump version string via `pg_dump --version`.
     * Returns null if the command fails (best-effort).
     */
    private function capturePgDumpVersion(string $binary): ?string
    {
        try {
            $process = new Process([$binary, '--version']);
            $process->setTimeout(10);
            $process->mustRun();
            return trim($process->getOutput());
        } catch (\Throwable $e) {
            Log::warning('Could not capture pg_dump version', ['binary' => $binary, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Record a 'failed' backup row in the database.
     */
    private function recordFailedBackup(int $fiscalYearId, string $filePath, ?int $userId, string $errorMessage): void
    {
        DB::table('database_backups')->insert([
            'fiscal_year_id'     => $fiscalYearId,
            'file_path'          => $filePath,
            'file_size_bytes'    => 0,
            'sha256_hash'        => '',
            'pg_dump_version'    => null,
            'created_by_user_id' => $userId,
            'status'             => 'failed',
            'error_message'      => $errorMessage,
            'created_at'         => now(),
        ]);
    }

    /**
     * Mark a backup row as 'failed' with the given reason.
     */
    private function markFailed(DatabaseBackup $backup, string $reason): void
    {
        $backup->update([
            'status'        => 'failed',
            'error_message' => $reason,
        ]);

        Log::warning('Database backup verification failed', [
            'backup_id' => $backup->id,
            'reason'    => $reason,
        ]);
    }
}

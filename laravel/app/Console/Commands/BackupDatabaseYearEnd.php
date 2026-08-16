<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use App\Support\FiscalYearResolver;
use Illuminate\Console\Command;

/**
 * BackupDatabaseYearEnd — `php artisan db:backup-year-end`.
 *
 * Created in Session 3 (Q1, Gap 3).
 *
 * Produces a `pg_dump -Fc` backup file for a fiscal year, computes the
 * SHA-256 hash, and writes a row to the `database_backups` table with
 * status='verified'. The backup file is written to the path configured
 * in config/backup.php (typically storage_path('app/backups') in dev,
 * or a path on the client's PC in production — set via BACKUP_PATH env).
 *
 * Usage:
 *   php artisan db:backup-year-end
 *   php artisan db:backup-year-end --fiscal-year=42
 *   php artisan db:backup-year-end --verify  # verify latest backup only
 *
 * The yearEndClose() gate calls DatabaseBackupService::isBackupFresh()
 * — if no fresh verified backup exists, close throws
 * YearEndCloseException. So the typical workflow at year-end is:
 *
 *   1. php artisan db:backup-year-end --fiscal-year=<closing-fy-id>
 *   2. Verify the file exists at the printed path.
 *   3. Trigger year-end close via the UI or via FiscalYearService.
 *
 * Exit codes:
 *   0 — success (backup created and verified, or --verify passed)
 *   1 — failure (pg_dump error, FY not found, file not writable, etc.)
 *
 * @see \App\Services\DatabaseBackupService
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 3
 */
class BackupDatabaseYearEnd extends Command
{
    protected $signature = 'db:backup-year-end
                            {--fiscal-year= : Fiscal year ID (defaults to active FY)}
                            {--verify : Verify the latest backup for the FY instead of creating a new one}
                            {--user= : User ID to attribute the backup to (default: null = system)}';

    protected $description = 'Create a pg_dump -Fc year-end backup for a fiscal year, with SHA-256 verification.';

    public function handle(DatabaseBackupService $service): int
    {
        // Resolve the fiscal year ID.
        $fyIdOption = $this->option('fiscal-year');
        if ($fyIdOption) {
            $fyId = (int) $fyIdOption;
        } else {
            try {
                $fyId = FiscalYearResolver::activeId();
            } catch (\RuntimeException $e) {
                $this->error('No active fiscal year found.');
                $this->line('  Specify one with --fiscal-year=<id>, or activate a FY first.');
                $this->line('  Error: ' . $e->getMessage());
                return 1;
            }
        }

        $userId = $this->option('user') ? (int) $this->option('user') : null;

        // --verify mode: just check the latest backup.
        if ($this->option('verify')) {
            return $this->handleVerify($service, $fyId);
        }

        // Normal mode: create a new backup.
        $this->info("Creating year-end backup for fiscal year #{$fyId}...");
        $this->line('  Output path: ' . config('backup.backup_path'));

        $start = microtime(true);

        try {
            $result = $service->backupFiscalYear($fyId, $userId);
        } catch (\Throwable $e) {
            $this->error('Backup FAILED: ' . $e->getMessage());
            return 1;
        }

        $elapsed = round(microtime(true) - $start, 2);

        $this->newLine();
        $this->info('✓ Backup created and verified.');
        $this->table(
            ['Field', 'Value'],
            [
                ['Backup ID',       $result['backup_id']],
                ['File path',       $result['file_path']],
                ['Size',            $this->formatBytes($result['size_bytes'])],
                ['SHA-256',         $result['sha256']],
                ['pg_dump version', $result['pg_dump_version'] ?? '(unknown)'],
                ['Elapsed',         "{$elapsed}s"],
            ]
        );

        $this->newLine();
        $this->line('  The year-end close gate now permits close for fiscal year #'.$fyId.'.');
        $this->line('  (Backup is fresh for '.config('backup.freshness_hours', 24).' hours.)');

        return 0;
    }

    /**
     * --verify mode: re-check the latest backup's file + hash.
     */
    private function handleVerify(DatabaseBackupService $service, int $fyId): int
    {
        $latest = $service->latestBackupForFiscalYear($fyId);
        if (!$latest) {
            $this->warn("No verified backup found for fiscal year #{$fyId}.");
            return 1;
        }

        $this->info("Verifying backup #{$latest->id} for fiscal year #{$fyId}...");
        $ok = $service->verifyBackup($latest->id);

        if ($ok) {
            $this->info('✓ Verification PASSED.');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Backup ID',  $latest->id],
                    ['File path',  $latest->file_path],
                    ['Size',       $this->formatBytes($latest->file_size_bytes)],
                    ['SHA-256',    $latest->sha256_hash],
                    ['Created at', $latest->created_at->format('Y-m-d H:i:s')],
                    ['Fresh',      $service->isBackupFresh($fyId) ? 'YES' : 'NO (stale)'],
                ]
            );
            return 0;
        } else {
            $this->error('✗ Verification FAILED — file missing or SHA-256 mismatch.');
            $latest->refresh();
            $this->line('  Reason: ' . ($latest->error_message ?? 'unknown'));
            return 1;
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, 2) . ' ' . $units[$i];
    }
}

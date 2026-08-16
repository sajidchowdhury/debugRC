<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Session 3 — database_backups table.
 *
 * Tracks every year-end database backup produced by
 * `php artisan db:backup-year-end`. Each row records the absolute file
 * path, file size, SHA-256 hash, pg_dump version, and verification
 * status. The yearEndClose() gate queries this table to check whether
 * a fresh, verified backup exists for the fiscal year being closed.
 *
 * Schema (per plan §Session 3):
 *   id                  BIGINT PK
 *   fiscal_year_id      BIGINT FK -> fiscal_years.id
 *   file_path           TEXT (absolute path on disk)
 *   file_size_bytes     BIGINT
 *   sha256_hash         CHAR(64)
 *   pg_dump_version     TEXT (e.g., "pg_dump (PostgreSQL) 16.4")
 *   created_at          TIMESTAMP
 *   created_by_user_id  BIGINT FK -> users.id (nullable for system-run)
 *   status              TEXT CHECK in ('verified','failed','superseded')
 *
 * Indexes:
 *   idx_db_backups_fy_status    (fiscal_year_id, status)  — used by
 *        latestBackupForFiscalYear() and isBackupFresh()
 *   idx_db_backups_created_at   (created_at DESC)  — used by retention
 *        pruning (mark older verified backups as 'superseded')
 *
 * @see \App\Models\DatabaseBackup
 * @see \App\Services\DatabaseBackupService
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 3
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_backups', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Fiscal year this backup was created for. FK to fiscal_years.
            $table->unsignedBigInteger('fiscal_year_id');
            $table->foreign('fiscal_year_id', 'fk_db_backups_fy')
                  ->references('id')->on('fiscal_years')
                  ->onDelete('restrict');

            // Absolute path to the .dump file on disk. TEXT because paths
            // can be long (especially on Windows: C:\rcerp\backups\...).
            $table->text('file_path');

            // File size in bytes. BIGINT — pg_dump -Fc output for a
            // medium-sized ERP DB is typically 100MB-2GB.
            $table->bigInteger('file_size_bytes');

            // SHA-256 hash of the file (64 hex chars). Used by
            // verifyBackup() to detect file corruption / tampering.
            $table->char('sha256_hash', 64);

            // pg_dump version string, captured at backup time so we know
            // which pg_restore version is needed to restore.
            $table->string('pg_dump_version', 100)->nullable();

            // Who triggered the backup. Nullable for system/cron-run backups.
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->foreign('created_by_user_id', 'fk_db_backups_user')
                  ->references('id')->on('users')
                  ->onDelete('set null');

            // Status lifecycle:
            //   verified   — file exists, SHA-256 matches, fresh
            //   failed     — pg_dump exited non-zero, no file written
            //   superseded — a newer verified backup exists for this FY
            //                (file NOT deleted — kept for manual recovery)
            $table->string('status', 20)->default('verified');

            // Error message if status='failed'. Nullable.
            $table->text('error_message')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // Indexes — see class docblock.
            $table->index(['fiscal_year_id', 'status'], 'idx_db_backups_fy_status');
            $table->index('created_at', 'idx_db_backups_created_at');
        });

        // CHECK constraint added via raw SQL — Blueprint::check() is not
        // available on all Laravel 12.x minor releases (added in 12.x
        // but missing from earlier patches). Using ALTER TABLE works on
        // every version and produces the same PostgreSQL constraint.
        DB::statement(
            "ALTER TABLE database_backups " .
            "ADD CONSTRAINT db_backups_status_check " .
            "CHECK (status IN ('verified', 'failed', 'superseded'))"
        );
    }

    public function down(): void
    {
        // Drop the CHECK constraint before dropping the table — symmetric
        // teardown, also makes the migration reversible on PG.
        DB::statement('ALTER TABLE database_backups DROP CONSTRAINT IF EXISTS db_backups_status_check');
        Schema::dropIfExists('database_backups');
    }
};

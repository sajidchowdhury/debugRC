<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10.1 — Phase 0.5 + 0.6: Schedule pg_partman maintenance cron + create archive schema.
 *
 * Audit findings:
 *   B6: No pg_partman.run_maintenance() cron job is scheduled. Future partitions
 *       beyond p_premake=6 months will silently stop being auto-created.
 *   B7: The `archive` schema is referenced by 12 retention configs
 *       (retention_schema = 'archive') but is never explicitly created.
 *       pg_partman would lazy-create it on first retention run, but the
 *       absence is fragile.
 *
 * This migration:
 *   1. Creates the `archive` schema explicitly (idempotent).
 *   2. Schedules a daily pg_cron job to run pg_partman maintenance at 02:00.
 *      pg_partman's run_maintenance_proc() auto-creates future partitions
 *      and detaches expired ones per the retention config.
 *
 * Prerequisite: pg_cron extension must be available (installed by migration
 * 2025_01_20_000009_add_pg_cron_scheduled_jobs.php).
 * Prerequisite: pg_partman extension must be available (installed by migration
 * 2025_01_21_000004_set_up_table_partitioning.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. Create the archive schema explicitly.
        //    This is where pg_partman moves detached partitions when
        //    retention_keep_table = true and retention_schema = 'archive'.
        //    Creating it now prevents surprises during the first retention run.
        // ============================================================
        DB::statement('CREATE SCHEMA IF NOT EXISTS archive');

        // Grant USAGE to the application role (if it exists) so archived
        // partitions can still be queried directly if needed.
        $roles = DB::select("
            SELECT rolname FROM pg_roles
            WHERE rolname IN ('remote_center', 'postgres')
        ");
        foreach ($roles as $role) {
            try {
                DB::statement("GRANT USAGE ON SCHEMA archive TO {$role->rolname}");
            } catch (\Throwable $e) {
                // Non-fatal — the schema exists, grants can be added later.
            }
        }

        // ============================================================
        // 2. Schedule pg_partman daily maintenance via pg_cron.
        //    Runs at 02:00 daily — before the 03:00 partition-health-check
        //    job (to be added in Phase 8) so health checks see fresh state.
        //
        //    pg_partman 5.x uses run_maintenance_proc() (a PROCEDURE).
        //    pg_cron can call procedures via CALL or via SELECT on the
        //    function wrapper. We use the proc form with a fallback.
        // ============================================================
        $pgCronAvailable = DB::selectOne("
            SELECT EXISTS (
                SELECT 1 FROM pg_extension WHERE extname = 'pg_cron'
            ) AS installed
        ");

        if (!($pgCronAvailable->installed ?? false)) {
            Log::warning('pg_cron extension not available — pg_partman maintenance cron not scheduled. Laravel scheduler is the fallback.');
            return;
        }

        $partmanAvailable = DB::selectOne("
            SELECT EXISTS (
                SELECT 1 FROM pg_extension WHERE extname = 'pg_partman'
            ) AS installed
        ");

        if (!($partmanAvailable->installed ?? false)) {
            Log::warning('pg_partman extension not available — maintenance cron not scheduled.');
            return;
        }

        // Unschedule any existing job with the same name (idempotent).
        try {
            DB::statement("SELECT cron.unschedule('partman-maintenance')");
        } catch (\Throwable $e) {
            // Job doesn't exist yet — safe to ignore.
        }

        // Schedule: daily at 02:00.
        // Use run_maintenance_proc() (pg_partman 5.x). If running pg_partman 4.x,
        // fall back to run_maintenance() function.
        $partmanVersion = DB::selectOne("
            SELECT extversion FROM pg_extension WHERE extname = 'pg_partman'
        ");

        $isV5 = $partmanVersion && version_compare($partmanVersion->extversion, '5.0', '>=');

        if ($isV5) {
            // pg_partman 5.x: use CALL on the procedure.
            // pg_cron wraps the command in a transaction automatically.
            DB::statement(<<<'SQL'
                SELECT cron.schedule(
                    'partman-maintenance',
                    '0 2 * * *',
                    $$CALL partman.run_maintenance_proc()$$
                )
            SQL);
        } else {
            // pg_partman 4.x: use the function form.
            DB::statement(<<<'SQL'
                SELECT cron.schedule(
                    'partman-maintenance',
                    '0 2 * * *',
                    $$SELECT partman.run_maintenance()$$
                )
            SQL);
        }

        Log::info('pg_partman maintenance cron scheduled: daily at 02:00');
    }

    public function down(): void
    {
        // Unschedule the pg_partman maintenance job.
        try {
            DB::statement("SELECT cron.unschedule('partman-maintenance')");
        } catch (\Throwable $e) {
            // Job doesn't exist — safe to ignore.
        }

        // Note: We do NOT drop the archive schema in down() because it may
        // already contain detached partitions. Dropping it would cause data loss.
        // If a full rollback is needed, drop the schema manually after verifying
        // it's empty: DROP SCHEMA archive CASCADE;
    }
};

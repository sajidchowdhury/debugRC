<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: Widen financial_audit_log.operation from VARCHAR(6) to VARCHAR(10)
 * and update the CHECK constraint to allow 'REFRESH'.
 *
 * The migration 2026_09_04_000001 added 'REFRESH' to the CHECK constraint
 * but didn't widen the column — 'REFRESH' is 7 chars but the column was
 * VARCHAR(6), causing "value too long for type character varying(6)" errors
 * when refresh_all_report_views() inserts audit rows with operation='REFRESH'.
 *
 * Also updates the SQL file's fn_financial_audit_trigger() variable
 * declaration from VARCHAR(6) to VARCHAR(10) — but since the trigger
 * function is recreated by existing migrations, only the column ALTER
 * is needed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Widen the operation column from VARCHAR(6) to VARCHAR(10).
        //    For partitioned tables, ALTER on the parent propagates to children.
        DB::statement("
            ALTER TABLE financial_audit_log
            ALTER COLUMN operation TYPE VARCHAR(10)
        ");

        // 2. Update the CHECK constraint to include 'REFRESH'.
        //    (Idempotent — drop + re-add.)
        DB::statement("
            ALTER TABLE financial_audit_log
            DROP CONSTRAINT IF EXISTS financial_audit_log_operation_check
        ");
        DB::statement("
            ALTER TABLE financial_audit_log
            ADD CONSTRAINT financial_audit_log_operation_check
            CHECK (operation IN ('INSERT','UPDATE','DELETE','REFRESH'))
        ");
    }

    public function down(): void
    {
        // Remove REFRESH from the constraint.
        DB::statement("
            ALTER TABLE financial_audit_log
            DROP CONSTRAINT IF EXISTS financial_audit_log_operation_check
        ");
        DB::statement("
            ALTER TABLE financial_audit_log
            ADD CONSTRAINT financial_audit_log_operation_check
            CHECK (operation IN ('INSERT','UPDATE','DELETE'))
        ");

        // Shrink the column back (will fail if REFRESH rows exist,
        // but that's expected on rollback).
        DB::statement("
            ALTER TABLE financial_audit_log
            ALTER COLUMN operation TYPE VARCHAR(6)
        ");
    }
};

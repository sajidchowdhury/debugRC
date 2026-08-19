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
 * IMPORTANT: PostgreSQL refuses to ALTER COLUMN ... TYPE on a column that a
 * view depends on (SQLSTATE[0A000]: "cannot alter type of a column used by a
 * view or rule"). The view v_financial_audit_chain_verification SELECTs the
 * operation column, so we must DROP the view before the ALTER and RECREATE it
 * afterward — using the same definition established by
 * 2026_08_15_000001_fix_financial_audit_log_partitioning (so the resulting
 * schema is identical to before, just with a wider column).
 */
return new class extends Migration
{
    /**
     * Canonical view definition — kept in sync with
     * 2026_08_15_000001_fix_financial_audit_log_partitioning.
     */
    private const VIEW_DEFINITION = <<<'SQL'
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
    SQL;

    private const VIEW_COMMENT = <<<'SQL'
        COMMENT ON VIEW v_financial_audit_chain_verification IS
            'Phase 1.3: Verification view for the cryptographic hash chain. If chain_valid is FALSE, the audit trail has been tampered with.'
    SQL;

    public function up(): void
    {
        // 1. Drop the dependent view — PG won't let us ALTER the column otherwise.
        DB::statement('DROP VIEW IF EXISTS v_financial_audit_chain_verification');

        // 2. Widen the operation column from VARCHAR(6) to VARCHAR(10).
        //    For partitioned tables, ALTER on the parent propagates to children.
        DB::statement("
            ALTER TABLE financial_audit_log
            ALTER COLUMN operation TYPE VARCHAR(10)
        ");

        // 3. Update the CHECK constraint to include 'REFRESH'.
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

        // 4. Recreate the verification view (identical definition to before).
        DB::statement(self::VIEW_DEFINITION);
        DB::statement(self::VIEW_COMMENT);
    }

    public function down(): void
    {
        // 1. Drop the dependent view again before shrinking the column.
        DB::statement('DROP VIEW IF EXISTS v_financial_audit_chain_verification');

        // 2. Remove REFRESH from the CHECK constraint.
        DB::statement("
            ALTER TABLE financial_audit_log
            DROP CONSTRAINT IF EXISTS financial_audit_log_operation_check
        ");
        DB::statement("
            ALTER TABLE financial_audit_log
            ADD CONSTRAINT financial_audit_log_operation_check
            CHECK (operation IN ('INSERT','UPDATE','DELETE'))
        ");

        // 3. Shrink the column back to VARCHAR(6).
        //    Will fail if REFRESH rows exist — that's expected on rollback.
        DB::statement("
            ALTER TABLE financial_audit_log
            ALTER COLUMN operation TYPE VARCHAR(6)
        ");

        // 4. Recreate the verification view.
        DB::statement(self::VIEW_DEFINITION);
        DB::statement(self::VIEW_COMMENT);
    }
};

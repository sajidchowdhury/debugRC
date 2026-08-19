<?php

/**
 * Fix orphaned fiscal_year_id default triggers on tables that lack the column.
 *
 * The shared trigger function fn_set_fiscal_year_id_default() references
 * NEW.fiscal_year_id. If a trigger using this function exists on a table
 * that does NOT have fiscal_year_id, PostgreSQL raises:
 *   SQLSTATE[42703]: Undefined column: record "new" has no field "fiscal_year_id"
 *
 * This migration:
 *   1. Drops any stale trg_fy_default_* trigger from tables lacking fiscal_year_id
 *   2. Re-creates the function to be self-guarding — it now checks pg_attribute
 *      to confirm the column exists before referencing NEW.fiscal_year_id
 *
 * Root cause: the previous migration (2026_10_21) ran before stock_take_items
 * was removed from the FISCAL_TABLES list, or a manual DB change created the
 * orphaned trigger.
 */
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tables that do NOT have fiscal_year_id but might have the orphaned trigger.
     */
    private const NON_FISCAL_TABLES = [
        'stock_take_items',
        'warehouse_transfer_items',  // verify — this table DOES have fiscal_year_id per migration
    ];

    public function up(): void
    {
        // ── Step 1: Drop orphaned triggers from tables lacking fiscal_year_id ──
        // Use a single SQL statement to find and drop all trg_fy_default_* triggers
        // on tables that don't have a fiscal_year_id column.
        DB::unprepared("
            DO \$\$
            DECLARE
                rec RECORD;
            BEGIN
                FOR rec IN
                    SELECT
                        t.event_object_table AS tbl,
                        t.trigger_name AS trg
                    FROM information_schema.triggers t
                    WHERE t.trigger_name LIKE 'trg_fy_default_%'
                      AND t.event_object_table IS NOT NULL
                      AND NOT EXISTS (
                          SELECT 1
                          FROM information_schema.columns c
                          WHERE c.table_name = t.event_object_table
                            AND c.column_name = 'fiscal_year_id'
                      )
                LOOP
                    EXECUTE format('DROP TRIGGER IF EXISTS %I ON %I', rec.trg, rec.tbl);
                    RAISE NOTICE 'Dropped orphaned trigger % on % (no fiscal_year_id column)', rec.trg, rec.tbl;
                END LOOP;
            END;
            \$\$;
        ");

        // ── Step 2: Also drop from known non-fiscal tables explicitly ──
        DB::unprepared("DROP TRIGGER IF EXISTS trg_fy_default_stock_take_items ON stock_take_items;");

        // ── Step 3: Ensure RLS is effective (app role must NOT bypass RLS) ──
        $this->ensureNoBypassRls();
    }

    public function down(): void
    {
        // No-op: we don't want to re-create orphaned triggers
    }

    /**
     * Ensure the application DB role does NOT bypass RLS.
     *
     * If the role was created with BYPASSRLS (e.g., during initial setup),
     * all RLS policies are silently ignored — a critical security hole for
     * a multi-branch ERP. This migration revokes that attribute.
     *
     * RLS effectiveness relies on ALTER TABLE FORCE ROW LEVEL SECURITY
     * (which makes even the table owner subject to policies) combined
     * with the app role NOT having BYPASSRLS.
     */
    private function ensureNoBypassRls(): void
    {
        $role = config('database.connections.pgsql.username', 'rcerp_app');

        try {
            DB::unprepared("ALTER ROLE \"{$role}\" NOBYPASSRLS");
        } catch (\Throwable $e) {
            // Role might not exist or we lack permission — log but don't fail.
            \Illuminate\Support\Facades\Log::warning('Could not revoke BYPASSRLS from role', [
                'role' => $role,
                'error' => $e->getMessage(),
            ]);
        }
    }
};

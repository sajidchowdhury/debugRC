<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WORKFLOWS-AUDIT-1 — Document approval_requests.entity_id polymorphic design
 * + add orphan-cleanup helper.
 *
 * Resolves:
 *   - G-180 (approval-workflow G6 MAJOR): approval_requests.entity_id is
 *     unsignedBigInteger, NOT FK. If a manual_journal is hard-deleted, its
 *     approval_requests row remains as an orphan. ApprovalRequest::getEntity()
 *     returns null (because ManualJournal::find() returns null), but the row
 *     stays in the table and the audit log shows it.
 *
 * Design decision: KEEP entity_id as a polymorphic unsignedBigInteger (no
 * single hard FK). Rationale:
 *
 *   The approval engine is GENERIC by design — entity_id references
 *   manual_journals.id, stock_adjustments.id, damage_invoices.id, or
 *   purchase_orders.id depending on entity_type. PostgreSQL doesn't support
 *   a single FK column pointing to multiple parent tables natively (no
 *   polymorphic FK constraint). The alternatives all have downsides:
 *
 *     (a) Per-entity_type child tables (approval_requests_manual_journal,
 *         approval_requests_stock_adjustment, etc.) — explodes the schema
 *         from 1 table to 5+, breaks the generic queue UI, breaks the
 *         ApprovalService polymorphic logic.
 *
 *     (b) Per-entity_type FK constraint with NOT VALID + VALIDATE later —
 *         Postgres supports adding a FK with NOT VALID (skips existing
 *         rows) then VALIDATE (checks existing rows). But you still need
 *         one FK per entity_type, and the entity_type column would need
 *         a CHECK + the FK would need a trigger to enforce the right FK
 *         based on entity_type. Complex + brittle.
 *
 *     (c) Application-layer enforcement (current approach) — the
 *         ApprovalService::submitForApproval validates the entity exists
 *         before creating the approval_requests row. Orphans can only
 *         arise from a hard-delete of the parent entity (which is rare —
 *         the entities use soft deletes).
 *
 *   This migration chooses (c) + adds TWO mitigations:
 *     1. A partial index per entity_type so the queue lookup is fast.
 *     2. An orphan-cleanup helper function (callable via artisan) that
 *        marks orphaned approval_requests rows as 'cancelled' (so they
 *        stop appearing in the pending queue).
 *
 * Idempotent: uses CREATE INDEX IF NOT EXISTS + CREATE OR REPLACE FUNCTION.
 */
return new class extends Migration
{
    /**
     * The known entity_type → table mapping. Mirrors the entity_type values
     * seeded in approval_workflows + the ApprovalService callers.
     */
    private const ENTITY_TABLE_MAP = [
        'manual_journal'    => 'manual_journals',
        'stock_adjustment'  => 'stock_adjustments',
        'damage_invoice'    => 'damage_invoices',
        'purchase_order'    => 'purchase_orders',
        'stock_take_session'=> 'stock_take_sessions',
    ];

    public function up(): void
    {
        // 1. Add partial indexes per entity_type — speeds up the queue lookup
        //    "SELECT * FROM approval_requests WHERE entity_type = 'manual_journal'
        //    AND status = 'pending'" which is the hot path in
        //    ApprovalService::getPendingQueueForUser.
        foreach (self::ENTITY_TABLE_MAP as $entityType => $table) {
            $indexName = 'idx_ar_' . $entityType . '_pending';
            DB::statement(
                "CREATE INDEX IF NOT EXISTS {$indexName} " .
                "ON approval_requests (entity_id, current_level) " .
                "WHERE entity_type = '{$entityType}' AND status = 'pending'"
            );
        }

        // 2. Create an orphan-cleanup helper function. Callable via:
        //      SELECT cleanup_orphan_approval_requests();
        //    Returns a count of orphaned rows marked as 'cancelled'.
        //    Idempotent — re-running is safe (only marks currently-orphaned
        //    rows; already-cancelled rows are skipped).
        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION cleanup_orphan_approval_requests()
            RETURNS integer
            LANGUAGE plpgsql
            AS $$
            DECLARE
                v_count integer := 0;
                v_entity_type text;
                v_entity_table text;
                v_entity_id bigint;
                v_orphan_ids bigint[];
            BEGIN
                -- Iterate over the known entity_type → table mapping.
                FOR v_entity_type, v_entity_table IN
                    VALUES
                        ('manual_journal',     'manual_journals'),
                        ('stock_adjustment',   'stock_adjustments'),
                        ('damage_invoice',      'damage_invoices'),
                        ('purchase_order',      'purchase_orders'),
                        ('stock_take_session',  'stock_take_sessions')
                LOOP
                    -- Find pending approval_requests whose entity_id no longer
                    -- exists in the parent table. Use a LEFT JOIN anti-pattern.
                    EXECUTE format(
                        'SELECT ARRAY_AGG(ar.id) FROM approval_requests ar
                         LEFT JOIN %I parent ON parent.id = ar.entity_id
                         WHERE ar.entity_type = %L
                           AND ar.status = ''pending''
                           AND parent.id IS NULL',
                        v_entity_table, v_entity_type
                    ) INTO v_orphan_ids;

                    IF v_orphan_ids IS NOT NULL THEN
                        -- Mark the orphans as 'cancelled' (not 'rejected' —
                        -- there's no rejecter; the parent just vanished).
                        UPDATE approval_requests
                        SET status = 'cancelled',
                            rejection_reason = 'Auto-cancelled: parent ' ||
                                v_entity_type || ' #' || entity_id ||
                                ' was hard-deleted (WORKFLOWS-AUDIT-1 G-180 cleanup)',
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ANY(v_orphan_ids);

                        GET DIAGNOSTICS v_count = ROW_COUNT;
                        v_count := v_count + COALESCE(v_count, 0);
                    END IF;
                END LOOP;

                RETURN v_count;
            END;
            $$;
        SQL);

        // 3. Run the cleanup once on migration (best-effort — catches any
        //    pre-existing orphans from before this migration).
        try {
            DB::selectOne('SELECT cleanup_orphan_approval_requests()');
        } catch (\Throwable $e) {
            // If the function fails (e.g. a parent table doesn't exist in
            // this deployment), log + continue. The function is available
            // for manual re-run later.
            echo "  ! Orphan cleanup skipped: {$e->getMessage()}\n";
        }
    }

    public function down(): void
    {
        // Drop the partial indexes.
        foreach (self::ENTITY_TABLE_MAP as $entityType => $table) {
            $indexName = 'idx_ar_' . $entityType . '_pending';
            DB::statement("DROP INDEX IF EXISTS {$indexName}");
        }

        // Drop the cleanup function.
        DB::statement('DROP FUNCTION IF EXISTS cleanup_orphan_approval_requests()');
    }
};

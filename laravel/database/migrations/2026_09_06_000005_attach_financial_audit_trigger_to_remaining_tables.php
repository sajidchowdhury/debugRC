<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AUDIT-TRAIL-1 (G-094) — Attach fn_financial_audit_trigger to the last 2
 * remaining monitored tables: system_policies + damage_attachments.
 *
 * Resolves G-094 (= architecture/realtime-events.md G6). The G6 row
 * originally listed 10 tables missing the trigger; 7 were attached by 3
 * prior migrations. This migration attaches the final 2 that are
 * technically compatible, leaving only `notifications` excluded for a
 * documented technical + domain reason (see the EXCLUSION note below).
 *
 * Tables covered (2):
 *   1. system_policies    — the Compliance & Security Policy Framework
 *      header (mode: NORMAL/INVESTIGATION/READ_ONLY/MAINTENANCE/EMERGENCY,
 *      is_active, activated_by/deactivated_by, reason, expires_at). A
 *      malicious DB admin could flip `is_active` or change `mode` to
 *      silently relax investigation/lockdown posture without leaving a
 *      hash-chained audit trail. Schema: migration
 *      2025_01_07_000001_create_system_policies_table.php. PK `id` is
 *      bigint (bigIncrements) — fits financial_audit_log.record_id BIGINT.
 *   2. damage_attachments — photographic / documentary evidence against a
 *      damage invoice. A malicious DB admin could DELETE attachment rows
 *      to erase proof of a fake write-off (insurance fraud vector) with no
 *      audit trail. Schema: migration 2026_01_03_000001_damage_attachments.
 *      PK `id` is integer GENERATED ALWAYS AS IDENTITY — fits BIGINT.
 *
 * EXCLUSION — `notifications` table (NOT attached by this migration)
 * ------------------------------------------------------------------
 * The `notifications` table (Laravel-standard polymorphic notification
 * queue, migration 2025_01_06_000001) has a **UUID** primary key
 * (`$table->uuid('id')->primary()`). The trigger function
 * `fn_financial_audit_trigger()` declares `_record_id BIGINT` and executes
 * `_record_id := NEW.id;` on INSERT/UPDATE/DELETE. PostgreSQL has NO
 * implicit cast from uuid to bigint, and the UUID text form (e.g.
 * "550e8400-e29b-41d4-a716-446655440000") is not a valid bigint literal.
 * Attaching the trigger to `notifications` would therefore raise
 * `ERROR: invalid input syntax for type bigint` on EVERY notification
 * INSERT — breaking Laravel's notification dispatch (high-frequency,
 * user-facing). This is a hard blocker, not a preference.
 *
 * Domain rationale for accepting the exclusion: `notifications` is a
 * TRANSIENT dispatch queue (read-once, routinely purged by
 * `notifications:prune`), NOT a crown-jewel financial table. The
 * tamper-evidence that matters for notification SECURITY is on
 * `notification_rules` + `notification_rule_recipients` (the CONFIG
 * tables that determine who gets notified for what event) — both already
 * audited by migration 2026_09_05_000010 (WORKFLOWS-AUDIT-1, G-181/G-187).
 *
 * Remediation path (deferred, out of scope for this 30-min task): if the
 * team later decides the transient notification queue itself needs
 * hash-chain auditing, either (a) widen `financial_audit_log.record_id`
 * from BIGINT to TEXT/varchar(100) on the large partitioned audit table
 * with 30+ integer-PK consumers (risky — requires a partition-aware
 * ALTER + backfill + index rebuild), or (b) add a separate UUID-aware
 * audit trigger function + a nullable `uuid_record_id` column. Either
 * should be a dedicated task with its own migration + test plan.
 *
 * Background
 * ----------
 * `fn_financial_audit_trigger()` is defined in `database/sql/02_accounting.sql`
 * (L396-458) and was hardened by migrations 2026_08_08_000005/000006/000007.
 * Each row in `financial_audit_log` carries a SHA-256 `row_hash` chained to
 * the previous row's `prev_hash`, producing a tamper-evident ledger. UPDATE
 * and DELETE on `financial_audit_log` are REVOKE'd at the DB level.
 *
 * Prior coverage migrations (for reference):
 *   - 02_accounting.sql L461-470: 10 finance tables.
 *   - 2026_09_01_000002 (SALES-AUDIT): 3 sales tables.
 *   - 2026_09_01_000003 (FINANCE-1): 14 finance-side tables.
 *   - 2026_09_03_000002 (PURCHASING-1): 6 purchase tables.
 *   - 2026_09_05_000010 (WORKFLOWS-AUDIT-1): 2 notification-config +
 *     4 approval-engine tables.
 *   - 2026_09_06_000002 (REPORTS-AUDIT-3): 6 inventory tables.
 *
 * Trigger function prerequisites
 * ------------------------------
 * The function reads `branch_id` from the row's JSONB representation
 * (`_after ->> 'branch_id'` / `_before ->> 'branch_id'` with COALESCE) so
 * it works for tables WITH or WITHOUT a `branch_id` column. Neither
 * `system_policies` (system-wide, no branch) nor `damage_attachments`
 * (branch implied via parent damage_invoices) has a `branch_id` column —
 * `_branch_id` will resolve to NULL, which is the correct posture for
 * system-scoped / branch-derived tables.
 *
 * Idempotency
 * -----------
 * Each attachment uses `DROP TRIGGER IF EXISTS` before `CREATE TRIGGER` so
 * the migration is safe to re-run. No existing `trg_audit_system_policies`
 * or `trg_audit_damage_attachments` triggers exist (verified via grep of
 * database/sql/ + database/migrations/), so this is a pure addition.
 *
 * Pattern: mirrors 2026_09_06_000002 (REPORTS-AUDIT-3) + 2026_09_05_000010
 * (WORKFLOWS-AUDIT-1).
 */
return new class extends Migration
{
    /**
     * The 2 remaining tables to attach the audit trigger to.
     * `notifications` is INTENTIONALLY EXCLUDED — see the class docstring
     * EXCLUSION note (UUID PK is incompatible with BIGINT record_id).
     */
    private const TABLES = [
        'system_policies',
        'damage_attachments',
    ];

    public function up(): void
    {
        // Defensive: verify the trigger function exists before attaching.
        // If 02_accounting.sql was not loaded (broken fresh install),
        // attaching would succeed but fire NULL at runtime.
        $fnExists = DB::table('pg_proc')
            ->join('pg_namespace', 'pg_proc.pronamespace', '=', 'pg_namespace.oid')
            ->where('pg_namespace.nspname', 'public')
            ->where('pg_proc.proname', 'fn_financial_audit_trigger')
            ->exists();

        if (! $fnExists) {
            throw new RuntimeException(
                'fn_financial_audit_trigger() function does not exist. '
                . 'Run database/sql/02_accounting.sql first, then re-run migrations.'
            );
        }

        foreach (self::TABLES as $table) {
            // Skip if the table does not exist (defensive — both tables are
            // created by earlier migrations, but a partial/half-migrated
            // environment should not hard-fail the whole migration).
            $tableExists = DB::table('information_schema.tables')
                ->where('table_name', $table)
                ->exists();

            if (! $tableExists) {
                echo "  ! Table {$table} does not exist — skipping trigger attachment.\n";
                continue;
            }

            $this->attachAuditTrigger($table);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $this->detachAuditTrigger($table);
        }
    }

    /**
     * Attach trg_audit_<table> AFTER INSERT OR UPDATE OR DELETE.
     *
     * DROP IF EXISTS first makes this idempotent — safe to re-run on tables
     * that already have the trigger (e.g. on a re-run after a failed
     * migration).
     */
    private function attachAuditTrigger(string $table): void
    {
        $trigger = 'trg_audit_' . $table;

        DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");

        DB::statement(
            "CREATE TRIGGER {$trigger} "
            . "AFTER INSERT OR UPDATE OR DELETE ON {$table} "
            . "FOR EACH ROW EXECUTE FUNCTION fn_financial_audit_trigger()"
        );
    }

    private function detachAuditTrigger(string $table): void
    {
        $trigger = 'trg_audit_' . $table;

        DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
    }
};

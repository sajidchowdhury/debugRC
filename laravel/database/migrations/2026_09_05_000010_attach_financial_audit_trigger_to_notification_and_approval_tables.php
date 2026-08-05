<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WORKFLOWS-AUDIT-1 (G-181 + G-187) — Attach fn_financial_audit_trigger to
 * the 2 notification config tables + the 4 approval engine tables.
 *
 * Resolves:
 *   - G-181 (notification-workflow G6 HIGH): NO fn_financial_audit_trigger
 *     on notification_rules + notification_rule_recipients. Rule config
 *     changes (who gets notified for what) are NOT tamper-evident. A
 *     malicious DB admin could UPDATE notification_rules SET is_active =
 *     false to silently suppress security-relevant notifications without
 *     leaving a hash-chained audit trail.
 *   - G-187 (approval-workflow G12 HIGH): No fn_financial_audit_trigger
 *     on approval tables. approval_workflows and approval_steps (which
 *     hold the approval POLICY) have NO immutable audit trail — an admin
 *     can silently change min_amount or is_active on a workflow and
 *     erase the evidence.
 *
 * Background: the trigger function fn_financial_audit_trigger() is defined
 * in database/sql/02_accounting.sql (lines 381-443) and hardened by 3
 * prior migrations. The trigger reads branch_id from the row's JSONB
 * representation (works for tables WITHOUT a branch_id column — confirmed
 * safe for all 6 target tables: none of them have branch_id).
 *
 * Tables covered (6):
 *   Notification config (2):
 *     1. notification_rules           (admin-managed; tamper-evidence for
 *        is_active / event / recipient_type changes)
 *     2. notification_rule_recipients (pivot; tamper-evidence for
 *        recipient_type changes)
 *
 *   Approval engine (4):
 *     3. approval_workflows           (POLICY: min_amount, is_active,
 *        requires_approval_levels — admin can silently change thresholds)
 *     4. approval_steps               (POLICY: role, level, is_parallel)
 *     5. approval_requests            (STATE: status, current_level,
 *        approved_by/rejected_by — already has application-level audit
 *        via approval_actions, but the trigger adds row-level tamper
 *        evidence for direct DB mutations)
 *     6. approval_actions             (audit log itself — redundant with
 *        financial_audit_log, but provides defense-in-depth: a malicious
 *        DB admin who DELETEs from approval_actions to erase evidence
 *        leaves a trail in financial_audit_log)
 *
 * Idempotency: each attachment uses DROP TRIGGER IF EXISTS before CREATE
 * TRIGGER so the migration is safe to re-run.
 *
 * Performance note: the trigger fires on every INSERT/UPDATE/DELETE and
 * does a SELECT row_hash FROM financial_audit_log ORDER BY id DESC LIMIT 1
 * for the hash chain. The 6 target tables have LOW write volume
 * (notification_rules + approval_workflows are admin-config tables;
 * approval_requests + approval_actions are write-on-approve which is
 * rare). The cost is negligible compared to the existing 10 audited
 * financial tables (journal_entries, customer_payments) which have much
 * higher write volume.
 */
return new class extends Migration
{
    /**
     * The 6 tables to which fn_financial_audit_trigger will be attached.
     */
    private const TABLES = [
        // Notification config (G-181)
        'notification_rules',
        'notification_rule_recipients',
        // Approval engine (G-187)
        'approval_workflows',
        'approval_steps',
        'approval_requests',
        'approval_actions',
    ];

    public function up(): void
    {
        // Verify the trigger function exists before attaching (defensive —
        // the function is created by 02_accounting.sql + hardened by 3
        // migrations, but a fresh `migrate` without the SQL bootstrap
        // would fail here).
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
            // Skip if the table doesn't exist (e.g. notification_rule_recipients
            // is created by a later migration in some deployment orderings).
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
     * Attach trg_audit_<table> to a table. Idempotent — drops any existing
     * trigger with the same name first.
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

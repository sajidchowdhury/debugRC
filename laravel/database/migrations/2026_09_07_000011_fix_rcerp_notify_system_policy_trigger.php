<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MEDIUM-WAVE-2-A (G-244) — Fix the dead `rcerp_notify_system_policy()` PG
 * trigger so the realtime broadcast for system-policy changes actually fires.
 *
 * Background
 * ----------
 * The original trigger was created by migration `2025_01_21_000001` (Task 31)
 * with this shape:
 *
 *     CREATE FUNCTION rcerp_notify_system_policy() RETURNS trigger AS $$
 *     BEGIN
 *         IF TG_OP = 'UPDATE' AND NEW.mode IS DISTINCT FROM OLD.mode THEN
 *             PERFORM rcerp_notify('rcerp_system', TG_TABLE_NAME, 'UPDATE',
 *                 NEW.id, NULL, jsonb_build_object(
 *                     'policy_id', NEW.id,
 *                     'old_mode',  OLD.mode,
 *                     'new_mode',  NEW.mode
 *                 ));
 *         END IF;
 *         RETURN NEW;
 *     END;
 *     $$ LANGUAGE plpgsql;
 *
 *     CREATE TRIGGER trg_notify_system_policies
 *         AFTER UPDATE ON system_policies
 *         FOR EACH ROW EXECUTE FUNCTION rcerp_notify_system_policy();
 *
 * This is dead in practice because `SystemPolicyService::activate()` and
 * `::deactivate()` NEVER update the `mode` column of an existing row:
 *
 *   - `activate($newMode)`:
 *       1. UPDATE previous policy SET is_active=false, deactivated_by,
 *          deactivated_at  — `mode` column is NOT touched.
 *       2. INSERT a new policy row with mode=$newMode, is_active=true.
 *
 *   - `deactivate($reason)`:
 *       1. UPDATE current policy SET is_active=false, deactivated_by,
 *          deactivated_at  — `mode` column is NOT touched.
 *
 * Neither step changes `mode` on an UPDATE, so the
 * `NEW.mode IS DISTINCT FROM OLD.mode` guard in the original trigger never
 * fires. The `rcerp_system` channel is registered in
 * `ListenNotifyService::PG_CHANNELS` and consumed by the SSE handler in
 * `public/assets/js/notification.js` (event listener `rcerp_system`), but
 * real policy activations produce NO notification. The realtime broadcast
 * for system-policy changes is dead code.
 *
 * Fix
 * ---
 * Replace the function with one that fires on the three operations the
 * service actually performs:
 *
 *   (1) INSERT of a new active policy (is_active=true). Captures
 *       `SystemPolicyService::activate()` step 2. `old_mode` is looked up
 *       from the just-deactivated prior policy (the row updated in step 1
 *       of the same transaction — found by `is_active=false AND
 *       deactivated_at IS NOT NULL ORDER BY deactivated_at DESC LIMIT 1`).
 *       If no prior policy exists (first-ever activation), `old_mode`
 *       defaults to `'NORMAL'`.
 *
 *   (2) UPDATE on `is_active` from true to false. Captures BOTH
 *       `SystemPolicyService::deactivate()` (no following INSERT — the
 *       policy returns to NORMAL) AND `activate()` step 1 (the prior policy
 *       is being deactivated; the following INSERT in step 2 will emit its
 *       own event under Case 1 with the real new_mode). `new_mode` for
 *       this case is `'NORMAL'` (no active policy after this UPDATE).
 *
 *   (3) UPDATE on `mode` change (original Case). The current service NEVER
 *       updates `mode` on an existing row, so this case is dead in practice
 *       — but it is retained for safety (a future DBA hot-fix or a new code
 *       path that does an in-place mode change will still emit a
 *       notification rather than silently dropping the broadcast).
 *
 * The trigger is recreated as `AFTER INSERT OR UPDATE` (the original was
 * `AFTER UPDATE` only — INSERT was not in the trigger's event list, which
 * is the second reason the original was dead even if the function had
 * handled INSERT).
 *
 * Consumers
 * ---------
 * The `rcerp_system` channel is already wired up end-to-end:
 *   - `app/Services/Notification/ListenNotifyService::PG_CHANNELS` includes
 *     `'rcerp_system'` (line 59).
 *   - `app/Console/Commands/ListenNotifyWorker` LISTENs on every channel
 *     in PG_CHANNELS and forwards payloads to Redis Pub/Sub + the SSE
 *     controller.
 *   - `public/assets/js/notification.js` (line 158) registers an
 *     `eventSource.addEventListener('rcerp_system', ...)` handler that
 *     calls `showBeautifulNotification('System Policy Changed', ...)`.
 *
 * Only the producer (this trigger) was broken. Fixing the trigger completes
 * the realtime broadcast chain.
 *
 * Payload contract (unchanged from original — keeps JS consumer working):
 *   {
 *     "table": "system_policies",
 *     "action": "INSERT" | "UPDATE" | "DEACTIVATE",
 *     "id": <policy id>,
 *     "branch_id": null,
 *     "changes": {
 *       "policy_id": <policy id>,
 *       "old_mode":  "NORMAL" | "INVESTIGATION" | ...,
 *       "new_mode":  "NORMAL" | "INVESTIGATION" | ...
 *     },
 *     "triggered_at": "<ISO timestamp>"
 *   }
 *
 * The JS consumer reads `data.changes.old_mode` and `data.changes.new_mode`
 * — both fields are present in all three cases above.
 *
 * Idempotency & defensive checks
 * ------------------------------
 * Mirrors the `2026_09_06_000005` (AUDIT-TRAIL-1) pattern:
 *   - Verifies the `rcerp_notify(text, text, text, integer, integer, jsonb)`
 *     helper function exists (created by `2025_01_21_000001`). If missing,
 *     throws RuntimeException — the trigger function would compile but fail
 *     at runtime on every fire (cannot `PERFORM rcerp_notify(...)` if the
 *     helper doesn't exist).
 *   - Verifies `system_policies` table exists. If missing (partial/half-
 *     migrated environment), prints a warning and skips — does not hard-
 *     fail the whole migration.
 *   - Uses `DROP FUNCTION IF EXISTS ... CASCADE` + `DROP TRIGGER IF EXISTS`
 *     before recreating, so the migration is safe to re-run.
 *
 * Reversibility
 * -------------
 * `down()` restores the ORIGINAL broken shape (function fires only on
 * UPDATE with `NEW.mode IS DISTINCT FROM OLD.mode`; trigger is
 * `AFTER UPDATE` only). This is the exact shape from `2025_01_21_000001`,
 * so rolling back this migration returns the system to the pre-fix state.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Defensive: verify the rcerp_notify() helper function exists before
        // creating a trigger function that depends on it. The helper is
        // created by migration 2025_01_21_000001 (Task 31). If 2025_01_21
        // was somehow rolled back or skipped, creating this trigger function
        // would succeed at CREATE time but raise
        // `function rcerp_notify(...) does not exist` at every fire.
        $helperExists = DB::table('pg_proc')
            ->join('pg_namespace', 'pg_proc.pronamespace', '=', 'pg_namespace.oid')
            ->where('pg_namespace.nspname', 'public')
            ->where('pg_proc.proname', 'rcerp_notify')
            ->exists();

        if (! $helperExists) {
            throw new RuntimeException(
                'rcerp_notify(text, text, text, integer, integer, jsonb) helper '
                . 'function does not exist. Run migration 2025_01_21_000001 '
                . '(add_listen_notify_triggers) first, then re-run.'
            );
        }

        // Defensive: verify the system_policies table exists. If a partial
        // / half-migrated environment lacks the table, skip rather than
        // hard-fail (mirrors 2026_09_06_000005 pattern).
        $tableExists = DB::table('information_schema.tables')
            ->where('table_name', 'system_policies')
            ->exists();

        if (! $tableExists) {
            echo "  ! Table system_policies does not exist — skipping rcerp_notify_system_policy trigger fix.\n";
            return;
        }

        // Drop the old function + trigger so the recreate is idempotent.
        // CASCADE on DROP FUNCTION also drops any triggers depending on it,
        // but we DROP TRIGGER explicitly first to be defensive in case the
        // function has been replaced (e.g. by a prior partial run of this
        // migration) and the trigger name has drifted.
        DB::statement('DROP TRIGGER IF EXISTS trg_notify_system_policies ON system_policies');
        // Defensive: also drop the singular-name variant in case any prior
        // partial run of this migration created it under that name. The
        // canonical name (per 2025_01_21_000001 convention `trg_notify_<table>`)
        // is the plural form `trg_notify_system_policies`.
        DB::statement('DROP TRIGGER IF EXISTS trg_notify_system_policy ON system_policies');
        DB::statement('DROP FUNCTION IF EXISTS rcerp_notify_system_policy() CASCADE');

        // Recreate the function with the 3-case logic described in the
        // class docstring. Each case emits a `rcerp_system` notification
        // with policy_id + old_mode + new_mode (the payload contract the JS
        // SSE consumer reads).
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION rcerp_notify_system_policy()
RETURNS trigger AS $$
DECLARE
    v_old_mode text;
    v_new_mode text;
    v_action   text;
BEGIN
    -- Case 1: INSERT of a new active policy.
    -- Captures SystemPolicyService::activate() step 2 (the new policy
    -- INSERT). The just-deactivated prior policy (step 1 UPDATE) is in the
    -- same transaction, so we look up its mode as old_mode. If no prior
    -- policy existed (first-ever activation), old_mode defaults to NORMAL.
    IF TG_OP = 'INSERT' AND NEW.is_active = true THEN
        SELECT mode INTO v_old_mode
        FROM system_policies
        WHERE is_active = false
          AND deactivated_at IS NOT NULL
          AND id <> NEW.id
        ORDER BY deactivated_at DESC
        LIMIT 1;

        v_old_mode := COALESCE(v_old_mode, 'NORMAL');
        v_new_mode := NEW.mode;
        v_action   := 'INSERT';

        PERFORM rcerp_notify('rcerp_system', TG_TABLE_NAME, v_action, NEW.id, NULL,
            jsonb_build_object(
                'policy_id', NEW.id,
                'old_mode',  v_old_mode,
                'new_mode',  v_new_mode
            )
        );
        RETURN NEW;
    END IF;

    -- Case 2: UPDATE on is_active from true to false.
    -- Captures BOTH SystemPolicyService::deactivate() (no following INSERT —
    -- the policy returns to NORMAL) AND activate() step 1 (the prior policy
    -- is being deactivated; the following INSERT in step 2 will emit its
    -- own event under Case 1 with the real new_mode). new_mode for this
    -- case is 'NORMAL' (no active policy after this UPDATE).
    IF TG_OP = 'UPDATE' AND OLD.is_active = true AND NEW.is_active = false THEN
        v_old_mode := OLD.mode;
        v_new_mode := 'NORMAL';
        v_action   := 'DEACTIVATE';

        PERFORM rcerp_notify('rcerp_system', TG_TABLE_NAME, v_action, NEW.id, NULL,
            jsonb_build_object(
                'policy_id', NEW.id,
                'old_mode',  v_old_mode,
                'new_mode',  v_new_mode
            )
        );
        RETURN NEW;
    END IF;

    -- Case 3 (defensive, original behaviour): UPDATE on mode change.
    -- The current SystemPolicyService NEVER updates `mode` on an existing
    -- row (it INSERTs a new row instead), so this case is dead in practice.
    -- It is retained for safety: a future DBA hot-fix or a new code path
    -- that does an in-place mode change will still emit a notification.
    IF TG_OP = 'UPDATE' AND NEW.mode IS DISTINCT FROM OLD.mode THEN
        v_old_mode := OLD.mode;
        v_new_mode := NEW.mode;
        v_action   := 'UPDATE';

        PERFORM rcerp_notify('rcerp_system', TG_TABLE_NAME, v_action, NEW.id, NULL,
            jsonb_build_object(
                'policy_id', NEW.id,
                'old_mode',  v_old_mode,
                'new_mode',  v_new_mode
            )
        );
        RETURN NEW;
    END IF;

    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql
SQL);

        // Recreate the trigger as AFTER INSERT OR UPDATE (the original was
        // AFTER UPDATE only — INSERT was missing, which is the second reason
        // the original was dead even if the function had handled INSERT).
        DB::statement(<<<'SQL'
CREATE TRIGGER trg_notify_system_policies
    AFTER INSERT OR UPDATE ON system_policies
    FOR EACH ROW EXECUTE FUNCTION rcerp_notify_system_policy()
SQL);
    }

    public function down(): void
    {
        // Drop the fixed function + trigger.
        DB::statement('DROP TRIGGER IF EXISTS trg_notify_system_policies ON system_policies');
        DB::statement('DROP TRIGGER IF EXISTS trg_notify_system_policy ON system_policies');
        DB::statement('DROP FUNCTION IF EXISTS rcerp_notify_system_policy() CASCADE');

        // Restore the ORIGINAL broken shape from 2025_01_21_000001 so the
        // system returns to the pre-fix state on rollback. This is the
        // exact function body + trigger definition from that migration
        // (dead-in-practice: fires only on UPDATE with NEW.mode IS DISTINCT
        // FROM OLD.mode, and the trigger is AFTER UPDATE only — does not
        // catch the INSERT path used by SystemPolicyService::activate()).
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION rcerp_notify_system_policy()
RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.mode IS DISTINCT FROM OLD.mode THEN
        PERFORM rcerp_notify('rcerp_system', TG_TABLE_NAME, 'UPDATE', NEW.id, NULL,
            jsonb_build_object(
                'policy_id', NEW.id,
                'old_mode',  OLD.mode,
                'new_mode',  NEW.mode
            )
        );
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER trg_notify_system_policies
    AFTER UPDATE ON system_policies
    FOR EACH ROW EXECUTE FUNCTION rcerp_notify_system_policy()
SQL);
    }
};

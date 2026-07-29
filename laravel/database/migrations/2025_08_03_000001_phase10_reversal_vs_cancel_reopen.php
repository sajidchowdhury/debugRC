<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10 (Stock Take plan) — Reversal vs cancellation distinction + re-open after reversal.
 *
 * Before Phase 10, the system conflated two semantically different operations
 * under a single `cancelSession` method + a single `cancelled` terminal state:
 *
 *   (a) "user abandoned a draft / counting session" — no stock moved, no GL
 *       posted, nothing to reverse. Just mark it dead.
 *   (b) "we rolled back a POSTED session" — stock movements + a GL journal
 *       entry were reversed. This is a materially different action with
 *       different audit implications, and it should be re-openable.
 *
 * Phase 10 splits these into two distinct terminal states:
 *   - `cancelled` — terminal, draft/counting only, NO reversal (case a).
 *   - `reversed`  — terminal-ish, posted only, full stock + GL reversal (b).
 *
 * A reversed session can be RE-OPENED (reversed → counting) with a mandatory
 * reason. Re-opening preserves the reversal rows (stock_transactions +
 * journal_entries) as audit history and resets stock_take_items.is_applied=
 * false so the counts can be corrected and the session re-posted. Re-posting
 * creates a NEW journal entry; the old reversed entry is linked on the
 * session via the new `reversal_of_entry_id` column so the full history is
 * traceable.
 *
 * Re-open count is capped by the `stock_take.max_reopens` policy (default 1)
 * to prevent endless re-open/re-post churn on a single session.
 *
 * Schema additions on stock_take_sessions:
 *   - re_open_count        integer NOT NULL DEFAULT 0
 *   - last_reopened_at     timestamp(0)
 *   - last_reopened_by     integer REFERENCES users(id) ON DELETE SET NULL
 *   - reversal_of_entry_id integer REFERENCES journal_entries(id) ON DELETE SET NULL
 *     (the journal_entry_id of the PRIOR post when this session is reversed;
 *      null on first post. Set by reverseSession; cleared on re-open so the
 *      next re-post starts fresh. The CURRENT post's journal_entry_id is
 *      always in the existing journal_entry_id column.)
 *
 * The status CHECK already allows 'reversed' (added forward-compatibly by the
 * Phase 4 migration), so this migration does NOT touch the status CHECK.
 *
 * The audit_log action CHECK already includes 'reverse' and 're_open' (added
 * by the Phase 7 migration), so this migration does NOT touch the action CHECK.
 *
 * Idempotency: every ALTER uses ADD COLUMN IF NOT EXISTS, every constraint
 * add is name-guarded via a DO block (exact conname match, NOT ILIKE on
 * pg_get_constraintdef — see Task 13 hotfix for why ILIKE fails), every
 * CREATE INDEX uses IF NOT EXISTS, and the policy seed uses updateOrInsert.
 * Re-running (or running against a partially-migrated DB) is safe.
 *
 * PostgreSQL prepared-statement safety: every DB::statement() in this file
 * contains exactly ONE SQL command. Multi-command strings are rejected by
 * PDO_PGSQL with SQLSTATE[42601]; see Task 14 hotfix for the prior instance.
 *
 * References:
 *   - docs/STOCK_TAKE_PHYSICAL_COUNT_IMPLEMENTATION_PLAN.md  §Phase 10
 *   - app/Services/Stock/StockTakeService.php  (cancelSession, reverseSession, reOpen, postSession)
 *   - app/Services/Stock/StockTakePolicyService.php  (maxReopens)
 *   - app/Models/StockTakeSession.php  (isReversed, isCancelled)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── (1) re_open_count ─────────────────────────────────────────────
        // How many times this session has been re-opened after a reversal.
        // Starts at 0; incremented by StockTakeService::reOpen. Capped by
        // the stock_take.max_reopens policy (default 1).
        DB::statement(
            'ALTER TABLE stock_take_sessions '
            . 'ADD COLUMN IF NOT EXISTS re_open_count integer NOT NULL DEFAULT 0'
        );

        // ── (2) last_reopened_at ──────────────────────────────────────────
        // Timestamp of the most recent re-open. Null until the first re-open.
        DB::statement(
            'ALTER TABLE stock_take_sessions '
            . 'ADD COLUMN IF NOT EXISTS last_reopened_at timestamp(0)'
        );

        // ── (3) last_reopened_by ──────────────────────────────────────────
        // User who performed the most recent re-open. ON DELETE SET NULL so
        // deleting the user does not orphan the session row.
        DB::statement(
            'ALTER TABLE stock_take_sessions '
            . 'ADD COLUMN IF NOT EXISTS last_reopened_by integer'
        );

        // Name-guarded FK for last_reopened_by → users(id). DO block so re-runs
        // are a no-op (single SQL command — DO $$ ... $$; is ONE statement).
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'sts_last_reopened_by_fk'
                      AND conrelid = 'stock_take_sessions'::regclass
                ) THEN
                    ALTER TABLE stock_take_sessions
                    ADD CONSTRAINT sts_last_reopened_by_fk
                    FOREIGN KEY (last_reopened_by) REFERENCES users(id) ON DELETE SET NULL;
                END IF;
            END $$;
        SQL);

        // ── (4) reversal_of_entry_id ──────────────────────────────────────
        // The journal_entry_id of the PRIOR post, captured when the session
        // is reversed. Lets the UI/audit show "this session was post #5,
        // reversed, re-opened, re-posted as #7" — the link from #7 back to
        // #5 lives here. Null on first post (never reversed). Cleared on
        // re-open so the next re-post starts a fresh chain.
        //
        // The CURRENT post's journal_entry_id is always in the existing
        // journal_entry_id column (set by postSession, cleared to the prior
        // value's link by reverseSession). This column is the AUDIT LINK,
        // not the live pointer.
        DB::statement(
            'ALTER TABLE stock_take_sessions '
            . 'ADD COLUMN IF NOT EXISTS reversal_of_entry_id integer'
        );

        // Name-guarded FK for reversal_of_entry_id → journal_entries(id).
        // ON DELETE SET NULL: deleting the journal entry (rare, admin-only)
        // should not cascade-delete the session's audit link.
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint
                    WHERE conname = 'sts_reversal_of_entry_id_fk'
                      AND conrelid = 'stock_take_sessions'::regclass
                ) THEN
                    ALTER TABLE stock_take_sessions
                    ADD CONSTRAINT sts_reversal_of_entry_id_fk
                    FOREIGN KEY (reversal_of_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL;
                END IF;
            END $$;
        SQL);

        // ── (5) Index for the "reversed sessions" worklist ────────────────
        // Partial index on reversed sessions for the admin "reversed sessions"
        // worklist + the re-open-eligible list. Mirrors the existing
        // idx_sts_is_reversed (which indexes the is_reversed boolean) but
        // scopes by the Phase 10 status='reversed' state specifically.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_sts_reversed '
            . 'ON stock_take_sessions(branch_id, reversed_at) '
            . 'WHERE status = \'reversed\''
        );

        // ── (6) Seed the max_reopens policy ───────────────────────────────
        // Reuses the Phase 4 stock_take_policies table. updateOrInsert makes
        // this idempotent — re-runs update the row in place rather than
        // inserting a duplicate (the table has a UNIQUE on `key`).
        DB::table('stock_take_policies')->updateOrInsert(
            ['key' => 'stock_take.max_reopens'],
            [
                'value'       => json_encode(1),
                'description' => 'Phase 10: maximum number of times a reversed stock-take session can be re-opened for correction + re-posting. Default 1 (one re-open per session — prevents endless re-open/re-post churn). Set to 0 to forbid re-opening entirely (reversed = hard terminal). Set to a higher number for environments that allow iterative correction. The cap is enforced by StockTakeService::reOpen, which throws when re_open_count >= max_reopens.',
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );
    }

    public function down(): void
    {
        // Drop the reversed-sessions index.
        DB::statement('DROP INDEX IF EXISTS idx_sts_reversed');

        // Drop the two FK constraints + four columns. All idempotent.
        DB::statement('ALTER TABLE stock_take_sessions DROP CONSTRAINT IF EXISTS sts_reversal_of_entry_id_fk');
        DB::statement('ALTER TABLE stock_take_sessions DROP CONSTRAINT IF EXISTS sts_last_reopened_by_fk');
        DB::statement('ALTER TABLE stock_take_sessions DROP COLUMN IF EXISTS reversal_of_entry_id');
        DB::statement('ALTER TABLE stock_take_sessions DROP COLUMN IF EXISTS last_reopened_by');
        DB::statement('ALTER TABLE stock_take_sessions DROP COLUMN IF EXISTS last_reopened_at');
        DB::statement('ALTER TABLE stock_take_sessions DROP COLUMN IF EXISTS re_open_count');

        // Remove the max_reopens policy seed.
        DB::table('stock_take_policies')
            ->where('key', 'stock_take.max_reopens')
            ->delete();
    }
};

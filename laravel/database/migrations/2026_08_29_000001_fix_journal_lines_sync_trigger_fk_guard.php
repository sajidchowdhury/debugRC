<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10.1 — HOTFIX-9: Fix trg_jl_sync_entry_date partition-move crash.
 *
 * BUG DISCOVERED BY verify_partitioning.sql Section O:
 *   The trigger-based FK guard test (insert a journal_lines row with a
 *   non-existent journal_entry_id = 99999999) failed with the WRONG error:
 *
 *     ERROR: 0A000: moving row to another partition during a BEFORE FOR EACH
 *            ROW trigger is not supported
 *     DETAIL: Before executing trigger "trg_jl_sync_entry_date", the row was
 *             to be in partition "public.journal_lines_2026_06".
 *
 * ROOT CAUSE:
 *   Two BEFORE INSERT triggers fire on journal_lines, in alphabetical order:
 *
 *     1. trg_jl_sync_entry_date                      (fires FIRST — 'jl' < 'jo')
 *        Function fn_jl_sync_entry_date() UNCONDITIONALLY overwrites:
 *            NEW.entry_date := (SELECT entry_date FROM journal_entries
 *                               WHERE id = NEW.journal_entry_id);
 *        When journal_entry_id = 99999999 (doesn't exist), the subquery
 *        returns NULL → NEW.entry_date becomes NULL. NULL routes to the
 *        _default partition, but the original INSERT target was
 *        journal_lines_2026_06 (based on the caller-provided entry_date).
 *        PostgreSQL forbids moving a row between partitions inside a BEFORE
 *        FOR EACH ROW trigger → ERROR 0A000.
 *
 *     2. trg_journal_lines_journal_entry_id_je_fk     (NEVER fires — trigger
 *        1 already crashed) — this is the actual FK guard created by
 *        migration 2026_08_22_000004. It would have raised a clean
 *        'FK violation: journal_entries(id=99999999) not found' exception,
 *        but it never gets the chance.
 *
 *   So the FK guard EXISTS but is shadowed by the sync trigger's crash.
 *
 * PRODUCTION IMPACT:
 *   LOW in the normal application path — JournalPostingService always inserts
 *   journal_lines with a valid journal_entry_id (created in the same
 *   transaction), so the sync subquery finds the parent and entry_date is
 *   set correctly. No partition move, no error.
 *
 *   MODERATE for direct SQL / ETL / artisan tinker paths — any INSERT with
 *   a stale or wrong journal_entry_id gets a confusing partition-move error
 *   instead of a clear FK violation. Debugging is harder.
 *
 *   There is also a LATENT production risk: if the caller provides an
 *   entry_date that differs from the parent journal_entry's entry_date, the
 *   sync trigger overwrites it, which can also trigger the partition-move
 *   error. The application sets entry_date correctly today, but this is
 *   fragile.
 *
 * FIX:
 *   Make fn_jl_sync_entry_date() SELF-DEFENSIVE:
 *     1. SELECT entry_date INTO a local variable.
 *     2. If NOT FOUND (parent doesn't exist), RAISE EXCEPTION with a clear
 *        FK-violation message — same message the FK guard would have raised.
 *        This fails fast with a meaningful error BEFORE entry_date is
 *        touched, so no partition-move can occur.
 *     3. Otherwise, set NEW.entry_date := v_parent_date.
 *
 *   Result:
 *     - Section O test now produces a clean 'FK violation' ERROR (the guard
 *       works as intended).
 *     - The separate trg_journal_lines_journal_entry_id_je_fk trigger
 *       becomes belt-and-suspenders (still useful for UPDATE OF
 *       journal_entry_id on existing rows, where the sync trigger also
 *       fires and now also guards).
 *     - No change to the happy path (valid journal_entry_id → entry_date
 *       synced from parent, same as before).
 *
 * IDEMPOTENT — uses CREATE OR REPLACE FUNCTION. The trigger itself
 * (trg_jl_sync_entry_date) does not need to be recreated because it
 * already calls fn_jl_sync_entry_date() by name; replacing the function
 * body is sufficient.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Replace the sync function with a self-defensive version.
        //
        // Changes vs. the original (migration 2026_08_22_000001):
        //   - Uses SELECT ... INTO v_parent_date + IF NOT FOUND check
        //     instead of a scalar subquery that silently returns NULL.
        //   - Raises a clear FK-violation exception when the parent
        //     journal_entry doesn't exist, BEFORE touching NEW.entry_date.
        //     This prevents the "moving row to another partition" error
        //     that occurred when entry_date was set to NULL.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_jl_sync_entry_date()
            RETURNS TRIGGER AS $$
            DECLARE
                v_parent_date DATE;
            BEGIN
                -- Look up the parent journal_entry's entry_date.
                -- If the parent doesn't exist, NOT FOUND is set (PL/pgSQL
                -- behavior after a SELECT INTO with no rows).
                SELECT entry_date
                  INTO v_parent_date
                  FROM journal_entries
                 WHERE id = NEW.journal_entry_id;

                IF NOT FOUND THEN
                    -- Fail fast with a clear FK-violation message BEFORE
                    -- modifying NEW.entry_date. This prevents the
                    -- partition-move error that occurred when entry_date
                    -- was silently set to NULL.
                    RAISE EXCEPTION
                        'FK violation: journal_entries(id=%) not found for journal_lines.journal_entry_id',
                        NEW.journal_entry_id
                        USING ERRCODE = 'foreign_key_violation';
                END IF;

                -- Parent exists — sync entry_date to the parent's value.
                -- This keeps journal_lines.entry_date denormalized but
                -- consistent with journal_entries.entry_date, which is
                -- required for partition-wise joins (Phase 6.4 design).
                NEW.entry_date := v_parent_date;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        Log::info(
            'HOTFIX-9: Replaced fn_jl_sync_entry_date() with a self-defensive '
            . 'version that raises a clear FK-violation exception when the '
            . 'parent journal_entry does not exist, instead of silently '
            . 'setting entry_date to NULL (which caused a partition-move '
            . 'error that shadowed the FK guard trigger).'
        );
    }

    public function down(): void
    {
        // Restore the original (buggy) function body from migration
        // 2026_08_22_000001. This is provided for completeness only —
        // rolling back is NOT recommended because it reintroduces the
        // partition-move crash.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_jl_sync_entry_date()
            RETURNS TRIGGER AS $$
            BEGIN
                NEW.entry_date := (
                    SELECT entry_date FROM journal_entries WHERE id = NEW.journal_entry_id
                );
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        Log::warning(
            'HOTFIX-9 rollback: restored the original fn_jl_sync_entry_date() '
            . 'function body. The partition-move crash on non-existent '
            . 'journal_entry_id will recur. Re-apply HOTFIX-9 to fix.'
        );
    }
};

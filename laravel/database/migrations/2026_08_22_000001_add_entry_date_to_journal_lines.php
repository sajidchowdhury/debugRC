<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10.1 — Phase 6.2: Add denormalized entry_date to journal_lines.
 *
 * This is the SINGLE MOST IMPORTANT design decision in the entire partitioning
 * plan (roadmap §5.2 / plan §6.2). It MUST be done BEFORE partitioning
 * journal_entries (Phase 6.3) and journal_lines (Phase 6.4) — without a
 * denormalized entry_date on journal_lines, partition-wise joins between
 * journal_entries and journal_lines are impossible.
 *
 * Steps:
 *   1. ALTER TABLE journal_lines ADD COLUMN entry_date date NOT NULL DEFAULT CURRENT_DATE;
 *   2. Backfill in 100k-row chunks (loop until rowcount = 0):
 *        UPDATE journal_lines jl
 *        SET entry_date = je.entry_date
 *        FROM journal_entries je
 *        WHERE jl.journal_entry_id = je.id
 *          AND jl.entry_date = CURRENT_DATE
 *          AND je.entry_date <> CURRENT_DATE;
 *   3. ALTER TABLE journal_lines ALTER COLUMN entry_date DROP DEFAULT;
 *   4. Add a sync trigger that keeps journal_lines.entry_date aligned with
 *      journal_entries.entry_date (mitigates plan risk R17 — any direct
 *      INSERT that forgets to set entry_date will be auto-populated).
 *   5. Create a temporary B-tree index on journal_lines(entry_date).
 *      This will be dropped in Phase 6.5 / migration 000004 and replaced
 *      by a BRIN index (pages_per_range=32) which is more space-efficient
 *      for the monotonically-increasing entry_date column.
 *
 * IDEMPOTENT — every statement uses IF NOT EXISTS / CREATE OR REPLACE.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. Add entry_date column with a temporary DEFAULT CURRENT_DATE
        //    so existing rows get a non-null value (the backfill below
        //    overwrites it with the true value from journal_entries).
        //    NOT NULL is enforced from the start — the DEFAULT prevents
        //    the ADD COLUMN from failing on a populated table.
        // ============================================================
        DB::statement(<<<'SQL'
            ALTER TABLE journal_lines
                ADD COLUMN IF NOT EXISTS entry_date DATE NOT NULL DEFAULT CURRENT_DATE
        SQL);

        // ============================================================
        // 2. Backfill entry_date from journal_entries in 100k chunks.
        //    We loop until no rows are updated. Each iteration is a
        //    separate statement (auto-committed by Laravel), avoiding
        //    one giant long-running transaction.
        //
        //    The WHERE clause restricts the UPDATE to rows whose
        //    entry_date is still the placeholder CURRENT_DATE (i.e.,
        //    not yet backfilled) AND whose parent's entry_date differs
        //    from CURRENT_DATE. We use a subquery LIMIT to chunk the
        //    work — this is a common PostgreSQL pattern for batched
        //    updates without a long lock.
        // ============================================================
        $maxIterations = 1000; // safety valve — 1000 * 100k = 100M rows max
        $iteration = 0;
        while ($iteration < $maxIterations) {
            $iteration++;
            $affected = DB::affectingStatement(<<<'SQL'
                UPDATE journal_lines AS jl
                SET entry_date = je.entry_date
                FROM journal_entries AS je
                WHERE jl.journal_entry_id = je.id
                  AND jl.entry_date = CURRENT_DATE
                  AND je.entry_date <> CURRENT_DATE
                  AND jl.id IN (
                      SELECT jl2.id
                      FROM journal_lines jl2
                      JOIN journal_entries je2 ON je2.id = jl2.journal_entry_id
                      WHERE jl2.entry_date = CURRENT_DATE
                        AND je2.entry_date <> CURRENT_DATE
                      LIMIT 100000
                  )
            SQL);

            Log::info("Phase 6.2: backfill iteration {$iteration} updated {$affected} journal_lines rows");

            if ($affected === 0) {
                break;
            }
        }

        // Safety net: any rows that didn't get backfilled (orphaned
        // journal_lines whose journal_entry_id no longer exists) keep
        // their entry_date = CURRENT_DATE. With entry_date being NOT NULL,
        // we can't leave them NULL. The CURRENT_DATE placeholder is
        // acceptable because:
        //   1. Orphans should not exist (journal_lines has ON DELETE
        //      CASCADE from journal_entries).
        //   2. Even if a few orphans exist, the partition-wise join
        //      behavior on the _default partition still works — they
        //      simply won't match a partition boundary, which is fine.
        //
        // No further action needed.

        // ============================================================
        // 3. Drop the temporary DEFAULT. Going forward, entry_date must
        //    be set explicitly by the application (or by the sync trigger
        //    below). The DEFAULT was only needed for the initial
        //    backfill of pre-existing rows.
        // ============================================================
        DB::statement(<<<'SQL'
            ALTER TABLE journal_lines ALTER COLUMN entry_date DROP DEFAULT
        SQL);

        // ============================================================
        // 4. Sync trigger: keep journal_lines.entry_date aligned with
        //    journal_entries.entry_date. This is a SAFETY NET — the
        //    application code in JournalPostingService::createJournalEntry()
        //    will be updated in Phase 6.7 to set entry_date explicitly
        //    so the trigger does not fire on the hot path. The trigger
        //    catches any direct INSERTs that bypass the service (ETL
        //    scripts, artisan commands, manual SQL).
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_jl_sync_entry_date()
            RETURNS TRIGGER AS $$
            BEGIN
                -- Only sync when journal_entry_id is being set (INSERT) or
                -- changed (UPDATE OF journal_entry_id). This avoids firing
                -- on every UPDATE that touches other columns.
                NEW.entry_date := (
                    SELECT entry_date FROM journal_entries WHERE id = NEW.journal_entry_id
                );
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_jl_sync_entry_date ON journal_lines
        SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_jl_sync_entry_date
                BEFORE INSERT OR UPDATE OF journal_entry_id ON journal_lines
                FOR EACH ROW EXECUTE FUNCTION fn_jl_sync_entry_date()
        SQL);

        // ============================================================
        // 5. Temporary B-tree index on journal_lines(entry_date).
        //    This makes the partition copy in Phase 6.4 faster (the
        //    ORDER BY entry_date, id will use this index). The index
        //    is dropped and replaced by a BRIN index in Phase 6.5
        //    (migration 000004) — BRIN is more space-efficient for
        //    monotonically-increasing date columns.
        // ============================================================
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_jl_entry_date
                ON journal_lines (entry_date)
        SQL);
    }

    public function down(): void
    {
        // Drop the trigger + function + index, then drop the column.
        DB::statement('DROP TRIGGER IF EXISTS trg_jl_sync_entry_date ON journal_lines');
        DB::statement('DROP FUNCTION IF EXISTS fn_jl_sync_entry_date() CASCADE');
        DB::statement('DROP INDEX IF EXISTS idx_jl_entry_date');
        DB::statement('ALTER TABLE journal_lines DROP COLUMN IF EXISTS entry_date');
    }
};

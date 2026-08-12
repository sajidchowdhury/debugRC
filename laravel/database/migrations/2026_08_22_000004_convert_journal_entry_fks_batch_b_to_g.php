<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10.1 — Phase 6.5 + 6.6: BRIN indexes + convert remaining
 * declarative FKs to journal_entries into trigger-based FKs (Batches B-G).
 *
 * This migration runs AFTER migration 000002 (partition journal_entries)
 * and migration 000003 (partition journal_lines). At this point:
 *
 *   - journal_entries is partitioned by entry_date (PK = (id, entry_date)).
 *   - journal_lines is partitioned by entry_date (PK = (id, entry_date)).
 *   - All declarative FKs to journal_entries(id) were DROPPED by migration
 *     000002 (necessary for DROP TABLE journal_entries_unpartitioned to
 *     succeed). They are re-created as trigger-based FKs here.
 *   - All declarative FKs to journal_lines(id) were converted to
 *     trigger-based by migration 000003 (in the same migration that
 *     partitioned journal_lines). They are NOT re-done here.
 *
 * This migration does TWO things:
 *
 *   A. Add BRIN indexes (Phase 6.5):
 *      - idx_je_entry_date_brin            (entry_date, pages_per_range=32)
 *      - idx_je_created_at_brin            (created_at, pages_per_range=32)
 *      - idx_je_active_entry_date_brin     (entry_date WHERE is_reversed=false)
 *      - idx_jl_entry_date_brin            (entry_date, pages_per_range=32)
 *      Drop the temporary B-tree idx_jl_entry_date (created in migration
 *      000001) since BRIN replaces it. Drop the old B-tree idx_je_entry_date
 *      if it still exists (should not, but DROP IF EXISTS is safe).
 *
 *   B. Convert declarative FKs to trigger-based (Phase 6.6):
 *      15 child tables, 17 FK columns total (customer_payments and
 *      supplier_payments each have 2). Schema audit found additional
 *      FK columns the original plan didn't enumerate (documented in
 *      $fkMap below), so the actual count is higher — see comments.
 *
 * Pattern: same as Phase 0.3 migration 2026_08_15_000003:
 *   1. Look up the FK constraint name + confdeltype from pg_constraint.
 *   2. Drop the declarative FK (if it exists — may already be gone).
 *   3. Create a trigger function that checks parent existence.
 *   4. Create a BEFORE INSERT OR UPDATE trigger on the child table.
 *   5. If CASCADE or SET NULL, create the corresponding AFTER DELETE
 *      trigger on journal_entries (the parent).
 *   6. If NO ACTION / RESTRICT, create a constraint trigger on
 *      journal_entries that RAISES EXCEPTION if child rows exist.
 *
 * IDEMPOTENT — every operation is safe to re-run. Uses CREATE OR REPLACE
 * FUNCTION, DROP TRIGGER IF EXISTS, DROP CONSTRAINT IF EXISTS, CREATE
 * INDEX IF NOT EXISTS.
 */
return new class extends Migration
{
    /**
     * Map of child_table => [fk_column, on_delete_behavior].
     *
     * The on_delete_behavior is the BEHAVIOR WE WANT to enforce via
     * triggers. The migration also queries pg_constraint at runtime
     * to detect the actual confdeltype of any existing declarative FK
     * (for logging purposes). If a declarative FK exists with a
     * different confdeltype, we honor the value in this map (the
     * declarative FK is dropped and replaced with the trigger-based
     * FK configured per this map).
     *
     * ON DELETE behaviors:
     *   - 'CASCADE'    : delete child rows when parent is deleted.
     *   - 'SET_NULL'   : nullify child FK when parent is deleted.
     *   - 'NO_ACTION'  : prevent parent delete if child rows exist (default).
     *   - 'RESTRICT'   : same as NO_ACTION but cannot be deferred.
     *
     * SCHEMA AUDIT FINDINGS (deviations from the original task spec):
     *
     *   1. bank_reconciliations: the actual FK column is
     *      `adjustment_journal_entry_id` (NOT `journal_entry_id` as
     *      the task spec says). ON DELETE SET NULL. The task spec
     *      was wrong on both the column name and the ON DELETE behavior.
     *      [migration 2026_08_12_000001 lines 81-83]
     *
     *   2. bank_reconciliation_items: has `journal_entry_id` FK to
     *      journal_entries(id) ON DELETE SET NULL. Not in the task
     *      spec's table but exists in the schema. Added here.
     *      [migration 2026_08_12_000001 lines 145-147]
     *
     *   3. sales_challans: has TWO FK columns to journal_entries —
     *      `journal_entry_id` AND `adjustment_journal_entry_id`. The
     *      task spec only listed `journal_entry_id`. Both converted.
     *      [04_sales.sql lines 141-142]
     *
     *   4. warehouse_transfers: has TWO FK columns — `journal_entry_id`
     *      AND `journal_entry_id_debtor`. Task spec only listed
     *      `journal_entry_id`. Both converted.
     *      [03_stock.sql lines 649-650]
     *
     *   5. stock_take_sessions: has TWO FK columns — `journal_entry_id`
     *      AND `reversal_of_entry_id` (ON DELETE SET NULL). Task spec
     *      only listed `journal_entry_id`. Both converted.
     *      [03_stock.sql lines 222, 269]
     *
     *   6. branch_demands: has TWO FK columns — `journal_entry_id`
     *      AND `journal_entry_id_debtor`. Task spec only listed
     *      `journal_entry_id`. Both converted.
     *      [03_stock.sql line 722, migration 2026_07_29_000010 line 44-45]
     *
     *   7. branch_demand_money_transfer_settlements: does NOT have a
     *      `journal_entry_id` column at all! Task spec listed it but
     *      it doesn't exist. Removed from $fkMap.
     *      [migration 2026_07_29_000014 — table has only transfer_id, demand_id]
     *
     *   8. asset_depreciation_schedules, asset_disposals, elimination_entries:
     *      task spec said NO_ACTION but the actual FK is ON DELETE SET NULL.
     *      Corrected to SET_NULL.
     *      [migration 2026_08_13_000001 lines 134-135, 186-187;
     *       migration 2026_08_11_000001 lines 154-155]
     *
     *   9. journal_posting_logs: was supposed to be converted by Phase 0.3
     *      (it's in the FK_MAP of migration 2026_08_15_000003). It MAY
     *      already be trigger-based. We handle this idempotently — if
     *      no declarative FK is found, log and skip (the trigger-based
     *      FK from Phase 0.3 is still in place).
     *
     *  10. journal_lines: the declarative FK was DROPPED by migration
     *      000002 (necessary to partition journal_entries). We re-create
     *      it as a trigger-based FK here, with CASCADE on parent delete.
     *
     * Net FK conversions in this migration: 21 FK columns across 15 tables
     * (original task spec said 17 FK columns across 15 tables — we found
     * 5 additional columns the spec missed: sales_challans.adjustment_journal_entry_id,
     * warehouse_transfers.journal_entry_id_debtor, stock_take_sessions.reversal_of_entry_id,
     * branch_demands.journal_entry_id_debtor, bank_reconciliation_items.journal_entry_id;
     * and we removed branch_demand_money_transfer_settlements which does NOT
     * have a journal_entry_id column at all. Net: +5 columns, +1 table
     * (bank_reconciliation_items) −1 table (branch_demand_money_transfer_settlements)
     * = same 15 tables, 21 columns).
     */
    private const FK_MAP = [
        // Batch B — partitioned children of journal_entries
        // journal_lines: declarative FK was DROPPED by migration 000002.
        // Re-create as trigger-based with CASCADE (matches original ON DELETE CASCADE).
        'journal_lines' => [
            ['column' => 'journal_entry_id', 'on_delete' => 'CASCADE'],
        ],

        // journal_posting_logs: may already be trigger-based from Phase 0.3.
        // Idempotent — if declarative FK is gone, log and skip.
        'journal_posting_logs' => [
            ['column' => 'journal_entry_id', 'on_delete' => 'CASCADE'],
        ],

        // Batch C — payment tables (Phase 5 partitioned them)
        'customer_payments' => [
            ['column' => 'journal_entry_id',              'on_delete' => 'NO_ACTION'],
            ['column' => 'intercompany_journal_entry_id', 'on_delete' => 'NO_ACTION'],
        ],
        'supplier_payments' => [
            ['column' => 'journal_entry_id',              'on_delete' => 'NO_ACTION'],
            ['column' => 'intercompany_journal_entry_id', 'on_delete' => 'NO_ACTION'],
        ],

        // Batch D — sales/challan tables (Phase 5 partitioned sales_challans)
        'sales_challans' => [
            ['column' => 'journal_entry_id',              'on_delete' => 'NO_ACTION'],
            ['column' => 'adjustment_journal_entry_id',   'on_delete' => 'NO_ACTION'],
        ],

        // Batch E — stock tables (Phase 5 partitioned them)
        'stock_adjustments' => [
            ['column' => 'journal_entry_id', 'on_delete' => 'NO_ACTION'],
        ],
        'stock_take_sessions' => [
            ['column' => 'journal_entry_id',      'on_delete' => 'NO_ACTION'],
            ['column' => 'reversal_of_entry_id',  'on_delete' => 'SET_NULL'],
        ],
        'warehouse_transfers' => [
            ['column' => 'journal_entry_id',         'on_delete' => 'NO_ACTION'],
            ['column' => 'journal_entry_id_debtor',  'on_delete' => 'NO_ACTION'],
        ],

        // Batch E (cont.) — branch_demands NOT yet partitioned, convert FK anyway
        'branch_demands' => [
            ['column' => 'journal_entry_id',         'on_delete' => 'NO_ACTION'],
            ['column' => 'journal_entry_id_debtor',  'on_delete' => 'NO_ACTION'],
        ],

        // Batch G — financial-period tables
        'bank_reconciliations' => [
            // Schema audit: actual column is adjustment_journal_entry_id (NOT journal_entry_id).
            ['column' => 'adjustment_journal_entry_id', 'on_delete' => 'SET_NULL'],
        ],
        'bank_reconciliation_items' => [
            // Schema audit: this table also has a journal_entry_id FK (not in task spec).
            ['column' => 'journal_entry_id', 'on_delete' => 'SET_NULL'],
        ],
        'asset_depreciation_schedules' => [
            // Schema audit: actual ON DELETE is SET NULL (not NO_ACTION as task spec said).
            ['column' => 'journal_entry_id', 'on_delete' => 'SET_NULL'],
        ],
        'asset_disposals' => [
            // Schema audit: actual ON DELETE is SET NULL.
            ['column' => 'journal_entry_id', 'on_delete' => 'SET_NULL'],
        ],
        'elimination_entries' => [
            // Schema audit: actual ON DELETE is SET NULL.
            ['column' => 'journal_entry_id', 'on_delete' => 'SET_NULL'],
        ],
        'branch_demand_repricing' => [
            ['column' => 'journal_entry_id', 'on_delete' => 'NO_ACTION'],
        ],

        // NOTE: branch_demand_money_transfer_settlements was listed in the
        // task spec but does NOT have a journal_entry_id column. Removed.
    ];

    public function up(): void
    {
        // ============================================================
        // A. BRIN indexes (Phase 6.5)
        // ============================================================
        $this->addBrinIndexes();

        // ============================================================
        // B. Convert declarative FKs to trigger-based (Phase 6.6)
        // ============================================================
        // Re-create the shared helper from Phase 0.3 (idempotent).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_check_journal_entry_exists(p_je_id BIGINT)
            RETURNS BOOLEAN AS $$
            BEGIN
                IF p_je_id IS NULL THEN
                    RETURN TRUE;
                END IF;
                RETURN EXISTS (
                    SELECT 1 FROM journal_entries WHERE id = p_je_id
                );
            END;
            $$ LANGUAGE plpgsql
        SQL);

        foreach (self::FK_MAP as $childTable => $fkColumns) {
            foreach ($fkColumns as $fkConfig) {
                $this->convertFkToTrigger($childTable, $fkConfig['column'], $fkConfig['on_delete']);
            }
        }
    }

    /**
     * Add BRIN indexes on journal_entries and journal_lines.
     * Drop the temporary B-tree idx_jl_entry_date (created in migration 000001)
     * since BRIN replaces it. Drop the old B-tree idx_je_entry_date if it
     * still exists (should not — migration 000002 used dropIndexesExceptPK
     * which dropped it — but DROP IF EXISTS is safe).
     */
    private function addBrinIndexes(): void
    {
        // Drop temporary B-tree indexes that BRIN replaces.
        DB::statement('DROP INDEX IF EXISTS idx_jl_entry_date');
        DB::statement('DROP INDEX IF EXISTS idx_je_entry_date');

        // BRIN on journal_entries.entry_date — primary partition-key index.
        // pages_per_range=32 is the recommended value for monotonically-increasing
        // date columns (default 128 is too coarse for monthly partitions).
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_je_entry_date_brin
                ON journal_entries USING BRIN (entry_date)
                WITH (pages_per_range = 32)
        SQL);

        // BRIN on journal_entries.created_at — for audit queries that filter
        // by created_at (e.g., "show me all JEs created in the last hour").
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_je_created_at_brin
                ON journal_entries USING BRIN (created_at)
                WITH (pages_per_range = 32)
        SQL);

        // Partial BRIN on journal_entries.entry_date WHERE is_reversed=false.
        // Powers the Trial Balance / GL reports (which exclude reversed entries).
        // Partial BRINs are smaller and faster to scan than full BRINs.
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_je_active_entry_date_brin
                ON journal_entries USING BRIN (entry_date)
                WHERE is_reversed = false
        SQL);

        // BRIN on journal_lines.entry_date — primary partition-key index.
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_jl_entry_date_brin
                ON journal_lines USING BRIN (entry_date)
                WITH (pages_per_range = 32)
        SQL);
    }

    /**
     * Convert a single declarative FK to a trigger-based FK.
     *
     * Steps (same as Phase 0.3 migration 2026_08_15_000003):
     *   1. Look up the FK constraint name + confdeltype from pg_constraint.
     *   2. Drop the declarative FK (if it exists).
     *   3. Create a trigger function that checks parent existence.
     *   4. Create a BEFORE INSERT OR UPDATE trigger on the child table.
     *   5. Based on $onDelete, create the corresponding AFTER DELETE
     *      trigger on journal_entries (CASCADE / SET_NULL) or a
     *      constraint trigger that RAISES EXCEPTION (NO_ACTION / RESTRICT).
     *
     * If the declarative FK is already gone (e.g., dropped by migration
     * 000002 for journal_lines, or already converted by Phase 0.3 for
     * journal_posting_logs), log and skip the drop step. The trigger-based
     * FK is still created (idempotently — CREATE OR REPLACE FUNCTION +
     * DROP TRIGGER IF EXISTS + CREATE TRIGGER).
     */
    private function convertFkToTrigger(string $childTable, string $fkColumn, string $onDelete): void
    {
        // ── Look up the FK constraint name + delete behaviour ──
        // confdeltype values: 'a' = NO ACTION, 'r' = RESTRICT,
        //                    'c' = CASCADE, 'n' = SET NULL, 'd' = SET DEFAULT
        $fk = DB::selectOne(<<<SQL
            SELECT c.conname, c.confdeltype
            FROM pg_constraint c
            JOIN pg_class t ON t.oid = c.conrelid
            JOIN pg_namespace n ON n.oid = t.relnamespace
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(c.conkey)
            JOIN pg_class rt ON rt.oid = c.confrelid
            JOIN pg_namespace rn ON rn.oid = rt.relnamespace
            WHERE t.relname = ?
              AND n.nspname = 'public'
              AND c.contype = 'f'
              AND a.attname = ?
              AND rt.relname = 'journal_entries'
              AND rn.nspname = 'public'
            LIMIT 1
        SQL, [$childTable, $fkColumn]);

        if (!$fk) {
            // FK may have already been converted in a prior run, or the
            // column may not have a declarative FK. Log and skip the drop
            // step — we still create the trigger-based FK below.
            Log::info("Phase 6.6: No declarative FK found on {$childTable}.{$fkColumn} → journal_entries(id) — skipping drop, creating trigger-based FK only.");
        } else {
            $constraintName = $fk->conname;
            $delType = $fk->confdeltype;
            // Drop the declarative FK.
            DB::statement("ALTER TABLE {$childTable} DROP CONSTRAINT IF EXISTS {$constraintName}");
            Log::info("Phase 6.6: Dropped declarative FK {$constraintName} on {$childTable}.{$fkColumn} [deltype={$delType}] — replacing with trigger-based FK configured for ON DELETE {$onDelete}.");
        }

        // ── Create trigger function (per-column, to allow precise error messages) ──
        $funcName = $this->sanitizeIdentifier("fn_trg_{$childTable}_{$fkColumn}_je_fk");
        $triggerName = $this->sanitizeIdentifier("trg_{$childTable}_{$fkColumn}_je_fk");

        DB::statement(<<<SQL
            CREATE OR REPLACE FUNCTION {$funcName}()
            RETURNS TRIGGER AS \$\$
            BEGIN
                IF NEW.{$fkColumn} IS NOT NULL THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM journal_entries
                        WHERE id = NEW.{$fkColumn}
                    ) THEN
                        RAISE EXCEPTION 'FK violation: journal_entries(id=%) not found for {$childTable}.{$fkColumn}',
                            NEW.{$fkColumn};
                    END IF;
                END IF;
                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql
        SQL);

        // ── Create BEFORE INSERT OR UPDATE trigger on the child table ──
        DB::statement("DROP TRIGGER IF EXISTS {$triggerName} ON {$childTable}");
        DB::statement(<<<SQL
            CREATE TRIGGER {$triggerName}
                BEFORE INSERT OR UPDATE OF {$fkColumn} ON {$childTable}
                FOR EACH ROW EXECUTE FUNCTION {$funcName}()
        SQL);

        // ── Handle ON DELETE behaviour ──
        $cascadeFunc = $this->sanitizeIdentifier("fn_trg_{$childTable}_{$fkColumn}_je_cascade");
        $cascadeTrigger = $this->sanitizeIdentifier("trg_je_del_cascade_{$childTable}_{$fkColumn}");

        if ($onDelete === 'CASCADE') {
            // ON DELETE CASCADE — delete child rows when parent is deleted.
            DB::statement("DROP TRIGGER IF EXISTS {$cascadeTrigger} ON journal_entries");
            DB::statement(<<<SQL
                CREATE OR REPLACE FUNCTION {$cascadeFunc}()
                RETURNS TRIGGER AS \$\$
                BEGIN
                    DELETE FROM {$childTable} WHERE {$fkColumn} = OLD.id;
                    RETURN OLD;
                END;
                \$\$ LANGUAGE plpgsql
            SQL);
            DB::statement(<<<SQL
                CREATE TRIGGER {$cascadeTrigger}
                    AFTER DELETE ON journal_entries
                    FOR EACH ROW EXECUTE FUNCTION {$cascadeFunc}()
            SQL);
            Log::info("Phase 6.6: Created CASCADE trigger on journal_entries for {$childTable}.{$fkColumn}");
        } elseif ($onDelete === 'SET_NULL') {
            // ON DELETE SET NULL — nullify child FK when parent is deleted.
            DB::statement("DROP TRIGGER IF EXISTS {$cascadeTrigger} ON journal_entries");
            DB::statement(<<<SQL
                CREATE OR REPLACE FUNCTION {$cascadeFunc}()
                RETURNS TRIGGER AS \$\$
                BEGIN
                    UPDATE {$childTable} SET {$fkColumn} = NULL WHERE {$fkColumn} = OLD.id;
                    RETURN OLD;
                END;
                \$\$ LANGUAGE plpgsql
            SQL);
            DB::statement(<<<SQL
                CREATE TRIGGER {$cascadeTrigger}
                    AFTER DELETE ON journal_entries
                    FOR EACH ROW EXECUTE FUNCTION {$cascadeFunc}()
            SQL);
            Log::info("Phase 6.6: Created SET NULL trigger on journal_entries for {$childTable}.{$fkColumn}");
        } else {
            // NO_ACTION / RESTRICT — prevent parent delete if child rows exist.
            // Use a constraint trigger so it can be DEFERRABLE if needed.
            DB::statement("DROP TRIGGER IF EXISTS {$cascadeTrigger} ON journal_entries");
            DB::statement(<<<SQL
                CREATE OR REPLACE FUNCTION {$cascadeFunc}()
                RETURNS TRIGGER AS \$\$
                BEGIN
                    IF EXISTS (
                        SELECT 1 FROM {$childTable} WHERE {$fkColumn} = OLD.id
                    ) THEN
                        RAISE EXCEPTION 'Cannot delete journal_entries(id=%): referenced by {$childTable}.{$fkColumn}',
                            OLD.id;
                    END IF;
                    RETURN OLD;
                END;
                \$\$ LANGUAGE plpgsql
            SQL);
            DB::statement(<<<SQL
                CREATE CONSTRAINT TRIGGER {$cascadeTrigger}
                    AFTER DELETE ON journal_entries
                    DEFERRABLE INITIALLY IMMEDIATE
                    FOR EACH ROW EXECUTE FUNCTION {$cascadeFunc}()
            SQL);
            Log::info("Phase 6.6: Created RESTRICT trigger on journal_entries for {$childTable}.{$fkColumn}");
        }
    }

    /**
     * Sanitize a string for use as a PostgreSQL identifier.
     * Replaces non-alphanumeric characters with underscores.
     */
    private function sanitizeIdentifier(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
    }

    public function down(): void
    {
        // Drop all trigger functions and triggers created by this migration.
        // Re-adding the declarative FKs is left to the DBA — it requires
        // verifying data integrity first (orphaned rows may exist if
        // journal_entries were deleted during the trigger-based period).

        // Drop BRIN indexes (Phase 6.5).
        DB::statement('DROP INDEX IF EXISTS idx_je_entry_date_brin');
        DB::statement('DROP INDEX IF EXISTS idx_je_created_at_brin');
        DB::statement('DROP INDEX IF EXISTS idx_je_active_entry_date_brin');
        DB::statement('DROP INDEX IF EXISTS idx_jl_entry_date_brin');

        foreach (self::FK_MAP as $childTable => $fkColumns) {
            foreach ($fkColumns as $fkConfig) {
                $fkColumn = $fkConfig['column'];
                $funcName = $this->sanitizeIdentifier("fn_trg_{$childTable}_{$fkColumn}_je_fk");
                $triggerName = $this->sanitizeIdentifier("trg_{$childTable}_{$fkColumn}_je_fk");
                $cascadeFunc = $this->sanitizeIdentifier("fn_trg_{$childTable}_{$fkColumn}_je_cascade");
                $cascadeTrigger = $this->sanitizeIdentifier("trg_je_del_cascade_{$childTable}_{$fkColumn}");

                // Drop child-table trigger
                DB::statement("DROP TRIGGER IF EXISTS {$triggerName} ON {$childTable}");

                // Drop parent-table cascade/restrict trigger
                DB::statement("DROP TRIGGER IF EXISTS {$cascadeTrigger} ON journal_entries");

                // Drop functions
                DB::statement("DROP FUNCTION IF EXISTS {$funcName}() CASCADE");
                DB::statement("DROP FUNCTION IF EXISTS {$cascadeFunc}() CASCADE");
            }
        }

        Log::warning('Phase 6.5+6.6 rollback: BRIN indexes and trigger-based FKs to journal_entries have been removed. Declarative FKs must be re-added manually by the DBA after verifying data integrity.');
    }
};

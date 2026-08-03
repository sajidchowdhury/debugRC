<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 10.1 — Phase 0.3 + 0.4: Convert declarative FKs to journal_entries
 * into trigger-based FKs (Batch A).
 *
 * CRITICAL BLOCKER FIX (Audit finding B4 + B5).
 *
 * 15 partitioned child tables still carry declarative
 * `REFERENCES journal_entries(id)` FKs. PostgreSQL will REJECT partitioning
 * `journal_entries` (Phase 6) while these declarative FKs exist. All 15
 * must be converted to trigger-based FKs BEFORE Phase 6.3.
 *
 * Tables converted (15):
 *
 *   Phase 1 audit log (1):
 *     - journal_posting_logs.journal_entry_id          (CASCADE)
 *
 *   Phase 2 sub-ledgers (5):
 *     - customer_ledger.journal_entry_id
 *     - supplier_ledger.journal_entry_id
 *     - employee_ledger.journal_entry_id
 *     - cash_ledger.journal_entry_id
 *     - branch_ledger.journal_entry_id
 *
 *   Phase 4 transaction headers (9):
 *     - money_transfers.journal_entry_id
 *     - money_transfers.intercompany_journal_entry_id
 *     - employee_transactions.journal_entry_id
 *     - other_incomes.journal_entry_id
 *     - other_expenses.journal_entry_id
 *     - sales_returns.journal_entry_id
 *     - sales_returns.cogs_journal_entry_id
 *     - purchase_receives.journal_entry_id
 *     - purchase_returns.journal_entry_id
 *     - damage_invoices.journal_entry_id
 *     - manual_journals.journal_entry_id
 *
 *   Initial-setup partitioned table (1):
 *     - sales_invoices.journal_entry_id                 (B5 — plan §6
 *       incorrectly claimed this was already trigger-based)
 *     - sales_invoices.cogs_journal_entry_id
 *
 * For each FK:
 *   1. Look up the constraint name + ON DELETE behaviour from pg_constraint.
 *   2. Drop the declarative FK.
 *   3. Create a trigger function that checks parent existence.
 *   4. Create a BEFORE INSERT OR UPDATE trigger on the child table.
 *   5. If the original FK was ON DELETE CASCADE, also create an
 *      AFTER DELETE cascade trigger on journal_entries (the parent).
 *   6. If the original FK was ON DELETE SET NULL, also create an
 *      AFTER DELETE set-null trigger on journal_entries.
 *
 * The trigger functions use a shared helper: fn_check_journal_entry_exists()
 * to avoid creating 17 separate functions. Cascade/set-null triggers use
 * per-table functions because the child table name is embedded in the body.
 *
 * Idempotent — re-running is safe. If a trigger already exists, it is
 * replaced (CREATE OR REPLACE FUNCTION / DROP TRIGGER IF EXISTS).
 */
return new class extends Migration
{
    /**
     * Map of child_table => [fk_column, ...] for all FKs to journal_entries.
     *
     * Includes the ON DELETE behaviour where known:
     *   - journal_posting_logs: CASCADE (per 02_accounting.sql)
     *   - journal_lines: CASCADE (but NOT in this batch — Phase 6)
     *   - All others: NO ACTION / RESTRICT (the default)
     *
     * The migration queries pg_constraint at runtime to detect the actual
     * confdeltype, so this map only needs the column names.
     */
    private const FK_MAP = [
        // Phase 1 — audit log
        'journal_posting_logs'  => ['journal_entry_id'],

        // Phase 2 — sub-ledgers
        'customer_ledger'       => ['journal_entry_id'],
        'supplier_ledger'       => ['journal_entry_id'],
        'employee_ledger'       => ['journal_entry_id'],
        'cash_ledger'           => ['journal_entry_id'],
        'branch_ledger'         => ['journal_entry_id'],

        // Phase 4 — transaction headers
        'money_transfers'       => ['journal_entry_id', 'intercompany_journal_entry_id'],
        'employee_transactions' => ['journal_entry_id'],
        'other_incomes'         => ['journal_entry_id'],
        'other_expenses'        => ['journal_entry_id'],
        'sales_returns'         => ['journal_entry_id', 'cogs_journal_entry_id'],
        'purchase_receives'     => ['journal_entry_id'],
        'purchase_returns'      => ['journal_entry_id'],
        'damage_invoices'       => ['journal_entry_id'],
        'manual_journals'       => ['journal_entry_id'],

        // Initial-setup partitioned table (B5)
        'sales_invoices'        => ['journal_entry_id', 'cogs_journal_entry_id'],
    ];

    public function up(): void
    {
        // ============================================================
        // 1. Create a shared helper function that checks whether a
        //    journal_entries row exists by id. This avoids creating 17
        //    nearly-identical trigger functions.
        // ============================================================
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

        // ============================================================
        // 2. Process each child table + FK column.
        // ============================================================
        foreach (self::FK_MAP as $childTable => $fkColumns) {
            foreach ($fkColumns as $fkColumn) {
                $this->convertFkToTrigger($childTable, $fkColumn);
            }
        }
    }

    /**
     * Convert a single declarative FK to a trigger-based FK.
     *
     * Steps:
     *   1. Look up the FK constraint name + confdeltype from pg_constraint.
     *   2. Drop the declarative FK.
     *   3. Create a trigger function that checks parent existence.
     *   4. Create a BEFORE INSERT OR UPDATE trigger on the child table.
     *   5. If CASCADE or SET NULL, create the corresponding AFTER DELETE
     *      trigger on journal_entries.
     */
    private function convertFkToTrigger(string $childTable, string $fkColumn): void
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
            // column may not have a declarative FK. Log and skip.
            Log::info("FK conversion: No declarative FK found on {$childTable}.{$fkColumn} → journal_entries(id) — skipping (may already be trigger-based).");
            return;
        }

        $constraintName = $fk->conname;
        $delType = $fk->confdeltype;

        // ── Drop the declarative FK ──
        DB::statement("ALTER TABLE {$childTable} DROP CONSTRAINT IF EXISTS {$constraintName}");
        Log::info("FK conversion: Dropped declarative FK {$constraintName} on {$childTable}.{$fkColumn}");

        // ── Create trigger function (per-column, to allow precise error messages) ──
        $funcName = "fn_trg_{$childTable}_{$fkColumn}_je_fk";
        $triggerName = "trg_{$childTable}_{$fkColumn}_je_fk";

        // Sanitize names for SQL identifiers
        $funcName = $this->sanitizeIdentifier($funcName);
        $triggerName = $this->sanitizeIdentifier($triggerName);

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
        // Only CASCADE and SET NULL need a trigger on the parent.
        // NO ACTION / RESTRICT are handled by the check trigger on the child
        // (the DELETE on the parent will fail if children exist, because
        // the child rows still reference the parent id — but wait, we just
        // dropped the FK, so there's no declarative protection either).
        //
        // For NO ACTION / RESTRICT: we need to add an AFTER DELETE trigger
        // on journal_entries that RAISES EXCEPTION if child rows exist.
        // This preserves the original "prevent delete if referenced" behaviour.
        //
        // However, journal_entries are almost never deleted (they are
        // reversed instead). Adding a restrict trigger would add overhead
        // to every JE delete. We'll add it only for tables that originally
        // had RESTRICT/NO ACTION (the default).

        $cascadeFunc = "fn_trg_{$childTable}_{$fkColumn}_je_cascade";
        $cascadeTrigger = "trg_je_del_cascade_{$childTable}_{$fkColumn}";
        $cascadeFunc = $this->sanitizeIdentifier($cascadeFunc);
        $cascadeTrigger = $this->sanitizeIdentifier($cascadeTrigger);

        if ($delType === 'c') {
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
            Log::info("FK conversion: Created CASCADE trigger on journal_entries for {$childTable}.{$fkColumn}");
        } elseif ($delType === 'n') {
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
            Log::info("FK conversion: Created SET NULL trigger on journal_entries for {$childTable}.{$fkColumn}");
        } else {
            // NO ACTION / RESTRICT — prevent parent delete if child rows exist.
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
            Log::info("FK conversion: Created RESTRICT trigger on journal_entries for {$childTable}.{$fkColumn}");
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

        // Drop the shared helper
        DB::statement('DROP FUNCTION IF EXISTS fn_check_journal_entry_exists(BIGINT) CASCADE');

        foreach (self::FK_MAP as $childTable => $fkColumns) {
            foreach ($fkColumns as $fkColumn) {
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

        Log::warning('Phase 0.3+0.4 rollback: Trigger-based FKs to journal_entries have been removed. Declarative FKs must be re-added manually by the DBA after verifying data integrity.');
    }
};

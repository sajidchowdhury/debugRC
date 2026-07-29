<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Damage Phase 4 — Witness & Accountable Employee.
 *
 * Closes the biggest remaining accountability loophole: a damage declaration
 * had NO named responsible party. An employee could declare stock as
 * "missing" (unaccounted for) or "theft" and walk away — no witness to
 * corroborate the story, no one held responsible for the loss, and no path
 * to recover the cost from the person at fault.
 *
 * This migration adds four columns to `damage_invoices`:
 *
 *   1. witness_employee_id      — the employee who corroborates a theft /
 *      sensitive write-off (required for damage_type='theft', enforced in
 *      DamageService::createDamage). Mirrors the legacy "witness" concept
 *      that was never actually persisted.
 *
 *   2. accountable_employee_id  — the employee responsible for the loss
 *      (required for damage_type='missing' — someone must own the
 *      unaccounted-for stock). Optional for other types (recommended when
 *      an employee caused the damage).
 *
 *   3. recovery_amount          — the BDT amount recovered from the
 *      accountable employee (via employee_ledger debit / salary deduction).
 *      0 by default; set once by DamageService::postEmployeeRecovery.
 *
 *   4. employee_ledger_entry_id — link to the `employee_ledger` row created
 *      by the recovery, so the sub-ledger entry can be reversed if the
 *      damage is later cancelled (the employee should not owe us for a
 *      write-off that was itself reversed).
 *
 *   5. recovery_journal_entry_id — link to the GL journal entry posted by
 *      the recovery (Dr employee_payable / Cr loss ledger). Stored
 *      explicitly (in addition to employee_ledger_entry_id, which also
 *      carries journal_entry_id) so cancelDamage can reverse the recovery
 *      JE directly without an extra lookup. This mirrors how the main
 *      damage JE is tracked via `journal_entry_id`.
 *
 * Design notes:
 *   - All five columns are NULLABLE. Existing rows (and the sales-return-
 *     linked auto-flow) have no witness/accountable — that's fine. The
 *     requirement is enforced at create-time by damage_type, not by a NOT
 *     NULL constraint (a NOT NULL would break backfill + the linked flow).
 *   - No CHECK constraint on the columns themselves — the type-conditional
 *     requirement (missing→accountable, theft→witness) lives in the service
 *     layer because it depends on another column (damage_type), which a
 *     CHECK could express but would couple schema to business rules. The
 *     service gate + the integrity panel (Phase 2 service, 7th check) are
 *     the enforcement surface.
 *   - FKs use ON DELETE NO ACTION (the default). An employee referenced by
 *     a damage must NOT be hard-deleted (soft-delete employees instead —
 *     the employees table has deleted_at). Same for employee_ledger /
 *     journal_entries rows — they're append-only / reversal-only, never
 *     deleted, so NO ACTION is correct.
 *   - Partial indexes (WHERE ... IS NOT NULL) on the two employee FKs:
 *     most damages won't have them set (only missing/theft), so a full
 *     index would be mostly NULLs. Partial indexes stay small + serve the
 *     Phase 6 "damages by accountable employee" report + the index-page
 *     accountable filter.
 *   - No RLS changes: these are integer columns on damage_invoices, which
 *     already has 5 RLS policies. The referenced employees / employee_ledger
 *     / journal_entries tables have their own RLS. Branch scoping is
 *     inherited from the parent damage row.
 *
 * Recovery flow (wired in DamageService, not here):
 *   Dr employee_payable (reduce payable — employee owes us)
 *   Cr <loss ledger> (reduce the damage_loss / inventory_shrinkage expense
 *      that was debited on confirm — nets the recovery against the loss)
 *   + employee_ledger row: transaction_type='deduction', debit=amount
 *     (employee owes us; balance = prev + credit - debit goes down).
 *
 * @see docs/DAMAGE_IMPLEMENTATION_PLAN.md  Phase 4
 * @see app/Services/Stock/DamageService.php  createDamage + postEmployeeRecovery
 * @see app/Models/DamageInvoice.php  witnessEmployee / accountableEmployee
 */
return new class extends Migration
{
    public function up(): void
    {
        // ============================================================
        // 1. witness_employee_id
        // ============================================================
        if (!Schema::hasColumn('damage_invoices', 'witness_employee_id')) {
            Schema::table('damage_invoices', function (Blueprint $table) {
                $table->integer('witness_employee_id')
                      ->nullable()
                      ->after('created_by');
            });
            DB::statement(
                'ALTER TABLE damage_invoices ' .
                'ADD CONSTRAINT damage_invoices_witness_employee_id_foreign ' .
                'FOREIGN KEY (witness_employee_id) REFERENCES employees(id) ' .
                'ON DELETE NO ACTION'
            );
        }

        // ============================================================
        // 2. accountable_employee_id
        // ============================================================
        if (!Schema::hasColumn('damage_invoices', 'accountable_employee_id')) {
            Schema::table('damage_invoices', function (Blueprint $table) {
                $table->integer('accountable_employee_id')
                      ->nullable()
                      ->after('witness_employee_id');
            });
            DB::statement(
                'ALTER TABLE damage_invoices ' .
                'ADD CONSTRAINT damage_invoices_accountable_employee_id_foreign ' .
                'FOREIGN KEY (accountable_employee_id) REFERENCES employees(id) ' .
                'ON DELETE NO ACTION'
            );
        }

        // ============================================================
        // 3. recovery_amount (default 0 — most damages have no recovery)
        // ============================================================
        if (!Schema::hasColumn('damage_invoices', 'recovery_amount')) {
            Schema::table('damage_invoices', function (Blueprint $table) {
                $table->decimal('recovery_amount', 14, 2)
                      ->default(0)
                      ->after('accountable_employee_id');
            });
        }

        // ============================================================
        // 4. employee_ledger_entry_id (link to the recovery sub-ledger row)
        // ============================================================
        if (!Schema::hasColumn('damage_invoices', 'employee_ledger_entry_id')) {
            Schema::table('damage_invoices', function (Blueprint $table) {
                $table->integer('employee_ledger_entry_id')
                      ->nullable()
                      ->after('recovery_amount');
            });
            DB::statement(
                'ALTER TABLE damage_invoices ' .
                'ADD CONSTRAINT damage_invoices_employee_ledger_entry_id_foreign ' .
                'FOREIGN KEY (employee_ledger_entry_id) REFERENCES employee_ledger(id) ' .
                'ON DELETE NO ACTION'
            );
        }

        // ============================================================
        // 5. recovery_journal_entry_id (link to the recovery GL JE)
        // ============================================================
        if (!Schema::hasColumn('damage_invoices', 'recovery_journal_entry_id')) {
            Schema::table('damage_invoices', function (Blueprint $table) {
                $table->integer('recovery_journal_entry_id')
                      ->nullable()
                      ->after('employee_ledger_entry_id');
            });
            DB::statement(
                'ALTER TABLE damage_invoices ' .
                'ADD CONSTRAINT damage_invoices_recovery_journal_entry_id_foreign ' .
                'FOREIGN KEY (recovery_journal_entry_id) REFERENCES journal_entries(id) ' .
                'ON DELETE NO ACTION'
            );
        }

        // ============================================================
        // 6. Partial indexes (only rows that actually have a value set)
        // ============================================================
        $this->ensureIndex(
            'damage_invoices',
            'idx_dmg_accountable',
            'CREATE INDEX idx_dmg_accountable ON damage_invoices(accountable_employee_id) WHERE accountable_employee_id IS NOT NULL'
        );
        $this->ensureIndex(
            'damage_invoices',
            'idx_dmg_witness',
            'CREATE INDEX idx_dmg_witness ON damage_invoices(witness_employee_id) WHERE witness_employee_id IS NOT NULL'
        );
        // Index for the "recoverable" stat (confirmed, not yet recovered).
        $this->ensureIndex(
            'damage_invoices',
            'idx_dmg_recovery',
            'CREATE INDEX idx_dmg_recovery ON damage_invoices(accountable_employee_id) WHERE accountable_employee_id IS NOT NULL AND recovery_amount = 0 AND status = \'confirmed\''
        );
    }

    public function down(): void
    {
        // --- Drop indexes ---
        DB::statement('DROP INDEX IF EXISTS idx_dmg_recovery');
        DB::statement('DROP INDEX IF EXISTS idx_dmg_witness');
        DB::statement('DROP INDEX IF EXISTS idx_dmg_accountable');

        // --- Drop FK constraints, then columns ---
        foreach ([
            'recovery_journal_entry_id',
            'employee_ledger_entry_id',
            'accountable_employee_id',
            'witness_employee_id',
        ] as $column) {
            $constraint = 'damage_invoices_' . $column . '_foreign';
            $exists = DB::table('pg_constraint')
                ->where('conname', $constraint)
                ->where('contype', 'f')
                ->exists();
            if ($exists) {
                DB::statement('ALTER TABLE damage_invoices DROP CONSTRAINT "' . $constraint . '"');
            }
        }

        Schema::table('damage_invoices', function (Blueprint $table) {
            foreach ([
                'recovery_journal_entry_id',
                'employee_ledger_entry_id',
                'recovery_amount',
                'accountable_employee_id',
                'witness_employee_id',
            ] as $column) {
                if (Schema::hasColumn('damage_invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * Create an index only if it doesn't already exist (idempotent).
     */
    private function ensureIndex(string $table, string $indexName, string $ddl): void
    {
        $exists = collect(DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?",
            [$table, $indexName]
        ))->count();
        if (!$exists) {
            DB::statement($ddl);
        }
    }
};

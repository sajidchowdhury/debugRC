<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9.3: Bank Reconciliation
 *
 * Creates tables to support the bank reconciliation workflow:
 *
 *   1. bank_reconciliations — header record for each reconciliation run
 *   2. bank_statement_lines — imported bank statement entries (CSV or manual)
 *   3. bank_reconciliation_items — matched pairs (system transaction ↔ statement line)
 *
 * Workflow:
 *   ┌──────────────────────────────────────────────────────────────────┐
 *   │ 1. User selects a bank account + statement period              │
 *   │ 2. User imports bank statement lines (CSV upload or manual)    │
 *   │ 3. System auto-matches statement lines against system txns     │
 *   │    (by amount, date, reference)                                 │
 *   │ 4. User manually matches remaining items                       │
 *   │ 5. User reviews reconciliation summary                         │
 *   │ 6. User completes reconciliation → posts adjustment entries    │
 *   │    (bank charges, interest, errors)                             │
 *   │ 7. Reconciliation is locked — no further changes               │
 *   └──────────────────────────────────────────────────────────────────┘
 *
 * Key design decisions:
 *   - bank_statement_lines are per-bank, per-statement-period
 *   - System transactions are identified via journal_lines (GL entries)
 *     that hit the bank's ledger, not by querying 6+ transaction tables
 *   - Matching is many-to-many: one statement line can match multiple
 *     journal lines (e.g., a lump deposit covering several receipts)
 *   - Adjustment entries (bank charges, interest) are posted as regular
 *     journal entries via JournalPostingService
 *   - The banks.balance is NOT updated by reconciliation — it is updated
 *     when the actual transaction is posted. Reconciliation only verifies.
 *   - RLS via app.is_admin for admin-only operations
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. bank_reconciliations ──────────────────────────────────
        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->string('reconciliation_code', 30)->unique();  // e.g. "BR-2026-08-001"
            $table->unsignedBigInteger('bank_id');
            $table->foreign('bank_id', 'fk_br_bank')
                  ->references('id')->on('banks')->restrictOnDelete();

            $table->date('statement_date');         // End of statement period
            $table->date('period_from');             // Start of reconciliation period
            $table->date('period_to');               // End of reconciliation period

            // Opening / closing balances per bank statement
            $table->decimal('statement_opening_balance', 15, 2)->default(0);
            $table->decimal('statement_closing_balance', 15, 2)->default(0);

            // Calculated system balances
            $table->decimal('system_opening_balance', 15, 2)->default(0);
            $table->decimal('system_closing_balance', 15, 2)->default(0);

            // Adjusted (after reconciliation) balances
            $table->decimal('adjusted_book_balance', 15, 2)->default(0);
            $table->decimal('adjusted_bank_balance', 15, 2)->default(0);
            $table->decimal('difference', 15, 2)->default(0);

            $table->string('status', 20)->default('draft')
                  ->check("status IN ('draft','in_progress','completed','reversed')");

            // Summary counts
            $table->integer('total_statement_lines')->default(0);
            $table->integer('matched_lines')->default(0);
            $table->integer('unmatched_statement_lines')->default(0);
            $table->integer('unmatched_system_entries')->default(0);

            // Adjustment journal entry (for bank charges, interest, errors)
            $table->unsignedBigInteger('adjustment_journal_entry_id')->nullable();
            $table->foreign('adjustment_journal_entry_id', 'fk_br_adjustment_je')
                  ->references('id')->on('journal_entries')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('reversed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reverse_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['bank_id', 'status'], 'idx_br_bank_status');
            $table->index(['bank_id', 'period_from', 'period_to'], 'idx_br_bank_period');
            $table->index('statement_date');
        });

        // ── 2. bank_statement_lines ──────────────────────────────────
        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bank_reconciliation_id');
            $table->foreign('bank_reconciliation_id', 'fk_bsl_reconciliation')
                  ->references('id')->on('bank_reconciliations')->cascadeOnDelete();

            $table->date('transaction_date');
            $table->string('description', 255)->nullable();
            $table->string('reference', 100)->nullable();     // Cheque no, reference no
            $table->decimal('debit', 15, 2)->default(0);      // Money out (withdrawal)
            $table->decimal('credit', 15, 2)->default(0);     // Money in (deposit)
            $table->decimal('balance', 15, 2)->default(0)->nullable(); // Running balance from statement

            $table->string('match_status', 20)->default('unmatched')
                  ->check("match_status IN ('unmatched','suggested','matched','excluded')");

            $table->integer('line_number')->default(0);       // Original line order from CSV
            $table->text('raw_data')->nullable();             // Original CSV row for audit
            $table->timestamps();

            $table->index(['bank_reconciliation_id', 'match_status'], 'idx_bsl_recon_status');
            $table->index(['bank_reconciliation_id', 'transaction_date'], 'idx_bsl_recon_date');
            $table->index('reference');
        });

        // ── 3. bank_reconciliation_items ─────────────────────────────
        // Links statement lines to system journal lines (many-to-many)
        Schema::create('bank_reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('bank_reconciliation_id');
            $table->foreign('bank_reconciliation_id', 'fk_bri_reconciliation')
                  ->references('id')->on('bank_reconciliations')->cascadeOnDelete();

            // The bank statement line
            $table->unsignedBigInteger('bank_statement_line_id');
            $table->foreign('bank_statement_line_id', 'fk_bri_statement_line')
                  ->references('id')->on('bank_statement_lines')->cascadeOnDelete();

            // The system journal line (the GL entry that hit the bank's ledger)
            $table->unsignedBigInteger('journal_line_id');
            $table->foreign('journal_line_id', 'fk_bri_journal_line')
                  ->references('id')->on('journal_lines')->cascadeOnDelete();

            // The parent journal entry for quick reference
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreign('journal_entry_id', 'fk_bri_journal_entry')
                  ->references('id')->on('journal_entries')->nullOnDelete();

            $table->string('match_type', 20)->default('auto')
                  ->check("match_type IN ('auto','manual')");

            $table->decimal('matched_amount', 15, 2)->default(0); // Amount matched (for partial matches)
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('matched_by')->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();

            $table->index(['bank_reconciliation_id', 'bank_statement_line_id'], 'idx_bri_recon_line');
            $table->index(['bank_reconciliation_id', 'journal_line_id'], 'idx_bri_recon_jl');
            $table->index('journal_entry_id');
        });

        // ── 4. Add reconciliation status to journal_lines ────────────
        // Marks which journal lines have been cleared in bank reconciliation
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->boolean('is_bank_reconciled')->default(false)->after('dimension_value_id');
            $table->unsignedBigInteger('bank_reconciliation_id')->nullable()->after('is_bank_reconciled');
            $table->foreign('bank_reconciliation_id', 'fk_jl_bank_reconciliation')
                  ->references('id')->on('bank_reconciliations')->nullOnDelete();

            $table->index(['is_bank_reconciled', 'ledger_id'], 'idx_jl_reconciled_ledger');
        });

        // ── 5. Partial unique index for bank_reconciliations ─────────
        // Prevent duplicate reconciliations for the same bank + period (only active)
        DB::statement("
            CREATE UNIQUE INDEX uq_br_bank_period_active
            ON bank_reconciliations (bank_id, period_from, period_to)
            WHERE deleted_at IS NULL AND status IN ('draft', 'in_progress')
        ");

        // ── 6. View: unreconciled bank journal lines ─────────────────
        // Shows all journal lines that hit a bank's ledger but haven't been
        // cleared in bank reconciliation yet — used by the reconciliation UI
        DB::statement(<<<'SQL'
CREATE OR REPLACE VIEW v_unreconciled_bank_entries AS
SELECT
    jl.id AS journal_line_id,
    jl.journal_entry_id,
    je.entry_no,
    je.entry_date,
    je.description AS entry_description,
    je.source AS entry_source,
    je.reference_type,
    je.reference_id,
    jl.ledger_id,
    jl.debit,
    jl.credit,
    jl.memo,
    jl.is_bank_reconciled,
    l.ledger_code,
    l.ledger_name,
    b.id AS bank_id,
    b.bank_name,
    b.account_number,
    blm.bank_id AS mapping_bank_id,
    je.branch_id,
    br.branch_name
FROM journal_lines jl
JOIN journal_entries je ON je.id = jl.journal_entry_id
JOIN ledgers l ON l.id = jl.ledger_id
LEFT JOIN bank_ledger_mappings blm ON blm.ledger_id = l.id
LEFT JOIN banks b ON b.id = blm.bank_id
LEFT JOIN branches br ON br.id = je.branch_id
WHERE jl.is_bank_reconciled = false
  AND COALESCE(je.is_reversed, false) = false
  AND blm.bank_id IS NOT NULL
  AND l.deleted_at IS NULL
  AND l.is_active = true
ORDER BY je.entry_date, je.entry_no
SQL
        );

        // ── 7. RLS policies ─────────────────────────────────────────
        foreach (['bank_reconciliations', 'bank_statement_lines', 'bank_reconciliation_items'] as $tbl) {
            DB::statement("ALTER TABLE {$tbl} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$tbl} FORCE ROW LEVEL SECURITY");
            DB::statement("
                CREATE POLICY {$tbl}_admin_policy ON {$tbl}
                USING (current_setting('app.is_admin', true) = 'true')
                WITH CHECK (current_setting('app.is_admin', true) = 'true')
            ");
        }
    }

    public function down(): void
    {
        // Drop view first
        DB::statement("DROP VIEW IF EXISTS v_unreconciled_bank_entries CASCADE");

        // Drop RLS
        foreach (['bank_reconciliations', 'bank_statement_lines', 'bank_reconciliation_items'] as $tbl) {
            DB::statement("DROP POLICY IF EXISTS {$tbl}_admin_policy ON {$tbl}");
            DB::statement("ALTER TABLE {$tbl} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$tbl} DISABLE ROW LEVEL SECURITY");
        }

        // Drop partial unique index
        DB::statement("DROP INDEX IF EXISTS uq_br_bank_period_active");

        // Remove columns from journal_lines
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropForeign('fk_jl_bank_reconciliation');
            $table->dropIndex('idx_jl_reconciled_ledger');
            $table->dropColumn('is_bank_reconciled');
            $table->dropColumn('bank_reconciliation_id');
        });

        // Drop tables in reverse dependency order
        Schema::dropIfExists('bank_reconciliation_items');
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_reconciliations');
    }
};

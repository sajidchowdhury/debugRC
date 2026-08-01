<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add intercompany_journal_entry_id to employee_transactions.
 *
 * Phase 2 (Accounts Sub-Ledger) — brings employee_transactions to parity
 * with supplier_payments + customer_payments, which already have this
 * column for cross-branch bank-mode intercompany settlement.
 *
 * When an employee transaction is recorded at Branch A but the bank belongs
 * to Branch B, EmployeeTransactionService::postIntercompanySettlement()
 * posts intercompany journal entries (Dr interbranch_receivable at the
 * bank's branch / Cr interbranch_payable at the transaction's branch) +
 * a branch_ledger obligation row. The primary (debtor) JE id is stored
 * in this column for the show page + reversal lookup.
 *
 * Nullable because:
 *   - Cash-mode transactions never have intercompany.
 *   - Same-branch bank-mode transactions never have intercompany.
 *   - Shared / head-office banks (branch_id = NULL) never have intercompany.
 *   - deduction type has no bank GL line, so no intercompany.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('employee_transactions')) {
            return;
        }

        if (!Schema::hasColumn('employee_transactions', 'intercompany_journal_entry_id')) {
            Schema::table('employee_transactions', function (Blueprint $table) {
                $table->integer('intercompany_journal_entry_id')->nullable()
                    ->after('journal_entry_id')
                    ->comment('FK to journal_entries — cross-branch bank-mode settlement (debtor JE id)');
            });

            // Add FK constraint separately so it survives on DBs that dislike
            // inline FK on ALTER TABLE ADD COLUMN.
            $hasFk = DB::table('information_schema.table_constraints')
                ->where('table_name', 'employee_transactions')
                ->where('constraint_type', 'FOREIGN KEY')
                ->where('constraint_name', 'employee_transactions_intercompany_journal_entry_id_foreign')
                ->exists();

            if (!$hasFk) {
                DB::statement(
                    'ALTER TABLE employee_transactions '
                    . 'ADD CONSTRAINT employee_transactions_intercompany_journal_entry_id_foreign '
                    . 'FOREIGN KEY (intercompany_journal_entry_id) REFERENCES journal_entries(id) ON DELETE SET NULL'
                );
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('employee_transactions')) {
            return;
        }

        $hasFk = DB::table('information_schema.table_constraints')
            ->where('table_name', 'employee_transactions')
            ->where('constraint_type', 'FOREIGN KEY')
            ->where('constraint_name', 'employee_transactions_intercompany_journal_entry_id_foreign')
            ->exists();

        if ($hasFk) {
            DB::statement(
                'ALTER TABLE employee_transactions '
                . 'DROP CONSTRAINT employee_transactions_intercompany_journal_entry_id_foreign'
            );
        }

        if (Schema::hasColumn('employee_transactions', 'intercompany_journal_entry_id')) {
            Schema::table('employee_transactions', function (Blueprint $table) {
                $table->dropColumn('intercompany_journal_entry_id');
            });
        }
    }
};

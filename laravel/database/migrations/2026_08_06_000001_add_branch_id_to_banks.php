<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add nullable branch_id to the banks table.
 *
 * Phase 1 (Accounts Sub-Ledger) — resolves the "dead intercompany code"
 * flag in SupplierTransactionService::postIntercompanySettlement().
 *
 * Previously the banks table had NO branch_id column, so the supplier +
 * customer payment services could not determine whether a bank belonged
 * to a different branch than the payment — the intercompany settlement
 * method hard-returned null and ~60 lines of intended logic were dead.
 *
 * This migration adds a nullable branch_id (FK → branches) so each bank
 * CAN be scoped to a company branch. Nullable because:
 *   - Existing banks (legacy migration) are shared / head-office and have
 *     no branch — they remain branch_id = NULL (no intercompany needed).
 *   - A bank with branch_id = NULL is treated as shared (no intercompany).
 *
 * With this column in place, SupplierTransactionService can now detect
 * cross-branch bank-mode payments and post intercompany journal entries
 * (Dr interbranch_receivable at the bank's branch / Cr interbranch_payable
 * at the payment's branch) + a branch_ledger obligation row.
 *
 * Note: the CustomerPaymentService has the same dead-code pattern; this
 * migration unblocks it too, but activating the customer side is Phase 3
 * scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('banks')) {
            return;
        }

        if (!Schema::hasColumn('banks', 'branch_id')) {
            Schema::table('banks', function (Blueprint $table) {
                // Nullable — NULL means the bank is shared / head-office.
                // Placed after ledger_id to keep related FK columns grouped.
                $table->integer('branch_id')->nullable()
                    ->after('ledger_id')
                    ->comment('Company branch this bank belongs to (NULL = shared / head-office). Used for cross-branch intercompany settlement.');
            });

            // Add FK constraint separately so it survives on DBs that dislike
            // inline FK on ALTER TABLE ADD COLUMN.
            $hasFk = DB::table('information_schema.table_constraints')
                ->where('table_name', 'banks')
                ->where('constraint_type', 'FOREIGN KEY')
                ->where('constraint_name', 'banks_branch_id_foreign')
                ->exists();

            if (!$hasFk) {
                DB::statement('ALTER TABLE banks ADD CONSTRAINT banks_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL');
            }

            // Index for "find all banks of branch X" lookups.
            DB::statement('CREATE INDEX IF NOT EXISTS idx_banks_branch ON banks(branch_id) WHERE branch_id IS NOT NULL');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('banks')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_banks_branch');

        $hasFk = DB::table('information_schema.table_constraints')
            ->where('table_name', 'banks')
            ->where('constraint_type', 'FOREIGN KEY')
            ->where('constraint_name', 'banks_branch_id_foreign')
            ->exists();

        if ($hasFk) {
            DB::statement('ALTER TABLE banks DROP CONSTRAINT banks_branch_id_foreign');
        }

        if (Schema::hasColumn('banks', 'branch_id')) {
            Schema::table('banks', function (Blueprint $table) {
                $table->dropColumn('branch_id');
            });
        }
    }
};

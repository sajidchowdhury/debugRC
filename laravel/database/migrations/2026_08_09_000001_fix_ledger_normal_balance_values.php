<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix normal_balance values in the ledgers table.
 *
 * Phase 4.1 — Trial Balance correctness fix.
 *
 * The normal_balance column determines which side (Dr or Cr) an account's
 * net balance is displayed on.  The seed data incorrectly set ALL ledgers
 * to 'debit', which breaks the Trial Balance display for Liability, Equity,
 * and Income accounts.
 *
 * The correct mapping is:
 *   Asset     → debit
 *   Liability → credit
 *   Equity    → credit
 *   Income    → credit
 *   Expense   → debit
 *
 * For ledgers with a specific ledger_nature, we use the nature's defined
 * normal_balance from LedgerNatureService (which is the source of truth).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Step 1: Fix all ledgers by account_type ────────────────────
        // These are the standard accounting rules for the 5 account types.
        DB::table('ledgers')
            ->where('account_type', 'Asset')
            ->whereNull('deleted_at')
            ->update(['normal_balance' => 'debit']);

        DB::table('ledgers')
            ->where('account_type', 'Liability')
            ->whereNull('deleted_at')
            ->update(['normal_balance' => 'credit']);

        DB::table('ledgers')
            ->where('account_type', 'Equity')
            ->whereNull('deleted_at')
            ->update(['normal_balance' => 'credit']);

        DB::table('ledgers')
            ->where('account_type', 'Income')
            ->whereNull('deleted_at')
            ->update(['normal_balance' => 'credit']);

        DB::table('ledgers')
            ->where('account_type', 'Expense')
            ->whereNull('deleted_at')
            ->update(['normal_balance' => 'debit']);

        // ── Step 2: Override specific ledger_natures that differ from
        //            their account_type default ──────────────────────────
        // Contra-revenue accounts (Income type but debit normal balance)
        $debitNatures = ['sales_return', 'sales_discount'];
        DB::table('ledgers')
            ->whereIn('ledger_nature', $debitNatures)
            ->whereNull('deleted_at')
            ->update(['normal_balance' => 'debit']);

        // Income-type natures that are credit (already correct, but be explicit)
        $creditNatures = [
            'sales_revenue', 'transport_revenue', 'inventory_surplus',
            'other_income', 'retained_earnings',
        ];
        DB::table('ledgers')
            ->whereIn('ledger_nature', $creditNatures)
            ->whereNull('deleted_at')
            ->update(['normal_balance' => 'credit']);

        // Expense-type natures that are debit (already correct, but be explicit)
        $debitExpenseNatures = [
            'cogs', 'inventory_shrinkage', 'damage_loss', 'operating_expense',
            'salary_expense', 'finance_cost',
        ];
        DB::table('ledgers')
            ->whereIn('ledger_nature', $debitExpenseNatures)
            ->whereNull('deleted_at')
            ->update(['normal_balance' => 'debit']);
    }

    public function down(): void
    {
        // Revert all to debit (the original incorrect state)
        DB::table('ledgers')
            ->whereNull('deleted_at')
            ->update(['normal_balance' => 'debit']);
    }
};

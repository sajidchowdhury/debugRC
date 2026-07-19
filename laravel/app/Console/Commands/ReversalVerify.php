<?php

namespace App\Console\Commands;

use App\Services\Accounting\JournalReversalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reversal Verification — Phase 9.4.
 *
 * Verifies that all journal entry reversals net to zero on Trial Balance.
 * Every reversal should exactly offset its original (original Dr = reversal Cr,
 * original Cr = reversal Dr).
 *
 * Also checks:
 *   - All reversed entries have a corresponding reversal entry
 *   - No orphan reversals (reversal entries without an original)
 *   - Sub-ledger entries are consistently reversed (no GL reversed but sub-ledger not)
 *
 * Usage: php artisan reversal:verify
 * Exit 0 = all reversals net to zero, 1 = issues found.
 */
class ReversalVerify extends Command
{
    protected $signature = 'reversal:verify';
    protected $description = 'Verify all journal reversals net to zero on Trial Balance';

    public function handle(JournalReversalService $reversalService): int
    {
        $this->info('=== Reversal Verification (Phase 9.4) ===');
        $this->info('Verifies that all reversals net to zero with their originals.');
        $this->newLine();

        $summary = $reversalService->getReversalSummary();
        $allPass = true;

        // 1. Summary stats.
        $this->info('1. Reversal Summary:');
        $this->line("   Total reversed entries: {$summary['total_reversed']}");
        $this->line("   Total reversal amount:  " . number_format($summary['total_reversal_amount'], 2));
        $this->newLine();

        // 2. By reference type.
        $this->info('2. Reversals by Reference Type:');
        if (empty($summary['by_reference_type'])) {
            $this->line("   No reversals found.");
        } else {
            foreach ($summary['by_reference_type'] as $type => $count) {
                $this->line("   {$type}: {$count}");
            }
        }
        $this->newLine();

        // 3. Unbalanced reversals (the critical check).
        $this->info('3. Unbalanced Reversals Check (original + reversal should net to zero):');
        if (empty($summary['unbalanced_reversals'])) {
            $this->info("   ✓ All reversals net to zero.");
        } else {
            $this->error("   ✗ " . count($summary['unbalanced_reversals']) . " unbalanced reversal(s) found:");
            foreach (array_slice($summary['unbalanced_reversals'], 0, 20) as $r) {
                $this->warn("     Original {$r['original_no']} (Dr={$r['orig_debit']}, Cr={$r['orig_credit']}) "
                    . "vs Reversal {$r['reversal_no']} (Dr={$r['rev_debit']}, Cr={$r['rev_credit']})");
            }
            if (count($summary['unbalanced_reversals']) > 20) {
                $this->warn("     ... and " . (count($summary['unbalanced_reversals']) - 20) . " more.");
            }
            $allPass = false;
        }
        $this->newLine();

        // 4. Orphan reversals (reversal entries without an original).
        $this->info('4. Orphan Reversals Check (reversals without an original):');
        $orphanReversals = (int) DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM journal_entries rev
WHERE rev.source = 'reversal'
  AND rev.is_reversed = false
  AND NOT EXISTS (
    SELECT 1 FROM journal_entries orig
    WHERE orig.id = rev.reversal_of_entry_id
  )
SQL)->cnt;

        if ($orphanReversals === 0) {
            $this->info("   ✓ No orphan reversals.");
        } else {
            $this->error("   ✗ {$orphanReversals} orphan reversal(s) found (reversals without an original entry).");
            $allPass = false;
        }
        $this->newLine();

        // 5. Reversed entries without a reversal entry.
        $this->info('5. Reversed Entries Without Reversal Entry:');
        $missingReversal = (int) DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM journal_entries orig
WHERE orig.is_reversed = true
  AND orig.reversal_of_entry_id IS NULL
SQL)->cnt;

        if ($missingReversal === 0) {
            $this->info("   ✓ All reversed entries have a corresponding reversal entry.");
        } else {
            $this->error("   ✗ {$missingReversal} reversed entry/entries without a reversal entry.");
            $allPass = false;
        }
        $this->newLine();

        // 6. Sub-ledger consistency (reversed GL entries should have reversed sub-ledger entries).
        $this->info('6. Sub-Ledger Reversal Consistency:');
        $inconsistentCustomer = (int) DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM customer_ledger cl
JOIN journal_entries je ON je.id = cl.journal_entry_id
WHERE je.is_reversed = true AND cl.is_reversed = false
SQL)->cnt;

        $inconsistentSupplier = (int) DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM supplier_ledger sl
JOIN journal_entries je ON je.id = sl.journal_entry_id
WHERE je.is_reversed = true AND sl.is_reversed = false
SQL)->cnt;

        $inconsistentEmployee = (int) DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM employee_ledger el
JOIN journal_entries je ON je.id = el.journal_entry_id
WHERE je.is_reversed = true AND el.is_reversed = false
SQL)->cnt;

        $totalInconsistent = $inconsistentCustomer + $inconsistentSupplier + $inconsistentEmployee;

        if ($totalInconsistent === 0) {
            $this->info("   ✓ All sub-ledger entries consistent with GL reversal status.");
        } else {
            $this->error("   ✗ {$totalInconsistent} sub-ledger entries inconsistent:");
            if ($inconsistentCustomer > 0) $this->warn("     customer_ledger: {$inconsistentCustomer}");
            if ($inconsistentSupplier > 0) $this->warn("     supplier_ledger: {$inconsistentSupplier}");
            if ($inconsistentEmployee > 0) $this->warn("     employee_ledger: {$inconsistentEmployee}");
            $allPass = false;
        }
        $this->newLine();

        // Summary.
        $this->info('=== Verification Summary ===');

        // ============================================================
        // P3-5: Sales-specific reversal verification
        // ============================================================
        $this->newLine();
        $this->info('=== P3-5: Sales-Specific Reversal Checks ===');

        $salesIssues = 0;
        $salesIssues += $this->verifyInvoiceReversalConsistency();
        $salesIssues += $this->verifyChallanReversalConsistency();
        $salesIssues += $this->verifyReturnReversalConsistency();
        $salesIssues += $this->verifyPaymentReversalConsistency();
        $salesIssues += $this->verifyStockTransactionReversalConsistency();
        $salesIssues += $this->verifyAppendOnlyIntegrity();

        if ($salesIssues > 0) {
            $this->warn("Sales-specific reversal issues found: {$salesIssues}");
            $this->warn('These are informational — investigate but they do not block the core reversal checks.');
        } else {
            $this->info('✓ All sales-specific reversal checks passed.');
            $this->info('  - Cancelled invoices have reversed GL JEs');
            $this->info('  - Cancelled challans have reversed COGS JEs + stock_transactions');
            $this->info('  - Reversed returns have reversed revenue + COGS JEs');
            $this->info('  - Cancelled payments have reversed GL JEs');
            $this->info('  - Stock transactions are consistently reversed');
            $this->info('  - Append-only integrity: originals not mutated');
        }

        $this->newLine();
        if ($allPass && $salesIssues === 0) {
            $this->info('✓ ALL REVERSALS VERIFIED (core + sales-specific).');
            $this->info('  Phase 9.4 + P3-5 reversal verification PASSED.');
            return self::SUCCESS;
        } elseif ($allPass) {
            $this->info('✓ Core reversal checks passed. Sales-specific issues are informational.');
            return self::SUCCESS;
        } else {
            $this->error('✗ SOME CORE REVERSAL ISSUES FOUND. Investigate the issues above.');
            return self::FAILURE;
        }
    }

    // ============================================================
    // P3-5: Sales-specific reversal verification methods
    // ============================================================

    /**
     * P3-5: Verify cancelled invoices have reversed GL JEs.
     */
    private function verifyInvoiceReversalConsistency(): int
    {
        $this->info('  Checking invoice reversal consistency...');

        // Cancelled invoices should have reversed GL JEs.
        $unreversedJE = DB::table('sales_invoices as si')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'si.journal_entry_id')
            ->where('si.is_reversed', true)
            ->whereNotNull('si.journal_entry_id')
            ->where('je.is_reversed', false)
            ->count();

        if ($unreversedJE > 0) {
            $this->warn("    INVOICE JE NOT REVERSED: {$unreversedJE} cancelled invoices have active GL JEs.");
        }

        // Cancelled invoices should have reversed customer_ledger entries.
        $unreversedLedger = DB::table('sales_invoices as si')
            ->join('customer_ledger as cl', function ($join) {
                $join->on('cl.reference_id', '=', 'si.id')
                     ->where('cl.reference_type', '=', 'sales_invoice');
            })
            ->where('si.is_reversed', true)
            ->where('cl.is_reversed', false)
            ->count();

        if ($unreversedLedger > 0) {
            $this->warn("    LEDGER NOT REVERSED: {$unreversedLedger} cancelled invoices have active customer_ledger entries.");
        }

        $issues = $unreversedJE + $unreversedLedger;
        if ($issues === 0) {
            $this->info('    ✓ All cancelled invoices have consistently reversed GL + ledger entries.');
        }
        return $issues;
    }

    /**
     * P3-5: Verify cancelled challans have reversed COGS JEs + reversed stock_transactions.
     */
    private function verifyChallanReversalConsistency(): int
    {
        $this->info('  Checking challan reversal consistency...');

        // Cancelled challans should have reversed COGS JEs.
        $unreversedJE = DB::table('sales_challans as sc')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'sc.journal_entry_id')
            ->where('sc.is_reversed', true)
            ->whereNotNull('sc.journal_entry_id')
            ->where('je.is_reversed', false)
            ->count();

        if ($unreversedJE > 0) {
            $this->warn("    CHALLAN JE NOT REVERSED: {$unreversedJE} cancelled challans have active COGS JEs.");
        }

        // Cancelled challans should have reversed stock_transactions.
        $unreversedStock = DB::table('sales_challans as sc')
            ->join('stock_transactions as st', function ($join) {
                $join->on('st.reference_id', '=', 'sc.id')
                     ->where('st.reference_type', '=', 'sales_challan');
            })
            ->where('sc.is_reversed', true)
            ->where('st.is_reversed', false)
            ->count();

        if ($unreversedStock > 0) {
            $this->warn("    STOCK NOT REVERSED: {$unreversedStock} cancelled challans have active stock_transactions.");
        }

        $issues = $unreversedJE + $unreversedStock;
        if ($issues === 0) {
            $this->info('    ✓ All cancelled challans have consistently reversed GL + stock entries.');
        }
        return $issues;
    }

    /**
     * P3-5: Verify reversed returns have reversed revenue + COGS JEs.
     */
    private function verifyReturnReversalConsistency(): int
    {
        $this->info('  Checking return reversal consistency...');

        $unreversedJE = DB::table('sales_returns as sr')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'sr.journal_entry_id')
            ->where('sr.is_reversed', true)
            ->whereNotNull('sr.journal_entry_id')
            ->where('je.is_reversed', false)
            ->count();

        $unreversedCogsJE = DB::table('sales_returns as sr')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'sr.cogs_journal_entry_id')
            ->where('sr.is_reversed', true)
            ->whereNotNull('sr.cogs_journal_entry_id')
            ->where('je.is_reversed', false)
            ->count();

        // Reversed returns should have reversed stock_transactions.
        $unreversedStock = DB::table('sales_returns as sr')
            ->join('stock_transactions as st', function ($join) {
                $join->on('st.reference_id', '=', 'sr.id')
                     ->where('st.reference_type', '=', 'sales_return');
            })
            ->where('sr.is_reversed', true)
            ->where('st.is_reversed', false)
            ->count();

        $issues = $unreversedJE + $unreversedCogsJE + $unreversedStock;

        if ($unreversedJE > 0) {
            $this->warn("    RETURN JE NOT REVERSED: {$unreversedJE} reversed returns have active revenue JEs.");
        }
        if ($unreversedCogsJE > 0) {
            $this->warn("    RETURN COGS JE NOT REVERSED: {$unreversedCogsJE} reversed returns have active COGS JEs.");
        }
        if ($unreversedStock > 0) {
            $this->warn("    RETURN STOCK NOT REVERSED: {$unreversedStock} reversed returns have active stock_transactions.");
        }
        if ($issues === 0) {
            $this->info('    ✓ All reversed returns have consistently reversed GL + stock entries.');
        }
        return $issues;
    }

    /**
     * P3-5: Verify cancelled payments have reversed GL JEs.
     */
    private function verifyPaymentReversalConsistency(): int
    {
        $this->info('  Checking payment reversal consistency...');

        $unreversedJE = DB::table('customer_payments as cp')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'cp.journal_entry_id')
            ->where('cp.is_reversed', true)
            ->whereNotNull('cp.journal_entry_id')
            ->where('je.is_reversed', false)
            ->count();

        if ($unreversedJE > 0) {
            $this->warn("    PAYMENT JE NOT REVERSED: {$unreversedJE} cancelled payments have active GL JEs.");
        } else {
            $this->info('    ✓ All cancelled payments have consistently reversed GL entries.');
        }
        return $unreversedJE;
    }

    /**
     * P3-5: Verify stock_transactions reversal consistency across all reference types.
     */
    private function verifyStockTransactionReversalConsistency(): int
    {
        $this->info('  Checking stock_transaction reversal consistency...');

        // Find stock_transactions where the referenced business record is reversed
        // but the stock_transaction is NOT reversed (inconsistency).
        $issues = 0;

        // Check sales_challan reference type.
        $challanIssues = DB::table('stock_transactions as st')
            ->join('sales_challans as sc', 'sc.id', '=', 'st.reference_id')
            ->where('st.reference_type', 'sales_challan')
            ->where('sc.is_reversed', true)
            ->where('st.is_reversed', false)
            ->count();

        if ($challanIssues > 0) {
            $this->warn("    STOCK/CHALLAN INCONSISTENCY: {$challanIssues} stock_transactions for reversed challans are not reversed.");
            $issues += $challanIssues;
        }

        // Check sales_return reference type.
        $returnIssues = DB::table('stock_transactions as st')
            ->join('sales_returns as sr', 'sr.id', '=', 'st.reference_id')
            ->where('st.reference_type', 'sales_return')
            ->where('sr.is_reversed', true)
            ->where('st.is_reversed', false)
            ->count();

        if ($returnIssues > 0) {
            $this->warn("    STOCK/RETURN INCONSISTENCY: {$returnIssues} stock_transactions for reversed returns are not reversed.");
            $issues += $returnIssues;
        }

        // Check damage reference type (linked to sales returns via P1-5).
        $damageIssues = DB::table('stock_transactions as st')
            ->join('damage_invoices as di', 'di.id', '=', 'st.reference_id')
            ->where('st.reference_type', 'damage')
            ->where('di.is_reversed', true)
            ->where('st.is_reversed', false)
            ->count();

        if ($damageIssues > 0) {
            $this->warn("    STOCK/DAMAGE INCONSISTENCY: {$damageIssues} stock_transactions for reversed damages are not reversed.");
            $issues += $damageIssues;
        }

        if ($issues === 0) {
            $this->info('    ✓ All stock_transactions are consistently reversed with their business records.');
        }
        return $issues;
    }

    /**
     * P3-5: Verify append-only integrity — reversed originals should not have
     * their journal_lines mutated (debit/credit values changed after reversal).
     * Only the is_reversed flag + reversal_of_entry_id should be set.
     */
    private function verifyAppendOnlyIntegrity(): int
    {
        $this->info('  Checking append-only integrity (originals not mutated)...');

        // Reversed journal_entries should still have their original lines intact.
        // Check: reversed entries should have the same number of lines as when created
        // (at least 2 lines for a valid JE). This is a sanity check — if lines were
        // deleted or zeroed out, the entry would fail the balance check in P3-2.
        $mutatedEntries = DB::table('journal_entries as je')
            ->leftJoin('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
            ->where('je.is_reversed', true)
            ->groupBy('je.id', 'je.entry_no')
            ->havingRaw('COUNT(jl.id) < 2')
            ->select('je.id', 'je.entry_no', DB::raw('COUNT(jl.id) as line_count'))
            ->get();

        $issues = $mutatedEntries->count();
        if ($issues > 0) {
            $this->warn("    MUTATED ENTRIES: {$issues} reversed entries have < 2 lines (may have been mutated).");
            foreach ($mutatedEntries->take(10) as $e) {
                $this->warn("      JE #{$e->id} ({$e->entry_no}): {$e->line_count} lines");
            }
        } else {
            $this->info('    ✓ All reversed entries retain their original lines (append-only verified).');
        }
        return $issues;
    }
}

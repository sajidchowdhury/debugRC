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
        if ($allPass) {
            $this->info('✓ ALL REVERSALS VERIFIED. Every reversal nets to zero.');
            $this->info('  Phase 9.4 reversal verification PASSED.');
            return self::SUCCESS;
        } else {
            $this->error('✗ SOME REVERSAL ISSUES FOUND. Investigate the issues above.');
            return self::FAILURE;
        }
    }
}

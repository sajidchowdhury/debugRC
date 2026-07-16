<?php

namespace App\Console\Commands;

use App\Services\Accounting\SubLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sub-Ledger Reconciliation — Phase 9.3.
 *
 * Verifies that all 3 sub-ledgers match their GL control accounts:
 *   1. AR: customer_ledger total == GL AR control (nature: ar)
 *   2. AP: supplier_ledger total == GL AP control (nature: ap)
 *   3. Employee: employee_ledger total == GL employee_payable control
 *
 * Also checks for:
 *   - Sub-ledger entries without journal_entry_id (orphan — not linked to GL)
 *   - Sub-ledger entries referencing reversed journal entries
 *
 * Usage: php artisan subledger:reconcile
 * Exit 0 = all match, 1 = drift detected.
 */
class SubLedgerReconcile extends Command
{
    protected $signature = 'subledger:reconcile';
    protected $description = 'Reconcile sub-ledgers (AR/AP/Employee) against GL control accounts';

    public function handle(SubLedgerService $subLedgerService): int
    {
        $this->info('=== Sub-Ledger Reconciliation (Phase 9.3) ===');
        $this->info('Verifies AR/AP/Employee sub-ledgers match GL control accounts.');
        $this->newLine();

        $result = $subLedgerService->reconcileAll();

        // 1. AR reconciliation.
        $this->info('1. Accounts Receivable (AR):');
        $this->line("   Sub-ledger total: " . number_format($result['ar']['subledger'], 2));
        $this->line("   GL AR control:    " . number_format($result['ar']['gl_control'], 2));
        $this->line("   Drift:            " . number_format($result['ar']['drift'], 2));
        if ($result['ar']['match']) {
            $this->info("   ✓ MATCH");
        } else {
            $this->error("   ✗ MISMATCH");
        }
        $this->newLine();

        // 2. AP reconciliation.
        $this->info('2. Accounts Payable (AP):');
        $this->line("   Sub-ledger total: " . number_format($result['ap']['subledger'], 2));
        $this->line("   GL AP control:    " . number_format($result['ap']['gl_control'], 2));
        $this->line("   Drift:            " . number_format($result['ap']['drift'], 2));
        if ($result['ap']['match']) {
            $this->info("   ✓ MATCH");
        } else {
            $this->error("   ✗ MISMATCH");
        }
        $this->newLine();

        // 3. Employee reconciliation.
        $this->info('3. Employee Payable:');
        $this->line("   Sub-ledger total: " . number_format($result['employee']['subledger'], 2));
        $this->line("   GL control:       " . number_format($result['employee']['gl_control'], 2));
        $this->line("   Drift:            " . number_format($result['employee']['drift'], 2));
        if ($result['employee']['match']) {
            $this->info("   ✓ MATCH");
        } else {
            $this->error("   ✗ MISMATCH");
        }
        $this->newLine();

        // 4. Orphan entries (sub-ledger rows without journal_entry_id).
        $this->info('4. Orphan Sub-Ledger Entries (no journal_entry_id):');
        foreach (['customer_ledger' => 'customer', 'supplier_ledger' => 'supplier', 'employee_ledger' => 'employee'] as $table => $label) {
            $orphanCount = (int) DB::table($table)
                ->whereNull('journal_entry_id')
                ->where('is_reversed', false)
                ->count();
            if ($orphanCount === 0) {
                $this->info("   ✓ {$label}: no orphans");
            } else {
                $this->warn("   ⚠ {$label}: {$orphanCount} entries without journal_entry_id (may be pre-GL data)");
            }
        }
        $this->newLine();

        // Summary.
        $this->info('=== Reconciliation Summary ===');
        if ($result['all_match']) {
            $this->info('✓ ALL sub-ledgers match GL control accounts.');
            return self::SUCCESS;
        } else {
            $this->error('✗ Sub-ledger drift detected. Investigate before period close.');
            return self::FAILURE;
        }
    }
}

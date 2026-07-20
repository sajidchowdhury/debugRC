<?php

namespace App\Console\Commands;

use App\Services\Accounting\LedgerNatureService;
use Illuminate\Console\Command;

/**
 * Validate Chart of Accounts — Phase 9.1.
 *
 * Usage: php artisan chart:validate
 *
 * Checks:
 *   1. All 7 critical natures resolve to exactly one active ledger
 *   2. Account types are consistent with nature definitions
 *   3. Extended natures are configured (warnings if missing)
 *   4. No unknown natures on active ledgers
 *
 * Exit 0 = valid, 1 = critical issues found.
 */
class ValidateChartOfAccounts extends Command
{
    protected $signature = 'chart:validate';
    protected $description = 'Validate the chart of accounts: critical natures, account types, extended natures';

    public function handle(LedgerNatureService $natureService): int
    {
        $this->info('=== Chart of Accounts Validation ===');
        $this->newLine();

        $result = $natureService->validateChartOfAccounts();

        // Summary.
        $this->info("Critical natures: {$result['critical_resolved']}/{$result['critical_count']} resolved");
        $this->info("Total active ledgers: {$result['total_ledgers']}");
        $this->newLine();

        // Critical issues.
        if (!empty($result['critical_issues'])) {
            $this->error('CRITICAL ISSUES (' . count($result['critical_issues']) . '):');
            foreach ($result['critical_issues'] as $issue) {
                $this->warn("  [{$issue['nature']}] {$issue['message']}");
            }
            $this->newLine();
        }

        // Extended issues.
        if (!empty($result['extended_issues'])) {
            $this->error('EXTENDED ISSUES (' . count($result['extended_issues']) . '):');
            foreach ($result['extended_issues'] as $issue) {
                $this->warn("  [{$issue['nature']}] {$issue['message']}");
            }
            $this->newLine();
        }

        // Warnings.
        if (!empty($result['warnings'])) {
            $this->warn('WARNINGS (' . count($result['warnings']) . '):');
            foreach ($result['warnings'] as $warning) {
                $this->line("  [{$warning['nature']}] {$warning['message']}");
            }
            $this->newLine();
        }

        // List all natures + their ledgers.
        $this->info('--- Nature Resolution ---');
        $allNatures = LedgerNatureService::allNatures();
        foreach ($allNatures as $nature => $meta) {
            $ledgerId = $natureService->resolveLedgerByNature($nature);
            $status = $ledgerId ? "<fg=green>✓ Ledger #{$ledgerId}</>" : '<fg=red>✗ NOT FOUND</>';
            $critical = LedgerNatureService::isCritical($nature) ? '[CRITICAL]' : '[extended]';
            $this->line("  {$nature} {$critical} → {$status}");
        }
        $this->newLine();

        // Final result.
        if ($result['valid']) {
            $this->info('✓ Chart of Accounts is VALID. All critical natures resolved.');
            return self::SUCCESS;
        } else {
            $this->error('✗ Chart of Accounts has CRITICAL issues. Fix before posting.');
            $this->warn('Run `php artisan chart:seed` to seed the default chart of accounts.');
            return self::FAILURE;
        }
    }
}

<?php

namespace App\Services\Accounting;

use App\Models\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ledger Nature Service — Phase 9.1.
 *
 * Validates and manages the ledger nature system that drives the posting engine.
 *
 * Responsibilities:
 *   1. Define all ledger natures (critical + extended) with metadata
 *   2. Validate that the 7 critical natures each resolve to exactly one active ledger
 *   3. Resolve a nature to its ledger_id (used by JournalPostingService)
 *   4. Validate account_type consistency for each nature
 *   5. Provide CoA integrity checks (for the chart:validate command)
 *
 * See docs/migration/journal_posting_rules.md for the full rules document.
 */
class LedgerNatureService
{
    /**
     * The 7 critical natures — each MUST resolve to exactly one active ledger.
     * The posting engine cannot function without these.
     */
    public const CRITICAL_NATURES = [
        'cash_bank' => [
            'account_type' => 'Asset',
            'normal_balance' => 'debit',
            'description' => 'Cash and bank balances',
            'used_by' => 'Payments, transfers, money transfers',
        ],
        'ar' => [
            'account_type' => 'Asset',
            'normal_balance' => 'debit',
            'description' => 'Accounts Receivable (customers owe us)',
            'used_by' => 'Sales invoices, customer payments, sales returns',
        ],
        'ap' => [
            'account_type' => 'Liability',
            'normal_balance' => 'credit',
            'description' => 'Accounts Payable (we owe suppliers)',
            'used_by' => 'Purchase receives, supplier payments, purchase returns',
        ],
        'inventory' => [
            'account_type' => 'Asset',
            'normal_balance' => 'debit',
            'description' => 'Stock valuation at moving-average cost',
            'used_by' => 'All stock movements (receive, issue, adjust, transfer, damage)',
        ],
        'sales_revenue' => [
            'account_type' => 'Income',
            'normal_balance' => 'credit',
            'description' => 'Sales revenue',
            'used_by' => 'Sales invoice finalize',
        ],
        'cogs' => [
            'account_type' => 'Expense',
            'normal_balance' => 'debit',
            'description' => 'Cost of Goods Sold',
            'used_by' => 'Sales challan issue, sales return confirm',
        ],
        'retained_earnings' => [
            'account_type' => 'Equity',
            'normal_balance' => 'credit',
            'description' => 'Accumulated profits/losses',
            'used_by' => 'Year-end close',
        ],
    ];

    /**
     * Extended natures — used by specific posting methods.
     * These are optional but recommended for full functionality.
     */
    public const EXTENDED_NATURES = [
        'sales_return' => [
            'account_type' => 'Income',
            'normal_balance' => 'debit',
            'description' => 'Sales return (contra-revenue)',
            'used_by' => 'Sales return confirm',
        ],
        'sales_discount' => [
            'account_type' => 'Expense',
            'normal_balance' => 'debit',
            'description' => 'Sales discount allowed (contra-revenue)',
            'used_by' => 'Sales invoice with discount',
        ],
        'transport_revenue' => [
            'account_type' => 'Income',
            'normal_balance' => 'credit',
            'description' => 'Transport revenue',
            'used_by' => 'Sales invoice with transport cost',
        ],
        'inventory_shrinkage' => [
            'account_type' => 'Expense',
            'normal_balance' => 'debit',
            'description' => 'Inventory loss (shrinkage, adjustment decrease)',
            'used_by' => 'Stock adjustment decrease, stock take loss, damage',
        ],
        'inventory_surplus' => [
            'account_type' => 'Income',
            'normal_balance' => 'credit',
            'description' => 'Inventory gain (surplus, adjustment increase)',
            'used_by' => 'Stock adjustment increase, stock take gain',
        ],
        'damage_loss' => [
            'account_type' => 'Expense',
            'normal_balance' => 'debit',
            'description' => 'Damage write-off (falls back to inventory_shrinkage)',
            'used_by' => 'Damage confirm',
        ],
        'employee_payable' => [
            'account_type' => 'Liability',
            'normal_balance' => 'credit',
            'description' => 'Employee payable (advances, salary)',
            'used_by' => 'Employee transactions',
        ],
        'interbranch_receivable' => [
            'account_type' => 'Asset',
            'normal_balance' => 'debit',
            'description' => 'Due from Branches (intercompany)',
            'used_by' => 'Cross-branch transfers, intercompany settlement',
        ],
        'interbranch_payable' => [
            'account_type' => 'Liability',
            'normal_balance' => 'credit',
            'description' => 'Due to Branches (intercompany)',
            'used_by' => 'Cross-branch transfers, intercompany settlement',
        ],
        'other_income' => [
            'account_type' => 'Income',
            'normal_balance' => 'credit',
            'description' => 'Other income',
            'used_by' => 'Other income entries',
        ],
        'operating_expense' => [
            'account_type' => 'Expense',
            'normal_balance' => 'debit',
            'description' => 'Operating expense',
            'used_by' => 'Other expense entries',
        ],
        'salary_expense' => [
            'account_type' => 'Expense',
            'normal_balance' => 'debit',
            'description' => 'Salary expense',
            'used_by' => 'Employee salary postings',
        ],
        'write_off' => [
            'account_type' => 'Expense',
            'normal_balance' => 'debit',
            'description' => 'Bad debt write-off (uncollectable AR)',
            'used_by' => 'Customer payment write-off transaction',
        ],
        'finance_cost' => [
            'account_type' => 'Expense',
            'normal_balance' => 'debit',
            'description' => 'Bank charges, interest',
            'used_by' => 'Financial expense entries',
        ],
        // Phase 8: Elimination natures for consolidation
        'elimination_receivable' => [
            'account_type' => 'Asset',
            'normal_balance' => 'debit',
            'description' => 'Elimination receivable (contra to interbranch_receivable)',
            'used_by' => 'Consolidation elimination — balance sheet',
        ],
        'elimination_payable' => [
            'account_type' => 'Liability',
            'normal_balance' => 'credit',
            'description' => 'Elimination payable (contra to interbranch_payable)',
            'used_by' => 'Consolidation elimination — balance sheet',
        ],
        'elimination_revenue' => [
            'account_type' => 'Equity',
            'normal_balance' => 'credit',
            'description' => 'Elimination revenue (contra to intercompany revenue)',
            'used_by' => 'Consolidation elimination — income statement',
        ],
        'elimination_cogs' => [
            'account_type' => 'Equity',
            'normal_balance' => 'debit',
            'description' => 'Elimination COGS (contra to intercompany COGS)',
            'used_by' => 'Consolidation elimination — income statement',
        ],
        'elimination_investment' => [
            'account_type' => 'Equity',
            'normal_balance' => 'credit',
            'description' => 'Elimination investment (contra to investment in subsidiary)',
            'used_by' => 'Consolidation elimination — investment',
        ],
    ];

    /**
     * All natures (critical + extended).
     */
    public static function allNatures(): array
    {
        return array_merge(self::CRITICAL_NATURES, self::EXTENDED_NATURES);
    }

    /**
     * Validate the entire chart of accounts.
     * Returns an array of issues (empty = valid).
     *
     * @return array{ valid: bool, critical_issues: array, extended_issues: array, warnings: array }
     */
    public function validateChartOfAccounts(): array
    {
        $criticalIssues = [];
        $extendedIssues = [];
        $warnings = [];

        // Check each critical nature.
        foreach (self::CRITICAL_NATURES as $nature => $meta) {
            $count = Ledger::active()->where('ledger_nature', $nature)->count();

            if ($count === 0) {
                $criticalIssues[] = [
                    'nature' => $nature,
                    'issue' => 'missing',
                    'message' => "Critical nature '{$nature}' has no active ledger. "
                        . "Expected: {$meta['account_type']} ({$meta['description']}). "
                        . "Used by: {$meta['used_by']}.",
                ];
            } elseif ($count > 1) {
                $criticalIssues[] = [
                    'nature' => $nature,
                    'issue' => 'multiple',
                    'message' => "Critical nature '{$nature}' has {$count} active ledgers. "
                        . "Exactly one is required. Ambiguous — the posting engine cannot determine which to use.",
                ];
            } else {
                // Validate account_type consistency.
                $ledger = Ledger::active()->where('ledger_nature', $nature)->first();
                if ($ledger && $ledger->account_type !== $meta['account_type']) {
                    $criticalIssues[] = [
                        'nature' => $nature,
                        'issue' => 'wrong_type',
                        'message' => "Nature '{$nature}' ledger '{$ledger->ledger_name}' has account_type "
                            . "'{$ledger->account_type}' but should be '{$meta['account_type']}'.",
                    ];
                }
            }
        }

        // Check extended natures (warnings, not errors).
        foreach (self::EXTENDED_NATURES as $nature => $meta) {
            $count = Ledger::active()->where('ledger_nature', $nature)->count();

            if ($nature === 'damage_loss' && $count === 0) {
                // damage_loss falls back to inventory_shrinkage — just a warning.
                $shrinkageCount = Ledger::active()->where('ledger_nature', 'inventory_shrinkage')->count();
                if ($shrinkageCount > 0) {
                    $warnings[] = [
                        'nature' => $nature,
                        'message' => "Nature '{$nature}' has no ledger. Will fall back to 'inventory_shrinkage'.",
                    ];
                } else {
                    $extendedIssues[] = [
                        'nature' => $nature,
                        'issue' => 'missing',
                        'message' => "Extended nature '{$nature}' has no active ledger AND fallback 'inventory_shrinkage' is also missing.",
                    ];
                }
            } elseif ($count === 0) {
                $warnings[] = [
                    'nature' => $nature,
                    'message' => "Extended nature '{$nature}' has no active ledger. "
                        . "Some posting methods may fail. Used by: {$meta['used_by']}.",
                ];
            } elseif ($count > 1) {
                $warnings[] = [
                    'nature' => $nature,
                    'message' => "Extended nature '{$nature}' has {$count} active ledgers. "
                        . "Using the first one. Consider consolidating.",
                ];
            }
        }

        // Check for ledgers with unknown natures.
        $knownNatures = array_keys(self::allNatures());
        $unknownNatureLedgers = Ledger::active()
            ->whereNotNull('ledger_nature')
            ->whereNotIn('ledger_nature', $knownNatures)
            ->get();

        foreach ($unknownNatureLedgers as $ledger) {
            $warnings[] = [
                'nature' => $ledger->ledger_nature,
                'message' => "Ledger '{$ledger->ledger_name}' ({$ledger->ledger_code}) has unknown nature '{$ledger->ledger_nature}'. "
                    . "Not recognized by the posting engine.",
            ];
        }

        return [
            'valid' => empty($criticalIssues),
            'critical_issues' => $criticalIssues,
            'extended_issues' => $extendedIssues,
            'warnings' => $warnings,
            'critical_count' => count(self::CRITICAL_NATURES),
            'critical_resolved' => count(self::CRITICAL_NATURES) - count($criticalIssues),
            'total_ledgers' => Ledger::active()->count(),
        ];
    }

    /**
     * Resolve a nature to its ledger_id.
     * For critical natures, returns the single active ledger.
     * For extended natures, returns the first active ledger (or null).
     *
     * @param string $nature
     * @return int|null
     */
    public function resolveLedgerByNature(string $nature): ?int
    {
        // Critical natures: must resolve to exactly one.
        if (isset(self::CRITICAL_NATURES[$nature])) {
            $ledger = Ledger::active()->where('ledger_nature', $nature)->first();
            return $ledger ? (int) $ledger->id : null;
        }

        // damage_loss falls back to inventory_shrinkage.
        if ($nature === 'damage_loss') {
            $ledger = Ledger::active()->where('ledger_nature', 'damage_loss')->first();
            if (!$ledger) {
                $ledger = Ledger::active()->where('ledger_nature', 'inventory_shrinkage')->first();
            }
            return $ledger ? (int) $ledger->id : null;
        }

        // Extended natures: first active ledger.
        $ledger = Ledger::active()->where('ledger_nature', $nature)->first();
        return $ledger ? (int) $ledger->id : null;
    }

    /**
     * Get the expected account_type for a nature.
     */
    public static function getExpectedAccountType(string $nature): ?string
    {
        $all = self::allNatures();
        return $all[$nature]['account_type'] ?? null;
    }

    /**
     * Get the normal balance for a nature ('debit' or 'credit').
     */
    public static function getNormalBalance(string $nature): ?string
    {
        $all = self::allNatures();
        return $all[$nature]['normal_balance'] ?? null;
    }

    /**
     * Check if a nature is critical.
     */
    public static function isCritical(string $nature): bool
    {
        return isset(self::CRITICAL_NATURES[$nature]);
    }
}

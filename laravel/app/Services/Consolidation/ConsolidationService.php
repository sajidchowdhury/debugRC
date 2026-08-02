<?php

namespace App\Services\Consolidation;

use App\Models\Company;
use App\Models\ConsolidationRun;
use App\Models\EliminationEntry;
use App\Models\EliminationRule;
use App\Models\Accounting\JournalEntry;
use App\Models\Accounting\JournalLine;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\DocumentSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ConsolidationService — Phase 8: Intercompany & Consolidation
 *
 * Handles the full consolidation lifecycle:
 *   1. Run consolidation — calculate elimination entries for a period
 *   2. Post consolidation — create elimination journal entries
 *   3. Reverse consolidation — reverse elimination journal entries
 *
 * Consolidation Process:
 *   ┌──────────────────────────────────────────────────────────────────┐
 *   │ Step 1: Calculate intercompany balances per branch pair         │
 *   │ Step 2: Apply elimination rules to determine eliminations       │
 *   │ Step 3: Create elimination_entries records (draft)              │
 *   │ Step 4: Post elimination journal entries (when user confirms)   │
 *   │ Step 5: Refresh materialized views for consolidated reporting   │
 *   └──────────────────────────────────────────────────────────────────┘
 *
 * Elimination Logic:
 *   For each active elimination rule:
 *     - Calculate the total debit balance of the debit_ledger across all branches
 *     - Calculate the total credit balance of the credit_ledger across all branches
 *     - The elimination amount is the lesser of the two (they should be equal
 *       in a balanced system, but we take the lesser to avoid over-elimination)
 *     - Create an elimination entry that:
 *       - Credits the debit_ledger (reduces the receivable)
 *       - Debits the credit_ledger (reduces the payable)
 *       - Or uses the elimination contra accounts if specified
 *
 * Per-branch-pair elimination:
 *   For balance-type rules, we also calculate per-branch-pair elimination
 *   from the branch_ledger table, which provides more granular detail.
 *
 * Design Notes:
 *   - All amounts are in BDT (single currency, no FX translation)
 *   - Elimination journal entries use source='elimination' for easy filtering
 *   - Each consolidation run is auditable and reversible
 *   - The system currently has one legal entity (RC Group) with multiple branches,
 *     so the primary use case is inter-branch elimination. Multi-entity
 *     consolidation (with minority interest) is supported by the schema but
 *     not yet by the posting engine.
 */
class ConsolidationService
{
    public function __construct(
        private JournalPostingService $journalPosting,
        private DocumentSequenceService $docSequence,
    ) {}

    // ── Run Consolidation ──────────────────────────────────────────

    /**
     * Run a consolidation for a given period.
     *
     * Creates a ConsolidationRun record and calculates elimination entries
     * based on active elimination rules. The run is initially in 'draft' status.
     *
     * @param array $data { name, period_from, period_to, fiscal_year_id?, company_ids?, notes?, created_by }
     * @return ConsolidationRun
     */
    public function runConsolidation(array $data): ConsolidationRun
    {
        return DB::transaction(function () use ($data) {
            // Generate run code
            $runCode = $this->docSequence->next('CONS', $data['created_by']);

            $run = ConsolidationRun::create([
                'run_code'      => $runCode,
                'name'          => $data['name'],
                'period_from'   => $data['period_from'],
                'period_to'     => $data['period_to'],
                'status'        => 'draft',
                'fiscal_year_id' => $data['fiscal_year_id'] ?? null,
                'company_ids'   => $data['company_ids'] ?? null,
                'notes'         => $data['notes'] ?? null,
                'created_by'    => $data['created_by'],
            ]);

            // Calculate and create elimination entries
            $eliminationEntries = $this->calculateEliminations($run);

            // Update summary
            $run->update([
                'elimination_summary' => $this->buildSummary($eliminationEntries),
            ]);

            return $run->fresh('eliminationEntries');
        });
    }

    /**
     * Calculate elimination entries for a consolidation run.
     *
     * For each active elimination rule:
     *   1. Get the total debit balance of the debit_ledger in the period
     *   2. Get the total credit balance of the credit_ledger in the period
     *   3. The elimination amount is the lesser of the two
     *   4. For balance-type rules, also calculate per-branch-pair elimination
     *
     * @param ConsolidationRun $run
     * @return \Illuminate\Support\Collection
     */
    private function calculateEliminations(ConsolidationRun $run): \Illuminate\Support\Collection
    {
        $rules = EliminationRule::active()->orderBy('sort_order')->get();
        $entries = collect();

        foreach ($rules as $rule) {
            if ($rule->rule_type === 'balance') {
                // Balance-type: calculate per-branch-pair elimination from branch_ledger
                $branchPairEntries = $this->calculateBalanceElimination($run, $rule);
                $entries = $entries->merge($branchPairEntries);
            } else {
                // Revenue/Investment/Dividend/Custom: calculate aggregate elimination
                $aggregateEntries = $this->calculateAggregateElimination($run, $rule);
                $entries = $entries->merge($aggregateEntries);
            }
        }

        return $entries;
    }

    /**
     * Calculate balance-type elimination per branch pair.
     *
     * Uses the branch_ledger to find outstanding intercompany balances
     * between each branch pair and creates elimination entries.
     */
    private function calculateBalanceElimination(ConsolidationRun $run, EliminationRule $rule): \Illuminate\Support\Collection
    {
        $entries = collect();

        // Get outstanding intercompany balances per branch pair from branch_ledger
        $branchPairs = DB::select("
            SELECT
                from_branch_id,
                to_branch_id,
                SUM(debit) - SUM(credit) AS net_balance
            FROM branch_ledger
            WHERE transaction_date BETWEEN ? AND ?
              AND is_reversed = false
            GROUP BY from_branch_id, to_branch_id
            HAVING SUM(debit) - SUM(credit) != 0
            ORDER BY from_branch_id, to_branch_id
        ", [$run->period_from, $run->period_to]);

        foreach ($branchPairs as $pair) {
            $netBalance = (float) $pair->net_balance;

            if (abs($netBalance) < 0.01) {
                continue; // Skip zero balances
            }

            // The elimination amount: the net balance between the two branches
            // For a positive net_balance, the from_branch owes the to_branch
            // We eliminate this by:
            //   Dr interbranch_payable (L-0303) / Cr interbranch_receivable (L-0105)
            //   Or using elimination contra accounts if specified
            $eliminationAmount = abs($netBalance);

            $entry = EliminationEntry::create([
                'consolidation_run_id' => $run->id,
                'elimination_rule_id' => $rule->id,
                'journal_entry_id' => null, // Will be set when posted
                'from_branch_id' => $pair->from_branch_id,
                'to_branch_id' => $pair->to_branch_id,
                'debit_ledger_id' => $rule->credit_ledger_id,  // Dr payable (reduce liability)
                'credit_ledger_id' => $rule->debit_ledger_id,  // Cr receivable (reduce asset)
                'elimination_amount' => $eliminationAmount,
                'description' => "Elimination of intercompany balance: "
                    . "Branch {$pair->from_branch_id} ↔ Branch {$pair->to_branch_id}, "
                    . "Amount: " . number_format($eliminationAmount, 2),
            ]);

            $entries->push($entry);
        }

        return $entries;
    }

    /**
     * Calculate aggregate elimination for revenue/investment/dividend/custom rules.
     *
     * For these rule types, we look at the total debit/credit balance of
     * the specified ledgers across all branches in the period.
     */
    private function calculateAggregateElimination(ConsolidationRun $run, EliminationRule $rule): \Illuminate\Support\Collection
    {
        $entries = collect();

        // Get total debit balance of the debit ledger
        $debitTotal = DB::selectOne("
            SELECT COALESCE(SUM(jl.debit), 0) - COALESCE(SUM(jl.credit), 0) AS net_balance
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.journal_entry_id
            WHERE jl.ledger_id = ?
              AND je.entry_date BETWEEN ? AND ?
              AND COALESCE(je.is_reversed, false) = false
              AND je.source != 'elimination'
        ", [$rule->debit_ledger_id, $run->period_from, $run->period_to]);

        // Get total credit balance of the credit ledger
        $creditTotal = DB::selectOne("
            SELECT COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0) AS net_balance
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.journal_entry_id
            WHERE jl.ledger_id = ?
              AND je.entry_date BETWEEN ? AND ?
              AND COALESCE(je.is_reversed, false) = false
              AND je.source != 'elimination'
        ", [$rule->credit_ledger_id, $run->period_from, $run->period_to]);

        $debitNet = abs((float) ($debitTotal->net_balance ?? 0));
        $creditNet = abs((float) ($creditTotal->net_balance ?? 0));

        // The elimination amount is the lesser of the two
        $eliminationAmount = min($debitNet, $creditNet);

        if ($eliminationAmount < 0.01) {
            return $entries; // Nothing to eliminate
        }

        $entry = EliminationEntry::create([
            'consolidation_run_id' => $run->id,
            'elimination_rule_id' => $rule->id,
            'journal_entry_id' => null,
            'from_branch_id' => null,
            'to_branch_id' => null,
            'debit_ledger_id' => $rule->debit_ledger_id,
            'credit_ledger_id' => $rule->credit_ledger_id,
            'elimination_amount' => $eliminationAmount,
            'description' => "Elimination of {$rule->rule_name}: "
                . "Amount: " . number_format($eliminationAmount, 2),
        ]);

        $entries->push($entry);

        return $entries;
    }

    /**
     * Build the elimination summary for a consolidation run.
     */
    private function buildSummary(\Illuminate\Support\Collection $entries): array
    {
        $byRuleType = $entries->groupBy(function ($entry) {
            return $entry->eliminationRule?->rule_type ?? 'unknown';
        });

        $summary = [];
        foreach ($byRuleType as $type => $typeEntries) {
            $summary[$type] = [
                'count' => $typeEntries->count(),
                'total_amount' => $typeEntries->sum('elimination_amount'),
            ];
        }

        $summary['total_entries'] = $entries->count();
        $summary['total_amount'] = $entries->sum('elimination_amount');

        return $summary;
    }

    // ── Post Consolidation ─────────────────────────────────────────

    /**
     * Post a draft consolidation run — creates elimination journal entries.
     *
     * For each elimination entry:
     *   1. Create a journal entry with source='elimination'
     *   2. Create journal lines for the elimination
     *   3. Link the journal entry to the elimination entry
     *
     * @param ConsolidationRun $run
     * @param int $postedBy
     * @return ConsolidationRun
     */
    public function postConsolidation(ConsolidationRun $run, int $postedBy): ConsolidationRun
    {
        if (!$run->isDraft()) {
            throw new \RuntimeException("Only draft consolidation runs can be posted. Current status: {$run->status}");
        }

        if ($run->eliminationEntries()->count() === 0) {
            throw new \RuntimeException("No elimination entries found for this consolidation run. Nothing to post.");
        }

        return DB::transaction(function () use ($run, $postedBy) {
            foreach ($run->eliminationEntries as $entry) {
                $this->postEliminationEntry($entry, $run, $postedBy);
            }

            // Update run status
            $run->update([
                'status' => 'posted',
                'posted_by' => $postedBy,
                'posted_at' => now(),
            ]);

            // Refresh materialized views
            $this->refreshMaterializedViews();

            Log::info('Consolidation run posted', [
                'run_id' => $run->id,
                'run_code' => $run->run_code,
                'posted_by' => $postedBy,
                'entry_count' => $run->eliminationEntries()->count(),
            ]);

            return $run->fresh();
        });
    }

    /**
     * Post a single elimination entry as a journal entry.
     *
     * Creates a journal entry with source='elimination' and two journal lines:
     *   - Debit the credit_ledger (reduces the payable/revenue)
     *   - Credit the debit_ledger (reduces the receivable/asset)
     *
     * Or if elimination contra accounts are specified:
     *   - Debit the elimination_debit_ledger
     *   - Credit the elimination_credit_ledger
     */
    private function postEliminationEntry(EliminationEntry $entry, ConsolidationRun $run, int $postedBy): void
    {
        $rule = $entry->eliminationRule;

        // Determine which ledgers to use for the elimination entry
        // If elimination contra accounts are specified, use those; otherwise use the originals
        $debitLedgerId = $rule->elimination_debit_ledger_id ?? $entry->credit_ledger_id;
        $creditLedgerId = $rule->elimination_credit_ledger_id ?? $entry->debit_ledger_id;

        // Determine the branch for the journal entry
        // For balance-type eliminations, we use the from_branch
        // For aggregate eliminations, we use the consolidation parent's branch (or null)
        $branchId = $entry->from_branch_id;

        // If no from_branch_id, try to find the consolidation parent's branch
        if (!$branchId) {
            $parentCompany = Company::consolidationParent()->first();
            if ($parentCompany) {
                $branchId = $parentCompany->branches()->first()?->id;
            }
        }

        // Create the journal entry
        $entryNo = $this->docSequence->next('JE', $postedBy);

        $je = JournalEntry::create([
            'entry_no' => $entryNo,
            'entry_date' => $run->period_to,
            'reference_type' => 'consolidation_elimination',
            'reference_id' => $run->id,
            'branch_id' => $branchId,
            'description' => $entry->description . " [Run: {$run->run_code}]",
            'source' => 'elimination',
            'created_by' => $postedBy,
        ]);

        // Create journal lines
        JournalLine::create([
            'journal_entry_id' => $je->id,
            'ledger_id' => $debitLedgerId,
            'debit' => $entry->elimination_amount,
            'credit' => 0,
            'memo' => "Elimination: {$rule->rule_name}",
        ]);

        JournalLine::create([
            'journal_entry_id' => $je->id,
            'ledger_id' => $creditLedgerId,
            'debit' => 0,
            'credit' => $entry->elimination_amount,
            'memo' => "Elimination: {$rule->rule_name}",
        ]);

        // Link the journal entry to the elimination entry
        $entry->update(['journal_entry_id' => $je->id]);
    }

    // ── Reverse Consolidation ──────────────────────────────────────

    /**
     * Reverse a posted consolidation run.
     *
     * Reverses all elimination journal entries and marks the run as 'reversed'.
     *
     * @param ConsolidationRun $run
     * @param int $reversedBy
     * @param string $reason
     * @return ConsolidationRun
     */
    public function reverseConsolidation(ConsolidationRun $run, int $reversedBy, string $reason): ConsolidationRun
    {
        if (!$run->isPosted()) {
            throw new \RuntimeException("Only posted consolidation runs can be reversed. Current status: {$run->status}");
        }

        return DB::transaction(function () use ($run, $reversedBy, $reason) {
            // Reverse each elimination journal entry
            foreach ($run->eliminationEntries as $entry) {
                if ($entry->journal_entry_id) {
                    $this->reverseEliminationJournal($entry->journalEntry, $reversedBy, $reason);
                }
            }

            // Update run status
            $run->update([
                'status' => 'reversed',
                'reversed_by' => $reversedBy,
                'reversed_at' => now(),
                'reverse_reason' => $reason,
            ]);

            // Refresh materialized views
            $this->refreshMaterializedViews();

            Log::warning('Consolidation run reversed', [
                'run_id' => $run->id,
                'run_code' => $run->run_code,
                'reversed_by' => $reversedBy,
                'reason' => $reason,
            ]);

            return $run->fresh();
        });
    }

    /**
     * Reverse a single elimination journal entry.
     *
     * Creates a reversal journal entry with swapped Dr/Cr.
     */
    private function reverseEliminationJournal(JournalEntry $original, int $reversedBy, string $reason): void
    {
        if ($original->is_reversed) {
            return; // Already reversed
        }

        $reversalNo = $this->docSequence->next('JE', $reversedBy);

        $reversal = JournalEntry::create([
            'entry_no' => $reversalNo,
            'entry_date' => $original->entry_date,
            'reference_type' => 'consolidation_reversal',
            'reference_id' => $original->id,
            'branch_id' => $original->branch_id,
            'description' => "Reversal of elimination entry {$original->entry_no}: {$reason}",
            'source' => 'elimination',
            'is_reversed' => false,
            'reversal_of_entry_id' => $original->id,
            'reversed_by' => $reversedBy,
            'reverse_reason' => $reason,
            'created_by' => $reversedBy,
        ]);

        // Create reversal lines (swap Dr/Cr)
        foreach ($original->lines as $line) {
            JournalLine::create([
                'journal_entry_id' => $reversal->id,
                'ledger_id' => $line->ledger_id,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'memo' => "Reversal: " . ($line->memo ?? ''),
            ]);
        }

        // Mark original as reversed
        $original->update([
            'is_reversed' => true,
            'reversed_at' => now(),
            'reversed_by' => $reversedBy,
            'reverse_reason' => $reason,
        ]);
    }

    // ── Consolidated Reporting ─────────────────────────────────────

    /**
     * Get the consolidated trial balance for a period.
     *
     * Returns the aggregated trial balance across all branches,
     * with elimination adjustments applied.
     *
     * @param string $fromDate
     * @param string $toDate
     * @param int|null $companyId Filter by company (null = all companies)
     * @return array
     */
    public function getConsolidatedTrialBalance(string $fromDate, string $toDate, ?int $companyId = null): array
    {
        // Build the branch filter based on company
        $branchFilter = '';
        $params = [$fromDate, $toDate, $fromDate, $toDate, $fromDate, $toDate];

        if ($companyId) {
            $branchIds = Company::find($companyId)?->branches()->pluck('id')->toArray() ?? [];
            if (!empty($branchIds)) {
                $placeholders = implode(',', array_fill(0, count($branchIds), '?'));
                $branchFilter = "AND je.branch_id IN ({$placeholders})";
                $params = array_merge([$fromDate, $toDate, $fromDate, $toDate, $fromDate, $toDate], $branchIds);
            }
        }

        $sql = <<<SQL
SELECT
    l.id AS ledger_id,
    l.ledger_code,
    l.ledger_name,
    l.account_type,
    l.ledger_nature,
    l.normal_balance,
    l.is_elimination,
    -- Aggregate across all branches
    COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.debit ELSE 0 END), 0) AS total_debit,
    COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.credit ELSE 0 END), 0) AS total_credit,
    -- Elimination adjustments (from posted consolidation runs)
    COALESCE(elim.elim_debit, 0) AS elimination_debit,
    COALESCE(elim.elim_credit, 0) AS elimination_credit,
    -- Consolidated (net of elimination)
    COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.debit ELSE 0 END), 0) - COALESCE(elim.elim_debit, 0) AS consolidated_debit,
    COALESCE(SUM(CASE WHEN je.entry_date <= ? THEN jl.credit ELSE 0 END), 0) - COALESCE(elim.elim_credit, 0) AS consolidated_credit
FROM ledgers l
LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
    AND COALESCE(je.is_reversed, false) = false
    AND je.source != 'elimination'
    {$branchFilter}
LEFT JOIN LATERAL (
    SELECT
        SUM(elim_jl.debit) AS elim_debit,
        SUM(elim_jl.credit) AS elim_credit
    FROM elimination_entries ee
    JOIN consolidation_runs cr ON cr.id = ee.consolidation_run_id
    JOIN journal_entries elim_je ON elim_je.id = ee.journal_entry_id
    JOIN journal_lines elim_jl ON elim_jl.journal_entry_id = elim_je.id
    WHERE elim_jl.ledger_id = l.id
      AND cr.status = 'posted'
      AND cr.period_to <= ?
      AND COALESCE(elim_je.is_reversed, false) = false
) elim ON TRUE
WHERE l.is_active = true
  AND l.deleted_at IS NULL
  AND l.is_elimination = false
GROUP BY l.id, l.ledger_code, l.ledger_name, l.account_type, l.ledger_nature,
         l.normal_balance, l.is_elimination, elim.elim_debit, elim.elim_credit
HAVING COALESCE(SUM(jl.debit), 0) != 0 OR COALESCE(SUM(jl.credit), 0) != 0
     OR COALESCE(elim.elim_debit, 0) != 0 OR COALESCE(elim.elim_credit, 0) != 0
ORDER BY l.account_type, l.ledger_code
SQL;

        $params[] = $toDate; // For the elimination period filter

        $rows = collect(DB::select($sql, $params));

        // Calculate totals
        $totals = [
            'total_debit' => $rows->sum('total_debit'),
            'total_credit' => $rows->sum('total_credit'),
            'elimination_debit' => $rows->sum('elimination_debit'),
            'elimination_credit' => $rows->sum('elimination_credit'),
            'consolidated_debit' => $rows->sum('consolidated_debit'),
            'consolidated_credit' => $rows->sum('consolidated_credit'),
        ];

        return [
            'meta' => [
                'title' => 'Consolidated Trial Balance',
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'company_id' => $companyId,
            ],
            'data' => $rows,
            'totals' => $totals,
            'checks' => [
                [
                    'label' => 'Total Debits = Total Credits (Pre-Elimination)',
                    'passed' => abs($totals['total_debit'] - $totals['total_credit']) < 0.01,
                    'detail' => "Debit: " . number_format($totals['total_debit'], 2)
                        . " / Credit: " . number_format($totals['total_credit'], 2),
                ],
                [
                    'label' => 'Consolidated Debits = Consolidated Credits (Post-Elimination)',
                    'passed' => abs($totals['consolidated_debit'] - $totals['consolidated_credit']) < 0.01,
                    'detail' => "Debit: " . number_format($totals['consolidated_debit'], 2)
                        . " / Credit: " . number_format($totals['consolidated_credit'], 2),
                ],
            ],
        ];
    }

    /**
     * Get the consolidated balance sheet for a given date.
     *
     * @param string $asOfDate
     * @param int|null $companyId
     * @return array
     */
    public function getConsolidatedBalanceSheet(string $asOfDate, ?int $companyId = null): array
    {
        $tb = $this->getConsolidatedTrialBalance($asOfDate, $asOfDate, $companyId);

        $assets = $tb['data']->where('account_type', 'Asset');
        $liabilities = $tb['data']->where('account_type', 'Liability');
        $equity = $tb['data']->where('account_type', 'Equity');

        $totalAssets = $assets->sum('consolidated_debit') - $assets->sum('consolidated_credit');
        $totalLiabilities = $liabilities->sum('consolidated_credit') - $liabilities->sum('consolidated_debit');
        $totalEquity = $equity->sum('consolidated_credit') - $equity->sum('consolidated_debit');

        return [
            'meta' => [
                'title' => 'Consolidated Balance Sheet',
                'as_of_date' => $asOfDate,
                'company_id' => $companyId,
            ],
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'totals' => [
                'total_assets' => $totalAssets,
                'total_liabilities' => $totalLiabilities,
                'total_equity' => $totalEquity,
                'total_liabilities_and_equity' => $totalLiabilities + $totalEquity,
            ],
            'checks' => [
                [
                    'label' => 'Assets = Liabilities + Equity',
                    'passed' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.01,
                    'detail' => "Assets: " . number_format($totalAssets, 2)
                        . " / L+E: " . number_format($totalLiabilities + $totalEquity, 2),
                ],
            ],
        ];
    }

    /**
     * Get the consolidated profit & loss statement for a period.
     *
     * @param string $fromDate
     * @param string $toDate
     * @param int|null $companyId
     * @return array
     */
    public function getConsolidatedProfitAndLoss(string $fromDate, string $toDate, ?int $companyId = null): array
    {
        $branchFilter = '';
        $params = [$fromDate, $toDate];

        if ($companyId) {
            $branchIds = Company::find($companyId)?->branches()->pluck('id')->toArray() ?? [];
            if (!empty($branchIds)) {
                $placeholders = implode(',', array_fill(0, count($branchIds), '?'));
                $branchFilter = "AND je.branch_id IN ({$placeholders})";
                $params = array_merge([$fromDate, $toDate], $branchIds);
            }
        }

        $sql = <<<SQL
SELECT
    l.id AS ledger_id,
    l.ledger_code,
    l.ledger_name,
    l.account_type,
    l.ledger_nature,
    l.normal_balance,
    COALESCE(SUM(jl.debit), 0) AS total_debit,
    COALESCE(SUM(jl.credit), 0) AS total_credit
FROM ledgers l
LEFT JOIN journal_lines jl ON jl.ledger_id = l.id
LEFT JOIN journal_entries je ON je.id = jl.journal_entry_id
    AND je.entry_date BETWEEN ? AND ?
    AND COALESCE(je.is_reversed, false) = false
    AND je.source != 'elimination'
    {$branchFilter}
WHERE l.is_active = true
  AND l.deleted_at IS NULL
  AND l.is_elimination = false
  AND l.account_type IN ('Income', 'Expense')
GROUP BY l.id, l.ledger_code, l.ledger_name, l.account_type, l.ledger_nature, l.normal_balance
HAVING COALESCE(SUM(jl.debit), 0) != 0 OR COALESCE(SUM(jl.credit), 0) != 0
ORDER BY l.account_type, l.ledger_code
SQL;

        $rows = collect(DB::select($sql, $params));

        $income = $rows->where('account_type', 'Income');
        $expense = $rows->where('account_type', 'Expense');

        $totalIncome = $income->sum('total_credit') - $income->sum('total_debit');
        $totalExpense = $expense->sum('total_debit') - $expense->sum('total_credit');
        $netIncome = $totalIncome - $totalExpense;

        return [
            'meta' => [
                'title' => 'Consolidated Profit & Loss',
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'company_id' => $companyId,
            ],
            'income' => $income,
            'expense' => $expense,
            'totals' => [
                'total_income' => $totalIncome,
                'total_expense' => $totalExpense,
                'net_income' => $netIncome,
            ],
        ];
    }

    /**
     * Get the intercompany reconciliation summary.
     *
     * Shows the due-from/due-to balances between all branch pairs,
     * highlighting any imbalances that need to be resolved before
     * consolidation can proceed.
     */
    public function getIntercompanyReconciliation(string $asOfDate): array
    {
        $sql = <<<SQL
SELECT
    bl.from_branch_id,
    bl.to_branch_id,
    fb.branch_name AS from_branch_name,
    tb.branch_name AS to_branch_name,
    COALESCE(SUM(CASE WHEN NOT bl.is_reversed THEN bl.debit ELSE 0 END), 0) AS total_debit,
    COALESCE(SUM(CASE WHEN NOT bl.is_reversed THEN bl.credit ELSE 0 END), 0) AS total_credit,
    COALESCE(SUM(CASE WHEN NOT bl.is_reversed THEN bl.debit - bl.credit ELSE 0 END), 0) AS net_balance,
    COUNT(CASE WHEN NOT bl.is_reversed THEN 1 END) AS active_entry_count
FROM branch_ledger bl
INNER JOIN branches fb ON fb.id = bl.from_branch_id
INNER JOIN branches tb ON tb.id = bl.to_branch_id
WHERE bl.transaction_date <= ?
GROUP BY bl.from_branch_id, bl.to_branch_id, fb.branch_name, tb.branch_name
HAVING COALESCE(SUM(CASE WHEN NOT bl.is_reversed THEN bl.debit - bl.credit ELSE 0 END), 0) != 0
ORDER BY fb.branch_name, tb.branch_name
SQL;

        $rows = collect(DB::select($sql, [$asOfDate]));

        // Calculate total intercompany balances
        $totalDueFrom = $rows->filter(fn($r) => $r->net_balance > 0)->sum('net_balance');
        $totalDueTo = abs($rows->filter(fn($r) => $r->net_balance < 0)->sum('net_balance'));

        return [
            'meta' => [
                'title' => 'Intercompany Reconciliation',
                'as_of_date' => $asOfDate,
            ],
            'data' => $rows,
            'totals' => [
                'total_due_from' => $totalDueFrom,
                'total_due_to' => $totalDueTo,
                'imbalance' => abs($totalDueFrom - $totalDueTo),
                'is_balanced' => abs($totalDueFrom - $totalDueTo) < 0.01,
            ],
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────

    /**
     * Refresh materialized views used by consolidation reporting.
     */
    private function refreshMaterializedViews(): void
    {
        try {
            DB::statement("REFRESH MATERIALIZED VIEW CONCURRENTLY mv_consolidated_trial_balance");
        } catch (\Throwable $e) {
            // If CONCURRENTLY fails (needs unique index), try without
            try {
                DB::statement("REFRESH MATERIALIZED VIEW mv_consolidated_trial_balance");
            } catch (\Throwable $e2) {
                Log::warning('Failed to refresh mv_consolidated_trial_balance', [
                    'error' => $e2->getMessage(),
                ]);
            }
        }

        try {
            DB::statement("REFRESH MATERIALIZED VIEW CONCURRENTLY mv_branch_intercompany");
        } catch (\Throwable $e) {
            try {
                DB::statement("REFRESH MATERIALIZED VIEW mv_branch_intercompany");
            } catch (\Throwable $e2) {
                Log::warning('Failed to refresh mv_branch_intercompany', [
                    'error' => $e2->getMessage(),
                ]);
            }
        }
    }

    /**
     * Delete a draft consolidation run (and its elimination entries).
     */
    public function deleteDraftRun(ConsolidationRun $run): bool
    {
        if (!$run->isDraft()) {
            throw new \RuntimeException("Only draft consolidation runs can be deleted. Current status: {$run->status}");
        }

        return DB::transaction(function () use ($run) {
            $run->eliminationEntries()->delete();
            return $run->delete();
        });
    }
}

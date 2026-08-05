<?php

namespace App\Services\Budgeting;

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BudgetService — Phase 6: Budget Management
 *
 * Provides CRUD for budgets, budget-vs-actual comparison, and budget
 * control checks (warning/error when actual exceeds budget).
 *
 * Budget entry is spreadsheet-like: ledgers as rows, months as columns.
 * Each cell is a budget_line with ledger_id + period + amount.
 */
class BudgetService
{
    // ── CRUD ────────────────────────────────────────────────────────

    /**
     * Create a new budget with its lines.
     *
     * @param array $data  Budget header fields
     * @param array $lines [] each: { ledger_id: int, period: int, amount: float, notes: string|null }
     * @return Budget
     */
    public function createBudget(array $data, array $lines = []): Budget
    {
        return DB::transaction(function () use ($data, $lines) {
            $data['total_amount'] = collect($lines)->sum('amount');
            $data['status'] = 'draft';
            $data['created_by'] = $data['created_by'] ?? auth()->id();

            $budget = Budget::create($data);

            if (!empty($lines)) {
                $this->syncBudgetLines($budget, $lines);
            }

            return $budget->fresh('lines.ledger');
        });
    }

    /**
     * Update a budget header and its lines.
     * Only draft budgets can be edited.
     */
    public function updateBudget(Budget $budget, array $data, array $lines = []): Budget
    {
        if (!$budget->isEditable()) {
            throw new \RuntimeException("Only draft budgets can be edited. Current status: {$budget->status}");
        }

        return DB::transaction(function () use ($budget, $data, $lines) {
            $budget->update($data);

            if (!empty($lines)) {
                $this->syncBudgetLines($budget, $lines);
            }

            // Recalculate total
            $budget->total_amount = $budget->lines()->sum('amount');
            $budget->save();

            return $budget->fresh('lines.ledger');
        });
    }

    /**
     * Activate a draft budget (makes it live for budget control checks).
     *
     * FINANCE-3 (G-322): the duplicate-active-budget check was buggy — the
     * `->when($budget->branch_id, ...)` clause only re-applied the same
     * branch filter when branch_id was truthy, so the check passed whenever
     * a company-wide (NULL-branch) budget was being activated alongside an
     * existing branch-specific one (or vice versa). The `budget_vs_actual`
     * view then double-counted actuals across both budgets. The check now
     * blocks ANY coexistence: a company-wide budget cannot coexist with any
     * branch-specific budget for the same fiscal year, and two branch-
     * specific budgets for the same (year, branch) cannot coexist either.
     * The check is wrapped in a DB::transaction + lockForUpdate to close
     * the TOCTOU race (a concurrent activation could slip between the
     * SELECT and the UPDATE). A partial UNIQUE index
     * (`uq_budgets_active_per_year_branch`) is added by migration
     * `2026_09_05_000001` as the DB-level backstop.
     *
     * FINANCE-3 (G-326): added a maker-checker guard — the user who
     * activates the budget MUST NOT be the same user who created it
     * (`created_by`). This is the minimal enforcement of the maker-checker
     * principle without introducing a full `BudgetApproval` model (which
     * is deferred to Phase 14 per the budgeting gap catalogue). The full
     * workflow will add a `BudgetApproval` model with `requested_by` /
     * `approved_by` / `status` columns and a multi-step approval flow;
     * until then, this guard prevents the most common self-approval case.
     */
    public function activateBudget(Budget $budget): Budget
    {
        if ($budget->status !== 'draft') {
            throw new \RuntimeException("Only draft budgets can be activated. Current status: {$budget->status}");
        }

        // G-326: maker-checker guard — activator cannot be the creator.
        $activatorId = auth()->id();
        if ($activatorId !== null && $budget->created_by !== null && $activatorId === (int) $budget->created_by) {
            throw new \RuntimeException(
                'Maker-checker violation: the user who created this budget cannot activate it. '
                . 'Have another manager or admin review and activate it.'
            );
        }

        // G-322: duplicate-active-budget check (rewritten).
        // Block ANY coexistence of active budgets for the same fiscal year
        // when one of them is company-wide (branch_id IS NULL). Block same-
        // scope duplicates (two company-wide OR two same-branch) as well.
        return DB::transaction(function () use ($budget, $activatorId) {
            // Lock the budget row to serialize concurrent activations.
            $locked = Budget::where('id', $budget->id)->lockForUpdate()->first();
            $budget->refresh();

            if ($budget->status !== 'draft') {
                // Re-check after lock — another transaction may have flipped it.
                throw new \RuntimeException("Only draft budgets can be activated. Current status: {$budget->status}");
            }

            $conflictingQuery = Budget::where('fiscal_year', $budget->fiscal_year)
                ->where('status', 'active');

            if ($budget->branch_id === null) {
                // Activating a company-wide budget: block if ANY active
                // budget exists for this fiscal year (company-wide OR
                // branch-specific) — variance double-counts otherwise.
                $conflictingQuery->where(function ($q) {
                    $q->whereNull('branch_id')->orWhereNotNull('branch_id');
                });
            } else {
                // Activating a branch-specific budget: block if (a) another
                // active budget exists for the same (year, branch) OR
                // (b) an active company-wide budget exists for this year.
                $conflictingQuery->where(function ($q) use ($budget) {
                    $q->where('branch_id', $budget->branch_id)
                        ->orWhereNull('branch_id');
                });
            }

            $conflicting = $conflictingQuery->lockForUpdate()->exists();

            if ($conflicting) {
                $scope = $budget->branch_id === null
                    ? 'company-wide'
                    : "branch #{$budget->branch_id}";
                throw new \RuntimeException(
                    "An active budget already exists for fiscal year {$budget->fiscal_year} "
                    . "({$scope} or company-wide). Close the existing active budget before "
                    . "activating a new one — variance computation would otherwise double-count."
                );
            }

            $budget->update([
                'status'      => 'active',
                'approved_by' => $activatorId,
                'approved_at' => now(),
            ]);

            return $budget;
        });
    }

    /**
     * Close an active budget.
     */
    public function closeBudget(Budget $budget): Budget
    {
        if ($budget->status !== 'active') {
            throw new \RuntimeException("Only active budgets can be closed. Current status: {$budget->status}");
        }

        $budget->update(['status' => 'closed']);
        return $budget;
    }

    /**
     * Cancel a draft budget.
     */
    public function cancelBudget(Budget $budget): Budget
    {
        if (!in_array($budget->status, ['draft', 'active'])) {
            throw new \RuntimeException("Cannot cancel budget with status: {$budget->status}");
        }

        $budget->update(['status' => 'cancelled']);
        return $budget;
    }

    // ── Budget Lines ────────────────────────────────────────────────

    /**
     * Sync budget lines — replaces all existing lines for this budget.
     *
     * @param Budget $budget
     * @param array $lines [] each: { ledger_id, period, amount, notes? }
     */
    private function syncBudgetLines(Budget $budget, array $lines): void
    {
        // Delete existing lines
        $budget->lines()->delete();

        // Insert new lines
        $lineRows = [];
        foreach ($lines as $line) {
            $amount = (float) ($line['amount'] ?? 0);
            if ($amount == 0) {
                continue; // Skip zero-amount lines
            }
            $lineRows[] = [
                'budget_id'  => $budget->id,
                'ledger_id'  => (int) $line['ledger_id'],
                'period'     => (int) $line['period'],
                'amount'     => $amount,
                'notes'      => $line['notes'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (!empty($lineRows)) {
            collect($lineRows)->chunk(500)->each(function ($chunk) {
                DB::table('budget_lines')->insert($chunk->toArray());
            });
        }
    }

    // ── Budget vs Actual ────────────────────────────────────────────

    /**
     * Get budget vs actual comparison for a specific budget.
     *
     * @param Budget $budget
     * @param int|null $period  Filter by specific period (1-12)
     * @return array
     */
    public function getBudgetVsActual(Budget $budget, ?int $period = null): array
    {
        $query = DB::table('budget_vs_actual')
            ->where('budget_id', $budget->id);

        if ($period !== null) {
            $query->where('period', $period);
        }

        $results = $query->orderBy('account_type')->orderBy('ledger_code')->get();

        // Group by account type for structured output
        $grouped = [];
        $totals = [
            'budget_amount'   => 0,
            'actual_amount'   => 0,
            'variance_amount' => 0,
        ];

        foreach ($results as $row) {
            $type = $row->account_type ?? 'Other';
            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $grouped[$type][] = $row;

            $totals['budget_amount']   += (float) $row->budget_amount;
            $totals['actual_amount']   += (float) $row->actual_amount;
            $totals['variance_amount'] += (float) $row->variance_amount;
        }

        return [
            'budget' => $budget,
            'lines'  => $grouped,
            'totals' => $totals,
            'period' => $period,
        ];
    }

    /**
     * Get budget vs actual for a specific ledger and period.
     * Used by the budget control check.
     */
    public function getLedgerBudgetVsActual(int $ledgerId, int $period, string $fiscalYear, ?int $branchId = null): ?object
    {
        $query = DB::table('budget_vs_actual')
            ->where('ledger_id', $ledgerId)
            ->where('period', $period)
            ->where('fiscal_year', $fiscalYear);

        if ($branchId !== null) {
            $query->where(function ($q) use ($branchId) {
                $q->whereNull('budget_branch_id')
                  ->orWhere('budget_branch_id', $branchId);
            });
        }

        return $query->first();
    }

    // ── Budget Control ──────────────────────────────────────────────

    /**
     * Check if a posting would exceed the budget for a given ledger + period.
     *
     * Warning threshold: 80% of budget consumed
     * Error threshold: 100% of budget consumed (budget exceeded)
     */
    public function checkBudgetControl(
        int $ledgerId,
        float $proposedAmount,
        string $fiscalYear,
        int $period,
        ?int $branchId = null
    ): object {
        $ledger = Ledger::find($ledgerId);

        // Only check budget for Expense and Income accounts
        if (!$ledger || !in_array($ledger->account_type, ['Expense', 'Income'])) {
            return (object) ['level' => 'ok', 'message' => 'No budget control for this account type.'];
        }

        $bva = $this->getLedgerBudgetVsActual($ledgerId, $period, $fiscalYear, $branchId);

        if (!$bva || (float) $bva->budget_amount == 0) {
            return (object) ['level' => 'ok', 'message' => 'No budget defined for this account and period.'];
        }

        $budgetAmount  = (float) $bva->budget_amount;
        $actualAmount  = (float) $bva->actual_amount;
        $afterProposal = $actualAmount + $proposedAmount;
        $usagePercent  = ($afterProposal / $budgetAmount) * 100;

        $result = (object) [
            'level'           => 'ok',
            'message'         => '',
            'budget_amount'   => $budgetAmount,
            'actual_amount'   => $actualAmount,
            'proposed_amount' => $proposedAmount,
            'after_proposal'  => $afterProposal,
            'usage_percent'   => round($usagePercent, 1),
            'variance'        => $budgetAmount - $afterProposal,
        ];

        if ($afterProposal > $budgetAmount) {
            $result->level = 'error';
            $result->message = sprintf(
                'Budget exceeded! %s budget: %.2f, actual: %.2f, proposed: %.2f, over by %.2f (%.1f%%)',
                $ledger->ledger_name, $budgetAmount, $actualAmount, $proposedAmount,
                $afterProposal - $budgetAmount, $usagePercent
            );
        } elseif ($usagePercent >= 80) {
            $result->level = 'warning';
            $result->message = sprintf(
                'Budget warning: %s is at %.1f%% of budget (budget: %.2f, actual: %.2f, proposed: %.2f)',
                $ledger->ledger_name, $usagePercent, $budgetAmount, $actualAmount, $proposedAmount
            );
        } else {
            $result->message = sprintf(
                '%s budget OK: %.1f%% used (budget: %.2f, actual: %.2f)',
                $ledger->ledger_name, $usagePercent, $budgetAmount, $actualAmount
            );
        }

        return $result;
    }

    // ── Spreadsheet Grid Data ───────────────────────────────────────

    /**
     * Get the budget entry grid data for a fiscal year.
     * Returns ledgers as rows, periods as columns.
     */
    public function getBudgetGridData(string $fiscalYear, ?int $branchId = null, string $periodType = 'monthly'): array
    {
        $maxPeriod = match ($periodType) {
            'quarterly' => 4,
            'yearly'    => 1,
            default     => 12,
        };

        // Get all posting-level ledgers (Expense and Income for budget)
        $ledgers = Ledger::active()
            ->whereIn('account_type', ['Expense', 'Income'])
            ->whereNotNull('ledger_nature')
            ->orderBy('account_type')
            ->orderBy('sort_order')
            ->orderBy('ledger_code')
            ->get();

        // Get existing budget lines for this year/branch
        $budgetQuery = Budget::where('fiscal_year', $fiscalYear)
            ->where('period_type', $periodType);

        if ($branchId !== null) {
            $budgetQuery->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
            });
        }

        $budget = $budgetQuery->first();

        $existingLines = [];
        if ($budget) {
            $existingLines = $budget->lines()->get()->keyBy(function ($line) {
                return $line->ledger_id . '_' . $line->period;
            });
        }

        // Build grid: ledgers × periods
        $grid = [];
        foreach ($ledgers as $ledger) {
            $row = [
                'ledger_id'    => $ledger->id,
                'ledger_code'  => $ledger->ledger_code,
                'ledger_name'  => $ledger->ledger_name,
                'account_type' => $ledger->account_type,
                'periods'      => [],
            ];

            for ($p = 1; $p <= $maxPeriod; $p++) {
                $key = $ledger->id . '_' . $p;
                $row['periods'][$p] = [
                    'amount' => isset($existingLines[$key]) ? (float) $existingLines[$key]->amount : 0,
                    'notes'  => isset($existingLines[$key]) ? $existingLines[$key]->notes : null,
                ];
            }

            $grid[] = $row;
        }

        return [
            'fiscal_year'   => $fiscalYear,
            'period_type'   => $periodType,
            'max_period'    => $maxPeriod,
            'budget'        => $budget,
            'ledgers'       => $grid,
            'period_labels' => $this->getPeriodLabels($periodType),
        ];
    }

    /**
     * Save budget grid data from the spreadsheet-like form.
     */
    public function saveBudgetGrid(string $fiscalYear, ?int $branchId, string $periodType, string $name, array $gridData): Budget
    {
        return DB::transaction(function () use ($fiscalYear, $branchId, $periodType, $name, $gridData) {
            // Find or create budget
            $budget = Budget::where('fiscal_year', $fiscalYear)
                ->where('period_type', $periodType)
                ->where('branch_id', $branchId)
                ->first();

            $lines = [];
            foreach ($gridData as $row) {
                $ledgerId = (int) $row['ledger_id'];
                foreach ($row['periods'] ?? [] as $period => $amount) {
                    $amount = (float) $amount;
                    if ($amount > 0) {
                        $lines[] = [
                            'ledger_id' => $ledgerId,
                            'period'    => (int) $period,
                            'amount'    => $amount,
                        ];
                    }
                }
            }

            if ($budget) {
                return $this->updateBudget($budget, ['name' => $name], $lines);
            } else {
                return $this->createBudget([
                    'name'        => $name,
                    'fiscal_year' => $fiscalYear,
                    'branch_id'   => $branchId,
                    'period_type' => $periodType,
                    'created_by'  => auth()->id(),
                ], $lines);
            }
        });
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function getPeriodLabels(string $periodType): array
    {
        if ($periodType === 'quarterly') {
            return [1 => 'Q1', 2 => 'Q2', 3 => 'Q3', 4 => 'Q4'];
        }

        if ($periodType === 'yearly') {
            return [1 => 'Annual'];
        }

        return [
            1  => 'Jan', 2  => 'Feb', 3  => 'Mar',
            4  => 'Apr', 5  => 'May', 6  => 'Jun',
            7  => 'Jul', 8  => 'Aug', 9  => 'Sep',
            10 => 'Oct', 11 => 'Nov', 12 => 'Dec',
        ];
    }
}

<?php

namespace App\Services\BranchDemand;

use Illuminate\Support\Facades\DB;

/**
 * Branch Demand Audit Service — Phase 8 (Anti-Gaming & Accountability Controls).
 *
 * Implements three categories of anti-gaming flags and the audit checklist /
 * reconciliation logic that the plan requires:
 *
 *   1. Anti-Gaming Flags:
 *      - Catalog below locked rate: current product_price_history.default_rate
 *        < branch_demand_items.cost_rate on open received demands
 *      - Sales below locked cost: receiver branch sells at a rate below the
 *        locked demand cost
 *      - Stale outstanding: open principal > 30 days old
 *
 *   2. Audit Checklist:
 *      - GL Journal Links: journal_entry_id and journal_entry_id_debtor exist
 *      - Ledger Nature: interbranch_receivable / interbranch_payable accounts exist
 *      - Demand GL Alignment: all received demands have both journal entries
 *      - Journal Balance: each journal entry has total Dr = total Cr
 *
 *   3. Reconciliation:
 *      - Compare demand outstanding vs branch_ledger running balance
 *      - Identify any discrepancies
 *
 *   4. Per-Demand Audit:
 *      - Stock trace, settlement trace, GL journal blocks, anti-gaming flags
 */
class BranchDemandAuditService
{
    public function __construct(
        private BranchDemandAuditLogger $auditLogger,
    ) {}

    // ===================== ANTI-GAMING FLAGS =====================

    /**
     * Get all anti-gaming flags for a branch within a date range.
     *
     * Returns three flag categories:
     *   - catalog_below_locked:  current default_rate < locked cost_rate
     *   - sales_below_cost:      receiver sells below locked cost
     *   - stale_outstanding:     open principal > 30 days old
     *
     * @param int       $branchId  The branch to check (checks both as debtor and creditor)
     * @param string|null $dateFrom  Start date filter (Y-m-d)
     * @param string|null $dateTo    End date filter (Y-m-d)
     * @return array
     */
    public function getDemandAntiGamingFlags(int $branchId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return [
            'catalog_below_locked' => $this->getCatalogBelowLockedRate($branchId, $dateFrom, $dateTo),
            'sales_below_cost'     => $this->getSalesBelowLockedCost($branchId, $dateFrom, $dateTo),
            'stale_outstanding'    => $this->getStaleOutstanding($branchId, $dateFrom, $dateTo),
        ];
    }

    /**
     * Flag 1: Catalog below locked rate.
     *
     * When the current product_price_history.default_rate is LESS than the
     * branch_demand_items.cost_rate on open received demands, the receiver
     * branch is effectively paying more than the current market price. This
     * could indicate:
     *   - The supplier branch is inflating the cost rate
     *   - The price has dropped since the demand was sent
     *   - The demand was created at a favorable rate for the supplier
     *
     * This flag is the "catalog below locked rate" check from the plan.
     *
     * @param int       $branchId
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @return \Illuminate\Support\Collection
     */
    private function getCatalogBelowLockedRate(int $branchId, ?string $dateFrom, ?string $dateTo)
    {
        $today = now()->format('Y-m-d');

        // Get current effective price ranges for all products
        $currentPrices = DB::table('product_price_history')
            ->where('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')
                  ->orWhere('effective_to', '>=', $today);
            })
            ->orderByDesc('effective_from')
            ->get()
            ->groupBy('product_id')
            ->map(function ($group) {
                return $group->first(); // Most recent effective range
            });

        // Get open received demand items for this branch (as debtor/requester)
        $query = DB::table('branch_demand_items')
            ->join('branch_demands', 'branch_demand_items.branch_demand_id', '=', 'branch_demands.id')
            ->where('branch_demands.from_branch_id', $branchId)
            ->where('branch_demands.status', 'received')
            ->where('branch_demands.is_reversed', false)
            ->where('branch_demand_items.cost_rate', '>', 0)
            ->select([
                'branch_demands.id as demand_id',
                'branch_demands.demand_code',
                'branch_demands.demand_date',
                'branch_demands.total_value',
                'branch_demands.settlement_amount',
                'branch_demand_items.id as item_id',
                'branch_demand_items.product_id',
                'branch_demand_items.qty',
                'branch_demand_items.cost_rate as locked_cost_rate',
                'branch_demand_items.price_min',
                'branch_demand_items.price_max',
                'branch_demand_items.price_default',
            ]);

        if ($dateFrom) {
            $query->where('branch_demands.demand_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('branch_demands.demand_date', '<=', $dateTo);
        }

        $items = $query->get();

        // Filter to items where current default_rate < locked cost_rate
        $flags = collect();
        foreach ($items as $item) {
            $currentPrice = $currentPrices[$item->product_id] ?? null;
            if (!$currentPrice) {
                continue;
            }

            $currentDefault = (float) $currentPrice->default_rate;
            $lockedCost = (float) $item->locked_cost_rate;

            if ($currentDefault < $lockedCost && $currentDefault > 0) {
                $overcharge = $lockedCost - $currentDefault;
                $overchargeTotal = $overcharge * (float) $item->qty;

                $flags->push([
                    'demand_id'          => $item->demand_id,
                    'demand_code'        => $item->demand_code,
                    'demand_date'        => $item->demand_date,
                    'item_id'            => $item->item_id,
                    'product_id'         => $item->product_id,
                    'qty'                => (float) $item->qty,
                    'locked_cost_rate'   => round($lockedCost, 4),
                    'current_default_rate' => round($currentDefault, 4),
                    'overcharge_per_unit' => round($overcharge, 4),
                    'overcharge_total'   => round($overchargeTotal, 2),
                    'outstanding'        => round((float) $item->total_value - (float) $item->settlement_amount, 2),
                    'flag_type'          => 'catalog_below_locked',
                    'severity'           => $overchargeTotal > 1000 ? 'high' : ($overchargeTotal > 100 ? 'medium' : 'low'),
                ]);
            }
        }

        return $flags->sortByDesc('overcharge_total')->values();
    }

    /**
     * Flag 2: Sales below locked cost.
     *
     * When the receiver branch sells products at a rate below the locked
     * demand cost, they are selling at a loss. This could indicate:
     *   - The receiver is dumping goods at below-cost prices
     *   - The receiver is trying to inflate volume at the expense of margin
     *   - The receiver is engaging in a price war using intercompany goods
     *
     * This flag checks sales invoices (sales_items) for products that were
     * received via branch demand, comparing the sale rate against the
     * locked cost_rate.
     *
     * @param int       $branchId
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @return \Illuminate\Support\Collection
     */
    private function getSalesBelowLockedCost(int $branchId, ?string $dateFrom, ?string $dateTo)
    {
        // Get demand items received by this branch (from_branch_id = branchId as debtor)
        $demandItems = DB::table('branch_demand_items')
            ->join('branch_demands', 'branch_demand_items.branch_demand_id', '=', 'branch_demands.id')
            ->where('branch_demands.from_branch_id', $branchId)
            ->where('branch_demands.status', 'received')
            ->where('branch_demands.is_reversed', false)
            ->where('branch_demand_items.cost_rate', '>', 0)
            ->select([
                'branch_demand_items.product_id',
                'branch_demand_items.cost_rate as locked_cost_rate',
                'branch_demand_items.qty as demand_qty',
                'branch_demands.demand_code',
                'branch_demands.id as demand_id',
                'branch_demands.demand_date',
            ])
            ->get();

        if ($demandItems->isEmpty()) {
            return collect();
        }

        $productIds = $demandItems->pluck('product_id')->unique()->toArray();

        // Find sales items for those products in this branch
        $salesQuery = DB::table('sales_items')
            ->join('sales', 'sales_items.sale_id', '=', 'sales.id')
            ->where('sales.branch_id', $branchId)
            ->whereIn('sales_items.product_id', $productIds)
            ->where('sales.status', '!=', 'cancelled')
            ->where('sales_items.rate', '>', 0)
            ->select([
                'sales.id as sale_id',
                'sales.sale_code',
                'sales.sale_date',
                'sales_items.product_id',
                'sales_items.qty as sale_qty',
                'sales_items.rate as sale_rate',
            ]);

        if ($dateFrom) {
            $salesQuery->where('sales.sale_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $salesQuery->where('sales.sale_date', '<=', $dateTo);
        }

        $salesItems = $salesQuery->get();

        // Cross-reference: find sales where sale_rate < locked_cost_rate
        $flags = collect();
        $demandItemsByProduct = $demandItems->groupBy('product_id');

        foreach ($salesItems as $sale) {
            $relevantDemandItems = $demandItemsByProduct->get($sale->product_id, collect());
            foreach ($relevantDemandItems as $di) {
                $lockedCost = (float) $di->locked_cost_rate;
                $saleRate = (float) $sale->sale_rate;

                if ($saleRate < $lockedCost && $lockedCost > 0) {
                    $lossPerUnit = $lockedCost - $saleRate;
                    $lossTotal = $lossPerUnit * (float) $sale->sale_qty;

                    $flags->push([
                        'demand_id'         => $di->demand_id,
                        'demand_code'       => $di->demand_code,
                        'demand_date'       => $di->demand_date,
                        'sale_id'           => $sale->sale_id,
                        'sale_code'         => $sale->sale_code,
                        'sale_date'         => $sale->sale_date,
                        'product_id'        => $sale->product_id,
                        'sale_qty'          => (float) $sale->sale_qty,
                        'sale_rate'         => round($saleRate, 4),
                        'locked_cost_rate'  => round($lockedCost, 4),
                        'loss_per_unit'     => round($lossPerUnit, 4),
                        'loss_total'        => round($lossTotal, 2),
                        'flag_type'         => 'sales_below_cost',
                        'severity'          => $lossTotal > 1000 ? 'high' : ($lossTotal > 100 ? 'medium' : 'low'),
                    ]);
                }
            }
        }

        return $flags->sortByDesc('loss_total')->values();
    }

    /**
     * Flag 3: Stale outstanding — open principal > 30 days old.
     *
     * When a demand has been received but not fully settled after 30 days,
     * it indicates a potential problem:
     *   - The debtor branch is not paying
     *   - The settlement process is broken
     *   - The demand amount is disputed
     *
     * Stale outstanding balances are a common vector for gaming: branches
     * create demands, receive goods, but never settle, effectively getting
     * free inventory.
     *
     * @param int       $branchId
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @return \Illuminate\Support\Collection
     */
    private function getStaleOutstanding(int $branchId, ?string $dateFrom, ?string $dateTo)
    {
        $staleThreshold = now()->subDays(30)->format('Y-m-d');

        $query = DB::table('branch_demands')
            ->where(function ($q) use ($branchId) {
                $q->where('from_branch_id', $branchId)
                  ->orWhere('to_branch_id', $branchId);
            })
            ->where('status', 'received')
            ->where('is_reversed', false)
            ->where('total_value', '>', 0)
            ->whereColumn('settlement_amount', '<', 'total_value')
            ->where('demand_date', '<=', $staleThreshold)
            ->select([
                'id', 'demand_code', 'demand_date', 'from_branch_id', 'to_branch_id',
                'total_value', 'settlement_amount',
                DB::raw('total_value - settlement_amount as outstanding'),
                DB::raw('(CURRENT_DATE - demand_date::date) as days_outstanding'),
            ]);

        if ($dateFrom) {
            $query->where('demand_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('demand_date', '<=', $dateTo);
        }

        $stale = $query->get();

        // Enrich with branch names and severity
        $branchNames = DB::table('branches')->pluck('branch_name', 'id');

        return $stale->map(function ($item) use ($branchNames) {
            $outstanding = (float) $item->outstanding;
            $days = (int) $item->days_outstanding;

            return [
                'demand_id'       => $item->id,
                'demand_code'     => $item->demand_code,
                'demand_date'     => $item->demand_date,
                'from_branch_id'  => $item->from_branch_id,
                'from_branch'     => $branchNames[$item->from_branch_id] ?? 'Unknown',
                'to_branch_id'    => $item->to_branch_id,
                'to_branch'       => $branchNames[$item->to_branch_id] ?? 'Unknown',
                'total_value'     => round((float) $item->total_value, 2),
                'settlement_amount' => round((float) $item->settlement_amount, 2),
                'outstanding'     => round($outstanding, 2),
                'days_outstanding' => $days,
                'flag_type'       => 'stale_outstanding',
                'severity'        => $days > 90 ? 'high' : ($days > 60 ? 'medium' : 'low'),
            ];
        })->sortByDesc('days_outstanding')->values();
    }

    // ===================== AUDIT CHECKLIST =====================

    /**
     * Run the full audit checklist for the Branch Demand module.
     *
     * Returns an array of health checks, each with:
     *   - name: Human-readable name
     *   - status: pass | warning | fail
     *   - message: Description of the check result
     *   - count: Number of affected records (for warnings/failures)
     *   - details: Affected records (sample)
     *
     * @return array
     */
    public function getChecklist(): array
    {
        return [
            'gl_journal_links'       => $this->checkGLJournalLinks(),
            'ledger_nature'          => $this->checkLedgerNature(),
            'demand_gl_alignment'    => $this->checkDemandGLAlignment(),
            'journal_balance'        => $this->checkJournalBalance(),
            'orphaned_settlements'   => $this->checkOrphanedSettlements(),
            'reversed_with_open_settlements' => $this->checkReversedWithOpenSettlements(),
        ];
    }

    /**
     * Check 1: GL Journal Links — all received demands must have journal_entry_id
     * and journal_entry_id_debtor populated.
     */
    private function checkGLJournalLinks(): array
    {
        $missing = DB::table('branch_demands')
            ->where('status', 'received')
            ->where('is_reversed', false)
            ->where(function ($q) {
                $q->whereNull('journal_entry_id')
                  ->orWhereNull('journal_entry_id_debtor');
            })
            ->count();

        return [
            'name'    => 'GL Journal Links',
            'status'  => $missing === 0 ? 'pass' : 'fail',
            'message' => $missing === 0
                ? 'All received demands have both creditor and debtor journal entries.'
                : "{$missing} received demand(s) are missing creditor or debtor journal entries.",
            'count'   => $missing,
            'details' => $missing > 0
                ? DB::table('branch_demands')
                    ->where('status', 'received')
                    ->where('is_reversed', false)
                    ->where(function ($q) {
                        $q->whereNull('journal_entry_id')
                          ->orWhereNull('journal_entry_id_debtor');
                    })
                    ->limit(10)
                    ->get(['id', 'demand_code', 'journal_entry_id', 'journal_entry_id_debtor'])
                    ->toArray()
                : [],
        ];
    }

    /**
     * Check 2: Ledger Nature — interbranch_receivable and interbranch_payable
     * accounts must exist in the chart of accounts.
     */
    private function checkLedgerNature(): array
    {
        // Guard: chart_of_accounts may not exist in all environments (e.g. test DB)
        try {
            $receivable = DB::table('chart_of_accounts')
                ->where('account_code', 'interbranch_receivable')
                ->exists();

            $payable = DB::table('chart_of_accounts')
                ->where('account_code', 'interbranch_payable')
                ->exists();
        } catch (\Throwable $e) {
            return [
                'name'    => 'Ledger Nature',
                'status'  => 'skip',
                'message' => 'chart_of_accounts table not available — skipping check.',
                'count'   => 0,
                'details' => ['error' => $e->getMessage()],
            ];
        }

        $bothExist = $receivable && $payable;

        return [
            'name'    => 'Ledger Nature',
            'status'  => $bothExist ? 'pass' : 'fail',
            'message' => $bothExist
                ? 'Both interbranch_receivable and interbranch_payable accounts exist.'
                : 'Missing ' . (!$receivable ? 'interbranch_receivable ' : '') . (!$payable ? 'interbranch_payable ' : '') . 'account(s).',
            'count'   => ($receivable ? 0 : 1) + ($payable ? 0 : 1),
            'details' => [
                'interbranch_receivable_exists' => $receivable,
                'interbranch_payable_exists'    => $payable,
            ],
        ];
    }

    /**
     * Check 3: Demand GL Alignment — all received demands must have both
     * journal entries (creditor + debtor) that are not reversed.
     */
    private function checkDemandGLAlignment(): array
    {
        $misaligned = DB::table('branch_demands')
            ->where('status', 'received')
            ->where('is_reversed', false)
            ->whereNotNull('journal_entry_id')
            ->whereNotNull('journal_entry_id_debtor')
            ->where(function ($q) {
                // Check if either journal entry is reversed
                $q->whereExists(function ($subQ) {
                    $subQ->selectRaw(1)
                        ->from('journal_entries as je1')
                        ->whereColumn('je1.id', 'branch_demands.journal_entry_id')
                        ->where('je1.is_reversed', true);
                })
                ->orWhereExists(function ($subQ) {
                    $subQ->selectRaw(1)
                        ->from('journal_entries as je2')
                        ->whereColumn('je2.id', 'branch_demands.journal_entry_id_debtor')
                        ->where('je2.is_reversed', true);
                });
            })
            ->count();

        return [
            'name'    => 'Demand GL Alignment',
            'status'  => $misaligned === 0 ? 'pass' : 'warning',
            'message' => $misaligned === 0
                ? 'All received demands have properly aligned (non-reversed) journal entries.'
                : "{$misaligned} received demand(s) have reversed journal entries but the demand itself is not reversed.",
            'count'   => $misaligned,
            'details' => [],
        ];
    }

    /**
     * Check 4: Journal Balance — each journal entry must have total Dr = total Cr.
     */
    private function checkJournalBalance(): array
    {
        // Find journal entries linked to branch demands where Dr != Cr
        $unbalanced = DB::table('journal_entries')
            ->leftJoin('journal_entry_items', 'journal_entries.id', '=', 'journal_entry_items.journal_entry_id')
            ->whereNotNull('journal_entries.id')
            ->whereExists(function ($q) {
                $q->selectRaw(1)
                    ->from('branch_demands')
                    ->whereColumn('branch_demands.journal_entry_id', 'journal_entries.id')
                    ->orWhereColumn('branch_demands.journal_entry_id_debtor', 'journal_entries.id');
            })
            ->groupBy('journal_entries.id', 'journal_entries.journal_code')
            ->havingRaw('ROUND(CAST(SUM(CASE WHEN journal_entry_items.debit_credit = \'debit\' THEN journal_entry_items.amount ELSE 0 END) AS numeric), 2) != ROUND(CAST(SUM(CASE WHEN journal_entry_items.debit_credit = \'credit\' THEN journal_entry_items.amount ELSE 0 END) AS numeric), 2)')
            ->select([
                'journal_entries.id',
                'journal_entries.journal_code',
                DB::raw('SUM(CASE WHEN journal_entry_items.debit_credit = \'debit\' THEN journal_entry_items.amount ELSE 0 END) as total_debit'),
                DB::raw('SUM(CASE WHEN journal_entry_items.debit_credit = \'credit\' THEN journal_entry_items.amount ELSE 0 END) as total_credit'),
            ])
            ->get();

        $count = $unbalanced->count();

        return [
            'name'    => 'Journal Balance',
            'status'  => $count === 0 ? 'pass' : 'fail',
            'message' => $count === 0
                ? 'All branch demand journal entries have balanced Dr/Cr.'
                : "{$count} journal entry/entries have unbalanced Dr/Cr.",
            'count'   => $count,
            'details' => $unbalanced->take(10)->toArray(),
        ];
    }

    /**
     * Check 5: Orphaned Settlements — settlements that reference a demand
     * that has been reversed or deleted.
     */
    private function checkOrphanedSettlements(): array
    {
        $orphanedMt = DB::table('branch_demand_money_transfer_settlements')
            ->join('branch_demands', 'branch_demand_money_transfer_settlements.demand_id', '=', 'branch_demands.id')
            ->where('branch_demands.is_reversed', true)
            ->count();

        $orphanedCp = DB::table('branch_demand_customer_payment_settlements')
            ->join('branch_demands', 'branch_demand_customer_payment_settlements.demand_id', '=', 'branch_demands.id')
            ->where('branch_demands.is_reversed', true)
            ->count();

        $total = $orphanedMt + $orphanedCp;

        return [
            'name'    => 'Orphaned Settlements',
            'status'  => $total === 0 ? 'pass' : 'warning',
            'message' => $total === 0
                ? 'No settlements reference reversed demands.'
                : "{$total} settlement(s) reference reversed demands ({$orphanedMt} from money transfers, {$orphanedCp} from customer payments).",
            'count'   => $total,
            'details' => [
                'money_transfer_settlements' => $orphanedMt,
                'customer_payment_settlements' => $orphanedCp,
            ],
        ];
    }

    /**
     * Check 6: Reversed demands that still have open (non-reversed) settlements.
     */
    private function checkReversedWithOpenSettlements(): array
    {
        $count = DB::table('branch_demands')
            ->where('is_reversed', true)
            ->where(function ($q) {
                $q->whereExists(function ($subQ) {
                    $subQ->selectRaw(1)
                        ->from('branch_demand_money_transfer_settlements')
                        ->whereColumn('branch_demand_money_transfer_settlements.demand_id', 'branch_demands.id')
                        ->where('is_reversed', false);
                })
                ->orWhereExists(function ($subQ) {
                    $subQ->selectRaw(1)
                        ->from('branch_demand_customer_payment_settlements')
                        ->whereColumn('branch_demand_customer_payment_settlements.demand_id', 'branch_demands.id')
                        ->where('is_reversed', false);
                });
            })
            ->count();

        return [
            'name'    => 'Reversed with Open Settlements',
            'status'  => $count === 0 ? 'pass' : 'fail',
            'message' => $count === 0
                ? 'No reversed demands have open (non-reversed) settlements.'
                : "{$count} reversed demand(s) still have open settlements that should have been reversed.",
            'count'   => $count,
            'details' => [],
        ];
    }

    // ===================== PER-DEMAND AUDIT =====================

    /**
     * Get the full audit trail for a specific demand.
     *
     * Returns:
     *   - demand: The demand header
     *   - stock_trace: Stock transactions linked to this demand
     *   - settlement_trace: All settlements for this demand
     *   - gl_journal_blocks: Creditor and debtor journal entries with items
     *   - anti_gaming_flags: Any flags specific to this demand
     *   - audit_log: The chronological audit trail
     *   - repricing_history: Any repricing adjustments
     *
     * @param int $demandId
     * @return array
     */
    public function getDemandAudit(int $demandId): array
    {
        $demand = DB::table('branch_demands')
            ->where('id', $demandId)
            ->first();

        if (!$demand) {
            throw new \RuntimeException("Branch demand {$demandId} not found.");
        }

        $branchNames = DB::table('branches')->pluck('branch_name', 'id');

        // Stock trace
        $stockTrace = DB::table('stock_transactions')
            ->where('reference_id', $demandId)
            ->whereIn('reference_type', ['demand_send', 'demand_receive', 'demand_reversal'])
            ->orderBy('id')
            ->get();

        // Settlement trace
        $mtSettlements = DB::table('branch_demand_money_transfer_settlements')
            ->where('demand_id', $demandId)
            ->orderBy('id')
            ->get();

        $cpSettlements = DB::table('branch_demand_customer_payment_settlements')
            ->where('demand_id', $demandId)
            ->orderBy('id')
            ->get();

        // GL journal blocks
        $creditorJournal = null;
        $debtorJournal = null;
        if ($demand->journal_entry_id) {
            $creditorJournal = DB::table('journal_entries')
                ->where('id', $demand->journal_entry_id)
                ->first();
            if ($creditorJournal) {
                $creditorJournal->items = DB::table('journal_entry_items')
                    ->where('journal_entry_id', $creditorJournal->id)
                    ->orderBy('id')
                    ->get();
            }
        }
        if ($demand->journal_entry_id_debtor) {
            $debtorJournal = DB::table('journal_entries')
                ->where('id', $demand->journal_entry_id_debtor)
                ->first();
            if ($debtorJournal) {
                $debtorJournal->items = DB::table('journal_entry_items')
                    ->where('journal_entry_id', $debtorJournal->id)
                    ->orderBy('id')
                    ->get();
            }
        }

        // Repricing history
        $repricingHistory = DB::table('branch_demand_repricing')
            ->where('branch_demand_id', $demandId)
            ->orderBy('created_at')
            ->get();

        // Anti-gaming flags for this specific demand
        $flags = collect();
        if ($demand->status === 'received' && !$demand->is_reversed) {
            $items = DB::table('branch_demand_items')
                ->where('branch_demand_id', $demandId)
                ->get();

            $today = now()->format('Y-m-d');
            $productIds = $items->pluck('product_id')->unique()->toArray();

            $currentPrices = DB::table('product_price_history')
                ->whereIn('product_id', $productIds)
                ->where('effective_from', '<=', $today)
                ->where(function ($q) use ($today) {
                    $q->whereNull('effective_to')
                      ->orWhere('effective_to', '>=', $today);
                })
                ->orderByDesc('effective_from')
                ->get()
                ->groupBy('product_id')
                ->map(fn ($g) => $g->first());

            foreach ($items as $item) {
                $cp = $currentPrices[$item->product_id] ?? null;
                if ($cp && (float) $cp->default_rate < (float) $item->cost_rate && (float) $item->cost_rate > 0) {
                    $flags->push([
                        'type'       => 'catalog_below_locked',
                        'product_id' => $item->product_id,
                        'locked_rate' => (float) $item->cost_rate,
                        'current_default' => (float) $cp->default_rate,
                        'variance'   => round((float) $item->cost_rate - (float) $cp->default_rate, 4),
                    ]);
                }
            }

            // Stale outstanding check
            $outstanding = (float) $demand->total_value - (float) $demand->settlement_amount;
            if ($outstanding > 0) {
                $days = (int) now()->diffInDays(now()->parse($demand->demand_date));
                if ($days > 30) {
                    $flags->push([
                        'type'     => 'stale_outstanding',
                        'outstanding' => round($outstanding, 2),
                        'days_outstanding' => $days,
                    ]);
                }
            }
        }

        // Audit log
        $auditLog = $this->auditLogger->getTrailForDemand($demandId);

        return [
            'demand'            => $demand,
            'branch_names'      => $branchNames->toArray(),
            'stock_trace'       => $stockTrace,
            'settlement_trace'  => [
                'money_transfer'     => $mtSettlements,
                'customer_payment'   => $cpSettlements,
            ],
            'gl_journal_blocks' => [
                'creditor' => $creditorJournal,
                'debtor'   => $debtorJournal,
            ],
            'anti_gaming_flags' => $flags->values(),
            'repricing_history' => $repricingHistory,
            'audit_log'         => $auditLog,
        ];
    }

    // ===================== RECONCILIATION =====================

    /**
     * Reconcile demand outstanding vs branch_ledger running balance.
     *
     * For each branch pair, compares:
     *   - Sum of outstanding demand amounts (total_value - settlement_amount)
     *   - Latest running balance from branch_ledger
     *
     * Any discrepancy indicates a data integrity issue.
     *
     * @param int       $branchId  The branch to reconcile (checks both as debtor and creditor)
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @return array
     */
    public function getReconciliation(int $branchId, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $branchNames = DB::table('branches')->pluck('branch_name', 'id');

        // As debtor (from_branch_id = branchId): I owe money
        $debtorDemands = DB::table('branch_demands')
            ->where('from_branch_id', $branchId)
            ->where('status', 'received')
            ->where('is_reversed', false)
            ->when($dateFrom, fn ($q) => $q->where('demand_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('demand_date', '<=', $dateTo))
            ->select([
                'to_branch_id as partner_branch_id',
                DB::raw('SUM(total_value) as total_demand_value'),
                DB::raw('SUM(settlement_amount) as total_settlement'),
                DB::raw('SUM(total_value - settlement_amount) as total_outstanding'),
                DB::raw('COUNT(*) as demand_count'),
            ])
            ->groupBy('to_branch_id')
            ->get();

        // As creditor (to_branch_id = branchId): I am owed money
        $creditorDemands = DB::table('branch_demands')
            ->where('to_branch_id', $branchId)
            ->where('status', 'received')
            ->where('is_reversed', false)
            ->when($dateFrom, fn ($q) => $q->where('demand_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('demand_date', '<=', $dateTo))
            ->select([
                'from_branch_id as partner_branch_id',
                DB::raw('SUM(total_value) as total_demand_value'),
                DB::raw('SUM(settlement_amount) as total_settlement'),
                DB::raw('SUM(total_value - settlement_amount) as total_outstanding'),
                DB::raw('COUNT(*) as demand_count'),
            ])
            ->groupBy('from_branch_id')
            ->get();

        // Get ledger running balances for each partner
        $reconciliation = [];
        $processedPartners = collect();

        foreach ($debtorDemands as $row) {
            $partnerId = (int) $row->partner_branch_id;
            $debtorBranchId = min($branchId, $partnerId);
            $creditorBranchId = max($branchId, $partnerId);

            $ledgerBalance = $this->getLedgerRunningBalance($debtorBranchId, $creditorBranchId);

            $demandOutstanding = (float) $row->total_outstanding;
            $ledgerOutstanding = (float) ($ledgerBalance->running_balance ?? 0);
            $discrepancy = round($demandOutstanding - $ledgerOutstanding, 2);

            $reconciliation[] = [
                'partner_branch_id' => $partnerId,
                'partner_branch'    => $branchNames[$partnerId] ?? 'Unknown',
                'role'              => 'debtor',
                'demand_count'      => (int) $row->demand_count,
                'total_demand_value' => round((float) $row->total_demand_value, 2),
                'total_settlement'  => round((float) $row->total_settlement, 2),
                'demand_outstanding' => round($demandOutstanding, 2),
                'ledger_outstanding' => round($ledgerOutstanding, 2),
                'discrepancy'       => $discrepancy,
                'status'            => abs($discrepancy) < 0.01 ? 'balanced' : 'discrepancy',
            ];

            $processedPartners->push($partnerId);
        }

        foreach ($creditorDemands as $row) {
            $partnerId = (int) $row->partner_branch_id;
            $debtorBranchId = min($branchId, $partnerId);
            $creditorBranchId = max($branchId, $partnerId);

            $ledgerBalance = $this->getLedgerRunningBalance($debtorBranchId, $creditorBranchId);

            $demandOutstanding = (float) $row->total_outstanding;
            // As creditor, the ledger balance is from the opposite perspective
            $ledgerOutstanding = (float) ($ledgerBalance->running_balance ?? 0);
            $discrepancy = round($demandOutstanding - $ledgerOutstanding, 2);

            $reconciliation[] = [
                'partner_branch_id' => $partnerId,
                'partner_branch'    => $branchNames[$partnerId] ?? 'Unknown',
                'role'              => 'creditor',
                'demand_count'      => (int) $row->demand_count,
                'total_demand_value' => round((float) $row->total_demand_value, 2),
                'total_settlement'  => round((float) $row->total_settlement, 2),
                'demand_outstanding' => round($demandOutstanding, 2),
                'ledger_outstanding' => round($ledgerOutstanding, 2),
                'discrepancy'       => $discrepancy,
                'status'            => abs($discrepancy) < 0.01 ? 'balanced' : 'discrepancy',
            ];
        }

        return $reconciliation;
    }

    /**
     * Get the latest running balance from branch_ledger for a debtor/creditor pair.
     */
    private function getLedgerRunningBalance(int $debtorBranchId, int $creditorBranchId)
    {
        return DB::table('branch_ledger')
            ->where('debtor_branch_id', $debtorBranchId)
            ->where('creditor_branch_id', $creditorBranchId)
            ->where('is_reversed', false)
            ->orderByDesc('id')
            ->first(['running_balance']);
    }
}

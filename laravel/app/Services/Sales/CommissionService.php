<?php

namespace App\Services\Sales;

use App\Models\CommissionRule;
use App\Models\CommissionEntry;
use App\Models\CommissionRuleTier;
use App\Models\CommissionRuleProductGroup;
use App\Models\CommissionRuleTarget;
use App\Models\SalesInvoice;
use App\Models\InvoicePaymentAllocation;
use App\Models\SalesReturn;
use App\Models\Employee;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\DocumentSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Commission Service — Task 37.
 *
 * Handles all commission calculation, confirmation, and reversal logic.
 *
 * CALCULATION TRIGGER:
 *   Commission is calculated when a payment is allocated to an invoice
 *   (on allocation creation). The amount is proportional to the payment:
 *     commission = allocated_amount * rate / 100
 *
 *   For flat rate: rate is the single rule rate.
 *   For tiered: rate depends on cumulative sales volume in the period.
 *   For product_group: rate depends on the product group of invoice items.
 *   For target_bonus: base rate + bonus rate if target is exceeded.
 *
 * RETURN REVERSAL:
 *   When a sales return is confirmed, a negative commission entry is created
 *   for the return amount at the original rate, reversing the commission
 *   earned on the returned items.
 *
 * CONFIRMATION (MONTH-END BATCH):
 *   Manager confirms all calculated entries for a period:
 *   1. GL posting: Dr Commission Expense / Cr Employee Payable
 *   2. Status → confirmed
 *
 * PAYMENT:
 *   When employee is paid, status → paid (via employee_transactions link).
 */
class CommissionService
{
    public function __construct(
        private JournalPostingService $journalPosting,
        private DocumentSequenceService $sequenceService,
        private SalesAuditLogger $auditLogger,
        private SalesAccess $salesAccess
    ) {}

    // ===================================================================
    // RULE MANAGEMENT
    // ===================================================================

    /**
     * Create a commission rule for a salesman.
     *
     * @param array $data Rule data (salesman_id, rule_type, rate, etc.)
     * @return CommissionRule
     */
    public function createRule(array $data): CommissionRule
    {
        return DB::transaction(function () use ($data) {
            // Close any existing active open-ended rule for this salesman
            if (isset($data['branch_id'])) {
                $this->closeExistingRule($data['salesman_id'], $data['branch_id'] ?? null);
            } else {
                $this->closeExistingRule($data['salesman_id'], null);
            }

            $rule = CommissionRule::create([
                'salesman_id' => $data['salesman_id'],
                'rule_type' => $data['rule_type'],
                'rate' => $data['rate'] ?? 0,
                'effective_from' => $data['effective_from'] ?? now()->toDateString(),
                'effective_to' => $data['effective_to'] ?? null,
                'is_active' => true,
                'branch_id' => $data['branch_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? auth()->id(),
            ]);

            // Create sub-rules based on type
            if ($rule->rule_type === 'tiered' && !empty($data['tiers'])) {
                foreach ($data['tiers'] as $i => $tier) {
                    CommissionRuleTier::create([
                        'commission_rule_id' => $rule->id,
                        'threshold' => $tier['threshold'],
                        'rate' => $tier['rate'],
                        'sort_order' => $i + 1,
                    ]);
                }
            }

            if ($rule->rule_type === 'product_group' && !empty($data['product_groups'])) {
                foreach ($data['product_groups'] as $pg) {
                    CommissionRuleProductGroup::create([
                        'commission_rule_id' => $rule->id,
                        'product_group_id' => $pg['product_group_id'],
                        'rate' => $pg['rate'],
                    ]);
                }
            }

            if ($rule->rule_type === 'target_bonus' && !empty($data['targets'])) {
                foreach ($data['targets'] as $target) {
                    CommissionRuleTarget::create([
                        'commission_rule_id' => $rule->id,
                        'target_amount' => $target['target_amount'],
                        'bonus_rate' => $target['bonus_rate'],
                        'period' => $target['period'] ?? 'monthly',
                    ]);
                }
            }

            $this->auditLogger->log('commission_rule_created', [
                'rule_id' => $rule->id,
                'salesman_id' => $rule->salesman_id,
                'rule_type' => $rule->rule_type,
                'rate' => $rule->rate,
            ]);

            return $rule;
        });
    }

    /**
     * Close the existing active rule by setting effective_to.
     */
    private function closeExistingRule(int $salesmanId, ?int $branchId): void
    {
        $query = CommissionRule::where('salesman_id', $salesmanId)
            ->where('is_active', true)
            ->whereNull('effective_to');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $existing = $query->first();
        if ($existing) {
            $existing->update([
                'effective_to' => now()->toDateString(),
                'is_active' => false,
            ]);
        }
    }

    /**
     * Get the active commission rule for a salesman on a given date.
     *
     * @param int $salesmanId
     * @param string $date Y-m-d format
     * @param int|null $branchId
     * @return CommissionRule|null
     */
    public function getActiveRule(int $salesmanId, string $date, ?int $branchId = null): ?CommissionRule
    {
        // First try branch-specific rule
        if ($branchId !== null) {
            $rule = CommissionRule::where('salesman_id', $salesmanId)
                ->where('is_active', true)
                ->where('effective_from', '<=', $date)
                ->where(function ($q) use ($date) {
                    $q->whereNull('effective_to')
                        ->orWhere('effective_to', '>=', $date);
                })
                ->where('branch_id', $branchId)
                ->first();
            if ($rule) {
                return $rule;
            }
        }

        // Fall back to global rule (branch_id IS NULL)
        return CommissionRule::where('salesman_id', $salesmanId)
            ->where('is_active', true)
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            })
            ->whereNull('branch_id')
            ->first();
    }

    // ===================================================================
    // COMMISSION CALCULATION — Triggered by payment allocation
    // ===================================================================

    /**
     * Calculate and create commission entry when a payment is allocated
     * to an invoice.
     *
     * Called from CustomerPaymentService::confirmPayment() after allocation.
     *
     * @param InvoicePaymentAllocation $allocation The payment allocation
     * @return CommissionEntry|null NULL if salesman has no commission rule
     */
    public function calculateOnAllocation(InvoicePaymentAllocation $allocation): ?CommissionEntry
    {
        // Load the invoice with items (need salesman_id and product groups)
        $invoice = SalesInvoice::with('items.product.group')
            ->find($allocation->invoice_id);

        if (!$invoice || !$invoice->salesman_id) {
            // No salesman assigned → no commission
            return null;
        }

        if ($invoice->isReversed() || $invoice->isCancelled()) {
            // Reversed/cancelled invoices don't earn commission
            return null;
        }

        $rule = $this->getActiveRule(
            $invoice->salesman_id,
            $invoice->invoice_date->toDateString(),
            $invoice->branch_id
        );

        if (!$rule || ($rule->rate == 0 && $rule->rule_type === 'flat')) {
            // No active rule or 0% flat rate → no commission
            return null;
        }

        // Calculate commission based on rule type
        $result = $this->calculateCommissionAmount($rule, $allocation, $invoice);

        $entry = CommissionEntry::create([
            'salesman_id' => $invoice->salesman_id,
            'branch_id' => $invoice->branch_id,
            'sales_invoice_id' => $invoice->id,
            'commission_rule_id' => $rule->id,
            'allocation_id' => $allocation->id,
            'invoice_total' => $invoice->total_amount,
            'commission_base' => $result['base'],
            'commission_rate' => $result['rate'],
            'commission_amount' => $result['amount'],
            'status' => 'calculated',
            'entry_date' => now()->toDateString(),
            'commission_period' => now()->format('Y-m'),
            'created_by' => auth()->id(),
        ]);

        $this->auditLogger->log('commission_calculated', [
            'entry_id' => $entry->id,
            'salesman_id' => $invoice->salesman_id,
            'invoice_id' => $invoice->id,
            'allocation_id' => $allocation->id,
            'commission_amount' => $entry->commission_amount,
            'rule_type' => $rule->rule_type,
        ]);

        return $entry;
    }

    /**
     * Calculate commission amount based on the rule type.
     *
     * @return array{base: string, rate: string, amount: string}
     */
    private function calculateCommissionAmount(
        CommissionRule $rule,
        InvoicePaymentAllocation $allocation,
        SalesInvoice $invoice
    ): array {
        return match ($rule->rule_type) {
            'flat' => $this->calculateFlat($rule, $allocation),
            'tiered' => $this->calculateTiered($rule, $allocation, $invoice),
            'product_group' => $this->calculateProductGroup($rule, $allocation, $invoice),
            'target_bonus' => $this->calculateTargetBonus($rule, $allocation, $invoice),
            default => ['base' => '0', 'rate' => '0', 'amount' => '0'],
        };
    }

    /**
     * FLAT: Simple rate on the allocated amount.
     */
    private function calculateFlat(CommissionRule $rule, InvoicePaymentAllocation $allocation): array
    {
        $base = (float) $allocation->allocated_amount;
        $rate = (float) $rule->rate;
        $amount = round($base * $rate / 100, 2);

        return [
            'base' => number_format($base, 2, '.', ''),
            'rate' => number_format($rate, 4, '.', ''),
            'amount' => number_format($amount, 2, '.', ''),
        ];
    }

    /**
     * TIERED: Progressive rates based on cumulative sales in the period.
     *
     * The allocated amount is applied to the tiers incrementally.
     * We first find the salesman's cumulative sales for the period,
     * then determine which tiers the allocation falls into.
     */
    private function calculateTiered(
        CommissionRule $rule,
        InvoicePaymentAllocation $allocation,
        SalesInvoice $invoice
    ): array {
        $allocatedAmount = (float) $allocation->allocated_amount;
        $period = now()->format('Y-m');

        // Get cumulative sales for this salesman in the current period
        // (before this allocation)
        $cumulativeSales = $this->getCumulativeSalesForPeriod(
            $invoice->salesman_id,
            $period,
            $invoice->branch_id,
            excludeAllocationId: $allocation->id
        );

        $tiers = $rule->tiers()->orderBy('threshold')->get();
        if ($tiers->isEmpty()) {
            // No tiers defined, fall back to flat rate
            return $this->calculateFlat($rule, $allocation);
        }

        $totalCommission = 0.0;
        $effectiveRate = 0.0;
        $remaining = $allocatedAmount;

        foreach ($tiers as $tier) {
            $tierStart = (float) $tier->threshold;
            $tierRate = (float) $tier->rate;
            $nextThreshold = $this->getNextTierThreshold($tiers, $tier);
            $tierEnd = $nextThreshold !== null ? (float) $nextThreshold : PHP_FLOAT_MAX;

            // How much of the salesman's sales are in this tier?
            $salesInTier = max(0, min($cumulativeSales + $allocatedAmount, $tierEnd) - max($cumulativeSales, $tierStart));
            $salesInTier = min($salesInTier, $remaining);

            if ($salesInTier > 0) {
                $totalCommission += $salesInTier * $tierRate / 100;
                $remaining -= $salesInTier;
                // Record the effective rate (rate of the tier where most allocation falls)
                if ($effectiveRate == 0 || $salesInTier > $allocatedAmount / 2) {
                    $effectiveRate = $tierRate;
                }
            }

            if ($remaining <= 0) {
                break;
            }
        }

        // Update cumulative sales after this allocation
        $cumulativeSales += $allocatedAmount;

        return [
            'base' => number_format($allocatedAmount, 2, '.', ''),
            'rate' => number_format($effectiveRate, 4, '.', ''),
            'amount' => number_format(round($totalCommission, 2), 2, '.', ''),
        ];
    }

    /**
     * PRODUCT_GROUP: Different rates per product group.
     *
     * Calculate commission per invoice item based on the product's group.
     * The allocation proportion is applied to each item.
     */
    private function calculateProductGroup(
        CommissionRule $rule,
        InvoicePaymentAllocation $allocation,
        SalesInvoice $invoice
    ): array {
        $allocatedAmount = (float) $allocation->allocated_amount;
        $invoiceTotal = (float) $invoice->total_amount;

        if ($invoiceTotal <= 0) {
            return ['base' => '0', 'rate' => '0', 'amount' => '0'];
        }

        // Load group-specific rates
        $groupRates = $rule->productGroups()->get()->keyBy('product_group_id');
        $defaultRate = (float) $rule->rate;

        $totalCommission = 0.0;
        $weightedRate = 0.0;

        foreach ($invoice->items as $item) {
            $itemAmount = (float) $item->amount - (float) $item->discount_amount;
            if ($itemAmount <= 0) {
                continue;
            }

            // Proportion of allocation applied to this item
            $proportion = $itemAmount / $invoiceTotal;
            $itemBase = $allocatedAmount * $proportion;

            // Get the rate for this product's group
            $product = $item->product;
            $groupId = $product?->group_id;
            $rate = $groupId && $groupRates->has($groupId)
                ? (float) $groupRates[$groupId]->rate
                : $defaultRate;

            $totalCommission += $itemBase * $rate / 100;
            $weightedRate += $rate * $proportion;
        }

        return [
            'base' => number_format($allocatedAmount, 2, '.', ''),
            'rate' => number_format(round($weightedRate, 4), 4, '.', ''),
            'amount' => number_format(round($totalCommission, 2), 2, '.', ''),
        ];
    }

    /**
     * TARGET_BONUS: Base rate + bonus when target is exceeded.
     *
     * The base rate applies to all allocated amounts.
     * If cumulative sales exceed the target, the bonus rate applies to
     * the portion above the target.
     */
    private function calculateTargetBonus(
        CommissionRule $rule,
        InvoicePaymentAllocation $allocation,
        SalesInvoice $invoice
    ): array {
        $allocatedAmount = (float) $allocation->allocated_amount;
        $baseRate = (float) $rule->rate;
        $period = now()->format('Y-m');

        // Get the target for this period
        $target = $rule->targets()->where('period', 'monthly')->first();
        if (!$target) {
            $target = $rule->targets()->first();
        }

        // Base commission always applies
        $baseCommission = $allocatedAmount * $baseRate / 100;

        $bonusCommission = 0.0;
        $effectiveRate = $baseRate;

        if ($target) {
            // Get cumulative sales before this allocation
            $cumulativeSales = $this->getCumulativeSalesForPeriod(
                $invoice->salesman_id,
                $period,
                $invoice->branch_id,
                excludeAllocationId: $allocation->id
            );

            $targetAmount = (float) $target->target_amount;
            $bonusRate = (float) $target->bonus_rate;

            // Check if the cumulative + this allocation crosses the target
            $beforeTarget = $cumulativeSales;
            $afterTarget = $cumulativeSales + $allocatedAmount;

            if ($afterTarget > $targetAmount && $targetAmount > $beforeTarget) {
                // The allocation crosses the target threshold
                $amountAboveTarget = $afterTarget - $targetAmount;
                $amountAboveTarget = min($amountAboveTarget, $allocatedAmount);
                $bonusCommission = $amountAboveTarget * $bonusRate / 100;
                $effectiveRate = $baseRate + $bonusRate;
            } elseif ($beforeTarget >= $targetAmount) {
                // Already above target — entire allocation gets bonus
                $bonusCommission = $allocatedAmount * $bonusRate / 100;
                $effectiveRate = $baseRate + $bonusRate;
            }
        }

        $totalCommission = $baseCommission + $bonusCommission;

        return [
            'base' => number_format($allocatedAmount, 2, '.', ''),
            'rate' => number_format(round($effectiveRate, 4), 4, '.', ''),
            'amount' => number_format(round($totalCommission, 2), 2, '.', ''),
        ];
    }

    // ===================================================================
    // COMMISSION REVERSAL — Triggered by sales return
    // ===================================================================

    /**
     * Create a negative commission entry when a sales return is confirmed.
     *
     * The reversal amount is based on the return's total_amount at the
     * rate that was originally applied to the invoice.
     *
     * @param SalesReturn $return The confirmed sales return
     * @return CommissionEntry|null
     */
    public function reverseOnReturn(SalesReturn $return): ?CommissionEntry
    {
        $invoice = SalesInvoice::find($return->sales_invoice_id);

        if (!$invoice || !$invoice->salesman_id) {
            return null;
        }

        // Find the original commission entries for this invoice
        $originalEntries = CommissionEntry::where('sales_invoice_id', $invoice->id)
            ->where('salesman_id', $invoice->salesman_id)
            ->where('status', '!=', 'reversed')
            ->whereNotNull('allocation_id')
            ->get();

        if ($originalEntries->isEmpty()) {
            return null;
        }

        // Calculate the proportion of the return vs the invoice total
        $returnAmount = (float) $return->total_amount;
        $invoiceTotal = (float) $invoice->total_amount;

        if ($invoiceTotal <= 0) {
            return null;
        }

        $reversalProportion = min($returnAmount / $invoiceTotal, 1.0);

        // Calculate the total commission to reverse
        $totalOriginalCommission = $originalEntries->sum(function ($entry) {
            return (float) $entry->commission_amount;
        });

        $reversalAmount = round($totalOriginalCommission * $reversalProportion, 2);

        if ($reversalAmount <= 0) {
            return null;
        }

        // Use the weighted average rate from original entries
        $weightedRate = 0;
        if ($totalOriginalCommission > 0) {
            $totalBase = $originalEntries->sum(fn($e) => (float) $e->commission_base);
            $weightedRate = $totalBase > 0
                ? ($totalOriginalCommission / $totalBase) * 100
                : (float) $originalEntries->first()->commission_rate;
        }

        $entry = CommissionEntry::create([
            'salesman_id' => $invoice->salesman_id,
            'branch_id' => $invoice->branch_id,
            'sales_invoice_id' => $invoice->id,
            'commission_rule_id' => $originalEntries->first()->commission_rule_id,
            'sales_return_id' => $return->id,
            'invoice_total' => $invoiceTotal,
            'commission_base' => number_format($returnAmount, 2, '.', ''),
            'commission_rate' => number_format(round($weightedRate, 4), 4, '.', ''),
            'commission_amount' => number_format(-$reversalAmount, 2, '.', ''), // NEGATIVE
            'status' => 'calculated',
            'entry_date' => now()->toDateString(),
            'commission_period' => now()->format('Y-m'),
            'notes' => "Reversal for return {$return->return_code}",
            'created_by' => auth()->id(),
        ]);

        $this->auditLogger->log('commission_reversed_on_return', [
            'entry_id' => $entry->id,
            'salesman_id' => $invoice->salesman_id,
            'return_id' => $return->id,
            'reversal_amount' => -$reversalAmount,
        ]);

        return $entry;
    }

    /**
     * Reverse a commission entry when a payment is reversed.
     *
     * @param InvoicePaymentAllocation $allocation The allocation being reversed
     * @return CommissionEntry|null
     */
    public function reverseOnPaymentReversal(InvoicePaymentAllocation $allocation): ?CommissionEntry
    {
        $originalEntry = CommissionEntry::where('allocation_id', $allocation->id)
            ->where('status', '!=', 'reversed')
            ->first();

        if (!$originalEntry) {
            return null;
        }

        // Mark the original entry as reversed
        $originalEntry->update([
            'status' => 'reversed',
            'is_reversed' => true,
            'reversed_at' => now(),
            'reversed_by' => auth()->id(),
            'reverse_reason' => 'Payment reversed',
        ]);

        // Create a negative reversal entry
        $entry = CommissionEntry::create([
            'salesman_id' => $originalEntry->salesman_id,
            'branch_id' => $originalEntry->branch_id,
            'sales_invoice_id' => $originalEntry->sales_invoice_id,
            'commission_rule_id' => $originalEntry->commission_rule_id,
            'allocation_id' => $allocation->id,
            'invoice_total' => $originalEntry->invoice_total,
            'commission_base' => $originalEntry->commission_base,
            'commission_rate' => $originalEntry->commission_rate,
            'commission_amount' => -((float) $originalEntry->commission_amount), // NEGATIVE
            'status' => 'calculated',
            'entry_date' => now()->toDateString(),
            'commission_period' => now()->format('Y-m'),
            'reversed_by_entry_id' => $originalEntry->id,
            'notes' => "Reversal of entry #{$originalEntry->id} (payment reversed)",
            'created_by' => auth()->id(),
        ]);

        $this->auditLogger->log('commission_reversed_on_payment_reversal', [
            'original_entry_id' => $originalEntry->id,
            'reversal_entry_id' => $entry->id,
            'allocation_id' => $allocation->id,
            'reversal_amount' => $entry->commission_amount,
        ]);

        return $entry;
    }

    // ===================================================================
    // CONFIRMATION — Month-end batch processing
    // ===================================================================

    /**
     * Confirm all calculated commission entries for a period.
     *
     * Posts GL entries: Dr Commission Expense / Cr Employee Payable
     *
     * @param string $period Format: '2025-01'
     * @param int $confirmedBy User ID confirming the batch
     * @return array{confirmed_count: int, total_amount: float, journal_entry_id: int|null}
     */
    public function confirmPeriod(string $period, int $confirmedBy): array
    {
        $this->salesAccess->assertAdmin();

        $entries = CommissionEntry::where('commission_period', $period)
            ->where('status', 'calculated')
            ->get();

        if ($entries->isEmpty()) {
            return ['confirmed_count' => 0, 'total_amount' => 0, 'journal_entry_id' => null];
        }

        return DB::transaction(function () use ($entries, $period, $confirmedBy) {
            // Group entries by salesman for per-salesman GL posting
            $bySalesman = $entries->groupBy('salesman_id');
            $totalAmount = 0;
            $confirmedCount = 0;
            $journalEntryId = null;

            foreach ($bySalesman as $salesmanId => $salesmanEntries) {
                $netCommission = $salesmanEntries->sum(fn($e) => (float) $e->commission_amount);

                if (abs($netCommission) < 0.01) {
                    // Net zero — just mark as confirmed without GL
                    foreach ($salesmanEntries as $entry) {
                        $entry->update(['status' => 'confirmed']);
                    }
                    $confirmedCount += $salesmanEntries->count();
                    continue;
                }

                // Post GL: Dr Commission Expense / Cr Employee Payable
                $salesman = Employee::find($salesmanId);
                $je = $this->journalPosting->postCommissionExpense([
                    'amount' => $netCommission,
                    'salesman_name' => $salesman?->name ?? "Employee #{$salesmanId}",
                    'period' => $period,
                    'description' => "Commission for {$period} — {$salesman?->name}",
                ]);

                $journalEntryId = $je->id;

                foreach ($salesmanEntries as $entry) {
                    $entry->update([
                        'status' => 'confirmed',
                        'journal_entry_id' => $je->id,
                    ]);
                    $confirmedCount++;
                }

                $totalAmount += $netCommission;
            }

            $this->auditLogger->log('commission_period_confirmed', [
                'period' => $period,
                'confirmed_count' => $confirmedCount,
                'total_amount' => $totalAmount,
                'confirmed_by' => $confirmedBy,
            ]);

            return [
                'confirmed_count' => $confirmedCount,
                'total_amount' => round($totalAmount, 2),
                'journal_entry_id' => $journalEntryId,
            ];
        });
    }

    /**
     * Mark commission entries as paid for a salesman in a period.
     *
     * This is called after an employee_transaction (type=repayment) is created
     * to pay the commission.
     *
     * @param int $salesmanId
     * @param string $period
     * @return int Number of entries marked as paid
     */
    public function markAsPaid(int $salesmanId, string $period): int
    {
        $entries = CommissionEntry::where('salesman_id', $salesmanId)
            ->where('commission_period', $period)
            ->where('status', 'confirmed')
            ->get();

        foreach ($entries as $entry) {
            $entry->update(['status' => 'paid']);
        }

        return $entries->count();
    }

    // ===================================================================
    // REPORTING
    // ===================================================================

    /**
     * Get commission summary for a salesman in a period.
     *
     * @return array{
     *   salesman: array,
     *   period: string,
     *   total_sales: float,
     *   total_commission: float,
     *   confirmed_commission: float,
     *   pending_commission: float,
     *   paid_commission: float,
     *   entries: \Illuminate\Database\Eloquent\Collection
     * }
     */
    public function getSalesmanSummary(int $salesmanId, string $period): array
    {
        $salesman = Employee::find($salesmanId);
        $entries = CommissionEntry::where('salesman_id', $salesmanId)
            ->where('commission_period', $period)
            ->orderBy('entry_date')
            ->get();

        $totalSales = $this->getCumulativeSalesForPeriod($salesmanId, $period);
        $totalCommission = $entries->sum(fn($e) => (float) $e->commission_amount);
        $confirmedCommission = $entries->where('status', 'confirmed')->sum(fn($e) => (float) $e->commission_amount);
        $pendingCommission = $entries->where('status', 'calculated')->sum(fn($e) => (float) $e->commission_amount);
        $paidCommission = $entries->where('status', 'paid')->sum(fn($e) => (float) $e->commission_amount);

        return [
            'salesman' => [
                'id' => $salesmanId,
                'name' => $salesman?->name,
                'employee_code' => $salesman?->employee_code,
            ],
            'period' => $period,
            'total_sales' => round($totalSales, 2),
            'total_commission' => round($totalCommission, 2),
            'confirmed_commission' => round($confirmedCommission, 2),
            'pending_commission' => round($pendingCommission, 2),
            'paid_commission' => round($paidCommission, 2),
            'entries' => $entries,
        ];
    }

    /**
     * Get commission summary for all salesmen in a branch/period.
     *
     * @return array
     */
    public function getBranchSummary(?int $branchId, string $period): array
    {
        $query = CommissionEntry::where('commission_period', $period);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $entries = $query->get();
        $salesmenIds = $entries->pluck('salesman_id')->unique();

        $summaries = [];
        foreach ($salesmenIds as $salesmanId) {
            $salesmanEntries = $entries->where('salesman_id', $salesmanId);
            $salesman = Employee::find($salesmanId);

            $summaries[] = [
                'salesman_id' => $salesmanId,
                'salesman_name' => $salesman?->name,
                'total_commission' => round($salesmanEntries->sum(fn($e) => (float) $e->commission_amount), 2),
                'pending_commission' => round($salesmanEntries->where('status', 'calculated')->sum(fn($e) => (float) $e->commission_amount), 2),
                'confirmed_commission' => round($salesmanEntries->where('status', 'confirmed')->sum(fn($e) => (float) $e->commission_amount), 2),
                'paid_commission' => round($salesmanEntries->where('status', 'paid')->sum(fn($e) => (float) $e->commission_amount), 2),
            ];
        }

        return [
            'period' => $period,
            'branch_id' => $branchId,
            'total_commission' => round($entries->sum(fn($e) => (float) $e->commission_amount), 2),
            'salesmen_count' => $salesmenIds->count(),
            'salesmen' => $summaries,
        ];
    }

    // ===================================================================
    // HELPERS
    // ===================================================================

    /**
     * Get cumulative sales for a salesman in a period.
     *
     * Sums total_amount from non-reversed, non-cancelled invoices where
     * the salesman is assigned and the invoice date falls within the period.
     */
    private function getCumulativeSalesForPeriod(
        int $salesmanId,
        string $period,
        ?int $branchId = null,
        ?int $excludeAllocationId = null
    ): float {
        // Parse period '2025-01' into date range
        $startDate = $period . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        $query = SalesInvoice::where('salesman_id', $salesmanId)
            ->where('invoice_date', '>=', $startDate)
            ->where('invoice_date', '<=', $endDate)
            ->whereNotIn('status', ['cancelled', 'reversed'])
            ->where('is_reversed', false);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return (float) $query->sum('total_amount');
    }

    /**
     * Get the next tier's threshold after the given tier.
     */
    private function getNextTierThreshold($tiers, $currentTier): ?string
    {
        $currentIndex = $tiers->search(fn($t) => $t->id === $currentTier->id);
        $nextTier = $tiers->get($currentIndex + 1);
        return $nextTier?->threshold;
    }
}

<?php

namespace App\Services\BranchDemand;

use App\Models\BranchDemand;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Branch Intercompany Service — Phase 3.
 *
 * Handles the full intercompany accounting for cross-branch demand transfers:
 *   - Dual GL journal entries (creditor + debtor)
 *   - Branch ledger with running balance
 *   - Reversal of both journals and ledger
 *
 * GL Posting Rules:
 *
 *   When goods are sent (demand fulfillment):
 *     ┌──────────────────────────────────────────────────────────────────────┐
 *     │ CREDITOR (SUPPLIER) JOURNAL — branch_id = to_branch_id (supplier) │
 *     │   Dr Due from Branches   (interbranch_receivable)  = total_value  │
 *     │   Cr Inventory           (inventory)               = total_value  │
 *     ├──────────────────────────────────────────────────────────────────────┤
 *     │ DEBTOR (REQUESTER) JOURNAL — branch_id = from_branch_id (requester)│
 *     │   Dr Inventory           (inventory)               = total_value  │
 *     │   Cr Due to Branches     (interbranch_payable)     = total_value  │
 *     └──────────────────────────────────────────────────────────────────────┘
 *
 *   When a demand is reversed:
 *     - Both journals are reversed (swap Dr/Cr, mark original is_reversed)
 *     - Both ledger entries are marked is_reversed
 *
 *   When a settlement occurs (Phase 4):
 *     ┌──────────────────────────────────────────────────────────────────────┐
 *     │ SETTLEMENT JOURNAL — posted on the debtor branch                   │
 *     │   Dr Due to Branches     (interbranch_payable)     = settled_amt  │
 *     │   Cr Cash/Bank           (cash_bank)               = settled_amt  │
 *     └──────────────────────────────────────────────────────────────────────┘
 *
 * Branch Ledger:
 *   Each financial event (demand transfer, settlement, reversal) creates a
 *   pair of rows in the branch_ledger:
 *     - From the debtor's perspective: debit (owes more) or credit (paid)
 *     - From the creditor's perspective: credit (owed more) or debit (received)
 *   The running_balance column tracks the net owed between each branch pair
 *   after each transaction. Positive = debtor owes creditor. Negative = creditor
 *   owes debtor.
 *
 * Terminology:
 *   - from_branch_id = requester (debtor) — the branch that NEEDS the products
 *   - to_branch_id   = supplier (creditor) — the branch that SUPPLIES the products
 */
class BranchIntercompanyService
{
    public function __construct(
        private JournalPostingService $journalPosting,
        private BranchDemandAuditLogger $auditLogger,
    ) {}

    // ===================== DEMAND FULFILLMENT =====================

    /**
     * Post two journal entries for a demand fulfillment:
     *   1. Creditor (supplier) journal: Dr Due from Branches / Cr Inventory
     *   2. Debtor (requester) journal: Dr Inventory / Cr Due to Branches
     *
     * Also records the branch ledger pair with running balance.
     *
     * @param BranchDemand $demand The demand that was just sent (status='received')
     * @param int $postedBy User ID who triggered the posting
     * @return array{ creditor_je_id: int, debtor_je_id: int }
     * @throws \RuntimeException If required ledger accounts not found
     */
    public function postDemandFulfillmentJournals(BranchDemand $demand, int $postedBy): array
    {
        $totalValue = (float) ($demand->total_value ?? 0);
        if ($totalValue <= 0) {
            throw new \RuntimeException("Cannot post fulfillment journals for demand with zero total_value.");
        }

        $creditorBranchId = (int) $demand->to_branch_id;   // supplier
        $debtorBranchId = (int) $demand->from_branch_id;    // requester

        // Resolve ledger accounts
        $interbranchReceivableId = $this->journalPosting->lookupLedgerByNature('interbranch_receivable');
        $interbranchPayableId = $this->journalPosting->lookupLedgerByNature('interbranch_payable');
        $inventoryId = $this->journalPosting->lookupLedgerByNature('inventory');

        if (!$interbranchReceivableId) {
            throw new \RuntimeException("GL account 'interbranch_receivable' (Due from Branches) not found. Please seed L-0105.");
        }
        if (!$interbranchPayableId) {
            throw new \RuntimeException("GL account 'interbranch_payable' (Due to Branches) not found. Please seed L-0303.");
        }
        if (!$inventoryId) {
            throw new \RuntimeException("GL account 'inventory' not found. Please seed the inventory ledger.");
        }

        $entryDate = $demand->demand_date ?? now()->format('Y-m-d');
        $demandCode = $demand->demand_code;

        // 1. Creditor (supplier) journal
        //    Dr Due from Branches (interbranch_receivable) = total_value
        //    Cr Inventory (inventory) = total_value
        $creditorJeId = $this->journalPosting->createJournalEntry([
            'entry_date'     => $entryDate,
            'reference_type' => 'branch_demand_fulfillment',
            'reference_id'   => (int) $demand->id,
            'branch_id'      => $creditorBranchId,
            'description'    => "Demand #{$demandCode} fulfillment: goods sent to requester branch",
            'source'         => 'branch_demand',
            'created_by'     => $postedBy,
        ], [
            [
                'ledger_id' => $interbranchReceivableId,
                'debit'     => $totalValue,
                'credit'    => 0,
                'memo'      => "Due from Branch for demand #{$demandCode}",
            ],
            [
                'ledger_id' => $inventoryId,
                'debit'     => 0,
                'credit'    => $totalValue,
                'memo'      => "Inventory issued for demand #{$demandCode}",
            ],
        ]);

        // 2. Debtor (requester) journal
        //    Dr Inventory (inventory) = total_value
        //    Cr Due to Branches (interbranch_payable) = total_value
        $debtorJeId = $this->journalPosting->createJournalEntry([
            'entry_date'     => $entryDate,
            'reference_type' => 'branch_demand_fulfillment',
            'reference_id'   => (int) $demand->id,
            'branch_id'      => $debtorBranchId,
            'description'    => "Demand #{$demandCode} fulfillment: goods received from supplier branch",
            'source'         => 'branch_demand',
            'created_by'     => $postedBy,
        ], [
            [
                'ledger_id' => $inventoryId,
                'debit'     => $totalValue,
                'credit'    => 0,
                'memo'      => "Inventory received from demand #{$demandCode}",
            ],
            [
                'ledger_id' => $interbranchPayableId,
                'debit'     => 0,
                'credit'    => $totalValue,
                'memo'      => "Due to Branch for demand #{$demandCode}",
            ],
        ]);

        // Update the demand header with the journal entry IDs
        DB::table('branch_demands')
            ->where('id', $demand->id)
            ->update([
                'journal_entry_id'        => $creditorJeId,
                'journal_entry_id_debtor'  => $debtorJeId,
                'updated_at'              => now(),
            ]);

        // Record branch ledger pair
        $this->recordDemandTransfer($demand, $creditorJeId, $debtorJeId, $postedBy);

        Log::info('BranchDemand fulfillment journals posted', [
            'demand_id'      => $demand->id,
            'demand_code'    => $demandCode,
            'total_value'    => $totalValue,
            'creditor_je_id' => $creditorJeId,
            'debtor_je_id'   => $debtorJeId,
            'posted_by'      => $postedBy,
        ]);

        return [
            'creditor_je_id' => $creditorJeId,
            'debtor_je_id'   => $debtorJeId,
        ];
    }

    // ===================== BRANCH LEDGER =====================

    /**
     * Record a branch ledger pair for a demand transfer.
     *
     * Creates two rows:
     *   1. Debtor row: debit = total_value (debtor owes more)
     *   2. Creditor row: credit = total_value (creditor is owed more)
     *
     * Both rows share the same running_balance, which is computed as:
     *   running_balance = previous_running_balance + total_value
     * (positive = debtor owes creditor)
     *
     * @param BranchDemand $demand
     * @param int $creditorJeId Creditor journal entry ID
     * @param int $debtorJeId Debtor journal entry ID
     * @param int $postedBy User ID
     */
    public function recordDemandTransfer(
        BranchDemand $demand,
        int $creditorJeId,
        int $debtorJeId,
        int $postedBy
    ): void {
        $totalValue = (float) ($demand->total_value ?? 0);
        $debtorBranchId = (int) $demand->from_branch_id;
        $creditorBranchId = (int) $demand->to_branch_id;
        $entryDate = $demand->demand_date ?? now()->format('Y-m-d');

        // Compute the running balance
        $previousBalance = $this->getRunningBalance($debtorBranchId, $creditorBranchId);
        $newBalance = $previousBalance + $totalValue;

        // Debtor row: debit = total_value (debtor owes more)
        DB::table('branch_ledger')->insert([
            'transaction_date' => $entryDate,
            'from_branch_id'   => $debtorBranchId,
            'to_branch_id'     => $creditorBranchId,
            'reference_type'   => 'demand_transfer',
            'reference_id'     => (int) $demand->id,
            'journal_entry_id' => $debtorJeId,
            'debit'            => $totalValue,
            'credit'           => 0,
            'running_balance'  => $newBalance,
            'remarks'          => "Demand #{$demand->demand_code} goods sent",
            'is_reversed'      => false,
            'created_by'       => $postedBy,
            'created_at'       => now(),
        ]);

        // Creditor row: credit = total_value (creditor is owed more)
        DB::table('branch_ledger')->insert([
            'transaction_date' => $entryDate,
            'from_branch_id'   => $debtorBranchId,
            'to_branch_id'     => $creditorBranchId,
            'reference_type'   => 'demand_transfer',
            'reference_id'     => (int) $demand->id,
            'journal_entry_id' => $creditorJeId,
            'debit'            => 0,
            'credit'           => $totalValue,
            'running_balance'  => $newBalance,
            'remarks'          => "Demand #{$demand->demand_code} goods sent",
            'is_reversed'      => false,
            'created_by'       => $postedBy,
            'created_at'       => now(),
        ]);
    }

    /**
     * Record a branch ledger pair for a settlement.
     *
     * Creates two rows:
     *   1. Debtor row: credit = settled_amount (debtor paid, owes less)
     *   2. Creditor row: debit = settled_amount (creditor received, is owed less)
     *
     * @param int $debtorBranchId
     * @param int $creditorBranchId
     * @param float $settledAmount
     * @param string $referenceType 'demand_settlement_bank' or 'demand_settlement_transfer'
     * @param int $referenceId The payment_id or transfer_id
     * @param int|null $journalEntryId The settlement journal entry ID
     * @param int $postedBy User ID
     * @param string $entryDate Y-m-d
     * @param string $remarks
     */
    public function recordDemandSettlement(
        int $debtorBranchId,
        int $creditorBranchId,
        float $settledAmount,
        string $referenceType,
        int $referenceId,
        ?int $journalEntryId,
        int $postedBy,
        string $entryDate,
        string $remarks = ''
    ): void {
        // Compute the running balance
        $previousBalance = $this->getRunningBalance($debtorBranchId, $creditorBranchId);
        $newBalance = $previousBalance - $settledAmount; // Settlement reduces the debt

        // Debtor row: credit = settled_amount (debtor paid, owes less)
        DB::table('branch_ledger')->insert([
            'transaction_date' => $entryDate,
            'from_branch_id'   => $debtorBranchId,
            'to_branch_id'     => $creditorBranchId,
            'reference_type'   => $referenceType,
            'reference_id'     => $referenceId,
            'journal_entry_id' => $journalEntryId,
            'debit'            => 0,
            'credit'           => $settledAmount,
            'running_balance'  => $newBalance,
            'remarks'          => $remarks,
            'is_reversed'      => false,
            'created_by'       => $postedBy,
            'created_at'       => now(),
        ]);

        // Creditor row: debit = settled_amount (creditor received, is owed less)
        DB::table('branch_ledger')->insert([
            'transaction_date' => $entryDate,
            'from_branch_id'   => $debtorBranchId,
            'to_branch_id'     => $creditorBranchId,
            'reference_type'   => $referenceType,
            'reference_id'     => $referenceId,
            'journal_entry_id' => $journalEntryId,
            'debit'            => $settledAmount,
            'credit'           => 0,
            'running_balance'  => $newBalance,
            'remarks'          => $remarks,
            'is_reversed'      => false,
            'created_by'       => $postedBy,
            'created_at'       => now(),
        ]);
    }

    // ===================== REVERSALS =====================

    /**
     * Reverse both creditor and debtor journals for a demand.
     *
     * Called when a demand is reversed. The journals are reversed using
     * JournalPostingService::reverseJournalEntry() which swaps Dr/Cr
     * and marks the original as is_reversed.
     *
     * @param BranchDemand $demand The demand being reversed
     * @param int $reversedBy User ID who reversed
     * @param string $reason Reversal reason
     * @return array{ creditor_reversal_je_id: int|null, debtor_reversal_je_id: int|null }
     */
    public function reverseDemandJournals(BranchDemand $demand, int $reversedBy, string $reason): array
    {
        $creditorJeId = $demand->journal_entry_id;
        $debtorJeId = $demand->journal_entry_id_debtor;

        $creditorReversalId = null;
        $debtorReversalId = null;

        // Reverse the creditor (supplier) journal
        if ($creditorJeId) {
            $creditorReversalId = $this->journalPosting->reverseJournalEntry(
                (int) $creditorJeId,
                $reversedBy,
                "Demand #{$demand->demand_code} reversal: {$reason}",
                $demand->demand_date
            );
        }

        // Reverse the debtor (requester) journal
        if ($debtorJeId) {
            $debtorReversalId = $this->journalPosting->reverseJournalEntry(
                (int) $debtorJeId,
                $reversedBy,
                "Demand #{$demand->demand_code} reversal: {$reason}",
                $demand->demand_date
            );
        }

        Log::info('BranchDemand fulfillment journals reversed', [
            'demand_id'               => $demand->id,
            'demand_code'             => $demand->demand_code,
            'creditor_reversal_je_id' => $creditorReversalId,
            'debtor_reversal_je_id'   => $debtorReversalId,
            'reversed_by'             => $reversedBy,
        ]);

        return [
            'creditor_reversal_je_id' => $creditorReversalId,
            'debtor_reversal_je_id'   => $debtorReversalId,
        ];
    }

    /**
     * Reverse branch ledger entries by reference (demand_transfer + demand_id).
     *
     * Marks all non-reversed ledger entries for this reference as is_reversed.
     * Also records a reversal entry pair that reduces the running balance.
     *
     * FINANCE-2 (G-108): the two counter-rows now carry the GL reversal JE id
     * (`journal_entry_id`), so the sub-ledger can be traced back to the
     * specific reversal JE posted by `JournalPostingService::reverseJournalEntry`.
     * Previously both rows were inserted with `journal_entry_id = null`, making
     * the `branch_ledger` ↔ GL reversal linkage one-directional (GL → ledger
     * via `reference_type='reversal'`, but not ledger → GL).
     *
     * @param string      $referenceType         e.g. 'demand_transfer'
     * @param int         $referenceId           The demand ID
     * @param int         $reversedBy            User ID
     * @param string      $reason                Reversal reason
     * @param string      $entryDate             Y-m-d for the reversal entry
     * @param int|null    $creditorReversalJeId  GL reversal JE id for the creditor row
     * @param int|null    $debtorReversalJeId    GL reversal JE id for the debtor row
     */
    public function reverseLedgerByReference(
        string $referenceType,
        int $referenceId,
        int $reversedBy,
        string $reason,
        string $entryDate,
        ?int $creditorReversalJeId = null,
        ?int $debtorReversalJeId = null
    ): void {
        // Find all non-reversed ledger entries for this reference
        $entries = DB::table('branch_ledger')
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('is_reversed', false)
            ->get();

        if ($entries->isEmpty()) {
            return; // Nothing to reverse
        }

        // Mark all entries as reversed
        DB::table('branch_ledger')
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('is_reversed', false)
            ->update([
                'is_reversed' => true,
            ]);

        // Compute the total value of the transfer being reversed
        // The debit side (debtor row) represents the amount owed
        $totalDebit = $entries->where('debit', '>', 0)->sum('debit');
        $totalCredit = $entries->where('credit', '>', 0)->sum('credit');
        $reversalAmount = max($totalDebit, $totalCredit);

        if ($reversalAmount <= 0) {
            return;
        }

        // Get the branch pair from the first entry
        $firstEntry = $entries->first();
        $debtorBranchId = (int) $firstEntry->from_branch_id;
        $creditorBranchId = (int) $firstEntry->to_branch_id;

        // Compute the new running balance (reversal reduces the debt)
        $previousBalance = $this->getRunningBalance($debtorBranchId, $creditorBranchId);
        $newBalance = $previousBalance - $reversalAmount;

        // Record reversal entries
        // Debtor row: debit = 0, credit = 0 for reversal, but the running balance changes
        // We record explicit reversal entries that mirror the original
        DB::table('branch_ledger')->insert([
            'transaction_date' => $entryDate,
            'from_branch_id'   => $debtorBranchId,
            'to_branch_id'     => $creditorBranchId,
            'reference_type'   => 'demand_reversal',
            'reference_id'     => $referenceId,
            'journal_entry_id' => $debtorReversalJeId, // G-108: link to GL reversal JE
            'debit'            => 0,
            'credit'           => $reversalAmount, // Debtor: credit = reversal (owes less)
            'running_balance'  => $newBalance,
            'remarks'          => "Reversal of demand transfer #{$referenceId}: {$reason}",
            'is_reversed'      => false,
            'created_by'       => $reversedBy,
            'created_at'       => now(),
        ]);

        DB::table('branch_ledger')->insert([
            'transaction_date' => $entryDate,
            'from_branch_id'   => $debtorBranchId,
            'to_branch_id'     => $creditorBranchId,
            'reference_type'   => 'demand_reversal',
            'reference_id'     => $referenceId,
            'journal_entry_id' => $creditorReversalJeId, // G-108: link to GL reversal JE
            'debit'            => $reversalAmount, // Creditor: debit = reversal (owed less)
            'credit'           => 0,
            'running_balance'  => $newBalance,
            'remarks'          => "Reversal of demand transfer #{$referenceId}: {$reason}",
            'is_reversed'      => false,
            'created_by'       => $reversedBy,
            'created_at'       => now(),
        ]);

        Log::info('BranchDemand ledger reversed', [
            'reference_type'  => $referenceType,
            'reference_id'    => $referenceId,
            'reversal_amount' => $reversalAmount,
            'new_balance'     => $newBalance,
            'reversed_by'     => $reversedBy,
        ]);
    }

    // ===================== QUERY METHODS =====================

    /**
     * Get the current running balance between a debtor and creditor branch.
     *
     * Positive = debtor owes creditor.
     * Negative = creditor owes debtor.
     * Zero = settled.
     *
     * Computed from the latest non-reversed branch_ledger entry.
     *
     * @param int $debtorBranchId
     * @param int $creditorBranchId
     * @return float
     */
    public function getRunningBalance(int $debtorBranchId, int $creditorBranchId): float
    {
        $latest = DB::table('branch_ledger')
            ->where('from_branch_id', $debtorBranchId)
            ->where('to_branch_id', $creditorBranchId)
            ->where('is_reversed', false)
            ->orderByDesc('id')
            ->first();

        return $latest ? (float) $latest->running_balance : 0.0;
    }

    /**
     * Get ledger history for a branch pair within a date range.
     *
     * Returns all non-reversed ledger entries ordered by transaction_date, id.
     *
     * @param int $debtorBranchId
     * @param int $creditorBranchId
     * @param string|null $dateFrom Y-m-d
     * @param string|null $dateTo Y-m-d
     * @return \Illuminate\Support\Collection
     */
    public function getLedgerHistory(
        int $debtorBranchId,
        int $creditorBranchId,
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): \Illuminate\Support\Collection {
        $query = DB::table('branch_ledger as bl')
            ->join('branches as fb', 'fb.id', '=', 'bl.from_branch_id')
            ->join('branches as tb', 'tb.id', '=', 'bl.to_branch_id')
            ->where('bl.from_branch_id', $debtorBranchId)
            ->where('bl.to_branch_id', $creditorBranchId)
            ->where('bl.is_reversed', false)
            ->select('bl.*', 'fb.branch_name as from_branch_name', 'tb.branch_name as to_branch_name');

        if ($dateFrom) {
            $query->where('bl.transaction_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('bl.transaction_date', '<=', $dateTo);
        }

        return $query->orderBy('bl.transaction_date')
            ->orderBy('bl.id')
            ->get();
    }

    /**
     * Get all outstanding amounts owed to/from a branch.
     *
     * Returns a collection of objects with:
     *   - partner_branch_id: the other branch in the pair
     *   - partner_branch_name: the other branch's name
     *   - running_balance: the net owed (positive = my branch is the debtor, negative = creditor)
     *   - total_demand_value: total value of all non-reversed, received demands
     *   - total_settled: total settlement amount
     *
     * @param int $branchId
     * @return \Illuminate\Support\Collection
     */
    public function getOutstandingByBranch(int $branchId): \Illuminate\Support\Collection
    {
        // Get all unique branch pairs where this branch is involved
        $pairs = DB::table('branch_ledger')
            ->where('is_reversed', false)
            ->where(function ($q) use ($branchId) {
                $q->where('from_branch_id', $branchId)
                  ->orWhere('to_branch_id', $branchId);
            })
            ->selectRaw('DISTINCT from_branch_id, to_branch_id')
            ->get();

        $result = [];

        foreach ($pairs as $pair) {
            $debtorBranchId = (int) $pair->from_branch_id;
            $creditorBranchId = (int) $pair->to_branch_id;

            // Determine the partner branch
            $partnerBranchId = ($debtorBranchId === $branchId) ? $creditorBranchId : $debtorBranchId;

            // Skip if we already have this partner
            $partnerKey = (string) $partnerBranchId;
            if (isset($result[$partnerKey])) {
                continue;
            }

            // Get the running balance
            $balance = $this->getRunningBalance($debtorBranchId, $creditorBranchId);

            // Get the partner branch name
            $partnerBranch = DB::table('branches')->where('id', $partnerBranchId)->first();
            $partnerName = $partnerBranch ? $partnerBranch->branch_name : "Branch #{$partnerBranchId}";

            // If this branch is the creditor, invert the sign
            // (positive = I am owed, negative = I owe)
            $displayBalance = ($creditorBranchId === $branchId) ? $balance : -$balance;

            // Get total demand value and settlement
            $demandStats = $this->getDemandStats($debtorBranchId, $creditorBranchId);

            $result[$partnerKey] = (object) [
                'partner_branch_id'   => $partnerBranchId,
                'partner_branch_name' => $partnerName,
                'running_balance'     => round($displayBalance, 2),
                'total_demand_value'  => round($demandStats['total_value'], 2),
                'total_settled'       => round($demandStats['total_settled'], 2),
                'total_outstanding'   => round($demandStats['total_value'] - $demandStats['total_settled'], 2),
            ];
        }

        return collect(array_values($result));
    }

    /**
     * Get demand statistics for a branch pair.
     *
     * @param int $debtorBranchId
     * @param int $creditorBranchId
     * @return array{ total_value: float, total_settled: float, count: int }
     */
    private function getDemandStats(int $debtorBranchId, int $creditorBranchId): array
    {
        $stats = DB::table('branch_demands')
            ->where('from_branch_id', $debtorBranchId)
            ->where('to_branch_id', $creditorBranchId)
            ->where('status', 'received')
            ->where('is_reversed', false)
            ->selectRaw('
                COALESCE(SUM(total_value), 0) as total_value,
                COALESCE(SUM(settlement_amount), 0) as total_settled,
                COUNT(*) as count
            ')
            ->first();

        return [
            'total_value'  => (float) ($stats->total_value ?? 0),
            'total_settled' => (float) ($stats->total_settled ?? 0),
            'count'        => (int) ($stats->count ?? 0),
        ];
    }

    // ===================== FIFO SETTLEMENT =====================

    /**
     * Settle branch demands from a bank customer payment.
     *
     * When a customer payment with payment_mode = 'bank' is confirmed at the
     * debtor branch, the bank is central (not branch-specific). This means the
     * debtor branch has effectively paid toward its obligation to the creditor branch.
     *
     * FIFO settlement: oldest demands are settled first.
     * Cash payments do NOT settle demands (they use money transfer instead).
     *
     * @param int $paymentId The customer_payments.id
     * @param int $branchId The debtor branch (where the payment was made)
     * @param float $amount The payment amount available for settlement
     * @param int $postedBy User ID who confirmed the payment
     * @return array{ total_settled: float, settlements: array }
     */
    public function settleFromCustomerPayment(
        int $paymentId,
        int $branchId,
        float $amount,
        int $postedBy
    ): array {
        if ($amount <= 0) {
            return ['total_settled' => 0, 'settlements' => []];
        }

        // Get all creditor branches with open demands for this debtor branch
        $creditorBranches = DB::table('branch_demands')
            ->where('from_branch_id', $branchId) // debtor
            ->where('status', 'received')
            ->where('is_reversed', false)
            ->whereColumn('total_value', '>', 'settlement_amount')
            ->distinct()
            ->pluck('to_branch_id');

        $totalSettled = 0.0;
        $remainingAmount = $amount;
        $allSettlements = [];

        foreach ($creditorBranches as $creditorBranchId) {
            if ($remainingAmount <= 0.01) {
                break;
            }

            $result = $this->fifoSettleDemands(
                debtorBranchId: $branchId,
                creditorBranchId: (int) $creditorBranchId,
                amount: $remainingAmount,
                referenceType: 'demand_settlement_bank',
                referenceId: $paymentId,
                postedBy: $postedBy,
                settlementTable: 'branch_demand_customer_payment_settlements',
                foreignKeyColumn: 'payment_id',
            );

            $totalSettled += $result['total_settled'];
            $remainingAmount -= $result['total_settled'];
            $allSettlements = array_merge($allSettlements, $result['settlements']);
        }

        Log::info('BranchDemand settlement from customer payment', [
            'payment_id'    => $paymentId,
            'branch_id'     => $branchId,
            'amount'        => $amount,
            'total_settled' => $totalSettled,
            'settlements_count' => count($allSettlements),
        ]);

        // ★ Phase 8 — Audit log for each settled demand
        foreach ($allSettlements as $settlement) {
            $this->auditLogger->log(
                (int) $settlement['demand_id'],
                'settle',
                $branchId,
                [
                    'source'            => 'customer_payment',
                    'payment_id'        => $paymentId,
                    'settled_amount'    => $settlement['settled_amount'],
                    'outstanding_before' => $settlement['outstanding_before'],
                    'outstanding_after' => $settlement['outstanding_after'],
                ],
                $postedBy
            );
        }

        return [
            'total_settled' => round($totalSettled, 2),
            'settlements'   => $allSettlements,
        ];
    }

    /**
     * Settle branch demands from a money transfer.
     *
     * When a cash_to_cash or cash_to_bank money transfer is made between branches,
     * it auto-settles open branch demands in FIFO order (oldest first).
     *
     * bank_to_cash / bank_to_bank at the same branch do NOT settle demands
     * (the bank is central, not branch-specific — those are settled via
     * customer payments instead).
     *
     * @param int $transferId The money_transfers.id
     * @param int $fromBranchId The debtor branch (sending money)
     * @param int $toBranchId The creditor branch (receiving money)
     * @param float $amount The transfer amount available for settlement
     * @param string $transferType The transfer type (cash_to_cash, cash_to_bank, etc.)
     * @param int $postedBy User ID who confirmed the transfer
     * @return array{ total_settled: float, settlements: array }
     */
    public function settleFromMoneyTransfer(
        int $transferId,
        int $fromBranchId,
        int $toBranchId,
        float $amount,
        string $transferType,
        int $postedBy
    ): array {
        // Only inter-branch transfers from debtor to creditor settle demands
        if (!in_array($transferType, ['cash_to_cash', 'cash_to_bank'])) {
            return ['total_settled' => 0, 'settlements' => []];
        }

        if ($fromBranchId === $toBranchId) {
            return ['total_settled' => 0, 'settlements' => []];
        }

        if ($amount <= 0) {
            return ['total_settled' => 0, 'settlements' => []];
        }

        $result = $this->fifoSettleDemands(
            debtorBranchId: $fromBranchId,
            creditorBranchId: $toBranchId,
            amount: $amount,
            referenceType: 'demand_settlement_transfer',
            referenceId: $transferId,
            postedBy: $postedBy,
            settlementTable: 'branch_demand_money_transfer_settlements',
            foreignKeyColumn: 'transfer_id',
        );

        Log::info('BranchDemand settlement from money transfer', [
            'transfer_id'   => $transferId,
            'from_branch'   => $fromBranchId,
            'to_branch'     => $toBranchId,
            'transfer_type' => $transferType,
            'amount'        => $amount,
            'total_settled' => $result['total_settled'],
            'settlements_count' => count($result['settlements']),
        ]);

        // ★ Phase 8 — Audit log for each settled demand
        foreach ($result['settlements'] as $settlement) {
            $this->auditLogger->log(
                (int) $settlement['demand_id'],
                'settle',
                $fromBranchId,
                [
                    'source'            => 'money_transfer',
                    'transfer_id'       => $transferId,
                    'settled_amount'    => $settlement['settled_amount'],
                    'outstanding_before' => $settlement['outstanding_before'],
                    'outstanding_after' => $settlement['outstanding_after'],
                ],
                $postedBy
            );
        }

        return $result;
    }

    /**
     * Preview which demands would be settled for a given amount.
     *
     * Used in the money transfer UI to show the user what will happen
     * before they confirm the transfer.
     *
     * @param int $debtorBranchId
     * @param int $creditorBranchId
     * @param float $amount
     * @return array{ total_settled: float, demands: array }
     */
    public function previewDemandSettlement(
        int $debtorBranchId,
        int $creditorBranchId,
        float $amount
    ): array {
        $openDemands = $this->getOpenDemandsForFifo($debtorBranchId, $creditorBranchId);

        $remainingAmount = $amount;
        $preview = [];

        foreach ($openDemands as $demand) {
            $outstanding = (float) $demand->total_value - (float) $demand->settlement_amount;
            if ($outstanding <= 0 || $remainingAmount <= 0.01) {
                continue;
            }

            $settleAmount = min($outstanding, $remainingAmount);
            $remainingAmount -= $settleAmount;

            $preview[] = [
                'demand_id'       => (int) $demand->id,
                'demand_code'     => $demand->demand_code,
                'demand_date'     => $demand->demand_date,
                'total_value'     => (float) $demand->total_value,
                'current_settled' => (float) $demand->settlement_amount,
                'outstanding'     => round($outstanding, 2),
                'would_settle'    => round($settleAmount, 2),
                'would_remain'    => round($outstanding - $settleAmount, 2),
            ];
        }

        return [
            'total_settled' => round($amount - $remainingAmount, 2),
            'demands'       => $preview,
        ];
    }

    /**
     * Reverse all customer payment settlements for a payment.
     *
     * Called when a customer payment is cancelled/reversed.
     * Reverses the settlement journals, ledger entries, and reduces
     * settlement_amount on the demands.
     *
     * @param int $paymentId
     * @param int $reversedBy
     * @param string $reason
     */
    public function reverseCustomerPaymentSettlements(
        int $paymentId,
        int $reversedBy,
        string $reason
    ): void {
        $this->reverseSettlementsByReference(
            referenceType: 'demand_settlement_bank',
            referenceId: $paymentId,
            settlementTable: 'branch_demand_customer_payment_settlements',
            foreignKeyColumn: 'payment_id',
            reversedBy: $reversedBy,
            reason: $reason,
        );
    }

    /**
     * Reverse all money transfer settlements for a transfer.
     *
     * Called when a money transfer is cancelled/reversed.
     *
     * @param int $transferId
     * @param int $reversedBy
     * @param string $reason
     */
    public function reverseMoneyTransferSettlements(
        int $transferId,
        int $reversedBy,
        string $reason
    ): void {
        $this->reverseSettlementsByReference(
            referenceType: 'demand_settlement_transfer',
            referenceId: $transferId,
            settlementTable: 'branch_demand_money_transfer_settlements',
            foreignKeyColumn: 'transfer_id',
            reversedBy: $reversedBy,
            reason: $reason,
        );
    }

    // ===================== FIFO PRIVATE HELPERS =====================

    /**
     * Get open (received, not fully settled, not reversed) demands for FIFO.
     *
     * Ordered by demand_date ASC, id ASC (oldest first).
     */
    private function getOpenDemandsForFifo(int $debtorBranchId, int $creditorBranchId): \Illuminate\Support\Collection
    {
        return DB::table('branch_demands')
            ->where('from_branch_id', $debtorBranchId)
            ->where('to_branch_id', $creditorBranchId)
            ->where('status', 'received')
            ->where('is_reversed', false)
            ->whereColumn('total_value', '>', 'settlement_amount')
            ->orderBy('demand_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Core FIFO settlement logic.
     *
     * For each open demand (oldest first), allocate as much as possible
     * until the available amount is exhausted.
     *
     * For each settlement:
     *   1. Create settlement row in the appropriate table
     *   2. Update branch_demands.settlement_amount
     *   3. Record branch_ledger pair
     *   4. Post settlement journal (Dr Due to Branches / Cr Cash/Bank)
     *
     * @return array{ total_settled: float, settlements: array }
     */
    private function fifoSettleDemands(
        int $debtorBranchId,
        int $creditorBranchId,
        float $amount,
        string $referenceType,
        int $referenceId,
        int $postedBy,
        string $settlementTable,
        string $foreignKeyColumn
    ): array {
        $openDemands = $this->getOpenDemandsForFifo($debtorBranchId, $creditorBranchId);

        $remainingAmount = $amount;
        $totalSettled = 0.0;
        $settlements = [];

        // Resolve ledger accounts for settlement journal
        $interbranchPayableId = $this->journalPosting->lookupLedgerByNature('interbranch_payable');
        $cashBankId = $this->journalPosting->lookupLedgerByNature('cash_bank');

        foreach ($openDemands as $demand) {
            $outstanding = (float) $demand->total_value - (float) $demand->settlement_amount;
            if ($outstanding <= 0 || $remainingAmount <= 0.01) {
                continue;
            }

            $settleAmount = min($outstanding, $remainingAmount);

            // 1. Create settlement row
            DB::table($settlementTable)->insert([
                $foreignKeyColumn => $referenceId,
                'demand_id'       => (int) $demand->id,
                'settled_amount'  => round($settleAmount, 2),
                'created_at'      => now(),
            ]);

            // 2. Update demand settlement_amount
            $newSettlementAmount = (float) $demand->settlement_amount + $settleAmount;
            DB::table('branch_demands')
                ->where('id', $demand->id)
                ->update([
                    'settlement_amount' => round($newSettlementAmount, 2),
                    'updated_at'        => now(),
                ]);

            // 3. Record branch ledger pair
            $demandDate = $demand->demand_date ?? now()->format('Y-m-d');
            $this->recordDemandSettlement(
                debtorBranchId: $debtorBranchId,
                creditorBranchId: $creditorBranchId,
                settledAmount: $settleAmount,
                referenceType: $referenceType,
                referenceId: $referenceId,
                journalEntryId: null, // Settlement journal posted per batch, not per demand
                postedBy: $postedBy,
                entryDate: $demandDate,
                remarks: "FIFO settlement for demand #{$demand->demand_code}",
            );

            $totalSettled += $settleAmount;
            $remainingAmount -= $settleAmount;

            $settlements[] = [
                'demand_id'      => (int) $demand->id,
                'demand_code'    => $demand->demand_code,
                'settled_amount' => round($settleAmount, 2),
                'outstanding_before' => round($outstanding, 2),
                'outstanding_after'  => round($outstanding - $settleAmount, 2),
            ];
        }

        // 4. Post a single settlement journal for the total amount settled
        if ($totalSettled > 0.01 && $interbranchPayableId && $cashBankId) {
            $this->journalPosting->createJournalEntry([
                'entry_date'     => now()->format('Y-m-d'),
                'reference_type' => $referenceType,
                'reference_id'   => $referenceId,
                'branch_id'      => $debtorBranchId,
                'description'    => "FIFO demand settlement — " . str_replace('demand_settlement_', '', $referenceType) . " #{$referenceId}",
                'source'         => 'branch_demand_settlement',
                'created_by'     => $postedBy,
            ], [
                [
                    'ledger_id' => $interbranchPayableId,
                    'debit'     => $totalSettled,
                    'credit'    => 0,
                    'memo'      => "Settlement of branch demands via {$referenceType} #{$referenceId}",
                ],
                [
                    'ledger_id' => $cashBankId,
                    'debit'     => 0,
                    'credit'    => $totalSettled,
                    'memo'      => "Bank payment for branch demand settlement #{$referenceId}",
                ],
            ]);
        }

        return [
            'total_settled' => round($totalSettled, 2),
            'settlements'   => $settlements,
        ];
    }

    /**
     * Reverse settlements by reference type and ID.
     *
     * Used by both reverseCustomerPaymentSettlements and reverseMoneyTransferSettlements.
     *
     * For each settlement:
     *   1. Reduce branch_demands.settlement_amount by the settled amount
     *   2. Delete the settlement rows
     *   3. Reverse the branch ledger entries
     *   4. Reverse the settlement journal
     */
    private function reverseSettlementsByReference(
        string $referenceType,
        int $referenceId,
        string $settlementTable,
        string $foreignKeyColumn,
        int $reversedBy,
        string $reason
    ): void {
        $settlements = DB::table($settlementTable)
            ->where($foreignKeyColumn, $referenceId)
            ->get();

        if ($settlements->isEmpty()) {
            return;
        }

        // Reverse each settlement
        foreach ($settlements as $settlement) {
            $demandId = (int) $settlement->demand_id;
            $settledAmount = (float) $settlement->settled_amount;

            // Reduce the demand's settlement_amount
            DB::table('branch_demands')
                ->where('id', $demandId)
                ->decrement('settlement_amount', round($settledAmount, 2));
        }

        // Delete the settlement rows
        DB::table($settlementTable)
            ->where($foreignKeyColumn, $referenceId)
            ->delete();

        // Reverse the settlement journal FIRST (if found) so we can link the
        // reversal branch_ledger rows to the GL reversal JE (G-108).
        $settlementReversalJeId = null;
        $journal = $this->journalPosting->findJournalEntryByReference($referenceType, $referenceId);
        if ($journal) {
            $settlementReversalJeId = $this->journalPosting->reverseJournalEntry(
                (int) $journal->id,
                $reversedBy,
                "Settlement reversal: {$reason}"
            );
        }

        // Reverse the branch ledger entries for this settlement, linking both
        // counter-rows to the single settlement reversal JE.
        $this->reverseLedgerByReference(
            $referenceType,
            $referenceId,
            $reversedBy,
            $reason,
            now()->format('Y-m-d'),
            $settlementReversalJeId,
            $settlementReversalJeId
        );

        Log::info('BranchDemand settlement reversed', [
            'reference_type'     => $referenceType,
            'reference_id'       => $referenceId,
            'settlements_count'  => $settlements->count(),
            'reversed_by'        => $reversedBy,
        ]);

        // ★ Phase 8 — Audit log for each reversed settlement
        foreach ($settlements as $settlement) {
            $this->auditLogger->log(
                (int) $settlement->demand_id,
                'settlement_reverse',
                null, // branch_id not available from settlement row
                [
                    'source'          => $referenceType,
                    'reference_id'    => $referenceId,
                    'reversed_amount' => (float) $settlement->settled_amount,
                    'reason'          => $reason,
                ],
                $reversedBy
            );
        }
    }
}

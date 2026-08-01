<?php

namespace App\Services\Accounting;

use App\Models\SupplierPayment;
use App\Models\SupplierLedger;
use App\Models\Bank;
use App\Models\BankLedgerMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Supplier Transaction Service — Phase 1 (Accounts Sub-Ledger).
 *
 * Handles the full lifecycle of supplier payments:
 *   create → confirm → reverse.
 *
 * Transaction types (transaction_type column, CHECK constraint):
 *   - payment:  Paying a supplier → Dr AP, Cr Bank/Cash
 *   - advance:  Advance payment to supplier → Dr AP, Cr Bank/Cash
 *   - receive:  Goods received on credit → Dr Inventory, Cr AP
 *
 * On confirm (atomic operations):
 *   1. GL: Type-specific journal entry (see postPaymentGL)
 *   2. Supplier ledger: debit entry for payment/advance, credit for receive
 *   3. GRN allocation (optional): supplier_payment_settlements
 *   4. Bank balance sync (if bank mode)
 *   5. Intercompany settlement (if bank-mode + cross-branch): branch_ledger entry
 *
 * GL posting by transaction_type:
 *   payment/advance:  Dr AP / Cr Bank/Cash
 *   receive:          Dr Inventory / Cr AP
 */
class SupplierTransactionService
{
    public function __construct(
        private JournalPostingService $journalPosting,
        private JournalReversalService $journalReversal,
        private SubLedgerService $subLedger,
        private DocumentSequenceService $sequenceService,
    ) {}

    /**
     * Valid transaction types (matches DB CHECK constraint).
     */
    public const TRANSACTION_TYPES = ['payment', 'advance', 'receive'];

    /**
     * Transaction types that reduce AP (we owe less → debit supplier_ledger).
     */
    public const AP_REDUCTION_TYPES = ['payment', 'advance'];

    /**
     * Transaction types that increase AP (we owe more → credit supplier_ledger).
     */
    public const AP_INCREASE_TYPES = ['receive'];

    // ============================================================
    // CREATE + CONFIRM (single-step, like legacy)
    // ============================================================

    /**
     * Create and confirm a supplier payment in one step.
     *
     * Flow (atomic):
     * 1. Validate supplier is active
     * 2. Generate payment code (SP-YYYY-NNNNN)
     * 3. Insert supplier_payments record
     * 4. Post GL journal entry (via JournalPostingService)
     * 5. Insert supplier_ledger entry (via SubLedgerService)
     * 6. Link journal_entry_id back to supplier_payments
     * 7. GRN allocation (if provided)
     * 8. Bank balance sync (if bank mode)
     * 9. Intercompany settlement (if bank-mode + cross-branch)
     *
     * @param array $data {
     *     supplier_id: int,
     *     branch_id: int,
     *     payment_date: string (Y-m-d),
     *     transaction_type: string ('payment'|'advance'|'receive'),
     *     payment_mode: string ('cash'|'bank'|'mobile_banking'|'cheque'|'adjustment'),
     *     bank_id: int|null,
     *     amount: float,
     *     discount_amount: float,
     *     reference_no: string|null,
     *     collected_by: int|null,
     *     notes: string,
     *     created_by: int,
     *     allocations: array|null  [{purchase_receive_id, allocated_amount}, ...]
     * }
     * @return SupplierPayment
     */
    public function createPayment(array $data): SupplierPayment
    {
        $this->validateCreateInput($data);

        $paymentCode = $this->generatePaymentCode($data['transaction_type'] ?? 'payment');
        $supplierId = (int) $data['supplier_id'];
        $branchId = (int) $data['branch_id'];
        $transactionType = $data['transaction_type'] ?? 'payment';
        $amount = round((float) $data['amount'], 2);

        return DB::transaction(function () use ($data, $paymentCode, $supplierId, $branchId, $transactionType, $amount) {
            // 1. Insert supplier_payments record.
            $paymentId = DB::table('supplier_payments')->insertGetId([
                'payment_code'    => $paymentCode,
                'payment_date'    => $data['payment_date'] ?? now()->format('Y-m-d'),
                'supplier_id'     => $supplierId,
                'branch_id'       => $branchId,
                'bank_id'         => $data['bank_id'] ?? null,
                'payment_mode'    => $data['payment_mode'] ?? 'cash',
                'transaction_type'=> $transactionType,
                'amount'          => $amount,
                'discount_amount' => round((float) ($data['discount_amount'] ?? 0), 2),
                'reference_no'    => $data['reference_no'] ?? null,
                'collected_by'    => $data['collected_by'] ?? null,
                'is_reversed'     => false,
                'notes'           => $data['notes'] ?? null,
                'created_by'      => $data['created_by'] ?? null,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            // 2. Reload as Eloquent model.
            $payment = SupplierPayment::with(['supplier', 'branch', 'bank'])->find($paymentId);

            // 3. Post GL journal entry.
            $journalEntryId = $this->postPaymentGL($payment, (int) ($data['created_by'] ?? 0));

            // 4. Post supplier_ledger entry.
            $this->postSupplierLedgerForType($payment, $journalEntryId, (int) ($data['created_by'] ?? 0));

            // 5. GRN allocation (if provided).
            $allocations = $data['allocations'] ?? [];
            $totalAllocated = 0.0;
            foreach ($allocations as $alloc) {
                $receiveId = (int) ($alloc['purchase_receive_id'] ?? 0);
                $allocatedAmount = (float) ($alloc['allocated_amount'] ?? 0);
                if ($receiveId > 0 && $allocatedAmount > 0.001) {
                    $this->allocateToGRN($paymentId, $receiveId, $allocatedAmount);
                    $totalAllocated += $allocatedAmount;
                }
            }

            // Validate: total allocations must not exceed payment amount.
            if ($totalAllocated > $amount + 0.01) {
                throw new \RuntimeException(
                    "Total allocations ({$totalAllocated}) exceed payment amount ({$amount})."
                );
            }

            // 6. Intercompany settlement (if bank-mode + payment/advance types).
            $intercompanyJournalId = null;
            if ($payment->isBankMode() && in_array($transactionType, self::AP_REDUCTION_TYPES)) {
                $intercompanyJournalId = $this->postIntercompanySettlement($payment, (int) ($data['created_by'] ?? 0));
            }

            // 7. Update payment with journal IDs.
            DB::table('supplier_payments')
                ->where('id', $paymentId)
                ->update([
                    'journal_entry_id' => $journalEntryId,
                    'intercompany_journal_entry_id' => $intercompanyJournalId,
                    'updated_at' => now(),
                ]);

            // 8. Sync bank balance (if bank mode).
            if ($payment->isBankMode() && $payment->bank_id) {
                $this->syncBankBalance($payment->bank_id, $amount, $transactionType);
            }

            // 9. Audit log.
            $this->logAudit('supplier_payment_created', (int) ($data['created_by'] ?? 0), $paymentId, [
                'payment_code'     => $paymentCode,
                'transaction_type' => $transactionType,
                'amount'           => $amount,
                'supplier_id'      => $supplierId,
                'journal_entry_id' => $journalEntryId,
            ]);

            return SupplierPayment::with([
                'supplier', 'branch', 'bank',
                'journalEntry.lines.ledger',
                'intercompanyJournalEntry.lines.ledger',
            ])->find($paymentId);
        });
    }

    // ============================================================
    // REVERSE (CANCEL)
    // ============================================================

    /**
     * Reverse a supplier payment — reverse GL + ledger + allocation + bank balance.
     *
     * @param int $paymentId
     * @param int $reversedBy
     * @param string $reason
     * @return SupplierPayment
     */
    public function reversePayment(int $paymentId, int $reversedBy, string $reason = ''): SupplierPayment
    {
        return DB::transaction(function () use ($paymentId, $reversedBy, $reason) {
            $payment = SupplierPayment::lockForUpdate()->find($paymentId);

            if (!$payment) {
                throw new \RuntimeException("Payment {$paymentId} not found.");
            }
            if ($payment->is_reversed) {
                throw new \RuntimeException("Payment is already reversed.");
            }
            if (strlen(trim($reason)) < 3) {
                throw new \RuntimeException('Reversal reason is required (min 3 characters).');
            }

            // 1. Reverse GL + linked supplier_ledger via JournalReversalService (cascade).
            if ($payment->journal_entry_id) {
                $this->journalReversal->reverseByJournalEntry(
                    $payment->journal_entry_id,
                    $reversedBy,
                    "Supplier payment reversal: {$reason}"
                );
            }

            // 2. Reverse ALL intercompany GL entries for this payment.
            //    postIntercompanySettlement() posts TWO entries (creditor at
            //    the bank's branch + debtor at the payment's branch), both
            //    tagged reference_type='supplier_payment_intercompany'. Only
            //    the debtor id is stored in intercompany_journal_entry_id, so
            //    we reverse by reference lookup to catch both (and any future
            //    siblings). Idempotent: skip entries already reversed.
            $intercompanyJeIds = DB::table('journal_entries')
                ->where('reference_type', 'supplier_payment_intercompany')
                ->where('reference_id', $paymentId)
                ->where('is_reversed', false)
                ->pluck('id');

            foreach ($intercompanyJeIds as $icJeId) {
                $this->journalReversal->reverseByJournalEntry(
                    (int) $icJeId,
                    $reversedBy,
                    "Supplier payment reversal: {$reason}"
                );
            }

            // Also reverse the branch_ledger obligation row.
            // NOTE: branch_ledger has NO updated_at column (only created_at),
            // so we set only is_reversed. See migration 2026_07_29_000013
            // _create_branch_ledger_table.php for the schema.
            DB::table('branch_ledger')
                ->where('reference_type', 'supplier_payment')
                ->where('reference_id', $paymentId)
                ->where('is_reversed', false)
                ->update([
                    'is_reversed' => true,
                ]);

            // 3. Reverse GRN allocations.
            $allocations = DB::table('supplier_payment_settlements')
                ->where('payment_id', $paymentId)
                ->get();

            foreach ($allocations as $allocation) {
                // Restore GRN paid_amount (subtract the settled amount).
                DB::table('purchase_receives')
                    ->where('id', $allocation->purchase_receive_id)
                    ->update([
                        'paid_amount' => DB::raw('GREATEST(0, paid_amount - ' . (float) $allocation->settled_amount . ')'),
                        'updated_at' => now(),
                    ]);
            }

            // Delete allocations.
            DB::table('supplier_payment_settlements')->where('payment_id', $paymentId)->delete();

            // 4. Mark payment as reversed.
            DB::table('supplier_payments')
                ->where('id', $paymentId)
                ->update([
                    'is_reversed'    => true,
                    'reversed_at'    => now(),
                    'reversed_by'    => $reversedBy,
                    'reverse_reason' => $reason,
                    'updated_at'     => now(),
                ]);

            // 5. Undo bank balance sync.
            if ($payment->isBankMode() && $payment->bank_id) {
                $this->syncBankBalance(
                    $payment->bank_id,
                    (float) $payment->amount,
                    $payment->transaction_type ?? 'payment',
                    undo: true
                );
            }

            // 6. Audit log.
            $this->logAudit('supplier_payment_reversed', $reversedBy, $paymentId, [
                'payment_code' => $payment->payment_code,
                'reason'       => $reason,
            ]);

            return SupplierPayment::find($paymentId);
        });
    }

    // ============================================================
    // QUERY HELPERS
    // ============================================================

    /**
     * Get supplier's current AP balance (what we owe).
     */
    public function getSupplierDue(int $supplierId): float
    {
        return SupplierLedger::getBalance($supplierId);
    }

    /**
     * Get filtered supplier payments with pagination.
     */
    public function getFilteredPayments(array $filters = [], ?int $branchId = null, int $perPage = 25)
    {
        $query = SupplierPayment::with(['supplier', 'branch', 'bank', 'collectedBy'])
            ->when($filters['date_from'] ?? null, fn($q, $d) => $q->where('payment_date', '>=', $d))
            ->when($filters['date_to'] ?? null, fn($q, $d) => $q->where('payment_date', '<=', $d))
            ->when($filters['supplier_id'] ?? null, fn($q, $sid) => $q->where('supplier_id', $sid))
            ->when($filters['branch_id'] ?? null, fn($q, $bid) => $q->where('branch_id', $bid))
            ->when(($filters['payment_mode'] ?? null) && $filters['payment_mode'] !== 'all', fn($q, $m) => $q->where('payment_mode', $m))
            ->when(($filters['transaction_type'] ?? null) && $filters['transaction_type'] !== 'all', fn($q, $t) => $q->where('transaction_type', $t))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where('payment_code', 'ILIKE', "%{$search}%");
            });

        // Status filter.
        if (($filters['status'] ?? 'all') === 'reversed') {
            $query->where('is_reversed', true);
        } elseif (($filters['status'] ?? 'all') === 'active') {
            $query->where('is_reversed', false);
        }

        // No default date filter — show all records when no dates provided.
        // This matches the summary cards which have no date restriction.

        return $query->orderBy('payment_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get summary stats for the index page.
     */
    public function getStats(?int $branchId = null): array
    {
        $baseQuery = SupplierPayment::query();
        if ($branchId) {
            $baseQuery->where('branch_id', $branchId);
        }

        return [
            'total'        => (clone $baseQuery)->count(),
            'total_amount' => (float) (clone $baseQuery)->where('is_reversed', false)->sum('amount'),
            'cash'         => (float) (clone $baseQuery)->where('is_reversed', false)->where('payment_mode', 'cash')->sum('amount'),
            'bank'         => (float) (clone $baseQuery)->where('is_reversed', false)->where('payment_mode', 'bank')->sum('amount'),
            'reversed'     => (clone $baseQuery)->where('is_reversed', true)->count(),
            'payments'     => (float) (clone $baseQuery)->where('is_reversed', false)->where('transaction_type', 'payment')->sum('amount'),
            'advances'     => (float) (clone $baseQuery)->where('is_reversed', false)->where('transaction_type', 'advance')->sum('amount'),
            'receives'     => (float) (clone $baseQuery)->where('is_reversed', false)->where('transaction_type', 'receive')->sum('amount'),
            'out_today'    => (float) (clone $baseQuery)->where('is_reversed', false)
                ->whereIn('transaction_type', self::AP_REDUCTION_TYPES)
                ->where('payment_date', now()->format('Y-m-d'))
                ->sum('amount'),
            'out_month'    => (float) (clone $baseQuery)->where('is_reversed', false)
                ->whereIn('transaction_type', self::AP_REDUCTION_TYPES)
                ->whereMonth('payment_date', now()->month)
                ->whereYear('payment_date', now()->year)
                ->sum('amount'),
        ];
    }

    /**
     * Get GL preview labels for the create form.
     */
    public function getGlPreviewLabels(): array
    {
        return [
            'payment' => 'Dr AP · Cr Bank/Cash',
            'advance' => 'Dr AP · Cr Bank/Cash',
            'receive' => 'Dr Inventory · Cr AP',
        ];
    }

    // ============================================================
    // GL POSTING — TYPE-SPECIFIC
    // ============================================================

    /**
     * Post GL journal entry based on transaction_type.
     *
     * payment/advance:  Dr AP / Cr Bank/Cash
     * receive:          Dr Inventory / Cr AP
     *
     * @return int journal_entry_id
     */
    private function postPaymentGL(SupplierPayment $payment, int $createdBy): int
    {
        $amount = (float) $payment->amount;
        if ($amount < 0.01) return 0;

        $apLedgerId = $this->journalPosting->lookupLedgerByNature('ap');
        if (!$apLedgerId) {
            throw new \RuntimeException('Accounts Payable ledger not found (nature: ap). Configure in Chart of Accounts.');
        }

        $transactionType = $payment->transaction_type ?? 'payment';
        $lines = [];

        switch ($transactionType) {
            case 'payment':
            case 'advance':
                $lines = $this->buildPaymentGL($payment, $apLedgerId, $amount, $transactionType);
                break;

            case 'receive':
                $lines = $this->buildReceiveGL($payment, $apLedgerId, $amount);
                break;

            default:
                throw new \RuntimeException("Unknown transaction_type: {$transactionType}");
        }

        // Build description based on type.
        $typeLabels = [
            'payment' => 'Supplier Payment',
            'advance' => 'Supplier Advance',
            'receive' => 'Supplier Credit Receive',
        ];
        $label = $typeLabels[$transactionType] ?? 'Supplier Payment';

        return $this->journalPosting->createJournalEntry([
            'entry_date' => $payment->payment_date->format('Y-m-d'),
            'reference_type' => 'supplier_payment',
            'reference_id' => $payment->id,
            'branch_id' => $payment->branch_id,
            'description' => $label . ' ' . $payment->payment_code
                . ($payment->supplier ? ' — ' . $payment->supplier->supplier_name : '')
                . ($payment->notes ? ' — ' . $payment->notes : ''),
            'source' => 'supplier_payment_' . $transactionType,
            'created_by' => $createdBy,
        ], $lines);
    }

    /**
     * Build GL lines for 'payment' / 'advance' type.
     *   Dr AP / Cr Bank/Cash
     */
    private function buildPaymentGL(SupplierPayment $payment, int $apLedgerId, float $amount, string $transactionType): array
    {
        $lines = [];

        // Debit side: AP (we owe less).
        $lines[] = [
            'ledger_id' => $apLedgerId,
            'debit' => $amount, 'credit' => 0,
            'entity_type' => 'supplier', 'entity_id' => $payment->supplier_id,
            'memo' => 'Supplier ' . $transactionType . ' — ' . $payment->payment_code,
        ];

        // Credit side: Bank or Cash.
        $creditLedgerId = $this->resolveCreditLedger($payment);
        $lines[] = [
            'ledger_id' => $creditLedgerId,
            'debit' => 0, 'credit' => $amount,
            'entity_type' => $payment->isBankMode() ? 'bank' : 'supplier_payment',
            'entity_id' => $payment->isBankMode() ? $payment->bank_id : $payment->id,
            'memo' => ucfirst($transactionType) . ' paid — ' . $payment->payment_code,
        ];

        return $lines;
    }

    /**
     * Build GL lines for 'receive' type.
     *   Dr Inventory / Cr AP
     */
    private function buildReceiveGL(SupplierPayment $payment, int $apLedgerId, float $amount): array
    {
        $lines = [];

        $inventoryLedgerId = $this->journalPosting->lookupLedgerByNature('inventory');
        if (!$inventoryLedgerId) {
            throw new \RuntimeException('Inventory ledger not found (nature: inventory). Configure in Chart of Accounts.');
        }

        // Debit side: Inventory (goods received).
        $lines[] = [
            'ledger_id' => $inventoryLedgerId,
            'debit' => $amount, 'credit' => 0,
            'entity_type' => 'supplier', 'entity_id' => $payment->supplier_id,
            'memo' => 'Supplier credit receive — ' . $payment->payment_code,
        ];

        // Credit side: AP (we owe more).
        $lines[] = [
            'ledger_id' => $apLedgerId,
            'debit' => 0, 'credit' => $amount,
            'entity_type' => 'supplier', 'entity_id' => $payment->supplier_id,
            'memo' => 'AP increase — ' . $payment->payment_code,
        ];

        return $lines;
    }

    // ============================================================
    // SUPPLIER LEDGER (SUB-LEDGER)
    // ============================================================

    /**
     * Post supplier_ledger entry via SubLedgerService.
     *
     * payment/advance: debit (reduce AP — we owe less)
     * receive: credit (increase AP — we owe more)
     */
    private function postSupplierLedgerForType(SupplierPayment $payment, int $journalEntryId, int $createdBy): void
    {
        $amount = (float) $payment->amount;
        $transactionType = $payment->transaction_type ?? 'payment';

        // Determine debit/credit sides.
        if (in_array($transactionType, self::AP_REDUCTION_TYPES)) {
            $debit = $amount;
            $credit = 0.0;
        } else {
            $debit = 0.0;
            $credit = $amount;
        }

        $this->subLedger->postSupplierLedgerEntry([
            'supplier_id'     => $payment->supplier_id,
            'branch_id'       => $payment->branch_id,
            'transaction_date'=> $payment->payment_date->format('Y-m-d'),
            'transaction_type'=> $transactionType,
            'reference_type'  => 'supplier_payment',
            'reference_id'    => $payment->id,
            'debit'           => $debit,
            'credit'          => $credit,
            'description'     => $payment->notes ?? ucfirst($transactionType) . ' payment — ' . $payment->payment_code,
            'journal_entry_id'=> $journalEntryId,
            'created_by'      => $createdBy,
        ]);
    }

    // ============================================================
    // GRN ALLOCATION
    // ============================================================

    /**
     * Allocate payment amount to a specific GRN (purchase_receive).
     */
    private function allocateToGRN(int $paymentId, int $purchaseReceiveId, float $allocatedAmount): void
    {
        DB::table('supplier_payment_settlements')->insert([
            'payment_id'         => $paymentId,
            'purchase_receive_id'=> $purchaseReceiveId,
            'settled_amount'     => round($allocatedAmount, 2),
            'created_at'         => now(),
        ]);

        // Update GRN paid_amount.
        DB::table('purchase_receives')
            ->where('id', $purchaseReceiveId)
            ->update([
                'paid_amount' => DB::raw('paid_amount + ' . round($allocatedAmount, 2)),
                'updated_at'  => now(),
            ]);
    }

    // ============================================================
    // INTERCOMPANY SETTLEMENT
    // ============================================================

    /**
     * Post intercompany settlement for cross-branch bank-mode payments.
     *
     * When a supplier payment is recorded at Branch A but the bank belongs
     * to Branch B, Branch A used Branch B's bank to pay the supplier — so
     * Branch A owes Branch B the payment amount. This posts two balanced
     * intercompany journal entries (mirrors BranchIntercompanyService):
     *
     *   Creditor JE (at bank's branch / Branch B):
     *     Dr interbranch_receivable (Due from Branches)   amount
     *        Cr bank-ledger (the bank that funded it)      amount
     *
     *   Debtor JE (at payment's branch / Branch A):
     *     Dr bank-ledger (clearing — nets the main GL bank credit)  amount
     *        Cr interbranch_payable (Due to Branches)               amount
     *
     * Net effect on the shared bank-ledger: main GL Cr amount + creditor
     * Cr amount + debtor Dr amount = net Cr amount (matches the bank book
     * decrease from syncBankBalance). interbranch_receivable (asset) ↑ at
     * Branch B and interbranch_payable (liability) ↑ at Branch A — the
     * interbranch obligation. A branch_ledger row tracks the obligation
     * for cross-branch reconciliation.
     *
     * Returns the debtor JE id (stored in supplier_payments.
     * intercompany_journal_entry_id for the show page). The creditor JE is
     * linked by reference_type='supplier_payment_intercompany' and is
     * reversed alongside the debtor in reversePayment().
     *
     * Skips (returns null) when:
     *   - amount < 0.01 or no bank_id
     *   - bank has no branch_id (NULL = shared / head-office bank)
     *   - bank's branch == payment's branch (same branch, no intercompany)
     *   - interbranch ledgers not configured (logs a warning)
     */
    private function postIntercompanySettlement(SupplierPayment $payment, int $createdBy): ?int
    {
        $amount = (float) $payment->amount;
        if ($amount < 0.01 || !$payment->bank_id) {
            return null;
        }

        // Load the bank with its branch_id (added by migration
        // 2026_08_06_000001_add_branch_id_to_banks). NULL = shared bank.
        $bank = Bank::find($payment->bank_id);
        if (!$bank) {
            return null;
        }
        $bankBranchId = $bank->branch_id ? (int) $bank->branch_id : null;
        if (!$bankBranchId) {
            // Shared / head-office bank — no intercompany needed.
            return null;
        }

        $fromBranchId = (int) $payment->branch_id;   // payment's branch (debtor)
        $toBranchId = $bankBranchId;                  // bank's branch (creditor)

        // Same branch — no intercompany needed.
        if ($toBranchId === $fromBranchId) {
            return null;
        }

        // Resolve interbranch ledgers (seeded L-0105 / L-0303).
        $dueFromLedgerId = $this->journalPosting->lookupLedgerByNature('interbranch_receivable');
        $dueToLedgerId = $this->journalPosting->lookupLedgerByNature('interbranch_payable');
        if (!$dueFromLedgerId || !$dueToLedgerId) {
            Log::warning('Intercompany ledgers not configured, skipping supplier settlement', [
                'payment_id'      => $payment->id,
                'payment_code'    => $payment->payment_code,
                'from_branch_id'  => $fromBranchId,
                'to_branch_id'    => $toBranchId,
            ]);
            return null;
        }

        // Resolve the bank's mapped ledger (falls back to cash_bank nature).
        $bankLedgerId = $this->resolveCreditLedger($payment);

        $entryDate = $payment->payment_date->format('Y-m-d');
        $paymentCode = $payment->payment_code;

        // 1. Creditor journal (at the bank's branch):
        //    Dr interbranch_receivable / Cr bank-ledger
        //    "Branch B's bank funded Branch A's supplier payment; Branch B
        //     is now owed amount by Branch A."
        $creditorJeId = $this->journalPosting->createJournalEntry([
            'entry_date'     => $entryDate,
            'reference_type' => 'supplier_payment_intercompany',
            'reference_id'   => $payment->id,
            'branch_id'      => $toBranchId,
            'description'    => "Intercompany — Supplier payment {$paymentCode} (bank at branch {$toBranchId})",
            'source'         => 'supplier_payment_intercompany',
            'created_by'     => $createdBy,
        ], [
            [
                'ledger_id' => $dueFromLedgerId,
                'debit'     => $amount,
                'credit'    => 0,
                'memo'      => "Due from branch {$fromBranchId} — supplier payment {$paymentCode}",
            ],
            [
                'ledger_id' => $bankLedgerId,
                'debit'     => 0,
                'credit'    => $amount,
                'memo'      => "Bank funded supplier payment {$paymentCode} for branch {$fromBranchId}",
            ],
        ]);

        // 2. Debtor journal (at the payment's branch):
        //    Dr bank-ledger / Cr interbranch_payable
        //    "Branch A's supplier was paid using Branch B's bank; Branch A
        //     owes Branch B amount." The Dr bank-ledger nets the main GL's
        //     Cr bank-ledger at Branch A, so the shared bank-ledger control
        //     account still reconciles to the bank book.
        $debtorJeId = $this->journalPosting->createJournalEntry([
            'entry_date'     => $entryDate,
            'reference_type' => 'supplier_payment_intercompany',
            'reference_id'   => $payment->id,
            'branch_id'      => $fromBranchId,
            'description'    => "Intercompany — Supplier payment {$paymentCode} (paid from branch {$fromBranchId})",
            'source'         => 'supplier_payment_intercompany',
            'created_by'     => $createdBy,
        ], [
            [
                'ledger_id' => $bankLedgerId,
                'debit'     => $amount,
                'credit'    => 0,
                'memo'      => "Bank clearing — supplier payment {$paymentCode} via branch {$toBranchId} bank",
            ],
            [
                'ledger_id' => $dueToLedgerId,
                'debit'     => 0,
                'credit'    => $amount,
                'memo'      => "Due to branch {$toBranchId} — supplier payment {$paymentCode}",
            ],
        ]);

        // 3. Record the interbranch obligation in branch_ledger.
        DB::table('branch_ledger')->insert([
            'transaction_date' => $entryDate,
            'from_branch_id'   => $fromBranchId,
            'to_branch_id'     => $toBranchId,
            'reference_type'   => 'supplier_payment',
            'reference_id'     => $payment->id,
            'debit'            => $amount,
            'credit'           => 0,
            'remarks'          => "Supplier payment {$paymentCode} — bank at branch {$toBranchId}",
            'journal_entry_id' => $debtorJeId,
            'is_reversed'      => false,
            'created_by'       => $createdBy,
            'created_at'       => now(),
        ]);

        Log::info('Supplier payment intercompany settlement posted', [
            'payment_id'       => $payment->id,
            'payment_code'     => $paymentCode,
            'from_branch_id'   => $fromBranchId,
            'to_branch_id'     => $toBranchId,
            'amount'           => $amount,
            'creditor_je_id'   => $creditorJeId,
            'debtor_je_id'     => $debtorJeId,
        ]);

        // Return the debtor JE id (primary, stored in intercompany_journal_entry_id).
        // The creditor JE is linked by reference and reversed alongside in
        // reversePayment().
        return $debtorJeId;
    }

    // ============================================================
    // BANK BALANCE SYNC
    // ============================================================

    /**
     * Sync bank balance after a supplier transaction.
     *
     * payment/advance: money going out (decrease bank balance)
     * receive: no bank change (goods received on credit, not a cash movement)
     */
    private function syncBankBalance(int $bankId, float $amount, string $transactionType, bool $undo = false): void
    {
        $bank = Bank::find($bankId);
        if (!$bank) return;

        // payment/advance = money going out (decrease bank balance)
        $decrease = in_array($transactionType, self::AP_REDUCTION_TYPES);
        if ($undo) $decrease = !$decrease;

        if ($decrease) {
            $bank->decrement('balance', $amount);
        } else {
            $bank->increment('balance', $amount);
        }
    }

    // ============================================================
    // LEDGER RESOLUTION HELPERS
    // ============================================================

    /**
     * Resolve the credit ledger (Bank or Cash) for a payment.
     */
    private function resolveCreditLedger(SupplierPayment $payment): int
    {
        if ($payment->isBankMode() && $payment->bank_id) {
            $mapping = BankLedgerMapping::where('bank_id', $payment->bank_id)->first();
            if ($mapping) {
                return (int) $mapping->ledger_id;
            }
        }

        $cashBankLedgerId = $this->journalPosting->lookupLedgerByNature('cash_bank');
        if (!$cashBankLedgerId) {
            throw new \RuntimeException('Cash/Bank ledger not found (nature: cash_bank). Configure in Chart of Accounts.');
        }

        return $cashBankLedgerId;
    }

    // ============================================================
    // VALIDATION
    // ============================================================

    /**
     * Validate input data for createPayment.
     */
    private function validateCreateInput(array $data): void
    {
        $amount = (float) ($data['amount'] ?? 0);
        $supplierId = (int) ($data['supplier_id'] ?? 0);
        $transactionType = $data['transaction_type'] ?? 'payment';
        $paymentMode = $data['payment_mode'] ?? 'cash';

        if ($amount <= 0) {
            throw new \RuntimeException('Amount must be greater than zero.');
        }

        if ($supplierId <= 0) {
            throw new \RuntimeException('Supplier is required.');
        }

        if (!in_array($transactionType, self::TRANSACTION_TYPES)) {
            throw new \RuntimeException("Invalid transaction type: {$transactionType}. Must be one of: " . implode(', ', self::TRANSACTION_TYPES));
        }

        // Validate supplier is active.
        $supplier = \App\Models\Supplier::find($supplierId);
        if (!$supplier) {
            throw new \RuntimeException("Supplier not found (ID: {$supplierId}).");
        }
        if (!$supplier->is_active) {
            throw new \RuntimeException("Supplier is inactive: {$supplier->supplier_name}.");
        }

        // Validate bank if bank mode.
        if ($paymentMode === 'bank' && empty($data['bank_id'])) {
            throw new \RuntimeException('Select a bank account for bank mode.');
        }
    }

    // ============================================================
    // DOCUMENT SEQUENCE
    // ============================================================

    /**
     * Generate a unique payment code: SP-YYYY-NNNNN.
     */
    private function generatePaymentCode(string $transactionType = 'payment'): string
    {
        $prefix = match ($transactionType) {
            'advance' => 'SA',   // Supplier Advance
            'receive' => 'SR',   // Supplier Receive
            default    => 'SP',  // Supplier Payment
        };

        return $this->sequenceService->nextCode(
            docType: 'supplier_payment',
            prefix: $prefix,
            datePart: now()->format('Y'),
            padLength: 5,
            periodKey: now()->format('Y'),
        );
    }

    // ============================================================
    // AUDIT LOG
    // ============================================================

    /**
     * Log an audit entry for supplier payment actions.
     */
    private function logAudit(string $action, int $userId, int $paymentId, array $details = []): void
    {
        try {
            DB::table('user_audit_log')->insert([
                'user_id'        => $userId,
                'action'         => $action,
                'target_user_id' => null,
                'branch_id'      => $details['branch_id'] ?? session('branch_id'),
                'details'        => json_encode(array_merge(['payment_id' => $paymentId], $details)),
                'ip_address'     => request()?->ip(),
                'user_agent'     => request()?->userAgent() ? mb_substr(request()->userAgent(), 0, 255) : null,
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('SupplierTransactionService: failed to log audit', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}

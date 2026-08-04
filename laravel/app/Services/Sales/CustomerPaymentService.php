<?php

namespace App\Services\Sales;

use App\Models\Bank;
use App\Models\CustomerPayment;
use App\Models\InvoicePaymentAllocation;
use App\Services\Accounting\DocumentSequenceService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\JournalReversalService;
use App\Services\Accounting\SubLedgerService;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Customer Payment Service — Phase 8.4 + Transaction Types (P2-5).
 *
 * Two-phase: draft → confirm → cancel.
 *
 * Transaction types (transaction_type column, CHECK constraint):
 *   - receive:  Customer paying us → Dr Bank/Cash, Cr AR
 *   - discount: Discount allowed → Dr Sales Discount, Cr AR
 *   - write_off: Bad debt write-off → Dr Bad Debt Expense, Cr AR
 *   - payment:  Refund to customer → Dr AR, Cr Bank/Cash
 *
 * On confirm (5 operations, all atomic):
 *   1. GL: Type-specific journal entry (see postPaymentGL)
 *   2. Customer ledger: credit for receive/discount/write_off; debit for payment (refund)
 *   3. Multi-invoice allocation: invoice_payment_allocations (one row per invoice)
 *   4. Invoice paid_amount + due_amount updated per allocation
 *   5. Intercompany settlement (if bank-mode + cross-branch bank): branch_ledger
 *
 * GL posting by transaction_type:
 *   receive:   Dr Bank/Cash / Cr AR
 *   discount:  Dr Sales Discount (contra-revenue) / Cr AR
 *   write_off: Dr Bad Debt Expense (write_off nature) / Cr AR
 *   payment:   Dr AR / Cr Bank/Cash
 */
class CustomerPaymentService
{
    public function __construct(
        private JournalPostingService $journalPosting,
        private JournalReversalService $journalReversal,
        private SubLedgerService $subLedger,
        private SalesAccess $salesAccess,
        private SalesAuditLogger $auditLogger,
        private NotificationService $notifications,
        private CommissionService $commission
    ) {}

    /**
     * Valid transaction types (matches DB CHECK constraint).
     */
    public const TRANSACTION_TYPES = ['receive', 'discount', 'write_off', 'payment'];

    /**
     * Transaction types that reduce AR (customer owes less → credit customer_ledger).
     */
    public const AR_REDUCTION_TYPES = ['receive', 'discount', 'write_off'];

    /**
     * Transaction types that increase AR (customer owes more → debit customer_ledger).
     */
    public const AR_INCREASE_TYPES = ['payment']; // refund

    /**
     * Phase 1: Create a draft customer payment (no GL, no ledger, no allocation).
     *
     * @param array $data {
     *     customer_id: int,
     *     branch_id: int,
     *     bank_id: int|null,
     *     payment_mode: string (cash|bank|mobile_banking|cheque|adjustment),
     *     transaction_type: string (receive|discount|write_off|payment),
     *     amount: float,
     *     discount_amount: float,
     *     payment_date: string (Y-m-d),
     *     reference_no: string|null,
     *     notes: string|null,
     *     invoice_id: int|null (specific invoice to allocate against — DEPRECATED, use allocations array),
     *     allocations: array|null (multi-invoice: [{invoice_id, allocated_amount}, …]),
     *     created_by: int,
     * }
     * @return CustomerPayment
     */
    public function createPayment(array $data): CustomerPayment
    {
        $this->validateCreateInput($data);

        $paymentCode = $this->generatePaymentCode($data['transaction_type'] ?? 'receive');
        $customerId = (int) $data['customer_id'];
        $branchId = (int) $data['branch_id'];
        $transactionType = $data['transaction_type'] ?? 'receive';

        // P0-8: Defense-in-depth branch isolation check.
        $this->salesAccess->assertBranchAccessible($branchId);

        return DB::transaction(function () use ($data, $paymentCode, $customerId, $branchId, $transactionType) {
            $paymentId = DB::table('customer_payments')->insertGetId([
                'payment_code' => $paymentCode,
                'payment_date' => $data['payment_date'] ?? now()->format('Y-m-d'),
                'customer_id' => $customerId,
                'branch_id' => $branchId,
                'bank_id' => $data['bank_id'] ?? null,
                'collected_by' => $data['collected_by'] ?? null,
                'payment_mode' => $data['payment_mode'] ?? 'cash',
                'transaction_type' => $transactionType,
                'amount' => round((float) $data['amount'], 2),
                'discount_amount' => round((float) ($data['discount_amount'] ?? 0), 2),
                'reference_no' => $data['reference_no'] ?? null,
                'is_reversed' => false,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return CustomerPayment::with(['customer', 'branch', 'bank'])->find($paymentId);
        });
    }

    /**
     * Phase 2: Confirm a draft payment — GL + customer_ledger + allocation + intercompany.
     *
     * Supports multi-invoice allocation: pass an array of {invoice_id, allocated_amount}
     * to split a single payment across multiple invoices. If no allocations are provided,
     * the payment remains as an unallocated customer credit.
     *
     * GL posting varies by transaction_type:
     *   receive:   Dr Bank/Cash / Cr AR
     *   discount:  Dr Sales Discount / Cr AR  (+ optional discount_amount Dr Sales Discount / Cr AR)
     *   write_off: Dr Bad Debt Expense / Cr AR
     *   payment:   Dr AR / Cr Bank/Cash
     *
     * @param int $paymentId
     * @param int $confirmedBy
     * @param array $allocations  Array of ['invoice_id' => int, 'allocated_amount' => float]
     * @return CustomerPayment
     * @throws \RuntimeException If not draft, or GL/ledger posting fails, or allocations exceed payment amount.
     */
    public function confirmPayment(int $paymentId, int $confirmedBy, array $allocations = []): CustomerPayment
    {
        return DB::transaction(function () use ($paymentId, $confirmedBy, $allocations) {
            $payment = CustomerPayment::lockForUpdate()->find($paymentId);

            if (!$payment) {
                throw new \RuntimeException("Payment {$paymentId} not found.");
            }

            $amount = (float) $payment->amount;
            $discountAmount = (float) $payment->discount_amount;
            $customerId = $payment->customer_id;
            $branchId = $payment->branch_id;
            $transactionType = $payment->transaction_type ?? 'receive';
            $paymentDate = $payment->payment_date->format('Y-m-d');

            // 1. Post GL: type-specific journal entry.
            $journalEntryId = $this->postPaymentGL($payment, $confirmedBy);

            // 2. Post customer_ledger entry via SubLedgerService.
            $this->postCustomerLedgerForType($payment, $journalEntryId, $confirmedBy);

            // 3. Multi-invoice allocation (if provided).
            //    For 'payment' (refund) type, allocations adjust invoice paid_amount downward.
            $totalAllocated = 0.0;
            $firstInvoiceId = null;
            foreach ($allocations as $alloc) {
                $invoiceId = (int) ($alloc['invoice_id'] ?? 0);
                $allocatedAmount = (float) ($alloc['allocated_amount'] ?? 0);
                if ($invoiceId > 0 && $allocatedAmount > 0.001) {
                    $allocationId = $this->allocateToInvoice($paymentId, $invoiceId, $allocatedAmount, $confirmedBy, $transactionType);
                    $totalAllocated += $allocatedAmount;
                    if ($firstInvoiceId === null) {
                        $firstInvoiceId = $invoiceId;
                    }

                    // SALES-2 (G2/G-058): Trigger commission calculation on
                    // payment allocation — but ONLY for AR-reduction types
                    // (receive/discount/write_off = customer paying us, which
                    // earns commission). 'payment' (refund) does NOT earn
                    // commission. Wrapped in try/catch so a commission failure
                    // (e.g. no rule for salesman) never blocks the payment —
                    // commission is a downstream concern, not a payment gate.
                    if (in_array($transactionType, self::AR_REDUCTION_TYPES) && $allocationId !== null) {
                        try {
                            $allocationModel = InvoicePaymentAllocation::find($allocationId);
                            if ($allocationModel) {
                                $this->commission->calculateOnAllocation($allocationModel);
                            }
                        } catch (\Throwable $e) {
                            Log::warning('Commission calculateOnAllocation failed (non-blocking)', [
                                'payment_id'    => $paymentId,
                                'allocation_id' => $allocationId,
                                'invoice_id'    => $invoiceId,
                                'error'         => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }

            // Validate: total allocations must not exceed payment amount.
            if ($totalAllocated > $amount + 0.01) {
                throw new \RuntimeException(
                    "Total allocations ({$totalAllocated}) exceed payment amount ({$amount})."
                );
            }

            // 4. Intercompany settlement (if bank-mode + receive/discount/write_off types).
            $intercompanyJournalId = null;
            if ($payment->isBankMode() && in_array($transactionType, self::AR_REDUCTION_TYPES)) {
                $intercompanyJournalId = $this->postIntercompanySettlement($payment, $confirmedBy);
            }

            // 4b. Sync bank balance (if bank mode).
            // Phase 3A: receive = money coming in (increase bank balance)
            //           payment (refund) = money going out (decrease bank balance)
            //           discount/write_off = no bank change (adjustment)
            if ($payment->isBankMode() && $payment->bank_id) {
                $this->syncBankBalance($payment->bank_id, $amount, $transactionType);
            }

            // 5. Update payment status.
            DB::table('customer_payments')
                ->where('id', $paymentId)
                ->update([
                    'journal_entry_id' => $journalEntryId,
                    'intercompany_journal_entry_id' => $intercompanyJournalId,
                    'updated_at' => now(),
                ]);

            // P1-3: Audit log — type-specific.
            $this->auditPaymentConfirmed(
                $confirmedBy, $payment, $firstInvoiceId
            );

            // F-18c: Notify configured recipients that money was received.
            // Only fires for the 'receive' transaction type (customer paying
            // us). Other types (discount / write_off / payment-refund) are
            // not "receive money" events per the user's predefined list.
            if ($transactionType === 'receive') {
                try {
                    $this->notifications->dispatch(
                        'payment_receive',
                        "Payment {$payment->payment_code} received — Tk "
                        . number_format($amount, 2)
                        . " from customer #{$customerId} (branch #{$branchId}).",
                        'customer_payment',
                        $paymentId,
                        [],
                        [
                            'branch_id'   => $branchId,
                            'customer_id' => $customerId,
                            'created_by'  => $confirmedBy,
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning('Notification dispatch failed (payment_receive)', [
                        'payment_id' => $paymentId,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            return CustomerPayment::with([
                'customer', 'branch', 'bank',
                'journalEntry.lines.ledger',
                'intercompanyJournalEntry.lines.ledger',
                'allocations.invoice',
            ])->find($paymentId);
        });
    }

    /**
     * Phase 3: Cancel a payment — reverse GL + ledger + allocation.
     *
     * Works for all transaction types (receive, discount, write_off, payment).
     *
     * @param int $paymentId
     * @param int $cancelledBy
     * @param string $reason
     * @return CustomerPayment
     */
    public function cancelPayment(int $paymentId, int $cancelledBy, string $reason = ''): CustomerPayment
    {
        return DB::transaction(function () use ($paymentId, $cancelledBy, $reason) {
            $payment = CustomerPayment::lockForUpdate()->find($paymentId);

            if (!$payment) {
                throw new \RuntimeException("Payment {$paymentId} not found.");
            }
            if ($payment->is_reversed) {
                throw new \RuntimeException("Payment is already cancelled.");
            }

            // Reverse GL + linked customer_ledger via JournalReversalService (cascade).
            if ($payment->journal_entry_id) {
                $this->journalReversal->reverseByJournalEntry(
                    $payment->journal_entry_id, $cancelledBy,
                    "Payment cancelled: {$reason}"
                );
            }

            // Reverse intercompany GL + linked sub-ledger via JournalReversalService.
            if ($payment->intercompany_journal_entry_id) {
                $this->journalReversal->reverseByJournalEntry(
                    $payment->intercompany_journal_entry_id, $cancelledBy,
                    "Payment cancelled: {$reason}"
                );
            }

            // Reverse invoice allocations (P1-4: use invoice_payment_allocations).
            $transactionType = $payment->transaction_type ?? 'receive';
            $allocations = DB::table('invoice_payment_allocations')
                ->where('payment_id', $paymentId)
                ->get();

            // SALES-2 (G2/G-058): Reverse commission entries tied to each
            // allocation BEFORE the allocations are deleted (reverseOnPaymentReversal
            // looks up the commission_entry by allocation_id). Only for
            // AR-reduction types (receive/discount/write_off) — 'payment'
            // (refund) never earned commission, so there's nothing to reverse.
            // Non-blocking: a commission reversal failure must not prevent the
            // payment cancellation (the GL/ledger reversal is the source of truth).
            if (in_array($transactionType, self::AR_REDUCTION_TYPES)) {
                foreach ($allocations as $allocation) {
                    try {
                        $allocationModel = InvoicePaymentAllocation::find($allocation->id);
                        if ($allocationModel) {
                            $this->commission->reverseOnPaymentReversal($allocationModel);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Commission reverseOnPaymentReversal failed (non-blocking)', [
                            'payment_id'    => $paymentId,
                            'allocation_id' => $allocation->id ?? null,
                            'error'         => $e->getMessage(),
                        ]);
                    }
                }
            }

            foreach ($allocations as $allocation) {
                if ($transactionType === 'payment') {
                    // Refund allocation was a debit to paid_amount — reverse it (add back).
                    // due_amount is GENERATED (total_amount - paid_amount) — auto-updated by PostgreSQL
                    DB::table('sales_invoices')
                        ->where('id', $allocation->invoice_id)
                        ->update([
                            'paid_amount' => DB::raw('paid_amount + ' . (float) $allocation->allocated_amount),
                            'updated_at' => now(),
                        ]);
                } else {
                    // Normal allocation was a credit to paid_amount — reverse it (subtract).
                    // due_amount is GENERATED (total_amount - paid_amount) — auto-updated by PostgreSQL
                    DB::table('sales_invoices')
                        ->where('id', $allocation->invoice_id)
                        ->update([
                            'paid_amount' => DB::raw('GREATEST(0, paid_amount - ' . (float) $allocation->allocated_amount . ')'),
                            'updated_at' => now(),
                        ]);
                }
            }

            // Delete allocations.
            DB::table('invoice_payment_allocations')->where('payment_id', $paymentId)->delete();

            // Mark payment as reversed.
            DB::table('customer_payments')
                ->where('id', $paymentId)
                ->update([
                    'is_reversed' => true,
                    'reversed_at' => now(),
                    'reversed_by' => $cancelledBy,
                    'reverse_reason' => $reason,
                    'updated_at' => now(),
                ]);

            // Phase 3A: Undo bank balance sync.
            if ($payment->isBankMode() && $payment->bank_id) {
                $this->syncBankBalance(
                    $payment->bank_id,
                    (float) $payment->amount,
                    $payment->transaction_type ?? 'receive',
                    undo: true
                );
            }

            // P1-3: Audit log — payment_reversed.
            $this->auditLogger->paymentReversed(
                $cancelledBy,
                $paymentId, $payment->payment_code, (int) $payment->branch_id,
                (float) $payment->amount, $reason
            );

            return CustomerPayment::find($paymentId);
        });
    }

    // ============================================================
    // GL POSTING — TYPE-SPECIFIC
    // ============================================================

    /**
     * Post GL journal entry based on transaction_type.
     *
     * receive:   Dr Bank/Cash / Cr AR
     * discount:  Dr Sales Discount / Cr AR  (+ discount_amount: Dr Sales Discount / Cr AR)
     * write_off: Dr Bad Debt Expense / Cr AR
     * payment:   Dr AR / Cr Bank/Cash
     *
     * For 'receive' with discount_amount > 0, also posts:
     *   Dr Sales Discount / Cr AR (the discount portion reduces AR separately)
     *
     * @return int journal_entry_id
     */
    private function postPaymentGL(CustomerPayment $payment, int $createdBy): int
    {
        $amount = (float) $payment->amount;
        $discountAmount = (float) $payment->discount_amount;
        if ($amount < 0.01 && $discountAmount < 0.01) return 0;

        $arLedgerId = $this->journalPosting->lookupLedgerByNature('ar');
        if (!$arLedgerId) {
            throw new \RuntimeException('Accounts Receivable ledger not found (nature: ar).');
        }

        $transactionType = $payment->transaction_type ?? 'receive';
        $lines = [];

        switch ($transactionType) {
            case 'receive':
                $lines = $this->buildReceiveGL($payment, $arLedgerId, $amount, $discountAmount);
                break;

            case 'discount':
                $lines = $this->buildDiscountGL($payment, $arLedgerId, $amount, $discountAmount);
                break;

            case 'write_off':
                $lines = $this->buildWriteOffGL($payment, $arLedgerId, $amount);
                break;

            case 'payment':
                $lines = $this->buildRefundGL($payment, $arLedgerId, $amount);
                break;

            default:
                throw new \RuntimeException("Unknown transaction_type: {$transactionType}");
        }

        // Build description based on type.
        $typeLabels = [
            'receive' => 'Customer Payment',
            'discount' => 'Discount Allowed',
            'write_off' => 'Bad Debt Write-off',
            'payment' => 'Customer Refund',
        ];
        $label = $typeLabels[$transactionType] ?? 'Customer Payment';

        return $this->journalPosting->createJournalEntry([
            'entry_date' => $payment->payment_date->format('Y-m-d'),
            'reference_type' => 'customer_payment',
            'reference_id' => $payment->id,
            'branch_id' => $payment->branch_id,
            'description' => $label . ' ' . $payment->payment_code
                . ($payment->customer ? ' — ' . $payment->customer->customer_name : '')
                . ($payment->notes ? ' — ' . $payment->notes : ''),
            'source' => 'customer_payment_' . $transactionType,
            'created_by' => $createdBy,
        ], $lines);
    }

    /**
     * Build GL lines for 'receive' type.
     *   Dr Bank/Cash / Cr AR
     *   + if discount_amount: Dr Sales Discount / Cr AR (discount portion)
     */
    private function buildReceiveGL(CustomerPayment $payment, int $arLedgerId, float $amount, float $discountAmount): array
    {
        $lines = [];

        // Debit side: Bank or Cash.
        $debitLedgerId = $this->resolveDebitLedger($payment);
        $lines[] = [
            'ledger_id' => $debitLedgerId,
            'debit' => $amount, 'credit' => 0,
            'entity_type' => $payment->isBankMode() ? 'bank' : 'customer_payment',
            'entity_id' => $payment->isBankMode() ? $payment->bank_id : $payment->id,
            'memo' => 'Customer payment received — ' . $payment->payment_code,
        ];

        // Credit side: AR (full amount including discount portion).
        $totalARCredit = $amount + $discountAmount;
        $lines[] = [
            'ledger_id' => $arLedgerId,
            'debit' => 0, 'credit' => $totalARCredit,
            'entity_type' => 'customer', 'entity_id' => $payment->customer_id,
            'memo' => 'Payment ' . $payment->payment_code . ' — AR cleared',
        ];

        // If discount_amount > 0: Dr Sales Discount / Cr AR is already included above
        // because the bank debit is only $amount but AR credit is $amount + $discount.
        // The extra $discountAmount credit to AR is balanced by a debit to Sales Discount.
        if ($discountAmount > 0.001) {
            $discountLedgerId = $this->journalPosting->lookupLedgerByNature('sales_discount');
            if (!$discountLedgerId) {
                throw new \RuntimeException('Sales Discount ledger not found (nature: sales_discount). Configure in Chart of Accounts.');
            }
            $lines[] = [
                'ledger_id' => $discountLedgerId,
                'debit' => $discountAmount, 'credit' => 0,
                'entity_type' => 'customer', 'entity_id' => $payment->customer_id,
                'memo' => 'Discount on payment ' . $payment->payment_code,
            ];
        }

        return $lines;
    }

    /**
     * Build GL lines for 'discount' type.
     *   Dr Sales Discount / Cr AR
     *   The entire amount is treated as a discount (contra-revenue).
     */
    private function buildDiscountGL(CustomerPayment $payment, int $arLedgerId, float $amount, float $discountAmount): array
    {
        $discountLedgerId = $this->journalPosting->lookupLedgerByNature('sales_discount');
        if (!$discountLedgerId) {
            throw new \RuntimeException('Sales Discount ledger not found (nature: sales_discount). Configure in Chart of Accounts.');
        }

        $totalDiscount = $amount + $discountAmount;

        $lines = [];
        $lines[] = [
            'ledger_id' => $discountLedgerId,
            'debit' => $totalDiscount, 'credit' => 0,
            'entity_type' => 'customer', 'entity_id' => $payment->customer_id,
            'memo' => 'Discount allowed — ' . $payment->payment_code,
        ];

        $lines[] = [
            'ledger_id' => $arLedgerId,
            'debit' => 0, 'credit' => $totalDiscount,
            'entity_type' => 'customer', 'entity_id' => $payment->customer_id,
            'memo' => 'Discount ' . $payment->payment_code . ' — AR reduced',
        ];

        return $lines;
    }

    /**
     * Build GL lines for 'write_off' type.
     *   Dr Bad Debt Expense (write_off nature) / Cr AR
     */
    private function buildWriteOffGL(CustomerPayment $payment, int $arLedgerId, float $amount): array
    {
        $writeOffLedgerId = $this->journalPosting->lookupLedgerByNature('write_off');
        if (!$writeOffLedgerId) {
            // Fallback: try finance_cost or operating_expense
            $writeOffLedgerId = $this->journalPosting->lookupLedgerByNature('finance_cost')
                ?? $this->journalPosting->lookupLedgerByNature('operating_expense');
        }
        if (!$writeOffLedgerId) {
            throw new \RuntimeException('Bad Debt Write-off ledger not found (nature: write_off, finance_cost, or operating_expense). Configure in Chart of Accounts.');
        }

        $lines = [];
        $lines[] = [
            'ledger_id' => $writeOffLedgerId,
            'debit' => $amount, 'credit' => 0,
            'entity_type' => 'customer', 'entity_id' => $payment->customer_id,
            'memo' => 'Bad debt write-off — ' . $payment->payment_code,
        ];

        $lines[] = [
            'ledger_id' => $arLedgerId,
            'debit' => 0, 'credit' => $amount,
            'entity_type' => 'customer', 'entity_id' => $payment->customer_id,
            'memo' => 'Write-off ' . $payment->payment_code . ' — AR reduced',
        ];

        return $lines;
    }

    /**
     * Build GL lines for 'payment' (refund) type.
     *   Dr AR / Cr Bank/Cash
     *   Money going OUT to the customer.
     */
    private function buildRefundGL(CustomerPayment $payment, int $arLedgerId, float $amount): array
    {
        $creditLedgerId = $this->resolveCreditLedger($payment);

        $lines = [];
        $lines[] = [
            'ledger_id' => $arLedgerId,
            'debit' => $amount, 'credit' => 0,
            'entity_type' => 'customer', 'entity_id' => $payment->customer_id,
            'memo' => 'Customer refund — AR increased — ' . $payment->payment_code,
        ];

        $lines[] = [
            'ledger_id' => $creditLedgerId,
            'debit' => 0, 'credit' => $amount,
            'entity_type' => $payment->isBankMode() ? 'bank' : 'customer_payment',
            'entity_id' => $payment->isBankMode() ? $payment->bank_id : $payment->id,
            'memo' => 'Refund to customer — ' . $payment->payment_code,
        ];

        return $lines;
    }

    // ============================================================
    // CUSTOMER LEDGER POSTING — TYPE-SPECIFIC
    // ============================================================

    /**
     * Post customer_ledger entry based on transaction_type.
     *
     * receive/discount/write_off: credit (customer owes less)
     * payment (refund): debit (customer owes more, or AR increases)
     *
     * For 'receive' with discount_amount > 0, the discount is included in the
     * credit amount (total = amount + discount_amount) since AR is reduced by both.
     */
    private function postCustomerLedgerForType(CustomerPayment $payment, int $journalEntryId, int $confirmedBy): void
    {
        $transactionType = $payment->transaction_type ?? 'receive';
        $amount = (float) $payment->amount;
        $discountAmount = (float) $payment->discount_amount;

        $typeDescriptions = [
            'receive' => 'Payment received',
            'discount' => 'Discount allowed',
            'write_off' => 'Bad debt written off',
            'payment' => 'Refund to customer',
        ];
        $description = ($typeDescriptions[$transactionType] ?? 'Payment')
            . ' ' . $payment->payment_code
            . ($payment->notes ? ' — ' . $payment->notes : '');

        if (in_array($transactionType, self::AR_REDUCTION_TYPES)) {
            // Credit: customer owes less.
            // For receive: credit = amount + discount_amount (both reduce AR)
            // For discount: credit = amount + discount_amount (entire amount is discount)
            // For write_off: credit = amount
            $credit = $amount;
            if ($transactionType === 'receive') {
                $credit = $amount + $discountAmount;
            } elseif ($transactionType === 'discount') {
                $credit = $amount + $discountAmount;
            }

            $this->subLedger->postCustomerLedgerEntry([
                'customer_id' => $payment->customer_id,
                'branch_id' => $payment->branch_id,
                'transaction_date' => $payment->payment_date->format('Y-m-d'),
                'transaction_type' => 'customer_payment' . ($transactionType !== 'receive' ? '_' . $transactionType : ''),
                'reference_type' => 'customer_payment',
                'reference_id' => $payment->id,
                'debit' => 0,
                'credit' => $credit,
                'description' => $description,
                'journal_entry_id' => $journalEntryId,
                'created_by' => $confirmedBy,
            ]);
        } else {
            // Debit: customer owes more (refund).
            $this->subLedger->postCustomerLedgerEntry([
                'customer_id' => $payment->customer_id,
                'branch_id' => $payment->branch_id,
                'transaction_date' => $payment->payment_date->format('Y-m-d'),
                'transaction_type' => 'customer_payment_refund',
                'reference_type' => 'customer_payment',
                'reference_id' => $payment->id,
                'debit' => $amount,
                'credit' => 0,
                'description' => $description,
                'journal_entry_id' => $journalEntryId,
                'created_by' => $confirmedBy,
            ]);
        }
    }

    // ============================================================
    // ALLOCATION — TYPE-AWARE
    // ============================================================

    /**
     * Allocate payment to a specific invoice.
     *
     * For 'payment' (refund) type: reduces invoice paid_amount (increases due_amount).
     * For all other types: increases invoice paid_amount (decreases due_amount).
     */
    private function allocateToInvoice(int $paymentId, int $invoiceId, float $amount, int $createdBy, string $transactionType = 'receive'): ?int
    {
        // Check invoice exists + not reversed.
        $invoice = DB::table('sales_invoices')
            ->where('id', $invoiceId)
            ->where('is_reversed', false)
            ->lockForUpdate()
            ->first();

        if (!$invoice) {
            throw new \RuntimeException("Invoice {$invoiceId} not found or reversed.");
        }

        if ($transactionType === 'payment') {
            // Refund allocation: reduce paid_amount, increase due_amount.
            $currentPaid = (float) $invoice->paid_amount;
            if ($amount > $currentPaid + 0.01) {
                throw new \RuntimeException(
                    "Refund allocation ({$amount}) exceeds paid amount on invoice {$invoiceId} (paid: {$currentPaid})."
                );
            }

            $allocationId = DB::table('invoice_payment_allocations')->insertGetId([
                'invoice_id' => $invoiceId,
                'payment_id' => $paymentId,
                'allocated_amount' => $amount,
                'created_at' => now(),
            ]);

            // due_amount is GENERATED (total_amount - paid_amount) — auto-updated by PostgreSQL
            DB::table('sales_invoices')
                ->where('id', $invoiceId)
                ->update([
                    'paid_amount' => DB::raw('GREATEST(0, paid_amount - ' . $amount . ')'),
                    'updated_at' => now(),
                ]);

            return (int) $allocationId;
        } else {
            // Normal allocation: check payment doesn't exceed invoice outstanding.
            $paidSoFar = (float) DB::table('invoice_payment_allocations as ipa')
                ->join('customer_payments as cp', 'cp.id', '=', 'ipa.payment_id')
                ->where('ipa.invoice_id', $invoiceId)
                ->where('cp.is_reversed', false)
                ->sum('ipa.allocated_amount');

            $outstanding = (float) $invoice->total_amount - $paidSoFar;
            if ($amount > $outstanding + 0.01) {
                throw new \RuntimeException(
                    "Payment exceeds invoice outstanding. Outstanding: {$outstanding}, Payment: {$amount}"
                );
            }

            // Create allocation.
            $allocationId = DB::table('invoice_payment_allocations')->insertGetId([
                'invoice_id' => $invoiceId,
                'payment_id' => $paymentId,
                'allocated_amount' => $amount,
                'created_at' => now(),
            ]);

            // Update invoice paid_amount. due_amount is GENERATED — auto-updated by PostgreSQL.
            DB::table('sales_invoices')
                ->where('id', $invoiceId)
                ->update([
                    'paid_amount' => DB::raw('paid_amount + ' . $amount),
                    'updated_at' => now(),
                ]);

            return (int) $allocationId;
        }
    }

    // ============================================================
    // LEDGER RESOLUTION HELPERS
    // ============================================================

    /**
     * Resolve the debit ledger for receive-type payments (Bank or Cash).
     */
    private function resolveDebitLedger(CustomerPayment $payment): int
    {
        if ($payment->isBankMode()) {
            $bankLedgerId = DB::table('bank_ledger_mappings')
                ->where('bank_id', $payment->bank_id)
                ->value('ledger_id');

            if (!$bankLedgerId) {
                $bankLedgerId = $this->journalPosting->lookupLedgerByNature('cash_bank');
            }
            if (!$bankLedgerId) {
                throw new \RuntimeException('Bank ledger not found. Configure bank_ledger_mappings or cash_bank nature.');
            }
            return $bankLedgerId;
        }

        // Cash mode.
        $cashLedgerId = $this->journalPosting->lookupLedgerByNature('cash_bank');
        if (!$cashLedgerId) {
            throw new \RuntimeException('Cash/Bank ledger not found (nature: cash_bank).');
        }
        return $cashLedgerId;
    }

    /**
     * Resolve the credit ledger for refund-type payments (Bank or Cash — money goes OUT).
     */
    private function resolveCreditLedger(CustomerPayment $payment): int
    {
        // Same ledger as debit for receive — but now it's the credit side.
        return $this->resolveDebitLedger($payment);
    }

    // ============================================================
    // INTERCOMPANY SETTLEMENT
    // ============================================================

    /**
     * Post intercompany settlement for bank-mode payments.
     *
     * When a customer pays at Branch A but the bank belongs to Branch B,
     * this creates an intercompany entry: Branch A owes Branch B for the bank deposit.
     *
     * Only applies to AR-reduction types (receive, discount, write_off).
     * Refund type reverses the flow (not handled here — refund intercompany is rare).
     *
     * @return int|null journal_entry_id (null if no intercompany needed)
     */
    private function postIntercompanySettlement(CustomerPayment $payment, int $createdBy): ?int
    {
        $amount = (float) $payment->amount;
        if ($amount < 0.01 || !$payment->bank_id) return null;

        // NOTE: The `banks` table does NOT have a `branch_id` column — banks
        // are not branch-scoped in the current schema. Intercompany settlement
        // requires bank→branch mapping which doesn't exist yet. Skip entirely.
        return null;

        $fromBranchId = $payment->branch_id;        // customer's branch
        $toBranchId = (int) $bankBranchId;          // bank's branch

        $dueToLedgerId = $this->journalPosting->lookupLedgerByNature('interbranch_payable');
        $dueFromLedgerId = $this->journalPosting->lookupLedgerByNature('interbranch_receivable');

        if (!$dueToLedgerId || !$dueFromLedgerId) {
            // Intercompany ledgers not configured — skip (not critical).
            Log::warning('Intercompany ledgers not configured, skipping settlement', [
                'payment_id' => $payment->id,
            ]);
            return null;
        }

        // From-branch: Dr Due-to-Branch / Cr (the bank ledger is already debited in main GL).
        // This records that from-branch owes to-branch for the bank deposit.
        $journalEntryId = $this->journalPosting->createJournalEntry([
            'entry_date' => $payment->payment_date->format('Y-m-d'),
            'reference_type' => 'customer_payment',
            'reference_id' => $payment->id,
            'branch_id' => $fromBranchId,
            'description' => 'Intercompany settlement — Payment ' . $payment->payment_code,
            'source' => 'customer_payment_intercompany',
            'created_by' => $createdBy,
        ], [
            [
                'ledger_id' => $dueToLedgerId,
                'debit' => $amount, 'credit' => 0,
                'memo' => 'Bank deposit at branch ' . $toBranchId . ' — ' . $payment->payment_code,
            ],
            [
                'ledger_id' => $dueFromLedgerId,
                'debit' => 0, 'credit' => $amount,
                'memo' => 'Payment received at branch ' . $fromBranchId . ' — ' . $payment->payment_code,
            ],
        ]);

        // Record in branch_ledger.
        DB::table('branch_ledger')->insert([
            'from_branch_id' => $fromBranchId,
            'to_branch_id' => $toBranchId,
            'transaction_date' => $payment->payment_date->format('Y-m-d'),
            'transaction_type' => 'customer_payment',
            'reference_type' => 'customer_payment',
            'reference_id' => $payment->id,
            'amount' => $amount,
            'description' => 'Payment ' . $payment->payment_code . ' — bank at different branch',
            'journal_entry_id' => $journalEntryId,
            'is_settled' => false,
            'created_at' => now(),
        ]);

        return $journalEntryId;
    }

    // ============================================================
    // PAYMENT CODE GENERATION — TYPE-SPECIFIC PREFIX
    // ============================================================

    /**
     * Generate atomic payment code: PREFIX-YYYYMMDD-NNNN.
     * Uses DocumentSequenceService with advisory locks (Task 20).
     *
     * Prefixes by transaction_type:
     *   receive   → PAY
     *   discount  → DISC
     *   write_off → WOFF
     *   payment   → RFND
     */
    private function generatePaymentCode(string $transactionType = 'receive'): string
    {
        $prefixes = [
            'receive' => 'PAY',
            'discount' => 'DISC',
            'write_off' => 'WOFF',
            'payment' => 'RFND',
        ];
        $prefix = $prefixes[$transactionType] ?? 'PAY';

        return DocumentSequenceService::nextCode(
            docType:  'customer_payment_' . $transactionType,
            prefix:   $prefix,
            datePart: now()->format('Ymd'),
            padLength: 4,
        );
    }

    // ============================================================
    // VALIDATION
    // ============================================================

    private function validateCreateInput(array $data): void
    {
        if (empty($data['customer_id']) || (int) $data['customer_id'] <= 0) {
            throw new \InvalidArgumentException('customer_id is required.');
        }
        if (empty($data['branch_id']) || (int) $data['branch_id'] <= 0) {
            throw new \InvalidArgumentException('branch_id is required.');
        }
        if (empty($data['amount']) || (float) $data['amount'] <= 0) {
            throw new \InvalidArgumentException('amount must be positive.');
        }
        $mode = $data['payment_mode'] ?? 'cash';
        if (!in_array($mode, ['cash', 'bank', 'mobile_banking', 'cheque', 'adjustment'])) {
            throw new \InvalidArgumentException('Invalid payment_mode: ' . $mode);
        }
        $transactionType = $data['transaction_type'] ?? 'receive';
        if (!in_array($transactionType, self::TRANSACTION_TYPES)) {
            throw new \InvalidArgumentException('Invalid transaction_type: ' . $transactionType);
        }

        // Bank/cash validation by type.
        if (in_array($transactionType, ['receive', 'payment'])) {
            // These move money — require bank_id for bank mode.
            if ($mode === 'bank' && empty($data['bank_id'])) {
                throw new \InvalidArgumentException('bank_id is required for bank mode.');
            }
        } elseif ($transactionType === 'discount') {
            // Discount type: no money moves, payment_mode should be 'adjustment'.
            // We allow any mode but log a warning for non-adjustment.
            if ($mode !== 'adjustment') {
                Log::info('Discount payment created with non-adjustment mode', [
                    'mode' => $mode, 'customer_id' => $data['customer_id'],
                ]);
            }
        } elseif ($transactionType === 'write_off') {
            // Write-off type: no money moves, payment_mode should be 'adjustment'.
            if ($mode !== 'adjustment') {
                Log::info('Write-off payment created with non-adjustment mode', [
                    'mode' => $mode, 'customer_id' => $data['customer_id'],
                ]);
            }
        }
    }

    // ============================================================
    // BANK BALANCE SYNC
    // ============================================================

    /**
     * Sync bank balance after a customer payment.
     *
     * receive:  money coming in (increase bank balance)
     * payment:  money going out — refund (decrease bank balance)
     * discount/write_off: no bank change (adjustment, no money moves)
     */
    private function syncBankBalance(int $bankId, float $amount, string $transactionType, bool $undo = false): void
    {
        $bank = Bank::find($bankId);
        if (!$bank) return;

        // receive = money coming in (increase bank balance)
        // payment (refund) = money going out (decrease bank balance)
        // discount/write_off = no bank change
        $increase = in_array($transactionType, self::AR_REDUCTION_TYPES);
        if ($undo) $increase = !$increase;

        if ($increase) {
            $bank->increment('balance', $amount);
        } else {
            $bank->decrement('balance', $amount);
        }
    }

    // ============================================================
    // AUDIT HELPERS
    // ============================================================

    /**
     * Route audit logging by transaction_type.
     */
    private function auditPaymentConfirmed(int $confirmedBy, CustomerPayment $payment, ?int $firstInvoiceId): void
    {
        $transactionType = $payment->transaction_type ?? 'receive';

        switch ($transactionType) {
            case 'receive':
                $this->auditLogger->paymentReceived(
                    $confirmedBy,
                    $payment->id, $payment->payment_code, (int) $payment->customer_id,
                    (int) $payment->branch_id, (float) $payment->amount,
                    $payment->payment_mode, $firstInvoiceId
                );
                break;

            case 'discount':
                $this->auditLogger->paymentDiscount(
                    $confirmedBy,
                    $payment->id, $payment->payment_code, (int) $payment->customer_id,
                    (int) $payment->branch_id, (float) $payment->amount,
                    (float) $payment->discount_amount
                );
                break;

            case 'write_off':
                $this->auditLogger->paymentWriteOff(
                    $confirmedBy,
                    $payment->id, $payment->payment_code, (int) $payment->customer_id,
                    (int) $payment->branch_id, (float) $payment->amount
                );
                break;

            case 'payment':
                $this->auditLogger->paymentRefund(
                    $confirmedBy,
                    $payment->id, $payment->payment_code, (int) $payment->customer_id,
                    (int) $payment->branch_id, (float) $payment->amount,
                    $payment->payment_mode
                );
                break;
        }
    }
}

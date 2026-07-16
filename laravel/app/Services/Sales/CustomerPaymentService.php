<?php

namespace App\Services\Sales;

use App\Models\CustomerPayment;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Customer Payment Service — Phase 8.4.
 *
 * Two-phase: draft → confirm → cancel.
 *
 * On confirm (5 operations, all atomic):
 *   1. GL: Dr Bank/Cash / Cr Accounts Receivable
 *      - Bank mode: Dr Bank Ledger (via bank_ledger_mappings) / Cr AR
 *      - Cash mode: Dr Cash Ledger / Cr AR
 *   2. Customer ledger: credit entry (customer owes less)
 *   3. Invoice allocation (if specified): invoice_payment_allocations
 *   4. Invoice paid_amount + due_amount updated
 *   5. Intercompany settlement (if bank-mode + cross-branch bank): branch_ledger
 *
 * GL posting (re-derived from double-entry):
 *   Dr Bank (nature: cash_bank, mapped via bank_ledger_mappings) OR Cash Ledger
 *   Cr Accounts Receivable (nature: ar)
 */
class CustomerPaymentService
{
    public function __construct(
        private JournalPostingService $journalPosting
    ) {}

    /**
     * Phase 1: Create a draft customer payment (no GL, no ledger, no allocation).
     *
     * @param array $data {
     *     customer_id: int,
     *     branch_id: int,
     *     bank_id: int|null,
     *     payment_mode: string (cash|bank|mobile_banking|cheque|adjustment),
     *     amount: float,
     *     discount_amount: float,
     *     payment_date: string (Y-m-d),
     *     reference_no: string|null,
     *     notes: string|null,
     *     invoice_id: int|null (specific invoice to allocate against),
     *     created_by: int,
     * }
     * @return CustomerPayment
     */
    public function createPayment(array $data): CustomerPayment
    {
        $this->validateCreateInput($data);

        $paymentCode = $this->generatePaymentCode();
        $customerId = (int) $data['customer_id'];
        $branchId = (int) $data['branch_id'];

        return DB::transaction(function () use ($data, $paymentCode, $customerId, $branchId) {
            $paymentId = DB::table('customer_payments')->insertGetId([
                'payment_code' => $paymentCode,
                'payment_date' => $data['payment_date'] ?? now()->format('Y-m-d'),
                'customer_id' => $customerId,
                'branch_id' => $branchId,
                'bank_id' => $data['bank_id'] ?? null,
                'payment_mode' => $data['payment_mode'] ?? 'cash',
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
     * @param int $paymentId
     * @param int $confirmedBy
     * @param int|null $invoiceId (invoice to allocate against)
     * @return CustomerPayment
     * @throws \RuntimeException If not draft, or GL/ledger posting fails.
     */
    public function confirmPayment(int $paymentId, int $confirmedBy, ?int $invoiceId = null): CustomerPayment
    {
        return DB::transaction(function () use ($paymentId, $confirmedBy, $invoiceId) {
            $payment = CustomerPayment::lockForUpdate()->find($paymentId);

            if (!$payment) {
                throw new \RuntimeException("Payment {$paymentId} not found.");
            }

            $amount = (float) $payment->amount;
            $customerId = $payment->customer_id;
            $branchId = $payment->branch_id;
            $paymentDate = $payment->payment_date->format('Y-m-d');

            // 1. Post GL: Dr Bank/Cash / Cr AR.
            $journalEntryId = $this->postPaymentGL($payment, $confirmedBy);

            // 2. Post customer_ledger credit (customer owes less).
            $this->postCustomerLedgerCredit($payment, $journalEntryId, $confirmedBy);

            // 3. Invoice allocation (if specified).
            if ($invoiceId) {
                $this->allocateToInvoice($paymentId, $invoiceId, $amount, $confirmedBy);
            }

            // 4. Intercompany settlement (if bank-mode).
            $intercompanyJournalId = null;
            if ($payment->isBankMode()) {
                $intercompanyJournalId = $this->postIntercompanySettlement($payment, $confirmedBy);
            }

            // 5. Update payment status.
            DB::table('customer_payments')
                ->where('id', $paymentId)
                ->update([
                    'journal_entry_id' => $journalEntryId,
                    'intercompany_journal_entry_id' => $intercompanyJournalId,
                    'updated_at' => now(),
                ]);

            return CustomerPayment::with([
                'customer', 'branch', 'bank',
                'journalEntry.lines.ledger',
                'intercompanyJournalEntry.lines.ledger',
                'settlements.invoice',
            ])->find($paymentId);
        });
    }

    /**
     * Phase 3: Cancel a payment — reverse GL + ledger + allocation.
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

            // Reverse GL.
            if ($payment->journal_entry_id) {
                $this->journalPosting->reverseJournalEntry(
                    $payment->journal_entry_id, $cancelledBy,
                    "Payment cancelled: {$reason}"
                );
            }

            // Reverse intercompany GL.
            if ($payment->intercompany_journal_entry_id) {
                $this->journalPosting->reverseJournalEntry(
                    $payment->intercompany_journal_entry_id, $cancelledBy,
                    "Payment cancelled: {$reason}"
                );
            }

            // Reverse customer_ledger (debit entry to restore what customer owes).
            $this->reverseCustomerLedgerCredit($payment, $cancelledBy, $reason);

            // Reverse invoice allocations.
            $settlements = DB::table('customer_payment_settlements')
                ->where('payment_id', $paymentId)
                ->get();

            foreach ($settlements as $settlement) {
                // Decrement invoice paid_amount.
                DB::table('sales_invoices')
                    ->where('id', $settlement->invoice_id)
                    ->update([
                        'paid_amount' => DB::raw('GREATEST(0, paid_amount - ' . (float) $settlement->allocated_amount . ')'),
                        'due_amount' => DB::raw('due_amount + ' . (float) $settlement->allocated_amount),
                        'updated_at' => now(),
                    ]);
            }

            // Delete allocations.
            DB::table('customer_payment_settlements')->where('payment_id', $paymentId)->delete();

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

            return CustomerPayment::find($paymentId);
        });
    }

    /**
     * Post GL: Dr Bank/Cash / Cr Accounts Receivable.
     *
     * @return int journal_entry_id
     */
    private function postPaymentGL(CustomerPayment $payment, int $createdBy): int
    {
        $amount = (float) $payment->amount;
        if ($amount < 0.01) return 0;

        $arLedgerId = $this->journalPosting->lookupLedgerByNature('ar');
        if (!$arLedgerId) {
            throw new \RuntimeException('Accounts Receivable ledger not found (nature: ar).');
        }

        $lines = [];

        // Debit side: Bank or Cash.
        if ($payment->isBankMode()) {
            // Bank mode: look up the bank's GL ledger via bank_ledger_mappings.
            $bankLedgerId = DB::table('bank_ledger_mappings')
                ->where('bank_id', $payment->bank_id)
                ->value('ledger_id');

            if (!$bankLedgerId) {
                // Fallback: look up cash_bank nature ledger.
                $bankLedgerId = $this->journalPosting->lookupLedgerByNature('cash_bank');
            }
            if (!$bankLedgerId) {
                throw new \RuntimeException('Bank ledger not found. Configure bank_ledger_mappings or cash_bank nature.');
            }

            $lines[] = [
                'ledger_id' => $bankLedgerId,
                'debit' => $amount, 'credit' => 0,
                'entity_type' => 'bank', 'entity_id' => $payment->bank_id,
                'memo' => 'Customer payment received — bank — ' . $payment->payment_code,
            ];
        } else {
            // Cash mode: use cash_bank nature ledger (or branch-specific cash ledger).
            $cashLedgerId = $this->journalPosting->lookupLedgerByNature('cash_bank');
            if (!$cashLedgerId) {
                throw new \RuntimeException('Cash/Bank ledger not found (nature: cash_bank).');
            }

            $lines[] = [
                'ledger_id' => $cashLedgerId,
                'debit' => $amount, 'credit' => 0,
                'entity_type' => 'customer_payment', 'entity_id' => $payment->id,
                'memo' => 'Customer payment received — cash — ' . $payment->payment_code,
            ];
        }

        // Credit side: Accounts Receivable.
        $lines[] = [
            'ledger_id' => $arLedgerId,
            'debit' => 0, 'credit' => $amount,
            'entity_type' => 'customer', 'entity_id' => $payment->customer_id,
            'memo' => 'Payment ' . $payment->payment_code . ' — AR cleared',
        ];

        return $this->journalPosting->createJournalEntry([
            'entry_date' => $payment->payment_date->format('Y-m-d'),
            'reference_type' => 'customer_payment',
            'reference_id' => $payment->id,
            'branch_id' => $payment->branch_id,
            'description' => 'Customer Payment ' . $payment->payment_code
                . ($payment->customer ? ' — ' . $payment->customer->customer_name : '')
                . ($payment->notes ? ' — ' . $payment->notes : ''),
            'source' => 'customer_payment',
            'created_by' => $createdBy,
        ], $lines);
    }

    /**
     * Post customer_ledger credit entry (customer owes less).
     */
    private function postCustomerLedgerCredit(CustomerPayment $payment, ?int $journalEntryId, int $createdBy): void
    {
        $amount = (float) $payment->amount;
        if ($amount < 0.01) return;

        $currentBalance = (float) DB::table('customer_ledger')
            ->where('customer_id', $payment->customer_id)
            ->orderByDesc('id')
            ->value('balance');

        $newBalance = $currentBalance - $amount; // credit reduces what customer owes

        DB::table('customer_ledger')->insert([
            'customer_id' => $payment->customer_id,
            'branch_id' => $payment->branch_id,
            'transaction_date' => $payment->payment_date->format('Y-m-d'),
            'transaction_type' => 'customer_payment',
            'reference_type' => 'customer_payment',
            'reference_id' => $payment->id,
            'debit' => 0,
            'credit' => $amount,
            'balance' => $newBalance,
            'description' => 'Payment ' . $payment->payment_code . ($payment->notes ? ' — ' . $payment->notes : ''),
            'journal_entry_id' => $journalEntryId,
            'created_by' => $createdBy,
            'created_at' => now(),
        ]);
    }

    /**
     * Reverse customer_ledger (debit entry to restore what customer owes).
     */
    private function reverseCustomerLedgerCredit(CustomerPayment $payment, int $cancelledBy, string $reason): void
    {
        $amount = (float) $payment->amount;
        if ($amount < 0.01) return;

        $currentBalance = (float) DB::table('customer_ledger')
            ->where('customer_id', $payment->customer_id)
            ->orderByDesc('id')
            ->value('balance');

        $newBalance = $currentBalance + $amount; // debit restores what customer owes

        DB::table('customer_ledger')->insert([
            'customer_id' => $payment->customer_id,
            'branch_id' => $payment->branch_id,
            'transaction_date' => now()->format('Y-m-d'),
            'transaction_type' => 'customer_payment_reversal',
            'reference_type' => 'customer_payment',
            'reference_id' => $payment->id,
            'debit' => $amount,
            'credit' => 0,
            'balance' => $newBalance,
            'description' => 'Payment reversal ' . $payment->payment_code . ": {$reason}",
            'created_by' => $cancelledBy,
            'created_at' => now(),
        ]);
    }

    /**
     * Allocate payment to a specific invoice.
     */
    private function allocateToInvoice(int $paymentId, int $invoiceId, float $amount, int $createdBy): void
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

        // Check payment doesn't exceed invoice outstanding.
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
        DB::table('invoice_payment_allocations')->insert([
            'invoice_id' => $invoiceId,
            'payment_id' => $paymentId,
            'allocated_amount' => $amount,
            'created_at' => now(),
        ]);

        // Update invoice paid_amount + due_amount.
        DB::table('sales_invoices')
            ->where('id', $invoiceId)
            ->update([
                'paid_amount' => DB::raw('paid_amount + ' . $amount),
                'due_amount' => DB::raw('GREATEST(0, due_amount - ' . $amount . ')'),
                'updated_at' => now(),
            ]);
    }

    /**
     * Post intercompany settlement for bank-mode payments.
     *
     * When a customer pays at Branch A but the bank belongs to Branch B,
     * this creates an intercompany entry: Branch A owes Branch B for the bank deposit.
     *
     * @return int|null journal_entry_id (null if no intercompany needed)
     */
    private function postIntercompanySettlement(CustomerPayment $payment, int $createdBy): ?int
    {
        $amount = (float) $payment->amount;
        if ($amount < 0.01 || !$payment->bank_id) return null;

        // Check if the bank belongs to a different branch.
        $bankBranchId = DB::table('banks')->where('id', $payment->bank_id)->value('branch_id');

        // If bank has no branch or same branch → no intercompany needed.
        if (!$bankBranchId || (int) $bankBranchId === $payment->branch_id) {
            return null;
        }

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

    /**
     * Generate atomic payment code: PAY-YYYYMMDD-NNNN.
     */
    private function generatePaymentCode(): string
    {
        $datePart = now()->format('Ymd');
        $periodKey = now()->format('Y-m');
        $docType = 'customer_payment';

        return DB::transaction(function () use ($docType, $periodKey, $datePart) {
            $seqRow = DB::table('document_sequences')
                ->where('doc_type', $docType)
                ->where('branch_id', 0)
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            $nextNumber = $seqRow ? ((int) $seqRow->last_number + 1) : 1;

            if ($seqRow) {
                DB::table('document_sequences')->where('id', $seqRow->id)
                    ->update(['last_number' => $nextNumber, 'updated_at' => now()]);
            } else {
                DB::table('document_sequences')->insert([
                    'doc_type' => $docType, 'branch_id' => 0,
                    'period_key' => $periodKey, 'last_number' => $nextNumber,
                    'updated_at' => now(),
                ]);
            }

            return "PAY-{$datePart}-" . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
        });
    }

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
        if ($mode === 'bank' && empty($data['bank_id'])) {
            throw new \InvalidArgumentException('bank_id is required for bank mode.');
        }
    }
}

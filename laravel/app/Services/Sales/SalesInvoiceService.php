<?php

namespace App\Services\Sales;

use App\Models\SalesInvoice;
use App\Models\SalesDraftCart;
use App\Services\Stock\StockAvailabilityService;
use App\Services\Stock\StockService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\JournalReversalService;
use App\Services\Accounting\SubLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sales Invoice Service — Phase 8.2.
 *
 * Finalizes a cart into a draft sales invoice:
 *   1. Validate cart (stock availability + price range)
 *   2. Check credit limit (with optional override)
 *   3. Lock branch products FOR UPDATE (prevent race conditions)
 *   4. Create sales_invoice (status=draft)
 *   5. Create sales_invoice_items (from cart, warehouse_id=NULL)
 *   6. Create sales_invoice_dispatches (ordered_qty, dispatched_qty=0)
 *   7. Post customer_ledger debit (customer owes more)
 *   8. Post GL: Dr Accounts Receivable / Cr Sales Revenue
 *      (+ Dr Discount / Cr Transport if applicable)
 *   9. Clear the cart
 *
 * The invoice is a DRAFT at this point — no stock movement yet.
 * Stock moves on challan issue (Phase 8.3).
 */
class SalesInvoiceService
{
    public function __construct(
        private SalesCartService $cartService,
        private StockAvailabilityService $availabilityService,
        private StockService $stockService,
        private JournalPostingService $journalPosting,
        private JournalReversalService $journalReversal,
        private SubLedgerService $subLedger,
        private SalesAccess $salesAccess,
        private SalesAuditLogger $auditLogger
    ) {}

    /**
     * Finalize a cart into a draft sales invoice.
     *
     * @param array $data {
     *     customer_id: int,
     *     branch_id: int,
     *     invoice_date: string (Y-m-d),
     *     salesman_id: int|null,
     *     sales_person: string|null,
     *     discount_amount: float,
     *     transport_cost: float,
     *     notes: string|null,
     *     is_soft_hold: bool,
     *     credit_limit_override: bool,
     *     override_reason: string|null,
     *     created_by: int,
     * }
     * @return SalesInvoice
     * @throws \RuntimeException If cart empty, stock insufficient, credit limit exceeded without override, or GL posting fails.
     */
    public function finalizeFromCart(array $data): SalesInvoice
    {
        $customerId = (int) $data['customer_id'];
        $branchId = (int) $data['branch_id'];

        if ($customerId <= 0) {
            throw new \InvalidArgumentException('customer_id is required.');
        }
        if ($branchId <= 0) {
            throw new \InvalidArgumentException('branch_id is required.');
        }

        // P0-8: Defense-in-depth branch isolation check.
        $this->salesAccess->assertBranchAccessible($branchId);

        // Step 1: Load + validate the cart.
        $cartData = $this->cartService->getCart($data['created_by'] ?? auth()->id(), $customerId, $branchId);
        $items = $cartData['items'];

        if (empty($items)) {
            throw new \RuntimeException('Cart is empty. Add products before finalizing.');
        }

        $validation = $cartData['validation'];
        if (!$validation['valid']) {
            throw new \RuntimeException('Cart validation failed: ' . $validation['message']);
        }

        // Step 2: Calculate totals.
        $subTotal = collect($items)->sum('total');
        $discount = (float) ($data['discount_amount'] ?? 0);
        $transport = (float) ($data['transport_cost'] ?? 0);
        $totalAmount = $subTotal + $transport - $discount;

        // Step 3: Credit limit check.
        $creditCheck = $this->checkCreditLimit($customerId, $totalAmount);
        $isOverride = !empty($data['credit_limit_override']);
        $overrideReason = trim($data['override_reason'] ?? '');

        if ($creditCheck['exceeds'] && !$isOverride) {
            throw new \RuntimeException(
                "Credit limit exceeded. Customer balance: {$creditCheck['current_balance']}, "
                . "Credit limit: {$creditCheck['credit_limit']}, "
                . "This invoice: {$totalAmount}. "
                . "Override with a reason to proceed."
            );
        }

        if ($creditCheck['exceeds'] && $isOverride && strlen($overrideReason) < 10) {
            throw new \RuntimeException('Override reason must be at least 10 characters when exceeding credit limit.');
        }

        // Step 4: Atomic finalize in a DB transaction.
        return DB::transaction(function () use (
            $data, $items, $customerId, $branchId, $subTotal, $discount, $transport, $totalAmount,
            $creditCheck, $isOverride, $overrideReason
        ) {
            // Lock branch products FOR UPDATE (prevent race conditions on concurrent finalizes).
            $productIds = collect($items)->pluck('product_id')->unique()->toArray();
            $this->stockService->lockBranchProductsForUpdate($branchId, $productIds);

            // Re-check availability after locking.
            $qtyByProduct = [];
            foreach ($items as $item) {
                $pid = (int) $item['product_id'];
                $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + (float) $item['qty'];
            }

            foreach ($qtyByProduct as $pid => $qty) {
                $available = $this->availabilityService->getBranchAvailableQty($pid, $branchId);
                if ($qty > $available + 0.0001) {
                    throw new \RuntimeException(
                        "Insufficient stock for product {$pid}: requested {$qty}, available {$available}"
                    );
                }
            }

            // Generate invoice code.
            $invoiceCode = $this->generateInvoiceCode();

            // Step 5: Create sales_invoice header.
            $invoiceId = DB::table('sales_invoices')->insertGetId([
                'invoice_code' => $invoiceCode,
                'invoice_date' => $data['invoice_date'] ?? now()->format('Y-m-d'),
                'customer_id' => $customerId,
                'salesman_id' => $data['salesman_id'] ?? null,
                'sales_person' => $data['sales_person'] ?? null,
                'branch_id' => $branchId,
                'sub_total' => round($subTotal, 2),
                'discount_amount' => round($discount, 2),
                'transport_cost' => round($transport, 2),
                'total_amount' => round($totalAmount, 2),
                'paid_amount' => 0,
                'due_amount' => round($totalAmount, 2),
                'payment_mode' => 'cash',
                'status' => 'draft',
                'is_godown_prepared' => false,
                'is_challan_issued' => false,
                'is_reversed' => false,
                'is_soft_hold' => $data['is_soft_hold'] ?? false,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Step 6: Create sales_invoice_items (warehouse_id=NULL — assigned at godown).
            $itemRows = [];
            foreach ($items as $item) {
                $itemRows[] = [
                    'sales_invoice_id' => $invoiceId,
                    'product_id' => (int) $item['product_id'],
                    'warehouse_id' => null, // assigned at godown (Phase 8.3)
                    'qty' => (float) $item['qty'],
                    'rate' => (float) $item['rate'],
                    'condition_state' => 'Good',
                ];
            }
            DB::table('sales_invoice_items')->insert($itemRows);

            // Step 7: Create sales_invoice_dispatches (pipeline tracking).
            $dispatchRows = [];
            foreach ($items as $item) {
                $dispatchRows[] = [
                    'sales_invoice_id' => $invoiceId,
                    'product_id' => (int) $item['product_id'],
                    'warehouse_id' => null, // assigned at godown
                    'qty' => (float) $item['qty'], // mirrors ordered_qty for GENERATED amount
                    'ordered_qty' => (float) $item['qty'],
                    'dispatched_qty' => 0,
                    'created_by' => $data['created_by'] ?? null,
                ];
            }
            DB::table('sales_invoice_dispatches')->insert($dispatchRows);

            // Step 8: Post GL FIRST to get journal_entry_id.
            $journalEntryId = $this->postInvoiceGL(
                $invoiceId, $invoiceCode, $customerId, $branchId,
                $subTotal, $discount, $transport, $totalAmount,
                $data['invoice_date'] ?? now()->format('Y-m-d'),
                $data['created_by'] ?? null
            );

            // Step 9: Post customer_ledger debit via SubLedgerService (customer owes more).
            $this->subLedger->postCustomerLedgerEntry([
                'customer_id' => $customerId,
                'branch_id' => $branchId,
                'transaction_date' => $data['invoice_date'] ?? now()->format('Y-m-d'),
                'transaction_type' => 'sales_invoice',
                'reference_type' => 'sales_invoice',
                'reference_id' => $invoiceId,
                'debit' => $totalAmount,
                'credit' => 0,
                'description' => 'Invoice ' . $invoiceCode,
                'journal_entry_id' => $journalEntryId,
                'created_by' => $data['created_by'] ?? null,
            ]);

            // Update invoice with journal_entry_id.
            DB::table('sales_invoices')
                ->where('id', $invoiceId)
                ->update(['journal_entry_id' => $journalEntryId, 'updated_at' => now()]);

            // Step 10: Clear the cart.
            $this->cartService->clearCart($data['created_by'] ?? auth()->id(), $customerId);

            // Audit log for credit limit override.
            if ($isOverride && $creditCheck['exceeds']) {
                DB::table('user_audit_log')->insert([
                    'user_id' => $data['created_by'] ?? null,
                    'action' => 'credit_limit_override',
                    'target_user_id' => null,
                    'branch_id' => $branchId,
                    'details' => json_encode([
                        'invoice_id' => $invoiceId,
                        'invoice_code' => $invoiceCode,
                        'customer_id' => $customerId,
                        'total_amount' => $totalAmount,
                        'credit_limit' => $creditCheck['credit_limit'],
                        'current_balance' => $creditCheck['current_balance'],
                        'override_reason' => $overrideReason,
                    ]),
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent() ? mb_substr(request()->userAgent(), 0, 255) : null,
                    'created_at' => now(),
                ]);
            }

            // P1-3: Audit log — sale_created.
            $this->auditLogger->saleCreated(
                $data['created_by'] ?? auth()->id() ?? 0,
                $invoiceId, $invoiceCode, $customerId, $branchId,
                $totalAmount, $data['salesman_id'] ?? null
            );

            // P2-7: Invalidate pipeline cache (new dispatches were added).
            $this->availabilityService->invalidatePipelineForInvoice($invoiceId);

            return SalesInvoice::with(['items.product', 'customer', 'branch', 'journalEntry.lines.ledger'])
                ->find($invoiceId);
        });
    }

    /**
     * Cancel a draft invoice (reverse GL + customer_ledger + dispatches).
     * Only draft invoices can be cancelled (before godown/challan).
     *
     * @param int $invoiceId
     * @param int $cancelledBy
     * @param string $reason
     * @return SalesInvoice
     */
    public function cancelInvoice(int $invoiceId, int $cancelledBy, string $reason = ''): SalesInvoice
    {
        return DB::transaction(function () use ($invoiceId, $cancelledBy, $reason) {
            $invoice = SalesInvoice::lockForUpdate()->find($invoiceId);

            if (!$invoice) {
                throw new \RuntimeException("Invoice {$invoiceId} not found.");
            }

            // P0-8: Defense-in-depth branch isolation check.
            $this->salesAccess->assertBranchAccessible((int) $invoice->branch_id);

            if (!$invoice->isDraft()) {
                throw new \RuntimeException("Only draft invoices can be cancelled (current: {$invoice->status}). Cancel via reversal for confirmed invoices.");
            }

            // P2-2: Explicit guards with clear messages (legacy layered checks).
            if ($this->hasActiveChallan($invoiceId)) {
                throw new \RuntimeException("Cannot cancel: a delivery challan exists for this invoice. Reverse the challan first.");
            }
            if ($this->invoiceHasPayments($invoiceId)) {
                throw new \RuntimeException("Cannot cancel: payments have been received against this invoice. Reverse the payments first.");
            }

            // Reverse GL + linked customer_ledger via JournalReversalService (cascade).
            if ($invoice->journal_entry_id) {
                $this->journalReversal->reverseByJournalEntry(
                    $invoice->journal_entry_id, $cancelledBy,
                    "Invoice cancelled: {$reason}"
                );
            }

            // Mark invoice as cancelled.
            DB::table('sales_invoices')
                ->where('id', $invoiceId)
                ->update([
                    'status' => 'cancelled',
                    'is_reversed' => true,
                    'reversed_at' => now(),
                    'reversed_by' => $cancelledBy,
                    'reverse_reason' => $reason,
                    'updated_at' => now(),
                ]);

            // P1-3: Audit log — sale_cancelled.
            $this->auditLogger->saleCancelled(
                $cancelledBy,
                $invoiceId, $invoice->invoice_code, (int) $invoice->branch_id,
                (float) $invoice->total_amount, $reason
            );

            // P2-7: Invalidate pipeline cache (dispatches were deleted).
            $this->availabilityService->invalidatePipelineForInvoice($invoiceId);

            return SalesInvoice::find($invoiceId);
        });
    }

    /**
     * Update an existing DRAFT invoice (P1-1).
     *
     * Restores the legacy updateExistingInvoice flow:
     *   1. Assert editable (draft, no godown, no payments, not reversed)
     *   2. Re-validate credit limit (using NET increase = max(0, newTotal - oldTotal))
     *   3. Lock branch products FOR UPDATE + re-check availability
     *   4. Reverse old GL journal entry + linked customer_ledger via JournalReversalService
     *   5. DELETE old items + dispatches + dispatchers
     *   6. INSERT new items + dispatches (soft reservation, warehouse_id=NULL)
     *   7. Post new customer_ledger debit (new total)
     *   8. Post new GL journal entry (Dr AR / Cr Revenue + Discount + Transport)
     *   9. UPDATE invoice header (sub_total, discount, transport, total, notes, etc.)
     *
     * @param int $invoiceId
     * @param array $data {
     *     items: array of {product_id, qty, rate},
     *     invoice_date: string,
     *     sales_person: string|null,
     *     discount_amount: float,
     *     transport_cost: float,
     *     notes: string|null,
     *     is_soft_hold: bool,
     *     credit_limit_override: bool,
     *     override_reason: string|null,
     *     updated_by: int,
     * }
     * @return SalesInvoice
     * @throws \RuntimeException If not editable, stock insufficient, credit exceeded, or GL fails.
     */
    public function updateInvoice(int $invoiceId, array $data): SalesInvoice
    {
        $items = $data['items'] ?? [];
        $updatedBy = (int) ($data['updated_by'] ?? auth()->id() ?? 0);

        if (empty($items)) {
            throw new \RuntimeException('Cannot update: items list is empty.');
        }

        // --- Pre-flight: load invoice (without transaction yet) for validation ---
        $invoice = SalesInvoice::find($invoiceId);
        if (!$invoice) {
            throw new \RuntimeException("Invoice {$invoiceId} not found.");
        }

        // P0-8: Defense-in-depth branch isolation check.
        $this->salesAccess->assertBranchAccessible((int) $invoice->branch_id);

        // Assert editable.
        $this->assertEditable($invoice);

        // Assert no payments.
        if ($this->invoiceHasPayments($invoiceId)) {
            throw new \RuntimeException('Cannot edit: payments have been received against this invoice. Reverse the payments first.');
        }

        $oldTotal = (float) $invoice->total_amount;
        $customerId = (int) $invoice->customer_id;
        $branchId = (int) $invoice->branch_id;
        $oldJournalId = $invoice->journal_entry_id ? (int) $invoice->journal_entry_id : null;

        // --- Calculate new totals ---
        $subTotal = 0.0;
        foreach ($items as $item) {
            $subTotal += (float) $item['qty'] * (float) $item['rate'];
        }
        $discount = (float) ($data['discount_amount'] ?? 0);
        $transport = (float) ($data['transport_cost'] ?? 0);
        $newTotal = $subTotal + $transport - $discount;

        // --- Credit limit check (NET increase, not full new total) ---
        $netIncrease = max(0.0, $newTotal - $oldTotal);
        $creditCheck = $this->checkCreditLimit($customerId, $netIncrease);
        $isOverride = !empty($data['credit_limit_override']);
        $overrideReason = trim($data['override_reason'] ?? '');

        if ($creditCheck['exceeds'] && !$isOverride) {
            throw new \RuntimeException(
                "Updating this invoice would exceed the customer's credit limit. "
                . "Current balance: {$creditCheck['current_balance']}, "
                . "Credit limit: {$creditCheck['credit_limit']}, "
                . "Net increase: {$netIncrease}. "
                . "Override with a reason to proceed."
            );
        }

        if ($creditCheck['exceeds'] && $isOverride && strlen($overrideReason) < 10) {
            throw new \RuntimeException('Override reason must be at least 10 characters when exceeding credit limit.');
        }

        $invoiceDate = $data['invoice_date'] ?? $invoice->invoice_date;

        // --- Atomic update in a DB transaction ---
        return DB::transaction(function () use (
            $invoiceId, $invoice, $items, $customerId, $branchId, $oldTotal, $newTotal,
            $subTotal, $discount, $transport, $invoiceDate, $oldJournalId,
            $creditCheck, $isOverride, $overrideReason, $updatedBy, $data
        ) {
            // Lock the invoice row FOR UPDATE.
            $locked = SalesInvoice::lockForUpdate()->find($invoiceId);
            if (!$locked) {
                throw new \RuntimeException("Invoice {$invoiceId} not found (locked).");
            }

            // Re-assert editable after lock (race protection).
            $this->assertEditable($locked);

            // Lock branch products FOR UPDATE.
            $productIds = collect($items)->pluck('product_id')->unique()->toArray();
            $this->stockService->lockBranchProductsForUpdate($branchId, $productIds);

            // Re-check stock availability (excluding this invoice's own pipeline).
            $qtyByProduct = [];
            foreach ($items as $item) {
                $pid = (int) $item['product_id'];
                $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + (float) $item['qty'];
            }

            foreach ($qtyByProduct as $pid => $qty) {
                $available = $this->availabilityService->getBranchAvailableQty($pid, $branchId, $invoiceId);
                if ($qty > $available + 0.0001) {
                    throw new \RuntimeException(
                        "Insufficient stock for product {$pid}: requested {$qty}, available {$available}"
                    );
                }
            }

            // Step 1: Reverse old GL journal entry + linked customer_ledger via JournalReversalService.
            // The cascade handles both the GL reversal (swap Dr/Cr) and the sub-ledger
            // reversal (mark original is_reversed, post opposite entry) atomically.
            if ($oldJournalId) {
                $this->journalReversal->reverseByJournalEntry(
                    $oldJournalId, $updatedBy,
                    'Invoice edited: ' . $invoice->invoice_code
                );
            }

            // Step 2: DELETE old items + dispatches + dispatchers.
            DB::table('sales_invoice_items')->where('sales_invoice_id', $invoiceId)->delete();
            DB::table('sales_invoice_dispatches')->where('sales_invoice_id', $invoiceId)->delete();
            DB::table('sales_invoice_dispatchers')->where('sales_invoice_id', $invoiceId)->delete();

            // Step 4: INSERT new items.
            $itemRows = [];
            foreach ($items as $item) {
                $itemRows[] = [
                    'sales_invoice_id' => $invoiceId,
                    'product_id' => (int) $item['product_id'],
                    'warehouse_id' => null, // reset to NULL — godown must re-assign after edit
                    'qty' => (float) $item['qty'],
                    'rate' => (float) $item['rate'],
                    'condition_state' => $item['condition_state'] ?? 'Good',
                ];
            }
            DB::table('sales_invoice_items')->insert($itemRows);

            // Step 5: INSERT new dispatches (soft reservation, warehouse_id=NULL).
            $dispatchRows = [];
            foreach ($items as $item) {
                $dispatchRows[] = [
                    'sales_invoice_id' => $invoiceId,
                    'product_id' => (int) $item['product_id'],
                    'warehouse_id' => null,
                    'qty' => (float) $item['qty'],
                    'ordered_qty' => (float) $item['qty'],
                    'dispatched_qty' => 0,
                    'created_by' => $updatedBy,
                ];
            }
            DB::table('sales_invoice_dispatches')->insert($dispatchRows);

            // Step 6: Post GL FIRST to get new journal_entry_id.
            $newJournalEntryId = $this->postInvoiceGL(
                $invoiceId, $invoice->invoice_code, $customerId, $branchId,
                $subTotal, $discount, $transport, $newTotal,
                $invoiceDate, $updatedBy
            );

            // Step 7: Post new customer_ledger debit via SubLedgerService.
            $this->subLedger->postCustomerLedgerEntry([
                'customer_id' => $customerId,
                'branch_id' => $branchId,
                'transaction_date' => $invoiceDate,
                'transaction_type' => 'sales_invoice',
                'reference_type' => 'sales_invoice',
                'reference_id' => $invoiceId,
                'debit' => $newTotal,
                'credit' => 0,
                'description' => 'Invoice ' . $invoice->invoice_code . ' (edited)',
                'journal_entry_id' => $newJournalEntryId,
                'created_by' => $updatedBy,
            ]);

            // Step 8: UPDATE invoice header.
            DB::table('sales_invoices')
                ->where('id', $invoiceId)
                ->update([
                    'invoice_date' => $invoiceDate,
                    'sales_person' => $data['sales_person'] ?? null,
                    'sub_total' => round($subTotal, 2),
                    'discount_amount' => round($discount, 2),
                    'transport_cost' => round($transport, 2),
                    'total_amount' => round($newTotal, 2),
                    'due_amount' => round($newTotal, 2) - (float) $invoice->paid_amount,
                    'is_soft_hold' => $data['is_soft_hold'] ?? false,
                    'notes' => $data['notes'] ?? null,
                    'journal_entry_id' => $newJournalEntryId,
                    // Reset godown/challan flags (edit invalidates prior godown prep).
                    'is_godown_prepared' => false,
                    'godown_prepared_at' => null,
                    'updated_at' => now(),
                ]);

            // Step 9: Audit log for credit limit override.
            if ($isOverride && $creditCheck['exceeds']) {
                DB::table('user_audit_log')->insert([
                    'user_id' => $updatedBy,
                    'action' => 'credit_limit_override',
                    'target_user_id' => null,
                    'branch_id' => $branchId,
                    'details' => json_encode([
                        'action' => 'invoice_edit',
                        'invoice_id' => $invoiceId,
                        'invoice_code' => $invoice->invoice_code,
                        'customer_id' => $customerId,
                        'old_total' => $oldTotal,
                        'new_total' => $newTotal,
                        'net_increase' => $netIncrease,
                        'credit_limit' => $creditCheck['credit_limit'],
                        'current_balance' => $creditCheck['current_balance'],
                        'override_reason' => $overrideReason,
                    ]),
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent() ? mb_substr(request()->userAgent(), 0, 255) : null,
                    'created_at' => now(),
                ]);
            }

            // Audit log for the edit itself.
            DB::table('user_audit_log')->insert([
                'user_id' => $updatedBy,
                'action' => 'sale_updated',
                'target_user_id' => null,
                'branch_id' => $branchId,
                'details' => json_encode([
                    'invoice_id' => $invoiceId,
                    'invoice_code' => $invoice->invoice_code,
                    'old_total' => $oldTotal,
                    'new_total' => $newTotal,
                    'items_count' => count($items),
                    'credit_override' => $isOverride && $creditCheck['exceeds'],
                ]),
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent() ? mb_substr(request()->userAgent(), 0, 255) : null,
                'created_at' => now(),
            ]);

            // P2-7: Invalidate pipeline cache (dispatches were deleted + re-inserted).
            $this->availabilityService->invalidatePipelineForInvoice($invoiceId);

            return SalesInvoice::with(['items.product', 'customer', 'branch', 'journalEntry.lines.ledger'])
                ->find($invoiceId);
        });
    }

    /**
     * Assert an invoice is editable (P1-1).
     * Must be: status='draft', not godown-prepared, not reversed.
     */
    private function assertEditable(SalesInvoice $invoice): void
    {
        if ($invoice->is_reversed) {
            throw new \RuntimeException('Cannot edit: invoice is reversed.');
        }
        if ($invoice->status !== 'draft') {
            throw new \RuntimeException("Cannot edit: invoice status is '{$invoice->status}'. Only draft invoices can be edited.");
        }
        if ($invoice->is_godown_prepared) {
            throw new \RuntimeException('Cannot edit: godown has already been prepared for this invoice.');
        }
        if ($invoice->is_challan_issued) {
            throw new \RuntimeException('Cannot edit: a delivery challan has already been issued for this invoice.');
        }
    }

    /**
     * Check if an invoice has any non-reversed payment allocations (P1-1).
     */
    private function invoiceHasPayments(int $invoiceId): bool
    {
        return DB::table('invoice_payment_allocations as ipa')
            ->join('customer_payments as cp', 'cp.id', '=', 'ipa.payment_id')
            ->where('ipa.invoice_id', $invoiceId)
            ->where('cp.is_reversed', false)
            ->exists();
    }

    /**
     * Check if an invoice has any non-reversed sales challans (P2-2).
     */
    private function hasActiveChallan(int $invoiceId): bool
    {
        return DB::table('sales_challans')
            ->where('sales_invoice_id', $invoiceId)
            ->where('is_reversed', false)
            ->exists();
    }

    /**
     * Check if the invoice would exceed the customer's credit limit.
     *
     * @return array{ exceeds: bool, current_balance: float, credit_limit: float, new_balance: float }
     */
    private function checkCreditLimit(int $customerId, float $invoiceAmount): array
    {
        $customer = DB::table('customers')->where('id', $customerId)->first();

        if (!$customer) {
            return ['exceeds' => false, 'current_balance' => 0, 'credit_limit' => 0, 'new_balance' => 0];
        }

        $creditLimit = (float) ($customer->credit_limit ?? 0);

        // Current AR balance from customer_ledger (sum of debit - credit).
        $currentBalance = (float) DB::table('customer_ledger')
            ->where('customer_id', $customerId)
            ->where('is_reversed', false)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as balance')
            ->value('balance');

        $newBalance = $currentBalance + $invoiceAmount;
        $exceeds = $creditLimit > 0 && $newBalance > $creditLimit + 0.01;

        return [
            'exceeds' => $exceeds,
            'current_balance' => $currentBalance,
            'credit_limit' => $creditLimit,
            'new_balance' => $newBalance,
        ];
    }

    /**
     * Post GL: Dr Accounts Receivable / Cr Sales Revenue.
     * + Dr Discount (if discount > 0)
     * + Cr Transport Revenue (if transport > 0)
     *
     * @return int journal_entry_id
     */
    private function postInvoiceGL(
        int $invoiceId, string $invoiceCode, int $customerId, int $branchId,
        float $subTotal, float $discount, float $transport, float $total,
        string $invoiceDate, ?int $createdBy
    ): int {
        $arLedgerId = $this->journalPosting->lookupLedgerByNature('ar');
        $revenueLedgerId = $this->journalPosting->lookupLedgerByNature('sales_revenue');

        if (!$arLedgerId) {
            throw new \RuntimeException('Accounts Receivable ledger not found (nature: ar).');
        }
        if (!$revenueLedgerId) {
            throw new \RuntimeException('Sales Revenue ledger not found (nature: sales_revenue).');
        }

        $lines = [];

        // Dr Accounts Receivable (total)
        $lines[] = [
            'ledger_id' => $arLedgerId,
            'debit' => $total, 'credit' => 0,
            'entity_type' => 'customer', 'entity_id' => $customerId,
            'memo' => 'Invoice ' . $invoiceCode . ' — AR',
        ];

        // Cr Sales Revenue (subTotal - discount, or subTotal if no discount ledger)
        $discountLedgerId = $discount > 0.01 ? $this->journalPosting->lookupLedgerByNature('sales_discount') : null;
        $revenueAmount = $discountLedgerId ? $subTotal : max(0, $subTotal - $discount);

        $lines[] = [
            'ledger_id' => $revenueLedgerId,
            'debit' => 0, 'credit' => $revenueAmount,
            'entity_type' => 'sales_invoice', 'entity_id' => $invoiceId,
            'memo' => 'Invoice ' . $invoiceCode . ' — Revenue',
        ];

        // Dr Discount (if applicable)
        if ($discountLedgerId && $discount > 0.01) {
            $lines[] = [
                'ledger_id' => $discountLedgerId,
                'debit' => $discount, 'credit' => 0,
                'entity_type' => 'sales_invoice', 'entity_id' => $invoiceId,
                'memo' => 'Invoice ' . $invoiceCode . ' — Discount allowed',
            ];
        }

        // Cr Transport Revenue (if applicable)
        if ($transport > 0.01) {
            $transportLedgerId = $this->journalPosting->lookupLedgerByNature('transport_revenue');
            if ($transportLedgerId) {
                $lines[] = [
                    'ledger_id' => $transportLedgerId,
                    'debit' => 0, 'credit' => $transport,
                    'entity_type' => 'sales_invoice', 'entity_id' => $invoiceId,
                    'memo' => 'Invoice ' . $invoiceCode . ' — Transport',
                ];
            }
        }

        return $this->journalPosting->createJournalEntry([
            'entry_date' => $invoiceDate,
            'reference_type' => 'sales_invoice',
            'reference_id' => $invoiceId,
            'branch_id' => $branchId,
            'description' => 'Sales Invoice ' . $invoiceCode,
            'source' => 'sales_invoice',
            'created_by' => $createdBy,
        ], $lines);
    }

    /**
     * Generate atomic invoice code: INV-YYYYMMDD-NNNN.
     */
    private function generateInvoiceCode(): string
    {
        $datePart = now()->format('Ymd');
        $periodKey = now()->format('Y-m');
        $docType = 'sales_invoice';

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

            return "INV-{$datePart}-" . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
        });
    }
}

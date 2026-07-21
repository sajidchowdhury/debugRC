<?php

namespace App\Services\Sales;

use App\Services\Auth\UserAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sales Audit Logger — P1-3.
 *
 * Wraps the generic UserAuditLogger with sales-specific event methods,
 * restoring the 9+ business-event audit trail that legacy had:
 *
 *   1. sale_created          — invoice finalize
 *   2. sale_updated          — invoice edit (already added in P1-1)
 *   3. sale_cancelled        — invoice cancel
 *   4. credit_limit_override — override used (already in P0-6/P1-1)
 *   5. payment_received      — customer payment confirmed
 *   6. payment_reversed      — payment cancelled
 *   6a. payment_discount     — discount allowed (transaction_type='discount')
 *   6b. payment_write_off    — bad debt written off (transaction_type='write_off')
 *   6c. payment_refund       — refund to customer (transaction_type='payment')
 *   7. return_created        — sales return created
 *   8. return_confirmed      — return confirmed (stock IN + GL)
 *   9. return_reversed       — return reversed
 *  10. godown_prepared       — godown prep (warehouse assigned)
 *  11. challan_issued        — challan issued (stock OUT + COGS)
 *  12. challan_reversed      — challan cancelled
 *  13. stale_drafts_cancelled — bulk stale cleanup (already in P1-2)
 *  14. cart_item_added       — product added to draft cart (R4)
 *  15. cart_item_updated     — cart item qty/rate edited (R4)
 *  16. cart_item_removed     — product removed from cart (R4)
 *  17. cart_cleared          — entire cart emptied (R4)
 *
 * Legacy equivalent: SalesController::auditLog:39-42 wrote to user_audit_log
 * via $this->userAudit->log(). Events: sale_created, sale_updated, sale_deleted,
 * sale_call_a_day, sale_credit_limit_override, payment_received, payment_reversed,
 * return_confirmed, return_reversed + godown_prepared, challan_completed,
 * challan_reversed.
 *
 * R4 (2026-07-21): added the 4 cart-mutation events (14–17). Legacy had no
 * equivalent — this closes audit risk V4 (cart tampering leaves no trail).
 *
 * This logger uses UserAuditLogger::log() which does dual-write:
 *   - user_audit_log table (PG, jsonb details)
 *   - logs/user_audit.log file (JSON lines, defense in depth)
 *
 * All methods are safe to call inside a DB transaction (the audit insert
 * joins the same transaction). If the transaction rolls back, the audit
 * row is also rolled back (no orphan audit entries).
 */
class SalesAuditLogger
{
    /**
     * Log a sales invoice creation (finalize).
     */
    public function saleCreated(
        int $userId, int $invoiceId, string $invoiceCode, int $customerId,
        int $branchId, float $totalAmount, ?int $salesmanId = null
    ): void {
        $this->log($userId, 'sale_created', $branchId, [
            'invoice_id' => $invoiceId,
            'invoice_code' => $invoiceCode,
            'customer_id' => $customerId,
            'total_amount' => round($totalAmount, 2),
            'salesman_id' => $salesmanId,
        ]);
    }

    /**
     * Log a sales invoice cancellation.
     */
    public function saleCancelled(
        int $userId, int $invoiceId, string $invoiceCode, int $branchId,
        float $totalAmount, string $reason
    ): void {
        $this->log($userId, 'sale_cancelled', $branchId, [
            'invoice_id' => $invoiceId,
            'invoice_code' => $invoiceCode,
            'total_amount' => round($totalAmount, 2),
            'reason' => $reason,
        ]);
    }

    /**
     * Log a customer payment received (confirmed).
     */
    public function paymentReceived(
        int $userId, int $paymentId, string $paymentCode, int $customerId,
        int $branchId, float $amount, string $mode, ?int $invoiceId = null
    ): void {
        $this->log($userId, 'payment_received', $branchId, [
            'payment_id' => $paymentId,
            'payment_code' => $paymentCode,
            'customer_id' => $customerId,
            'amount' => round($amount, 2),
            'payment_mode' => $mode,
            'invoice_id' => $invoiceId,
        ]);
    }

    /**
     * Log a customer payment reversal (cancel).
     */
    public function paymentReversed(
        int $userId, int $paymentId, string $paymentCode, int $branchId,
        float $amount, string $reason
    ): void {
        $this->log($userId, 'payment_reversed', $branchId, [
            'payment_id' => $paymentId,
            'payment_code' => $paymentCode,
            'amount' => round($amount, 2),
            'reason' => $reason,
        ]);
    }

    /**
     * Log a customer discount allowed (transaction_type='discount').
     */
    public function paymentDiscount(
        int $userId, int $paymentId, string $paymentCode, int $customerId,
        int $branchId, float $amount, float $discountAmount
    ): void {
        $this->log($userId, 'payment_discount', $branchId, [
            'payment_id' => $paymentId,
            'payment_code' => $paymentCode,
            'customer_id' => $customerId,
            'amount' => round($amount, 2),
            'discount_amount' => round($discountAmount, 2),
            'transaction_type' => 'discount',
        ]);
    }

    /**
     * Log a bad debt write-off (transaction_type='write_off').
     */
    public function paymentWriteOff(
        int $userId, int $paymentId, string $paymentCode, int $customerId,
        int $branchId, float $amount
    ): void {
        $this->log($userId, 'payment_write_off', $branchId, [
            'payment_id' => $paymentId,
            'payment_code' => $paymentCode,
            'customer_id' => $customerId,
            'amount' => round($amount, 2),
            'transaction_type' => 'write_off',
        ]);
    }

    /**
     * Log a customer refund (transaction_type='payment').
     */
    public function paymentRefund(
        int $userId, int $paymentId, string $paymentCode, int $customerId,
        int $branchId, float $amount, string $mode
    ): void {
        $this->log($userId, 'payment_refund', $branchId, [
            'payment_id' => $paymentId,
            'payment_code' => $paymentCode,
            'customer_id' => $customerId,
            'amount' => round($amount, 2),
            'payment_mode' => $mode,
            'transaction_type' => 'payment',
        ]);
    }

    /**
     * Log a Call It A Day batch operation (Gap G-10).
     * Sets call_a_day = true on selected invoices to remove them
     * from the daily collection list (Sales Today view).
     * Legacy event name: sale_call_a_day.
     */
    public function callItADay(
        int $userId, int $branchId, array $invoiceIds, int $updatedCount
    ): void {
        $this->log($userId, 'sale_call_a_day', $branchId, [
            'invoice_ids' => $invoiceIds,
            'invoice_count' => count($invoiceIds),
            'updated_count' => $updatedCount,
        ]);
    }

    /**
     * Log a sales return creation.
     */
    public function returnCreated(
        int $userId, int $returnId, string $returnCode, int $invoiceId,
        int $customerId, int $branchId, float $totalAmount, float $cogsAmount
    ): void {
        $this->log($userId, 'return_created', $branchId, [
            'return_id' => $returnId,
            'return_code' => $returnCode,
            'invoice_id' => $invoiceId,
            'customer_id' => $customerId,
            'total_amount' => round($totalAmount, 2),
            'cogs_amount' => round($cogsAmount, 2),
        ]);
    }

    /**
     * Log a sales return confirmation (stock IN + GL posted).
     */
    public function returnConfirmed(
        int $userId, int $returnId, string $returnCode, int $branchId,
        float $totalAmount, float $cogsAmount, ?int $journalEntryId = null
    ): void {
        $this->log($userId, 'return_confirmed', $branchId, [
            'return_id' => $returnId,
            'return_code' => $returnCode,
            'total_amount' => round($totalAmount, 2),
            'cogs_amount' => round($cogsAmount, 2),
            'journal_entry_id' => $journalEntryId,
        ]);
    }

    /**
     * Log a sales return reversal.
     */
    public function returnReversed(
        int $userId, int $returnId, string $returnCode, int $branchId,
        float $totalAmount, string $reason
    ): void {
        $this->log($userId, 'return_reversed', $branchId, [
            'return_id' => $returnId,
            'return_code' => $returnCode,
            'total_amount' => round($totalAmount, 2),
            'reason' => $reason,
        ]);
    }

    /**
     * Log a godown preparation (warehouse assigned to invoice items).
     */
    public function godownPrepared(
        int $userId, int $invoiceId, string $invoiceCode, int $branchId
    ): void {
        $this->log($userId, 'godown_prepared', $branchId, [
            'invoice_id' => $invoiceId,
            'invoice_code' => $invoiceCode,
        ]);
    }

    /**
     * Log a challan issue (stock OUT + COGS posted).
     */
    public function challanIssued(
        int $userId, int $challanId, string $challanCode, int $invoiceId,
        int $branchId, float $cogsAmount, ?int $journalEntryId = null
    ): void {
        $this->log($userId, 'challan_issued', $branchId, [
            'challan_id' => $challanId,
            'challan_code' => $challanCode,
            'invoice_id' => $invoiceId,
            'cogs_amount' => round($cogsAmount, 2),
            'journal_entry_id' => $journalEntryId,
        ]);
    }

    /**
     * Log a challan reversal (cancel).
     */
    public function challanReversed(
        int $userId, int $challanId, string $challanCode, int $invoiceId,
        int $branchId, string $reason
    ): void {
        $this->log($userId, 'challan_reversed', $branchId, [
            'challan_id' => $challanId,
            'challan_code' => $challanCode,
            'invoice_id' => $invoiceId,
            'reason' => $reason,
        ]);
    }

    /**
     * R4: Log a cart item addition (product added to draft cart).
     *
     * Captures the product, qty, rate, whether the line was newly created
     * or merged into an existing same-rate line, and the resulting cart
     * totals so an auditor can replay the cart state at any point.
     *
     * @param int      $userId
     * @param int      $customerId
     * @param int      $branchId
     * @param int      $productId
     * @param float    $qty            Quantity added in this call (NOT the resulting cart qty).
     * @param float    $rate           Unit rate.
     * @param bool     $merged         True if the line was merged into an existing same-rate line.
     * @param int      $cartItemCount  Total distinct products in cart after the add.
     * @param float    $cartSubtotal   Cart subtotal after the add.
     */
    public function cartItemAdded(
        int $userId, int $customerId, int $branchId,
        int $productId, float $qty, float $rate,
        bool $merged, int $cartItemCount, float $cartSubtotal
    ): void {
        $this->log($userId, 'cart_item_added', $branchId, [
            'customer_id'     => $customerId,
            'product_id'      => $productId,
            'qty_added'       => round($qty, 4),
            'rate'            => round($rate, 4),
            'merged'          => $merged,
            'cart_item_count' => $cartItemCount,
            'cart_subtotal'   => round($cartSubtotal, 2),
        ]);
    }

    /**
     * R4: Log a cart item update (qty and/or rate change).
     *
     * Captures before/after values so the delta is auditable without
     * having to reconstruct prior cart snapshots.
     *
     * @param int        $userId
     * @param int        $customerId
     * @param int        $branchId
     * @param int        $productId
     * @param float      $oldQty
     * @param float      $newQty
     * @param float|null $oldRate Null if rate was not changed in this call.
     * @param float|null $newRate Null if rate was not changed in this call.
     * @param float      $cartSubtotal Cart subtotal after the update.
     */
    public function cartItemUpdated(
        int $userId, int $customerId, int $branchId,
        int $productId,
        float $oldQty, float $newQty,
        ?float $oldRate, ?float $newRate,
        float $cartSubtotal
    ): void {
        $this->log($userId, 'cart_item_updated', $branchId, [
            'customer_id'    => $customerId,
            'product_id'     => $productId,
            'old_qty'        => round($oldQty, 4),
            'new_qty'        => round($newQty, 4),
            'old_rate'       => $oldRate !== null ? round($oldRate, 4) : null,
            'new_rate'       => $newRate !== null ? round($newRate, 4) : null,
            'cart_subtotal'  => round($cartSubtotal, 2),
        ]);
    }

    /**
     * R4: Log a cart item removal (product removed from cart).
     *
     * Captures the qty + rate of the removed line so the foregone
     * revenue is auditable.
     *
     * @param int        $userId
     * @param int        $customerId
     * @param int        $branchId
     * @param int        $productId
     * @param float      $removedQty   Qty of the line that was removed.
     * @param float      $removedRate  Rate of the line that was removed.
     * @param int        $cartItemCount Total distinct products remaining after the remove.
     * @param float      $cartSubtotal  Cart subtotal after the remove.
     */
    public function cartItemRemoved(
        int $userId, int $customerId, int $branchId,
        int $productId, float $removedQty, float $removedRate,
        int $cartItemCount, float $cartSubtotal
    ): void {
        $this->log($userId, 'cart_item_removed', $branchId, [
            'customer_id'     => $customerId,
            'product_id'      => $productId,
            'removed_qty'     => round($removedQty, 4),
            'removed_rate'    => round($removedRate, 4),
            'removed_total'   => round($removedQty * $removedRate, 2),
            'cart_item_count' => $cartItemCount,
            'cart_subtotal'   => round($cartSubtotal, 2),
        ]);
    }

    /**
     * R4: Log a cart clear (all items removed at once).
     *
     * Captures the count + value of what was discarded so a suspicious
     * bulk-clear (e.g. right before finalizing with a different cart)
     * can be flagged.
     *
     * @param int   $userId
     * @param int   $customerId
     * @param int   $branchId
     * @param int   $itemsClearedCount Number of distinct product lines cleared.
     * @param float $itemsClearedValue Sum of (qty × rate) for the cleared lines.
     */
    public function cartCleared(
        int $userId, int $customerId, int $branchId,
        int $itemsClearedCount, float $itemsClearedValue
    ): void {
        $this->log($userId, 'cart_cleared', $branchId, [
            'customer_id'        => $customerId,
            'items_cleared_count'=> $itemsClearedCount,
            'items_cleared_value'=> round($itemsClearedValue, 2),
        ]);
    }

    /**
     * Query recent sales audit events for display.
     * Returns events matching sales-related action prefixes.
     *
     * @param int $limit
     * @param int|null $branchId
     * @return \Illuminate\Support\Collection
     */
    public function recentSalesEvents(int $limit = 300, ?int $branchId = null)
    {
        $actions = [
            'sale_created', 'sale_updated', 'sale_cancelled', 'sale_call_a_day',
            'credit_limit_override',
            'payment_received', 'payment_reversed',
            'payment_discount', 'payment_write_off', 'payment_refund',
            'return_created', 'return_confirmed', 'return_reversed',
            'godown_prepared', 'challan_issued', 'challan_reversed',
            'stale_drafts_cancelled',
            // R4: cart mutation events
            'cart_item_added', 'cart_item_updated',
            'cart_item_removed', 'cart_cleared',
        ];

        $query = DB::table('user_audit_log')
            ->whereIn('action', $actions)
            ->orderByDesc('id')
            ->limit($limit);

        if ($branchId !== null && $branchId > 0) {
            $query->where('branch_id', $branchId);
        }

        return $query->get();
    }

    /**
     * Internal: delegate to UserAuditLogger (dual-write: DB + file).
     */
    private function log(int $userId, string $action, int $branchId, array $details): void
    {
        // Override the branch_id from session if not already in details.
        $details['branch_id'] = $branchId;

        UserAuditLogger::log($userId, $action, null, $details);
    }
}

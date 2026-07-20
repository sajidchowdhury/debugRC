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
 *
 * Legacy equivalent: SalesController::auditLog:39-42 wrote to user_audit_log
 * via $this->userAudit->log(). Events: sale_created, sale_updated, sale_deleted,
 * sale_call_a_day, sale_credit_limit_override, payment_received, payment_reversed,
 * return_confirmed, return_reversed + godown_prepared, challan_completed,
 * challan_reversed.
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

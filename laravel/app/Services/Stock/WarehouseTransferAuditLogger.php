<?php

namespace App\Services\Stock;

use App\Services\Auth\UserAuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Warehouse Transfer Audit Logger — Phase 4.
 *
 * Logs every state transition in the warehouse transfer lifecycle to the
 * user_audit_log table (via UserAuditLogger dual-write: DB + file).
 *
 * This is the equivalent of the legacy's WarehouseTransferAuditModel logging,
 * but adapted for the Laravel two-phase flow (draft → confirm → cancel).
 *
 * The WarehouseTransferService uses DB::table() which bypasses Eloquent events,
 * so explicit audit logging is required. The AuditableMasterData trait on the
 * WarehouseTransfer model only catches Eloquent-model-level changes (which
 * don't happen when the service uses DB::table()).
 *
 * Events logged:
 *   1. transfer_created   — draft transfer created
 *   2. transfer_confirmed — stock moved (source OUT + dest IN)
 *   3. transfer_cancelled — cancelled (with reversal info if confirmed)
 *
 * All methods are safe to call inside a DB transaction (the audit insert
 * joins the same transaction). If the transaction rolls back, the audit
 * row is also rolled back (no orphan audit entries).
 */
class WarehouseTransferAuditLogger
{
    /**
     * Log a draft transfer creation.
     */
    public function transferCreated(
        int $userId,
        int $transferId,
        string $transferCode,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $branchId,
        int $itemsCount,
        float $totalAmount
    ): void {
        $this->log($userId, 'transfer_created', $branchId, [
            'transfer_id'      => $transferId,
            'transfer_code'    => $transferCode,
            'from_warehouse_id' => $fromWarehouseId,
            'to_warehouse_id'   => $toWarehouseId,
            'items_count'      => $itemsCount,
            'total_amount'     => round($totalAmount, 2),
            'status'           => 'draft',
        ]);
    }

    /**
     * Log a transfer confirmation (stock moved).
     */
    public function transferConfirmed(
        int $userId,
        int $transferId,
        string $transferCode,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $branchId,
        int $itemsCount,
        float $totalAmount
    ): void {
        $this->log($userId, 'transfer_confirmed', $branchId, [
            'transfer_id'      => $transferId,
            'transfer_code'    => $transferCode,
            'from_warehouse_id' => $fromWarehouseId,
            'to_warehouse_id'   => $toWarehouseId,
            'items_count'      => $itemsCount,
            'total_amount'     => round($totalAmount, 2),
            'status'           => 'confirmed',
        ]);
    }

    /**
     * Log a transfer cancellation.
     */
    public function transferCancelled(
        int $userId,
        int $transferId,
        string $transferCode,
        int $branchId,
        string $previousStatus,
        bool $wasReversed,
        string $reason
    ): void {
        $this->log($userId, 'transfer_cancelled', $branchId, [
            'transfer_id'    => $transferId,
            'transfer_code'  => $transferCode,
            'previous_status' => $previousStatus,
            'was_reversed'   => $wasReversed,
            'reason'         => $reason,
            'status'         => 'cancelled',
        ]);
    }

    /**
     * Query recent warehouse transfer audit events for display.
     *
     * @param int $limit
     * @param int|null $branchId
     * @return \Illuminate\Support\Collection
     */
    public function recentTransferEvents(int $limit = 300, ?int $branchId = null)
    {
        $actions = [
            'transfer_created',
            'transfer_confirmed',
            'transfer_cancelled',
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
     * Query audit events for a single warehouse transfer.
     *
     * G-284 (G22) FINANCE-CONSOLIDATION-1: replaces the prior
     * recentTransferEvents(100, ...)->filter() pattern in
     * WarehouseTransferController::audit that loaded the most-recent 100
     * transfer events and filtered in PHP by parsing JSON details.transfer_id.
     * The old pattern silently dropped history for old transfers whose events
     * had been pushed out of the most-recent-100 window — the per-transfer
     * audit page would show ZERO history for any transfer older than ~33
     * transfers ago (3 events per transfer × 100 = ~33 transfers).
     *
     * The new method uses a native JSONB containment filter
     * (WHERE details->>'transfer_id' = ?) which is supported on PG 12+.
     * The existing idx_ual_action index on `action` pre-filters to the 3
     * transfer_* actions; the JSONB filter then narrows to the single
     * transfer. Returns ALL events for the transfer (no 100-row cap).
     *
     * @param int $transferId
     * @param int|null $branchId
     * @return \Illuminate\Support\Collection
     */
    public function transferEvents(int $transferId, ?int $branchId = null)
    {
        $actions = [
            'transfer_created',
            'transfer_confirmed',
            'transfer_cancelled',
        ];

        $query = DB::table('user_audit_log')
            ->whereIn('action', $actions)
            ->where('details->>transfer_id', '=', (string) $transferId)
            ->orderByDesc('id');

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
        $details['branch_id'] = $branchId;

        UserAuditLogger::log($userId, $action, null, $details);
    }
}

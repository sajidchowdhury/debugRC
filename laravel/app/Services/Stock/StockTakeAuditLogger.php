<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;

/**
 * Stock Take Audit Logger — Phase 2 (Stock Take plan).
 *
 * Writes exactly one `stock_take_audit_log` row for every state transition
 * in the stock-take lifecycle. The write MUST happen inside the same
 * DB::transaction as the data change it documents, so that a rolled-back
 * post also rolls back its audit row (the plan's acceptance criterion:
 * "Rolling back a post … also rolls back the audit log row").
 *
 * The logger is deliberately a thin, side-effect-free writer:
 *   - It does NOT throw on validation errors (the service has already done
 *     its own validation). If the action payload is malformed, we still
 *     log what we can — the audit row is the forensic record, not a gate.
 *   - It does NOT start its own transaction (the caller's transaction is
 *     the unit of work).
 *   - It resolves actor_id from auth()->id() when not explicitly passed,
 *     so callers in service code (which has no Request context) do not
 *     need to thread the user id through every call.
 *
 * Usage (inside a DB::transaction):
 *   $this->auditLogger->log($session, 'post', $fromStatus, 'posted', [
 *       'variance_lines' => $varianceItems->count(),
 *       'total_gain'     => $totalGain,
 *       'total_loss'     => $totalLoss,
 *       'journal_entry_id' => $journalEntryId,
 *   ]);
 *
 * The branch_id is read from the session model so RLS on the audit table
 * can scope reads by branch without a join (denormalized at insert time).
 */
class StockTakeAuditLogger
{
    /**
     * Write one audit-log row. Must be called inside the caller's
     * DB::transaction so the row commits/rolls back with the data change.
     *
     * @param object|array $session  A StockTakeSession model or an array with
     *     at least {id, branch_id, status} (the service sometimes passes a
     *     plain stdClass fetched via lockForUpdate before the model is
     *     reloaded — we accept either to avoid a forced reload mid-transaction).
     * @param string $action  One of the CHECK-constrained values:
     *     create|setup|save_count|mark_complete|submit|approve|reject|post|
     *     reverse|re_open|delete|cancel
     * @param string|null $fromStatus  Status before the transition (null on create).
     * @param string|null $toStatus  Status after the transition.
     * @param array $payload  Action-specific snapshot (counts, variance
     *     summary, journal_entry_id, etc.). Stored as jsonb.
     * @param int|null $actorId  Override for the acting user id; defaults to
     *     auth()->id() so callers in service code do not need to thread it.
     * @param int|null $warehouseId  For warehouse-scoped actions
     *     (setup, save_count, mark_complete).
     * @param int|null $itemId  For item-scoped actions (reserved; not yet used).
     */
    public function log(
        $session,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        array $payload = [],
        ?int $actorId = null,
        ?int $warehouseId = null,
        ?int $itemId = null
    ): void {
        // Accept either a model or a bare object/array — the service
        // sometimes holds a stdClass from a lockForUpdate fetch.
        $sessionId  = is_object($session) ? ($session->id ?? null) : ($session['id'] ?? null);
        $branchId   = is_object($session) ? ($session->branch_id ?? null) : ($session['branch_id'] ?? null);

        if (!$sessionId || !$branchId) {
            // Nothing we can log without a session identity. This is a
            // no-op rather than a throw so a logging failure can never
            // break a stock-take transition (the data change is the
            // source of truth; the audit row is the forensic record).
            return;
        }

        $actorId = $actorId ?? (function_exists('auth') ? auth()->id() : null);

        DB::table('stock_take_audit_log')->insert([
            'stock_take_session_id'   => (int) $sessionId,
            'stock_take_warehouse_id' => $warehouseId,
            'stock_take_item_id'      => $itemId,
            'action'                  => $action,
            'actor_id'                => $actorId,
            'from_status'             => $fromStatus,
            'to_status'               => $toStatus,
            'payload'                 => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'branch_id'               => (int) $branchId,
            'created_at'              => now(),
        ]);
    }
}

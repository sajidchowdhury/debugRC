<?php

namespace App\Services\Stock;

use App\Models\StockAdjustment;
use Illuminate\Support\Facades\DB;

/**
 * Stock Adjustment Audit Logger — Phase 4 (Stock Adjustment plan).
 *
 * Writes exactly one `stock_adjustment_audit_log` row for every state
 * transition in the stock-adjustment lifecycle (create / submit / approve /
 * reject / confirm / cancel). The write MUST happen inside the same
 * DB::transaction as the data change it documents, so that a rolled-back
 * confirm also rolls back its audit row (the acceptance criterion copied
 * from the sibling Stock Take Phase 2 audit logger).
 *
 * This replaces the dead `AuditableMasterData` trait, which never fired
 * because `StockAdjustmentService` writes header/items via `DB::table()`
 * (bypassing the Eloquent model events the trait hooks into). The trait is
 * left on the model for safety (removing it could affect other code paths)
 * but is documented as superseded — this logger is the source of truth.
 *
 * The logger is deliberately a thin, side-effect-free writer:
 *   - It does NOT throw on validation errors (the service has already done
 *     its own validation). If the action payload is malformed, we still
 *     log what we can — the audit row is the forensic record, not a gate.
 *   - It does NOT start its own transaction (the caller's transaction is
 *     the unit of work).
 *   - It resolves actor_id / actor_role / ip / user_agent from the request
 *     context when not explicitly passed, so callers in service code (which
 *     has no Request binding) do not need to thread them through every call.
 *
 * Usage (inside a DB::transaction):
 *   $this->audit->log($adjustment, 'confirm', [
 *       'confirm_reason'   => $confirmReason,
 *       'journal_entry_id' => $journalEntryId,
 *       'total_amount'     => (float) $adjustment->total_amount,
 *       'items_count'      => $adjustment->items->count(),
 *       'reference_type'   => $adjustment->ledgerReferenceType(),
 *   ]);
 *
 * The branch_id is read from the adjustment model so RLS on the audit table
 * can scope reads by branch without a join (denormalized at insert time).
 */
class StockAdjustmentAuditLogger
{
    /**
     * Write one audit-log row. Must be called inside the caller's
     * DB::transaction so the row commits/rolls back with the data change.
     *
     * @param StockAdjustment $adjustment  The adjustment being acted on.
     *     branch_id is read from this model so RLS can scope the audit row
     *     without a join.
     * @param string $action  One of the CHECK-constrained values:
     *     create|update|submit|approve|reject|confirm|cancel|reverse|
     *     force_confirm|reopen|delete|export|print
     * @param array $payload  Action-specific snapshot (total_amount,
     *     items_count, reason, journal_entry_id, auto_approved flag, etc.).
     *     Stored as jsonb.
     * @param int|null $actorId  Override for the acting user id; defaults to
     *     auth()->id() so callers in service code do not need to thread it.
     * @return void
     */
    public function log(
        StockAdjustment $adjustment,
        string $action,
        array $payload = [],
        ?int $actorId = null
    ): void {
        $adjustmentId = $adjustment->id ?? null;
        $branchId     = $adjustment->branch_id ?? null;

        if (!$adjustmentId || !$branchId) {
            // Nothing we can log without an adjustment identity. This is a
            // no-op rather than a throw so a logging failure can never break
            // a stock-adjustment transition (the data change is the source of
            // truth; the audit row is the forensic record). Mirrors the
            // StockTakeAuditLogger's defensive return.
            return;
        }

        $actorId = $actorId ?? (function_exists('auth') ? auth()->id() : null);

        // Snapshot the actor's role at action time. Roles can change later
        // (a manager is demoted to accountant), but the audit row must keep
        // the role the user held when they performed the action — that is the
        // whole point of an audit trail. Resolved via User::getRole() which
        // reads from the employee relationship.
        $actorRole = null;
        $user      = function_exists('auth') ? auth()->user() : null;
        if ($user !== null && method_exists($user, 'getRole')) {
            $actorRole = $user->getRole();
        }

        // Request context — only available in an HTTP request. When the
        // logger is called from a console command / queue job / tinker there
        // is no active request, so request() returns null and we store null.
        $request    = function_exists('request') ? request() : null;
        $ipAddress  = $request?->ip();
        $userAgent  = $request?->userAgent();
        // Clamp user_agent to the column's varchar(255) ceiling so a
        // pathologically long UA never blows the insert.
        if ($userAgent !== null && strlen($userAgent) > 255) {
            $userAgent = substr($userAgent, 0, 255);
        }

        DB::table('stock_adjustment_audit_log')->insert([
            'stock_adjustment_id' => (int) $adjustmentId,
            'branch_id'           => (int) $branchId,
            'action'              => $action,
            'actor_id'            => $actorId,
            'actor_role'          => $actorRole,
            'payload'             => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip_address'          => $ipAddress,
            'user_agent'          => $userAgent,
            'created_at'          => now(),
        ]);
    }
}

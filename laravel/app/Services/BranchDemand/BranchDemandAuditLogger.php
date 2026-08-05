<?php

namespace App\Services\BranchDemand;

use App\Models\BranchDemand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Branch Demand Audit Logger — Phase 8 (Anti-Gaming & Accountability Controls).
 *
 * Writes exactly one `branch_demand_audit_log` row for every state transition
 * in the Branch Demand lifecycle. The write MUST happen inside the same
 * DB::transaction as the data change it documents, so that a rolled-back
 * operation also rolls back its audit row.
 *
 * This replaces the dead `AuditableMasterData` trait on the BranchDemand model,
 * which never fired because `BranchDemandService` writes via `DB::table()`
 * (bypassing Eloquent model events the trait hooks into). The trait is left on
 * the model for safety but is documented as superseded — this logger is the
 * source of truth for the demand audit trail.
 *
 * Design principles (mirrors StockAdjustmentAuditLogger):
 *   - Thin, side-effect-free writer
 *   - Does NOT throw on validation errors (audit is forensic, not a gate)
 *   - Does NOT start its own transaction (caller's transaction is the unit of work)
 *   - Resolves actor_id / actor_role / ip / user_agent from request context
 *     when not explicitly passed, so callers in service code do not need to
 *     thread them through every call
 *
 * Usage (inside a DB::transaction):
 *   $this->auditLogger->log($demandId, 'create', $fromBranchId, [
 *       'demand_code'   => $demandCode,
 *       'to_branch_id'  => $toBranchId,
 *       'items_count'   => count($items),
 *   ]);
 *
 * The branch_id is denormalized from the demand so RLS on the audit table
 * can scope reads by branch without a join.
 *
 * Action enum (CHECK-constrained in the migration):
 *   create|send|confirm_receipt|reverse|delete|reject|
 *   reprice|settle|settlement_reverse|export|print
 */
class BranchDemandAuditLogger
{
    /**
     * Write one audit-log row. Must be called inside the caller's
     * DB::transaction so the row commits/rolls back with the data change.
     *
     * @param int    $demandId  The demand being acted on.
     * @param string $action    One of the CHECK-constrained values:
     *     create|send|confirm_receipt|reverse|delete|reject|
     *     reprice|settle|settlement_reverse|export|print
     * @param int|null $branchId  The branch_id for RLS. Set to the
     *     from_branch_id (requester) since the requester is the primary
     *     stakeholder. Pass null if the demand hasn't been created yet.
     * @param array $payload  Action-specific snapshot (demand_code,
     *     total_value, items_count, reason, journal_entry_id, etc.).
     *     Stored as jsonb.
     * @param int|null $actorId  Override for the acting user id; defaults to
     *     auth()->id() so callers in service code do not need to thread it.
     * @return void
     */
    public function log(
        int $demandId,
        string $action,
        ?int $branchId,
        array $payload = [],
        ?int $actorId = null
    ): void {
        if (!$demandId) {
            // Nothing we can log without a demand identity. This is a
            // no-op rather than a throw so a logging failure can never break
            // a demand transition (the data change is the source of truth;
            // the audit row is the forensic record).
            return;
        }

        $actorId = $actorId ?? (function_exists('auth') ? auth()->id() : null);

        // Snapshot the actor's role at action time. Roles can change later
        // (a manager is demoted to accountant), but the audit row must keep
        // the role the user held when they performed the action.
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
        // Clamp user_agent to the column's varchar(255) ceiling
        if ($userAgent !== null && strlen($userAgent) > 255) {
            $userAgent = substr($userAgent, 0, 255);
        }

        // FINANCE-3 (G-336): wrap the audit-row INSERT in try/catch.
        // The audit row is forensic, NOT a gate — a failure here MUST NOT
        // roll back the caller's DB::transaction. The design principle
        // documented in the class doc-block ("audit is forensic, not a
        // gate") is now enforced by construction: if the INSERT fails
        // (CHECK constraint violation on `action` enum, RLS policy
        // violation, connection drop, etc.), we Log::warning with the
        // full context and return without re-throwing. The parent
        // transaction commits the data change; the missing audit row is
        // surfaced for follow-up via the log.
        try {
            DB::table('branch_demand_audit_log')->insert([
                'branch_demand_id' => $demandId,
                'branch_id'        => $branchId,
                'action'           => $action,
                'actor_id'         => $actorId,
                'actor_role'       => $actorRole,
                'payload'          => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip_address'       => $ipAddress,
                'user_agent'       => $userAgent,
                'created_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            // Forensic-only: do NOT re-throw. Log with full context so the
            // missing audit row can be reconciled manually.
            Log::warning('BranchDemandAuditLogger: audit-row insert failed (forensic-only, parent txn continues)', [
                'demand_id'   => $demandId,
                'branch_id'   => $branchId,
                'action'      => $action,
                'actor_id'    => $actorId,
                'actor_role'  => $actorRole,
                'payload'     => $payload,
                'error_class' => get_class($e),
                'error_msg'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the audit trail for a specific demand.
     *
     * Returns all audit log entries for the demand, ordered chronologically.
     *
     * @param int $demandId
     * @return \Illuminate\Support\Collection
     */
    public function getTrailForDemand(int $demandId)
    {
        return DB::table('branch_demand_audit_log')
            ->where('branch_demand_id', $demandId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Get the audit trail for a branch within a date range.
     *
     * @param int      $branchId
     * @param string   $dateFrom  Y-m-d
     * @param string   $dateTo    Y-m-d
     * @param array    $actions   Optional filter by action types
     * @return \Illuminate\Support\Collection
     */
    public function getTrailForBranch(int $branchId, string $dateFrom, string $dateTo, array $actions = [])
    {
        $query = DB::table('branch_demand_audit_log')
            ->where('branch_id', $branchId)
            ->where('created_at', '>=', $dateFrom . ' 00:00:00')
            ->where('created_at', '<=', $dateTo . ' 23:59:59');

        if (!empty($actions)) {
            $query->whereIn('action', $actions);
        }

        return $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Get critical actions (reverse, delete, reprice, settlement_reverse)
     * for a branch within a date range.
     *
     * @param int    $branchId
     * @param string $dateFrom  Y-m-d
     * @param string $dateTo    Y-m-d
     * @return \Illuminate\Support\Collection
     */
    public function getCriticalActions(int $branchId, string $dateFrom, string $dateTo)
    {
        return $this->getTrailForBranch($branchId, $dateFrom, $dateTo, [
            'reverse', 'delete', 'reprice', 'settlement_reverse',
        ]);
    }
}

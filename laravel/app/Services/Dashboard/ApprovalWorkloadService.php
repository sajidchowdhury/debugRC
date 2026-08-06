<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Approval Workload Service — Dashboard Phase 5.
 *
 * Extracted from UserPerformanceDashboardController (G-144 / dashboards.md G9,
 * HIGH-WAVE-3). Contains the manager/admin approval workload metric (pending
 * + approved counts for stock_adjustments + damage_invoices).
 *
 * Attribution: "pending my approval" is branch-wide (not user-attributed);
 * "approved by me" uses `approved_by = $userId`.
 *
 * Uses the EXISTING approved_by / submitted_by columns on stock_adjustments
 * (migration 2025_07_29_000001) and damage_invoices (migration
 * 2026_01_05_000001). No new migrations needed.
 */
class ApprovalWorkloadService
{
    /**
     * Approval workload for manager / admin / superadmin roles.
     *
     * Pulls counts of:
     *   - Stock adjustments submitted but not yet approved (status='submitted')
     *     → "pending my approval" — these are branch-wide, not user-attributed.
     *   - Stock adjustments this user has approved in the period
     *     (approved_by = $userId, approved_at within range).
     *   - Damage invoices submitted but not yet approved (status='submitted')
     *     → "pending my approval" — same logic.
     *   - Damage invoices this user has approved in the period.
     *   - Total pending value = SUM(total_amount) of pending stock adjustments.
     *
     * Uses the EXISTING approved_by / submitted_by columns on
     * stock_adjustments (migration 2025_07_29_000001) and damage_invoices
     * (migration 2026_01_05_000001). No new migrations needed.
     *
     * Note on attribution: "pending my approval" is inherently branch-wide
     * (any manager in the branch can approve), so we don't filter by user.
     * The "approved by me" count IS user-attributed via approved_by.
     *
     * @return array{
     *   adjustments_pending_my_approval: int,
     *   adjustments_approved_by_me: int,
     *   damages_pending_my_approval: int,
     *   damages_approved_by_me: int,
     *   total_pending_value: float
     * }
     */
    public function getApprovalWorkload(int $userId, int $employeeId, array $range): array
    {
        $zero = [
            'adjustments_pending_my_approval' => 0,
            'adjustments_approved_by_me'      => 0,
            'damages_pending_my_approval'     => 0,
            'damages_approved_by_me'          => 0,
            'total_pending_value'             => 0.0,
        ];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            // ── 1. Stock adjustments pending approval (branch-wide)
            //    status='submitted' means submitted-but-not-yet-approved.
            //    RLS auto-scopes to the user's branch.
            $saPending = DB::table('stock_adjustments')
                ->where('status', 'submitted')
                ->where('is_reversed', false)
                ->whereNull('deleted_at')
                ->selectRaw("
                    COUNT(*) AS cnt,
                    COALESCE(SUM(total_amount), 0) AS total_value
                ")
                ->first();

            // ── 2. Stock adjustments this user has approved in the period.
            //    approved_by references users.id (set in StockAdjustmentService::approve).
            //    approved_at is a timestamp — we filter by date range for the period.
            $saApproved = DB::table('stock_adjustments')
                ->where('approved_by', $userId)
                ->whereBetween('approved_at', [
                    $range['start'] . ' 00:00:00',
                    $range['end'] . ' 23:59:59',
                ])
                ->where('is_reversed', false)
                ->whereNull('deleted_at')
                ->count();

            // ── 3. Damage invoices pending approval (branch-wide)
            $dmgPending = DB::table('damage_invoices')
                ->where('status', 'submitted')
                ->where('is_reversed', false)
                ->count();

            // ── 4. Damage invoices this user has approved in the period
            $dmgApproved = DB::table('damage_invoices')
                ->where('approved_by', $userId)
                ->whereBetween('approved_at', [
                    $range['start'] . ' 00:00:00',
                    $range['end'] . ' 23:59:59',
                ])
                ->where('is_reversed', false)
                ->count();

            return [
                'adjustments_pending_my_approval' => (int) ($saPending->cnt ?? 0),
                'adjustments_approved_by_me'      => (int) $saApproved,
                'damages_pending_my_approval'     => (int) $dmgPending,
                'damages_approved_by_me'          => (int) $dmgApproved,
                'total_pending_value'             => (float) ($saPending->total_value ?? 0),
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 5 getApprovalWorkload failed: ' . $e->getMessage());
            return $zero;
        }
    }
}

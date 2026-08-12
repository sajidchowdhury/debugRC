<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Commission Metrics Service — Dashboard Phase 4 (commission subset).
 *
 * Extracted from UserPerformanceDashboardController (G-144 / dashboards.md G9,
 * HIGH-WAVE-3). Contains the commission summary metric. Uses
 * `salesman_id = $employeeId` (employees.id) — NOT created_by — because
 * commission is attributed to the salesman's employee record, not the
 * invoice creator.
 */
class CommissionMetricsService
{
    /**
     * Commission summary for the target employee (salesman).
     *
     * Pulls commission_entries grouped by status, plus the active rule +
     * target (if any). Returns net commission (sum of non-reversed entries),
     * status breakdown, attainment %, and the active rule metadata.
     *
     * No period filter on the status breakdown — commission_entries is a
     * ledger, not partitioned, so we filter by entry_date for the period
     * piece (the "calculated this period" amount) AND by status for the
     * lifetime piece (paid to date).
     *
     * @return array{
     *   net_commission: float, calculated: float, confirmed: float,
     *   paid: float, reversed: float, total_to_date: float,
     *   attainment_pct: float, target_amount: float, sales_to_date: float,
     *   has_rule: bool, rule_type: string|null, rate: float|null,
     *   period_label: string
     * }
     */
    public function getCommissionSummary(int $employeeId, array $range): array
    {
        $zero = [
            'net_commission'  => 0.0,
            'calculated'      => 0.0,
            'confirmed'       => 0.0,
            'paid'            => 0.0,
            'reversed'        => 0.0,
            'total_to_date'   => 0.0,
            'attainment_pct'  => 0.0,
            'target_amount'   => 0.0,
            'sales_to_date'   => 0.0,
            'has_rule'        => false,
            'rule_type'       => null,
            'rate'            => null,
            'period_label'    => '',
        ];
        if ($employeeId <= 0) {
            return $zero;
        }
        try {
            // ── 1. Lifetime status breakdown (paid to date, confirmed, etc.)
            //    Single query, FILTER clauses per status.
            $statusRow = DB::table('commission_entries')
                ->where('salesman_id', $employeeId)
                ->selectRaw("
                    COALESCE(SUM(commission_amount) FILTER (WHERE status = 'calculated' AND is_reversed = false), 0) AS calculated,
                    COALESCE(SUM(commission_amount) FILTER (WHERE status = 'confirmed'  AND is_reversed = false), 0) AS confirmed,
                    COALESCE(SUM(commission_amount) FILTER (WHERE status = 'paid'       AND is_reversed = false), 0) AS paid,
                    COALESCE(SUM(commission_amount) FILTER (WHERE is_reversed = true), 0) AS reversed,
                    COALESCE(SUM(commission_amount) FILTER (WHERE is_reversed = false), 0) AS total_to_date
                ")
                ->first();

            // ── 2. Period commission (this period only, by entry_date)
            $periodRow = DB::table('commission_entries')
                ->where('salesman_id', $employeeId)
                ->whereBetween('entry_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->selectRaw("COALESCE(SUM(commission_amount), 0) AS period_total")
                ->first();
            $periodNet = (float) ($periodRow->period_total ?? 0);

            // ── 3. Active commission rule (one open-ended active rule per salesman,
            //    enforced by EXCLUDE constraint — so we can take ->first()).
            $rule = DB::table('commission_rules')
                ->where('salesman_id', $employeeId)
                ->where('is_active', true)
                ->whereNull('effective_to')
                ->orderByDesc('effective_from')
                ->first();

            $hasRule  = $rule !== null;
            $ruleType = $hasRule ? $rule->rule_type : null;
            $rate     = $hasRule ? (float) $rule->rate : null;

            // ── 4. Target (for 'target_bonus' rules) — monthly/quarterly/yearly.
            $targetAmount = 0.0;
            if ($hasRule && $ruleType === 'target_bonus') {
                $target = DB::table('commission_rule_targets')
                    ->where('commission_rule_id', $rule->id)
                    ->where('period', 'monthly')
                    ->first();
                $targetAmount = $target ? (float) $target->target_amount : 0.0;
            }

            // ── 5. Sales-to-date (this month) for attainment %.
            //    Uses salesman_id (the salesman's portfolio of invoices),
            //    not created_by. Filtered by this month for monthly target
            //    comparison.
            $monthStart = now()->startOfMonth()->toDateString();
            $monthEnd   = now()->endOfMonth()->toDateString();
            $salesRow = DB::table('sales_invoices')
                ->where('salesman_id', $employeeId)
                ->whereBetween('invoice_date', [$monthStart, $monthEnd])
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->selectRaw("COALESCE(SUM(total_amount), 0) AS month_sales")
                ->first();
            $salesToDate = (float) ($salesRow->month_sales ?? 0);

            $attainment = $targetAmount > 0
                ? round(min(150, ($salesToDate / $targetAmount) * 100), 1)
                : 0.0;

            return [
                'net_commission'  => $periodNet,
                'calculated'      => (float) ($statusRow->calculated ?? 0),
                'confirmed'       => (float) ($statusRow->confirmed ?? 0),
                'paid'            => (float) ($statusRow->paid ?? 0),
                'reversed'        => (float) ($statusRow->reversed ?? 0),
                'total_to_date'   => (float) ($statusRow->total_to_date ?? 0),
                'attainment_pct'  => $attainment,
                'target_amount'   => $targetAmount,
                'sales_to_date'   => $salesToDate,
                'has_rule'        => $hasRule,
                'rule_type'       => $ruleType,
                'rate'            => $rate,
                'period_label'    => now()->format('M Y'),
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 4 getCommissionSummary failed: ' . $e->getMessage());
            return $zero;
        }
    }
}

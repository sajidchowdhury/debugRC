<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Dashboard\Concerns\PeriodRangeHelpers;

/**
 * Stock Discipline & Accuracy Metrics Service — Dashboard Phase 4
 * (stock+accuracy subset).
 *
 * Extracted from UserPerformanceDashboardController (G-144 / dashboards.md G9,
 * HIGH-WAVE-3). Contains the stock discipline + accuracy metric methods.
 *
 * Attribution: stock discipline uses `created_by` (activity) +
 * `accountable_employee_id` (damage blame); accuracy uses `created_by`.
 *
 * Uses the PeriodRangeHelpers trait for parity with the other Phase 2/4
 * services — current methods do not call previousPeriodRange, but the
 * trait is composed so future additions (e.g., stock-discipline growth %)
 * can use it without a follow-up refactor.
 */
class StockDisciplineMetricsService
{
    use PeriodRangeHelpers;

    /**
     * Stock-discipline scorecard for the target user/employee.
     *
     * K-catalogue metrics:
     *   K1  adjustments_initiated   — COUNT(stock_adjustments) by created_by
     *   K2  adjustment_value        — SUM(total_amount) for 'decrease' adjustments
     *   K3  loss_adjustments        — subset of K1 with adjustment_type='decrease'
     *   K4  accountable_damages     — SUM(damage_invoice_items.qty*rate) where
     *                                 accountable_employee_id = $employeeId
     *                                 (K11 in plan; red highlight if > 0)
     *   K5  damage_recovery         — (not implemented; placeholder 0)
     *   K6  stock_take_variances    — COUNT(stock_adjustments with
     *                                 adjustment_category='reconciliation_variance')
     *   K7  transfers_initiated     — COUNT(warehouse_transfers) by created_by
     *
     * Partitioned-table safe: stock_adjustments and damage_invoices are
     * NOT partitioned in this schema, but we still date-filter for index
     * usage (adjustment_date / damage_date / transfer_date BETWEEN).
     *
     * @return array{
     *   adjustments_initiated: int, adjustment_value: float,
     *   loss_adjustments: int, accountable_damages: float,
     *   damage_recovery: float, stock_take_variances: int,
     *   transfers_initiated: int, accountable_damages_count: int
     * }
     */
    public function getStockDiscipline(int $userId, int $employeeId, array $range): array
    {
        $zero = [
            'adjustments_initiated'    => 0,
            'adjustment_value'         => 0.0,
            'loss_adjustments'         => 0,
            'accountable_damages'      => 0.0,
            'accountable_damages_count'=> 0,
            'damage_recovery'          => 0.0,
            'stock_take_variances'     => 0,
            'transfers_initiated'      => 0,
        ];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            // ── K1, K2, K3, K6: stock_adjustments aggregates in one query
            $saRow = DB::table('stock_adjustments')
                ->where('created_by', $userId)
                ->whereBetween('adjustment_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->selectRaw("
                    COUNT(*) AS total_cnt,
                    COUNT(*) FILTER (WHERE adjustment_type = 'decrease') AS loss_cnt,
                    COALESCE(SUM(total_amount) FILTER (WHERE adjustment_type = 'decrease'), 0) AS loss_value,
                    COUNT(*) FILTER (WHERE adjustment_category = 'reconciliation_variance') AS variance_cnt
                ")
                ->first();

            // ── K4, K11: accountable damages (damage_invoices where this
            //    employee is the accountable party). damage_invoices is NOT
            //    partitioned but we date-filter on damage_date.
            //    Value = SUM(dii.qty * dii.rate) joined.
            $dmgRow = DB::table('damage_invoices as di')
                ->join('damage_invoice_items as dii', 'dii.damage_invoice_id', '=', 'di.id')
                ->where('di.accountable_employee_id', $employeeId)
                ->whereBetween('di.damage_date', [$range['start'], $range['end']])
                ->where('di.is_reversed', false)
                ->whereNotNull('di.accountable_employee_id')
                ->selectRaw("
                    COUNT(DISTINCT di.id) AS dmg_count,
                    COALESCE(SUM(dii.qty * dii.rate), 0) AS dmg_value
                ")
                ->first();

            // ── K7: warehouse transfers initiated
            $wtRow = DB::table('warehouse_transfers')
                ->where('created_by', $userId)
                ->whereBetween('transfer_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->selectRaw("COUNT(*) AS transfer_cnt")
                ->first();

            return [
                'adjustments_initiated'    => (int) ($saRow->total_cnt ?? 0),
                'adjustment_value'         => (float) ($saRow->loss_value ?? 0),
                'loss_adjustments'         => (int) ($saRow->loss_cnt ?? 0),
                'accountable_damages'      => (float) ($dmgRow->dmg_value ?? 0),
                'accountable_damages_count'=> (int) ($dmgRow->dmg_count ?? 0),
                'damage_recovery'          => 0.0, // placeholder — recovery tracking is post-launch
                'stock_take_variances'     => (int) ($saRow->variance_cnt ?? 0),
                'transfers_initiated'      => (int) ($wtRow->transfer_cnt ?? 0),
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 4 getStockDiscipline failed: ' . $e->getMessage());
            return $zero;
        }
    }

    /**
     * Accuracy / error-rate scorecard for the target user.
     *
     * X-catalogue metrics (composite error rate X10):
     *   X1  reversed_invoices    — sales_invoices where is_reversed=true
     *   X2  cancelled_invoices   — sales_invoices where status='cancelled'
     *   X3  reversed_payments    — customer_payments where is_reversed=true
     *   X4  reversed_returns     — sales_returns where is_reversed=true
     *   X5  reversed_challans    — sales_challans where is_reversed=true
     *   X10 composite_error_rate = reversed+cancelled / total_attempts
     *
     * All counts are period-filtered on the table's primary date column
     * for partition pruning (sales_invoices is partitioned by invoice_date;
     * customer_payments by payment_date; sales_returns by return_date;
     * sales_challans by challan_date if present, else created_at).
     *
     * @return array{
     *   reversed_invoices: int, cancelled_invoices: int,
     *   reversed_payments: int, reversed_returns: int,
     *   reversed_challans: int, manual_journals: int,
     *   total_actions: int, composite_error_rate: float
     * }
     */
    public function getAccuracyKPIs(int $userId, array $range): array
    {
        $zero = [
            'reversed_invoices'   => 0,
            'cancelled_invoices'  => 0,
            'reversed_payments'   => 0,
            'reversed_returns'    => 0,
            'reversed_challans'   => 0,
            'manual_journals'     => 0,
            'total_actions'       => 0,
            'composite_error_rate'=> 0.0,
        ];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            // ── X1, X2: sales_invoices — reversed + cancelled in one query
            //    (sales_invoices is partitioned — invoice_date BETWEEN required)
            $siRow = DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->whereBetween('invoice_date', [$range['start'], $range['end']])
                ->whereNull('deleted_at')
                ->selectRaw("
                    COUNT(*) FILTER (WHERE is_reversed = true) AS reversed_cnt,
                    COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled_cnt,
                    COUNT(*) AS total_cnt
                ")
                ->first();

            // ── X3: customer_payments — reversed (period-filtered by payment_date)
            $cpRow = DB::table('customer_payments')
                ->where('created_by', $userId)
                ->whereBetween('payment_date', [$range['start'], $range['end']])
                ->selectRaw("
                    COUNT(*) FILTER (WHERE is_reversed = true) AS reversed_cnt,
                    COUNT(*) AS total_cnt
                ")
                ->first();

            // ── X4: sales_returns — reversed
            $srRow = DB::table('sales_returns')
                ->where('created_by', $userId)
                ->whereBetween('return_date', [$range['start'], $range['end']])
                ->selectRaw("
                    COUNT(*) FILTER (WHERE is_reversed = true) AS reversed_cnt,
                    COUNT(*) AS total_cnt
                ")
                ->first();

            // ── X5: sales_challans — reversed (date filter via created_at)
            $scRow = DB::table('sales_challans')
                ->where('created_by', $userId)
                ->whereBetween('created_at', [$range['start'] . ' 00:00:00', $range['end'] . ' 23:59:59'])
                ->selectRaw("
                    COUNT(*) FILTER (WHERE is_reversed = true) AS reversed_cnt,
                    COUNT(*) AS total_cnt
                ")
                ->first();

            // ── Aggregate
            $revInv = (int) ($siRow->reversed_cnt ?? 0);
            $canInv = (int) ($siRow->cancelled_cnt ?? 0);
            $revPay = (int) ($cpRow->reversed_cnt ?? 0);
            $revRet = (int) ($srRow->reversed_cnt ?? 0);
            $revCha = (int) ($scRow->reversed_cnt ?? 0);

            $totalActions = (int) ($siRow->total_cnt ?? 0)
                          + (int) ($cpRow->total_cnt ?? 0)
                          + (int) ($srRow->total_cnt ?? 0)
                          + (int) ($scRow->total_cnt ?? 0);

            $errorCount = $revInv + $canInv + $revPay + $revRet + $revCha;
            $errorRate  = $totalActions > 0
                ? round(($errorCount / $totalActions) * 100, 2)
                : 0.0;

            return [
                'reversed_invoices'   => $revInv,
                'cancelled_invoices'  => $canInv,
                'reversed_payments'   => $revPay,
                'reversed_returns'    => $revRet,
                'reversed_challans'   => $revCha,
                'manual_journals'     => 0, // placeholder — manual_journal_entries table is post-launch
                'total_actions'       => $totalActions,
                'composite_error_rate'=> $errorRate,
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 4 getAccuracyKPIs failed: ' . $e->getMessage());
            return $zero;
        }
    }
}

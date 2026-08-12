<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Dashboard\Concerns\PeriodRangeHelpers;

/**
 * Collection & Returns Metrics Service — Dashboard Phase 2.
 *
 * Extracted from UserPerformanceDashboardController (G-144 / dashboards.md G9,
 * HIGH-WAVE-3). Contains the 4 collections/returns metric methods.
 *
 * Attribution: customer_payments + sales_returns filtered by `created_by = $userId`.
 * Receivable aging is point-in-time (no period filter).
 *
 * Phase 2 query conventions:
 *   - customer_payments (NOT partitioned):
 *       WHERE created_by = $userId
 *         AND payment_date BETWEEN $range   (period-bound metrics)
 *         AND is_reversed = false
 *         AND deleted_at IS NULL (column may not exist — guard via try/catch)
 *   - sales_returns (NOT partitioned):
 *       WHERE created_by = $userId
 *         AND return_date BETWEEN $range
 *         AND is_reversed = false
 *   - sales_invoices (partitioned):
 *       Period-bound: WHERE invoice_date BETWEEN $range
 *       Snapshot (outstanding/aging): no period filter — reflect current book.
 *
 * Collection Rate (C2) = Σ customer_payments.amount (created_by=?, period,
 *   transaction_type='receive') / NULLIF(Σ sales_invoices.total_amount
 *   (created_by=?, period), 0) * 100. NOT the company-wide ratio.
 *
 * Overdue (C4) uses an assumed 30-day term until G3 adds due_date.
 *   Label "Overdue (>30 days)" in the UI per the plan.
 *
 * Discount Allowed (C7) sums customer_payments.discount_amount for the
 *   user's payments in the period. (transaction_type='discount' rows are
 *   a separate write-off; C7 specifically tracks the inline discount
 *   field on receive-type payments.)
 */
class CollectionMetricsService
{
    use PeriodRangeHelpers;

    /**
     * Collection KPIs for the user: count, value, rate, outstanding,
     * overdue count + value, discount allowed.
     *
     * @param  bool  $hasTxnType  Result of checkCustomerPaymentsTransactionType()
     *                            — when true, we filter transaction_type='receive'
     *                            so that 'discount'/'write_off'/'payment' rows
     *                            are excluded from the collection volume.
     * @return array{
     *   collection_count:int, collection_value:float, collection_rate:float,
     *   outstanding:float, overdue_count:int, overdue_value:float,
     *   discount_allowed:float, prev_collection_value:float, growth_pct:float
     * }
     */
    public function getCollectionKPIs(int $userId, array $range, bool $hasTxnType): array
    {
        $zero = [
            'collection_count'     => 0,
            'collection_value'     => 0.0,
            'collection_rate'      => 0.0,
            'outstanding'          => 0.0,
            'overdue_count'        => 0,
            'overdue_value'        => 0.0,
            'discount_allowed'     => 0.0,
            'prev_collection_value'=> 0.0,
            'growth_pct'           => 0.0,
        ];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            // --- C1: collection count + value (transaction_type='receive' if column exists) ---
            $cpQuery = DB::table('customer_payments')
                ->where('created_by', $userId)
                ->whereBetween('payment_date', [$range['start'], $range['end']])
                ->where('is_reversed', false);
            if ($hasTxnType) {
                $cpQuery->where('transaction_type', 'receive');
            }
            $c1 = (clone $cpQuery)->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(amount),0) AS total')->first();
            $collectionCount = (int) ($c1->cnt ?? 0);
            $collectionValue = (float) ($c1->total ?? 0);

            // --- C7: discount allowed (sum of discount_amount on receive-type payments in period) ---
            $discountQuery = DB::table('customer_payments')
                ->where('created_by', $userId)
                ->whereBetween('payment_date', [$range['start'], $range['end']])
                ->where('is_reversed', false);
            if ($hasTxnType) {
                $discountQuery->where('transaction_type', 'receive');
            }
            $discountAllowed = (float) (clone $discountQuery)->sum('discount_amount');

            // --- C2: collection rate = collection / sales * 100 (same period, same user) ---
            $periodSales = (float) DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->whereBetween('invoice_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->sum('total_amount');
            $collectionRate = $periodSales > 0
                ? round(($collectionValue / $periodSales) * 100, 1)
                : 0.0;

            // --- C3: my outstanding (point-in-time snapshot — ALL the user's invoices) ---
            $outstanding = (float) DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->sum('due_amount');

            // --- C4: overdue (>30 days, assumed term per G3) ---
            //   Count + sum of due_amount on user's invoices older than 30 days
            //   with a positive balance.
            $overdueCutoff = now()->subDays(30)->toDateString();
            $overdueRow = DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->where('due_amount', '>', 0)
                ->where('invoice_date', '<', $overdueCutoff)
                ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(due_amount),0) AS total')
                ->first();
            $overdueCount = (int) ($overdueRow->cnt ?? 0);
            $overdueValue = (float) ($overdueRow->total ?? 0);

            // --- Growth vs previous period (for the collection value delta pill) ---
            $prevRange = $this->previousPeriodRange($range);
            $prevQuery = DB::table('customer_payments')
                ->where('created_by', $userId)
                ->whereBetween('payment_date', [$prevRange['start'], $prevRange['end']])
                ->where('is_reversed', false);
            if ($hasTxnType) {
                $prevQuery->where('transaction_type', 'receive');
            }
            $prevCollectionValue = (float) (clone $prevQuery)->sum('amount');
            $growthPct = $prevCollectionValue > 0
                ? round((($collectionValue - $prevCollectionValue) / $prevCollectionValue) * 100, 1)
                : 0.0;

            return [
                'collection_count'      => $collectionCount,
                'collection_value'      => $collectionValue,
                'collection_rate'       => $collectionRate,
                'outstanding'           => $outstanding,
                'overdue_count'         => $overdueCount,
                'overdue_value'         => $overdueValue,
                'discount_allowed'      => $discountAllowed,
                'prev_collection_value' => $prevCollectionValue,
                'growth_pct'            => $growthPct,
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 2 getCollectionKPIs failed: ' . $e->getMessage());
            return $zero;
        }
    }

    /**
     * Receivable aging snapshot — 5 buckets, scoped to the user's book.
     *
     * Same CASE expression as the legacy dashboard's getReceivableAging
     * (deleted in REPORTS-AUDIT-3 G-136 — see git history), but with
     * `AND created_by = $userId`. Point-in-time (no period filter).
     *
     * @return array{Current:float,1-30:float,31-60:float,61-90:float,90+:float,total:float}
     */
    public function getReceivableAging(int $userId): array
    {
        $empty = [
            'Current'  => 0.0,
            '1-30'     => 0.0,
            '31-60'    => 0.0,
            '61-90'    => 0.0,
            '90+'      => 0.0,
            'total'    => 0.0,
        ];
        if ($userId <= 0) {
            return $empty;
        }
        try {
            $rows = DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->where('due_amount', '>', 0)
                ->selectRaw("
                    CASE
                        WHEN invoice_date >= CURRENT_DATE THEN 'Current'
                        WHEN invoice_date >= CURRENT_DATE - INTERVAL '30 days' THEN '1-30'
                        WHEN invoice_date >= CURRENT_DATE - INTERVAL '60 days' THEN '31-60'
                        WHEN invoice_date >= CURRENT_DATE - INTERVAL '90 days' THEN '61-90'
                        ELSE '90+'
                    END AS bucket,
                    COALESCE(SUM(due_amount), 0) AS total_due
                ")
                ->groupBy('bucket')
                ->get();

            $buckets = $empty;
            foreach ($rows as $row) {
                if (array_key_exists($row->bucket, $buckets)) {
                    $buckets[$row->bucket] = (float) $row->total_due;
                }
            }
            $buckets['total'] = array_sum([
                $buckets['Current'], $buckets['1-30'], $buckets['31-60'],
                $buckets['61-90'], $buckets['90+'],
            ]);
            return $buckets;
        } catch (\Throwable $e) {
            Log::warning('Phase 2 getReceivableAging failed: ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * Return KPIs for the user: count, value, rate, top return reasons.
     *
     * R1 = COUNT(sales_returns WHERE created_by=? AND period AND is_reversed=false)
     * R2 = SUM(total_amount WHERE same + status='confirmed')
     * R3 = R2 / NULLIF(period sales, 0) * 100
     * R5 = GROUP BY reason, top 5 by count (fallback: status if reason is mostly null)
     *
     * @return array{
     *   return_count:int, return_value:float, return_rate:float,
     *   prev_return_value:float, growth_pct:float,
     *   top_reasons: array<int, array{reason:string,count:int,value:float}>
     * }
     */
    public function getReturnKPIs(int $userId, array $range): array
    {
        $zero = [
            'return_count'      => 0,
            'return_value'      => 0.0,
            'return_rate'       => 0.0,
            'prev_return_value' => 0.0,
            'growth_pct'        => 0.0,
            'top_reasons'       => [],
        ];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            // --- R1: return count (all non-reversed returns) ---
            $returnCount = (int) DB::table('sales_returns')
                ->where('created_by', $userId)
                ->whereBetween('return_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->count();

            // --- R2: return value (confirmed only) ---
            $returnValue = (float) DB::table('sales_returns')
                ->where('created_by', $userId)
                ->whereBetween('return_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->where('status', 'confirmed')
                ->sum('total_amount');

            // --- R3: return rate = return value / period sales * 100 ---
            $periodSales = (float) DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->whereBetween('invoice_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->sum('total_amount');
            $returnRate = $periodSales > 0
                ? round(($returnValue / $periodSales) * 100, 2)
                : 0.0;

            // --- Growth vs previous period ---
            $prevRange = $this->previousPeriodRange($range);
            $prevReturnValue = (float) DB::table('sales_returns')
                ->where('created_by', $userId)
                ->whereBetween('return_date', [$prevRange['start'], $prevRange['end']])
                ->where('is_reversed', false)
                ->where('status', 'confirmed')
                ->sum('total_amount');
            $growthPct = $prevReturnValue > 0
                ? round((($returnValue - $prevReturnValue) / $prevReturnValue) * 100, 1)
                : 0.0;

            // --- R5: top return reasons ---
            //   Group by COALESCE(reason, '(No reason given)') so nulls
            //   bucket together. If almost all rows have null reason, the
            //   chart will still show one big bucket — that's the "coaching
            //   signal" the plan calls out.
            $reasons = DB::table('sales_returns')
                ->where('created_by', $userId)
                ->whereBetween('return_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->groupBy('reason_bucket')
                ->orderByDesc('cnt')
                ->limit(5)
                ->selectRaw("
                    COALESCE(NULLIF(TRIM(reason), ''), '(No reason given)') AS reason_bucket,
                    COUNT(*) AS cnt,
                    COALESCE(SUM(total_amount), 0) AS total
                ")
                ->get();
            $topReasons = $reasons->map(fn ($r) => [
                'reason' => $r->reason_bucket,
                'count'  => (int) $r->cnt,
                'value'  => (float) $r->total,
            ])->values()->toArray();

            return [
                'return_count'      => $returnCount,
                'return_value'      => $returnValue,
                'return_rate'       => $returnRate,
                'prev_return_value' => $prevReturnValue,
                'growth_pct'        => $growthPct,
                'top_reasons'       => $topReasons,
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 2 getReturnKPIs failed: ' . $e->getMessage());
            return $zero;
        }
    }

    /**
     * Payment mode mix — C8: bank vs cash vs cheque vs mobile_banking.
     *
     * Returns counts + totals per payment_mode for the user's receive-type
     * payments in the period. Used for the donut chart + breakdown legend.
     *
     * @return array<int, array{mode:string,label:string,count:int,value:float,share:float}>
     */
    public function getPaymentModeMix(int $userId, array $range): array
    {
        if ($userId <= 0) {
            return [];
        }
        // Friendly labels + colors for the donut chart.
        $labels = [
            'cash'            => 'Cash',
            'bank'            => 'Bank Transfer',
            'cheque'          => 'Cheque',
            'mobile_banking'  => 'Mobile Banking',
            'adjustment'      => 'Adjustment',
        ];
        try {
            $rows = DB::table('customer_payments')
                ->where('created_by', $userId)
                ->whereBetween('payment_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->where('transaction_type', 'receive')
                ->groupBy('payment_mode')
                ->orderByDesc('total')
                ->selectRaw("
                    payment_mode,
                    COUNT(*) AS cnt,
                    COALESCE(SUM(amount), 0) AS total
                ")
                ->get();

            $grand = $rows->sum(fn ($r) => (float) $r->total);
            return $rows->map(function ($r) use ($labels, $grand) {
                $mode = $r->payment_mode;
                return [
                    'mode'   => $mode,
                    'label'  => $labels[$mode] ?? ucfirst(str_replace('_', ' ', $mode)),
                    'count'  => (int) $r->cnt,
                    'value'  => (float) $r->total,
                    'share'  => $grand > 0 ? round(((float) $r->total / $grand) * 100, 1) : 0.0,
                ];
            })->values()->toArray();
        } catch (\Throwable $e) {
            Log::warning('Phase 2 getPaymentModeMix failed: ' . $e->getMessage());
            return [];
        }
    }
}

<?php
namespace App\Services\Dashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Operational Efficiency & Productivity Metrics Service — Dashboard Phase 3.
 *
 * Extracted from UserPerformanceDashboardController (G-144 / dashboards.md G9,
 * HIGH-WAVE-3). Contains the 5 operational metric methods including the heavy
 * UNION ALL queries (getWorkPattern, getActivitySummary) that join 6 activity
 * tables.
 *
 * Attribution: activity tables filtered by `created_by = $userId`.
 * Notification engagement uses `notifications.user_id` (NOT created_by).
 *
 * Phase 3 query conventions:
 *   - Velocity (O1/O2/O3/O4): sales_invoices lifecycle timestamps.
 *     WHERE created_by = $userId AND invoice_date BETWEEN $range
 *     (partition-pruned) AND is_reversed=false AND status NOT IN
 *     ('cancelled','reversed','draft') AND deleted_at IS NULL.
 *     Each AVG only counts rows where the relevant timestamp is set
 *     (e.g., AVG(invoice→godown) only for is_godown_prepared=true).
 *   - Pipeline (O5/O6/O8): point-in-time snapshot — no period filter.
 *   - Work pattern (A4): hourly histogram UNIONed across 6 activity
 *     tables. Each table contributes (hour, count) rows for the user
 *     within $range; results summed per hour. The histogram always
 *     returns 24 bins (0..23), zero-filled for empty hours.
 *   - Activity summary (A1/A2/A3): cross-table active days = COUNT
 *     DISTINCT date across the same 6 tables UNIONed. Transactions
 *     per day = total activity count / active days. Peak day = day
 *     with the highest total activity.
 *   - Notification engagement (A7): notifications.user_id = $userId,
 *     read_rate = is_read=true / total * 100. NO period filter
 *     (notifications are already scoped to the user).
 *
 * Every method wrapped in try/catch with safe defaults so a missing
 * table or column doesn't break the dashboard.
 */
class OperationalMetricsService
{
    /**
     * Velocity KPIs: invoice→godown, godown→challan, invoice→challan avg hours,
     * same-day dispatch %.
     *
     * O1 = AVG(EXTRACT(EPOCH FROM (godown_prepared_at - created_at))/3600)
     *      WHERE is_godown_prepared=true AND godown_prepared_at IS NOT NULL
     * O2 = AVG(EXTRACT(EPOCH FROM (challan_issued_at - godown_prepared_at))/3600)
     *      WHERE is_challan_issued=true AND challan_issued_at IS NOT NULL
     *      AND godown_prepared_at IS NOT NULL
     * O3 = AVG(EXTRACT(EPOCH FROM (challan_issued_at - created_at))/3600)
     *      WHERE is_challan_issued=true AND challan_issued_at IS NOT NULL
     * O4 = COUNT(*) WHERE challan_issued_at::date = invoice_date
     *      / NULLIF(COUNT(*) WHERE is_challan_issued=true, 0) * 100
     *
     * @return array{
     *   avg_invoice_to_godown_hrs:?float, avg_godown_to_challan_hrs:?float,
     *   avg_invoice_to_challan_hrs:?float, same_day_dispatch_pct:float,
     *   dispatched_count:int, total_invoices:int
     * }
     */
    public function getVelocityKPIs(int $userId, array $range): array
    {
        $zero = [
            'avg_invoice_to_godown_hrs'    => null,
            'avg_godown_to_challan_hrs'    => null,
            'avg_invoice_to_challan_hrs'   => null,
            'same_day_dispatch_pct'        => 0.0,
            'dispatched_count'             => 0,
            'total_invoices'               => 0,
        ];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            // Single query — 4 aggregates in one pass for efficiency.
            $row = DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->whereBetween('invoice_date', [$range['start'], $range['end']])
                ->where('is_reversed', false)
                ->whereNotIn('status', ['cancelled', 'reversed', 'draft'])
                ->whereNull('deleted_at')
                ->selectRaw("
                    COUNT(*) AS total_invoices,
                    COUNT(*) FILTER (WHERE is_challan_issued = true) AS dispatched_count,
                    AVG(EXTRACT(EPOCH FROM (godown_prepared_at - created_at)) / 3600)
                        FILTER (WHERE is_godown_prepared = true AND godown_prepared_at IS NOT NULL) AS avg_i2g,
                    AVG(EXTRACT(EPOCH FROM (challan_issued_at - godown_prepared_at)) / 3600)
                        FILTER (WHERE is_challan_issued = true AND challan_issued_at IS NOT NULL AND godown_prepared_at IS NOT NULL) AS avg_g2c,
                    AVG(EXTRACT(EPOCH FROM (challan_issued_at - created_at)) / 3600)
                        FILTER (WHERE is_challan_issued = true AND challan_issued_at IS NOT NULL) AS avg_i2c,
                    COUNT(*) FILTER (WHERE is_challan_issued = true AND challan_issued_at::date = invoice_date) AS same_day
                ")
                ->first();

            $total = (int) ($row->total_invoices ?? 0);
            $dispatched = (int) ($row->dispatched_count ?? 0);
            $sameDay = (int) ($row->same_day ?? 0);
            $sameDayPct = $dispatched > 0 ? round(($sameDay / $dispatched) * 100, 1) : 0.0;

            return [
                'avg_invoice_to_godown_hrs'  => $row->avg_i2g !== null ? round((float) $row->avg_i2g, 1) : null,
                'avg_godown_to_challan_hrs'  => $row->avg_g2c !== null ? round((float) $row->avg_g2c, 1) : null,
                'avg_invoice_to_challan_hrs' => $row->avg_i2c !== null ? round((float) $row->avg_i2c, 1) : null,
                'same_day_dispatch_pct'      => $sameDayPct,
                'dispatched_count'           => $dispatched,
                'total_invoices'             => $total,
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 3 getVelocityKPIs failed: ' . $e->getMessage());
            return $zero;
        }
    }

    /**
     * Pipeline snapshot — point-in-time view of the user's WIP.
     *
     * O5 = stale drafts (status='draft' AND created_at < CURRENT_DATE - 7)
     * O6 = open pipeline value (status='confirmed' AND is_challan_issued=false)
     * O8 = parked sales (call_a_day=true) — "removed from today list"
     *
     * @return array{
     *   stale_draft_count:int, open_pipeline_value:float,
     *   parked_sales_count:int, draft_count:int, confirmed_pending_dispatch:int
     * }
     */
    public function getPipelineSnapshot(int $userId): array
    {
        $zero = [
            'stale_draft_count'            => 0,
            'open_pipeline_value'          => 0.0,
            'parked_sales_count'           => 0,
            'draft_count'                  => 0,
            'confirmed_pending_dispatch'   => 0,
        ];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            // Single query — 5 aggregates in one pass.
            $row = DB::table('sales_invoices')
                ->where('created_by', $userId)
                ->where('is_reversed', false)
                ->whereNull('deleted_at')
                ->selectRaw("
                    COUNT(*) FILTER (WHERE status = 'draft') AS draft_count,
                    COUNT(*) FILTER (WHERE status = 'draft' AND created_at < CURRENT_DATE - INTERVAL '7 days') AS stale_draft,
                    COUNT(*) FILTER (WHERE status = 'confirmed' AND is_challan_issued = false) AS confirmed_pending,
                    COALESCE(SUM(total_amount) FILTER (WHERE status = 'confirmed' AND is_challan_issued = false), 0) AS pipeline_value,
                    COUNT(*) FILTER (WHERE call_a_day = true) AS parked
                ")
                ->first();

            return [
                'stale_draft_count'          => (int) ($row->stale_draft ?? 0),
                'open_pipeline_value'        => (float) ($row->pipeline_value ?? 0),
                'parked_sales_count'         => (int) ($row->parked ?? 0),
                'draft_count'                => (int) ($row->draft_count ?? 0),
                'confirmed_pending_dispatch' => (int) ($row->confirmed_pending ?? 0),
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 3 getPipelineSnapshot failed: ' . $e->getMessage());
            return $zero;
        }
    }

    /**
     * Work-pattern histogram — 24-bin hour-of-day distribution.
     *
     * UNIONs activity across 6 tables (sales_invoices, customer_payments,
     * sales_returns, sales_challans, stock_adjustments, damage_invoices),
     * each filtered by created_by=$userId AND created_at BETWEEN $range.
     * Returns a 24-element array [{hour:0..23, count}], zero-filled.
     *
     * @return array<int, array{hour:int, count:int}>
     */
    public function getWorkPattern(int $userId, array $range): array
    {
        $empty = array_map(fn ($h) => ['hour' => $h, 'count' => 0], range(0, 23));
        if ($userId <= 0) {
            return $empty;
        }
        // Build the UNION ALL query as a raw SQL — Laravel's query builder
        // doesn't compose UNIONs ergonomically. Each arm pulls the hour-of-day
        // and a count bucketed by hour for one table. If a table is missing
        // or has no created_at/created_by columns, the arm's catch will skip
        // it (the surrounding try/catch falls back to all-zero).
        $startTs = $range['start'] . ' 00:00:00';
        $endTs   = $range['end'] . ' 23:59:59';

        $arms = [
            'sales_invoices',
            'customer_payments',
            'sales_returns',
            'sales_challans',
            'stock_adjustments',
            'damage_invoices',
        ];
        $unionParts = [];
        foreach ($arms as $tbl) {
            $unionParts[] = "SELECT EXTRACT(HOUR FROM created_at)::int AS hr, COUNT(*) AS cnt
                             FROM {$tbl}
                             WHERE created_by = ? AND created_at BETWEEN ? AND ?
                             GROUP BY hr";
        }
        $sql = "SELECT hr, SUM(cnt) AS total
                FROM (" . implode(' UNION ALL ', $unionParts) . ") AS u
                GROUP BY hr";

        try {
            // Bind userId + range params N times (one per arm).
            $bindings = [];
            foreach ($arms as $_) {
                $bindings[] = $userId;
                $bindings[] = $startTs;
                $bindings[] = $endTs;
            }
            $rows = DB::select($sql, $bindings);

            $byHour = [];
            foreach ($rows as $r) {
                $byHour[(int) $r->hr] = (int) $r->total;
            }
            $result = [];
            for ($h = 0; $h < 24; $h++) {
                $result[] = ['hour' => $h, 'count' => $byHour[$h] ?? 0];
            }
            return $result;
        } catch (\Throwable $e) {
            Log::warning('Phase 3 getWorkPattern failed: ' . $e->getMessage());
            return $empty;
        }
    }

    /**
     * Activity summary — transactions per day, cross-table active days,
     * peak day (the day with the most total activity by the user).
     *
     * A1 = total activity count / cross-table active days
     * A2 = COUNT(DISTINCT DATE(created_at)) UNIONed across the 6 tables
     * A3 = day with the most total activity (count + date)
     *
     * @return array{
     *   transactions_per_day:float, active_days_cross_table:int,
     *   total_activity:int, peak_day:?string, peak_day_count:int
     * }
     */
    public function getActivitySummary(int $userId, array $range): array
    {
        $zero = [
            'transactions_per_day'   => 0.0,
            'active_days_cross_table'=> 0,
            'total_activity'         => 0,
            'peak_day'               => null,
            'peak_day_count'         => 0,
        ];
        if ($userId <= 0) {
            return $zero;
        }
        $startTs = $range['start'] . ' 00:00:00';
        $endTs   = $range['end'] . ' 23:59:59';

        $arms = [
            'sales_invoices',
            'customer_payments',
            'sales_returns',
            'sales_challans',
            'stock_adjustments',
            'damage_invoices',
        ];
        // Arm 1: per-date activity counts (for peak day + total)
        $countParts = [];
        foreach ($arms as $tbl) {
            $countParts[] = "SELECT DATE(created_at) AS d, COUNT(*) AS cnt
                             FROM {$tbl}
                             WHERE created_by = ? AND created_at BETWEEN ? AND ?
                             GROUP BY DATE(created_at)";
        }
        $countSql = "SELECT d, SUM(cnt) AS total FROM (" .
                    implode(' UNION ALL ', $countParts) . ") AS u
                    GROUP BY d ORDER BY total DESC LIMIT 1";

        // Arm 2: distinct dates (cross-table active days)
        $distinctParts = [];
        foreach ($arms as $tbl) {
            $distinctParts[] = "SELECT DISTINCT DATE(created_at) AS d
                                FROM {$tbl}
                                WHERE created_by = ? AND created_at BETWEEN ? AND ?";
        }
        $distinctSql = "SELECT COUNT(*) AS cnt FROM (" .
                       implode(' UNION ALL ', $distinctParts) . ") AS u";

        try {
            $bindings1 = [];
            foreach ($arms as $_) {
                $bindings1[] = $userId;
                $bindings1[] = $startTs;
                $bindings1[] = $endTs;
            }
            $peakRow = DB::selectOne($countSql, $bindings1);

            $bindings2 = [];
            foreach ($arms as $_) {
                $bindings2[] = $userId;
                $bindings2[] = $startTs;
                $bindings2[] = $endTs;
            }
            $activeRow = DB::selectOne($distinctSql, $bindings2);

            // Total activity count (sum across all 6 tables in range)
            // — computed as a separate simple SUM query for clarity.
            $totalParts = [];
            foreach ($arms as $tbl) {
                $totalParts[] = "SELECT COUNT(*) AS cnt FROM {$tbl}
                                 WHERE created_by = ? AND created_at BETWEEN ? AND ?";
            }
            $totalSql = "SELECT SUM(cnt) AS total FROM (" .
                        implode(' UNION ALL ', $totalParts) . ") AS u";
            $bindings3 = [];
            foreach ($arms as $_) {
                $bindings3[] = $userId;
                $bindings3[] = $startTs;
                $bindings3[] = $endTs;
            }
            $totalRow = DB::selectOne($totalSql, $bindings3);

            $activeDays = (int) ($activeRow->cnt ?? 0);
            $totalActivity = (int) ($totalRow->total ?? 0);
            $txnsPerDay = $activeDays > 0 ? round($totalActivity / $activeDays, 1) : 0.0;

            return [
                'transactions_per_day'    => $txnsPerDay,
                'active_days_cross_table' => $activeDays,
                'total_activity'          => $totalActivity,
                'peak_day'                => isset($peakRow->d) ? (string) $peakRow->d : null,
                'peak_day_count'          => isset($peakRow->total) ? (int) $peakRow->total : 0,
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 3 getActivitySummary failed: ' . $e->getMessage());
            return $zero;
        }
    }

    /**
     * Notification engagement — read rate for the user's notifications.
     *
     * A7 = COUNT(*) WHERE is_read=true / NULLIF(COUNT(*), 0) * 100.
     * NO period filter — notifications are already scoped to user_id.
     * Also returns total + unread counts for the UI badge.
     *
     * @return array{read_rate:float, total:int, unread:int, read:int}
     */
    public function getNotificationEngagement(int $userId): array
    {
        $zero = ['read_rate' => 0.0, 'total' => 0, 'unread' => 0, 'read' => 0];
        if ($userId <= 0) {
            return $zero;
        }
        try {
            $row = DB::table('notifications')
                ->where('user_id', $userId)
                ->selectRaw("
                    COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE is_read = true) AS read_cnt,
                    COUNT(*) FILTER (WHERE is_read = false) AS unread_cnt
                ")
                ->first();
            $total = (int) ($row->total ?? 0);
            $read = (int) ($row->read_cnt ?? 0);
            $unread = (int) ($row->unread_cnt ?? 0);
            $rate = $total > 0 ? round(($read / $total) * 100, 1) : 0.0;
            return [
                'read_rate' => $rate,
                'total'     => $total,
                'unread'    => $unread,
                'read'      => $read,
            ];
        } catch (\Throwable $e) {
            Log::warning('Phase 3 getNotificationEngagement failed: ' . $e->getMessage());
            return $zero;
        }
    }
}

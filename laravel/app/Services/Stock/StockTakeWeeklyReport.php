<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stock Take Weekly Control Report — Phase 6 (Stock Take plan).
 *
 * Port of legacy StockTakeVarianceReport::getWeeklyReport() +
 * getTopVarianceProducts() + exportWeeklyCsv().
 *
 * Mirrors the legacy structure: per-session summary (warehouse_count,
 * warehouses_done, variance_lines, gain_value, loss_value, net_value) +
 * totals + top variance products. Uses the PG `difference` generated column
 * on stock_take_items instead of recomputing (physical - system).
 *
 * Status map (Laravel vs legacy 'adjusted'):
 *   Laravel 'posted'  == legacy 'adjusted' (a session whose variance has
 *   been posted to stock + GL).
 *   We include 'posted','reversed','counting','submitted','approved' so the
 *   weekly control report surfaces in-flight sessions too — the legacy code
 *   included 'counting' for the same reason (managers want to see open
 *   counts in their weekly control view, not just finished ones).
 *
 * Warehouse "done" count:
 *   Legacy counted stw.status IN ('counted','posted'). Laravel's
 *   stock_take_warehouses.status uses ('pending','counting','completed'), so
 *   we count 'completed'.
 *
 * RLS: enforced on stock_take_sessions at the DB layer.
 */
class StockTakeWeeklyReport
{
    /**
     * Build the weekly control report for a date range (optional branch).
     *
     * @return array{date_from:string, date_to:string, branch_id:?int, totals:array, sessions:array<int,object>, top_products:array<int,object>}
     */
    public function getWeekly(string $dateFrom, string $dateTo, ?int $branchId = null): array
    {
        $sessions = DB::table('stock_take_sessions as sts')
            ->join('branches as b', 'b.id', '=', 'sts.branch_id')
            ->leftJoin('stock_take_warehouses as stw', 'stw.stock_take_session_id', '=', 'sts.id')
            ->whereBetween('sts.session_date', [$dateFrom, $dateTo])
            ->whereIn('sts.status', ['posted', 'reversed', 'counting', 'submitted', 'approved'])
            ->when($branchId, fn($q) => $q->where('sts.branch_id', $branchId))
            ->groupBy('sts.id', 'sts.session_code', 'sts.session_date', 'sts.status', 'sts.is_reversed', 'sts.journal_entry_id', 'b.branch_name')
            ->orderByDesc('sts.session_date')
            ->orderByDesc('sts.id')
            ->select(
                'sts.id',
                'sts.session_code',
                'sts.session_date',
                'sts.status',
                'sts.is_reversed',
                'sts.journal_entry_id',
                'b.branch_name',
                DB::raw('COUNT(DISTINCT stw.id) AS warehouse_count'),
                DB::raw("SUM(CASE WHEN stw.status = 'completed' THEN 1 ELSE 0 END) AS warehouses_done"),
                DB::raw('(
                    SELECT COUNT(*)
                    FROM stock_take_items sti
                    WHERE sti.stock_take_session_id = sts.id
                      AND sti.difference <> 0
                ) AS variance_lines'),
                DB::raw('(
                    SELECT COALESCE(SUM(
                        CASE WHEN sti.difference > 0
                            THEN sti.difference * COALESCE(sti.rate, 0) ELSE 0 END
                    ), 0)
                    FROM stock_take_items sti
                    WHERE sti.stock_take_session_id = sts.id
                ) AS gain_value'),
                DB::raw('(
                    SELECT COALESCE(SUM(
                        CASE WHEN sti.difference < 0
                            THEN ABS(sti.difference) * COALESCE(sti.rate, 0) ELSE 0 END
                    ), 0)
                    FROM stock_take_items sti
                    WHERE sti.stock_take_session_id = sts.id
                ) AS loss_value')
            )
            ->get();

        $totals = [
            'sessions'       => $sessions->count(),
            'posted'         => 0,
            'reversed'       => 0,
            'open'           => 0,
            'variance_lines' => 0,
            'gain_value'     => 0.0,
            'loss_value'     => 0.0,
        ];

        $sessions = $sessions->map(function ($s) use (&$totals) {
            $gain = (float) ($s->gain_value ?? 0);
            $loss = (float) ($s->loss_value ?? 0);
            $s->net_value = round($gain - $loss, 2);
            $status = !empty($s->is_reversed) ? 'reversed' : ($s->status ?? '');
            if ($status === 'posted') {
                $totals['posted']++;
            } elseif ($status === 'reversed') {
                $totals['reversed']++;
            } else {
                $totals['open']++;
            }
            $totals['variance_lines'] += (int) ($s->variance_lines ?? 0);
            $totals['gain_value'] += $gain;
            $totals['loss_value'] += $loss;
            return $s;
        })->all();

        $totals['gain_value'] = round($totals['gain_value'], 2);
        $totals['loss_value'] = round($totals['loss_value'], 2);
        $totals['net_value']  = round($totals['gain_value'] - $totals['loss_value'], 2);

        $topProducts = $this->getTopVarianceProducts($dateFrom, $dateTo, $branchId, 15);

        return [
            'date_from'    => $dateFrom,
            'date_to'      => $dateTo,
            'branch_id'    => $branchId,
            'totals'       => $totals,
            'sessions'     => $sessions,
            'top_products' => $topProducts,
        ];
    }

    /**
     * Top N products by absolute value variance in the period.
     *
     * @return array<int, object>
     */
    public function getTopVarianceProducts(string $dateFrom, string $dateTo, ?int $branchId = null, int $limit = 15): array
    {
        return DB::table('stock_take_items as sti')
            ->join('stock_take_sessions as sts', 'sts.id', '=', 'sti.stock_take_session_id')
            ->join('products as p', 'p.id', '=', 'sti.product_id')
            ->whereBetween('sts.session_date', [$dateFrom, $dateTo])
            ->where('sti.difference', '<>', 0)
            ->when($branchId, fn($q) => $q->where('sts.branch_id', $branchId))
            ->groupBy('p.id', 'p.product_code', 'p.product_name')
            ->orderByDesc('abs_value_variance')
            ->limit($limit)
            ->select(
                'p.product_code',
                'p.product_name',
                DB::raw('SUM(ABS(sti.difference)) AS abs_qty_variance'),
                DB::raw('SUM(ABS(sti.difference * COALESCE(sti.rate, 0))) AS abs_value_variance'),
                DB::raw("SUM(CASE WHEN sti.difference > 0 THEN 1 ELSE 0 END) AS surplus_lines"),
                DB::raw("SUM(CASE WHEN sti.difference < 0 THEN 1 ELSE 0 END) AS shortage_lines")
            )
            ->get()
            ->all();
    }

    /**
     * Stream a CSV of the weekly report sessions (Excel-friendly with BOM).
     *
     * @param array{sessions:array<int,object>} $report
     */
    public function exportCsv(array $report): StreamedResponse
    {
        $headers = [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="Stock_Take_Weekly_' . now()->format('Y-m-d_His') . '.csv"',
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        return response()->stream(function () use ($report) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($out, ['Session', 'Date', 'Branch', 'Status', 'WH done', 'Variance lines', 'Gain', 'Loss', 'Net', 'Has GL']);
            foreach ($report['sessions'] ?? [] as $s) {
                fputcsv($out, [
                    $s->session_code ?? '',
                    $s->session_date ?? '',
                    $s->branch_name ?? '',
                    !empty($s->is_reversed) ? 'reversed' : ($s->status ?? ''),
                    ($s->warehouses_done ?? 0) . '/' . ($s->warehouse_count ?? 0),
                    $s->variance_lines ?? 0,
                    $s->gain_value ?? 0,
                    $s->loss_value ?? 0,
                    $s->net_value ?? 0,
                    !empty($s->journal_entry_id) ? 'Yes' : 'No',
                ]);
            }
            fclose($out);
        }, 200, $headers);
    }
}

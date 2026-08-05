<?php

namespace App\Services\Stock;

use App\Facades\CsvExporter;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stock Take Variance Report — Phase 6 (Stock Take plan).
 *
 * Port of legacy app/models/Reports/StockTakeVarianceReport.php (detail part).
 *
 * Key differences from legacy:
 *   - session_date     (legacy column was take_date)
 *   - status 'posted'  (legacy used 'adjusted')
 *   - is_reversed      boolean (legacy: int 0/1)
 *   - difference       PG GENERATED STORED column on stock_take_items —
 *                      selected directly instead of recomputing
 *                      (physical_qty - system_qty).
 *   - journal_line_id  per-line GL traceability (Phase 1 feature) —
 *                      exposed as a column for drill-down.
 *   - system_rate / post_rate / revaluation_amount (Phase 9) — costing
 *                      columns that show the setup-time vs post-time avg
 *                      cost and the revaluation adjusting amount.
 *
 * RLS: branch isolation is enforced at the DB layer on stock_take_sessions
 * (and cascades to stock_take_items via the session join). No manual
 * branch_id filter is required here; admin sees all branches, non-admin
 * sees only their own branch automatically.
 */
class StockTakeVarianceReport
{
    /**
     * Line-level variance rows: every count line where physical ≠ system.
     *
     * @param array{session_id?:int, branch_id?:int, warehouse_id?:int, product_id?:int, from?:string, to?:string} $filters
     * @return array<int, object>
     */
    public function getVarianceLines(array $filters = []): array
    {
        $query = DB::table('stock_take_items as sti')
            ->join('stock_take_sessions as sts', 'sts.id', '=', 'sti.stock_take_session_id')
            ->join('branches as b', 'b.id', '=', 'sts.branch_id')
            ->join('warehouses as w', 'w.id', '=', 'sti.warehouse_id')
            ->join('products as p', 'p.id', '=', 'sti.product_id')
            ->where('sti.difference', '<>', 0)
            ->select(
                'sts.id as session_id',
                'sts.session_code',
                'sts.session_date',
                'sts.status as session_status',
                'sts.is_reversed',
                'sts.journal_entry_id',
                'b.id as branch_id',
                'b.branch_name',
                'w.id as warehouse_id',
                'w.warehouse_name',
                'p.id as product_id',
                'p.product_code',
                'p.product_name',
                'sti.system_qty',
                'sti.physical_qty',
                'sti.difference as variance_qty',
                'sti.rate',
                DB::raw('(sti.difference * COALESCE(sti.rate, 0)) as value_diff'),
                // Phase 9: costing columns. system_rate = setup-time avg cost
                // (snapshot); post_rate = post-time avg cost (re-fetched at post);
                // revaluation_amount = (post_rate - system_rate) * physical_qty
                // when the drift exceeded epsilon, else 0.
                'sti.system_rate',
                'sti.post_rate',
                'sti.revaluation_amount',
                'sti.reason',
                'sti.is_applied',
                'sti.journal_line_id',
                'sti.revaluation_line_id'
            );

        if (!empty($filters['from']) && !empty($filters['to'])) {
            $query->whereBetween('sts.session_date', [$filters['from'], $filters['to']]);
        }
        if (!empty($filters['session_id'])) {
            $query->where('sti.stock_take_session_id', (int) $filters['session_id']);
        }
        if (!empty($filters['branch_id'])) {
            $query->where('sts.branch_id', (int) $filters['branch_id']);
        }
        if (!empty($filters['warehouse_id'])) {
            $query->where('sti.warehouse_id', (int) $filters['warehouse_id']);
        }
        if (!empty($filters['product_id'])) {
            $query->where('sti.product_id', (int) $filters['product_id']);
        }

        $query->orderByDesc('sts.session_date')
              ->orderBy('sts.session_code')
              ->orderBy('w.warehouse_name')
              ->orderBy('p.product_code');

        return $query->get()->all();
    }

    /**
     * Totals for a set of variance lines.
     *
     * @param array<int, object> $rows
     * @return array{total_items:int, total_variance:float, total_value_diff:float, gain_lines:int, loss_lines:int, gain_value:float, loss_value:float, total_revaluation:float, reval_lines:int}
     */
    public function summarize(array $rows): array
    {
        $totalItems = count($rows);
        $totalVariance = 0.0;
        $totalValue = 0.0;
        $gainLines = 0;
        $lossLines = 0;
        $gainValue = 0.0;
        $lossValue = 0.0;
        // Phase 9: revaluation totals.
        $totalRevaluation = 0.0;
        $revalLines = 0;

        foreach ($rows as $row) {
            $qty = (float) ($row->variance_qty ?? 0);
            $val = (float) ($row->value_diff ?? 0);
            $totalVariance += $qty;
            $totalValue += $val;
            if ($qty > 0) {
                $gainLines++;
                $gainValue += $val;
            } elseif ($qty < 0) {
                $lossLines++;
                $lossValue += abs($val);
            }
            // Phase 9: accumulate the revaluation adjusting amount.
            $reval = (float) ($row->revaluation_amount ?? 0);
            if (abs($reval) >= 0.01) {
                $revalLines++;
                $totalRevaluation += $reval;
            }
        }

        return [
            'total_items'        => $totalItems,
            'total_variance'     => round($totalVariance, 4),
            'total_value_diff'   => round($totalValue, 2),
            'gain_lines'         => $gainLines,
            'loss_lines'         => $lossLines,
            'gain_value'         => round($gainValue, 2),
            'loss_value'         => round($lossValue, 2),
            // Phase 9: cost-drift revaluation summary.
            'total_revaluation'  => round($totalRevaluation, 6),
            'reval_lines'        => $revalLines,
        ];
    }

    /**
     * Sessions list for the filter dropdown (RLS-scoped).
     *
     * @return array<int, object>
     */
    public function getSessionsList(): array
    {
        return DB::table('stock_take_sessions as sts')
            ->join('branches as b', 'b.id', '=', 'sts.branch_id')
            ->select(
                'sts.id',
                'sts.session_code',
                'sts.session_date',
                'sts.status',
                'sts.is_reversed',
                'b.branch_name'
            )
            ->orderByDesc('sts.session_date')
            ->orderByDesc('sts.id')
            ->limit(200)
            ->get()
            ->all();
    }

    /**
     * Stream a CSV of variance lines (Excel-friendly with UTF-8 BOM).
     *
     * REPORTS-AUDIT-4 (G-150 / csv-export.md G11): refactored to delegate
     * to CsvExporter::exportFromRows(). BOM + Content-Type + RFC 4180
     * escaping now handled by the canonical service. Column order and
     * column labels preserved exactly. Audit-log row is written by the
     * calling controller (ReportController::stocktakeVarianceExport).
     *
     * @param array<int, object> $rows
     */
    public function exportCsv(array $rows): StreamedResponse
    {
        $headerRow = [
            'Session', 'Date', 'Branch', 'Warehouse', 'Code', 'Product',
            'System', 'Physical', 'Variance Qty', 'System Rate', 'Post Rate',
            'Value Diff', 'Revaluation', 'Reason', 'Applied',
        ];

        $rowGenerator = $this->buildVarianceCsvRows($rows);

        $filename = 'Stock_Take_Variance_' . now()->format('Y-m-d_His');

        return CsvExporter::exportFromRows($filename, $headerRow, $rowGenerator);
    }

    /**
     * Build the row generator for the variance CSV export.
     *
     * Extracted as a private method so the lint checker can validate the
     * exportCsv() method body (the linter cannot parse `yield` inside an
     * inline closure expression).
     *
     * @param  array<int, object> $rows
     * @return \Generator<int, array<int,mixed>>
     */
    private function buildVarianceCsvRows(array $rows): \Generator
    {
        foreach ($rows as $r) {
            yield [
                $r->session_code ?? '',
                $r->session_date ?? '',
                $r->branch_name ?? '',
                $r->warehouse_name ?? '',
                $r->product_code ?? '',
                $r->product_name ?? '',
                $r->system_qty ?? 0,
                $r->physical_qty ?? 0,
                $r->variance_qty ?? 0,
                // Phase 9: system_rate = setup-time avg cost,
                // post_rate = post-time avg cost, revaluation_amount =
                // the adjusting entry for the cost drift.
                $r->system_rate ?? 0,
                $r->post_rate ?? 0,
                $r->value_diff ?? 0,
                $r->revaluation_amount ?? 0,
                $r->reason ?? '',
                !empty($r->is_applied) ? 'Yes' : 'No',
            ];
        }
    }
}

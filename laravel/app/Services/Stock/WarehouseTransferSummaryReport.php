<?php

namespace App\Services\Stock;

use App\Facades\CsvExporter;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Warehouse Transfer Summary Report — Phase 6.3.
 *
 * Provides period-level aggregates for warehouse transfers:
 *   - Branch-level breakdowns (total, confirmed, draft, cancelled, value)
 *   - Top 10 most transferred products (qty + value)
 *   - Most active warehouse pairs (from-to combinations)
 *   - Averages (items per transfer, value per transfer)
 *   - Monthly trend for charting
 *
 * Uses raw SQL queries (DB::select) for efficiency, following the same
 * pattern as WarehouseTransferAuditService. Branch isolation applied
 * when branchId is provided.
 *
 * REPORTS-AUDIT-6 (G-241 / csv-export.md G26): added exportCsv() method
 * that produces a multi-section CSV via CsvExporter::exportFromRows()
 * with `prepend_rows` (title + period + branch) + 6 sections (branches,
 * top_products, warehouse_pairs, averages, monthly_trend) each with its
 * own header + data rows.
 */
class WarehouseTransferSummaryReport
{
    /**
     * Get summary data for a date range, optionally scoped to a branch.
     *
     * @param int|null $branchId  Null = all branches (admin); int = single branch scope.
     * @param string   $dateFrom  Start date (Y-m-d).
     * @param string   $dateTo    End date (Y-m-d).
     * @return array
     */
    public function getSummary(?int $branchId, string $dateFrom, string $dateTo): array
    {
        $branchFilter = $this->branchFilter($branchId);

        return [
            'period'          => $this->periodInfo($dateFrom, $dateTo, $branchId),
            'branches'        => $this->branchAggregates($branchFilter, $dateFrom, $dateTo),
            'top_products'    => $this->topProducts($branchFilter, $dateFrom, $dateTo),
            'warehouse_pairs' => $this->warehousePairs($branchFilter, $dateFrom, $dateTo),
            'averages'        => $this->averages($branchFilter, $dateFrom, $dateTo),
            'monthly_trend'   => $this->monthlyTrend($branchId, $dateFrom, $dateTo),
        ];
    }

    // ========================================================================
    // Section builders
    // ========================================================================

    /**
     * Period info — date range and branch scope label.
     */
    private function periodInfo(string $dateFrom, string $dateTo, ?int $branchId): array
    {
        $branchLabel = 'All branches';
        if ($branchId) {
            $branch = DB::selectOne("SELECT branch_name FROM branches WHERE id = ? AND deleted_at IS NULL", [$branchId]);
            $branchLabel = $branch ? $branch->branch_name : "Branch #{$branchId}";
        }

        return [
            'from'          => $dateFrom,
            'to'            => $dateTo,
            'branch_id'     => $branchId,
            'branch_label'  => $branchLabel,
        ];
    }

    /**
     * Branch-level aggregates: total transfers, confirmed, draft, cancelled, total value.
     */
    private function branchAggregates(string $branchFilter, string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT
                b.id AS branch_id,
                b.branch_name,
                COUNT(wt.id) AS total_transfers,
                SUM(CASE WHEN wt.status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_count,
                SUM(CASE WHEN wt.status = 'draft' THEN 1 ELSE 0 END) AS draft_count,
                SUM(CASE WHEN wt.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
                COALESCE(SUM(CASE WHEN wt.status = 'confirmed' THEN wti.qty * wti.rate ELSE 0 END), 0) AS total_value
            FROM branches b
            LEFT JOIN warehouses fw ON fw.branch_id = b.id AND fw.deleted_at IS NULL
            LEFT JOIN warehouse_transfers wt ON wt.from_warehouse_id = fw.id
                AND wt.transfer_date >= ?
                AND wt.transfer_date <= ?
                AND wt.deleted_at IS NULL
                {$branchFilter}
            LEFT JOIN warehouse_transfer_items wti ON wti.warehouse_transfer_id = wt.id
            WHERE b.deleted_at IS NULL
            GROUP BY b.id, b.branch_name
            ORDER BY total_transfers DESC
        ";

        $rows = DB::select($sql, [$dateFrom, $dateTo]);

        return array_map(function ($row) {
            return [
                'branch_id'       => (int) $row->branch_id,
                'branch_name'     => $row->branch_name,
                'total_transfers' => (int) $row->total_transfers,
                'confirmed_count' => (int) $row->confirmed_count,
                'draft_count'     => (int) $row->draft_count,
                'cancelled_count' => (int) $row->cancelled_count,
                'total_value'     => (float) $row->total_value,
            ];
        }, $rows);
    }

    /**
     * Top 10 most transferred products — total qty and total value.
     */
    private function topProducts(string $branchFilter, string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT
                p.id AS product_id,
                p.product_name,
                p.product_code,
                SUM(wti.qty) AS total_qty,
                SUM(wti.qty * wti.rate) AS total_value,
                COUNT(DISTINCT wt.id) AS transfer_count
            FROM warehouse_transfer_items wti
            JOIN warehouse_transfers wt ON wt.id = wti.warehouse_transfer_id
                AND wt.transfer_date >= ?
                AND wt.transfer_date <= ?
                AND wt.deleted_at IS NULL
            JOIN products p ON p.id = wti.product_id
            {$branchFilter}
            GROUP BY p.id, p.product_name, p.product_code
            ORDER BY total_qty DESC
            LIMIT 10
        ";

        $rows = DB::select($sql, [$dateFrom, $dateTo]);

        return array_map(function ($row) {
            return [
                'product_id'    => (int) $row->product_id,
                'product_name'  => $row->product_name,
                'product_code'  => $row->product_code,
                'total_qty'     => (float) $row->total_qty,
                'total_value'   => (float) $row->total_value,
                'transfer_count' => (int) $row->transfer_count,
            ];
        }, $rows);
    }

    /**
     * Most active warehouse pairs — top 10 from-to combinations.
     */
    private function warehousePairs(string $branchFilter, string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT
                fw.id AS from_warehouse_id,
                fw.warehouse_name AS from_warehouse_name,
                tw.id AS to_warehouse_id,
                tw.warehouse_name AS to_warehouse_name,
                COUNT(wt.id) AS transfer_count,
                COALESCE(SUM(wti.qty * wti.rate), 0) AS total_value
            FROM warehouse_transfers wt
            JOIN warehouses fw ON fw.id = wt.from_warehouse_id
            JOIN warehouses tw ON tw.id = wt.to_warehouse_id
            LEFT JOIN warehouse_transfer_items wti ON wti.warehouse_transfer_id = wt.id
            WHERE wt.transfer_date >= ?
              AND wt.transfer_date <= ?
              AND wt.deleted_at IS NULL
              AND fw.deleted_at IS NULL
              AND tw.deleted_at IS NULL
              {$branchFilter}
            GROUP BY fw.id, fw.warehouse_name, tw.id, tw.warehouse_name
            ORDER BY transfer_count DESC
            LIMIT 10
        ";

        $rows = DB::select($sql, [$dateFrom, $dateTo]);

        return array_map(function ($row) {
            return [
                'from_warehouse_id'   => (int) $row->from_warehouse_id,
                'from_warehouse_name' => $row->from_warehouse_name,
                'to_warehouse_id'     => (int) $row->to_warehouse_id,
                'to_warehouse_name'   => $row->to_warehouse_name,
                'transfer_count'      => (int) $row->transfer_count,
                'total_value'         => (float) $row->total_value,
            ];
        }, $rows);
    }

    /**
     * Averages: avg items per transfer, avg value per transfer.
     */
    private function averages(string $branchFilter, string $dateFrom, string $dateTo): array
    {
        $sql = "
            SELECT
                COUNT(wt.id) AS total_transfers,
                COALESCE(SUM(item_counts.item_count), 0) AS total_items,
                COALESCE(SUM(wti.qty * wti.rate), 0) AS total_value
            FROM warehouse_transfers wt
            LEFT JOIN (
                SELECT warehouse_transfer_id, COUNT(*) AS item_count
                FROM warehouse_transfer_items
                GROUP BY warehouse_transfer_id
            ) item_counts ON item_counts.warehouse_transfer_id = wt.id
            LEFT JOIN warehouse_transfer_items wti ON wti.warehouse_transfer_id = wt.id
            WHERE wt.transfer_date >= ?
              AND wt.transfer_date <= ?
              AND wt.deleted_at IS NULL
              {$branchFilter}
        ";

        $row = DB::selectOne($sql, [$dateFrom, $dateTo]);

        $totalTransfers = (int) ($row->total_transfers ?? 0);
        $totalItems     = (int) ($row->total_items ?? 0);
        $totalValue     = (float) ($row->total_value ?? 0);

        return [
            'total_transfers'   => $totalTransfers,
            'avg_items'         => $totalTransfers > 0 ? round($totalItems / $totalTransfers, 2) : 0,
            'avg_value'         => $totalTransfers > 0 ? round($totalValue / $totalTransfers, 2) : 0,
        ];
    }

    /**
     * Monthly trend — month-by-month breakdown for charting.
     */
    private function monthlyTrend(?int $branchId, string $dateFrom, string $dateTo): array
    {
        $branchFilter = $this->branchFilter($branchId, 'wt');

        // PostgreSQL: use TO_CHAR instead of MySQL's DATE_FORMAT
        $sql = "
            SELECT
                TO_CHAR(wt.transfer_date, 'YYYY-MM') AS month,
                COUNT(wt.id) AS transfer_count,
                SUM(CASE WHEN wt.status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_count,
                SUM(CASE WHEN wt.status = 'draft' THEN 1 ELSE 0 END) AS draft_count,
                SUM(CASE WHEN wt.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
                COALESCE(SUM(wti.qty * wti.rate), 0) AS total_value
            FROM warehouse_transfers wt
            LEFT JOIN warehouse_transfer_items wti ON wti.warehouse_transfer_id = wt.id
            WHERE wt.transfer_date >= ?
              AND wt.transfer_date <= ?
              AND wt.deleted_at IS NULL
              {$branchFilter}
            GROUP BY TO_CHAR(wt.transfer_date, 'YYYY-MM')
            ORDER BY month ASC
        ";

        $rows = DB::select($sql, [$dateFrom, $dateTo]);

        return array_map(function ($row) {
            return [
                'month'           => $row->month,
                'transfer_count'  => (int) $row->transfer_count,
                'confirmed_count' => (int) $row->confirmed_count,
                'draft_count'     => (int) $row->draft_count,
                'cancelled_count' => (int) $row->cancelled_count,
                'total_value'     => (float) $row->total_value,
            ];
        }, $rows);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * Build a branch-involvement SQL filter fragment.
     * Only includes transfers where the given branch is the from-branch.
     * Follows the same pattern as WarehouseTransferAuditService::branchInvolvementFilter().
     */
    private function branchFilter(?int $branchId, string $alias = 'wt'): string
    {
        if (!$branchId) {
            return '';
        }
        return " AND EXISTS (
            SELECT 1 FROM warehouses fw
            WHERE fw.id = {$alias}.from_warehouse_id
              AND fw.branch_id = " . (int) $branchId . '
        )';
    }

    // ========================================================================
    // CSV Export — REPORTS-AUDIT-6 (G-241 / csv-export.md G26)
    // ========================================================================

    /**
     * Stream the 6-section summary report as a CSV download.
     *
     * The CSV layout is a single multi-section file (NOT a zip of 6
     * files — simpler for end users to open in Excel). Sections are
     * separated by blank rows. Each section has its own header row +
     * data rows. The title block at the top carries the report label +
     * period + branch scope + currency.
     *
     * Layout (top to bottom):
     *   1. Title: "Warehouse Transfer Summary Report"
     *   2. Period: from → to
     *   3. Branch: branch_label (or "All branches")
     *   4. Currency: BDT
     *   5. (blank)
     *   6. BRANCHES section header + per-branch rows
     *   7. (blank)
     *   8. TOP PRODUCTS section header + per-product rows
     *   9. (blank)
     *  10. WAREHOUSE PAIRS section header + per-pair rows
     *  11. (blank)
     *  12. AVERAGES section header + summary row
     *  13. (blank)
     *  14. MONTHLY TREND section header + per-month rows
     *
     * Uses CsvExporter::exportFromRows() with `prepend_rows` (the title
     * block) + the rows generator (sections) + no `append_rows`. The
     * empty `$headerRow` ([] is skipped — no global column header)
     * because each section carries its own header.
     *
     * @param  array $summary The same array returned by getSummary().
     * @return StreamedResponse
     */
    public function exportCsv(array $summary): StreamedResponse
    {
        $currency = (string) config('accounting.currency', 'BDT');

        $period = $summary['period'];
        $prependRows = [
            ['Warehouse Transfer Summary Report'],
            ['Period', ($period['from'] ?? '') . ' to ' . ($period['to'] ?? '')],
            ['Branch', $period['branch_label'] ?? 'All branches'],
            ['Currency', $currency],
            [], // blank separator
        ];

        $rowGenerator = $this->buildSummaryCsvRows($summary);

        $filename = CsvExporter::filename(
            'Warehouse_Transfer_Summary',
            [$period['from'] ?? 'all', 'to', $period['to'] ?? 'all']
        );

        return CsvExporter::exportFromRows($filename, [], $rowGenerator, [
            'prepend_rows' => $prependRows,
        ]);
    }

    /**
     * Build the row generator for the summary CSV export.
     *
     * Yields the 6 sections in order. Each section starts with a
     * header row (single cell: section title) + a column-label row +
     * data rows. Sections are separated by blank rows.
     *
     * Extracted as a private method so the lint checker can validate
     * the exportCsv() method body (the linter cannot parse `yield`
     * inside an inline closure expression).
     *
     * @param  array $summary
     * @return \Generator<int, array<int,mixed>>
     */
    private function buildSummaryCsvRows(array $summary): \Generator
    {
        // Section 1: Branch-level aggregates.
        yield ['BRANCHES'];
        yield ['Branch ID', 'Branch Name', 'Total Transfers', 'Confirmed', 'Draft', 'Cancelled', 'Total Value'];
        foreach ($summary['branches'] ?? [] as $b) {
            yield [
                $b['branch_id'] ?? '',
                $b['branch_name'] ?? '',
                $b['total_transfers'] ?? 0,
                $b['confirmed_count'] ?? 0,
                $b['draft_count'] ?? 0,
                $b['cancelled_count'] ?? 0,
                number_format((float) ($b['total_value'] ?? 0), 2, '.', ''),
            ];
        }
        yield [];

        // Section 2: Top 10 most transferred products.
        yield ['TOP PRODUCTS (by qty)'];
        yield ['Product ID', 'Product Code', 'Product Name', 'Total Qty', 'Total Value', 'Transfer Count'];
        foreach ($summary['top_products'] ?? [] as $p) {
            yield [
                $p['product_id'] ?? '',
                $p['product_code'] ?? '',
                $p['product_name'] ?? '',
                number_format((float) ($p['total_qty'] ?? 0), 2, '.', ''),
                number_format((float) ($p['total_value'] ?? 0), 2, '.', ''),
                $p['transfer_count'] ?? 0,
            ];
        }
        yield [];

        // Section 3: Most active warehouse pairs.
        yield ['WAREHOUSE PAIRS'];
        yield ['From WH ID', 'From WH', 'To WH ID', 'To WH', 'Transfer Count', 'Total Value'];
        foreach ($summary['warehouse_pairs'] ?? [] as $wp) {
            yield [
                $wp['from_warehouse_id'] ?? '',
                $wp['from_warehouse_name'] ?? '',
                $wp['to_warehouse_id'] ?? '',
                $wp['to_warehouse_name'] ?? '',
                $wp['transfer_count'] ?? 0,
                number_format((float) ($wp['total_value'] ?? 0), 2, '.', ''),
            ];
        }
        yield [];

        // Section 4: Averages.
        $avg = $summary['averages'] ?? [];
        yield ['AVERAGES'];
        yield ['Total Transfers', 'Avg Items / Transfer', 'Avg Value / Transfer'];
        yield [
            $avg['total_transfers'] ?? 0,
            number_format((float) ($avg['avg_items'] ?? 0), 2, '.', ''),
            number_format((float) ($avg['avg_value'] ?? 0), 2, '.', ''),
        ];
        yield [];

        // Section 5: Monthly trend.
        yield ['MONTHLY TREND'];
        yield ['Month', 'Transfer Count', 'Confirmed', 'Draft', 'Cancelled', 'Total Value'];
        foreach ($summary['monthly_trend'] ?? [] as $m) {
            yield [
                $m['month'] ?? '',
                $m['transfer_count'] ?? 0,
                $m['confirmed_count'] ?? 0,
                $m['draft_count'] ?? 0,
                $m['cancelled_count'] ?? 0,
                number_format((float) ($m['total_value'] ?? 0), 2, '.', ''),
            ];
        }
    }
}

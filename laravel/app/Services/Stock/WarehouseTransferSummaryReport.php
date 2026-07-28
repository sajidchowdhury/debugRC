<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;

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
}

<?php
// app/models/Reports/GrossMarginReport.php

require_once '../core/Database.php';

class GrossMarginReport
{
    protected Database $db;

    public function __construct()
    {
        $this->db = new Database();
    }

    /**
     * Gross margin by invoice or product with delivery vs invoice date basis.
     *
     * @param array{
     *   from_date:string,
     *   to_date:string,
     *   branch_id?:int,
     *   date_basis?:string,
     *   salesman_id?:int,
     *   group_by?:string
     * } $filters
     * @return array<string, mixed>
     */
    public function run(array $filters): array
    {
        $fromDate = $filters['from_date'] ?? date('Y-m-01');
        $toDate = $filters['to_date'] ?? date('Y-m-d');
        $branchId = (int)($filters['branch_id'] ?? 0);
        $dateBasis = ($filters['date_basis'] ?? 'delivery') === 'invoice' ? 'invoice' : 'delivery';
        $salesmanId = (int)($filters['salesman_id'] ?? 0);
        $groupBy = ($filters['group_by'] ?? 'invoice') === 'product' ? 'product' : 'invoice';

        $invoiceRows = $dateBasis === 'delivery'
            ? $this->fetchDeliveredInvoices($fromDate, $toDate, $branchId, $salesmanId)
            : $this->fetchInvoiceBasisRows($fromDate, $toDate, $branchId, $salesmanId);

        $productRows = $groupBy === 'product'
            ? ($dateBasis === 'delivery'
                ? $this->fetchDeliveredProducts($fromDate, $toDate, $branchId, $salesmanId)
                : $this->fetchInvoiceBasisProducts($fromDate, $toDate, $branchId, $salesmanId))
            : [];

        foreach ($invoiceRows as &$row) {
            $rev = (float)($row['revenue'] ?? 0);
            $rowCogs = (float)($row['cogs'] ?? 0);
            $row['gross_profit'] = round($rev - $rowCogs, 2);
            $row['margin_pct'] = $rev > 0.0001 ? round(($row['gross_profit'] / $rev) * 100, 2) : null;
            $row['timing_gap'] = $dateBasis === 'invoice'
                && $rev > 0.0001
                && $rowCogs <= 0.0001
                && ($row['status'] ?? '') !== 'challan_completed';
        }
        unset($row);

        $deliveredRevenue = array_sum(array_map(fn($r) => (float)($r['revenue'] ?? 0), $invoiceRows));
        $cogs = array_sum(array_map(fn($r) => (float)($r['cogs'] ?? 0), $invoiceRows));
        $timingGapCount = count(array_filter($invoiceRows, fn($r) => !empty($r['timing_gap'])));
        $deliveredCount = count($invoiceRows) - $timingGapCount;

        $grossProfit = round($deliveredRevenue - $cogs, 2);
        $marginPct = $deliveredRevenue > 0.0001
            ? round(($grossProfit / $deliveredRevenue) * 100, 2)
            : null;

        $pipeline = $this->fetchPipelineRevenue($branchId);
        $returnsSummary = $this->fetchReturnsSummary($fromDate, $toDate, $branchId);

        return [
            'filters' => [
                'from_date'    => $fromDate,
                'to_date'      => $toDate,
                'branch_id'    => $branchId,
                'date_basis'   => $dateBasis,
                'salesman_id'  => $salesmanId,
                'group_by'     => $groupBy,
            ],
            'summary' => [
                'delivered_revenue' => round($deliveredRevenue, 2),
                'cogs'              => round($cogs, 2),
                'gross_profit'      => $grossProfit,
                'margin_pct'        => $marginPct,
                'pipeline_revenue'  => round($pipeline['amount'], 2),
                'pipeline_count'    => (int)$pipeline['count'],
                'invoice_count'     => count($invoiceRows),
                'delivered_count'   => $deliveredCount,
                'timing_gap_count'  => $timingGapCount,
                'returns_amount'    => $returnsSummary['amount'],
                'returns_count'   => $returnsSummary['count'],
            ],
            'invoices' => $invoiceRows,
            'products' => $productRows,
        ];
    }

    private function branchSql(int $branchId, string $alias = 'si'): string
    {
        return $branchId > 0 ? " AND {$alias}.branch_id = " . (int)$branchId : '';
    }

    private function salesmanSql(int $salesmanId, string $alias = 'si'): string
    {
        if ($salesmanId <= 0) {
            return '';
        }

        $id = (int)$salesmanId;

        return " AND ({$alias}.salesman_id = {$id} OR {$alias}.sales_person = {$id})";
    }

    private function cogsExpr(): string
    {
        return 'COALESCE(SUM(CASE WHEN sci.cogs_amount > 0 THEN sci.cogs_amount ELSE sci.qty * sci.issue_rate END), 0)';
    }

    private function fetchDeliveredInvoices(string $from, string $to, int $branchId, int $salesmanId): array
    {
        $branchSql = $this->branchSql($branchId);
        $salesmanSql = $this->salesmanSql($salesmanId);
        $cogsExpr = $this->cogsExpr();

        $this->db->query("
            SELECT
                si.id,
                si.invoice_code,
                si.invoice_date,
                si.status,
                si.total_amount AS revenue,
                sc.id AS challan_id,
                sc.challan_code,
                sc.challan_date,
                {$cogsExpr} AS cogs,
                1 AS has_challan
            FROM sales_invoices si
            INNER JOIN sales_challans sc
                ON sc.sales_invoice_id = si.id
               AND COALESCE(sc.is_reversed, 0) = 0
            LEFT JOIN sales_challan_items sci ON sci.sales_challan_id = sc.id
            WHERE sc.challan_date BETWEEN :from_d AND :to_d
              AND COALESCE(si.is_reversed, 0) = 0
              AND si.status = 'challan_completed'
              {$branchSql}
              {$salesmanSql}
            GROUP BY si.id, si.invoice_code, si.invoice_date, si.status, si.total_amount,
                     sc.id, sc.challan_code, sc.challan_date
            ORDER BY sc.challan_date DESC, si.invoice_code DESC
        ");
        $this->db->bind(':from_d', $from);
        $this->db->bind(':to_d', $to);

        return $this->db->resultSet();
    }

    private function fetchInvoiceBasisRows(string $from, string $to, int $branchId, int $salesmanId): array
    {
        $branchSql = $this->branchSql($branchId);
        $salesmanSql = $this->salesmanSql($salesmanId);
        $cogsExpr = $this->cogsExpr();

        $this->db->query("
            SELECT
                si.id,
                si.invoice_code,
                si.invoice_date,
                si.status,
                si.total_amount AS revenue,
                sc.id AS challan_id,
                sc.challan_code,
                sc.challan_date,
                CASE WHEN sc.id IS NOT NULL THEN {$cogsExpr} ELSE 0 END AS cogs,
                CASE WHEN sc.id IS NOT NULL THEN 1 ELSE 0 END AS has_challan
            FROM sales_invoices si
            LEFT JOIN sales_challans sc
                ON sc.sales_invoice_id = si.id
               AND COALESCE(sc.is_reversed, 0) = 0
            LEFT JOIN sales_challan_items sci ON sci.sales_challan_id = sc.id
            WHERE si.invoice_date BETWEEN :from_d AND :to_d
              AND COALESCE(si.is_reversed, 0) = 0
              AND si.status NOT IN ('reversed', 'cancelled')
              {$branchSql}
              {$salesmanSql}
            GROUP BY si.id, si.invoice_code, si.invoice_date, si.status, si.total_amount,
                     sc.id, sc.challan_code, sc.challan_date
            ORDER BY si.invoice_date DESC, si.invoice_code DESC
        ");
        $this->db->bind(':from_d', $from);
        $this->db->bind(':to_d', $to);

        return $this->db->resultSet();
    }

    private function fetchDeliveredProducts(string $from, string $to, int $branchId, int $salesmanId): array
    {
        $branchSql = $this->branchSql($branchId);
        $salesmanSql = $this->salesmanSql($salesmanId);

        $this->db->query("
            SELECT
                p.id AS product_id,
                p.product_code,
                p.product_name,
                SUM(sci.qty) AS qty,
                SUM(sci.qty * COALESCE(NULLIF(sii.rate, 0), 0)) AS revenue,
                SUM(CASE WHEN sci.cogs_amount > 0 THEN sci.cogs_amount ELSE sci.qty * sci.issue_rate END) AS cogs
            FROM sales_challan_items sci
            INNER JOIN sales_challans sc
                ON sc.id = sci.sales_challan_id
               AND COALESCE(sc.is_reversed, 0) = 0
            INNER JOIN sales_invoices si
                ON si.id = sc.sales_invoice_id
               AND COALESCE(si.is_reversed, 0) = 0
               AND si.status = 'challan_completed'
            INNER JOIN products p ON p.id = sci.product_id
            LEFT JOIN sales_invoice_items sii
                ON sii.sales_invoice_id = si.id
               AND sii.product_id = sci.product_id
            WHERE sc.challan_date BETWEEN :from_d AND :to_d
              {$branchSql}
              {$salesmanSql}
            GROUP BY p.id, p.product_code, p.product_name
            HAVING SUM(sci.qty) > 0
            ORDER BY revenue DESC
        ");
        $this->db->bind(':from_d', $from);
        $this->db->bind(':to_d', $to);

        $rows = $this->db->resultSet();
        foreach ($rows as &$row) {
            $rev = (float)($row['revenue'] ?? 0);
            $rowCogs = (float)($row['cogs'] ?? 0);
            $row['gross_profit'] = round($rev - $rowCogs, 2);
            $row['margin_pct'] = $rev > 0.0001 ? round(($row['gross_profit'] / $rev) * 100, 2) : null;
        }
        unset($row);

        return $rows;
    }

    private function fetchInvoiceBasisProducts(string $from, string $to, int $branchId, int $salesmanId): array
    {
        $branchSql = $this->branchSql($branchId);
        $salesmanSql = $this->salesmanSql($salesmanId);

        $this->db->query("
            SELECT
                p.id AS product_id,
                p.product_code,
                p.product_name,
                SUM(COALESCE(sci.qty, sii.qty)) AS qty,
                SUM(COALESCE(sci.qty, sii.qty) * COALESCE(NULLIF(sii.rate, 0), 0)) AS revenue,
                SUM(CASE WHEN sci.id IS NOT NULL
                    THEN CASE WHEN sci.cogs_amount > 0 THEN sci.cogs_amount ELSE sci.qty * sci.issue_rate END
                    ELSE 0 END) AS cogs
            FROM sales_invoices si
            INNER JOIN sales_invoice_items sii ON sii.sales_invoice_id = si.id
            INNER JOIN products p ON p.id = sii.product_id
            LEFT JOIN sales_challans sc
                ON sc.sales_invoice_id = si.id
               AND COALESCE(sc.is_reversed, 0) = 0
            LEFT JOIN sales_challan_items sci
                ON sci.sales_challan_id = sc.id
               AND sci.product_id = sii.product_id
            WHERE si.invoice_date BETWEEN :from_d AND :to_d
              AND COALESCE(si.is_reversed, 0) = 0
              AND si.status NOT IN ('reversed', 'cancelled')
              {$branchSql}
              {$salesmanSql}
            GROUP BY p.id, p.product_code, p.product_name
            HAVING SUM(COALESCE(sci.qty, sii.qty)) > 0
            ORDER BY revenue DESC
        ");
        $this->db->bind(':from_d', $from);
        $this->db->bind(':to_d', $to);

        $rows = $this->db->resultSet();
        foreach ($rows as &$row) {
            $rev = (float)($row['revenue'] ?? 0);
            $rowCogs = (float)($row['cogs'] ?? 0);
            $row['gross_profit'] = round($rev - $rowCogs, 2);
            $row['margin_pct'] = $rev > 0.0001 ? round(($row['gross_profit'] / $rev) * 100, 2) : null;
        }
        unset($row);

        return $rows;
    }

    /**
     * Open pipeline: draft/godown invoices without a completed challan.
     */
    private function fetchPipelineRevenue(int $branchId): array
    {
        $branchSql = $this->branchSql($branchId);

        $this->db->query("
            SELECT
                COUNT(*) AS cnt,
                COALESCE(SUM(si.total_amount), 0) AS amt
            FROM sales_invoices si
            WHERE COALESCE(si.is_reversed, 0) = 0
              AND si.status IN ('draft', 'godown_issued')
              AND NOT EXISTS (
                  SELECT 1 FROM sales_challans sc
                  WHERE sc.sales_invoice_id = si.id
                    AND COALESCE(sc.is_reversed, 0) = 0
              )
              {$branchSql}
        ");
        $row = $this->db->single();

        return [
            'count'  => (int)($row['cnt'] ?? 0),
            'amount' => (float)($row['amt'] ?? 0),
        ];
    }

    private function fetchReturnsSummary(string $from, string $to, int $branchId): array
    {
        $branchSql = $branchId > 0 ? ' AND si.branch_id = ' . (int)$branchId : '';

        $this->db->query("
            SELECT COUNT(*) AS cnt, COALESCE(SUM(sr.total_amount), 0) AS amt
            FROM sales_returns sr
            INNER JOIN sales_invoices si ON si.id = sr.sales_invoice_id
            WHERE sr.return_date BETWEEN :from_d AND :to_d
              AND COALESCE(sr.is_reversed, 0) = 0
              AND sr.status = 'completed'
              {$branchSql}
        ");
        $this->db->bind(':from_d', $from);
        $this->db->bind(':to_d', $to);
        $row = $this->db->single();

        return [
            'count'  => (int)($row['cnt'] ?? 0),
            'amount' => round((float)($row['amt'] ?? 0), 2),
        ];
    }

    public function exportCsv(array $report): void
    {
        $filters = $report['filters'] ?? [];
        $summary = $report['summary'] ?? [];
        $groupBy = $filters['group_by'] ?? 'invoice';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Gross_Margin_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($out, ['Gross Margin Report', date('Y-m-d H:i')]);
        fputcsv($out, ['Period', ($filters['from_date'] ?? '') . ' to ' . ($filters['to_date'] ?? '')]);
        fputcsv($out, ['Date basis', $filters['date_basis'] ?? 'delivery']);
        fputcsv($out, []);
        fputcsv($out, ['Delivered revenue', number_format((float)($summary['delivered_revenue'] ?? 0), 2, '.', '')]);
        fputcsv($out, ['COGS', number_format((float)($summary['cogs'] ?? 0), 2, '.', '')]);
        fputcsv($out, ['Gross profit', number_format((float)($summary['gross_profit'] ?? 0), 2, '.', '')]);
        fputcsv($out, ['Margin %', $summary['margin_pct'] ?? '']);
        fputcsv($out, ['Pipeline revenue', number_format((float)($summary['pipeline_revenue'] ?? 0), 2, '.', '')]);
        fputcsv($out, []);

        if ($groupBy === 'product') {
            fputcsv($out, ['Product code', 'Product name', 'Qty', 'Revenue', 'COGS', 'Gross profit', 'Margin %']);
            foreach ($report['products'] ?? [] as $row) {
                fputcsv($out, [
                    $row['product_code'] ?? '',
                    $row['product_name'] ?? '',
                    number_format((float)($row['qty'] ?? 0), 3, '.', ''),
                    number_format((float)($row['revenue'] ?? 0), 2, '.', ''),
                    number_format((float)($row['cogs'] ?? 0), 2, '.', ''),
                    number_format((float)($row['gross_profit'] ?? 0), 2, '.', ''),
                    $row['margin_pct'] ?? '',
                ]);
            }
        } else {
            fputcsv($out, ['Invoice', 'Invoice date', 'Challan date', 'Status', 'Revenue', 'COGS', 'Gross profit', 'Margin %', 'Timing gap']);
            foreach ($report['invoices'] ?? [] as $row) {
                fputcsv($out, [
                    $row['invoice_code'] ?? '',
                    $row['invoice_date'] ?? '',
                    $row['challan_date'] ?? '',
                    $row['status'] ?? '',
                    number_format((float)($row['revenue'] ?? 0), 2, '.', ''),
                    number_format((float)($row['cogs'] ?? 0), 2, '.', ''),
                    number_format((float)($row['gross_profit'] ?? 0), 2, '.', ''),
                    $row['margin_pct'] ?? '',
                    !empty($row['timing_gap']) ? 'Yes' : 'No',
                ]);
            }
        }

        fclose($out);
        exit;
    }
}

<?php
// app/models/Reports/ReceivableAgingReport.php — Phase 7D customer receivable aging

require_once __DIR__ . '/../ReportModel.php';
require_once __DIR__ . '/../../helpers/AgingReportHelper.php';

class ReceivableAgingReport extends ReportModel
{
    /**
     * @return array<string, mixed>
     */
    public function getReceivableAging(string $asOfDate, ?int $branchId = null): array
    {
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND (cl.branch_id = ' . (int)$branchId . ' OR cl.branch_id IS NULL)';
        }

        $sql = "
            SELECT
                c.id AS customer_id,
                c.customer_code,
                c.shop_name,
                c.customer_name,
                c.mobile,
                COALESCE(MAX(b.branch_name), '—') AS branch_name,
                SUM(CASE WHEN DATEDIFF(:as_of_date, cl.transaction_date) <= 30 THEN
                    (cl.debit - cl.credit) ELSE 0 END) AS bucket_0_30,
                SUM(CASE WHEN DATEDIFF(:as_of_date, cl.transaction_date) BETWEEN 31 AND 60 THEN
                    (cl.debit - cl.credit) ELSE 0 END) AS bucket_31_60,
                SUM(CASE WHEN DATEDIFF(:as_of_date, cl.transaction_date) BETWEEN 61 AND 90 THEN
                    (cl.debit - cl.credit) ELSE 0 END) AS bucket_61_90,
                SUM(CASE WHEN DATEDIFF(:as_of_date, cl.transaction_date) > 90 THEN
                    (cl.debit - cl.credit) ELSE 0 END) AS bucket_90_plus,
                SUM(cl.debit - cl.credit) AS total_receivable
            FROM customer_ledger cl
            INNER JOIN customers c ON c.id = cl.customer_id
            LEFT JOIN branches b ON b.id = cl.branch_id
            WHERE cl.transaction_date <= :as_of_date
              AND COALESCE(cl.is_reversed, 0) = 0
              {$branchSql}
            GROUP BY c.id, c.customer_code, c.shop_name, c.customer_name, c.mobile
            HAVING total_receivable > 0.005
            ORDER BY total_receivable DESC
        ";

        $this->db->query($sql);
        $this->db->bind(':as_of_date', $asOfDate);
        $rows = $this->db->resultSet() ?: [];

        foreach ($rows as &$row) {
            $row['branch_name'] = $row['branch_name'] ?? '—';
        }
        unset($row);

        $grandTotal = 0.0;
        foreach ($rows as $row) {
            $grandTotal += (float)($row['total_receivable'] ?? 0);
        }
        $grandTotal = round($grandTotal, 2);

        return [
            'as_of_date'  => $asOfDate,
            'branch_id'   => $branchId,
            'rows'        => $rows,
            'grand_total' => $grandTotal,
            'footnote'    => AgingReportHelper::buildArFootnote($grandTotal, $asOfDate, $branchId),
        ];
    }

    public function exportReceivableAging(array $report): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Receivable_Aging_' . ($report['as_of_date'] ?? date('Y-m-d')) . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Receivable Aging Report']);
        fputcsv($out, ['As of', $report['as_of_date'] ?? '']);
        fputcsv($out, []);

        fputcsv($out, ['Customer Code', 'Shop', 'Customer', 'Mobile', 'Branch', '0-30', '31-60', '61-90', '90+', 'Total']);

        foreach ($report['rows'] ?? [] as $row) {
            fputcsv($out, [
                $row['customer_code'] ?? '',
                $row['shop_name'] ?? '',
                $row['customer_name'] ?? '',
                $row['mobile'] ?? '',
                $row['branch_name'] ?? '',
                number_format((float)($row['bucket_0_30'] ?? 0), 2, '.', ''),
                number_format((float)($row['bucket_31_60'] ?? 0), 2, '.', ''),
                number_format((float)($row['bucket_61_90'] ?? 0), 2, '.', ''),
                number_format((float)($row['bucket_90_plus'] ?? 0), 2, '.', ''),
                number_format((float)($row['total_receivable'] ?? 0), 2, '.', ''),
            ]);
        }

        $fn = $report['footnote'] ?? [];
        fputcsv($out, []);
        fputcsv($out, ['Aging total', number_format((float)($fn['aging_total'] ?? 0), 2, '.', '')]);
        fputcsv($out, ['Sub-ledger total', number_format((float)($fn['sub_ledger_total'] ?? 0), 2, '.', '')]);
        fputcsv($out, ['GL control total', number_format((float)($fn['gl_control_total'] ?? 0), 2, '.', '')]);

        fclose($out);
        exit;
    }
}

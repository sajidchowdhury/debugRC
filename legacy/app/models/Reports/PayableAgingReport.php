<?php
// app/models/Reports/PayableAgingReport.php — Phase 7D enhanced payable aging

require_once __DIR__ . '/../ReportModel.php';
require_once __DIR__ . '/../../helpers/AgingReportHelper.php';

class PayableAgingReport extends ReportModel
{
    /**
     * @return array<string, mixed>
     */
    public function getPayableAging(string $asOfDate, ?int $branchId = null): array
    {
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND (sl.branch_id = ' . (int)$branchId . ' OR sl.branch_id IS NULL)';
        }

        $sql = "
            SELECT
                s.id AS supplier_id,
                s.supplier_code,
                s.supplier_name,
                s.mobile,
                COALESCE(MAX(b.branch_name), '—') AS branch_name,
                SUM(CASE WHEN DATEDIFF(:as_of_date, sl.transaction_date) <= 30 THEN
                    (sl.debit - sl.credit) ELSE 0 END) AS bucket_0_30,
                SUM(CASE WHEN DATEDIFF(:as_of_date, sl.transaction_date) BETWEEN 31 AND 60 THEN
                    (sl.debit - sl.credit) ELSE 0 END) AS bucket_31_60,
                SUM(CASE WHEN DATEDIFF(:as_of_date, sl.transaction_date) BETWEEN 61 AND 90 THEN
                    (sl.debit - sl.credit) ELSE 0 END) AS bucket_61_90,
                SUM(CASE WHEN DATEDIFF(:as_of_date, sl.transaction_date) > 90 THEN
                    (sl.debit - sl.credit) ELSE 0 END) AS bucket_90_plus,
                SUM(sl.debit - sl.credit) AS total_payable
            FROM supplier_ledger sl
            INNER JOIN suppliers s ON s.id = sl.supplier_id
            LEFT JOIN branches b ON b.id = sl.branch_id
            WHERE sl.transaction_date <= :as_of_date
              AND COALESCE(sl.is_reversed, 0) = 0
              {$branchSql}
            GROUP BY s.id, s.supplier_code, s.supplier_name, s.mobile
            HAVING total_payable > 0.005
            ORDER BY total_payable DESC
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
            $grandTotal += (float)($row['total_payable'] ?? 0);
        }
        $grandTotal = round($grandTotal, 2);

        return [
            'as_of_date'  => $asOfDate,
            'branch_id'   => $branchId,
            'rows'        => $rows,
            'grand_total' => $grandTotal,
            'footnote'    => AgingReportHelper::buildApFootnote($grandTotal, $asOfDate, $branchId),
        ];
    }

    public function exportPayableAging(array $report): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Payable_Aging_' . ($report['as_of_date'] ?? date('Y-m-d')) . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Payable Aging Report']);
        fputcsv($out, ['As of', $report['as_of_date'] ?? '']);
        fputcsv($out, []);

        fputcsv($out, ['Supplier Code', 'Supplier Name', 'Mobile', 'Branch', '0-30', '31-60', '61-90', '90+', 'Total']);

        foreach ($report['rows'] ?? [] as $row) {
            fputcsv($out, [
                $row['supplier_code'] ?? '',
                $row['supplier_name'] ?? '',
                $row['mobile'] ?? '',
                $row['branch_name'] ?? '',
                number_format((float)($row['bucket_0_30'] ?? 0), 2, '.', ''),
                number_format((float)($row['bucket_31_60'] ?? 0), 2, '.', ''),
                number_format((float)($row['bucket_61_90'] ?? 0), 2, '.', ''),
                number_format((float)($row['bucket_90_plus'] ?? 0), 2, '.', ''),
                number_format((float)($row['total_payable'] ?? 0), 2, '.', ''),
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

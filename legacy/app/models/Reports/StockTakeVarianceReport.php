<?php
// app/models/Reports/StockTakeVarianceReport.php — Phase 4 variance & weekly reports

require_once __DIR__ . '/../../../core/Database.php';
require_once __DIR__ . '/../../helpers/Helper.php';

class StockTakeVarianceReport
{
    protected Database $db;
    protected ?int $branchId;

    public function __construct()
    {
        $this->db = new Database();
        $this->branchId = Helper::sessionBranchId();
    }

    public function getSessionsList(): array
    {
        $sql = "
            SELECT sts.id, sts.session_code, sts.take_date, sts.status, sts.is_reversed, b.branch_name
            FROM stock_take_sessions sts
            JOIN branches b ON b.id = sts.branch_id
            WHERE 1=1
        ";
        if ($this->branchId) {
            $sql .= ' AND sts.branch_id = ' . (int)$this->branchId;
        }
        $sql .= ' ORDER BY sts.take_date DESC, sts.id DESC LIMIT 200';

        $this->db->query($sql);
        return $this->db->resultSet() ?: [];
    }

    /**
     * @param array{session_id?:int,branch_id?:int,warehouse_id?:int,product_id?:int} $filters
     */
    public function getVarianceLines(array $filters = []): array
    {
        $sql = "
            SELECT
                sts.session_code,
                sts.take_date,
                sts.status AS session_status,
                sts.is_reversed,
                b.branch_name,
                w.warehouse_name,
                p.product_code,
                p.product_name,
                sti.system_qty,
                sti.physical_qty,
                (sti.physical_qty - sti.system_qty) AS variance_qty,
                sti.rate,
                ((sti.physical_qty - sti.system_qty) * COALESCE(sti.rate, 0)) AS value_diff,
                sti.reason,
                sti.is_applied
            FROM stock_take_items sti
            JOIN stock_take_sessions sts ON sts.id = sti.stock_take_session_id
            JOIN branches b ON b.id = sts.branch_id
            JOIN warehouses w ON w.id = sti.warehouse_id
            JOIN products p ON p.id = sti.product_id
            WHERE sti.physical_qty <> sti.system_qty
        ";

        $bindings = [];
        $branchId = $this->resolveBranchFilter($filters);
        if ($branchId) {
            $sql .= ' AND sts.branch_id = :branch_id';
            $bindings[':branch_id'] = $branchId;
        }
        if (!empty($filters['session_id'])) {
            $sql .= ' AND sti.stock_take_session_id = :session_id';
            $bindings[':session_id'] = (int)$filters['session_id'];
        }
        if (!empty($filters['warehouse_id'])) {
            $sql .= ' AND sti.warehouse_id = :warehouse_id';
            $bindings[':warehouse_id'] = (int)$filters['warehouse_id'];
        }
        if (!empty($filters['product_id'])) {
            $sql .= ' AND sti.product_id = :product_id';
            $bindings[':product_id'] = (int)$filters['product_id'];
        }

        $sql .= ' ORDER BY sts.take_date DESC, sts.session_code, w.warehouse_name, p.product_code';

        $this->db->query($sql);
        foreach ($bindings as $k => $v) {
            $this->db->bind($k, $v);
        }

        return $this->db->resultSet() ?: [];
    }

    public function summarizeVarianceLines(array $rows): array
    {
        $totalItems = count($rows);
        $totalVariance = 0.0;
        $totalValue = 0.0;
        foreach ($rows as $row) {
            $totalVariance += (float)($row['variance_qty'] ?? 0);
            $totalValue += (float)($row['value_diff'] ?? 0);
        }

        return [
            'total_items'      => $totalItems,
            'total_variance'   => round($totalVariance, 4),
            'total_value_diff' => round($totalValue, 2),
        ];
    }

    /**
     * Weekly control report for posted/reversed sessions in date range.
     */
    public function getWeeklyReport(string $dateFrom, string $dateTo, ?int $branchId = null): array
    {
        $branchId = $branchId ?: $this->branchId;
        $bindings = [
            ':date_from' => $dateFrom,
            ':date_to'   => $dateTo,
        ];

        $branchSql = '';
        if ($branchId) {
            $branchSql = ' AND sts.branch_id = :branch_id';
            $bindings[':branch_id'] = $branchId;
        }

        $this->db->query("
            SELECT
                sts.id,
                sts.session_code,
                sts.take_date,
                sts.status,
                sts.is_reversed,
                sts.journal_entry_id,
                b.branch_name,
                COUNT(DISTINCT stw.id) AS warehouse_count,
                SUM(CASE WHEN stw.status IN ('counted','posted') THEN 1 ELSE 0 END) AS warehouses_done,
                (
                    SELECT COUNT(*)
                    FROM stock_take_items sti
                    WHERE sti.stock_take_session_id = sts.id
                      AND sti.physical_qty <> sti.system_qty
                ) AS variance_lines,
                (
                    SELECT COALESCE(SUM(
                        CASE WHEN sti.physical_qty > sti.system_qty
                            THEN (sti.physical_qty - sti.system_qty) * COALESCE(sti.rate, 0) ELSE 0 END
                    ), 0)
                    FROM stock_take_items sti
                    WHERE sti.stock_take_session_id = sts.id
                ) AS gain_value,
                (
                    SELECT COALESCE(SUM(
                        CASE WHEN sti.physical_qty < sti.system_qty
                            THEN (sti.system_qty - sti.physical_qty) * COALESCE(sti.rate, 0) ELSE 0 END
                    ), 0)
                    FROM stock_take_items sti
                    WHERE sti.stock_take_session_id = sts.id
                ) AS loss_value
            FROM stock_take_sessions sts
            JOIN branches b ON b.id = sts.branch_id
            LEFT JOIN stock_take_warehouses stw ON stw.stock_take_session_id = sts.id
            WHERE sts.take_date BETWEEN :date_from AND :date_to
              AND sts.status IN ('adjusted', 'reversed', 'counting')
              {$branchSql}
            GROUP BY sts.id
            ORDER BY sts.take_date DESC, sts.id DESC
        ");
        foreach ($bindings as $k => $v) {
            $this->db->bind($k, $v);
        }
        $sessions = $this->db->resultSet() ?: [];

        $totals = [
            'sessions'        => count($sessions),
            'posted'          => 0,
            'reversed'        => 0,
            'open'            => 0,
            'variance_lines'  => 0,
            'gain_value'      => 0.0,
            'loss_value'      => 0.0,
        ];

        foreach ($sessions as &$s) {
            $gain = (float)($s['gain_value'] ?? 0);
            $loss = (float)($s['loss_value'] ?? 0);
            $s['net_value'] = round($gain - $loss, 2);
            $status = !empty($s['is_reversed']) ? 'reversed' : ($s['status'] ?? '');
            if ($status === 'adjusted') {
                $totals['posted']++;
            } elseif ($status === 'reversed') {
                $totals['reversed']++;
            } else {
                $totals['open']++;
            }
            $totals['variance_lines'] += (int)($s['variance_lines'] ?? 0);
            $totals['gain_value'] += $gain;
            $totals['loss_value'] += $loss;
        }
        unset($s);
        $totals['gain_value'] = round($totals['gain_value'], 2);
        $totals['loss_value'] = round($totals['loss_value'], 2);
        $totals['net_value'] = round($totals['gain_value'] - $totals['loss_value'], 2);

        $topProducts = $this->getTopVarianceProducts($dateFrom, $dateTo, $branchId, 15);

        return [
            'date_from'  => $dateFrom,
            'date_to'    => $dateTo,
            'branch_id'  => $branchId,
            'totals'     => $totals,
            'sessions'   => $sessions,
            'top_products' => $topProducts,
        ];
    }

    public function getTopVarianceProducts(string $dateFrom, string $dateTo, ?int $branchId, int $limit = 15): array
    {
        $bindings = [':date_from' => $dateFrom, ':date_to' => $dateTo];
        $branchSql = '';
        if ($branchId) {
            $branchSql = ' AND sts.branch_id = :branch_id';
            $bindings[':branch_id'] = $branchId;
        }

        $this->db->query("
            SELECT
                p.product_code,
                p.product_name,
                SUM(ABS(sti.physical_qty - sti.system_qty)) AS abs_qty_variance,
                SUM(ABS((sti.physical_qty - sti.system_qty) * COALESCE(sti.rate, 0))) AS abs_value_variance,
                SUM(CASE WHEN sti.physical_qty > sti.system_qty THEN 1 ELSE 0 END) AS surplus_lines,
                SUM(CASE WHEN sti.physical_qty < sti.system_qty THEN 1 ELSE 0 END) AS shortage_lines
            FROM stock_take_items sti
            JOIN stock_take_sessions sts ON sts.id = sti.stock_take_session_id
            JOIN products p ON p.id = sti.product_id
            WHERE sts.take_date BETWEEN :date_from AND :date_to
              AND sti.physical_qty <> sti.system_qty
              {$branchSql}
            GROUP BY p.id
            ORDER BY abs_value_variance DESC
            LIMIT " . (int)$limit
        );
        foreach ($bindings as $k => $v) {
            $this->db->bind($k, $v);
        }

        return $this->db->resultSet() ?: [];
    }

    public function exportVarianceCsv(array $rows): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Stock_Take_Variance_' . date('Y-m-d_H-i') . '.csv"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, [
            'Session', 'Date', 'Branch', 'Warehouse', 'Code', 'Product',
            'System', 'Physical', 'Variance Qty', 'Rate', 'Value Diff', 'Reason', 'Applied',
        ]);

        foreach ($rows as $r) {
            fputcsv($out, [
                $r['session_code'] ?? '',
                $r['take_date'] ?? '',
                $r['branch_name'] ?? '',
                $r['warehouse_name'] ?? '',
                $r['product_code'] ?? '',
                $r['product_name'] ?? '',
                $r['system_qty'] ?? 0,
                $r['physical_qty'] ?? 0,
                $r['variance_qty'] ?? 0,
                $r['rate'] ?? 0,
                $r['value_diff'] ?? 0,
                $r['reason'] ?? '',
                !empty($r['is_applied']) ? 'Yes' : 'No',
            ]);
        }
        fclose($out);
        exit;
    }

    public function exportWeeklyCsv(array $report): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="Stock_Take_Weekly_' . date('Y-m-d_H-i') . '.csv"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, ['Session', 'Date', 'Branch', 'Status', 'WH done', 'Variance lines', 'Gain', 'Loss', 'Net', 'Has GL']);

        foreach ($report['sessions'] ?? [] as $s) {
            fputcsv($out, [
                $s['session_code'] ?? '',
                $s['take_date'] ?? '',
                $s['branch_name'] ?? '',
                !empty($s['is_reversed']) ? 'reversed' : ($s['status'] ?? ''),
                ($s['warehouses_done'] ?? 0) . '/' . ($s['warehouse_count'] ?? 0),
                $s['variance_lines'] ?? 0,
                $s['gain_value'] ?? 0,
                $s['loss_value'] ?? 0,
                $s['net_value'] ?? 0,
                !empty($s['journal_entry_id']) ? 'Yes' : 'No',
            ]);
        }
        fclose($out);
        exit;
    }

    private function resolveBranchFilter(array $filters): ?int
    {
        if ($this->branchId) {
            return $this->branchId;
        }
        $bid = (int)($filters['branch_id'] ?? 0);

        return $bid > 0 ? $bid : null;
    }
}
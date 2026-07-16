<?php
// app/models/Reports/BranchIntercompanyWeeklyReport.php

require_once __DIR__ . '/../ReportModel.php';
require_once __DIR__ . '/../../helpers/Helper.php';

class BranchIntercompanyWeeklyReport extends ReportModel
{
    private const STALE_OUTSTANDING_DAYS = 30;
    private const PRICE_DROP_MIN_GAP = 0.01;
    private const BELOW_COST_MIN_GAP = 0.01;

    /**
     * Weekly inter-branch control: demands, settlements, outstanding, floor stock at locked cost.
     *
     * @param array{from_date:string,to_date:string,branch_id:int,counterparty_branch_id:int} $filters
     * @return array<string, mixed>
     */
    public function buildWeeklyReport(array $filters): array
    {
        $fromDate = $filters['from_date'] ?? date('Y-m-d', strtotime('-6 days'));
        $toDate = $filters['to_date'] ?? date('Y-m-d');
        $branchId = (int)($filters['branch_id'] ?? Helper::sessionBranchId());
        $counterpartyId = (int)($filters['counterparty_branch_id'] ?? 0);

        $branchName = $this->branchName($branchId);
        $counterpartyName = $counterpartyId > 0 ? $this->branchName($counterpartyId) : 'All branches';

        $demands = $this->getDemandsInPeriod($fromDate, $toDate, $branchId, $counterpartyId);
        $settlements = $this->getSettlementsInPeriod($fromDate, $toDate, $branchId, $counterpartyId);
        $floorStock = $this->getFloorStockExposure($branchId, $counterpartyId);
        $pairs = $counterpartyId > 0
            ? []
            : $this->getOutstandingByPair($branchId);

        $outstandingIOwe = $this->sumOutstandingAsDebtor($branchId, $counterpartyId);
        $outstandingOwedToMe = $this->sumOutstandingAsCreditor($branchId, $counterpartyId);
        $ledgerNetIOwe = $this->ledgerNetOwed($branchId, $counterpartyId, true);
        $ledgerNetOwedToMe = $counterpartyId > 0
            ? $this->ledgerNetOwed($counterpartyId, $branchId, true)
            : 0.0;

        $demandsApprovedValue = 0.0;
        $demandsApprovedCount = 0;
        foreach ($demands as $d) {
            if (($d['status'] ?? '') === 'received' && empty($d['is_reversed'])) {
                $demandsApprovedCount++;
                $demandsApprovedValue += (float)($d['total_value'] ?? 0);
            }
        }

        $settlementsTotal = 0.0;
        foreach ($settlements as $s) {
            $settlementsTotal += (float)($s['amount'] ?? 0);
        }

        $floorValue = 0.0;
        foreach ($floorStock as $row) {
            $floorValue += (float)($row['floor_value'] ?? 0);
        }

        $priceDrops = $this->getCatalogPriceDropFlags($branchId, $counterpartyId);
        $belowCostSales = $this->getBelowLockedCostSales($fromDate, $toDate, $branchId, $counterpartyId);
        $staleOutstanding = $this->getStaleOutstandingDemands($branchId, $counterpartyId);

        $antiTotal = count($priceDrops) + count($belowCostSales) + count($staleOutstanding);

        return [
            'filters' => [
                'from_date'               => $fromDate,
                'to_date'                 => $toDate,
                'branch_id'               => $branchId,
                'counterparty_branch_id'  => $counterpartyId,
                'branch_name'             => $branchName,
                'counterparty_name'       => $counterpartyName,
            ],
            'summary' => [
                'demands_approved_count'  => $demandsApprovedCount,
                'demands_approved_value'  => $demandsApprovedValue,
                'settlements_in_period'   => $settlementsTotal,
                'outstanding_i_owe'       => $outstandingIOwe,
                'outstanding_owed_to_me'  => $outstandingOwedToMe,
                'floor_stock_value'       => $floorValue,
                'ledger_net_i_owe'        => $ledgerNetIOwe,
                'ledger_recon_diff_owe'   => round($outstandingIOwe - $ledgerNetIOwe, 2),
                'anti_gaming_alert_count' => $antiTotal,
                'price_drop_count'        => count($priceDrops),
                'below_cost_sale_count'   => count($belowCostSales),
                'stale_demand_count'      => count($staleOutstanding),
            ],
            'demands'      => $demands,
            'settlements'  => $settlements,
            'floor_stock'  => $floorStock,
            'pairs'        => $pairs,
            'anti_gaming'  => [
                'stale_days_threshold' => self::STALE_OUTSTANDING_DAYS,
                'price_drops'          => $priceDrops,
                'below_cost_sales'     => $belowCostSales,
                'stale_outstanding'    => $staleOutstanding,
            ],
        ];
    }

    /**
     * Per-demand risk flags for the details screen.
     *
     * @return array{flags:list<array>,has_alerts:bool}
     */
    public function getDemandAntiGamingFlags(int $demandId): array
    {
        $flags = [];

        $this->db->query("
            SELECT bd.*, fb.branch_name AS from_branch, tb.branch_name AS to_branch
            FROM branch_demands bd
            JOIN branches fb ON fb.id = bd.from_branch_id
            JOIN branches tb ON tb.id = bd.to_branch_id
            WHERE bd.id = :id
            LIMIT 1
        ");
        $this->db->bind(':id', $demandId);
        $demand = $this->db->single();
        if (!$demand || ($demand['status'] ?? '') !== 'received' || !empty($demand['is_reversed'])) {
            return ['flags' => [], 'has_alerts' => false];
        }

        foreach ($this->getCatalogPriceDropFlags(0, 0, $demandId) as $row) {
            $flags[] = [
                'severity' => 'warn',
                'code'     => 'catalog_below_locked',
                'message'  => sprintf(
                    '%s: catalog Tk %s is below locked transfer Tk %s (gap Tk %s). Principal stays frozen.',
                    $row['product_code'] ?? 'Product',
                    number_format((float)($row['catalog_rate'] ?? 0), 2),
                    number_format((float)($row['locked_rate'] ?? 0), 2),
                    number_format((float)($row['rate_gap'] ?? 0), 2)
                ),
            ];
        }

        $outstanding = max(
            0,
            (float)($demand['total_value'] ?? 0) - (float)($demand['settlement_amount'] ?? 0)
        );
        $ageDays = (int)floor((time() - strtotime($demand['demand_date'] ?? 'now')) / 86400);
        if ($outstanding > 0.01 && $ageDays >= self::STALE_OUTSTANDING_DAYS) {
            $flags[] = [
                'severity' => 'danger',
                'code'     => 'stale_outstanding',
                'message'  => sprintf(
                    'Outstanding Tk %s open for %d days (threshold %d).',
                    number_format($outstanding, 2),
                    $ageDays,
                    self::STALE_OUTSTANDING_DAYS
                ),
            ];
        }

        $from = date('Y-m-d', strtotime('-365 days'));
        $to = date('Y-m-d');
        foreach ($this->getBelowLockedCostSales($from, $to, 0, 0, $demandId) as $row) {
            $flags[] = [
                'severity' => 'danger',
                'code'     => 'sale_below_locked',
                'message'  => sprintf(
                    'Invoice %s (%s): sold at Tk %s vs locked Tk %s (qty %s).',
                    $row['invoice_code'] ?? '',
                    $row['invoice_date'] ?? '',
                    number_format((float)($row['sale_rate'] ?? 0), 2),
                    number_format((float)($row['locked_rate'] ?? 0), 2),
                    number_format((float)($row['sale_qty'] ?? 0), 2)
                ),
            ];
        }

        return ['flags' => $flags, 'has_alerts' => count($flags) > 0];
    }

    public function exportCsv(array $report): void
    {
        $f = $report['filters'] ?? [];
        $filename = 'Interbranch_Weekly_' . ($f['from_date'] ?? 'from') . '_to_' . ($f['to_date'] ?? 'to') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($out, ['Inter-branch weekly report']);
        fputcsv($out, ['Branch', $f['branch_name'] ?? '']);
        fputcsv($out, ['Counterparty', $f['counterparty_name'] ?? '']);
        fputcsv($out, ['Period', ($f['from_date'] ?? '') . ' to ' . ($f['to_date'] ?? '')]);
        fputcsv($out, []);

        $s = $report['summary'] ?? [];
        fputcsv($out, ['Summary']);
        fputcsv($out, ['Demands approved (count)', $s['demands_approved_count'] ?? 0]);
        fputcsv($out, ['Demands approved (value)', $s['demands_approved_value'] ?? 0]);
        fputcsv($out, ['Settlements in period', $s['settlements_in_period'] ?? 0]);
        fputcsv($out, ['Outstanding I owe', $s['outstanding_i_owe'] ?? 0]);
        fputcsv($out, ['Outstanding owed to me', $s['outstanding_owed_to_me'] ?? 0]);
        fputcsv($out, ['Floor stock (locked cost)', $s['floor_stock_value'] ?? 0]);
        fputcsv($out, ['Anti-gaming alerts (total)', $s['anti_gaming_alert_count'] ?? 0]);
        fputcsv($out, ['— Catalog below locked', $s['price_drop_count'] ?? 0]);
        fputcsv($out, ['— Sales below locked (period)', $s['below_cost_sale_count'] ?? 0]);
        fputcsv($out, ['— Stale outstanding', $s['stale_demand_count'] ?? 0]);
        fputcsv($out, []);

        fputcsv($out, ['Demands in period']);
        fputcsv($out, ['Date', 'Code', 'From', 'To', 'Status', 'Total', 'Settled', 'Outstanding']);
        foreach ($report['demands'] ?? [] as $d) {
            $total = (float)($d['total_value'] ?? 0);
            $settled = (float)($d['settlement_amount'] ?? 0);
            fputcsv($out, [
                $d['demand_date'] ?? '',
                $d['demand_code'] ?? '',
                $d['from_branch'] ?? '',
                $d['to_branch'] ?? '',
                $d['status'] ?? '',
                $total,
                $settled,
                max(0, $total - $settled),
            ]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Settlements in period']);
        fputcsv($out, ['Date', 'Type', 'Ref', 'Counterparty', 'Amount', 'Remarks']);
        foreach ($report['settlements'] ?? [] as $row) {
            fputcsv($out, [
                $row['transaction_date'] ?? '',
                $row['source_type'] ?? '',
                $row['reference_label'] ?? '',
                $row['counterparty_name'] ?? '',
                $row['amount'] ?? 0,
                $row['remarks'] ?? '',
            ]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Floor stock (receiver warehouses, locked transfer cost)']);
        fputcsv($out, ['Demand', 'Product', 'Locked rate', 'WH qty', 'Floor value']);
        foreach ($report['floor_stock'] ?? [] as $row) {
            fputcsv($out, [
                $row['demand_code'] ?? '',
                $row['product_name'] ?? '',
                $row['cost_rate'] ?? 0,
                $row['warehouse_qty'] ?? 0,
                $row['floor_value'] ?? 0,
            ]);
        }
        fputcsv($out, []);

        $ag = $report['anti_gaming'] ?? [];
        $threshold = (int)($ag['stale_days_threshold'] ?? self::STALE_OUTSTANDING_DAYS);

        fputcsv($out, ['Anti-gaming alerts']);
        fputcsv($out, ['Catalog below locked (open received demands)']);
        fputcsv($out, ['Demand', 'Product', 'Locked', 'Catalog', 'Gap']);
        foreach ($ag['price_drops'] ?? [] as $row) {
            fputcsv($out, [
                $row['demand_code'] ?? '',
                ($row['product_code'] ?? '') . ' ' . ($row['product_name'] ?? ''),
                $row['locked_rate'] ?? 0,
                $row['catalog_rate'] ?? 0,
                $row['rate_gap'] ?? 0,
            ]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Sales below locked transfer cost (period)']);
        fputcsv($out, ['Invoice', 'Date', 'Demand', 'Product', 'Sale rate', 'Locked', 'Qty']);
        foreach ($ag['below_cost_sales'] ?? [] as $row) {
            fputcsv($out, [
                $row['invoice_code'] ?? '',
                $row['invoice_date'] ?? '',
                $row['demand_code'] ?? '',
                ($row['product_code'] ?? '') . ' ' . ($row['product_name'] ?? ''),
                $row['sale_rate'] ?? 0,
                $row['locked_rate'] ?? 0,
                $row['sale_qty'] ?? 0,
            ]);
        }
        fputcsv($out, []);

        fputcsv($out, ['Stale outstanding (>' . $threshold . ' days)']);
        fputcsv($out, ['Demand', 'Date', 'Age days', 'Outstanding', 'Counterparty']);
        foreach ($ag['stale_outstanding'] ?? [] as $row) {
            fputcsv($out, [
                $row['demand_code'] ?? '',
                $row['demand_date'] ?? '',
                $row['age_days'] ?? 0,
                $row['outstanding'] ?? 0,
                $row['counterparty_name'] ?? '',
            ]);
        }

        fclose($out);
        exit;
    }

    private function branchName(int $branchId): string
    {
        if ($branchId <= 0) {
            return '—';
        }
        $this->db->query('SELECT branch_name FROM branches WHERE id = :id');
        $this->db->bind(':id', $branchId);

        return $this->db->single()['branch_name'] ?? ('Branch #' . $branchId);
    }

    private function getDemandsInPeriod(
        string $fromDate,
        string $toDate,
        int $branchId,
        int $counterpartyId
    ): array {
        $sql = "
            SELECT bd.*,
                   fb.branch_name AS from_branch,
                   tb.branch_name AS to_branch,
                   GREATEST(0, COALESCE(bd.total_value, 0) - COALESCE(bd.settlement_amount, 0)) AS outstanding
            FROM branch_demands bd
            JOIN branches fb ON fb.id = bd.from_branch_id
            JOIN branches tb ON tb.id = bd.to_branch_id
            WHERE bd.demand_date BETWEEN :from_date AND :to_date
              AND (
                  bd.from_branch_id = :branch_id OR bd.to_branch_id = :branch_id
              )
        ";
        if ($counterpartyId > 0) {
            $sql .= ' AND (bd.from_branch_id = :counterparty_id OR bd.to_branch_id = :counterparty_id)';
        }
        $sql .= ' ORDER BY bd.demand_date DESC, bd.id DESC';

        $this->db->query($sql);
        $this->db->bind(':from_date', $fromDate);
        $this->db->bind(':to_date', $toDate);
        $this->db->bind(':branch_id', $branchId);
        if ($counterpartyId > 0) {
            $this->db->bind(':counterparty_id', $counterpartyId);
        }

        return $this->db->resultSet();
    }

    private function getSettlementsInPeriod(
        string $fromDate,
        string $toDate,
        int $branchId,
        int $counterpartyId
    ): array {
        $sql = "
            SELECT
                bl.transaction_date,
                bl.reference_type,
                bl.reference_id,
                bl.credit AS amount,
                bl.remarks,
                bl.from_branch_id,
                bl.to_branch_id,
                tb.branch_name AS counterparty_name,
                CASE
                    WHEN bl.remarks LIKE 'Customer Payment%' THEN 'customer_payment'
                    WHEN bl.remarks LIKE 'Money Transfer%' THEN 'money_transfer'
                    ELSE 'ledger'
                END AS source_type,
                CONCAT(bl.reference_type, ' #', bl.reference_id) AS reference_label
            FROM branch_ledger bl
            JOIN branches tb ON tb.id = bl.to_branch_id
            WHERE bl.reference_type = 'demand_settlement'
              AND COALESCE(bl.is_reversed, 0) = 0
              AND bl.transaction_date BETWEEN :from_date AND :to_date
              AND bl.from_branch_id = :branch_id
              AND bl.credit > 0
        ";
        if ($counterpartyId > 0) {
            $sql .= ' AND bl.to_branch_id = :counterparty_id';
        }
        $sql .= ' ORDER BY bl.transaction_date DESC, bl.id DESC';

        $this->db->query($sql);
        $this->db->bind(':from_date', $fromDate);
        $this->db->bind(':to_date', $toDate);
        $this->db->bind(':branch_id', $branchId);
        if ($counterpartyId > 0) {
            $this->db->bind(':counterparty_id', $counterpartyId);
        }

        return $this->db->resultSet();
    }

    private function getFloorStockExposure(int $branchId, int $counterpartyId): array
    {
        $sql = "
            SELECT
                bd.demand_code,
                bd.demand_date,
                fb.branch_name AS from_branch,
                tb.branch_name AS to_branch,
                p.product_code,
                p.product_name,
                bdi.qty AS transferred_qty,
                bdi.cost_rate,
                bdi.to_warehouse_id,
                w.warehouse_name,
                COALESCE(ws.qty, 0) AS warehouse_qty,
                COALESCE(NULLIF(ws.avg_cost, 0), bdi.cost_rate) AS valuation_rate,
                LEAST(COALESCE(ws.qty, 0), bdi.qty)
                    * COALESCE(NULLIF(ws.avg_cost, 0), bdi.cost_rate) AS floor_value
            FROM branch_demand_items bdi
            JOIN branch_demands bd ON bd.id = bdi.branch_demand_id
            JOIN products p ON p.id = bdi.product_id
            JOIN branches fb ON fb.id = bd.from_branch_id
            JOIN branches tb ON tb.id = bd.to_branch_id
            LEFT JOIN warehouses w ON w.id = bdi.to_warehouse_id
            LEFT JOIN warehouse_stock ws
                ON ws.warehouse_id = bdi.to_warehouse_id AND ws.product_id = bdi.product_id
            WHERE bd.status = 'received'
              AND COALESCE(bd.is_reversed, 0) = 0
              AND bdi.to_warehouse_id IS NOT NULL
              AND COALESCE(ws.qty, 0) > 0.0001
              AND (bd.from_branch_id = :branch_id OR bd.to_branch_id = :branch_id2)
        ";

        if ($counterpartyId > 0) {
            $sql .= ' AND (bd.from_branch_id = :cp OR bd.to_branch_id = :cp2)';
        }
        $sql .= ' ORDER BY bd.demand_date DESC, bd.demand_code, p.product_code';

        $this->db->query($sql);
        $this->db->bind(':branch_id', $branchId);
        $this->db->bind(':branch_id2', $branchId);
        if ($counterpartyId > 0) {
            $this->db->bind(':cp', $counterpartyId);
            $this->db->bind(':cp2', $counterpartyId);
        }

        return $this->db->resultSet();
    }

    private function sumOutstandingAsDebtor(int $branchId, int $counterpartyId): float
    {
        $sql = "
            SELECT COALESCE(SUM(
                GREATEST(0, COALESCE(total_value, 0) - COALESCE(settlement_amount, 0))
            ), 0) AS total
            FROM branch_demands
            WHERE from_branch_id = :branch_id
              AND status = 'received'
              AND COALESCE(is_reversed, 0) = 0
        ";
        if ($counterpartyId > 0) {
            $sql .= ' AND to_branch_id = :counterparty_id';
        }
        $this->db->query($sql);
        $this->db->bind(':branch_id', $branchId);
        if ($counterpartyId > 0) {
            $this->db->bind(':counterparty_id', $counterpartyId);
        }

        return (float)($this->db->single()['total'] ?? 0);
    }

    private function sumOutstandingAsCreditor(int $branchId, int $counterpartyId): float
    {
        $sql = "
            SELECT COALESCE(SUM(
                GREATEST(0, COALESCE(total_value, 0) - COALESCE(settlement_amount, 0))
            ), 0) AS total
            FROM branch_demands
            WHERE to_branch_id = :branch_id
              AND status = 'received'
              AND COALESCE(is_reversed, 0) = 0
        ";
        if ($counterpartyId > 0) {
            $sql .= ' AND from_branch_id = :counterparty_id';
        }
        $this->db->query($sql);
        $this->db->bind(':branch_id', $branchId);
        if ($counterpartyId > 0) {
            $this->db->bind(':counterparty_id', $counterpartyId);
        }

        return (float)($this->db->single()['total'] ?? 0);
    }

    /**
     * Net owed by debtorBranch to creditorBranch from branch_ledger running balance.
     */
    private function ledgerNetOwed(int $debtorBranchId, int $creditorBranchId, bool $useRunningBalance): float
    {
        if ($debtorBranchId <= 0 || $creditorBranchId <= 0) {
            return 0.0;
        }

        if ($useRunningBalance) {
            $this->db->query("
                SELECT running_balance
                FROM branch_ledger
                WHERE from_branch_id = :debtor
                  AND to_branch_id = :creditor
                  AND COALESCE(is_reversed, 0) = 0
                  AND running_balance IS NOT NULL
                ORDER BY id DESC
                LIMIT 1
            ");
            $this->db->bind(':debtor', $debtorBranchId);
            $this->db->bind(':creditor', $creditorBranchId);
            $row = $this->db->single();
            if ($row) {
                return max(0.0, (float)$row['running_balance']);
            }
        }

        $this->db->query("
            SELECT
                COALESCE(SUM(CASE WHEN reference_type = 'demand_transfer' THEN debit - credit ELSE 0 END), 0)
                - COALESCE(SUM(CASE WHEN reference_type = 'demand_settlement' THEN credit - debit ELSE 0 END), 0) AS net
            FROM branch_ledger
            WHERE from_branch_id = :debtor
              AND to_branch_id = :creditor
              AND COALESCE(is_reversed, 0) = 0
        ");
        $this->db->bind(':debtor', $debtorBranchId);
        $this->db->bind(':creditor', $creditorBranchId);

        return max(0.0, (float)($this->db->single()['net'] ?? 0));
    }

    /** @return list<array> */
    private function getOutstandingByPair(int $branchId): array
    {
        $this->db->query("
            SELECT
                bd.to_branch_id AS counterparty_id,
                tb.branch_name AS counterparty_name,
                COUNT(*) AS demand_count,
                COALESCE(SUM(GREATEST(0, COALESCE(bd.total_value, 0) - COALESCE(bd.settlement_amount, 0))), 0) AS outstanding
            FROM branch_demands bd
            JOIN branches tb ON tb.id = bd.to_branch_id
            WHERE bd.from_branch_id = :branch_id
              AND bd.status = 'received'
              AND COALESCE(bd.is_reversed, 0) = 0
              AND COALESCE(bd.total_value, 0) > COALESCE(bd.settlement_amount, 0)
            GROUP BY bd.to_branch_id, tb.branch_name
            ORDER BY outstanding DESC
        ");
        $this->db->bind(':branch_id', $branchId);

        return $this->db->resultSet();
    }

    /**
     * Catalog list price fell below frozen demand cost (open received demands only).
     *
     * @return list<array<string, mixed>>
     */
    private function getCatalogPriceDropFlags(
        int $branchId,
        int $counterpartyId,
        int $demandIdOnly = 0
    ): array {
        $sql = "
            SELECT
                bd.id AS demand_id,
                bd.demand_code,
                bd.demand_date,
                fb.branch_name AS from_branch,
                tb.branch_name AS to_branch,
                p.product_code,
                p.product_name,
                bdi.cost_rate AS locked_rate,
                (
                    SELECT ph.default_rate
                    FROM product_price_history ph
                    WHERE ph.product_id = bdi.product_id
                    ORDER BY ph.effective_from DESC, ph.created_at DESC, ph.id DESC
                    LIMIT 1
                ) AS catalog_rate,
                ROUND(bdi.cost_rate - (
                    SELECT ph.default_rate
                    FROM product_price_history ph
                    WHERE ph.product_id = bdi.product_id
                    ORDER BY ph.effective_from DESC, ph.created_at DESC, ph.id DESC
                    LIMIT 1
                ), 2) AS rate_gap
            FROM branch_demand_items bdi
            JOIN branch_demands bd ON bd.id = bdi.branch_demand_id
            JOIN products p ON p.id = bdi.product_id
            JOIN branches fb ON fb.id = bd.from_branch_id
            JOIN branches tb ON tb.id = bd.to_branch_id
            WHERE bd.status = 'received'
              AND COALESCE(bd.is_reversed, 0) = 0
              AND bdi.cost_rate > 0
              AND COALESCE(bd.total_value, 0) > COALESCE(bd.settlement_amount, 0)
        ";
        if ($demandIdOnly > 0) {
            $sql .= ' AND bd.id = :demand_only';
        } elseif ($branchId > 0) {
            $sql .= ' AND (bd.from_branch_id = :branch_id OR bd.to_branch_id = :branch_id2)';
        }
        if ($counterpartyId > 0) {
            $sql .= ' AND (bd.from_branch_id = :cp OR bd.to_branch_id = :cp2)';
        }
        $sql .= "
            HAVING catalog_rate IS NOT NULL
               AND catalog_rate + :min_gap < locked_rate
            ORDER BY rate_gap DESC, bd.demand_date DESC
        ";

        $this->db->query($sql);
        $this->db->bind(':min_gap', self::PRICE_DROP_MIN_GAP);
        if ($demandIdOnly > 0) {
            $this->db->bind(':demand_only', $demandIdOnly);
        } elseif ($branchId > 0) {
            $this->db->bind(':branch_id', $branchId);
            $this->db->bind(':branch_id2', $branchId);
        }
        if ($counterpartyId > 0) {
            $this->db->bind(':cp', $counterpartyId);
            $this->db->bind(':cp2', $counterpartyId);
        }

        return $this->db->resultSet();
    }

    /**
     * Receiver-branch sales under locked inter-branch cost (challan stock from receive warehouse).
     *
     * @return list<array<string, mixed>>
     */
    private function getBelowLockedCostSales(
        string $fromDate,
        string $toDate,
        int $branchId,
        int $counterpartyId,
        int $demandIdOnly = 0
    ): array {
        $sql = "
            SELECT DISTINCT
                bd.id AS demand_id,
                bd.demand_code,
                si.id AS invoice_id,
                si.invoice_code,
                si.invoice_date,
                p.product_code,
                p.product_name,
                bdi.cost_rate AS locked_rate,
                sii.rate AS sale_rate,
                sii.qty AS sale_qty,
                ROUND(bdi.cost_rate - sii.rate, 2) AS rate_gap,
                fb.branch_name AS debtor_branch,
                tb.branch_name AS supplier_branch
            FROM branch_demand_items bdi
            JOIN branch_demands bd ON bd.id = bdi.branch_demand_id
            JOIN products p ON p.id = bdi.product_id
            JOIN branches fb ON fb.id = bd.from_branch_id
            JOIN branches tb ON tb.id = bd.to_branch_id
            JOIN sales_invoices si ON si.branch_id = bd.from_branch_id
            JOIN sales_invoice_items sii ON sii.sales_invoice_id = si.id
                AND sii.product_id = bdi.product_id
            JOIN sales_challans sc ON sc.sales_invoice_id = si.id
                AND COALESCE(sc.is_reversed, 0) = 0
            JOIN stock_transactions st ON st.reference_type = 'sales_challan'
                AND st.reference_id = sc.id
                AND st.product_id = bdi.product_id
                AND st.warehouse_id = bdi.to_warehouse_id
                AND st.qty < -0.0001
                AND COALESCE(st.is_reversed, 0) = 0
            WHERE bd.status = 'received'
              AND COALESCE(bd.is_reversed, 0) = 0
              AND bdi.to_warehouse_id IS NOT NULL
              AND si.invoice_date >= bd.demand_date
              AND si.invoice_date BETWEEN :from_date AND :to_date
              AND si.status IN ('godown_issued', 'challan_completed')
              AND COALESCE(si.is_reversed, 0) = 0
              AND sii.rate + :below_gap < bdi.cost_rate
        ";
        if ($demandIdOnly > 0) {
            $sql .= ' AND bd.id = :demand_only';
        } elseif ($branchId > 0) {
            $sql .= ' AND (bd.from_branch_id = :branch_id OR bd.to_branch_id = :branch_id2)';
        }
        if ($counterpartyId > 0) {
            $sql .= ' AND (bd.from_branch_id = :cp OR bd.to_branch_id = :cp2)';
        }
        $sql .= ' ORDER BY si.invoice_date DESC, si.invoice_code';

        $this->db->query($sql);
        $this->db->bind(':from_date', $fromDate);
        $this->db->bind(':to_date', $toDate);
        $this->db->bind(':below_gap', self::BELOW_COST_MIN_GAP);
        if ($demandIdOnly > 0) {
            $this->db->bind(':demand_only', $demandIdOnly);
        } elseif ($branchId > 0) {
            $this->db->bind(':branch_id', $branchId);
            $this->db->bind(':branch_id2', $branchId);
        }
        if ($counterpartyId > 0) {
            $this->db->bind(':cp', $counterpartyId);
            $this->db->bind(':cp2', $counterpartyId);
        }

        return $this->db->resultSet();
    }

    /**
     * Open principal on received demands older than threshold.
     *
     * @return list<array<string, mixed>>
     */
    private function getStaleOutstandingDemands(
        int $branchId,
        int $counterpartyId,
        int $demandIdOnly = 0
    ): array {
        $sql = "
            SELECT
                bd.id,
                bd.demand_code,
                bd.demand_date,
                fb.branch_name AS from_branch,
                tb.branch_name AS to_branch,
                CONCAT(fb.branch_name, ' → ', tb.branch_name) AS counterparty_name,
                GREATEST(0, COALESCE(bd.total_value, 0) - COALESCE(bd.settlement_amount, 0)) AS outstanding,
                DATEDIFF(CURDATE(), bd.demand_date) AS age_days
            FROM branch_demands bd
            JOIN branches fb ON fb.id = bd.from_branch_id
            JOIN branches tb ON tb.id = bd.to_branch_id
            WHERE bd.status = 'received'
              AND COALESCE(bd.is_reversed, 0) = 0
              AND COALESCE(bd.total_value, 0) > COALESCE(bd.settlement_amount, 0)
              AND DATEDIFF(CURDATE(), bd.demand_date) >= :stale_days
        ";
        if ($demandIdOnly > 0) {
            $sql .= ' AND bd.id = :demand_only';
        } elseif ($branchId > 0) {
            $sql .= ' AND (bd.from_branch_id = :branch_id OR bd.to_branch_id = :branch_id2)';
        }
        if ($counterpartyId > 0) {
            $sql .= ' AND (bd.from_branch_id = :cp OR bd.to_branch_id = :cp2)';
        }
        $sql .= ' ORDER BY age_days DESC, outstanding DESC';

        $this->db->query($sql);
        $this->db->bind(':stale_days', self::STALE_OUTSTANDING_DAYS);
        if ($demandIdOnly > 0) {
            $this->db->bind(':demand_only', $demandIdOnly);
        } elseif ($branchId > 0) {
            $this->db->bind(':branch_id', $branchId);
            $this->db->bind(':branch_id2', $branchId);
        }
        if ($counterpartyId > 0) {
            $this->db->bind(':cp', $counterpartyId);
            $this->db->bind(':cp2', $counterpartyId);
        }

        return $this->db->resultSet();
    }
}
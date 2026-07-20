<?php
// app/models/WarehouseTransferAuditModel.php

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';
require_once __DIR__ . '/WarehouseTransferModel.php';
require_once __DIR__ . '/BranchIntercompanyAuditModel.php';

class WarehouseTransferAuditModel
{
    protected Database $db;
    protected ?int $branchId;

    public function __construct()
    {
        $this->db = new Database();
        $this->branchId = Helper::sessionBranchId();
    }

    public function runHealthChecks(): array
    {
        $sections = [
            $this->sectionSameBranch(),
            ...(new BranchIntercompanyAuditModel())->sharedInterbranchSections(),
            $this->sectionStockGl(),
            $this->sectionDataIntegrity(),
        ];

        $pass = $warn = $fail = $info = 0;
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                switch ($item['status']) {
                    case 'pass': $pass++; break;
                    case 'warn': $warn++; break;
                    case 'fail': $fail++; break;
                    default: $info++;
                }
            }
        }

        return [
            'sections'  => $sections,
            'summary'   => [
                'pass'  => $pass,
                'warn'  => $warn,
                'fail'  => $fail,
                'info'  => $info,
                'total' => $pass + $warn + $fail + $info,
            ],
            'ran_at'    => date('Y-m-d H:i:s'),
            'branch_id' => $this->branchId,
        ];
    }

    public function runTransferChecks(int $transferId): array
    {
        $t = (new WarehouseTransferModel())->getTransferById($transferId);
        if (!$t) {
            return ['items' => [], 'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 0]];
        }

        $items = [];
        $fromBranch = (int)($t['from_branch_id'] ?? 0);
        $toBranch   = (int)($t['to_branch_id'] ?? 0);
        $isReversed = !empty($t['is_reversed']);
        $demandId   = (int)($t['branch_demand_id'] ?? 0);
        $total      = (float)($t['total_amount'] ?? 0);
        $sameBranch = $fromBranch > 0 && $fromBranch === $toBranch;

        $items[] = $this->item(
            'same_branch',
            'auto',
            'Same-branch route',
            'From and to warehouses must belong to the same branch.',
            $sameBranch ? 'pass' : 'fail',
            $sameBranch
                ? ($t['from_branch'] ?? 'Branch')
                : (($t['from_branch'] ?? '') . ' → ' . ($t['to_branch'] ?? ''))
        );

        $movements = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_transactions
            WHERE reference_type = 'warehouse_transfer' AND reference_id = :id
              AND COALESCE(is_reversed, 0) = 0
        ", [':id' => $transferId]);

        $items[] = $this->item(
            'stock',
            'auto',
            'Stock movements',
            'Out from source WH and in to destination WH.',
            $movements > 0 ? 'pass' : ($isReversed ? 'info' : 'fail'),
            $movements > 0 ? "{$movements} active row(s)" : 'Missing'
        );

        if ($isReversed) {
            $items[] = $this->item(
                'reversed',
                'auto',
                'Transfer reversed',
                'Stock restored via reversal audit rows; transfer marked reversed.',
                !empty($t['reverse_reason']) ? 'pass' : 'warn',
                trim(($t['reverse_reason'] ?? '') . ' '
                    . (!empty($t['reversed_by_name']) ? 'by ' . $t['reversed_by_name'] : ''))
            );
        } elseif ($demandId === 0) {
            $items[] = $this->item(
                'can_reverse',
                'reference',
                'Reversal available',
                'Use Reverse on this page or list to undo stock (reason required).',
                'info',
                'Restores qty to source WH and removes from destination WH'
            );
        }

        if ($demandId > 0) {
            $items[] = $this->item(
                'demand_gl',
                'info',
                'Branch demand link',
                'Created from branch demand — use Branch Demand for cross-branch GL.',
                'pass',
                $t['branch_demand_code'] ?? ('Demand #' . $demandId)
            );
        } elseif ($sameBranch && !$isReversed) {
            $hasGl = !empty($t['journal_entry_id']) || !empty($t['journal_entry_id_debtor']);
            $items[] = $this->item(
                'gl_internal',
                'auto',
                'No GL on internal transfer',
                'Same-branch moves update stock only; no inter-branch journals.',
                $hasGl ? 'warn' : 'pass',
                $hasGl ? 'Unexpected journal link present' : 'Stock only (expected)'
            );
        }

        $pass = $warn = $fail = 0;
        foreach ($items as $it) {
            match ($it['status']) {
                'pass' => $pass++,
                'warn' => $warn++,
                'fail' => $fail++,
                default => null,
            };
        }

        return ['items' => $items, 'summary' => ['pass' => $pass, 'warn' => $warn, 'fail' => $fail]];
    }

    private function sectionSameBranch(): array
    {
        $crossBranchManual = $this->scalarCount("
            SELECT COUNT(*) AS c FROM warehouse_transfers wt
            JOIN warehouses fw ON fw.id = wt.from_warehouse_id
            JOIN warehouses tw ON tw.id = wt.to_warehouse_id
            WHERE fw.branch_id <> tw.branch_id
              AND COALESCE(wt.branch_demand_id, 0) = 0
              AND COALESCE(wt.is_reversed, 0) = 0
              {$this->branchInvolvementFilter()}
        ");

        return [
            'id'    => 'same_branch',
            'title' => 'Same-branch rules',
            'icon'  => 'fa-warehouse',
            'items' => [
                $this->item('rule_sb', 'reference', 'Your branch warehouses only', 'Move stock between two different warehouses in the same branch.', 'info'),
                $this->item('rule_demand', 'reference', 'Cross-branch stock', 'Use Branch Demand for transfers to another branch.', 'info', null, true, 'BranchDemand'),
                $this->item(
                    'cross_branch_manual',
                    'auto',
                    'Invalid cross-branch manual transfers',
                    'Standalone transfers where from/to branches differ (should use Branch Demand).',
                    $crossBranchManual === 0 ? 'pass' : 'fail',
                    $crossBranchManual === 0 ? 'None' : "{$crossBranchManual} row(s)"
                ),
            ],
        ];
    }

    private function sectionStockGl(): array
    {
        $noStock = $this->scalarCount("
            SELECT COUNT(*) AS c FROM warehouse_transfers wt
            JOIN warehouses fw ON fw.id = wt.from_warehouse_id
            JOIN warehouses tw ON tw.id = wt.to_warehouse_id
            WHERE fw.branch_id = tw.branch_id
              AND COALESCE(wt.is_reversed, 0) = 0
              AND COALESCE(wt.branch_demand_id, 0) = 0
              AND COALESCE(wt.total_amount, 0) >= 0.01
              AND NOT EXISTS (
                  SELECT 1 FROM stock_transactions st
                  WHERE st.reference_type = 'warehouse_transfer'
                    AND st.reference_id = wt.id
                    AND COALESCE(st.is_reversed, 0) = 0
              )
              {$this->branchInvolvementFilter()}
        ");

        return [
            'id'    => 'stock_gl',
            'title' => 'Stock',
            'icon'  => 'fa-balance-scale',
            'items' => [
                $this->item('posted_stock', 'auto', 'Transfers have stock rows', 'warehouse_transfer movements for same-branch transfers.', $noStock === 0 ? 'pass' : 'fail', $noStock === 0 ? 'OK' : "{$noStock} missing"),
            ],
        ];
    }

    private function sectionDataIntegrity(): array
    {
        $zeroRate = $this->scalarCount("
            SELECT COUNT(*) AS c FROM warehouse_transfer_items wti
            JOIN warehouse_transfers wt ON wt.id = wti.warehouse_transfer_id
            WHERE wti.qty > 0 AND COALESCE(wti.rate, 0) = 0
              AND COALESCE(wt.is_reversed, 0) = 0
              {$this->branchInvolvementFilter('wt')}
        ");

        return [
            'id'    => 'integrity',
            'title' => 'Data quality',
            'icon'  => 'fa-database',
            'items' => [
                $this->item('zero_rate', 'auto', 'Lines with zero rate', 'Should use warehouse moving average cost.', $zeroRate === 0 ? 'pass' : 'warn', $zeroRate === 0 ? 'OK' : "{$zeroRate} line(s)"),
            ],
        ];
    }

    private function item(
        string $id,
        string $type,
        string $title,
        string $expected,
        string $status,
        ?string $detail = null,
        bool $reference = false,
        ?string $route = null
    ): array {
        return [
            'id'        => $id,
            'type'      => $type,
            'title'     => $title,
            'expected'  => $expected,
            'status'    => $status,
            'detail'    => $detail ?? '',
            'reference' => $reference,
            'url'       => $route ? (defined('BASE_URL') ? BASE_URL : '') . $route : null,
        ];
    }

    private function scalarCount(string $sql, array $bind = []): int
    {
        try {
            $this->db->query($sql);
            foreach ($bind as $k => $v) {
                $this->db->bind($k, $v);
            }
            return (int)($this->db->single()['c'] ?? 0);
        } catch (Exception $e) {
            error_log('WarehouseTransferAuditModel: ' . $e->getMessage());
            return -1;
        }
    }

    private function branchInvolvementFilter(string $wtAlias = 'wt'): string
    {
        if (!$this->branchId) {
            return '';
        }
        return " AND EXISTS (
            SELECT 1 FROM warehouses fw
            WHERE fw.id = {$wtAlias}.from_warehouse_id
              AND fw.branch_id = " . (int)$this->branchId . '
        )';
    }
}
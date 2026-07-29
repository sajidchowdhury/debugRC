<?php

namespace App\Services\Stock;

use App\Models\WarehouseTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Warehouse Transfer Audit Service — Phase 4.
 *
 * Ported from legacy WarehouseTransferAuditModel.php. Provides two
 * complementary audit functions:
 *
 *   1. runHealthChecks() — branch-scoped data integrity checks
 *      (same-branch violations, missing stock movements, zero-rate items,
 *       GL integrity). These are the same checks the legacy system runs
 *      on its "Audit Checklist" page.
 *
 *   2. runTransferChecks($transferId) — per-transfer checks
 *      (same-branch, stock movements, reversal, demand link, GL).
 *      These are the checks the legacy system shows on the transfer detail page.
 *
 *   3. reconcileStock() — verify the fundamental stock invariant:
 *      For every warehouse W and product P:
 *        SUM(stock_transactions.qty) = warehouse_stock.qty
 *      This can be run as a scheduled job or on-demand.
 *
 * All methods are read-only (no side effects). They query the database
 * and return structured arrays that the controller can pass to views.
 */
class WarehouseTransferAuditService
{
    /**
     * Run all health checks for the current branch (or all branches for admin).
     *
     * @param int|null $branchId  If null, check all branches.
     * @return array{sections: array, summary: array, ran_at: string, branch_id: int|null}
     */
    public function runHealthChecks(?int $branchId = null): array
    {
        $sections = [
            $this->sectionSameBranch($branchId),
            $this->sectionStockMovements($branchId),
            $this->sectionDataQuality($branchId),
            $this->sectionGlIntegrity($branchId),
        ];

        $pass = $warn = $fail = $info = 0;
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                switch ($item['status']) {
                    case 'pass':  $pass++;  break;
                    case 'warn':  $warn++;  break;
                    case 'fail':  $fail++;  break;
                    default:      $info++;  break;
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
            'ran_at'    => now()->toDateTimeString(),
            'branch_id' => $branchId,
        ];
    }

    /**
     * Run per-transfer checks for a specific transfer.
     *
     * @param int $transferId
     * @return array{items: array, summary: array}
     */
    public function runTransferChecks(int $transferId): array
    {
        $transfer = WarehouseTransfer::with(['fromWarehouse.branch', 'toWarehouse.branch', 'items'])
            ->find($transferId);

        if (!$transfer) {
            return ['items' => [], 'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0]];
        }

        $items = [];
        $fromBranch = (int) $transfer->from_branch_id;
        $toBranch   = (int) $transfer->to_branch_id;
        $isReversed = (bool) $transfer->is_reversed;
        $demandId   = (int) ($transfer->branch_demand_id ?? 0);
        $sameBranch = $fromBranch > 0 && $fromBranch === $toBranch;

        // 1. Same-branch check
        $items[] = $this->item(
            'same_branch',
            'auto',
            'Same-branch route',
            'From and to warehouses must belong to the same branch.',
            $sameBranch ? 'pass' : 'fail',
            $sameBranch
                ? ($transfer->fromBranch->branch_name ?? 'Branch')
                : (($transfer->fromBranch->branch_name ?? '') . ' → ' . ($transfer->toBranch->branch_name ?? ''))
        );

        // 2. Stock movements check
        $movements = DB::table('stock_transactions')
            ->where('reference_type', 'warehouse_transfer')
            ->where('reference_id', $transferId)
            ->where('is_reversed', false)
            ->count();

        $items[] = $this->item(
            'stock',
            'auto',
            'Stock movements',
            'Out from source WH and in to destination WH.',
            $movements > 0 ? 'pass' : ($isReversed ? 'info' : ($transfer->isDraft() ? 'info' : 'fail')),
            $movements > 0 ? "{$movements} active row(s)" : ($transfer->isDraft() ? 'Draft — no movements yet' : 'Missing')
        );

        // 3. Reversal check
        if ($isReversed) {
            $items[] = $this->item(
                'reversed',
                'auto',
                'Transfer reversed',
                'Stock restored via reversal; transfer marked reversed.',
                !empty($transfer->reverse_reason) ? 'pass' : 'warn',
                trim(($transfer->reverse_reason ?? '') . ' '
                    . ($transfer->reversed_by ? 'by user #' . $transfer->reversed_by : ''))
            );
        } elseif ($demandId === 0 && $transfer->isConfirmed()) {
            $items[] = $this->item(
                'can_reverse',
                'reference',
                'Reversal available',
                'Use Cancel on this page to undo stock (reason required).',
                'info',
                'Restores qty to source WH and removes from destination WH'
            );
        }

        // 4. Branch demand link check
        if ($demandId > 0) {
            $items[] = $this->item(
                'demand_gl',
                'info',
                'Branch demand link',
                'Created from branch demand — use Branch Demand for cross-branch GL.',
                'pass',
                'Demand #' . $demandId
            );
        } elseif ($sameBranch && !$isReversed && $transfer->isConfirmed()) {
            $hasGl = !empty($transfer->journal_entry_id) || !empty($transfer->journal_entry_id_debtor);
            $items[] = $this->item(
                'gl_internal',
                'auto',
                'No GL on internal transfer',
                'Same-branch moves update stock only; no inter-branch journals.',
                $hasGl ? 'warn' : 'pass',
                $hasGl ? 'Unexpected journal link present' : 'Stock only (expected)'
            );
        }

        // 5. Zero-rate items check
        $zeroRateCount = DB::table('warehouse_transfer_items')
            ->where('warehouse_transfer_id', $transferId)
            ->where('qty', '>', 0)
            ->where(function ($q) {
                $q->whereNull('rate')->orWhere('rate', 0);
            })
            ->count();

        if ($zeroRateCount > 0) {
            $items[] = $this->item(
                'zero_rate',
                'auto',
                'Lines with zero rate',
                'Should use warehouse moving average cost.',
                'warn',
                "{$zeroRateCount} line(s)"
            );
        }

        $pass = $warn = $fail = $info = 0;
        foreach ($items as $it) {
            match ($it['status']) {
                'pass' => $pass++,
                'warn' => $warn++,
                'fail' => $fail++,
                default => $info++,
            };
        }

        return ['items' => $items, 'summary' => ['pass' => $pass, 'warn' => $warn, 'fail' => $fail, 'info' => $info]];
    }

    /**
     * Stock reconciliation: verify the fundamental invariant.
     *
     * For every warehouse W and product P:
     *   SUM(stock_transactions.qty) WHERE warehouse_id=W AND product_id=P AND is_reversed=false
     *   = warehouse_stock.qty WHERE warehouse_id=W AND product_id=P
     *
     * Returns an array of mismatches. Empty array = all good.
     *
     * @param int|null $branchId  If null, check all branches.
     * @param int $limit  Max mismatches to return.
     * @return array{mismatches: array, checked: int, mismatched: int, ran_at: string}
     */
    public function reconcileStock(?int $branchId = null, int $limit = 100): array
    {
        $branchFilter = '';
        $bindings = [];

        if ($branchId !== null && $branchId > 0) {
            $branchFilter = ' AND ws.warehouse_id IN (SELECT id FROM warehouses WHERE branch_id = ?)';
            $bindings[] = $branchId;
        }

        $sql = "
            SELECT
                ws.warehouse_id,
                ws.product_id,
                ws.qty AS stock_qty,
                COALESCE(st.tx_sum, 0) AS transaction_sum,
                ws.qty - COALESCE(st.tx_sum, 0) AS difference,
                w.warehouse_name,
                p.product_name,
                w.branch_id
            FROM warehouse_stock ws
            JOIN warehouses w ON w.id = ws.warehouse_id
            JOIN products p ON p.id = ws.product_id
            LEFT JOIN (
                SELECT warehouse_id, product_id, SUM(qty) AS tx_sum
                FROM stock_transactions
                WHERE is_reversed = false
                GROUP BY warehouse_id, product_id
            ) st ON st.warehouse_id = ws.warehouse_id AND st.product_id = ws.product_id
            WHERE ABS(ws.qty - COALESCE(st.tx_sum, 0)) > 0.01
            {$branchFilter}
            ORDER BY ABS(ws.qty - COALESCE(st.tx_sum, 0)) DESC
            LIMIT ?
        ";
        $bindings[] = $limit;

        $mismatches = DB::select($sql, $bindings);

        // Count total rows checked
        $countSql = "
            SELECT COUNT(*) AS c FROM warehouse_stock ws
            JOIN warehouses w ON w.id = ws.warehouse_id
            WHERE 1=1
            {$branchFilter}
        ";
        $checked = (int) DB::selectOne($countSql, array_slice($bindings, 0, -1))->c ?? 0;

        return [
            'mismatches' => $mismatches,
            'checked'    => $checked,
            'mismatched' => count($mismatches),
            'ran_at'     => now()->toDateTimeString(),
        ];
    }

    // ========================================================================
    // Section builders (for runHealthChecks)
    // ========================================================================

    /**
     * Same-branch rule checks.
     */
    private function sectionSameBranch(?int $branchId): array
    {
        $branchFilter = $this->branchInvolvementFilter($branchId);

        $crossBranchManual = DB::selectOne("
            SELECT COUNT(*) AS c FROM warehouse_transfers wt
            JOIN warehouses fw ON fw.id = wt.from_warehouse_id
            JOIN warehouses tw ON tw.id = wt.to_warehouse_id
            WHERE fw.branch_id <> tw.branch_id
              AND COALESCE(wt.branch_demand_id, 0) = 0
              AND COALESCE(wt.is_reversed, false) = false
              AND wt.deleted_at IS NULL
              {$branchFilter}
        ")->c ?? 0;

        return [
            'id'    => 'same_branch',
            'title' => 'Same-branch rules',
            'icon'  => 'fa-warehouse',
            'items' => [
                $this->item('rule_sb', 'reference', 'Your branch warehouses only', 'Move stock between two different warehouses in the same branch.', 'info'),
                $this->item('rule_demand', 'reference', 'Cross-branch stock', 'Use Branch Demand for transfers to another branch.', 'info'),
                $this->item(
                    'cross_branch_manual',
                    'auto',
                    'Invalid cross-branch manual transfers',
                    'Standalone transfers where from/to branches differ (should use Branch Demand).',
                    $crossBranchManual == 0 ? 'pass' : 'fail',
                    $crossBranchManual == 0 ? 'None' : "{$crossBranchManual} row(s)"
                ),
            ],
        ];
    }

    /**
     * Stock movements checks.
     */
    private function sectionStockMovements(?int $branchId): array
    {
        $branchFilter = $this->branchInvolvementFilter($branchId);

        $noStock = DB::selectOne("
            SELECT COUNT(*) AS c FROM warehouse_transfers wt
            JOIN warehouses fw ON fw.id = wt.from_warehouse_id
            JOIN warehouses tw ON tw.id = wt.to_warehouse_id
            WHERE fw.branch_id = tw.branch_id
              AND COALESCE(wt.is_reversed, false) = false
              AND COALESCE(wt.branch_demand_id, 0) = 0
              AND wt.status = 'confirmed'
              AND wt.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM stock_transactions st
                  WHERE st.reference_type = 'warehouse_transfer'
                    AND st.reference_id = wt.id
                    AND COALESCE(st.is_reversed, false) = false
              )
              {$branchFilter}
        ")->c ?? 0;

        return [
            'id'    => 'stock_gl',
            'title' => 'Stock',
            'icon'  => 'fa-balance-scale',
            'items' => [
                $this->item(
                    'posted_stock',
                    'auto',
                    'Confirmed transfers have stock rows',
                    'warehouse_transfer movements for confirmed same-branch transfers.',
                    $noStock == 0 ? 'pass' : 'fail',
                    $noStock == 0 ? 'OK' : "{$noStock} missing"
                ),
            ],
        ];
    }

    /**
     * Data quality checks.
     */
    private function sectionDataQuality(?int $branchId): array
    {
        $branchFilter = $this->branchInvolvementFilter($branchId, 'wt');

        $zeroRate = DB::selectOne("
            SELECT COUNT(*) AS c FROM warehouse_transfer_items wti
            JOIN warehouse_transfers wt ON wt.id = wti.warehouse_transfer_id
            WHERE wti.qty > 0 AND COALESCE(wti.rate, 0) = 0
              AND COALESCE(wt.is_reversed, false) = false
              AND wt.deleted_at IS NULL
              {$branchFilter}
        ")->c ?? 0;

        return [
            'id'    => 'integrity',
            'title' => 'Data quality',
            'icon'  => 'fa-database',
            'items' => [
                $this->item(
                    'zero_rate',
                    'auto',
                    'Lines with zero rate',
                    'Should use warehouse moving average cost.',
                    $zeroRate == 0 ? 'pass' : 'warn',
                    $zeroRate == 0 ? 'OK' : "{$zeroRate} line(s)"
                ),
            ],
        ];
    }

    /**
     * GL integrity checks.
     * Same-branch transfers should NOT have GL journals.
     */
    private function sectionGlIntegrity(?int $branchId): array
    {
        $branchFilter = $this->branchInvolvementFilter($branchId, 'wt');

        $sameBranchWithGl = DB::selectOne("
            SELECT COUNT(*) AS c FROM warehouse_transfers wt
            JOIN warehouses fw ON fw.id = wt.from_warehouse_id
            JOIN warehouses tw ON tw.id = wt.to_warehouse_id
            WHERE fw.branch_id = tw.branch_id
              AND COALESCE(wt.branch_demand_id, 0) = 0
              AND COALESCE(wt.is_reversed, false) = false
              AND wt.deleted_at IS NULL
              AND (wt.journal_entry_id IS NOT NULL OR wt.journal_entry_id_debtor IS NOT NULL)
              {$branchFilter}
        ")->c ?? 0;

        return [
            'id'    => 'gl_integrity',
            'title' => 'GL integrity',
            'icon'  => 'fa-book',
            'items' => [
                $this->item(
                    'same_branch_no_gl',
                    'auto',
                    'Same-branch transfers have no GL journals',
                    'Same-branch transfers are stock-only; no intercompany journals expected.',
                    $sameBranchWithGl == 0 ? 'pass' : 'warn',
                    $sameBranchWithGl == 0 ? 'OK' : "{$sameBranchWithGl} unexpected journal(s)"
                ),
            ],
        ];
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * Build a single audit check item.
     */
    private function item(
        string $id,
        string $type,
        string $title,
        string $expected,
        string $status,
        ?string $detail = null
    ): array {
        return [
            'id'       => $id,
            'type'     => $type,
            'title'    => $title,
            'expected' => $expected,
            'status'   => $status,
            'detail'   => $detail ?? '',
        ];
    }

    /**
     * Build a branch-involvement SQL filter fragment.
     * Only includes transfers where the given branch is the from-branch.
     */
    private function branchInvolvementFilter(?int $branchId, string $wtAlias = 'wt'): string
    {
        if (!$branchId) {
            return '';
        }
        return " AND EXISTS (
            SELECT 1 FROM warehouses fw
            WHERE fw.id = {$wtAlias}.from_warehouse_id
              AND fw.branch_id = " . (int) $branchId . '
        )';
    }
}

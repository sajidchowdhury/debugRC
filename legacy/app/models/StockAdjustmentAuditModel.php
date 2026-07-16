<?php
// app/models/StockAdjustmentAuditModel.php — audit checklist (aligned with Stock Take Phase 4)

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';
require_once __DIR__ . '/StockAdjustmentModel.php';

class StockAdjustmentAuditModel
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
            $this->sectionWorkflow(),
            $this->sectionGlJournalLinks(),
            $this->sectionLedgerNature(),
            $this->sectionStockGl(),
            $this->sectionDataIntegrity(),
            $this->sectionOperations(),
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
            'missing_adjustment_journals' => $this->getAdjustmentsMissingJournalRows(),
            'missing_damage_journals'     => $this->getDamageMissingJournalRows(),
        ];
    }

    public function runAdjustmentChecks(int $adjustmentId): array
    {
        $adj = (new StockAdjustmentModel())->getAdjustmentById($adjustmentId);
        if (!$adj) {
            return ['items' => [], 'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 0]];
        }

        if ($this->branchId && (int)($adj['branch_id'] ?? 0) !== $this->branchId) {
            return [
                'items' => [
                    $this->item('branch', 'fail', 'Branch access', 'Adjustment is outside your branch.', 'fail', 'Not allowed'),
                ],
                'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 1],
            ];
        }

        $items = [];
        $isReversed = !empty($adj['is_reversed']);
        $totalAmount = (float)($adj['total_amount'] ?? 0);
        $type = $adj['adjustment_type'] ?? '';

        $movements = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_transactions
            WHERE reference_type = 'adjustment' AND reference_id = :id
              AND COALESCE(is_reversed, 0) = 0
        ", [':id' => $adjustmentId]);

        $items[] = $this->item(
            'stock_mv',
            'auto',
            'Stock movements',
            'Active adjustment should have stock_take-style audit rows.',
            $movements > 0 ? 'pass' : ($isReversed ? 'info' : 'fail'),
            $movements > 0 ? "{$movements} movement(s)" : 'Missing movements'
        );

        $journalId = (int)($adj['journal_entry_id'] ?? 0);
        $needsGl = $totalAmount >= 0.01 && !$isReversed;
        $items[] = $this->item(
            'gl',
            'auto',
            'GL journal',
            $type === 'decrease'
                ? 'Decrease: Dr shrinkage / Cr inventory when value > 0.'
                : 'Increase: Dr inventory / Cr surplus when value > 0.',
            !$needsGl || $journalId > 0 ? 'pass' : 'warn',
            $journalId > 0 ? "Journal #{$journalId}" : ($needsGl ? 'Missing journal (run migration 025?)' : 'N/A (zero value)')
        );

        if ($isReversed) {
            $items[] = $this->item(
                'reversed',
                'auto',
                'Reversed',
                'Stock and linked GL should be undone.',
                'info',
                $adj['reverse_reason'] ?? ''
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

    private function sectionWorkflow(): array
    {
        return [
            'id'    => 'workflow',
            'title' => 'Manual adjustment flow',
            'icon'  => 'fa-route',
            'items' => [
                $this->item('wf_immediate', 'reference', 'Immediate post', 'Save applies stock + GL in one step (unlike Stock Take count-then-post).', 'info'),
                $this->item('wf_st', 'reference', 'Physical counts', 'Use Stock Take for warehouse-wide counts; use Adjustment for damage, found stock, corrections.', 'info', null, true, 'StockTake'),
            ],
        ];
    }

    private function sectionGlJournalLinks(): array
    {
        return [
            'id'    => 'gl_links',
            'title' => 'GL journal link columns',
            'icon'  => 'fa-link',
            'items' => [
                $this->item(
                    'gl_col_adj',
                    'reference',
                    'stock_adjustments.journal_entry_id',
                    'Decrease → Dr shrinkage / Cr inventory; increase → Dr inventory / Cr surplus. View on StockAdjustment/details/{id}.',
                    'info',
                    null,
                    true,
                    'StockAdjustment'
                ),
                $this->item(
                    'gl_col_st',
                    'reference',
                    'stock_take_sessions.journal_entry_id',
                    'Physical count variances. View on StockTake/details/{id}.',
                    'info',
                    null,
                    true,
                    'StockTake'
                ),
                $this->item(
                    'gl_col_dmg',
                    'reference',
                    'damage_invoices.journal_entry_id',
                    'Write-off GL. View on Damage/details/{id}.',
                    'info',
                    null,
                    true,
                    'Damage'
                ),
            ],
        ];
    }

    private function sectionLedgerNature(): array
    {
        $shrinkageLedgers = $this->scalarCount("
            SELECT COUNT(*) AS c FROM ledgers
            WHERE ledger_nature = 'inventory_shrinkage' AND is_active = 1
        ");
        $surplusLedgers = $this->scalarCount("
            SELECT COUNT(*) AS c FROM ledgers
            WHERE ledger_nature = 'inventory_surplus' AND is_active = 1
        ");

        return [
            'id'    => 'ledger_nature',
            'title' => 'Shrinkage & surplus ledgers (migration 024)',
            'icon'  => 'fa-book',
            'items' => [
                $this->item(
                    'nat_shrink',
                    'auto',
                    'inventory_shrinkage ledger exists',
                    'Decrease adjustments and damage write-offs debit this nature.',
                    $shrinkageLedgers > 0 ? 'pass' : 'fail',
                    $shrinkageLedgers > 0 ? "{$shrinkageLedgers} active ledger(s)" : 'Missing — run migration 024'
                ),
                $this->item(
                    'nat_surplus',
                    'auto',
                    'inventory_surplus ledger exists',
                    'Increase adjustments credit this nature.',
                    $surplusLedgers > 0 ? 'pass' : 'fail',
                    $surplusLedgers > 0 ? "{$surplusLedgers} active ledger(s)" : 'Missing — run migration 024'
                ),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAdjustmentsMissingJournalRows(int $limit = 15): array
    {
        try {
            $this->db->query("
                SELECT sa.id, sa.adjustment_code, sa.adjustment_date, sa.total_amount, sa.adjustment_type
                FROM stock_adjustments sa
                WHERE COALESCE(sa.is_reversed, 0) = 0
                  AND sa.total_amount >= 0.01
                  AND COALESCE(sa.journal_entry_id, 0) = 0
                  {$this->branchFilter('sa.warehouse_id')}
                ORDER BY sa.adjustment_date DESC
                LIMIT " . (int)$limit
            );

            return $this->db->resultSet() ?: [];
        } catch (Exception $e) {
            error_log('StockAdjustmentAuditModel::getAdjustmentsMissingJournalRows: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDamageMissingJournalRows(int $limit = 15): array
    {
        try {
            $this->db->query("
                SELECT di.id, di.damage_code, di.damage_date, di.total_value
                FROM damage_invoices di
                WHERE COALESCE(di.is_reversed, 0) = 0
                  AND di.total_value >= 0.01
                  AND COALESCE(di.journal_entry_id, 0) = 0
                  {$this->branchFilter('di.warehouse_id')}
                ORDER BY di.damage_date DESC
                LIMIT " . (int)$limit
            );

            return $this->db->resultSet() ?: [];
        } catch (Exception $e) {
            error_log('StockAdjustmentAuditModel::getDamageMissingJournalRows: ' . $e->getMessage());

            return [];
        }
    }

    private function sectionStockGl(): array
    {
        $noMv = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_adjustments sa
            WHERE COALESCE(sa.is_reversed, 0) = 0
              AND sa.total_amount >= 0.01
              AND NOT EXISTS (
                  SELECT 1 FROM stock_transactions st
                  WHERE st.reference_type = 'adjustment'
                    AND st.reference_id = sa.id
                    AND COALESCE(st.is_reversed, 0) = 0
              )
              {$this->branchFilter('sa.warehouse_id')}
        ");

        $noGl = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_adjustments sa
            WHERE COALESCE(sa.is_reversed, 0) = 0
              AND sa.total_amount >= 0.01
              AND COALESCE(sa.journal_entry_id, 0) = 0
              {$this->branchFilter('sa.warehouse_id')}
        ");

        $revGl = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_adjustments sa
            WHERE COALESCE(sa.is_reversed, 0) = 1
              AND COALESCE(sa.journal_entry_id, 0) > 0
              AND NOT EXISTS (
                  SELECT 1 FROM journal_entries je
                  WHERE je.id = sa.journal_entry_id AND COALESCE(je.is_reversed, 0) = 1
              )
              {$this->branchFilter('sa.warehouse_id')}
        ");

        return [
            'id'    => 'stock_gl',
            'title' => 'Stock & GL alignment',
            'icon'  => 'fa-balance-scale',
            'items' => [
                $this->item('posted_stock', 'auto', 'Adjustments have stock movements', 'Non-zero value rows need adjustment transactions.', $noMv === 0 ? 'pass' : 'fail', $noMv === 0 ? 'OK' : "{$noMv} missing"),
                $this->item('posted_gl', 'auto', 'Adjustments have GL (when value ≠ 0)', 'journal_entry_id after migration 025.', $noGl === 0 ? 'pass' : 'warn', $noGl === 0 ? 'OK' : "{$noGl} missing journal"),
                $this->item('rev_gl', 'auto', 'Reversed adjustments reverse GL', 'Linked journal should be reversed.', $revGl === 0 ? 'pass' : 'warn', $revGl === 0 ? 'OK' : "{$revGl} active journal on reversed adj"),
            ],
        ];
    }

    private function sectionDataIntegrity(): array
    {
        $zeroRate = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_adjustment_items sai
            JOIN stock_adjustments sa ON sa.id = sai.stock_adjustment_id
            JOIN warehouses w ON w.id = sa.warehouse_id
            WHERE sai.qty > 0 AND COALESCE(sai.rate, 0) = 0
              AND COALESCE(sa.is_reversed, 0) = 0
              {$this->branchFilterWarehouse()}
        ");

        return [
            'id'    => 'integrity',
            'title' => 'Data quality',
            'icon'  => 'fa-database',
            'items' => [
                $this->item('zero_rate', 'auto', 'Lines with qty but zero rate', 'Rates should come from warehouse avg cost when possible.', $zeroRate === 0 ? 'pass' : 'warn', $zeroRate === 0 ? 'OK' : "{$zeroRate} line(s)"),
            ],
        ];
    }

    private function sectionOperations(): array
    {
        $negStock = $this->scalarCount("
            SELECT COUNT(*) AS c FROM warehouse_stock ws
            INNER JOIN warehouses w ON w.id = ws.warehouse_id
            WHERE ws.qty < -0.0001
              {$this->branchFilter('w.branch_id')}
        ");

        return [
            'id'    => 'ops',
            'title' => 'Operations',
            'icon'  => 'fa-chart-bar',
            'items' => [
                $this->item('neg_stock', 'auto', 'Negative warehouse stock', 'Fix before new decreases.', $negStock === 0 ? 'pass' : 'fail', $negStock === 0 ? 'None' : "{$negStock} pair(s)"),
                $this->item('rpt_list', 'reference', 'Adjustment list', 'Filter by date, warehouse, type — export CSV.', 'info', null, true, 'StockAdjustment'),
                $this->item('rpt_st', 'reference', 'Stock Take', 'Periodic physical counts with workflow B.', 'info', null, true, 'StockTake'),
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
            $row = $this->db->single();
            return (int)($row['c'] ?? 0);
        } catch (Exception $e) {
            error_log('StockAdjustmentAuditModel: ' . $e->getMessage());
            return -1;
        }
    }

    private function branchFilter(string $column): string
    {
        if (!$this->branchId) {
            return '';
        }
        if ($column === 'sa.warehouse_id') {
            return " AND EXISTS (
                SELECT 1 FROM warehouses w
                WHERE w.id = sa.warehouse_id AND w.branch_id = " . (int)$this->branchId . '
            )';
        }
        if ($column === 'di.warehouse_id') {
            return " AND EXISTS (
                SELECT 1 FROM warehouses w
                WHERE w.id = di.warehouse_id AND w.branch_id = " . (int)$this->branchId . '
            )';
        }
        return ' AND ' . $column . ' = ' . (int)$this->branchId;
    }

    private function branchFilterWarehouse(): string
    {
        if (!$this->branchId) {
            return '';
        }
        return ' AND w.branch_id = ' . (int)$this->branchId;
    }
}
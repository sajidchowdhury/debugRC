<?php
// app/models/StockTakeAuditModel.php — Phase 4 audit checklist

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';
require_once __DIR__ . '/StockTakeModel.php';

class StockTakeAuditModel
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
            $this->sectionDataIntegrity(),
            $this->sectionGlJournalLinks(),
            $this->sectionLedgerNature(),
            $this->sectionStockGl(),
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
            'missing_session_journals' => $this->getSessionsMissingJournalRows(),
        ];
    }

    /**
     * Per-session checks shown on session hub before/after post.
     */
    public function runSessionChecks(int $sessionId): array
    {
        $session = (new StockTakeModel())->getSessionById($sessionId);
        if (!$session) {
            return ['items' => [], 'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 0], 'ready_to_post' => false];
        }

        if ($this->branchId && (int)($session['branch_id'] ?? 0) !== $this->branchId) {
            return [
                'items' => [
                    $this->item('branch', 'fail', 'Branch access', 'Session is outside your branch.', 'fail', 'Not allowed'),
                ],
                'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 1],
                'ready_to_post' => false,
            ];
        }

        $items = [];
        $status = $session['status'] ?? 'draft';
        $isReversed = !empty($session['is_reversed']);

        $pendingWh = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_take_warehouses
            WHERE stock_take_session_id = :sid AND status = 'pending'
        ", [':sid' => $sessionId]);

        $countedWh = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_take_warehouses
            WHERE stock_take_session_id = :sid AND status IN ('counted','posted')
        ", [':sid' => $sessionId]);

        $totalWh = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_take_warehouses WHERE stock_take_session_id = :sid
        ", [':sid' => $sessionId]);

        $items[] = $this->item(
            'wh_complete',
            'auto',
            'Warehouses marked complete',
            'Every warehouse in the session should be counted/complete before post.',
            $pendingWh === 0 && $countedWh > 0 ? 'pass' : ($status === 'counting' ? 'warn' : 'info'),
            $pendingWh === 0
                ? "{$countedWh}/{$totalWh} complete"
                : "{$pendingWh} still pending · {$countedWh}/{$totalWh} done"
        );

        $varianceLines = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_take_items
            WHERE stock_take_session_id = :sid AND physical_qty <> system_qty
        ", [':sid' => $sessionId]);

        $items[] = $this->item(
            'variance',
            'auto',
            'Variance lines',
            'Physical ≠ system on counted products.',
            $varianceLines > 0 ? 'info' : 'pass',
            $varianceLines > 0 ? "{$varianceLines} line(s) with variance" : 'No variances (stock unchanged on post)'
        );

        $noReason = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_take_items sti
            WHERE sti.stock_take_session_id = :sid
              AND sti.physical_qty <> sti.system_qty
              AND ABS((sti.physical_qty - sti.system_qty) * COALESCE(sti.rate, 0)) >= 500
              AND TRIM(COALESCE(sti.reason, '')) = ''
        ", [':sid' => $sessionId]);

        $items[] = $this->item(
            'reason',
            'auto',
            'Large variance reasons',
            'Lines with |value| ≥ 500 should have a reason before post.',
            $noReason === 0 ? 'pass' : 'warn',
            $noReason === 0 ? 'OK' : "{$noReason} large line(s) missing reason"
        );

        if ($status === 'adjusted' && !$isReversed) {
            $movements = $this->scalarCount("
                SELECT COUNT(*) AS c FROM stock_transactions
                WHERE reference_type = 'stock_take' AND reference_id = :sid
                  AND COALESCE(is_reversed, 0) = 0
            ", [':sid' => $sessionId]);

            $items[] = $this->item(
                'stock_mv',
                'auto',
                'Stock movements posted',
                'Posted sessions should have stock_take transaction rows.',
                $varianceLines === 0 || $movements > 0 ? 'pass' : 'fail',
                $movements > 0 ? "{$movements} movement(s)" : 'Missing movements'
            );

            $journalId = (int)($session['journal_entry_id'] ?? 0);
            $varianceValue = (new StockTakeModel())->computeSessionVarianceValue($sessionId);
            $hasGlAmount = ((float)($varianceValue['gain_value'] ?? 0) + (float)($varianceValue['loss_value'] ?? 0)) >= 0.01;

            $items[] = $this->item(
                'gl',
                'auto',
                'GL journal',
                'Phase 3: shrinkage/surplus journal when variance value > 0.',
                !$hasGlAmount || $journalId > 0 ? 'pass' : 'warn',
                $journalId > 0 ? "Journal #{$journalId}" : ($hasGlAmount ? 'Missing journal' : 'N/A (zero value)')
            );
        }

        if ($isReversed) {
            $items[] = $this->item('reversed', 'auto', 'Session reversed', 'Stock and GL should be undone.', 'info', $session['reverse_reason'] ?? '');
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

        $readyToPost = !$isReversed
            && $status === 'counting'
            && $pendingWh === 0
            && $countedWh > 0
            && $fail === 0;

        return [
            'items'         => $items,
            'summary'       => ['pass' => $pass, 'warn' => $warn, 'fail' => $fail],
            'ready_to_post' => $readyToPost,
        ];
    }

    private function sectionWorkflow(): array
    {
        return [
            'id'    => 'workflow',
            'title' => 'Workflow B (count → post)',
            'icon'  => 'fa-route',
            'items' => [
                $this->item('wf_b', 'reference', 'Two-step process', 'Save counts per warehouse (partial OK) → mark warehouse complete → post whole session once.', 'info'),
                $this->item('wf_stock', 'reference', 'Stock timing', 'warehouse_stock changes only on Post adjustments, not on Save count.', 'info'),
                $this->item('wf_gl', 'reference', 'GL timing', 'Shrinkage/surplus journal posts with session finalize (Phase 3).', 'info'),
            ],
        ];
    }

    private function sectionDataIntegrity(): array
    {
        $dupItems = $this->scalarCount("
            SELECT COUNT(*) AS c FROM (
                SELECT stock_take_session_id, warehouse_id, product_id, COUNT(*) AS n
                FROM stock_take_items
                GROUP BY stock_take_session_id, warehouse_id, product_id
                HAVING n > 1
            ) x
        ");

        $openStale = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_take_sessions sts
            WHERE sts.status IN ('draft','counting')
              AND sts.take_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
              AND COALESCE(sts.is_reversed, 0) = 0
              {$this->branchFilter('sts.branch_id')}
        ");

        return [
            'id'    => 'integrity',
            'title' => 'Data integrity',
            'icon'  => 'fa-database',
            'items' => [
                $this->item(
                    'dup_lines',
                    'auto',
                    'Duplicate count lines',
                    'Unique (session, warehouse, product) per migration 023.',
                    $dupItems === 0 ? 'pass' : 'fail',
                    $dupItems === 0 ? 'OK' : "{$dupItems} duplicate group(s)"
                ),
                $this->item(
                    'stale_open',
                    'auto',
                    'Stale open sessions (>30 days)',
                    'Draft/counting sessions older than 30 days should be posted or deleted.',
                    $openStale === 0 ? 'pass' : 'warn',
                    $openStale === 0 ? 'None' : "{$openStale} session(s)"
                ),
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
                    'gl_col_st',
                    'reference',
                    'stock_take_sessions.journal_entry_id',
                    'Session post: Dr shrinkage / Cr inventory (shortage) and Dr inventory / Cr surplus (overage). View on StockTake/details/{id}.',
                    'info',
                    null,
                    true,
                    'StockTake'
                ),
                $this->item(
                    'gl_col_adj',
                    'reference',
                    'stock_adjustments.journal_entry_id',
                    'Decrease/increase adjustment GL. View on StockAdjustment/details/{id}.',
                    'info',
                    null,
                    true,
                    'StockAdjustment'
                ),
                $this->item(
                    'gl_col_dmg',
                    'reference',
                    'damage_invoices.journal_entry_id',
                    'Dr shrinkage / Cr inventory. View on Damage/details/{id}.',
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
        $shortageNotShrinkage = $this->countStockTakeShortageNotUsingShrinkageNature();

        return [
            'id'    => 'ledger_nature',
            'title' => 'Shrinkage & surplus ledgers (migration 024)',
            'icon'  => 'fa-book',
            'items' => [
                $this->item(
                    'nat_shrink',
                    'auto',
                    'inventory_shrinkage ledger exists',
                    'Required for stock take shortage, adjustment decrease, and damage posting.',
                    $shrinkageLedgers > 0 ? 'pass' : 'fail',
                    $shrinkageLedgers > 0 ? "{$shrinkageLedgers} active ledger(s)" : 'Missing — run migration 024'
                ),
                $this->item(
                    'nat_surplus',
                    'auto',
                    'inventory_surplus ledger exists',
                    'Required for stock take overage and adjustment increase.',
                    $surplusLedgers > 0 ? 'pass' : 'fail',
                    $surplusLedgers > 0 ? "{$surplusLedgers} active ledger(s)" : 'Missing — run migration 024'
                ),
                $this->item(
                    'st_shrink_use',
                    'auto',
                    'Stock take shortages use shrinkage nature',
                    'Posted sessions with shortage should debit inventory_shrinkage (not COGS fallback).',
                    $shortageNotShrinkage === 0 ? 'pass' : 'warn',
                    $shortageNotShrinkage === 0
                        ? 'OK'
                        : "{$shortageNotShrinkage} session(s) debiting non-shrinkage ledger"
                ),
            ],
        ];
    }

    private function countStockTakeShortageNotUsingShrinkageNature(): int
    {
        if ($this->scalarCount("SELECT COUNT(*) AS c FROM ledgers WHERE ledger_nature = 'inventory_shrinkage' AND is_active = 1") === 0) {
            return 0;
        }

        return $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_take_sessions sts
            INNER JOIN journal_entries je ON je.id = sts.journal_entry_id
            WHERE sts.status = 'adjusted'
              AND COALESCE(sts.is_reversed, 0) = 0
              AND COALESCE(sts.journal_entry_id, 0) > 0
              AND EXISTS (
                  SELECT 1 FROM stock_take_items sti
                  WHERE sti.stock_take_session_id = sts.id
                    AND sti.physical_qty < sti.system_qty
                    AND ABS((sti.physical_qty - sti.system_qty) * COALESCE(sti.rate, 0)) >= 0.01
              )
              AND NOT EXISTS (
                  SELECT 1 FROM journal_entry_lines jel
                  INNER JOIN ledgers l ON l.id = jel.ledger_id
                  WHERE jel.journal_entry_id = je.id
                    AND l.ledger_nature = 'inventory_shrinkage'
                    AND jel.debit >= 0.01
              )
              {$this->branchFilter('sts.branch_id')}
        ");
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSessionsMissingJournalRows(int $limit = 15): array
    {
        try {
            $this->db->query("
                SELECT sts.id, sts.session_code, sts.take_date,
                       COALESCE(SUM(
                           CASE
                               WHEN sti.physical_qty < sti.system_qty
                                   THEN ABS((sti.physical_qty - sti.system_qty) * COALESCE(sti.rate, 0))
                               WHEN sti.physical_qty > sti.system_qty
                                   THEN ABS((sti.physical_qty - sti.system_qty) * COALESCE(sti.rate, 0))
                               ELSE 0
                           END
                       ), 0) AS variance_value
                FROM stock_take_sessions sts
                INNER JOIN stock_take_items sti ON sti.stock_take_session_id = sts.id
                WHERE sts.status = 'adjusted'
                  AND COALESCE(sts.is_reversed, 0) = 0
                  AND COALESCE(sts.journal_entry_id, 0) = 0
                  AND sti.physical_qty <> sti.system_qty
                  AND ABS((sti.physical_qty - sti.system_qty) * COALESCE(sti.rate, 0)) >= 0.01
                  {$this->branchFilter('sts.branch_id')}
                GROUP BY sts.id, sts.session_code, sts.take_date
                ORDER BY sts.take_date DESC
                LIMIT " . (int)$limit
            );

            return $this->db->resultSet() ?: [];
        } catch (Exception $e) {
            error_log('StockTakeAuditModel::getSessionsMissingJournalRows: ' . $e->getMessage());

            return [];
        }
    }

    private function sectionStockGl(): array
    {
        $postedNoMv = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_take_sessions sts
            WHERE sts.status = 'adjusted'
              AND COALESCE(sts.is_reversed, 0) = 0
              AND EXISTS (
                  SELECT 1 FROM stock_take_items sti
                  WHERE sti.stock_take_session_id = sts.id
                    AND sti.physical_qty <> sti.system_qty
              )
              AND NOT EXISTS (
                  SELECT 1 FROM stock_transactions st
                  WHERE st.reference_type = 'stock_take'
                    AND st.reference_id = sts.id
                    AND COALESCE(st.is_reversed, 0) = 0
              )
              {$this->branchFilter('sts.branch_id')}
        ");

        $postedNoGl = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_take_sessions sts
            WHERE sts.status = 'adjusted'
              AND COALESCE(sts.is_reversed, 0) = 0
              AND COALESCE(sts.journal_entry_id, 0) = 0
              AND EXISTS (
                  SELECT 1 FROM stock_take_items sti
                  WHERE sti.stock_take_session_id = sts.id
                    AND sti.physical_qty <> sti.system_qty
                    AND ABS((sti.physical_qty - sti.system_qty) * COALESCE(sti.rate, 0)) >= 0.01
              )
              {$this->branchFilter('sts.branch_id')}
        ");

        $reversedNoGlRev = $this->scalarCount("
            SELECT COUNT(*) AS c FROM stock_take_sessions sts
            WHERE COALESCE(sts.is_reversed, 0) = 1
              AND COALESCE(sts.journal_entry_id, 0) > 0
              AND NOT EXISTS (
                  SELECT 1 FROM journal_entries je
                  WHERE je.id = sts.journal_entry_id AND COALESCE(je.is_reversed, 0) = 1
              )
              {$this->branchFilter('sts.branch_id')}
        ");

        return [
            'id'    => 'stock_gl',
            'title' => 'Stock & GL alignment',
            'icon'  => 'fa-balance-scale',
            'items' => [
                $this->item(
                    'posted_stock',
                    'auto',
                    'Posted sessions have stock movements',
                    'Adjusted sessions with variances must have stock_take rows.',
                    $postedNoMv === 0 ? 'pass' : 'fail',
                    $postedNoMv === 0 ? 'OK' : "{$postedNoMv} session(s) missing movements"
                ),
                $this->item(
                    'posted_gl',
                    'auto',
                    'Posted sessions have GL (when value ≠ 0)',
                    'journal_entry_id set when shrinkage/surplus value exists.',
                    $postedNoGl === 0 ? 'pass' : 'warn',
                    $postedNoGl === 0 ? 'OK' : "{$postedNoGl} session(s) missing journal"
                ),
                $this->item(
                    'rev_gl',
                    'auto',
                    'Reversed sessions reverse GL',
                    'Reversed stock take should reverse linked journal.',
                    $reversedNoGlRev === 0 ? 'pass' : 'warn',
                    $reversedNoGlRev === 0 ? 'OK' : "{$reversedNoGlRev} reversed session(s) with active journal"
                ),
            ],
        ];
    }

    private function sectionOperations(): array
    {
        $negStock = $this->scalarCount("
            SELECT COUNT(*) AS c FROM warehouse_stock ws
            INNER JOIN warehouses w ON w.id = ws.warehouse_id
            WHERE ws.qty < -0.0001
              {$this->branchWarehouseFilter('ws.warehouse_id')}
        ");

        return [
            'id'    => 'ops',
            'title' => 'Operations & reports',
            'icon'  => 'fa-chart-bar',
            'items' => [
                $this->item(
                    'neg_stock',
                    'auto',
                    'Negative warehouse stock',
                    'Investigate before next stock take post.',
                    $negStock === 0 ? 'pass' : 'fail',
                    $negStock === 0 ? 'None in scope' : "{$negStock} product/warehouse pair(s)"
                ),
                $this->item('rpt_weekly', 'reference', 'Weekly variance report', 'StockTake/weekly — branch totals, top SKU variances.', 'info', null, true, 'StockTake/weekly'),
                $this->item('rpt_detail', 'reference', 'Variance detail report', 'StockTake/variance — filter by session/warehouse/product.', 'info', null, true, 'StockTake/variance'),
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
            error_log('StockTakeAuditModel: ' . $e->getMessage());

            return -1;
        }
    }

    private function branchFilter(string $column): string
    {
        if (!$this->branchId) {
            return '';
        }

        return ' AND ' . $column . ' = ' . (int)$this->branchId;
    }

    private function branchWarehouseFilter(string $warehouseColumn): string
    {
        if (!$this->branchId) {
            return '';
        }

        return " AND EXISTS (
            SELECT 1 FROM warehouses w
            WHERE w.id = {$warehouseColumn} AND w.branch_id = " . (int)$this->branchId . '
        )';
    }
}
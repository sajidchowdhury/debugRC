<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;

/**
 * Stock Take Health-Check Service — Phase 2 (Stock Take plan).
 *
 * Port of the legacy `StockTakeAuditModel` (legacy/app/models/StockTakeAuditModel.php)
 * to Laravel + PostgreSQL. Provides two surfaces:
 *
 *   1. {@see runHealthChecks()} — the global "checklist" screen that auditors
 *      run before month-end close. Six sections mirroring the legacy:
 *        - Workflow (reference / informational)
 *        - Data integrity (duplicate count lines, stale open sessions)
 *        - GL journal link columns (reference)
 *        - Shrinkage & surplus ledgers (ledger_nature existence + usage)
 *        - Stock & GL alignment (posted sessions missing movements/GL,
 *          reversed sessions whose GL was not reversed)
 *        - Operations (negative warehouse stock, report links)
 *
 *   2. {@see runSessionChecks()} — the per-session pre-post checklist shown
 *      on the session detail page: warehouses complete, variance lines,
 *      large-variance reasons, stock movements posted, GL journal present.
 *
 * Adaptations from legacy MySQL → Laravel PostgreSQL:
 *   - `status='adjusted'` (legacy) → `status='posted'` (Laravel vocab).
 *   - `take_date` (legacy) → `session_date`.
 *   - `journal_entry_lines` (legacy) → `journal_lines` (Laravel).
 *   - `COALESCE(is_reversed, 0) = 0/1` → `is_reversed = false/true`
 *     (PostgreSQL boolean; Laravel schema uses boolean NOT NULL).
 *   - `is_active = 1/0` (legacy int) → `is_active = true/false`.
 *   - Named placeholders `:sid` → `?` (Laravel PDO convention).
 *   - Branch scoping is handled by RLS on `stock_take_sessions`,
 *     `stock_take_items`, `warehouse_stock` (via warehouses), and
 *     `journal_entries`. The optional `$branchId` arg adds an explicit
 *     WHERE for the admin "all-branches" view, where RLS is bypassed.
 *
 * The legacy checklist view (legacy/app/views/StockTake/checklist.php) is
 * ported to a Laravel Blade view at resources/views/admin/stock-take/checklist.blade.php.
 */
class StockTakeHealthCheckService
{
    /**
     * Run the full global health-check checklist.
     *
     * @param int|null $branchId  When non-null, scope all counts to this
     *     branch (used by the admin "all branches" view when RLS bypass is
     *     active). When null, RLS on each table does the scoping.
     * @return array{sections: array, summary: array, ran_at: string, branch_id: int|null, missing_session_journals: array}
     */
    public function runHealthChecks(?int $branchId = null): array
    {
        $sections = [
            $this->sectionWorkflow(),
            $this->sectionDataIntegrity($branchId),
            $this->sectionGlJournalLinks(),
            $this->sectionLedgerNature($branchId),
            $this->sectionStockGl($branchId),
            $this->sectionOperations($branchId),
        ];

        $pass = $warn = $fail = $info = 0;
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                switch ($item['status']) {
                    case 'pass': $pass++; break;
                    case 'warn': $warn++; break;
                    case 'fail': $fail++; break;
                    default:     $info++;
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
            'ran_at'    => now()->format('Y-m-d H:i:s'),
            'branch_id' => $branchId,
            'missing_session_journals' => $this->getSessionsMissingJournalRows($branchId),
        ];
    }

    /**
     * Per-session pre-post / post-post checklist.
     *
     * @return array{items: array, summary: array, ready_to_post: bool}
     */
    public function runSessionChecks(int $sessionId): array
    {
        $session = DB::table('stock_take_sessions')->where('id', $sessionId)->first();
        if (!$session) {
            return [
                'items'         => [],
                'summary'       => ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0],
                'ready_to_post' => false,
            ];
        }

        $items   = [];
        $status  = $session->status ?? 'draft';
        $isReversed = (bool) ($session->is_reversed ?? false);

        // Warehouses complete?
        $whStats = DB::table('stock_take_warehouses')
            ->where('stock_take_session_id', $sessionId)
            ->selectRaw("
                COUNT(*) AS total_wh,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_wh,
                SUM(CASE WHEN status IN ('counting','completed') THEN 1 ELSE 0 END) AS active_wh,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_wh
            ")
            ->first();

        $pendingWh   = (int) ($whStats->pending_wh ?? 0);
        $completedWh = (int) ($whStats->completed_wh ?? 0);
        $totalWh     = (int) ($whStats->total_wh ?? 0);

        $items[] = $this->item(
            'wh_complete',
            'Warehouses marked complete',
            'Every warehouse in the session should be completed before post.',
            $pendingWh === 0 && $completedWh > 0 ? 'pass' : ($status === 'counting' ? 'warn' : 'info'),
            $pendingWh === 0
                ? "{$completedWh}/{$totalWh} complete"
                : "{$pendingWh} still pending · {$completedWh}/{$totalWh} done"
        );

        // Variance lines?
        $varianceLines = (int) DB::table('stock_take_items')
            ->where('stock_take_session_id', $sessionId)
            ->whereRaw('physical_qty <> system_qty')
            ->count();

        $items[] = $this->item(
            'variance',
            'Variance lines',
            'Physical ≠ system on counted products.',
            $varianceLines > 0 ? 'info' : 'pass',
            $varianceLines > 0 ? "{$varianceLines} line(s) with variance" : 'No variances (stock unchanged on post)'
        );

        // Large variance reasons (|value| >= 500 with no reason)?
        $noReason = (int) DB::table('stock_take_items')
            ->where('stock_take_session_id', $sessionId)
            ->whereRaw('physical_qty <> system_qty')
            ->whereRaw('ABS((physical_qty - system_qty) * COALESCE(rate, 0)) >= 500')
            ->whereRaw("TRIM(COALESCE(reason, '')) = ''")
            ->count();

        $items[] = $this->item(
            'reason',
            'Large variance reasons',
            'Lines with |value| ≥ 500 should have a reason before post.',
            $noReason === 0 ? 'pass' : 'warn',
            $noReason === 0 ? 'OK' : "{$noReason} large line(s) missing reason"
        );

        // Stock movements + GL (only relevant for posted sessions).
        if ($status === 'posted' && !$isReversed) {
            $movements = (int) DB::table('stock_transactions')
                ->where('reference_type', 'stock_take')
                ->where('reference_id', $sessionId)
                ->where('is_reversed', false)
                ->count();

            $items[] = $this->item(
                'stock_mv',
                'Stock movements posted',
                'Posted sessions should have stock_take transaction rows.',
                $varianceLines === 0 || $movements > 0 ? 'pass' : 'fail',
                $movements > 0 ? "{$movements} movement(s)" : 'Missing movements'
            );

            $journalId   = (int) ($session->journal_entry_id ?? 0);
            $varianceRow = DB::table('stock_take_items')
                ->where('stock_take_session_id', $sessionId)
                ->whereRaw('physical_qty <> system_qty')
                ->selectRaw('COALESCE(SUM(ABS((physical_qty - system_qty) * COALESCE(rate, 0))), 0) AS variance_value')
                ->first();
            $hasGlAmount = ((float) ($varianceRow->variance_value ?? 0)) >= 0.01;

            $items[] = $this->item(
                'gl',
                'GL journal',
                'Shrinkage/surplus journal when variance value > 0.',
                !$hasGlAmount || $journalId > 0 ? 'pass' : 'warn',
                $journalId > 0 ? "Journal #{$journalId}" : ($hasGlAmount ? 'Missing journal' : 'N/A (zero value)')
            );
        }

        if ($isReversed) {
            $items[] = $this->item(
                'reversed',
                'Session reversed',
                'Stock and GL should be undone.',
                'info',
                $session->reverse_reason ?? ''
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

        $readyToPost = !$isReversed
            && $status === 'counting'
            && $pendingWh === 0
            && $completedWh > 0
            && $fail === 0;

        return [
            'items'         => $items,
            'summary'       => ['pass' => $pass, 'warn' => $warn, 'fail' => $fail, 'info' => $info],
            'ready_to_post' => $readyToPost,
        ];
    }

    /**
     * Posted sessions with variance value ≥ 0.01 but journal_entry_id = 0/null.
     * Surfaced on the global checklist as an actionable list (legacy parity).
     *
     * @return array<int, array{id: int, session_code: string, session_date: string, variance_value: float}>
     */
    public function getSessionsMissingJournalRows(?int $branchId = null, int $limit = 15): array
    {
        return DB::table('stock_take_sessions as sts')
            ->join('stock_take_items as sti', 'sti.stock_take_session_id', '=', 'sts.id')
            ->where('sts.status', 'posted')
            ->where('sts.is_reversed', false)
            ->whereRaw('COALESCE(sts.journal_entry_id, 0) = 0')
            ->whereRaw('sti.physical_qty <> sti.system_qty')
            ->whereRaw('ABS((sti.physical_qty - sti.system_qty) * COALESCE(sti.rate, 0)) >= 0.01')
            ->when($branchId, fn($q) => $q->where('sts.branch_id', $branchId))
            ->select(
                'sts.id',
                'sts.session_code',
                'sts.session_date',
                DB::raw('COALESCE(SUM(ABS((sti.physical_qty - sti.system_qty) * COALESCE(sti.rate, 0))), 0) AS variance_value')
            )
            ->groupBy('sts.id', 'sts.session_code', 'sts.session_date')
            ->orderByDesc('sts.session_date')
            ->limit($limit)
            ->get()
            ->map(fn($r) => [
                'id'             => (int) $r->id,
                'session_code'   => $r->session_code,
                'session_date'   => (string) $r->session_date,
                'variance_value' => (float) $r->variance_value,
            ])
            ->all();
    }

    // ============================================================
    // Sections — each returns one card on the checklist screen.
    // ============================================================

    private function sectionWorkflow(): array
    {
        return [
            'id'    => 'workflow',
            'title' => 'Workflow (count → post)',
            'icon'  => 'fa-route',
            'items' => [
                $this->item('wf_b', 'Two-step process', 'Save counts per warehouse (partial OK) → mark warehouse complete → post whole session once.', 'info', null),
                $this->item('wf_stock', 'Stock timing', 'warehouse_stock changes only on Post, not on Save count.', 'info', null),
                $this->item('wf_gl', 'GL timing', 'Shrinkage/surplus journal posts with session post.', 'info', null),
            ],
        ];
    }

    private function sectionDataIntegrity(?int $branchId): array
    {
        $dupItems = (int) DB::table('stock_take_items')
            ->selectRaw('COUNT(*) AS c')
            ->fromSub(function ($q) {
                $q->selectRaw('COUNT(*) AS n')
                    ->from('stock_take_items')
                    ->groupBy('stock_take_session_id', 'warehouse_id', 'product_id')
                    ->havingRaw('COUNT(*) > 1');
            }, 'x')
            ->first()->c ?? 0;

        $openStale = (int) DB::table('stock_take_sessions as sts')
            ->whereIn('sts.status', ['draft', 'counting'])
            ->where('sts.is_reversed', false)
            ->whereRaw("sts.session_date < (CURRENT_DATE - INTERVAL '30 days')")
            ->when($branchId, fn($q) => $q->where('sts.branch_id', $branchId))
            ->count();

        return [
            'id'    => 'integrity',
            'title' => 'Data integrity',
            'icon'  => 'fa-database',
            'items' => [
                $this->item(
                    'dup_lines',
                    'Duplicate count lines',
                    'Unique (session, warehouse, product) per the stock_take_items unique constraint.',
                    $dupItems === 0 ? 'pass' : 'fail',
                    $dupItems === 0 ? 'OK' : "{$dupItems} duplicate group(s)"
                ),
                $this->item(
                    'stale_open',
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
                $this->item('gl_col_st', 'stock_take_sessions.journal_entry_id', 'Session post: Dr shrinkage / Cr inventory (shortage) and Dr inventory / Cr surplus (overage). View on the session detail page.', 'info', null),
                $this->item('gl_col_sti', 'stock_take_items.journal_line_id', 'Phase 1: per-line GL traceability — each variance item links to the exact journal_lines row. View on the session detail page.', 'info', null),
                $this->item('gl_col_adj', 'stock_adjustments.journal_entry_id', 'Decrease/increase adjustment GL. View on the adjustment detail page.', 'info', null),
                $this->item('gl_col_dmg', 'damage_invoices.journal_entry_id', 'Dr shrinkage / Cr inventory. View on the damage detail page.', 'info', null),
            ],
        ];
    }

    private function sectionLedgerNature(?int $branchId): array
    {
        $shrinkageLedgers = (int) DB::table('ledgers')
            ->where('ledger_nature', 'inventory_shrinkage')
            ->where('is_active', true)
            ->count();
        $surplusLedgers = (int) DB::table('ledgers')
            ->where('ledger_nature', 'inventory_surplus')
            ->where('is_active', true)
            ->count();
        $shortageNotShrinkage = $this->countStockTakeShortageNotUsingShrinkageNature($branchId);

        return [
            'id'    => 'ledger_nature',
            'title' => 'Shrinkage & surplus ledgers',
            'icon'  => 'fa-book',
            'items' => [
                $this->item(
                    'nat_shrink',
                    'inventory_shrinkage ledger exists',
                    'Required for stock take shortage, adjustment decrease, and damage posting.',
                    $shrinkageLedgers > 0 ? 'pass' : 'fail',
                    $shrinkageLedgers > 0 ? "{$shrinkageLedgers} active ledger(s)" : 'Missing — seed the ledgers'
                ),
                $this->item(
                    'nat_surplus',
                    'inventory_surplus ledger exists',
                    'Required for stock take overage and adjustment increase.',
                    $surplusLedgers > 0 ? 'pass' : 'fail',
                    $surplusLedgers > 0 ? "{$surplusLedgers} active ledger(s)" : 'Missing — seed the ledgers'
                ),
                $this->item(
                    'st_shrink_use',
                    'Stock take shortages use shrinkage nature',
                    'Posted sessions with shortage should debit inventory_shrinkage.',
                    $shortageNotShrinkage === 0 ? 'pass' : 'warn',
                    $shortageNotShrinkage === 0
                        ? 'OK'
                        : "{$shortageNotShrinkage} session(s) debiting non-shrinkage ledger"
                ),
            ],
        ];
    }

    private function sectionStockGl(?int $branchId): array
    {
        // Posted sessions with variances but no stock movements.
        $postedNoMv = (int) DB::table('stock_take_sessions as sts')
            ->where('sts.status', 'posted')
            ->where('sts.is_reversed', false)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('stock_take_items as sti')
                    ->whereColumn('sti.stock_take_session_id', 'sts.id')
                    ->whereRaw('sti.physical_qty <> sti.system_qty');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('stock_transactions as st')
                    ->whereColumn('st.reference_id', 'sts.id')
                    ->where('st.reference_type', 'stock_take')
                    ->where('st.is_reversed', false);
            })
            ->when($branchId, fn($q) => $q->where('sts.branch_id', $branchId))
            ->count();

        // Posted sessions with variance value ≥ 0.01 but no journal.
        $postedNoGl = (int) DB::table('stock_take_sessions as sts')
            ->where('sts.status', 'posted')
            ->where('sts.is_reversed', false)
            ->whereRaw('COALESCE(sts.journal_entry_id, 0) = 0')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('stock_take_items as sti')
                    ->whereColumn('sti.stock_take_session_id', 'sts.id')
                    ->whereRaw('sti.physical_qty <> sti.system_qty')
                    ->whereRaw('ABS((sti.physical_qty - sti.system_qty) * COALESCE(sti.rate, 0)) >= 0.01');
            })
            ->when($branchId, fn($q) => $q->where('sts.branch_id', $branchId))
            ->count();

        // Reversed sessions whose GL was not reversed.
        $reversedNoGlRev = (int) DB::table('stock_take_sessions as sts')
            ->where('sts.is_reversed', true)
            ->whereRaw('COALESCE(sts.journal_entry_id, 0) > 0')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('journal_entries as je')
                    ->whereColumn('je.id', 'sts.journal_entry_id')
                    ->where('je.is_reversed', true);
            })
            ->when($branchId, fn($q) => $q->where('sts.branch_id', $branchId))
            ->count();

        return [
            'id'    => 'stock_gl',
            'title' => 'Stock & GL alignment',
            'icon'  => 'fa-balance-scale',
            'items' => [
                $this->item(
                    'posted_stock',
                    'Posted sessions have stock movements',
                    'Posted sessions with variances must have stock_take rows.',
                    $postedNoMv === 0 ? 'pass' : 'fail',
                    $postedNoMv === 0 ? 'OK' : "{$postedNoMv} session(s) missing movements"
                ),
                $this->item(
                    'posted_gl',
                    'Posted sessions have GL (when value ≠ 0)',
                    'journal_entry_id set when shrinkage/surplus value exists.',
                    $postedNoGl === 0 ? 'pass' : 'warn',
                    $postedNoGl === 0 ? 'OK' : "{$postedNoGl} session(s) missing journal"
                ),
                $this->item(
                    'rev_gl',
                    'Reversed sessions reverse GL',
                    'Reversed stock take should reverse linked journal.',
                    $reversedNoGlRev === 0 ? 'pass' : 'warn',
                    $reversedNoGlRev === 0 ? 'OK' : "{$reversedNoGlRev} reversed session(s) with active journal"
                ),
            ],
        ];
    }

    private function sectionOperations(?int $branchId): array
    {
        $negStock = (int) DB::table('warehouse_stock as ws')
            ->join('warehouses as w', 'w.id', '=', 'ws.warehouse_id')
            ->whereRaw('ws.qty < -0.0001')
            ->when($branchId, fn($q) => $q->where('w.branch_id', $branchId))
            ->count();

        return [
            'id'    => 'ops',
            'title' => 'Operations & reports',
            'icon'  => 'fa-chart-bar',
            'items' => [
                $this->item(
                    'neg_stock',
                    'Negative warehouse stock',
                    'Investigate before next stock take post.',
                    $negStock === 0 ? 'pass' : 'fail',
                    $negStock === 0 ? 'None in scope' : "{$negStock} product/warehouse pair(s)"
                ),
                $this->item('rpt_variance', 'Variance detail report', 'Filter by session/warehouse/product on the stock-take index page.', 'info', null),
            ],
        ];
    }

    /**
     * Counts posted sessions that have a shortage variance value ≥ 0.01 but
     * whose journal entry does NOT debit the inventory_shrinkage ledger
     * (i.e., they are using a wrong/fallback ledger for shrinkage).
     */
    private function countStockTakeShortageNotUsingShrinkageNature(?int $branchId): int
    {
        if ((int) DB::table('ledgers')->where('ledger_nature', 'inventory_shrinkage')->where('is_active', true)->count() === 0) {
            return 0;
        }

        return (int) DB::table('stock_take_sessions as sts')
            ->join('journal_entries as je', 'je.id', '=', 'sts.journal_entry_id')
            ->where('sts.status', 'posted')
            ->where('sts.is_reversed', false)
            ->whereRaw('COALESCE(sts.journal_entry_id, 0) > 0')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('stock_take_items as sti')
                    ->whereColumn('sti.stock_take_session_id', 'sts.id')
                    ->whereRaw('sti.physical_qty < sti.system_qty')
                    ->whereRaw('ABS((sti.physical_qty - sti.system_qty) * COALESCE(sti.rate, 0)) >= 0.01');
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('journal_lines as jl')
                    ->join('ledgers as l', 'l.id', '=', 'jl.ledger_id')
                    ->whereColumn('jl.journal_entry_id', 'je.id')
                    ->where('l.ledger_nature', 'inventory_shrinkage')
                    ->where('jl.debit', '>=', 0.01);
            })
            ->when($branchId, fn($q) => $q->where('sts.branch_id', $branchId))
            ->count();
    }

    /**
     * Build one checklist item. Kept private — only this service builds items.
     */
    private function item(string $id, string $title, string $expected, string $status, ?string $detail = null): array
    {
        return [
            'id'       => $id,
            'title'    => $title,
            'expected' => $expected,
            'status'   => $status,
            'detail'   => $detail ?? '',
        ];
    }
}

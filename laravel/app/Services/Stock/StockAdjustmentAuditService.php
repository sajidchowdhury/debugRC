<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\DB;

/**
 * Stock Adjustment Audit Service — Phase 8.2 (Stock Adjustment plan).
 *
 * A dedicated, sectioned health-check service that ports Legacy's 6-section
 * integrity checklist (workflow, gl_journal_links, ledger_nature, stock_gl,
 * data_integrity, operations) and adds a 7th — approval_workflow — that
 * surfaces maker-checker drift (stale drafts, approved-but-not-confirmed,
 * self-approval violations).
 *
 * Replaces the controller's private computeAuditChecks() (which was a flat
 * 4-check list). The controller's audit() route now delegates here; the old
 * flat audit.blade.php is kept as a backward-compat alias that redirects to
 * the new checklist route.
 *
 * Design:
 *   - Each SECTION is an array of CHECKS.
 *   - Each CHECK returns: key, label, count, status (pass|warn|fail), and an
 *     optional `samples` array (max 10 rows) so the view can link the auditor
 *     straight to the offending adjustment.
 *   - Branch-scoped for non-admins (defense-in-depth on top of RLS — the
 *     service lets the view show "scoped to Branch X" and a forged request
 *     from another branch sees no rows).
 *   - All queries are COUNT-first (cheap); sample rows are fetched only for
 *     checks whose count > 0, so a green tenant pays no sample-row cost.
 *   - No writes — pure read. Safe to run any time (the "Re-run checks"
 *     button just hits the route again).
 *
 * The 7 sections map to the gap table (G4) and the plan §13.2:
 *   1. workflow          — lifecycle distribution + stale states
 *   2. gl_journal_links  — confirmed ↔ journal_entries integrity
 *   3. ledger_nature     — stock_transactions reference/reversal integrity
 *   4. stock_gl          — warehouse_stock ↔ stock_transactions drift + completeness
 *   5. data_integrity    — duplicate-product, negative snapshot, missing branch
 *   6. operations        — orphan items, missing UOM factor, future-dated
 *   7. approval_workflow — maker-checker drift (stale drafts, stuck approvals, self-approve)
 */
class StockAdjustmentAuditService
{
    /** Max sample rows returned per check (keeps the page payload small). */
    private const SAMPLE_LIMIT = 10;

    /** Approved-but-not-confirmed staleness threshold (days). */
    private const STUCK_APPROVAL_DAYS = 3;

    public function __construct(
        // Phase 7 reconcile service — reused for the stock_gl drift summary
        // so there is ONE drift formula (no risk of the checklist and the
        // reconcile page disagreeing).
        private StockAdjustmentReconcileService $reconcile
    ) {}

    /**
     * Run the full 7-section checklist.
     *
     * @param int|null $branchId  null = all branches (admin); int = scoped.
     * @return array{sections: array<int, array>, summary: array}
     */
    public function runChecks(?int $branchId = null): array
    {
        $sections = [
            $this->sectionWorkflow($branchId),
            $this->sectionGlJournalLinks($branchId),
            $this->sectionLedgerNature($branchId),
            $this->sectionStockGl($branchId),
            $this->sectionDataIntegrity($branchId),
            $this->sectionOperations($branchId),
            $this->sectionApprovalWorkflow($branchId),
        ];

        // Summary: roll up pass/warn/fail across all checks.
        $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'total' => 0];
        foreach ($sections as $section) {
            foreach ($section['checks'] as $check) {
                $summary['total']++;
                $summary[$check['status']] = ($summary[$check['status']] ?? 0) + 1;
            }
        }

        return ['sections' => $sections, 'summary' => $summary];
    }

    // ========================================================================
    // §1 — Workflow State
    // ========================================================================

    private function sectionWorkflow(?int $branchId): array
    {
        $staleDays = (int) config('stock_adjustment.stale_draft_days', 7);

        $checks = [];

        // 1a. Stale drafts (> stale_draft_days old).
        $staleDrafts = $this->countAndSamples(
            $this->baseQuery($branchId)
                ->where('status', 'draft')
                ->where('adjustment_date', '<', DB::raw("CURRENT_DATE - INTERVAL '{$staleDays} days'")),
            "Drafts older than {$staleDays} days"
        );
        $checks[] = $this->check('stale_drafts', "Stale drafts (>{$staleDays} days)", $staleDrafts, 'warn');

        // 1b. Drafts in a terminal-ish state (cancelled with no cancel_reason — G15).
        $cancelNoReason = $this->countAndSamples(
            $this->baseQuery($branchId)
                ->where('status', 'cancelled')
                ->whereNull('cancel_reason'),
            'Cancelled without a cancel_reason (G15)'
        );
        $checks[] = $this->check('cancel_no_reason', 'Cancelled without a cancel_reason', $cancelNoReason, 'warn');

        // 1c. Confirmed-but-not-reversed with a future adjustment_date (data-entry error).
        $futureDated = $this->countAndSamples(
            $this->baseQuery($branchId)
                ->where('status', 'confirmed')
                ->where('adjustment_date', '>', DB::raw('CURRENT_DATE')),
            'Confirmed with a future adjustment_date'
        );
        $checks[] = $this->check('future_dated_confirmed', 'Confirmed with a future date', $futureDated, 'warn');

        return $this->section('workflow', 'Workflow State', 'fa-diagram-project', $checks);
    }

    // ========================================================================
    // §2 — GL Journal Links
    // ========================================================================

    private function sectionGlJournalLinks(?int $branchId): array
    {
        $checks = [];

        // 2a. Confirmed adjustments without a journal_entry_id (G6).
        $missingGl = $this->countAndSamples(
            $this->baseQuery($branchId)
                ->where('status', 'confirmed')
                ->whereNull('journal_entry_id'),
            'Confirmed without a GL journal entry'
        );
        $checks[] = $this->check('missing_gl', 'Confirmed without GL journal entry', $missingGl, 'fail');

        // 2b. Confirmed adjustments whose journal_entry_id points at a missing JE (FK orphan — should be impossible).
        $orphanGl = $this->countAndSamples(
            $this->baseQuery($branchId)
                ->where('status', 'confirmed')
                ->whereNotNull('journal_entry_id')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('journal_entries')
                    ->whereColumn('journal_entries.id', 'stock_adjustments.journal_entry_id')),
            'Confirmed with a dangling journal_entry_id (FK orphan)'
        );
        $checks[] = $this->check('orphan_gl', 'Confirmed with dangling journal_entry_id', $orphanGl, 'fail');

        // 2c. Unbalanced stock-adjustment journal entries (DB trigger enforces, but check anyway).
        $unbalanced = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM (
    SELECT je.id
    FROM journal_entries je
    JOIN journal_lines jl ON jl.journal_entry_id = je.id
    WHERE je.reference_type = 'stock_adjustment'
    GROUP BY je.id
    HAVING SUM(jl.debit) <> SUM(jl.credit)
) x
SQL);
        $checks[] = $this->check(
            'unbalanced_je',
            'Unbalanced stock-adjustment journal entries',
            $this->plainCount((int) $unbalanced->cnt),
            'fail'
        );

        return $this->section('gl_journal_links', 'GL Journal Links', 'fa-link', $checks);
    }

    // ========================================================================
    // §3 — Ledger Nature (stock_transactions integrity)
    // ========================================================================

    private function sectionLedgerNature(?int $branchId): array
    {
        $checks = [];

        // 3a. Confirmed adjustments with ZERO stock_transactions rows (movement lost).
        $missingStock = $this->countAndSamples(
            $this->baseQuery($branchId)
                ->where('status', 'confirmed')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                    ->from('stock_transactions')
                    ->whereColumn('stock_transactions.reference_id', 'stock_adjustments.id')
                    ->whereIn('stock_transactions.reference_type', ['stock_adjustment', 'opening_balance'])),
            'Confirmed without any stock_transactions row'
        );
        $checks[] = $this->check('missing_stock_tx', 'Confirmed without stock transactions', $missingStock, 'fail');

        // 3b. Reversal rows whose reversal_of_transaction_id is itself reversed (double-reversal — corruption).
        $doubleReversal = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM stock_transactions st
WHERE st.reference_type = 'reversal'
  AND st.reversal_of_transaction_id IS NOT NULL
  AND EXISTS (
    SELECT 1 FROM stock_transactions orig
    WHERE orig.id = st.reversal_of_transaction_id
      AND orig.is_reversed = true
  )
SQL);
        $checks[] = $this->check(
            'double_reversal',
            'Reversals of already-reversed transactions (double-reversal)',
            $this->plainCount((int) $doubleReversal->cnt),
            'fail'
        );

        // 3c. Reversal rows with no reversal_of_transaction_id (orphan reversal — can't trace the original).
        $orphanReversal = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM stock_transactions
WHERE reference_type = 'reversal' AND reversal_of_transaction_id IS NULL
SQL);
        $checks[] = $this->check(
            'orphan_reversal',
            'Reversal rows with no original (reversal_of_transaction_id NULL)',
            $this->plainCount((int) $orphanReversal->cnt),
            'fail'
        );

        return $this->section('ledger_nature', 'Ledger Nature', 'fa-layer-group', $checks);
    }

    // ========================================================================
    // §4 — Stock ↔ GL (warehouse_stock snapshot integrity)
    // ========================================================================

    private function sectionStockGl(?int $branchId): array
    {
        $checks = [];

        // 4a. warehouse_stock ↔ stock_transactions drift (delegates to the
        // Phase 7 ReconcileService so the checklist and the reconcile page
        // can NEVER disagree — one drift formula).
        try {
            $drift = $this->reconcile->computeDrift($branchId, null);
            $mismatched = (int) ($drift['mismatched'] ?? 0);
            $checked    = (int) ($drift['checked'] ?? 0);
        } catch (\Throwable $e) {
            // If the drift query fails (e.g. a partition is missing), do not
            // crash the whole checklist — surface it as a single fail.
            $mismatched = -1;
            $checked    = -1;
        }

        $status = $mismatched === 0 ? 'pass' : 'fail';
        $samples = [];
        if ($mismatched > 0 && isset($drift['mismatches'])) {
            foreach (array_slice($drift['mismatches'], 0, self::SAMPLE_LIMIT) as $m) {
                $samples[] = [
                    'id'   => $m['warehouse_id'] ?? null,
                    'code' => ($m['warehouse_name'] ?? 'WH#' . ($m['warehouse_id'] ?? '?'))
                        . ' / ' . ($m['product_code'] ?? 'P#' . ($m['product_id'] ?? '?')),
                    'date' => null,
                    'extra' => 'snap=' . ($m['snapshot_qty'] ?? '?') . ' vs ledger=' . ($m['ledger_qty'] ?? '?')
                        . ' (drift ' . ($m['drift'] ?? '?') . ')',
                ];
            }
        } elseif ($mismatched < 0) {
            $samples[] = ['id' => null, 'code' => 'Query error', 'date' => null, 'extra' => 'See laravel.log'];
        }

        $checks[] = [
            'key'     => 'stock_drift',
            'label'   => 'warehouse_stock ↔ stock_transactions drift',
            'count'   => $mismatched < 0 ? 0 : $mismatched,
            'status'  => $status,
            'samples' => $samples,
            'meta'    => $checked >= 0 ? "{$checked} row(s) checked" : null,
        ];

        // 4b. Negative warehouse_stock.qty (physical stock went below zero — data error).
        $negative = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM warehouse_stock WHERE qty < 0
SQL);
        $checks[] = $this->check(
            'negative_snapshot',
            'Negative warehouse_stock.qty (physical stock < 0)',
            $this->plainCount((int) $negative->cnt),
            'fail'
        );

        return $this->section('stock_gl', 'Stock ↔ GL', 'fa-warehouse', $checks);
    }

    // ========================================================================
    // §5 — Data Integrity
    // ========================================================================

    private function sectionDataIntegrity(?int $branchId): array
    {
        $checks = [];

        // 5a. Duplicate product_id within one adjustment (G11 backstop — the
        // UNIQUE constraint added in Phase 6.2 prevents new dupes, but a row
        // that predates the constraint would still be here).
        $dupProduct = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM (
    SELECT stock_adjustment_id, product_id, COUNT(*) AS n
    FROM stock_adjustment_items
    GROUP BY stock_adjustment_id, product_id
    HAVING COUNT(*) > 1
) d
SQL);
        $dupSamples = [];
        if ((int) $dupProduct->cnt > 0) {
            $rows = DB::select(<<<SQL
SELECT stock_adjustment_id AS id, product_id, COUNT(*) AS n
FROM stock_adjustment_items
GROUP BY stock_adjustment_id, product_id
HAVING COUNT(*) > 1
ORDER BY stock_adjustment_id
LIMIT ?
SQL, [self::SAMPLE_LIMIT]);
            foreach ($rows as $r) {
                $dupSamples[] = [
                    'id'    => $r->id,
                    'code'  => 'SA#' . $r->id,
                    'date'  => null,
                    'extra' => "product #{$r->product_id} × {$r->n}",
                ];
            }
        }
        $checks[] = [
            'key'     => 'duplicate_product',
            'label'   => 'Duplicate product_id within one adjustment (G11)',
            'count'   => (int) $dupProduct->cnt,
            'status'  => (int) $dupProduct->cnt === 0 ? 'pass' : 'fail',
            'samples' => $dupSamples,
        ];

        // 5b. Adjustments missing branch_id (RLS would hide them, but a NULL
        // branch_id is a data-quality smell).
        $missingBranch = $this->countAndSamples(
            $this->baseQuery($branchId)->whereNull('branch_id'),
            'Adjustments with a NULL branch_id'
        );
        $checks[] = $this->check('missing_branch', 'Adjustments with NULL branch_id', $missingBranch, 'fail');

        // 5c. Adjustment items pointing at a missing product (FK orphan).
        $orphanProduct = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM stock_adjustment_items sai
WHERE NOT EXISTS (SELECT 1 FROM products p WHERE p.id = sai.product_id)
SQL);
        $checks[] = $this->check(
            'orphan_product',
            'Adjustment items pointing at a missing product',
            $this->plainCount((int) $orphanProduct->cnt),
            'fail'
        );

        return $this->section('data_integrity', 'Data Integrity', 'fa-shield-halved', $checks);
    }

    // ========================================================================
    // §6 — Operations
    // ========================================================================

    private function sectionOperations(?int $branchId): array
    {
        $checks = [];

        // 6a. Items with a UOM but a NULL uom_factor (Phase 5 snapshot incomplete).
        $missingFactor = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM stock_adjustment_items
WHERE uom_id IS NOT NULL AND uom_factor IS NULL
SQL);
        $checks[] = $this->check(
            'missing_uom_factor',
            'Items with a UOM but no uom_factor snapshot (Phase 5)',
            $this->plainCount((int) $missingFactor->cnt),
            'warn'
        );

        // 6b. Items with qty_base NULL but qty > 0 (pre-Phase-5 rows that the
        // backfill migration missed — non-fatal but the show view falls back).
        $missingQtyBase = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM stock_adjustment_items
WHERE qty_base IS NULL AND qty > 0
SQL);
        $checks[] = $this->check(
            'missing_qty_base',
            'Items with qty > 0 but qty_base NULL (Phase 5 backfill gap)',
            $this->plainCount((int) $missingQtyBase->cnt),
            'warn'
        );

        // 6c. Items whose stock_transaction_id is set but stock_transaction_date
        // is NULL (Phase 6.2 composite-FK fix incompletely applied).
        $missingTxAe = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM stock_adjustment_items
WHERE stock_transaction_id IS NOT NULL AND stock_transaction_date IS NULL
SQL);
        $checks[] = $this->check(
            'missing_tx_date',
            'Items with stock_transaction_id but no stock_transaction_date',
            $this->plainCount((int) $missingTxAe->cnt),
            'fail'
        );

        return $this->section('operations', 'Operations', 'fa-screwdriver-wrench', $checks);
    }

    // ========================================================================
    // §7 — Approval Workflow (maker-checker health)
    // ========================================================================

    private function sectionApprovalWorkflow(?int $branchId): array
    {
        $staleDays = (int) config('stock_adjustment.stale_draft_days', 7);
        $checks = [];

        // 7a. Drafts stuck > stale_draft_days (same as §1a but framed as an
        // approval-workflow smell — the maker never submitted).
        $stuckDrafts = $this->countAndSamples(
            $this->baseQuery($branchId)
                ->where('status', 'draft')
                ->where('adjustment_date', '<', DB::raw("CURRENT_DATE - INTERVAL '{$staleDays} days'")),
            "Drafts never submitted (>{$staleDays} days)"
        );
        $checks[] = $this->check('stuck_draft', "Drafts never submitted (>{$staleDays} days)", $stuckDrafts, 'warn');

        // 7b. Approved-but-not-confirmed > STUCK_APPROVAL_DAYS days (the
        // checker approved but nobody posted — the stock correction is
        // sitting idle).
        $stuckApproved = $this->countAndSamples(
            $this->baseQuery($branchId)
                ->where('status', 'approved')
                ->whereNotNull('approved_at')
                ->where('approved_at', '<', DB::raw("NOW() - INTERVAL '" . self::STUCK_APPROVAL_DAYS . " days'")),
            'Approved but not confirmed (>' . self::STUCK_APPROVAL_DAYS . ' days)'
        );
        $checks[] = $this->check('stuck_approved', 'Approved but not confirmed (>' . self::STUCK_APPROVAL_DAYS . ' days)', $stuckApproved, 'warn');

        // 7c. Self-approval violation (approved_by == submitted_by) — the
        // service forbids this, but check the DB for any historical leak.
        $selfApprove = $this->countAndSamples(
            $this->baseQuery($branchId)
                ->whereNotNull('approved_by')
                ->whereNotNull('submitted_by')
                ->whereColumn('approved_by', 'submitted_by'),
            'Self-approval (approved_by = submitted_by)'
        );
        $checks[] = $this->check('self_approval', 'Self-approval violation (maker = checker)', $selfApprove, 'fail');

        return $this->section('approval_workflow', 'Approval Workflow', 'fa-people-arrows', $checks);
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    /**
     * Base query scoped to a branch (or all when branchId is null). Returns
     * a query builder on stock_adjustments (NOT yet executed).
     */
    private function baseQuery(?int $branchId): \Illuminate\Database\Query\Builder
    {
        return DB::table('stock_adjustments')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
    }

    /**
     * Count + (lazily) sample rows for a check whose source is a
     * stock_adjustments query. Returns the raw {count, samples} pair before
     * the check() wrapper adds key/label/status.
     */
    private function countAndSamples(\Illuminate\Database\Query\Builder $query, string $sampleLabel): array
    {
        $count = (int) $query->count();

        $samples = [];
        if ($count > 0) {
            $rows = (clone $query)
                ->select('id', 'adjustment_code', 'adjustment_date', 'status')
                ->orderByDesc('id')
                ->limit(self::SAMPLE_LIMIT)
                ->get();

            foreach ($rows as $r) {
                $samples[] = [
                    'id'    => $r->id,
                    'code'  => $r->adjustment_code ?? ('SA#' . $r->id),
                    'date'  => $r->adjustment_date,
                    'extra' => $sampleLabel . ' · status=' . $r->status,
                ];
            }
        }

        return ['count' => $count, 'samples' => $samples];
    }

    /**
     * Wrap a plain integer count (no sample rows — used for checks whose
     * source is NOT a stock_adjustments query, so a per-adjustment sample
     * doesn't apply).
     */
    private function plainCount(int $count): array
    {
        return ['count' => $count, 'samples' => []];
    }

    /**
     * Build a check array. Status is derived from the count when the caller
     * passes a hint status — but the caller can override (e.g. a drift check
     * is always fail when mismatched > 0).
     */
    private function check(string $key, string $label, array $countAndSamples, string $failStatus): array
    {
        $count = $countAndSamples['count'];
        $status = $count === 0 ? 'pass' : $failStatus;

        return [
            'key'     => $key,
            'label'   => $label,
            'count'   => $count,
            'status'  => $status,
            'samples' => $countAndSamples['samples'],
        ];
    }

    /**
     * Build a section array.
     */
    private function section(string $id, string $title, string $icon, array $checks): array
    {
        $pass = $fail = $warn = 0;
        foreach ($checks as $c) {
            if ($c['status'] === 'pass') $pass++;
            elseif ($c['status'] === 'fail') $fail++;
            else $warn++;
        }

        return [
            'id'     => $id,
            'title'  => $title,
            'icon'   => $icon,
            'checks' => $checks,
            'pass'   => $pass,
            'fail'   => $fail,
            'warn'   => $warn,
        ];
    }
}

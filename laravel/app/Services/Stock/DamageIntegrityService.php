<?php

namespace App\Services\Stock;

use App\Models\DamageInvoice;
use Illuminate\Support\Facades\DB;

/**
 * Damage Integrity Service — Phase 2 (port legacy DamageAuditModel strength).
 *
 * Laravel dropped the legacy `DamageAuditModel::runDamageChecks()` feature
 * entirely when it migrated to the two-phase (draft → confirmed → cancelled)
 * damage flow. This restores it as a live-computed, read-only integrity
 * panel rendered on every damage detail page.
 *
 * Why live-computed (not a persisted `damage_audit_log` table)?
 *   - Avoids a second source of truth (the live DB IS the truth).
 *   - No stale results — every page load re-verifies against current state.
 *   - Mirrors legacy exactly (legacy computed on demand in details.php).
 *   - All lookups are indexed (stock_transactions.reference_id, damage_invoice_items
 *     .damage_invoice_id, journal_entries.id) so the panel is fast.
 *
 * The 5 checks (ported + adapted to the two-phase state machine):
 *
 *   1. branch_wh   — damage.branch_id == warehouse.branch_id.
 *                     (Legacy only displayed this; we actually VERIFY it —
 *                     a mismatch means the damage header was written with a
 *                     branch that doesn't own the warehouse, which would
 *                     break branch isolation.)
 *
 *   2. total_value — header.total_value == SUM(items.qty * items.rate),
 *                     tolerance 0.02 (rounding). pass/fail. Applies to ALL
 *                     states (even drafts — a draft with a mismatched total
 *                     is a bug to catch before confirm).
 *
 *   3. stock       — state-aware:
 *                     · draft      → info  "Not yet posted" (no movements expected)
 *                     · confirmed  → pass if active (non-reversed) stock_transactions
 *                                    exist with reference_type='damage';
 *                                    fail if missing.
 *                     · cancelled  → pass if ALL damage stock_transactions are
 *                                    reversed; warn if any active remain (partial
 *                                    reversal — should not happen but flags drift).
 *
 *   4. gl          — state-aware:
 *                     · draft      → info  "Not yet posted"
 *                     · confirmed  → pass if journal_entry_id set AND JE not
 *                                    reversed; warn if missing (total>=0.01);
 *                                    info if total<0.01 (no GL expected).
 *                     · cancelled  → pass if journal_entry_id set AND JE
 *                                    is_reversed=true (proper reversal);
 *                                    warn if JE not reversed.
 *
 *   5. reversed    — only when is_reversed=true: pass if reverse_reason is
 *                     non-empty; warn otherwise (legacy behaviour).
 *
 * Each check returns: id, title, expected (description), status (pass/warn/
 * fail/info), detail (human-readable evidence). The summary tallies
 * pass/warn/fail so the UI can show a headline (e.g. "3 pass · 1 warn").
 */
class DamageIntegrityService
{
    /** Tolerance for total_value reconciliation (BDT). */
    private const TOTAL_VALUE_TOLERANCE = 0.02;

    /**
     * Run all integrity checks for a single damage invoice.
     *
     * Read-only: performs indexed SELECTs only. Safe to call on every
     * detail-page render. Never throws — a check that errors is reported
     * as `fail` with the error message in `detail` (so a broken DB state
     * surfaces in the panel instead of crashing the page).
     *
     * Accepts the already-eager-loaded DamageInvoice model from the
     * controller (which loads warehouse.branch, items, journalEntry) so we
     * don't re-query the header. The only extra queries are indexed count/
     * sum lookups on stock_transactions + damage_invoice_items.
     *
     * @param DamageInvoice $damage
     * @return array{items: array<int, array>, summary: array{pass: int, warn: int, fail: int, info: int}}
     */
    public function runChecks(DamageInvoice $damage): array
    {
        $items = [];
        $items[] = $this->checkBranchWarehouse($damage);
        $items[] = $this->checkTotalValue($damage);
        $items[] = $this->checkStockMovements($damage);
        $items[] = $this->checkGlJournal($damage);
        if ($damage->is_reversed) {
            $items[] = $this->checkReversed($damage);
        }

        $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
        foreach ($items as $it) {
            $summary[$it['status']] = ($summary[$it['status']] ?? 0) + 1;
        }

        return ['items' => $items, 'summary' => $summary];
    }

    /**
     * 1. Branch ↔ warehouse consistency.
     *
     * The damage header's branch_id MUST match the warehouse's branch_id.
     * A mismatch breaks RLS (the damage would be invisible to the warehouse's
     * branch users) and indicates a write-path bug. Legacy only *displayed*
     * this; we verify it.
     */
    private function checkBranchWarehouse(DamageInvoice $damage): array
    {
        $warehouse = $damage->warehouse;
        $branchName = $damage->branch?->branch_name ?? '—';
        $warehouseName = $warehouse?->warehouse_name ?? '—';

        if (!$warehouse) {
            return $this->item('branch_wh', 'Branch / warehouse',
                'Damage warehouse must belong to the damage branch.',
                'fail', "Warehouse missing (warehouse_id={$damage->warehouse_id}).");
        }

        $whBranchId = (int) $warehouse->branch_id;
        $dmgBranchId = (int) $damage->branch_id;

        if ($whBranchId === $dmgBranchId) {
            return $this->item('branch_wh', 'Branch / warehouse',
                'Damage warehouse must belong to the damage branch.',
                'pass',
                "{$warehouseName} · {$branchName}");
        }

        return $this->item('branch_wh', 'Branch / warehouse',
            'Damage warehouse must belong to the damage branch.',
            'fail',
            "Mismatch: damage branch #{$dmgBranchId} ({$branchName}) "
            . "≠ warehouse branch #{$whBranchId} ({$warehouseName}).");
    }

    /**
     * 2. total_value reconciliation.
     *
     * header.total_value must equal SUM(items.qty * items.rate), within a
     * 0.02 tolerance (rounding on the 2-dp total). Applies to all states.
     */
    private function checkTotalValue(DamageInvoice $damage): array
    {
        try {
            $lineSum = (float) DB::table('damage_invoice_items')
                ->where('damage_invoice_id', $damage->id)
                ->selectRaw('COALESCE(SUM(qty * rate), 0) AS s')
                ->value('s');
        } catch (\Throwable $e) {
            return $this->item('total_value', 'Damage amount',
                'Header total_value must equal sum of line qty × rate.',
                'fail', 'Lookup failed: ' . $e->getMessage());
        }

        $headerTotal = (float) $damage->total_value;
        $delta = abs($lineSum - $headerTotal);

        if ($delta < self::TOTAL_VALUE_TOLERANCE) {
            return $this->item('total_value', 'Damage amount',
                'Header total_value must equal sum of line qty × rate.',
                'pass',
                'Header ' . number_format($headerTotal, 2)
                . ' · Lines ' . number_format($lineSum, 2));
        }

        return $this->item('total_value', 'Damage amount',
            'Header total_value must equal sum of line qty × rate.',
            'fail',
            'Header ' . number_format($headerTotal, 2)
            . ' · Lines ' . number_format($lineSum, 2)
            . ' · Δ ' . number_format($delta, 2));
    }

    /**
     * 3. Stock movements consistency (state-aware).
     *
     * draft      → info (not yet posted)
     * confirmed  → fail if no active movements; pass with count
     * cancelled  → warn if any active (non-reversed) movements remain
     */
    private function checkStockMovements(DamageInvoice $damage): array
    {
        try {
            $active = (int) DB::table('stock_transactions')
                ->where('reference_type', 'damage')
                ->where('reference_id', $damage->id)
                ->where('is_reversed', false)
                ->count();
            $total = (int) DB::table('stock_transactions')
                ->where('reference_type', 'damage')
                ->where('reference_id', $damage->id)
                ->count();
        } catch (\Throwable $e) {
            return $this->item('stock', 'Stock movements',
                'Negative-qty movements for each damaged line (on confirm).',
                'fail', 'Lookup failed: ' . $e->getMessage());
        }

        if ($damage->isDraft()) {
            return $this->item('stock', 'Stock movements',
                'Negative-qty movements for each damaged line (on confirm).',
                'info',
                'Not yet posted — draft does not move stock.');
        }

        if ($damage->isConfirmed()) {
            if ($active > 0) {
                return $this->item('stock', 'Stock movements',
                    'Negative-qty movements for each damaged line (on confirm).',
                    'pass', "{$active} active movement(s).");
            }
            return $this->item('stock', 'Stock movements',
                'Negative-qty movements for each damaged line (on confirm).',
                'fail', 'Missing — confirmed damage has no active stock movements.');
        }

        // cancelled (was confirmed → reversed)
        if ($active > 0) {
            return $this->item('stock', 'Stock movements',
                'All damage stock movements should be reversed on cancel.',
                'warn',
                "{$active} of {$total} movement(s) still active (not reversed).");
        }
        return $this->item('stock', 'Stock movements',
            'All damage stock movements should be reversed on cancel.',
            'pass', "{$total} movement(s), all reversed.");
    }

    /**
     * 4. GL journal consistency (state-aware).
     *
     * draft      → info (not yet posted)
     * confirmed  → pass if JE set + not reversed; warn if missing (total>=0.01);
     *              info if total<0.01 (no GL expected)
     * cancelled  → pass if JE set + reversed; warn if JE not reversed
     */
    private function checkGlJournal(DamageInvoice $damage): array
    {
        $total = (float) $damage->total_value;
        $jeId = $damage->journal_entry_id;
        $je = $damage->journalEntry;

        if ($damage->isDraft()) {
            return $this->item('gl', 'Damage GL',
                'Dr loss / Cr inventory journal posted on confirm (when total ≥ 0.01).',
                'info', 'Not yet posted — draft does not post GL.');
        }

        if ($damage->isConfirmed()) {
            if ($total < 0.01) {
                return $this->item('gl', 'Damage GL',
                    'Dr loss / Cr inventory journal posted on confirm (when total ≥ 0.01).',
                    'info', 'No GL expected — total value < 0.01.');
            }
            if ($jeId && $je && !$je->is_reversed) {
                return $this->item('gl', 'Damage GL',
                    'Dr loss / Cr inventory journal posted on confirm (when total ≥ 0.01).',
                    'pass', 'Journal #' . $je->entry_no . ' (id ' . $jeId . ').');
            }
            return $this->item('gl', 'Damage GL',
                'Dr loss / Cr inventory journal posted on confirm (when total ≥ 0.01).',
                'warn', 'Missing (re-post?) — confirmed damage has no active journal entry.');
        }

        // cancelled (was confirmed → reversed)
        if ($jeId && $je && $je->is_reversed) {
            return $this->item('gl', 'Damage GL',
                'Journal entry should be reversed on cancel.',
                'pass', 'Journal #' . $je->entry_no . ' reversed.');
        }
        if ($jeId && $je && !$je->is_reversed) {
            return $this->item('gl', 'Damage GL',
                'Journal entry should be reversed on cancel.',
                'warn', 'Journal #' . $je->entry_no . ' NOT reversed.');
        }
        // No JE on a cancelled damage — only a problem if it was ever confirmed
        // with a non-trivial total. Draft→cancelled skips GL entirely (correct).
        if ($total >= 0.01) {
            return $this->item('gl', 'Damage GL',
                'Journal entry should be reversed on cancel.',
                'warn', 'No journal entry on record for a cancelled damage with total ≥ 0.01.');
        }
        return $this->item('gl', 'Damage GL',
            'Journal entry should be reversed on cancel.',
            'info', 'No GL expected — total value < 0.01.');
    }

    /**
     * 5. Reversal reason (only when is_reversed).
     *
     * A reversed damage must carry a reverse_reason (legacy behaviour).
     * Missing reason → warn (the reversal happened but wasn't documented).
     */
    private function checkReversed(DamageInvoice $damage): array
    {
        $reason = trim((string) $damage->reverse_reason);
        $by = $damage->reversed_by ? ' · by user #' . $damage->reversed_by : '';

        if ($reason !== '') {
            return $this->item('reversed', 'Reversed',
                'Reversed damage must carry a reverse reason.',
                'pass', $reason . $by);
        }
        return $this->item('reversed', 'Reversed',
            'Reversed damage must carry a reverse reason.',
            'warn', 'No reverse reason recorded' . $by);
    }

    /**
     * Build a single check result row.
     *
     * @param string      $id       Stable check identifier (e.g. 'total_value').
     * @param string      $title    Short human title.
     * @param string      $expected What the check verifies (tooltip/help text).
     * @param string      $status   pass|warn|fail|info
     * @param string|null $detail   Human-readable evidence.
     */
    private function item(
        string $id,
        string $title,
        string $expected,
        string $status,
        ?string $detail = null
    ): array {
        return [
            'id'       => $id,
            'title'    => $title,
            'expected' => $expected,
            'status'   => $status,
            'detail'   => $detail ?? '',
        ];
    }
}

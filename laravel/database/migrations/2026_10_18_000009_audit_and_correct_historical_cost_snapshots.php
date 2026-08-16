<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2 / Session 9 — Audit and correct historical `cost_rate`
 * snapshots on `sales_invoice_items` that were populated by the
 * buggy S5 `getActiveCostRate()` filter.
 *
 * Background
 * ==========
 * Session 5 introduced `sales_invoice_items.cost_rate` as a snapshot
 * of the locked inter-branch transfer cost (what the selling branch
 * PAID when it received the goods via a branch demand). The snapshot
 * is populated at finalize time by
 * `BranchDemandService::getActiveCostRate($branchId, $productId)`,
 * which queries `branch_demand_items` for the FIFO-oldest open row
 * matching the selling branch + product.
 *
 * The S5 implementation filtered on `bd.to_branch_id = $branchId`.
 * Per the codebase convention (see `BranchDemandService` class
 * docblock lines 42-43), `to_branch_id` is the SUPPLIER, not the
 * receiver. So the S5 query returned the cost OTHER branches paid
 * when THIS branch supplied them — the WRONG cost for the selling
 * branch's own sales.
 *
 * The S5 backfill migration `2026_10_17_000005` has the same bug at
 * line 106 (`WHERE bd.to_branch_id = si_inner.branch_id`). So both
 * the runtime snapshot AND the historical backfill are wrong.
 *
 * Session 7 fixed the schema (added `bdi.receiving_branch_id`
 * denormalized from `from_branch_id`) and the S7 backfill migration
 * `2026_10_18_000008` correctly used `bdi.receiving_branch_id` when
 * linking historical sale lines to demand items. So the
 * `sales_invoice_items.branch_demand_item_id` link itself is correct.
 *
 * Session 9 (this migration) closes the loop:
 *   - The runtime bug is fixed in `BranchDemandService::getActiveCostRate()`
 *     (filter changed from `bd.to_branch_id` → `bdi.receiving_branch_id`
 *     + added `consumed_qty < qty` open-qty filter).
 *   - This migration re-derives `cost_rate` for every historical
 *     sale line that has a `branch_demand_item_id` link, by copying
 *     the linked `branch_demand_items.cost_rate`. This is the
 *     canonical, accurate cost — it's the cost the SELLING branch
 *     actually paid for THAT specific demand item (not a FIFO guess).
 *
 * Strategy
 * ========
 * Single `UPDATE ... FROM ...` statement joining
 * `sales_invoice_items` to `branch_demand_items` on the S7 link.
 * Only touches rows where `branch_demand_item_id IS NOT NULL` AND
 * the linked demand item's `cost_rate` differs from the snapshot
 * (avoids touching rows that were already correct — e.g. post-S7
 * sales where the snapshot was set correctly by S7-integrated
 * `finalizeFromCart`).
 *
 * Idempotency
 * ===========
 * Re-runnable. Each pass only updates rows where the snapshot
 * differs from the linked demand item's cost_rate. After the first
 * successful run, the second run is a no-op (all snapshots match).
 *
 * Rows NOT touched
 * ================
 * Sale lines with `branch_demand_item_id IS NULL` are not touched
 * by this migration. These are rows that the S7 backfill could not
 * attribute (typically direct supplier purchases, or demand items
 * that were fully consumed by later sales). Their `cost_rate`
 * remains as the S5 fallback (`products.purchase_rate`), which is
 * the best available estimate for those rows.
 *
 * @see \App\Services\BranchDemand\BranchDemandService::getActiveCostRate()
 *      (S9 fix applied to the runtime path)
 * @see database/migrations/2026_10_17_000005_backfill_price_classification.php
 *      (S5 backfill — buggy `to_branch_id` filter at line 106)
 * @see database/migrations/2026_10_18_000008_backfill_branch_demand_item_id.php
 *      (S7 backfill — correctly used `receiving_branch_id`)
 * @see docs/IMPLEMENTATION_PLAN_SESSION8_CONFIRMATION.md
 *      Known Limitations item #3 (S5 inconsistency)
 * @see docs/IMPLEMENTATION_PLAN_SESSION9_CONFIRMATION.md
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Pre-correction audit: how many rows are wrong, and by how much? ──
        //
        // We compute the absolute delta between the existing snapshot
        // and the linked demand item's cost_rate. Rows with delta > 0.01
        // (matching the PriceClassifier EPSILON tolerance) are
        // considered "wrong" and will be corrected.
        //
        // The audit is logged for traceability — the dev team / DBA
        // can review which sale lines were affected and by how much.
        $audit = DB::selectOne(<<<SQL
SELECT
    COUNT(*) AS total_linked_rows,
    COUNT(*) FILTER (
        WHERE ABS(sii.cost_rate - bdi.cost_rate) > 0.01
    ) AS rows_to_correct,
    ROUND(
        MAX(ABS(sii.cost_rate - bdi.cost_rate))::numeric, 4
    ) AS max_delta,
    ROUND(
        AVG(ABS(sii.cost_rate - bdi.cost_rate))::numeric
            FILTER (WHERE ABS(sii.cost_rate - bdi.cost_rate) > 0.01),
        4
    ) AS avg_delta_among_wrong
FROM sales_invoice_items AS sii
JOIN branch_demand_items AS bdi ON bdi.id = sii.branch_demand_item_id
WHERE sii.branch_demand_item_id IS NOT NULL
SQL);

        $totalLinked  = (int) ($audit->total_linked_rows ?? 0);
        $toCorrect    = (int) ($audit->rows_to_correct ?? 0);
        $maxDelta     = $audit->max_delta ?? '0';
        $avgDeltaWrong = $audit->avg_delta_among_wrong ?? '0';

        Log::info('S9 cost-snapshot audit (pre-correction)', [
            'total_linked_rows'    => $totalLinked,
            'rows_to_correct'      => $toCorrect,
            'max_delta'            => $maxDelta,
            'avg_delta_among_wrong' => $avgDeltaWrong,
        ]);

        echo "S9 cost-snapshot audit:\n";
        echo "  total sale lines with branch_demand_item_id link: {$totalLinked}\n";
        echo "  rows with wrong cost_rate snapshot (delta > 0.01): {$toCorrect}\n";
        echo "  max |delta|: {$maxDelta}\n";
        echo "  avg |delta| among wrong rows: {$avgDeltaWrong}\n";

        if ($toCorrect === 0) {
            echo "  No corrections needed — all snapshots match the linked demand item.\n";
            Log::info('S9 cost-snapshot correction: no-op (all snapshots already correct).');
            return;
        }

        // ── Correction: re-derive cost_rate from the linked demand item ──
        //
        // PostgreSQL UPDATE ... FROM ... WHERE pattern. The target table
        // (sii) is NOT re-listed in FROM (only the source bdi is). The
        // join lives in WHERE. This is the same pattern used by the S2
        // backfill migration (which works) — see
        // 2026_10_16_000002_backfill_fiscal_year_id.php.
        //
        // We only touch rows where the snapshot differs from the linked
        // demand item's cost_rate by more than 0.01 (the PriceClassifier
        // EPSILON tolerance). This avoids a no-op UPDATE on rows that
        // were already correct (e.g. post-S7 sales), keeping the
        // transaction log small and the migration fast.
        //
        // We do NOT touch rows where cost_rate IS NULL — those are
        // "cost unknown" rows (no demand item link, no fallback). They
        // remain NULL and the S8 P&L report flags them as "cost unknown".
        $corrected = DB::affectingStatement(<<<SQL
UPDATE sales_invoice_items AS sii
SET cost_rate = bdi.cost_rate,
    updated_at = CURRENT_TIMESTAMP
FROM branch_demand_items AS bdi
WHERE bdi.id = sii.branch_demand_item_id
  AND sii.branch_demand_item_id IS NOT NULL
  AND sii.cost_rate IS NOT NULL
  AND ABS(sii.cost_rate - bdi.cost_rate) > 0.01
SQL);

        Log::info('S9 cost-snapshot correction applied', [
            'rows_corrected' => $corrected,
        ]);

        echo "  corrected {$corrected} row(s).\n";

        // ── Post-correction audit: confirm zero deltas remain ──
        $postAudit = DB::selectOne(<<<SQL
SELECT
    COUNT(*) FILTER (
        WHERE ABS(sii.cost_rate - bdi.cost_rate) > 0.01
    ) AS remaining_wrong
FROM sales_invoice_items AS sii
JOIN branch_demand_items AS bdi ON bdi.id = sii.branch_demand_item_id
WHERE sii.branch_demand_item_id IS NOT NULL
SQL);

        $remaining = (int) ($postAudit->remaining_wrong ?? 0);
        if ($remaining > 0) {
            Log::warning('S9 cost-snapshot correction: rows still wrong after pass', [
                'remaining_wrong' => $remaining,
            ]);
            echo "  WARNING: {$remaining} row(s) still have a delta > 0.01 after correction.\n";
            echo "  This should not happen — investigate manually.\n";
        } else {
            echo "  Post-correction audit: 0 rows with delta > 0.01. Correction verified.\n";
        }
    }

    public function down(): void
    {
        // No-op. The original (buggy) cost_rate snapshots are not
        // worth restoring — they were wrong. If you need to roll back
        // S9 entirely, restore from a pre-S9 database backup. The
        // runtime fix in BranchDemandService::getActiveCostRate() is
        // also a one-way correction (the old behavior was a bug, not
        // a feature).
        echo "S9 cost-snapshot correction down(): no-op (the old snapshots were buggy).\n";
        Log::info('S9 cost-snapshot correction down(): no-op.');
    }
};

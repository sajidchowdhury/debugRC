<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2 / Session 7 — Backfill `sales_invoice_items.branch_demand_item_id`
 * for historical sale lines, and atomically bump `consumed_qty` on the
 * linked demand items.
 *
 * Goal: every historical sale line created BEFORE S7 (which left
 * branch_demand_item_id NULL) should now be attributed to the
 * demand item that supplied the goods, using the FIFO rule
 * against the demand items that were OPEN at the sale date.
 *
 * Strategy:
 *   - For each historical sales_invoice_items row where
 *     branch_demand_item_id IS NULL:
 *     1. Find candidate demand items in the SELLING BRANCH (sales_invoices
 *        .branch_id == branch_demand_items.receiving_branch_id) for the
 *        same product, where the demand was 'received' on or before
 *        the sale date, ordered oldest-first.
 *     2. Try to allocate the sale qty across these candidates using
 *        the same FIFO logic as DemandItemFifoResolver::consume(),
 *        BUT replaying history — only consume from demand items
 *        that had un-consumed qty AT THE TIME OF SALE, considering
 *        all prior historical sale lines that have already been
 *        attributed in this backfill pass.
 *     3. If allocation succeeds (fully covers the sale qty), UPDATE
 *        the sales_invoice_items row with the FIRST demand item id
 *        (single-line case) and bump consumed_qty on each
 *        allocated demand item.
 *     4. Multi-demand-item splits are NOT performed for historical
 *        rows — that would create new sales_invoice_items rows
 *        and break historical reporting. Instead, the sale line
 *        links to the FIRST demand item that contributed (the
 *        oldest), and consumed_qty is bumped proportionally on
 *        each contributing demand item. This is a known
 *        approximation for historical data only; new sales (post-S7)
 *        get the proper split.
 *     5. If allocation fails (insufficient open qty), log a warning
 *        and leave branch_demand_item_id NULL — the line is
 *        "unattributable" (typically a direct supplier purchase
 *        or a demand item that was fully consumed by a later sale).
 *
 * Performance:
 *   - Processes rows in batches of 1000 with sleep(0.1) between
 *     batches to avoid locking the table.
 *   - Each batch is wrapped in its own transaction.
 *   - The partial index `idx_bdi_fifo_open` (created in the S7
 *     schema migration) keeps the candidate lookup fast.
 *
 * Idempotency:
 *   - The migration only touches rows where branch_demand_item_id
 *     IS NULL. Re-running after partial failure resumes from
 *     where it left off.
 *   - The consumed_qty bumps are wrapped in the same transaction
 *     as the branch_demand_item_id UPDATE — atomic per row.
 *
 * @see database/migrations/2026_10_18_000007_add_consumed_qty_to_branch_demand_items.php
 * @see \App\Services\DemandItemFifoResolver
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 7
 */
return new class extends Migration
{
    private const BATCH_SIZE = 1000;
    private const SLEEP_BETWEEN_BATCHES = 100_000; // microseconds = 0.1s

    public function up(): void
    {
        $totalRows = DB::table('sales_invoice_items')
            ->whereNull('branch_demand_item_id')
            ->count();

        if ($totalRows === 0) {
            echo "S7 backfill: no historical sale lines need attribution.\n";
            Log::info('S7 backfill: no historical sale lines need attribution.');
            return;
        }

        echo "S7 backfill: {$totalRows} historical sale line(s) to attribute.\n";
        Log::info('S7 backfill: starting', ['total_rows' => $totalRows]);

        $attributed = 0;
        $unattributable = 0;
        $processed = 0;
        $batchNumber = 0;

        // Process in batches ordered by invoice_date + sii.id (oldest first)
        // so the FIFO allocation respects historical chronology. Earlier
        // sale lines consume demand item qty before later sale lines are
        // processed.
        //
        // Note: sales_invoice_items does NOT have an invoice_date column
        // — it lives on the parent sales_invoices. We join to get it.
        // The sales_invoice_id JOIN is non-declarative (sales_invoices
        // is partitioned, so a declarative FK from sii.sales_invoice_id
        // to si(id) is not supported — see 04_sales.sql comment).
        $lastId = 0;
        while (true) {
            $batchNumber++;

            $rows = DB::table('sales_invoice_items as sii')
                ->join('sales_invoices as si', 'si.id', '=', 'sii.sales_invoice_id')
                ->whereNull('sii.branch_demand_item_id')
                ->where('sii.id', '>', $lastId)
                ->orderBy('si.invoice_date', 'asc')
                ->orderBy('sii.id', 'asc')
                ->limit(self::BATCH_SIZE)
                ->get([
                    'sii.id',
                    'sii.sales_invoice_id',
                    'sii.product_id',
                    'sii.qty',
                    'si.branch_id',
                    'si.invoice_date',
                ]);

            if ($rows->isEmpty()) {
                break;
            }

            DB::transaction(function () use ($rows, &$attributed, &$unattributable, &$processed, &$lastId) {
                foreach ($rows as $row) {
                    $lastId = $row->id;
                    $processed++;

                    $result = $this->attributeHistoricalLine(
                        (int) $row->id,
                        (int) $row->branch_id,
                        (int) $row->product_id,
                        (float) $row->qty,
                        $row->invoice_date
                    );

                    if ($result) {
                        $attributed++;
                    } else {
                        $unattributable++;
                    }
                }
            });

            // Sleep between batches to avoid holding locks too long.
            usleep(self::SLEEP_BETWEEN_BATCHES);

            echo "  batch {$batchNumber}: processed={$processed} attributed={$attributed} unattributable={$unattributable}\n";
        }

        $coverage = $totalRows > 0 ? round(($attributed / $totalRows) * 100, 1) : 0;
        echo "S7 backfill complete:\n";
        echo "  total historical lines: {$totalRows}\n";
        echo "  attributed: {$attributed} ({$coverage}%)\n";
        echo "  unattributable: {$unattributable}\n";
        echo "  target coverage: >= 80% (acceptance test S7)\n";

        Log::info('S7 backfill complete', [
            'total' => $totalRows,
            'attributed' => $attributed,
            'unattributable' => $unattributable,
            'coverage_pct' => $coverage,
        ]);
    }

    public function down(): void
    {
        // Reverse the backfill: clear branch_demand_item_id on all
        // rows (we can't distinguish backfilled from post-S7 rows
        // without a marker column, so we leave post-S7 rows alone —
        // only clear rows that have branch_demand_item_id set AND
        // whose consumed_qty bump should be reversed).
        //
        // In practice, this down() method is a no-op: rolling back
        // S7's schema migration will DROP the consumed_qty column
        // and the branch_demand_item_id column entirely (via the
        // S5 migration's down(), since S5 created that column).
        // There's no value in restoring NULL to historical rows that
        // are about to lose the column itself.
        //
        // The forward-only nature is documented in the class
        // docblock: this migration is a one-shot replay. If you
        // need to re-run it (e.g. after a partial failure
        // followed by a rollback), use `php artisan migrate:fresh`
        // or restore from a backup.
        echo "S7 backfill down(): no-op (column drops handled by sibling schema migration).\n";
    }

    /**
     * Attempt to attribute one historical sale line to demand item(s).
     * Returns true if attribution succeeded, false if unattributable.
     */
    private function attributeHistoricalLine(
        int $saleItemId,
        int $branchId,
        int $productId,
        float $qty,
        string $invoiceDate
    ): bool {
        // Find candidate demand items: open (consumed_qty < qty),
        // same receiving branch, same product, demand_date <= invoice_date,
        // demand status = 'received' (goods shipped to the branch).
        $candidates = DB::table('branch_demand_items as bdi')
            ->join('branch_demands as bd', 'bd.id', '=', 'bdi.branch_demand_id')
            ->where('bdi.receiving_branch_id', $branchId)
            ->where('bdi.product_id', $productId)
            ->where('bd.status', 'received')
            ->where('bd.is_reversed', false)
            ->where('bd.demand_date', '<=', $invoiceDate)
            ->whereColumn('bdi.consumed_qty', '<', 'bdi.qty')
            ->orderBy('bd.demand_date', 'asc')
            ->orderBy('bdi.id', 'asc')
            ->lockForUpdate()
            ->get([
                'bdi.id',
                'bdi.qty',
                'bdi.consumed_qty',
            ]);

        if ($candidates->isEmpty()) {
            return false;
        }

        $totalAvailable = $candidates->sum(fn ($r) => (float) $r->qty - (float) $r->consumed_qty);
        if ($totalAvailable + 0.001 < $qty) {
            // Insufficient open qty at the time of sale — unattributable.
            return false;
        }

        // Allocate FIFO across the candidates.
        $remaining = $qty;
        $firstDemandItemId = null;
        $updates = [];

        foreach ($candidates as $row) {
            if ($remaining <= 0.001) {
                break;
            }
            $available = (float) $row->qty - (float) $row->consumed_qty;
            if ($available <= 0.001) {
                continue;
            }
            $take = min($available, $remaining);
            if ($firstDemandItemId === null) {
                $firstDemandItemId = (int) $row->id;
            }
            $updates[] = ['id' => (int) $row->id, 'take' => $take];
            $remaining -= $take;
        }

        if ($firstDemandItemId === null) {
            return false;
        }

        // Apply the consumed_qty bumps atomically.
        foreach ($updates as $u) {
            DB::table('branch_demand_items')
                ->where('id', $u['id'])
                ->update([
                    'consumed_qty' => DB::raw('consumed_qty + ' . $this->numericLiteral($u['take'])),
                    'consumed_qty_updated_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        // Link the sale line to the FIRST (oldest) demand item.
        // Historical rows are NOT split across multiple demand items
        // (see class docblock for rationale).
        DB::table('sales_invoice_items')
            ->where('id', $saleItemId)
            ->update(['branch_demand_item_id' => $firstDemandItemId]);

        return true;
    }

    private function numericLiteral(float $v): string
    {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }
};

<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FIFO resolver for branch_demand_items → sales_invoice_items linkage.
 *
 * Session 7 — Demand-Item Linkage.
 *
 * Goal: when a sale is finalized, determine WHICH demand item(s) supplied
 * the goods being sold, and atomically mark that qty as "consumed" on
 * each demand item so the same qty can't be attributed twice.
 *
 * FIFO rule: among all open (un-consumed) demand items for the same
 * (receiving_branch_id, product_id), the OLDEST one (lowest id — and
 * id is monotonically increasing with demand_date, so id ordering ≈
 * chronological) is consumed first. If the oldest doesn't have enough
 * remaining, the resolver spills into the next-oldest, and so on.
 *
 * Concurrency:
 *   - `consume()` MUST be called inside a DB transaction. It uses
 *     `SELECT ... FOR UPDATE` on the candidate demand items to
 *     serialize concurrent finalize calls for the same
 *     (branch, product). Two concurrent sales of the same product
 *     from the same branch will NOT lost-update consumed_qty.
 *   - `release()` MUST also be called inside a DB transaction (it
 *     locks the demand item rows it touches).
 *
 * Failure modes:
 *   - If the requested qty exceeds the total open qty across all
 *     matching demand items, `consume()` returns an EMPTY array
 *     (rather than throwing). The caller (`SalesInvoiceService`)
 *     interprets this as "stock exists but no demand item is open"
 *     — typically a direct supplier purchase by the branch — and
 *     leaves `sales_invoice_items.branch_demand_item_id` NULL.
 *     The line is still created; the report will mark it as
 *     "cost unknown / direct purchase".
 *   - If `$qty <= 0`, `consume()` returns `[]` without touching
 *     the DB (defensive — caller should not pass 0).
 *
 * Numeric precision:
 *   - `qty` on branch_demand_items is numeric(14,4); `consumed_qty`
 *     is numeric(14,3) (added in S7 migration). The resolver works
 *     in float internally but writes back as numeric — the CHECK
 *     constraint `consumed_qty BETWEEN 0 AND qty` will reject
 *     over-consumption.
 *   - An EPSILON of 0.001 is used for the "fully consumed" check
 *     to avoid float dust (e.g., 0.0000001 remaining is treated as 0).
 *
 * @see \App\Services\Sales\SalesInvoiceService::finalizeFromCart()
 * @see \App\Services\Sales\SalesReturnService::confirmReturn()
 * @see database/migrations/2026_10_18_000007_add_consumed_qty_to_branch_demand_items.php
 */
class DemandItemFifoResolver
{
    /** Float dust tolerance for "is this demand item fully consumed?" */
    private const EPSILON = 0.001;

    /**
     * Atomically consume `$qty` units from the oldest open demand items
     * for (branchId, productId). Returns a list of
     * `['demand_item_id' => int, 'qty' => float]` pairs whose `qty`
     * values sum to `$qty` (or empty array if insufficient open qty).
     *
     * MUST be called inside a DB transaction. The caller is responsible
     * for committing/rolling back.
     *
     * @param  int    $branchId   The selling branch (== receiving_branch_id on the demand item).
     * @param  int    $productId  The product being sold.
     * @param  float  $qty        The qty being sold (must be > 0).
     * @return array<int, array{demand_item_id: int, qty: float}>
     */
    public function consume(int $branchId, int $productId, float $qty): array
    {
        if ($qty <= 0 || $branchId <= 0 || $productId <= 0) {
            return [];
        }

        // Lock candidate demand items FOR UPDATE. This serializes
        // concurrent consume() calls for the same (branch, product)
        // — the second caller blocks until the first commits, then
        // sees the updated consumed_qty values.
        //
        // We deliberately do NOT use a LIMIT clause here — PG's FOR
        // UPDATE with LIMIT can return a non-deterministic subset
        // under concurrent access, which would break FIFO. Instead
        // we pull all open items (the partial index keeps this fast
        // — typically 1-5 rows per (branch, product) in practice).
        $candidates = DB::table('branch_demand_items')
            ->where('receiving_branch_id', $branchId)
            ->where('product_id', $productId)
            ->whereColumn('consumed_qty', '<', 'qty')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get(['id', 'qty', 'consumed_qty']);

        if ($candidates->isEmpty()) {
            // No open demand item — direct supplier purchase case.
            // Caller leaves branch_demand_item_id NULL.
            return [];
        }

        $totalAvailable = $candidates->sum(fn ($r) => (float) $r->qty - (float) $r->consumed_qty);
        if ($totalAvailable + self::EPSILON < $qty) {
            // Insufficient open qty across all matching demand items.
            // This indicates a data-integrity issue: stock exists in
            // the warehouse (the availability check passed in
            // SalesInvoiceService) but no demand item is open. The
            // most likely cause is a direct supplier purchase by the
            // branch (stock_transactions has the IN, but no demand
            // item was created). Return [] and let the caller leave
            // branch_demand_item_id NULL.
            Log::warning('S7 DemandItemFifoResolver::consume — insufficient open qty', [
                'branch_id' => $branchId,
                'product_id' => $productId,
                'requested' => $qty,
                'available' => $totalAvailable,
            ]);
            return [];
        }

        $allocations = [];
        $remaining = $qty;

        foreach ($candidates as $row) {
            if ($remaining <= self::EPSILON) {
                break;
            }

            $available = (float) $row->qty - (float) $row->consumed_qty;
            if ($available <= self::EPSILON) {
                continue; // race-safety: skip fully-consumed rows
            }

            $take = min($available, $remaining);

            DB::table('branch_demand_items')
                ->where('id', $row->id)
                ->update([
                    'consumed_qty' => DB::raw("consumed_qty + " . $this->numericLiteral($take)),
                    'consumed_qty_updated_at' => now(),
                    'updated_at' => now(),
                ]);

            $allocations[] = [
                'demand_item_id' => (int) $row->id,
                'qty' => $take,
            ];

            $remaining -= $take;
        }

        return $allocations;
    }

    /**
     * Read-only variant of {@see consume()} — returns the SAME allocation
     * shape but does NOT mutate `consumed_qty`. Used by cart preview /
     * godown prep to show the user which demand items will be attributed
     * before final commit.
     *
     * No lock is taken (read-only). Two concurrent peeks may return the
     * same allocation; only the eventual `consume()` call is authoritative.
     *
     * @return array<int, array{demand_item_id: int, qty: float}>
     */
    public function peek(int $branchId, int $productId, float $qty): array
    {
        if ($qty <= 0 || $branchId <= 0 || $productId <= 0) {
            return [];
        }

        $candidates = DB::table('branch_demand_items')
            ->where('receiving_branch_id', $branchId)
            ->where('product_id', $productId)
            ->whereColumn('consumed_qty', '<', 'qty')
            ->orderBy('id', 'asc')
            ->get(['id', 'qty', 'consumed_qty']);

        if ($candidates->isEmpty()) {
            return [];
        }

        $totalAvailable = $candidates->sum(fn ($r) => (float) $r->qty - (float) $r->consumed_qty);
        if ($totalAvailable + self::EPSILON < $qty) {
            return [];
        }

        $allocations = [];
        $remaining = $qty;

        foreach ($candidates as $row) {
            if ($remaining <= self::EPSILON) {
                break;
            }
            $available = (float) $row->qty - (float) $row->consumed_qty;
            if ($available <= self::EPSILON) {
                continue;
            }
            $take = min($available, $remaining);
            $allocations[] = [
                'demand_item_id' => (int) $row->id,
                'qty' => $take,
            ];
            $remaining -= $take;
        }

        return $allocations;
    }

    /**
     * Release previously-consumed qty back to the demand items linked
     * to a given sales_invoice_item. Called by SalesReturnService when
     * a return is confirmed (the returned qty goes back to the demand
     * item's pool — it can be re-attributed to a future sale).
     *
     * If `$qty` is NULL, releases the FULL qty stored on the
     * sales_invoice_item row (used when an entire invoice is reversed).
     *
     * If the sales_invoice_item has no `branch_demand_item_id` (direct
     * purchase case), this method is a no-op.
     *
     * MUST be called inside a DB transaction.
     *
     * @param  int       $salesInvoiceItemId
     * @param  float|null $qty  NULL = release the full qty from the sale line.
     * @return array<int, array{demand_item_id: int, qty: float}>  The demand items that were credited (for audit logging).
     */
    public function release(int $salesInvoiceItemId, ?float $qty = null): array
    {
        $line = DB::table('sales_invoice_items')
            ->where('id', $salesInvoiceItemId)
            ->first(['id', 'branch_demand_item_id', 'qty']);

        if (!$line || $line->branch_demand_item_id === null) {
            return [];
        }

        $releaseQty = $qty ?? (float) $line->qty;
        if ($releaseQty <= 0) {
            return [];
        }

        // Cap the release at the line's qty — never release more than
        // what was originally consumed on this sale line.
        $releaseQty = min($releaseQty, (float) $line->qty);

        $demandItemId = (int) $line->branch_demand_item_id;

        // Lock the demand item row to prevent a concurrent consume()
        // from reading stale consumed_qty while we decrement.
        $demandItem = DB::table('branch_demand_items')
            ->where('id', $demandItemId)
            ->lockForUpdate()
            ->first(['id', 'consumed_qty']);

        if (!$demandItem) {
            // Demand item was deleted (rare — demands are reversed, not
            // deleted). Nothing to release.
            Log::warning('S7 DemandItemFifoResolver::release — demand item not found', [
                'sales_invoice_item_id' => $salesInvoiceItemId,
                'demand_item_id' => $demandItemId,
            ]);
            return [];
        }

        // Never decrement below 0 (CHECK constraint would reject it anyway,
        // but we want a clean no-op rather than a constraint violation).
        $currentConsumed = (float) $demandItem->consumed_qty;
        $actualRelease = min($releaseQty, $currentConsumed);

        if ($actualRelease <= self::EPSILON) {
            return [];
        }

        DB::table('branch_demand_items')
            ->where('id', $demandItemId)
            ->update([
                'consumed_qty' => DB::raw("consumed_qty - " . $this->numericLiteral($actualRelease)),
                'consumed_qty_updated_at' => now(),
                'updated_at' => now(),
            ]);

        Log::info('S7 DemandItemFifoResolver::release — released consumed qty', [
            'sales_invoice_item_id' => $salesInvoiceItemId,
            'demand_item_id' => $demandItemId,
            'released_qty' => $actualRelease,
        ]);

        return [
            ['demand_item_id' => $demandItemId, 'qty' => $actualRelease],
        ];
    }

    /**
     * Format a float as a PostgreSQL numeric literal. Avoids locale issues
     * with comma decimal separators (e.g., sprintf('%.3f', 1.5) on some
     * locales produces "1,500" which PG rejects).
     */
    private function numericLiteral(float $v): string
    {
        return rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.');
    }
}

<?php

namespace App\Services\Stock;

use App\Exceptions\StockTakeNegativeStockException;
use App\Models\StockTakeSession;
use App\Models\StockTakeItem;
use App\Services\Accounting\DocumentSequenceService;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Stock\StockTakeAuditLogger;

/**
 * Stock Take Service — Phase 6.4.
 *
 * Workflow (re-derived from inventory audit principles):
 *   1. createSession: header + selected warehouses (status=draft). NO counts yet.
 *   2. setupCounts: for each warehouse, load all active products + their current
 *      system_qty (warehouse_stock.qty) + avg_cost into stock_take_items.
 *   3. saveCount: user enters physical_qty per item. status → counting.
 *   4. postSession: for each item with variance (physical ≠ system):
 *      a. Apply stock movement via StockService (reference_type='stock_take')
 *         - Positive variance (physical > system): stock IN at current avg_cost
 *         - Negative variance (physical < system): stock OUT at current avg_cost
 *      b. Post GL journal (Dr/Cr Inventory vs Shrinkage/Surplus)
 *      c. Mark item is_applied=true
 *      status → posted
 *   5. cancelSession: if posted, reverse stock + GL; if draft/counting, just mark cancelled.
 *
 * The difference column (physical_qty - system_qty) is a PG GENERATED STORED
 * column — the DB computes it, not the app.
 *
 * GL posting rules (same as stock adjustments — re-derived from double-entry):
 *   - Net gain (physical > system): Dr Inventory / Cr Inventory Surplus
 *   - Net loss (physical < system): Dr Inventory Shrinkage / Cr Inventory
 */
class StockTakeService
{
    public function __construct(
        private StockService $stockService,
        private JournalPostingService $journalPosting,
        private StockTakeAuditLogger $auditLogger
    ) {}

    /**
     * Phase 1: Create a stock take session (draft) with selected warehouses.
     *
     * Phase 3: now accepts an optional `freeze_outbound` flag. When true, the
     * covered warehouses are immediately marked is_frozen_for_count=true and
     * StockService::applyTransaction will reject any outbound movement for
     * them while the session is active (draft/counting). frozen_at is set to
     * now() for audit purposes. The flag is released on post/cancel by
     * refreshWarehouseFreezeFlags (which honors overlapping sessions).
     *
     * @param array $data {
     *     branch_id: int,
     *     session_date: string (Y-m-d),
     *     warehouse_ids: array<int>,
     *     notes: string|null,
     *     freeze_outbound: bool (default false),
     *     created_by: int,
     * }
     * @return StockTakeSession
     */
    public function createSession(array $data): StockTakeSession
    {
        if (empty($data['branch_id']) || (int) $data['branch_id'] <= 0) {
            throw new \InvalidArgumentException('branch_id is required.');
        }
        if (empty($data['warehouse_ids']) || !is_array($data['warehouse_ids'])) {
            throw new \InvalidArgumentException('At least one warehouse is required.');
        }

        $sessionCode = $this->generateSessionCode();
        $freezeOutbound = !empty($data['freeze_outbound']);

        return DB::transaction(function () use ($data, $sessionCode, $freezeOutbound) {
            $sessionId = DB::table('stock_take_sessions')->insertGetId([
                'session_code' => $sessionCode,
                'session_date' => $data['session_date'] ?? now()->format('Y-m-d'),
                'branch_id' => (int) $data['branch_id'],
                'status' => 'draft',
                'is_reversed' => false,
                // Phase 3: outbound freeze columns.
                'freeze_outbound' => $freezeOutbound,
                'frozen_at' => $freezeOutbound ? now() : null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $warehouseIds = [];
            foreach ($data['warehouse_ids'] as $wid) {
                $wid = (int) $wid;
                if ($wid <= 0) continue;
                DB::table('stock_take_warehouses')->insert([
                    'stock_take_session_id' => $sessionId,
                    'warehouse_id' => $wid,
                    'status' => 'pending',
                ]);
                $warehouseIds[] = $wid;
            }

            // Phase 3: if outbound freeze is on, mark the covered warehouses
            // as frozen for count. refreshWarehouseFreezeFlags recomputes the
            // flag from the set of active freezing sessions, so it correctly
            // handles the case where another session already froze the same
            // warehouse (flag stays true) or this is the first (flag set true).
            if ($freezeOutbound && !empty($warehouseIds)) {
                $this->refreshWarehouseFreezeFlags($warehouseIds);
            }

            // Phase 2: audit-log the creation (same transaction).
            $this->auditLogger->log(
                session:    ['id' => $sessionId, 'branch_id' => (int) $data['branch_id']],
                action:     'create',
                fromStatus: null,
                toStatus:   'draft',
                payload:    [
                    'session_code'   => $sessionCode,
                    'session_date'   => $data['session_date'] ?? now()->format('Y-m-d'),
                    'warehouse_ids'  => array_map('intval', $data['warehouse_ids']),
                    'warehouse_count' => count($data['warehouse_ids']),
                    'notes'          => $data['notes'] ?? null,
                    // Phase 3: record the freeze decision in the audit trail.
                    'freeze_outbound' => $freezeOutbound,
                    'frozen_at'       => $freezeOutbound ? now()->toDateTimeString() : null,
                ],
                actorId:    $data['created_by'] ?? null,
            );

            return StockTakeSession::with('warehouses.warehouse', 'branch')->find($sessionId);
        });
    }

    /**
     * Phase 2: Setup counts for a warehouse — load all active products with
     * their current system_qty + avg_cost into stock_take_items.
     *
     * @param int $sessionId
     * @param int $warehouseId
     * @return int Number of items created
     */
    public function setupWarehouseCounts(int $sessionId, int $warehouseId): int
    {
        return DB::transaction(function () use ($sessionId, $warehouseId) {
            // Phase 1: Lock the session row to serialize concurrent counters.
            // Prevents two users from simultaneously setting up counts for the
            // same session (which would cause a delete+insert race on items).
            $session = StockTakeSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found.");
            }

            // Verify the warehouse belongs to this session.
            $stw = DB::table('stock_take_warehouses')
                ->where('stock_take_session_id', $sessionId)
                ->where('warehouse_id', $warehouseId)
                ->first();
            if (!$stw) {
                throw new \RuntimeException("Warehouse {$warehouseId} is not part of session {$sessionId}.");
            }

            // Delete any existing items for this warehouse (re-setup allowed).
            DB::table('stock_take_items')
                ->where('stock_take_session_id', $sessionId)
                ->where('warehouse_id', $warehouseId)
                ->delete();

            // Load all active products + their current warehouse_stock.
            // Phase 3: also fetch product_code + product_name so the snapshot
            // captured below can reconstruct the count even after a product is
            // later renamed or soft-deleted.
            $products = DB::table('products as p')
                ->leftJoin('warehouse_stock as ws', function ($join) use ($warehouseId) {
                    $join->on('ws.product_id', '=', 'p.id')
                         ->where('ws.warehouse_id', '=', $warehouseId);
                })
                ->where('p.is_active', true)
                ->whereNull('p.deleted_at')
                ->select(
                    'p.id as product_id',
                    'p.product_code',
                    'p.product_name',
                    'p.unit',
                    DB::raw('COALESCE(ws.qty, 0) as system_qty'),
                    DB::raw('COALESCE(ws.avg_cost, 0) as rate')
                )
                ->orderBy('p.product_name')
                ->get();

            $rows = [];
            $now = now();
            foreach ($products as $p) {
                $rows[] = [
                    'stock_take_session_id' => $sessionId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $p->product_id,
                    'system_qty' => $p->system_qty,
                    'physical_qty' => $p->system_qty, // default = system (no variance until user enters)
                    'rate' => $p->rate,
                    'reason' => null,
                    'is_applied' => false,
                    'updated_at' => $now,
                ];
            }

            if (!empty($rows)) {
                DB::table('stock_take_items')->insert($rows);
            }

            // Phase 3: capture the count_snapshot for this warehouse — the
            // frozen product list at setup time (product_id, product_code,
            // product_name, unit, system_qty, avg_cost). Stored on the session
            // row as jsonb keyed by warehouse_id, merged across warehouses so a
            // multi-warehouse session accumulates one snapshot per warehouse.
            // This lets the session detail page reconstruct "what the counter
            // saw" months later, even if products are renamed/deleted or stock
            // drifts. Re-setup overwrites that warehouse's slice.
            $this->captureWarehouseSnapshot($sessionId, $warehouseId, $products);

            // Mark warehouse as counting.
            DB::table('stock_take_warehouses')
                ->where('stock_take_session_id', $sessionId)
                ->where('warehouse_id', $warehouseId)
                ->update(['status' => 'counting']);

            // Mark session as counting.
            $fromStatus = $session->status;
            DB::table('stock_take_sessions')
                ->where('id', $sessionId)
                ->update(['status' => 'counting', 'updated_at' => now()]);

            // Phase 2: audit-log the setup (warehouse-scoped action).
            $this->auditLogger->log(
                session:      $session,
                action:       'setup',
                fromStatus:   $fromStatus,
                toStatus:     'counting',
                payload:      [
                    'warehouse_id'   => $warehouseId,
                    'products_loaded' => count($rows),
                    're_setup'       => true, // setup always deletes existing items first
                    // Phase 3: record that a snapshot was captured for this wh.
                    'snapshot_captured' => true,
                ],
                warehouseId:  $warehouseId,
            );

            return count($rows);
        });
    }

    /**
     * Phase 3: Save physical counts for a warehouse.
     *
     * @param int $sessionId
     * @param int $warehouseId
     * @param array $counts [product_id => physical_qty]
     * @return int Number of items updated
     */
    public function saveCounts(int $sessionId, int $warehouseId, array $counts): int
    {
        return DB::transaction(function () use ($sessionId, $warehouseId, $counts) {
            // Phase 1: Lock the session row to serialize concurrent counters.
            // Prevents lost-update when two users save counts simultaneously.
            $session = StockTakeSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found.");
            }

            $updated = 0;
            foreach ($counts as $productId => $physicalQty) {
                $productId = (int) $productId;
                $physicalQty = (float) $physicalQty;
                if ($productId <= 0) continue;

                DB::table('stock_take_items')
                    ->where('stock_take_session_id', $sessionId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->update([
                        'physical_qty' => $physicalQty,
                        'updated_at' => now(),
                    ]);
                $updated++;
            }

            // Mark warehouse as completed.
            DB::table('stock_take_warehouses')
                ->where('stock_take_session_id', $sessionId)
                ->where('warehouse_id', $warehouseId)
                ->update(['status' => 'completed']);

            // Phase 2: audit-log the save (warehouse-scoped; records count
            // of lines saved + which products had reasons attached).
            $this->auditLogger->log(
                session:      $session,
                action:       'save_count',
                fromStatus:   $session->status,
                toStatus:     $session->status, // session status does not change here
                payload:      [
                    'warehouse_id'  => $warehouseId,
                    'lines_saved'   => $updated,
                    'product_ids'   => array_map('intval', array_keys($counts)),
                ],
                warehouseId:  $warehouseId,
            );

            return $updated;
        });
    }

    /**
     * Phase 4: Post the session — apply variances + post GL.
     *
     * For each item where physical ≠ system:
     *   - Apply stock movement via StockService (reference_type='stock_take')
     *   - Mark item is_applied=true
     * Then post a single GL journal for the net gain/loss.
     *
     * @param int $sessionId
     * @param int $postedBy
     * @return StockTakeSession
     * @throws \RuntimeException If not countable, or stock/GL posting fails.
     */
    public function postSession(int $sessionId, int $postedBy): StockTakeSession
    {
        return DB::transaction(function () use ($sessionId, $postedBy) {
            $session = StockTakeSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found.");
            }
            if (!in_array($session->status, ['counting', 'draft'])) {
                throw new \RuntimeException("Only counting/draft sessions can be posted (current: {$session->status}).");
            }

            // Capture the transition's from-status BEFORE any writes mutate
            // the session row (used by the Phase 2 audit log below).
            $fromStatus = $session->status;

            // Phase 1: Guard — all warehouses must be 'completed' before posting.
            // The UI hides the Post button until all warehouses are completed, but
            // this server-side guard closes the "direct POST bypasses UI" hole.
            $incompleteCount = DB::table('stock_take_warehouses')
                ->where('stock_take_session_id', $sessionId)
                ->where('status', '<>', 'completed')
                ->count();
            if ($incompleteCount > 0) {
                throw new \RuntimeException(
                    "All warehouses must be marked 'completed' before posting ({$incompleteCount} warehouse(s) still pending/counting)."
                );
            }

            // Get all items with variance that haven't been applied yet.
            $varianceItems = DB::table('stock_take_items')
                ->where('stock_take_session_id', $sessionId)
                ->where('is_applied', false)
                ->whereRaw('physical_qty <> system_qty')
                ->get();

            // Phase 1: Negative-stock pre-check.
            // For each shortage (difference < 0), verify current warehouse_stock.qty
            // is sufficient. Locks the warehouse_stock rows (FOR UPDATE via the join)
            // so no other transaction can change them between this check and the
            // actual applyTransaction calls below. Throws a friendly exception with
            // the product list instead of letting the DB trigger raise a generic
            // check_violation error partway through the post.
            $this->assertNoNegativeStockOutcomes($sessionId);

            // Phase 3: Reconcile the setup-time snapshot (system_qty) against
            // the LIVE warehouse_stock.qty for every counted product. If stock
            // moved between setup and post (possible when freeze_outbound=false,
            // or via inbound receipts while frozen), the variance computed from
            // the stale system_qty would be wrong. This is a WARNING, not a
            // block — the post still proceeds (the negative-stock pre-check
            // above already prevents corruption); the drift is recorded in the
            // audit payload so reviewers can see exactly which products moved
            // during the count. With freeze_outbound=true this list should be
            // empty by construction for outbound drift (only inbound receipts
            // can have changed live qty).
            $stockDrift = $this->reconcileSnapshotWithLiveStock($sessionId);

            $totalGain = 0.0;
            $totalLoss = 0.0;
            $resolvedRates = []; // item_id => rate (for the deferred is_applied update)

            foreach ($varianceItems as $item) {
                $variance = (float) $item->physical_qty - (float) $item->system_qty;
                $rate = (float) $item->rate;
                if ($rate <= 0) {
                    $rate = $this->stockService->getWarehouseAvgCost(
                        $item->warehouse_id, $item->product_id
                    );
                }

                // Apply the stock movement.
                // Positive variance = IN (use rate for avg_cost recalc).
                // Negative variance = OUT (rate is the cost; avg unchanged).
                $this->stockService->applyTransaction([
                    'warehouse_id' => $item->warehouse_id,
                    'product_id' => $item->product_id,
                    'qty' => $variance, // signed: +IN / -OUT
                    'rate' => $rate,
                    'reference_type' => 'stock_take',
                    'reference_id' => $sessionId,
                    'notes' => 'Stock Take #' . $session->session_code
                        . ($item->reason ? ' — ' . $item->reason : ''),
                    'transaction_date' => $session->session_date->format('Y-m-d'),
                    'created_by' => $postedBy,
                ]);

                // Track gain/loss for GL.
                $value = abs($variance) * $rate;
                if ($variance > 0) {
                    $totalGain += $value;
                } else {
                    $totalLoss += $value;
                }

                $resolvedRates[$item->id] = $rate;
            }

            // Post GL journal (single entry for the net gain/loss).
            $journalEntryId = null;
            $gainInventoryLineId = null;
            $lossInventoryLineId = null;
            if ($totalGain >= 0.01 || $totalLoss >= 0.01) {
                $journalEntryId = $this->postStockTakeGL($session, $totalGain, $totalLoss, $postedBy);

                // Phase 1: Capture per-line journal_line_id for traceability.
                // Query back the journal lines and identify the Inventory-side lines
                // (gain → Dr Inventory; loss → Cr Inventory). Each variance item is
                // linked to the Inventory line of its bucket. This provides basic
                // traceability; full 1:1 per-item lines are deferred to Phase 9.
                $inventoryLedgerId = $this->journalPosting->lookupLedgerByNature('inventory');
                if ($inventoryLedgerId) {
                    $journalLines = DB::table('journal_lines')
                        ->where('journal_entry_id', $journalEntryId)
                        ->get();
                    $gainLine = $journalLines->first(
                        fn($l) => $l->ledger_id == $inventoryLedgerId && $l->debit > 0
                    );
                    $lossLine = $journalLines->first(
                        fn($l) => $l->ledger_id == $inventoryLedgerId && $l->credit > 0
                    );
                    $gainInventoryLineId = $gainLine?->id;
                    $lossInventoryLineId = $lossLine?->id;
                }
            }

            // Phase 1: Mark all variance items as applied + back-link journal_line_id.
            // (Deferred to here — after GL posting — so journal_line_id is available.
            // If the transaction fails before this point, all stock movements and GL
            // inserts are rolled back, so items correctly remain is_applied=false.)
            foreach ($varianceItems as $item) {
                $variance = (float) $item->physical_qty - (float) $item->system_qty;
                $lineId = $variance > 0 ? $gainInventoryLineId : $lossInventoryLineId;
                DB::table('stock_take_items')
                    ->where('id', $item->id)
                    ->update([
                        'is_applied' => true,
                        'rate' => $resolvedRates[$item->id],
                        'journal_line_id' => $lineId,
                        'updated_at' => now(),
                    ]);
            }

            // Mark session as posted.
            DB::table('stock_take_sessions')
                ->where('id', $sessionId)
                ->update([
                    'status' => 'posted',
                    'journal_entry_id' => $journalEntryId,
                    'updated_at' => now(),
                ]);

            // Phase 3: release the outbound freeze on this session's warehouses.
            // A posted session is no longer "actively counting", so its freeze
            // must end. refreshWarehouseFreezeFlags recomputes the flag from
            // ALL remaining active (draft/counting) freezing sessions, so if
            // another overlapping session still covers a warehouse, its flag
            // stays true (acceptance: "flag stays true until the last session
            // ends").
            $this->releaseSessionFreeze($sessionId);

            // Phase 2: audit-log the post (the critical transition). Logged
            // AFTER the session status update, so the to_status='posted'
            // reflects the committed state. If anything in this transaction
            // rolls back, the audit row rolls back with it (same transaction).
            $this->auditLogger->log(
                session:    $session,
                action:     'post',
                fromStatus: $fromStatus,
                toStatus:   'posted',
                payload:    [
                    'variance_lines'   => $varianceItems->count(),
                    'total_gain'       => round($totalGain, 4),
                    'total_loss'       => round($totalLoss, 4),
                    'journal_entry_id' => $journalEntryId,
                    'stock_movements'  => $varianceItems->count(),
                    // Phase 3: surface the stock-drift warning so reviewers can
                    // see which products moved during the count. Empty when the
                    // outbound freeze held for the full count.
                    'stock_drift'      => $stockDrift,
                    'stock_drift_count' => count($stockDrift),
                    'freeze_outbound'  => (bool) $session->freeze_outbound,
                ],
                actorId:    $postedBy,
            );

            return StockTakeSession::with(['warehouses.warehouse', 'branch', 'items.product', 'journalEntry.lines.ledger'])
                ->find($sessionId);
        });
    }

    /**
     * Phase 1: Pre-check that no shortage variance would drive warehouse_stock
     * below zero. Locks the relevant warehouse_stock rows (FOR UPDATE via the
     * join) for the duration of the transaction so the check is race-free.
     *
     * For each shortage item (physical_qty < system_qty), compares the current
     * warehouse_stock.qty against the shortage magnitude. If any would result in
     * a negative qty, throws StockTakeNegativeStockException with the full
     * product list — BEFORE any stock movement is applied.
     *
     * @throws StockTakeNegativeStockException
     */
    private function assertNoNegativeStockOutcomes(int $sessionId): void
    {
        $shortages = DB::table('stock_take_items as sti')
            ->leftJoin('warehouse_stock as ws', function ($join) {
                $join->on('ws.warehouse_id', '=', 'sti.warehouse_id')
                     ->on('ws.product_id', '=', 'sti.product_id');
            })
            ->join('products as p', 'p.id', '=', 'sti.product_id')
            ->where('sti.stock_take_session_id', $sessionId)
            ->where('sti.is_applied', false)
            ->whereRaw('sti.physical_qty < sti.system_qty')
            ->select(
                'sti.product_id',
                'sti.warehouse_id',
                'sti.system_qty',
                'sti.physical_qty',
                DB::raw('COALESCE(ws.qty, 0) as current_qty'),
                'p.product_code',
                'p.product_name'
            )
            ->lockForUpdate()
            ->get();

        $offending = [];
        foreach ($shortages as $s) {
            $variance = (float) $s->physical_qty - (float) $s->system_qty;
            $resultingQty = (float) $s->current_qty + $variance;
            if ($resultingQty < -0.0001) {
                $offending[] = [
                    'product_id' => $s->product_id,
                    'product_code' => $s->product_code,
                    'product_name' => $s->product_name,
                    'warehouse_id' => $s->warehouse_id,
                    'system_qty' => (float) $s->system_qty,
                    'physical_qty' => (float) $s->physical_qty,
                    'current_stock' => (float) $s->current_qty,
                    'shortage' => abs($variance),
                    'resulting_qty' => $resultingQty,
                ];
            }
        }

        if (!empty($offending)) {
            throw new StockTakeNegativeStockException($offending);
        }
    }

    /**
     * Phase 5: Cancel a session.
     * - If posted: reverse stock + GL.
     * - If draft/counting: just mark cancelled.
     *
     * @param int $sessionId
     * @param int $cancelledBy
     * @param string $reason
     * @return StockTakeSession
     */
    public function cancelSession(int $sessionId, int $cancelledBy, string $reason = ''): StockTakeSession
    {
        return DB::transaction(function () use ($sessionId, $cancelledBy, $reason) {
            $session = StockTakeSession::lockForUpdate()->find($sessionId);
            if (!$session) {
                throw new \RuntimeException("Session {$sessionId} not found.");
            }
            if ($session->isCancelled()) {
                throw new \RuntimeException("Session is already cancelled.");
            }

            // Capture the transition's from-status BEFORE any writes (Phase 2).
            $fromStatus = $session->status;
            $wasPosted  = $session->isPosted();
            $stockTxsReversed = 0;
            $journalReversed = false;

            if ($session->isPosted()) {
                // Reverse GL.
                if ($session->journal_entry_id) {
                    $this->journalPosting->reverseJournalEntry(
                        $session->journal_entry_id,
                        $cancelledBy,
                        "Stock take cancelled: {$reason}"
                    );
                    $journalReversed = true;
                }

                // Reverse each stock movement.
                $stockTxs = DB::table('stock_transactions')
                    ->where('reference_type', 'stock_take')
                    ->where('reference_id', $sessionId)
                    ->where('is_reversed', false)
                    ->get();

                foreach ($stockTxs as $tx) {
                    $this->stockService->reverseTransaction(
                        $tx->id, $cancelledBy,
                        "Stock take cancelled: {$reason}"
                    );
                    $stockTxsReversed++;
                }

                DB::table('stock_take_sessions')
                    ->where('id', $sessionId)
                    ->update([
                        'is_reversed' => true,
                        'reversed_at' => now(),
                        'reversed_by' => $cancelledBy,
                        'reverse_reason' => $reason,
                    ]);
            }

            DB::table('stock_take_sessions')
                ->where('id', $sessionId)
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            // Phase 3: release the outbound freeze on this session's warehouses.
            // A cancelled session is no longer "actively counting" — same as a
            // posted session, the freeze must end. If the session was never
            // frozen (freeze_outbound=false) this is a no-op recomputation that
            // leaves flags unchanged. Honors overlapping sessions.
            $this->releaseSessionFreeze($sessionId);

            // Phase 2: audit-log the cancel. For a posted session this is a
            // reversal (logged as action='reverse' so the critical-events
            // partial index catches it); for a draft/counting session this
            // is a plain cancel. Both write a 'cancel' row so the timeline
            // shows the user-facing action; the 'reverse' row (when present)
            // records the underlying stock+GL rollback.
            if ($wasPosted) {
                $this->auditLogger->log(
                    session:    $session,
                    action:     'reverse',
                    fromStatus: $fromStatus,
                    toStatus:   'cancelled',
                    payload:    [
                        'reason'              => $reason,
                        'stock_reversed'      => $stockTxsReversed,
                        'journal_reversed'    => $journalReversed,
                        'journal_entry_id'    => $session->journal_entry_id,
                    ],
                    actorId:    $cancelledBy,
                );
            }

            $this->auditLogger->log(
                session:    $session,
                action:     'cancel',
                fromStatus: $fromStatus,
                toStatus:   'cancelled',
                payload:    [
                    'reason'      => $reason,
                    'was_posted'  => $wasPosted,
                    // Phase 3: record whether the freeze was released.
                    'freeze_outbound' => (bool) $session->freeze_outbound,
                    'freeze_released' => (bool) $session->freeze_outbound,
                ],
                actorId:    $cancelledBy,
            );

            return StockTakeSession::find($sessionId);
        });
    }

    /**
     * Post the GL journal for a stock take session.
     * Single entry with up to 4 lines (gain + loss).
     *
     * @param StockTakeSession $session
     * @param float $totalGain
     * @param float $totalLoss
     * @param int $createdBy
     * @return int journal_entry_id
     */
    private function postStockTakeGL(StockTakeSession $session, float $totalGain, float $totalLoss, int $createdBy): int
    {
        $inventoryLedgerId = $this->journalPosting->lookupLedgerByNature('inventory');
        if (!$inventoryLedgerId) {
            throw new \RuntimeException('Inventory ledger not found (nature: inventory).');
        }

        $lines = [];

        // Gain: Dr Inventory / Cr Surplus
        if ($totalGain >= 0.01) {
            $surplusLedgerId = $this->journalPosting->lookupLedgerByNature('inventory_surplus');
            if (!$surplusLedgerId) {
                throw new \RuntimeException('Inventory surplus ledger not found.');
            }
            $lines[] = [
                'ledger_id' => $inventoryLedgerId,
                'debit' => $totalGain, 'credit' => 0,
                'memo' => 'Stock take gain — ' . $session->session_code,
            ];
            $lines[] = [
                'ledger_id' => $surplusLedgerId,
                'debit' => 0, 'credit' => $totalGain,
                'memo' => 'Stock take surplus — ' . $session->session_code,
            ];
        }

        // Loss: Dr Shrinkage / Cr Inventory
        if ($totalLoss >= 0.01) {
            $shrinkageLedgerId = $this->journalPosting->lookupLedgerByNature('inventory_shrinkage');
            if (!$shrinkageLedgerId) {
                throw new \RuntimeException('Inventory shrinkage ledger not found.');
            }
            $lines[] = [
                'ledger_id' => $shrinkageLedgerId,
                'debit' => $totalLoss, 'credit' => 0,
                'memo' => 'Stock take loss — ' . $session->session_code,
            ];
            $lines[] = [
                'ledger_id' => $inventoryLedgerId,
                'debit' => 0, 'credit' => $totalLoss,
                'memo' => 'Stock take decrease — ' . $session->session_code,
            ];
        }

        return $this->journalPosting->createJournalEntry([
            'entry_date' => $session->session_date->format('Y-m-d'),
            'reference_type' => 'stock_take',
            'reference_id' => $session->id,
            'branch_id' => $session->branch_id,
            'description' => 'Stock Take ' . $session->session_code
                . ($session->notes ? ' — ' . $session->notes : ''),
            'source' => 'stock_take',
            'created_by' => $createdBy,
        ], $lines);
    }

    /**
     * Generate atomic session code: ST-YYYYMMDD-NNNN.
     * Uses DocumentSequenceService with advisory locks (Task 20).
     */
    private function generateSessionCode(): string
    {
        return DocumentSequenceService::nextCode(
            docType:  'stock_take',
            prefix:   'ST',
            datePart: now()->format('Ymd'),
            padLength: 4,
        );
    }

    /**
     * Phase 3: capture the per-warehouse count_snapshot jsonb on the session
     * row. Merges this warehouse's product list (product_id, product_code,
     * product_name, unit, system_qty, avg_cost) into the session-level jsonb
     * keyed by warehouse_id. Re-setup overwrites that warehouse's slice.
     *
     * Must run inside the caller's transaction (it reads-modifies-writes the
     * session row). The session row is already locked by setupWarehouseCounts'
     * lockForUpdate.
     *
     * @param int $sessionId
     * @param int $warehouseId
     * @param \Illuminate\Support\Collection $products  Rows from the products
     *     query in setupWarehouseCounts (product_id, product_code,
     *     product_name, unit, system_qty, rate).
     */
    private function captureWarehouseSnapshot(int $sessionId, int $warehouseId, $products): void
    {
        $slice = [];
        foreach ($products as $p) {
            $slice[] = [
                'product_id'   => (int) $p->product_id,
                'product_code' => $p->product_code,
                'product_name' => $p->product_name,
                'unit'         => $p->unit,
                'system_qty'   => (float) $p->system_qty,
                'avg_cost'     => (float) $p->rate,
            ];
        }

        // Read the raw jsonb (DB::table returns the jsonb column as a string).
        $raw = DB::table('stock_take_sessions')
            ->where('id', $sessionId)
            ->value('count_snapshot');

        $snapshot = $raw ? json_decode($raw, true) : [];
        if (!is_array($snapshot)) {
            $snapshot = [];
        }
        if (!isset($snapshot['warehouses']) || !is_array($snapshot['warehouses'])) {
            $snapshot['warehouses'] = [];
        }

        $snapshot['warehouses'][(string) $warehouseId] = [
            'warehouse_id' => $warehouseId,
            'captured_at'  => now()->toDateTimeString(),
            'product_count' => count($slice),
            'products'     => $slice,
        ];
        // Refresh the top-level captured_at so the latest setup is reflected.
        $snapshot['captured_at'] = now()->toDateTimeString();

        DB::table('stock_take_sessions')
            ->where('id', $sessionId)
            ->update([
                'count_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
    }

    /**
     * Phase 3: reconcile the setup-time snapshot (system_qty on each
     * stock_take_items row) against the LIVE warehouse_stock.qty.
     *
     * Returns a list of products whose live qty has drifted from the snapshot
     * captured at setup — i.e. stock moved between setup and post. Each entry
     * carries the product identity, the snapshot system_qty, the live qty, and
     * the delta (live − snapshot) so the UI can say "product X changed from
     * 10 to 7".
     *
     * This is a WARNING, not a block. The post still proceeds; the drift is
     * recorded in the post audit payload + surfaced on the session detail
     * page. With freeze_outbound=true, outbound drift should be empty by
     * construction; only inbound receipts (purchases, transfers IN) can have
     * changed live qty while frozen.
     *
     * @param int $sessionId
     * @return array<int, array{product_id: int, product_code: string, product_name: string, warehouse_id: int, warehouse_name: string, snapshot_qty: float, live_qty: float, delta: float}>
     */
    private function reconcileSnapshotWithLiveStock(int $sessionId): array
    {
        $rows = DB::table('stock_take_items as sti')
            ->join('products as p', 'p.id', '=', 'sti.product_id')
            ->join('warehouses as w', 'w.id', '=', 'sti.warehouse_id')
            ->leftJoin('warehouse_stock as ws', function ($join) {
                $join->on('ws.warehouse_id', '=', 'sti.warehouse_id')
                     ->on('ws.product_id', '=', 'sti.product_id');
            })
            ->where('sti.stock_take_session_id', $sessionId)
            ->whereRaw('COALESCE(ws.qty, 0) <> sti.system_qty')
            ->select(
                'sti.product_id',
                'p.product_code',
                'p.product_name',
                'sti.warehouse_id',
                'w.warehouse_name',
                'sti.system_qty',
                DB::raw('COALESCE(ws.qty, 0) as live_qty')
            )
            ->orderBy('w.warehouse_name')
            ->orderBy('p.product_name')
            ->get();

        $drift = [];
        foreach ($rows as $r) {
            $snapshot = (float) $r->system_qty;
            $live = (float) $r->live_qty;
            $drift[] = [
                'product_id'     => (int) $r->product_id,
                'product_code'   => $r->product_code,
                'product_name'   => $r->product_name,
                'warehouse_id'   => (int) $r->warehouse_id,
                'warehouse_name' => $r->warehouse_name,
                'snapshot_qty'   => $snapshot,
                'live_qty'       => $live,
                'delta'          => round($live - $snapshot, 4),
            ];
        }
        return $drift;
    }

    /**
     * Phase 3: release the outbound freeze for a session's warehouses.
     *
     * Called on post + cancel (the two terminal transitions) and would be
     * called on delete if a delete flow is added. Recomputes the
     * is_frozen_for_count flag for each of the session's warehouses based on
     * ALL remaining ACTIVE (draft/counting) sessions with freeze_outbound=true
     * that still cover the warehouse — so an overlapping session keeps the
     * flag true until IT ends.
     *
     * @param int $sessionId
     */
    private function releaseSessionFreeze(int $sessionId): void
    {
        $warehouseIds = DB::table('stock_take_warehouses')
            ->where('stock_take_session_id', $sessionId)
            ->pluck('warehouse_id')
            ->all();

        $this->refreshWarehouseFreezeFlags($warehouseIds);
    }

    /**
     * Phase 3: recompute the is_frozen_for_count flag for the given warehouses
     * based on the set of ACTIVE stock-take sessions (status IN draft/counting)
     * with freeze_outbound=true that cover each warehouse.
     *
     * This is the single source of truth for the denormalized flag. It is
     * called from createSession (set true when the first freezing session
     * covers a warehouse) and from releaseSessionFreeze (post/cancel — clear
     * when the last freezing session ends). Idempotent: running it twice with
     * the same state produces the same flags.
     *
     * @param array<int> $warehouseIds
     */
    private function refreshWarehouseFreezeFlags(array $warehouseIds): void
    {
        $warehouseIds = array_values(array_unique(array_filter(array_map('intval', $warehouseIds))));
        if (empty($warehouseIds)) {
            return;
        }

        foreach ($warehouseIds as $wid) {
            // A warehouse is frozen iff at least one ACTIVE (draft/counting)
            // session with freeze_outbound=true covers it.
            $frozen = DB::table('stock_take_warehouses as stw')
                ->join('stock_take_sessions as sts', 'sts.id', '=', 'stw.stock_take_session_id')
                ->where('stw.warehouse_id', $wid)
                ->where('sts.freeze_outbound', true)
                ->whereIn('sts.status', ['draft', 'counting'])
                ->exists();

            DB::table('warehouses')
                ->where('id', $wid)
                ->update([
                    'is_frozen_for_count' => $frozen,
                    'updated_at' => now(),
                ]);
        }
    }
}

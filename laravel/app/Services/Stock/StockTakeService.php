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
     * @param array $data {
     *     branch_id: int,
     *     session_date: string (Y-m-d),
     *     warehouse_ids: array<int>,
     *     notes: string|null,
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

        return DB::transaction(function () use ($data, $sessionCode) {
            $sessionId = DB::table('stock_take_sessions')->insertGetId([
                'session_code' => $sessionCode,
                'session_date' => $data['session_date'] ?? now()->format('Y-m-d'),
                'branch_id' => (int) $data['branch_id'],
                'status' => 'draft',
                'is_reversed' => false,
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($data['warehouse_ids'] as $wid) {
                $wid = (int) $wid;
                if ($wid <= 0) continue;
                DB::table('stock_take_warehouses')->insert([
                    'stock_take_session_id' => $sessionId,
                    'warehouse_id' => $wid,
                    'status' => 'pending',
                ]);
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
            $products = DB::table('products as p')
                ->leftJoin('warehouse_stock as ws', function ($join) use ($warehouseId) {
                    $join->on('ws.product_id', '=', 'p.id')
                         ->where('ws.warehouse_id', '=', $warehouseId);
                })
                ->where('p.is_active', true)
                ->whereNull('p.deleted_at')
                ->select(
                    'p.id as product_id',
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
}

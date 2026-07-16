<?php

namespace App\Services\Stock;

use App\Models\StockTakeSession;
use App\Models\StockTakeItem;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        private JournalPostingService $journalPosting
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
            DB::table('stock_take_sessions')
                ->where('id', $sessionId)
                ->update(['status' => 'counting', 'updated_at' => now()]);

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

            // Get all items with variance that haven't been applied yet.
            $varianceItems = DB::table('stock_take_items')
                ->where('stock_take_session_id', $sessionId)
                ->where('is_applied', false)
                ->whereRaw('physical_qty <> system_qty')
                ->get();

            $totalGain = 0.0;
            $totalLoss = 0.0;

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

                // Mark item as applied.
                DB::table('stock_take_items')
                    ->where('id', $item->id)
                    ->update(['is_applied' => true, 'rate' => $rate, 'updated_at' => now()]);
            }

            // Post GL journal (single entry for the net gain/loss).
            $journalEntryId = null;
            if ($totalGain >= 0.01 || $totalLoss >= 0.01) {
                $journalEntryId = $this->postStockTakeGL($session, $totalGain, $totalLoss, $postedBy);
            }

            // Mark session as posted.
            DB::table('stock_take_sessions')
                ->where('id', $sessionId)
                ->update([
                    'status' => 'posted',
                    'journal_entry_id' => $journalEntryId,
                    'updated_at' => now(),
                ]);

            return StockTakeSession::with(['warehouses.warehouse', 'branch', 'items.product', 'journalEntry.lines.ledger'])
                ->find($sessionId);
        });
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

            if ($session->isPosted()) {
                // Reverse GL.
                if ($session->journal_entry_id) {
                    $this->journalPosting->reverseJournalEntry(
                        $session->journal_entry_id,
                        $cancelledBy,
                        "Stock take cancelled: {$reason}"
                    );
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
     */
    private function generateSessionCode(): string
    {
        $datePart = now()->format('Ymd');
        $periodKey = now()->format('Y-m');
        $docType = 'stock_take';

        return DB::transaction(function () use ($docType, $periodKey, $datePart) {
            $seqRow = DB::table('document_sequences')
                ->where('doc_type', $docType)
                ->where('branch_id', 0)
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            $nextNumber = $seqRow ? ((int) $seqRow->last_number + 1) : 1;

            if ($seqRow) {
                DB::table('document_sequences')->where('id', $seqRow->id)
                    ->update(['last_number' => $nextNumber, 'updated_at' => now()]);
            } else {
                DB::table('document_sequences')->insert([
                    'doc_type' => $docType, 'branch_id' => 0,
                    'period_key' => $periodKey, 'last_number' => $nextNumber,
                    'updated_at' => now(),
                ]);
            }

            return "ST-{$datePart}-" . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
        });
    }
}

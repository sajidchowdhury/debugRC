<?php

namespace App\Services\Stock;

use App\Models\DamageInvoice;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Damage Service — Phase 6.6.
 *
 * Two-phase flow (same as 6.3/6.4/6.5):
 *   1. createDamage(): creates draft (header + items, no stock/GL)
 *   2. confirmDamage(): stock OUT via StockService + GL (Dr Damage Loss / Cr Inventory)
 *   3. cancelDamage(): if confirmed, reverses; if draft, marks cancelled
 *
 * GL posting (re-derived from double-entry):
 *   Dr Damage Loss (nature: damage_loss, fallback: inventory_shrinkage)
 *   Cr Inventory (nature: inventory)
 *   The loss is valued at the current avg_cost at time of damage.
 *
 * Rate semantics (per avg_cost_rule.md §3):
 *   - Stock OUT at current avg_cost (cost flows out at average, avg unchanged)
 */
class DamageService
{
    public function __construct(
        private StockService $stockService,
        private JournalPostingService $journalPosting
    ) {}

    /**
     * Phase 1: Create a draft damage invoice (no stock movement, no GL).
     *
     * @param array $data {
     *     warehouse_id: int,
     *     damage_date: string (Y-m-d),
     *     reason: string|null,
     *     created_by: int,
     *     items: array each { product_id, qty, rate }
     * }
     * @return DamageInvoice
     */
    public function createDamage(array $data): DamageInvoice
    {
        $this->validateCreateInput($data);

        $warehouseId = (int) $data['warehouse_id'];
        $warehouse = DB::table('warehouses')->where('id', $warehouseId)->first();
        if (!$warehouse) {
            throw new \InvalidArgumentException("Warehouse {$warehouseId} not found.");
        }
        $branchId = (int) $warehouse->branch_id;

        $totalValue = 0.0;
        $validatedItems = [];
        foreach ($data['items'] as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (float) ($item['qty'] ?? 0);
            if ($productId <= 0 || $qty <= 0) continue;

            $rate = (float) ($item['rate'] ?? 0);
            if ($rate <= 0) {
                $rate = $this->stockService->getWarehouseAvgCost($warehouseId, $productId);
            }

            $validatedItems[] = [
                'product_id' => $productId,
                'qty' => $qty,
                'rate' => $rate,
            ];
            $totalValue += $qty * $rate;
        }

        if (empty($validatedItems)) {
            throw new \InvalidArgumentException('At least one valid item is required.');
        }

        // Pre-check availability (will be re-checked on confirm).
        foreach ($validatedItems as $item) {
            $available = $this->stockService->getWarehouseQty($warehouseId, $item['product_id']);
            if ($item['qty'] > $available + 0.0001) {
                throw new \RuntimeException(
                    "Insufficient stock for product {$item['product_id']}: "
                    . "available {$available}, requested {$item['qty']}"
                );
            }
        }

        $damageCode = $this->generateDamageCode();

        return DB::transaction(function () use (
            $damageCode, $data, $warehouseId, $branchId, $totalValue, $validatedItems
        ) {
            $damageId = DB::table('damage_invoices')->insertGetId([
                'damage_code' => $damageCode,
                'damage_date' => $data['damage_date'] ?? now()->format('Y-m-d'),
                'warehouse_id' => $warehouseId,
                'branch_id' => $branchId,
                'total_value' => round($totalValue, 2),
                'reason' => trim((string) ($data['reason'] ?? '')),
                'status' => 'draft',
                'is_reversed' => false,
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $itemRows = [];
            foreach ($validatedItems as $item) {
                $itemRows[] = [
                    'damage_invoice_id' => $damageId,
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'rate' => $item['rate'],
                ];
            }
            DB::table('damage_invoice_items')->insert($itemRows);

            return DamageInvoice::with(['items.product', 'warehouse.branch'])->find($damageId);
        });
    }

    /**
     * Phase 2: Confirm a draft damage — apply stock OUT + post GL.
     *
     * @param int $damageId
     * @param int $confirmedBy
     * @return DamageInvoice
     * @throws \RuntimeException If not draft, or stock/GL posting fails.
     */
    public function confirmDamage(int $damageId, int $confirmedBy): DamageInvoice
    {
        return DB::transaction(function () use ($damageId, $confirmedBy) {
            $damage = DamageInvoice::with('items')->lockForUpdate()->find($damageId);

            if (!$damage) {
                throw new \RuntimeException("Damage invoice {$damageId} not found.");
            }
            if (!$damage->isDraft()) {
                throw new \RuntimeException("Only draft damages can be confirmed (current: {$damage->status}).");
            }

            $warehouseId = $damage->warehouse_id;
            $damageDate = $damage->damage_date->format('Y-m-d');

            // Apply stock OUT for each item.
            foreach ($damage->items as $item) {
                $this->stockService->applyTransaction([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $item->product_id,
                    'qty' => -(float) $item->qty, // negative = OUT
                    'rate' => (float) $item->rate, // current avg_cost (cost flows out, avg unchanged)
                    'reference_type' => 'damage',
                    'reference_id' => $damage->id,
                    'notes' => 'Damage #' . $damage->damage_code,
                    'transaction_date' => $damageDate,
                    'created_by' => $confirmedBy,
                ]);
            }

            // Post GL journal.
            $journalEntryId = $this->postDamageGL($damage, $confirmedBy);

            DB::table('damage_invoices')
                ->where('id', $damageId)
                ->update([
                    'status' => 'confirmed',
                    'journal_entry_id' => $journalEntryId,
                    'updated_at' => now(),
                ]);

            return DamageInvoice::with(['items.product', 'warehouse.branch', 'journalEntry.lines.ledger'])
                ->find($damageId);
        });
    }

    /**
     * Phase 3: Cancel a damage invoice.
     * - If confirmed: reverse stock + GL.
     * - If draft: just mark cancelled.
     *
     * @param int $damageId
     * @param int $cancelledBy
     * @param string $reason
     * @return DamageInvoice
     */
    public function cancelDamage(int $damageId, int $cancelledBy, string $reason = ''): DamageInvoice
    {
        return DB::transaction(function () use ($damageId, $cancelledBy, $reason) {
            $damage = DamageInvoice::with('items')->lockForUpdate()->find($damageId);

            if (!$damage) {
                throw new \RuntimeException("Damage invoice {$damageId} not found.");
            }
            if ($damage->isCancelled()) {
                throw new \RuntimeException("Damage invoice is already cancelled.");
            }

            if ($damage->isConfirmed()) {
                // Reverse GL.
                if ($damage->journal_entry_id) {
                    $this->journalPosting->reverseJournalEntry(
                        $damage->journal_entry_id, $cancelledBy,
                        "Damage cancelled: {$reason}"
                    );
                }

                // Reverse each stock movement.
                $stockTxs = DB::table('stock_transactions')
                    ->where('reference_type', 'damage')
                    ->where('reference_id', $damageId)
                    ->where('is_reversed', false)
                    ->get();

                foreach ($stockTxs as $tx) {
                    $this->stockService->reverseTransaction(
                        $tx->id, $cancelledBy,
                        "Damage cancelled: {$reason}"
                    );
                }

                DB::table('damage_invoices')
                    ->where('id', $damageId)
                    ->update([
                        'is_reversed' => true,
                        'reversed_at' => now(),
                        'reversed_by' => $cancelledBy,
                        'reverse_reason' => $reason,
                    ]);
            }

            DB::table('damage_invoices')
                ->where('id', $damageId)
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            return DamageInvoice::find($damageId);
        });
    }

    /**
     * Post the GL journal for a damage invoice.
     *
     * Re-derived GL rule:
     *   Dr Damage Loss / Cr Inventory
     *
     * Looks up damage_loss nature first, falls back to inventory_shrinkage.
     *
     * @param DamageInvoice $damage
     * @param int $createdBy
     * @return int journal_entry_id
     */
    private function postDamageGL(DamageInvoice $damage, int $createdBy): int
    {
        $totalValue = (float) $damage->total_value;

        if ($totalValue < 0.01) {
            return 0; // No GL for zero-value damages
        }

        $inventoryLedgerId = $this->journalPosting->lookupLedgerByNature('inventory');
        if (!$inventoryLedgerId) {
            throw new \RuntimeException('Inventory ledger not found (nature: inventory).');
        }

        // Look up damage_loss first, fall back to inventory_shrinkage.
        $damageLossLedgerId = $this->journalPosting->lookupLedgerByNature('damage_loss');
        if (!$damageLossLedgerId) {
            $damageLossLedgerId = $this->journalPosting->lookupLedgerByNature('inventory_shrinkage');
        }
        if (!$damageLossLedgerId) {
            throw new \RuntimeException('Damage loss / inventory shrinkage ledger not found. Configure nature: damage_loss or inventory_shrinkage.');
        }

        return $this->journalPosting->createJournalEntry([
            'entry_date' => $damage->damage_date->format('Y-m-d'),
            'reference_type' => 'damage',
            'reference_id' => $damage->id,
            'branch_id' => $damage->branch_id,
            'description' => 'Damage Write-off ' . $damage->damage_code
                . ($damage->reason ? ' — ' . $damage->reason : ''),
            'source' => 'damage',
            'created_by' => $createdBy,
        ], [
            [
                'ledger_id' => $damageLossLedgerId,
                'debit' => $totalValue, 'credit' => 0,
                'memo' => 'Damage / write-off — ' . $damage->damage_code,
            ],
            [
                'ledger_id' => $inventoryLedgerId,
                'debit' => 0, 'credit' => $totalValue,
                'memo' => 'Inventory reduction (damaged goods) — ' . $damage->damage_code,
            ],
        ]);
    }

    /**
     * Generate atomic damage code: DMG-YYYYMMDD-NNNN.
     */
    private function generateDamageCode(): string
    {
        $datePart = now()->format('Ymd');
        $periodKey = now()->format('Y-m');
        $docType = 'damage';

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

            return "DMG-{$datePart}-" . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
        });
    }

    private function validateCreateInput(array $data): void
    {
        if (empty($data['warehouse_id']) || (int) $data['warehouse_id'] <= 0) {
            throw new \InvalidArgumentException('warehouse_id is required.');
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new \InvalidArgumentException('At least one item is required.');
        }
    }
}

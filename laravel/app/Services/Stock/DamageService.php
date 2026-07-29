<?php

namespace App\Services\Stock;

use App\Models\DamageInvoice;
use App\Models\DamageInvoiceItem;
use App\Models\DamageReason;
use App\Services\Accounting\DocumentSequenceService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Damage Service — Phase 6.6 + Phase 1 (Damage Category & Reason Taxonomy).
 *
 * Two-phase flow (same as 6.3/6.4/6.5):
 *   1. createDamage(): creates draft (header + items, no stock/GL)
 *   2. confirmDamage(): stock OUT via StockService + GL (Dr Damage Loss / Cr Inventory)
 *   3. cancelDamage(): if confirmed, reverses; if draft, marks cancelled
 *
 * GL posting (re-derived from double-entry):
 *   Dr <loss ledger selected by damage_type> / Cr Inventory
 *   The loss is valued at the current avg_cost at time of damage.
 *
 * Phase 1 — type-aware loss ledger selection (postDamageGL):
 *   real_damage / quality_reject / customer_return / other → damage_loss
 *   missing / theft                                        → inventory_shrinkage
 *   Both natures roll up under Operating Expenses in the P&L (Phase 0 fix),
 *   so the P&L now splits damage cost by type automatically.
 *
 * Rate semantics (per avg_cost_rule.md §3):
 *   - Stock OUT at current avg_cost (cost flows out at average, avg unchanged)
 */
class DamageService
{
    public function __construct(
        private StockService $stockService,
        private JournalPostingService $journalPosting,
        private NotificationService $notifications
    ) {}

    /**
     * Phase 1: Create a draft damage invoice (no stock movement, no GL).
     *
     * @param array $data {
     *     warehouse_id: int,
     *     damage_date: string (Y-m-d),
     *     damage_type: string (one of DamageInvoice::DAMAGE_TYPES) — Phase 1, required,
     *     reason_code: string|null (must exist in damage_reasons for the given damage_type),
     *     reason_detail: string|null,
     *     reason: string|null (legacy free-text, kept for back-compat),
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

        // Phase 1: resolve + validate the structured reason_code against the
        // damage_reasons taxonomy. If a reason_code is supplied it MUST be
        // active and belong to the chosen damage_type — otherwise the dropdown
        // filter on the form would be meaningless.
        $reasonCode  = trim((string) ($data['reason_code'] ?? ''));
        $reasonLabel = '';
        if ($reasonCode !== '') {
            $reasonRow = DamageReason::active()
                ->where('reason_code', $reasonCode)
                ->where('damage_type', $data['damage_type'])
                ->first();
            if (!$reasonRow) {
                throw new \InvalidArgumentException(
                    "Invalid reason_code '{$reasonCode}' for damage_type '{$data['damage_type']}'."
                );
            }
            $reasonLabel = $reasonRow->label;
        }

        return DB::transaction(function () use (
            $damageCode, $data, $warehouseId, $branchId, $totalValue, $validatedItems,
            $reasonCode, $reasonLabel
        ) {
            // Phase 0 (Damage plan): use Eloquent create() so the
            // AuditableMasterData trait's `created` event fires and writes
            // a user_audit_log entry. Previously this used raw
            // DB::table()->insertGetId() which BYPASSED the trait entirely
            // (no audit trail for damage creation — a regression vs legacy).
            $damage = DamageInvoice::create([
                'damage_code'   => $damageCode,
                'damage_date'   => $data['damage_date'] ?? now()->format('Y-m-d'),
                'warehouse_id'  => $warehouseId,
                'branch_id'     => $branchId,
                'total_value'   => round($totalValue, 2),
                'reason'        => trim((string) ($data['reason'] ?? '')),
                // Phase 1 — structured categorization.
                'damage_type'   => $data['damage_type'],
                'reason_code'   => $reasonCode !== '' ? $reasonCode : null,
                'reason_detail' => trim((string) ($data['reason_detail'] ?? '')) ?: null,
                'status'        => 'draft',
                'is_reversed'   => false,
                'created_by'    => $data['created_by'] ?? null,
            ]);

            // Insert items via Eloquent (bulk insert via the model so the
            // relation is consistent on the returned fresh model).
            $itemRows = [];
            foreach ($validatedItems as $item) {
                $itemRows[] = new DamageInvoiceItem([
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'rate' => $item['rate'],
                ]);
            }
            $damage->items()->saveMany($itemRows);

            // F-18c: Notify configured recipients that a damage invoice was
            // created. Skipped when `suppress_notification` is set — the
            // sales-return linked-damage flow (SalesReturnService::
            // createLinkedDamageWriteOffs) sets this flag to avoid firing
            // damage_invoice_created on top of return_confirmed.
            if (empty($data['suppress_notification'])) {
                $typeLabel = DamageInvoice::DAMAGE_TYPE_LABELS[$data['damage_type']] ?? $data['damage_type'];
                try {
                    $this->notifications->dispatch(
                        'damage_invoice_created',
                        "Damage invoice {$damageCode} created ({$typeLabel})"
                        . ($reasonLabel ? " — {$reasonLabel}" : '')
                        . " — Tk " . number_format((float) $totalValue, 2)
                        . " at warehouse #{$warehouseId} (branch #{$branchId}).",
                        'damage_invoice',
                        $damage->id,
                        [],
                        [
                            'branch_id'  => $branchId,
                            'created_by' => (int) ($data['created_by'] ?? 0),
                        ]
                    );
                } catch (\Throwable $e) {
                    Log::warning('Notification dispatch failed (damage_invoice_created)', [
                        'damage_id' => $damage->id,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }

            return $damage->fresh(['items.product', 'warehouse.branch']);
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

            // Phase 0 (Damage plan): use Eloquent update() so the
            // AuditableMasterData trait's `updated` event fires and writes
            // a user_audit_log entry (status: draft → confirmed, plus the
            // journal_entry_id). Previously raw DB::table()->update()
            // bypassed the trait.
            $damage->update([
                'status' => 'confirmed',
                'journal_entry_id' => $journalEntryId,
            ]);

            return $damage->fresh(['items.product', 'warehouse.branch', 'journalEntry.lines.ledger']);
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

                // Phase 0 (Damage plan): use Eloquent update() so the
                // AuditableMasterData trait's `updated` event fires and
                // writes a user_audit_log entry capturing the reversal
                // metadata + status change in a single audit row.
                $damage->update([
                    'is_reversed' => true,
                    'reversed_at' => now(),
                    'reversed_by' => $cancelledBy,
                    'reverse_reason' => $reason,
                    'status' => 'cancelled',
                ]);
            } else {
                // Draft → cancelled (no stock/GL to reverse). Still use
                // Eloquent so the audit trait fires.
                $damage->update(['status' => 'cancelled']);
            }

            return $damage->fresh();
        });
    }

    /**
     * Post the GL journal for a damage invoice.
     *
     * Re-derived GL rule:
     *   Dr <loss ledger selected by damage_type> / Cr Inventory
     *
     * Phase 1 — type-aware loss ledger selection:
     *   real_damage / quality_reject / customer_return / other → damage_loss
     *     (falls back to inventory_shrinkage if damage_loss ledger missing)
     *   missing / theft                                        → inventory_shrinkage
     *     (falls back to damage_loss if inventory_shrinkage ledger missing)
     *
     * Both natures roll up under Operating Expenses in the P&L (Phase 0 fix),
     * so the P&L now splits damage cost by type — real physical losses hit
     * `damage_loss`, while unaccounted / stolen stock hits
     * `inventory_shrinkage`, making the accountability gap visible.
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

        $lossLedgerId = $this->resolveLossLedgerId($damage->damage_type);

        $typeLabel = DamageInvoice::DAMAGE_TYPE_LABELS[$damage->damage_type] ?? $damage->damage_type;
        // Build a descriptive memo using the raw columns (NOT the
        // reasonTaxonomy relation — that would lazy-load inside the GL
        // transaction and the `reason` attribute shadows a `reason()`
        // relation anyway). The UI renders the human label; the GL memo
        // just needs enough text to identify the write-off.
        $reasonText = '';
        if ($damage->reason_code) {
            $reasonText = $damage->reason_code;
        } elseif ($damage->reason) {
            $reasonText = $damage->reason;
        }
        if ($damage->reason_detail) {
            $reasonText = ($reasonText ? $reasonText . ' — ' : '') . $damage->reason_detail;
        }

        return $this->journalPosting->createJournalEntry([
            'entry_date' => $damage->damage_date->format('Y-m-d'),
            'reference_type' => 'damage',
            'reference_id' => $damage->id,
            'branch_id' => $damage->branch_id,
            'description' => "Damage Write-off {$damage->damage_code} ({$typeLabel})"
                . ($reasonText ? ' — ' . $reasonText : ''),
            'source' => 'damage',
            'created_by' => $createdBy,
        ], [
            [
                'ledger_id' => $lossLedgerId,
                'debit' => $totalValue, 'credit' => 0,
                'memo' => "Damage / write-off ({$typeLabel}) — {$damage->damage_code}",
            ],
            [
                'ledger_id' => $inventoryLedgerId,
                'debit' => 0, 'credit' => $totalValue,
                'memo' => 'Inventory reduction (damaged goods) — ' . $damage->damage_code,
            ],
        ]);
    }

    /**
     * Phase 1 — resolve the loss ledger to debit based on damage_type.
     *
     * Mapping (see class docblock):
     *   real_damage / quality_reject / customer_return / other → damage_loss
     *     (fallback: inventory_shrinkage)
     *   missing / theft                                        → inventory_shrinkage
     *     (fallback: damage_loss)
     *
     * Falls back to whichever of the two natures is configured if the
     * primary one is missing — so the GL post never fails just because a
     * specific ledger hasn't been created yet. The fallback keeps the
     * transaction balanced (the loss MUST be recorded somewhere).
     *
     * @throws \RuntimeException if NEITHER damage_loss nor inventory_shrinkage
     *         ledgers are configured.
     */
    private function resolveLossLedgerId(string $damageType): int
    {
        $shrinkageNatures = ['missing', 'theft'];
        $preferShrinkage  = in_array($damageType, $shrinkageNatures, true);

        $primary   = $preferShrinkage ? 'inventory_shrinkage' : 'damage_loss';
        $secondary = $preferShrinkage ? 'damage_loss' : 'inventory_shrinkage';

        $id = $this->journalPosting->lookupLedgerByNature($primary);
        if (!$id) {
            $id = $this->journalPosting->lookupLedgerByNature($secondary);
        }
        if (!$id) {
            throw new \RuntimeException(
                'Neither damage_loss nor inventory_shrinkage ledger is configured. '
                . 'Configure at least one of these natures in the chart of accounts.'
            );
        }

        return $id;
    }

    /**
     * Generate atomic damage code: DMG-YYYYMMDD-NNNN.
     * Uses DocumentSequenceService with advisory locks (Task 20).
     */
    private function generateDamageCode(): string
    {
        return DocumentSequenceService::nextCode(
            docType:  'damage',
            prefix:   'DMG',
            datePart: now()->format('Ymd'),
            padLength: 4,
        );
    }

    private function validateCreateInput(array $data): void
    {
        if (empty($data['warehouse_id']) || (int) $data['warehouse_id'] <= 0) {
            throw new \InvalidArgumentException('warehouse_id is required.');
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            throw new \InvalidArgumentException('At least one item is required.');
        }

        // Phase 1 — damage_type is required and must be one of the known
        // enum values. The DB CHECK constraint is the final guard, but we
        // validate here first to give a clear error before any work starts.
        if (empty($data['damage_type'])) {
            throw new \InvalidArgumentException('damage_type is required.');
        }
        if (!in_array($data['damage_type'], DamageInvoice::DAMAGE_TYPES, true)) {
            throw new \InvalidArgumentException(
                'Invalid damage_type: ' . $data['damage_type']
                . '. Must be one of: ' . implode(', ', DamageInvoice::DAMAGE_TYPES)
            );
        }
    }
}

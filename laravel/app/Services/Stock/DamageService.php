<?php

namespace App\Services\Stock;

use App\Models\DamageInvoice;
use App\Models\DamageInvoiceItem;
use App\Models\DamageReason;
use App\Services\Accounting\DocumentSequenceService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\SubLedgerService;
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
 * Phase 4 — Witness & Accountable Employee:
 *   createDamage accepts optional witness_employee_id + accountable_employee_id.
 *   Validation by damage_type (config-driven):
 *     missing → accountable_employee_id REQUIRED
 *     theft   → witness_employee_id REQUIRED
 *   postEmployeeRecovery() posts a one-shot recovery (Dr employee_payable /
 *   Cr loss ledger + employee_ledger deduction row). cancelDamage reverses
 *   the recovery before reversing the main write-off so the employee doesn't
 *   owe us for a damage that was itself reversed.
 *
 * Rate semantics (per avg_cost_rule.md §3):
 *   - Stock OUT at current avg_cost (cost flows out at average, avg unchanged)
 */
class DamageService
{
    public function __construct(
        private StockService $stockService,
        private JournalPostingService $journalPosting,
        private NotificationService $notifications,
        private SubLedgerService $subLedger
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
     *     witness_employee_id: int|null (Phase 4 — required when damage_type='theft'),
     *     accountable_employee_id: int|null (Phase 4 — required when damage_type='missing'),
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

        // Phase 4 — resolve + validate the witness / accountable employees.
        // Both are nullable integers; the type-conditional requirement
        // (missing→accountable, theft→witness) is enforced inside
        // validateAccountability(). resolveEmployeeId() also verifies the
        // employee is active + belongs to the damage's branch (so a user
        // can't pin a write-off on an employee in another branch).
        $witnessEmployeeId     = $this->resolveEmployeeId($data['witness_employee_id'] ?? null, $branchId);
        $accountableEmployeeId = $this->resolveEmployeeId($data['accountable_employee_id'] ?? null, $branchId);
        $this->validateAccountability($data['damage_type'], $witnessEmployeeId, $accountableEmployeeId);

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
            $reasonCode, $reasonLabel, $witnessEmployeeId, $accountableEmployeeId
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
                // Phase 4 — named responsible parties.
                'witness_employee_id'     => $witnessEmployeeId,
                'accountable_employee_id' => $accountableEmployeeId,
                'recovery_amount'         => 0,
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

            // Phase 3 — evidence requirement. For damage types that represent
            // a real, photographable loss (real_damage / theft /
            // quality_reject), at least one attachment MUST exist before the
            // write-off is posted. This is the core accountability control:
            // without it, an employee can declare stock as "damaged" and walk
            // out with it, no proof required. `missing` is exempt (nothing to
            // photograph — Phase 4 requires an accountable employee instead).
            // `customer_return` is exempt (the return itself is the evidence;
            // the linked-damage auto-flow would otherwise be blocked).
            //
            // The list is config-driven (config/damage.php) so a stricter
            // install can add `missing` / `other` without code changes.
            $requirePhoto = config('damage.require_photo_for_types', []);
            if (is_array($requirePhoto) && in_array($damage->damage_type, $requirePhoto, true)) {
                // Count inside the locked transaction so a concurrent delete
                // can't race the gate. Indexed on damage_invoice_id.
                $count = DB::table('damage_attachments')
                    ->where('damage_invoice_id', $damage->id)
                    ->count();
                if ($count < 1) {
                    $typeLabel = DamageInvoice::DAMAGE_TYPE_LABELS[$damage->damage_type] ?? $damage->damage_type;
                    throw new \RuntimeException(
                        "Cannot confirm a {$typeLabel} damage without at least one photo/evidence attachment. "
                        . "Upload evidence on the detail page and retry."
                    );
                }
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

            // Post GL journal. May return null for a zero-value damage
            // (stock OUT still applied; GL skipped) — journal_entry_id is
            // nullable, so passing null is FK-safe. Never 0.
            $journalEntryId = $this->postDamageGL($damage, $confirmedBy);

            // Phase 0 (Damage plan): use Eloquent update() so the
            // AuditableMasterData trait's `updated` event fires and writes
            // a user_audit_log entry (status: draft → confirmed, plus the
            // journal_entry_id when one was posted). Previously raw
            // DB::table()->update() bypassed the trait.
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
                // Phase 4 — reverse any employee recovery BEFORE reversing
                // the main write-off. If a recovery was posted (Dr employee_
                // payable / Cr loss + employee_ledger deduction), undoing the
                // damage must also undo the recovery — otherwise the employee
                // would remain liable for a write-off that no longer exists.
                // Order matters: reverse the GL recovery JE first (so the
                // balanced reversal is in place), then the employee_ledger
                // row (sub-ledger reversal posts its own opposite entry with
                // the same reference). Both are append-only / reversal-only.
                if ($damage->recovery_journal_entry_id) {
                    $this->journalPosting->reverseJournalEntry(
                        (int) $damage->recovery_journal_entry_id, $cancelledBy,
                        "Damage cancelled: recovery reversed — {$reason}"
                    );
                }
                if ($damage->employee_ledger_entry_id) {
                    $this->subLedger->reverseEmployeeLedgerEntry(
                        (int) $damage->employee_ledger_entry_id, $cancelledBy,
                        "Damage cancelled: {$reason}"
                    );
                }

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
     * @return int|null journal_entry_id — null when total_value < 0.01 (no GL
     *         posted; the FK on journal_entry_id is nullable). Never returns 0.
     */
    private function postDamageGL(DamageInvoice $damage, int $createdBy): ?int
    {
        $totalValue = (float) $damage->total_value;

        if ($totalValue < 0.01) {
            // No GL for zero-value damages — return NULL (not 0) so the
            // nullable damage_invoices.journal_entry_id FK is satisfied.
            // Previously this returned 0, which triggered
            // SQLSTATE[23503]: damage_invoices_journal_entry_id_fkey violation
            // because no journal_entries row has id=0. The stock OUT above
            // still applies (qty is real); only the GL is skipped — a balanced
            // 0.00/0.00 JE would be pointless clutter. cancelDamage already
            // guards the reversal with `if ($damage->journal_entry_id)`, which
            // is falsy for null, so the no-GL case reverses cleanly too.
            return null;
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

    /*
    |--------------------------------------------------------------------------
    | Phase 4 — Witness & Accountable Employee helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve + validate a witness/accountable employee ID.
     *
     * Accepts a nullable ID (0 / null / empty string → null). When an ID is
     * supplied, verifies the employee EXISTS, is ACTIVE (not soft-deleted,
     * is_active=true), and belongs to the damage's branch — so a user can't
     * pin a write-off on an employee in another branch (which would defeat
     * the accountability purpose: the named party must be reachable in the
     * same branch for follow-up / recovery).
     *
     * Returns the validated integer ID, or null when none was supplied.
     *
     * @throws \InvalidArgumentException When the ID is invalid / inactive /
     *         cross-branch.
     */
    private function resolveEmployeeId(mixed $rawId, int $branchId): ?int
    {
        $id = (int) ($rawId ?? 0);
        if ($id <= 0) {
            return null;
        }

        $employee = DB::table('employees')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (!$employee) {
            throw new \InvalidArgumentException(
                "Employee #{$id} not found (or is deleted)."
            );
        }
        if (!$employee->is_active) {
            throw new \InvalidArgumentException(
                "Employee #{$id} ({$employee->name}) is inactive and cannot be named as witness / accountable."
            );
        }
        if ((int) $employee->branch_id !== $branchId) {
            throw new \InvalidArgumentException(
                "Employee #{$id} ({$employee->name}) belongs to branch #{$employee->branch_id}, "
                . "but the damage is for branch #{$branchId}. The named party must be in the same branch."
            );
        }

        return $id;
    }

    /**
     * Enforce the type-conditional witness/accountable requirement.
     *
     * Driven by config('damage.accountability'):
     *   - require_accountable_for_types (default: ['missing'])
     *   - require_witness_for_types     (default: ['theft'])
     *
     * A `missing`-type damage CANNOT be created without an accountable
     * employee — someone must own the unaccounted-for stock. A `theft`-type
     * damage CANNOT be created without a witness — a single-person theft
     * declaration is an abuse vector (the same person who took the stock
     * could report it as "theft" with no one to corroborate).
     *
     * customer_return is deliberately excluded (the sales-return-linked
     * auto-flow has no human selecting an employee).
     *
     * @throws \InvalidArgumentException When a required party is missing.
     */
    private function validateAccountability(string $damageType, ?int $witnessId, ?int $accountableId): void
    {
        $rules = (array) config('damage.accountability', []);

        $requireAccountable = in_array(
            $damageType,
            (array) ($rules['require_accountable_for_types'] ?? []),
            true
        );
        $requireWitness = in_array(
            $damageType,
            (array) ($rules['require_witness_for_types'] ?? []),
            true
        );

        if ($requireAccountable && empty($accountableId)) {
            $label = DamageInvoice::DAMAGE_TYPE_LABELS[$damageType] ?? $damageType;
            throw new \InvalidArgumentException(
                "An accountable employee is required for a {$label} damage — "
                . "someone must be named responsible for the unaccounted-for stock."
            );
        }
        if ($requireWitness && empty($witnessId)) {
            $label = DamageInvoice::DAMAGE_TYPE_LABELS[$damageType] ?? $damageType;
            throw new \InvalidArgumentException(
                "A witness employee is required for a {$label} damage — "
                . "a theft write-off must be corroborated by a second person."
            );
        }
    }

    /**
     * Phase 4: Post a one-shot employee recovery for a confirmed damage.
     *
     * When an employee is accountable for a loss (damage_type='missing' or
     * an explicitly-set accountable_employee_id), the company may recover
     * part or all of the loss from that employee. This posts:
     *
     *   GL:    Dr employee_payable (reduce payable — employee owes us)
     *          Cr <loss ledger>     (reduce the loss expense — nets the
     *                                recovery against the original write-off
     *                                that debited the same ledger)
     *   Sub-ledger: employee_ledger row, transaction_type='deduction',
     *               debit=amount (employee owes us more / we owe them less).
     *
     * The loss ledger is resolved from the ORIGINAL damage JE's debit line
     * (NOT re-resolved from damage_type) — this guarantees the recovery
     * credits the exact ledger that took the original debit, even if the
     * fallback path was used at confirm time.
     *
     * Recovery is one-shot: a damage may have at most one recovery. To
     * undo a recovery, cancel the damage (cancelDamage reverses both the
     * recovery and the main write-off). This keeps the flow simple and the
     * audit trail linear.
     *
     * @param int $damageId
     * @param float $amount  BDT to recover (must be > 0 and ≤ total_value).
     * @param int $postedBy  The user posting the recovery (admin/manager).
     * @return DamageInvoice  Fresh damage with the recovery relations loaded.
     * @throws \RuntimeException  When preconditions aren't met (not confirmed,
     *         no accountable employee, already recovered, amount out of range,
     *         or GL/sub-ledger resolution fails).
     */
    public function postEmployeeRecovery(int $damageId, float $amount, int $postedBy): DamageInvoice
    {
        return DB::transaction(function () use ($damageId, $amount, $postedBy) {
            $damage = DamageInvoice::with(['journalEntry.lines'])
                ->lockForUpdate()
                ->find($damageId);

            if (!$damage) {
                throw new \RuntimeException("Damage invoice {$damageId} not found.");
            }
            if (!$damage->isConfirmed()) {
                throw new \RuntimeException(
                    "Recovery can only be posted for a confirmed damage (current: {$damage->status})."
                );
            }
            if (empty($damage->accountable_employee_id)) {
                throw new \RuntimeException(
                    "Cannot post a recovery — this damage has no accountable employee."
                );
            }
            if ($damage->hasRecovery()) {
                throw new \RuntimeException(
                    "A recovery (Tk " . number_format((float) $damage->recovery_amount, 2)
                    . ") has already been posted for this damage. Cancel the damage to reverse it."
                );
            }

            $amount = round($amount, 2);
            $totalValue = (float) $damage->total_value;
            if ($amount <= 0) {
                throw new \RuntimeException('Recovery amount must be greater than zero.');
            }
            if ($amount > $totalValue + 0.01) {
                throw new \RuntimeException(
                    "Recovery amount (Tk " . number_format($amount, 2)
                    . ") cannot exceed the damage total value (Tk " . number_format($totalValue, 2) . ")."
                );
            }

            // Resolve the loss ledger from the ORIGINAL damage JE's debit
            // line — credits the exact ledger that was debited on confirm,
            // so the recovery nets against the actual loss (not a re-resolved
            // one that might differ if the fallback path was used).
            $lossLedgerId = $this->resolveOriginalLossLedgerId($damage);

            // Resolve the employee_payable GL control account (the debit side).
            $employeePayableLedgerId = $this->journalPosting->lookupLedgerByNature('employee_payable');
            if (!$employeePayableLedgerId) {
                throw new \RuntimeException(
                    'No active employee_payable ledger is configured. '
                    . 'Create one (nature=employee_payable) before posting a recovery.'
                );
            }

            $typeLabel = DamageInvoice::DAMAGE_TYPE_LABELS[$damage->damage_type] ?? $damage->damage_type;
            $description = "Damage recovery — {$damage->damage_code} ({$typeLabel})";

            // 1. Post the GL recovery entry (balanced: Dr employee_payable / Cr loss).
            $recoveryJeId = $this->journalPosting->createJournalEntry([
                'entry_date'      => $damage->damage_date->format('Y-m-d'),
                'reference_type'  => 'damage',
                'reference_id'    => $damage->id,
                'branch_id'       => $damage->branch_id,
                'description'     => $description,
                'source'          => 'damage_recovery',
                'created_by'      => $postedBy,
            ], [
                [
                    'ledger_id' => $employeePayableLedgerId,
                    'debit'     => $amount, 'credit' => 0,
                    'memo'      => "Recovery from employee — {$damage->damage_code}",
                    'entity_type' => 'employee',
                    'entity_id'   => (int) $damage->accountable_employee_id,
                ],
                [
                    'ledger_id' => $lossLedgerId,
                    'debit'     => 0, 'credit' => $amount,
                    'memo'      => "Damage loss recovered — {$damage->damage_code}",
                ],
            ]);

            // 2. Post the employee_ledger sub-ledger row (dual-write). The
            //    sub-ledger links back to the damage (reference_type='damage')
            //    AND to the recovery GL JE (journal_entry_id) so the
            //    reconciliation hub can trace the balance to its source.
            $txType = (string) (config('damage.accountability.recovery_transaction_type') ?? 'deduction');
            $employeeLedgerId = $this->subLedger->postEmployeeLedgerEntry([
                'employee_id'      => (int) $damage->accountable_employee_id,
                'branch_id'        => $damage->branch_id,
                'transaction_date' => $damage->damage_date->format('Y-m-d'),
                'transaction_type' => $txType,
                'reference_type'   => 'damage',
                'reference_id'     => $damage->id,
                'debit'            => $amount,
                'credit'           => 0,
                'description'      => $description,
                'journal_entry_id' => $recoveryJeId,
                'created_by'       => $postedBy,
            ]);

            // 3. Stamp the damage with the recovery links (Eloquent so the
            //    audit trait fires — captures who posted it + when).
            $damage->update([
                'recovery_amount'            => $amount,
                'employee_ledger_entry_id'   => $employeeLedgerId,
                'recovery_journal_entry_id'  => $recoveryJeId,
            ]);

            return $damage->fresh([
                'items.product', 'warehouse.branch',
                'witnessEmployee.branch', 'accountableEmployee.branch',
                'employeeLedgerEntry.employee', 'recoveryJournalEntry',
            ]);
        });
    }

    /**
     * Resolve the loss ledger that was DEBITED by the original damage confirm.
     *
     * Looks up the original damage JE's journal_lines, finds the debit line
     * (a damage JE has exactly one debit = the loss), and returns its
     * ledger_id. This is used by postEmployeeRecovery to credit the EXACT
     * same ledger (so the recovery nets against the actual loss, regardless
     * of whether the confirm used damage_loss or its inventory_shrinkage
     * fallback).
     *
     * Falls back to resolveLossLedgerId($damageType) if the original JE / its
     * debit line can't be found (defensive — should not happen on a properly
     * confirmed damage, but keeps the recovery path resilient).
     *
     * @throws \RuntimeException If neither path resolves a ledger.
     */
    private function resolveOriginalLossLedgerId(DamageInvoice $damage): int
    {
        $je = $damage->journalEntry;
        if ($je) {
            foreach ($je->lines as $line) {
                if ((float) $line->debit > 0) {
                    return (int) $line->ledger_id;
                }
            }
        }

        // Defensive fallback: re-resolve by damage_type (may differ from the
        // original if the fallback path was used, but guarantees a valid
        // ledger so the recovery can proceed).
        return $this->resolveLossLedgerId($damage->damage_type);
    }
}

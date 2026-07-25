<?php

namespace App\Services\Sales;

use App\Models\SalesInvoice;
use App\Models\SalesChallan;
use App\Services\Stock\StockService;
use App\Services\Stock\StockAvailabilityService;
use App\Services\Accounting\DocumentSequenceService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\JournalReversalService;
use App\Services\Accounting\SubLedgerService;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sales Challan Service — Phase 8.3.
 *
 * Two-step flow (from Phase 8.2 draft invoice):
 *   1. prepareGodown: assign warehouse_id to invoice items + dispatches.
 *      Invoice status: draft → confirmed, is_godown_prepared=true.
 *      NO stock movement, NO GL — just warehouse assignment.
 *
 *   2. issueChallan: stock OUT via StockService at avg_cost + GL Dr COGS / Cr Inventory.
 *      Creates sales_challan, moves stock, posts COGS journal.
 *      Invoice: is_challan_issued=true.
 *
 *   3. cancelChallan: reverse stock + GL (append-only reversal).
 *
 * Rate semantics (per avg_cost_rule.md §3):
 *   Stock OUT at current avg_cost (cost flows out at average, avg unchanged on OUT).
 *   The COGS = Σ (qty × avg_cost) for all dispatch lines.
 *
 * GL posting (re-derived from double-entry):
 *   Dr COGS (nature: cogs)
 *   Cr Inventory (nature: inventory)
 */
class SalesChallanService
{
    public function __construct(
        private StockService $stockService,
        private JournalPostingService $journalPosting,
        private JournalReversalService $journalReversal,
        private SubLedgerService $subLedger,
        private SalesAccess $salesAccess,
        private SalesAuditLogger $auditLogger,
        private StockAvailabilityService $availabilityService,
        private NotificationService $notifications
    ) {}

    /**
     * Step 1: Prepare godown — assign warehouse_id to invoice items + dispatches.
     * No stock movement, no GL.
     *
     * @param int $invoiceId
     * @param array $warehouseAssignments [product_id => warehouse_id]
     * @param int $preparedBy
     * @return SalesInvoice
     * @throws \RuntimeException If invoice not draft or already godown-prepared.
     */
    public function prepareGodown(int $invoiceId, array $warehouseAssignments, int $preparedBy): SalesInvoice
    {
        return DB::transaction(function () use ($invoiceId, $warehouseAssignments, $preparedBy) {
            $invoice = SalesInvoice::with('items', 'dispatches')->lockForUpdate()->find($invoiceId);

            if (!$invoice) {
                throw new \RuntimeException("Invoice {$invoiceId} not found.");
            }
            if (!$invoice->isDraft()) {
                throw new \RuntimeException("Only draft invoices can be godown-prepared (current: {$invoice->status}).");
            }

            // Assign warehouse_id to each invoice item.
            foreach ($invoice->items as $item) {
                $wid = $warehouseAssignments[$item->product_id] ?? null;
                if (!$wid) {
                    throw new \RuntimeException("Warehouse not assigned for product {$item->product_id}.");
                }

                // Check availability in the assigned warehouse.
                $available = $this->stockService->getWarehouseQty((int) $wid, $item->product_id);
                if ((float) $item->qty > $available + 0.0001) {
                    throw new \RuntimeException(
                        "Insufficient stock in warehouse {$wid} for product {$item->product_id}: "
                        . "available {$available}, required {$item->qty}"
                    );
                }

                DB::table('sales_invoice_items')
                    ->where('id', $item->id)
                    ->update(['warehouse_id' => (int) $wid]);

                DB::table('sales_invoice_dispatches')
                    ->where('sales_invoice_id', $invoiceId)
                    ->where('product_id', $item->product_id)
                    ->update(['warehouse_id' => (int) $wid]);
            }

            // Update invoice status.
            DB::table('sales_invoices')
                ->where('id', $invoiceId)
                ->update([
                    'status' => 'confirmed',
                    'is_godown_prepared' => true,
                    'godown_prepared_at' => now(),
                    'updated_at' => now(),
                ]);

            // P1-3: Audit log — godown_prepared.
            $this->auditLogger->godownPrepared(
                $preparedBy, $invoiceId, $invoice->invoice_code, (int) $invoice->branch_id
            );

            return SalesInvoice::with(['items.product', 'dispatches', 'customer', 'branch'])->find($invoiceId);
        });
    }

    /**
     * Step 2: Issue challan — stock OUT + GL Dr COGS / Cr Inventory.
     *
     * @param int $invoiceId
     * @param array $data {
     *     transport_name: string|null,
     *     vehicle_number: string|null,
     *     driver_name: string|null,
     *     transport_cost: float,
     *     notes: string|null,
     *     created_by: int,
     * }
     * @return SalesChallan
     * @throws \RuntimeException If not godown-prepared or already challan-issued.
     */
    public function issueChallan(int $invoiceId, array $data): SalesChallan
    {
        return DB::transaction(function () use ($invoiceId, $data) {
            $invoice = SalesInvoice::with(['items', 'dispatches'])->lockForUpdate()->find($invoiceId);

            if (!$invoice) {
                throw new \RuntimeException("Invoice {$invoiceId} not found.");
            }

            // P0-8: Defense-in-depth branch isolation check.
            $this->salesAccess->assertBranchAccessible((int) $invoice->branch_id);

            if (!$invoice->is_godown_prepared) {
                throw new \RuntimeException("Invoice must be godown-prepared before issuing challan.");
            }
            if ($invoice->is_challan_issued) {
                throw new \RuntimeException("Challan already issued for this invoice.");
            }

            $challanCode = $this->generateChallanCode();
            $challanDate = now()->format('Y-m-d');
            $cogsTotal = 0.0;

            // Create the challan header.
            $challanId = DB::table('sales_challans')->insertGetId([
                'challan_code' => $challanCode,
                'challan_date' => $challanDate,
                'sales_invoice_id' => $invoiceId,
                'branch_id' => $invoice->branch_id,
                'transport_name' => $data['transport_name'] ?? null,
                'transport_phone' => $data['transport_phone'] ?? null,
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'transport_cost' => $data['transport_cost'] ?? 0,
                'is_reversed' => false,
                'is_dispatch_soft_hold' => false,
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Process each dispatch line: stock OUT at avg_cost.
            foreach ($invoice->items as $item) {
                $warehouseId = $item->warehouse_id;
                $productId = $item->product_id;
                $qty = (float) $item->qty;

                if (!$warehouseId) {
                    throw new \RuntimeException("Product {$productId} has no warehouse assigned. Run godown prep first.");
                }

                // Get current avg_cost (this is the COGS rate).
                $avgCost = $this->stockService->getWarehouseAvgCost($warehouseId, $productId);
                if ($avgCost <= 0) {
                    throw new \RuntimeException(
                        "Cannot issue challan: zero avg_cost for product {$productId} in warehouse {$warehouseId}. "
                        . "Receive stock or set cost first."
                    );
                }

                // Stock OUT via StockService (reference_type='sales_challan', rate=avg_cost).
                $this->stockService->applyTransaction([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'qty' => -$qty, // negative = OUT
                    'rate' => $avgCost, // current avg_cost (cost flows out, avg unchanged on OUT)
                    'reference_type' => 'sales_challan',
                    'reference_id' => $challanId,
                    'notes' => 'Sales Challan ' . $challanCode,
                    'transaction_date' => $challanDate,
                    'created_by' => $data['created_by'] ?? null,
                ]);

                // P0-5: Persist per-line issue cost snapshot (sales_challan_items).
                // This denormalized record captures the avg_cost at the moment of stock OUT
                // so that reversals and reports can use the ORIGINAL per-line rate.
                DB::table('sales_challan_items')->insert([
                    'sales_challan_id' => $challanId,
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'qty' => $qty,
                    'issue_rate' => $avgCost,
                    // cogs_amount is GENERATED: qty * issue_rate (auto-computed by PostgreSQL)
                    'created_at' => now(),
                ]);

                // Update dispatch row: dispatched_qty = ordered_qty, qty mirrors for GENERATED amount.
                DB::table('sales_invoice_dispatches')
                    ->where('sales_invoice_id', $invoiceId)
                    ->where('product_id', $productId)
                    ->update([
                        'qty' => DB::raw('ordered_qty'),
                        'dispatched_qty' => DB::raw('ordered_qty'),
                        'warehouse_id' => $warehouseId,
                    ]);

                $cogsTotal += $qty * $avgCost;
            }

            // Post GL: Dr COGS / Cr Inventory (single journal for all lines).
            $journalEntryId = $this->postCogsGL($challanId, $challanCode, $challanDate, $invoice->branch_id, $cogsTotal, $data['created_by'] ?? null);

            // Update challan with journal_entry_id + issue_cost.
            DB::table('sales_challans')
                ->where('id', $challanId)
                ->update([
                    'journal_entry_id' => $journalEntryId,
                    'issue_cost' => round($cogsTotal, 2),
                    'updated_at' => now(),
                ]);

            // P2-3: Transport snapshot + adjustment.
            // If the challan form's transport_cost differs from the invoice's
            // original transport_cost, snapshot the original values + post an
            // adjustment (customer_ledger + GL) for the delta.
            $newTransport = (float) ($data['transport_cost'] ?? 0);
            $oldTransport = (float) $invoice->transport_cost;
            $transportAdjustment = round($newTransport - $oldTransport, 2);
            $adjustmentJournalEntryId = null;

            if (abs($transportAdjustment) > 0.01) {
                // Snapshot original values on the invoice.
                DB::table('sales_invoices')
                    ->where('id', $invoiceId)
                    ->update([
                        'pre_challan_transport' => $oldTransport,
                        'pre_challan_total' => (float) $invoice->total_amount,
                    ]);

                // Update invoice transport_cost + total_amount. due_amount is GENERATED — auto-updated by PostgreSQL.
                $newTotal = (float) $invoice->sub_total - (float) $invoice->discount_amount + $newTransport;
                DB::table('sales_invoices')
                    ->where('id', $invoiceId)
                    ->update([
                        'transport_cost' => round($newTransport, 2),
                        'total_amount' => round($newTotal, 2),
                    ]);

                // Post GL adjustment JE FIRST (Dr/Cr AR + Revenue, swapped by sign) to get journal_entry_id.
                $adjustmentJournalEntryId = $this->postTransportAdjustmentGL(
                    $invoiceId, $challanCode, $challanDate, (int) $invoice->branch_id,
                    $transportAdjustment, (int) $invoice->customer_id,
                    $data['created_by'] ?? null
                );

                // Post customer_ledger 'invoice_adjustment' entry via SubLedgerService for the delta.
                $debit = $transportAdjustment > 0 ? abs($transportAdjustment) : 0;
                $credit = $transportAdjustment < 0 ? abs($transportAdjustment) : 0;
                $this->subLedger->postCustomerLedgerEntry([
                    'customer_id' => $invoice->customer_id,
                    'branch_id' => $invoice->branch_id,
                    'transaction_date' => now()->format('Y-m-d'),
                    'transaction_type' => 'invoice_adjustment',
                    'reference_type' => 'sales_invoice',
                    'reference_id' => $invoice->id,
                    'debit' => $debit,
                    'credit' => $credit,
                    'description' => 'Challan ' . $challanCode . ' — transport adjustment ' . ($transportAdjustment >= 0 ? '+' : '') . number_format($transportAdjustment, 2),
                    'journal_entry_id' => $adjustmentJournalEntryId,
                    'created_by' => $data['created_by'] ?? null,
                ]);
            }

            // Store transport_adjustment + adjustment_journal_entry_id on the challan.
            DB::table('sales_challans')
                ->where('id', $challanId)
                ->update([
                    'transport_adjustment' => $transportAdjustment,
                    'adjustment_journal_entry_id' => $adjustmentJournalEntryId,
                ]);

            // Update invoice: is_challan_issued=true.
            DB::table('sales_invoices')
                ->where('id', $invoiceId)
                ->update([
                    'is_challan_issued' => true,
                    'challan_issued_at' => now(),
                    'cogs_journal_entry_id' => $journalEntryId,
                    'updated_at' => now(),
                ]);

            // P1-3: Audit log — challan_issued.
            $this->auditLogger->challanIssued(
                $data['created_by'] ?? auth()->id() ?? 0,
                $challanId, $challanCode, $invoiceId, (int) $invoice->branch_id,
                $cogsTotal, $journalEntryId
            );

            // P2-7: Invalidate pipeline cache (dispatched_qty changed).
            $this->availabilityService->invalidatePipelineForInvoice($invoiceId);

            // F-18c: Notify configured recipients that a challan was issued.
            // sales_challans has no salesman_id column — derive from the
            // parent invoice (salesman_id references employees.id).
            try {
                $salesmanId = (int) (DB::table('sales_invoices')
                    ->where('id', $invoiceId)
                    ->value('salesman_id') ?: 0);

                $this->notifications->dispatch(
                    'challan_create',
                    "Challan {$challanCode} issued against invoice #{$invoiceId} — COGS Tk "
                    . number_format((float) $cogsTotal, 2) . '.',
                    'sales_challan',
                    $challanId,
                    [],
                    [
                        'branch_id'   => (int) $invoice->branch_id,
                        'salesman_id' => $salesmanId,
                        'customer_id' => (int) $invoice->customer_id,
                        'created_by'  => (int) ($data['created_by'] ?? auth()->id() ?? 0),
                    ]
                );
            } catch (\Throwable $e) {
                Log::warning('Notification dispatch failed (challan_create)', [
                    'challan_id' => $challanId,
                    'error'      => $e->getMessage(),
                ]);
            }

            return SalesChallan::with(['salesInvoice.items.product', 'items.product', 'items.warehouse', 'branch', 'journalEntry.lines.ledger'])
                ->find($challanId);
        });
    }

    /**
     * Step 3: Cancel a challan — reverse stock + GL (append-only reversal).
     *
     * @param int $challanId
     * @param int $cancelledBy
     * @param string $reason
     * @return SalesChallan
     */
    public function cancelChallan(int $challanId, int $cancelledBy, string $reason = ''): SalesChallan
    {
        return DB::transaction(function () use ($challanId, $cancelledBy, $reason) {
            $challan = SalesChallan::with('salesInvoice.items')->lockForUpdate()->find($challanId);

            if (!$challan) {
                throw new \RuntimeException("Challan {$challanId} not found.");
            }
            if ($challan->is_reversed) {
                throw new \RuntimeException("Challan is already cancelled.");
            }

            // Reverse GL (COGS journal) + linked sub-ledger via JournalReversalService (cascade).
            if ($challan->journal_entry_id) {
                $this->journalReversal->reverseByJournalEntry(
                    $challan->journal_entry_id, $cancelledBy,
                    "Challan cancelled: {$reason}"
                );
            }

            // Reverse each stock movement.
            $stockTxs = DB::table('stock_transactions')
                ->where('reference_type', 'sales_challan')
                ->where('reference_id', $challanId)
                ->where('is_reversed', false)
                ->get();

            foreach ($stockTxs as $tx) {
                $this->stockService->reverseTransaction(
                    $tx->id, $cancelledBy,
                    "Challan cancelled: {$reason}"
                );
            }

            // Reset dispatch rows: dispatched_qty = 0, qty mirrors for GENERATED amount.
            DB::table('sales_invoice_dispatches')
                ->where('sales_invoice_id', $challan->sales_invoice_id)
                ->update([
                    'qty' => 0,
                    'dispatched_qty' => 0,
                ]);

            // Mark challan as reversed.
            DB::table('sales_challans')
                ->where('id', $challanId)
                ->update([
                    'is_reversed' => true,
                    'reversed_at' => now(),
                    'reversed_by' => $cancelledBy,
                    'reverse_reason' => $reason,
                ]);

            // P2-3: Reverse transport adjustment (if any) + linked sub-ledger via JournalReversalService.
            // If the challan had a transport_adjustment, reverse the adjustment
            // GL JE + restore the invoice's original transport_cost + total_amount
            // from the pre_challan snapshot.
            if ($challan->adjustment_journal_entry_id) {
                $this->journalReversal->reverseByJournalEntry(
                    $challan->adjustment_journal_entry_id, $cancelledBy,
                    "Challan cancelled: transport adjustment reversed — {$reason}"
                );
            }

            // Restore invoice transport snapshot (if snapshot exists).
            $invoiceRow = DB::table('sales_invoices')
                ->where('id', $challan->sales_invoice_id)
                ->first(['pre_challan_transport', 'pre_challan_total', 'paid_amount']);

            if ($invoiceRow && $invoiceRow->pre_challan_transport !== null) {
                $restoredTotal = (float) $invoiceRow->pre_challan_total;
                // due_amount is GENERATED — auto-updated by PostgreSQL when total_amount changes
                DB::table('sales_invoices')
                    ->where('id', $challan->sales_invoice_id)
                    ->update([
                        'transport_cost' => (float) $invoiceRow->pre_challan_transport,
                        'total_amount' => $restoredTotal,
                        'pre_challan_transport' => null,
                        'pre_challan_total' => null,
                    ]);
            }

            // P2-2: Reset invoice back to DRAFT state (fully editable).
            // Legacy reverseChallan resets invoice to 'godown_issued' state,
            // but Laravel's edit flow requires status='draft'. Since cancelling
            // a challan invalidates the godown assignment (warehouse_id on items
            // was set during godown prep, and the items may need re-picking),
            // we reset the invoice all the way back to draft.
            DB::table('sales_invoices')
                ->where('id', $challan->sales_invoice_id)
                ->update([
                    'status' => 'draft',
                    'is_challan_issued' => false,
                    'challan_issued_at' => null,
                    'cogs_journal_entry_id' => null,
                    'is_godown_prepared' => false,
                    'godown_prepared_at' => null,
                    'updated_at' => now(),
                ]);

            // P2-2: Reset warehouse_id on invoice items + dispatches (godown
            // assignment is invalidated — user must re-run godown prep).
            DB::table('sales_invoice_items')
                ->where('sales_invoice_id', $challan->sales_invoice_id)
                ->update(['warehouse_id' => null]);

            DB::table('sales_invoice_dispatches')
                ->where('sales_invoice_id', $challan->sales_invoice_id)
                ->update(['warehouse_id' => null]);

            // P1-3: Audit log — challan_reversed.
            $this->auditLogger->challanReversed(
                $cancelledBy,
                $challanId, $challan->challan_code, $challan->sales_invoice_id,
                (int) $challan->branch_id, $reason
            );

            // P2-7: Invalidate pipeline cache (dispatched_qty reset to 0).
            $this->availabilityService->invalidatePipelineForInvoice($challan->sales_invoice_id);

            return SalesChallan::find($challanId);
        });
    }

    /**
     * Post GL: Dr COGS / Cr Inventory.
     *
     * @return int journal_entry_id
     */
    private function postCogsGL(int $challanId, string $challanCode, string $challanDate, int $branchId, float $cogsAmount, ?int $createdBy): int
    {
        if ($cogsAmount < 0.01) {
            return 0; // No COGS for zero-value
        }

        $cogsLedgerId = $this->journalPosting->lookupLedgerByNature('cogs');
        $inventoryLedgerId = $this->journalPosting->lookupLedgerByNature('inventory');

        if (!$cogsLedgerId) {
            throw new \RuntimeException('COGS ledger not found (nature: cogs).');
        }
        if (!$inventoryLedgerId) {
            throw new \RuntimeException('Inventory ledger not found (nature: inventory).');
        }

        return $this->journalPosting->createJournalEntry([
            'entry_date' => $challanDate,
            'reference_type' => 'sales_challan',
            'reference_id' => $challanId,
            'branch_id' => $branchId,
            'description' => 'Sales Challan COGS ' . $challanCode,
            'source' => 'sales_challan',
            'created_by' => $createdBy,
        ], [
            [
                'ledger_id' => $cogsLedgerId,
                'debit' => $cogsAmount, 'credit' => 0,
                'entity_type' => 'sales_challan', 'entity_id' => $challanId,
                'memo' => 'Challan ' . $challanCode . ' — COGS',
            ],
            [
                'ledger_id' => $inventoryLedgerId,
                'debit' => 0, 'credit' => $cogsAmount,
                'entity_type' => 'sales_challan', 'entity_id' => $challanId,
                'memo' => 'Challan ' . $challanCode . ' — Inventory issued',
            ],
        ]);
    }

    /**
     * Generate atomic challan code: CH-YYYYMMDD-NNNN.
     * Uses DocumentSequenceService with advisory locks (Task 20).
     */
    private function generateChallanCode(): string
    {
        return DocumentSequenceService::nextCode(
            docType:  'sales_challan',
            prefix:   'CH',
            datePart: now()->format('Ymd'),
            padLength: 4,
        );
    }

    /**
     * Get per-line issue-cost items for a challan (P0-5).
     *
     * Returns the snapshot of avg_cost at the moment each line's stock was
     * issued OUT. Used by:
     *   - GrossMargin report (per-product COGS breakdown)
     *   - challan_reversal_smoke test (verify issue_rate > 0)
     *   - Audit / display on challan show page
     *
     * @param int $challanId
     * @return \Illuminate\Support\Collection
     */
    public function getChallanLineItems(int $challanId)
    {
        return DB::table('sales_challan_items as sci')
            ->join('products as p', 'p.id', '=', 'sci.product_id')
            ->join('warehouses as w', 'w.id', '=', 'sci.warehouse_id')
            ->where('sci.sales_challan_id', $challanId)
            ->select(
                'sci.id',
                'sci.product_id',
                'p.product_name',
                'p.product_code',
                'sci.warehouse_id',
                'w.warehouse_name',
                'sci.qty',
                'sci.issue_rate',
                'sci.cogs_amount',
                'sci.created_at'
            )
            ->orderBy('sci.id')
            ->get();
    }

    /**
     * P2-3: Post GL adjustment journal for transport delta.
     *
     * If transport increased (positive adjustment):
     *   Dr AR / Cr Transport Revenue
     * If transport decreased (negative adjustment):
     *   Dr Transport Revenue / Cr AR  (i.e., Cr AR / Dr Transport Revenue swapped)
     *
     * @return int|null journal_entry_id (null if no transport_revenue ledger configured)
     */
    private function postTransportAdjustmentGL(
        int $invoiceId, string $challanCode, string $challanDate, int $branchId,
        float $adjustment, int $customerId, ?int $createdBy
    ): ?int {
        $amount = abs($adjustment);
        if ($amount < 0.01) {
            return null;
        }

        $arLedgerId = $this->journalPosting->lookupLedgerByNature('ar');
        if (!$arLedgerId) {
            throw new \RuntimeException('Accounts Receivable ledger not found (nature: ar).');
        }

        $transportLedgerId = $this->journalPosting->lookupLedgerByNature('transport_revenue');
        if (!$transportLedgerId) {
            // Fall back to sales_revenue if transport_revenue not configured.
            $transportLedgerId = $this->journalPosting->lookupLedgerByNature('sales_revenue');
        }
        if (!$transportLedgerId) {
            throw new \RuntimeException('Transport Revenue / Sales Revenue ledger not found.');
        }

        // Positive adjustment: Dr AR / Cr Transport Revenue
        // Negative adjustment: Dr Transport Revenue / Cr AR (swapped)
        $isIncrease = $adjustment > 0;

        $lines = [
            [
                'ledger_id' => $arLedgerId,
                'debit' => $isIncrease ? $amount : 0,
                'credit' => $isIncrease ? 0 : $amount,
                'entity_type' => 'customer', 'entity_id' => $customerId,
                'memo' => 'Challan ' . $challanCode . ' — transport adjustment AR',
            ],
            [
                'ledger_id' => $transportLedgerId,
                'debit' => $isIncrease ? 0 : $amount,
                'credit' => $isIncrease ? $amount : 0,
                'entity_type' => 'sales_invoice', 'entity_id' => $invoiceId,
                'memo' => 'Challan ' . $challanCode . ' — transport adjustment revenue',
            ],
        ];

        return $this->journalPosting->createJournalEntry([
            'entry_date' => $challanDate,
            'reference_type' => 'sales_invoice',
            'reference_id' => $invoiceId,
            'branch_id' => $branchId,
            'description' => 'Transport adjustment — Challan ' . $challanCode,
            'source' => 'sales_challan',
            'created_by' => $createdBy,
        ], $lines);
    }
}

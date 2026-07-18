<?php

namespace App\Services\Sales;

use App\Models\SalesInvoice;
use App\Models\SalesChallan;
use App\Services\Stock\StockService;
use App\Services\Accounting\JournalPostingService;
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
        private JournalPostingService $journalPosting
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

            // Update invoice: is_challan_issued=true.
            DB::table('sales_invoices')
                ->where('id', $invoiceId)
                ->update([
                    'is_challan_issued' => true,
                    'challan_issued_at' => now(),
                    'cogs_journal_entry_id' => $journalEntryId,
                    'updated_at' => now(),
                ]);

            return SalesChallan::with(['salesInvoice.items.product', 'branch', 'journalEntry.lines.ledger'])
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

            // Reverse GL (COGS journal).
            if ($challan->journal_entry_id) {
                $this->journalPosting->reverseJournalEntry(
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

            // Reset invoice: is_challan_issued=false (back to godown-prepared state).
            DB::table('sales_invoices')
                ->where('id', $challan->sales_invoice_id)
                ->update([
                    'is_challan_issued' => false,
                    'challan_issued_at' => null,
                    'cogs_journal_entry_id' => null,
                    'updated_at' => now(),
                ]);

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
     */
    private function generateChallanCode(): string
    {
        $datePart = now()->format('Ymd');
        $periodKey = now()->format('Y-m');
        $docType = 'sales_challan';

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

            return "CH-{$datePart}-" . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
        });
    }
}

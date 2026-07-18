<?php

namespace App\Services\Sales;

use App\Models\SalesReturn;
use App\Services\Stock\StockService;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sales Return Service — Phase 8.5.
 *
 * Two-phase: create → confirm → reverse.
 *
 * CRITICAL CORRECTNESS (per avg_cost_rule.md §3):
 *   Stock comes back IN at the ORIGINAL avg_cost from the challan's stock_transaction
 *   (NOT current avg_cost). This ensures:
 *   1. The COGS reversal matches the original COGS exactly.
 *   2. The avg_cost is restored to its pre-sale value.
 *
 * The original_cost is looked up from the stock_transaction for the challan
 * (reference_type='sales_challan', reference_id=challan_id, product_id).
 * This is snapshotted into sales_return_items.original_cost.
 *
 * On confirm (4 operations, all atomic):
 *   1. Stock IN via StockService at ORIGINAL avg_cost (ref='sales_return')
 *      → avg_cost recalculated (IN rule: weighted average with original cost)
 *   2. GL Revenue Reversal: Dr Sales Return / Cr AR (at sales rate)
 *   3. GL COGS Reversal: Dr Inventory / Cr COGS (at original avg_cost)
 *   4. Customer ledger: credit entry (customer owes less)
 */
class SalesReturnService
{
    public function __construct(
        private StockService $stockService,
        private JournalPostingService $journalPosting,
        private SalesAccess $salesAccess,
        private SalesAuditLogger $auditLogger
    ) {}

    /**
     * Phase 1: Create a sales return (no stock, no GL).
     * Looks up ORIGINAL avg_cost from the challan's stock_transaction.
     *
     * @param array $data {
     *     sales_invoice_id: int,
     *     return_date: string (Y-m-d),
     *     reason: string|null,
     *     created_by: int,
     *     items: array each { product_id, warehouse_id, qty, rate, sales_invoice_item_id }
     * }
     * @return SalesReturn
     */
    public function createReturn(array $data): SalesReturn
    {
        $invoiceId = (int) ($data['sales_invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            throw new \InvalidArgumentException('sales_invoice_id is required.');
        }

        $invoice = DB::table('sales_invoices')->where('id', $invoiceId)->first();
        if (!$invoice) {
            throw new \InvalidArgumentException("Invoice {$invoiceId} not found.");
        }
        if ($invoice->is_reversed) {
            throw new \RuntimeException("Cannot return against a reversed invoice.");
        }

        // P0-8: Defense-in-depth branch isolation check.
        $this->salesAccess->assertBranchAccessible((int) $invoice->branch_id);

        // Find the challan for this invoice.
        $challan = DB::table('sales_challans')
            ->where('sales_invoice_id', $invoiceId)
            ->where('is_reversed', false)
            ->first();
        if (!$challan) {
            throw new \RuntimeException("No active challan found for invoice {$invoiceId}. Returns require a completed challan.");
        }

        $items = $this->validateItems($data['items'], $invoiceId, $challan->id);
        $totalRevenue = collect($items)->sum(fn($i) => $i['qty'] * $i['rate']);
        $totalCogs = collect($items)->sum(fn($i) => $i['qty'] * $i['original_cost']);

        $returnCode = $this->generateReturnCode();
        $customerId = (int) $invoice->customer_id;
        $branchId = (int) $invoice->branch_id;

        return DB::transaction(function () use (
            $data, $items, $totalRevenue, $totalCogs, $returnCode, $invoiceId, $customerId, $branchId
        ) {
            $returnId = DB::table('sales_returns')->insertGetId([
                'return_code' => $returnCode,
                'return_date' => $data['return_date'] ?? now()->format('Y-m-d'),
                'sales_invoice_id' => $invoiceId,
                'customer_id' => $customerId,
                'branch_id' => $branchId,
                'total_amount' => round($totalRevenue, 2),
                'cogs_amount' => round($totalCogs, 2),
                'status' => 'created',
                'is_reversed' => false,
                'reason' => $data['reason'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $itemRows = [];
            foreach ($items as $item) {
                $itemRows[] = [
                    'sales_return_id' => $returnId,
                    'sales_invoice_item_id' => $item['sales_invoice_item_id'],
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $item['warehouse_id'],
                    'qty' => $item['qty'],
                    'rate' => $item['rate'],
                    'original_cost' => $item['original_cost'],
                ];
            }
            DB::table('sales_return_items')->insert($itemRows);

            // P1-3: Audit log — return_created.
            $this->auditLogger->returnCreated(
                $data['created_by'] ?? auth()->id() ?? 0,
                $returnId, $returnCode, $invoiceId, $customerId, $branchId,
                $totalRevenue, $totalCogs
            );

            return SalesReturn::with(['items.product', 'salesInvoice', 'customer', 'branch'])->find($returnId);
        });
    }

    /**
     * Phase 2: Confirm a sales return — stock IN + GL + customer_ledger.
     *
     * @param int $returnId
     * @param int $confirmedBy
     * @return SalesReturn
     * @throws \RuntimeException If not 'created' status, or stock/GL fails.
     */
    public function confirmReturn(int $returnId, int $confirmedBy): SalesReturn
    {
        return DB::transaction(function () use ($returnId, $confirmedBy) {
            $return = SalesReturn::with('items')->lockForUpdate()->find($returnId);

            if (!$return) {
                throw new \RuntimeException("Return {$returnId} not found.");
            }
            if (!$return->isCreated()) {
                throw new \RuntimeException("Only 'created' returns can be confirmed (current: {$return->status}).");
            }

            $returnDate = $return->return_date->format('Y-m-d');

            // 1. Stock IN at ORIGINAL avg_cost (CRITICAL: not current avg_cost).
            foreach ($return->items as $item) {
                $this->stockService->applyTransaction([
                    'warehouse_id' => $item->warehouse_id,
                    'product_id' => $item->product_id,
                    'qty' => (float) $item->qty, // positive = IN
                    'rate' => (float) $item->original_cost, // ORIGINAL avg_cost from challan
                    'reference_type' => 'sales_return',
                    'reference_id' => $return->id,
                    'notes' => 'Sales Return ' . $return->return_code . ' — stock restored at original cost',
                    'transaction_date' => $returnDate,
                    'created_by' => $confirmedBy,
                ]);
            }

            // 2. GL Revenue Reversal: Dr Sales Return / Cr AR.
            $journalEntryId = $this->postRevenueReversalGL($return, $confirmedBy);

            // 3. GL COGS Reversal: Dr Inventory / Cr COGS.
            $cogsJournalEntryId = $this->postCogsReversalGL($return, $confirmedBy);

            // 4. Customer ledger: credit entry (customer owes less).
            $this->postCustomerLedgerCredit($return, $journalEntryId, $confirmedBy);

            // 5. Update return status.
            DB::table('sales_returns')
                ->where('id', $returnId)
                ->update([
                    'status' => 'confirmed',
                    'journal_entry_id' => $journalEntryId,
                    'cogs_journal_entry_id' => $cogsJournalEntryId,
                    'updated_at' => now(),
                ]);

            // P1-3: Audit log — return_confirmed.
            $this->auditLogger->returnConfirmed(
                $confirmedBy,
                $returnId, $return->return_code, (int) $return->branch_id,
                (float) $return->total_amount, (float) $return->cogs_amount,
                $journalEntryId
            );

            return SalesReturn::with([
                'items.product', 'salesInvoice', 'customer', 'branch',
                'journalEntry.lines.ledger', 'cogsJournalEntry.lines.ledger',
            ])->find($returnId);
        });
    }

    /**
     * Phase 3: Reverse a confirmed return.
     * Reverses stock + both GL journals + customer_ledger.
     *
     * @param int $returnId
     * @param int $reversedBy
     * @param string $reason
     * @return SalesReturn
     */
    public function reverseReturn(int $returnId, int $reversedBy, string $reason = ''): SalesReturn
    {
        return DB::transaction(function () use ($returnId, $reversedBy, $reason) {
            $return = SalesReturn::with('items')->lockForUpdate()->find($returnId);

            if (!$return) {
                throw new \RuntimeException("Return {$returnId} not found.");
            }
            if (!$return->isConfirmed()) {
                throw new \RuntimeException("Only confirmed returns can be reversed (current: {$return->status}).");
            }

            // Reverse both GL journals.
            if ($return->journal_entry_id) {
                $this->journalPosting->reverseJournalEntry(
                    $return->journal_entry_id, $reversedBy,
                    "Return reversed: {$reason}"
                );
            }
            if ($return->cogs_journal_entry_id) {
                $this->journalPosting->reverseJournalEntry(
                    $return->cogs_journal_entry_id, $reversedBy,
                    "Return reversed: {$reason}"
                );
            }

            // Reverse customer_ledger.
            $this->reverseCustomerLedgerCredit($return, $reversedBy, $reason);

            // Reverse each stock movement (stock OUT at original cost — append-only reversal).
            $stockTxs = DB::table('stock_transactions')
                ->where('reference_type', 'sales_return')
                ->where('reference_id', $returnId)
                ->where('is_reversed', false)
                ->get();

            foreach ($stockTxs as $tx) {
                $this->stockService->reverseTransaction(
                    $tx->id, $reversedBy,
                    "Return reversed: {$reason}"
                );
            }

            // Mark return as reversed.
            DB::table('sales_returns')
                ->where('id', $returnId)
                ->update([
                    'status' => 'reversed',
                    'is_reversed' => true,
                    'reversed_at' => now(),
                    'reversed_by' => $reversedBy,
                    'reverse_reason' => $reason,
                    'updated_at' => now(),
                ]);

            // P1-3: Audit log — return_reversed.
            $this->auditLogger->returnReversed(
                $reversedBy,
                $returnId, $return->return_code, (int) $return->branch_id,
                (float) $return->total_amount, $reason
            );

            return SalesReturn::find($returnId);
        });
    }

    /**
     * Post GL Revenue Reversal: Dr Sales Return / Cr AR.
     *
     * @return int journal_entry_id
     */
    private function postRevenueReversalGL(SalesReturn $return, int $createdBy): int
    {
        $amount = (float) $return->total_amount;
        if ($amount < 0.01) return 0;

        $returnLedgerId = $this->journalPosting->lookupLedgerByNature('sales_return');
        $arLedgerId = $this->journalPosting->lookupLedgerByNature('ar');

        if (!$returnLedgerId) {
            throw new \RuntimeException('Sales Return ledger not found (nature: sales_return).');
        }
        if (!$arLedgerId) {
            throw new \RuntimeException('Accounts Receivable ledger not found (nature: ar).');
        }

        return $this->journalPosting->createJournalEntry([
            'entry_date' => $return->return_date->format('Y-m-d'),
            'reference_type' => 'sales_return',
            'reference_id' => $return->id,
            'branch_id' => $return->branch_id,
            'description' => 'Sales Return Revenue Reversal ' . $return->return_code,
            'source' => 'sales_return',
            'created_by' => $createdBy,
        ], [
            [
                'ledger_id' => $returnLedgerId,
                'debit' => $amount, 'credit' => 0,
                'entity_type' => 'sales_return', 'entity_id' => $return->id,
                'memo' => 'Return ' . $return->return_code . ' — revenue reversal',
            ],
            [
                'ledger_id' => $arLedgerId,
                'debit' => 0, 'credit' => $amount,
                'entity_type' => 'customer', 'entity_id' => $return->customer_id,
                'memo' => 'Return ' . $return->return_code . ' — AR credit',
            ],
        ]);
    }

    /**
     * Post GL COGS Reversal: Dr Inventory / Cr COGS (at ORIGINAL avg_cost).
     *
     * @return int journal_entry_id
     */
    private function postCogsReversalGL(SalesReturn $return, int $createdBy): int
    {
        $cogsAmount = (float) $return->cogs_amount;
        if ($cogsAmount < 0.01) return 0;

        $inventoryLedgerId = $this->journalPosting->lookupLedgerByNature('inventory');
        $cogsLedgerId = $this->journalPosting->lookupLedgerByNature('cogs');

        if (!$inventoryLedgerId) {
            throw new \RuntimeException('Inventory ledger not found (nature: inventory).');
        }
        if (!$cogsLedgerId) {
            throw new \RuntimeException('COGS ledger not found (nature: cogs).');
        }

        return $this->journalPosting->createJournalEntry([
            'entry_date' => $return->return_date->format('Y-m-d'),
            'reference_type' => 'sales_return',
            'reference_id' => $return->id,
            'branch_id' => $return->branch_id,
            'description' => 'Sales Return COGS Reversal ' . $return->return_code,
            'source' => 'sales_return',
            'created_by' => $createdBy,
        ], [
            [
                'ledger_id' => $inventoryLedgerId,
                'debit' => $cogsAmount, 'credit' => 0,
                'entity_type' => 'sales_return', 'entity_id' => $return->id,
                'memo' => 'Return ' . $return->return_code . ' — inventory restored',
            ],
            [
                'ledger_id' => $cogsLedgerId,
                'debit' => 0, 'credit' => $cogsAmount,
                'entity_type' => 'sales_return', 'entity_id' => $return->id,
                'memo' => 'Return ' . $return->return_code . ' — COGS reversal',
            ],
        ]);
    }

    /**
     * Post customer_ledger credit (customer owes less).
     */
    private function postCustomerLedgerCredit(SalesReturn $return, ?int $journalEntryId, int $createdBy): void
    {
        $amount = (float) $return->total_amount;
        if ($amount < 0.01) return;

        $currentBalance = (float) DB::table('customer_ledger')
            ->where('customer_id', $return->customer_id)
            ->orderByDesc('id')
            ->value('balance');

        $newBalance = $currentBalance - $amount;

        DB::table('customer_ledger')->insert([
            'customer_id' => $return->customer_id,
            'branch_id' => $return->branch_id,
            'transaction_date' => $return->return_date->format('Y-m-d'),
            'transaction_type' => 'sales_return',
            'reference_type' => 'sales_return',
            'reference_id' => $return->id,
            'debit' => 0,
            'credit' => $amount,
            'balance' => $newBalance,
            'description' => 'Sales Return ' . $return->return_code . ($return->reason ? ' — ' . $return->reason : ''),
            'journal_entry_id' => $journalEntryId,
            'created_by' => $createdBy,
            'created_at' => now(),
        ]);
    }

    /**
     * Reverse customer_ledger (debit entry to restore what customer owes).
     */
    private function reverseCustomerLedgerCredit(SalesReturn $return, int $reversedBy, string $reason): void
    {
        $amount = (float) $return->total_amount;
        if ($amount < 0.01) return;

        $currentBalance = (float) DB::table('customer_ledger')
            ->where('customer_id', $return->customer_id)
            ->orderByDesc('id')
            ->value('balance');

        $newBalance = $currentBalance + $amount;

        DB::table('customer_ledger')->insert([
            'customer_id' => $return->customer_id,
            'branch_id' => $return->branch_id,
            'transaction_date' => now()->format('Y-m-d'),
            'transaction_type' => 'sales_return_reversal',
            'reference_type' => 'sales_return',
            'reference_id' => $return->id,
            'debit' => $amount,
            'credit' => 0,
            'balance' => $newBalance,
            'description' => 'Return reversal ' . $return->return_code . ": {$reason}",
            'created_by' => $reversedBy,
            'created_at' => now(),
        ]);
    }

    /**
     * Generate atomic return code: SR-YYYYMMDD-NNNN.
     */
    private function generateReturnCode(): string
    {
        $datePart = now()->format('Ymd');
        $periodKey = now()->format('Y-m');
        $docType = 'sales_return';

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

            return "SR-{$datePart}-" . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Validate items — look up ORIGINAL avg_cost from the challan's stock_transaction.
     *
     * @param array $items
     * @param int $invoiceId
     * @param int $challanId
     * @return array
     */
    private function validateItems(array $items, int $invoiceId, int $challanId): array
    {
        $validated = [];

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (float) ($item['qty'] ?? 0);
            $warehouseId = (int) ($item['warehouse_id'] ?? 0);
            $invoiceItemId = (int) ($item['sales_invoice_item_id'] ?? 0);

            if ($productId <= 0 || $qty <= 0 || $warehouseId <= 0) continue;

            // Check returnable_qty (invoice item qty - already returned).
            if ($invoiceItemId > 0) {
                $invoiceItem = DB::table('sales_invoice_items')->where('id', $invoiceItemId)->first();
                if ($invoiceItem) {
                    $alreadyReturned = (float) DB::table('sales_return_items as sri')
                        ->join('sales_returns as sr', 'sr.id', '=', 'sri.sales_return_id')
                        ->where('sri.sales_invoice_item_id', $invoiceItemId)
                        ->whereIn('sr.status', ['created', 'confirmed'])
                        ->where('sr.is_reversed', false)
                        ->sum('sri.qty');

                    $returnable = (float) $invoiceItem->qty - $alreadyReturned;
                    if ($qty > $returnable + 0.0001) {
                        throw new \RuntimeException(
                            "Return qty {$qty} exceeds returnable qty {$returnable} for product {$productId}."
                        );
                    }
                }
            }

            // CRITICAL: Look up ORIGINAL avg_cost from the challan's stock_transaction.
            $challanStockTx = DB::table('stock_transactions')
                ->where('reference_type', 'sales_challan')
                ->where('reference_id', $challanId)
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->where('is_reversed', false)
                ->first();

            $originalCost = $challanStockTx ? (float) $challanStockTx->rate : 0;
            if ($originalCost <= 0) {
                throw new \RuntimeException(
                    "Cannot determine original avg_cost for product {$productId} from challan. "
                    . "The challan stock transaction may be missing or reversed."
                );
            }

            // Rate = sales rate from the invoice item (for revenue reversal).
            $rate = (float) ($item['rate'] ?? 0);
            if ($rate <= 0 && $invoiceItemId > 0) {
                $invoiceItem = DB::table('sales_invoice_items')->where('id', $invoiceItemId)->first();
                $rate = $invoiceItem ? (float) $invoiceItem->rate : 0;
            }

            $validated[] = [
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'qty' => $qty,
                'rate' => $rate, // sales rate (for revenue reversal)
                'original_cost' => $originalCost, // ORIGINAL avg_cost (for COGS + stock IN)
                'sales_invoice_item_id' => $invoiceItemId > 0 ? $invoiceItemId : null,
            ];
        }

        if (empty($validated)) {
            throw new \InvalidArgumentException('At least one valid item is required.');
        }

        return $validated;
    }
}

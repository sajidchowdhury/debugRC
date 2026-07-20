<?php
// app/services/Accounting/JournalPostingService.php

require_once __DIR__ . '/../../../core/Database.php';
require_once __DIR__ . '/../../models/JournalEntryModel.php';
require_once __DIR__ . '/../../models/LedgerModel.php';
require_once __DIR__ . '/../../models/BankLedgerMappingModel.php';

class JournalPostingService
{
    private $journalModel;
    private $ledgerModel;
    private BankLedgerMappingModel $bankLedgerMapping;

    public function __construct(?Database $db = null)
    {
        $this->journalModel = new JournalEntryModel($db);
        $this->ledgerModel = new LedgerModel($db);
        $this->bankLedgerMapping = new BankLedgerMappingModel($db);
    }

    /**
     * Post Other Income using proper double-entry
     */
    public function postOtherIncome($incomeId, array $incomeData): array
    {
        $amount = (float)$incomeData['amount'];
        $narration = $incomeData['narration'] ?? 'Other Income Received';

        $lines = [];

        // Credit → Income Ledger (e.g. "Other Income - Rent Received")
        $lines[] = [
            'ledger_id'   => (int)$incomeData['ledger_id'],
            'debit'       => 0,
            'credit'      => $amount,
            'description' => $narration,
        ];

        // Debit → Bank or Cash
        if ($incomeData['payment_mode'] === 'bank' && !empty($incomeData['bank_id'])) {
            $bankLedgerId = $this->getLedgerForBank((int)$incomeData['bank_id']);

            $lines[] = [
                'ledger_id'   => $bankLedgerId,
                'debit'       => $amount,
                'credit'      => 0,
                'description' => 'Bank Receipt',
                'entity_type' => 'bank',
                'entity_id'   => (int)$incomeData['bank_id'],
            ];
        } else {
            // Cash
            $cashLedgerId = $this->getCashLedgerId();

            $lines[] = [
                'ledger_id'   => $cashLedgerId,
                'debit'       => $amount,
                'credit'      => 0,
                'description' => 'Cash Receipt',
            ];
        }

        $header = [
            'entry_date'     => $incomeData['income_date'] ?? date('Y-m-d'),
            'description'    => 'Other Income - ' . ($incomeData['income_code'] ?? ''),
            'reference_type' => 'other_income',
            'reference_id'   => $incomeId,
            'branch_id'      => $incomeData['branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
        ];

        return $this->journalModel->createEntry($header, $lines);
    }

    /**
     * Post Other Expense using proper double-entry
     */
    public function postOtherExpense($expenseId, array $expenseData): array
    {
        $amount = (float)$expenseData['amount'];
        $narration = $expenseData['narration'] ?? 'Other Expense Paid';

        $lines = [];

        // Debit → Expense Ledger (e.g. "Office Rent", "Electricity Bill")
        $lines[] = [
            'ledger_id'   => (int)$expenseData['ledger_id'],
            'debit'       => $amount,
            'credit'      => 0,
            'description' => $narration,
        ];

        // Credit → Bank or Cash
        if ($expenseData['payment_mode'] === 'bank' && !empty($expenseData['bank_id'])) {
            $bankLedgerId = $this->getLedgerForBank((int)$expenseData['bank_id']);

            $lines[] = [
                'ledger_id'   => $bankLedgerId,
                'debit'       => 0,
                'credit'      => $amount,
                'description' => 'Bank Payment',
                'entity_type' => 'bank',
                'entity_id'   => (int)$expenseData['bank_id'],
            ];
        } else {
            $cashLedgerId = $this->getCashLedgerId();

            $lines[] = [
                'ledger_id'   => $cashLedgerId,
                'debit'       => 0,
                'credit'      => $amount,
                'description' => 'Cash Payment',
            ];
        }

        $header = [
            'entry_date'     => $expenseData['expense_date'] ?? date('Y-m-d'),
            'description'    => 'Other Expense - ' . ($expenseData['expense_code'] ?? ''),
            'reference_type' => 'other_expense',
            'reference_id'   => $expenseId,
            'branch_id'      => $expenseData['branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
        ];

        return $this->journalModel->createEntry($header, $lines);
    }

    // ================== Helper Methods (Nature-based resolution) ==================

    /**
     * Get the main Bank Control Ledger (used for most bank transactions)
     */
    private function getBankControlLedgerId(): ?int
    {
        $ledgers = $this->ledgerModel->getLedgersByNature('cash_bank');

        // Prefer a ledger that explicitly says "Bank" in the name
        foreach ($ledgers as $ledger) {
            if (stripos($ledger['ledger_name'], 'bank') !== false) {
                return $ledger['id'];
            }
        }

        // Fallback to first cash_bank ledger
        return $ledgers[0]['id'] ?? null;
    }

    /**
     * Get Cash ledger (usually "Cash in Hand" or similar)
     */
    private function getCashLedgerId(): ?int
    {
        $ledgers = $this->ledgerModel->getLedgersByNature('cash_bank');

        foreach ($ledgers as $ledger) {
            $name = strtolower($ledger['ledger_name']);
            if (str_contains($name, 'cash') && !str_contains($name, 'bank')) {
                return $ledger['id'];
            }
        }

        // Fallback
        return $ledgers[0]['id'] ?? null;
    }

    /**
     * Get primary Inventory ledger (for future Sales/Purchase integration)
     */
    public function getInventoryLedgerId(): ?int
    {
        $ledgers = $this->ledgerModel->getLedgersByNature('inventory');
        return $ledgers[0]['id'] ?? null;
    }

    /**
     * Get primary Customer Receivable control account
     */
    public function getCustomerReceivableLedgerId(): ?int
    {
        $ledgers = $this->ledgerModel->getLedgersByNature('customer_receivable');
        return $ledgers[0]['id'] ?? null;
    }

    /**
     * Get primary Supplier Payable control account
     */
    public function getSupplierPayableLedgerId(): ?int
    {
        $ledgers = $this->ledgerModel->getLedgersByNature('supplier_payable');
        return $ledgers[0]['id'] ?? null;
    }

    /**
     * Get a ledger by nature (generic helper for future use)
     */
    public function getLedgerByNature(string $nature): ?int
    {
        $ledgers = $this->ledgerModel->getLedgersByNature($nature);
        return $ledgers[0]['id'] ?? null;
    }

    /**
     * Resolve GL account for a bank receipt/payment (Phase 5: bank_ledger_mappings).
     */
    private function getLedgerForBank(int $bankId): ?int
    {
        if ($bankId > 0) {
            $mapped = $this->bankLedgerMapping->getLedgerIdForBank($bankId);
            if ($mapped) {
                return $mapped;
            }
        }

        return $this->getBankControlLedgerId();
    }

    /**
     * Optional contra-revenue account for invoice line discounts (nature: sales_discount).
     */
    public function getSalesDiscountLedgerId(): ?int
    {
        $ledgers = $this->ledgerModel->getLedgersByNature('sales_discount');
        if (!empty($ledgers[0]['id'])) {
            return (int)$ledgers[0]['id'];
        }

        $all = $this->ledgerModel->getAllLedgers();
        foreach ($all as $ledger) {
            $name = strtolower($ledger['ledger_name'] ?? '');
            if (str_contains($name, 'discount') && ($ledger['is_active'] ?? 1)) {
                return (int)$ledger['id'];
            }
        }

        return null;
    }

    /**
     * Post Money Transfer using proper double-entry accounting
     */
    public function postMoneyTransfer($transferId, array $transferData): array
    {
        $amount = (float)$transferData['amount'];
        $type = $transferData['transfer_type'];
        $narration = $transferData['narration'] ?? 'Money Transfer';

        $lines = [];
        $description = "Money Transfer #{$transferData['transfer_code']} - {$type}";

        switch ($type) {
            case 'cash_to_bank':
                // Debit Bank, Credit Cash
                $bankLedgerId = $this->getLedgerForBank($transferData['to_bank_id'] ?? 0);
                $cashLedgerId = $this->getCashLedgerId();

                $lines[] = [
                    'ledger_id'   => $bankLedgerId,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => 'Cash deposited to Bank',
                    'entity_type' => 'bank',
                    'entity_id'   => $transferData['to_bank_id'] ?? null,
                ];
                $lines[] = [
                    'ledger_id'   => $cashLedgerId,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => 'Cash out for Bank deposit',
                ];
                break;

            case 'bank_to_cash':
                // Debit Cash, Credit Bank
                $bankLedgerId = $this->getLedgerForBank($transferData['from_bank_id'] ?? 0);
                $cashLedgerId = $this->getCashLedgerId();

                $lines[] = [
                    'ledger_id'   => $cashLedgerId,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => 'Cash withdrawn from Bank',
                ];
                $lines[] = [
                    'ledger_id'   => $bankLedgerId,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => 'Bank withdrawal for Cash',
                    'entity_type' => 'bank',
                    'entity_id'   => $transferData['from_bank_id'] ?? null,
                ];
                break;

            case 'cash_to_cash':
                // Inter-branch cash movement
                // Debit Receiving Branch Cash, Credit Sending Branch Cash
                $cashLedgerId = $this->getCashLedgerId();

                // Credit Sending
                $lines[] = [
                    'ledger_id'   => $cashLedgerId,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => 'Cash sent to another branch',
                    'entity_type' => 'branch',
                    'entity_id'   => $transferData['from_branch_id'] ?? null,
                ];
                // Debit Receiving
                $lines[] = [
                    'ledger_id'   => $cashLedgerId,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => 'Cash received from another branch',
                    'entity_type' => 'branch',
                    'entity_id'   => $transferData['to_branch_id'] ?? null,
                ];
                break;

            case 'bank_to_bank':
                // Bank to Bank transfer
                $fromBankLedger = $this->getLedgerForBank($transferData['from_bank_id'] ?? 0);
                $toBankLedger   = $this->getLedgerForBank($transferData['to_bank_id'] ?? 0);

                $lines[] = [
                    'ledger_id'   => $toBankLedger,
                    'debit'       => $amount,
                    'credit'      => 0,
                    'description' => 'Received from another bank',
                    'entity_type' => 'bank',
                    'entity_id'   => $transferData['to_bank_id'] ?? null,
                ];
                $lines[] = [
                    'ledger_id'   => $fromBankLedger,
                    'debit'       => 0,
                    'credit'      => $amount,
                    'description' => 'Transferred to another bank',
                    'entity_type' => 'bank',
                    'entity_id'   => $transferData['from_bank_id'] ?? null,
                ];
                break;

            default:
                return ['status' => 'error', 'message' => 'Unknown transfer type for journal posting'];
        }

        $header = [
            'entry_date'     => $transferData['transfer_date'] ?? date('Y-m-d'),
            'description'    => $description,
            'reference_type' => 'money_transfer',
            'reference_id'   => $transferId,
            'branch_id'      => $transferData['from_branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
        ];

        return $this->journalModel->createEntry($header, $lines);
    }

    /**
     * Reverse an Other Expense by creating a proper reversing journal entry.
     */
    public function reverseOtherExpense($expenseId, $reason = ''): array
    {
        // Find the original journal entry for this expense
        $this->journalModel->db->query("  -- using raw for simplicity
            SELECT id FROM journal_entries 
            WHERE reference_type = 'other_expense' AND reference_id = :expense_id 
            LIMIT 1
        ");
        // Note: This is a bit hacky. Better to have a helper in JournalEntryModel.
        // For now, we'll assume we pass the journal id or query inside the model.

        // Better approach: Let the model handle finding the journal.
        // We'll call the JournalEntryModel directly for reversal.

        // For clean implementation, we'll do the reversal logic in the model for now
        // and keep this service for posting new transactions.

        return ['status' => 'error', 'message' => 'Use direct reversal in model for now'];
    }

    // ================== PHASE 5: Purchase GL Integration ==================

    /**
     * Post Purchase Receive (GRN) using double-entry.
     *
     * Decision (Phase 2): Credit AP on Goods Receipt (not PO).
     * Dr Inventory (at actual purchase cost from GRN total)
     * Cr Supplier Payable (control account)
     *
     * Amount uses the receive's total_amount (actual cost). Inventory avg cost
     * is handled separately by StockTransactionModel for valuation/COGS.
     */
    public function postPurchaseReceive($receiveId, array $receiveData): array
    {
        $amount = (float)$receiveData['total_amount'];
        if ($amount <= 0) {
            return ['status' => 'error', 'message' => 'Invalid receive amount for journal'];
        }

        $narration = $receiveData['remarks'] ?? 'Goods Received';
        $receiveCode = $receiveData['receive_code'] ?? ('GRN-' . $receiveId);

        $lines = [];

        // Debit Inventory (asset increase at cost)
        $inventoryLedgerId = $this->getInventoryLedgerId();
        if (!$inventoryLedgerId) {
            return ['status' => 'error', 'message' => 'Inventory ledger not found (nature: inventory)'];
        }

        $lines[] = [
            'ledger_id'   => $inventoryLedgerId,
            'debit'       => $amount,
            'credit'      => 0,
            'description' => 'GRN #' . $receiveCode . ' - Inventory received @ purchase cost',
            'entity_type' => 'purchase_receive',
            'entity_id'   => (int)$receiveId,
        ];

        // Credit Supplier Payable (liability)
        $supplierPayableLedgerId = $this->getSupplierPayableLedgerId();
        if (!$supplierPayableLedgerId) {
            return ['status' => 'error', 'message' => 'Supplier Payable ledger not found (nature: supplier_payable)'];
        }

        $lines[] = [
            'ledger_id'   => $supplierPayableLedgerId,
            'debit'       => 0,
            'credit'      => $amount,
            'description' => 'GRN #' . $receiveCode . ' - Payable to supplier',
            'entity_type' => 'supplier',
            'entity_id'   => $receiveData['supplier_id'] ?? null,
        ];

        $header = [
            'entry_date'     => $receiveData['receive_date'] ?? date('Y-m-d'),
            'description'    => 'Purchase Receive - ' . $receiveCode,
            'reference_type' => 'purchase_receive',
            'reference_id'   => (int)$receiveId,
            'branch_id'      => $receiveData['branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
        ];

        $result = $this->journalModel->createEntry($header, $lines);

        // Return enhanced result so caller can easily get the id
        if (!empty($result['journal_entry_id'])) {
            $result['journal_entry_id'] = (int)$result['journal_entry_id'];
        }

        return $result;
    }

    /**
     * Post Purchase Return using double-entry (reverses the GRN impact).
     *
     * Dr Supplier Payable
     * Cr Inventory (at the cost recorded on the return)
     */
    public function postPurchaseReturn($returnId, array $returnData): array
    {
        $amount = (float)$returnData['total_amount'];
        if ($amount <= 0) {
            return ['status' => 'error', 'message' => 'Invalid return amount for journal'];
        }

        $narration = $returnData['reason'] ?? 'Purchase Return';
        $returnCode = $returnData['return_code'] ?? ('PR-' . $returnId);

        $lines = [];

        // Debit Supplier Payable (reduce liability)
        $supplierPayableLedgerId = $this->getSupplierPayableLedgerId();
        if (!$supplierPayableLedgerId) {
            return ['status' => 'error', 'message' => 'Supplier Payable ledger not found'];
        }

        $lines[] = [
            'ledger_id'   => $supplierPayableLedgerId,
            'debit'       => $amount,
            'credit'      => 0,
            'description' => 'Return #' . $returnCode . ' - AP reduced',
            'entity_type' => 'purchase_return',
            'entity_id'   => (int)$returnId,
        ];

        // Credit Inventory (asset reduction)
        $inventoryLedgerId = $this->getInventoryLedgerId();
        if (!$inventoryLedgerId) {
            return ['status' => 'error', 'message' => 'Inventory ledger not found'];
        }

        $lines[] = [
            'ledger_id'   => $inventoryLedgerId,
            'debit'       => 0,
            'credit'      => $amount,
            'description' => 'Return #' . $returnCode . ' - Inventory out (cost)',
            'entity_type' => 'purchase_return',
            'entity_id'   => (int)$returnId,
        ];

        $header = [
            'entry_date'     => $returnData['return_date'] ?? date('Y-m-d'),
            'description'    => 'Purchase Return - ' . $returnCode,
            'reference_type' => 'purchase_return',
            'reference_id'   => (int)$returnId,
            'branch_id'      => $returnData['branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
        ];

        $result = $this->journalModel->createEntry($header, $lines);

        if (!empty($result['journal_entry_id'])) {
            $result['journal_entry_id'] = (int)$result['journal_entry_id'];
        }

        return $result;
    }

    // ================== PHASE 5: Sales GL Integration ==================
    //
    // Posting policy (aligned with SALES_MODULE_MODERNIZATION_PLAN.md §5):
    // - Sales invoice (draft finalized): Dr AR (control) / Cr Sales Revenue (+ transport in revenue)
    // - Challan completed: Dr COGS / Cr Inventory at warehouse avg cost
    // - Transport/total change at challan: Dr/Cr AR vs revenue for the delta only
    // - Customer payment: Dr Bank or Cash / Cr AR
    // - Return confirmed: Dr Sales Return & Allowances / Cr AR; Dr Inventory / Cr COGS (good stock)
    // Sub-ledgers (customer_ledger) remain source for party balance; AR control reconciles in TB.

    public function getSalesRevenueLedgerId(): ?int
    {
        return $this->getLedgerByNature('sales_revenue');
    }

    public function getCogsLedgerId(): ?int
    {
        return $this->getLedgerByNature('cogs');
    }

    public function getSalesReturnLedgerId(): ?int
    {
        return $this->getLedgerByNature('sales_return');
    }

    /**
     * Post sales invoice — Dr AR / Cr revenue (and transport portion).
     */
    public function postSalesInvoice(int $invoiceId, array $invoiceData): array
    {
        $total = (float)($invoiceData['total_amount'] ?? 0);
        $subtotal = (float)($invoiceData['subtotal'] ?? 0);
        $discount = (float)($invoiceData['discount'] ?? 0);
        $transport = (float)($invoiceData['transport_cost'] ?? 0);

        if ($total <= 0) {
            return ['status' => 'error', 'message' => 'Invalid invoice amount for journal'];
        }

        $discount = max(0, round($discount, 2));
        $transport = max(0, round($transport, 2));
        $grossRevenue = max(0, round($subtotal, 2));
        $discountLedgerId = $discount > 0.0001 ? $this->getSalesDiscountLedgerId() : null;
        $revenueBase = $discountLedgerId
            ? $grossRevenue
            : max(0, round($subtotal - $discount, 2));

        if (round($revenueBase + $transport - ($discountLedgerId ? $discount : 0), 2) !== round($total, 2)) {
            $revenueBase = max(0, round($total - $transport, 2));
        }

        $arLedgerId = $this->getCustomerReceivableLedgerId();
        $revenueLedgerId = $this->getSalesRevenueLedgerId();
        if (!$arLedgerId) {
            return ['status' => 'error', 'message' => 'Accounts Receivable ledger not found (nature: customer_receivable)'];
        }
        if (!$revenueLedgerId) {
            return ['status' => 'error', 'message' => 'Sales Revenue ledger not found (nature: sales_revenue)'];
        }

        $invoiceCode = $invoiceData['invoice_code'] ?? ('SI-' . $invoiceId);
        $lines = [];

        $lines[] = [
            'ledger_id'   => $arLedgerId,
            'debit'       => $total,
            'credit'      => 0,
            'description' => 'Invoice #' . $invoiceCode . ' — AR',
            'entity_type' => 'customer',
            'entity_id'   => $invoiceData['customer_id'] ?? null,
        ];

        if ($discountLedgerId && $discount > 0) {
            $lines[] = [
                'ledger_id'   => $discountLedgerId,
                'debit'       => $discount,
                'credit'      => 0,
                'description' => 'Invoice #' . $invoiceCode . ' — Discount allowed',
                'entity_type' => 'sales_invoice',
                'entity_id'   => $invoiceId,
            ];
        }

        if ($revenueBase > 0) {
            $lines[] = [
                'ledger_id'   => $revenueLedgerId,
                'debit'       => 0,
                'credit'      => $revenueBase,
                'description' => 'Invoice #' . $invoiceCode . ' — Sales revenue' . ($discountLedgerId ? ' (gross)' : ''),
                'entity_type' => 'sales_invoice',
                'entity_id'   => $invoiceId,
            ];
        }

        if ($transport > 0) {
            $lines[] = [
                'ledger_id'   => $revenueLedgerId,
                'debit'       => 0,
                'credit'      => $transport,
                'description' => 'Invoice #' . $invoiceCode . ' — Transport charges',
                'entity_type' => 'sales_invoice',
                'entity_id'   => $invoiceId,
            ];
        }

        $creditSum = $revenueBase + $transport;
        if ($creditSum < 0.0001 && $total > 0) {
            $lines[] = [
                'ledger_id'   => $revenueLedgerId,
                'debit'       => 0,
                'credit'      => $total,
                'description' => 'Invoice #' . $invoiceCode . ' — Sales',
                'entity_type' => 'sales_invoice',
                'entity_id'   => $invoiceId,
            ];
        }

        $header = [
            'entry_date'     => $invoiceData['invoice_date'] ?? date('Y-m-d'),
            'description'    => 'Sales Invoice - ' . $invoiceCode,
            'reference_type' => 'sales_invoice',
            'reference_id'   => $invoiceId,
            'branch_id'      => $invoiceData['branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
        ];

        return $this->finalizeJournalResult($this->journalModel->createEntry($header, $lines));
    }

    /**
     * Post AR/revenue adjustment when invoice total changes (e.g. transport at challan).
     */
    public function postSalesInvoiceTotalAdjustment(int $invoiceId, float $delta, array $context): array
    {
        $delta = round($delta, 2);
        if (abs($delta) < 0.0001) {
            return ['status' => 'success', 'message' => 'No adjustment needed', 'journal_entry_id' => null];
        }

        $arLedgerId = $this->getCustomerReceivableLedgerId();
        $revenueLedgerId = $this->getSalesRevenueLedgerId();
        if (!$arLedgerId || !$revenueLedgerId) {
            return ['status' => 'error', 'message' => 'AR or Sales Revenue ledger not configured'];
        }

        $invoiceCode = $context['invoice_code'] ?? ('SI-' . $invoiceId);
        $challanCode = trim((string)($context['challan_code'] ?? ''));
        $challanSuffix = $challanCode !== '' ? ' (Challan #' . $challanCode . ')' : '';
        $lines = [];

        if ($delta > 0) {
            $lines[] = [
                'ledger_id'   => $arLedgerId,
                'debit'       => $delta,
                'credit'      => 0,
                'description' => 'Invoice #' . $invoiceCode . ' — total increase',
                'entity_type' => 'customer',
                'entity_id'   => $context['customer_id'] ?? null,
            ];
            $lines[] = [
                'ledger_id'   => $revenueLedgerId,
                'debit'       => 0,
                'credit'      => $delta,
                'description' => 'Invoice #' . $invoiceCode . ' — revenue adjustment',
                'entity_type' => 'sales_invoice',
                'entity_id'   => $invoiceId,
            ];
        } else {
            $amt = abs($delta);
            $lines[] = [
                'ledger_id'   => $revenueLedgerId,
                'debit'       => $amt,
                'credit'      => 0,
                'description' => 'Invoice #' . $invoiceCode . ' — revenue reduction',
                'entity_type' => 'sales_invoice',
                'entity_id'   => $invoiceId,
            ];
            $lines[] = [
                'ledger_id'   => $arLedgerId,
                'debit'       => 0,
                'credit'      => $amt,
                'description' => 'Invoice #' . $invoiceCode . ' — AR reduction',
                'entity_type' => 'customer',
                'entity_id'   => $context['customer_id'] ?? null,
            ];
        }

        $header = [
            'entry_date'     => $context['entry_date'] ?? date('Y-m-d'),
            'description'    => 'Sales Invoice Adjustment - ' . $invoiceCode . $challanSuffix,
            'reference_type' => 'sales_invoice_adjustment',
            'reference_id'   => $invoiceId,
            'branch_id'      => $context['branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
        ];

        return $this->finalizeJournalResult($this->journalModel->createEntry($header, $lines));
    }

    /**
     * Post COGS at challan completion — Dr COGS / Cr Inventory (sum of qty × avg cost).
     */
    public function postSalesChallanCOGS(int $challanId, array $challanData): array
    {
        $cogsAmount = (float)($challanData['cogs_amount'] ?? 0);
        if ($cogsAmount <= 0) {
            return ['status' => 'success', 'message' => 'No COGS to post', 'journal_entry_id' => null];
        }

        $cogsLedgerId = $this->getCogsLedgerId();
        $inventoryLedgerId = $this->getInventoryLedgerId();
        if (!$cogsLedgerId) {
            return ['status' => 'error', 'message' => 'COGS ledger not found (nature: cogs)'];
        }
        if (!$inventoryLedgerId) {
            return ['status' => 'error', 'message' => 'Inventory ledger not found (nature: inventory)'];
        }

        $challanCode = $challanData['challan_code'] ?? ('CH-' . $challanId);
        $lines = [
            [
                'ledger_id'   => $cogsLedgerId,
                'debit'       => $cogsAmount,
                'credit'      => 0,
                'description' => 'Challan #' . $challanCode . ' — COGS',
                'entity_type' => 'sales_challan',
                'entity_id'   => $challanId,
            ],
            [
                'ledger_id'   => $inventoryLedgerId,
                'debit'       => 0,
                'credit'      => $cogsAmount,
                'description' => 'Challan #' . $challanCode . ' — Inventory issued',
                'entity_type' => 'sales_challan',
                'entity_id'   => $challanId,
            ],
        ];

        $header = [
            'entry_date'     => $challanData['challan_date'] ?? date('Y-m-d'),
            'description'    => 'Sales Challan COGS - ' . $challanCode,
            'reference_type' => 'sales_challan',
            'reference_id'   => $challanId,
            'branch_id'      => $challanData['branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
        ];

        return $this->finalizeJournalResult($this->journalModel->createEntry($header, $lines));
    }

    /**
     * Post customer payment — Dr Bank/Cash / Cr AR.
     */
    public function postCustomerPayment(int $paymentId, array $paymentData): array
    {
        $amount = (float)($paymentData['amount'] ?? 0);
        if ($amount <= 0) {
            return ['status' => 'error', 'message' => 'Invalid payment amount for journal'];
        }

        $arLedgerId = $this->getCustomerReceivableLedgerId();
        if (!$arLedgerId) {
            return ['status' => 'error', 'message' => 'Accounts Receivable ledger not found'];
        }

        $lines = [];
        $paymentMode = $paymentData['payment_mode'] ?? 'cash';

        if ($paymentMode === 'bank' && !empty($paymentData['bank_id'])) {
            $bankLedgerId = $this->getLedgerForBank((int)$paymentData['bank_id']);
            if (!$bankLedgerId) {
                return ['status' => 'error', 'message' => 'Bank ledger not found'];
            }
            $lines[] = [
                'ledger_id'   => $bankLedgerId,
                'debit'       => $amount,
                'credit'      => 0,
                'description' => 'Customer payment received — bank',
                'entity_type' => 'bank',
                'entity_id'   => (int)$paymentData['bank_id'],
            ];
        } else {
            $cashLedgerId = $this->getCashLedgerId();
            if (!$cashLedgerId) {
                return ['status' => 'error', 'message' => 'Cash ledger not found'];
            }
            $lines[] = [
                'ledger_id'   => $cashLedgerId,
                'debit'       => $amount,
                'credit'      => 0,
                'description' => 'Customer payment received — cash',
            ];
        }

        $lines[] = [
            'ledger_id'   => $arLedgerId,
            'debit'       => 0,
            'credit'      => $amount,
            'description' => 'Payment #' . ($paymentData['payment_code'] ?? $paymentId) . ' — AR cleared',
            'entity_type' => 'customer',
            'entity_id'   => $paymentData['customer_id'] ?? null,
        ];

        $header = [
            'entry_date'     => $paymentData['payment_date'] ?? date('Y-m-d'),
            'description'    => 'Customer Payment - ' . ($paymentData['payment_code'] ?? ('PAY-' . $paymentId)),
            'reference_type' => 'customer_payment',
            'reference_id'   => $paymentId,
            'branch_id'      => $paymentData['branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
        ];

        return $this->finalizeJournalResult($this->journalModel->createEntry($header, $lines));
    }

    /**
     * Post customer payment voucher to GL by transaction_type.
     */
    public function postCustomerTransactionJournal(int $paymentId, array $paymentData, string $transactionType): array
    {
        $type = strtolower(trim($transactionType));

        return match ($type) {
            'receive'   => $this->postCustomerPayment($paymentId, $paymentData),
            'payment'   => $this->postCustomerRefund($paymentId, $paymentData),
            'discount'  => $this->postCustomerDiscount($paymentId, $paymentData),
            'write_off' => $this->postCustomerWriteOff($paymentId, $paymentData),
            default     => ['status' => 'error', 'message' => 'Unknown customer transaction type: ' . $transactionType],
        };
    }

    /**
     * Customer refund / advance paid out — Dr AR / Cr Cash or Bank.
     */
    public function postCustomerRefund(int $paymentId, array $paymentData): array
    {
        $amount = (float)($paymentData['amount'] ?? 0);
        if ($amount <= 0) {
            return ['status' => 'error', 'message' => 'Invalid refund amount for journal'];
        }

        $arLedgerId = $this->getCustomerReceivableLedgerId();
        if (!$arLedgerId) {
            return ['status' => 'error', 'message' => 'Accounts Receivable ledger not found'];
        }

        $lines = [
            [
                'ledger_id'   => $arLedgerId,
                'debit'       => $amount,
                'credit'      => 0,
                'description' => 'Refund #' . ($paymentData['payment_code'] ?? $paymentId) . ' — AR',
                'entity_type' => 'customer',
                'entity_id'   => $paymentData['customer_id'] ?? null,
            ],
        ];

        $creditLine = $this->buildCashBankCreditLine($amount, $paymentData, 'Customer refund paid — ');
        if (isset($creditLine['status'])) {
            return $creditLine;
        }
        $lines[] = $creditLine;

        $header = $this->buildCustomerPaymentJournalHeader(
            $paymentId,
            $paymentData,
            'Customer Refund'
        );

        return $this->finalizeJournalResult($this->journalModel->createEntry($header, $lines));
    }

    /**
     * Discount allowed on customer balance — Dr expense / Cr AR.
     */
    public function postCustomerDiscount(int $paymentId, array $paymentData): array
    {
        $debitLedgerId = $this->resolveCustomerDiscountLedgerId();
        if (!$debitLedgerId) {
            return ['status' => 'error', 'message' => 'Discount ledger not found (configure sales_discount or operating_expense)'];
        }

        return $this->postCustomerArAdjustment(
            $paymentId,
            $paymentData,
            'Customer Discount',
            'Customer discount allowed',
            $debitLedgerId
        );
    }

    /**
     * Bad debt write-off — Dr expense / Cr AR.
     */
    public function postCustomerWriteOff(int $paymentId, array $paymentData): array
    {
        $debitLedgerId = $this->resolveCustomerWriteOffLedgerId();
        if (!$debitLedgerId) {
            return ['status' => 'error', 'message' => 'Write-off ledger not found (configure financial_expense or operating_expense)'];
        }

        return $this->postCustomerArAdjustment(
            $paymentId,
            $paymentData,
            'Customer Write-off',
            'Bad debt write-off',
            $debitLedgerId
        );
    }

    /**
     * Dr contra-revenue or expense / Cr AR — no cash movement.
     */
    private function postCustomerArAdjustment(
        int $paymentId,
        array $paymentData,
        string $entryLabel,
        string $lineDescription,
        int $debitLedgerId
    ): array {
        $amount = (float)($paymentData['amount'] ?? 0);
        if ($amount <= 0) {
            return ['status' => 'error', 'message' => 'Invalid adjustment amount for journal'];
        }

        $arLedgerId = $this->getCustomerReceivableLedgerId();
        if (!$arLedgerId) {
            return ['status' => 'error', 'message' => 'Accounts Receivable ledger not found'];
        }

        $expenseLedgerId = $debitLedgerId;

        $code = $paymentData['payment_code'] ?? ('PAY-' . $paymentId);
        $lines = [
            [
                'ledger_id'   => $expenseLedgerId,
                'debit'       => $amount,
                'credit'      => 0,
                'description' => $lineDescription . ' #' . $code,
            ],
            [
                'ledger_id'   => $arLedgerId,
                'debit'       => 0,
                'credit'      => $amount,
                'description' => $lineDescription . ' #' . $code . ' — AR',
                'entity_type' => 'customer',
                'entity_id'   => $paymentData['customer_id'] ?? null,
            ],
        ];

        $header = $this->buildCustomerPaymentJournalHeader($paymentId, $paymentData, $entryLabel);

        return $this->finalizeJournalResult($this->journalModel->createEntry($header, $lines));
    }

    private function buildCustomerPaymentJournalHeader(int $paymentId, array $paymentData, string $label): array
    {
        return [
            'entry_date'     => $paymentData['payment_date'] ?? date('Y-m-d'),
            'description'    => $label . ' - ' . ($paymentData['payment_code'] ?? ('PAY-' . $paymentId)),
            'reference_type' => 'customer_payment',
            'reference_id'   => $paymentId,
            'branch_id'      => $paymentData['branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
        ];
    }

    /**
     * Cash/bank line for money paid out (credit cash/bank).
     *
     * @return array Journal line or error payload with status=error
     */
    private function buildCashBankCreditLine(float $amount, array $paymentData, string $descriptionPrefix): array
    {
        $paymentMode = $paymentData['payment_mode'] ?? 'cash';

        if ($paymentMode === 'bank' && !empty($paymentData['bank_id'])) {
            $bankLedgerId = $this->getLedgerForBank((int)$paymentData['bank_id']);
            if (!$bankLedgerId) {
                return ['status' => 'error', 'message' => 'Bank ledger not found'];
            }
            return [
                'ledger_id'   => $bankLedgerId,
                'debit'       => 0,
                'credit'      => $amount,
                'description' => $descriptionPrefix . 'bank',
                'entity_type' => 'bank',
                'entity_id'   => (int)$paymentData['bank_id'],
            ];
        }

        $cashLedgerId = $this->getCashLedgerId();
        if (!$cashLedgerId) {
            return ['status' => 'error', 'message' => 'Cash ledger not found'];
        }

        return [
            'ledger_id'   => $cashLedgerId,
            'debit'       => 0,
            'credit'      => $amount,
            'description' => $descriptionPrefix . 'cash',
        ];
    }

    /**
     * Cash/bank line for money received in (debit cash/bank).
     */
    private function buildCashBankDebitLine(float $amount, array $paymentData, string $descriptionPrefix): array
    {
        $paymentMode = $paymentData['payment_mode'] ?? 'cash';

        if ($paymentMode === 'bank' && !empty($paymentData['bank_id'])) {
            $bankLedgerId = $this->getLedgerForBank((int)$paymentData['bank_id']);
            if (!$bankLedgerId) {
                return ['status' => 'error', 'message' => 'Bank ledger not found'];
            }
            return [
                'ledger_id'   => $bankLedgerId,
                'debit'       => $amount,
                'credit'      => 0,
                'description' => $descriptionPrefix . 'bank',
                'entity_type' => 'bank',
                'entity_id'   => (int)$paymentData['bank_id'],
            ];
        }

        $cashLedgerId = $this->getCashLedgerId();
        if (!$cashLedgerId) {
            return ['status' => 'error', 'message' => 'Cash ledger not found'];
        }

        return [
            'ledger_id'   => $cashLedgerId,
            'debit'       => $amount,
            'credit'      => 0,
            'description' => $descriptionPrefix . 'cash',
        ];
    }

    /**
     * Customer discount — prefer sales_discount (contra-revenue), else operating expense.
     */
    private function resolveCustomerDiscountLedgerId(): ?int
    {
        return $this->getSalesDiscountLedgerId()
            ?? $this->getLedgerByNature('operating_expense');
    }

    /**
     * Bad debt write-off — prefer financial expense, else operating expense.
     */
    private function resolveCustomerWriteOffLedgerId(): ?int
    {
        $id = $this->getLedgerByNature('financial_expense');
        if ($id) {
            return $id;
        }

        $all = $this->ledgerModel->getAllLedgers();
        foreach ($all as $ledger) {
            if (empty($ledger['is_active'])) {
                continue;
            }
            $name = strtolower($ledger['ledger_name'] ?? '');
            if (str_contains($name, 'bad debt') || str_contains($name, 'write-off') || str_contains($name, 'write off')) {
                return (int)$ledger['id'];
            }
        }

        return $this->getLedgerByNature('operating_expense');
    }

    /**
     * Human-readable labels for customer payment GL preview (create form).
     *
     * @return array{ar: string, cash: string, bank: string, discount: string, write_off: string}
     */
    public function getCustomerTransactionGlPreviewLabels(): array
    {
        return [
            'ar'        => $this->ledgerLabelById($this->getCustomerReceivableLedgerId(), 'Accounts Receivable'),
            'cash'      => $this->ledgerLabelById($this->getCashLedgerId(), 'Cash'),
            'bank'      => 'Selected bank account',
            'discount'  => $this->ledgerLabelById($this->resolveCustomerDiscountLedgerId(), 'Sales discount'),
            'write_off' => $this->ledgerLabelById($this->resolveCustomerWriteOffLedgerId(), 'Bad debt expense'),
        ];
    }

    /**
     * Human-readable labels for supplier payment GL preview (create form).
     *
     * @return array{ap: string, cash: string, bank: string}
     */
    public function getSupplierTransactionGlPreviewLabels(): array
    {
        return [
            'ap'   => $this->ledgerLabelById($this->getSupplierPayableLedgerId(), 'Supplier Payable'),
            'cash' => $this->ledgerLabelById($this->getCashLedgerId(), 'Cash'),
            'bank' => 'Selected bank account',
        ];
    }

    /**
     * Human-readable labels for employee transaction GL preview (create form).
     *
     * @return array{employee_payable: string, cash: string, bank: string}
     */
    public function getEmployeeTransactionGlPreviewLabels(): array
    {
        return [
            'employee_payable' => $this->ledgerLabelById($this->getEmployeePayableLedgerId(), 'Employee Payable'),
            'cash'             => $this->ledgerLabelById($this->getCashLedgerId(), 'Cash'),
            'bank'             => 'Selected bank account',
        ];
    }

    /**
     * Whether exactly one active employee_payable control ledger exists.
     *
     * @return array{configured: bool, count: int, ledger_name: ?string, ledger_id: ?int}
     */
    public function getEmployeePayableLedgerStatus(): array
    {
        $ledgers = $this->ledgerModel->getLedgersByNature('employee_payable');
        $active = array_values(array_filter($ledgers, static fn($l) => !empty($l['is_active'])));
        $count = count($active);

        return [
            'configured'  => $count === 1,
            'count'       => $count,
            'ledger_name' => $active[0]['ledger_name'] ?? null,
            'ledger_id'   => isset($active[0]['id']) ? (int)$active[0]['id'] : null,
        ];
    }

    private function ledgerLabelById(?int $ledgerId, string $fallback): string
    {
        if (!$ledgerId) {
            return $fallback;
        }

        $ledger = $this->ledgerModel->getLedgerById($ledgerId);

        return $ledger['ledger_name'] ?? $fallback;
    }

    /**
     * Post confirmed sales return — Dr Sales Return / Cr AR; Dr Inventory / Cr COGS (at cost).
     */
    public function postSalesReturn(int $returnId, array $returnData): array
    {
        $revenueAmount = round((float)($returnData['revenue_amount'] ?? 0), 2);
        $cogsAmount = round((float)($returnData['cogs_amount'] ?? 0), 2);

        if ($revenueAmount <= 0 && $cogsAmount <= 0) {
            return ['status' => 'error', 'message' => 'Nothing to post for sales return'];
        }

        $arLedgerId = $this->getCustomerReceivableLedgerId();
        $returnLedgerId = $this->getSalesReturnLedgerId();
        $cogsLedgerId = $this->getCogsLedgerId();
        $inventoryLedgerId = $this->getInventoryLedgerId();

        if (!$arLedgerId) {
            return ['status' => 'error', 'message' => 'Accounts Receivable ledger not found'];
        }

        $returnCode = $returnData['return_code'] ?? ('SR-' . $returnId);
        $lines = [];

        if ($revenueAmount > 0) {
            if (!$returnLedgerId) {
                return ['status' => 'error', 'message' => 'Sales Return ledger not found (nature: sales_return)'];
            }
            $lines[] = [
                'ledger_id'   => $returnLedgerId,
                'debit'       => $revenueAmount,
                'credit'      => 0,
                'description' => 'Return #' . $returnCode . ' — revenue reversal',
                'entity_type' => 'sales_return',
                'entity_id'   => $returnId,
            ];
            $lines[] = [
                'ledger_id'   => $arLedgerId,
                'debit'       => 0,
                'credit'      => $revenueAmount,
                'description' => 'Return #' . $returnCode . ' — AR credit',
                'entity_type' => 'customer',
                'entity_id'   => $returnData['customer_id'] ?? null,
            ];
        }

        if ($cogsAmount > 0) {
            if (!$cogsLedgerId || !$inventoryLedgerId) {
                return ['status' => 'error', 'message' => 'COGS or Inventory ledger not found'];
            }
            $lines[] = [
                'ledger_id'   => $inventoryLedgerId,
                'debit'       => $cogsAmount,
                'credit'      => 0,
                'description' => 'Return #' . $returnCode . ' — inventory restored',
                'entity_type' => 'sales_return',
                'entity_id'   => $returnId,
            ];
            $lines[] = [
                'ledger_id'   => $cogsLedgerId,
                'debit'       => 0,
                'credit'      => $cogsAmount,
                'description' => 'Return #' . $returnCode . ' — COGS reversal',
                'entity_type' => 'sales_return',
                'entity_id'   => $returnId,
            ];
        }

        $header = [
            'entry_date'     => $returnData['return_date'] ?? date('Y-m-d'),
            'description'    => 'Sales Return - ' . $returnCode,
            'reference_type' => 'sales_return',
            'reference_id'   => $returnId,
            'branch_id'      => $returnData['branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
        ];

        return $this->finalizeJournalResult($this->journalModel->createEntry($header, $lines));
    }

    // ================== Stock Take GL (Phase 3) ==================

    /**
     * Expense for count shortages — Dr shrinkage / Cr inventory.
     */
    public function getInventoryShrinkageLedgerId(): ?int
    {
        $id = $this->getLedgerByNature('inventory_shrinkage');
        if ($id) {
            return $id;
        }

        return $this->getCogsLedgerId();
    }

    /**
     * Income/contra for count overages — Dr inventory / Cr surplus.
     */
    public function getInventorySurplusLedgerId(): ?int
    {
        $id = $this->getLedgerByNature('inventory_surplus');
        if ($id) {
            return $id;
        }

        return $this->getLedgerByNature('other_income');
    }

    /**
     * Post stock take variances: shortage → Dr shrinkage / Cr inventory;
     * overage → Dr inventory / Cr surplus. Amounts at avg cost from count lines.
     */
    public function postStockTakeSession(int $sessionId, array $session, float $lossAmount, float $gainAmount): array
    {
        $lossAmount = round(max(0, $lossAmount), 2);
        $gainAmount = round(max(0, $gainAmount), 2);

        if ($lossAmount < 0.01 && $gainAmount < 0.01) {
            return [
                'status'           => 'success',
                'message'          => 'No GL amounts for this session',
                'journal_entry_id' => null,
            ];
        }

        $inventoryId = $this->getInventoryLedgerId();
        if (!$inventoryId) {
            return ['status' => 'error', 'message' => 'Inventory ledger not found (nature: inventory)'];
        }

        $shrinkageId = $this->getInventoryShrinkageLedgerId();
        $surplusId   = $this->getInventorySurplusLedgerId();
        if ($lossAmount >= 0.01 && !$shrinkageId) {
            return ['status' => 'error', 'message' => 'Shrinkage/COGS ledger not found for shortage posting'];
        }
        if ($gainAmount >= 0.01 && !$surplusId) {
            return ['status' => 'error', 'message' => 'Surplus/other income ledger not found for overage posting'];
        }

        $sessionCode = $session['session_code'] ?? ('ST-' . $sessionId);
        $lines       = [];

        if ($lossAmount >= 0.01) {
            $lines[] = [
                'ledger_id'   => $shrinkageId,
                'debit'       => $lossAmount,
                'credit'      => 0,
                'description' => 'Stock take shortage — ' . $sessionCode,
            ];
            $lines[] = [
                'ledger_id'   => $inventoryId,
                'debit'       => 0,
                'credit'      => $lossAmount,
                'description' => 'Inventory reduction (count shortage)',
            ];
        }

        if ($gainAmount >= 0.01) {
            $lines[] = [
                'ledger_id'   => $inventoryId,
                'debit'       => $gainAmount,
                'credit'      => 0,
                'description' => 'Inventory increase (count surplus)',
            ];
            $lines[] = [
                'ledger_id'   => $surplusId,
                'debit'       => 0,
                'credit'      => $gainAmount,
                'description' => 'Stock take surplus — ' . $sessionCode,
            ];
        }

        $header = [
            'entry_date'     => $session['take_date'] ?? date('Y-m-d'),
            'description'    => 'Stock Take — ' . $sessionCode,
            'reference_type' => 'stock_take',
            'reference_id'   => $sessionId,
            'branch_id'      => (int)($session['branch_id'] ?? ($_SESSION['branch_id'] ?? 1)),
        ];

        return $this->finalizeJournalResult($this->journalModel->createEntry($header, $lines));
    }

    /**
     * Post manual stock adjustment: decrease → Dr shrinkage / Cr inventory;
     * increase → Dr inventory / Cr surplus (same economics as stock take).
     */
    public function postStockAdjustment(int $adjustmentId, array $adjustment, float $lossAmount, float $gainAmount): array
    {
        $lossAmount = round(max(0, $lossAmount), 2);
        $gainAmount = round(max(0, $gainAmount), 2);

        if ($lossAmount < 0.01 && $gainAmount < 0.01) {
            return [
                'status'           => 'success',
                'message'          => 'No GL amounts for this adjustment',
                'journal_entry_id' => null,
            ];
        }

        $inventoryId = $this->getInventoryLedgerId();
        if (!$inventoryId) {
            return ['status' => 'error', 'message' => 'Inventory ledger not found (nature: inventory)'];
        }

        $shrinkageId = $this->getInventoryShrinkageLedgerId();
        $surplusId   = $this->getInventorySurplusLedgerId();
        if ($lossAmount >= 0.01 && !$shrinkageId) {
            return ['status' => 'error', 'message' => 'Shrinkage/COGS ledger not found for decrease posting'];
        }
        if ($gainAmount >= 0.01 && !$surplusId) {
            return ['status' => 'error', 'message' => 'Surplus/other income ledger not found for increase posting'];
        }

        $adjCode = $adjustment['adjustment_code'] ?? ('ADJ-' . $adjustmentId);
        $lines   = [];

        if ($lossAmount >= 0.01) {
            $lines[] = [
                'ledger_id'   => $shrinkageId,
                'debit'       => $lossAmount,
                'credit'      => 0,
                'description' => 'Stock adjustment decrease — ' . $adjCode,
            ];
            $lines[] = [
                'ledger_id'   => $inventoryId,
                'debit'       => 0,
                'credit'      => $lossAmount,
                'description' => 'Inventory reduction (manual adjustment)',
            ];
        }

        if ($gainAmount >= 0.01) {
            $lines[] = [
                'ledger_id'   => $inventoryId,
                'debit'       => $gainAmount,
                'credit'      => 0,
                'description' => 'Inventory increase (manual adjustment)',
            ];
            $lines[] = [
                'ledger_id'   => $surplusId,
                'debit'       => 0,
                'credit'      => $gainAmount,
                'description' => 'Stock adjustment increase — ' . $adjCode,
            ];
        }

        $header = [
            'entry_date'     => $adjustment['adjustment_date'] ?? date('Y-m-d'),
            'description'    => 'Stock Adjustment — ' . $adjCode,
            'reference_type' => 'stock_adjustment',
            'reference_id'   => $adjustmentId,
            'branch_id'      => (int)($adjustment['branch_id'] ?? ($_SESSION['branch_id'] ?? 1)),
        ];

        return $this->finalizeJournalResult($this->journalModel->createEntry($header, $lines));
    }

    /**
     * Post damage write-off: Dr shrinkage / Cr inventory (same economics as stock decrease).
     */
    public function postDamage(int $damageId, array $damage, float $lossAmount): array
    {
        $lossAmount = round(max(0, $lossAmount), 2);
        if ($lossAmount < 0.01) {
            return [
                'status'           => 'success',
                'message'          => 'No GL amount for this damage',
                'journal_entry_id' => null,
            ];
        }

        $inventoryId = $this->getInventoryLedgerId();
        if (!$inventoryId) {
            return ['status' => 'error', 'message' => 'Inventory ledger not found (nature: inventory)'];
        }

        $shrinkageId = $this->getInventoryShrinkageLedgerId();
        if (!$shrinkageId) {
            return ['status' => 'error', 'message' => 'Shrinkage ledger not found for damage posting'];
        }

        $dmgCode = $damage['damage_code'] ?? ('DMG-' . $damageId);
        $lines   = [
            [
                'ledger_id'   => $shrinkageId,
                'debit'       => $lossAmount,
                'credit'      => 0,
                'description' => 'Damage / write-off — ' . $dmgCode,
            ],
            [
                'ledger_id'   => $inventoryId,
                'debit'       => 0,
                'credit'      => $lossAmount,
                'description' => 'Inventory reduction (damaged goods)',
            ],
        ];

        $header = [
            'entry_date'     => $damage['damage_date'] ?? date('Y-m-d'),
            'description'    => 'Damage — ' . $dmgCode,
            'reference_type' => 'damage',
            'reference_id'   => $damageId,
            'branch_id'      => (int)($damage['branch_id'] ?? ($_SESSION['branch_id'] ?? 1)),
        ];

        return $this->finalizeJournalResult($this->journalModel->createEntry($header, $lines));
    }

    /**
     * Create a reversing journal for any linked sales document.
     */
    public function reverseLinkedJournal(?int $journalEntryId, string $reason): array
    {
        if (!$journalEntryId) {
            return ['status' => 'success', 'message' => 'No journal to reverse', 'journal_entry_id' => null];
        }
        return $this->journalModel->createReversingEntry($journalEntryId, $reason);
    }

    /**
     * Find active (non-reversed) journal id for a reference document.
     */
    public function findActiveJournalIdByReference(string $referenceType, int $referenceId): ?int
    {
        return $this->journalModel->findActiveJournalEntryByReference($referenceType, $referenceId);
    }

    private function finalizeJournalResult(array $result): array
    {
        if (!empty($result['journal_entry_id'])) {
            $result['journal_entry_id'] = (int)$result['journal_entry_id'];
        }
        return $result;
    }

    // ================== Supplier Payment GL Integration (for SupplierTransaction) ==================

    /**
     * Post supplier payment voucher to GL by transaction_type.
     * payment/advance: pay supplier -> Dr AP / Cr Cash/Bank (reduce liability)
     * receive: receive from supplier (e.g. credit) -> Dr Cash/Bank / Cr AP (increase liability)
     */
    public function postSupplierTransactionJournal(int $paymentId, array $paymentData, string $transactionType): array
    {
        $type = strtolower(trim($transactionType));

        return match ($type) {
            'payment' => $this->postSupplierPayment($paymentId, $paymentData),
            'advance' => $this->postSupplierPayment($paymentId, $paymentData),
            'receive' => $this->postSupplierReceive($paymentId, $paymentData),
            default   => ['status' => 'error', 'message' => 'Unknown supplier transaction type: ' . $transactionType],
        };
    }

    /**
     * Pay supplier (or advance) — Dr Supplier Payable / Cr Cash or Bank.
     */
    public function postSupplierPayment(int $paymentId, array $paymentData): array
    {
        $amount = (float)($paymentData['amount'] ?? 0);
        if ($amount <= 0) {
            return ['status' => 'error', 'message' => 'Invalid payment amount for journal'];
        }

        $apLedgerId = $this->getSupplierPayableLedgerId();
        if (!$apLedgerId) {
            return ['status' => 'error', 'message' => 'Supplier Payable ledger not found'];
        }

        $lines = [
            [
                'ledger_id'   => $apLedgerId,
                'debit'       => $amount,
                'credit'      => 0,
                'description' => 'Payment #' . ($paymentData['payment_code'] ?? $paymentId) . ' — AP reduced',
                'entity_type' => 'supplier',
                'entity_id'   => $paymentData['supplier_id'] ?? null,
            ],
        ];

        $creditLine = $this->buildCashBankCreditLine($amount, $paymentData, 'Supplier payment — ');
        if (isset($creditLine['status'])) {
            return $creditLine;
        }
        $lines[] = $creditLine;

        $header = [
            'entry_date'     => $paymentData['payment_date'] ?? date('Y-m-d'),
            'description'    => 'Supplier Payment - ' . ($paymentData['payment_code'] ?? ('SPAY-' . $paymentId)),
            'reference_type' => 'supplier_payment',
            'reference_id'   => $paymentId,
            'branch_id'      => $paymentData['branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
        ];

        return $this->finalizeJournalResult($this->journalModel->createEntry($header, $lines));
    }

    /**
     * Receive from supplier (credit/return of funds) — Dr Cash/Bank / Cr Supplier Payable.
     */
    public function postSupplierReceive(int $paymentId, array $paymentData): array
    {
        $amount = (float)($paymentData['amount'] ?? 0);
        if ($amount <= 0) {
            return ['status' => 'error', 'message' => 'Invalid receive amount for journal'];
        }

        $apLedgerId = $this->getSupplierPayableLedgerId();
        if (!$apLedgerId) {
            return ['status' => 'error', 'message' => 'Supplier Payable ledger not found'];
        }

        $lines = [
            [
                'ledger_id'   => $apLedgerId,
                'debit'       => 0,
                'credit'      => $amount,
                'description' => 'Receive #' . ($paymentData['payment_code'] ?? $paymentId) . ' — AP increased',
                'entity_type' => 'supplier',
                'entity_id'   => $paymentData['supplier_id'] ?? null,
            ],
        ];

        $debitLine = $this->buildCashBankDebitLine($amount, $paymentData, 'Supplier receive — ');
        if (isset($debitLine['status'])) {
            return $debitLine;
        }
        $lines[] = $debitLine;

        $header = [
            'entry_date'     => $paymentData['payment_date'] ?? date('Y-m-d'),
            'description'    => 'Supplier Receive - ' . ($paymentData['payment_code'] ?? ('SPAY-' . $paymentId)),
            'reference_type' => 'supplier_payment',
            'reference_id'   => $paymentId,
            'branch_id'      => $paymentData['branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
        ];

        return $this->finalizeJournalResult($this->journalModel->createEntry($header, $lines));
    }

    // ================== Employee Transaction GL Integration ==================

    public function getEmployeePayableLedgerId(): ?int
    {
        return $this->getLedgerByNature('employee_payable');
    }

    /**
     * Post employee transaction to GL.
     * Outflow (advance, loan, salary, adjustment): Dr employee control / Cr cash-bank.
     * Inflow (repayment, deduction): Dr cash-bank / Cr employee control.
     */
    public function postEmployeeTransactionJournal(int $transactionId, array $txnData, string $transactionType): array
    {
        $type = strtolower(trim($transactionType));

        if (in_array($type, ['advance', 'loan', 'salary', 'adjustment'], true)) {
            return $this->postEmployeeOutflow($transactionId, $txnData, $type);
        }

        if (in_array($type, ['repayment', 'deduction'], true)) {
            return $this->postEmployeeInflow($transactionId, $txnData, $type);
        }

        return ['status' => 'error', 'message' => 'Unknown employee transaction type: ' . $transactionType];
    }

    private function postEmployeeOutflow(int $transactionId, array $txnData, string $type): array
    {
        $amount = (float)($txnData['amount'] ?? 0);
        if ($amount <= 0) {
            return ['status' => 'error', 'message' => 'Invalid amount for journal'];
        }

        $empLedgerId = $this->getEmployeePayableLedgerId();
        if (!$empLedgerId) {
            return ['status' => 'error', 'message' => 'Employee Payable ledger not found (nature: employee_payable)'];
        }

        $lines = [
            [
                'ledger_id'   => $empLedgerId,
                'debit'       => $amount,
                'credit'      => 0,
                'description' => ucfirst($type) . ' #' . ($txnData['transaction_code'] ?? $transactionId),
                'entity_type' => 'employee',
                'entity_id'   => $txnData['employee_id'] ?? null,
            ],
        ];

        $creditLine = $this->buildCashBankCreditLine($amount, $txnData, 'Employee ' . $type . ' — ');
        if (isset($creditLine['status'])) {
            return $creditLine;
        }
        $lines[] = $creditLine;

        $header = [
            'entry_date'     => $txnData['transaction_date'] ?? date('Y-m-d'),
            'description'    => 'Employee ' . ucfirst($type) . ' - ' . ($txnData['transaction_code'] ?? ('ET-' . $transactionId)),
            'reference_type' => 'employee_transaction',
            'reference_id'   => $transactionId,
            'branch_id'      => $txnData['branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
        ];

        return $this->finalizeJournalResult($this->journalModel->createEntry($header, $lines));
    }

    private function postEmployeeInflow(int $transactionId, array $txnData, string $type): array
    {
        $amount = (float)($txnData['amount'] ?? 0);
        if ($amount <= 0) {
            return ['status' => 'error', 'message' => 'Invalid amount for journal'];
        }

        $empLedgerId = $this->getEmployeePayableLedgerId();
        if (!$empLedgerId) {
            return ['status' => 'error', 'message' => 'Employee Payable ledger not found (nature: employee_payable)'];
        }

        $lines = [
            [
                'ledger_id'   => $empLedgerId,
                'debit'       => 0,
                'credit'      => $amount,
                'description' => ucfirst($type) . ' #' . ($txnData['transaction_code'] ?? $transactionId),
                'entity_type' => 'employee',
                'entity_id'   => $txnData['employee_id'] ?? null,
            ],
        ];

        $debitLine = $this->buildCashBankDebitLine($amount, $txnData, 'Employee ' . $type . ' — ');
        if (isset($debitLine['status'])) {
            return $debitLine;
        }
        $lines[] = $debitLine;

        $header = [
            'entry_date'     => $txnData['transaction_date'] ?? date('Y-m-d'),
            'description'    => 'Employee ' . ucfirst($type) . ' - ' . ($txnData['transaction_code'] ?? ('ET-' . $transactionId)),
            'reference_type' => 'employee_transaction',
            'reference_id'   => $transactionId,
            'branch_id'      => $txnData['branch_id'] ?? ($_SESSION['branch_id'] ?? 1),
        ];

        return $this->finalizeJournalResult($this->journalModel->createEntry($header, $lines));
    }

    public function getInterbranchReceivableLedgerId(): ?int
    {
        return $this->resolveLedgerByNature('interbranch_receivable', 'due from');
    }

    public function getInterbranchPayableLedgerId(): ?int
    {
        return $this->resolveLedgerByNature('interbranch_payable', 'due to');
    }

    private function resolveLedgerByNature(string $nature, string $nameHint): ?int
    {
        $ledgers = $this->ledgerModel->getLedgersByNature($nature);
        if (!empty($ledgers[0]['id'])) {
            return (int)$ledgers[0]['id'];
        }

        foreach ($this->ledgerModel->getAllLedgers() as $ledger) {
            $name = strtolower($ledger['ledger_name'] ?? '');
            if (str_contains($name, $nameHint) && ($ledger['is_active'] ?? 1)) {
                return (int)$ledger['id'];
            }
        }

        return null;
    }

    /**
     * Inter-branch stock fulfillment — creditor: Dr Due from branch / Cr Inventory;
     * debtor: Dr Inventory / Cr Due to branch.
     */
    public function postBranchDemandFulfillment(
        int $demandId,
        int $branchId,
        int $counterpartyBranchId,
        float $amount,
        string $entryDate,
        string $side
    ): array {
        if ($amount <= 0) {
            return ['status' => 'success', 'message' => 'No amount', 'journal_entry_id' => null];
        }

        $inventoryId = $this->getInventoryLedgerId();
        $recvId = $this->getInterbranchReceivableLedgerId();
        $payId = $this->getInterbranchPayableLedgerId();

        if (!$inventoryId || !$recvId || !$payId) {
            return ['status' => 'error', 'message' => 'Inter-branch or inventory ledger not configured. Run migration 021.'];
        }

        $lines = [];
        if ($side === 'creditor') {
            $lines[] = [
                'ledger_id'   => $recvId,
                'debit'       => $amount,
                'credit'      => 0,
                'description' => "Demand #{$demandId} — due from branch {$counterpartyBranchId}",
                'entity_type' => 'branch',
                'entity_id'   => $counterpartyBranchId,
            ];
            $lines[] = [
                'ledger_id'   => $inventoryId,
                'debit'       => 0,
                'credit'      => $amount,
                'description' => "Demand #{$demandId} — stock out",
            ];
        } else {
            $lines[] = [
                'ledger_id'   => $inventoryId,
                'debit'       => $amount,
                'credit'      => 0,
                'description' => "Demand #{$demandId} — stock in",
            ];
            $lines[] = [
                'ledger_id'   => $payId,
                'debit'       => 0,
                'credit'      => $amount,
                'description' => "Demand #{$demandId} — due to branch {$counterpartyBranchId}",
                'entity_type' => 'branch',
                'entity_id'   => $counterpartyBranchId,
            ];
        }

        $header = [
            'entry_date'     => $entryDate,
            'description'    => "Branch demand fulfillment #{$demandId} ({$side})",
            'reference_type' => 'branch_demand',
            'reference_id'   => $demandId,
            'branch_id'      => $branchId,
        ];

        return $this->finalizeJournalResult($this->journalModel->createEntry($header, $lines));
    }

    /**
     * Standalone inter-branch warehouse transfer — posts both branch journals.
     * Sender (from branch): Dr Due from / Cr Inventory.
     * Receiver (to branch): Dr Inventory / Cr Due to.
     * Skipped when amount < 0.01. Not used when GL already posted via branch_demand.
     */
    public function postWarehouseTransferInterbranch(
        int $transferId,
        string $transferCode,
        string $entryDate,
        int $fromBranchId,
        int $toBranchId,
        float $amount
    ): array {
        $amount = round(max(0, $amount), 2);
        if ($amount < 0.01) {
            return [
                'status'               => 'success',
                'message'              => 'No GL amounts for this transfer',
                'journal_entry_id'     => null,
                'journal_entry_id_debtor' => null,
            ];
        }

        $creditor = $this->postInterbranchStockMovement(
            $transferId,
            'warehouse_transfer',
            "Warehouse transfer {$transferCode} — stock out",
            $entryDate,
            $fromBranchId,
            $toBranchId,
            $amount,
            'creditor'
        );
        if (($creditor['status'] ?? '') !== 'success') {
            return $creditor;
        }

        $debtor = $this->postInterbranchStockMovement(
            $transferId,
            'warehouse_transfer',
            "Warehouse transfer {$transferCode} — stock in",
            $entryDate,
            $toBranchId,
            $fromBranchId,
            $amount,
            'debtor'
        );
        if (($debtor['status'] ?? '') !== 'success') {
            return $debtor;
        }

        return [
            'status'                  => 'success',
            'message'                 => 'Inter-branch GL posted',
            'journal_entry_id'        => $creditor['journal_entry_id'] ?? null,
            'journal_entry_id_debtor' => $debtor['journal_entry_id'] ?? null,
            'entry_no_from'           => $creditor['entry_no'] ?? null,
            'entry_no_to'             => $debtor['entry_no'] ?? null,
        ];
    }

    /**
     * One branch leg of inter-branch stock movement (shared by demand fulfillment and WT).
     */
    public function postInterbranchStockMovement(
        int $documentId,
        string $referenceType,
        string $description,
        string $entryDate,
        int $branchId,
        int $counterpartyBranchId,
        float $amount,
        string $side
    ): array {
        if ($amount <= 0) {
            return ['status' => 'success', 'message' => 'No amount', 'journal_entry_id' => null];
        }

        $inventoryId = $this->getInventoryLedgerId();
        $recvId = $this->getInterbranchReceivableLedgerId();
        $payId = $this->getInterbranchPayableLedgerId();

        if (!$inventoryId || !$recvId || !$payId) {
            return ['status' => 'error', 'message' => 'Inter-branch or inventory ledger not configured. Run migration 021.'];
        }

        $lines = [];
        if ($side === 'creditor') {
            $lines[] = [
                'ledger_id'   => $recvId,
                'debit'       => $amount,
                'credit'      => 0,
                'description' => $description,
                'entity_type' => 'branch',
                'entity_id'   => $counterpartyBranchId,
            ];
            $lines[] = [
                'ledger_id'   => $inventoryId,
                'debit'       => 0,
                'credit'      => $amount,
                'description' => 'Inter-branch stock out',
            ];
        } else {
            $lines[] = [
                'ledger_id'   => $inventoryId,
                'debit'       => $amount,
                'credit'      => 0,
                'description' => 'Inter-branch stock in',
            ];
            $lines[] = [
                'ledger_id'   => $payId,
                'debit'       => 0,
                'credit'      => $amount,
                'description' => $description,
                'entity_type' => 'branch',
                'entity_id'   => $counterpartyBranchId,
            ];
        }

        $header = [
            'entry_date'     => $entryDate,
            'description'    => $description,
            'reference_type' => $referenceType,
            'reference_id'   => $documentId,
            'branch_id'      => $branchId,
        ];

        return $this->finalizeJournalResult($this->journalModel->createEntry($header, $lines));
    }

    /**
     * Settlement memo: reduce inter-branch due without re-posting cash (bank payment already Dr Bank / Cr AR).
     */
    public function postBranchDemandSettlement(
        int $demandId,
        int $debtorBranchId,
        int $creditorBranchId,
        float $amount,
        string $entryDate,
        string $sourceType,
        int $sourceId
    ): array {
        if ($amount <= 0) {
            return ['status' => 'success', 'message' => 'No amount', 'journal_entry_id' => null];
        }

        $recvId = $this->getInterbranchReceivableLedgerId();
        $payId = $this->getInterbranchPayableLedgerId();
        if (!$recvId || !$payId) {
            return ['status' => 'error', 'message' => 'Inter-branch ledgers not configured.'];
        }

        $lines = [
            [
                'ledger_id'   => $payId,
                'debit'       => $amount,
                'credit'      => 0,
                'description' => "Settlement demand #{$demandId} — reduce payable",
                'entity_type' => 'branch',
                'entity_id'   => $creditorBranchId,
            ],
            [
                'ledger_id'   => $recvId,
                'debit'       => 0,
                'credit'      => $amount,
                'description' => "Settlement demand #{$demandId} — reduce receivable",
                'entity_type' => 'branch',
                'entity_id'   => $debtorBranchId,
            ],
        ];

        $header = [
            'entry_date'     => $entryDate,
            'description'    => "Branch demand settlement #{$demandId} ({$sourceType} #{$sourceId})",
            'reference_type' => 'branch_demand_settlement',
            'reference_id'   => $sourceId,
            'branch_id'      => $debtorBranchId,
        ];

        return $this->finalizeJournalResult($this->journalModel->createEntry($header, $lines));
    }
}
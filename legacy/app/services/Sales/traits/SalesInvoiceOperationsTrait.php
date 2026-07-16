<?php
// app/services/Sales/traits/SalesInvoiceOperationsTrait.php — Phase 6 (extracted from SalesModel)

trait SalesInvoiceOperationsTrait
{
    public function finalizeSales($data) { 

    
        $customer_id = $data['customer_id'] ?? 0;
        if (!$customer_id) return ['status' => 'error', 'message' => 'Customer required'];

        $cartKey = 'sales_draft_carts';
        $items = $_SESSION[$cartKey][$customer_id] ?? [];
        if (empty($items)) return ['status' => 'error', 'message' => 'Cart is empty'];

        $subtotal   = array_sum(array_column($items, 'total'));
        $transport  = floatval($data['transport_cost'] ?? 0);
        $discount   = floatval($data['discount'] ?? 0);
        $total_amount = $subtotal + $transport - $discount;

        // =====================================================
        // STRONG CREDIT LIMIT ENFORCEMENT (Phase 3)
        // Only checks posted invoices in customer_ledger
        // =====================================================
        require_once __DIR__ . '/../../../models/CustomerModel.php';
        $customerModel = new \CustomerModel();

        $creditCheck = $customerModel->wouldExceedCreditLimit($customer_id, $total_amount);
        $isOverride  = !empty($data['credit_limit_override']);
        $overrideReason = trim($data['override_reason'] ?? '');

        if ($creditCheck['exceeds'] && !$isOverride) {
            return [
                'status'           => 'credit_limit_exceeded',
                'message'          => 'This sale would exceed the customer\'s credit limit.',
                'credit_check'     => $creditCheck,
                'requires_override' => true
            ];
        }

        if ($creditCheck['exceeds'] && $isOverride) {
            if (strlen($overrideReason) < 10) {
                return [
                    'status'  => 'error',
                    'message' => 'Override reason is required and must be at least 10 characters when exceeding credit limit.'
                ];
            }
            // We will let it proceed, but flag for audit logging in controller
        }

        $branch_id = $data['branch_id'];
        $stockErrors = $this->validateCartStockAvailability($items, $branch_id);
        if (!empty($stockErrors)) {
            return [
                'status'  => 'error',
                'message' => 'Insufficient stock for one or more products.',
                'stock_errors' => $stockErrors,
            ];
        }

        $rateErrors = $this->validateCartRatesInRange($items);
        if ($rateErrors !== []) {
            return [
                'status'       => 'error',
                'code'         => 'price_out_of_range',
                'message'      => implode(' ', $rateErrors),
                'rate_errors'  => $rateErrors,
            ];
        }

        $qtyByProduct = [];
        foreach ($items as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            if ($pid > 0) {
                $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + (float)($item['qty'] ?? 0);
            }
        }

        $this->db->beginTransaction();
        try {
            $stock = $this->stock();
            $stock->lockBranchProductsForUpdate((int)$branch_id, array_keys($qtyByProduct));
            $stockErrors = $stock->assertBranchProductsAvailable((int)$branch_id, $qtyByProduct);
            if ($stockErrors !== []) {
                throw new Exception('Insufficient stock: ' . implode('; ', $stockErrors));
            }

            $invoice_code = $this->generateSalesInvoiceCode($branch_id);

            // 1. Create Sales Invoice
            $this->db->query("INSERT INTO sales_invoices 
                (invoice_code, invoice_date, customer_id, salesman_id, branch_id, 
                 subtotal, discount, transport_cost, narration, sales_person, 
                 total_amount, status, created_by)
                VALUES (:code, :date, :cid, :salesman, :branch, :sub, :disc, :trans, 
                        :nar, :sp, :total, 'draft', :created_by)");

            $salesman_id = $data['sales_person'] ?? $data['sales_by'] ?? 0;

            $this->db->bind(':code', $invoice_code);
            $this->db->bind(':date', $data['invoice_date'] ?? date('Y-m-d'));
            $this->db->bind(':cid', $customer_id);
            $this->db->bind(':salesman', $salesman_id);
            $this->db->bind(':branch', $branch_id);
            $this->db->bind(':sub', $subtotal);
            $this->db->bind(':disc', $discount);
            $this->db->bind(':trans', $transport);
            $this->db->bind(':nar', $data['narration'] ?? '');
            $this->db->bind(':sp', $data['sales_person'] ?? null);
            $this->db->bind(':total', $total_amount);
            $this->db->bind(':created_by', $_SESSION['user_id'] ?? 1);

            $this->db->execute();
            $invoice_id = $this->db->lastInsertId();

  // === 2. Create Invoice Items (warehouse assigned at godown) ===
foreach ($items as $item) {
    $this->db->query("
        INSERT INTO sales_invoice_items 
        (sales_invoice_id, product_id, warehouse_id, qty, rate)
        VALUES (:invoice_id, :product_id, NULL, :qty, :rate)
    ");
    $this->db->bind(':invoice_id', $invoice_id);
    $this->db->bind(':product_id', $item['product_id']);
    $this->db->bind(':qty', $item['qty']);
    $this->db->bind(':rate', $item['rate']);
    $this->db->execute();
}

// === 3. Branch-level soft reservation (warehouse picked at godown) ===
foreach ($items as $item) {
    $this->db->query("
        INSERT INTO sales_invoice_dispatches 
        (sales_invoice_id, product_id, ordered_qty, dispatched_qty, 
         warehouse_id, created_by)
        VALUES (:inv_id, :pid, :oqty, 0, NULL, :uid)
    ");
    $this->db->bind(':inv_id', $invoice_id);
    $this->db->bind(':pid', $item['product_id']);
    $this->db->bind(':oqty', $item['qty']);
    $this->db->bind(':uid', $_SESSION['user_id'] ?? 1);
    $this->db->execute();
}

            // 3. Create Ledger Entry (Debit)
            $prevBalance = $this->getCustomerRunningBalance($customer_id);
            $newBalance  = $prevBalance + $total_amount;

            $this->insertCustomerLedgerEntry([
                'customer_id'      => $customer_id,
                'reference_type'   => 'invoice',
                'reference_id'     => $invoice_id,
                'debit'            => $total_amount,
                'credit'           => 0,
                'running_balance'  => $newBalance,
                'branch_id'        => $branch_id,
                'transaction_date' => $data['invoice_date'] ?? date('Y-m-d'),
            ]);

            // === Phase 5 GL: Dr AR / Cr Sales Revenue ===
            require_once __DIR__ . '/../../Accounting/JournalPostingService.php';
            $journalService = new JournalPostingService($this->db ?? null);
            $journalResult = $journalService->postSalesInvoice($invoice_id, [
                'invoice_code'    => $invoice_code,
                'invoice_date'    => $data['invoice_date'] ?? date('Y-m-d'),
                'customer_id'     => $customer_id,
                'branch_id'       => $branch_id,
                'subtotal'        => $subtotal,
                'discount'        => $discount,
                'transport_cost'  => $transport,
                'total_amount'    => $total_amount,
            ]);
            if (($journalResult['status'] ?? '') === 'error') {
                throw new Exception('Journal posting failed: ' . ($journalResult['message'] ?? 'unknown'));
            }
            if (!empty($journalResult['journal_entry_id'])) {
                $this->setInvoiceJournalEntryId($invoice_id, (int)$journalResult['journal_entry_id']);
            }

            $this->db->commit();

            unset($_SESSION[$cartKey][$customer_id]);

            return [
                'status' => 'success',
                'message' => 'Invoice created successfully!',
                'invoice_id' => $invoice_id,
                'invoice_code' => $invoice_code,
                'journal_entry_id' => $journalResult['journal_entry_id'] ?? null,
                'credit_limit_override_used' => $isOverride && $creditCheck['exceeds'],
                'override_reason' => $isOverride ? $overrideReason : null
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            return ['status' => 'error', 'message' => $e->getMessage()];
        }


     }         


    public function invoiceHasPayments(int $invoice_id): bool
    {
        $this->db->query("
            SELECT COUNT(*) AS c
            FROM invoice_payment_allocations ipa
            INNER JOIN customer_payments cp ON cp.id = ipa.payment_id
            WHERE ipa.invoice_id = :id AND COALESCE(cp.is_reversed, 0) = 0
        ");
        $this->db->bind(':id', $invoice_id);
        return (int)($this->db->single()['c'] ?? 0) > 0;
    }

    /**
     * Human-readable reason when the sales edit screen must be read-only.
     */
    public function getSalesEditBlockReason(array $invoice): string
    {
        if (!empty($invoice['godown_issued_at'])) {
            return 'Godown has already been prepared for this invoice. Refresh warehouse screens or complete the flow before editing.';
        }
        if ($this->invoiceHasPayments((int)($invoice['id'] ?? 0))) {
            return 'Payments have been received against this invoice. It can no longer be edited from sales.';
        }
        return '';
    }

    /**
     * Sales may only change invoice lines/amounts while warehouse has not started (draft, no godown timestamp).
     */
    protected function assertInvoiceEditableBySales(array $invoice): void
    {
        if (($invoice['status'] ?? '') !== 'draft') {
            throw new Exception(
                'Cannot edit: invoice status is "' . ($invoice['status'] ?? '') . '". '
                . 'Warehouse may already be processing this invoice. Reverse challan first if needed.'
            );
        }
        if (!empty($invoice['godown_issued_at'])) {
            throw new Exception(
                'Cannot edit: godown has already been prepared for this invoice. Refresh warehouse screens.'
            );
        }
        if (!empty($invoice['is_reversed'])) {
            throw new Exception('Cannot edit: invoice has been deleted or reversed.');
        }
    }

    public function updateExistingInvoice($invoice_id, $data) { 

            // 1. Get old invoice data (before starting transaction)
            $this->db->query("
                SELECT id, invoice_code, total_amount, subtotal, discount, transport_cost,
                       customer_id, branch_id, status, godown_issued_at, is_reversed, journal_entry_id
                FROM sales_invoices WHERE id = :id AND is_reversed = 0
            ");
            $this->db->bind(':id', $invoice_id);
            $oldInvoice = $this->db->single();

            if (!$oldInvoice) {
                return ['status' => 'error', 'message' => 'Invoice not found'];
            }

            try {
                $this->assertInvoiceAccessible((int)$oldInvoice['branch_id']);
                $this->assertInvoiceEditableBySales($oldInvoice);
            } catch (Exception $e) {
                return ['status' => 'error', 'message' => $e->getMessage()];
            }

            if ($this->invoiceHasPayments($invoice_id)) {
                return ['status' => 'error', 'message' => 'Cannot edit: payments have been received against this invoice.'];
            }

            $oldTotal = (float)$oldInvoice['total_amount'];
            $customer_id = $oldInvoice['customer_id'];
            $invoiceBranchId = (int)$oldInvoice['branch_id'];
            $oldJournalId = !empty($oldInvoice['journal_entry_id']) ? (int)$oldInvoice['journal_entry_id'] : null;

            // 2. Prepare new data from cart
            $cartKey = 'sales_draft_carts';
            $items = $_SESSION[$cartKey][$data['customer_id']] ?? [];

            if (empty($items)) {
                return ['status' => 'error', 'message' => 'Cart is empty'];
            }

            $subtotal   = array_sum(array_column($items, 'total')) ?? 0;
            $transport  = (float)($data['transport_cost'] ?? 0);
            $discount   = (float)($data['discount'] ?? 0);
            $newTotal   = $subtotal + $transport - $discount;

            require_once __DIR__ . '/../../../models/CustomerModel.php';
            $customerModel = new \CustomerModel();

            $netIncrease = max(0, $newTotal - $oldTotal);
            $creditCheck = $customerModel->wouldExceedCreditLimit($customer_id, $netIncrease);

            $isOverride     = !empty($data['credit_limit_override']);
            $overrideReason = trim($data['override_reason'] ?? '');

            if ($creditCheck['exceeds'] && !$isOverride) {
                return [
                    'status'            => 'credit_limit_exceeded',
                    'message'           => 'Updating this invoice would exceed the customer\'s credit limit.',
                    'credit_check'      => $creditCheck,
                    'requires_override' => true
                ];
            }

            if ($creditCheck['exceeds'] && $isOverride && strlen($overrideReason) < 10) {
                return [
                    'status'  => 'error',
                    'message' => 'Override reason (min 10 characters) is required when exceeding credit limit.'
                ];
            }

            $branch_id = $this->resolveBranchIdForWrite(
                (int)($data['branch_id'] ?? 0),
                $invoiceBranchId
            );

            $stockErrors = $this->validateCartStockAvailability($items, $branch_id, $invoice_id);
            if (!empty($stockErrors)) {
                return [
                    'status'  => 'error',
                    'message' => 'Insufficient stock for one or more products.',
                    'stock_errors' => $stockErrors,
                ];
            }

            $rateErrors = $this->validateCartRatesInRange($items);
            if ($rateErrors !== []) {
                return [
                    'status'       => 'error',
                    'code'         => 'price_out_of_range',
                    'message'      => implode(' ', $rateErrors),
                    'rate_errors'  => $rateErrors,
                ];
            }

        $qtyByProduct = [];
        foreach ($items as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            if ($pid > 0) {
                $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + (float)($item['qty'] ?? 0);
            }
        }

        $this->db->beginTransaction();
        try {
            $this->db->query("SELECT status, godown_issued_at FROM sales_invoices WHERE id = :id FOR UPDATE");
            $this->db->bind(':id', $invoice_id);
            $locked = $this->db->single();
            $this->assertInvoiceEditableBySales($locked ?: []);

            $stock = $this->stock();
            $stock->lockBranchProductsForUpdate($branch_id, array_keys($qtyByProduct));
            $stockErrors = $stock->assertBranchProductsAvailable($branch_id, $qtyByProduct, $invoice_id);
            if ($stockErrors !== []) {
                throw new Exception('Insufficient stock: ' . implode('; ', $stockErrors));
            }

            // 3. Reverse old ledger entry (credit the old amount)
            $this->db->query("SELECT running_balance FROM customer_ledger 
                              WHERE customer_id = :cid 
                              ORDER BY id DESC LIMIT 1");
            $this->db->bind(':cid', $customer_id);
            $currentBalance = $this->db->single()['running_balance'] ?? 0;

            $reversedBalance = $currentBalance - $oldTotal;

            $this->insertCustomerLedgerEntry([
                'customer_id'      => $customer_id,
                'reference_type'   => 'reversal',
                'reference_id'     => $invoice_id,
                'debit'            => 0,
                'credit'           => $oldTotal,
                'running_balance'  => $reversedBalance,
                'branch_id'        => $branch_id,
                'remarks'          => 'Reversed due to invoice edit',
                'is_reversed'      => 1,
            ]);

            // 4. Create new ledger entry (debit the new total)
            $newBalance = $reversedBalance + $newTotal;

            $this->insertCustomerLedgerEntry([
                'customer_id'      => $customer_id,
                'reference_type'   => 'invoice',
                'reference_id'     => $invoice_id,
                'debit'            => $newTotal,
                'credit'           => 0,
                'running_balance'  => $newBalance,
                'branch_id'        => $branch_id,
                'remarks'          => 'Updated invoice',
            ]);

            // 5. Update sales_invoices master
            $this->db->query("UPDATE sales_invoices SET 
                subtotal = :subtotal,
                discount = :discount,
                transport_cost = :transport,
                total_amount = :total_amount,
                narration = :narration,
                sales_person = :sales_person,
                salesman_id = :salesman_id,
                invoice_date = :invoice_date,
                branch_id = :branch_id
                WHERE id = :id");

            $this->db->bind(':subtotal', $subtotal);
            $this->db->bind(':discount', $discount);
            $this->db->bind(':transport', $transport);
            $this->db->bind(':total_amount', $newTotal);
            $this->db->bind(':narration', $data['narration'] ?? '');
            $this->db->bind(':sales_person', $data['sales_person'] ?? null);
            $this->db->bind(':salesman_id', $data['sales_by'] ?? null);
            $this->db->bind(':invoice_date', $data['invoice_date'] ?? date('Y-m-d'));
            $this->db->bind(':branch_id', $branch_id);
            $this->db->bind(':id', $invoice_id);
            $this->db->execute();

            // 6. Delete old items and insert new ones
            $this->db->query("DELETE FROM sales_invoice_items WHERE sales_invoice_id = :id");
            $this->db->bind(':id', $invoice_id);
            $this->db->execute();

            foreach ($items as $item) {
                $this->db->query("INSERT INTO sales_invoice_items 
                    (sales_invoice_id, product_id, warehouse_id, qty, rate) 
                    VALUES (:invoice_id, :product_id, NULL, :qty, :rate)");

                $this->db->bind(':invoice_id', $invoice_id);
                $this->db->bind(':product_id', $item['product_id']);
                $this->db->bind(':qty', $item['qty']);
                $this->db->bind(':rate', $item['rate']);
                $this->db->execute();
            }


            // Clear warehouse reservations/dispatchers (warehouse must re-open godown after edit)
            $this->db->query("DELETE FROM sales_invoice_dispatchers WHERE sales_invoice_id = :id");
            $this->db->bind(':id', $invoice_id);
            $this->db->execute();

            $this->db->query("DELETE FROM sales_invoice_dispatches WHERE sales_invoice_id = :id");
            $this->db->bind(':id', $invoice_id);
            $this->db->execute();

// Re-insert branch-level soft reservations (warehouse assigned at godown)
foreach ($items as $item) {
    $this->db->query("
        INSERT INTO sales_invoice_dispatches 
        (sales_invoice_id, product_id, ordered_qty, dispatched_qty, 
         warehouse_id, created_by)
        VALUES (:inv_id, :pid, :oqty, 0, NULL, :uid)
    ");
    $this->db->bind(':inv_id', $invoice_id);
    $this->db->bind(':pid', $item['product_id']);
    $this->db->bind(':oqty', $item['qty']);
    $this->db->bind(':uid', $_SESSION['user_id'] ?? 1);
    $this->db->execute();
}

            require_once __DIR__ . '/../../Accounting/JournalPostingService.php';
            $journalService = new JournalPostingService($this->db ?? null);
            if ($oldJournalId) {
                $rev = $journalService->reverseLinkedJournal(
                    $oldJournalId,
                    'Invoice edited: ' . ($oldInvoice['invoice_code'] ?? $invoice_id)
                );
                if (($rev['status'] ?? '') === 'error') {
                    throw new Exception('Failed to reverse prior invoice journal: ' . ($rev['message'] ?? ''));
                }
            }
            $journalResult = $journalService->postSalesInvoice($invoice_id, [
                'invoice_code'   => $oldInvoice['invoice_code'],
                'invoice_date'   => $data['invoice_date'] ?? date('Y-m-d'),
                'customer_id'    => $customer_id,
                'branch_id'      => $branch_id,
                'subtotal'       => $subtotal,
                'discount'       => $discount,
                'transport_cost' => $transport,
                'total_amount'   => $newTotal,
            ]);
            if (($journalResult['status'] ?? '') === 'error') {
                throw new Exception('Journal posting failed on edit: ' . ($journalResult['message'] ?? ''));
            }
            if (!empty($journalResult['journal_entry_id'])) {
                $this->setInvoiceJournalEntryId($invoice_id, (int)$journalResult['journal_entry_id']);
            }

            $this->db->commit();

            // Clear session cart after successful update
            unset($_SESSION['sales_draft_carts'][$data['customer_id']]);

            return [
                'status' => 'success',
                'message' => 'Invoice updated successfully!',
                'credit_limit_override_used' => $isOverride && $creditCheck['exceeds'],
                'override_reason' => $isOverride ? $overrideReason : null,
                'journal_entry_id' => $journalResult['journal_entry_id'] ?? null,
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

     }


    public function getInvoiceForEdit($invoice_id) { 

    
        $this->db->query("
            SELECT si.*, c.shop_name, c.customer_name, c.mobile , b.branch_name, e.name as salesman_name, s.name as sales_person_name
            FROM sales_invoices si
            JOIN customers c ON si.customer_id = c.id
            JOIN branches b ON si.branch_id = b.id
            JOIN employees e ON si.salesman_id = e.id
            LEFT JOIN employees s ON si.sales_person = s.id
            WHERE si.id = :id AND si.status = 'draft' AND si.is_reversed = 0
        ");
        $this->db->bind(':id', $invoice_id);
        $invoice = $this->db->single();

        if (!$invoice) return false;

        try {
            $this->assertInvoiceAccessible((int)$invoice['branch_id']);
        } catch (Exception $e) {
            return false;
        }

        $this->db->query("
            SELECT sii.*, p.product_name 
            FROM sales_invoice_items sii
            JOIN products p ON sii.product_id = p.id
            WHERE sii.sales_invoice_id = :id
        ");
        $this->db->bind(':id', $invoice_id);
        $invoice['items'] = $this->db->resultSet();

        return $invoice;

     }


    public function getInvoiceById($id) { 
    return $this->Invoice_Details($id);
     }
    
     public function getInvoiceItems($invoice_id) {     
    return $this->Invoice_Item_Details($invoice_id);
     }

    public function getTodayInvoices($branch_id) { 

    
        $this->db->query("
            SELECT 
                si.id,
                si.invoice_code,
                si.invoice_date,
                si.total_amount,
                si.status,
                c.shop_name,
                c.mobile,
                c.address,
                e.name as salesman_name,
                si.created_at
            FROM sales_invoices si
            JOIN customers c ON si.customer_id = c.id
            JOIN employees e ON si.salesman_id = e.id
           WHERE si.branch_id = :branch_id 
  AND si.is_reversed = 0 
  AND si.call_a_day = 0
            ORDER BY si.created_at DESC
        ");

        $this->db->bind(':branch_id', $branch_id);
        return $this->db->resultSet();

     }


    public function callItADay($invoice_ids, $branch_id) { 

    
        if (empty($invoice_ids) || !is_array($invoice_ids)) {
            return ['status' => 'error', 'message' => 'No invoice selected'];
        }

        $this->db->beginTransaction();
        try {
            $updated = 0;
            foreach ($invoice_ids as $id) {
                $id = (int)$id;
                if ($id <= 0) {
                    continue;
                }

                $this->db->query("
                    UPDATE sales_invoices 
                    SET call_a_day = 1 
                    WHERE id = :id 
                      AND branch_id = :branch_id 
                      AND is_reversed = 0
                      AND COALESCE(call_a_day, 0) = 0
                ");

                $this->db->bind(':id', $id);
                $this->db->bind(':branch_id', (int)$branch_id);
                $this->db->execute();
                $updated += $this->db->rowCount();
            }

            $this->db->commit();

            if ($updated === 0) {
                return [
                    'status'  => 'error',
                    'message' => 'No invoices were removed from the list (already cleared or not found).',
                ];
            }

            return [
                'status'  => 'success',
                'message' => $updated . ' invoice(s) removed from your daily collection list.',
                'updated' => $updated,
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

     }


    public function deleteInvoice($invoice_id, ?string $deleteReason = null) { 

    
        $this->db->beginTransaction();
        try {
            // 1. Get invoice details
            $this->db->query("SELECT * FROM sales_invoices WHERE id = :id");
            $this->db->bind(':id', $invoice_id);
            $invoice = $this->db->single();

            if (!$invoice) {
                throw new Exception("Invoice not found");
            }

            if ((int)($invoice['is_reversed'] ?? 0) === 1) {
                throw new Exception('Invoice is already deleted.');
            }

            if ($invoice['status'] !== 'draft') {
                throw new Exception(
                    'Cannot delete invoice: status is "' . $invoice['status'] . '". '
                    . 'Only draft invoices (before godown/challan) can be deleted.'
                );
            }

            if (!empty($invoice['godown_issued_at'])) {
                throw new Exception(
                    'Cannot delete: godown has been prepared. Reverse or complete warehouse flow first.'
                );
            }

            $this->assertInvoiceAccessible((int)$invoice['branch_id']);

            if (!empty($invoice['journal_entry_id'])) {
                require_once __DIR__ . '/../../Accounting/JournalPostingService.php';
                $journalService = new JournalPostingService($this->db ?? null);
                $rev = $journalService->reverseLinkedJournal(
                    (int)$invoice['journal_entry_id'],
                    'Invoice deleted: ' . ($invoice['invoice_code'] ?? $invoice_id)
                );
                if (($rev['status'] ?? '') === 'error') {
                    throw new Exception('Failed to reverse invoice journal: ' . ($rev['message'] ?? ''));
                }
            }

            $this->db->query("
                SELECT id FROM sales_challans
                WHERE sales_invoice_id = :id AND COALESCE(is_reversed, 0) = 0
                LIMIT 1
            ");
            $this->db->bind(':id', $invoice_id);
            if ($this->db->single()) {
                throw new Exception('Cannot delete: a delivery challan exists. Reverse the challan first.');
            }

            $this->db->query("
                SELECT COUNT(*) AS c FROM sales_invoice_dispatches
                WHERE sales_invoice_id = :id AND COALESCE(dispatched_qty, 0) > 0
            ");
            $this->db->bind(':id', $invoice_id);
            if ((int)($this->db->single()['c'] ?? 0) > 0) {
                throw new Exception('Cannot delete: stock has already been dispatched for this invoice.');
            }

            // 2. Check if any payment has been made against this invoice
            if ($this->invoiceHasPayments($invoice_id)) {
                throw new Exception("Cannot delete invoice. Payments have already been received against it.");
            }

            // 3. Find and reverse the customer ledger entry
            $this->db->query("SELECT id FROM customer_ledger 
                              WHERE reference_type = 'invoice' 
                                AND reference_id = :invoice_id");
            $this->db->bind(':invoice_id', $invoice_id);
            $ledger = $this->db->single();

            if ($ledger) {
                // Reverse the debit entry by creating a credit reversal
                $this->db->query("SELECT running_balance FROM customer_ledger 
                                  WHERE customer_id = :cid 
                                  ORDER BY id DESC LIMIT 1");
                $this->db->bind(':cid', $invoice['customer_id']);
                $currentBalance = $this->db->single()['running_balance'] ?? 0;

                $newBalance = $currentBalance - $invoice['total_amount'];

                $this->insertCustomerLedgerEntry([
                    'customer_id'      => (int)$invoice['customer_id'],
                    'reference_type'   => 'reversal',
                    'reference_id'     => $invoice_id,
                    'debit'            => 0,
                    'credit'           => (float)$invoice['total_amount'],
                    'running_balance'  => $newBalance,
                    'branch_id'        => (int)$invoice['branch_id'],
                    'remarks'          => 'Reversed due to invoice deletion',
                    'is_reversed'      => 1,
                ]);
            }

            // 4. Delete invoice items      
            $this->db->query("DELETE FROM sales_invoice_items WHERE sales_invoice_id = :id");
            $this->db->bind(':id', $invoice_id);
            $this->db->execute();

            $reverseReason = ($deleteReason !== null && trim($deleteReason) !== '')
                ? trim($deleteReason)
                : 'Deleted by user';

            // 5. Delete the invoice itself (soft delete using is_reversed)
            $this->db->query("UPDATE sales_invoices 
                              SET is_reversed = 1, 
                                  reversed_at = NOW(), 
                                  reversed_by = :reversed_by,
                                  reverse_reason = :reverse_reason
                              WHERE id = :id");
            $this->db->bind(':id', $invoice_id);
            $this->db->bind(':reversed_by', $_SESSION['user_id'] ?? 1);
            $this->db->bind(':reverse_reason', $reverseReason);
            $this->db->execute();

            // 6. Remove soft dispatch reservations (invoice is reversed)
            $this->db->query("DELETE FROM sales_invoice_dispatches WHERE sales_invoice_id = :id");
            $this->db->bind(':id', $invoice_id);
            $this->db->execute();

            $this->db->commit();

            return [
                'status' => 'success',
                'message' => 'Invoice #' . $invoice['invoice_code'] . ' has been deleted successfully.'
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }

     }

    private function invoicePaidAmountSql(): string
    {
        return "COALESCE((
            SELECT SUM(ipa.allocated_amount)
            FROM invoice_payment_allocations ipa
            INNER JOIN customer_payments cp ON cp.id = ipa.payment_id
            WHERE ipa.invoice_id = si.id
              AND COALESCE(cp.is_reversed, 0) = 0
        ), 0)";
    }

    private const TODAY_INVOICE_FROM = "
        FROM sales_invoices si
        JOIN customers c ON si.customer_id = c.id
        JOIN branches b ON si.branch_id = b.id
        JOIN employees e ON si.salesman_id = e.id
        LEFT JOIN users u ON u.id = si.created_by
    ";

    private function todayInvoiceSelectSql(): string
    {
        $paid = $this->invoicePaidAmountSql();

        return "
        SELECT
            si.id,
            si.invoice_code,
            si.invoice_date,
            si.total_amount,
            si.status,
            si.call_a_day,
            si.created_at,
            c.shop_name,
            c.customer_name,
            c.mobile,
            c.address,
            b.branch_name,
            e.name AS salesman_name,
            {$paid} AS paid_amount,
            GREATEST(0, si.total_amount - {$paid}) AS balance_due,
            DATEDIFF(CURDATE(), si.invoice_date) AS age_days
        ";
    }

    private function buildTodayInvoiceWhere(array $filters, array &$bindings, ?string $dataTablesSearch = null): array
    {
        $where = [];
        $where[] = "si.branch_id = :scope_branch_id";
        $bindings[':scope_branch_id'] = self::sessionBranchId();

        $where[] = "COALESCE(si.call_a_day, 0) = 0";

        if (!$this->canSeeAllBranchInvoices()) {
            $where[] = "si.created_by = :created_by_user";
            $bindings[':created_by_user'] = (int)($_SESSION['user_id'] ?? 0);
        }

        $hasDateFilter = false;
        if (!empty($filters['date_from'])) {
            $where[] = "si.invoice_date >= :date_from";
            $bindings[':date_from'] = $filters['date_from'];
            $hasDateFilter = true;
        }
        if (!empty($filters['date_to'])) {
            $where[] = "si.invoice_date <= :date_to";
            $bindings[':date_to'] = $filters['date_to'];
            $hasDateFilter = true;
        }
        if (!$hasDateFilter && empty($filters['skip_default_today'])) {
            $where[] = "si.invoice_date = CURDATE()";
        }

        $searchTerm = trim($dataTablesSearch ?? $filters['search'] ?? '');
        if ($searchTerm !== '') {
            $term = '%' . $searchTerm . '%';
            $where[] = "(
                si.invoice_code LIKE :t1
                OR c.shop_name LIKE :t2
                OR c.customer_name LIKE :t3
                OR c.mobile LIKE :t4
                OR b.branch_name LIKE :t5
                OR e.name LIKE :t6
                OR u.username LIKE :t7
                OR EXISTS (
                    SELECT 1 FROM sales_invoice_items sii
                    JOIN products p ON sii.product_id = p.id
                    WHERE sii.sales_invoice_id = si.id
                    AND (p.product_name LIKE :t8 OR p.product_code LIKE :t9)
                )
            )";
            for ($i = 1; $i <= 9; $i++) {
                $bindings[":t$i"] = $term;
            }
        }

        $this->applyTodayChallanStatusFilter((string)($filters['challan_status'] ?? 'all'), $where);

        $where[] = "si.is_reversed = 0";
        return $where;
    }

    private function applyTodayChallanStatusFilter(string $status, array &$where): void
    {
        $status = trim($status);
        if ($status === '' || $status === 'all') {
            return;
        }

        switch ($status) {
            case 'open_pipeline':
                $where[] = "si.status IN ('draft', 'godown_issued')";
                return;
            case 'pending':
                $where[] = "si.status = 'draft'";
                return;
            case 'godown_copy':
                $where[] = "si.status = 'godown_issued'";
                return;
            case 'challan_generated':
                $where[] = "si.status = 'challan_completed'";
                return;
            case 'awaiting_payment':
                $paid = $this->invoicePaidAmountSql();
                $where[] = "(si.total_amount - {$paid}) > 0.009";
                return;
        }
    }

    /**
     * Status counts for sales/today (date + search scope; ignores workflow chip filter).
     */
    public function getTodayFilterSummary(array $filters): array
    {
        $summaryFilters = $filters;
        unset($summaryFilters['challan_status'], $summaryFilters['smart_sort']);
        $summaryFilters['challan_status'] = 'all';
        $summaryFilters['skip_default_today'] = true;

        $search = trim($filters['search'] ?? '');
        $bindings = [];
        $where = $this->buildTodayInvoiceWhere(
            $summaryFilters,
            $bindings,
            $search !== '' ? $search : null
        );
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $this->db->query("
            SELECT si.status, COUNT(*) AS cnt
            " . self::TODAY_INVOICE_FROM . $whereSql . "
            GROUP BY si.status
        ");
        foreach ($bindings as $param => $value) {
            $this->db->bind($param, $value);
        }
        $rows = $this->db->resultSet();

        $draft = 0;
        $godown = 0;
        $done = 0;
        $awaitingPayment = 0;
        $total = 0;
        foreach ($rows as $row) {
            $cnt = (int)($row['cnt'] ?? 0);
            $total += $cnt;
            switch ($row['status'] ?? '') {
                case 'draft':
                    $draft = $cnt;
                    break;
                case 'godown_issued':
                    $godown = $cnt;
                    break;
                case 'challan_completed':
                    $done = $cnt;
                    break;
            }
        }

        $paidSql = $this->invoicePaidAmountSql();
        $awaitBindings = $bindings;
        $awaitWhere = $this->buildTodayInvoiceWhere(
            $summaryFilters,
            $awaitBindings,
            $search !== '' ? $search : null
        );
        $awaitWhere[] = "(si.total_amount - {$paidSql}) > 0.009";
        $awaitWhereSql = ' WHERE ' . implode(' AND ', $awaitWhere);
        $this->db->query('SELECT COUNT(*) AS c ' . self::TODAY_INVOICE_FROM . $awaitWhereSql);
        foreach ($awaitBindings as $param => $value) {
            $this->db->bind($param, $value);
        }
        $awaitingPayment = (int)($this->db->single()['c'] ?? 0);

        return [
            'total'             => $total,
            'pending'           => $draft,
            'godown_copy'       => $godown,
            'challan_generated' => $done,
            'open_pipeline'     => $draft + $godown,
            'awaiting_payment'  => $awaitingPayment,
        ];
    }

    public function countTodayInvoicesScoped(): int
    {
        $bindings = [];
        $where = $this->buildTodayInvoiceWhere(['skip_default_today' => true], $bindings, null);
        $sql = "SELECT COUNT(*) AS cnt " . self::TODAY_INVOICE_FROM;
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $this->db->query($sql);
        foreach ($bindings as $param => $value) {
            $this->db->bind($param, $value);
        }
        return (int)($this->db->single()['cnt'] ?? 0);
    }

    public function getFilteredTodayInvoices($filters = [])
    {
        $bindings = [];
        $where = $this->buildTodayInvoiceWhere($filters, $bindings, null);
        $sql = $this->todayInvoiceSelectSql() . self::TODAY_INVOICE_FROM;
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY si.created_at DESC';
        $this->db->query($sql);
        foreach ($bindings as $param => $value) {
            $this->db->bind($param, $value);
        }
        return $this->db->resultSet();
    }

    /**
     * Server-side DataTables payload for sales/today (Phase 6).
     */
    public function getTodayInvoicesDatatable(
        array $filters,
        int $start,
        int $length,
        int $orderColumnIndex,
        string $orderDir,
        string $searchValue = ''
    ): array {
        if (!empty($filters['smart_sort'])) {
            $paid = $this->invoicePaidAmountSql();
            $orderBy = "CASE WHEN (si.total_amount - {$paid}) > 0.009 THEN 0 ELSE 1 END,
                si.invoice_date ASC,
                si.created_at DESC";
            $orderDir = '';
        } else {
            $orderMap = [
                1 => 'si.invoice_code',
                2 => 'si.invoice_date',
                3 => 'c.shop_name',
                4 => 'b.branch_name',
                5 => 'e.name',
                6 => 'si.total_amount',
                7 => 'paid_amount',
                8 => 'balance_due',
                9 => 'si.status',
            ];
            $orderBy = $orderMap[$orderColumnIndex] ?? 'si.invoice_date';
            $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';
        }

        $countBindings = [];
        $countWhere = $this->buildTodayInvoiceWhere(
            $filters,
            $countBindings,
            $searchValue !== '' ? $searchValue : null
        );
        $countWhereSql = empty($countWhere) ? '' : ' WHERE ' . implode(' AND ', $countWhere);

        $this->db->query("SELECT COUNT(*) AS cnt " . self::TODAY_INVOICE_FROM . $countWhereSql);
        foreach ($countBindings as $param => $value) {
            $this->db->bind($param, $value);
        }
        $recordsFiltered = (int)($this->db->single()['cnt'] ?? 0);

        $orderClause = $orderDir !== ''
            ? " ORDER BY {$orderBy} {$orderDir}"
            : " ORDER BY {$orderBy}";

        $sql = $this->todayInvoiceSelectSql() . self::TODAY_INVOICE_FROM . $countWhereSql
            . $orderClause . " LIMIT :start, :length";
        $this->db->query($sql);
        foreach ($countBindings as $param => $value) {
            $this->db->bind($param, $value);
        }
        $this->db->bind(':start', $start);
        $this->db->bind(':length', $length);
        $rows = $this->db->resultSet();

        return [
            'total'    => $this->countTodayInvoicesScoped(),
            'filtered' => $recordsFiltered,
            'data'     => $rows,
        ];
    }

    protected function getStaleDraftThresholdDays(?int $maxDays = null): int
    {
        $days = $maxDays ?? (defined('SALES_STALE_DRAFT_DAYS') ? (int)SALES_STALE_DRAFT_DAYS : 14);

        return max(1, $days);
    }

    public function countStaleDraftInvoices(?int $maxDays = null, ?int $branchId = null): int
    {
        $days = $this->getStaleDraftThresholdDays($maxDays);
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND si.branch_id = ' . (int)$branchId;
        }

        $this->db->query("
            SELECT COUNT(*) AS c
            FROM sales_invoices si
            WHERE si.status = 'draft'
              AND COALESCE(si.is_reversed, 0) = 0
              AND si.godown_issued_at IS NULL
              AND si.created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
              {$branchSql}
        ");
        $this->db->bind(':days', $days);

        return (int)($this->db->single()['c'] ?? 0);
    }

    /**
     * @return list<array{id:int, invoice_code:string, created_at:string, total_amount:float}>
     */
    public function listStaleDraftInvoices(?int $maxDays = null, ?int $branchId = null, int $limit = 5): array
    {
        $days = $this->getStaleDraftThresholdDays($maxDays);
        $limit = max(1, min(20, $limit));
        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND si.branch_id = ' . (int)$branchId;
        }

        $this->db->query("
            SELECT si.id, si.invoice_code, si.created_at, si.total_amount
            FROM sales_invoices si
            WHERE si.status = 'draft'
              AND COALESCE(si.is_reversed, 0) = 0
              AND si.godown_issued_at IS NULL
              AND si.created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
              {$branchSql}
            ORDER BY si.created_at ASC
            LIMIT {$limit}
        ");
        $this->db->bind(':days', $days);
        $rows = $this->db->resultSet() ?: [];

        return array_map(static function (array $row): array {
            return [
                'id'           => (int)($row['id'] ?? 0),
                'invoice_code' => (string)($row['invoice_code'] ?? ''),
                'created_at'   => (string)($row['created_at'] ?? ''),
                'total_amount' => (float)($row['total_amount'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * Auto-cancel stale drafts at most once per branch every 6 hours (when enabled in config).
     */
    public function runStaleDraftCleanupIfDue(?int $branchId = null, bool $force = false): array
    {
        if (!defined('SALES_STALE_DRAFT_AUTO_CANCEL') || !SALES_STALE_DRAFT_AUTO_CANCEL) {
            return [
                'status'    => 'skipped',
                'reason'    => 'auto_cancel_disabled',
                'cancelled' => 0,
                'days'      => $this->getStaleDraftThresholdDays(),
            ];
        }

        $branchId = ($branchId !== null && $branchId > 0) ? $branchId : null;
        $sessionKey = 'sales_stale_cleanup_at_' . ($branchId ?? 'all');
        $now = time();
        if (
            !$force
            && !empty($_SESSION[$sessionKey])
            && ($now - (int)$_SESSION[$sessionKey]) < 21600
        ) {
            return [
                'status'    => 'skipped',
                'reason'    => 'throttled',
                'cancelled' => 0,
                'days'      => $this->getStaleDraftThresholdDays(),
            ];
        }

        $_SESSION[$sessionKey] = $now;

        return $this->cancelStaleDraftInvoices(null, $branchId);
    }

    public function cancelStaleDraftInvoices(?int $maxDays = null, ?int $branchId = null): array
    {
        $days = $this->getStaleDraftThresholdDays($maxDays);

        $branchSql = '';
        if ($branchId !== null && $branchId > 0) {
            $branchSql = ' AND si.branch_id = ' . (int)$branchId;
        }

        $this->db->query("
            SELECT si.id, si.invoice_code
            FROM sales_invoices si
            WHERE si.status = 'draft'
              AND COALESCE(si.is_reversed, 0) = 0
              AND si.godown_issued_at IS NULL
              AND si.created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
              {$branchSql}
            ORDER BY si.id ASC
            LIMIT 200
        ");
        $this->db->bind(':days', $days);
        $rows = $this->db->resultSet();

        $cancelled = 0;
        $errors = [];
        $cancelReason = sprintf('Stale draft auto-cancel (>%d days)', $days);
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $result = $this->deleteInvoice($id, $cancelReason);
            if (($result['status'] ?? '') === 'success') {
                $cancelled++;
            } else {
                $code = (string)($row['invoice_code'] ?? $id);
                $errors[] = "{$code}: " . ($result['message'] ?? 'failed');
            }
        }

        return [
            'status'    => 'success',
            'cancelled' => $cancelled,
            'errors'    => $errors,
            'days'      => $days,
        ];
    }

    /**
     * Link a posted journal entry to a sales invoice (Phase 5 GL).
     */
    public function setInvoiceJournalEntryId(int $invoiceId, ?int $journalEntryId): bool
    {
        $this->db->query("UPDATE sales_invoices SET journal_entry_id = :jid WHERE id = :id");
        $this->db->bind(':jid', $journalEntryId);
        $this->db->bind(':id', $invoiceId);
        return $this->db->execute();
    }

}

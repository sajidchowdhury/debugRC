<?php
// app/services/Sales/traits/SalesPaymentOperationsTrait.php — Phase 6 (extracted from SalesModel)

trait SalesPaymentOperationsTrait
{
    /**
     * Due breakdown for invoice print copy (মোট বকেয়া block).
     *
     * @return array{previous_due: float, this_invoice_gross: float, this_invoice_payment: float, this_invoice_net: float, cumulative_due: float, current_due: float}
     */
    public function getCustomerDueBreakdown($customer_id, $invoice_id = null): array
    {
        return $this->Customer_Due_Break_Down($customer_id, $invoice_id);
    }

    /**
     * Link a posted journal entry to a customer payment (Phase 5 GL).
     */
    public function setPaymentJournalEntryId(int $paymentId, ?int $journalEntryId): bool
    {
        $this->db->query('UPDATE customer_payments SET journal_entry_id = :jid WHERE id = :id');
        $this->db->bind(':jid', $journalEntryId);
        $this->db->bind(':id', $paymentId);
        return $this->db->execute();
    }

    protected function parsePaymentInput(array $data): array
    {
        $raw = $data['payment_mode'] ?? 'cash';
        if ($raw === 'cash' || $raw === '' || $raw === '0') {
            return ['mode' => 'cash', 'bank_id' => null];
        }
        $bankId = (int)$raw;
        if ($bankId > 0) {
            return ['mode' => 'bank', 'bank_id' => $bankId];
        }
        return ['mode' => 'cash', 'bank_id' => null];
    }

    public function recordCustomerPayment($data) { 
        
        $customer_id    = (int)($data['customer_id'] ?? 0);
        $amount         = (float)($data['receive_amount'] ?? 0);
        $payment        = $this->parsePaymentInput($data);
        $payment_mode   = $payment['mode'];
        $bank_id        = $payment['bank_id'];
        $reference_no   = trim($data['reference_no'] ?? '') ?: null;
        $remarks        = $data['remarks'] ?? '';
        $invoice_id     = (int)($data['invoice_id'] ?? 0);
        $branch_id      = self::sessionBranchId();

        if (!$customer_id || $amount <= 0) {
            return ['status' => 'error', 'message' => 'Invalid customer or amount'];
        }

        $this->db->beginTransaction();
        try {
            if ($invoice_id > 0) {
                $this->db->query("
                    SELECT id, status, is_reversed, total_amount, branch_id, invoice_code
                    FROM sales_invoices WHERE id = :id FOR UPDATE
                ");
                $this->db->bind(':id', $invoice_id);
                $invRow = $this->db->single();
                if (!$invRow || (int)$invRow['is_reversed'] === 1) {
                    throw new Exception('Invoice not found or has been deleted.');
                }
                $this->assertInvoiceAccessible((int)$invRow['branch_id']);

                $this->db->query("
                    SELECT COALESCE(SUM(ipa.allocated_amount), 0) AS paid
                    FROM invoice_payment_allocations ipa
                    INNER JOIN customer_payments cp ON cp.id = ipa.payment_id
                    WHERE ipa.invoice_id = :id AND COALESCE(cp.is_reversed, 0) = 0
                ");
                $this->db->bind(':id', $invoice_id);
                $paidSoFar = (float)($this->db->single()['paid'] ?? 0);
                if ($paidSoFar + $amount > (float)$invRow['total_amount'] + 0.01) {
                    throw new Exception(
                        'Payment exceeds invoice balance. Outstanding: '
                        . number_format(max(0, (float)$invRow['total_amount'] - $paidSoFar), 2)
                    );
                }
            }

            $payment_code = $this->generateCustomerPaymentCode($branch_id);

            // 2. Create detailed payment record
            $this->db->query("INSERT INTO customer_payments 
                (payment_code, payment_date, transaction_type, customer_id, amount, payment_mode, 
                 reference_no, bank_id, remarks, created_by, branch_id)
                VALUES (:code, :date, 'receive', :cid, :amount, :mode, :ref, :bank, :remarks, :created_by, :branch_id)");

            $this->db->bind(':code', $payment_code);
            $this->db->bind(':date', $data['payment_date'] ?? date('Y-m-d'));
            $this->db->bind(':cid', $customer_id);
            $this->db->bind(':amount', $amount);
            $this->db->bind(':mode', $payment_mode);
            $this->db->bind(':ref', $reference_no);
            $this->db->bind(':bank', $bank_id);
            $this->db->bind(':remarks', $remarks);
            $this->db->bind(':created_by', $_SESSION['user_id'] ?? 1);
            $this->db->bind(':branch_id', $branch_id);
            $this->db->execute();

            $payment_id = $this->db->lastInsertId();

            // 3. Create credit in customer_ledger
            $prevBalance = $this->getCustomerRunningBalance($customer_id);
            $newBalance  = $prevBalance - $amount;

            $this->insertCustomerLedgerEntry([
                'customer_id'      => $customer_id,
                'reference_type'   => 'payment',
                'reference_id'     => $payment_id,
                'debit'            => 0,
                'credit'           => $amount,
                'running_balance'  => $newBalance,
                'branch_id'        => $branch_id,
                'transaction_date' => $data['payment_date'] ?? date('Y-m-d'),
                'remarks'          => $remarks,
            ]);

            // 4. If invoice_id is provided → Create Invoice-wise Allocation
            if ($invoice_id > 0) {
                $this->db->query("INSERT INTO invoice_payment_allocations
                    (invoice_id, payment_id, allocated_amount, created_by)
                    VALUES (:invoice_id, :payment_id, :amount, :created_by)");

                $this->db->bind(':invoice_id', $invoice_id);
                $this->db->bind(':payment_id', $payment_id);
                $this->db->bind(':amount', $amount);
                $this->db->bind(':created_by', $_SESSION['user_id'] ?? 1);
                $this->db->execute();
            }

            require_once __DIR__ . '/../../Accounting/JournalPostingService.php';
            $journalService = new JournalPostingService();
            $journalResult = $journalService->postCustomerPayment($payment_id, [
                'payment_code'  => $payment_code,
                'payment_date'  => $data['payment_date'] ?? date('Y-m-d'),
                'customer_id'   => $customer_id,
                'amount'        => $amount,
                'payment_mode'  => $payment_mode,
                'bank_id'       => $bank_id,
                'branch_id'     => $branch_id,
            ]);
            if (($journalResult['status'] ?? '') === 'error') {
                throw new Exception('Journal posting failed: ' . ($journalResult['message'] ?? 'unknown'));
            }
            if (!empty($journalResult['journal_entry_id'])) {
                $this->setPaymentJournalEntryId($payment_id, (int)$journalResult['journal_entry_id']);
            }

            require_once __DIR__ . '/../../Branch/BranchIntercompanyService.php';
            $intercompany = new BranchIntercompanyService($this->db);
            $settleResult = $intercompany->settleFromCustomerPayment($payment_id, [
                'payment_code'  => $payment_code,
                'payment_date'  => $data['payment_date'] ?? date('Y-m-d'),
                'amount'        => $amount,
                'payment_mode'  => $payment_mode,
                'bank_id'       => $bank_id,
                'branch_id'     => $branch_id,
            ]);
            if (($settleResult['status'] ?? '') === 'error') {
                throw new Exception('Branch demand settlement failed: ' . ($settleResult['message'] ?? ''));
            }

            $this->db->commit();

            $receipt = $invoice_id > 0 ? $this->getPaymentReceiptData($invoice_id) : null;

            return [
                'status' => 'success',
                'message' => 'Payment recorded successfully!',
                'payment_id' => $payment_id,
                'payment_code' => $payment_code,
                'journal_entry_id' => $journalResult['journal_entry_id'] ?? null,
                'invoice_id' => $invoice_id,
                'balance_due' => (float)($receipt['balance_due'] ?? 0),
                'is_fully_paid' => !empty($receipt['is_fully_paid']),
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            return ['status' => 'error', 'message' => 'Payment failed: ' . $e->getMessage()];
        }

     }

    /**
     * Reverse a customer payment recorded from sales/today (undo allocation, ledger, GL).
     */
    public function reverseCustomerPayment(int $payment_id, string $reason): array
    {
        $reason = trim($reason);
        if ($payment_id <= 0) {
            return ['status' => 'error', 'message' => 'Invalid payment ID.'];
        }
        if (strlen($reason) < 5) {
            return ['status' => 'error', 'message' => 'Reversal reason is required (at least 5 characters).'];
        }

        $this->db->beginTransaction();
        try {
            $this->db->query("
                SELECT * FROM customer_payments
                WHERE id = :id
                FOR UPDATE
            ");
            $this->db->bind(':id', $payment_id);
            $payment = $this->db->single();

            if (!$payment || (int)($payment['is_reversed'] ?? 0) === 1) {
                throw new Exception('Payment not found or already reversed.');
            }

            if (($payment['transaction_type'] ?? 'receive') !== 'receive') {
                throw new Exception('Only receive payments can be reversed from sales.');
            }

            $branchId = (int)($payment['branch_id'] ?? 0);
            if ($branchId > 0) {
                $this->assertInvoiceAccessible($branchId);
            }

            $amount = (float)($payment['amount'] ?? 0);
            if ($amount <= 0) {
                throw new Exception('Invalid payment amount.');
            }

            $customer_id = (int)($payment['customer_id'] ?? 0);
            if ($customer_id <= 0) {
                throw new Exception('Invalid customer on payment.');
            }

            $this->db->query("
                SELECT ipa.*, si.invoice_code, si.branch_id AS invoice_branch_id
                FROM invoice_payment_allocations ipa
                LEFT JOIN sales_invoices si ON si.id = ipa.invoice_id
                WHERE ipa.payment_id = :pid
            ");
            $this->db->bind(':pid', $payment_id);
            $allocations = $this->db->resultSet();

            if ($allocations === []) {
                throw new Exception(
                    'This payment has no invoice allocation from sales. Reverse it from Accounting → Customer.'
                );
            }

            foreach ($allocations as $alloc) {
                if (!empty($alloc['invoice_branch_id'])) {
                    $this->assertInvoiceAccessible((int)$alloc['invoice_branch_id']);
                }
            }

            if (!empty($payment['journal_entry_id'])) {
                require_once __DIR__ . '/../../Accounting/JournalPostingService.php';
                $journalService = new JournalPostingService();
                $rev = $journalService->reverseLinkedJournal(
                    (int)$payment['journal_entry_id'],
                    'Payment reversal: ' . ($payment['payment_code'] ?? $payment_id) . ' — ' . $reason
                );
                if (($rev['status'] ?? '') === 'error') {
                    throw new Exception('Failed to reverse payment journal: ' . ($rev['message'] ?? ''));
                }
            }

            require_once __DIR__ . '/../../Branch/BranchIntercompanyService.php';
            (new BranchIntercompanyService($this->db))->reverseCustomerPaymentSettlements($payment_id);

            $prevBalance = $this->getCustomerRunningBalance($customer_id);
            $newBalance = $prevBalance + $amount;

            $paymentBranchId = (int)($payment['branch_id'] ?? 0);
            $this->insertCustomerLedgerEntry([
                'customer_id'      => $customer_id,
                'reference_type'   => 'reversal',
                'reference_id'     => $payment_id,
                'debit'            => $amount,
                'credit'           => 0,
                'running_balance'  => $newBalance,
                'branch_id'        => $paymentBranchId > 0 ? $paymentBranchId : self::sessionBranchId(),
                'remarks'          => 'Reversal of #' . ($payment['payment_code'] ?? $payment_id) . ' — ' . $reason,
                'is_reversed'      => 1,
            ]);

            $this->db->query("DELETE FROM invoice_payment_allocations WHERE payment_id = :pid");
            $this->db->bind(':pid', $payment_id);
            $this->db->execute();

            $this->db->query("
                UPDATE customer_payments
                SET is_reversed = 1,
                    reversed_at = NOW(),
                    reversed_by = :uid,
                    reverse_reason = :reason
                WHERE id = :id
            ");
            $this->db->bind(':uid', $_SESSION['user_id'] ?? 1);
            $this->db->bind(':reason', $reason);
            $this->db->bind(':id', $payment_id);
            $this->db->execute();

            $invoiceIds = array_values(array_unique(array_filter(array_map(
                static fn($a) => (int)($a['invoice_id'] ?? 0),
                $allocations
            ))));

            $this->db->commit();

            return [
                'status'       => 'success',
                'message'      => 'Payment reversed successfully.',
                'payment_id'   => $payment_id,
                'payment_code' => $payment['payment_code'] ?? null,
                'amount'       => $amount,
                'invoice_ids'  => $invoiceIds,
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }


    public function getInvoiceForReceive($invoice_id) {

    
        $this->db->query("
            SELECT si.*, c.shop_name, c.mobile, c.address,
                   COALESCE((
                       SELECT SUM(ipa.allocated_amount)
                       FROM invoice_payment_allocations ipa
                       INNER JOIN customer_payments cp ON cp.id = ipa.payment_id
                       WHERE ipa.invoice_id = si.id
                         AND COALESCE(cp.is_reversed, 0) = 0
                   ), 0) AS receive_amount
            FROM sales_invoices si
            JOIN customers c ON si.customer_id = c.id
            WHERE si.id = :id AND si.is_reversed = 0
        ");
        $this->db->bind(':id', $invoice_id);
        $row = $this->db->single();
        if ($row) {
            try {
                $this->assertInvoiceAccessible((int)$row['branch_id']);
            } catch (Exception $e) {
                return false;
            }
        }
        return $row;

    }


    public function getInvoiceForPrint($invoice_id) {
        $ctx = $this->getPaymentReceiptData((int)$invoice_id);
        if (!$ctx) {
            return false;
        }
        $inv = $ctx['invoice'];
        $inv['received_amount'] = $ctx['paid_total'];
        return $inv;
    }

    /**
     * Full context for payment receipt print (invoice + allocation lines).
     */
    public function getPaymentReceiptData(int $invoiceId): ?array
    {
        $this->db->query("
            SELECT si.id, si.invoice_code, si.invoice_date, si.status,
                   si.total_amount, si.transport_cost, si.discount, si.subtotal,
                   si.customer_id, si.branch_id,
                   c.shop_name, c.customer_name, c.mobile, c.address,
                   e.name AS salesman_name,
                   b.branch_name, b.address AS branch_address, b.phone AS branch_phone
            FROM sales_invoices si
            JOIN customers c ON si.customer_id = c.id
            JOIN branches b ON si.branch_id = b.id
            LEFT JOIN employees e ON si.salesman_id = e.id
            WHERE si.id = :id AND si.is_reversed = 0
        ");
        $this->db->bind(':id', $invoiceId);
        $invoice = $this->db->single();
        if (!$invoice) {
            return null;
        }

        try {
            $this->assertInvoiceAccessible((int)$invoice['branch_id']);
        } catch (Exception $e) {
            return null;
        }

        $this->db->query("
            SELECT ipa.allocated_amount,
                   ipa.created_at AS allocated_at,
                   cp.id AS payment_id,
                   cp.payment_code,
                   cp.payment_date,
                   cp.amount AS payment_amount,
                   cp.payment_mode,
                   cp.reference_no,
                   cp.remarks,
                   cp.bank_id,
                   b.bank_name,
                   b.account_number,
                   COALESCE(emp.name, u.username, '—') AS received_by_name
            FROM invoice_payment_allocations ipa
            INNER JOIN customer_payments cp ON cp.id = ipa.payment_id
            LEFT JOIN banks b ON b.id = cp.bank_id
            LEFT JOIN users u ON u.id = cp.created_by
            LEFT JOIN employees emp ON emp.id = u.employee_id
            WHERE ipa.invoice_id = :id
              AND COALESCE(cp.is_reversed, 0) = 0
            ORDER BY cp.payment_date DESC, cp.id DESC
        ");
        $this->db->bind(':id', $invoiceId);
        $payments = $this->db->resultSet();

        $paidTotal = 0.0;
        foreach ($payments as $p) {
            $paidTotal += (float)($p['allocated_amount'] ?? 0);
        }
        $invoiceTotal = (float)($invoice['total_amount'] ?? 0);
        $balanceDue = max(0, round($invoiceTotal - $paidTotal, 2));

        return [
            'invoice'     => $invoice,
            'payments'    => $payments,
            'paid_total'  => round($paidTotal, 2),
            'balance_due' => $balanceDue,
            'is_fully_paid' => $balanceDue < 0.01,
        ];
    }

}

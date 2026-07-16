<?php
// app/models/ChallanModel.php

require_once '../core/Database.php';
require_once 'SalesModel.php';
require_once __DIR__ . '/../services/Stock/StockService.php';
require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';

class ChallanModel extends SalesModel {

    protected StockService $stock;

    public function __construct() {
        parent::__construct();
        $this->stock = new StockService($this->db);
    }

    /**
     * Challan/godown is always limited to the logged-in user's session branch
     * (warehouse staff must not open or list other branches' invoices).
     */
    public function assertInvoiceAccessible(int $invoiceBranchId): void
    {
        if ((int)$invoiceBranchId !== self::sessionBranchId()) {
            throw new Exception('You do not have access to invoices from another branch.');
        }
    }

    /**
     * Resolve each dispatch line: demand qty = invoice line qty (cannot be reduced).
     * Validates warehouse, stock, and optional posted qty tampering.
     *
     * @param string $validationMode godown = pick warehouse (net available); finalize = trust godown + physical qty
     * @return array<int, array{item_id:int, product_id:int, product_name:string, warehouse_id:int, demand_qty:float, dispatched_ctn:float}>
     */
    protected function resolveDispatchLinesForInvoice(
        array $data,
        int $invoiceId,
        int $invoiceBranchId,
        string $validationMode = 'godown'
    ): array
    {
        $validationMode = $validationMode === 'finalize' ? 'finalize' : 'godown';
        if (empty($data['item_id']) || !is_array($data['item_id'])) {
            throw new Exception('No invoice lines to dispatch.');
        }

        $lines = [];
        foreach ($data['item_id'] as $i => $rawItemId) {
            $itemId = (int)$rawItemId;
            if ($itemId <= 0) {
                continue;
            }

            $this->db->query("
                SELECT sii.id, sii.product_id, sii.qty, p.product_name
                FROM sales_invoice_items sii
                JOIN products p ON p.id = sii.product_id
                WHERE sii.id = :iid AND sii.sales_invoice_id = :inv
            ");
            $this->db->bind(':iid', $itemId);
            $this->db->bind(':inv', $invoiceId);
            $row = $this->db->single();
            if (!$row) {
                throw new Exception(
                    'Invoice lines have changed. Refresh this page and try again.'
                );
            }

            $demandQty = (float)$row['qty'];
            if ($demandQty <= 0) {
                throw new Exception('Invalid invoice quantity on line #' . ($i + 1) . '.');
            }

            $postedQty = (float)($data['dispatched_qty'][$i] ?? $demandQty);
            if (abs($postedQty - $demandQty) > 0.0001) {
                throw new Exception(
                    'Dispatched quantity must match invoice demand ('
                    . number_format($demandQty, 2) . '). Partial dispatch is not allowed for '
                    . ($row['product_name'] ?? 'product') . '.'
                );
            }

            $wid = (int)($data['warehouse_id'][$i] ?? 0);
            if ($wid <= 0) {
                throw new Exception(
                    'Select a warehouse for ' . ($row['product_name'] ?? 'each product') . '.'
                );
            }

            $sessionBranchId = self::sessionBranchId();
            if (!$this->warehouseBelongsToBranch($wid, $sessionBranchId)) {
                throw new Exception('Warehouse does not belong to your branch.');
            }

            $productId = (int)$row['product_id'];
            $productName = (string)($row['product_name'] ?? 'product');

            if ($validationMode === 'finalize') {
                $this->db->query("
                    SELECT warehouse_id, ordered_qty
                    FROM sales_invoice_dispatches
                    WHERE sales_invoice_id = :inv AND product_id = :pid
                    LIMIT 1
                ");
                $this->db->bind(':inv', $invoiceId);
                $this->db->bind(':pid', $productId);
                $godownRow = $this->db->single();
                if (!$godownRow) {
                    throw new Exception(
                        'Godown setup missing for ' . $productName . '. Save godown copy again.'
                    );
                }

                $godownWh = (int)$godownRow['warehouse_id'];
                if ($godownWh <= 0) {
                    throw new Exception('Godown warehouse not set for ' . $productName . '.');
                }
                if ($wid !== $godownWh) {
                    throw new Exception(
                        'Warehouse for ' . $productName . ' was fixed at godown and cannot be changed.'
                    );
                }
                $wid = $godownWh;

                $godownOrdered = (float)$godownRow['ordered_qty'];
                if (abs($godownOrdered - $demandQty) > 0.0001) {
                    throw new Exception(
                        'Invoice quantity changed since godown was saved. Refresh and try again.'
                    );
                }

                $physical = $this->getWarehousePhysicalStock($productId, $wid);
                if ($demandQty > $physical + 0.0001) {
                    throw new Exception(
                        'Not enough physical stock for ' . $productName
                        . ' in the selected warehouse (need '
                        . number_format($demandQty, 2) . ', on hand '
                        . number_format($physical, 2)
                        . '). Receive stock or reverse and re-assign godown.'
                    );
                }
            } else {
                $available = $this->Get_Warehouse_Available_Stock($productId, $wid, $invoiceId);
                if ($demandQty > $available + 0.0001) {
                    throw new Exception(
                        'Insufficient stock for ' . $productName
                        . '. Invoice demand: ' . number_format($demandQty, 2)
                        . ', available in warehouse: ' . number_format($available, 2)
                        . '. Choose another warehouse or receive stock first.'
                    );
                }
            }

            $lines[] = [
                'item_id'         => $itemId,
                'product_id'      => (int)$row['product_id'],
                'product_name'    => (string)$row['product_name'],
                'warehouse_id'    => $wid,
                'demand_qty'      => $demandQty,
                'dispatched_ctn'  => (float)($data['dispatched_ctn'][$i] ?? 0),
            ];
        }

        if ($lines === []) {
            throw new Exception('No invoice lines to dispatch.');
        }

        return $lines;
    }

    protected function assertGodownPreparedForChallan(int $invoiceId): void
    {
        $this->db->query("
            SELECT status FROM sales_invoices
            WHERE id = :id AND is_reversed = 0
        ");
        $this->db->bind(':id', $invoiceId);
        $row = $this->db->single();
        if (!$row) {
            throw new Exception('Invoice not found.');
        }
        if (($row['status'] ?? '') !== 'godown_issued') {
            throw new Exception(
                'Save godown copy first. Challan can only be created after godown setup is saved.'
            );
        }

        $this->db->query("
            SELECT COUNT(*) AS c FROM sales_invoice_items WHERE sales_invoice_id = :id
        ");
        $this->db->bind(':id', $invoiceId);
        $itemCount = (int)($this->db->single()['c'] ?? 0);

        $this->db->query("
            SELECT COUNT(*) AS c FROM sales_invoice_dispatches WHERE sales_invoice_id = :id
        ");
        $this->db->bind(':id', $invoiceId);
        $dispatchCount = (int)($this->db->single()['c'] ?? 0);

        if ($itemCount <= 0 || $dispatchCount < $itemCount) {
            throw new Exception(
                'Godown dispatch records are missing. Save godown copy again before creating challan.'
            );
        }
    }

    
       // ================= PREPARE GODOWN (Step 1) =================
    public function prepareGodown($data) {
        $this->db->beginTransaction();
        try {
            $invoice_id = (int)$data['invoice_id'];

            $this->db->query("
                SELECT status, branch_id, customer_id, subtotal, discount, transport_cost, total_amount
                FROM sales_invoices
                WHERE id = :id AND is_reversed = 0
                FOR UPDATE
            ");
            $this->db->bind(':id', $invoice_id);
            $invoiceRow = $this->db->single();

            if (!$invoiceRow) {
                throw new Exception('Invoice not found.');
            }

            $this->assertInvoiceAccessible((int)$invoiceRow['branch_id']);
            $invoiceBranchId = (int)$invoiceRow['branch_id'];

            $status = $invoiceRow['status'] ?? '';
            if (!in_array($status, ['draft', 'godown_issued'], true)) {
                throw new Exception('Godown can only be prepared for draft or godown-issued invoices.');
            }

            $transportResult = $this->persistInvoiceTransportCost(
                $data,
                $invoiceRow,
                $invoice_id,
                'Transport/total updated at godown save'
            );

            $dispatchMode = ($status === 'godown_issued') ? 'finalize' : 'godown';
            $dispatchLines = $this->resolveDispatchLinesForInvoice(
                $data,
                $invoice_id,
                $invoiceBranchId,
                $dispatchMode
            );

            foreach ($dispatchLines as $line) {
                $this->db->query("UPDATE sales_invoice_items SET warehouse_id = :wid WHERE id = :id");
                $this->db->bind(':wid', $line['warehouse_id']);
                $this->db->bind(':id', $line['item_id']);
                $this->db->execute();
            }

            // 2. Save dispatchers (junction table) - Clear & Insert
            $this->db->query("DELETE FROM sales_invoice_dispatchers WHERE sales_invoice_id = :iid");
            $this->db->bind(':iid', $invoice_id);
            $this->db->execute();

            if (!empty($data['dispatcher_id']) && is_array($data['dispatcher_id'])) {
                foreach ($data['dispatcher_id'] as $emp_id) {
                    if (!empty($emp_id)) {
                        $this->db->query("INSERT INTO sales_invoice_dispatchers (sales_invoice_id, employee_id) 
                                        VALUES (:iid, :eid)");
                        $this->db->bind(':iid', $invoice_id);
                        $this->db->bind(':eid', (int)$emp_id);
                        $this->db->execute();
                    }
                }
            }

            // 3. Clear previous dispatches and INSERT fresh (This solves your duplicate problem)
            $this->db->query("DELETE FROM sales_invoice_dispatches WHERE sales_invoice_id = :iid");
            $this->db->bind(':iid', $invoice_id);
            $this->db->execute();

            foreach ($dispatchLines as $line) {
                $this->db->query("
                    INSERT INTO sales_invoice_dispatches
                    (sales_invoice_id, product_id, warehouse_id, ordered_qty, dispatched_qty, dispatched_ctn)
                    VALUES (:iid, :pid, :wid, :oqty, 0, :ctn)
                ");
                $this->db->bind(':iid', $invoice_id);
                $this->db->bind(':pid', $line['product_id']);
                $this->db->bind(':wid', $line['warehouse_id']);
                $this->db->bind(':oqty', $line['demand_qty']);
                $this->db->bind(':ctn', $line['dispatched_ctn']);
                $this->db->execute();
            }

            // 4. Update invoice status
            $this->db->query("
                UPDATE sales_invoices
                SET status = 'godown_issued',
                    godown_issued_at = NOW()
                WHERE id = :id
            ");
            $this->db->bind(':id', $invoice_id);
            $this->db->execute();

            $this->db->commit();

            $message = 'Godown prepared successfully!';
            if (abs($transportResult['total_diff']) > 0.0001) {
                $message .= ' Invoice total updated to Tk '
                    . number_format($transportResult['new_total'], 2) . '.';
            }

            return [
                'status'       => 'success',
                'message'      => $message,
                'new_total'    => $transportResult['new_total'],
                'transport'    => $transportResult['transport'],
                'total_diff'   => $transportResult['total_diff'],
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }


    public function finalizeChallan($data) {
        $this->db->beginTransaction();
        try {
            $invoice_id = (int)$data['invoice_id'];

            // Double-finalization protection + branch scope
            $this->db->query("
                SELECT status, branch_id, customer_id, subtotal, discount, transport_cost, total_amount, invoice_code
                FROM sales_invoices WHERE id = :id AND is_reversed = 0 FOR UPDATE
            ");
            $this->db->bind(':id', $invoice_id);
            $invoiceRow = $this->db->single();
            if (!$invoiceRow) {
                throw new Exception('Invoice not found.');
            }
            $this->assertInvoiceAccessible((int)$invoiceRow['branch_id']);
            $invoiceBranchId = (int)$invoiceRow['branch_id'];
            $customerId = (int)$invoiceRow['customer_id'];
            if (($invoiceRow['status'] ?? '') === 'challan_completed') {
                throw new Exception("Challan already completed.");
            }

            $this->assertGodownPreparedForChallan($invoice_id);
            $dispatchLines = $this->resolveDispatchLinesForInvoice(
                $data,
                $invoice_id,
                $invoiceBranchId,
                'finalize'
            );

            $challan_code = $this->generateSalesChallanCode();
            $oldTransport = (float)$invoiceRow['transport_cost'];
            $oldTotal = (float)$invoiceRow['total_amount'];

            $transportResult = $this->persistInvoiceTransportCost(
                $data,
                $invoiceRow,
                $invoice_id,
                'Transport/total adjustment on challan #' . $challan_code
            );
            $newTotal = $transportResult['new_total'];
            $totalDiff = $transportResult['total_diff'];

            // Update Invoice status (transport/total already persisted above)
            if ($this->invoiceHasPreChallanColumns()) {
                $this->db->query("
                    UPDATE sales_invoices
                    SET status = 'challan_completed',
                        challan_completed_at = NOW(),
                        godown_issued_at = COALESCE(godown_issued_at, NOW()),
                        pre_challan_transport = :pre_tc,
                        pre_challan_total = :pre_total
                    WHERE id = :id
                ");
                $this->db->bind(':pre_tc', $oldTransport);
                $this->db->bind(':pre_total', $oldTotal);
            } else {
                $this->db->query("
                    UPDATE sales_invoices
                    SET status = 'challan_completed',
                        challan_completed_at = NOW(),
                        godown_issued_at = COALESCE(godown_issued_at, NOW())
                    WHERE id = :id
                ");
            }
            $this->db->bind(':id', $invoice_id);
            $this->db->execute();

            // Create Challan
            $this->db->query("INSERT INTO sales_challans (sales_invoice_id, challan_code, challan_date, created_by) VALUES (:iid, :code, CURDATE(), :user)");
            $this->db->bind(':iid', $invoice_id);
            $this->db->bind(':code', $challan_code);
            $this->db->bind(':user', $_SESSION['user_id'] ?? null);
            $this->db->execute();
            $challan_id = $this->db->lastInsertId();
            $cogsTotal = 0.0;

            foreach ($dispatchLines as $line) {
                $qty = $line['demand_qty'];
                $pid = $line['product_id'];
                $wid = $line['warehouse_id'];

                $this->db->query("SELECT avg_cost FROM warehouse_stock WHERE product_id = :pid AND warehouse_id = :wid");
                $this->db->bind(':pid', $pid);
                $this->db->bind(':wid', $wid);
                $avg_cost = round((float)($this->db->single()['avg_cost'] ?? 0), 4);
                if ($avg_cost <= 0) {
                    throw new Exception(
                        'Cannot complete challan: zero cost for product #' . $pid . ' in warehouse #' . $wid . '. Receive stock or set cost first.'
                    );
                }

                $this->db->query("
                    UPDATE sales_invoice_dispatches
                    SET dispatched_qty = :dqty, dispatched_ctn = :ctn, dispatched_at = NOW(),
                        warehouse_id = :wid
                    WHERE sales_invoice_id = :iid AND product_id = :pid
                ");
                $this->db->bind(':dqty', $qty);
                $this->db->bind(':ctn', $line['dispatched_ctn']);
                $this->db->bind(':wid', $wid);
                $this->db->bind(':iid', $invoice_id);
                $this->db->bind(':pid', $pid);
                $this->db->execute();
                if ($this->db->rowCount() === 0) {
                    throw new Exception(
                        'Could not update dispatch row for product #' . $pid . '. Save godown copy again before challan.'
                    );
                }

                $this->db->query("
                    UPDATE sales_invoice_items
                    SET warehouse_id = :wid
                    WHERE id = :item_id AND sales_invoice_id = :iid
                ");
                $this->db->bind(':wid', $wid);
                $this->db->bind(':item_id', $line['item_id']);
                $this->db->bind(':iid', $invoice_id);
                $this->db->execute();

                $this->stock->updateWarehouseStock($wid, $pid, -$qty, $avg_cost);

                $this->stock->logMovement([
                    'product_id'     => $pid,
                    'warehouse_id'   => $wid,
                    'qty'            => -$qty,
                    'rate'           => $avg_cost,
                    'reference_type' => 'sales_challan',
                    'reference_id'   => $challan_id,
                    'remarks'        => 'Sales Challan #' . $challan_code,
                ]);

                $this->saveChallanIssueLine($challan_id, $pid, $wid, $qty, $avg_cost);

                $cogsTotal += $qty * $avg_cost;
            }

            $journalService = new JournalPostingService($this->db);
            $adjustmentJournalId = null;

            if (abs($totalDiff) > 0.0001) {
                $adjResult = $journalService->postSalesInvoiceTotalAdjustment($invoice_id, $totalDiff, [
                    'invoice_code' => $invoiceRow['invoice_code'] ?? null,
                    'customer_id'  => $customerId,
                    'branch_id'    => $invoiceBranchId,
                    'entry_date'   => date('Y-m-d'),
                    'challan_code' => $challan_code,
                    'challan_id'   => (int)$challan_id,
                ]);
                if (($adjResult['status'] ?? '') === 'error') {
                    throw new Exception('Transport/total GL adjustment failed: ' . ($adjResult['message'] ?? ''));
                }
                $adjustmentJournalId = !empty($adjResult['journal_entry_id'])
                    ? (int)$adjResult['journal_entry_id']
                    : null;
            }

            $this->updateChallanTransportMeta($challan_id, $totalDiff, $adjustmentJournalId);

            $cogsResult = $journalService->postSalesChallanCOGS($challan_id, [
                'challan_code'  => $challan_code,
                'challan_date'  => date('Y-m-d'),
                'cogs_amount'   => round($cogsTotal, 2),
                'branch_id'     => $invoiceBranchId,
            ]);
            if (($cogsResult['status'] ?? '') === 'error') {
                throw new Exception('COGS journal posting failed: ' . ($cogsResult['message'] ?? ''));
            }
            $cogsPosted = round($cogsTotal, 2);
            if ($cogsPosted > 0.0001 && empty($cogsResult['journal_entry_id'])) {
                throw new Exception('COGS journal was not created although stock was issued (amount ' . $cogsPosted . ').');
            }
            if (!empty($cogsResult['journal_entry_id'])) {
                $this->setChallanJournalEntryId($challan_id, (int)$cogsResult['journal_entry_id']);
            }

            $this->db->commit();
            return [
                'status' => 'success',
                'message' => 'Challan completed! No: ' . $challan_code,
                'challan_code' => $challan_code,
                'challan_id' => $challan_id,
                'invoice_id' => $invoice_id,
                'transport_adjustment' => $totalDiff,
                'new_total' => $newTotal,
                'journal_entry_id' => $cogsResult['journal_entry_id'] ?? null,
                'cogs_amount' => round($cogsTotal, 2),
            ];

        } catch (Exception $e) {
            $this->db->rollback();
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Phase 4: Reverse a completed sales challan — restore stock, rollback invoice status.
     * Phase 5: reversing journal when journal_entry_id is set on challan.
     */
    public function reverseChallan(int $invoiceId, string $reason): array
    {
        $reason = trim($reason);
        if ($invoiceId <= 0 || strlen($reason) < 5) {
            return ['status' => 'error', 'message' => 'Invoice ID and reversal reason (min 5 chars) are required.'];
        }

        $this->db->beginTransaction();
        try {
            $userId = (int)($_SESSION['user_id'] ?? 1);

            $this->db->query("
                SELECT si.* FROM sales_invoices si
                WHERE si.id = :id AND si.is_reversed = 0 FOR UPDATE
            ");
            $this->db->bind(':id', $invoiceId);
            $invoice = $this->db->single();

            if (!$invoice) {
                throw new Exception('Invoice not found.');
            }
            $this->assertInvoiceAccessible((int)$invoice['branch_id']);

            if (($invoice['status'] ?? '') !== 'challan_completed') {
                throw new Exception('Only completed challans can be reversed.');
            }

            $this->db->query("
                SELECT COUNT(*) AS c FROM sales_returns
                WHERE sales_invoice_id = :iid AND status = 'completed' AND COALESCE(is_reversed, 0) = 0
            ");
            $this->db->bind(':iid', $invoiceId);
            if ((int)($this->db->single()['c'] ?? 0) > 0) {
                throw new Exception('Cannot reverse challan: completed sales returns exist for this invoice.');
            }

            $this->db->query("
                SELECT * FROM sales_challans
                WHERE sales_invoice_id = :iid AND COALESCE(is_reversed, 0) = 0
                ORDER BY id DESC LIMIT 1
            ");
            $this->db->bind(':iid', $invoiceId);
            $challan = $this->db->single();
            if (!$challan) {
                throw new Exception('Active challan record not found.');
            }

            $challanId = (int)$challan['id'];
            $challanCode = $challan['challan_code'];
            $transportAdjustment = (float)($challan['transport_adjustment'] ?? 0);
            if (abs($transportAdjustment) < 0.0001) {
                $transportAdjustment = round(
                    (float)$invoice['total_amount'] - (float)($invoice['pre_challan_total'] ?? 0),
                    2
                );
            }

            $ledgerAdjustment = $this->sumChallanInvoiceAdjustments(
                (int)$invoice['customer_id'],
                $invoiceId,
                $challanCode
            );
            if (abs($ledgerAdjustment) > 0.0001) {
                $transportAdjustment = $ledgerAdjustment;
            }

            $itemsReversed = $this->restoreStockFromChallanIssue(
                $challanId,
                $challanCode,
                $invoiceId,
                (int)$invoice['branch_id'],
                $reason,
                (int)($challan['journal_entry_id'] ?? 0)
            );

            $journalService = new JournalPostingService($this->db);

            if (abs($transportAdjustment) > 0.0001) {
                $this->reverseChallanTransportLedger(
                    (int)$invoice['customer_id'],
                    $invoiceId,
                    $transportAdjustment,
                    (int)$invoice['branch_id'],
                    $challanCode,
                    $reason
                );

                $adjJournalId = (int)($challan['adjustment_journal_entry_id'] ?? 0);
                if ($adjJournalId <= 0) {
                    $adjJournalId = (int)($this->findChallanAdjustmentJournalId(
                        $invoiceId,
                        $challanCode,
                        $journalService
                    ) ?? 0);
                }
                if ($adjJournalId > 0) {
                    $adjRev = $journalService->reverseLinkedJournal(
                        $adjJournalId,
                        'Challan reversal transport adjustment: ' . $reason
                    );
                    if (($adjRev['status'] ?? '') === 'error') {
                        throw new Exception(
                            'Failed to reverse transport adjustment journal: ' . ($adjRev['message'] ?? '')
                        );
                    }
                }
            }

            $restoreTransport = $invoice['pre_challan_transport'] ?? null;
            $restoreTotal = $invoice['pre_challan_total'] ?? null;
            if ($restoreTransport === null || $restoreTotal === null) {
                $subtotal = (float)($invoice['subtotal'] ?? 0);
                $discount = (float)($invoice['discount'] ?? 0);
                $restoreTransport = round((float)($invoice['transport_cost'] ?? 0) - $transportAdjustment, 2);
                $restoreTotal = round($subtotal + (float)$restoreTransport - $discount, 2);
            } else {
                $restoreTransport = (float)$restoreTransport;
                $restoreTotal = (float)$restoreTotal;
            }

            $this->db->query("
                UPDATE sales_challans
                SET is_reversed = 1,
                    reversed_at = NOW(),
                    reversed_by = :uid,
                    reverse_reason = :reason
                WHERE id = :id
            ");
            $this->db->bind(':uid', $userId);
            $this->db->bind(':reason', $reason);
            $this->db->bind(':id', $challanId);
            $this->db->execute();

            if ($this->invoiceHasPreChallanColumns()) {
                $this->db->query("
                    UPDATE sales_invoices
                    SET status = 'godown_issued',
                        challan_completed_at = NULL,
                        transport_cost = :tc,
                        total_amount = :total,
                        pre_challan_transport = NULL,
                        pre_challan_total = NULL
                    WHERE id = :id
                ");
            } else {
                $this->db->query("
                    UPDATE sales_invoices
                    SET status = 'godown_issued',
                        challan_completed_at = NULL,
                        transport_cost = :tc,
                        total_amount = :total
                    WHERE id = :id
                ");
            }
            $this->db->bind(':tc', (float)$restoreTransport);
            $this->db->bind(':total', (float)$restoreTotal);
            $this->db->bind(':id', $invoiceId);
            $this->db->execute();

            $journalEntryId = null;
            if (!empty($challan['journal_entry_id'])) {
                $rev = $journalService->reverseLinkedJournal((int)$challan['journal_entry_id'], $reason);
                if (!empty($rev['journal_entry_id'])) {
                    $journalEntryId = (int)$rev['journal_entry_id'];
                }
            }

            $this->db->commit();

            return [
                'status'                 => 'success',
                'message'                => 'Challan reversed. Stock, transport/AR, and COGS rolled back; invoice returned to godown issued.',
                'invoice_id'             => $invoiceId,
                'invoice_code'           => $invoice['invoice_code'],
                'challan_id'             => $challanId,
                'challan_code'           => $challanCode,
                'items_reversed'         => $itemsReversed,
                'journal_entry_id'       => $journalEntryId,
                'transport_restored'   => (float)$restoreTransport,
                'total_restored'       => (float)$restoreTotal,
                'transport_adjustment' => $transportAdjustment,
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Restore stock at original challan issue rates (sales_challan_items or stock_transactions).
     */
    protected function restoreStockFromChallanIssue(
        int $challanId,
        string $challanCode,
        int $invoiceId,
        int $branchId,
        string $reason,
        int $cogsJournalId = 0
    ): int {
        $issueLines = $this->loadChallanIssueLinesForRestore($challanId, $invoiceId, $cogsJournalId);
        $itemsReversed = 0;

        foreach ($issueLines as $line) {
            $pid = (int)($line['product_id'] ?? 0);
            $wid = (int)($line['warehouse_id'] ?? 0);
            $qty = (float)($line['qty'] ?? 0);
            if ($qty <= 0 || $wid <= 0 || $pid <= 0) {
                continue;
            }

            if (!$this->warehouseBelongsToBranch($wid, $branchId)) {
                throw new Exception('Warehouse branch mismatch during challan reversal.');
            }

            $issueRate = $this->resolveChallanIssueRate(
                $challanId,
                $invoiceId,
                $pid,
                $wid,
                $qty,
                $cogsJournalId,
                isset($line['issue_rate']) ? (float)$line['issue_rate'] : null
            );

            $this->stock->updateWarehouseStock($wid, $pid, $qty, $issueRate);
            $this->stock->logMovement([
                'product_id'     => $pid,
                'warehouse_id'   => $wid,
                'qty'            => $qty,
                'rate'           => $issueRate,
                'reference_type' => 'sales_challan_reversal',
                'reference_id'   => $challanId,
                'remarks'        => "Reversal of Challan #{$challanCode}: {$reason}",
            ]);
            $itemsReversed++;

            $this->db->query("
                UPDATE sales_invoice_dispatches
                SET dispatched_qty = 0, dispatched_ctn = 0, dispatched_at = NULL
                WHERE sales_invoice_id = :iid AND product_id = :pid
            ");
            $this->db->bind(':iid', $invoiceId);
            $this->db->bind(':pid', $pid);
            $this->db->execute();
        }

        return $itemsReversed;
    }

    /**
     * @return list<array{product_id:int, warehouse_id:int, qty:float, issue_rate?:float}>
     */
    protected function loadChallanIssueLinesForRestore(int $challanId, int $invoiceId, int $cogsJournalId): array
    {
        if ($this->hasChallanIssueItemsTable()) {
            $items = $this->fetchChallanIssueItems($challanId);
            if ($items !== []) {
                return $items;
            }
        }

        $fromTx = $this->fetchIssueLinesFromStockTransactions($challanId);
        if ($fromTx !== []) {
            return $fromTx;
        }

        return $this->fetchLegacyDispatchIssueLines($challanId, $invoiceId, $cogsJournalId);
    }

    /**
     * @return list<array{product_id:int, warehouse_id:int, qty:float, issue_rate:float}>
     */
    protected function fetchChallanIssueItems(int $challanId): array
    {
        $this->db->query("
            SELECT product_id, warehouse_id, qty, issue_rate
            FROM sales_challan_items
            WHERE sales_challan_id = :cid
            ORDER BY id ASC
        ");
        $this->db->bind(':cid', $challanId);
        $rows = $this->db->resultSet() ?: [];

        return array_map(static function (array $row): array {
            return [
                'product_id'   => (int)$row['product_id'],
                'warehouse_id' => (int)$row['warehouse_id'],
                'qty'          => (float)$row['qty'],
                'issue_rate'   => (float)$row['issue_rate'],
            ];
        }, $rows);
    }

    /**
     * @return list<array{product_id:int, warehouse_id:int, qty:float, issue_rate:float}>
     */
    protected function fetchIssueLinesFromStockTransactions(int $challanId): array
    {
        $this->db->query("
            SELECT product_id, warehouse_id, qty, rate
            FROM stock_transactions
            WHERE reference_type = 'sales_challan'
              AND reference_id = :cid
              AND qty < -0.0001
            ORDER BY id ASC
        ");
        $this->db->bind(':cid', $challanId);
        $rows = $this->db->resultSet() ?: [];
        $lines = [];

        foreach ($rows as $row) {
            $lines[] = [
                'product_id'   => (int)$row['product_id'],
                'warehouse_id' => (int)$row['warehouse_id'],
                'qty'          => abs((float)($row['qty'] ?? 0)),
                'issue_rate'   => (float)($row['rate'] ?? 0),
            ];
        }

        return $lines;
    }

    /**
     * @return list<array{product_id:int, warehouse_id:int, qty:float}>
     */
    protected function fetchLegacyDispatchIssueLines(int $challanId, int $invoiceId, int $cogsJournalId): array
    {
        $this->db->query("
            SELECT product_id, warehouse_id, dispatched_qty AS qty
            FROM sales_invoice_dispatches
            WHERE sales_invoice_id = :iid AND COALESCE(dispatched_qty, 0) > 0
        ");
        $this->db->bind(':iid', $invoiceId);
        $rows = $this->db->resultSet() ?: [];
        $lines = [];

        foreach ($rows as $row) {
            $qty = (float)($row['qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $lines[] = [
                'product_id'   => (int)$row['product_id'],
                'warehouse_id' => (int)$row['warehouse_id'],
                'qty'          => $qty,
            ];
        }

        if ($lines === [] && $cogsJournalId > 0) {
            throw new Exception(
                'Cannot reverse challan: no issue lines found. Restore stock_transactions or run migration 040_sales_challan_issue_cost.sql.'
            );
        }

        return $lines;
    }

    protected function resolveChallanIssueRate(
        int $challanId,
        int $invoiceId,
        int $productId,
        int $warehouseId,
        float $qty,
        int $cogsJournalId,
        ?float $knownRate = null
    ): float {
        if ($knownRate !== null && $knownRate > 0) {
            return round($knownRate, 4);
        }

        if ($this->hasChallanIssueItemsTable()) {
            $this->db->query("
                SELECT issue_rate FROM sales_challan_items
                WHERE sales_challan_id = :cid
                  AND product_id = :pid
                  AND warehouse_id = :wid
                ORDER BY id DESC
                LIMIT 1
            ");
            $this->db->bind(':cid', $challanId);
            $this->db->bind(':pid', $productId);
            $this->db->bind(':wid', $warehouseId);
            $stored = (float)($this->db->single()['issue_rate'] ?? 0);
            if ($stored > 0) {
                return round($stored, 4);
            }
        }

        $this->db->query("
            SELECT rate FROM stock_transactions
            WHERE reference_type = 'sales_challan'
              AND reference_id = :cid
              AND product_id = :pid
              AND warehouse_id = :wid
              AND qty < -0.0001
            ORDER BY id DESC
            LIMIT 1
        ");
        $this->db->bind(':cid', $challanId);
        $this->db->bind(':pid', $productId);
        $this->db->bind(':wid', $warehouseId);
        $txRate = (float)($this->db->single()['rate'] ?? 0);
        if ($txRate > 0) {
            return round($txRate, 4);
        }

        if ($cogsJournalId > 0) {
            $lineCount = count($this->fetchChallanIssueItems($challanId))
                + count($this->fetchIssueLinesFromStockTransactions($challanId));
            if ($lineCount <= 1) {
                $cogsAmount = $this->sumJournalCogsDebit($cogsJournalId);
                if ($cogsAmount > 0 && $qty > 0) {
                    return round($cogsAmount / $qty, 4);
                }
            }
        }

        throw new Exception(
            'Cannot resolve original issue cost for product #' . $productId
            . ' (challan #' . $challanId . '). Re-post from stock history or run migration 040.'
        );
    }

    protected function sumJournalCogsDebit(int $journalEntryId): float
    {
        $this->db->query("
            SELECT COALESCE(SUM(jel.debit - jel.credit), 0) AS cogs
            FROM journal_entry_lines jel
            INNER JOIN ledgers l ON l.id = jel.ledger_id
            WHERE jel.journal_entry_id = :jid
              AND l.ledger_nature = 'cogs'
        ");
        $this->db->bind(':jid', $journalEntryId);
        $row = $this->db->single();

        return max(0.0, round((float)($row['cogs'] ?? 0), 2));
    }

    protected function saveChallanIssueLine(
        int $challanId,
        int $productId,
        int $warehouseId,
        float $qty,
        float $issueRate
    ): void {
        if (!$this->hasChallanIssueItemsTable()) {
            return;
        }

        $issueRate = round($issueRate, 4);
        $cogsAmount = round($qty * $issueRate, 2);

        $this->db->query("
            INSERT INTO sales_challan_items
            (sales_challan_id, product_id, warehouse_id, qty, issue_rate, cogs_amount)
            VALUES (:cid, :pid, :wid, :qty, :rate, :cogs)
        ");
        $this->db->bind(':cid', $challanId);
        $this->db->bind(':pid', $productId);
        $this->db->bind(':wid', $warehouseId);
        $this->db->bind(':qty', $qty);
        $this->db->bind(':rate', $issueRate);
        $this->db->bind(':cogs', $cogsAmount);
        $this->db->execute();
    }

    protected function hasChallanIssueItemsTable(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $this->db->query("SHOW TABLES LIKE 'sales_challan_items'");
            $cached = (bool)$this->db->single();
        } catch (Throwable $e) {
            $cached = false;
        }

        return $cached;
    }

    /**
     * Undo customer_ledger transport/total adjustment posted at challan finalize only.
     * Godown transport adjustments remain active (invoice stays at godown totals).
     */
    protected function reverseChallanTransportLedger(
        int $customerId,
        int $invoiceId,
        float $adjustmentDelta,
        int $branchId,
        string $challanCode,
        string $reason
    ): void {
        if (abs($adjustmentDelta) < 0.0001) {
            return;
        }

        $prevBalance = $this->getCustomerRunningBalance($customerId);
        $newBalance = $prevBalance - $adjustmentDelta;

        $this->insertCustomerLedgerEntry([
            'customer_id'      => $customerId,
            'reference_type'   => 'reversal',
            'reference_id'     => $invoiceId,
            'debit'            => $adjustmentDelta < 0 ? abs($adjustmentDelta) : 0,
            'credit'           => $adjustmentDelta > 0 ? $adjustmentDelta : 0,
            'running_balance'  => $newBalance,
            'branch_id'        => $branchId,
            'remarks'          => "Reversal of transport/total on challan #{$challanCode} — {$reason}",
            'is_reversed'      => 1,
        ]);

        $needle = '%adjustment on challan #' . $challanCode . '%';
        $this->db->query("
            UPDATE customer_ledger
            SET is_reversed = 1
            WHERE customer_id = :cid
              AND reference_type = 'invoice_adjustment'
              AND reference_id = :iid
              AND COALESCE(is_reversed, 0) = 0
              AND remarks LIKE :needle
        ");
        $this->db->bind(':cid', $customerId);
        $this->db->bind(':iid', $invoiceId);
        $this->db->bind(':needle', $needle);
        $this->db->execute();
    }

    /**
     * Net AR delta from challan-time invoice_adjustment rows (excludes godown saves).
     */
    protected function sumChallanInvoiceAdjustments(int $customerId, int $invoiceId, string $challanCode): float
    {
        $needle = '%adjustment on challan #' . $challanCode . '%';
        $this->db->query("
            SELECT COALESCE(SUM(debit - credit), 0) AS delta
            FROM customer_ledger
            WHERE customer_id = :cid
              AND reference_type = 'invoice_adjustment'
              AND reference_id = :iid
              AND COALESCE(is_reversed, 0) = 0
              AND remarks LIKE :needle
        ");
        $this->db->bind(':cid', $customerId);
        $this->db->bind(':iid', $invoiceId);
        $this->db->bind(':needle', $needle);

        return round((float)($this->db->single()['delta'] ?? 0), 2);
    }

    /**
     * Resolve transport adjustment GL for this challan (not another invoice/challan cycle).
     */
    protected function findChallanAdjustmentJournalId(
        int $invoiceId,
        string $challanCode,
        JournalPostingService $journalService
    ): ?int {
        $needle = '%Challan #' . $challanCode . '%';
        $this->db->query("
            SELECT id FROM journal_entries
            WHERE reference_type = 'sales_invoice_adjustment'
              AND reference_id = :iid
              AND COALESCE(is_reversed, 0) = 0
              AND description LIKE :needle
            ORDER BY id DESC
            LIMIT 1
        ");
        $this->db->bind(':iid', $invoiceId);
        $this->db->bind(':needle', $needle);
        $row = $this->db->single();

        if (!empty($row['id'])) {
            return (int)$row['id'];
        }

        return $journalService->findActiveJournalIdByReference('sales_invoice_adjustment', $invoiceId);
    }

    protected function updateChallanTransportMeta(
        int $challanId,
        float $transportAdjustment,
        ?int $adjustmentJournalId
    ): void {
        if (!$this->challanHasTransportMetaColumns()) {
            return;
        }

        $this->db->query("
            UPDATE sales_challans
            SET transport_adjustment = :adj,
                adjustment_journal_entry_id = :jid
            WHERE id = :id
        ");
        $this->db->bind(':adj', round($transportAdjustment, 2));
        $this->db->bind(':jid', $adjustmentJournalId);
        $this->db->bind(':id', $challanId);
        $this->db->execute();
    }

    protected function challanHasTransportMetaColumns(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $this->db->query("SHOW COLUMNS FROM sales_challans LIKE 'transport_adjustment'");
            $cached = (bool)$this->db->single();
        } catch (Throwable $e) {
            $cached = false;
        }

        return $cached;
    }

    protected function invoiceHasPreChallanColumns(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $this->db->query("SHOW COLUMNS FROM sales_invoices LIKE 'pre_challan_total'");
            $cached = (bool)$this->db->single();
        } catch (Throwable $e) {
            $cached = false;
        }

        return $cached;
    }

    /**
     * Save transport from challan form and recalculate invoice total (+ customer ledger if changed).
     *
     * @return array{transport:float,new_total:float,total_diff:float}
     */
    protected function persistInvoiceTransportCost(
        array $data,
        array $invoiceRow,
        int $invoiceId,
        string $ledgerRemark
    ): array {
        $newTransport = max(0, (float)($data['transport_cost'] ?? 0));
        $subtotal = (float)($invoiceRow['subtotal'] ?? 0);
        $discount = (float)($invoiceRow['discount'] ?? 0);
        $oldTotal = (float)($invoiceRow['total_amount'] ?? 0);
        $newTotal = round($subtotal + $newTransport - $discount, 2);
        $totalDiff = round($newTotal - $oldTotal, 2);

        $this->db->query("
            UPDATE sales_invoices
            SET transport_cost = :tc,
                total_amount = :total
            WHERE id = :id
        ");
        $this->db->bind(':tc', $newTransport);
        $this->db->bind(':total', $newTotal);
        $this->db->bind(':id', $invoiceId);
        $this->db->execute();

        if (abs($totalDiff) > 0.0001) {
            $this->applyCustomerLedgerDelta(
                (int)($invoiceRow['customer_id'] ?? 0),
                $invoiceId,
                $totalDiff,
                $ledgerRemark,
                (int)($invoiceRow['branch_id'] ?? 0)
            );
        }

        return [
            'transport'   => $newTransport,
            'new_total'   => $newTotal,
            'total_diff'  => $totalDiff,
        ];
    }

    /** Adjust customer AR when invoice total changes (e.g. transport at challan). */
    protected function applyCustomerLedgerDelta(
        int $customerId,
        int $invoiceId,
        float $delta,
        string $remarks,
        int $branchId = 0
    ): void {
        if (abs($delta) < 0.0001) {
            return;
        }

        $prevBalance = $this->getCustomerRunningBalance($customerId);
        $newBalance = $prevBalance + $delta;

        $this->insertCustomerLedgerEntry([
            'customer_id'      => $customerId,
            'reference_type'   => 'invoice_adjustment',
            'reference_id'     => $invoiceId,
            'debit'            => $delta > 0 ? $delta : 0,
            'credit'           => $delta < 0 ? abs($delta) : 0,
            'running_balance'  => $newBalance,
            'branch_id'        => $branchId,
            'remarks'          => $remarks,
        ]);
    }


    public function getInvoiceForGodownCopy($invoice_id) {
        // Main invoice
        $this->db->query("
            SELECT si.*, c.shop_name, c.mobile, c.address,
                   b.branch_name, b.address AS branch_address, b.phone AS branch_phone,
                   b.id as branch_id,
                   e.name as salesman_name
            FROM sales_invoices si
            JOIN customers c ON si.customer_id = c.id
            JOIN branches b ON si.branch_id = b.id
            JOIN employees e ON si.salesman_id = e.id
            WHERE si.id = :id AND si.is_reversed = 0
        ");
        $this->db->bind(':id', $invoice_id);
        $invoice = $this->db->single();

        if (!$invoice) return false;

        try {
            $this->assertInvoiceAccessible((int)$invoice['branch_id']);
        } catch (Exception $e) {
            return false;
        }

        // Items with warehouse & available stock
        $this->db->query("
            SELECT 
                sii.*,
                p.product_name, 
                p.unit,
                p.pcs_per_carton,
                w.warehouse_name,
                COALESCE((SELECT ws.qty FROM warehouse_stock ws WHERE ws.product_id = sii.product_id LIMIT 1), 0) as current_stock,
                COALESCE(sid.dispatched_qty, 0) as dispatched_qty,
                COALESCE(sid.dispatched_ctn, 0) as dispatched_ctn,
                COALESCE(sid.warehouse_id, sii.warehouse_id) AS resolved_warehouse_id,
                GREATEST(0, 
                    COALESCE((SELECT SUM(ws.qty) FROM warehouse_stock ws WHERE ws.product_id = sii.product_id), 0) - 
                    COALESCE((SELECT SUM(COALESCE(d.ordered_qty,0) - COALESCE(d.dispatched_qty,0))
                              FROM sales_invoice_dispatches d 
                              JOIN sales_invoices inv ON inv.id = d.sales_invoice_id
                              WHERE d.product_id = sii.product_id 
                                AND inv.status NOT IN ('challan_completed', 'reversed')
                                AND inv.is_reversed = 0), 0)
                ) as available_qty
            FROM sales_invoice_items sii
            JOIN products p ON sii.product_id = p.id
            LEFT JOIN warehouses w ON w.id = sii.warehouse_id
            LEFT JOIN sales_invoice_dispatches sid 
                   ON sid.sales_invoice_id = sii.sales_invoice_id 
                  AND sid.product_id = sii.product_id
            WHERE sii.sales_invoice_id = :id
            ORDER BY sii.id
        ");
        $this->db->bind(':id', $invoice_id);
        $invoice['items'] = $this->db->resultSet();

        // Dispatchers for prints
        $this->db->query("
            SELECT e.id, e.name 
            FROM sales_invoice_dispatchers sd
            JOIN employees e ON sd.employee_id = e.id
            WHERE sd.sales_invoice_id = :id
            ORDER BY e.name
        ");
        $this->db->bind(':id', $invoice_id);
        $invoice['dispatchers'] = $this->db->resultSet();

        $this->db->query("
            SELECT challan_code, challan_date, created_at
            FROM sales_challans
            WHERE sales_invoice_id = :id
            ORDER BY id DESC
            LIMIT 1
        ");
        $this->db->bind(':id', $invoice_id);
        $challan = $this->db->single();
        if ($challan) {
            $invoice['challan_code'] = $challan['challan_code'] ?? null;
            $invoice['challan_date'] = $challan['challan_date'] ?? null;
            $invoice['challan_created_at'] = $challan['created_at'] ?? null;
        }

        return $invoice;
    }

    // Unchanged methods below (copy from your original file)
    public function getPendingForChallan($branch_id = 0) {
        $branch_id = (int)$branch_id > 0 ? (int)$branch_id : self::sessionBranchId();

        $this->db->query("
            SELECT 
                si.id, si.invoice_code, si.invoice_date, si.total_amount, si.status,
                c.shop_name, c.mobile, e.name as salesman_name,
                si.created_at, si.godown_issued_at, si.challan_completed_at
            FROM sales_invoices si
            JOIN customers c ON si.customer_id = c.id
            JOIN employees e ON si.salesman_id = e.id
            WHERE si.branch_id = :branch_id 
              AND si.is_reversed = 0 
              AND si.status IN ('draft', 'godown_issued')
            ORDER BY si.created_at DESC
        ");
        $this->db->bind(':branch_id', $branch_id);
        return $this->db->resultSet();

     }
    protected function getWarehousePhysicalStock(int $productId, int $warehouseId): float
    {
        $this->db->query("
            SELECT COALESCE(qty, 0) AS qty
            FROM warehouse_stock
            WHERE product_id = :pid AND warehouse_id = :wid
        ");
        $this->db->bind(':pid', $productId);
        $this->db->bind(':wid', $warehouseId);
        $row = $this->db->single();

        return (float)($row['qty'] ?? 0);
    }

    public function getWarehousesForProduct($product_id, $branch_id = 0, ?int $excludeInvoiceId = null) {
        $branch_id = self::sessionBranchId();
        require_once __DIR__ . '/../services/Stock/StockAvailabilityService.php';
        $svc = new StockAvailabilityService($this->db);
        $rows = $svc->getWarehouseWiseStock((int)$product_id, $branch_id, $excludeInvoiceId);

        foreach ($rows as &$row) {
            $avail = (float)($row['available_qty'] ?? 0);
            $row['physical_stock'] = (float)($row['physical_qty'] ?? 0);
            $row['warehouse_name'] = ($row['warehouse_name'] ?? '')
                . ' (Avail: ' . number_format($avail, 2, '.', '') . ')';
        }
        unset($row);

        return $rows;
     }
    public function getDispatchers() { 
        
        $this->db->query("
            SELECT id, name FROM employees 
            WHERE role = 'dispatcher' AND is_active = 1
            ORDER BY name
        ");
        return $this->db->resultSet();
     }


     public function getFilteredChallans($filters = []) {
        $bindings = [];
        $search = trim($filters['search'] ?? '');
        $where = $this->buildChallanListWhere(
            $filters,
            $bindings,
            $search !== '' ? $search : null
        );

        $sql = self::CHALLAN_LIST_SELECT . self::CHALLAN_LIST_FROM;
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        if (!empty($filters['smart_sort'])) {
            $sql .= " ORDER BY CASE si.status
                WHEN 'draft' THEN 1
                WHEN 'godown_issued' THEN 2
                WHEN 'challan_completed' THEN 3
                ELSE 4 END, si.created_at DESC";
        } else {
            $sql .= " ORDER BY si.created_at DESC";
        }

        $this->db->query($sql);
        foreach ($bindings as $param => $value) {
            $this->db->bind($param, $value);
        }

        return $this->db->resultSet();
    }

    private const CHALLAN_LIST_FROM = "
        FROM sales_invoices si
        JOIN customers c ON si.customer_id = c.id
        JOIN branches b ON si.branch_id = b.id
        JOIN employees e ON si.salesman_id = e.id
    ";

    private const CHALLAN_LIST_SELECT = "
        SELECT
            si.id,
            si.invoice_code,
            si.invoice_date,
            si.total_amount,
            si.status,
            si.godown_issued_at,
            si.challan_completed_at,
            c.shop_name,
            c.customer_name,
            c.mobile,
            c.address,
            b.branch_name,
            e.name AS salesman_name
    ";

    /**
     * Extended status keys for warehouse list filters.
     */
    private function applyChallanStatusFilter(string $status, array &$where, array &$bindings): void
    {
        $status = trim($status);
        if ($status === '' || $status === 'all') {
            return;
        }

        switch ($status) {
            case 'open':
                $where[] = "si.status IN ('draft', 'godown_issued')";
                return;
            case 'needs_godown':
                $where[] = "si.status = 'draft'";
                return;
            case 'needs_challan':
                $where[] = "si.status = 'godown_issued'";
                return;
            default:
                $where[] = "si.status = :status";
                $bindings[':status'] = $status;
        }
    }

    private function buildChallanListWhere(array $filters, array &$bindings, ?string $dtSearch = null): array
    {
        $where = [];
        $where[] = "si.branch_id = :branch_id";
        $bindings[':branch_id'] = self::sessionBranchId();

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

        $searchTerm = trim($dtSearch ?? $filters['search'] ?? '');
        if ($searchTerm !== '') {
            $term = '%' . $searchTerm . '%';
            $where[] = "(
                si.invoice_code LIKE :t1
                OR c.shop_name LIKE :t2
                OR c.customer_name LIKE :t3
                OR c.mobile LIKE :t4
                OR c.address LIKE :t5
                OR e.name LIKE :t6
            )";
            $bindings[':t1'] = $term;
            $bindings[':t2'] = $term;
            $bindings[':t3'] = $term;
            $bindings[':t4'] = $term;
            $bindings[':t5'] = $term;
            $bindings[':t6'] = $term;
        }

        $this->applyChallanStatusFilter((string)($filters['status'] ?? 'all'), $where, $bindings);

        $where[] = "si.is_reversed = 0";
        return $where;
    }

    /**
     * Status counts for the current date/search scope (ignores status filter).
     */
    public function getChallanFilterSummary(array $filters): array
    {
        $summaryFilters = $filters;
        unset($summaryFilters['status'], $summaryFilters['smart_sort']);

        $bindings = [];
        $where = $this->buildChallanListWhere(
            array_merge($summaryFilters, ['status' => 'all']),
            $bindings,
            trim($filters['search'] ?? '') !== '' ? trim($filters['search']) : null
        );
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $this->db->query("
            SELECT si.status, COUNT(*) AS cnt
            " . self::CHALLAN_LIST_FROM . $whereSql . "
            GROUP BY si.status
        ");
        foreach ($bindings as $param => $value) {
            $this->db->bind($param, $value);
        }
        $rows = $this->db->resultSet();

        $byStatus = [
            'draft'             => 0,
            'godown_issued'     => 0,
            'challan_completed' => 0,
        ];
        $total = 0;
        foreach ($rows as $row) {
            $key = $row['status'] ?? '';
            $cnt = (int)($row['cnt'] ?? 0);
            $total += $cnt;
            if (isset($byStatus[$key])) {
                $byStatus[$key] = $cnt;
            }
        }

        return [
            'total'          => $total,
            'draft'          => $byStatus['draft'],
            'godown_issued'  => $byStatus['godown_issued'],
            'challan_completed' => $byStatus['challan_completed'],
            'open'           => $byStatus['draft'] + $byStatus['godown_issued'],
            'needs_godown'   => $byStatus['draft'],
            'needs_challan'  => $byStatus['godown_issued'],
        ];
    }

    public function countChallansScoped(): int
    {
        $bindings = [];
        $where = $this->buildChallanListWhere(['skip_default_today' => true], $bindings, null);
        $sql = "SELECT COUNT(*) AS cnt " . self::CHALLAN_LIST_FROM;
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $this->db->query($sql);
        foreach ($bindings as $param => $value) {
            $this->db->bind($param, $value);
        }
        return (int)($this->db->single()['cnt'] ?? 0);
    }

    public function getChallansDatatable(
        array $filters,
        int $start,
        int $length,
        int $orderColumnIndex,
        string $orderDir,
        string $searchValue = ''
    ): array {
        if (!empty($filters['smart_sort'])) {
            $orderBy = "CASE si.status
                WHEN 'draft' THEN 1
                WHEN 'godown_issued' THEN 2
                WHEN 'challan_completed' THEN 3
                ELSE 4 END, si.created_at";
            $orderDir = 'DESC';
        } else {
            $orderMap = [
                0 => 'si.invoice_code',
                1 => 'si.invoice_date',
                2 => 'c.shop_name',
                3 => 'e.name',
                4 => 'si.status',
                5 => 'si.total_amount',
            ];
            $orderBy = $orderMap[$orderColumnIndex] ?? 'si.created_at';
            $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';
        }

        $countBindings = [];
        $countWhere = $this->buildChallanListWhere(
            $filters,
            $countBindings,
            $searchValue !== '' ? $searchValue : null
        );
        $countWhereSql = empty($countWhere) ? '' : ' WHERE ' . implode(' AND ', $countWhere);

        $this->db->query("SELECT COUNT(*) AS cnt " . self::CHALLAN_LIST_FROM . $countWhereSql);
        foreach ($countBindings as $param => $value) {
            $this->db->bind($param, $value);
        }
        $recordsFiltered = (int)($this->db->single()['cnt'] ?? 0);

        $sql = self::CHALLAN_LIST_SELECT . self::CHALLAN_LIST_FROM . $countWhereSql
            . " ORDER BY {$orderBy} {$orderDir} LIMIT :start, :length";
        $this->db->query($sql);
        foreach ($countBindings as $param => $value) {
            $this->db->bind($param, $value);
        }
        $this->db->bind(':start', $start);
        $this->db->bind(':length', $length);
        $rows = $this->db->resultSet();

        return [
            'total'    => $this->countChallansScoped(),
            'filtered' => $recordsFiltered,
            'data'     => $rows,
        ];
    }

    /**
     * Link a posted journal entry to a sales challan (Phase 5 COGS posting).
     */
    public function setChallanJournalEntryId(int $challanId, ?int $journalEntryId): bool
    {
        $this->db->query("UPDATE sales_challans SET journal_entry_id = :jid WHERE id = :id");
        $this->db->bind(':jid', $journalEntryId);
        $this->db->bind(':id', $challanId);
        return $this->db->execute();
    }

    /**
     * Build Telegram alert payload after challan finalize.
     *
     * @return array<string, mixed>|null
     */
    public function getChallanTelegramPayload(
        int $challanId,
        int $invoiceId,
        string $challanCode,
        ?float $totalAmount = null
    ): ?array {
        if ($invoiceId <= 0) {
            return null;
        }

        $this->db->query("
            SELECT
                si.id AS invoice_id,
                si.invoice_code,
                si.total_amount,
                si.salesman_id,
                si.sales_person,
                COALESCE(NULLIF(TRIM(c.shop_name), ''), NULLIF(TRIM(c.customer_name), ''), 'Customer') AS customer_name,
                b.id AS branch_id,
                b.branch_name
            FROM sales_invoices si
            JOIN customers c ON c.id = si.customer_id
            JOIN branches b ON b.id = si.branch_id
            WHERE si.id = :iid AND si.is_reversed = 0
            LIMIT 1
        ");
        $this->db->bind(':iid', $invoiceId, PDO::PARAM_INT);
        $invoice = $this->db->single();
        if (!$invoice) {
            return null;
        }

        $this->db->query("
            SELECT COUNT(*) AS c
            FROM sales_invoice_items
            WHERE sales_invoice_id = :iid
              AND COALESCE(dispatched_qty, ordered_qty, 0) > 0
        ");
        $this->db->bind(':iid', $invoiceId, PDO::PARAM_INT);
        $itemCount = (int)($this->db->single()['c'] ?? 0);

        $warehouseLabel = '—';
        if ($challanId > 0) {
            $this->db->query("
                SELECT GROUP_CONCAT(DISTINCT w.warehouse_name ORDER BY w.warehouse_name SEPARATOR ', ') AS names
                FROM sales_challan_items sci
                JOIN warehouses w ON w.id = sci.warehouse_id
                WHERE sci.sales_challan_id = :cid
            ");
            $this->db->bind(':cid', $challanId, PDO::PARAM_INT);
            $names = trim((string)($this->db->single()['names'] ?? ''));
            if ($names !== '') {
                $warehouseLabel = $names;
            }
        }

        if ($warehouseLabel === '—') {
            $this->db->query("
                SELECT GROUP_CONCAT(DISTINCT w.warehouse_name ORDER BY w.warehouse_name SEPARATOR ', ') AS names
                FROM sales_invoice_dispatches sid
                JOIN warehouses w ON w.id = sid.warehouse_id
                WHERE sid.sales_invoice_id = :iid
            ");
            $this->db->bind(':iid', $invoiceId, PDO::PARAM_INT);
            $names = trim((string)($this->db->single()['names'] ?? ''));
            if ($names !== '') {
                $warehouseLabel = $names;
            }
        }

        $publicBase = defined('PUBLIC_URL') ? rtrim(PUBLIC_URL, '/') . '/' : rtrim(BASE_URL, '/') . '/';
        $resolvedTotal = $totalAmount ?? (float)($invoice['total_amount'] ?? 0);

        return [
            'challan_id'      => $challanId,
            'challan_code'    => $challanCode,
            'invoice_id'      => $invoiceId,
            'invoice_code'    => (string)($invoice['invoice_code'] ?? ''),
            'customer_name'   => (string)($invoice['customer_name'] ?? ''),
            'item_count'      => $itemCount,
            'total_amount'    => $resolvedTotal,
            'branch_id'       => (int)($invoice['branch_id'] ?? 0),
            'branch_name'     => (string)($invoice['branch_name'] ?? ''),
            'warehouse_label' => $warehouseLabel,
            'formatted_at'    => date('d M Y, h:i A'),
            'view_url'        => $publicBase . 'Challan/challan_copy/' . $invoiceId,
        ];
    }

    /**
     * Challan detail for GL audit surface (Phase 5A).
     *
     * @return array<string, mixed>|null
     */
    public function getChallanForDetail(int $challanId): ?array
    {
        if ($challanId <= 0) {
            return null;
        }

        $this->db->query("
            SELECT sc.*,
                   si.id AS invoice_id,
                   si.invoice_code,
                   si.branch_id,
                   si.status AS invoice_status,
                   c.shop_name,
                   c.customer_name,
                   b.branch_name
            FROM sales_challans sc
            INNER JOIN sales_invoices si ON si.id = sc.sales_invoice_id
            INNER JOIN customers c ON c.id = si.customer_id
            INNER JOIN branches b ON b.id = si.branch_id
            WHERE sc.id = :id
            LIMIT 1
        ");
        $this->db->bind(':id', $challanId, PDO::PARAM_INT);
        $row = $this->db->single();
        if (!$row) {
            return null;
        }

        try {
            $this->assertInvoiceAccessible((int)$row['branch_id']);
        } catch (Exception $e) {
            return null;
        }

        return $row;
    }

}
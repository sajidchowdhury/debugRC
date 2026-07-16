<?php
// app/models/SalesReturnModel.php

require_once '../core/Database.php';
require_once __DIR__ . '/../services/Stock/StockService.php';
require_once __DIR__ . '/../helpers/Helper.php';

class SalesReturnModel extends Helper {

    protected $db;
    protected StockService $stock;
    protected $lastError = '';

    /** Returns only after delivery challan is completed. */
    private const RETURNABLE_INVOICE_STATUS = 'challan_completed';

    public function __construct() {
        parent::__construct();
        $this->stock = new StockService($this->db);
    }

    /**
     * Sales returns are always scoped to the logged-in user's session branch.
     */
    public function assertInvoiceAccessible(int $invoiceBranchId): void
    {
        if ((int)$invoiceBranchId !== self::sessionBranchId()) {
            throw new Exception('You do not have access to invoices from another branch.');
        }
    }

    /**
     * Warehouse stock breakdown for receive (SSOT — same as sales modal).
     *
     * @return list<array{id:int,warehouse_name:string,physical_qty:float,pipeline_qty:float,available_qty:float}>
     */
    public function getWarehouseStockForReceive(int $productId, ?int $branchId = null): array
    {
        if ($productId <= 0) {
            return [];
        }
        $branchId = $branchId > 0 ? $branchId : self::sessionBranchId();
        require_once __DIR__ . '/../services/Stock/StockAvailabilityService.php';
        $svc = new StockAvailabilityService($this->db);

        return $svc->getWarehouseWiseStock($productId, $branchId);
    }


    // Get all returns for index page
    public function getAllReturns() {
        $this->db->query("
            SELECT sr.*, si.invoice_code, c.shop_name, u.username as created_by_name
            FROM sales_returns sr
            JOIN sales_invoices si ON sr.sales_invoice_id = si.id
            JOIN customers c ON sr.customer_id = c.id
            LEFT JOIN users u ON sr.created_by = u.id
            ORDER BY sr.id DESC
        ");
        return $this->db->resultSet();
    }

    // Get pending returns for warehouse confirmation
    public function getPendingReturns() {
        $branchId = self::sessionBranchId();
        $this->db->query("
            SELECT sr.*, si.invoice_code, c.shop_name
            FROM sales_returns sr
            JOIN sales_invoices si ON sr.sales_invoice_id = si.id
            JOIN customers c ON sr.customer_id = c.id
            WHERE sr.status = 'pending'
              AND si.branch_id = :branch_id
              AND si.is_reversed = 0
            ORDER BY sr.id DESC
        ");
        $this->db->bind(':branch_id', $branchId);
        return $this->db->resultSet();
    }

    public function searchInvoices($term) {
        $branchId = self::sessionBranchId();
        $this->db->query("
            SELECT 
                si.id,
                si.invoice_code,
                si.invoice_date,
                si.total_amount,
                si.customer_id,
                c.shop_name,
                c.mobile
            FROM sales_invoices si
            JOIN customers c ON si.customer_id = c.id
            WHERE (si.invoice_code LIKE :term 
               OR c.shop_name LIKE :term 
               OR c.mobile LIKE :term)
              AND si.status = :returnable_status
              AND si.is_reversed = 0
              AND si.branch_id = :branch_id
            ORDER BY si.invoice_date DESC 
            LIMIT 10
        ");
        $this->db->bind(':term', "%$term%");
        $this->db->bind(':branch_id', $branchId);
        $this->db->bind(':returnable_status', self::RETURNABLE_INVOICE_STATUS);
        return $this->db->resultSet();
    }

    /**
     * Load invoice for return with clear errors (branch + challan rules).
     *
     * @return array{status:string,message?:string,invoice?:array}
     */
    public function resolveInvoiceForReturn(string $invoiceCode): array
    {
        $invoiceCode = trim($invoiceCode);
        if ($invoiceCode === '') {
            return ['status' => 'error', 'message' => 'Invoice code is required.'];
        }

        $branchId = self::sessionBranchId();
        $this->db->query("
            SELECT si.*, c.shop_name, c.mobile, c.address
            FROM sales_invoices si
            JOIN customers c ON si.customer_id = c.id
            WHERE si.invoice_code = :code
              AND si.is_reversed = 0
        ");
        $this->db->bind(':code', $invoiceCode);
        $row = $this->db->single();

        if (!$row) {
            return ['status' => 'error', 'message' => 'Invoice not found.'];
        }

        if ((int)$row['branch_id'] !== $branchId) {
            return ['status' => 'error', 'message' => 'This invoice belongs to another branch. You can only return invoices from your branch.'];
        }

        $status = (string)($row['status'] ?? '');
        if ($status === 'cancelled' || $status === 'reversed') {
            return ['status' => 'error', 'message' => 'This invoice is cancelled or reversed.'];
        }

        if ($status !== self::RETURNABLE_INVOICE_STATUS) {
            $label = str_replace('_', ' ', $status);
            return [
                'status'  => 'error',
                'message' => 'Return is allowed only after challan is completed. This invoice status: ' . $label . '.',
            ];
        }

        $invoice = $row;
        $this->db->query("
            SELECT sii.*, p.product_name, p.unit,
                   COALESCE(sii.qty - (SELECT COALESCE(SUM(sri.return_qty),0)
                                      FROM sales_return_items sri
                                      JOIN sales_returns sr ON sr.id = sri.sales_return_id
                                      WHERE sri.sales_invoice_item_id = sii.id
                                        AND sr.status != 'reversed'
                                        AND COALESCE(sr.is_reversed, 0) = 0), 0) as returnable_qty
            FROM sales_invoice_items sii
            JOIN products p ON sii.product_id = p.id
            WHERE sii.sales_invoice_id = :invoice_id
        ");
        $this->db->bind(':invoice_id', $invoice['id']);
        $invoice['items'] = $this->db->resultSet();

        $hasReturnable = false;
        foreach ($invoice['items'] as $line) {
            if ((float)($line['returnable_qty'] ?? 0) > 0.0001) {
                $hasReturnable = true;
                break;
            }
        }
        if (!$hasReturnable) {
            return ['status' => 'error', 'message' => 'No returnable quantity left on this invoice.'];
        }

        return ['status' => 'success', 'invoice' => $invoice];
    }

    public function getInvoiceForReturn($invoice_code) {
        $result = $this->resolveInvoiceForReturn($invoice_code);

        return ($result['status'] ?? '') === 'success' ? ($result['invoice'] ?? null) : null;
    }


    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Max returnable qty for an invoice line (excludes other non-reversed returns).
     */
    public function getMaxReturnableQty(int $salesInvoiceItemId): float
    {
        if ($salesInvoiceItemId <= 0) {
            return 0.0;
        }

        $this->db->query("
            SELECT GREATEST(0,
                COALESCE(sii.qty, 0) - COALESCE((
                    SELECT SUM(sri.return_qty)
                    FROM sales_return_items sri
                    JOIN sales_returns sr ON sr.id = sri.sales_return_id
                    WHERE sri.sales_invoice_item_id = sii.id
                      AND sr.status != 'reversed'
                      AND COALESCE(sr.is_reversed, 0) = 0
                ), 0)
            ) AS returnable_qty
            FROM sales_invoice_items sii
            WHERE sii.id = :id
        ");
        $this->db->bind(':id', $salesInvoiceItemId);
        $row = $this->db->single();

        return (float)($row['returnable_qty'] ?? 0);
    }

    // PHASE 1: Create Return (Sales Manager) — ledger credit posts on warehouse confirm (Phase 2)
public function createReturn($data, $items) {
    $this->lastError = '';
    $this->db->beginTransaction();
    try {
        $invoiceId = (int)($data['sales_invoice_id'] ?? 0);
        $this->db->query("SELECT branch_id, status FROM sales_invoices WHERE id = :id AND is_reversed = 0");
        $this->db->bind(':id', $invoiceId);
        $inv = $this->db->single();
        if (!$inv) {
            throw new Exception('Invoice not found.');
        }
        $this->assertInvoiceAccessible((int)$inv['branch_id']);
        if (($inv['status'] ?? '') !== self::RETURNABLE_INVOICE_STATUS) {
            throw new Exception('Return is allowed only after challan is completed for this invoice.');
        }

        $return_code = $this->generateSalesReturnCode();

        $computedTotal = 0.0;
        $validatedItems = [];

        foreach ($items as $item) {
            $return_qty = (float)($item['return_qty'] ?? 0);
            if ($return_qty <= 0) {
                continue;
            }

            $siiId = (int)($item['sales_invoice_item_id'] ?? 0);
            $maxReturnable = $this->getMaxReturnableQty($siiId);

            if ($return_qty > $maxReturnable + 0.0001) {
                throw new Exception(
                    "Return quantity exceeds returnable amount for invoice line #{$siiId}. "
                    . "Max: " . number_format($maxReturnable, 2)
                );
            }

            $rate = (float)($item['rate'] ?? $item['sales_rate'] ?? 0);
            $amount = $return_qty * $rate;
            $computedTotal += $amount;

            $validatedItems[] = [
                'sales_invoice_item_id' => $siiId,
                'product_id'            => (int)($item['product_id'] ?? 0),
                'return_qty'            => $return_qty,
                'rate'                  => $rate,
                'amount'                => $amount,
                'condition'             => $item['condition'] ?? 'Good',
            ];
        }

        if (empty($validatedItems)) {
            throw new Exception('No valid return quantities entered.');
        }

        // Insert main return
        $this->db->query("
            INSERT INTO sales_returns 
            (return_code, sales_invoice_id, customer_id, return_date, 
             total_amount, reason, status, created_by)
            VALUES (:code, :invoice_id, :customer_id, :return_date, 
                    :total_amount, :reason, 'pending', :created_by)
        ");

        $this->db->bind(':code', $return_code);
        $this->db->bind(':invoice_id', $data['sales_invoice_id']);
        $this->db->bind(':customer_id', $data['customer_id']);
        $this->db->bind(':return_date', $data['return_date'] ?? date('Y-m-d'));
        $this->db->bind(':total_amount', $computedTotal);
        $this->db->bind(':reason', $data['reason'] ?? '');
        $this->db->bind(':created_by', $_SESSION['user_id'] ?? 1);
        $this->db->execute();

        $return_id = $this->db->lastInsertId();

        foreach ($validatedItems as $item) {
            $this->db->query("
                INSERT INTO sales_return_items 
                (sales_return_id, sales_invoice_item_id, product_id, 
                 return_qty, rate, amount, `condition`)
                VALUES (:rid, :sii, :pid, :rqty, :rate, :amt, :cond)
            ");

            $this->db->bind(':rid', $return_id);
            $this->db->bind(':sii', $item['sales_invoice_item_id']);
            $this->db->bind(':pid', $item['product_id']);
            $this->db->bind(':rqty', $item['return_qty']);
            $this->db->bind(':rate', $item['rate']);
            $this->db->bind(':amt', $item['amount']);
            $this->db->bind(':cond', $item['condition']);
            $this->db->execute();
        }

        $this->db->commit();
        return $return_id;

    } catch (Exception $e) {
        $this->db->rollback();
        $this->lastError = $e->getMessage();
        error_log("Create Sales Return Error: " . $e->getMessage());
        return false;
    }
}

    public function getReturnSlipData($id) {
        $this->db->query("
            SELECT sr.*, si.invoice_code, si.invoice_date, si.branch_id,
                   c.shop_name, c.customer_name, c.mobile, c.address,
                   b.branch_name, b.address AS branch_address, b.phone AS branch_phone,
                   COALESCE(emp.name, u.username, '—') AS created_by_name,
                   COALESCE(cemp.name, cu.username) AS confirmed_by_name
            FROM sales_returns sr
            JOIN sales_invoices si ON sr.sales_invoice_id = si.id
            JOIN customers c ON sr.customer_id = c.id
            JOIN branches b ON si.branch_id = b.id
            LEFT JOIN users u ON sr.created_by = u.id
            LEFT JOIN employees emp ON emp.id = u.employee_id
            LEFT JOIN users cu ON sr.confirmed_by = cu.id
            LEFT JOIN employees cemp ON cemp.id = cu.employee_id
            WHERE sr.id = :id
        ");
        $this->db->bind(':id', $id);
        $return = $this->db->single();

        if ($return) {
            try {
                $this->assertInvoiceAccessible((int)$return['branch_id']);
            } catch (Exception $e) {
                return false;
            }
            $this->db->query("
                SELECT sri.*, p.product_name, p.unit, p.pcs_per_carton
                FROM sales_return_items sri
                JOIN products p ON sri.product_id = p.id
                WHERE sri.sales_return_id = :return_id
                ORDER BY sri.id
            ");
            $this->db->bind(':return_id', $id);
            $return['items'] = $this->db->resultSet();
            $return['linked_damages'] = $this->getLinkedDamageInvoices($id);
        }

        return $return;
    }

    /**
     * Damage write-offs auto-created from a confirmed sales return (C1 / W5).
     *
     * @return list<array<string, mixed>>
     */
    public function getLinkedDamageInvoices(int $returnId): array
    {
        if ($returnId <= 0) {
            return [];
        }

        $this->db->query("
            SELECT di.id, di.damage_code, di.damage_date, di.total_value,
                   COALESCE(di.is_reversed, 0) AS is_reversed,
                   w.warehouse_name
            FROM damage_invoices di
            LEFT JOIN warehouses w ON w.id = di.warehouse_id
            WHERE di.sales_return_id = :rid
            ORDER BY di.id ASC
        ");
        $this->db->bind(':rid', $returnId);

        return $this->db->resultSet() ?: [];
    }

// Helper Method
private function updateCustomerLedgerForReturn($customer_id, $return_id, $return_code, $amount) {
    $this->db->query("
        SELECT COALESCE(running_balance, 0) AS balance
        FROM customer_ledger
        WHERE customer_id = :cid
        ORDER BY id DESC
        LIMIT 1
    ");
    $this->db->bind(':cid', $customer_id);
    $prev = (float)($this->db->single()['balance'] ?? 0);

    $newBalance = $prev - $amount;

    $branchId = 0;
    $this->db->query("SELECT branch_id FROM sales_invoices WHERE id = (
        SELECT sales_invoice_id FROM sales_returns WHERE id = :rid LIMIT 1
    )");
    $this->db->bind(':rid', $return_id);
    $invRow = $this->db->single();
    $branchId = (int)($invRow['branch_id'] ?? 0);

    $this->insertCustomerLedgerEntry([
        'customer_id'      => $customer_id,
        'reference_type'   => 'sales_return',
        'reference_id'     => $return_id,
        'debit'            => 0,
        'credit'           => $amount,
        'running_balance'  => $newBalance,
        'branch_id'        => $branchId,
        'remarks'          => "Sales Return #{$return_code}",
    ]);
}

public function confirmReturn($return_id, $items) {
    $this->lastError = '';
    $return_id = (int)$return_id;

    if ($return_id <= 0) {
        $this->lastError = 'Invalid Return ID';
        return ['success' => false, 'message' => $this->lastError];
    }

    $this->db->beginTransaction();
    try {
        $this->db->query("
            SELECT * FROM sales_returns
            WHERE id = :id AND status = 'pending' AND COALESCE(is_reversed, 0) = 0
            FOR UPDATE
        ");
        $this->db->bind(':id', $return_id);
        $returnHeader = $this->db->single();

        if (!$returnHeader) {
            throw new Exception('Return not found or already processed.');
        }

        $this->db->query("SELECT branch_id FROM sales_invoices WHERE id = :id");
        $this->db->bind(':id', (int)$returnHeader['sales_invoice_id']);
        $invBranch = $this->db->single();
        $branchId = (int)($invBranch['branch_id'] ?? self::sessionBranchId());
        $this->assertInvoiceAccessible($branchId);

        $cogsTotal = 0.0;
        $damageByWarehouse = [];

        foreach ($items as $item) {
            $return_item_id = (int)$item['return_item_id'];
            $product_id     = (int)$item['product_id'];
            $warehouse_id   = (int)$item['warehouse_id'];
            $rate           = (float)($item['rate'] ?? 0);
            $condition      = $this->normalizeReturnCondition($item['condition'] ?? 'Good');
            $return_qty     = (float)($item['return_qty'] ?? 0);

            if ($warehouse_id <= 0) {
                throw new Exception("Warehouse is required");
            }

            if (!$this->warehouseBelongsToBranch($warehouse_id, $branchId)) {
                throw new Exception('Selected warehouse does not belong to your branch.');
            }

            $this->db->query("
                SELECT id, product_id, return_qty, rate
                FROM sales_return_items
                WHERE id = :item_id AND sales_return_id = :return_id
                FOR UPDATE
            ");
            $this->db->bind(':item_id', $return_item_id);
            $this->db->bind(':return_id', $return_id);
            $lineRow = $this->db->single();

            if (!$lineRow) {
                throw new Exception("Return line #{$return_item_id} not found on this return.");
            }

            if ((int)$lineRow['product_id'] !== $product_id) {
                throw new Exception("Product mismatch on return line #{$return_item_id}.");
            }

            $maxQty = (float)$lineRow['return_qty'];
            if ($return_qty <= 0) {
                throw new Exception("Return quantity must be greater than zero for line #{$return_item_id}.");
            }
            if ($return_qty > $maxQty + 0.0001) {
                throw new Exception(
                    "Confirmed quantity cannot exceed requested return qty ("
                    . number_format($maxQty, 2) . ") on line #{$return_item_id}."
                );
            }

            if ($rate <= 0) {
                $rate = (float)$lineRow['rate'];
            }

            $lineAmount = round($return_qty * $rate, 2);

            $this->db->query("
                UPDATE sales_return_items
                SET warehouse_id = :wid,
                    `condition` = :cond,
                    return_qty = :rqty,
                    rate = :rate,
                    amount = :amt,
                    confirmed_at = NOW()
                WHERE id = :item_id
                  AND sales_return_id = :return_id
            ");
            $this->db->bind(':wid', $warehouse_id);
            $this->db->bind(':cond', $condition);
            $this->db->bind(':rqty', $return_qty);
            $this->db->bind(':rate', $rate);
            $this->db->bind(':amt', $lineAmount);
            $this->db->bind(':item_id', $return_item_id);
            $this->db->bind(':return_id', $return_id);
            $this->db->execute();

            // === Stock Logic (matches persisted condition) ===
            if ($condition === 'Good' && $return_qty > 0) {
                $avgCost = $this->stock->getWarehouseAvgCost($warehouse_id, $product_id);
                if ($avgCost <= 0) {
                    $avgCost = $rate;
                }

                $this->stock->updateWarehouseStock(
                    $warehouse_id,
                    $product_id,
                    $return_qty,
                    $avgCost
                );

                $this->stock->logMovement([
                    'product_id'     => $product_id,
                    'warehouse_id'   => $warehouse_id,
                    'qty'            => $return_qty,
                    'rate'           => $avgCost,
                    'reference_type' => 'sales_return',
                    'reference_id'   => $return_id,
                    'remarks'        => "Sales Return - Good Condition"
                ]);
                $cogsTotal += $return_qty * $avgCost;
            } elseif ($condition === 'Damage' && $return_qty > 0) {
                $avgCost = $this->stock->getWarehouseAvgCost($warehouse_id, $product_id);
                if ($avgCost <= 0) {
                    $avgCost = $rate;
                }

                $this->stock->updateWarehouseStock(
                    $warehouse_id,
                    $product_id,
                    $return_qty,
                    $avgCost
                );

                $this->stock->logMovement([
                    'product_id'     => $product_id,
                    'warehouse_id'   => $warehouse_id,
                    'qty'            => $return_qty,
                    'rate'           => $avgCost,
                    'reference_type' => 'sales_return',
                    'reference_id'   => $return_id,
                    'remarks'        => 'Damaged return received for write-off',
                ]);

                if (!isset($damageByWarehouse[$warehouse_id])) {
                    $damageByWarehouse[$warehouse_id] = [];
                }
                $damageByWarehouse[$warehouse_id][] = [
                    'return_item_id' => $return_item_id,
                    'product_id'     => $product_id,
                    'qty'            => $return_qty,
                    'rate'           => round($avgCost, 2),
                ];
            }
        }

        $damageWriteOffTotal = 0.0;
        $linkedDamageIds = [];
        $linkedDamages = [];
        $returnCodeForDamage = (string)($returnHeader['return_code'] ?? ('SR-' . $return_id));
        $damageDate = (string)($returnHeader['return_date'] ?? date('Y-m-d'));

        foreach ($damageByWarehouse as $warehouseId => $damageLines) {
            $damageResult = $this->createLinkedDamageWriteOff(
                $return_id,
                $returnCodeForDamage,
                (int)$warehouseId,
                $branchId,
                $damageDate,
                $damageLines
            );
            $linkedDamageIds[] = (int)$damageResult['damage_id'];
            $linkedDamages[] = [
                'id'          => (int)$damageResult['damage_id'],
                'damage_code' => (string)$damageResult['damage_code'],
                'total_value' => (float)$damageResult['total_value'],
            ];
            $damageWriteOffTotal += (float)$damageResult['total_value'];
        }

        $this->db->query("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM sales_return_items
            WHERE sales_return_id = :id
        ");
        $this->db->bind(':id', $return_id);
        $confirmedTotal = (float)($this->db->single()['total'] ?? 0);

        if ($confirmedTotal <= 0) {
            throw new Exception('Return total is zero after confirmation.');
        }

        $this->db->query("UPDATE sales_returns SET total_amount = :total WHERE id = :id");
        $this->db->bind(':total', $confirmedTotal);
        $this->db->bind(':id', $return_id);
        $this->db->execute();

        $returnHeader['total_amount'] = $confirmedTotal;

        // Credit note — posted when warehouse confirms (aligned with stock)
        $this->updateCustomerLedgerForReturn(
            (int)$returnHeader['customer_id'],
            $return_id,
            $returnHeader['return_code'],
            $confirmedTotal
        );

        // Update status (only if still pending)
        $this->db->query("
            UPDATE sales_returns 
            SET status = 'completed', 
                confirmed_by = :uid, 
                confirmed_at = NOW() 
            WHERE id = :id AND status = 'pending'
        ");
        $this->db->bind(':uid', $_SESSION['user_id'] ?? 1);
        $this->db->bind(':id', $return_id);
        $this->db->execute();

        if ($this->db->rowCount() === 0) {
            throw new Exception('Return was already processed.');
        }

        require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
        $journalService = new JournalPostingService();
        $journalResult = $journalService->postSalesReturn($return_id, [
            'return_code'    => $returnHeader['return_code'],
            'return_date'    => $returnHeader['return_date'] ?? date('Y-m-d'),
            'customer_id'    => (int)$returnHeader['customer_id'],
            'branch_id'      => $branchId,
            'revenue_amount' => (float)$returnHeader['total_amount'],
            'cogs_amount'    => round($cogsTotal, 2),
        ]);
        if (($journalResult['status'] ?? '') === 'error') {
            throw new Exception('Journal posting failed: ' . ($journalResult['message'] ?? 'unknown'));
        }
        if (!empty($journalResult['journal_entry_id'])) {
            $this->setReturnJournalEntryId($return_id, (int)$journalResult['journal_entry_id']);
        }

        $this->db->commit();
        return [
            'success' => true,
            'journal_entry_id' => $journalResult['journal_entry_id'] ?? null,
            'cogs_amount' => round($cogsTotal, 2),
            'damage_writeoff_total' => round($damageWriteOffTotal, 2),
            'linked_damage_ids' => $linkedDamageIds,
            'linked_damages' => $linkedDamages,
        ];

    } catch (Exception $e) {
        $this->db->rollback();
        $this->lastError = $e->getMessage();
        error_log("ConfirmReturn Error (Return ID: $return_id): " . $e->getMessage());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}


    public function getReturnById($id) {
        $this->db->query("
            SELECT sr.*, si.invoice_code, si.branch_id, c.shop_name, c.mobile, c.address
            FROM sales_returns sr
            JOIN sales_invoices si ON sr.sales_invoice_id = si.id
            JOIN customers c ON sr.customer_id = c.id
            WHERE sr.id = :id
        ");
        $this->db->bind(':id', $id);
        $return = $this->db->single();

        if ($return) {
            try {
                $this->assertInvoiceAccessible((int)$return['branch_id']);
            } catch (Exception $e) {
                return false;
            }
            $this->db->query("
                SELECT sri.*, p.product_name, p.unit
                FROM sales_return_items sri
                JOIN products p ON sri.product_id = p.id
                WHERE sri.sales_return_id = :return_id
            ");
            $this->db->bind(':return_id', $id);
            $return['items'] = $this->db->resultSet();
        }

        return $return;
    }

    /**
     * Data for reverse confirmation screen (branch-scoped).
     */
    public function getReturnForReversal(int $id): ?array
    {
        $return = $this->getReturnById($id);
        if (!$return) {
            return null;
        }

        if (!empty($return['is_reversed'])) {
            return [
                'return'   => $return,
                'reversal' => [
                    'can_reverse'   => false,
                    'block_reason'  => 'This return has already been reversed.',
                ],
            ];
        }

        $existingReversalMoves = $this->stock->getByReference('sales_return_reversal', $id);
        if (!empty($existingReversalMoves)) {
            return [
                'return'   => $return,
                'reversal' => [
                    'can_reverse'   => false,
                    'block_reason'  => 'Stock reversal movements already exist for this return.',
                ],
            ];
        }

        $this->db->query("
            SELECT status, is_reversed
            FROM sales_invoices
            WHERE id = :id
        ");
        $this->db->bind(':id', (int)$return['sales_invoice_id']);
        $invoiceRow = $this->db->single();
        if (!$invoiceRow) {
            return [
                'return'   => $return,
                'reversal' => [
                    'can_reverse'  => false,
                    'block_reason' => 'Linked sales invoice not found.',
                ],
            ];
        }
        $invStatus = (string)($invoiceRow['status'] ?? '');
        if ((int)($invoiceRow['is_reversed'] ?? 0) === 1 || $invStatus === 'reversed') {
            return [
                'return'   => $return,
                'reversal' => [
                    'can_reverse'  => false,
                    'block_reason' => 'The linked invoice is reversed; this return cannot be undone here.',
                ],
            ];
        }

        $returnStatus = (string)($return['status'] ?? 'pending');
        if ($returnStatus !== 'pending' && $returnStatus !== 'completed') {
            return [
                'return'   => $return,
                'reversal' => [
                    'can_reverse'  => false,
                    'block_reason' => 'Return status is invalid for reversal.',
                ],
            ];
        }

        $isCompleted = $returnStatus === 'completed';
        $stockLines = $isCompleted ? $this->buildStockReversalPreview($id) : [];
        $ledgerAmount = $isCompleted ? $this->getReturnLedgerCreditAmount($id) : 0.0;
        $stockBlockReason = $isCompleted ? $this->getStockReversalBlockReason($stockLines) : null;
        $cogsPreview = $isCompleted ? $this->estimateReturnCogsFromStockLog($id) : 0.0;

        foreach ($return['items'] as &$item) {
            if (!empty($item['warehouse_id'])) {
                $this->db->query("SELECT warehouse_name FROM warehouses WHERE id = :id");
                $this->db->bind(':id', (int)$item['warehouse_id']);
                $wh = $this->db->single();
                $item['warehouse_name'] = $wh['warehouse_name'] ?? null;
            }
        }
        unset($item);

        if ($stockBlockReason !== null) {
            return [
                'return'   => $return,
                'reversal' => [
                    'can_reverse'      => false,
                    'block_reason'     => $stockBlockReason,
                    'is_completed'     => $isCompleted,
                    'ledger_amount'    => $ledgerAmount,
                    'cogs_amount'      => $cogsPreview,
                    'stock_lines'      => $stockLines,
                    'stock_line_count' => count($stockLines),
                ],
            ];
        }

        return [
            'return'   => $return,
            'reversal' => [
                'can_reverse'       => true,
                'is_completed'      => $isCompleted,
                'ledger_amount'     => $ledgerAmount,
                'cogs_amount'       => $cogsPreview,
                'stock_lines'       => $stockLines,
                'stock_line_count'  => count($stockLines),
            ],
        ];
    }

    /**
     * Block reversal when warehouse on-hand is less than qty that was added on confirm.
     */
    protected function getStockReversalBlockReason(array $stockLines): ?string
    {
        if ($stockLines === []) {
            return null;
        }

        foreach ($stockLines as $line) {
            $qty = (float)($line['qty'] ?? 0);
            $physical = (float)($line['physical_qty'] ?? 0);
            if ($qty > $physical + 0.0001) {
                $name = (string)($line['product_name'] ?? 'Product');
                $wh = (string)($line['warehouse_name'] ?? 'warehouse');
                return "Insufficient stock in {$wh} for {$name}: need "
                    . number_format($qty, 2)
                    . ' on hand, have '
                    . number_format($physical, 2)
                    . '. Adjust stock or cancel reversal.';
            }
        }

        return null;
    }

    /**
     * COGS amount implied by original sales_return stock IN (for reverse preview).
     */
    protected function estimateReturnCogsFromStockLog(int $returnId): float
    {
        $total = 0.0;
        foreach ($this->stock->getByReference('sales_return', $returnId) as $movement) {
            $qty = (float)($movement['qty'] ?? 0);
            if ($qty <= 0.0001) {
                continue;
            }
            $rate = (float)($movement['rate'] ?? 0);
            if ($rate <= 0) {
                $rate = $this->stock->getWarehouseAvgCost(
                    (int)($movement['warehouse_id'] ?? 0),
                    (int)($movement['product_id'] ?? 0)
                );
            }
            $total += $qty * $rate;
        }

        return round($total, 2);
    }

    protected function getReturnLedgerCreditAmount(int $returnId): float
    {
        $this->db->query("
            SELECT credit
            FROM customer_ledger
            WHERE reference_type = 'sales_return'
              AND reference_id = :rid
              AND COALESCE(is_reversed, 0) = 0
              AND credit > 0
            ORDER BY id DESC
            LIMIT 1
        ");
        $this->db->bind(':rid', $returnId);
        $row = $this->db->single();
        if ($row) {
            return (float)($row['credit'] ?? 0);
        }

        $this->db->query("SELECT total_amount FROM sales_returns WHERE id = :id");
        $this->db->bind(':id', $returnId);

        return (float)($this->db->single()['total_amount'] ?? 0);
    }

    /**
     * Lines that will be removed from stock on reversal (for UI + pre-check).
     */
    protected function buildStockReversalPreview(int $returnId): array
    {
        $lines = [];
        $movements = $this->stock->getByReference('sales_return', $returnId);

        foreach ($movements as $movement) {
            $qty = (float)($movement['qty'] ?? 0);
            if ($qty <= 0.0001) {
                continue;
            }
            $remarks = (string)($movement['remarks'] ?? '');
            if (stripos($remarks, 'Damaged return received') !== false) {
                continue;
            }
            $warehouseId = (int)($movement['warehouse_id'] ?? 0);
            $productId = (int)($movement['product_id'] ?? 0);
            if ($warehouseId <= 0 || $productId <= 0) {
                continue;
            }

            $this->db->query("SELECT product_name FROM products WHERE id = :id");
            $this->db->bind(':id', $productId);
            $productName = (string)($this->db->single()['product_name'] ?? 'Product');

            $this->db->query("SELECT warehouse_name FROM warehouses WHERE id = :id");
            $this->db->bind(':id', $warehouseId);
            $warehouseName = (string)($this->db->single()['warehouse_name'] ?? 'Warehouse');

            $this->db->query("
                SELECT COALESCE(qty, 0) AS qty
                FROM warehouse_stock
                WHERE warehouse_id = :wid AND product_id = :pid
            ");
            $this->db->bind(':wid', $warehouseId);
            $this->db->bind(':pid', $productId);
            $physical = (float)($this->db->single()['qty'] ?? 0);

            $lines[] = [
                'product_id'      => $productId,
                'product_name'    => $productName,
                'warehouse_id'    => $warehouseId,
                'warehouse_name'  => $warehouseName,
                'qty'             => $qty,
                'physical_qty'    => $physical,
            ];
        }

        if ($lines !== []) {
            return $lines;
        }

        $this->db->query("
            SELECT sri.*, p.product_name, w.warehouse_name
            FROM sales_return_items sri
            JOIN products p ON sri.product_id = p.id
            LEFT JOIN warehouses w ON w.id = sri.warehouse_id
            WHERE sri.sales_return_id = :rid
              AND sri.`condition` = 'Good'
              AND sri.confirmed_at IS NOT NULL
              AND COALESCE(sri.warehouse_id, 0) > 0
              AND COALESCE(sri.return_qty, 0) > 0
        ");
        $this->db->bind(':rid', $returnId);
        foreach ($this->db->resultSet() ?: [] as $item) {
            $wid = (int)$item['warehouse_id'];
            $pid = (int)$item['product_id'];
            $qty = (float)$item['return_qty'];
            $this->db->query("
                SELECT COALESCE(qty, 0) AS qty FROM warehouse_stock
                WHERE warehouse_id = :wid AND product_id = :pid
            ");
            $this->db->bind(':wid', $wid);
            $this->db->bind(':pid', $pid);
            $lines[] = [
                'product_id'     => $pid,
                'product_name'   => $item['product_name'] ?? '',
                'warehouse_id'   => $wid,
                'warehouse_name' => $item['warehouse_name'] ?? '',
                'qty'            => $qty,
                'physical_qty'   => (float)($this->db->single()['qty'] ?? 0),
            ];
        }

        return $lines;
    }

// UNDO / REVERSAL - Full Reversal (stock, customer ledger, GL)
public function reverseReturn($return_id, $reason = '') {
    $reason = trim($reason);
    if (strlen($reason) < 5) {
        return ['status' => 'error', 'message' => 'Reversal reason is required (minimum 5 characters).'];
    }

    $return_id = (int)$return_id;
    $this->db->beginTransaction();

    try {
        $this->db->query("
            SELECT * FROM sales_returns
            WHERE id = :id AND COALESCE(is_reversed, 0) = 0
            FOR UPDATE
        ");
        $this->db->bind(':id', $return_id);
        $return = $this->db->single();

        if (!$return) {
            throw new Exception('Return not found or already reversed.');
        }

        $priorStatus = (string)($return['status'] ?? 'pending');
        $wasCompleted = $priorStatus === 'completed';

        $this->db->query("SELECT branch_id FROM sales_invoices WHERE id = :id");
        $this->db->bind(':id', (int)$return['sales_invoice_id']);
        $invBr = $this->db->single();
        $returnBranchId = (int)($invBr['branch_id'] ?? 0);
        $this->assertInvoiceAccessible($returnBranchId);

        if (!empty($this->stock->getByReference('sales_return_reversal', $return_id))) {
            throw new Exception('This return was already reversed (stock log exists).');
        }

        $customer_id = (int)$return['customer_id'];
        $return_code = (string)($return['return_code'] ?? '');
        $journalReversedId = null;
        $stockLinesReversed = 0;

        if ($wasCompleted) {
            $previewLines = $this->buildStockReversalPreview($return_id);
            $stockBlock = $this->getStockReversalBlockReason($previewLines);
            if ($stockBlock !== null) {
                throw new Exception($stockBlock);
            }

            $this->reverseLinkedDamageForReturn($return_id, $return_code, $reason);
            $this->reverseConfirmedReturnStock($return_id, $return_code, $reason);
            $stockLinesReversed = count($this->stock->getByReference('sales_return_reversal', $return_id));

            $ledgerAmount = $this->getReturnLedgerCreditAmount($return_id);
            if ($ledgerAmount > 0.0001) {
                $this->db->query("
                    SELECT COALESCE(running_balance, 0) AS balance
                    FROM customer_ledger
                    WHERE customer_id = :cid
                    ORDER BY id DESC
                    LIMIT 1
                ");
                $this->db->bind(':cid', $customer_id);
                $prevBalance = (float)($this->db->single()['balance'] ?? 0);
                $newBalance = $prevBalance + $ledgerAmount;

                $this->insertCustomerLedgerEntry([
                    'customer_id'      => $customer_id,
                    'reference_type'   => 'reversal',
                    'reference_id'     => $return_id,
                    'debit'            => $ledgerAmount,
                    'credit'           => 0,
                    'running_balance'  => $newBalance,
                    'branch_id'        => $returnBranchId,
                    'remarks'          => "Reversal of Sales Return #{$return_code} — {$reason}",
                    'is_reversed'      => 1,
                ]);

                $this->db->query("
                    UPDATE customer_ledger
                    SET is_reversed = 1
                    WHERE reference_type = 'sales_return'
                      AND reference_id = :rid
                      AND COALESCE(is_reversed, 0) = 0
                ");
                $this->db->bind(':rid', $return_id);
                $this->db->execute();
            }

            require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
            $journalService = new JournalPostingService();
            $journalId = (int)($return['journal_entry_id'] ?? 0);
            if ($journalId <= 0) {
                $journalId = (int)($journalService->findActiveJournalIdByReference('sales_return', $return_id) ?? 0);
            }
            if ($journalId > 0) {
                $rev = $journalService->reverseLinkedJournal($journalId, $reason);
                if (($rev['status'] ?? '') === 'error') {
                    throw new Exception('Failed to reverse return journal: ' . ($rev['message'] ?? ''));
                }
                $journalReversedId = (int)($rev['journal_entry_id'] ?? $journalId);
            }
        }

        $this->db->query("
            UPDATE sales_returns
            SET is_reversed = 1
            WHERE id = :id
        ");
        $this->db->bind(':id', $return_id);
        $this->db->execute();

        $this->db->commit();
        return [
            'status'               => 'success',
            'return_id'            => $return_id,
            'return_code'          => $return_code,
            'total_amount'         => (float)$return['total_amount'],
            'was_completed'        => $wasCompleted,
            'stock_lines_reversed' => $stockLinesReversed,
            'journal_entry_id'     => $journalReversedId,
        ];

    } catch (Exception $e) {
        $this->db->rollback();
        error_log("Sales Return Reversal Error (ID: $return_id): " . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}


    /**
     * Normalize warehouse condition to enum values Good | Damage.
     */
    private function normalizeReturnCondition(string $condition): string
    {
        return strtolower(trim($condition)) === 'damage' ? 'Damage' : 'Good';
    }

    /**
     * Undo stock added on confirm: prefer stock_transactions (actual movements),
     * fall back to confirmed Good lines for legacy returns.
     */
    private function reverseConfirmedReturnStock(int $return_id, string $return_code, string $reason): void
    {
        $movements = $this->stock->getByReference('sales_return', $return_id);
        $reversedFromLog = false;

        foreach ($movements as $movement) {
            $qty = (float)($movement['qty'] ?? 0);
            if ($qty <= 0.0001) {
                continue;
            }

            $warehouse_id = (int)($movement['warehouse_id'] ?? 0);
            $product_id   = (int)($movement['product_id'] ?? 0);
            if ($warehouse_id <= 0 || $product_id <= 0) {
                continue;
            }

            $this->db->query("
                SELECT COALESCE(qty, 0) AS qty
                FROM warehouse_stock
                WHERE warehouse_id = :wid AND product_id = :pid
            ");
            $this->db->bind(':wid', $warehouse_id);
            $this->db->bind(':pid', $product_id);
            $physical = (float)($this->db->single()['qty'] ?? 0);
            if ($qty > $physical + 0.0001) {
                throw new Exception(
                    'Cannot reverse return: insufficient stock in warehouse to remove '
                    . number_format($qty, 2) . ' units (on hand ' . number_format($physical, 2) . ').'
                );
            }

            $avgCost = (float)($movement['rate'] ?? 0);
            if ($avgCost <= 0) {
                $avgCost = $this->stock->getWarehouseAvgCost($warehouse_id, $product_id);
            }

            $this->stock->updateWarehouseStock(
                $warehouse_id,
                $product_id,
                -$qty,
                $avgCost
            );

            $this->stock->logMovement([
                'product_id'     => $product_id,
                'warehouse_id'   => $warehouse_id,
                'qty'            => -$qty,
                'rate'           => $avgCost,
                'reference_type' => 'sales_return_reversal',
                'reference_id'   => $return_id,
                'remarks'        => "Reversal of Sales Return #{$return_code}" . ($reason !== '' ? " — {$reason}" : ''),
            ]);

            $reversedFromLog = true;
        }

        if ($reversedFromLog) {
            return;
        }

        // Legacy: confirmed before stock_transactions had positive qty rows
        $this->db->query("
            SELECT sri.*
            FROM sales_return_items sri
            WHERE sri.sales_return_id = :rid
              AND sri.`condition` = 'Good'
              AND sri.confirmed_at IS NOT NULL
              AND COALESCE(sri.warehouse_id, 0) > 0
              AND COALESCE(sri.return_qty, 0) > 0
        ");
        $this->db->bind(':rid', $return_id);
        $goodItems = $this->db->resultSet();

        foreach ($goodItems as $item) {
            $warehouse_id = (int)$item['warehouse_id'];
            $product_id   = (int)$item['product_id'];
            $return_qty   = (float)$item['return_qty'];

            $this->db->query("
                SELECT COALESCE(qty, 0) AS qty FROM warehouse_stock
                WHERE warehouse_id = :wid AND product_id = :pid
            ");
            $this->db->bind(':wid', $warehouse_id);
            $this->db->bind(':pid', $product_id);
            $physical = (float)($this->db->single()['qty'] ?? 0);
            if ($return_qty > $physical + 0.0001) {
                throw new Exception(
                    'Cannot reverse return: insufficient stock to remove '
                    . number_format($return_qty, 2) . ' units for product #' . $product_id . '.'
                );
            }

            $avgCost = $this->stock->getWarehouseAvgCost($warehouse_id, $product_id);
            if ($avgCost <= 0) {
                $avgCost = (float)$item['rate'];
            }

            $this->stock->updateWarehouseStock(
                $warehouse_id,
                $product_id,
                -$return_qty,
                $avgCost
            );

            $this->stock->logMovement([
                'product_id'     => $product_id,
                'warehouse_id'   => $warehouse_id,
                'qty'            => -$return_qty,
                'rate'           => $avgCost,
                'reference_type' => 'sales_return_reversal',
                'reference_id'   => $return_id,
                'remarks'        => "Reversal of Sales Return #{$return_code} (legacy line)",
            ]);
        }
    }

public function getFilteredReturns($filters = []) {
    $sql = "
        SELECT 
            sr.id,
            sr.return_code,
            sr.return_date,
            sr.total_amount,
            sr.status,
            sr.reason,
            sr.is_reversed,
            si.invoice_code,
            c.shop_name,
            c.mobile,
            b.branch_name,
            u.username as created_by_name
        FROM sales_returns sr
        JOIN sales_invoices si ON sr.sales_invoice_id = si.id
        JOIN customers c ON sr.customer_id = c.id
        JOIN branches b ON si.branch_id = b.id
        LEFT JOIN users u ON sr.created_by = u.id
    ";

    $where = [];
    $bindings = [];

    // === BRANCH FILTER (session branch) ===
    $where[] = "si.branch_id = :branch_id";
    $bindings[':branch_id'] = self::sessionBranchId();

    // === DATE FILTER (Default = Today) ===
    $hasDateFilter = false;

    if (!empty($filters['date_from'])) {
        $where[] = "sr.return_date >= :date_from";
        $bindings[':date_from'] = $filters['date_from'];
        $hasDateFilter = true;
    }
    if (!empty($filters['date_to'])) {
        $where[] = "sr.return_date <= :date_to";
        $bindings[':date_to'] = $filters['date_to'];
        $hasDateFilter = true;
    }

    if (!$hasDateFilter) {
        $where[] = "sr.return_date = CURDATE()";
    }

    // === GLOBAL SEARCH ===
    if (!empty($filters['search'])) {
        $term = '%' . $filters['search'] . '%';
        $where[] = "(
            sr.return_code LIKE :t1 
            OR si.invoice_code LIKE :t2 
            OR c.shop_name LIKE :t3 
            OR c.mobile LIKE :t4
            OR b.branch_name LIKE :t5
            OR u.username LIKE :t6
            OR EXISTS (
                SELECT 1 FROM sales_return_items sri
                JOIN products p ON sri.product_id = p.id
                WHERE sri.sales_return_id = sr.id
                AND (p.product_name LIKE :t7 OR p.product_code LIKE :t8)
            )
        )";
        for ($i = 1; $i <= 8; $i++) {
            $bindings[":t$i"] = $term;
        }
    }

    // === STATUS FILTER ===
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        if ($filters['status'] === 'reversed') {
            $where[] = "sr.status = 'reversed' OR sr.is_reversed = 1";
        } else {
            $where[] = "sr.status = :status";
            $bindings[':status'] = $filters['status'];
        }
    }

    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY sr.id DESC";

    $this->db->query($sql);
    foreach ($bindings as $param => $value) {
        $this->db->bind($param, $value);
    }

    return $this->db->resultSet();
}

    private const RETURN_LIST_FROM = "
        FROM sales_returns sr
        JOIN sales_invoices si ON sr.sales_invoice_id = si.id
        JOIN customers c ON sr.customer_id = c.id
        JOIN branches b ON si.branch_id = b.id
        LEFT JOIN users u ON sr.created_by = u.id
    ";

    private const RETURN_LIST_SELECT = "
        SELECT
            sr.id,
            sr.return_code,
            sr.return_date,
            sr.total_amount,
            sr.status,
            sr.is_reversed,
            si.invoice_code,
            c.shop_name,
            c.customer_name,
            c.mobile,
            b.branch_name,
            u.username AS created_by_name,
            (SELECT COUNT(*)
             FROM damage_invoices di
             WHERE di.sales_return_id = sr.id
               AND COALESCE(di.is_reversed, 0) = 0) AS linked_damage_count,
            (SELECT di.id
             FROM damage_invoices di
             WHERE di.sales_return_id = sr.id
               AND COALESCE(di.is_reversed, 0) = 0
             ORDER BY di.id ASC
             LIMIT 1) AS linked_damage_id
    ";

    private function applyReturnStatusFilter(string $status, array &$where, array &$bindings): void
    {
        $status = trim($status);
        if ($status === '' || $status === 'all') {
            return;
        }

        switch ($status) {
            case 'active':
                $where[] = "sr.status IN ('pending', 'completed') AND COALESCE(sr.is_reversed, 0) = 0";
                return;
            case 'pending':
                $where[] = "sr.status = 'pending' AND COALESCE(sr.is_reversed, 0) = 0";
                return;
            case 'completed':
                $where[] = "sr.status = 'completed' AND COALESCE(sr.is_reversed, 0) = 0";
                return;
            case 'reversed':
                $where[] = "(sr.status = 'reversed' OR sr.is_reversed = 1)";
                return;
        }
    }

    private function buildReturnListWhere(array $filters, array &$bindings, ?string $dtSearch = null): array
    {
        $where = [];
        $where[] = "si.branch_id = :branch_id";
        $bindings[':branch_id'] = self::sessionBranchId();

        $hasDateFilter = false;
        if (!empty($filters['date_from'])) {
            $where[] = "sr.return_date >= :date_from";
            $bindings[':date_from'] = $filters['date_from'];
            $hasDateFilter = true;
        }
        if (!empty($filters['date_to'])) {
            $where[] = "sr.return_date <= :date_to";
            $bindings[':date_to'] = $filters['date_to'];
            $hasDateFilter = true;
        }
        if (!$hasDateFilter && empty($filters['skip_default_today'])) {
            $where[] = "sr.return_date = CURDATE()";
        }

        $searchTerm = trim($dtSearch ?? $filters['search'] ?? '');
        if ($searchTerm !== '') {
            $term = '%' . $searchTerm . '%';
            $where[] = "(
                sr.return_code LIKE :t1
                OR si.invoice_code LIKE :t2
                OR c.shop_name LIKE :t3
                OR c.customer_name LIKE :t4
                OR c.mobile LIKE :t5
                OR b.branch_name LIKE :t6
                OR u.username LIKE :t7
                OR EXISTS (
                    SELECT 1 FROM sales_return_items sri
                    JOIN products p ON sri.product_id = p.id
                    WHERE sri.sales_return_id = sr.id
                    AND (p.product_name LIKE :t8 OR p.product_code LIKE :t9)
                )
            )";
            for ($i = 1; $i <= 9; $i++) {
                $bindings[":t$i"] = $term;
            }
        }

        $this->applyReturnStatusFilter((string)($filters['status'] ?? 'all'), $where, $bindings);

        return $where;
    }

    /**
     * Status counts for return index (ignores status chip filter).
     */
    public function getReturnFilterSummary(array $filters): array
    {
        $summaryFilters = $filters;
        unset($summaryFilters['status'], $summaryFilters['smart_sort']);
        $summaryFilters['status'] = 'all';
        $summaryFilters['skip_default_today'] = true;

        $search = trim($filters['search'] ?? '');
        $bindings = [];
        $where = $this->buildReturnListWhere(
            $summaryFilters,
            $bindings,
            $search !== '' ? $search : null
        );
        $whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

        $this->db->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN sr.status = 'pending' AND COALESCE(sr.is_reversed, 0) = 0 THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN sr.status = 'completed' AND COALESCE(sr.is_reversed, 0) = 0 THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN sr.status = 'reversed' OR sr.is_reversed = 1 THEN 1 ELSE 0 END) AS reversed
            " . self::RETURN_LIST_FROM . $whereSql
        );
        foreach ($bindings as $param => $value) {
            $this->db->bind($param, $value);
        }
        $row = $this->db->single() ?: [];

        $pending = (int)($row['pending'] ?? 0);
        $completed = (int)($row['completed'] ?? 0);
        $reversed = (int)($row['reversed'] ?? 0);

        return [
            'total'     => (int)($row['total'] ?? 0),
            'pending'   => $pending,
            'completed' => $completed,
            'reversed'  => $reversed,
            'active'    => $pending + $completed,
        ];
    }

    public function countReturnsScoped(): int
    {
        $bindings = [];
        $where = $this->buildReturnListWhere(['skip_default_today' => true], $bindings, null);
        $sql = "SELECT COUNT(*) AS cnt " . self::RETURN_LIST_FROM;
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $this->db->query($sql);
        foreach ($bindings as $param => $value) {
            $this->db->bind($param, $value);
        }
        return (int)($this->db->single()['cnt'] ?? 0);
    }

    public function getReturnsDatatable(
        array $filters,
        int $start,
        int $length,
        int $orderColumnIndex,
        string $orderDir,
        string $searchValue = ''
    ): array {
        if (!empty($filters['smart_sort'])) {
            $orderBy = "CASE
                WHEN sr.status = 'pending' AND COALESCE(sr.is_reversed, 0) = 0 THEN 1
                WHEN sr.status = 'completed' AND COALESCE(sr.is_reversed, 0) = 0 THEN 2
                ELSE 3 END, sr.id";
            $orderDir = 'DESC';
        } else {
            $orderMap = [
                0 => 'sr.return_code',
                1 => 'si.invoice_code',
                2 => 'c.shop_name',
                3 => 'b.branch_name',
                4 => 'sr.return_date',
                5 => 'sr.total_amount',
                6 => 'sr.status',
            ];
            $orderBy = $orderMap[$orderColumnIndex] ?? 'sr.id';
            $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';
        }

        $countBindings = [];
        $countWhere = $this->buildReturnListWhere(
            $filters,
            $countBindings,
            $searchValue !== '' ? $searchValue : null
        );
        $countWhereSql = empty($countWhere) ? '' : ' WHERE ' . implode(' AND ', $countWhere);

        $this->db->query("SELECT COUNT(*) AS cnt " . self::RETURN_LIST_FROM . $countWhereSql);
        foreach ($countBindings as $param => $value) {
            $this->db->bind($param, $value);
        }
        $recordsFiltered = (int)($this->db->single()['cnt'] ?? 0);

        $sql = self::RETURN_LIST_SELECT . self::RETURN_LIST_FROM . $countWhereSql
            . " ORDER BY {$orderBy} {$orderDir} LIMIT :start, :length";
        $this->db->query($sql);
        foreach ($countBindings as $param => $value) {
            $this->db->bind($param, $value);
        }
        $this->db->bind(':start', $start);
        $this->db->bind(':length', $length);
        $rows = $this->db->resultSet();
        foreach ($rows as &$row) {
            if (!empty($row['is_reversed'])) {
                $row['status'] = 'reversed';
            }
        }
        unset($row);

        return [
            'total'    => $this->countReturnsScoped(),
            'filtered' => $recordsFiltered,
            'data'     => $rows,
        ];
    }

    /**
     * Link a posted journal entry to a sales return (Phase 5 GL).
     */
    public function setReturnJournalEntryId(int $returnId, ?int $journalEntryId): bool
    {
        $this->db->query("UPDATE sales_returns SET journal_entry_id = :jid WHERE id = :id");
        $this->db->bind(':jid', $journalEntryId);
        $this->db->bind(':id', $returnId);
        return $this->db->execute();
    }

    /**
     * Auto damage write-off for confirmed damaged return lines (same DB transaction).
     *
     * @param list<array{return_item_id:int,product_id:int,qty:float,rate:float}> $lines
     * @return array{damage_id:int,damage_code:string,total_value:float,journal_entry_id:?int}
     */
    private function createLinkedDamageWriteOff(
        int $returnId,
        string $returnCode,
        int $warehouseId,
        int $branchId,
        string $damageDate,
        array $lines
    ): array {
        if ($warehouseId <= 0 || $lines === []) {
            throw new Exception('Invalid damage write-off request.');
        }

        if (!$this->warehouseBelongsToBranch($warehouseId, $branchId)) {
            throw new Exception('Damage warehouse does not belong to this branch.');
        }

        $lineItems = [];
        $totalValue = 0.0;
        foreach ($lines as $line) {
            $productId = (int)($line['product_id'] ?? 0);
            $qty = (float)($line['qty'] ?? 0);
            $rate = round((float)($line['rate'] ?? 0), 2);
            if ($productId <= 0 || $qty <= 0) {
                continue;
            }
            if ($rate <= 0) {
                $rate = round($this->stock->getWarehouseAvgCost($warehouseId, $productId), 2);
            }
            $lineItems[] = [
                'return_item_id' => (int)($line['return_item_id'] ?? 0),
                'product_id'     => $productId,
                'qty'            => $qty,
                'rate'           => $rate,
            ];
            $totalValue += $qty * $rate;
        }

        if ($lineItems === []) {
            throw new Exception('No valid lines for damage write-off.');
        }

        $totalValue = round($totalValue, 2);
        $damageCode = 'DMG-SR-' . $returnId . '-W' . $warehouseId . '-' . date('His');
        $remarks = 'Auto write-off for damaged sales return #' . $returnCode;
        $userId = (int)($_SESSION['user_id'] ?? 1);

        $this->db->query('
            INSERT INTO damage_invoices
            (damage_code, warehouse_id, damage_date, total_value, remarks, sales_return_id, created_by)
            VALUES (:code, :wid, :date, :total, :remarks, :srid, :uid)
        ');
        $this->db->bind(':code', $damageCode);
        $this->db->bind(':wid', $warehouseId);
        $this->db->bind(':date', $damageDate);
        $this->db->bind(':total', $totalValue);
        $this->db->bind(':remarks', $remarks);
        $this->db->bind(':srid', $returnId);
        $this->db->bind(':uid', $userId);
        $this->db->execute();

        $damageId = (int)$this->db->lastInsertId();

        foreach ($lineItems as $line) {
            $this->db->query('
                INSERT INTO damage_invoice_items
                (damage_invoice_id, product_id, qty, rate)
                VALUES (:did, :pid, :qty, :rate)
            ');
            $this->db->bind(':did', $damageId);
            $this->db->bind(':pid', $line['product_id']);
            $this->db->bind(':qty', $line['qty']);
            $this->db->bind(':rate', $line['rate']);
            $this->db->execute();

            $this->stock->updateWarehouseStock(
                $warehouseId,
                $line['product_id'],
                -$line['qty'],
                0
            );

            $this->stock->logMovement([
                'product_id'     => $line['product_id'],
                'warehouse_id'   => $warehouseId,
                'qty'            => -$line['qty'],
                'rate'           => $line['rate'],
                'reference_type' => 'damage',
                'reference_id'   => $damageId,
                'remarks'        => 'Damaged return write-off #' . $returnCode,
            ]);

            if ($line['return_item_id'] > 0) {
                $this->db->query('
                    UPDATE sales_return_items
                    SET damage_invoice_id = :did
                    WHERE id = :id AND sales_return_id = :rid
                ');
                $this->db->bind(':did', $damageId);
                $this->db->bind(':id', $line['return_item_id']);
                $this->db->bind(':rid', $returnId);
                $this->db->execute();
            }
        }

        $journalEntryId = null;
        if ($totalValue >= 0.01) {
            require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
            $journalService = new JournalPostingService();
            $glResult = $journalService->postDamage($damageId, [
                'damage_code'  => $damageCode,
                'damage_date'  => $damageDate,
                'branch_id'    => $branchId,
            ], $totalValue);

            if (($glResult['status'] ?? '') === 'error') {
                throw new Exception('Damage GL posting failed: ' . ($glResult['message'] ?? 'unknown'));
            }

            $journalEntryId = !empty($glResult['journal_entry_id']) ? (int)$glResult['journal_entry_id'] : null;
            if ($journalEntryId) {
                $this->db->query('UPDATE damage_invoices SET journal_entry_id = :jeid WHERE id = :id');
                $this->db->bind(':jeid', $journalEntryId);
                $this->db->bind(':id', $damageId);
                $this->db->execute();
            }
        }

        return [
            'damage_id'         => $damageId,
            'damage_code'       => $damageCode,
            'total_value'       => $totalValue,
            'journal_entry_id'  => $journalEntryId,
        ];
    }

    /**
     * Undo linked auto damage write-offs before reversing return stock receive legs.
     */
    private function reverseLinkedDamageForReturn(int $returnId, string $returnCode, string $reason): void
    {
        $this->db->query('
            SELECT id, damage_code, journal_entry_id
            FROM damage_invoices
            WHERE sales_return_id = :rid
              AND COALESCE(is_reversed, 0) = 0
            FOR UPDATE
        ');
        $this->db->bind(':rid', $returnId);
        $damageRows = $this->db->resultSet() ?: [];

        if ($damageRows === []) {
            return;
        }

        require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
        $journalService = new JournalPostingService();
        $movementReason = 'Reversal of return #' . $returnCode . ' linked damage: ' . $reason;

        foreach ($damageRows as $damage) {
            $damageId = (int)($damage['id'] ?? 0);
            if ($damageId <= 0) {
                continue;
            }

            $movements = $this->stock->getByReference('damage', $damageId);
            $reversed = 0;
            foreach ($movements as $movement) {
                if (!empty($movement['is_reversed'])) {
                    continue;
                }
                $qty = (float)($movement['qty'] ?? 0);
                if (abs($qty) < 0.0001) {
                    continue;
                }
                try {
                    $ok = $this->stock->transactions()->reverseTransaction(
                        (int)$movement['id'],
                        $movementReason
                    );
                } catch (RuntimeException $e) {
                    throw new Exception($e->getMessage());
                }
                if ($ok) {
                    $reversed++;
                }
            }

            if ($reversed === 0 && $movements !== []) {
                throw new Exception('Could not reverse linked damage stock for #' . ($damage['damage_code'] ?? $damageId));
            }

            $journalId = (int)($damage['journal_entry_id'] ?? 0);
            if ($journalId > 0) {
                $rev = $journalService->reverseLinkedJournal($journalId, $movementReason);
                if (($rev['status'] ?? '') === 'error') {
                    throw new Exception('Failed to reverse linked damage GL: ' . ($rev['message'] ?? ''));
                }
            }

            $this->db->query('
                UPDATE damage_invoices
                SET is_reversed = 1,
                    reversed_at = NOW(),
                    reversed_by = :uid,
                    reverse_reason = :reason
                WHERE id = :id
            ');
            $this->db->bind(':uid', (int)($_SESSION['user_id'] ?? 1));
            $this->db->bind(':reason', $reason);
            $this->db->bind(':id', $damageId);
            $this->db->execute();
        }
    }

}
<?php
// app/models/StockTransactionModel.php

require_once __DIR__ . '/../../core/Database.php';

/**
 * StockTransactionModel
 *
 * Costing Method Used: **Moving Average Cost (Weighted Average)**
 * 
 * - Inventory is valued at a continuously updated average cost.
 * - When stock is received: New average = (Old Qty × Old Avg) + (New Qty × New Rate) / Total Qty
 * - When stock is issued/returned: Quantity is reduced at the *current* average cost.
 *   The average cost itself remains unchanged during outflows (standard practice).
 *
 * This method is used across Purchase Receive, Purchase Return, Sales, Adjustments, etc.
 */
class StockTransactionModel {

    protected $db;

    /**
     * @param Database|null $db Shared connection from caller so stock updates
     *                        participate in the same transaction as the parent model.
     */
    public function __construct(?Database $db = null) {
        $this->db = $db ?? new Database();
    }

    public function setDatabase(Database $db): void {
        $this->db = $db;
    }

    /**
     * Log any stock movement (In / Out)
     * 
     * @param array $data {
     *     product_id: int,
     *     warehouse_id: int,
     *     qty: decimal (positive = in, negative = out),
     *     rate: decimal,
     *     reference_type: string (purchase_receive, purchase_return, sales_challan, etc.),
     *     reference_id: int,
     *     remarks: string (optional),
     *     created_by: int (optional)
     * }
     */

   public function adjustStockTake($warehouse_id, $product_id, $adjustment_qty) {
    $this->updateWarehouseStock($warehouse_id, $product_id, $adjustment_qty, 0);
}

 /**
     * Update warehouse stock with Moving Average Costing.
     *
     * Costing Method: Moving Average (Weighted Average)
     * - On stock IN (positive qty): Recalculate avg_cost using weighted average.
     * - On stock OUT (negative qty): Reduce quantity at current avg_cost. 
     *   Avg_cost itself is NOT changed when reducing stock (standard accounting practice).
     *
     * @param int $warehouse_id
     * @param int $product_id
     * @param float $qty   Positive = Increase stock, Negative = Decrease stock
     * @param float $rate  The unit cost of the transaction (used only for increases)
     */
    public function updateWarehouseStock($warehouse_id, $product_id, $qty, $rate = 0) {
        $qty = (float)$qty;
        $rate = (float)$rate;

        if ($qty > 0) {
            // === STOCK IN - Recalculate Moving Average Cost ===
            $this->db->query("
                INSERT INTO warehouse_stock (warehouse_id, product_id, qty, avg_cost)
                VALUES (:wid, :pid, :qty, :rate)
                ON DUPLICATE KEY UPDATE 
                    qty = qty + VALUES(qty),
                    avg_cost = CASE 
                        WHEN (qty + VALUES(qty)) > 0 
                        THEN (qty * avg_cost + VALUES(qty) * VALUES(avg_cost)) / (qty + VALUES(qty))
                        ELSE VALUES(avg_cost) 
                    END
            ");
            $this->db->bind(':wid', $warehouse_id);
            $this->db->bind(':pid', $product_id);
            $this->db->bind(':qty', $qty);
            $this->db->bind(':rate', $rate);
        } else {
            // === STOCK OUT - Reduce at current avg_cost (do not change avg_cost) ===
            $this->db->query("
                SELECT COALESCE(qty, 0) AS qty
                FROM warehouse_stock
                WHERE warehouse_id = :wid AND product_id = :pid
                FOR UPDATE
            ");
            $this->db->bind(':wid', $warehouse_id);
            $this->db->bind(':pid', $product_id);
            $row = $this->db->single();
            $currentQty = (float)($row['qty'] ?? 0);
            $issueQty = abs($qty);

            if ($issueQty > $currentQty + 0.0001) {
                throw new RuntimeException(
                    "Insufficient stock in warehouse. Available: {$currentQty}, Requested: {$issueQty}"
                );
            }

            $this->db->query("
                UPDATE warehouse_stock 
                SET qty = qty + :qty,
                    last_updated = CURRENT_TIMESTAMP
                WHERE warehouse_id = :wid AND product_id = :pid
            ");
            $this->db->bind(':qty', $qty);   // negative value
            $this->db->bind(':wid', $warehouse_id);
            $this->db->bind(':pid', $product_id);
        }

        $this->db->execute();
    }

    /**
     * Current moving average cost for a product in a warehouse.
     */
    public function getWarehouseAvgCost(int $warehouse_id, int $product_id): float
    {
        $this->db->query("
            SELECT COALESCE(avg_cost, 0) AS avg_cost
            FROM warehouse_stock
            WHERE warehouse_id = :wid AND product_id = :pid
        ");
        $this->db->bind(':wid', $warehouse_id);
        $this->db->bind(':pid', $product_id);
        $row = $this->db->single();
        return (float)($row['avg_cost'] ?? 0);
    }



    public function logMovement(array $data): bool
    {
        $demandItemId = !empty($data['branch_demand_item_id']) ? (int)$data['branch_demand_item_id'] : null;

        $this->db->query("
            INSERT INTO stock_transactions 
            (transaction_date, product_id, warehouse_id, qty, rate, 
             reference_type, reference_id, branch_demand_item_id, remarks, created_by)
            VALUES 
            (CURDATE(), :pid, :wid, :qty, :rate, 
             :ref_type, :ref_id, :bdi_id, :remarks, :created_by)
        ");

        $this->db->bind(':pid',       $data['product_id']);
        $this->db->bind(':wid',       $data['warehouse_id']);
        $this->db->bind(':qty',       $data['qty']);           // +ve = in, -ve = out
        $this->db->bind(':rate',      $data['rate']);
        $this->db->bind(':ref_type',  $data['reference_type']);
        $this->db->bind(':ref_id',    $data['reference_id']);
        $this->db->bind(':bdi_id',    $demandItemId);
        $this->db->bind(':remarks',   $data['remarks'] ?? '');
        $this->db->bind(':created_by', $data['created_by'] ?? $_SESSION['user_id'] ?? 1);

        return $this->db->execute();
    }

    /**
     * Reverse a stock movement: restore warehouse qty and append audit reversal row.
     */
    public function reverseTransaction(int $original_transaction_id, string $reason = ''): bool
    {
        $userId = (int)($_SESSION['user_id'] ?? 1);
        $reason = trim($reason);

        $this->db->query('SELECT * FROM stock_transactions WHERE id = :id FOR UPDATE');
        $this->db->bind(':id', $original_transaction_id);
        $original = $this->db->single();

        if (!$original || !empty($original['is_reversed'])) {
            return false;
        }

        $qty = (float)($original['qty'] ?? 0);
        if (abs($qty) < 0.0001) {
            return false;
        }

        $reverseQty   = -$qty;
        $warehouse_id = (int)($original['warehouse_id'] ?? 0);
        $product_id   = (int)($original['product_id'] ?? 0);
        if ($warehouse_id <= 0 || $product_id <= 0) {
            return false;
        }

        $rate = (float)($original['rate'] ?? 0);
        if ($rate <= 0) {
            $rate = $this->getWarehouseAvgCost($warehouse_id, $product_id);
        }

        if ($reverseQty < 0) {
            $this->db->query("
                SELECT COALESCE(qty, 0) AS qty
                FROM warehouse_stock
                WHERE warehouse_id = :wid AND product_id = :pid
                FOR UPDATE
            ");
            $this->db->bind(':wid', $warehouse_id);
            $this->db->bind(':pid', $product_id);
            $onHand = (float)($this->db->single()['qty'] ?? 0);
            $removeQty = abs($reverseQty);
            if ($removeQty > $onHand + 0.0001) {
                throw new RuntimeException(
                    'Cannot reverse: insufficient stock on hand ('
                    . number_format($onHand, 2) . ' available, need '
                    . number_format($removeQty, 2) . ' to undo this movement).'
                );
            }
        }

        $issueRate = $reverseQty > 0 ? $rate : 0.0;
        $this->updateWarehouseStock($warehouse_id, $product_id, $reverseQty, $issueRate);

        $refType = (string)($original['reference_type'] ?? 'movement');
        $this->logMovement([
            'product_id'     => $product_id,
            'warehouse_id'   => $warehouse_id,
            'qty'            => $reverseQty,
            'rate'           => $rate,
            'reference_type' => 'reversal',
            'reference_id'   => (int)($original['reference_id'] ?? 0),
            'remarks'        => "Reversal of {$refType} #{$original['id']}"
                . ($reason !== '' ? " — {$reason}" : ''),
            'created_by'     => $userId,
        ]);

        $this->db->query("
            UPDATE stock_transactions
            SET is_reversed = 1,
                reversed_at = NOW(),
                reversed_by = :uid,
                reverse_reason = :reason
            WHERE id = :id
        ");
        $this->db->bind(':uid', $userId);
        $this->db->bind(':reason', $reason);
        $this->db->bind(':id', $original_transaction_id);
        $this->db->execute();

        return true;
    }

    /**
     * Get stock transactions by reference
     */
    public function getByReference(string $type, int $id)
    {
        $this->db->query("
            SELECT * FROM stock_transactions 
            WHERE reference_type = :type AND reference_id = :id 
            ORDER BY id DESC
        ");
        $this->db->bind(':type', $type);
        $this->db->bind(':id', $id);
        return $this->db->resultSet();
    }
}
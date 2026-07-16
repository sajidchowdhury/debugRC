<?php
// app/models/BranchDemandModel.php

require_once '../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';
require_once __DIR__ . '/../services/Stock/StockService.php';
require_once __DIR__ . '/../services/Branch/BranchIntercompanyService.php';
require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';

class BranchDemandModel extends Helper {

    protected StockService $stock;

    public function __construct() {
        parent::__construct();
        $this->stock = new StockService($this->db);
    }
   


   // ===================== CREATE DEMAND =====================
    public function createDemand($post, $items) {
        $this->db->beginTransaction();
        try {
            $from_branch_id = $_SESSION['branch_id'] ?? 1;
            $user_id = $_SESSION['user_id'] ?? 1;

            $demand_code = "DEM-" . date('Ymd') . "-" . str_pad(rand(1000,9999), 4, '0', STR_PAD_LEFT);

            $this->db->query("
                INSERT INTO branch_demands 
                (demand_code, from_branch_id, to_branch_id, demand_date, status, created_by)
                VALUES (:code, :from_bid, :to_bid, :date, 'pending', :uid)
            ");

            $this->db->bind(':code', $demand_code);
            $this->db->bind(':from_bid', $from_branch_id);
            $this->db->bind(':to_bid', $post['to_branch_id']);
            $this->db->bind(':date', $post['demand_date'] ?? date('Y-m-d'));
            $this->db->bind(':uid', $user_id);
            $this->db->execute();

            $demand_id = $this->db->lastInsertId();

            foreach ($items as $item) {
                if (empty($item['product_id']) || empty($item['qty'])) continue;

                $this->db->query("
                    INSERT INTO branch_demand_items 
                    (branch_demand_id, product_id, qty, cost_rate)
                    VALUES (:did, :pid, :qty, 0)
                ");
                $this->db->bind(':did', $demand_id);
                $this->db->bind(':pid', $item['product_id']);
                $this->db->bind(':qty', $item['qty']);
                $this->db->execute();
            }

            $this->db->commit();
            return ['status' => 'success', 'demand_id' => $demand_id, 'demand_code' => $demand_code];

        } catch (Exception $e) {
            $this->db->rollback();
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function getAllDemands() {
        $this->db->query("
            SELECT bd.*, 
                   fb.branch_name as from_branch,
                   tb.branch_name as to_branch
            FROM branch_demands bd
            JOIN branches fb ON bd.from_branch_id = fb.id
            JOIN branches tb ON bd.to_branch_id = tb.id
            ORDER BY bd.id DESC
        ");
        return $this->db->resultSet();
    }

    public function getOtherBranches() {
        $current = $_SESSION['branch_id'] ?? 1;
        $this->db->query("SELECT id, branch_name, branch_code FROM branches WHERE id != :cid AND is_active = 1");
        $this->db->bind(':cid', $current);
        $this->sendJson($this->db->resultSet());
    }

       

    public function getAllProducts() {
        $this->sendJson($this->Get_All_Active_Product());
    }


        public function WarehouseWiseProductStock($product_id, $branch_id) {
       return  $this->Get_Warehouse_Wise_Product_Stock($product_id, $branch_id);
    }



    public function getDemandById($id) {
        $this->db->query("
            SELECT bd.*, 
                   fb.branch_name as from_branch,
                   tb.branch_name as to_branch
            FROM branch_demands bd
            JOIN branches fb ON bd.from_branch_id = fb.id
            JOIN branches tb ON bd.to_branch_id = tb.id
            WHERE bd.id = :id
        ");
        $this->db->bind(':id', $id);
        return $this->db->single();
    }

 public function getDemandItems($demand_id) {
    $this->db->query("
        SELECT bdi.*, 
               p.product_code, 
               p.product_name,
               COALESCE(bdi.qty, 0) as qty   -- ← Add this
        FROM branch_demand_items bdi
        JOIN products p ON bdi.product_id = p.id
        WHERE bdi.branch_demand_id = :did
    ");
    $this->db->bind(':did', $demand_id);
    return $this->db->resultSet();
}


    protected function sendJson($data) {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

  
    public function rejectDemand($id, $reason) {
        $this->db->query("
            UPDATE branch_demands 
            SET status = 'rejected', 
                reverse_reason = :reason,
                updated_at = NOW()
            WHERE id = :id AND status = 'pending'
        ");
        $this->db->bind(':reason', $reason);
        $this->db->bind(':id', $id);
        $this->db->execute();

        return ['status' => 'success', 'message' => 'Demand Rejected'];
    }

    
public function reverseDemand($id, $reason) {
    $this->db->beginTransaction();
    try {
        $user_id = $_SESSION['user_id'] ?? 1;

        $this->db->query("SELECT * FROM branch_demands WHERE id = :id AND is_reversed = 0");
        $this->db->bind(':id', $id);
        $demand = $this->db->single();

        if (!$demand || $demand['status'] === 'pending') {
            throw new Exception("Only sent/received demands can be reversed");
        }

        // Get items with warehouse info
        $this->db->query("
            SELECT * FROM branch_demand_items 
            WHERE branch_demand_id = :did 
              AND from_warehouse_id IS NOT NULL
        ");
        $this->db->bind(':did', $id);
        $items = $this->db->resultSet();

        foreach ($items as $item) {
            $pid   = (int)$item['product_id'];
            $qty   = (float)$item['qty'];
            $rate  = (float)$item['cost_rate'];
            $fromW = (int)$item['from_warehouse_id'];
            $toW   = (int)$item['to_warehouse_id'];

            // Reverse: Put back to original sender warehouse
            if ($fromW) {
                $this->stock->updateWarehouseStock($fromW, $pid, +$qty, $rate);
                $this->stock->logMovement([
                    'product_id'     => $pid,
                    'warehouse_id'   => $fromW,
                    'qty'            => +$qty,
                    'rate'           => $rate,
                    'reference_type' => 'demand_reversal',
                    'reference_id'   => $id,
                    'remarks'        => "Reversal - Returned to original warehouse"
                ]);
            }

            // Reverse: Take out from receiver warehouse
            if ($toW) {
                $this->stock->updateWarehouseStock($toW, $pid, -$qty, 0);
                $this->stock->logMovement([
                    'product_id'     => $pid,
                    'warehouse_id'   => $toW,
                    'qty'            => -$qty,
                    'rate'           => $rate,
                    'reference_type' => 'demand_reversal',
                    'reference_id'   => $id,
                    'remarks'        => "Reversal - Removed from receiver warehouse"
                ]);
            }
        }

        $intercompany = new BranchIntercompanyService($this->db);
        $intercompany->reverseLedgerByReference('demand_transfer', $id);
        if (!empty($demand['journal_entry_id'])) {
            $journal = new JournalPostingService();
            $journal->reverseLinkedJournal((int)$demand['journal_entry_id'], 'Demand reversal #' . $id);
        }
        if (!empty($demand['journal_entry_id_debtor'])) {
            $journal = new JournalPostingService();
            $journal->reverseLinkedJournal((int)$demand['journal_entry_id_debtor'], 'Demand reversal #' . $id);
        }

        $this->db->query("
            UPDATE branch_demands 
            SET is_reversed = 1, 
                reversed_at = NOW(), 
                reversed_by = :uid, 
                reverse_reason = :reason,
                status = 'reversed'
            WHERE id = :id
        ");
        $this->db->bind(':uid', $user_id);
        $this->db->bind(':reason', $reason);
        $this->db->bind(':id', $id);
        $this->db->execute();

        $this->db->commit();
        return ['status' => 'success', 'message' => 'Demand reversed successfully. Stock restored to original warehouses.'];

    } catch (Exception $e) {
        $this->db->rollback();
        error_log("Reverse Demand Error: " . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

public function deleteDraftDemand($id) {
    $this->db->beginTransaction();
    try {
        $this->db->query("SELECT status FROM branch_demands WHERE id = :id");
        $this->db->bind(':id', $id);
        $demand = $this->db->single();

        if (!$demand || $demand['status'] !== 'pending') {
            throw new Exception("Only pending demands can be deleted");
        }

        // Delete items first
        $this->db->query("DELETE FROM branch_demand_items WHERE branch_demand_id = :id");
        $this->db->bind(':id', $id);
        $this->db->execute();

        // Delete main demand
        $this->db->query("DELETE FROM branch_demands WHERE id = :id");
        $this->db->bind(':id', $id);
        $this->db->execute();

        $this->db->commit();
        return ['status' => 'success', 'message' => 'Draft demand deleted successfully'];

    } catch (Exception $e) {
        $this->db->rollback();
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

        // My Demands
    public function getMyDemands() {
        $bid = $_SESSION['branch_id'] ?? 1;
        $this->db->query("
            SELECT bd.*, fb.branch_name as from_branch, tb.branch_name as to_branch
            FROM branch_demands bd
            JOIN branches fb ON bd.from_branch_id = fb.id
            JOIN branches tb ON bd.to_branch_id = tb.id
            WHERE bd.from_branch_id = :bid
            ORDER BY bd.id DESC
        ");
        $this->db->bind(':bid', $bid);
        return $this->db->resultSet();
    }

    // Pending for Me
    public function getPendingDemandsForMe() {
        $bid = $_SESSION['branch_id'] ?? 1;
        $this->db->query("
            SELECT bd.*, fb.branch_name as from_branch, tb.branch_name as to_branch
            FROM branch_demands bd
            JOIN branches fb ON bd.from_branch_id = fb.id
            JOIN branches tb ON bd.to_branch_id = tb.id
            WHERE bd.to_branch_id = :bid AND bd.status = 'pending' AND bd.is_reversed = 0
            ORDER BY bd.id DESC
        ");
        $this->db->bind(':bid', $bid);
        return $this->db->resultSet();
    }

   

// ===================== SEND GOODS WITH PER-ITEM WAREHOUSE =====================
public function sendGoodsWithWarehouses($demand_id, $items) {
    $this->db->beginTransaction();
    try {
        $demand = $this->getDemandById($demand_id);
        if (!$demand || ($demand['status'] ?? '') !== 'pending') {
            throw new Exception('Demand not found or not pending.');
        }

        $debtorBranchId = (int)$demand['from_branch_id'];
        $creditorBranchId = (int)$demand['to_branch_id'];
        $demandDate = (string)($demand['demand_date'] ?? date('Y-m-d'));
        $total_value = 0.0;
        $transferLines = [];
        $firstFromW = 0;
        $firstToW = 0;
        $availabilityLines = [];

        foreach ($items as $item) {
            $pid         = (int)$item['product_id'];
            $qty         = (float)$item['qty'];
            $fromW       = (int)$item['from_warehouse_id'];
            $toW         = (int)$item['to_warehouse_id'];

            if ($qty <= 0 || $fromW <= 0 || $toW <= 0) {
                throw new Exception("Invalid warehouse or quantity for product ID: $pid");
            }

            $availabilityLines[] = [
                'product_id'   => $pid,
                'warehouse_id' => $fromW,
                'qty'          => $qty,
            ];
        }

        if ($availabilityLines === []) {
            throw new Exception('No valid lines to send.');
        }

        $this->Assert_Warehouse_Lines_Available($availabilityLines);

        foreach ($items as $item) {
            $pid         = (int)$item['product_id'];
            $qty         = (float)$item['qty'];
            $fromW       = (int)$item['from_warehouse_id'];
            $toW         = (int)$item['to_warehouse_id'];

            if ($qty <= 0 || $fromW <= 0 || $toW <= 0) {
                continue;
            }

            $rate = round($this->stock->getWarehouseAvgCost($fromW, $pid), 2);

            if ($rate <= 0) {
                throw new Exception("No warehouse cost for product ID {$pid} in sender warehouse. Receive stock first.");
            }

            $item_value = $qty * $rate;
            $total_value += $item_value;

            // Update branch_demand_items
            $this->db->query("
                UPDATE branch_demand_items 
                SET from_warehouse_id = :fw, 
                    to_warehouse_id   = :tw,
                    cost_rate         = :rate 
                WHERE branch_demand_id = :did 
                  AND product_id = :pid
            ");
            $this->db->bind(':fw', $fromW);
            $this->db->bind(':tw', $toW);
            $this->db->bind(':rate', $rate);
            $this->db->bind(':did', $demand_id);
            $this->db->bind(':pid', $pid);
            $this->db->execute();

            $this->db->query("
                SELECT id FROM branch_demand_items
                WHERE branch_demand_id = :did AND product_id = :pid LIMIT 1
            ");
            $this->db->bind(':did', $demand_id);
            $this->db->bind(':pid', $pid);
            $demandItemRow = $this->db->single();
            $demandItemId = (int)($demandItemRow['id'] ?? 0);

            // === Stock OUT from Sender ===
            $this->stock->updateWarehouseStock($fromW, $pid, -$qty, 0);
            $this->stock->logMovement([
                'product_id'             => $pid,
                'warehouse_id'           => $fromW,
                'qty'                    => -$qty,
                'rate'                   => $rate,
                'reference_type'         => 'demand_send',
                'reference_id'           => $demand_id,
                'branch_demand_item_id'  => $demandItemId > 0 ? $demandItemId : null,
                'remarks'                => "Sent to another branch"
            ]);

            // === Stock IN to Receiver ===
            $this->stock->updateWarehouseStock($toW, $pid, +$qty, $rate);
            $this->stock->logMovement([
                'product_id'             => $pid,
                'warehouse_id'           => $toW,
                'qty'                    => +$qty,
                'rate'                   => $rate,
                'reference_type'         => 'demand_receive',
                'reference_id'           => $demand_id,
                'branch_demand_item_id'  => $demandItemId > 0 ? $demandItemId : null,
                'remarks'                => "Received from another branch"
            ]);

            if ($firstFromW <= 0) {
                $firstFromW = $fromW;
                $firstToW = $toW;
            }
            $transferLines[] = ['product_id' => $pid, 'qty' => $qty, 'rate' => $rate];
        }

        $warehouseTransferId = $this->createWarehouseTransferForDemand(
            $demand_id,
            $firstFromW,
            $firstToW,
            $transferLines,
            $demandDate,
            $total_value
        );

        $intercompany = new BranchIntercompanyService($this->db);
        $journalResult = $intercompany->postDemandFulfillmentJournals(
            $demand_id,
            $debtorBranchId,
            $creditorBranchId,
            $total_value,
            $demandDate
        );
        if (($journalResult['status'] ?? '') === 'error') {
            throw new Exception($journalResult['message'] ?? 'Inter-branch journal posting failed.');
        }

        $intercompany->recordDemandTransfer(
            $demand_id,
            $debtorBranchId,
            $creditorBranchId,
            $total_value,
            $demandDate,
            $journalResult['creditor_journal_id'] ?? null,
            $journalResult['debtor_journal_id'] ?? null
        );

        $this->db->query("
            UPDATE branch_demands 
            SET status      = 'received',
                total_value = :total_value,
                warehouse_transfer_id = :wtid,
                journal_entry_id = :je_cred,
                journal_entry_id_debtor = :je_debt,
                updated_at  = NOW()
            WHERE id = :id
        ");
        $this->db->bind(':total_value', $total_value);
        $this->db->bind(':wtid', $warehouseTransferId);
        $this->db->bind(':je_cred', $journalResult['creditor_journal_id'] ?? null);
        $this->db->bind(':je_debt', $journalResult['debtor_journal_id'] ?? null);
        $this->db->bind(':id', $demand_id);
        $this->db->execute();

        $this->db->commit();
        return ['status' => 'success', 'message' => 'Goods sent successfully. Total Value: ' . number_format($total_value, 2)];

    } catch (Exception $e) {
        $this->db->rollback();
        error_log("Send Goods Error (Demand #$demand_id): " . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}



    /**
     * Documentary warehouse transfer linked to demand (stock already moved via StockService).
     */
    private function createWarehouseTransferForDemand(
        int $demandId,
        int $fromWarehouseId,
        int $toWarehouseId,
        array $lines,
        string $transferDate,
        float $totalAmount
    ): int {
        if ($fromWarehouseId <= 0 || $toWarehouseId <= 0 || $lines === []) {
            return 0;
        }

        $transferCode = 'WT-DEM-' . date('Ymd') . '-' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        $userId = (int)($_SESSION['user_id'] ?? 1);

        $this->db->query("
            INSERT INTO warehouse_transfers
            (transfer_code, transfer_date, from_warehouse_id, to_warehouse_id,
             branch_demand_id, total_amount, created_by, status, approved_by, received_by)
            VALUES
            (:code, :dt, :fw, :tw, :did, :total, :uid, 'received', :uid2, :uid3)
        ");
        $this->db->bind(':code', $transferCode);
        $this->db->bind(':dt', $transferDate);
        $this->db->bind(':fw', $fromWarehouseId);
        $this->db->bind(':tw', $toWarehouseId);
        $this->db->bind(':did', $demandId);
        $this->db->bind(':total', $totalAmount);
        $this->db->bind(':uid', $userId);
        $this->db->bind(':uid2', $userId);
        $this->db->bind(':uid3', $userId);
        $this->db->execute();

        $transferId = (int)$this->db->lastInsertId();

        foreach ($lines as $line) {
            $this->db->query("
                INSERT INTO warehouse_transfer_items
                (warehouse_transfer_id, product_id, qty, rate)
                VALUES (:tid, :pid, :qty, :rate)
            ");
            $this->db->bind(':tid', $transferId);
            $this->db->bind(':pid', $line['product_id']);
            $this->db->bind(':qty', $line['qty']);
            $this->db->bind(':rate', $line['rate']);
            $this->db->execute();
        }

        return $transferId;
    }

 // Helper to get a default warehouse for a branch (fallback)
    public function getDefaultWarehouse($branch_id) {
        return $this->Get_Warehouse_By_Branch($branch_id);
    }


    public function getFilteredDemands($filters = []) {
    $sql = "
        SELECT 
            bd.id,
            bd.demand_code,
            bd.demand_date,
            bd.status,
            bd.is_reversed,
            bd.reverse_reason,
            COALESCE(bd.total_value, 0) AS total_value,
            COALESCE(bd.settlement_amount, 0) AS settlement_amount,
            GREATEST(0, COALESCE(bd.total_value, 0) - COALESCE(bd.settlement_amount, 0)) AS outstanding,
            fb.branch_name as from_branch,
            tb.branch_name as to_branch,
            u.username as created_by_name
        FROM branch_demands bd
        JOIN branches fb ON bd.from_branch_id = fb.id
        JOIN branches tb ON bd.to_branch_id = tb.id
        LEFT JOIN users u ON bd.created_by = u.id
    ";

    $where = [];
    $bindings = [];

    // Branch Filter - Show both my demands and demands to me
    $myBranch = $_SESSION['branch_id'] ?? 1;

    if (!empty($filters['demand_type'])) {
        if ($filters['demand_type'] === 'my_demands') {
            $where[] = "bd.from_branch_id = :my_branch";
            $bindings[':my_branch'] = $myBranch;
        } elseif ($filters['demand_type'] === 'to_me') {
            $where[] = "bd.to_branch_id = :my_branch";
            $bindings[':my_branch'] = $myBranch;
        }
    } else {
        // Default: Show both
        $where[] = "(bd.from_branch_id = :my_branch OR bd.to_branch_id = :my_branch)";
        $bindings[':my_branch'] = $myBranch;
    }

    // Date Filter (Default = Today)
    $hasDateFilter = false;
    if (!empty($filters['date_from'])) {
        $where[] = "bd.demand_date >= :date_from";
        $bindings[':date_from'] = $filters['date_from'];
        $hasDateFilter = true;
    }
    if (!empty($filters['date_to'])) {
        $where[] = "bd.demand_date <= :date_to";
        $bindings[':date_to'] = $filters['date_to'];
        $hasDateFilter = true;
    }
    if (!$hasDateFilter) {
        $where[] = "bd.demand_date = CURDATE()";
    }

    // Status Filter
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $where[] = "bd.status = :status";
        $bindings[':status'] = $filters['status'];
    }

    if (!empty($where)) {
        $sql .= " WHERE " . implode(" AND ", $where);
    }

    $sql .= " ORDER BY bd.demand_date DESC, bd.id DESC";

    $this->db->query($sql);
    foreach ($bindings as $param => $value) {
        $this->db->bind($param, $value);
    }

    return $this->db->resultSet();
}

    /**
     * Settlement allocations for a demand (bank payments FIFO + inter-branch money transfers).
     *
     * @return list<array<string, mixed>>
     */
    public function getSettlementsForDemand(int $demandId): array
    {
        $this->db->query("
            SELECT
                'customer_payment' AS source_type,
                cps.created_at AS allocated_at,
                cp.payment_date AS transaction_date,
                cp.payment_code AS reference_code,
                cp.id AS reference_id,
                cps.settled_amount AS amount,
                cp.payment_mode AS channel,
                cp.amount AS source_total,
                COALESCE(c.customer_name, CONCAT('Customer #', cp.customer_id)) AS counterparty_label,
                COALESCE(cp.is_reversed, 0) AS is_reversed
            FROM customer_payment_settlements cps
            INNER JOIN customer_payments cp ON cp.id = cps.payment_id
            LEFT JOIN customers c ON c.id = cp.customer_id
            WHERE cps.demand_id = :did

            UNION ALL

            SELECT
                'money_transfer' AS source_type,
                mts.created_at AS allocated_at,
                mt.transfer_date AS transaction_date,
                mt.transfer_code AS reference_code,
                mt.id AS reference_id,
                mts.settled_amount AS amount,
                mt.transfer_type AS channel,
                mt.amount AS source_total,
                CONCAT(fb.branch_name, ' → ', tb.branch_name) AS counterparty_label,
                COALESCE(mt.is_reversed, 0) AS is_reversed
            FROM money_transfer_settlements mts
            INNER JOIN money_transfers mt ON mt.id = mts.transfer_id
            INNER JOIN branches fb ON fb.id = mt.from_branch_id
            INNER JOIN branches tb ON tb.id = mt.to_branch_id
            WHERE mts.demand_id = :did2

            ORDER BY transaction_date DESC, allocated_at DESC
        ");
        $this->db->bind(':did', $demandId);
        $this->db->bind(':did2', $demandId);

        return $this->db->resultSet();
    }

    public function getStockTraceForDemand(int $demandId): array
    {
        $this->db->query("
            SELECT st.*, p.product_code, p.product_name, w.warehouse_name
            FROM stock_transactions st
            JOIN products p ON p.id = st.product_id
            JOIN warehouses w ON w.id = st.warehouse_id
            WHERE st.reference_id = :did
              AND st.reference_type IN ('demand_send', 'demand_receive', 'demand_reversal')
              AND COALESCE(st.is_reversed, 0) = 0
            ORDER BY st.id ASC
        ");
        $this->db->bind(':did', $demandId);

        return $this->db->resultSet();
    }
}
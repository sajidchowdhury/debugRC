<?php
// app/models/StockAdjustmentModel.php

require_once '../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';
require_once 'StockTransactionModel.php';
require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
require_once __DIR__ . '/JournalEntryModel.php';

class StockAdjustmentModel extends Helper {

    protected StockTransactionModel $stockTransaction;
    protected JournalPostingService $journalPosting;

    public function __construct() {
        parent::__construct();
        $this->stockTransaction = new StockTransactionModel($this->db);
        $this->journalPosting   = new JournalPostingService();
    }

    /**
     * Warehouses visible on create/filter (branch-scoped unless admin).
     */
    public function getWarehousesForUser(): array
    {
        if ($this->canOverrideBranch()) {
            return $this->Get_All_Active_Warehouses() ?: [];
        }

        return $this->Get_Warehouse_By_Branch(self::sessionBranchId()) ?: [];
    }

    public function getProductRateForWarehouse(int $warehouseId, int $productId): float
    {
        if ($warehouseId <= 0 || $productId <= 0) {
            return 0.0;
        }

        return round($this->stockTransaction->getWarehouseAvgCost($warehouseId, $productId), 2);
    }

    public function createAdjustment($post, $items): array
    {
        $this->db->beginTransaction();
        try {
            $warehouseId = (int)($post['warehouse_id'] ?? 0);
            $adjustmentType = (string)($post['adjustment_type'] ?? '');
            $branchId = self::sessionBranchId();

            if ($warehouseId <= 0) {
                throw new Exception('Warehouse is required');
            }
            if (!in_array($adjustmentType, ['increase', 'decrease'], true)) {
                throw new Exception('Invalid adjustment type');
            }
            if (!$this->canOverrideBranch() && !$this->warehouseBelongsToBranch($warehouseId, $branchId)) {
                throw new Exception('Warehouse does not belong to your branch');
            }

            if (!is_array($items) || count($items) === 0) {
                throw new Exception('Add at least one item');
            }

            $adjustment_code = 'ADJ-' . date('Ymd') . '-' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $sign = ($adjustmentType === 'increase') ? 1 : -1;
            $totalAmount = 0.0;
            $lineItems = [];

            foreach ($items as $item) {
                $qty = (float)($item['qty'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $productId = (int)($item['product_id'] ?? 0);
                if ($productId <= 0) {
                    continue;
                }

                $rate = (float)($item['rate'] ?? 0);
                if ($rate <= 0) {
                    $rate = $this->getProductRateForWarehouse($warehouseId, $productId);
                }

                $lineItems[] = [
                    'product_id' => $productId,
                    'qty'        => $qty,
                    'rate'       => $rate,
                    'reason'     => trim((string)($item['reason'] ?? '')),
                ];
                $totalAmount += $qty * $rate;
            }

            if (count($lineItems) === 0) {
                throw new Exception('Add at least one valid item');
            }

            if ($adjustmentType === 'decrease') {
                $this->Assert_Warehouse_Lines_Available(array_map(static function (array $item) use ($warehouseId): array {
                    return [
                        'product_id'   => (int)$item['product_id'],
                        'warehouse_id' => $warehouseId,
                        'qty'          => (float)$item['qty'],
                    ];
                }, $lineItems));
            }

            $this->db->query("
                INSERT INTO stock_adjustments 
                (adjustment_code, adjustment_date, warehouse_id, adjustment_type, 
                 total_amount, narration, created_by)
                VALUES (:code, :date, :wid, :type, :total, :nar, :uid)
            ");

            $this->db->bind(':code', $adjustment_code);
            $this->db->bind(':date', $post['adjustment_date'] ?? date('Y-m-d'));
            $this->db->bind(':wid', $warehouseId);
            $this->db->bind(':type', $adjustmentType);
            $this->db->bind(':total', round($totalAmount, 2));
            $this->db->bind(':nar', trim((string)($post['narration'] ?? '')));
            $this->db->bind(':uid', $_SESSION['user_id'] ?? 1);

            $this->db->execute();
            $adj_id = (int)$this->db->lastInsertId();

            foreach ($lineItems as $item) {
                $this->db->query("
                    INSERT INTO stock_adjustment_items 
                    (stock_adjustment_id, product_id, qty, rate, reason)
                    VALUES (:aid, :pid, :qty, :rate, :reason)
                ");
                $this->db->bind(':aid', $adj_id);
                $this->db->bind(':pid', $item['product_id']);
                $this->db->bind(':qty', $item['qty']);
                $this->db->bind(':rate', $item['rate']);
                $this->db->bind(':reason', $item['reason']);
                $this->db->execute();

                $qtyChange = $sign * (float)$item['qty'];

                $this->stockTransaction->updateWarehouseStock(
                    $warehouseId,
                    $item['product_id'],
                    $qtyChange,
                    $item['rate']
                );

                $this->stockTransaction->logMovement([
                    'product_id'     => $item['product_id'],
                    'warehouse_id'   => $warehouseId,
                    'qty'            => $qtyChange,
                    'rate'           => $item['rate'],
                    'reference_type' => 'adjustment',
                    'reference_id'   => $adj_id,
                    'remarks'        => 'Stock Adjustment #' . $adjustment_code
                        . ($item['reason'] !== '' ? ' — ' . $item['reason'] : ''),
                ]);
            }

            $totalAmount = round($totalAmount, 2);
            $this->db->query('SELECT branch_id FROM warehouses WHERE id = :wid');
            $this->db->bind(':wid', $warehouseId);
            $whRow = $this->db->single();
            $whBranchId = (int)($whRow['branch_id'] ?? $branchId);

            $lossAmount = $adjustmentType === 'decrease' ? $totalAmount : 0.0;
            $gainAmount = $adjustmentType === 'increase' ? $totalAmount : 0.0;

            $journalResult = $this->journalPosting->postStockAdjustment($adj_id, [
                'adjustment_code'  => $adjustment_code,
                'adjustment_date'  => $post['adjustment_date'] ?? date('Y-m-d'),
                'branch_id'        => $whBranchId,
            ], $lossAmount, $gainAmount);

            if (($journalResult['status'] ?? '') !== 'success') {
                throw new Exception($journalResult['message'] ?? 'GL posting failed');
            }

            $journalId = !empty($journalResult['journal_entry_id'])
                ? (int)$journalResult['journal_entry_id']
                : null;

            if ($journalId) {
                $this->db->query('UPDATE stock_adjustments SET journal_entry_id = :jeid WHERE id = :id');
                $this->db->bind(':jeid', $journalId);
                $this->db->bind(':id', $adj_id);
                $this->db->execute();
            }

            $this->db->commit();

            $glNote = $journalId
                ? ' GL entry ' . ($journalResult['entry_no'] ?? ('#' . $journalId)) . ' created.'
                : '';

            return [
                'status'            => 'success',
                'adjustment_code'   => $adjustment_code,
                'adjustment_id'     => $adj_id,
                'total_amount'      => $totalAmount,
                'journal_entry_id'  => $journalId,
                'message'           => 'Adjustment saved. Stock updated.' . $glNote,
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Stock Adjustment Error: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function getAllAdjustments(): array
    {
        $this->db->query("
            SELECT sa.*, w.warehouse_name, u.username as created_by_name
            FROM stock_adjustments sa
            JOIN warehouses w ON sa.warehouse_id = w.id
            LEFT JOIN users u ON sa.created_by = u.id
            ORDER BY sa.adjustment_date DESC
        ");

        return $this->db->resultSet() ?: [];
    }

    public function getAdjustmentById(int $id): ?array
    {
        $this->db->query("
            SELECT sa.*, w.warehouse_name, w.branch_id, b.branch_name,
                   u.username as created_by_name
            FROM stock_adjustments sa
            JOIN warehouses w ON sa.warehouse_id = w.id
            JOIN branches b ON b.id = w.branch_id
            LEFT JOIN users u ON sa.created_by = u.id
            WHERE sa.id = :id
        ");
        $this->db->bind(':id', $id);
        $row = $this->db->single();

        return $row ?: null;
    }

    public function getAdjustmentItems(int $adj_id): array
    {
        $this->db->query("
            SELECT sai.*, p.product_code, p.product_name
            FROM stock_adjustment_items sai
            JOIN products p ON sai.product_id = p.id
            WHERE sai.stock_adjustment_id = :aid
        ");
        $this->db->bind(':aid', $adj_id);

        return $this->db->resultSet() ?: [];
    }

    public function getAdjustmentMovements(int $adj_id): array
    {
        $this->db->query("
            SELECT st.*, p.product_code, p.product_name
            FROM stock_transactions st
            LEFT JOIN products p ON p.id = st.product_id
            WHERE st.reference_type = 'adjustment' AND st.reference_id = :id
            ORDER BY st.id ASC
        ");
        $this->db->bind(':id', $adj_id);

        return $this->db->resultSet() ?: [];
    }

    public function getJournalEntryForAdjustment(int $adj_id): ?array
    {
        $this->db->query('SELECT journal_entry_id FROM stock_adjustments WHERE id = :id');
        $this->db->bind(':id', $adj_id);
        $row = $this->db->single();
        $jeId = (int)($row['journal_entry_id'] ?? 0);
        if ($jeId <= 0) {
            return null;
        }

        $journal = new JournalEntryModel();

        return $journal->getEntryWithLines($jeId);
    }

    public function userCanAccessAdjustment(array $adjustment): bool
    {
        if ($this->canOverrideBranch()) {
            return true;
        }

        return (int)($adjustment['branch_id'] ?? 0) === self::sessionBranchId();
    }

    public function reverseAdjustment(int $id, string $reason): array
    {
        $this->db->beginTransaction();
        try {
            $reason = trim($reason);
            if (strlen($reason) < 3) {
                throw new Exception('Reversal reason is required (min 3 characters)');
            }

            $this->db->query('SELECT * FROM stock_adjustments WHERE id = :id AND COALESCE(is_reversed, 0) = 0');
            $this->db->bind(':id', $id);
            $adj = $this->db->single();

            if (!$adj) {
                throw new Exception('Adjustment not found or already reversed');
            }

            $warehouseId = (int)($adj['warehouse_id'] ?? 0);
            if (!$this->canOverrideBranch() && !$this->warehouseBelongsToBranch($warehouseId, self::sessionBranchId())) {
                throw new Exception('You do not have access to reverse this adjustment');
            }

            $movements = $this->stockTransaction->getByReference('adjustment', $id);
            if (empty($movements)) {
                throw new Exception('No stock movements found for this adjustment');
            }

            $reversed = 0;
            $movementReason = 'Reversal of Adjustment #' . ($adj['adjustment_code'] ?? $id) . ': ' . $reason;

            foreach ($movements as $movement) {
                if (!empty($movement['is_reversed'])) {
                    continue;
                }
                if (abs((float)($movement['qty'] ?? 0)) < 0.0001) {
                    continue;
                }
                try {
                    $ok = $this->stockTransaction->reverseTransaction(
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

            if ($reversed === 0) {
                throw new Exception('No movements could be reversed');
            }

            if (!empty($adj['journal_entry_id'])) {
                $journalRev = $this->journalPosting->reverseLinkedJournal(
                    (int)$adj['journal_entry_id'],
                    'Reversal of Adjustment #' . ($adj['adjustment_code'] ?? $id) . ': ' . $reason
                );
                if (($journalRev['status'] ?? '') !== 'success') {
                    throw new Exception('Failed to reverse GL entry: ' . ($journalRev['message'] ?? ''));
                }
            }

            $this->db->query("
                UPDATE stock_adjustments 
                SET is_reversed = 1, 
                    reversed_at = NOW(), 
                    reversed_by = :uid, 
                    reverse_reason = :reason
                WHERE id = :id
            ");
            $this->db->bind(':uid', $_SESSION['user_id'] ?? 1);
            $this->db->bind(':reason', $reason);
            $this->db->bind(':id', $id);
            $this->db->execute();

            $this->db->commit();

            $glNote = !empty($adj['journal_entry_id']) ? ' Linked GL entry reversed.' : '';

            return [
                'status'  => 'success',
                'message' => "Reversed {$reversed} stock movement(s). Quantities restored via audit trail.{$glNote}",
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Stock Adjustment reverse: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function getFilteredAdjustments(array $filters = []): array
    {
        $sql = "
            SELECT 
                sa.id,
                sa.adjustment_code,
                sa.adjustment_date,
                sa.adjustment_type,
                sa.total_amount,
                sa.journal_entry_id,
                sa.is_reversed,
                sa.reverse_reason,
                sa.reversed_at,
                w.warehouse_name,
                b.branch_name,
                u.username as created_by_name
            FROM stock_adjustments sa
            JOIN warehouses w ON sa.warehouse_id = w.id
            JOIN branches b ON w.branch_id = b.id
            LEFT JOIN users u ON sa.created_by = u.id
        ";

        $where = [];
        $bindings = [];

        if (!$this->canOverrideBranch()) {
            $where[] = 'b.id = :branch_id';
            $bindings[':branch_id'] = self::sessionBranchId();
        }

        $hasDateFilter = false;
        if (!empty($filters['date_from'])) {
            $where[] = 'sa.adjustment_date >= :date_from';
            $bindings[':date_from'] = $filters['date_from'];
            $hasDateFilter = true;
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'sa.adjustment_date <= :date_to';
            $bindings[':date_to'] = $filters['date_to'];
            $hasDateFilter = true;
        }
        if (!$hasDateFilter) {
            $where[] = 'sa.adjustment_date = CURDATE()';
        }

        if (!empty($filters['warehouse_id'])) {
            $where[] = 'sa.warehouse_id = :warehouse_id';
            $bindings[':warehouse_id'] = (int)$filters['warehouse_id'];
        }

        if (!empty($filters['adjustment_type']) && $filters['adjustment_type'] !== 'all') {
            $where[] = 'sa.adjustment_type = :adjustment_type';
            $bindings[':adjustment_type'] = $filters['adjustment_type'];
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            if ($filters['status'] === 'reversed') {
                $where[] = 'sa.is_reversed = 1';
            } else {
                $where[] = 'COALESCE(sa.is_reversed, 0) = 0';
            }
        }

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY sa.adjustment_date DESC, sa.id DESC';

        $this->db->query($sql);
        foreach ($bindings as $param => $value) {
            $this->db->bind($param, $value);
        }

        return $this->db->resultSet() ?: [];
    }
}
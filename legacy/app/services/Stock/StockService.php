<?php
// app/services/Stock/StockService.php — Phase 4 SSOT for warehouse stock moves

require_once __DIR__ . '/../../../core/Database.php';
require_once __DIR__ . '/../../models/StockTransactionModel.php';
require_once __DIR__ . '/StockAvailabilityService.php';

class StockService
{
    protected Database $db;
    protected StockTransactionModel $transactions;
    protected StockAvailabilityService $availability;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? new Database();
        $this->transactions = new StockTransactionModel($this->db);
        $this->availability = new StockAvailabilityService($this->db);
    }

    public function transactions(): StockTransactionModel
    {
        return $this->transactions;
    }

    public function updateWarehouseStock(int $warehouseId, int $productId, float $qty, float $rate = 0): void
    {
        $this->transactions->updateWarehouseStock($warehouseId, $productId, $qty, $rate);
    }

    public function logMovement(array $data): bool
    {
        return $this->transactions->logMovement($data);
    }

    public function getWarehouseAvgCost(int $warehouseId, int $productId): float
    {
        return $this->transactions->getWarehouseAvgCost($warehouseId, $productId);
    }

    /**
     * Row locks for branch warehouse_stock rows (call inside an open transaction).
     */
    public function lockBranchProductsForUpdate(int $branchId, array $productIds): void
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($branchId <= 0 || $productIds === []) {
            return;
        }

        $inList = implode(',', $productIds);
        $this->db->query("
            SELECT ws.warehouse_id, ws.product_id
            FROM warehouse_stock ws
            INNER JOIN warehouses w ON w.id = ws.warehouse_id AND w.branch_id = :bid
            WHERE ws.product_id IN ({$inList})
            FOR UPDATE
        ");
        $this->db->bind(':bid', $branchId);
        $this->db->execute();
        $this->db->resultSet();
    }

    /**
     * @param array<int, float> $qtyByProduct product_id => qty
     * @return string[] error messages
     */
    public function assertBranchProductsAvailable(int $branchId, array $qtyByProduct, ?int $excludeInvoiceId = null): array
    {
        $errors = [];
        foreach ($qtyByProduct as $productId => $requestedQty) {
            $productId = (int)$productId;
            if ($productId <= 0) {
                continue;
            }
            $available = $this->getBranchAvailableQty($productId, $branchId, $excludeInvoiceId);
            if ($requestedQty > $available + 0.0001) {
                $errors[] = "Product ID {$productId}: requested " . number_format((float)$requestedQty, 2)
                    . ', available ' . number_format($available, 2);
            }
        }

        return $errors;
    }

    public function getBranchAvailableQty(int $productId, int $branchId, ?int $excludeInvoiceId = null): float
    {
        return $this->availability->getBranchAvailableQty($productId, $branchId, $excludeInvoiceId);
    }

    public function getWarehouseAvailableQty(int $productId, int $warehouseId, ?int $excludeInvoiceId = null): float
    {
        return $this->availability->getWarehouseAvailableQty($productId, $warehouseId, $excludeInvoiceId);
    }

    /**
     * @param array<int, float> $qtyByProduct product_id => qty (single warehouse)
     * @return string[] error messages
     */
    public function assertWarehouseProductsAvailable(
        int $warehouseId,
        array $qtyByProduct,
        ?int $excludeInvoiceId = null
    ): array {
        $errors = [];
        foreach ($qtyByProduct as $productId => $requestedQty) {
            $productId = (int)$productId;
            if ($productId <= 0) {
                continue;
            }
            $available = $this->getWarehouseAvailableQty($productId, $warehouseId, $excludeInvoiceId);
            if ($requestedQty > $available + 0.0001) {
                $errors[] = "Product ID {$productId}: requested " . number_format((float)$requestedQty, 2)
                    . ', available ' . number_format($available, 2);
            }
        }

        return $errors;
    }

    /**
     * First active warehouse for a branch (legacy fallback; draft dispatches no longer seed this at finalize).
     */
    public function getDefaultWarehouseId(int $branchId): ?int
    {
        if ($branchId <= 0) {
            return null;
        }

        $this->db->query("
            SELECT id FROM warehouses
            WHERE branch_id = :bid AND is_active = 1
            ORDER BY id ASC
            LIMIT 1
        ");
        $this->db->bind(':bid', $branchId);
        $row = $this->db->single();
        $id = (int)($row['id'] ?? 0);

        return $id > 0 ? $id : null;
    }
}
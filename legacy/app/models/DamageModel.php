<?php
// app/models/DamageModel.php — branch-scoped damage write-offs with stock + GL

require_once '../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';
require_once 'StockTransactionModel.php';
require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
require_once __DIR__ . '/JournalEntryModel.php';

class DamageModel extends Helper {

    protected StockTransactionModel $stockTransaction;
    protected JournalPostingService $journalPosting;

    public function __construct() {
        parent::__construct();
        $this->stockTransaction = new StockTransactionModel($this->db);
        $this->journalPosting   = new JournalPostingService();
    }

    public function getWarehousesForUser(): array
    {
        if ($this->canOverrideBranch()) {
            return $this->Get_All_Active_Warehouses() ?: [];
        }

        return $this->Get_Warehouse_By_Branch(self::sessionBranchId()) ?: [];
    }

    public function getAllProducts(): array
    {
        return $this->Get_All_Active_Product() ?: [];
    }

    public function getProductStockAndRate(int $productId, int $warehouseId): array
    {
        $balance = $this->Get_Product_Stock_Balance($productId, null, $warehouseId);

        $rate = $warehouseId > 0 && $productId > 0
            ? round($this->stockTransaction->getWarehouseAvgCost($warehouseId, $productId), 2)
            : 0.0;

        return [
            'available_qty' => (float)($balance['available'] ?? 0),
            'physical_qty'  => (float)($balance['physical'] ?? 0),
            'pipeline_qty'  => (float)($balance['pending_out'] ?? 0),
            'price'         => $rate,
            'rate'          => $rate,
        ];
    }

    public function createDamage(array $post, array $items): array
    {
        $this->db->beginTransaction();
        try {
            $warehouseId = (int)($post['warehouse_id'] ?? 0);
            $branchId    = self::sessionBranchId();

            if ($warehouseId <= 0) {
                throw new Exception('Warehouse is required');
            }
            if (!$this->canOverrideBranch() && !$this->warehouseBelongsToBranch($warehouseId, $branchId)) {
                throw new Exception('Warehouse does not belong to your branch');
            }

            if (!is_array($items) || $items === []) {
                throw new Exception('Add at least one product line');
            }

            $this->db->query('SELECT branch_id FROM warehouses WHERE id = :wid');
            $this->db->bind(':wid', $warehouseId);
            $whRow = $this->db->single();
            $whBranchId = (int)($whRow['branch_id'] ?? $branchId);

            $damageCode = 'DMG-' . date('Ymd') . '-' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $damageDate = $post['damage_date'] ?? date('Y-m-d');
            $userId     = (int)($_SESSION['user_id'] ?? 1);

            $lineItems   = [];
            $totalValue  = 0.0;

            foreach ($items as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $qty       = (float)($item['qty'] ?? 0);
                if ($productId <= 0 || $qty <= 0) {
                    continue;
                }

                $rate = (float)($item['rate'] ?? 0);
                if ($rate <= 0) {
                    $rate = round($this->stockTransaction->getWarehouseAvgCost($warehouseId, $productId), 2);
                }

                $lineItems[] = [
                    'product_id' => $productId,
                    'qty'        => $qty,
                    'rate'       => $rate,
                ];
                $totalValue += $qty * $rate;
            }

            if ($lineItems === []) {
                throw new Exception('Add at least one valid product line');
            }

            $this->Assert_Warehouse_Lines_Available(array_map(static function (array $line) use ($warehouseId): array {
                return [
                    'product_id'   => (int)$line['product_id'],
                    'warehouse_id' => $warehouseId,
                    'qty'          => (float)$line['qty'],
                ];
            }, $lineItems));

            $totalValue = round($totalValue, 2);

            $this->db->query('
                INSERT INTO damage_invoices
                (damage_code, warehouse_id, damage_date, total_value, remarks, created_by)
                VALUES (:code, :wid, :date, :total, :remarks, :uid)
            ');
            $this->db->bind(':code', $damageCode);
            $this->db->bind(':wid', $warehouseId);
            $this->db->bind(':date', $damageDate);
            $this->db->bind(':total', $totalValue);
            $this->db->bind(':remarks', trim((string)($post['remarks'] ?? '')));
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

                $this->stockTransaction->updateWarehouseStock(
                    $warehouseId,
                    $line['product_id'],
                    -$line['qty'],
                    0
                );

                $this->stockTransaction->logMovement([
                    'product_id'     => $line['product_id'],
                    'warehouse_id'   => $warehouseId,
                    'qty'            => -$line['qty'],
                    'rate'           => $line['rate'],
                    'reference_type' => 'damage',
                    'reference_id'   => $damageId,
                    'remarks'        => 'Damage #' . $damageCode,
                ]);
            }

            $glNote = '';
            if ($totalValue >= 0.01) {
                $glResult = $this->journalPosting->postDamage($damageId, [
                    'damage_code'  => $damageCode,
                    'damage_date'  => $damageDate,
                    'branch_id'    => $whBranchId,
                ], $totalValue);

                if (($glResult['status'] ?? '') !== 'success') {
                    throw new Exception($glResult['message'] ?? 'GL posting failed');
                }

                $journalId = !empty($glResult['journal_entry_id']) ? (int)$glResult['journal_entry_id'] : null;
                if ($journalId) {
                    $this->db->query('UPDATE damage_invoices SET journal_entry_id = :jeid WHERE id = :id');
                    $this->db->bind(':jeid', $journalId);
                    $this->db->bind(':id', $damageId);
                    $this->db->execute();
                    $glNote = ' GL posted (Dr shrinkage / Cr inventory).';
                }
            }

            $this->db->commit();

            return [
                'status'       => 'success',
                'damage_id'    => $damageId,
                'damage_code'  => $damageCode,
                'total_value'  => $totalValue,
                'message'      => 'Damage recorded.' . $glNote,
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Damage create: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function getDamageById(int $id): ?array
    {
        $this->db->query('
            SELECT di.*,
                   w.warehouse_name,
                   w.branch_id,
                   b.branch_name,
                   u.username AS created_by_name,
                   ru.username AS reversed_by_name,
                   sr.return_code AS sales_return_code
            FROM damage_invoices di
            JOIN warehouses w ON di.warehouse_id = w.id
            JOIN branches b ON w.branch_id = b.id
            LEFT JOIN users u ON di.created_by = u.id
            LEFT JOIN users ru ON di.reversed_by = ru.id
            LEFT JOIN sales_returns sr ON sr.id = di.sales_return_id
            WHERE di.id = :id
        ');
        $this->db->bind(':id', $id);
        $row = $this->db->single();

        return $row ?: null;
    }

    public function userCanAccessDamage(?array $damage): bool
    {
        if (!$damage) {
            return false;
        }
        if ($this->canOverrideBranch()) {
            return true;
        }

        return (int)($damage['branch_id'] ?? 0) === self::sessionBranchId();
    }

    public function canUserReverseDamage(?array $damage): bool
    {
        return $damage
            && empty($damage['is_reversed'])
            && $this->userCanAccessDamage($damage);
    }

    public function getDamageItems(int $damageId): array
    {
        $this->db->query('
            SELECT dii.*, p.product_code, p.product_name
            FROM damage_invoice_items dii
            JOIN products p ON dii.product_id = p.id
            WHERE dii.damage_invoice_id = :did
            ORDER BY p.product_code
        ');
        $this->db->bind(':did', $damageId);

        return $this->db->resultSet() ?: [];
    }

    public function getDamageMovements(int $damageId): array
    {
        $this->db->query('
            SELECT st.*, p.product_code, p.product_name, w.warehouse_name
            FROM stock_transactions st
            LEFT JOIN products p ON p.id = st.product_id
            LEFT JOIN warehouses w ON w.id = st.warehouse_id
            WHERE st.reference_type = :type AND st.reference_id = :id
            ORDER BY st.id ASC
        ');
        $this->db->bind(':type', 'damage');
        $this->db->bind(':id', $damageId);

        return $this->db->resultSet() ?: [];
    }

    public function getJournalEntryForDamage(int $damageId): ?array
    {
        $this->db->query('SELECT journal_entry_id FROM damage_invoices WHERE id = :id');
        $this->db->bind(':id', $damageId);
        $row = $this->db->single();
        $jeId = (int)($row['journal_entry_id'] ?? 0);
        if ($jeId <= 0) {
            return null;
        }

        return (new JournalEntryModel())->getEntryWithLines($jeId);
    }

    public function reverseDamage(int $id, string $reason): array
    {
        $this->db->beginTransaction();
        try {
            $reason = trim($reason);
            if (strlen($reason) < 3) {
                throw new Exception('Reversal reason is required (min 3 characters)');
            }

            $damage = $this->getDamageById($id);
            if (!$damage || !empty($damage['is_reversed'])) {
                throw new Exception('Damage record not found or already reversed');
            }

            if (!$this->canUserReverseDamage($damage)) {
                throw new Exception('You do not have access to reverse this damage');
            }

            $movements = $this->stockTransaction->getByReference('damage', $id);
            if (empty($movements)) {
                throw new Exception('No stock movements found for this damage');
            }

            $movementReason = 'Reversal of Damage #' . ($damage['damage_code'] ?? $id) . ': ' . $reason;
            $reversed       = 0;

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

            if (!empty($damage['journal_entry_id'])) {
                $rev = $this->journalPosting->reverseLinkedJournal(
                    (int)$damage['journal_entry_id'],
                    $movementReason
                );
                if (($rev['status'] ?? '') !== 'success') {
                    throw new Exception('Failed to reverse GL: ' . ($rev['message'] ?? ''));
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
            $this->db->bind(':uid', $_SESSION['user_id'] ?? 1);
            $this->db->bind(':reason', $reason);
            $this->db->bind(':id', $id);
            $this->db->execute();

            $this->db->commit();

            $glNote = !empty($damage['journal_entry_id']) ? ' Linked GL reversed.' : '';

            return [
                'status'  => 'success',
                'message' => "Reversed {$reversed} stock movement(s). Stock restored.{$glNote}",
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Damage reverse: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function getFilteredDamages(array $filters = []): array
    {
        $sql = '
            SELECT
                di.id,
                di.damage_code,
                di.damage_date,
                di.total_value,
                di.journal_entry_id,
                di.is_reversed,
                di.reverse_reason,
                di.reversed_at,
                di.sales_return_id,
                w.warehouse_name,
                b.branch_name,
                u.username AS created_by_name,
                sr.return_code AS sales_return_code
            FROM damage_invoices di
            JOIN warehouses w ON di.warehouse_id = w.id
            JOIN branches b ON w.branch_id = b.id
            LEFT JOIN users u ON di.created_by = u.id
            LEFT JOIN sales_returns sr ON sr.id = di.sales_return_id
        ';

        $where    = [];
        $bindings = [];

        if (!$this->canOverrideBranch()) {
            $where[] = 'b.id = :branch_id';
            $bindings[':branch_id'] = self::sessionBranchId();
        }

        $hasDateFilter = false;
        if (!empty($filters['date_from'])) {
            $where[] = 'di.damage_date >= :date_from';
            $bindings[':date_from'] = $filters['date_from'];
            $hasDateFilter = true;
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'di.damage_date <= :date_to';
            $bindings[':date_to'] = $filters['date_to'];
            $hasDateFilter = true;
        }
        if (!$hasDateFilter) {
            $where[] = 'di.damage_date = CURDATE()';
        }

        if (!empty($filters['warehouse_id'])) {
            $where[] = 'di.warehouse_id = :warehouse_id';
            $bindings[':warehouse_id'] = (int)$filters['warehouse_id'];
        }

        if (!empty($filters['sales_return_id'])) {
            $where[] = 'di.sales_return_id = :sales_return_id';
            $bindings[':sales_return_id'] = (int)$filters['sales_return_id'];
        }

        if (!empty($filters['source']) && $filters['source'] === 'manual') {
            $where[] = 'di.sales_return_id IS NULL';
        } elseif (!empty($filters['source']) && $filters['source'] === 'return') {
            $where[] = 'di.sales_return_id IS NOT NULL';
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            if ($filters['status'] === 'reversed') {
                $where[] = 'di.is_reversed = 1';
            } else {
                $where[] = 'COALESCE(di.is_reversed, 0) = 0';
            }
        }

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY di.damage_date DESC, di.id DESC';

        $this->db->query($sql);
        foreach ($bindings as $param => $value) {
            $this->db->bind($param, $value);
        }

        return $this->db->resultSet() ?: [];
    }
}
<?php
// app/models/WarehouseTransferModel.php — same-branch warehouse-to-warehouse transfers

require_once '../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';
require_once 'StockTransactionModel.php';
require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
require_once __DIR__ . '/JournalEntryModel.php';

class WarehouseTransferModel extends Helper {

    protected StockTransactionModel $stockTransaction;
    protected JournalPostingService $journalPosting;

    public function __construct() {
        parent::__construct();
        $this->stockTransaction = new StockTransactionModel($this->db);
        $this->journalPosting   = new JournalPostingService();
    }

    /** Warehouses the current user may pick on create (own branch only). */
    public function getWarehousesForCreate(): array
    {
        $branchId = self::sessionBranchId();
        if ($branchId > 0) {
            return $this->Get_Warehouse_By_Branch($branchId) ?: [];
        }

        if ($this->canOverrideBranch()) {
            return $this->Get_All_Active_Warehouses() ?: [];
        }

        return [];
    }

    public function getWarehousesForUserFilter(): array
    {
        return $this->getWarehousesForCreate();
    }

    public function getAllProducts(): array
    {
        return $this->Get_All_Active_Product() ?: [];
    }

    public function getProductStockAndRate(int $product_id, int $warehouse_id): array
    {
        $balance = $this->Get_Product_Stock_Balance($product_id, null, $warehouse_id);
        $rate  = $warehouse_id > 0 && $product_id > 0
            ? round($this->stockTransaction->getWarehouseAvgCost($warehouse_id, $product_id), 2)
            : 0.0;

        return [
            'available_qty' => (float)($balance['available'] ?? 0),
            'physical_qty'  => (float)($balance['physical'] ?? 0),
            'pipeline_qty'  => (float)($balance['pending_out'] ?? 0),
            'price'         => $rate,
            'rate'          => $rate,
        ];
    }

    private function getWarehouseQty(int $product_id, int $warehouse_id): float
    {
        return $this->Get_Warehouse_Available_Stock($product_id, $warehouse_id);
    }

    private function resolveWarehouseBranches(int $fromWarehouseId, int $toWarehouseId): array
    {
        $this->db->query('
            SELECT id, branch_id, warehouse_name
            FROM warehouses
            WHERE id IN (:from_id, :to_id)
        ');
        $this->db->bind(':from_id', $fromWarehouseId);
        $this->db->bind(':to_id', $toWarehouseId);
        $rows = $this->db->resultSet() ?: [];

        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['id']] = $r;
        }

        return [
            'from' => $map[$fromWarehouseId] ?? null,
            'to'   => $map[$toWarehouseId] ?? null,
        ];
    }

    public function createTransfer(array $post, array $items): array
    {
        $this->db->beginTransaction();
        try {
            $fromWarehouseId = (int)($post['from_warehouse_id'] ?? 0);
            $toWarehouseId   = (int)($post['to_warehouse_id'] ?? 0);
            $branchDemandId  = !empty($post['branch_demand_id']) ? (int)$post['branch_demand_id'] : null;

            if ($fromWarehouseId <= 0 || $toWarehouseId <= 0) {
                throw new Exception('From and To warehouses are required');
            }
            if ($fromWarehouseId === $toWarehouseId) {
                throw new Exception('From and To warehouse must be different');
            }

            $wh = $this->resolveWarehouseBranches($fromWarehouseId, $toWarehouseId);
            if (!$wh['from'] || !$wh['to']) {
                throw new Exception('Invalid warehouse selection');
            }

            $fromBranchId = (int)$wh['from']['branch_id'];
            $toBranchId   = (int)$wh['to']['branch_id'];
            if ($fromBranchId !== $toBranchId) {
                throw new Exception('Both warehouses must belong to the same branch');
            }

            $sessionBranch = self::sessionBranchId();
            if (!$this->canOverrideBranch()) {
                if ($sessionBranch <= 0 || $fromBranchId !== $sessionBranch) {
                    throw new Exception('You can only transfer between warehouses in your branch');
                }
            } elseif ($sessionBranch > 0 && $fromBranchId !== $sessionBranch) {
                throw new Exception('Select warehouses belonging to your assigned branch');
            }

            $lineItems = [];
            $totalAmount = 0.0;
            foreach ($items as $item) {
                $productId = (int)($item['product_id'] ?? 0);
                $qty = (float)($item['qty'] ?? 0);
                if ($productId <= 0 || $qty <= 0) {
                    continue;
                }
                $rate = (float)($item['rate'] ?? 0);
                if ($rate <= 0) {
                    $rate = round($this->stockTransaction->getWarehouseAvgCost($fromWarehouseId, $productId), 2);
                }
                $lineItems[] = [
                    'product_id' => $productId,
                    'qty'        => $qty,
                    'rate'       => $rate,
                ];
                $totalAmount += $qty * $rate;
            }

            if ($lineItems === []) {
                throw new Exception('Add at least one product line');
            }

            $this->Assert_Warehouse_Lines_Available(array_map(static function (array $item) use ($fromWarehouseId): array {
                return [
                    'product_id'   => (int)$item['product_id'],
                    'warehouse_id' => $fromWarehouseId,
                    'qty'          => (float)$item['qty'],
                ];
            }, $lineItems));

            $transfer_code = 'WT-' . date('Ymd') . '-' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
            $transferDate = $post['transfer_date'] ?? date('Y-m-d');

            $this->db->query('
                INSERT INTO warehouse_transfers
                (transfer_code, transfer_date, from_warehouse_id, to_warehouse_id,
                 branch_demand_id, total_amount, created_by, status)
                VALUES
                (:code, :date, :from_wid, :to_wid, :demand_id, :total, :uid, :status)
            ');

            $this->db->bind(':code', $transfer_code);
            $this->db->bind(':date', $transferDate);
            $this->db->bind(':from_wid', $fromWarehouseId);
            $this->db->bind(':to_wid', $toWarehouseId);
            $this->db->bind(':demand_id', $branchDemandId);
            $this->db->bind(':total', round($totalAmount, 2));
            $this->db->bind(':uid', $_SESSION['user_id'] ?? 1);
            $this->db->bind(':status', $branchDemandId ? 'received' : 'transferred');
            $this->db->execute();

            $transfer_id = (int)$this->db->lastInsertId();

            foreach ($lineItems as $item) {
                $this->db->query('
                    INSERT INTO warehouse_transfer_items
                    (warehouse_transfer_id, product_id, qty, rate)
                    VALUES (:tid, :pid, :qty, :rate)
                ');
                $this->db->bind(':tid', $transfer_id);
                $this->db->bind(':pid', $item['product_id']);
                $this->db->bind(':qty', $item['qty']);
                $this->db->bind(':rate', $item['rate']);
                $this->db->execute();

                $this->stockTransaction->updateWarehouseStock(
                    $fromWarehouseId,
                    $item['product_id'],
                    -$item['qty'],
                    0
                );
                $this->stockTransaction->logMovement([
                    'product_id'     => $item['product_id'],
                    'warehouse_id'   => $fromWarehouseId,
                    'qty'            => -$item['qty'],
                    'rate'           => $item['rate'],
                    'reference_type' => 'warehouse_transfer',
                    'reference_id'   => $transfer_id,
                    'remarks'        => 'Transfer out #' . $transfer_code,
                ]);

                $this->stockTransaction->updateWarehouseStock(
                    $toWarehouseId,
                    $item['product_id'],
                    $item['qty'],
                    $item['rate']
                );
                $this->stockTransaction->logMovement([
                    'product_id'     => $item['product_id'],
                    'warehouse_id'   => $toWarehouseId,
                    'qty'            => $item['qty'],
                    'rate'           => $item['rate'],
                    'reference_type' => 'warehouse_transfer',
                    'reference_id'   => $transfer_id,
                    'remarks'        => 'Transfer in #' . $transfer_code,
                ]);
            }

            $glNote = $branchDemandId ? ' Linked to branch demand.' : '';

            $this->db->commit();

            return [
                'status'        => 'success',
                'transfer_id'   => $transfer_id,
                'transfer_code' => $transfer_code,
                'total_amount'  => round($totalAmount, 2),
                'message'       => 'Warehouse transfer saved.' . $glNote,
                'from_branch'   => $fromBranchId,
                'to_branch'     => $toBranchId,
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Warehouse Transfer Error: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function getTransferById(int $id): ?array
    {
        $this->db->query('
            SELECT wt.*,
                   fw.warehouse_name AS from_warehouse,
                   tw.warehouse_name AS to_warehouse,
                   fw.branch_id AS from_branch_id,
                   tw.branch_id AS to_branch_id,
                   fb.branch_name AS from_branch,
                   tb.branch_name AS to_branch,
                   u.username AS created_by_name,
                   ru.username AS reversed_by_name,
                   bd.demand_code AS branch_demand_code
            FROM warehouse_transfers wt
            JOIN warehouses fw ON wt.from_warehouse_id = fw.id
            JOIN warehouses tw ON wt.to_warehouse_id = tw.id
            JOIN branches fb ON fw.branch_id = fb.id
            JOIN branches tb ON tw.branch_id = tb.id
            LEFT JOIN users u ON wt.created_by = u.id
            LEFT JOIN users ru ON wt.reversed_by = ru.id
            LEFT JOIN branch_demands bd ON bd.id = wt.branch_demand_id
            WHERE wt.id = :id
        ');
        $this->db->bind(':id', $id);
        $row = $this->db->single();

        return $row ?: null;
    }

    public function userCanAccessTransfer(array $transfer): bool
    {
        if ($this->canOverrideBranch()) {
            return true;
        }
        $sessionBranch = self::sessionBranchId();

        return (int)($transfer['from_branch_id'] ?? 0) === $sessionBranch;
    }

    public function canUserReverseTransfer(?array $transfer): bool
    {
        if (!$transfer || !empty($transfer['is_reversed'])) {
            return false;
        }
        if (!empty($transfer['branch_demand_id'])) {
            return false;
        }

        return $this->userCanAccessTransfer($transfer);
    }

    public function getTransferItems(int $transfer_id): array
    {
        $this->db->query('
            SELECT wti.*, p.product_code, p.product_name
            FROM warehouse_transfer_items wti
            JOIN products p ON wti.product_id = p.id
            WHERE wti.warehouse_transfer_id = :tid
            ORDER BY p.product_code
        ');
        $this->db->bind(':tid', $transfer_id);

        return $this->db->resultSet() ?: [];
    }

    public function getTransferMovements(int $transfer_id): array
    {
        $this->db->query('
            SELECT st.*, p.product_code, p.product_name, w.warehouse_name
            FROM stock_transactions st
            LEFT JOIN products p ON p.id = st.product_id
            LEFT JOIN warehouses w ON w.id = st.warehouse_id
            WHERE st.reference_type = :type AND st.reference_id = :id
            ORDER BY st.id ASC
        ');
        $this->db->bind(':type', 'warehouse_transfer');
        $this->db->bind(':id', $transfer_id);

        return $this->db->resultSet() ?: [];
    }

    public function getJournalEntriesForTransfer(array $transfer): array
    {
        $journal = new JournalEntryModel();
        $from = null;
        $to   = null;
        if (!empty($transfer['journal_entry_id'])) {
            $from = $journal->getEntryWithLines((int)$transfer['journal_entry_id']);
        }
        if (!empty($transfer['journal_entry_id_debtor'])) {
            $to = $journal->getEntryWithLines((int)$transfer['journal_entry_id_debtor']);
        }

        return ['from_branch' => $from, 'to_branch' => $to];
    }

    public function reverseTransfer(int $id, string $reason): array
    {
        $this->db->beginTransaction();
        try {
            $reason = trim($reason);
            if (strlen($reason) < 3) {
                throw new Exception('Reversal reason is required (min 3 characters)');
            }

            $transfer = $this->getTransferById($id);
            if (!$transfer || !empty($transfer['is_reversed'])) {
                throw new Exception('Transfer not found or already reversed');
            }

            if (!$this->userCanAccessTransfer($transfer)) {
                throw new Exception('You do not have access to reverse this transfer');
            }

            if (!empty($transfer['branch_demand_id'])) {
                throw new Exception('This transfer is linked to a branch demand — reverse the demand instead');
            }

            if (!$this->canUserReverseTransfer($transfer)) {
                throw new Exception('This transfer cannot be reversed');
            }

            $movements = $this->stockTransaction->getByReference('warehouse_transfer', $id);
            if (empty($movements)) {
                throw new Exception('No stock movements found for this transfer');
            }

            $movements = $this->sortMovementsForReversal($movements);

            $reversed = 0;
            $movementReason = 'Reversal of transfer #' . ($transfer['transfer_code'] ?? $id) . ': ' . $reason;

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

            if (!empty($transfer['journal_entry_id'])) {
                $rev = $this->journalPosting->reverseLinkedJournal(
                    (int)$transfer['journal_entry_id'],
                    $movementReason
                );
                if (($rev['status'] ?? '') !== 'success') {
                    throw new Exception('Failed to reverse sender GL: ' . ($rev['message'] ?? ''));
                }
            }
            if (!empty($transfer['journal_entry_id_debtor'])) {
                $rev = $this->journalPosting->reverseLinkedJournal(
                    (int)$transfer['journal_entry_id_debtor'],
                    $movementReason
                );
                if (($rev['status'] ?? '') !== 'success') {
                    throw new Exception('Failed to reverse receiver GL: ' . ($rev['message'] ?? ''));
                }
            }

            $this->db->query('
                UPDATE warehouse_transfers
                SET is_reversed = 1,
                    reversed_at = NOW(),
                    reversed_by = :uid,
                    reverse_reason = :reason,
                    status = :status
                WHERE id = :id
            ');
            $this->db->bind(':uid', $_SESSION['user_id'] ?? 1);
            $this->db->bind(':reason', $reason);
            $this->db->bind(':status', 'reversed');
            $this->db->bind(':id', $id);
            $this->db->execute();

            $this->db->commit();

            $glNote = (!empty($transfer['journal_entry_id']) || !empty($transfer['journal_entry_id_debtor']))
                ? ' Both branch GL entries reversed.'
                : '';

            return [
                'status'  => 'success',
                'message' => "Reversed {$reversed} stock movement(s).{$glNote}",
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Warehouse Transfer reverse: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Undo destination (positive qty) before source (negative qty) to avoid insufficient stock at receiver WH.
     *
     * @param array<int, array<string, mixed>> $movements
     * @return array<int, array<string, mixed>>
     */
    private function sortMovementsForReversal(array $movements): array
    {
        usort($movements, static function (array $a, array $b): int {
            $qa = (float)($a['qty'] ?? 0);
            $qb = (float)($b['qty'] ?? 0);
            if ($qa > 0 && $qb <= 0) {
                return -1;
            }
            if ($qa <= 0 && $qb > 0) {
                return 1;
            }

            return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
        });

        return $movements;
    }

    public function getFilteredTransfers(array $filters = []): array
    {
        $sql = '
            SELECT
                wt.id,
                wt.transfer_code,
                wt.transfer_date,
                wt.status,
                wt.is_reversed,
                wt.total_amount,
                wt.branch_demand_id,
                wt.journal_entry_id,
                wt.journal_entry_id_debtor,
                fw.warehouse_name AS from_warehouse,
                tw.warehouse_name AS to_warehouse,
                fb.branch_name AS from_branch,
                tb.branch_name AS to_branch,
                u.username AS created_by_name
            FROM warehouse_transfers wt
            JOIN warehouses fw ON wt.from_warehouse_id = fw.id
            JOIN warehouses tw ON wt.to_warehouse_id = tw.id
            JOIN branches fb ON fw.branch_id = fb.id
            JOIN branches tb ON tw.branch_id = tb.id
            LEFT JOIN users u ON wt.created_by = u.id
            WHERE fw.branch_id = tw.branch_id
        ';

        $where = [];
        $bindings = [];

        if (!$this->canOverrideBranch()) {
            $where[] = 'fw.branch_id = :branch_id';
            $bindings[':branch_id'] = self::sessionBranchId();
        }

        $hasDateFilter = false;
        if (!empty($filters['date_from'])) {
            $where[] = 'wt.transfer_date >= :date_from';
            $bindings[':date_from'] = $filters['date_from'];
            $hasDateFilter = true;
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'wt.transfer_date <= :date_to';
            $bindings[':date_to'] = $filters['date_to'];
            $hasDateFilter = true;
        }
        if (!$hasDateFilter) {
            $where[] = 'wt.transfer_date = CURDATE()';
        }

        if (!empty($filters['from_warehouse_id'])) {
            $where[] = 'wt.from_warehouse_id = :from_wid';
            $bindings[':from_wid'] = (int)$filters['from_warehouse_id'];
        }
        if (!empty($filters['to_warehouse_id'])) {
            $where[] = 'wt.to_warehouse_id = :to_wid';
            $bindings[':to_wid'] = (int)$filters['to_warehouse_id'];
        }
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            if ($filters['status'] === 'reversed') {
                $where[] = 'wt.is_reversed = 1';
            } else {
                $where[] = 'COALESCE(wt.is_reversed, 0) = 0';
            }
        }

        if (!empty($where)) {
            $sql .= ' AND ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY wt.transfer_date DESC, wt.id DESC';

        $this->db->query($sql);
        foreach ($bindings as $param => $value) {
            $this->db->bind($param, $value);
        }

        return $this->db->resultSet() ?: [];
    }
}
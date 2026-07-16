<?php
// app/models/StockTakeModel.php

require_once '../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';
require_once 'StockTransactionModel.php';
require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
require_once __DIR__ . '/JournalEntryModel.php';

class StockTakeModel extends Helper {

    protected StockTransactionModel $stockTransaction;
    protected JournalPostingService $journalPosting;

    public function __construct() {
        parent::__construct();
        $this->stockTransaction = new StockTransactionModel($this->db);
        $this->journalPosting   = new JournalPostingService();
    }

    public function createSession($post, $warehouse_ids): array
    {
        $this->db->beginTransaction();
        try {
            $session_code = 'ST-' . date('Ymd') . '-' . str_pad((string)random_int(1000, 9999), 4, '0', STR_PAD_LEFT);

            $this->db->query("
                INSERT INTO stock_take_sessions
                (session_code, branch_id, take_date, status, created_by)
                VALUES (:code, :branch, :date, 'draft', :uid)
            ");
            $this->db->bind(':code', $session_code);
            $this->db->bind(':branch', (int)($post['branch_id'] ?? 0));
            $this->db->bind(':date', $post['take_date'] ?? date('Y-m-d'));
            $this->db->bind(':uid', $_SESSION['user_id'] ?? 1);
            $this->db->execute();
            $session_id = (int)$this->db->lastInsertId();

            foreach ($warehouse_ids as $wid) {
                $wid = (int)$wid;
                if ($wid <= 0) {
                    continue;
                }
                $this->db->query("
                    INSERT INTO stock_take_warehouses
                    (stock_take_session_id, warehouse_id, status)
                    VALUES (:sid, :wid, 'pending')
                ");
                $this->db->bind(':sid', $session_id);
                $this->db->bind(':wid', $wid);
                $this->db->execute();
            }

            $this->db->commit();
            return ['status' => 'success', 'session_id' => $session_id, 'session_code' => $session_code];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function getSessionById(int $id): ?array
    {
        $this->db->query("
            SELECT sts.*, b.branch_name, u.username AS created_by_name
            FROM stock_take_sessions sts
            JOIN branches b ON sts.branch_id = b.id
            LEFT JOIN users u ON sts.created_by = u.id
            WHERE sts.id = :id
        ");
        $this->db->bind(':id', $id);
        $row = $this->db->single();

        return $row ?: null;
    }

    public function getSessionWarehouses(int $session_id): array
    {
        $this->db->query("
            SELECT
                stw.*,
                w.warehouse_name,
                (
                    SELECT COUNT(*)
                    FROM stock_take_items sti
                    WHERE sti.stock_take_session_id = stw.stock_take_session_id
                      AND sti.warehouse_id = stw.warehouse_id
                ) AS saved_lines,
                (
                    SELECT COUNT(*)
                    FROM stock_take_items sti
                    WHERE sti.stock_take_session_id = stw.stock_take_session_id
                      AND sti.warehouse_id = stw.warehouse_id
                      AND sti.physical_qty <> sti.system_qty
                ) AS variance_lines,
                (
                    SELECT COALESCE(SUM((sti.physical_qty - sti.system_qty) * COALESCE(sti.rate, 0)), 0)
                    FROM stock_take_items sti
                    WHERE sti.stock_take_session_id = stw.stock_take_session_id
                      AND sti.warehouse_id = stw.warehouse_id
                      AND sti.physical_qty <> sti.system_qty
                ) AS net_impact
            FROM stock_take_warehouses stw
            JOIN warehouses w ON stw.warehouse_id = w.id
            WHERE stw.stock_take_session_id = :sid
            ORDER BY w.warehouse_name
        ");
        $this->db->bind(':sid', $session_id);

        return $this->db->resultSet();
    }

    public function getSessionProgress(int $session_id): array
    {
        $this->db->query("
            SELECT
                COUNT(*) AS total_wh,
                SUM(CASE WHEN status IN ('counted','posted') THEN 1 ELSE 0 END) AS counted_wh,
                SUM(CASE WHEN status = 'posted' THEN 1 ELSE 0 END) AS posted_wh
            FROM stock_take_warehouses
            WHERE stock_take_session_id = :sid
        ");
        $this->db->bind(':sid', $session_id);
        $wh = $this->db->single() ?: [];

        $this->db->query("
            SELECT
                COUNT(*) AS variance_lines,
                COALESCE(SUM(ABS(physical_qty - system_qty)), 0) AS abs_qty_variance,
                COALESCE(SUM(ABS(physical_qty - system_qty) * COALESCE(rate, 0)), 0) AS variance_value,
                COALESCE(SUM(
                    CASE WHEN physical_qty > system_qty
                        THEN (physical_qty - system_qty) * COALESCE(rate, 0)
                        ELSE 0 END
                ), 0) AS gain_value,
                COALESCE(SUM(
                    CASE WHEN physical_qty < system_qty
                        THEN (system_qty - physical_qty) * COALESCE(rate, 0)
                        ELSE 0 END
                ), 0) AS loss_value
            FROM stock_take_items
            WHERE stock_take_session_id = :sid
              AND physical_qty <> system_qty
        ");
        $this->db->bind(':sid', $session_id);
        $var = $this->db->single() ?: [];

        return array_merge($wh, $var);
    }

    public function getProductsForCounting(int $warehouse_id): array
    {
        $this->db->query("
            SELECT
                p.id,
                p.product_code,
                p.product_name,
                p.category_id,
                c.category_name,
                COALESCE(ws.qty, 0) AS system_qty,
                COALESCE(ws.avg_cost, 0) AS avg_cost,
                (
                    SELECT ph.default_rate
                    FROM product_price_history ph
                    WHERE ph.product_id = p.id
                    ORDER BY ph.effective_from DESC, ph.created_at DESC, ph.id DESC
                    LIMIT 1
                ) AS receipt_price
            FROM products p
            LEFT JOIN product_categories c ON c.id = p.category_id
            LEFT JOIN warehouse_stock ws
                ON p.id = ws.product_id AND ws.warehouse_id = :wid
            WHERE p.is_active = 1
            ORDER BY c.category_name, p.product_code
        ");
        $this->db->bind(':wid', $warehouse_id);

        return $this->db->resultSet();
    }

    public function getWarehousesByBranch(int $branch_id): void
    {
        $this->sendJson($this->Get_Warehouse_By_Branch($branch_id));
    }

    protected function sendJson($data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    /**
     * Workflow B: save physical counts only (no stock movement).
     */
    public function saveCount($post): array
    {
        $this->db->beginTransaction();
        try {
            $session_id   = (int)($post['session_id'] ?? 0);
            $warehouse_id = (int)($post['warehouse_id'] ?? 0);
            $markComplete = !empty($post['mark_complete']);

            $session = $this->assertSessionEditable($session_id);
            $this->assertWarehouseInSession($session_id, $warehouse_id);

            $physicalQtys = $post['physical_qty'] ?? [];
            if (!is_array($physicalQtys)) {
                $physicalQtys = [];
            }
            $reasons = $post['reason'] ?? [];
            if (!is_array($reasons)) {
                $reasons = [];
            }

            $lineCount = 0;
            foreach ($physicalQtys as $product_id => $physical_qty_str) {
                $physical_qty_str = trim((string)$physical_qty_str);
                if ($physical_qty_str === '') {
                    continue;
                }

                $product_id   = (int)$product_id;
                $physical_qty = (float)$physical_qty_str;

                $this->db->query("
                    SELECT COALESCE(qty, 0) AS system_qty
                    FROM warehouse_stock
                    WHERE warehouse_id = :wid AND product_id = :pid
                ");
                $this->db->bind(':wid', $warehouse_id);
                $this->db->bind(':pid', $product_id);
                $systemRow = $this->db->single();
                $system_qty = (float)($systemRow['system_qty'] ?? 0);

                $avgCost = $this->stockTransaction->getWarehouseAvgCost($warehouse_id, $product_id);
                $receiptRate = (float)($post['receipt_rate'][$product_id] ?? 0);
                $rate = $avgCost > 0 ? $avgCost : $receiptRate;
                $reason = trim((string)($reasons[$product_id] ?? ''));

                $this->db->query("
                    INSERT INTO stock_take_items
                    (stock_take_session_id, warehouse_id, product_id, system_qty, physical_qty, rate, reason, is_applied)
                    VALUES (:sid, :wid, :pid, :system_qty, :pqty, :rate, :reason, 0)
                    ON DUPLICATE KEY UPDATE
                        system_qty   = VALUES(system_qty),
                        physical_qty = VALUES(physical_qty),
                        rate         = VALUES(rate),
                        reason       = VALUES(reason),
                        updated_at   = NOW()
                ");
                $this->db->bind(':sid', $session_id);
                $this->db->bind(':wid', $warehouse_id);
                $this->db->bind(':pid', $product_id);
                $this->db->bind(':system_qty', $system_qty);
                $this->db->bind(':pqty', $physical_qty);
                $this->db->bind(':rate', $rate);
                $this->db->bind(':reason', $reason);
                $this->db->execute();
                $lineCount++;
            }

            if ($lineCount === 0 && !$markComplete) {
                throw new Exception(
                    'Enter physical qty on at least one product, or check “Mark warehouse complete” if nothing to count.'
                );
            }

            // Partial saves keep warehouse pending until user marks complete (workflow B).
            if ($markComplete) {
                $this->db->query("
                    UPDATE stock_take_warehouses
                    SET status = 'counted', updated_at = NOW()
                    WHERE stock_take_session_id = :sid AND warehouse_id = :wid
                ");
                $this->db->bind(':sid', $session_id);
                $this->db->bind(':wid', $warehouse_id);
                $this->db->execute();
            }

            if (($session['status'] ?? '') === 'draft' && ($lineCount > 0 || $markComplete)) {
                $this->db->query("
                    UPDATE stock_take_sessions
                    SET status = 'counting'
                    WHERE id = :sid
                ");
                $this->db->bind(':sid', $session_id);
                $this->db->execute();
            }

            $this->db->commit();
            if ($markComplete && $lineCount === 0) {
                $msg = 'Warehouse marked complete. Stock still unchanged until you post the session from the session hub.';
            } elseif ($markComplete && $lineCount > 0) {
                $msg = "Saved {$lineCount} line(s) and marked this warehouse complete. Finalize the whole session from the session hub when all warehouses are done.";
            } elseif ($lineCount > 0) {
                $msg = "Saved {$lineCount} line(s). You can add more later — tick “Mark warehouse complete” when finished with this warehouse.";
            } else {
                $msg = 'Nothing saved.';
            }

            return [
                'status'          => 'success',
                'message'         => $msg,
                'lines_saved'     => $lineCount,
                'session_id'      => $session_id,
                'warehouse_done'  => $markComplete,
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Stock Take saveCount: ' . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Workflow B: apply all saved variances to warehouse_stock + stock_transactions.
     */
    public function postSession(int $session_id): array
    {
        $this->db->beginTransaction();
        try {
            $session = $this->assertSessionPostable($session_id);

            $this->db->query("
                SELECT sti.*, p.product_name
                FROM stock_take_items sti
                JOIN products p ON p.id = sti.product_id
                WHERE sti.stock_take_session_id = :sid
                  AND sti.is_applied = 0
                  AND sti.physical_qty <> sti.system_qty
            ");
            $this->db->bind(':sid', $session_id);
            $items = $this->db->resultSet();

            $applied = 0;
            if (empty($items)) {
                $this->finalizePostedSession($session_id, null);
                $this->db->commit();
                return [
                    'status'  => 'success',
                    'message' => 'Session posted. No quantity variances — stock and GL unchanged.',
                ];
            }
            foreach ($items as $item) {
                $warehouse_id = (int)$item['warehouse_id'];
                $product_id   = (int)$item['product_id'];
                $adjustment   = (float)$item['physical_qty'] - (float)$item['system_qty'];
                if (abs($adjustment) < 0.0001) {
                    continue;
                }

                $rate = (float)($item['rate'] ?? 0);
                if ($rate <= 0) {
                    $rate = $this->stockTransaction->getWarehouseAvgCost($warehouse_id, $product_id);
                }

                $issueRate = $adjustment < 0 ? $rate : $rate;

                $this->stockTransaction->updateWarehouseStock(
                    $warehouse_id,
                    $product_id,
                    $adjustment,
                    $adjustment > 0 ? $issueRate : 0
                );

                $this->stockTransaction->logMovement([
                    'product_id'     => $product_id,
                    'warehouse_id'   => $warehouse_id,
                    'qty'            => $adjustment,
                    'rate'           => $issueRate,
                    'reference_type' => 'stock_take',
                    'reference_id'   => $session_id,
                    'remarks'        => 'Stock Take #' . ($session['session_code'] ?? $session_id)
                        . ' — ' . ($item['reason'] ?? ''),
                ]);

                $this->db->query("
                    UPDATE stock_take_items
                    SET is_applied = 1, rate = :rate, updated_at = NOW()
                    WHERE stock_take_session_id = :sid
                      AND warehouse_id = :wid
                      AND product_id = :pid
                ");
                $this->db->bind(':rate', $issueRate);
                $this->db->bind(':sid', $session_id);
                $this->db->bind(':wid', $warehouse_id);
                $this->db->bind(':pid', $product_id);
                $this->db->execute();
                $applied++;
            }

            $varianceValue = $this->computeSessionVarianceValue($session_id);
            $journalResult = $this->journalPosting->postStockTakeSession(
                $session_id,
                $session,
                (float)($varianceValue['loss_value'] ?? 0),
                (float)($varianceValue['gain_value'] ?? 0)
            );
            if (($journalResult['status'] ?? '') !== 'success') {
                throw new Exception($journalResult['message'] ?? 'GL posting failed');
            }

            $journalId = !empty($journalResult['journal_entry_id'])
                ? (int)$journalResult['journal_entry_id']
                : null;

            $this->finalizePostedSession($session_id, $journalId);

            $this->db->commit();
            $glNote = $journalId
                ? ' GL entry ' . ($journalResult['entry_no'] ?? ('#' . $journalId)) . ' created.'
                : '';
            return [
                'status'  => 'success',
                'message' => "Posted {$applied} adjustment line(s). Stock and audit trail updated.{$glNote}",
                'journal_entry_id' => $journalId,
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Stock Take postSession: ' . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function reverseSession(int $id, string $reason): array
    {
        $this->db->beginTransaction();
        try {
            $user_id = (int)($_SESSION['user_id'] ?? 1);
            $reason  = trim($reason);
            if (strlen($reason) < 3) {
                throw new Exception('Reversal reason is required (min 3 characters)');
            }

            $session = $this->getSessionById($id);
            if (!$session) {
                throw new Exception('Stock Take session not found');
            }
            if (!empty($session['is_reversed'])) {
                throw new Exception('This session has already been reversed');
            }
            if (($session['status'] ?? '') !== 'adjusted') {
                throw new Exception('Only posted (adjusted) sessions can be reversed');
            }

            $movements = $this->stockTransaction->getByReference('stock_take', $id);
            if (empty($movements)) {
                throw new Exception('No stock movements found for this session');
            }

            $reversed = 0;
            $movementReason = 'Stock Take #' . ($session['session_code'] ?? $id) . ': ' . $reason;
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
                throw new Exception('No active stock movements to reverse');
            }

            if (!empty($session['journal_entry_id'])) {
                $journalRev = $this->journalPosting->reverseLinkedJournal(
                    (int)$session['journal_entry_id'],
                    'Reversal of Stock Take #' . ($session['session_code'] ?? $id) . ': ' . $reason
                );
                if (($journalRev['status'] ?? '') !== 'success') {
                    throw new Exception('Failed to reverse GL entry: ' . ($journalRev['message'] ?? ''));
                }
            }

            $this->db->query("
                UPDATE stock_take_items
                SET is_applied = 0, updated_at = NOW()
                WHERE stock_take_session_id = :sid
            ");
            $this->db->bind(':sid', $id);
            $this->db->execute();

            $this->db->query("
                UPDATE stock_take_sessions
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
            $glNote = !empty($session['journal_entry_id']) ? ' Linked GL entry reversed.' : '';
            return [
                'status'  => 'success',
                'message' => "Reversed {$reversed} stock movement(s). Quantities restored via audit trail.{$glNote}",
            ];
        } catch (Exception $e) {
            $this->db->rollback();
            error_log('Stock Take reverseSession: ' . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function deleteDraftSession(int $session_id): array
    {
        $this->db->beginTransaction();
        try {
            $this->db->query('SELECT status FROM stock_take_sessions WHERE id = :sid');
            $this->db->bind(':sid', $session_id);
            $session = $this->db->single();

            if (!$session || ($session['status'] ?? '') !== 'draft') {
                throw new Exception('Only draft sessions can be deleted');
            }

            $this->db->query('DELETE FROM stock_take_items WHERE stock_take_session_id = :sid');
            $this->db->bind(':sid', $session_id);
            $this->db->execute();

            $this->db->query('DELETE FROM stock_take_warehouses WHERE stock_take_session_id = :sid');
            $this->db->bind(':sid', $session_id);
            $this->db->execute();

            $this->db->query('DELETE FROM stock_take_sessions WHERE id = :sid');
            $this->db->bind(':sid', $session_id);
            $this->db->execute();

            $this->db->commit();
            return ['status' => 'success', 'message' => 'Draft session deleted'];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function getSavedCounts(int $session_id, int $warehouse_id): array
    {
        $this->db->query("
            SELECT product_id, physical_qty, reason
            FROM stock_take_items
            WHERE stock_take_session_id = :sid AND warehouse_id = :wid
        ");
        $this->db->bind(':sid', $session_id);
        $this->db->bind(':wid', $warehouse_id);
        $result = $this->db->resultSet();

        $saved = [];
        foreach ($result as $row) {
            $saved[$row['product_id']] = [
                'physical_qty' => $row['physical_qty'],
                'reason'       => $row['reason'],
            ];
        }

        return $saved;
    }

    public function getVarianceLines(int $session_id): array
    {
        $this->db->query("
            SELECT sti.*, p.product_code, p.product_name, w.warehouse_name
            FROM stock_take_items sti
            JOIN products p ON p.id = sti.product_id
            JOIN warehouses w ON w.id = sti.warehouse_id
            WHERE sti.stock_take_session_id = :sid
              AND sti.physical_qty <> sti.system_qty
            ORDER BY w.warehouse_name, p.product_code
        ");
        $this->db->bind(':sid', $session_id);

        return $this->db->resultSet();
    }

    public function getStockMovements(int $session_id): array
    {
        $this->db->query("
            SELECT st.*, p.product_code, p.product_name, w.warehouse_name
            FROM stock_transactions st
            JOIN products p ON p.id = st.product_id
            JOIN warehouses w ON w.id = st.warehouse_id
            WHERE st.reference_id = :sid
              AND (
                  st.reference_type IN ('stock_take', 'reversal')
                  OR (st.reference_type = '' AND st.remarks LIKE 'Reversal of%')
              )
            ORDER BY st.id ASC
        ");
        $this->db->bind(':sid', $session_id);

        return $this->db->resultSet();
    }

    public function getFilteredSessions(array $filters = []): array
    {
        $sql = "
            SELECT
                sts.id,
                sts.session_code,
                sts.take_date,
                sts.status,
                sts.is_reversed,
                sts.reverse_reason,
                sts.reversed_at,
                sts.adjusted_at,
                sts.posted_at,
                b.branch_name,
                u.username AS created_by_name,
                COUNT(DISTINCT stw.id) AS warehouse_count,
                SUM(CASE WHEN stw.status IN ('counted','posted') THEN 1 ELSE 0 END) AS warehouses_counted,
                SUM(CASE WHEN stw.status = 'posted' THEN 1 ELSE 0 END) AS warehouses_posted,
                (
                    SELECT COUNT(*)
                    FROM stock_take_items sti
                    WHERE sti.stock_take_session_id = sts.id
                      AND sti.physical_qty <> sti.system_qty
                ) AS variance_lines,
                (
                    SELECT COALESCE(SUM(ABS(sti.physical_qty - sti.system_qty) * COALESCE(sti.rate, 0)), 0)
                    FROM stock_take_items sti
                    WHERE sti.stock_take_session_id = sts.id
                      AND sti.physical_qty <> sti.system_qty
                ) AS variance_value
            FROM stock_take_sessions sts
            JOIN branches b ON sts.branch_id = b.id
            LEFT JOIN users u ON sts.created_by = u.id
            LEFT JOIN stock_take_warehouses stw ON stw.stock_take_session_id = sts.id
        ";

        $where = [];
        $bindings = [];

        $branchId = (int)($_SESSION['branch_id'] ?? 1);
        if (($_SESSION['role'] ?? '') === 'admin' && !empty($filters['branch_id'])) {
            $branchId = (int)$filters['branch_id'];
        }
        $where[] = 'sts.branch_id = :branch_id';
        $bindings[':branch_id'] = $branchId;

        $hasDateFilter = false;
        if (!empty($filters['date_from'])) {
            $where[] = 'sts.take_date >= :date_from';
            $bindings[':date_from'] = $filters['date_from'];
            $hasDateFilter = true;
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'sts.take_date <= :date_to';
            $bindings[':date_to'] = $filters['date_to'];
            $hasDateFilter = true;
        }
        if (!$hasDateFilter) {
            $where[] = 'sts.take_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
        }

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $where[] = "(
                sts.session_code LIKE :t1
                OR b.branch_name LIKE :t2
                OR EXISTS (
                    SELECT 1 FROM stock_take_warehouses stw2
                    JOIN warehouses w ON stw2.warehouse_id = w.id
                    WHERE stw2.stock_take_session_id = sts.id
                      AND w.warehouse_name LIKE :t3
                )
            )";
            $bindings[':t1'] = $term;
            $bindings[':t2'] = $term;
            $bindings[':t3'] = $term;
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            if ($filters['status'] === 'reversed') {
                $where[] = 'sts.is_reversed = 1';
            } else {
                $where[] = 'sts.status = :status AND COALESCE(sts.is_reversed, 0) = 0';
                $bindings[':status'] = $filters['status'];
            }
        } elseif (isset($filters['reversed']) && $filters['reversed'] !== 'all') {
            if ($filters['reversed'] === 'reversed') {
                $where[] = 'sts.is_reversed = 1';
            } else {
                $where[] = 'COALESCE(sts.is_reversed, 0) = 0';
            }
        }

        $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' GROUP BY sts.id ORDER BY sts.take_date DESC, sts.id DESC';

        $this->db->query($sql);
        foreach ($bindings as $param => $value) {
            $this->db->bind($param, $value);
        }

        return $this->db->resultSet();
    }

    private function assertSessionEditable(int $session_id): array
    {
        $this->db->query('SELECT * FROM stock_take_sessions WHERE id = :sid FOR UPDATE');
        $this->db->bind(':sid', $session_id);
        $session = $this->db->single();

        if (!$session) {
            throw new Exception('Session not found');
        }
        if (!empty($session['is_reversed'])) {
            throw new Exception('Reversed sessions cannot be modified');
        }
        if (!in_array($session['status'] ?? '', ['draft', 'counting'], true)) {
            throw new Exception('Counts can only be saved while session is draft or counting (not yet posted)');
        }

        return $session;
    }

    private function assertSessionPostable(int $session_id): array
    {
        $this->db->query('SELECT * FROM stock_take_sessions WHERE id = :sid FOR UPDATE');
        $this->db->bind(':sid', $session_id);
        $session = $this->db->single();

        if (!$session) {
            throw new Exception('Session not found');
        }
        if (!empty($session['is_reversed'])) {
            throw new Exception('Reversed sessions cannot be posted');
        }
        if (($session['status'] ?? '') === 'adjusted') {
            throw new Exception('Session is already posted');
        }

        $this->db->query("
            SELECT COUNT(*) AS pending
            FROM stock_take_warehouses
            WHERE stock_take_session_id = :sid AND status = 'pending'
        ");
        $this->db->bind(':sid', $session_id);
        $pending = (int)($this->db->single()['pending'] ?? 0);
        if ($pending > 0) {
            throw new Exception("{$pending} warehouse(s) still need counting before post");
        }

        $this->db->query("
            SELECT COUNT(*) AS counted
            FROM stock_take_warehouses
            WHERE stock_take_session_id = :sid AND status = 'counted'
        ");
        $this->db->bind(':sid', $session_id);
        $counted = (int)($this->db->single()['counted'] ?? 0);
        if ($counted === 0) {
            throw new Exception('Save counts for all warehouses before posting');
        }

        return $session;
    }

    private function finalizePostedSession(int $session_id, ?int $journal_entry_id = null): void
    {
        $this->db->query("
            UPDATE stock_take_warehouses
            SET status = 'posted', updated_at = NOW()
            WHERE stock_take_session_id = :sid
              AND status = 'counted'
        ");
        $this->db->bind(':sid', $session_id);
        $this->db->execute();

        $this->db->query("
            UPDATE stock_take_sessions
            SET status = 'adjusted',
                adjusted_at = NOW(),
                posted_at = NOW(),
                journal_entry_id = :jeid
            WHERE id = :sid
        ");
        $this->db->bind(':jeid', $journal_entry_id);
        $this->db->bind(':sid', $session_id);
        $this->db->execute();
    }

    /**
     * Gain/loss amounts at count rates for GL (applied or pending variance lines).
     */
    public function computeSessionVarianceValue(int $session_id): array
    {
        $this->db->query("
            SELECT
                COALESCE(SUM(
                    CASE WHEN physical_qty > system_qty
                        THEN (physical_qty - system_qty) * COALESCE(rate, 0)
                        ELSE 0 END
                ), 0) AS gain_value,
                COALESCE(SUM(
                    CASE WHEN physical_qty < system_qty
                        THEN (system_qty - physical_qty) * COALESCE(rate, 0)
                        ELSE 0 END
                ), 0) AS loss_value
            FROM stock_take_items
            WHERE stock_take_session_id = :sid
              AND physical_qty <> system_qty
        ");
        $this->db->bind(':sid', $session_id);
        $row = $this->db->single() ?: [];

        return [
            'gain_value' => (float)($row['gain_value'] ?? 0),
            'loss_value' => (float)($row['loss_value'] ?? 0),
        ];
    }

    public function getJournalEntryForSession(int $session_id): ?array
    {
        $this->db->query('SELECT journal_entry_id FROM stock_take_sessions WHERE id = :id');
        $this->db->bind(':id', $session_id);
        $row = $this->db->single();
        $jeId = (int)($row['journal_entry_id'] ?? 0);
        if ($jeId <= 0) {
            return null;
        }

        $journal = new JournalEntryModel();
        return $journal->getEntryWithLines($jeId);
    }

    private function assertWarehouseInSession(int $session_id, int $warehouse_id): void
    {
        $this->db->query("
            SELECT status FROM stock_take_warehouses
            WHERE stock_take_session_id = :sid AND warehouse_id = :wid
        ");
        $this->db->bind(':sid', $session_id);
        $this->db->bind(':wid', $warehouse_id);
        $row = $this->db->single();

        if (!$row) {
            throw new Exception('Warehouse is not part of this session');
        }
        if (($row['status'] ?? '') === 'posted') {
            throw new Exception('This warehouse is already posted');
        }
    }
}
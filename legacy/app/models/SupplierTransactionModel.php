<?php
// app/models/SupplierTransactionModel.php

require_once __DIR__ . '/../helpers/Helper.php';
require_once __DIR__ . '/BankModel.php';
require_once __DIR__ . '/JournalEntryModel.php';

class SupplierTransactionModel extends Helper {

    public function __construct() {
        parent::__construct();
    }

    public function getSuppliers(): array
    {
        return $this->Get_ALl_Active_Supplier() ?: [];
    }

    public function getBanks(): array
    {
        return $this->Get_ALl_Active_Bank() ?: [];
    }

    public function getEmployeesForUser(): array
    {
        $branchId = self::sessionBranchId();
        if ($branchId > 0 && !$this->canOverrideBranch()) {
            return $this->Get_Employees_By_Branch($branchId) ?: [];
        }

        return $this->All_Active_Employees() ?: [];
    }

    public function getSupplierDue($supplier_id): float
    {
        if (!$supplier_id) {
            return 0.0;
        }

        return (float)$this->Get_Supplier_Now_Due($supplier_id);
    }

    /**
     * Debit/credit for supplier_ledger (positive running_balance = payable to supplier).
     * - payment/advance: debit (reduce payable)
     * - receive: credit (increase payable)
     */
    private function getSupplierLedgerSides(string $type, float $amount): array
    {
        if (in_array($type, ['payment', 'advance'], true)) {
            return ['debit' => $amount, 'credit' => 0.0];
        }

        return ['debit' => 0.0, 'credit' => $amount];
    }

    public function createTransaction($post) {
        $this->db->beginTransaction();
        try {
            $branch_id = self::sessionBranchId() ?: (int)($_SESSION['branch_id'] ?? 0);
            if ($branch_id <= 0) {
                $branch_id = (int)($_SESSION['branch_id'] ?? 1);
            }
            $user_id = (int)($_SESSION['user_id'] ?? 1);

            $type = $post['transaction_type'] ?? 'payment';
            $amount = round((float)($post['amount'] ?? 0), 2);
            $supplier_id = (int)($post['supplier_id'] ?? 0);
            $mode = strtolower((string)($post['mode'] ?? 'cash'));

            if ($amount <= 0 || $supplier_id <= 0) {
                throw new Exception('Invalid amount or supplier');
            }

            $allowedTypes = ['payment', 'advance', 'receive'];
            if (!in_array($type, $allowedTypes, true)) {
                throw new Exception('Invalid transaction type');
            }

            if ($mode === 'bank' && empty($post['bank_id'])) {
                throw new Exception('Select a bank account for bank mode');
            }

            $supplier = $this->Get_Supplier_By_Id($supplier_id);
            if (!$supplier || empty($supplier['is_active'])) {
                throw new Exception('Supplier not found or inactive');
            }

            $payment_code = $this->generateSupplierPaymentCode($branch_id);

            $this->db->query("
                INSERT INTO supplier_payments
                (payment_code, payment_date, supplier_id, amount, payment_mode, bank_id,
                 remarks, created_by, branch_id, collected_by, transaction_type)
                VALUES
                (:code, :date, :sid, :amt, :mode, :bank, :rem, :uid, :bid, :col, :type)
            ");

            $this->db->bind(':code', $payment_code);
            $this->db->bind(':date', $post['transaction_date'] ?? date('Y-m-d'));
            $this->db->bind(':sid', $supplier_id);
            $this->db->bind(':amt', $amount);
            $this->db->bind(':mode', $post['mode'] ?? 'cash');
            $this->db->bind(':bank', $post['bank_id'] ?? null);
            $this->db->bind(':rem', $post['narration'] ?? '');
            $this->db->bind(':uid', $user_id);
            $this->db->bind(':bid', $branch_id);
            $this->db->bind(':col', $post['collected_by'] ?? $user_id);
            $this->db->bind(':type', $type);
            $this->db->execute();

            $payment_id = $this->db->lastInsertId();

            $this->insertSupplierLedger($payment_id, $post, $amount, $branch_id, $user_id, $type);

            $paymentPayload = [
                'payment_code'  => $payment_code,
                'payment_date'  => $post['transaction_date'] ?? date('Y-m-d'),
                'supplier_id'   => $supplier_id,
                'amount'        => $amount,
                'payment_mode'  => $post['mode'] ?? 'cash',
                'bank_id'       => !empty($post['bank_id']) ? (int)$post['bank_id'] : null,
                'branch_id'     => $branch_id,
            ];

            require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
            $journalService = new JournalPostingService($this->db);
            $journalResult = $journalService->postSupplierTransactionJournal($payment_id, $paymentPayload, $type);
            if (($journalResult['status'] ?? '') === 'error') {
                throw new Exception('Journal posting failed: ' . ($journalResult['message'] ?? 'unknown'));
            }

            $journalEntryId = null;
            if (!empty($journalResult['journal_entry_id'])) {
                $journalEntryId = (int)$journalResult['journal_entry_id'];
                $this->setSupplierPaymentJournalEntryId($payment_id, $journalEntryId);
            }

            $this->syncBankBookBalance(
                !empty($post['bank_id']) ? (int)$post['bank_id'] : null,
                $amount,
                $type,
                $post['mode'] ?? 'cash',
                false
            );

            $this->db->commit();

            return [
                'status' => 'success',
                'message' => 'Transaction recorded — supplier ledger and GL posted.',
                'payment_code' => $payment_code,
                'payment_id' => $payment_id,
                'journal_entry_id' => $journalEntryId,
                'total_amount' => $amount,
            ];

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            error_log('Supplier Transaction Error: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function insertSupplierLedger($payment_id, $post, $amount, $branch_id, $user_id, $type) {
        $supplier_id = (int)$post['supplier_id'];
        $current = $this->getSupplierRunningBalance($supplier_id);
        $sides = $this->getSupplierLedgerSides($type, $amount);
        $new_balance = $current - $sides['debit'] + $sides['credit'];

        $this->insertSupplierLedgerEntry([
            'supplier_id'      => $supplier_id,
            'reference_type'   => $type,
            'reference_id'     => (int)$payment_id,
            'debit'            => $sides['debit'],
            'credit'           => $sides['credit'],
            'running_balance'  => $new_balance,
            'branch_id'        => (int)$branch_id,
            'transaction_date' => $post['transaction_date'] ?? date('Y-m-d'),
            'remarks'          => $post['narration'] ?? '',
            'created_by'       => (int)$user_id,
        ]);
    }

    private function getSupplierRunningBalance(int $supplierId): float
    {
        $this->db->query("
            SELECT COALESCE(running_balance, 0) AS balance
            FROM supplier_ledger
            WHERE supplier_id = :sid
            ORDER BY id DESC
            LIMIT 1
        ");
        $this->db->bind(':sid', $supplierId);
        $row = $this->db->single();

        return (float)($row['balance'] ?? 0);
    }

    private function setSupplierPaymentJournalEntryId(int $paymentId, ?int $journalEntryId): void
    {
        $this->db->query('UPDATE supplier_payments SET journal_entry_id = :jid WHERE id = :id');
        $this->db->bind(':jid', $journalEntryId);
        $this->db->bind(':id', $paymentId);
        $this->db->execute();
    }

    private function syncBankBookBalance(?int $bankId, float $amount, string $transactionType, string $paymentMode, bool $undo = false): void
    {
        $paymentMode = strtolower(trim($paymentMode));
        if ($paymentMode !== 'bank' || !$bankId || $amount <= 0) {
            return;
        }

        if (!in_array($transactionType, ['payment', 'advance', 'receive'], true)) {
            return;
        }

        $bankModel = new BankModel($this->db);
        $increaseBank = ($transactionType === 'receive');
        if ($undo) {
            $increaseBank = !$increaseBank;
        }

        $bankModel->updateBalance($bankId, $amount, $increaseBank ? 'credit' : 'debit');
    }

    public function userCanAccessPayment(?array $payment): bool
    {
        if (!$payment) {
            return false;
        }
        if ($this->canOverrideBranch()) {
            return true;
        }

        return (int)($payment['branch_id'] ?? 0) === self::sessionBranchId();
    }

    public function canUserReversePayment(?array $payment): bool
    {
        return $payment
            && empty($payment['is_reversed'])
            && $this->userCanAccessPayment($payment);
    }

    public function reverseTransaction($id, $reason) {
        $this->db->beginTransaction();
        try {
            $reason = trim($reason);
            if (strlen($reason) < 3) {
                throw new Exception('Reversal reason is required (min 3 characters)');
            }

            $user_id = (int)($_SESSION['user_id'] ?? 1);

            $trans = $this->getTransactionById((int)$id);
            if (!$trans || !empty($trans['is_reversed'])) {
                throw new Exception('Transaction not found or already reversed');
            }

            if (!$this->canUserReversePayment($trans)) {
                throw new Exception('You do not have access to reverse this transaction');
            }

            $amount = (float)$trans['amount'];
            $type   = $trans['transaction_type'] ?? 'payment';

            if (!empty($trans['journal_entry_id'])) {
                require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
                $journalService = new JournalPostingService($this->db);
                $rev = $journalService->reverseLinkedJournal(
                    (int)$trans['journal_entry_id'],
                    'Supplier transaction reversal: ' . ($trans['payment_code'] ?? $id) . ' — ' . $reason
                );
                if (($rev['status'] ?? '') === 'error') {
                    throw new Exception('Failed to reverse supplier journal: ' . ($rev['message'] ?? ''));
                }
            }

            $bankId = !empty($trans['bank_id']) ? (int)$trans['bank_id'] : null;
            $payMode = strtolower(trim((string)($trans['payment_mode'] ?? 'cash')));
            if ($bankId && $payMode !== 'bank') {
                $payMode = 'bank';
            }
            $this->syncBankBookBalance($bankId, $amount, $type, $payMode, true);

            $orig = $this->getSupplierLedgerSides($type, $amount);
            $debit = $orig['credit'];
            $credit = $orig['debit'];

            $current = $this->getSupplierRunningBalance((int)$trans['supplier_id']);
            $new_balance = $current - $debit + $credit;
            $revBranchId = (int)($trans['branch_id'] ?? ($_SESSION['branch_id'] ?? 1));

            $this->insertSupplierLedgerEntry([
                'supplier_id'      => (int)$trans['supplier_id'],
                'reference_type'   => 'reversal',
                'reference_id'     => (int)$id,
                'debit'            => $debit,
                'credit'           => $credit,
                'running_balance'  => $new_balance,
                'branch_id'        => $revBranchId,
                'transaction_date' => date('Y-m-d'),
                'remarks'          => 'Reversal of #' . ($trans['payment_code'] ?? $id) . ' - ' . $reason,
                'created_by'       => (int)$user_id,
                'is_reversed'      => 0,
            ]);

            $this->db->query("
                UPDATE supplier_ledger
                SET is_reversed = 1
                WHERE reference_id = :pid
                  AND reference_type IN ('payment', 'advance', 'receive')
                  AND COALESCE(is_reversed, 0) = 0
            ");
            $this->db->bind(':pid', $id);
            $this->db->execute();

            $this->db->query('UPDATE supplier_payments SET is_reversed = 1, reversed_at = NOW(),
                              reversed_by = :uid, reverse_reason = :reason WHERE id = :id');
            $this->db->bind(':uid', $user_id);
            $this->db->bind(':reason', $reason);
            $this->db->bind(':id', $id);
            $this->db->execute();

            $this->db->commit();

            $glNote = !empty($trans['journal_entry_id']) ? ' GL and bank (if any) reversed.' : '';

            return [
                'status'  => 'success',
                'message' => 'Transaction reversed. Supplier ledger restored.' . $glNote,
            ];

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            error_log('Supplier payment reverse: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function getSupplierTransactionIndexStats(?int $branch_id = null): array
    {
        $stats = [
            'total'        => 0,
            'active'       => 0,
            'reversed'     => 0,
            'paid_today'   => 0.0,
            'paid_month'   => 0.0,
        ];

        $branchSql = $branch_id ? ' AND branch_id = :bid' : '';
        $bind = [];
        if ($branch_id) {
            $bind[':bid'] = $branch_id;
        }

        $this->db->query("SELECT COUNT(*) AS c FROM supplier_payments WHERE 1=1{$branchSql}");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['total'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query("SELECT COUNT(*) AS c FROM supplier_payments WHERE is_reversed = 0{$branchSql}");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['active'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query("SELECT COUNT(*) AS c FROM supplier_payments WHERE is_reversed = 1{$branchSql}");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['reversed'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM supplier_payments
            WHERE is_reversed = 0
              AND COALESCE(transaction_type, 'payment') IN ('payment', 'advance')
              AND payment_date = CURDATE()
              {$branchSql}
        ");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['paid_today'] = (float)($this->db->single()['total'] ?? 0);

        $this->db->query("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM supplier_payments
            WHERE is_reversed = 0
              AND COALESCE(transaction_type, 'payment') IN ('payment', 'advance')
              AND payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
              {$branchSql}
        ");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['paid_month'] = (float)($this->db->single()['total'] ?? 0);

        return $stats;
    }

    /**
     * Build WHERE clause fragments for payment list queries.
     *
     * @return array{0: array<int, string>, 1: array<string, mixed>}
     */
    private function buildPaymentListFilters(array $filters, ?int $branchId): array
    {
        $where = [];
        $bindings = [];

        if ($branchId > 0) {
            $where[] = 'sp.branch_id = :branch_id';
            $bindings[':branch_id'] = $branchId;
        } elseif (!$this->canOverrideBranch() && self::sessionBranchId() > 0) {
            $where[] = 'sp.branch_id = :branch_id';
            $bindings[':branch_id'] = self::sessionBranchId();
        }

        $dateFrom = !empty($filters['date_from']) ? (string)$filters['date_from'] : null;
        $dateTo = !empty($filters['date_to']) ? (string)$filters['date_to'] : null;

        if ($dateFrom && $dateTo && $dateFrom === $dateTo) {
            $where[] = 'sp.payment_date = :pay_date';
            $bindings[':pay_date'] = $dateFrom;
        } else {
            if ($dateFrom) {
                $where[] = 'sp.payment_date >= :date_from';
                $bindings[':date_from'] = $dateFrom;
            }
            if ($dateTo) {
                $where[] = 'sp.payment_date <= :date_to';
                $bindings[':date_to'] = $dateTo;
            }
            if (!$dateFrom && !$dateTo) {
                $where[] = 'sp.payment_date = CURDATE()';
            }
        }

        if (!empty($filters['transaction_type']) && $filters['transaction_type'] !== 'all') {
            $where[] = 'sp.transaction_type = :ttype';
            $bindings[':ttype'] = $filters['transaction_type'];
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            if ($filters['status'] === 'reversed') {
                $where[] = 'sp.is_reversed = 1';
            } else {
                $where[] = 'COALESCE(sp.is_reversed, 0) = 0';
            }
        }

        if (!empty($filters['payment_mode']) && $filters['payment_mode'] !== 'all') {
            $where[] = 'LOWER(sp.payment_mode) = :pmode';
            $bindings[':pmode'] = strtolower((string)$filters['payment_mode']);
        }

        if (!empty($filters['supplier_id'])) {
            $where[] = 'sp.supplier_id = :sid';
            $bindings[':sid'] = (int)$filters['supplier_id'];
        }

        return [$where, $bindings];
    }

    private function paymentListFromSql(): string
    {
        return "
            FROM supplier_payments sp
            JOIN suppliers s ON sp.supplier_id = s.id
            LEFT JOIN banks b ON sp.bank_id = b.id
            LEFT JOIN users u ON sp.created_by = u.id
            LEFT JOIN employees e ON sp.collected_by = e.id
            LEFT JOIN branches br ON sp.branch_id = br.id
        ";
    }

    /**
     * Server-side DataTables for supplier payment index.
     */
    public function getPaymentsForDataTable(array $params, array $filters, ?int $branchId): array
    {
        $start       = (int)($params['start'] ?? 0);
        $length      = (int)($params['length'] ?? 25);
        if ($length <= 0 || $length > 100) {
            $length = 25;
        }
        $searchValue = trim($params['search']['value'] ?? '');
        $reversedMode = $params['reversedMode'] ?? '';
        $orderColumn = (int)($params['order'][0]['column'] ?? 0);
        $orderDir    = strtolower($params['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $columns = [
            'sp.payment_date',
            'sp.payment_code',
            's.supplier_name',
            'sp.transaction_type',
            'sp.amount',
            'sp.payment_mode',
            'e.name',
            'sp.is_reversed',
        ];

        $baseQuery = $this->paymentListFromSql();

        [$where, $bindParams] = $this->buildPaymentListFilters($filters, $branchId);

        if ($reversedMode === 'only_reversed') {
            $where[] = 'sp.is_reversed = 1';
        }

        $branchOnlyWhere = [];
        $branchOnlyBind = [];
        if ($branchId > 0) {
            $branchOnlyWhere[] = 'sp.branch_id = :branch_id';
            $branchOnlyBind[':branch_id'] = $branchId;
        } elseif (!$this->canOverrideBranch() && self::sessionBranchId() > 0) {
            $branchOnlyWhere[] = 'sp.branch_id = :branch_id';
            $branchOnlyBind[':branch_id'] = self::sessionBranchId();
        }
        $branchOnlySql = $branchOnlyWhere ? 'WHERE ' . implode(' AND ', $branchOnlyWhere) : '';

        if ($searchValue !== '') {
            $where[] = '(sp.payment_code LIKE :search
                      OR s.supplier_name LIKE :search
                      OR s.mobile LIKE :search
                      OR s.supplier_code LIKE :search)';
            $bindParams[':search'] = '%' . $searchValue . '%';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $totalQuery = "SELECT COUNT(sp.id) AS total {$baseQuery} {$branchOnlySql}";
        $this->db->query($totalQuery);
        foreach ($branchOnlyBind as $key => $val) {
            $this->db->bind($key, $val);
        }
        $recordsTotal = (int)($this->db->single()['total'] ?? 0);

        $filteredQuery = "SELECT COUNT(sp.id) AS total {$baseQuery} {$whereSql}";
        $this->db->query($filteredQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $recordsFiltered = (int)($this->db->single()['total'] ?? 0);

        $orderBy = $columns[$orderColumn] ?? 'sp.payment_date';
        $dataQuery = "
            SELECT
                sp.id,
                sp.payment_date,
                sp.payment_code,
                sp.supplier_id,
                sp.transaction_type,
                sp.amount,
                sp.payment_mode,
                sp.is_reversed,
                sp.branch_id,
                s.supplier_name,
                s.mobile,
                e.name AS collected_by_name
            {$baseQuery}
            {$whereSql}
            ORDER BY {$orderBy} {$orderDir}, sp.id DESC
            LIMIT {$start}, {$length}
        ";

        $this->db->query($dataQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $data = $this->db->resultSet() ?: [];

        foreach ($data as &$row) {
            $row['can_reverse'] = $this->canUserReversePayment($row) ? 1 : 0;
        }
        unset($row);

        return [
            'draw'            => (int)($params['draw'] ?? 1),
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ];
    }

    public function getFilteredTransactions(array $filters = [], ?int $branchId = null): array
    {
        $sql = "
            SELECT
                sp.*,
                s.supplier_name,
                s.mobile,
                b.bank_name,
                u.username AS created_by_name,
                e.name AS collected_by_name,
                br.branch_name
            FROM supplier_payments sp
            JOIN suppliers s ON sp.supplier_id = s.id
            LEFT JOIN banks b ON sp.bank_id = b.id
            LEFT JOIN users u ON sp.created_by = u.id
            LEFT JOIN employees e ON sp.collected_by = e.id
            LEFT JOIN branches br ON sp.branch_id = br.id
        ";

        [$where, $bindings] = $this->buildPaymentListFilters($filters, $branchId);

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY sp.payment_date DESC, sp.id DESC LIMIT 500';

        $this->db->query($sql);
        foreach ($bindings as $k => $v) {
            $this->db->bind($k, $v);
        }

        return $this->db->resultSet() ?: [];
    }

    public function getTransactionById($id) {
        $this->db->query("
            SELECT sp.*,
                   s.supplier_name, s.mobile, s.address AS supplier_address,
                   e.name AS collected_by_name,
                   b.bank_name, b.account_number AS bank_account_number,
                   u.username AS created_by_name,
                   ru.username AS reversed_by_name,
                   br.branch_name, br.address AS branch_address, br.phone AS branch_phone
            FROM supplier_payments sp
            JOIN suppliers s ON sp.supplier_id = s.id
            LEFT JOIN employees e ON sp.collected_by = e.id
            LEFT JOIN banks b ON sp.bank_id = b.id
            LEFT JOIN users u ON sp.created_by = u.id
            LEFT JOIN users ru ON sp.reversed_by = ru.id
            LEFT JOIN branches br ON sp.branch_id = br.id
            WHERE sp.id = :id
        ");
        $this->db->bind(':id', $id);

        return $this->db->single();
    }

    public function getLedgerEntriesForPayment(int $paymentId): array
    {
        $this->db->query("
            SELECT sl.*, u.username AS created_by_name
            FROM supplier_ledger sl
            LEFT JOIN users u ON sl.created_by = u.id
            WHERE sl.reference_id = :pid
              AND sl.reference_type IN ('payment', 'advance', 'receive', 'reversal')
            ORDER BY sl.id ASC
        ");
        $this->db->bind(':pid', $paymentId);

        return $this->db->resultSet() ?: [];
    }

    public function getJournalEntryForPayment(int $paymentId): ?array
    {
        $this->db->query('SELECT journal_entry_id FROM supplier_payments WHERE id = :id');
        $this->db->bind(':id', $paymentId);
        $row = $this->db->single();
        $jeId = (int)($row['journal_entry_id'] ?? 0);
        if ($jeId <= 0) {
            return null;
        }

        return (new JournalEntryModel())->getEntryWithLines($jeId);
    }

    public function getPendingGRNsForSettlement($supplier_id) {
        if (!$supplier_id) {
            return [];
        }

        $this->db->query("
            SELECT
                pr.id,
                pr.receive_code,
                pr.receive_date,
                pr.total_amount as grn_amount,
                COALESCE(SUM(settle.settled_amount), 0) as settled_amount,
                GREATEST(0, pr.total_amount - COALESCE(SUM(settle.settled_amount), 0)) as pending_amount
            FROM purchase_receives pr
            LEFT JOIN supplier_payment_settlements settle ON settle.purchase_receive_id = pr.id
            WHERE pr.supplier_id = :sid
              AND pr.status = 'received'
              AND pr.is_reversed = 0
            GROUP BY pr.id, pr.receive_code, pr.receive_date, pr.total_amount
            HAVING pending_amount > 0.01
            ORDER BY pr.receive_date DESC
        ");
        $this->db->bind(':sid', $supplier_id);

        return $this->db->resultSet();
    }
}
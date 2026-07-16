<?php
// app/models/BankModel.php

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';


class BankModel extends Helper{

    protected  $db;

    public function __construct(?Database $db = null) {
        parent::__construct($db);
    }

    /**
     * Get all active banks for payment
     */
    public function getAllActiveBanks() {
        return $this->Get_All_Active_Bank();
    }

    /**
     * Get single bank by ID (any status — for master data screens)
     */
    public function getById($id) {
        if (!$id) {
            return null;
        }

        $this->db->query('SELECT * FROM banks WHERE id = :id');
        $this->db->bind(':id', (int)$id);

        return $this->db->single();
    }

    /**
     * Get all banks (for admin panel later)
     */
    public function getAllBanks() {
        return $this->Get_All_Bank();
    }

    public function accountNumberExists(string $accountNumber, ?int $excludeId = null): bool
    {
        $accountNumber = trim($accountNumber);
        if ($accountNumber === '') {
            return false;
        }

        $sql = 'SELECT id FROM banks WHERE account_number = :account_number';
        if ($excludeId) {
            $sql .= ' AND id != :exclude_id';
        }
        $this->db->query($sql);
        $this->db->bind(':account_number', $accountNumber);
        if ($excludeId) {
            $this->db->bind(':exclude_id', $excludeId);
        }

        return (bool)$this->db->single();
    }

    /**
     * @return array{ok: bool, error?: string, data?: array<string, mixed>}
     */
    public function validateBankPayload(array $input, ?int $excludeId = null): array
    {
        $bankName = trim((string)($input['bank_name'] ?? ''));
        $accountNumber = trim((string)($input['account_number'] ?? ''));
        $branchName = trim((string)($input['branch_name'] ?? ''));

        if ($bankName === '') {
            return ['ok' => false, 'error' => 'Bank name is required.'];
        }
        if ($accountNumber === '') {
            return ['ok' => false, 'error' => 'Account number is required.'];
        }
        if ($this->accountNumberExists($accountNumber, $excludeId)) {
            return ['ok' => false, 'error' => 'This account number already exists!'];
        }

        $payload = [
            'bank_name'      => $bankName,
            'account_number' => $accountNumber,
            'branch_name'    => $branchName,
        ];

        if ($excludeId !== null) {
            $existing = $this->getById($excludeId);
            if (!$existing) {
                return ['ok' => false, 'error' => 'Bank account not found.'];
            }

            $newActive = (int)($input['is_active'] ?? 1) === 1 ? 1 : 0;
            $wasActive = !empty($existing['is_active']);

            if ($wasActive && !$newActive) {
                $safety = $this->getDeactivationSafetyStatus($excludeId);
                if (!$safety['can_deactivate']) {
                    return ['ok' => false, 'error' => $this->getDeactivationMessage($excludeId)];
                }
            }

            $payload['is_active'] = $newActive;
        }

        return ['ok' => true, 'data' => $payload];
    }

    public function getDeactivationMessage(int $bankId): string
    {
        $safety = $this->getDeactivationSafetyStatus($bankId);
        $msg = 'Cannot deactivate this bank account.';
        if (!empty($safety['has_balance'])) {
            $msg .= ' Current balance: ' . number_format((float)$safety['balance'], 2);
        }
        if (!empty($safety['has_transaction_history'])) {
            $msg .= (!empty($safety['has_balance']) ? '. ' : ' ')
                . 'Has ' . number_format((int)$safety['transaction_count']) . ' linked transaction(s).';
        }
        $msg .= ' Zero the balance and review history before changing status.';

        return $msg;
    }

    public function createBank(array $data): array
    {
        $validated = $this->validateBankPayload($data);
        if (!$validated['ok']) {
            return ['status' => 'error', 'message' => $validated['error']];
        }

        $payload = $validated['data'];

        $this->db->query("
            INSERT INTO banks 
            (bank_name, account_number, branch_name, created_by)
            VALUES 
            (:bank_name, :account_number, :branch_name, :created_by)
        ");

        $this->db->bind(':bank_name', $payload['bank_name']);
        $this->db->bind(':account_number', $payload['account_number']);
        $this->db->bind(':branch_name', $payload['branch_name']);
        $this->db->bind(':created_by', $_SESSION['user_id'] ?? 1);

        if ($this->db->execute()) {
            return [
                'status'  => 'success',
                'message' => 'Bank account created successfully!',
                'id'      => (int)$this->db->lastInsertId(),
            ];
        }

        return ['status' => 'error', 'message' => 'Failed to create bank account!'];
    }

    public function updateBank($id, array $data): array
    {
        $bankId = (int)$id;
        $validated = $this->validateBankPayload($data, $bankId);
        if (!$validated['ok']) {
            return ['status' => 'error', 'message' => $validated['error']];
        }

        $payload = $validated['data'];

        $this->db->query("
            UPDATE banks SET 
                bank_name = :bank_name,
                account_number = :account_number,
                branch_name = :branch_name,
                is_active = :is_active,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $this->db->bind(':bank_name', $payload['bank_name']);
        $this->db->bind(':account_number', $payload['account_number']);
        $this->db->bind(':branch_name', $payload['branch_name']);
        $this->db->bind(':is_active', $payload['is_active']);
        $this->db->bind(':id', $bankId);

        if ($this->db->execute()) {
            return ['status' => 'success', 'message' => 'Bank account updated successfully!'];
        }

        return ['status' => 'error', 'message' => 'Failed to update bank account!'];
    }

    public function toggleStatus($id) {
        $this->db->query("UPDATE banks SET is_active = NOT is_active WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    public function softDeleteBank($id) {
        $this->db->query("UPDATE banks SET is_active = 0 WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    public function restoreBank($id) {
        $this->db->query("UPDATE banks SET is_active = 1 WHERE id = :id");
        $this->db->bind(':id', $id);
        return $this->db->execute();
    }

    public function updateBalance($bank_id, $amount, $type = 'credit') {
        $this->db->query("UPDATE banks SET balance = balance " .
                        ($type === 'credit' ? '+' : '-') . " :amount 
                         WHERE id = :bank_id");
        $this->db->bind(':amount', $amount);
        $this->db->bind(':bank_id', $bank_id);
        return $this->db->execute();
    }

    public function getCurrentBalance(int $bankId): float
    {
        if ($bankId <= 0) {
            return 0.0;
        }

        $this->db->query('SELECT COALESCE(balance, 0) AS balance FROM banks WHERE id = :id');
        $this->db->bind(':id', $bankId);

        return (float)($this->db->single()['balance'] ?? 0);
    }

    /**
     * Count linked payment/transfer/income/expense rows for this bank.
     */
    public function getBankTransactionCount(int $bankId): int
    {
        if ($bankId <= 0) {
            return 0;
        }

        $total = 0;

        $this->db->query('SELECT COUNT(*) AS c FROM customer_payments WHERE bank_id = :bid');
        $this->db->bind(':bid', $bankId);
        $total += (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COUNT(*) AS c FROM supplier_payments WHERE bank_id = :bid');
        $this->db->bind(':bid', $bankId);
        $total += (int)($this->db->single()['c'] ?? 0);

        $this->db->query('
            SELECT COUNT(*) AS c FROM money_transfers
            WHERE from_bank_id = :bid OR to_bank_id = :bid
        ');
        $this->db->bind(':bid', $bankId);
        $total += (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COUNT(*) AS c FROM other_incomes WHERE bank_id = :bid');
        $this->db->bind(':bid', $bankId);
        $total += (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COUNT(*) AS c FROM other_expenses WHERE bank_id = :bid');
        $this->db->bind(':bid', $bankId);
        $total += (int)($this->db->single()['c'] ?? 0);

        return $total;
    }

    public function getDeactivationSafetyStatus(int $bankId): array
    {
        $balance = $this->getCurrentBalance($bankId);
        $transactionCount = $this->getBankTransactionCount($bankId);

        $hasBalance = abs($balance) > 0.009;
        $hasHistory = $transactionCount > 0;

        return [
            'can_deactivate'          => !$hasBalance && !$hasHistory,
            'balance'                 => $balance,
            'transaction_count'       => $transactionCount,
            'has_balance'             => $hasBalance,
            'has_transaction_history' => $hasHistory,
        ];
    }

    public function getBankIndexStats(): array
    {
        $stats = [
            'active'         => 0,
            'inactive'       => 0,
            'total_balance'  => 0.0,
        ];

        $this->db->query('SELECT COUNT(*) AS c FROM banks WHERE is_active = 1');
        $stats['active'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COUNT(*) AS c FROM banks WHERE is_active = 0');
        $stats['inactive'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('SELECT COALESCE(SUM(balance), 0) AS total FROM banks WHERE is_active = 1');
        $stats['total_balance'] = (float)($this->db->single()['total'] ?? 0);

        return $stats;
    }

    public function getBankUsage(int $bankId): array
    {
        $safety = $this->getDeactivationSafetyStatus($bankId);

        return [
            'balance'                 => (float)($safety['balance'] ?? 0),
            'transaction_count'       => (int)($safety['transaction_count'] ?? 0),
            'can_deactivate'          => !empty($safety['can_deactivate']),
            'has_balance'             => !empty($safety['has_balance']),
            'has_transaction_history' => !empty($safety['has_transaction_history']),
        ];
    }

    /**
     * Hub summary for bank show page.
     */
    public function getBankHubSummary(int $bankId): array
    {
        $usage = $this->getBankUsage($bankId);

        $this->db->query('
            SELECT COUNT(*) AS c FROM customer_payments
            WHERE bank_id = :bid AND COALESCE(is_reversed, 0) = 0
        ');
        $this->db->bind(':bid', $bankId);
        $customerPayments = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('
            SELECT COUNT(*) AS c FROM supplier_payments
            WHERE bank_id = :bid AND COALESCE(is_reversed, 0) = 0
        ');
        $this->db->bind(':bid', $bankId);
        $supplierPayments = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('
            SELECT COUNT(*) AS c FROM money_transfers
            WHERE (from_bank_id = :bid OR to_bank_id = :bid) AND COALESCE(is_reversed, 0) = 0
        ');
        $this->db->bind(':bid', $bankId);
        $transfers = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('
            SELECT COUNT(*) AS c FROM other_incomes
            WHERE bank_id = :bid AND COALESCE(is_reversed, 0) = 0
        ');
        $this->db->bind(':bid', $bankId);
        $otherIncome = (int)($this->db->single()['c'] ?? 0);

        $this->db->query('
            SELECT COUNT(*) AS c FROM other_expenses
            WHERE bank_id = :bid AND COALESCE(is_reversed, 0) = 0
        ');
        $this->db->bind(':bid', $bankId);
        $otherExpense = (int)($this->db->single()['c'] ?? 0);

        return array_merge($usage, [
            'customer_payment_count' => $customerPayments,
            'supplier_payment_count' => $supplierPayments,
            'transfer_count'         => $transfers,
            'other_income_count'     => $otherIncome,
            'other_expense_count'    => $otherExpense,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentCustomerPayments(int $bankId, int $limit = 10): array
    {
        if ($bankId <= 0) {
            return [];
        }

        $limit = max(1, min(30, $limit));
        $this->db->query("
            SELECT cp.id, cp.payment_code, cp.payment_date, cp.amount, cp.transaction_type,
                   cp.payment_mode, cp.is_reversed, c.shop_name AS party_name, br.branch_name
            FROM customer_payments cp
            JOIN customers c ON c.id = cp.customer_id
            LEFT JOIN branches br ON br.id = cp.branch_id
            WHERE cp.bank_id = :bid
            ORDER BY cp.payment_date DESC, cp.id DESC
            LIMIT {$limit}
        ");
        $this->db->bind(':bid', $bankId);

        return $this->db->resultSet() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentSupplierPayments(int $bankId, int $limit = 10): array
    {
        if ($bankId <= 0) {
            return [];
        }

        $limit = max(1, min(30, $limit));
        $this->db->query("
            SELECT sp.id, sp.payment_code, sp.payment_date, sp.amount, sp.transaction_type,
                   sp.payment_mode, sp.is_reversed, s.supplier_name AS party_name, br.branch_name
            FROM supplier_payments sp
            JOIN suppliers s ON s.id = sp.supplier_id
            LEFT JOIN branches br ON br.id = sp.branch_id
            WHERE sp.bank_id = :bid
            ORDER BY sp.payment_date DESC, sp.id DESC
            LIMIT {$limit}
        ");
        $this->db->bind(':bid', $bankId);

        return $this->db->resultSet() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentMoneyTransfers(int $bankId, int $limit = 10): array
    {
        if ($bankId <= 0) {
            return [];
        }

        $limit = max(1, min(30, $limit));
        $this->db->query("
            SELECT mt.id, mt.transfer_code, mt.transfer_date, mt.transfer_type, mt.amount,
                   mt.is_reversed, fb.bank_name AS from_bank_name, tb.bank_name AS to_bank_name
            FROM money_transfers mt
            LEFT JOIN banks fb ON fb.id = mt.from_bank_id
            LEFT JOIN banks tb ON tb.id = mt.to_bank_id
            WHERE mt.from_bank_id = :bid OR mt.to_bank_id = :bid
            ORDER BY mt.transfer_date DESC, mt.id DESC
            LIMIT {$limit}
        ");
        $this->db->bind(':bid', $bankId);

        return $this->db->resultSet() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentOtherMovements(int $bankId, int $limit = 10): array
    {
        if ($bankId <= 0) {
            return [];
        }

        $limit = max(1, min(30, $limit));
        $this->db->query("
            SELECT * FROM (
                SELECT oi.id, oi.income_code AS doc_code, oi.income_date AS doc_date, oi.amount,
                       oi.payment_mode, oi.is_reversed, 'income' AS movement_type, l.ledger_name
                FROM other_incomes oi
                LEFT JOIN ledgers l ON l.id = oi.ledger_id
                WHERE oi.bank_id = :bid
                UNION ALL
                SELECT oe.id, oe.expense_code AS doc_code, oe.expense_date AS doc_date, oe.amount,
                       oe.payment_mode, oe.is_reversed, 'expense' AS movement_type, l.ledger_name
                FROM other_expenses oe
                LEFT JOIN ledgers l ON l.id = oe.ledger_id
                WHERE oe.bank_id = :bid2
            ) movements
            ORDER BY doc_date DESC, id DESC
            LIMIT {$limit}
        ");
        $this->db->bind(':bid', $bankId);
        $this->db->bind(':bid2', $bankId);

        return $this->db->resultSet() ?: [];
    }

    public function getBanksForDataTable(array $params): array
    {
        $start          = (int)($params['start'] ?? 0);
        $length         = (int)($params['length'] ?? 25);
        $searchValue    = trim($params['search']['value'] ?? '');
        $orderColumn    = (int)($params['order'][0]['column'] ?? 0);
        $orderDir       = $params['order'][0]['dir'] ?? 'asc';

        $filterStatus   = $params['filterStatus'] ?? '';
        $includeDeleted = !empty($params['includeDeleted']);

        $columns = ['bank_name', 'account_number', 'branch_name', 'balance', 'is_active'];

        $baseQuery = " FROM banks ";

        $where = [];
        $bindParams = [];

        if (!$includeDeleted) {
            $where[] = "is_active = 1";
        } else {
            $where[] = "is_active = 0";
        }

        if ($searchValue !== '') {
            $where[] = "(bank_name LIKE :search 
                      OR account_number LIKE :search 
                      OR branch_name LIKE :search)";
            $bindParams[':search'] = "%{$searchValue}%";
        }

        if ($filterStatus === 'active' && !$includeDeleted) {
            $where[] = "is_active = 1";
        } elseif ($filterStatus === 'inactive') {
            $where[] = "is_active = 0";
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $totalQuery = "SELECT COUNT(id) as total {$baseQuery}";
        if (!$includeDeleted) {
            $totalQuery .= " WHERE is_active = 1";
        } else {
            $totalQuery .= " WHERE is_active = 0";
        }
        $this->db->query($totalQuery);
        $totalResult = $this->db->single();
        $recordsTotal = $totalResult['total'] ?? 0;

        $filteredQuery = "SELECT COUNT(id) as total {$baseQuery} {$whereSql}";
        $this->db->query($filteredQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $filteredResult = $this->db->single();
        $recordsFiltered = $filteredResult['total'] ?? 0;

        $orderBy = $columns[$orderColumn] ?? 'bank_name';
        $dataQuery = "
            SELECT id, bank_name, account_number, branch_name, is_active,
                   COALESCE(balance, 0) AS balance
            {$baseQuery}
            {$whereSql}
            ORDER BY {$orderBy} {$orderDir}
            LIMIT {$start}, {$length}
        ";

        $this->db->query($dataQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $data = $this->db->resultSet();

        return [
            'draw'            => (int)($params['draw'] ?? 1),
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data
        ];
    }
}

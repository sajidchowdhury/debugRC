<?php
// app/models/Accounting/CustomerTransactionModel.php

require_once __DIR__ . '/../../core/Database.php';
require_once __DIR__ . '/../helpers/Helper.php';
require_once __DIR__ . '/BankModel.php';
require_once __DIR__ . '/JournalEntryModel.php';


class CustomerTransactionModel extends Helper {

    public function __construct() {
        parent::__construct();
    }

    public function getCustomers(): array
    {
        return $this->Get_All_Active_Customers() ?: [];
    }

    public function getBanks(): array
    {
        return $this->Get_All_Active_Bank() ?: [];
    }

    public function getEmployeesForUser(): array
    {
        $branchId = self::sessionBranchId();
        if ($branchId > 0 && !$this->canOverrideBranch()) {
            return $this->Get_Employees_By_Branch($branchId) ?: [];
        }

        return $this->All_Active_Employees() ?: [];
    }

    
   
public function createTransaction($post) {
    $this->db->beginTransaction();
    try {
        $branch_id = self::sessionBranchId() ?: (int)($_SESSION['branch_id'] ?? 0);
        if ($branch_id <= 0) {
            $branch_id = (int)($_SESSION['branch_id'] ?? 1);
        }
        $user_id = (int)($_SESSION['user_id'] ?? 1);

        $type   = $post['transaction_type'] ?? 'receive';
        $amount = round((float)($post['amount'] ?? 0), 2);
        $customer_id = (int)($post['customer_id'] ?? 0);
        $mode = strtolower((string)($post['mode'] ?? 'cash'));

        if ($amount <= 0 || $customer_id <= 0) {
            throw new Exception('Invalid amount or customer');
        }

        $allowedTypes = ['receive', 'payment', 'discount', 'write_off'];
        if (!in_array($type, $allowedTypes, true)) {
            throw new Exception('Invalid transaction type');
        }

        if ($mode === 'bank' && empty($post['bank_id'])) {
            throw new Exception('Select a bank account for bank mode');
        }

        $customer = $this->Get_Customer_By_Id($customer_id);
        if (!$customer || empty($customer['is_active'])) {
            throw new Exception('Customer not found or inactive');
        }

        if (in_array($type, ['discount', 'write_off'], true)) {
            $due = $this->getCustomerDue($customer_id);
            if ($amount > $due + 0.01) {
                throw new Exception(
                    'Amount cannot exceed customer due (Tk ' . number_format($due, 2) . ')'
                );
            }
        }

        $payment_code = $this->generateCustomerPaymentCode($branch_id);

        // === 1. INSERT MAIN PAYMENT RECORD ===
        $this->db->query("
            INSERT INTO customer_payments 
            (payment_code, payment_date, customer_id, amount, payment_mode, 
             bank_id, remarks, created_by, branch_id, collected_by, transaction_type)
            VALUES 
            (:code, :date, :cid, :amt, :mode, :bank, :rem, :uid, :bid, :col, :type)
        ");

        $this->db->bind(':code', $payment_code);
        $this->db->bind(':date', $post['transaction_date'] ?? date('Y-m-d'));
        $this->db->bind(':cid', $customer_id);
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

        // === 2. CUSTOMER LEDGER ===
        $this->insertCustomerLedger($payment_id, $post, $amount, $branch_id, $user_id, $type);

        // === 3. GL (all transaction types) ===
        $paymentPayload = [
            'payment_code'  => $payment_code,
            'payment_date'  => $post['transaction_date'] ?? date('Y-m-d'),
            'customer_id'   => $customer_id,
            'amount'        => $amount,
            'payment_mode'  => $post['mode'] ?? 'cash',
            'bank_id'       => !empty($post['bank_id']) ? (int)$post['bank_id'] : null,
            'branch_id'     => $branch_id,
        ];

        require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
        $journalService = new JournalPostingService($this->db);
        $journalResult = $journalService->postCustomerTransactionJournal($payment_id, $paymentPayload, $type);
        if (($journalResult['status'] ?? '') === 'error') {
            throw new Exception('Journal posting failed: ' . ($journalResult['message'] ?? 'unknown'));
        }

        $journalEntryId = null;
        if (!empty($journalResult['journal_entry_id'])) {
            $journalEntryId = (int)$journalResult['journal_entry_id'];
            $this->setPaymentJournalEntryId($payment_id, $journalEntryId);
        }

        // === 5. Cash book (banks.balance) for bank mode receive/refund ===
        $this->syncBankBookBalance(
            !empty($post['bank_id']) ? (int)$post['bank_id'] : null,
            $amount,
            $type,
            $post['mode'] ?? 'cash',
            false
        );

        if ($type === 'receive') {
            require_once __DIR__ . '/../services/Branch/BranchIntercompanyService.php';
            $mode = strtolower((string)($post['mode'] ?? 'cash'));
            $bankId = !empty($post['bank_id']) ? (int)$post['bank_id'] : null;
            $paymentMode = ($mode === 'bank' || $bankId > 0) ? 'bank' : 'cash';
            $settleResult = (new BranchIntercompanyService($this->db))->settleFromCustomerPayment($payment_id, [
                'payment_code'  => $payment_code,
                'payment_date'  => $post['transaction_date'] ?? date('Y-m-d'),
                'amount'        => $amount,
                'payment_mode'  => $paymentMode,
                'bank_id'       => $bankId,
                'branch_id'     => $branch_id,
            ]);
            if (($settleResult['status'] ?? '') === 'error') {
                throw new Exception('Branch demand settlement failed: ' . ($settleResult['message'] ?? ''));
            }
        }

        $this->db->commit();

        return [
            'status' => 'success',
            'message' => 'Payment recorded — ledger and GL posted.',
            'payment_code' => $payment_code,
            'payment_id' => $payment_id,
            'journal_entry_id' => $journalEntryId,
            'total_amount' => $amount,
        ];

    } catch (Throwable $e) {
        if ($this->db->inTransaction()) {
            $this->db->rollback();
        }
        error_log('Customer Transaction Error: ' . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}


/**
 * Get current due balance of a customer
 */
public function getCustomerDue($customer_id) {
    if (!$customer_id) {
        return 0.0;
    }
    return (float)$this->Get_Customer_Now_Due($customer_id);
}

    /**
     * Debit/credit sides for customer_ledger (positive running_balance = amount owed by customer).
     * - receive/discount/write_off: credit (reduce AR/due)
     * - payment (refund/advance out): debit (increase AR/due, matches Dr AR in GL)
     */
    private function getCustomerLedgerSides(string $type, float $amount): array
    {
        switch ($type) {
            case 'receive':
            case 'discount':
            case 'write_off':
                return ['debit' => 0.0, 'credit' => $amount];
            case 'payment':
                return ['debit' => $amount, 'credit' => 0.0];
            default:
                return ['debit' => 0.0, 'credit' => $amount];
        }
    }

    private function setPaymentJournalEntryId(int $paymentId, ?int $journalEntryId): void
    {
        $this->db->query('UPDATE customer_payments SET journal_entry_id = :jid WHERE id = :id');
        $this->db->bind(':jid', $journalEntryId);
        $this->db->bind(':id', $paymentId);
        $this->db->execute();
    }

    /**
     * Update banks.balance for bank-mode receive (in) or refund (out).
     *
     * @param bool $undo When true, reverses the balance movement (used on payment reversal).
     */
    private function syncBankBookBalance(?int $bankId, float $amount, string $transactionType, string $paymentMode, bool $undo = false): void
    {
        $paymentMode = strtolower(trim($paymentMode));
        if ($paymentMode !== 'bank' || !$bankId || $amount <= 0) {
            return;
        }

        if (!in_array($transactionType, ['receive', 'payment'], true)) {
            return;
        }

        $bankModel = new BankModel($this->db);
        $increaseBank = ($transactionType === 'receive');
        if ($undo) {
            $increaseBank = !$increaseBank;
        }

        $bankModel->updateBalance($bankId, $amount, $increaseBank ? 'credit' : 'debit');
    }


    private function insertCustomerLedger($payment_id, $post, $amount, $branch_id, $user_id, $type) {
        $customer_id = (int)$post['customer_id'];
        $current = $this->getCustomerRunningBalance($customer_id);
        $sides = $this->getCustomerLedgerSides($type, $amount);
        $new_balance = $current + $sides['debit'] - $sides['credit'];

        $this->insertCustomerLedgerEntry([
            'customer_id'      => $customer_id,
            'reference_type'   => 'payment',
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

    private function getCustomerRunningBalance(int $customerId): float
    {
        $this->db->query("
            SELECT COALESCE(running_balance, 0) AS balance
            FROM customer_ledger
            WHERE customer_id = :cid
            ORDER BY id DESC
            LIMIT 1
        ");
        $this->db->bind(':cid', $customerId);
        $row = $this->db->single();
        return (float)($row['balance'] ?? 0);
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
                throw new Exception('You do not have access to reverse this payment');
            }

            $amount = (float)$trans['amount'];
            $type   = $trans['transaction_type'] ?? 'receive';

            if (!empty($trans['journal_entry_id'])) {
                require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
                $journalService = new JournalPostingService($this->db);
                $rev = $journalService->reverseLinkedJournal(
                    (int)$trans['journal_entry_id'],
                    'Payment reversal: ' . ($trans['payment_code'] ?? $id) . ' — ' . $reason
                );
                if (($rev['status'] ?? '') === 'error') {
                    throw new Exception('Failed to reverse payment journal: ' . ($rev['message'] ?? ''));
                }
            }

            $bankId = !empty($trans['bank_id']) ? (int)$trans['bank_id'] : null;
            $payMode = strtolower(trim((string)($trans['payment_mode'] ?? 'cash')));
            if ($bankId && $payMode !== 'bank') {
                $payMode = 'bank';
            }
            $this->syncBankBookBalance($bankId, $amount, $type, $payMode, true);

            $orig = $this->getCustomerLedgerSides($type, $amount);
            $debit = $orig['credit'];
            $credit = $orig['debit'];

            $current = $this->getCustomerRunningBalance((int)$trans['customer_id']);
            $new_balance = $current + $debit - $credit;
            $revBranchId = (int)($trans['branch_id'] ?? ($_SESSION['branch_id'] ?? 1));

            $this->insertCustomerLedgerEntry([
                'customer_id'      => (int)$trans['customer_id'],
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
                UPDATE customer_ledger
                SET is_reversed = 1
                WHERE reference_id = :pid
                  AND reference_type = 'payment'
                  AND COALESCE(is_reversed, 0) = 0
            ");
            $this->db->bind(':pid', $id);
            $this->db->execute();

            if ($type === 'receive') {
                require_once __DIR__ . '/../services/Branch/BranchIntercompanyService.php';
                $settleRev = (new BranchIntercompanyService($this->db))->reverseCustomerPaymentSettlements($id);
                if (($settleRev['status'] ?? '') === 'error') {
                    throw new Exception($settleRev['message'] ?? 'Failed to reverse branch demand settlements');
                }
            }

            $this->db->query('UPDATE customer_payments SET is_reversed = 1, reversed_at = NOW(),
                              reversed_by = :uid, reverse_reason = :reason WHERE id = :id');
            $this->db->bind(':uid', $user_id);
            $this->db->bind(':reason', $reason);
            $this->db->bind(':id', $id);
            $this->db->execute();

            $this->db->commit();

            $glNote = !empty($trans['journal_entry_id']) ? ' GL and bank (if any) reversed.' : '';

            return [
                'status'  => 'success',
                'message' => 'Payment reversed. Customer ledger restored.' . $glNote,
            ];

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            error_log('Customer payment reverse: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }


    /**
     * Hero stat tiles for customer transaction index.
     */
    public function getCustomerTransactionIndexStats(?int $branch_id = null): array
    {
        $stats = [
            'total'           => 0,
            'active'          => 0,
            'reversed'        => 0,
            'received_today'  => 0.0,
            'received_month'  => 0.0,
        ];

        $branchSql = $branch_id ? ' AND branch_id = :bid' : '';
        $bind = [];
        if ($branch_id) {
            $bind[':bid'] = $branch_id;
        }

        $this->db->query("SELECT COUNT(*) AS c FROM customer_payments WHERE 1=1{$branchSql}");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['total'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query("SELECT COUNT(*) AS c FROM customer_payments WHERE is_reversed = 0{$branchSql}");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['active'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query("SELECT COUNT(*) AS c FROM customer_payments WHERE is_reversed = 1{$branchSql}");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['reversed'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM customer_payments
            WHERE is_reversed = 0
              AND COALESCE(transaction_type, 'receive') = 'receive'
              AND payment_date = CURDATE()
              {$branchSql}
        ");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['received_today'] = (float)($this->db->single()['total'] ?? 0);

        $this->db->query("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM customer_payments
            WHERE is_reversed = 0
              AND COALESCE(transaction_type, 'receive') = 'receive'
              AND payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
              {$branchSql}
        ");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['received_month'] = (float)($this->db->single()['total'] ?? 0);

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
            $where[] = 'cp.branch_id = :branch_id';
            $bindings[':branch_id'] = $branchId;
        } elseif (!$this->canOverrideBranch() && self::sessionBranchId() > 0) {
            $where[] = 'cp.branch_id = :branch_id';
            $bindings[':branch_id'] = self::sessionBranchId();
        }

        $dateFrom = !empty($filters['date_from']) ? (string)$filters['date_from'] : null;
        $dateTo = !empty($filters['date_to']) ? (string)$filters['date_to'] : null;

        if ($dateFrom && $dateTo && $dateFrom === $dateTo) {
            $where[] = 'cp.payment_date = :pay_date';
            $bindings[':pay_date'] = $dateFrom;
        } else {
            if ($dateFrom) {
                $where[] = 'cp.payment_date >= :date_from';
                $bindings[':date_from'] = $dateFrom;
            }
            if ($dateTo) {
                $where[] = 'cp.payment_date <= :date_to';
                $bindings[':date_to'] = $dateTo;
            }
            if (!$dateFrom && !$dateTo) {
                $where[] = 'cp.payment_date = CURDATE()';
            }
        }

        if (!empty($filters['transaction_type']) && $filters['transaction_type'] !== 'all') {
            $where[] = 'cp.transaction_type = :ttype';
            $bindings[':ttype'] = $filters['transaction_type'];
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            if ($filters['status'] === 'reversed') {
                $where[] = 'cp.is_reversed = 1';
            } else {
                $where[] = 'COALESCE(cp.is_reversed, 0) = 0';
            }
        }

        if (!empty($filters['payment_mode']) && $filters['payment_mode'] !== 'all') {
            $where[] = 'LOWER(cp.payment_mode) = :pmode';
            $bindings[':pmode'] = strtolower((string)$filters['payment_mode']);
        }

        if (!empty($filters['customer_id'])) {
            $where[] = 'cp.customer_id = :cid';
            $bindings[':cid'] = (int)$filters['customer_id'];
        }

        return [$where, $bindings];
    }

    private function paymentListFromSql(): string
    {
        return "
            FROM customer_payments cp
            JOIN customers c ON cp.customer_id = c.id
            LEFT JOIN banks b ON cp.bank_id = b.id
            LEFT JOIN users u ON cp.created_by = u.id
            LEFT JOIN employees e ON cp.collected_by = e.id
            LEFT JOIN branches br ON cp.branch_id = br.id
        ";
    }

    /**
     * Server-side DataTables for customer payment index.
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
            'cp.payment_date',
            'cp.payment_code',
            'c.shop_name',
            'cp.transaction_type',
            'cp.amount',
            'cp.payment_mode',
            'e.name',
            'cp.is_reversed',
        ];

        $baseQuery = $this->paymentListFromSql();

        [$where, $bindParams] = $this->buildPaymentListFilters($filters, $branchId);

        if ($reversedMode === 'only_reversed') {
            $where[] = 'cp.is_reversed = 1';
        }

        $branchOnlyWhere = [];
        $branchOnlyBind = [];
        if ($branchId > 0) {
            $branchOnlyWhere[] = 'cp.branch_id = :branch_id';
            $branchOnlyBind[':branch_id'] = $branchId;
        } elseif (!$this->canOverrideBranch() && self::sessionBranchId() > 0) {
            $branchOnlyWhere[] = 'cp.branch_id = :branch_id';
            $branchOnlyBind[':branch_id'] = self::sessionBranchId();
        }
        $branchOnlySql = $branchOnlyWhere ? 'WHERE ' . implode(' AND ', $branchOnlyWhere) : '';

        if ($searchValue !== '') {
            $where[] = '(cp.payment_code LIKE :search
                      OR c.shop_name LIKE :search
                      OR c.customer_name LIKE :search
                      OR c.mobile LIKE :search)';
            $bindParams[':search'] = '%' . $searchValue . '%';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $totalQuery = "SELECT COUNT(cp.id) AS total {$baseQuery} {$branchOnlySql}";
        $this->db->query($totalQuery);
        foreach ($branchOnlyBind as $key => $val) {
            $this->db->bind($key, $val);
        }
        $recordsTotal = (int)($this->db->single()['total'] ?? 0);

        $filteredQuery = "SELECT COUNT(cp.id) AS total {$baseQuery} {$whereSql}";
        $this->db->query($filteredQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $recordsFiltered = (int)($this->db->single()['total'] ?? 0);

        $orderBy = $columns[$orderColumn] ?? 'cp.payment_date';
        $dataQuery = "
            SELECT
                cp.id,
                cp.payment_date,
                cp.payment_code,
                cp.customer_id,
                cp.transaction_type,
                cp.amount,
                cp.payment_mode,
                cp.is_reversed,
                cp.branch_id,
                c.shop_name,
                c.mobile,
                e.name AS collected_by_name
            {$baseQuery}
            {$whereSql}
            ORDER BY {$orderBy} {$orderDir}, cp.id DESC
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

    /**
     * Get all customer transactions for listing
     */
    public function getFilteredTransactions(array $filters = [], ?int $branchId = null): array
    {
        $sql = "
            SELECT
                cp.*,
                c.shop_name,
                c.mobile,
                b.bank_name,
                u.username AS created_by_name,
                e.name AS collected_by_name,
                br.branch_name
            FROM customer_payments cp
            JOIN customers c ON cp.customer_id = c.id
            LEFT JOIN banks b ON cp.bank_id = b.id
            LEFT JOIN users u ON cp.created_by = u.id
            LEFT JOIN employees e ON cp.collected_by = e.id
            LEFT JOIN branches br ON cp.branch_id = br.id
        ";

        [$where, $bindings] = $this->buildPaymentListFilters($filters, $branchId);

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY cp.payment_date DESC, cp.id DESC LIMIT 500';

        $this->db->query($sql);
        foreach ($bindings as $k => $v) {
            $this->db->bind($k, $v);
        }

        return $this->db->resultSet() ?: [];
    }

    public function getAllTransactions($branch_id = null, $limit = 100) {
        return $this->getFilteredTransactions([
            'date_from' => null,
            'date_to'   => null,
        ], $branch_id ? (int)$branch_id : null);
    }



/**
 * Get single transaction with full details
 */
public function getTransactionById($id) {
    $this->db->query("
        SELECT cp.*,
               c.shop_name, c.customer_name, c.mobile, c.address AS customer_address,
               e.name AS collected_by_name,
               b.bank_name, b.account_number AS bank_account_number,
               u.username AS created_by_name,
               ru.username AS reversed_by_name,
               br.branch_name, br.address AS branch_address, br.phone AS branch_phone
        FROM customer_payments cp
        JOIN customers c ON cp.customer_id = c.id
        LEFT JOIN employees e ON cp.collected_by = e.id
        LEFT JOIN banks b ON cp.bank_id = b.id
        LEFT JOIN users u ON cp.created_by = u.id
        LEFT JOIN users ru ON cp.reversed_by = ru.id
        LEFT JOIN branches br ON cp.branch_id = br.id
        WHERE cp.id = :id
    ");
    $this->db->bind(':id', $id);
    return $this->db->single();
}

    public function getLedgerEntriesForPayment(int $paymentId): array
    {
        $this->db->query("
            SELECT cl.*, u.username AS created_by_name
            FROM customer_ledger cl
            LEFT JOIN users u ON cl.created_by = u.id
            WHERE cl.reference_id = :pid
              AND cl.reference_type IN ('payment', 'reversal')
            ORDER BY cl.id ASC
        ");
        $this->db->bind(':pid', $paymentId);

        return $this->db->resultSet() ?: [];
    }

    public function getJournalEntryForPayment(int $paymentId): ?array
    {
        $this->db->query('SELECT journal_entry_id FROM customer_payments WHERE id = :id');
        $this->db->bind(':id', $paymentId);
        $row = $this->db->single();
        $jeId = (int)($row['journal_entry_id'] ?? 0);
        if ($jeId <= 0) {
            return null;
        }

        return (new JournalEntryModel())->getEntryWithLines($jeId);
    }

    public function getPaymentSettlements(int $paymentId): array
    {
        try {
            $this->db->query("
                SELECT cps.*, bd.demand_code, bd.status AS demand_status
                FROM customer_payment_settlements cps
                JOIN branch_demands bd ON bd.id = cps.demand_id
                WHERE cps.payment_id = :pid
                ORDER BY cps.id ASC
            ");
            $this->db->bind(':pid', $paymentId);

            return $this->db->resultSet() ?: [];
        } catch (Exception $e) {
            return [];
        }
    }

    public function getCustomerDueAfterPayment(int $customerId): float
    {
        return $this->getCustomerDue($customerId);
    }
}
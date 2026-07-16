<?php
// app/models/EmployeeTransactionModel.php

require_once __DIR__ . '/../helpers/Helper.php';
require_once __DIR__ . '/BankModel.php';
require_once __DIR__ . '/JournalEntryModel.php';

class EmployeeTransactionModel extends Helper {

    private static ?bool $hasJournalColumn = null;

    public function __construct() {
        parent::__construct();
    }

    public function getEmployees(): array
    {
        $branchId = self::sessionBranchId();
        if ($branchId > 0 && !$this->canOverrideBranch()) {
            return $this->Get_Employees_By_Branch($branchId) ?: [];
        }

        return $this->All_Active_Employees() ?: [];
    }

    public function getBanks(): array
    {
        return $this->Get_All_Active_Bank() ?: [];
    }

    public function getEmployeeDue(int $employee_id): float
    {
        return $this->Get_Employee_Now_Due($employee_id);
    }

    /** Positive running_balance = amount employee owes company. */
    private function getEmployeeLedgerSides(string $type, float $amount): array
    {
        if (in_array($type, ['advance', 'loan', 'salary', 'adjustment'], true)) {
            return ['debit' => $amount, 'credit' => 0.0];
        }

        if (in_array($type, ['repayment', 'deduction'], true)) {
            return ['debit' => 0.0, 'credit' => $amount];
        }

        return ['debit' => 0.0, 'credit' => $amount];
    }

    private function mapLedgerReferenceType(string $transactionType, bool $isReversal = false): string
    {
        if ($isReversal) {
            return 'adjustment';
        }

        return match ($transactionType) {
            'advance'    => 'advance',
            'loan'       => 'loan',
            'repayment'  => 'payment',
            'salary'     => 'salary',
            'deduction'  => 'deduction',
            'adjustment' => 'adjustment',
            default      => 'adjustment',
        };
    }

    private function isMoneyOutflow(string $type): bool
    {
        return in_array($type, ['advance', 'loan', 'salary', 'adjustment'], true);
    }

    public function createTransaction($post) {
        $this->db->beginTransaction();
        try {
            $branch_id = self::sessionBranchId() ?: (int)($_SESSION['branch_id'] ?? 0);
            if ($branch_id <= 0) {
                $branch_id = (int)($_SESSION['branch_id'] ?? 1);
            }
            $user_id = (int)($_SESSION['user_id'] ?? 1);

            $amount = round((float)($post['amount'] ?? 0), 2);
            $employee_id = (int)($post['employee_id'] ?? 0);
            $type = strtolower(trim((string)($post['transaction_type'] ?? '')));
            $payment_mode = strtolower(trim((string)($post['payment_mode'] ?? 'cash')));
            $bank_id = ($payment_mode === 'bank') ? (int)($post['bank_id'] ?? 0) : null;

            if ($amount <= 0 || $employee_id <= 0 || $type === '') {
                throw new Exception('Invalid amount, employee, or transaction type');
            }

            $allowed = ['advance', 'loan', 'repayment', 'salary', 'deduction', 'adjustment'];
            if (!in_array($type, $allowed, true)) {
                throw new Exception('Invalid transaction type');
            }

            if ($payment_mode === 'bank' && !$bank_id) {
                throw new Exception('Select a bank account for bank mode');
            }

            $employee = $this->Get_Employee_By_Id($employee_id);
            if (!$employee || empty($employee['is_active'])) {
                throw new Exception('Employee not found or inactive');
            }

            $transaction_code = $this->generateEmployeeTransactionCode($branch_id);

            $this->db->query("
                INSERT INTO employee_transactions
                (transaction_code, transaction_date, employee_id, transaction_type, amount,
                 payment_mode, bank_id, remarks, created_by, branch_id, collected_by)
                VALUES
                (:code, :date, :eid, :type, :amount, :mode, :bank_id, :remarks, :uid, :bid, :col)
            ");

            $this->db->bind(':code', $transaction_code);
            $this->db->bind(':date', $post['transaction_date'] ?? date('Y-m-d'));
            $this->db->bind(':eid', $employee_id);
            $this->db->bind(':type', $type);
            $this->db->bind(':amount', $amount);
            $this->db->bind(':mode', $payment_mode);
            $this->db->bind(':bank_id', $bank_id ?: null);
            $this->db->bind(':remarks', $post['narration'] ?? '');
            $this->db->bind(':uid', $user_id);
            $this->db->bind(':bid', $branch_id);
            $this->db->bind(':col', $post['collected_by'] ?? $user_id);
            $this->db->execute();

            $trans_id = (int)$this->db->lastInsertId();

            $this->insertEmployeeLedger($trans_id, $post, $amount, $user_id, $type);

            $txnPayload = [
                'transaction_code'  => $transaction_code,
                'transaction_date'  => $post['transaction_date'] ?? date('Y-m-d'),
                'employee_id'       => $employee_id,
                'amount'            => $amount,
                'payment_mode'      => $payment_mode,
                'bank_id'           => $bank_id,
                'branch_id'         => $branch_id,
            ];

            require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
            $journalService = new JournalPostingService($this->db);
            $journalResult = $journalService->postEmployeeTransactionJournal($trans_id, $txnPayload, $type);
            if (($journalResult['status'] ?? '') === 'error') {
                throw new Exception('Journal posting failed: ' . ($journalResult['message'] ?? 'unknown'));
            }

            $journalEntryId = null;
            if (!empty($journalResult['journal_entry_id'])) {
                $journalEntryId = (int)$journalResult['journal_entry_id'];
                $this->setTransactionJournalEntryId($trans_id, $journalEntryId);
            }

            $this->syncBankBookBalance($bank_id, $amount, $type, $payment_mode, false);

            $this->db->commit();

            return [
                'status' => 'success',
                'message' => 'Transaction recorded — employee ledger and GL posted.',
                'transaction_code' => $transaction_code,
                'payment_id' => $trans_id,
                'transaction_id' => $trans_id,
                'journal_entry_id' => $journalEntryId,
            ];

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            error_log('Employee Transaction Error: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function insertEmployeeLedger(int $trans_id, array $post, float $amount, int $user_id, string $type): void
    {
        $employee_id = (int)$post['employee_id'];
        $current = $this->getEmployeeRunningBalance($employee_id);
        $sides = $this->getEmployeeLedgerSides($type, $amount);
        $new_balance = $current + $sides['debit'] - $sides['credit'];

        $this->insertEmployeeLedgerEntry([
            'employee_id'      => $employee_id,
            'reference_type'   => $this->mapLedgerReferenceType($type),
            'reference_id'     => $trans_id,
            'debit'            => $sides['debit'],
            'credit'           => $sides['credit'],
            'running_balance'  => $new_balance,
            'transaction_date' => $post['transaction_date'] ?? date('Y-m-d'),
            'remarks'          => $post['narration'] ?? (ucfirst($type) . ' transaction'),
            'created_by'       => $user_id,
        ]);
    }

    private function getEmployeeRunningBalance(int $employeeId): float
    {
        return $this->Get_Employee_Now_Due($employeeId);
    }

    private function syncBankBookBalance(?int $bankId, float $amount, string $transactionType, string $paymentMode, bool $undo = false): void
    {
        $paymentMode = strtolower(trim($paymentMode));
        if ($paymentMode !== 'bank' || !$bankId || $amount <= 0) {
            return;
        }

        $bankModel = new BankModel($this->db);
        $increaseBank = !$this->isMoneyOutflow($transactionType);
        if ($undo) {
            $increaseBank = !$increaseBank;
        }

        $bankModel->updateBalance($bankId, $amount, $increaseBank ? 'credit' : 'debit');
    }

    private function setTransactionJournalEntryId(int $transactionId, ?int $journalEntryId): void
    {
        if (!$this->employeeTransactionsHaveJournalColumn()) {
            return;
        }

        $this->db->query('UPDATE employee_transactions SET journal_entry_id = :jid WHERE id = :id');
        $this->db->bind(':jid', $journalEntryId);
        $this->db->bind(':id', $transactionId);
        $this->db->execute();
    }

    private function employeeTransactionsHaveJournalColumn(): bool
    {
        if (self::$hasJournalColumn !== null) {
            return self::$hasJournalColumn;
        }

        try {
            $this->db->query("SHOW COLUMNS FROM employee_transactions LIKE 'journal_entry_id'");
            self::$hasJournalColumn = (bool)$this->db->single();
        } catch (Throwable $e) {
            self::$hasJournalColumn = false;
        }

        return self::$hasJournalColumn;
    }

    public function userCanAccessTransaction(?array $txn): bool
    {
        if (!$txn) {
            return false;
        }
        if ($this->canOverrideBranch()) {
            return true;
        }

        return (int)($txn['branch_id'] ?? 0) === self::sessionBranchId();
    }

    public function canUserReverseTransaction(?array $txn): bool
    {
        return $txn
            && empty($txn['is_reversed'])
            && $this->userCanAccessTransaction($txn);
    }

    public function reverseTransaction($id, $reason) {
        $this->db->beginTransaction();
        try {
            $reason = trim((string)$reason);
            if (strlen($reason) < 3) {
                throw new Exception('Reversal reason is required (min 3 characters)');
            }

            $user_id = (int)($_SESSION['user_id'] ?? 1);

            $trans = $this->getTransactionById((int)$id);
            if (!$trans || !empty($trans['is_reversed'])) {
                throw new Exception('Transaction not found or already reversed');
            }

            if (!$this->canUserReverseTransaction($trans)) {
                throw new Exception('You do not have access to reverse this transaction');
            }

            $amount = (float)$trans['amount'];
            $type = strtolower((string)($trans['transaction_type'] ?? ''));

            if (!empty($trans['journal_entry_id'])) {
                require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
                $journalService = new JournalPostingService($this->db);
                $rev = $journalService->reverseLinkedJournal(
                    (int)$trans['journal_entry_id'],
                    'Employee transaction reversal: ' . ($trans['transaction_code'] ?? $id) . ' — ' . $reason
                );
                if (($rev['status'] ?? '') === 'error') {
                    throw new Exception('Failed to reverse employee journal: ' . ($rev['message'] ?? ''));
                }
            }

            $bankId = !empty($trans['bank_id']) ? (int)$trans['bank_id'] : null;
            $payMode = strtolower(trim((string)($trans['payment_mode'] ?? 'cash')));
            if ($bankId && $payMode !== 'bank') {
                $payMode = 'bank';
            }
            $this->syncBankBookBalance($bankId, $amount, $type, $payMode, true);

            $orig = $this->getEmployeeLedgerSides($type, $amount);
            $debit = $orig['credit'];
            $credit = $orig['debit'];

            $current = $this->getEmployeeRunningBalance((int)$trans['employee_id']);
            $new_balance = $current + $debit - $credit;

            $this->insertEmployeeLedgerEntry([
                'employee_id'      => (int)$trans['employee_id'],
                'reference_type'   => $this->mapLedgerReferenceType($type, true),
                'reference_id'     => (int)$id,
                'debit'            => $debit,
                'credit'           => $credit,
                'running_balance'  => $new_balance,
                'transaction_date' => date('Y-m-d'),
                'remarks'          => 'Reversal of #' . ($trans['transaction_code'] ?? $id) . ' - ' . $reason,
                'created_by'       => $user_id,
                'is_reversed'      => 0,
            ]);

            $this->db->query("
                UPDATE employee_ledger
                SET is_reversed = 1
                WHERE reference_id = :pid
                  AND reference_type IN ('advance', 'loan', 'payment', 'salary', 'deduction', 'adjustment')
                  AND COALESCE(is_reversed, 0) = 0
            ");
            $this->db->bind(':pid', (int)$id);
            $this->db->execute();

            $this->db->query('UPDATE employee_transactions SET is_reversed = 1, reversed_at = NOW(),
                              reversed_by = :uid, reverse_reason = :reason WHERE id = :id');
            $this->db->bind(':uid', $user_id);
            $this->db->bind(':reason', $reason);
            $this->db->bind(':id', $id);
            $this->db->execute();

            $this->db->commit();

            $glNote = !empty($trans['journal_entry_id']) ? ' GL and bank (if any) reversed.' : '';

            return [
                'status'  => 'success',
                'message' => 'Transaction reversed. Employee ledger restored.' . $glNote,
            ];

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            error_log('Employee transaction reverse: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function getEmployeeTransactionIndexStats(?int $branch_id = null): array
    {
        $stats = [
            'total'       => 0,
            'active'      => 0,
            'reversed'    => 0,
            'out_today'   => 0.0,
            'out_month'   => 0.0,
        ];

        $branchSql = $branch_id ? ' AND branch_id = :bid' : '';
        $bind = [];
        if ($branch_id) {
            $bind[':bid'] = $branch_id;
        }

        $this->db->query("SELECT COUNT(*) AS c FROM employee_transactions WHERE 1=1{$branchSql}");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['total'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query("SELECT COUNT(*) AS c FROM employee_transactions WHERE is_reversed = 0{$branchSql}");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['active'] = (int)($this->db->single()['c'] ?? 0);

        $this->db->query("SELECT COUNT(*) AS c FROM employee_transactions WHERE is_reversed = 1{$branchSql}");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['reversed'] = (int)($this->db->single()['c'] ?? 0);

        $outTypes = "'advance','loan','salary','adjustment'";

        $this->db->query("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM employee_transactions
            WHERE is_reversed = 0
              AND transaction_type IN ({$outTypes})
              AND transaction_date = CURDATE()
              {$branchSql}
        ");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['out_today'] = (float)($this->db->single()['total'] ?? 0);

        $this->db->query("
            SELECT COALESCE(SUM(amount), 0) AS total
            FROM employee_transactions
            WHERE is_reversed = 0
              AND transaction_type IN ({$outTypes})
              AND transaction_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
              {$branchSql}
        ");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['out_month'] = (float)($this->db->single()['total'] ?? 0);

        return $stats;
    }

    public function getFilteredTransactions(array $filters = [], ?int $branchId = null): array
    {
        $sql = "
            SELECT
                et.*,
                e.name AS employee_name,
                e.employee_code,
                e.mobile,
                b.bank_name,
                u.username AS created_by_name,
                br.branch_name
            FROM employee_transactions et
            JOIN employees e ON et.employee_id = e.id
            LEFT JOIN banks b ON et.bank_id = b.id
            LEFT JOIN users u ON et.created_by = u.id
            LEFT JOIN branches br ON et.branch_id = br.id
        ";

        $where = [];
        $bindings = [];

        if ($branchId > 0) {
            $where[] = 'et.branch_id = :branch_id';
            $bindings[':branch_id'] = $branchId;
        } elseif (!$this->canOverrideBranch() && self::sessionBranchId() > 0) {
            $where[] = 'et.branch_id = :branch_id';
            $bindings[':branch_id'] = self::sessionBranchId();
        }

        $dateFrom = !empty($filters['date_from']) ? (string)$filters['date_from'] : null;
        $dateTo = !empty($filters['date_to']) ? (string)$filters['date_to'] : null;

        if ($dateFrom && $dateTo && $dateFrom === $dateTo) {
            $where[] = 'et.transaction_date = :pay_date';
            $bindings[':pay_date'] = $dateFrom;
        } else {
            if ($dateFrom) {
                $where[] = 'et.transaction_date >= :date_from';
                $bindings[':date_from'] = $dateFrom;
            }
            if ($dateTo) {
                $where[] = 'et.transaction_date <= :date_to';
                $bindings[':date_to'] = $dateTo;
            }
            if (!$dateFrom && !$dateTo) {
                $where[] = 'et.transaction_date = CURDATE()';
            }
        }

        if (!empty($filters['transaction_type']) && $filters['transaction_type'] !== 'all') {
            $where[] = 'et.transaction_type = :ttype';
            $bindings[':ttype'] = $filters['transaction_type'];
        }

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            if ($filters['status'] === 'reversed') {
                $where[] = 'et.is_reversed = 1';
            } else {
                $where[] = 'COALESCE(et.is_reversed, 0) = 0';
            }
        }

        if (!empty($filters['payment_mode']) && $filters['payment_mode'] !== 'all') {
            $where[] = 'LOWER(et.payment_mode) = :pmode';
            $bindings[':pmode'] = strtolower($filters['payment_mode']);
        }

        if (!empty($filters['employee_id'])) {
            $where[] = 'et.employee_id = :eid';
            $bindings[':eid'] = (int)$filters['employee_id'];
        }

        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY et.transaction_date DESC, et.id DESC LIMIT 500';

        $this->db->query($sql);
        foreach ($bindings as $k => $v) {
            $this->db->bind($k, $v);
        }

        return $this->db->resultSet() ?: [];
    }

    public function getTransactionById($id) {
        $this->db->query("
            SELECT et.*,
                   e.name AS employee_name, e.employee_code, e.mobile,
                   b.bank_name, b.account_number AS bank_account_number,
                   u.username AS created_by_name,
                   ru.username AS reversed_by_name,
                   br.branch_name, br.address AS branch_address, br.phone AS branch_phone
            FROM employee_transactions et
            JOIN employees e ON et.employee_id = e.id
            LEFT JOIN banks b ON et.bank_id = b.id
            LEFT JOIN users u ON et.created_by = u.id
            LEFT JOIN users ru ON et.reversed_by = ru.id
            LEFT JOIN branches br ON et.branch_id = br.id
            WHERE et.id = :id
        ");
        $this->db->bind(':id', $id);

        return $this->db->single();
    }

    public function getLedgerEntriesForTransaction(int $transactionId): array
    {
        $this->db->query("
            SELECT el.*, u.username AS created_by_name
            FROM employee_ledger el
            LEFT JOIN users u ON el.created_by = u.id
            WHERE el.reference_id = :tid
              AND el.reference_type IN ('advance','loan','payment','salary','deduction','adjustment')
            ORDER BY el.id ASC
        ");
        $this->db->bind(':tid', $transactionId);

        return $this->db->resultSet() ?: [];
    }

    public function getJournalEntryForTransaction(int $transactionId): ?array
    {
        if (!$this->employeeTransactionsHaveJournalColumn()) {
            return null;
        }

        $this->db->query('SELECT journal_entry_id FROM employee_transactions WHERE id = :id');
        $this->db->bind(':id', $transactionId);
        $row = $this->db->single();
        $jeId = (int)($row['journal_entry_id'] ?? 0);
        if ($jeId <= 0) {
            return null;
        }

        return (new JournalEntryModel())->getEntryWithLines($jeId);
    }
}
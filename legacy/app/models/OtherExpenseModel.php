<?php
// app/models/OtherExpenseModel.php

require_once __DIR__ . '/../helpers/Helper.php';
require_once __DIR__ . '/BankModel.php';
require_once __DIR__ . '/JournalEntryModel.php';
require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';

class OtherExpenseModel extends Helper {

    public function __construct(?Database $db = null) {
        parent::__construct($db);
    }

    public function getExpenseLedgers(): array
    {
        return $this->Get_Ledger_By_Type('Expense') ?: [];
    }

    public function getBanks(): array
    {
        return $this->Get_All_Active_Bank() ?: [];
    }

    public function createExpense(array $post): array
    {
        $this->db->beginTransaction();
        try {
            $branch_id = self::sessionBranchId() ?: (int)($_SESSION['branch_id'] ?? 0);
            if ($branch_id <= 0) {
                $branch_id = (int)($_SESSION['branch_id'] ?? 1);
            }
            $user_id = (int)($_SESSION['user_id'] ?? 1);

            $amount = round((float)($post['amount'] ?? 0), 2);
            $ledger_id = (int)($post['ledger_id'] ?? 0);
            $payment_mode = strtolower(trim((string)($post['payment_mode'] ?? 'cash')));
            $bank_id = ($payment_mode === 'bank') ? (int)($post['bank_id'] ?? 0) : null;

            if ($amount <= 0 || $ledger_id <= 0) {
                throw new Exception('Invalid amount or expense head');
            }
            if ($payment_mode === 'bank' && $bank_id <= 0) {
                throw new Exception('Select a bank account for bank payment');
            }

            $expense_code = $this->generateOtherExpenseCode($branch_id);
            $expense_date = $post['expense_date'] ?? date('Y-m-d');

            require_once __DIR__ . '/../services/Accounting/AccountingPeriodService.php';
            $periodError = AccountingPeriodService::validatePostingDate($expense_date, $branch_id);
            if ($periodError !== null) {
                throw new Exception($periodError);
            }

            $this->db->query("
                INSERT INTO other_expenses
                (expense_code, expense_date, ledger_id, amount, payment_mode, bank_id,
                 remarks, created_by, branch_id)
                VALUES (:code, :date, :ledger_id, :amount, :mode, :bank_id, :remarks, :uid, :bid)
            ");
            $this->db->bind(':code', $expense_code);
            $this->db->bind(':date', $expense_date);
            $this->db->bind(':ledger_id', $ledger_id);
            $this->db->bind(':amount', $amount);
            $this->db->bind(':mode', $payment_mode);
            $this->db->bind(':bank_id', $bank_id);
            $this->db->bind(':remarks', $post['narration'] ?? '');
            $this->db->bind(':uid', $user_id);
            $this->db->bind(':bid', $branch_id);
            $this->db->execute();

            $expense_id = (int)$this->db->lastInsertId();

            $payload = [
                'expense_code'  => $expense_code,
                'expense_date'  => $expense_date,
                'ledger_id'     => $ledger_id,
                'amount'        => $amount,
                'payment_mode'  => $payment_mode,
                'bank_id'       => $bank_id,
                'narration'     => $post['narration'] ?? '',
                'branch_id'     => $branch_id,
            ];

            $journalService = new JournalPostingService($this->db);
            $journalResult = $journalService->postOtherExpense($expense_id, $payload);
            if (($journalResult['status'] ?? '') === 'error') {
                throw new Exception('Journal posting failed: ' . ($journalResult['message'] ?? 'unknown'));
            }

            $journalEntryId = null;
            if (!empty($journalResult['journal_entry_id'])) {
                $journalEntryId = (int)$journalResult['journal_entry_id'];
                $this->db->query('UPDATE other_expenses SET journal_entry_id = :jeid WHERE id = :eid');
                $this->db->bind(':jeid', $journalEntryId);
                $this->db->bind(':eid', $expense_id);
                $this->db->execute();
            }

            $this->syncBankBalance($bank_id, $amount, $payment_mode, false);
            $this->recordCashLedger($expense_id, $branch_id, $amount, $payment_mode, $expense_date, $expense_code, false);

            $this->db->commitOrFail();

            return [
                'status'           => 'success',
                'message'          => 'Expense recorded — GL and cash/bank updated.',
                'expense_code'     => $expense_code,
                'expense_id'       => $expense_id,
                'amount'           => $amount,
                'journal_entry_id' => $journalEntryId,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            error_log('Other Expense Error: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function reverseExpense(int $id, string $reason): array
    {
        $this->db->beginTransaction();
        try {
            $reason = trim($reason);
            if (strlen($reason) < 3) {
                throw new Exception('Reversal reason is required (min 3 characters)');
            }

            $user_id = (int)($_SESSION['user_id'] ?? 1);

            $this->db->query('SELECT * FROM other_expenses WHERE id = :id AND COALESCE(is_reversed, 0) = 0');
            $this->db->bind(':id', $id);
            $expense = $this->db->single();

            if (!$expense) {
                throw new Exception('Expense not found or already reversed');
            }
            if (!$this->userCanAccessExpense($expense)) {
                throw new Exception('You do not have access to reverse this expense');
            }

            $amount = (float)$expense['amount'];
            $payment_mode = strtolower((string)($expense['payment_mode'] ?? 'cash'));
            $bank_id = !empty($expense['bank_id']) ? (int)$expense['bank_id'] : null;
            $branch_id = (int)($expense['branch_id'] ?? 1);

            $jeId = (int)($expense['journal_entry_id'] ?? 0);
            $journalService = new JournalPostingService($this->db);

            if ($jeId > 0) {
                $rev = $journalService->reverseLinkedJournal(
                    $jeId,
                    'Other expense reversal: ' . ($expense['expense_code'] ?? $id) . ' — ' . $reason
                );
                if (($rev['status'] ?? '') === 'error') {
                    throw new Exception('Failed to reverse expense journal: ' . ($rev['message'] ?? ''));
                }
            } else {
                $journalModel = new JournalEntryModel($this->db);
                $originalJournalId = $journalModel->findJournalEntryByReference('other_expense', $id);
                if (!$originalJournalId) {
                    throw new Exception('No journal entry found for this expense.');
                }
                $reversalResult = $journalModel->createReversingEntry($originalJournalId, $reason);
                if (($reversalResult['status'] ?? '') === 'error') {
                    throw new Exception('Failed to post reversing journal: ' . ($reversalResult['message'] ?? ''));
                }
            }

            $this->syncBankBalance($bank_id, $amount, $payment_mode, true);
            $this->recordCashLedger(
                $id,
                $branch_id,
                $amount,
                $payment_mode,
                date('Y-m-d'),
                $expense['expense_code'] ?? '',
                true
            );

            $this->db->query('
                UPDATE other_expenses
                SET is_reversed = 1, reversed_at = NOW(), reversed_by = :uid, reverse_reason = :reason
                WHERE id = :id
            ');
            $this->db->bind(':uid', $user_id);
            $this->db->bind(':reason', $reason);
            $this->db->bind(':id', $id);
            $this->db->execute();

            $this->db->commitOrFail();

            return [
                'status'        => 'success',
                'message'       => 'Expense reversed. GL and cash/bank restored.',
                'expense_code'  => $expense['expense_code'] ?? null,
                'expense_id'    => $id,
                'redirect_url'  => (defined('BASE_URL') ? BASE_URL : '') . 'OtherExpense/details/' . $id,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            error_log('Other Expense Reverse Error (ID: ' . $id . '): ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /** Expense pays out — reduce bank on post, restore on reverse. */
    private function syncBankBalance(?int $bankId, float $amount, string $paymentMode, bool $undo): void
    {
        if (strtolower($paymentMode) !== 'bank' || !$bankId || $amount <= 0) {
            return;
        }
        $bankModel = new BankModel($this->db);
        $increase = $undo;
        $bankModel->updateBalance($bankId, $amount, $increase ? 'credit' : 'debit');
    }

    private function recordCashLedger(
        int $expenseId,
        int $branchId,
        float $amount,
        string $paymentMode,
        string $transactionDate,
        string $code,
        bool $undo
    ): void {
        if (strtolower($paymentMode) !== 'cash' || $branchId <= 0 || $amount <= 0) {
            return;
        }

        if ($undo) {
            $this->db->query("
                UPDATE cash_ledger
                SET is_reversed = 1, reversed_at = NOW()
                WHERE reference_type = 'other_expense'
                  AND reference_id = :rid
                  AND COALESCE(is_reversed, 0) = 0
            ");
            $this->db->bind(':rid', $expenseId);
            $this->db->execute();

            $this->insertCashLedgerRow($branchId, 0, $amount, 'reversal', $expenseId, $transactionDate, 'Reversal: ' . $code);
            return;
        }

        $this->insertCashLedgerRow($branchId, $amount, 0, 'other_expense', $expenseId, $transactionDate, 'Other expense: ' . $code);
    }

    private function insertCashLedgerRow(
        int $branchId,
        float $debit,
        float $credit,
        string $referenceType,
        int $referenceId,
        string $transactionDate,
        string $remarks
    ): void {
        $this->db->query("
            SELECT COALESCE(running_balance, 0) AS balance
            FROM cash_ledger
            WHERE branch_id = :bid AND cash_point = 'main_cash'
            ORDER BY id DESC LIMIT 1
        ");
        $this->db->bind(':bid', $branchId);
        $row = $this->db->single();
        $new_balance = (float)($row['balance'] ?? 0) + $credit - $debit;

        $this->db->query("
            INSERT INTO cash_ledger
            (transaction_date, branch_id, cash_point, reference_type, reference_id,
             debit, credit, running_balance, remarks, created_by)
            VALUES (:dt, :bid, 'main_cash', :rtype, :rid, :debit, :credit, :bal, :rem, :uid)
        ");
        $this->db->bind(':dt', $transactionDate);
        $this->db->bind(':bid', $branchId);
        $this->db->bind(':rtype', $referenceType);
        $this->db->bind(':rid', $referenceId);
        $this->db->bind(':debit', $debit);
        $this->db->bind(':credit', $credit);
        $this->db->bind(':bal', $new_balance);
        $this->db->bind(':rem', $remarks);
        $this->db->bind(':uid', $_SESSION['user_id'] ?? 1);
        $this->db->execute();

        $this->db->query("
            UPDATE branch_cash SET balance = :bal, last_updated = NOW()
            WHERE branch_id = :bid AND cash_point = 'main_cash'
        ");
        $this->db->bind(':bal', $new_balance);
        $this->db->bind(':bid', $branchId);
        $this->db->execute();
    }

    public function userCanAccessExpense(?array $expense): bool
    {
        if (!$expense) {
            return false;
        }
        if ($this->canOverrideBranch()) {
            return true;
        }

        return (int)($expense['branch_id'] ?? 0) === self::sessionBranchId();
    }

    public function canUserReverseExpense(?array $expense): bool
    {
        return $expense && empty($expense['is_reversed']) && $this->userCanAccessExpense($expense);
    }

    public function getExpenseById(int $id): ?array
    {
        $this->db->query("
            SELECT oe.*, l.ledger_name, b.bank_name, b.account_number AS bank_account_number,
                   u.username AS created_by_name, ru.username AS reversed_by_name,
                   br.branch_name
            FROM other_expenses oe
            LEFT JOIN ledgers l ON oe.ledger_id = l.id
            LEFT JOIN banks b ON oe.bank_id = b.id
            LEFT JOIN users u ON oe.created_by = u.id
            LEFT JOIN users ru ON oe.reversed_by = ru.id
            LEFT JOIN branches br ON oe.branch_id = br.id
            WHERE oe.id = :id
        ");
        $this->db->bind(':id', $id);

        return $this->db->single() ?: null;
    }

    public function getJournalEntryForExpense(int $expenseId): ?array
    {
        $this->db->query('SELECT journal_entry_id FROM other_expenses WHERE id = :id');
        $this->db->bind(':id', $expenseId);
        $row = $this->db->single();
        $jeId = (int)($row['journal_entry_id'] ?? 0);

        if ($jeId <= 0) {
            $jeId = (int)(new JournalEntryModel($this->db))->findJournalEntryByReference('other_expense', $expenseId) ?: 0;
        }

        return $jeId > 0 ? (new JournalEntryModel($this->db))->getEntryWithLines($jeId) : null;
    }

    public function getReversingJournalForExpense(int $expenseId): ?array
    {
        $originalId = (int)(new JournalEntryModel($this->db))->findJournalEntryByReference('other_expense', $expenseId) ?: 0;
        if ($originalId <= 0) {
            return null;
        }

        $this->db->query("
            SELECT id FROM journal_entries
            WHERE reversal_of_entry_id = :oid AND COALESCE(is_reversed, 0) = 0
            ORDER BY id DESC LIMIT 1
        ");
        $this->db->bind(':oid', $originalId);
        $rev = $this->db->single();
        $revId = (int)($rev['id'] ?? 0);

        return $revId > 0 ? (new JournalEntryModel($this->db))->getEntryWithLines($revId) : null;
    }

    public function getCashLedgerForExpense(int $expenseId): array
    {
        try {
            $this->db->query("
                SELECT id, transaction_date, branch_id, cash_point, reference_type, reference_id,
                       debit, credit, running_balance, remarks, COALESCE(is_reversed, 0) AS is_reversed
                FROM cash_ledger
                WHERE reference_type IN ('other_expense', 'reversal')
                  AND reference_id = :id
                ORDER BY id ASC
            ");
            $this->db->bind(':id', $expenseId);

            return $this->db->resultSet() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getOtherExpenseIndexStats(?int $branchId = null): array
    {
        $stats = ['total' => 0, 'active' => 0, 'reversed' => 0, 'today' => 0.0, 'this_month' => 0.0];
        $branchSql = '';
        $bind = [];
        if ($branchId > 0) {
            $branchSql = ' AND branch_id = :bid';
            $bind[':bid'] = $branchId;
        }

        foreach (['total' => '1=1', 'active' => 'COALESCE(is_reversed,0)=0', 'reversed' => 'is_reversed=1'] as $key => $cond) {
            $this->db->query("SELECT COUNT(*) AS c FROM other_expenses WHERE {$cond}{$branchSql}");
            foreach ($bind as $k => $v) {
                $this->db->bind($k, $v);
            }
            $stats[$key] = (int)($this->db->single()['c'] ?? 0);
        }

        $this->db->query("
            SELECT COALESCE(SUM(amount),0) AS t FROM other_expenses
            WHERE COALESCE(is_reversed,0)=0 AND expense_date = CURDATE(){$branchSql}
        ");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['today'] = (float)($this->db->single()['t'] ?? 0);

        $this->db->query("
            SELECT COALESCE(SUM(amount),0) AS t FROM other_expenses
            WHERE COALESCE(is_reversed,0)=0 AND expense_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01'){$branchSql}
        ");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['this_month'] = (float)($this->db->single()['t'] ?? 0);

        return $stats;
    }

    public function getExpensesForDataTable(array $params): array
    {
        $start = (int)($params['start'] ?? 0);
        $length = (int)($params['length'] ?? 25);
        $searchValue = trim($params['search']['value'] ?? '');
        $orderColumn = (int)($params['order'][0]['column'] ?? 0);
        $orderDir = strtolower($params['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $filterLedger = $params['filterLedger'] ?? '';
        $filterPaymentMode = $params['filterPaymentMode'] ?? '';
        $filterStatus = $params['filterStatus'] ?? '';
        $fromDate = $params['fromDate'] ?? '';
        $toDate = $params['toDate'] ?? '';
        $reversedMode = $params['reversedMode'] ?? '';

        $columns = [
            'oe.expense_date', 'oe.expense_code', 'l.ledger_name', 'oe.amount',
            'oe.payment_mode', 'oe.is_reversed',
        ];

        $baseQuery = "
            FROM other_expenses oe
            LEFT JOIN ledgers l ON oe.ledger_id = l.id
            LEFT JOIN banks b ON oe.bank_id = b.id
            LEFT JOIN users u ON oe.created_by = u.id
        ";

        $where = [];
        $bindParams = [];

        if (!$this->canOverrideBranch() && self::sessionBranchId() > 0) {
            $where[] = 'oe.branch_id = :branch_id';
            $bindParams[':branch_id'] = self::sessionBranchId();
        }

        if ($fromDate && $toDate && $fromDate === $toDate) {
            $where[] = 'oe.expense_date = :pay_date';
            $bindParams[':pay_date'] = $fromDate;
        } else {
            if ($fromDate) {
                $where[] = 'oe.expense_date >= :from_date';
                $bindParams[':from_date'] = $fromDate;
            }
            if ($toDate) {
                $where[] = 'oe.expense_date <= :to_date';
                $bindParams[':to_date'] = $toDate;
            }
            if (!$fromDate && !$toDate) {
                $where[] = 'oe.expense_date = CURDATE()';
            }
        }

        if ($filterLedger) {
            $where[] = 'oe.ledger_id = :ledger_id';
            $bindParams[':ledger_id'] = $filterLedger;
        }
        if ($filterPaymentMode) {
            $where[] = 'oe.payment_mode = :payment_mode';
            $bindParams[':payment_mode'] = $filterPaymentMode;
        }
        if ($reversedMode === 'only_reversed') {
            $where[] = 'oe.is_reversed = 1';
        } elseif ($filterStatus === 'active') {
            $where[] = 'COALESCE(oe.is_reversed, 0) = 0';
        } elseif ($filterStatus === 'reversed') {
            $where[] = 'oe.is_reversed = 1';
        }

        if ($searchValue !== '') {
            $where[] = '(oe.expense_code LIKE :search OR l.ledger_name LIKE :search OR oe.remarks LIKE :search OR b.bank_name LIKE :search)';
            $bindParams[':search'] = '%' . $searchValue . '%';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $this->db->query("SELECT COUNT(oe.id) AS total {$baseQuery} {$whereSql}");
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $recordsFiltered = (int)($this->db->single()['total'] ?? 0);

        $orderBy = $columns[$orderColumn] ?? 'oe.expense_date';
        $this->db->query("
            SELECT oe.id, oe.expense_code, oe.expense_date, oe.amount, oe.payment_mode,
                   oe.remarks, oe.is_reversed, l.ledger_name, b.bank_name
            {$baseQuery} {$whereSql}
            ORDER BY {$orderBy} {$orderDir}
            LIMIT {$start}, {$length}
        ");
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }

        return [
            'draw'            => (int)($params['draw'] ?? 1),
            'recordsTotal'    => $recordsFiltered,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $this->db->resultSet() ?: [],
        ];
    }
}
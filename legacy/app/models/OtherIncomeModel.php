<?php
// app/models/OtherIncomeModel.php

require_once __DIR__ . '/../helpers/Helper.php';
require_once __DIR__ . '/BankModel.php';
require_once __DIR__ . '/JournalEntryModel.php';
require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';

class OtherIncomeModel extends Helper {

    public function __construct(?Database $db = null) {
        parent::__construct($db);
    }

    public function getIncomeLedgers(): array
    {
        return $this->Get_Ledger_By_Type('Income') ?: [];
    }

    public function getBanks(): array
    {
        return $this->Get_All_Active_Bank() ?: [];
    }

    public function createIncome(array $post): array
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
                throw new Exception('Invalid amount or income head');
            }
            if ($payment_mode === 'bank' && $bank_id <= 0) {
                throw new Exception('Select a bank account for bank receipt');
            }

            $income_code = $this->generateOtherIncomeCode($branch_id);
            $income_date = $post['income_date'] ?? date('Y-m-d');

            $this->db->query("
                INSERT INTO other_incomes
                (income_code, income_date, ledger_id, amount, payment_mode, bank_id,
                 remarks, created_by, branch_id)
                VALUES (:code, :date, :ledger_id, :amount, :mode, :bank_id, :remarks, :uid, :bid)
            ");
            $this->db->bind(':code', $income_code);
            $this->db->bind(':date', $income_date);
            $this->db->bind(':ledger_id', $ledger_id);
            $this->db->bind(':amount', $amount);
            $this->db->bind(':mode', $payment_mode);
            $this->db->bind(':bank_id', $bank_id);
            $this->db->bind(':remarks', $post['narration'] ?? '');
            $this->db->bind(':uid', $user_id);
            $this->db->bind(':bid', $branch_id);
            $this->db->execute();

            $income_id = (int)$this->db->lastInsertId();

            $payload = [
                'income_code'   => $income_code,
                'income_date'   => $income_date,
                'ledger_id'     => $ledger_id,
                'amount'        => $amount,
                'payment_mode'  => $payment_mode,
                'bank_id'       => $bank_id,
                'narration'     => $post['narration'] ?? '',
                'branch_id'     => $branch_id,
            ];

            $journalService = new JournalPostingService($this->db);
            $journalResult = $journalService->postOtherIncome($income_id, $payload);
            if (($journalResult['status'] ?? '') === 'error') {
                throw new Exception('Journal posting failed: ' . ($journalResult['message'] ?? 'unknown'));
            }

            $journalEntryId = null;
            if (!empty($journalResult['journal_entry_id'])) {
                $journalEntryId = (int)$journalResult['journal_entry_id'];
                $this->db->query('UPDATE other_incomes SET journal_entry_id = :jeid WHERE id = :iid');
                $this->db->bind(':jeid', $journalEntryId);
                $this->db->bind(':iid', $income_id);
                $this->db->execute();
            }

            $this->syncBankBalance($bank_id, $amount, $payment_mode, false);
            $this->recordCashLedger($income_id, $branch_id, $amount, $payment_mode, $income_date, $income_code, false);

            $this->db->commitOrFail();

            return [
                'status'           => 'success',
                'message'          => 'Income recorded — GL and cash/bank updated.',
                'income_code'      => $income_code,
                'income_id'        => $income_id,
                'amount'           => $amount,
                'journal_entry_id' => $journalEntryId,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            error_log('Other Income Error: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function reverseIncome(int $id, string $reason): array
    {
        $this->db->beginTransaction();
        try {
            $reason = trim($reason);
            if (strlen($reason) < 3) {
                throw new Exception('Reversal reason is required (min 3 characters)');
            }

            $user_id = (int)($_SESSION['user_id'] ?? 1);

            $this->db->query('SELECT * FROM other_incomes WHERE id = :id AND COALESCE(is_reversed, 0) = 0');
            $this->db->bind(':id', $id);
            $income = $this->db->single();

            if (!$income) {
                throw new Exception('Income not found or already reversed');
            }
            if (!$this->userCanAccessIncome($income)) {
                throw new Exception('You do not have access to reverse this income');
            }

            $amount = (float)$income['amount'];
            $payment_mode = strtolower((string)($income['payment_mode'] ?? 'cash'));
            $bank_id = !empty($income['bank_id']) ? (int)$income['bank_id'] : null;
            $branch_id = (int)($income['branch_id'] ?? 1);

            $jeId = (int)($income['journal_entry_id'] ?? 0);
            $journalService = new JournalPostingService($this->db);

            if ($jeId > 0) {
                $rev = $journalService->reverseLinkedJournal(
                    $jeId,
                    'Other income reversal: ' . ($income['income_code'] ?? $id) . ' — ' . $reason
                );
                if (($rev['status'] ?? '') === 'error') {
                    throw new Exception('Failed to reverse income journal: ' . ($rev['message'] ?? ''));
                }
            } else {
                $journalModel = new JournalEntryModel($this->db);
                $originalJournalId = $journalModel->findJournalEntryByReference('other_income', $id);
                if (!$originalJournalId) {
                    throw new Exception('No journal entry found for this income.');
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
                $income['income_code'] ?? '',
                true
            );

            $this->db->query('
                UPDATE other_incomes
                SET is_reversed = 1, reversed_at = NOW(), reversed_by = :uid, reverse_reason = :reason
                WHERE id = :id
            ');
            $this->db->bind(':uid', $user_id);
            $this->db->bind(':reason', $reason);
            $this->db->bind(':id', $id);
            $this->db->execute();

            $this->db->commitOrFail();

            return [
                'status'      => 'success',
                'message'     => 'Income reversed. GL and cash/bank restored.',
                'income_code' => $income['income_code'] ?? null,
                'income_id'   => $id,
                'redirect_url'=> (defined('BASE_URL') ? BASE_URL : '') . 'OtherIncome/details/' . $id,
            ];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            error_log('Other Income Reverse Error (ID: ' . $id . '): ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function syncBankBalance(?int $bankId, float $amount, string $paymentMode, bool $undo): void
    {
        if (strtolower($paymentMode) !== 'bank' || !$bankId || $amount <= 0) {
            return;
        }
        $bankModel = new BankModel($this->db);
        $increase = !$undo;
        $bankModel->updateBalance($bankId, $amount, $increase ? 'credit' : 'debit');
    }

    private function recordCashLedger(
        int $incomeId,
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
                WHERE reference_type = 'other_income'
                  AND reference_id = :rid
                  AND COALESCE(is_reversed, 0) = 0
            ");
            $this->db->bind(':rid', $incomeId);
            $this->db->execute();

            $this->insertCashLedgerRow($branchId, $amount, 0, 'reversal', $incomeId, $transactionDate, 'Reversal: ' . $code);
            return;
        }

        $this->insertCashLedgerRow($branchId, 0, $amount, 'other_income', $incomeId, $transactionDate, 'Other income: ' . $code);
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

    public function userCanAccessIncome(?array $income): bool
    {
        if (!$income) {
            return false;
        }
        if ($this->canOverrideBranch()) {
            return true;
        }

        return (int)($income['branch_id'] ?? 0) === self::sessionBranchId();
    }

    public function canUserReverseIncome(?array $income): bool
    {
        return $income && empty($income['is_reversed']) && $this->userCanAccessIncome($income);
    }

    public function getIncomeById(int $id): ?array
    {
        $this->db->query("
            SELECT oi.*, l.ledger_name, b.bank_name, b.account_number AS bank_account_number,
                   u.username AS created_by_name, ru.username AS reversed_by_name,
                   br.branch_name
            FROM other_incomes oi
            LEFT JOIN ledgers l ON oi.ledger_id = l.id
            LEFT JOIN banks b ON oi.bank_id = b.id
            LEFT JOIN users u ON oi.created_by = u.id
            LEFT JOIN users ru ON oi.reversed_by = ru.id
            LEFT JOIN branches br ON oi.branch_id = br.id
            WHERE oi.id = :id
        ");
        $this->db->bind(':id', $id);

        return $this->db->single() ?: null;
    }

    public function getJournalEntryForIncome(int $incomeId): ?array
    {
        $this->db->query('SELECT journal_entry_id FROM other_incomes WHERE id = :id');
        $this->db->bind(':id', $incomeId);
        $row = $this->db->single();
        $jeId = (int)($row['journal_entry_id'] ?? 0);

        if ($jeId <= 0) {
            $jeId = (int)(new JournalEntryModel($this->db))->findJournalEntryByReference('other_income', $incomeId) ?: 0;
        }

        return $jeId > 0 ? (new JournalEntryModel($this->db))->getEntryWithLines($jeId) : null;
    }

    public function getReversingJournalForIncome(int $incomeId): ?array
    {
        $originalId = (int)(new JournalEntryModel($this->db))->findJournalEntryByReference('other_income', $incomeId) ?: 0;
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

    public function getCashLedgerForIncome(int $incomeId): array
    {
        try {
            $this->db->query("
                SELECT id, transaction_date, branch_id, cash_point, reference_type, reference_id,
                       debit, credit, running_balance, remarks, COALESCE(is_reversed, 0) AS is_reversed
                FROM cash_ledger
                WHERE reference_type IN ('other_income', 'reversal')
                  AND reference_id = :id
                ORDER BY id ASC
            ");
            $this->db->bind(':id', $incomeId);

            return $this->db->resultSet() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getOtherIncomeIndexStats(?int $branchId = null): array
    {
        $stats = ['total' => 0, 'active' => 0, 'reversed' => 0, 'today' => 0.0, 'this_month' => 0.0];
        $branchSql = '';
        $bind = [];
        if ($branchId > 0) {
            $branchSql = ' AND branch_id = :bid';
            $bind[':bid'] = $branchId;
        }

        foreach (['total' => '1=1', 'active' => 'COALESCE(is_reversed,0)=0', 'reversed' => 'is_reversed=1'] as $key => $cond) {
            $this->db->query("SELECT COUNT(*) AS c FROM other_incomes WHERE {$cond}{$branchSql}");
            foreach ($bind as $k => $v) {
                $this->db->bind($k, $v);
            }
            $stats[$key] = (int)($this->db->single()['c'] ?? 0);
        }

        $this->db->query("
            SELECT COALESCE(SUM(amount),0) AS t FROM other_incomes
            WHERE COALESCE(is_reversed,0)=0 AND income_date = CURDATE(){$branchSql}
        ");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['today'] = (float)($this->db->single()['t'] ?? 0);

        $this->db->query("
            SELECT COALESCE(SUM(amount),0) AS t FROM other_incomes
            WHERE COALESCE(is_reversed,0)=0 AND income_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01'){$branchSql}
        ");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['this_month'] = (float)($this->db->single()['t'] ?? 0);

        return $stats;
    }

    public function getIncomesForDataTable(array $params): array
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
            'oi.income_date', 'oi.income_code', 'l.ledger_name', 'oi.amount',
            'oi.payment_mode', 'oi.is_reversed',
        ];

        $baseQuery = "
            FROM other_incomes oi
            LEFT JOIN ledgers l ON oi.ledger_id = l.id
            LEFT JOIN banks b ON oi.bank_id = b.id
            LEFT JOIN users u ON oi.created_by = u.id
        ";

        $where = [];
        $bindParams = [];

        if (!$this->canOverrideBranch() && self::sessionBranchId() > 0) {
            $where[] = 'oi.branch_id = :branch_id';
            $bindParams[':branch_id'] = self::sessionBranchId();
        }

        if ($fromDate && $toDate && $fromDate === $toDate) {
            $where[] = 'oi.income_date = :pay_date';
            $bindParams[':pay_date'] = $fromDate;
        } else {
            if ($fromDate) {
                $where[] = 'oi.income_date >= :from_date';
                $bindParams[':from_date'] = $fromDate;
            }
            if ($toDate) {
                $where[] = 'oi.income_date <= :to_date';
                $bindParams[':to_date'] = $toDate;
            }
            if (!$fromDate && !$toDate) {
                $where[] = 'oi.income_date = CURDATE()';
            }
        }

        if ($filterLedger) {
            $where[] = 'oi.ledger_id = :ledger_id';
            $bindParams[':ledger_id'] = $filterLedger;
        }
        if ($filterPaymentMode) {
            $where[] = 'oi.payment_mode = :payment_mode';
            $bindParams[':payment_mode'] = $filterPaymentMode;
        }
        if ($reversedMode === 'only_reversed') {
            $where[] = 'oi.is_reversed = 1';
        } elseif ($filterStatus === 'active') {
            $where[] = 'COALESCE(oi.is_reversed, 0) = 0';
        } elseif ($filterStatus === 'reversed') {
            $where[] = 'oi.is_reversed = 1';
        }

        if ($searchValue !== '') {
            $where[] = '(oi.income_code LIKE :search OR l.ledger_name LIKE :search OR oi.remarks LIKE :search OR b.bank_name LIKE :search)';
            $bindParams[':search'] = '%' . $searchValue . '%';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $this->db->query("SELECT COUNT(oi.id) AS total {$baseQuery} {$whereSql}");
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $recordsFiltered = (int)($this->db->single()['total'] ?? 0);

        $orderBy = $columns[$orderColumn] ?? 'oi.income_date';
        $this->db->query("
            SELECT oi.id, oi.income_code, oi.income_date, oi.amount, oi.payment_mode,
                   oi.remarks, oi.is_reversed, l.ledger_name, b.bank_name
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
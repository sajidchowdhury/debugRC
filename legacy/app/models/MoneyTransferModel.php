<?php
// app/models/MoneyTransferModel.php

require_once __DIR__ . '/../helpers/Helper.php';
require_once __DIR__ . '/BankModel.php';
require_once __DIR__ . '/JournalEntryModel.php';
require_once __DIR__ . '/../services/Accounting/JournalPostingService.php';
require_once __DIR__ . '/../services/Branch/BranchIntercompanyService.php';

class MoneyTransferModel extends Helper {

    public function __construct() {
        parent::__construct();
    }

    public function getBanks(): array
    {
        return $this->Get_All_Active_Bank() ?: [];
    }

    public function getAllBranches(): array
    {
        return $this->Get_All_Active_Branches() ?: [];
    }

    public function getBranchName($branchId): ?string
    {
        if (!$branchId) {
            return null;
        }
        $this->db->query('SELECT branch_name FROM branches WHERE id = :id LIMIT 1');
        $this->db->bind(':id', $branchId);
        $row = $this->db->single();

        return $row['branch_name'] ?? null;
    }

    /**
     * Resolve from/to branch ids per transfer type (for GL, cash ledger, and demand settlement).
     */
    private function resolveTransferBranches(array $post, string $transferType, int $sessionBranchId): array
    {
        $type = strtolower(trim($transferType));
        $from = $sessionBranchId;
        $to = $sessionBranchId;

        switch ($type) {
            case 'cash_to_bank':
                $from = $sessionBranchId;
                $to = (int)($post['to_branch_id'] ?? $sessionBranchId);
                break;

            case 'bank_to_cash':
                $from = $sessionBranchId;
                $to = $sessionBranchId;
                break;

            case 'cash_to_cash':
                $from = $sessionBranchId;
                $to = (int)($post['to_branch_id'] ?? 0);
                if ($to <= 0) {
                    throw new Exception('Select destination branch for inter-branch cash transfer');
                }
                if ($to === $from) {
                    throw new Exception('Destination branch must differ from your branch');
                }
                break;

            case 'bank_to_bank':
                $from = $sessionBranchId;
                $to = (int)($post['to_branch_id'] ?? $sessionBranchId);
                break;

            default:
                throw new Exception('Invalid transfer type');
        }

        if ($from <= 0 || $to <= 0) {
            throw new Exception('Invalid branch context');
        }

        return ['from_branch_id' => $from, 'to_branch_id' => $to];
    }

    public function createTransfer($post) {
        $this->db->beginTransaction();
        try {
            $sessionBranchId = self::sessionBranchId() ?: (int)($_SESSION['branch_id'] ?? 0);
            if ($sessionBranchId <= 0) {
                $sessionBranchId = (int)($_SESSION['branch_id'] ?? 1);
            }
            $user_id = (int)($_SESSION['user_id'] ?? 1);

            $amount = round((float)($post['amount'] ?? 0), 2);
            $transfer_type = strtolower(trim((string)($post['transfer_type'] ?? '')));

            if ($amount <= 0) {
                throw new Exception('Invalid amount');
            }

            $allowed = ['cash_to_bank', 'bank_to_cash', 'cash_to_cash', 'bank_to_bank'];
            if (!in_array($transfer_type, $allowed, true)) {
                throw new Exception('Invalid transfer type');
            }

            if ($transfer_type === 'cash_to_bank' && empty($post['to_bank_id'])) {
                throw new Exception('Select destination bank account');
            }
            if ($transfer_type === 'bank_to_cash' && empty($post['from_bank_id'])) {
                throw new Exception('Select source bank account');
            }
            if ($transfer_type === 'bank_to_bank') {
                if (empty($post['from_bank_id']) || empty($post['to_bank_id'])) {
                    throw new Exception('Select both bank accounts');
                }
                if ((int)$post['from_bank_id'] === (int)$post['to_bank_id']) {
                    throw new Exception('Source and destination bank must differ');
                }
            }

            $branches = $this->resolveTransferBranches($post, $transfer_type, $sessionBranchId);
            $from_branch_id = $branches['from_branch_id'];
            $to_branch_id = $branches['to_branch_id'];

            $transfer_code = $this->generateMoneyTransferCode($from_branch_id);

            $this->db->query("
                INSERT INTO money_transfers
                (transfer_code, transfer_date, transfer_type,
                 from_bank_id, to_bank_id, from_branch_id, to_branch_id,
                 amount, narration, created_by, branch_id, from_cash_point, to_cash_point)
                VALUES
                (:code, :date, :type, :from_bank, :to_bank, :fbranch, :tbranch,
                 :amount, :narration, :uid, :bid, :fpoint, :tpoint)
            ");

            $this->db->bind(':code', $transfer_code);
            $this->db->bind(':date', $post['transfer_date'] ?? date('Y-m-d'));
            $this->db->bind(':type', $transfer_type);
            $this->db->bind(':from_bank', !empty($post['from_bank_id']) ? (int)$post['from_bank_id'] : null);
            $this->db->bind(':to_bank', !empty($post['to_bank_id']) ? (int)$post['to_bank_id'] : null);
            $this->db->bind(':fbranch', $from_branch_id);
            $this->db->bind(':tbranch', $to_branch_id);
            $this->db->bind(':amount', $amount);
            $this->db->bind(':narration', $post['narration'] ?? '');
            $this->db->bind(':uid', $user_id);
            $this->db->bind(':bid', $sessionBranchId);
            $this->db->bind(':fpoint', $post['from_cash_point'] ?? 'main_cash');
            $this->db->bind(':tpoint', $post['to_cash_point'] ?? 'main_cash');
            $this->db->execute();

            $transfer_id = (int)$this->db->lastInsertId();

            $transferPayload = [
                'transfer_code'    => $transfer_code,
                'transfer_date'    => $post['transfer_date'] ?? date('Y-m-d'),
                'transfer_type'    => $transfer_type,
                'from_bank_id'     => !empty($post['from_bank_id']) ? (int)$post['from_bank_id'] : null,
                'to_bank_id'       => !empty($post['to_bank_id']) ? (int)$post['to_bank_id'] : null,
                'from_branch_id'   => $from_branch_id,
                'to_branch_id'     => $to_branch_id,
                'amount'           => $amount,
                'narration'        => $post['narration'] ?? '',
            ];

            $journalService = new JournalPostingService($this->db);
            $journalResult = $journalService->postMoneyTransfer($transfer_id, $transferPayload);
            if (($journalResult['status'] ?? '') === 'error') {
                throw new Exception('Journal posting failed: ' . ($journalResult['message'] ?? 'unknown'));
            }

            $journalEntryId = null;
            if (!empty($journalResult['journal_entry_id'])) {
                $journalEntryId = (int)$journalResult['journal_entry_id'];
                $this->db->query('UPDATE money_transfers SET journal_entry_id = :jeid WHERE id = :tid');
                $this->db->bind(':jeid', $journalEntryId);
                $this->db->bind(':tid', $transfer_id);
                $this->db->execute();
            }

            $this->syncBankBalancesForTransfer($transferPayload, false);
            $this->recordCashLedger($transfer_id, $transferPayload);

            $intercompany = new BranchIntercompanyService($this->db);
            $settleResult = $intercompany->settleFromMoneyTransfer($transfer_id, $transferPayload);
            if (($settleResult['status'] ?? '') === 'error') {
                throw new Exception('Branch demand settlement failed: ' . ($settleResult['message'] ?? ''));
            }

            $this->db->commitOrFail();

            $settlementNote = '';
            if (($settleResult['status'] ?? '') === 'success' && !empty($settleResult['message'])) {
                $settlementNote = ' ' . $settleResult['message'];
            }

            return [
                'status'            => 'success',
                'message'           => 'Money transferred — GL and cash/bank updated.' . $settlementNote,
                'transfer_code'     => $transfer_code,
                'transfer_id'       => $transfer_id,
                'transfer_type'     => $transfer_type,
                'amount'            => $amount,
                'journal_entry_id'  => $journalEntryId,
                'settlement'        => $settleResult,
            ];

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            error_log('Money Transfer Error: ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /** Globally unique money transfer code. */
    public function generateMoneyTransferCode(int $branchId = 0): string
    {
        $period = date('Ymd');
        $n = $this->allocateDocumentSequence('money_transfer', $period);

        return 'MT-' . $period . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    }

    private function syncBankBalancesForTransfer(array $transfer, bool $undo = false): void
    {
        $type = strtolower((string)($transfer['transfer_type'] ?? ''));
        $amount = (float)($transfer['amount'] ?? 0);
        if ($amount <= 0) {
            return;
        }

        $bankModel = new BankModel($this->db);

        switch ($type) {
            case 'cash_to_bank':
                $bankId = (int)($transfer['to_bank_id'] ?? 0);
                if ($bankId > 0) {
                    $increase = !$undo;
                    $bankModel->updateBalance($bankId, $amount, $increase ? 'credit' : 'debit');
                }
                break;

            case 'bank_to_cash':
                $bankId = (int)($transfer['from_bank_id'] ?? 0);
                if ($bankId > 0) {
                    $increase = false;
                    if ($undo) {
                        $increase = true;
                    }
                    $bankModel->updateBalance($bankId, $amount, $increase ? 'credit' : 'debit');
                }
                break;

            case 'bank_to_bank':
                $fromBank = (int)($transfer['from_bank_id'] ?? 0);
                $toBank = (int)($transfer['to_bank_id'] ?? 0);
                if ($fromBank > 0) {
                    $bankModel->updateBalance($fromBank, $amount, $undo ? 'credit' : 'debit');
                }
                if ($toBank > 0) {
                    $bankModel->updateBalance($toBank, $amount, $undo ? 'debit' : 'credit');
                }
                break;
        }
    }

    private function recordCashLedger(int $transferId, array $transfer): void
    {
        $type = strtolower((string)($transfer['transfer_type'] ?? ''));
        $amount = (float)($transfer['amount'] ?? 0);
        $from = (int)($transfer['from_branch_id'] ?? 0);
        $to = (int)($transfer['to_branch_id'] ?? 0);

        switch ($type) {
            case 'cash_to_bank':
                $this->updateCashLedger($from, 'main_cash', $amount, 0, 'money_transfer', $transferId, 'Cash to bank deposit');
                break;

            case 'bank_to_cash':
                $this->updateCashLedger($to, 'main_cash', 0, $amount, 'money_transfer', $transferId, 'Bank to cash withdrawal');
                break;

            case 'cash_to_cash':
                $this->updateCashLedger($from, 'main_cash', $amount, 0, 'money_transfer', $transferId, 'Inter-branch cash send');
                $this->updateCashLedger($to, 'main_cash', 0, $amount, 'money_transfer', $transferId, 'Inter-branch cash receive');
                break;
        }
    }

    private function reverseCashLedgerForTransfer(array $transfer, int $transferId): void
    {
        $this->db->query("
            UPDATE cash_ledger
            SET is_reversed = 1, reversed_at = NOW()
            WHERE reference_type = 'money_transfer'
              AND reference_id = :tid
              AND COALESCE(is_reversed, 0) = 0
        ");
        $this->db->bind(':tid', $transferId);
        $this->db->execute();

        $type = strtolower((string)($transfer['transfer_type'] ?? ''));
        $amount = (float)($transfer['amount'] ?? 0);
        $from = (int)($transfer['from_branch_id'] ?? 0);
        $to = (int)($transfer['to_branch_id'] ?? 0);

        switch ($type) {
            case 'cash_to_bank':
                $this->updateCashLedger($from, 'main_cash', 0, $amount, 'reversal', $transferId, 'Reversal: cash to bank');
                break;
            case 'bank_to_cash':
                $this->updateCashLedger($to, 'main_cash', $amount, 0, 'reversal', $transferId, 'Reversal: bank to cash');
                break;
            case 'cash_to_cash':
                $this->updateCashLedger($from, 'main_cash', 0, $amount, 'reversal', $transferId, 'Reversal: cash send');
                $this->updateCashLedger($to, 'main_cash', $amount, 0, 'reversal', $transferId, 'Reversal: cash receive');
                break;
        }
    }

    public function reverseTransfer($id, $reason) {
        $this->db->beginTransaction();
        try {
            $reason = trim((string)$reason);
            if (strlen($reason) < 3) {
                throw new Exception('Reversal reason is required (min 3 characters)');
            }

            $user_id = (int)($_SESSION['user_id'] ?? 1);

            $this->db->query('SELECT * FROM money_transfers WHERE id = :id AND is_reversed = 0');
            $this->db->bind(':id', $id);
            $transfer = $this->db->single();

            if (!$transfer) {
                throw new Exception('Transfer not found or already reversed');
            }

            if (!$this->userCanAccessTransfer($transfer)) {
                throw new Exception('You do not have access to reverse this transfer');
            }

            $amount = (float)$transfer['amount'];
            $transferPayload = [
                'transfer_type'    => $transfer['transfer_type'],
                'from_bank_id'     => $transfer['from_bank_id'],
                'to_bank_id'       => $transfer['to_bank_id'],
                'from_branch_id'   => $transfer['from_branch_id'],
                'to_branch_id'     => $transfer['to_branch_id'],
                'amount'           => $amount,
            ];

            $settleRev = (new BranchIntercompanyService($this->db))->reverseMoneyTransferSettlements((int)$id);
            if (($settleRev['status'] ?? '') === 'error') {
                throw new Exception($settleRev['message'] ?? 'Failed to reverse branch demand settlements');
            }

            $jeId = (int)($transfer['journal_entry_id'] ?? 0);
            if ($jeId > 0) {
                $rev = (new JournalPostingService($this->db))->reverseLinkedJournal(
                    $jeId,
                    'Money transfer reversal: ' . ($transfer['transfer_code'] ?? $id) . ' — ' . $reason
                );
                if (($rev['status'] ?? '') === 'error') {
                    throw new Exception('Failed to reverse transfer journal: ' . ($rev['message'] ?? ''));
                }
            } else {
                $journalModel = new JournalEntryModel();
                $originalJournalId = $journalModel->findJournalEntryByReference('money_transfer', (int)$id);
                if ($originalJournalId) {
                    $rev = $journalModel->createReversingEntry($originalJournalId, $reason);
                    if (($rev['status'] ?? '') === 'error') {
                        throw new Exception('Reversing journal failed: ' . ($rev['message'] ?? ''));
                    }
                }
            }

            $this->syncBankBalancesForTransfer($transferPayload, true);
            $this->reverseCashLedgerForTransfer($transfer, (int)$id);

            $this->db->query('
                UPDATE money_transfers
                SET is_reversed = 1, reversed_at = NOW(), reversed_by = :uid, reverse_reason = :reason
                WHERE id = :id
            ');
            $this->db->bind(':uid', $user_id);
            $this->db->bind(':reason', $reason);
            $this->db->bind(':id', $id);
            $this->db->execute();

            $this->db->commit();

            return [
                'status'        => 'success',
                'message'       => 'Transfer reversed. GL, cash/bank, and branch demand settlements undone.',
                'transfer_code' => $transfer['transfer_code'] ?? null,
                'transfer_type' => $transfer['transfer_type'] ?? null,
                'amount'        => $amount,
                'transfer_id'   => (int)$id,
                'redirect_url'  => (defined('BASE_URL') ? BASE_URL : '') . 'MoneyTransfer/details/' . (int)$id,
            ];

        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }
            error_log('Money Transfer Reverse Error (ID: ' . $id . '): ' . $e->getMessage());

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    public function userCanAccessTransfer(?array $transfer): bool
    {
        if (!$transfer) {
            return false;
        }
        if ($this->canOverrideBranch()) {
            return true;
        }

        $sessionBranch = self::sessionBranchId();

        return $sessionBranch > 0 && (
            (int)($transfer['from_branch_id'] ?? 0) === $sessionBranch
            || (int)($transfer['to_branch_id'] ?? 0) === $sessionBranch
            || (int)($transfer['branch_id'] ?? 0) === $sessionBranch
        );
    }

    public function canUserReverseTransfer(?array $transfer): bool
    {
        if (!$transfer || !empty($transfer['is_reversed'])) {
            return false;
        }

        return $this->userCanAccessTransfer($transfer);
    }

    public function getCashLedgerForTransfer(int $transferId): array
    {
        try {
            $this->db->query("
                SELECT id, transaction_date, branch_id, cash_point, reference_type, reference_id,
                       debit, credit, running_balance, remarks, COALESCE(is_reversed, 0) AS is_reversed
                FROM cash_ledger
                WHERE reference_type IN ('money_transfer', 'reversal')
                  AND reference_id = :tid
                ORDER BY id ASC
            ");
            $this->db->bind(':tid', $transferId);

            return $this->db->resultSet() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getBranchLedgerForTransfer(int $transferId): array
    {
        try {
            $this->db->query("
                SELECT bl.*, fb.branch_name AS from_branch_name, tb.branch_name AS to_branch_name
                FROM branch_ledger bl
                LEFT JOIN branches fb ON fb.id = bl.from_branch_id
                LEFT JOIN branches tb ON tb.id = bl.to_branch_id
                WHERE bl.reference_type = 'demand_settlement'
                  AND bl.reference_id = :tid
                ORDER BY bl.id ASC
            ");
            $this->db->bind(':tid', $transferId);

            return $this->db->resultSet() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function transferTypeLabel(string $type): string
    {
        return match (strtolower(trim($type))) {
            'cash_to_bank' => 'Cash → Bank',
            'bank_to_cash' => 'Bank → Cash',
            'cash_to_cash' => 'Cash → Cash (inter-branch)',
            'bank_to_bank' => 'Bank → Bank',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    public function getTransferAccountsSummary(array $transfer): array
    {
        $type = strtolower((string)($transfer['transfer_type'] ?? ''));
        $from = 'Cash';
        $to = 'Cash';

        switch ($type) {
            case 'cash_to_bank':
                $from = 'Cash (' . ($transfer['from_branch_name'] ?? '') . ')';
                $to = trim(($transfer['to_bank'] ?? '') . ' — ' . ($transfer['to_branch_name'] ?? ''));
                break;
            case 'bank_to_cash':
                $from = (string)($transfer['from_bank'] ?? 'Bank');
                $to = 'Cash (' . ($transfer['to_branch_name'] ?? '') . ')';
                break;
            case 'cash_to_cash':
                $from = 'Cash (' . ($transfer['from_branch_name'] ?? '') . ')';
                $to = 'Cash (' . ($transfer['to_branch_name'] ?? '') . ')';
                break;
            case 'bank_to_bank':
                $from = (string)($transfer['from_bank'] ?? 'Bank');
                $to = (string)($transfer['to_bank'] ?? 'Bank');
                break;
        }

        return ['from' => $from, 'to' => $to];
    }

    public function getTransferSettlements(int $transferId): array
    {
        try {
            $this->db->query("
                SELECT mts.*, bd.demand_code, bd.status AS demand_status,
                       bd.total_value, bd.settlement_amount AS demand_settlement_total
                FROM money_transfer_settlements mts
                JOIN branch_demands bd ON bd.id = mts.demand_id
                WHERE mts.transfer_id = :tid
                ORDER BY mts.id ASC
            ");
            $this->db->bind(':tid', $transferId);

            return $this->db->resultSet() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getJournalEntryForTransfer(int $transferId): ?array
    {
        $this->db->query('SELECT journal_entry_id FROM money_transfers WHERE id = :id');
        $this->db->bind(':id', $transferId);
        $row = $this->db->single();
        $jeId = (int)($row['journal_entry_id'] ?? 0);

        if ($jeId <= 0) {
            $journalModel = new JournalEntryModel();
            $jeId = (int)($journalModel->findJournalEntryByReference('money_transfer', $transferId) ?: 0);
        }

        if ($jeId <= 0) {
            return null;
        }

        return (new JournalEntryModel())->getEntryWithLines($jeId);
    }

    public function getTransferById($id) {
        $this->db->query("
            SELECT mt.*,
                   f.bank_name AS from_bank, f.account_number AS from_bank_account,
                   t.bank_name AS to_bank, t.account_number AS to_bank_account,
                   fb.branch_name AS from_branch_name,
                   tb.branch_name AS to_branch_name,
                   u.username AS created_by_name,
                   ru.username AS reversed_by_name,
                   br.branch_name AS session_branch_name
            FROM money_transfers mt
            LEFT JOIN banks f ON mt.from_bank_id = f.id
            LEFT JOIN banks t ON mt.to_bank_id = t.id
            JOIN branches fb ON mt.from_branch_id = fb.id
            JOIN branches tb ON mt.to_branch_id = tb.id
            LEFT JOIN branches br ON mt.branch_id = br.id
            LEFT JOIN users u ON mt.created_by = u.id
            LEFT JOIN users ru ON mt.reversed_by = ru.id
            WHERE mt.id = :id
        ");
        $this->db->bind(':id', $id);

        return $this->db->single();
    }

    public function getMoneyTransferIndexStats(?int $branchId = null): array
    {
        $stats = ['total' => 0, 'active' => 0, 'reversed' => 0, 'today' => 0.0, 'this_month' => 0.0];

        $branchSql = '';
        $bind = [];
        if ($branchId > 0) {
            $branchSql = ' AND (from_branch_id = :bid OR to_branch_id = :bid OR branch_id = :bid)';
            $bind[':bid'] = $branchId;
        }

        foreach (['total' => '1=1', 'active' => 'is_reversed = 0', 'reversed' => 'is_reversed = 1'] as $key => $cond) {
            $this->db->query("SELECT COUNT(*) AS c FROM money_transfers WHERE {$cond}{$branchSql}");
            foreach ($bind as $k => $v) {
                $this->db->bind($k, $v);
            }
            $stats[$key] = (int)($this->db->single()['c'] ?? 0);
        }

        $this->db->query("
            SELECT COALESCE(SUM(amount), 0) AS total FROM money_transfers
            WHERE is_reversed = 0 AND transfer_date = CURDATE(){$branchSql}
        ");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['today'] = (float)($this->db->single()['total'] ?? 0);

        $this->db->query("
            SELECT COALESCE(SUM(amount), 0) AS total FROM money_transfers
            WHERE is_reversed = 0 AND transfer_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01'){$branchSql}
        ");
        foreach ($bind as $k => $v) {
            $this->db->bind($k, $v);
        }
        $stats['this_month'] = (float)($this->db->single()['total'] ?? 0);

        return $stats;
    }

    public function getTransfersForDataTable(array $params): array
    {
        $start       = (int)($params['start'] ?? 0);
        $length      = (int)($params['length'] ?? 25);
        $searchValue = trim($params['search']['value'] ?? '');
        $orderColumn = (int)($params['order'][0]['column'] ?? 0);
        $orderDir    = strtolower($params['order'][0]['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

        $fromDate         = $params['fromDate'] ?? '';
        $toDate           = $params['toDate'] ?? '';
        $filterType       = $params['filterType'] ?? '';
        $filterFromBranch = $params['filterFromBranch'] ?? '';
        $filterToBranch   = $params['filterToBranch'] ?? '';
        $filterStatus     = $params['filterStatus'] ?? '';
        $reversedMode     = $params['reversedMode'] ?? '';
        $listBranchId     = isset($params['listBranchId']) ? (int)$params['listBranchId'] : null;

        $columns = [
            'mt.transfer_date',
            'mt.transfer_code',
            'mt.transfer_type',
            'mt.amount',
            'fb.branch_name',
            'tb.branch_name',
            'mt.is_reversed',
        ];

        $baseQuery = "
            FROM money_transfers mt
            LEFT JOIN banks f ON mt.from_bank_id = f.id
            LEFT JOIN banks t ON mt.to_bank_id = t.id
            JOIN branches fb ON mt.from_branch_id = fb.id
            JOIN branches tb ON mt.to_branch_id = tb.id
            LEFT JOIN users u ON mt.created_by = u.id
        ";

        $where = [];
        $bindParams = [];

        if ($listBranchId > 0) {
            $where[] = '(mt.from_branch_id = :list_branch OR mt.to_branch_id = :list_branch OR mt.branch_id = :list_branch)';
            $bindParams[':list_branch'] = $listBranchId;
        } elseif (!$this->canOverrideBranch() && self::sessionBranchId() > 0) {
            $sid = self::sessionBranchId();
            $where[] = '(mt.from_branch_id = :list_branch OR mt.to_branch_id = :list_branch OR mt.branch_id = :list_branch)';
            $bindParams[':list_branch'] = $sid;
        }

        if ($fromDate && $toDate && $fromDate === $toDate) {
            $where[] = 'mt.transfer_date = :pay_date';
            $bindParams[':pay_date'] = $fromDate;
        } else {
            if ($fromDate) {
                $where[] = 'mt.transfer_date >= :from_date';
                $bindParams[':from_date'] = $fromDate;
            }
            if ($toDate) {
                $where[] = 'mt.transfer_date <= :to_date';
                $bindParams[':to_date'] = $toDate;
            }
            if (!$fromDate && !$toDate) {
                $where[] = 'mt.transfer_date = CURDATE()';
            }
        }

        if ($filterType) {
            $where[] = 'mt.transfer_type = :transfer_type';
            $bindParams[':transfer_type'] = $filterType;
        }
        if ($filterFromBranch) {
            $where[] = 'mt.from_branch_id = :from_branch';
            $bindParams[':from_branch'] = $filterFromBranch;
        }
        if ($filterToBranch) {
            $where[] = 'mt.to_branch_id = :to_branch';
            $bindParams[':to_branch'] = $filterToBranch;
        }

        if ($reversedMode === 'only_reversed') {
            $where[] = 'mt.is_reversed = 1';
        } elseif ($filterStatus === 'active') {
            $where[] = 'mt.is_reversed = 0';
        } elseif ($filterStatus === 'reversed') {
            $where[] = 'mt.is_reversed = 1';
        }

        if ($searchValue !== '') {
            $where[] = '(mt.transfer_code LIKE :search OR mt.narration LIKE :search)';
            $bindParams[':search'] = '%' . $searchValue . '%';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countQuery = "SELECT COUNT(mt.id) AS total {$baseQuery} {$whereSql}";
        $this->db->query($countQuery);
        foreach ($bindParams as $key => $val) {
            $this->db->bind($key, $val);
        }
        $recordsFiltered = (int)($this->db->single()['total'] ?? 0);

        $orderBy = $columns[$orderColumn] ?? 'mt.transfer_date';
        $dataQuery = "
            SELECT mt.id, mt.transfer_code, mt.transfer_date, mt.transfer_type, mt.amount,
                   mt.narration, mt.is_reversed, mt.from_branch_id, mt.to_branch_id,
                   f.bank_name AS from_bank, t.bank_name AS to_bank,
                   fb.branch_name AS from_branch_name, tb.branch_name AS to_branch_name
            {$baseQuery} {$whereSql}
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
            'recordsTotal'    => $recordsFiltered,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $data,
        ];
    }

    private function updateCashLedger($branch_id, $cash_point, $debit, $credit, $reference_type, $reference_id, $remarks) {
        if (!$branch_id) {
            return;
        }

        $this->db->query("
            SELECT COALESCE(running_balance, 0) AS balance
            FROM cash_ledger
            WHERE branch_id = :bid AND cash_point = :point
            ORDER BY id DESC LIMIT 1
        ");
        $this->db->bind(':bid', $branch_id);
        $this->db->bind(':point', $cash_point);
        $row = $this->db->single();
        $current = (float)($row['balance'] ?? 0);
        $new_balance = $current + $credit - $debit;

        $this->db->query("
            INSERT INTO cash_ledger
            (transaction_date, branch_id, cash_point, reference_type, reference_id,
             debit, credit, running_balance, remarks, created_by)
            VALUES (CURDATE(), :bid, :point, :ref_type, :ref_id, :debit, :credit, :bal, :rem, :uid)
        ");

        $this->db->bind(':bid', $branch_id);
        $this->db->bind(':point', $cash_point);
        $this->db->bind(':ref_type', $reference_type);
        $this->db->bind(':ref_id', $reference_id);
        $this->db->bind(':debit', $debit);
        $this->db->bind(':credit', $credit);
        $this->db->bind(':bal', $new_balance);
        $this->db->bind(':rem', $remarks);
        $this->db->bind(':uid', $_SESSION['user_id'] ?? 1);
        $this->db->execute();

        $this->db->query("
            UPDATE branch_cash
            SET balance = :new_balance, last_updated = NOW()
            WHERE branch_id = :bid AND cash_point = :point
        ");
        $this->db->bind(':new_balance', $new_balance);
        $this->db->bind(':bid', $branch_id);
        $this->db->bind(':point', $cash_point);
        $this->db->execute();
    }
}
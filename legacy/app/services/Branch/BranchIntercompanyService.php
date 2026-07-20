<?php
// app/services/Branch/BranchIntercompanyService.php
// Inter-branch demand ledger, settlement (FIFO), and GL posting.

require_once __DIR__ . '/../../../core/Database.php';
require_once __DIR__ . '/../Accounting/JournalPostingService.php';

class BranchIntercompanyService
{
    private Database $db;
    private JournalPostingService $journal;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? new Database();
        $this->journal = new JournalPostingService($this->db);
    }

    /**
     * Record stock transfer principal: debtor (requester) owes creditor (supplier).
     */
    public function recordDemandTransfer(
        int $demandId,
        int $debtorBranchId,
        int $creditorBranchId,
        float $amount,
        string $transactionDate,
        ?int $journalEntryIdCreditor = null,
        ?int $journalEntryIdDebtor = null
    ): void {
        if ($amount <= 0) {
            return;
        }

        $remarks = "Demand #{$demandId} — stock transfer principal";
        $running = $this->nextRunningBalance($debtorBranchId, $creditorBranchId, $amount, false);

        $this->insertLedgerPair(
            $debtorBranchId,
            $creditorBranchId,
            $amount,
            'demand_transfer',
            $demandId,
            $remarks,
            $transactionDate,
            $running,
            false,
            $journalEntryIdCreditor
        );
    }

    /**
     * Post fulfillment journals (inventory + inter-branch due) on both branches.
     *
     * @return array{creditor_journal_id:?int,debtor_journal_id:?int,status:string,message?:string}
     */
    public function postDemandFulfillmentJournals(
        int $demandId,
        int $debtorBranchId,
        int $creditorBranchId,
        float $amount,
        string $entryDate
    ): array {
        $creditor = $this->journal->postBranchDemandFulfillment(
            $demandId,
            $creditorBranchId,
            $debtorBranchId,
            $amount,
            $entryDate,
            'creditor'
        );
        if (($creditor['status'] ?? '') === 'error') {
            return $creditor;
        }

        $debtor = $this->journal->postBranchDemandFulfillment(
            $demandId,
            $debtorBranchId,
            $creditorBranchId,
            $amount,
            $entryDate,
            'debtor'
        );
        if (($debtor['status'] ?? '') === 'error') {
            return $debtor;
        }

        return [
            'status'              => 'success',
            'creditor_journal_id' => $creditor['journal_entry_id'] ?? null,
            'debtor_journal_id'   => $debtor['journal_entry_id'] ?? null,
        ];
    }

    /**
     * Allocate pool to open demands (FIFO) and post branch ledger + settlement journals.
     *
     * @return array{status:string,allocated:float,allocations:array,message?:string}
     */
    public function allocateSettlement(
        int $debtorBranchId,
        int $creditorBranchId,
        float $poolAmount,
        string $sourceType,
        int $sourceId,
        string $transactionDate,
        string $remarksPrefix
    ): array {
        if ($poolAmount <= 0) {
            return ['status' => 'success', 'allocated' => 0.0, 'allocations' => []];
        }

        $demands = $this->getOpenDemands($debtorBranchId, $creditorBranchId);
        $remaining = $poolAmount;
        $allocations = [];

        foreach ($demands as $demand) {
            if ($remaining <= 0.0001) {
                break;
            }

            $outstanding = max(
                0.0,
                (float)$demand['total_value'] - (float)($demand['settlement_amount'] ?? 0)
            );
            if ($outstanding <= 0.0001) {
                continue;
            }

            $apply = min($remaining, $outstanding);
            $demandId = (int)$demand['id'];

            $this->applyDemandSettlement(
                $demandId,
                $debtorBranchId,
                $creditorBranchId,
                $apply,
                $sourceType,
                $sourceId,
                $transactionDate,
                $remarksPrefix . " — Demand #{$demandId}"
            );

            $allocations[] = ['demand_id' => $demandId, 'settled_amount' => $apply];
            $remaining -= $apply;
        }

        return [
            'status'      => 'success',
            'allocated'   => $poolAmount - $remaining,
            'allocations' => $allocations,
            'unapplied'   => $remaining,
        ];
    }

    /**
     * Bank customer payment at debtor branch → settle inter-branch demands (FIFO).
     */
    public function settleFromCustomerPayment(int $paymentId, array $payment): array
    {
        $mode = strtolower((string)($payment['payment_mode'] ?? 'cash'));
        if ($mode !== 'bank') {
            return ['status' => 'skipped', 'message' => 'Cash payments settle via inter-branch money transfer.'];
        }

        $debtorBranchId = (int)($payment['branch_id'] ?? 0);
        $amount = (float)($payment['amount'] ?? 0);
        if ($debtorBranchId <= 0 || $amount <= 0) {
            return ['status' => 'skipped', 'message' => 'Invalid branch or amount.'];
        }

        $creditors = $this->getCreditorBranchesWithOpenDemands($debtorBranchId);
        $totalAllocated = 0.0;
        $allAllocations = [];
        $remaining = $amount;

        foreach ($creditors as $creditorBranchId) {
            if ($remaining <= 0.0001) {
                break;
            }
            $result = $this->allocateSettlement(
                $debtorBranchId,
                $creditorBranchId,
                $remaining,
                'customer_payment',
                $paymentId,
                (string)($payment['payment_date'] ?? date('Y-m-d')),
                'Customer Payment Settlement (Payment #' . ($payment['payment_code'] ?? $paymentId) . ')'
            );
            $totalAllocated += (float)($result['allocated'] ?? 0);
            $allAllocations = array_merge($allAllocations, $result['allocations'] ?? []);
            $remaining = (float)($result['unapplied'] ?? 0);
        }

        return [
            'status'      => 'success',
            'allocated'   => $totalAllocated,
            'allocations' => $allAllocations,
        ];
    }

    /**
     * Inter-branch money movement → FIFO settle branch demands (debtor = from, creditor = to).
     *
     * Applies when the paying branch (from) sends funds to the receiving branch (to):
     * - cash_to_cash: primary path (branch cash remittance)
     * - cash_to_bank: cash leaves from_branch, deposited at to_branch's bank
     *
     * bank_to_cash / bank_to_bank at the same branch do not settle demands (use cash_to_cash).
     */
    public function settleFromMoneyTransfer(int $transferId, array $transfer): array
    {
        $fromBranch = (int)($transfer['from_branch_id'] ?? 0);
        $toBranch = (int)($transfer['to_branch_id'] ?? 0);
        $amount = (float)($transfer['amount'] ?? 0);
        $type = strtolower((string)($transfer['transfer_type'] ?? ''));

        if ($fromBranch <= 0 || $toBranch <= 0 || $fromBranch === $toBranch || $amount <= 0) {
            return ['status' => 'skipped', 'message' => 'Same branch — no inter-branch demand to settle.'];
        }

        if (!in_array($type, ['cash_to_cash', 'cash_to_bank'], true)) {
            return [
                'status'  => 'skipped',
                'message' => 'Only Cash→Cash (inter-branch) or Cash→Bank (other branch) auto-settle branch demands.',
            ];
        }

        $result = $this->allocateSettlement(
            $fromBranch,
            $toBranch,
            $amount,
            'money_transfer',
            $transferId,
            (string)($transfer['transfer_date'] ?? date('Y-m-d')),
            'Money Transfer Settlement (MT #' . ($transfer['transfer_code'] ?? $transferId) . ')'
        );

        if (($result['status'] ?? '') === 'success') {
            $result['message'] = $this->formatSettlementSummary($result, $amount);
        }

        return $result;
    }

    /**
     * Preview open demands that would be settled (FIFO) for a proposed transfer.
     *
     * @return array{status:string,demands:array,total_outstanding:float,preview_allocations:array}
     */
    public function previewDemandSettlement(int $fromBranchId, int $toBranchId, float $amount): array
    {
        if ($fromBranchId <= 0 || $toBranchId <= 0 || $fromBranchId === $toBranchId || $amount <= 0) {
            return ['status' => 'skipped', 'demands' => [], 'total_outstanding' => 0.0, 'preview_allocations' => []];
        }

        $demands = $this->getOpenDemands($fromBranchId, $toBranchId);
        $remaining = $amount;
        $preview = [];
        $totalOutstanding = 0.0;

        foreach ($demands as $demand) {
            $outstanding = max(
                0.0,
                (float)$demand['total_value'] - (float)($demand['settlement_amount'] ?? 0)
            );
            $totalOutstanding += $outstanding;
            if ($remaining <= 0.0001) {
                continue;
            }
            $apply = min($remaining, $outstanding);
            if ($apply > 0.0001) {
                $preview[] = [
                    'demand_id'       => (int)$demand['id'],
                    'demand_code'     => $demand['demand_code'] ?? '',
                    'outstanding'     => $outstanding,
                    'would_settle'    => $apply,
                ];
                $remaining -= $apply;
            }
        }

        return [
            'status'              => 'success',
            'demands'             => $demands,
            'total_outstanding'   => $totalOutstanding,
            'preview_allocations' => $preview,
            'unapplied'           => $remaining,
        ];
    }

    private function formatSettlementSummary(array $allocateResult, float $transferAmount): string
    {
        $allocated = (float)($allocateResult['allocated'] ?? 0);
        $unapplied = (float)($allocateResult['unapplied'] ?? 0);
        $count = count($allocateResult['allocations'] ?? []);

        if ($count === 0) {
            return 'No open branch demands between these branches for this transfer.';
        }

        $msg = "Settled {$count} demand(s), Tk " . number_format($allocated, 2) . '.';
        if ($unapplied > 0.01) {
            $msg .= ' Unapplied Tk ' . number_format($unapplied, 2) . ' (transfer exceeds outstanding demands).';
        }

        return $msg;
    }

    /**
     * Reverse demand settlements linked to a customer payment.
     */
    public function reverseCustomerPaymentSettlements(int $paymentId): array
    {
        $this->db->query("
            SELECT demand_id, settled_amount
            FROM customer_payment_settlements
            WHERE payment_id = :pid
        ");
        $this->db->bind(':pid', $paymentId);
        $rows = $this->db->resultSet() ?: [];

        if ($rows !== []) {
            $journalRev = $this->reverseAllSettlementJournals($paymentId);
            if (($journalRev['status'] ?? '') === 'error') {
                return $journalRev;
            }
            $this->reverseLedgerByReference('demand_settlement', $paymentId);
        }

        foreach ($rows as $row) {
            $demandId = (int)$row['demand_id'];
            $amt = (float)$row['settled_amount'];

            $this->db->query("
                UPDATE branch_demands
                SET settlement_amount = GREATEST(0, COALESCE(settlement_amount, 0) - :amt),
                    updated_at = NOW()
                WHERE id = :did
            ");
            $this->db->bind(':amt', $amt);
            $this->db->bind(':did', $demandId);
            $this->db->execute();
        }

        $this->db->query('DELETE FROM customer_payment_settlements WHERE payment_id = :pid');
        $this->db->bind(':pid', $paymentId);
        $this->db->execute();

        return ['status' => 'success', 'message' => 'Settlements reversed'];
    }

    public function reverseMoneyTransferSettlements(int $transferId): array
    {
        $this->db->query('SELECT demand_id, settled_amount FROM money_transfer_settlements WHERE transfer_id = :tid');
        $this->db->bind(':tid', $transferId);
        $rows = $this->db->resultSet() ?: [];

        if ($rows !== []) {
            $journalRev = $this->reverseAllSettlementJournals($transferId);
            if (($journalRev['status'] ?? '') === 'error') {
                return $journalRev;
            }
            $this->reverseLedgerByReference('demand_settlement', $transferId);
        }

        foreach ($rows as $row) {
            $demandId = (int)$row['demand_id'];
            $amt = (float)$row['settled_amount'];

            $this->db->query("
                UPDATE branch_demands
                SET settlement_amount = GREATEST(0, COALESCE(settlement_amount, 0) - :amt),
                    updated_at = NOW()
                WHERE id = :did
            ");
            $this->db->bind(':amt', $amt);
            $this->db->bind(':did', $demandId);
            $this->db->execute();
        }

        $this->db->query('DELETE FROM money_transfer_settlements WHERE transfer_id = :tid');
        $this->db->bind(':tid', $transferId);
        $this->db->execute();

        return ['status' => 'success', 'message' => 'Branch demand settlements reversed'];
    }

    private function applyDemandSettlement(
        int $demandId,
        int $debtorBranchId,
        int $creditorBranchId,
        float $amount,
        string $sourceType,
        int $sourceId,
        string $transactionDate,
        string $remarks
    ): void {
        if ($sourceType === 'customer_payment') {
            $this->db->query("
                INSERT INTO customer_payment_settlements (payment_id, demand_id, settled_amount)
                VALUES (:pid, :did, :amt)
            ");
            $this->db->bind(':pid', $sourceId);
            $this->db->bind(':did', $demandId);
            $this->db->bind(':amt', $amount);
            $this->db->execute();
        } elseif ($sourceType === 'money_transfer') {
            $this->db->query("
                INSERT INTO money_transfer_settlements (transfer_id, demand_id, settled_amount)
                VALUES (:tid, :did, :amt)
            ");
            $this->db->bind(':tid', $sourceId);
            $this->db->bind(':did', $demandId);
            $this->db->bind(':amt', $amount);
            $this->db->execute();
        }

        $this->db->query("
            UPDATE branch_demands
            SET settlement_amount = COALESCE(settlement_amount, 0) + :amt,
                updated_at = NOW()
            WHERE id = :did
        ");
        $this->db->bind(':amt', $amount);
        $this->db->bind(':did', $demandId);
        $this->db->execute();

        $running = $this->nextRunningBalance($debtorBranchId, $creditorBranchId, $amount, true);

        $journal = $this->journal->postBranchDemandSettlement(
            $demandId,
            $debtorBranchId,
            $creditorBranchId,
            $amount,
            $transactionDate,
            $sourceType,
            $sourceId
        );

        $journalId = ($journal['status'] ?? '') === 'success' ? ($journal['journal_entry_id'] ?? null) : null;

        $this->insertLedgerPair(
            $debtorBranchId,
            $creditorBranchId,
            $amount,
            'demand_settlement',
            $sourceId,
            $remarks,
            $transactionDate,
            $running,
            true,
            $journalId
        );
    }

    private function insertLedgerPair(
        int $debtorBranchId,
        int $creditorBranchId,
        float $amount,
        string $referenceType,
        int $referenceId,
        string $remarks,
        string $transactionDate,
        float $runningBalance,
        bool $isSettlement,
        ?int $journalEntryId
    ): void {
        $userId = (int)($_SESSION['user_id'] ?? 1);

        if ($isSettlement) {
            $debtorDebit = 0;
            $debtorCredit = $amount;
            $creditorDebit = $amount;
            $creditorCredit = 0;
        } else {
            $debtorDebit = $amount;
            $debtorCredit = 0;
            $creditorDebit = 0;
            $creditorCredit = $amount;
        }

        $this->db->query("
            INSERT INTO branch_ledger
            (transaction_date, from_branch_id, to_branch_id, reference_type, reference_id,
             debit, credit, running_balance, remarks, created_by, journal_entry_id)
            VALUES (:dt, :debtor, :creditor, :rtype, :rid, :debit, :credit, :rb, :rem, :uid, :jid)
        ");
        $this->db->bind(':dt', $transactionDate);
        $this->db->bind(':debtor', $debtorBranchId);
        $this->db->bind(':creditor', $creditorBranchId);
        $this->db->bind(':rtype', $referenceType);
        $this->db->bind(':rid', $referenceId);
        $this->db->bind(':debit', $debtorDebit);
        $this->db->bind(':credit', $debtorCredit);
        $this->db->bind(':rb', $runningBalance);
        $this->db->bind(':rem', $remarks);
        $this->db->bind(':uid', $userId);
        $this->db->bind(':jid', $journalEntryId);
        $this->db->execute();

        $this->db->query("
            INSERT INTO branch_ledger
            (transaction_date, from_branch_id, to_branch_id, reference_type, reference_id,
             debit, credit, running_balance, remarks, created_by, journal_entry_id)
            VALUES (:dt, :debtor, :creditor, :rtype, :rid, :debit, :credit, :rb, :rem, :uid, :jid)
        ");
        $this->db->bind(':dt', $transactionDate);
        $this->db->bind(':debtor', $debtorBranchId);
        $this->db->bind(':creditor', $creditorBranchId);
        $this->db->bind(':rtype', $referenceType);
        $this->db->bind(':rid', $referenceId);
        $this->db->bind(':debit', $creditorDebit);
        $this->db->bind(':credit', $creditorCredit);
        $this->db->bind(':rb', $runningBalance);
        $this->db->bind(':rem', $remarks);
        $this->db->bind(':uid', $userId);
        $this->db->bind(':jid', $journalEntryId);
        $this->db->execute();
    }

    private function nextRunningBalance(
        int $debtorBranchId,
        int $creditorBranchId,
        float $amount,
        bool $isSettlement
    ): float {
        $this->db->query("
            SELECT running_balance
            FROM branch_ledger
            WHERE from_branch_id = :debtor
              AND to_branch_id = :creditor
              AND COALESCE(is_reversed, 0) = 0
              AND running_balance IS NOT NULL
            ORDER BY id DESC
            LIMIT 1
        ");
        $this->db->bind(':debtor', $debtorBranchId);
        $this->db->bind(':creditor', $creditorBranchId);
        $prev = $this->db->single();
        $base = $prev ? (float)$prev['running_balance'] : 0.0;

        if ($isSettlement) {
            return max(0.0, $base - $amount);
        }

        return $base + $amount;
    }

    /** @return list<array> */
    private function getOpenDemands(int $debtorBranchId, int $creditorBranchId): array
    {
        $this->db->query("
            SELECT id, demand_code, total_value, settlement_amount, demand_date
            FROM branch_demands
            WHERE from_branch_id = :debtor
              AND to_branch_id = :creditor
              AND status = 'received'
              AND COALESCE(is_reversed, 0) = 0
              AND COALESCE(total_value, 0) > COALESCE(settlement_amount, 0)
            ORDER BY demand_date ASC, id ASC
        ");
        $this->db->bind(':debtor', $debtorBranchId);
        $this->db->bind(':creditor', $creditorBranchId);

        return $this->db->resultSet();
    }

    /** @return list<int> */
    private function getCreditorBranchesWithOpenDemands(int $debtorBranchId): array
    {
        $this->db->query("
            SELECT DISTINCT to_branch_id AS creditor_id
            FROM branch_demands
            WHERE from_branch_id = :debtor
              AND status = 'received'
              AND COALESCE(is_reversed, 0) = 0
              AND COALESCE(total_value, 0) > COALESCE(settlement_amount, 0)
            ORDER BY to_branch_id ASC
        ");
        $this->db->bind(':debtor', $debtorBranchId);
        $rows = $this->db->resultSet();

        return array_map(static fn($r) => (int)$r['creditor_id'], $rows);
    }

    private function getDemandPair(int $demandId): ?array
    {
        $this->db->query('SELECT from_branch_id, to_branch_id FROM branch_demands WHERE id = :id');
        $this->db->bind(':id', $demandId);

        return $this->db->single() ?: null;
    }

    public function reverseLedgerByReference(string $referenceType, int $referenceId): void
    {
        $this->db->query("
            UPDATE branch_ledger
            SET is_reversed = 1
            WHERE reference_type = :rtype
              AND reference_id = :rid
              AND COALESCE(is_reversed, 0) = 0
        ");
        $this->db->bind(':rtype', $referenceType);
        $this->db->bind(':rid', $referenceId);
        $this->db->execute();
    }

    /**
     * Reverse every GL entry posted for branch-demand settlements on a payment or money transfer.
     * Multiple demands → multiple journals sharing the same reference_id; collect from both
     * journal_entries and branch_ledger before marking ledger rows reversed.
     */
    private function reverseAllSettlementJournals(int $sourceId): array
    {
        $journalIds = [];

        $this->db->query("
            SELECT DISTINCT journal_entry_id AS jid
            FROM branch_ledger
            WHERE reference_type = 'demand_settlement'
              AND reference_id = :rid
              AND journal_entry_id IS NOT NULL
              AND COALESCE(is_reversed, 0) = 0
        ");
        $this->db->bind(':rid', $sourceId);
        foreach ($this->db->resultSet() ?: [] as $row) {
            $jid = (int)($row['jid'] ?? 0);
            if ($jid > 0) {
                $journalIds[$jid] = true;
            }
        }

        $this->db->query("
            SELECT id
            FROM journal_entries
            WHERE reference_type = 'branch_demand_settlement'
              AND reference_id = :rid
              AND COALESCE(is_reversed, 0) = 0
        ");
        $this->db->bind(':rid', $sourceId);
        foreach ($this->db->resultSet() ?: [] as $row) {
            $jid = (int)($row['id'] ?? 0);
            if ($jid > 0) {
                $journalIds[$jid] = true;
            }
        }

        foreach (array_keys($journalIds) as $jeId) {
            $rev = $this->journal->reverseLinkedJournal(
                $jeId,
                'Branch demand settlement reversal — source #' . $sourceId
            );
            if (($rev['status'] ?? '') === 'error') {
                return [
                    'status'  => 'error',
                    'message' => 'Settlement journal reversal failed: ' . ($rev['message'] ?? ''),
                ];
            }
        }

        return ['status' => 'success', 'message' => 'OK'];
    }
}
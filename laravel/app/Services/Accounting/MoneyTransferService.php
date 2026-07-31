<?php

namespace App\Services\Accounting;

use App\Models\Bank;
use App\Models\MoneyTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Money Transfer Service — Phase 4 (Accounts Sub-Ledger).
 *
 * Handles transfers between cash and bank accounts.
 *
 * Transfer types:
 *   - cash_to_bank:  Cash → Bank (Dr Bank / Cr Cash)
 *   - bank_to_cash:  Bank → Cash (Dr Cash / Cr Bank)
 *   - cash_to_cash:  Inter-branch cash transfer (NO GL — same ledger)
 *   - bank_to_bank:  Bank → Bank (Dr Dest Bank / Cr Source Bank)
 *
 * On create (all atomic):
 *   1. GL journal entry (except cash_to_cash)
 *   2. Bank balance sync
 *   3. Cash ledger entry
 *   4. Intercompany settlement (if cross-branch)
 *
 * On reverse:
 *   1. Reverse GL journal entry
 *   2. Undo bank balance sync
 *   3. Reverse cash ledger entry
 *   4. Reverse intercompany entry
 */
class MoneyTransferService
{
    public function __construct(
        private JournalPostingService $journalPosting,
        private JournalReversalService $journalReversal,
        private DocumentSequenceService $documentSequence,
    ) {}

    public const TRANSFER_TYPES = ['cash_to_bank', 'bank_to_cash', 'cash_to_cash', 'bank_to_bank'];

    /**
     * Create a money transfer with GL posting, bank balance sync, and intercompany.
     */
    public function createTransfer(array $data): MoneyTransfer
    {
        $this->validateCreateInput($data);

        $transferCode = $this->generateTransferCode();
        $transferType = $data['transfer_type'];
        $amount = round((float) $data['amount'], 2);
        $fromBranchId = (int) ($data['from_branch_id'] ?? session('branch_id', 1));
        $toBranchId = (int) ($data['to_branch_id'] ?? $fromBranchId);

        return DB::transaction(function () use ($data, $transferCode, $transferType, $amount, $fromBranchId, $toBranchId) {
            // 1. Insert money_transfer record.
            $transferId = DB::table('money_transfers')->insertGetId([
                'transfer_code'  => $transferCode,
                'transfer_date'  => $data['transfer_date'] ?? now()->format('Y-m-d'),
                'transfer_type'  => $transferType,
                'from_branch_id' => $fromBranchId,
                'to_branch_id'   => $toBranchId,
                'from_bank_id'   => $data['from_bank_id'] ?? null,
                'to_bank_id'     => $data['to_bank_id'] ?? null,
                'amount'         => $amount,
                'is_reversed'    => false,
                'notes'          => $data['notes'] ?? null,
                'created_by'     => $data['created_by'] ?? null,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            // 2. Post GL journal entry (except cash_to_cash).
            $journalEntryId = null;
            if ($transferType !== 'cash_to_cash') {
                $journalEntryId = $this->postTransferGL($transferId, $transferType, $amount, $fromBranchId, $data);

                DB::table('money_transfers')->where('id', $transferId)->update([
                    'journal_entry_id' => $journalEntryId,
                    'updated_at' => now(),
                ]);
            }

            // 3. Sync bank balances.
            $this->syncBankBalances($transferType, $amount, $data);

            // 4. Record cash ledger entry.
            $this->recordCashLedger($transferId, $transferType, $amount, $fromBranchId, $toBranchId);

            // 5. Intercompany settlement (if cross-branch).
            $intercompanyJournalId = null;
            if ($fromBranchId !== $toBranchId) {
                $intercompanyJournalId = $this->postIntercompanySettlement(
                    $transferId, $transferType, $amount, $fromBranchId, $toBranchId, $data
                );

                if ($intercompanyJournalId) {
                    DB::table('money_transfers')->where('id', $transferId)->update([
                        'intercompany_journal_entry_id' => $intercompanyJournalId,
                        'updated_at' => now(),
                    ]);
                }
            }

            // 6. Audit log.
            $this->logAudit('money_transfer_created', (int) ($data['created_by'] ?? 0), $transferId, [
                'transfer_code' => $transferCode,
                'transfer_type' => $transferType,
                'amount'        => $amount,
                'from_branch_id' => $fromBranchId,
                'to_branch_id'  => $toBranchId,
                'journal_entry_id' => $journalEntryId,
            ]);

            return MoneyTransfer::with(['fromBranch', 'toBranch', 'fromBank', 'toBank', 'journalEntry'])->find($transferId);
        });
    }

    /**
     * Reverse a money transfer.
     */
    public function reverseTransfer(int $transferId, int $reversedBy, string $reason): MoneyTransfer
    {
        return DB::transaction(function () use ($transferId, $reversedBy, $reason) {
            $transfer = MoneyTransfer::lockForUpdate()->findOrFail($transferId);

            if ($transfer->is_reversed) {
                throw new \RuntimeException('Transfer is already reversed.');
            }

            // 1. Reverse GL journal entry.
            if ($transfer->journal_entry_id) {
                $this->journalReversal->reverseByJournalEntry(
                    $transfer->journal_entry_id, $reversedBy,
                    "Money transfer reversed: {$reason}"
                );
            }

            // 2. Reverse intercompany journal entry.
            if ($transfer->intercompany_journal_entry_id) {
                $this->journalReversal->reverseByJournalEntry(
                    $transfer->intercompany_journal_entry_id, $reversedBy,
                    "Money transfer reversed: {$reason}"
                );
            }

            // 3. Undo bank balance sync.
            $this->syncBankBalances(
                $transfer->transfer_type,
                (float) $transfer->amount,
                [
                    'from_bank_id' => $transfer->from_bank_id,
                    'to_bank_id'   => $transfer->to_bank_id,
                ],
                undo: true
            );

            // 4. Reverse cash ledger entry.
            $this->reverseCashLedger($transferId);

            // 5. Mark as reversed.
            DB::table('money_transfers')->where('id', $transferId)->update([
                'is_reversed'    => true,
                'reversed_at'    => now(),
                'reversed_by'    => $reversedBy,
                'reverse_reason' => $reason,
                'updated_at'     => now(),
            ]);

            // 6. Audit log.
            $this->logAudit('money_transfer_reversed', $reversedBy, $transferId, [
                'transfer_code' => $transfer->transfer_code,
                'transfer_type' => $transfer->transfer_type,
                'amount'        => (float) $transfer->amount,
                'reason'        => $reason,
            ]);

            return MoneyTransfer::find($transferId);
        });
    }

    /**
     * Get filtered transfers for index page.
     */
    public function getFilteredTransfers(array $filters, ?int $branchId = null)
    {
        $query = MoneyTransfer::with(['fromBranch', 'toBranch', 'fromBank', 'toBank'])
            ->when($filters['from_date'] ?? null, fn($q, $d) => $q->where('transfer_date', '>=', $d))
            ->when($filters['to_date'] ?? null, fn($q, $d) => $q->where('transfer_date', '<=', $d))
            ->when($filters['transfer_type'] ?? null, fn($q, $t) => $q->where('transfer_type', $t))
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where('transfer_code', 'ILIKE', "%{$search}%");
            });

        if ($branchId) {
            $query->where(function ($q) use ($branchId) {
                $q->where('from_branch_id', $branchId)
                  ->orWhere('to_branch_id', $branchId);
            });
        }

        return $query->orderBy('transfer_date', 'desc')
                     ->orderBy('id', 'desc')
                     ->paginate(25);
    }

    /**
     * Get stats for index page.
     */
    public function getStats(?int $branchId = null): array
    {
        $baseQuery = MoneyTransfer::where('is_reversed', false);
        if ($branchId) {
            $baseQuery->where(function ($q) use ($branchId) {
                $q->where('from_branch_id', $branchId)
                  ->orWhere('to_branch_id', $branchId);
            });
        }

        return [
            'total'           => MoneyTransfer::when($branchId, fn($q) => $q->where('from_branch_id', $branchId)->orWhere('to_branch_id', $branchId))->count(),
            'total_amount'    => (clone $baseQuery)->sum('amount'),
            'cash_to_bank'    => (clone $baseQuery)->where('transfer_type', 'cash_to_bank')->sum('amount'),
            'bank_to_cash'    => (clone $baseQuery)->where('transfer_type', 'bank_to_cash')->sum('amount'),
            'bank_to_bank'    => (clone $baseQuery)->where('transfer_type', 'bank_to_bank')->sum('amount'),
            'cash_to_cash'    => (clone $baseQuery)->where('transfer_type', 'cash_to_cash')->sum('amount'),
            'reversed'        => MoneyTransfer::where('is_reversed', true)->when($branchId, fn($q) => $q->where('from_branch_id', $branchId)->orWhere('to_branch_id', $branchId))->count(),
        ];
    }

    // ============================================================
    // GL POSTING
    // ============================================================

    /**
     * Post GL journal entry for money transfer.
     */
    private function postTransferGL(int $transferId, string $type, float $amount, int $branchId, array $data): int
    {
        $cashBankLedgerId = $this->journalPosting->lookupLedgerByNature('cash_bank');
        if (!$cashBankLedgerId) {
            throw new \RuntimeException('Cash/Bank ledger not found (nature: cash_bank). Configure in Chart of Accounts.');
        }

        $lines = [];

        switch ($type) {
            case 'cash_to_bank':
                $destBankLedgerId = $this->resolveBankLedger((int) ($data['to_bank_id'] ?? 0));
                $lines = [
                    ['ledger_id' => $destBankLedgerId, 'debit' => $amount, 'credit' => 0],
                    ['ledger_id' => $cashBankLedgerId, 'debit' => 0, 'credit' => $amount],
                ];
                break;

            case 'bank_to_cash':
                $srcBankLedgerId = $this->resolveBankLedger((int) ($data['from_bank_id'] ?? 0));
                $lines = [
                    ['ledger_id' => $cashBankLedgerId, 'debit' => $amount, 'credit' => 0],
                    ['ledger_id' => $srcBankLedgerId, 'debit' => 0, 'credit' => $amount],
                ];
                break;

            case 'bank_to_bank':
                $destBankLedgerId = $this->resolveBankLedger((int) ($data['to_bank_id'] ?? 0));
                $srcBankLedgerId = $this->resolveBankLedger((int) ($data['from_bank_id'] ?? 0));
                $lines = [
                    ['ledger_id' => $destBankLedgerId, 'debit' => $amount, 'credit' => 0],
                    ['ledger_id' => $srcBankLedgerId, 'debit' => 0, 'credit' => $amount],
                ];
                break;

            default:
                throw new \RuntimeException("Unknown transfer type: {$type}");
        }

        return $this->journalPosting->postJournalEntry([
            'reference_type' => 'money_transfer',
            'reference_id'   => $transferId,
            'branch_id'      => $branchId,
            'description'    => "Money Transfer — " . str_replace('_', ' ', ucfirst($type)),
            'lines'          => $lines,
            'created_by'     => $data['created_by'] ?? null,
        ]);
    }

    // ============================================================
    // BANK BALANCE SYNC
    // ============================================================

    /**
     * Sync bank balances for the transfer.
     */
    private function syncBankBalances(string $type, float $amount, array $data, bool $undo = false): void
    {
        switch ($type) {
            case 'cash_to_bank':
                // Destination bank increases (or decreases if undo).
                $bankId = (int) ($data['to_bank_id'] ?? 0);
                if ($bankId > 0) {
                    $bank = Bank::find($bankId);
                    if ($bank) {
                        $undo ? $bank->decrement('balance', $amount) : $bank->increment('balance', $amount);
                    }
                }
                break;

            case 'bank_to_cash':
                // Source bank decreases (or increases if undo).
                $bankId = (int) ($data['from_bank_id'] ?? 0);
                if ($bankId > 0) {
                    $bank = Bank::find($bankId);
                    if ($bank) {
                        $undo ? $bank->increment('balance', $amount) : $bank->decrement('balance', $amount);
                    }
                }
                break;

            case 'bank_to_bank':
                // Source bank decreases, destination bank increases.
                $srcBankId = (int) ($data['from_bank_id'] ?? 0);
                $destBankId = (int) ($data['to_bank_id'] ?? 0);
                if ($srcBankId > 0) {
                    $bank = Bank::find($srcBankId);
                    if ($bank) {
                        $undo ? $bank->increment('balance', $amount) : $bank->decrement('balance', $amount);
                    }
                }
                if ($destBankId > 0) {
                    $bank = Bank::find($destBankId);
                    if ($bank) {
                        $undo ? $bank->decrement('balance', $amount) : $bank->increment('balance', $amount);
                    }
                }
                break;

            case 'cash_to_cash':
                // No bank balance change.
                break;
        }
    }

    // ============================================================
    // CASH LEDGER
    // ============================================================

    /**
     * Record cash ledger entry for the transfer.
     */
    private function recordCashLedger(int $transferId, string $type, float $amount, int $fromBranchId, int $toBranchId): void
    {
        $now = now();
        $date = $now->format('Y-m-d');

        switch ($type) {
            case 'cash_to_bank':
                // Cash goes out from source branch.
                DB::table('cash_ledger')->insert([
                    'branch_id'        => $fromBranchId,
                    'transaction_date' => $date,
                    'transaction_type' => 'money_transfer_out',
                    'reference_type'   => 'money_transfer',
                    'reference_id'     => $transferId,
                    'amount'           => -$amount,
                    'balance'          => 0,
                    'description'      => 'Cash to Bank transfer',
                    'created_at'       => $now,
                ]);
                break;

            case 'bank_to_cash':
                // Cash comes in to branch.
                DB::table('cash_ledger')->insert([
                    'branch_id'        => $fromBranchId,
                    'transaction_date' => $date,
                    'transaction_type' => 'money_transfer_in',
                    'reference_type'   => 'money_transfer',
                    'reference_id'     => $transferId,
                    'amount'           => $amount,
                    'balance'          => 0,
                    'description'      => 'Bank to Cash transfer',
                    'created_at'       => $now,
                ]);
                break;

            case 'cash_to_cash':
                // Cash out from source branch.
                DB::table('cash_ledger')->insert([
                    'branch_id'        => $fromBranchId,
                    'transaction_date' => $date,
                    'transaction_type' => 'money_transfer_out',
                    'reference_type'   => 'money_transfer',
                    'reference_id'     => $transferId,
                    'amount'           => -$amount,
                    'balance'          => 0,
                    'description'      => 'Cash transfer to branch ' . $toBranchId,
                    'created_at'       => $now,
                ]);
                // Cash in to destination branch.
                DB::table('cash_ledger')->insert([
                    'branch_id'        => $toBranchId,
                    'transaction_date' => $date,
                    'transaction_type' => 'money_transfer_in',
                    'reference_type'   => 'money_transfer',
                    'reference_id'     => $transferId,
                    'amount'           => $amount,
                    'balance'          => 0,
                    'description'      => 'Cash transfer from branch ' . $fromBranchId,
                    'created_at'       => $now,
                ]);
                break;

            case 'bank_to_bank':
                // No cash ledger entry for bank-to-bank.
                break;
        }
    }

    /**
     * Reverse cash ledger entries for the transfer.
     */
    private function reverseCashLedger(int $transferId): void
    {
        // Delete cash ledger entries (they were just tracking records).
        DB::table('cash_ledger')
            ->where('reference_type', 'money_transfer')
            ->where('reference_id', $transferId)
            ->delete();
    }

    // ============================================================
    // INTERCOMPANY
    // ============================================================

    /**
     * Post intercompany journal entry for cross-branch transfers.
     */
    private function postIntercompanySettlement(int $transferId, string $type, float $amount, int $fromBranchId, int $toBranchId, array $data): ?int
    {
        try {
            $icLedgerId = $this->journalPosting->lookupLedgerByNature('intercompany');
            if (!$icLedgerId) {
                Log::warning('Intercompany ledger not found — skipping intercompany settlement for money transfer', [
                    'transfer_id' => $transferId,
                ]);
                return null;
            }

            $lines = [
                ['ledger_id' => $icLedgerId, 'debit' => $amount, 'credit' => 0, 'branch_id' => $toBranchId],
                ['ledger_id' => $icLedgerId, 'debit' => 0, 'credit' => $amount, 'branch_id' => $fromBranchId],
            ];

            return $this->journalPosting->postJournalEntry([
                'reference_type' => 'money_transfer_intercompany',
                'reference_id'   => $transferId,
                'branch_id'      => $fromBranchId,
                'description'    => "Intercompany — Money Transfer ({$fromBranchId} → {$toBranchId})",
                'lines'          => $lines,
                'created_by'     => $data['created_by'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Intercompany settlement failed for money transfer', [
                'transfer_id' => $transferId,
                'error'       => $e->getMessage(),
            ]);
            return null;
        }
    }

    // ============================================================
    // HELPERS
    // ============================================================

    /**
     * Resolve bank ledger ID from bank_ledger_mappings.
     */
    private function resolveBankLedger(int $bankId): int
    {
        if ($bankId > 0) {
            $mapping = \App\Models\BankLedgerMapping::where('bank_id', $bankId)->first();
            if ($mapping) {
                return (int) $mapping->ledger_id;
            }
        }

        $cashBankLedgerId = $this->journalPosting->lookupLedgerByNature('cash_bank');
        if (!$cashBankLedgerId) {
            throw new \RuntimeException('Cash/Bank ledger not found (nature: cash_bank). Configure in Chart of Accounts.');
        }
        return $cashBankLedgerId;
    }

    /**
     * Generate a unique money transfer code.
     */
    private function generateTransferCode(): string
    {
        $datePart = now()->format('Ymd');
        return DocumentSequenceService::nextCode(
            docType:   'money_transfer',
            prefix:    'MT',
            datePart:  $datePart,
            padLength: 5,
            periodKey: $datePart,
        );
    }

    /**
     * Validate input data for createTransfer.
     */
    private function validateCreateInput(array $data): void
    {
        $amount = (float) ($data['amount'] ?? 0);
        $type = $data['transfer_type'] ?? '';

        if ($amount <= 0) {
            throw new \RuntimeException('Amount must be greater than zero.');
        }

        if (!in_array($type, self::TRANSFER_TYPES)) {
            throw new \RuntimeException("Invalid transfer type: {$type}. Must be one of: " . implode(', ', self::TRANSFER_TYPES));
        }

        if ($type === 'cash_to_bank' && empty($data['to_bank_id'])) {
            throw new \RuntimeException('Destination bank is required for Cash to Bank transfer.');
        }

        if ($type === 'bank_to_cash' && empty($data['from_bank_id'])) {
            throw new \RuntimeException('Source bank is required for Bank to Cash transfer.');
        }

        if ($type === 'bank_to_bank') {
            if (empty($data['from_bank_id']) || empty($data['to_bank_id'])) {
                throw new \RuntimeException('Both source and destination banks are required for Bank to Bank transfer.');
            }
            if ((int) $data['from_bank_id'] === (int) $data['to_bank_id']) {
                throw new \RuntimeException('Source and destination bank must be different.');
            }
        }
    }

    /**
     * Log an audit entry.
     */
    private function logAudit(string $action, int $userId, int $recordId, array $details): void
    {
        try {
            DB::table('user_audit_log')->insert([
                'user_id'    => $userId,
                'action'     => $action,
                'record_id'  => $recordId,
                'details'    => json_encode($details),
                'ip_address' => request()->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Audit log failed for money transfer', [
                'action' => $action, 'error' => $e->getMessage(),
            ]);
        }
    }
}

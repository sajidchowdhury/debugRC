<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\AuditableMasterData;
use App\Models\Scopes\BranchScope;

/**
 * Money Transfer — Phase 4 (Accounts Sub-Ledger).
 *
 * Handles transfers between cash and bank accounts.
 * Lifecycle: create → reverse (no draft/confirm — transfers are immediate).
 *
 * Transfer types:
 *   - cash_to_bank:  Cash → Bank (Dr Bank / Cr Cash)
 *   - bank_to_cash:  Bank → Cash (Dr Cash / Cr Bank)
 *   - cash_to_cash:  Inter-branch cash transfer (NO GL — same ledger)
 *   - bank_to_bank:  Bank → Bank (Dr Dest Bank / Cr Source Bank)
 *
 * On create:
 *   1. GL journal entry (except cash_to_cash)
 *   2. Bank balance sync (if bank involved)
 *   3. Cash ledger entry
 *   4. Intercompany settlement (if cross-branch)
 *
 * @property int $id
 * @property string $transfer_code
 * @property string $transfer_date
 * @property int $from_branch_id
 * @property int $to_branch_id
 * @property string $transfer_type
 * @property int|null $from_bank_id
 * @property int|null $to_bank_id
 * @property string $amount
 * @property int|null $journal_entry_id
 * @property int|null $intercompany_journal_entry_id
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reverse_reason
 * @property string|null $notes
 * @property int|null $created_by
 */
class MoneyTransfer extends Model
{
    use AuditableMasterData;

    protected $table = 'money_transfers';

    public $timestamps = true;

    protected $fillable = [
        'transfer_code', 'transfer_date', 'transfer_type',
        'from_branch_id', 'to_branch_id',
        'from_bank_id', 'to_bank_id',
        'amount', 'journal_entry_id', 'intercompany_journal_entry_id',
        'is_reversed', 'reversed_at', 'reversed_by', 'reverse_reason',
        'notes', 'created_by',
    ];

    protected $casts = [
        'transfer_date' => 'date',
        'amount' => 'decimal:2',
        'from_branch_id' => 'integer',
        'to_branch_id' => 'integer',
        'from_bank_id' => 'integer',
        'to_bank_id' => 'integer',
        'journal_entry_id' => 'integer',
        'intercompany_journal_entry_id' => 'integer',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    public function fromBranch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function fromBank(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Bank::class, 'from_bank_id');
    }

    public function toBank(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Bank::class, 'to_bank_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    public function intercompanyJournalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'intercompany_journal_entry_id');
    }

    public const TRANSFER_TYPES = ['cash_to_bank', 'bank_to_cash', 'cash_to_cash', 'bank_to_bank'];

    public function isReversed(): bool
    {
        return $this->is_reversed;
    }

    public function isCashToBank(): bool { return $this->transfer_type === 'cash_to_bank'; }
    public function isBankToCash(): bool { return $this->transfer_type === 'bank_to_cash'; }
    public function isCashToCash(): bool { return $this->transfer_type === 'cash_to_cash'; }
    public function isBankToBank(): bool { return $this->transfer_type === 'bank_to_bank'; }

    public function isCrossBranch(): bool
    {
        return (int) $this->from_branch_id !== (int) $this->to_branch_id;
    }

    public function requiresGL(): bool
    {
        return !$this->isCashToCash();
    }

    public function getTransferTypeLabel(): string
    {
        return [
            'cash_to_bank'  => 'Cash to Bank',
            'bank_to_cash'  => 'Bank to Cash',
            'cash_to_cash'  => 'Cash to Cash',
            'bank_to_bank'  => 'Bank to Bank',
        ][$this->transfer_type] ?? ucfirst(str_replace('_', ' ', $this->transfer_type));
    }

    public function getGlDescription(): string
    {
        return [
            'cash_to_bank'  => 'Dr Bank Ledger · Cr Cash/Bank',
            'bank_to_cash'  => 'Dr Cash/Bank · Cr Bank Ledger',
            'cash_to_cash'  => 'No GL (same ledger)',
            'bank_to_bank'  => 'Dr Destination Bank · Cr Source Bank',
        ][$this->transfer_type] ?? 'N/A';
    }
}

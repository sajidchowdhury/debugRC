<?php

namespace App\Models;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;

/**
 * Supplier Ledger — Phase 9.3.
 *
 * Sub-ledger for Accounts Payable. Every transaction that touches the AP
 * control account in the GL also writes a row here (dual-write).
 *
 * Running balance: credit increases what we owe, debit decreases.
 *   balance = previous_balance + credit - debit
 *
 * @property int $id
 * @property int $supplier_id
 * @property int|null $branch_id
 * @property string $transaction_date
 * @property string $transaction_type
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string $debit
 * @property string $credit
 * @property string $balance
 * @property bool $is_reversed
 * @property string|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $description
 * @property int|null $journal_entry_id
 * @property int|null $created_by
 */
class SupplierLedger extends Model
{
    use BelongsToFiscalYear;

    protected $table = 'supplier_ledger';

    public $timestamps = false;

    protected $fillable = [
        'supplier_id', 'branch_id', 'transaction_date', 'transaction_type',
        'reference_type', 'reference_id', 'debit', 'credit', 'balance',
        'is_reversed', 'reversed_at', 'reversed_by',
        'description', 'journal_entry_id', 'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'balance' => 'decimal:2',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
        'supplier_id' => 'integer',
        'branch_id' => 'integer',
        'reference_id' => 'integer',
        'journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
    ];

    public function supplier(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    public function scopeNotReversed(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_reversed', false);
    }

    /**
     * Get the current AP balance for a supplier (what we owe = credit - debit).
     */
    public static function getBalance(int $supplierId): float
    {
        return (float) self::where('supplier_id', $supplierId)
            ->where('is_reversed', false)
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) AS balance')
            ->value('balance');
    }
}

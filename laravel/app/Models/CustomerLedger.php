<?php

namespace App\Models;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;

/**
 * Customer Ledger — Phase 9.3.
 *
 * Sub-ledger for Accounts Receivable. Every transaction that touches the AR
 * control account in the GL also writes a row here (dual-write).
 *
 * Running balance: debit increases what customer owes, credit decreases.
 *   balance = previous_balance + debit - credit
 *
 * @property int $id
 * @property int $customer_id
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
class CustomerLedger extends Model
{
    use BelongsToFiscalYear;

    protected $table = 'customer_ledger';

    public $timestamps = false;

    protected $fillable = [
        'customer_id', 'branch_id', 'transaction_date', 'transaction_type',
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
        'customer_id' => 'integer',
        'branch_id' => 'integer',
        'reference_id' => 'integer',
        'journal_entry_id' => 'integer',
        'created_by' => 'integer',
        'reversed_by' => 'integer',
    ];

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'journal_entry_id');
    }

    /**
     * Scope: non-reversed entries only.
     */
    public function scopeNotReversed(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_reversed', false);
    }

    /**
     * Get the current AR balance for a customer (sum of non-reversed entries).
     */
    public static function getBalance(int $customerId): float
    {
        return (float) self::where('customer_id', $customerId)
            ->where('is_reversed', false)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS balance')
            ->value('balance');
    }
}

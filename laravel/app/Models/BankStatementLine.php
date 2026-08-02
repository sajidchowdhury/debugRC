<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * BankStatementLine — a single line from a bank statement.
 *
 * Each line represents one transaction on the bank statement:
 *   - Deposits (credit) = money received into the bank account
 *   - Withdrawals (debit) = money paid out of the bank account
 *
 * Match status lifecycle:
 *   unmatched → Statement line has no system match yet
 *   suggested → System has auto-matched but user hasn't confirmed
 *   matched   → Confirmed match (auto or manual)
 *   excluded  → User excluded this line (e.g., internal transfer)
 */
class BankStatementLine extends Model
{
    protected $table = 'bank_statement_lines';

    protected $fillable = [
        'bank_reconciliation_id',
        'transaction_date',
        'description',
        'reference',
        'debit',
        'credit',
        'balance',
        'match_status',
        'line_number',
        'raw_data',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    // ── Relationships ───────────────────────────────────────────────

    public function reconciliation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    public function reconciliationItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BankReconciliationItem::class, 'bank_statement_line_id');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeUnmatched(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('match_status', 'unmatched');
    }

    public function scopeMatched(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('match_status', 'matched');
    }

    public function scopeSuggested(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('match_status', 'suggested');
    }

    public function scopeExcluded(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('match_status', 'excluded');
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function isDeposit(): bool
    {
        return $this->credit > 0;
    }

    public function isWithdrawal(): bool
    {
        return $this->debit > 0;
    }

    public function getAmount(): float
    {
        return $this->isDeposit() ? (float) $this->credit : (float) $this->debit;
    }

    public function isMatched(): bool
    {
        return $this->match_status === 'matched';
    }

    public function isUnmatched(): bool
    {
        return $this->match_status === 'unmatched';
    }

    public static function matchStatusOptions(): array
    {
        return [
            'unmatched' => 'Unmatched',
            'suggested' => 'Suggested',
            'matched' => 'Matched',
            'excluded' => 'Excluded',
        ];
    }
}

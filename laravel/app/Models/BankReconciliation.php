<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * BankReconciliation — Phase 9.3: Bank Reconciliation
 *
 * Represents a single bank reconciliation run for a specific bank account
 * and statement period. Contains the header information (balances, status)
 * and has many statement lines and reconciliation items.
 *
 * Status lifecycle:
 *   draft        → Initial creation, statement lines being imported/matched
 *   in_progress  → User is actively matching items
 *   completed    → Reconciliation is finalized, adjustment entries posted
 *   reversed     → Reversal of a completed reconciliation
 */
class BankReconciliation extends Model
{
    use SoftDeletes;

    protected $table = 'bank_reconciliations';

    protected $fillable = [
        'reconciliation_code',
        'bank_id',
        'statement_date',
        'period_from',
        'period_to',
        'statement_opening_balance',
        'statement_closing_balance',
        'system_opening_balance',
        'system_closing_balance',
        'adjusted_book_balance',
        'adjusted_bank_balance',
        'difference',
        'status',
        'total_statement_lines',
        'matched_lines',
        'unmatched_statement_lines',
        'unmatched_system_entries',
        'adjustment_journal_entry_id',
        'notes',
        'created_by',
        'completed_by',
        'completed_at',
        'reversed_by',
        'reversed_at',
        'reverse_reason',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'period_from' => 'date',
        'period_to' => 'date',
        'statement_opening_balance' => 'decimal:2',
        'statement_closing_balance' => 'decimal:2',
        'system_opening_balance' => 'decimal:2',
        'system_closing_balance' => 'decimal:2',
        'adjusted_book_balance' => 'decimal:2',
        'adjusted_bank_balance' => 'decimal:2',
        'difference' => 'decimal:2',
        'completed_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    // ── Relationships ───────────────────────────────────────────────

    public function bank(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    public function statementLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'bank_reconciliation_id');
    }

    public function reconciliationItems(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BankReconciliationItem::class, 'bank_reconciliation_id');
    }

    public function adjustmentJournalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Accounting\JournalEntry::class, 'adjustment_journal_entry_id');
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function reverser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeDraft(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'draft');
    }

    public function scopeInProgress(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeForBank(\Illuminate\Database\Eloquent\Builder $query, int $bankId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('bank_id', $bankId);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'in_progress']);
    }

    public function isBalanced(): bool
    {
        return abs($this->difference) < 0.01;
    }

    public function getMatchProgressPct(): float
    {
        if ($this->total_statement_lines === 0) {
            return 0;
        }
        return round(($this->matched_lines / $this->total_statement_lines) * 100, 1);
    }

    public static function statusOptions(): array
    {
        return [
            'draft' => 'Draft',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'reversed' => 'Reversed',
        ];
    }
}

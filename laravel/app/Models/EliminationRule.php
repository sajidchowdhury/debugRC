<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EliminationRule — defines which accounts to eliminate during consolidation.
 *
 * Each rule specifies:
 *   - A debit-side ledger to eliminate (e.g., interbranch_receivable)
 *   - A credit-side ledger to eliminate (e.g., interbranch_payable)
 *   - Optional elimination contra accounts (where the offset posts)
 *   - A rule type (balance, revenue, investment, dividend, custom)
 *
 * The consolidation engine matches the debit and credit sides and creates
 * elimination entries for the net difference.
 */
class EliminationRule extends Model
{
    use SoftDeletes;

    protected $table = 'elimination_rules';

    protected $fillable = [
        'rule_code',
        'rule_name',
        'rule_type',
        'description',
        'debit_ledger_id',
        'credit_ledger_id',
        'elimination_debit_ledger_id',
        'elimination_credit_ledger_id',
        'is_active',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ── Relationships ───────────────────────────────────────────────

    public function debitLedger(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'debit_ledger_id');
    }

    public function creditLedger(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'credit_ledger_id');
    }

    public function eliminationDebitLedger(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'elimination_debit_ledger_id');
    }

    public function eliminationCreditLedger(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'elimination_credit_ledger_id');
    }

    public function eliminationEntries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(EliminationEntry::class, 'elimination_rule_id');
    }

    public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByType(\Illuminate\Database\Eloquent\Builder $query, string $type): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('rule_type', $type);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public static function ruleTypeOptions(): array
    {
        return [
            'balance' => 'Balance Sheet Elimination',
            'revenue' => 'Revenue Elimination',
            'investment' => 'Investment Elimination',
            'dividend' => 'Dividend Elimination',
            'custom' => 'Custom Elimination',
        ];
    }

    public function getRuleTypeLabel(): string
    {
        return self::ruleTypeOptions()[$this->rule_type] ?? $this->rule_type;
    }
}

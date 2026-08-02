<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Ledger — the Chart of Accounts.
 *
 * Hierarchical (parent_id), tagged with account_type (Asset/Liability/Equity/
 * Income/Expense) and ledger_nature (behavior tag driving the posting engine).
 *
 * Phase 15 hardening:
 *   - is_system flag marks seeded critical-nature ledgers (Cash, AR, AP,
 *     Inventory, Sales Revenue, COGS, Retained Earnings). These cannot be
 *     edited or deleted — only their description is editable.
 *   - normal_balance ∈ {debit, credit} drives the account_type ↔ nature
 *     consistency check and the report side (Assets/Expenses → debit;
 *     Liabilities/Equity/Income → credit).
 *   - criticalNatures() now returns the canonical names that match
 *     LedgerNatureService::CRITICAL_NATURES keys (fixed 'sales' →
 *     'sales_revenue' bug — the previous code returned 'sales', but the
 *     posting engine and seeded data use 'sales_revenue').
 *
 * The 7 critical natures must resolve to exactly one active ledger:
 *   cash_bank, ar, ap, inventory, sales_revenue, cogs, retained_earnings
 */
class Ledger extends Model
{
    use SoftDeletes, HasFactory, AuditableMasterData;

    protected $table = 'ledgers';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'ledger_code',
        'ledger_name',
        'parent_id',
        'account_type',
        'ledger_nature',
        'is_control_account',
        'control_account_type',
        'is_active',
        'opening_balance',
        'sort_order',
        // Phase 15 hardening columns
        'is_system',
        'is_elimination',
        'normal_balance',
        'description',
        'created_by',
        'deleted_by',
    ];

    protected $casts = [
        'is_control_account' => 'boolean',
        'is_active'          => 'boolean',
        'is_system'          => 'boolean',
        'is_elimination'     => 'boolean',
        'opening_balance'    => 'decimal:2',
        'sort_order'         => 'integer',
        'parent_id'          => 'integer',
        'created_by'         => 'integer',
        'deleted_by'         => 'integer',
    ];

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'parent_id');
    }

    public function children(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Ledger::class, 'parent_id');
    }

    public function journalLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Accounting\JournalLine::class, 'ledger_id');
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }

    /**
     * Scope: only system (seeded critical-nature) ledgers.
     */
    public function scopeSystem(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope: only non-system (user-created) ledgers.
     */
    public function scopeNonSystem(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_system', false);
    }

    /**
     * Is this ledger a system (seeded critical-nature) ledger that is
     * protected from edit/delete?
     */
    public function isSystemLedger(): bool
    {
        return (bool) $this->is_system;
    }

    /**
     * The 7 critical ledger natures — each must resolve to exactly one
     * active ledger.
     *
     * Phase 15 fix: aligned with LedgerNatureService::CRITICAL_NATURES keys.
     * The previous code returned 'sales', but the seeded data and posting
     * engine use 'sales_revenue'.
     */
    public static function criticalNatures(): array
    {
        return [
            'cash_bank',
            'ar',
            'ap',
            'inventory',
            'sales_revenue',
            'cogs',
            'retained_earnings',
        ];
    }

    /**
     * Map of critical nature → expected account_type + normal_balance.
     * Mirrors LedgerNatureService::CRITICAL_NATURES metadata so the
     * controller can validate consistency without a circular dep.
     */
    public static function natureMetadata(): array
    {
        return [
            'cash_bank'         => ['account_type' => 'Asset',     'normal_balance' => 'debit'],
            'ar'                => ['account_type' => 'Asset',     'normal_balance' => 'debit'],
            'ap'                => ['account_type' => 'Liability', 'normal_balance' => 'credit'],
            'inventory'         => ['account_type' => 'Asset',     'normal_balance' => 'debit'],
            'sales_revenue'     => ['account_type' => 'Income',    'normal_balance' => 'credit'],
            'cogs'              => ['account_type' => 'Expense',   'normal_balance' => 'debit'],
            'retained_earnings' => ['account_type' => 'Equity',    'normal_balance' => 'credit'],
            'sales_return'      => ['account_type' => 'Income',    'normal_balance' => 'debit'],
            'sales_discount'    => ['account_type' => 'Expense',   'normal_balance' => 'debit'],
            'transport_revenue' => ['account_type' => 'Income',    'normal_balance' => 'credit'],
            'inventory_shrinkage' => ['account_type' => 'Expense', 'normal_balance' => 'debit'],
            'inventory_surplus' => ['account_type' => 'Income',    'normal_balance' => 'credit'],
            'damage_loss'       => ['account_type' => 'Expense',   'normal_balance' => 'debit'],
            'employee_payable'  => ['account_type' => 'Liability', 'normal_balance' => 'credit'],
            'interbranch_receivable' => ['account_type' => 'Asset',    'normal_balance' => 'debit'],
            'interbranch_payable'    => ['account_type' => 'Liability', 'normal_balance' => 'credit'],
            'elimination_receivable' => ['account_type' => 'Asset',    'normal_balance' => 'debit'],
            'elimination_payable'    => ['account_type' => 'Liability', 'normal_balance' => 'credit'],
            'elimination_revenue'    => ['account_type' => 'Equity',   'normal_balance' => 'credit'],
            'elimination_cogs'       => ['account_type' => 'Equity',   'normal_balance' => 'debit'],
            'elimination_investment' => ['account_type' => 'Equity',   'normal_balance' => 'credit'],
            'other_income'      => ['account_type' => 'Income',    'normal_balance' => 'credit'],
            'operating_expense' => ['account_type' => 'Expense',   'normal_balance' => 'debit'],
            'salary_expense'    => ['account_type' => 'Expense',   'normal_balance' => 'debit'],
            'finance_cost'      => ['account_type' => 'Expense',   'normal_balance' => 'debit'],
            // Phase 9.4: Fixed Asset & Depreciation natures
            'accumulated_depreciation' => ['account_type' => 'Asset',    'normal_balance' => 'credit'],
            'depreciation_expense' => ['account_type' => 'Expense',   'normal_balance' => 'debit'],
            'gain_on_disposal'  => ['account_type' => 'Income',    'normal_balance' => 'credit'],
            'loss_on_disposal'  => ['account_type' => 'Expense',   'normal_balance' => 'debit'],
        ];
    }

    /**
     * Get the expected account_type for a given nature, or null if the
     * nature is unknown.
     */
    public static function expectedAccountTypeForNature(?string $nature): ?string
    {
        if ($nature === null || $nature === '') {
            return null;
        }
        return static::natureMetadata()[$nature]['account_type'] ?? null;
    }

    /**
     * Get the expected normal_balance for a given nature, or null.
     */
    public static function expectedNormalBalanceForNature(?string $nature): ?string
    {
        if ($nature === null || $nature === '') {
            return null;
        }
        return static::natureMetadata()[$nature]['normal_balance'] ?? null;
    }
}

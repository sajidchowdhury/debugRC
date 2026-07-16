<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Ledger — the Chart of Accounts.
 * Hierarchical (parent_id), with account_type (Asset/Liability/Equity/Income/Expense)
 * and ledger_nature (behavior tag driving the posting engine).
 *
 * The 7 "critical natures" must resolve to exactly one active ledger:
 *   cash_bank, ar, ap, inventory, sales, cogs, retained_earnings
 */
class Ledger extends Model
{
    use SoftDeletes, AuditableMasterData;

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
        'deleted_by',
    ];

    protected $casts = [
        'is_control_account' => 'boolean',
        'is_active' => 'boolean',
        'opening_balance' => 'decimal:2',
        'sort_order' => 'integer',
        'parent_id' => 'integer',
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
     * The 7 critical ledger natures — each must resolve to exactly one active ledger.
     */
    public static function criticalNatures(): array
    {
        return ['cash_bank', 'ar', 'ap', 'inventory', 'sales', 'cogs', 'retained_earnings'];
    }
}

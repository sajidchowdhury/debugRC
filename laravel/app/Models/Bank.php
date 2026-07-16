<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\AuditableMasterData;

/**
 * Bank — maps to legacy `banks` table.
 * Phase 2 FIX: balance is now numeric(18,2) (was FLOAT in MySQL).
 * Each bank maps to a GL ledger of nature 'cash_bank' via bank_ledger_mappings.
 */
class Bank extends Model
{
    use SoftDeletes, AuditableMasterData;

    protected $table = 'banks';

    public $timestamps = true;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'bank_name',
        'account_number',
        'account_holder',
        'branch_name',
        'balance',
        'is_active',
        'ledger_id',
        'created_by',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function ledger(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'ledger_id');
    }

    public function ledgerMapping(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(BankLedgerMapping::class, 'bank_id');
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\AuditableMasterData;

/**
 * Bank — maps to legacy `banks` table.
 * Phase 2 FIX: balance is now numeric(18,2) (was FLOAT in MySQL).
 * Each bank maps to a GL ledger of nature 'cash_bank' via bank_ledger_mappings.
 *
 * Phase 13: added HasFactory trait for Bank module test suite. Added
 * `deleted_by` to $fillable (now exists on the table via migration
 * 2025_01_13_000001_add_soft_deletes_to_banks).
 */
class Bank extends Model
{
    use SoftDeletes, HasFactory, AuditableMasterData;

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
        'branch_id',
        'created_by',
        'deleted_by',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'is_active' => 'boolean',
        'branch_id' => 'integer',
    ];

    public function ledger(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'ledger_id');
    }

    public function ledgerMapping(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(BankLedgerMapping::class, 'bank_id');
    }

    /**
     * The company branch this bank belongs to (NULL = shared / head-office).
     * Used by SupplierTransactionService::postIntercompanySettlement() to
     * detect cross-branch bank-mode payments and post intercompany entries.
     */
    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_active', true);
    }
}

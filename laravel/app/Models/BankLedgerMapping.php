<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * BankLedgerMapping — links a Bank to a GL Ledger (nature = cash_bank).
 * Used for cash/bank reconciliation: each bank's GL control account.
 */
class BankLedgerMapping extends Model
{
    protected $table = 'bank_ledger_mappings';

    public $timestamps = true;

    protected $fillable = [
        'bank_id',
        'ledger_id',
    ];

    public function bank(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    public function ledger(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Ledger::class, 'ledger_id');
    }
}

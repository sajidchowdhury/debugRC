<?php

namespace App\Models\Accounting;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    use BelongsToFiscalYear;

    protected $table = 'journal_lines';

    public $timestamps = false;

    protected $fillable = [
        'fiscal_year_id',
        'journal_entry_id', 'ledger_id', 'debit', 'credit',
        'entity_type', 'entity_id', 'memo', 'dimension_value_id',
        'is_bank_reconciled', 'bank_reconciliation_id',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'is_bank_reconciled' => 'boolean',
    ];

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function ledger(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Ledger::class, 'ledger_id');
    }

    public function dimensionValue(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\DimensionValue::class, 'dimension_value_id');
    }

    public function bankReconciliation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\BankReconciliation::class, 'bank_reconciliation_id');
    }
}

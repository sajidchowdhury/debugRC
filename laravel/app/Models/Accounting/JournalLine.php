<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class JournalLine extends Model
{
    protected $table = 'journal_lines';

    public $timestamps = false;

    protected $fillable = [
        'journal_entry_id', 'ledger_id', 'debit', 'credit',
        'entity_type', 'entity_id', 'memo', 'dimension_value_id',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
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
}

<?php

namespace App\Models\Accounting;
use App\Models\Concerns\BelongsToFiscalYear;

use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    use BelongsToFiscalYear;

    protected $table = 'journal_entries';

    public $timestamps = true;

    protected $fillable = [
        'fiscal_year_id',
        'entry_no', 'entry_date', 'reference_type', 'reference_id',
        'branch_id', 'description', 'source', 'is_reversed',
        'reversal_of_entry_id', 'reversed_at', 'reversed_by',
        'reverse_reason', 'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'is_reversed' => 'boolean',
        'reversed_at' => 'datetime',
    ];

    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id');
    }
}

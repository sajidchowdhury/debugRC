<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PeriodCloseLog — audit trail of all period close/reopen/lock actions.
 *
 * Every time a period is closed, reopened, or locked, a row is created here
 * recording who did it, when, why, and the previous state of the period.
 */
class PeriodCloseLog extends Model
{
    protected $table = 'period_close_log';

    protected $fillable = [
        'fiscal_period_id',
        'fiscal_year_id',
        'branch_id',
        'action',
        'period_start_date',
        'period_end_date',
        'performed_by',
        'reason',
        'previous_state',
        'ip_address',
    ];

    protected $casts = [
        'period_start_date' => 'date',
        'period_end_date'   => 'date',
        'previous_state'    => 'array',
    ];

    // ── Relationships ───────────────────────────────────────────────

    public function fiscalPeriod(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function fiscalYear(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FiscalYear::class, 'fiscal_year_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function performer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    // ── Helpers ─────────────────────────────────────────────────────

    public function getActionLabelAttribute(): string
    {
        return match ($this->action) {
            'close'  => 'Period Closed',
            'reopen' => 'Period Reopened',
            'lock'   => 'Period Locked',
            default  => ucfirst($this->action),
        };
    }

    public function getActionIconAttribute(): string
    {
        return match ($this->action) {
            'close'  => 'fas fa-lock text-warning',
            'reopen' => 'fas fa-unlock text-success',
            'lock'   => 'fas fa-shield-alt text-danger',
            default  => 'fas fa-circle',
        };
    }
}

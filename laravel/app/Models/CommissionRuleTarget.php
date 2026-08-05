<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Commission Rule Target — Task 37.
 *
 * Sales target with bonus rate for target_bonus commission type.
 * Used when the parent CommissionRule has rule_type = 'target_bonus'.
 *
 * The salesman earns the base rate (from rule.rate) on ALL sales.
 * When cumulative sales within the period reach target_amount, the
 * bonus_rate applies to sales ABOVE the target (in addition to base rate).
 *
 * Example: Base 1%, target 100K, bonus 2%, period = monthly
 *   - Jan sales 80K → commission = 800 (1% of 80K, target not met)
 *   - Jan sales 120K → commission = 1000 (1% of 100K) + 40 (2% of 20K) = 1,040
 *
 * @property int $id
 * @property int $commission_rule_id
 * @property string $target_amount Cumulative sales target
 * @property string $bonus_rate Additional rate above the target
 * @property string $period monthly|quarterly|yearly
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at G-305: added by migration 2026_09_07_000001 (target-change audit trail)
 */
class CommissionRuleTarget extends Model
{
    protected $table = 'commission_rule_targets';

    // G-305 (LOW-E): timestamps now enabled. `created_at` was always present
    // in the DDL; `updated_at` was added by migration 2026_09_07_000001.
    // Target amount / bonus rate / period edits now bump updated_at for audit.

    protected $fillable = [
        'commission_rule_id', 'target_amount', 'bonus_rate', 'period',
    ];

    protected $casts = [
        'target_amount' => 'decimal:2',
        'bonus_rate' => 'decimal:4',
        'commission_rule_id' => 'integer',
    ];

    public function rule(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }
}

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
 */
class CommissionRuleTarget extends Model
{
    protected $table = 'commission_rule_targets';

    public $timestamps = false;

    public const CREATED_AT = null;
    public const UPDATED_AT = null;

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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Commission Rule Tier — Task 37.
 *
 * Tiered commission rates: progressive rates based on cumulative sales volume.
 * Used when the parent CommissionRule has rule_type = 'tiered'.
 *
 * The rate applies to the PORTION of sales within the tier (incremental model),
 * not the entire cumulative amount. This is the standard progressive model.
 *
 * Example: Salesman has 120K in cumulative sales with tiers:
 *   - 0 to 50K at 1% → commission on first 50K = 500
 *   - 50K to 100K at 1.5% → commission on next 50K = 750
 *   - above 100K at 2% → commission on remaining 20K = 400
 *   - Total commission = 1,650
 *
 * @property int $id
 * @property int $commission_rule_id
 * @property string $threshold Cumulative sales amount at which this tier starts
 * @property string $rate Commission rate for the portion of sales in this tier
 * @property int $sort_order
 */
class CommissionRuleTier extends Model
{
    protected $table = 'commission_rule_tiers';

    public $timestamps = false;

    public const CREATED_AT = null;
    public const UPDATED_AT = null;

    protected $fillable = [
        'commission_rule_id', 'threshold', 'rate', 'sort_order',
    ];

    protected $casts = [
        'threshold' => 'decimal:2',
        'rate' => 'decimal:4',
        'commission_rule_id' => 'integer',
        'sort_order' => 'integer',
    ];

    public function rule(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }
}

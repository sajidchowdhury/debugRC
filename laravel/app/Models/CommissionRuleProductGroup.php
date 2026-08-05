<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Commission Rule Product Group — Task 37.
 *
 * Per-product-group commission rate overrides.
 * Used when the parent CommissionRule has rule_type = 'product_group'.
 *
 * Each row sets a commission rate for a specific product group.
 * Products in groups NOT listed here use the rule's default rate.
 *
 * Example: Salesman has default rate 1%, but:
 *   - Electronics (group_id=2): rate = 2%
 *   - Furniture (group_id=5): rate = 0.8%
 *   - All other groups: 1% (from rule.rate)
 *
 * @property int $id
 * @property int $commission_rule_id
 * @property int $product_group_id
 * @property string $rate Commission rate for this product group
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at G-305: added by migration 2026_09_07_000001 (group-rate-change audit trail)
 */
class CommissionRuleProductGroup extends Model
{
    protected $table = 'commission_rule_product_groups';

    // G-305 (LOW-E): timestamps now enabled. `created_at` was always present
    // in the DDL; `updated_at` was added by migration 2026_09_07_000001.
    // Per-group rate edits now bump updated_at for audit.

    protected $fillable = [
        'commission_rule_id', 'product_group_id', 'rate',
    ];

    protected $casts = [
        'rate' => 'decimal:4',
        'commission_rule_id' => 'integer',
        'product_group_id' => 'integer',
    ];

    public function rule(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CommissionRule::class, 'commission_rule_id');
    }

    public function productGroup(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }
}

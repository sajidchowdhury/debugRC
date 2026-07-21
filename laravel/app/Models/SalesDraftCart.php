<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sales Draft Cart — Phase 8.1.
 *
 * Per-user-per-customer-per-branch draft cart stored in DB (not session).
 * Unique key: (user_id, customer_id, branch_id) — see R6.
 * Items stored as JSONB in items_json.
 *
 * The cart is the pre-invoice state: salesman adds products, sets qty + rate,
 * checks availability. When finalized (Phase 8.2), the cart becomes a
 * sales_invoice (status=draft) + sales_invoice_items.
 *
 * R6 (2026-07-21): the unique key was extended from (user_id, customer_id)
 * to (user_id, customer_id, branch_id) to prevent cross-branch cart
 * contamination. A salesman switching branches with the same customer
 * now gets a separate cart per branch. branch_id is NOT NULL DEFAULT 0
 * (Legacy semantics: 0 = "no specific branch").
 *
 * @property int $id
 * @property int $user_id
 * @property int $branch_id  R6: NOT NULL DEFAULT 0 (0 = "no specific branch")
 * @property int|null $customer_id
 * @property string|null $items_json JSONB array of cart items
 * @property bool $is_soft_hold
 * @property string $updated_at
 * @property string $created_at
 */
class SalesDraftCart extends Model
{
    protected $table = 'sales_draft_carts';

    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'branch_id',
        'customer_id',
        'items_json',
        'is_soft_hold',
    ];

    protected $casts = [
        'items_json' => 'array', // Laravel auto-casts jsonb to array
        'is_soft_hold' => 'boolean',
        'user_id' => 'integer',
        'branch_id' => 'integer',
        'customer_id' => 'integer',
    ];

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function branch(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Get or create a cart for a (user, customer, branch) tuple.
     *
     * R6 (2026-07-21): branch_id is now part of the unique key
     * (uq_sales_draft_user_customer_branch), so it must be in the
     * firstOrCreate search attributes — otherwise switching branches
     * would all hit the same cart row (the V11/C7 bug). Null is
     * normalized to 0 (Legacy "no specific branch" sentinel).
     *
     * @param int $userId
     * @param int $customerId
     * @param int|null $branchId  Null → 0 (no specific branch).
     * @return self
     */
    public static function getOrCreate(int $userId, int $customerId, ?int $branchId = null): self
    {
        // R6: normalize null → 0 so the unique key search is deterministic.
        // The DB column is NOT NULL DEFAULT 0, so this matches DB semantics.
        $branchId = $branchId ?? 0;

        return self::firstOrCreate(
            [
                'user_id' => $userId,
                'customer_id' => $customerId,
                'branch_id' => $branchId,
            ],
            [
                'items_json' => [],
                'is_soft_hold' => false,
            ]
        );
    }
}

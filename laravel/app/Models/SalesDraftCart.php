<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sales Draft Cart — Phase 8.1.
 *
 * Per-user-per-customer draft cart stored in DB (not session).
 * Unique key: (user_id, customer_id).
 * Items stored as JSONB in items_json.
 *
 * The cart is the pre-invoice state: salesman adds products, sets qty + rate,
 * checks availability. When finalized (Phase 8.2), the cart becomes a
 * sales_invoice (status=draft) + sales_invoice_items.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $branch_id
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
     * Get or create a cart for a user + customer.
     * Uses upsert on the unique key (user_id, customer_id).
     */
    public static function getOrCreate(int $userId, int $customerId, ?int $branchId = null): self
    {
        return self::firstOrCreate(
            ['user_id' => $userId, 'customer_id' => $customerId],
            ['branch_id' => $branchId, 'items_json' => [], 'is_soft_hold' => false]
        );
    }
}

<?php

namespace App\Http\Resources\Api\V1\Sales;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cart API Resource — returns cart state with enriched items.
 */
class CartResource extends JsonResource
{
    public function toArray($request): array
    {
        // The cart service returns an array with keys: cart, items, subtotal, validation
        return [
            'cart_id'     => $this->resource['cart']->id ?? null,
            'customer_id' => $this->resource['cart']->customer_id ?? null,
            'branch_id'   => $this->resource['cart']->branch_id ?? null,
            'is_soft_hold' => (bool) ($this->resource['cart']->is_soft_hold ?? false),
            'items'       => collect($this->resource['items'] ?? [])->map(fn($item) => [
                'product_id'    => $item['product_id'] ?? null,
                'product_name'  => $item['product_name'] ?? null,
                'qty'           => (float) ($item['qty'] ?? 0),
                'rate'          => (float) ($item['rate'] ?? 0),
                'total'         => (float) ($item['total'] ?? 0),
                'min_rate'      => isset($item['min_rate']) ? (float) $item['min_rate'] : null,
                'max_rate'      => isset($item['max_rate']) ? (float) $item['max_rate'] : null,
                'default_rate'  => isset($item['default_rate']) ? (float) $item['default_rate'] : null,
                'available_qty' => isset($item['available_qty']) ? (float) $item['available_qty'] : null,
            ])->values()->toArray(),
            'subtotal'    => (float) ($this->resource['subtotal'] ?? 0),
            'validation'  => $this->resource['validation'] ?? null,
            'updated_at'  => $this->resource['cart']->updated_at?->toIso8601String(),
        ];
    }
}

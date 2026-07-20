<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Add item to sales draft cart.
 *
 * Validates product_id existence, qty > 0, rate >= 0.
 * Branch_id is resolved server-side from the authenticated user's session
 * (never trusted from client input for non-admins).
 */
class StoreCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // api.auth middleware already verified token + role
    }

    public function rules(): array
    {
        return [
            'customer_id' => 'required|integer|exists:customers,id',
            'product_id'  => 'required|integer|exists:products,id',
            'qty'         => 'required|numeric|min:0.001',
            'rate'        => 'required|numeric|min:0',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'customer_id' => ['description' => 'Active customer ID', 'example' => 1],
            'product_id'  => ['description' => 'Active product ID', 'example' => 10],
            'qty'         => ['description' => 'Quantity (minimum 0.001 for fractional units)', 'example' => 5],
            'rate'        => ['description' => 'Unit selling rate (must be within product price range)', 'example' => 120.50],
        ];
    }
}

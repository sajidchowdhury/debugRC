<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Update a cart line item (qty and/or rate).
 */
class UpdateCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => 'required|integer|exists:customers,id',
            'product_id'  => 'required|integer|exists:products,id',
            'qty'         => 'required|numeric|min:0.001',
            'rate'        => 'nullable|numeric|min:0',
        ];
    }
}

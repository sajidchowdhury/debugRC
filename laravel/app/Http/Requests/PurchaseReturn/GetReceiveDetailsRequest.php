<?php

namespace App\Http\Requests\PurchaseReturn;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 7 — Form Request for the AJAX getReceiveDetails endpoint.
 *
 * Used by the Return create workspace to load a confirmed GRN + its
 * items + per-warehouse availability (Phase 4 dual cap). Only requires
 * receive_id — the controller enforces branch isolation separately
 * (Phase 1) before returning the payload.
 */
class GetReceiveDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC handled by route middleware
    }

    public function rules(): array
    {
        return [
            'receive_id' => 'required|integer|exists:purchase_receives,id',
        ];
    }

    public function messages(): array
    {
        return [
            'receive_id.required' => 'GRN ID is required.',
            'receive_id.exists'   => 'That GRN no longer exists.',
        ];
    }
}

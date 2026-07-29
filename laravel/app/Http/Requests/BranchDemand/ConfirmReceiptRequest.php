<?php

namespace App\Http\Requests\BranchDemand;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate the receipt confirmation request for a branch demand.
 *
 * Phase 5 — Warehouse Manager Confirmation (Receipt Acknowledgment).
 *
 * This request is used when the receiving warehouse manager confirms
 * that they have received the products from the supplier branch.
 * The confirmation is optional — a simple POST with no additional
 * data is sufficient. The remarks field is optional and allows
 * the warehouse manager to add notes about the receipt.
 */
class ConfirmReceiptRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Only the requesting branch's warehouse manager can confirm receipt.
     */
    public function authorize(): bool
    {
        return true; // Authorization is handled in the controller/service
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'remarks' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'remarks.max' => 'Remarks must not exceed 500 characters.',
        ];
    }
}

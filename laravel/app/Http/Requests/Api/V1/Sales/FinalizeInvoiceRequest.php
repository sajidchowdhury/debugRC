<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Finalize (create) a sales invoice from the draft cart.
 *
 * The cart must already contain items (added via POST /sales/cart).
 * On success, the cart is cleared and a draft sales_invoice is created
 * with GL entries (Dr AR / Cr Revenue) and customer_ledger debit.
 *
 * Idempotency: the client MUST send a UUID idempotency_token to prevent
 * duplicate invoice creation on network retries or double-taps.
 */
class FinalizeInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id'             => 'required|integer|exists:customers,id',
            'branch_id'               => 'required|integer|exists:branches,id',
            'invoice_date'            => 'required|date',
            'salesman_id'             => 'nullable|integer|exists:employees,id',
            'sales_person'            => 'nullable|string|max:100',
            'discount_amount'         => 'nullable|numeric|min:0',
            'transport_cost'          => 'nullable|numeric|min:0',
            'notes'                   => 'nullable|string|max:1000',
            'is_soft_hold'            => 'nullable|boolean',
            'credit_limit_override'   => 'nullable|boolean',
            'override_reason'         => 'nullable|string|max:500',
            'idempotency_token'       => 'required|string|uuid',
            'dispatcher_ids'          => 'nullable|array',
            'dispatcher_ids.*'        => 'integer|exists:employees,id',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'customer_id'           => ['description' => 'Customer for this invoice', 'example' => 1],
            'branch_id'             => ['description' => 'Branch (must match user session for non-admins)', 'example' => 1],
            'invoice_date'          => ['description' => 'Invoice date (Y-m-d)', 'example' => '2025-01-21'],
            'discount_amount'       => ['description' => 'Total discount on invoice', 'example' => 50.00],
            'transport_cost'        => ['description' => 'Transport charge added to invoice', 'example' => 200.00],
            'credit_limit_override' => ['description' => 'Set true to override credit limit (requires override_reason >= 10 chars)', 'example' => true],
            'override_reason'       => ['description' => 'Reason for credit limit override (min 10 chars)', 'example' => 'Approved by manager verbally'],
            'idempotency_token'     => ['description' => 'Client-generated UUID to prevent duplicate invoice creation', 'example' => '550e8400-e29b-41d4-a716-446655440000'],
            'dispatcher_ids'        => ['description' => 'Employee IDs to assign as dispatchers', 'example' => [3, 5]],
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Create a customer payment (two-phase: draft → confirm).
 *
 * On creation, a draft payment is created with no GL or ledger entries.
 * On confirmation, the GL, customer_ledger, and optional invoice allocations
 * are posted atomically. The client can optionally pass allocations at
 * creation time to auto-confirm in a single request.
 */
class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id'      => 'required|integer|exists:customers,id',
            'branch_id'        => 'required|integer|exists:branches,id',
            'bank_id'          => 'nullable|integer|exists:banks,id',
            'payment_mode'     => 'required|in:cash,bank,mobile_banking,cheque,adjustment',
            'transaction_type' => 'required|in:receive,discount,write_off,payment',
            'amount'           => 'required|numeric|min:0.01',
            'discount_amount'  => 'nullable|numeric|min:0',
            'payment_date'     => 'required|date',
            'reference_no'     => 'nullable|string|max:100',
            'notes'            => 'nullable|string|max:500',
            'allocations'                => 'nullable|array',
            'allocations.*.invoice_id'   => 'required_with:allocations|integer|exists:sales_invoices,id',
            'allocations.*.allocated_amount' => 'required_with:allocations|numeric|min:0.01',
            'auto_confirm'     => 'nullable|boolean',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'customer_id'      => ['description' => 'Customer making the payment', 'example' => 1],
            'branch_id'        => ['description' => 'Branch where payment is recorded', 'example' => 1],
            'payment_mode'     => ['description' => 'Payment mode: cash, bank, mobile_banking, cheque, adjustment', 'example' => 'bank'],
            'transaction_type' => ['description' => 'Type: receive, discount, write_off, payment(refund)', 'example' => 'receive'],
            'amount'           => ['description' => 'Payment amount', 'example' => 5000.00],
            'bank_id'          => ['description' => 'Required when payment_mode=bank', 'example' => 2],
            'allocations'      => [
                'description' => 'Optional: allocate this payment to specific invoices',
                'example' => [
                    ['invoice_id' => 42, 'allocated_amount' => 3000.00],
                    ['invoice_id' => 55, 'allocated_amount' => 2000.00],
                ],
            ],
            'auto_confirm'     => ['description' => 'Set true to create+confirm in one request (skips draft state)', 'example' => true],
        ];
    }
}

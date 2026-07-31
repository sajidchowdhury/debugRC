<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Store Supplier Transaction Request — Phase 1 (Accounts Sub-Ledger).
 *
 * Promotes the inline $request->validate() that used to live in
 * SupplierTransactionController::store() into a typed Form Request.
 *
 * Validation rules mirror the supplier_payments schema + the
 * transaction_type / payment_mode CHECK constraints enforced at the
 * database level (see migration 2026_08_01_000001_add_transaction_type_to_supplier_payments).
 *
 * RBAC is handled by the route middleware (role:accountant,manager,admin)
 * + branch.isolation on the store route. authorize() returns true so the
 * Form Request does not double-gate; the SupplierTransactionPolicy is the
 * defense-in-depth layer (see AppServiceProvider registration).
 *
 * Transaction types:
 *   - payment:  Paying a supplier → Dr AP, Cr Bank/Cash
 *   - advance:  Advance payment to supplier → Dr AP, Cr Bank/Cash
 *   - receive:  Goods received on credit → Dr Inventory, Cr AP
 *
 * Payment modes (matches supplier_payments.payment_mode CHECK constraint):
 *   cash, bank, mobile_banking, cheque, adjustment
 */
class StoreSupplierTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // RBAC is enforced by the route middleware (role:accountant,manager,admin)
        // + branch.isolation. The SupplierTransactionPolicy provides
        // defense-in-depth via $this->authorize() if wired in the controller.
        return true;
    }

    public function rules(): array
    {
        return [
            'supplier_id'      => ['required', 'integer', 'exists:suppliers,id'],
            'branch_id'        => ['required', 'integer', 'exists:branches,id'],
            'bank_id'          => ['nullable', 'integer', 'exists:banks,id'],
            'payment_mode'     => ['required', 'in:cash,bank,mobile_banking,cheque,adjustment'],
            'transaction_type' => ['required', 'in:payment,advance,receive'],
            'amount'           => ['required', 'numeric', 'min:0.01'],
            'discount_amount'  => ['nullable', 'numeric', 'min:0'],
            'payment_date'     => ['required', 'date'],
            'reference_no'     => ['nullable', 'string', 'max:100'],
            'collected_by'     => ['nullable', 'integer', 'exists:employees,id'],
            'notes'            => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'supplier_id'      => 'supplier',
            'branch_id'        => 'branch',
            'bank_id'          => 'bank account',
            'payment_mode'     => 'payment mode',
            'transaction_type' => 'transaction type',
            'amount'           => 'amount',
            'discount_amount'  => 'discount amount',
            'payment_date'     => 'payment date',
            'reference_no'     => 'reference number',
            'collected_by'     => 'collected by',
            'notes'            => 'notes',
        ];
    }

    /**
     * Build the payload array expected by SupplierTransactionService::createPayment().
     * Resolves nullable fields to sensible defaults and injects created_by.
     */
    public function toServicePayload(): array
    {
        $validated = $this->validated();

        return [
            'supplier_id'      => (int) $validated['supplier_id'],
            'branch_id'        => (int) $validated['branch_id'],
            'bank_id'          => isset($validated['bank_id']) ? (int) $validated['bank_id'] : null,
            'payment_mode'     => $validated['payment_mode'],
            'transaction_type' => $validated['transaction_type'],
            'amount'           => (float) $validated['amount'],
            'discount_amount'  => isset($validated['discount_amount']) ? (float) $validated['discount_amount'] : 0,
            'payment_date'     => $validated['payment_date'],
            'reference_no'     => $validated['reference_no'] ?? null,
            'collected_by'     => isset($validated['collected_by']) ? (int) $validated['collected_by'] : null,
            'notes'            => $validated['notes'] ?? '',
            'created_by'       => auth()->id(),
        ];
    }
}

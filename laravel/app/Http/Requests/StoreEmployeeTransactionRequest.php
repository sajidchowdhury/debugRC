<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Store Employee Transaction Request — Phase 2 (Accounts Sub-Ledger).
 *
 * Promotes the inline $request->validate() that used to live in
 * EmployeeTransactionController::store() into a typed Form Request.
 *
 * Validation rules mirror the employee_transactions schema + the
 * transaction_type / payment_mode CHECK constraints enforced at the
 * database level (see migration 2026_08_01_000002_add_payment_fields_to_employee_transactions).
 *
 * RBAC is handled by the route middleware (role:accountant,manager,admin)
 * + branch.isolation on the store route. authorize() returns true so the
 * Form Request does not double-gate; the EmployeeTransactionPolicy is the
 * defense-in-depth layer (see AppServiceProvider registration).
 *
 * Transaction types (6):
 *   advance, loan, repayment, salary, deduction, adjustment
 *
 * Payment modes (matches employee_transactions.payment_mode CHECK constraint):
 *   cash, bank, mobile_banking, cheque, adjustment
 */
class StoreEmployeeTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // RBAC is enforced by the route middleware (role:accountant,manager,admin)
        // + branch.isolation. The EmployeeTransactionPolicy provides
        // defense-in-depth via $this->authorize() if wired in the controller.
        return true;
    }

    public function rules(): array
    {
        return [
            'employee_id'      => ['required', 'integer', 'exists:employees,id'],
            'branch_id'        => ['required', 'integer', 'exists:branches,id'],
            'bank_id'          => ['nullable', 'integer', 'exists:banks,id'],
            'payment_mode'     => ['required', 'in:cash,bank,mobile_banking,cheque,adjustment'],
            'transaction_type' => ['required', 'in:advance,loan,repayment,salary,deduction,adjustment'],
            'amount'           => ['required', 'numeric', 'min:0.01'],
            'transaction_date' => ['nullable', 'date'],
            'description'      => ['nullable', 'string', 'max:500'],
            'collected_by'     => ['nullable', 'integer', 'exists:employees,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'employee_id'      => 'employee',
            'branch_id'        => 'branch',
            'bank_id'          => 'bank account',
            'payment_mode'     => 'payment mode',
            'transaction_type' => 'transaction type',
            'amount'           => 'amount',
            'transaction_date' => 'transaction date',
            'description'      => 'description',
            'collected_by'     => 'collected by',
        ];
    }

    /**
     * Build the payload array expected by EmployeeTransactionService::createTransaction().
     * Resolves nullable fields to sensible defaults and injects created_by.
     */
    public function toServicePayload(): array
    {
        $validated = $this->validated();

        return [
            'employee_id'      => (int) $validated['employee_id'],
            'branch_id'        => (int) $validated['branch_id'],
            'bank_id'          => isset($validated['bank_id']) ? (int) $validated['bank_id'] : null,
            'payment_mode'     => $validated['payment_mode'],
            'transaction_type' => $validated['transaction_type'],
            'amount'           => (float) $validated['amount'],
            'transaction_date' => $validated['transaction_date'] ?? null,
            'description'      => $validated['description'] ?? '',
            'collected_by'     => isset($validated['collected_by']) ? (int) $validated['collected_by'] : null,
            'created_by'       => auth()->id(),
        ];
    }
}

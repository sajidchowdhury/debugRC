<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Store Money Transfer Request — Phase 4 (Accounts Sub-Ledger).
 *
 * Promotes the inline $request->validate() that used to live in
 * MoneyTransferController::store() into a typed Form Request.
 *
 * Validation rules mirror the money_transfers schema + the transfer_type
 * CHECK constraint enforced at the database level.
 *
 * RBAC is handled by the route middleware (role:accountant,manager,admin)
 * + branch.isolation on the store route. authorize() returns true so the
 * Form Request does not double-gate.
 *
 * Transfer types (4):
 *   cash_to_bank, bank_to_cash, cash_to_cash, bank_to_bank
 *
 * GL posting rules (enforced in MoneyTransferService, NOT here):
 *   cash_to_bank:  Dr Bank Ledger / Cr Cash-Bank
 *   bank_to_cash:  Dr Cash-Bank / Cr Bank Ledger
 *   cash_to_cash:  NO GL (just a record)
 *   bank_to_bank:  Dr Dest Bank Ledger / Cr Source Bank Ledger
 */
class StoreMoneyTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        // RBAC is enforced by the route middleware (role:accountant,manager,admin)
        // + branch.isolation.
        return true;
    }

    public function rules(): array
    {
        return [
            'transfer_type'  => ['required', 'in:cash_to_bank,bank_to_cash,cash_to_cash,bank_to_bank'],
            'from_branch_id' => ['required', 'integer', 'exists:branches,id'],
            'to_branch_id'   => ['required', 'integer', 'exists:branches,id'],
            'from_bank_id'   => ['nullable', 'integer', 'exists:banks,id'],
            'to_bank_id'     => ['nullable', 'integer', 'exists:banks,id'],
            'amount'         => ['required', 'numeric', 'min:0.01'],
            'transfer_date'  => ['required', 'date'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ];
    }

    public function attributes(): array
    {
        return [
            'transfer_type'  => 'transfer type',
            'from_branch_id' => 'from branch',
            'to_branch_id'   => 'to branch',
            'from_bank_id'   => 'from bank account',
            'to_bank_id'     => 'to bank account',
            'amount'         => 'amount',
            'transfer_date'  => 'transfer date',
            'notes'          => 'notes',
        ];
    }

    /**
     * Build the payload array expected by MoneyTransferService::createTransfer().
     * Resolves nullable fields to sensible defaults and injects created_by.
     */
    public function toServicePayload(): array
    {
        $validated = $this->validated();

        return [
            'transfer_type'  => $validated['transfer_type'],
            'from_branch_id' => (int) $validated['from_branch_id'],
            'to_branch_id'   => (int) $validated['to_branch_id'],
            'from_bank_id'   => isset($validated['from_bank_id']) ? (int) $validated['from_bank_id'] : null,
            'to_bank_id'     => isset($validated['to_bank_id']) ? (int) $validated['to_bank_id'] : null,
            'amount'         => (float) $validated['amount'],
            'transfer_date'  => $validated['transfer_date'],
            'notes'          => $validated['notes'] ?? '',
            'created_by'     => auth()->id(),
        ];
    }
}

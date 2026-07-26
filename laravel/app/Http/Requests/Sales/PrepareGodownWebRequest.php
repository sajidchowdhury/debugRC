<?php

namespace App\Http\Requests\Sales;

use App\Models\Employee;
use App\Models\SalesInvoice;
use App\Rules\WarehouseBelongsToBranch;
use App\Rules\WarehouseHasStock;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Phase 3 + Phase 4 + Phase 6 + Phase 10 — Web Form Request for saving the godown copy.
 *
 * Promotes the inline $request->validate() that used to live in
 * SalesChallanController::storeGodown into a typed Form Request so the
 * validation rules + the branch-scoped dispatcher authorization are
 * declared in one place and reusable.
 *
 * Rules:
 *   warehouse_assignments      — required|array (keyed by sales_invoice_items.id)
 *   warehouse_assignments.*    — required|integer|exists:warehouses,id
 *                                + WarehouseBelongsToBranch (Phase 10)
 *                                + WarehouseHasStock (Phase 10, pipeline-aware)
 *   dispatcher_id              — required|array|min:1 (at least one dispatcher)
 *   dispatcher_id.*            — integer|exists:employees.id
 *   dispatched_ctn             — nullable|array (Phase 4: carton packing count)
 *   dispatched_ctn.*           — nullable|numeric|min:0
 *   transport_cost             — nullable|numeric|min:0 (Phase 6: transport
 *                               cost edited at godown; defaults to the
 *                               invoice's current transport_cost on the
 *                               view. A change posts a customer_ledger
 *                               'invoice_adjustment' delta; GL is deferred
 *                               to challan issue.)
 *
 * Additional server-side authorization (withValidator):
 *   Each dispatcher_id MUST reference an Employee whose:
 *     - role         = 'dispatcher'
 *     - is_active    = true
 *     - branch_id    = the invoice's branch_id
 *   This is the "branch-scoped dispatcher existence" check from
 *   challan_godown_copy.md Phase 3 task 5. It cannot be expressed as a
 *   single exists: rule because it depends on the invoice's branch
 *   (resolved from the {invoiceId} route param), so it is added as a
 *   custom after-validator.
 *
 * Phase 10 (A4): warehouse_assignments.* now carries TWO custom rules:
 *   - WarehouseBelongsToBranch: rejects a crafted POST submitting
 *     another branch's warehouse_id with a 422, BEFORE the controller
 *     body runs. Defense-in-depth on top of branch.isolation middleware
 *     + SalesChallanService::prepareGodown's branch lookup.
 *   - WarehouseHasStock: pipeline-aware availability check via
 *     StockAvailabilityService::getWarehouseAvailableQty (excludes the
 *     current invoice's own open dispatch rows so re-edit does not
 *     reserve against itself).
 */
class PrepareGodownWebRequest extends FormRequest
{
    public function authorize(): bool
    {
        // RBAC is handled by the route middleware (role:warehouse_manager,
        // dispatcher, manager, admin). Branch access for the invoice is
        // enforced by EnforceBranchIsolation + the SalesAccess service.
        return true;
    }

    public function rules(): array
    {
        $invoiceId = (int) $this->route('invoiceId');

        return [
            'warehouse_assignments'    => ['required', 'array'],
            'warehouse_assignments.*'  => [
                'required', 'integer', 'exists:warehouses,id',
                new WarehouseBelongsToBranch($invoiceId),
                new WarehouseHasStock($invoiceId),
            ],
            'dispatcher_id'            => ['required', 'array', 'min:1'],
            'dispatcher_id.*'          => ['integer', 'exists:employees,id'],
            'dispatched_ctn'           => ['nullable', 'array'],
            'dispatched_ctn.*'         => ['nullable', 'numeric', 'min:0'],
            'transport_cost'           => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return [
            'warehouse_assignments'  => 'warehouse assignments',
            'dispatcher_id'          => 'dispatcher',
            'dispatcher_id.*'        => 'dispatcher',
            'dispatched_ctn'         => 'dispatched cartons',
            'dispatched_ctn.*'       => 'dispatched cartons',
            'transport_cost'         => 'transport cost',
        ];
    }

    public function messages(): array
    {
        return [
            'dispatcher_id.required' => 'Please select at least one dispatcher for this delivery.',
            'dispatcher_id.min'      => 'Please select at least one dispatcher for this delivery.',
            'dispatcher_id.*.exists' => 'One of the selected dispatchers is not a valid employee.',
        ];
    }

    /**
     * After-validation: each dispatcher must be an active, dispatcher-role
     * employee belonging to the SAME branch as the invoice.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $invoiceId = (int) $this->route('invoiceId');
            if ($invoiceId <= 0) {
                return; // route param missing — let the 404 path handle it
            }

            $invoice = SalesInvoice::select('id', 'branch_id')->find($invoiceId);
            if (!$invoice) {
                return; // controller's findOrFail will 404
            }

            $ids = array_values((array) $this->input('dispatcher_id', []));
            $ids = array_filter(array_map('intval', $ids), static fn($v) => $v > 0);
            if (empty($ids)) {
                return; // required|min:1 already covers this
            }

            // Count how many of the submitted ids match an active dispatcher
            // in the invoice's branch.
            $valid = Employee::query()
                ->where('role', 'dispatcher')
                ->where('is_active', true)
                ->where('branch_id', $invoice->branch_id)
                ->whereIn('id', $ids)
                ->count();

            if ($valid !== count($ids)) {
                $validator->errors()->add(
                    'dispatcher_id',
                    'All dispatchers must be active employees with the dispatcher role in the invoice\'s branch.'
                );
            }
        });
    }
}

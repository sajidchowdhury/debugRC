<?php

namespace App\Http\Requests\Sales;

use App\Models\Employee;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 3-step godown workflow — Step 1 form request.
 *
 * Validates the "Print Blank Godown Copy" action: the warehouse manager
 * must select at least one dispatcher BEFORE the blank godown copy can be
 * printed. The dispatcher carries the handwriting picking sheet to the
 * warehouse floor, so a delivery without a dispatcher is meaningless.
 *
 * Rules:
 *   dispatcher_id        — required|array|min:1 (at least one dispatcher)
 *   dispatcher_id.*      — integer|exists:employees,id
 *
 * Additional server-side authorization (withValidator):
 *   Each dispatcher_id MUST reference an Employee whose:
 *     - role         = 'dispatcher'
 *     - is_active    = true
 *     - branch_id    = the invoice's branch_id (admins can cross-branch)
 *   This mirrors the branch-scoped check in PrepareGodownWebRequest.
 *
 * On success, SalesChallanController::storeBlankGodown() syncs the
 * dispatchers onto the invoice, stamps is_blank_godown_printed=true
 * (first print only), and redirects to the read-only print view.
 */
class PrintBlankGodownWebRequest extends FormRequest
{
    public function authorize(): bool
    {
        // RBAC is handled by the route middleware
        // (role:warehouse_manager,dispatcher,manager,admin). Branch access
        // for the invoice is enforced by EnforceBranchIsolation.
        return true;
    }

    public function rules(): array
    {
        return [
            'dispatcher_id'   => ['required', 'array', 'min:1'],
            'dispatcher_id.*' => ['integer', 'exists:employees,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'dispatcher_id'   => 'dispatcher',
            'dispatcher_id.*' => 'dispatcher',
        ];
    }

    public function messages(): array
    {
        return [
            'dispatcher_id.required' => 'Please select at least one dispatcher before printing the blank godown copy.',
            'dispatcher_id.min'      => 'Please select at least one dispatcher before printing the blank godown copy.',
            'dispatcher_id.*.exists' => 'One of the selected dispatchers is not a valid employee.',
        ];
    }

    /**
     * After-validation: each dispatcher must be an active, dispatcher-role
     * employee belonging to the SAME branch as the invoice (admins may
     * cross-branch). Mirrors PrepareGodownWebRequest::withValidator.
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

            // Admins/superadmins may attach dispatchers from any branch
            // (cross-branch access). Non-admins are locked to the invoice's
            // branch.
            $user = $this->user();
            $canCrossBranch = $user && method_exists($user, 'isAdmin') && $user->isAdmin();

            $valid = Employee::query()
                ->where('role', 'dispatcher')
                ->where('is_active', true)
                ->when(!$canCrossBranch, static fn($q) => $q->where('branch_id', $invoice->branch_id))
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

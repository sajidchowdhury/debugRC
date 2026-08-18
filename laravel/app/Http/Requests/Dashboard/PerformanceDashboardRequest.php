<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * FormRequest for the UserPerformanceDashboardController endpoints —
 * REPORTS-AUDIT-3 (G-148 / dashboards.md G10).
 *
 * Replaces the bare `Request $request` type hint on the dashboard endpoints
 * (index, salesTrendAjax, fragmentAjax). The controller body still reads
 * `$request->input('period')`, `$request->input('from')`, etc. — this
 * FormRequest only validates the inputs first, so malformed `from=abc`
 * gets a 422 instead of relying on the manual `isValidDate()` regex
 * fallback to MTD.
 *
 * Authorization is delegated to the route-level `auth` middleware (the
 * dashboard routes are inside the `auth` group). The controller enforces
 * role-based section visibility via `resolveRoleSections()` (which this
 * wave tightens — unknown roles now get NO sections instead of a
 * permissive default).
 *
 * The `withValidator()` hook enforces the semantic constraint:
 *   - When `period=custom`, BOTH `from` and `to` MUST be present.
 *   - When `period` is NOT `custom`, `from` / `to` are ignored (the
 *     controller's `resolvePeriod()` reads them only inside the `custom`
 *     case, so validating them is harmless but unnecessary).
 */
class PerformanceDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is delegated to route-level `auth` middleware —
        // this FormRequest only validates filter inputs. Role-based section
        // visibility is enforced downstream in resolveRoleSections().
        return true;
    }

    public function rules(): array
    {
        return [
            'period'     => ['nullable', 'string', 'in:today,mtd,qtd,last30,custom'],
            'from'       => ['nullable', 'date', 'before_or_equal:today'],
            'to'         => ['nullable', 'date', 'after_or_equal:from', 'before_or_equal:today'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            // No min/max constraint — the controller clamps out-of-range values
            // to [7, 90] internally. Rejecting at the FormRequest level would
            // prevent the clamping logic from exercising (G-297 test contract).
            'days'       => ['nullable', 'integer'],
        ];
    }

    /**
     * Semantic constraint: when period=custom, both from AND to must be
     * present. When period is NOT custom, from/to are ignored (the
     * controller's resolvePeriod only reads them in the custom branch).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $period = $this->input('period');

            if ($period === 'custom') {
                if (!$this->filled('from')) {
                    $validator->errors()->add(
                        'from',
                        'The from field is required when period is custom.'
                    );
                }
                if (!$this->filled('to')) {
                    $validator->errors()->add(
                        'to',
                        'The to field is required when period is custom.'
                    );
                }
            }
        });
    }
}

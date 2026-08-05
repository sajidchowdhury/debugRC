<?php

namespace App\Http\Requests\Export;

/**
 * Budget Variance CSV export FormRequest — REPORTS-AUDIT-1
 * (G-134/G-138 / csv-export.md G7).
 *
 * GET /admin/budgets/export-csv
 *
 * Tightens the base ExportRequest with budget-specific filter validation:
 *   - `fiscal_year` is a 4-digit year (or 'YYYY-YYYY' for cross-year
 *     fiscal calendars) — max 9 chars.
 *   - `dimension_id` must exist in the dimensions table (nullable — most
 *     budgets are unidimensional).
 *
 * NOTE: the controller's `exportCsv()` currently reads only `fiscal_year`
 * and `branch_id`. The `dimension_id` field is added here for forward-compat
 * — when the BudgetController is extended to filter by analytical dimension,
 * the validation will already be in place.
 *
 * The global 365-day cap from the base class doesn't apply (budgets are
 * fiscal-year-scoped, not date-range-scoped) — but it's harmless to inherit
 * since the controller never sends from_date/to_date.
 */
class BudgetExportRequest extends ExportRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // fiscal_year accepts '2026' (calendar year) or '2026-2027'
            // (cross-year fiscal calendar). Max 9 chars covers both shapes.
            'fiscal_year' => ['nullable', 'string', 'max:9'],
            // dimension_id is forward-compat for the analytical-dimension
            // filter (not yet wired into the controller, but the validation
            // is in place so a future controller change doesn't need to
            // touch the FormRequest).
            'dimension_id' => ['nullable', 'integer', 'exists:dimensions,id'],
        ]);
    }
}

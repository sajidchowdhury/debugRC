<?php

namespace App\Http\Requests\Reports;

/**
 * FormRequest for the Stock Take Variance report — REPORTS-AUDIT-3
 * (G-133 / reports-catalog.md G6).
 *
 * Extends ReportRangeRequest to inherit the from_date / to_date / branch_id
 * validation, then adds the per-filter fields unique to the variance report:
 *   - session_id    → which stock_take_sessions row to scope to.
 *   - warehouse_id  → which warehouse to scope to.
 *   - product_id    → which product to scope to.
 *
 * Used by both ReportController::stocktakeVariance (HTML view) and
 * ReportController::stocktakeVarianceExport (CSV download). Both methods
 * read the same `$filters` shape from the request.
 */
class StocktakeVarianceRequest extends ReportRangeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'session_id'   => ['nullable', 'integer', 'exists:stock_take_sessions,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'product_id'   => ['nullable', 'integer', 'exists:products,id'],
        ]);
    }
}

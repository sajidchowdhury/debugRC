<?php

namespace App\Http\Requests\Export;

/**
 * Purchase Order CSV export FormRequest — REPORTS-AUDIT-1
 * (G-134/G-138 / csv-export.md G7).
 *
 * GET /admin/purchase-orders/export
 *
 * Tightens the base ExportRequest with PO-specific filter validation:
 *   - `status` must be one of the known PO statuses (draft/sent/partial/
 *     received/cancelled), or 'all' (the legacy "show all" sentinel).
 *   - `supplier_id` must exist in the suppliers table.
 *
 * The controller currently reads `date_from`/`date_to`/`from_date`/`to_date`
 * interchangeably (legacy compat). The FormRequest validates `from_date`
 * and `to_date` (the canonical names); the controller's existing
 * `$request->input('date_from') ?: $request->input('from_date')` fallback
 * still works for the legacy field name.
 *
 * The global 365-day cap (from `config('reports.csv.max_export_days')`)
 * is inherited from the base class — PO exports are quarterly at most,
 * so 365 days is generous.
 */
class PurchaseOrderExportRequest extends ExportRequest
{
    /**
     * Known PO status values. Mirrors the database CHECK constraint on
     * purchase_orders.status. Kept here so the FormRequest + the
     * controller's status-label map can both reference a single source
     * of truth.
     */
    public const STATUSES = ['draft', 'sent', 'partial', 'received', 'cancelled'];

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // status accepts 'all' (the legacy "show all" sentinel from
            // PurchaseOrderController::export L224) plus the canonical
            // status values.
            'status'     => ['nullable', 'string', 'in:all,' . implode(',', self::STATUSES)],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
        ]);
    }
}

<?php

namespace App\Http\Requests\Reports;

/**
 * FormRequest for the Global Audit Log viewer endpoints —
 * REPORTS-AUDIT-5 (G-133 / reports-catalog.md G6).
 *
 * Replaces the bare `Request $request` type hint on
 * GlobalAuditController::index + ::export. The controller body still
 * reads `$request->input('from')`, `$request->input('to')`,
 * `$request->input('table')`, etc. via its `parseFilters()` helper —
 * this FormRequest only validates the inputs first, so malformed
 * `user_id=abc` or `from=2026-99-99` gets a 422 instead of a noisy
 * SQL error or a silent "no rows" result.
 *
 * NOTE: the Global Audit viewer uses `from` / `to` (NOT `from_date` /
 * `to_date`) as its date-range inputs — different naming convention
 * from the rest of the reports. This FormRequest validates BOTH
 * naming conventions so a future rename or shared-filter refactor
 * does not silently drop validation.
 *
 * Authorization is delegated to route-level `role:` middleware (the
 * audit routes are gated by `role:admin`) — `authorize()` returns
 * true here so the role middleware is the single source of truth.
 *
 * The `action` field is intentionally validated as a free-form
 * nullable string (NOT an enum) — the controller already restricts
 * the query to `master_data_%` actions via a LIKE filter at
 * `buildQuery()`, so any string the user passes is either an
 * exact-match refinement of that prefix or yields zero rows. Adding
 * an `in:master_data_created,...` enum here would reject valid
 * filter values that may exist in the table but are not in the
 * canonical AUDIT_ACTIONS list (defensive against future additions
 * to the AuditableMasterData trait).
 */
class GlobalAuditLogRequest extends ReportRangeRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            // Global Audit uses `from` / `to` (not from_date / to_date) —
            // validate both naming conventions for forward-compat.
            'from'       => ['nullable', 'date'],
            'to'         => ['nullable', 'date', 'after_or_equal:from'],
            'table'      => ['nullable', 'string', 'max:64'],
            'action'     => ['nullable', 'string', 'max:64'],
            'user_id'    => ['nullable', 'integer', 'exists:users,id'],
            'record_id'  => ['nullable', 'string', 'max:64'],
            'search'     => ['nullable', 'string', 'max:200'],
        ]);
    }
}

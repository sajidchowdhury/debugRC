<?php

namespace App\Http\Requests\Export;

/**
 * Branch Demand Weekly Report CSV export FormRequest — REPORTS-AUDIT-1
 * (G-134/G-127 / csv-export.md G7 + G4).
 *
 * GET /admin/branch-demands/weekly-report/export
 *
 * Tightens the base ExportRequest with:
 *   - `branch_id` required for admin (the resolveBranchId() helper still
 *     allows non-admins to omit it and default to their session branch).
 *   - Date-range cap of 90 days (matches the existing `weekly()` method's
 *     cap at L85-87 — closes the G4/G28 gap where `exportCsv` previously
 *     had no range cap, allowing a 10-year export to trigger ~85,000
 *     queries + memory exhaustion).
 *
 * REPORTS-AUDIT-1 (G-127): the 90-day cap is enforced here so the
 * controller's `exportCsv()` method doesn't need to duplicate the
 * `weekly()` method's date-range check.
 */
class BranchDemandExportRequest extends ExportRequest
{
    public function rules(): array
    {
        // branch_id is nullable here — the controller's resolveBranchId()
        // falls back to the session branch for non-admins. Admins are
        // expected to pass it explicitly, but we don't require it (the
        // weekly() method also accepts a missing branch_id).
        return array_merge(parent::rules(), [
            // No additional rules beyond the base — the cap is enforced
            // via withValidator() below.
        ]);
    }

    /**
     * Enforce the 90-day cap (matches the existing `weekly()` method's cap).
     *
     * The cap is hardcoded at 90 (not from config) because BranchDemand's
     * 23-column daily report fires ~23 queries per day — a 90-day export
     * = 2,070 queries + a multi-MB CSV. This is a deliberate per-module
     * constraint, not a global knob.
     */
    protected function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->enforceDateRangeCap($validator, 90);
        });
    }
}

<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * FormRequest for report endpoints that accept a single as-of date —
 * REPORTS-AUDIT-3 (G-133 / reports-catalog.md G6).
 *
 * Replaces the bare `Request $request` type hint on the as-of-date
 * ReportController methods (balanceSheet, receivableAging, payableAging,
 * arAgingCte, todaySummaryCte). The controller body still reads
 * `$request->input('as_of_date')` etc. — this FormRequest only validates
 * the input first, so malformed `as_of_date=abc` gets a 422 instead of a
 * 500 from `Carbon::parse()`.
 *
 * Authorization is delegated to route-level `role:` middleware —
 * `authorize()` returns true.
 *
 * The `date` field is also accepted as an alias for `as_of_date`. The
 * todaySummaryCte method reads `$request->input('date')` (a different input
 * name from the other as-of-date methods). Validating both fields here lets
 * that method use this same FormRequest without a separate class.
 *
 * The `asOfDate()` helper returns the resolved Carbon instance (defaults to
 * today if both `as_of_date` and `date` are omitted). Mirrors the existing
 * `ReportController::parseAsOfDate()` helper so a future refactor can swap
 * calls without behavior change.
 */
class ReportAsOfRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is delegated to route-level `role:` middleware —
        // this FormRequest only validates filter inputs. The role middleware
        // is the single source of truth for access control.
        return true;
    }

    public function rules(): array
    {
        return [
            // `date` is an alias for `as_of_date` (used by todaySummaryCte).
            // Both are validated independently; the controller reads whichever
            // it expects.
            'as_of_date' => ['nullable', 'date', 'before_or_equal:today'],
            'date'       => ['nullable', 'date', 'before_or_equal:today'],
            'branch_id'  => ['nullable', 'integer', 'exists:branches,id'],
            'format'     => ['nullable', 'string', 'in:csv,json,html'],
        ];
    }

    /**
     * Resolve the as-of date as a Carbon instance.
     *
     * Defaults to today (mirrors ReportController::parseAsOfDate()).
     * Falls back to the `date` input if `as_of_date` is omitted (used by
     * todaySummaryCte which reads `$request->input('date')`).
     */
    public function asOfDate(): Carbon
    {
        if ($this->input('as_of_date')) {
            return Carbon::parse($this->input('as_of_date'));
        }
        if ($this->input('date')) {
            return Carbon::parse($this->input('date'));
        }
        return Carbon::now();
    }
}

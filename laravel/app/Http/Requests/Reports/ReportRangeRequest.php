<?php

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Base FormRequest for report endpoints that accept a from_date / to_date
 * range — REPORTS-AUDIT-3 (G-133 / reports-catalog.md G6).
 *
 * Replaces the bare `Request $request` type hint on the highest-traffic
 * ReportController methods (trialBalance, profitAndLoss, cashFlow,
 * grossMarginCte, generalLedgerCte, stocktakeVariance, etc.). The
 * controller body still reads `$request->input('from_date')` etc. — this
 * FormRequest only validates the inputs first, so malformed `from_date=abc`
 * gets a 422 instead of a 500 from `Carbon::parse()`.
 *
 * Authorization is delegated to route-level `role:` middleware (the report
 * routes are already gated by `role:accountant,manager,admin` or tighter) —
 * `authorize()` returns true here so the role middleware is the single
 * source of truth for access control.
 *
 * The `dateRange()` helper returns the [from, to] pair as Carbon instances
 * with sensible defaults (start-of-month to today if both omitted). Mirrors
 * the existing `ReportController::parseDateRange()` helper so a future
 * refactor can swap calls without behavior change.
 */
class ReportRangeRequest extends FormRequest
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
            'from_date' => ['nullable', 'date', 'before_or_equal:today'],
            'to_date'   => ['nullable', 'date', 'after_or_equal:from_date', 'before_or_equal:today'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'format'    => ['nullable', 'string', 'in:csv,json,html'],
        ];
    }

    /**
     * Resolve the [from, to] date range as Carbon instances.
     *
     * Defaults mirror ReportController::parseDateRange():
     *   - from_date omitted → start of the current month.
     *   - to_date omitted   → now (today).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function dateRange(): array
    {
        $from = $this->input('from_date')
            ? Carbon::parse($this->input('from_date'))
            : Carbon::now()->startOfMonth();
        $to = $this->input('to_date')
            ? Carbon::parse($this->input('to_date'))
            : Carbon::now();

        return [$from, $to];
    }

    /**
     * Compute the inclusive day count between from_date and to_date.
     *
     * Returns 0 if either date is missing — cap checks treat 0 as
     * "no explicit range" (default month-to-date range never exceeds any
     * reasonable cap).
     */
    public function dateRangeDays(): int
    {
        $from = $this->input('from_date');
        $to = $this->input('to_date');

        if (!$from || !$to) {
            return 0;
        }

        try {
            return (int) Carbon::parse($from)->startOfDay()->diffInDays(
                Carbon::parse($to)->startOfDay()
            ) + 1;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

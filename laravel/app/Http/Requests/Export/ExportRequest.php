<?php

namespace App\Http\Requests\Export;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Base FormRequest for CSV/JSON/HTML export endpoints — REPORTS-AUDIT-1
 * (G-134 / csv-export.md G7).
 *
 * Per-module export endpoints (BranchDemandExportRequest, PurchaseOrderExportRequest,
 * BudgetExportRequest, etc.) extend this base class to inherit common filter
 * validation. The base rules cover the typical date-range + branch + warehouse
 * + format filters that appear on almost every export endpoint.
 *
 * Authorization is delegated to route-level `role:` middleware (the export
 * routes are already gated by `role:accountant,manager,admin` or tighter) —
 * `authorize()` returns true here so the role middleware is the single source
 * of truth for access control.
 *
 * The `dateRangeDays()` helper lets subclasses enforce a per-module cap
 * (e.g. BranchDemand caps at 90 days; the global ceiling is
 * `config('reports.csv.max_export_days')` = 365 days). Subclasses override
 * `withValidator()` to call `$this->enforceDateRangeCap($validator, $maxDays)`.
 */
abstract class ExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is delegated to route-level `role:` middleware —
        // this FormRequest only validates filter inputs. The role middleware
        // is the single source of truth for access control.
        return true;
    }

    /**
     * Common filter rules shared by every export endpoint.
     *
     * Subclasses extend this via `rules()` → `array_merge(parent::rules(), [...])`
     * to add module-specific fields (e.g. PurchaseOrderExportRequest adds
     * `status`, `supplier_id`).
     */
    public function rules(): array
    {
        return [
            'from_date'    => ['nullable', 'date', 'before_or_equal:today'],
            'to_date'      => ['nullable', 'date', 'after_or_equal:from_date', 'before_or_equal:today'],
            'branch_id'    => ['nullable', 'integer', 'exists:branches,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'format'       => ['nullable', 'string', 'in:csv,json,html'],
        ];
    }

    /**
     * Compute the day-count between from_date and to_date.
     *
     * Returns null if either date is missing or invalid (the validator will
     * already have flagged that case).
     *
     * @return int|null Number of days in the range (inclusive of both endpoints).
     */
    public function dateRangeDays(): ?int
    {
        $from = $this->input('from_date');
        $to = $this->input('to_date');

        if (!$from || !$to) {
            return null;
        }

        try {
            return (int) Carbon::parse($from)->startOfDay()->diffInDays(
                Carbon::parse($to)->startOfDay()
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get the maximum allowed date-range span (days).
     *
     * Defaults to the global ceiling `config('reports.csv.max_export_days')`
     * (365). Subclasses can override to enforce a tighter cap (e.g.
     * BranchDemand caps at 90 days).
     */
    protected function maxDateRangeDays(): int
    {
        return (int) config('reports.csv.max_export_days', 365);
    }

    /**
     * Hook the validator to enforce a date-range cap.
     *
     * Subclasses call this from their `withValidator()` override:
     *
     *   protected function withValidator($validator)
     *   {
     *       $validator->after(function ($validator) {
     *           $this->enforceDateRangeCap($validator, 90);
     *       });
     *   }
     *
     * @param  \Illuminate\Validation\Validator $validator
     * @param  int $maxDays The maximum allowed span (e.g. 90 for BranchDemand).
     */
    protected function enforceDateRangeCap($validator, int $maxDays): void
    {
        $days = $this->dateRangeDays();

        if ($days === null) {
            return; // missing/invalid dates — date rule already flagged it.
        }

        if ($days > $maxDays) {
            $validator->errors()->add(
                'to_date',
                "Date range cannot exceed {$maxDays} days (requested {$days} days)."
            );
        }
    }
}

<?php
namespace App\Services\Dashboard\Concerns;

/**
 * Period Range Helpers — shared by dashboard metric services.
 *
 * Extracted from UserPerformanceDashboardController (G-144 / dashboards.md G9,
 * HIGH-WAVE-3). Used by services that need to compute the previous period
 * for growth/comparison metrics (SalesPerformanceMetricsService +
 * CollectionMetricsService; also composed into StockDisciplineMetricsService
 * for parity / future use).
 *
 * The trait is stateless — the using class simply calls
 * $this->previousPeriodRange($range) as if the method were local.
 */
trait PeriodRangeHelpers
{
    /**
     * Compute the previous-period range (same length, immediately before $range).
     * Used for growth-pct comparisons.
     *
     * Examples:
     *   range = [2026-07-01, 2026-07-31] (MTD, 31 days) → prev = [2026-06-01, 2026-06-30]
     *   range = [2026-07-15, 2026-07-15] (today, 1 day)  → prev = [2026-07-14, 2026-07-14]
     */
    public function previousPeriodRange(array $range): array
    {
        try {
            $end = \Carbon\Carbon::parse($range['end']);
            $start = \Carbon\Carbon::parse($range['start']);
            $length = $end->diffInDays($start) + 1; // inclusive
            $prevEnd = $end->copy()->subDays($length);
            $prevStart = $prevEnd->copy()->subDays($length - 1);
            return ['start' => $prevStart->toDateString(), 'end' => $prevEnd->toDateString()];
        } catch (\Throwable $e) {
            return ['start' => $range['start'], 'end' => $range['end']];
        }
    }
}

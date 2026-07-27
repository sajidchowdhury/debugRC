<?php

namespace Tests\Unit\StockTake;

use App\Services\Stock\StockTakeVarianceReport;
use Tests\TestCase;

/**
 * Phase 12 — unit tests for StockTakeVarianceReport::summarize().
 *
 * The summarize() method is a PURE function: given an array of row objects
 * (each carrying variance_qty, value_diff, and revaluation_amount), it
 * computes the aggregate totals (counts, sums, gain/loss breakdown,
 * revaluation accumulation above the 0.01 epsilon threshold). No DB access,
 * no side effects, no service dependencies — perfect for table-driven unit
 * testing.
 *
 * Row shape consumed by summarize() (verified by reading the service body):
 *   - variance_qty       (int|float|null)  — signed difference (physical -
 *                                             system). Positive = gain,
 *                                             negative = loss, 0 = no
 *                                             variance (counted in
 *                                             total_items but NOT in
 *                                             gain_lines/loss_lines).
 *   - value_diff         (int|float|null)  — variance_qty * rate (currency).
 *                                             Signed: positive for gains,
 *                                             negative for losses.
 *   - revaluation_amount (int|float|null)  — Phase 9 cost-drift adjusting
 *                                             amount. Accumulated into
 *                                             total_revaluation ONLY when
 *                                             abs(value) >= 0.01 (the
 *                                             epsilon threshold). Missing
 *                                             property → treated as 0 via
 *                                             the ?? 0 fallback.
 *
 * Rounding rules (verified by reading the service body):
 *   - total_variance     → round(…, 4)   (qty precision)
 *   - total_value_diff   → round(…, 2)   (currency precision)
 *   - gain_value         → round(…, 2)
 *   - loss_value         → round(…, 2)   (loss_value is the ABSOLUTE sum of
 *                                         loss lines' value_diff — always ≥ 0)
 *   - total_revaluation  → round(…, 6)   (high-precision cost-drift math)
 *
 * These tests construct rows as `(object) [...]` stdClass instances so we
 * exercise the exact property-access path the service uses ($row->variance_qty
 * etc.) rather than array access. Each test stays inside the DatabaseTransactions
 * wrapper (inherited from TestCase) for consistency with the rest of the suite,
 * even though no DB I/O is performed — this avoids per-test bootstrap
 * differences.
 */
class StockTakeVarianceReportTest extends TestCase
{
    private StockTakeVarianceReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->report = app(StockTakeVarianceReport::class);
    }

    /**
     * Convenience: build a row object with the three properties summarize()
     * reads. revaluation_amount defaults to 0 (the typical non-revaluation
     * case); pass a value to exercise the Phase 9 accumulation path.
     */
    private function row($varianceQty, $valueDiff, $revaluationAmount = 0): object
    {
        return (object) [
            'variance_qty'       => $varianceQty,
            'value_diff'         => $valueDiff,
            'revaluation_amount' => $revaluationAmount,
        ];
    }

    public function test_summarize_with_empty_array_returns_all_zero_totals(): void
    {
        $totals = $this->report->summarize([]);

        $this->assertSame(0, $totals['total_items']);
        $this->assertSame(0.0, $totals['total_variance']);
        $this->assertSame(0.0, $totals['total_value_diff']);
        $this->assertSame(0, $totals['gain_lines']);
        $this->assertSame(0, $totals['loss_lines']);
        $this->assertSame(0.0, $totals['gain_value']);
        $this->assertSame(0.0, $totals['loss_value']);
        $this->assertSame(0.0, $totals['total_revaluation']);
        $this->assertSame(0, $totals['reval_lines']);
    }

    public function test_summarize_with_gain_only_lines(): void
    {
        // 2 gains: +3 units (val 30) and +1 unit (val 10) → total variance +4,
        // total value +40, 2 gain lines, 0 loss lines.
        $rows = [
            $this->row(3, 30),
            $this->row(1, 10),
        ];

        $totals = $this->report->summarize($rows);

        $this->assertSame(2, $totals['total_items']);
        $this->assertSame(4.0, $totals['total_variance']);
        $this->assertSame(40.0, $totals['total_value_diff']);
        $this->assertSame(2, $totals['gain_lines']);
        $this->assertSame(0, $totals['loss_lines']);
        $this->assertSame(40.0, $totals['gain_value']);
        $this->assertSame(0.0, $totals['loss_value']);
    }

    public function test_summarize_with_loss_only_lines(): void
    {
        // 2 losses: -2 units (val -20) and -1 unit (val -5) → total variance
        // -3, total value -25, 0 gain lines, 2 loss lines.
        // loss_value is the ABSOLUTE sum (always ≥ 0).
        $rows = [
            $this->row(-2, -20),
            $this->row(-1, -5),
        ];

        $totals = $this->report->summarize($rows);

        $this->assertSame(2, $totals['total_items']);
        $this->assertSame(-3.0, $totals['total_variance']);
        $this->assertSame(-25.0, $totals['total_value_diff']);
        $this->assertSame(0, $totals['gain_lines']);
        $this->assertSame(2, $totals['loss_lines']);
        $this->assertSame(0.0, $totals['gain_value']);
        $this->assertSame(25.0, $totals['loss_value']);
    }

    public function test_summarize_with_mixed_gain_and_loss(): void
    {
        // 1 gain (+3, val 30) + 1 loss (-2, val -20) → total variance +1,
        // total value +10 (signed), 1 gain line, 1 loss line.
        // gain_value=30 (positive sum), loss_value=20 (absolute loss sum).
        $rows = [
            $this->row(3, 30),
            $this->row(-2, -20),
        ];

        $totals = $this->report->summarize($rows);

        $this->assertSame(2, $totals['total_items']);
        $this->assertSame(1.0, $totals['total_variance']);
        $this->assertSame(10.0, $totals['total_value_diff']);
        $this->assertSame(1, $totals['gain_lines']);
        $this->assertSame(1, $totals['loss_lines']);
        $this->assertSame(30.0, $totals['gain_value']);
        $this->assertSame(20.0, $totals['loss_value']);
    }

    public function test_summarize_with_zero_variance_line_excluded_from_gain_loss_counts(): void
    {
        // 1 gain (+3, val 30) + 1 zero (0, val 0) → the zero line is counted
        // in total_items (every row is) but NOT in gain_lines or loss_lines.
        $rows = [
            $this->row(3, 30),
            $this->row(0, 0),
        ];

        $totals = $this->report->summarize($rows);

        $this->assertSame(2, $totals['total_items']);
        $this->assertSame(1, $totals['gain_lines']);
        $this->assertSame(0, $totals['loss_lines']);
        $this->assertSame(3.0, $totals['total_variance']);
        $this->assertSame(30.0, $totals['total_value_diff']);
        $this->assertSame(30.0, $totals['gain_value']);
        $this->assertSame(0.0, $totals['loss_value']);
    }

    public function test_summarize_accumulates_revaluation_amount_when_above_epsilon(): void
    {
        // The epsilon threshold is 0.01. A revaluation_amount of 0.5 is above
        // the threshold → accumulated into total_revaluation + counted as a
        // reval_line.
        $rows = [$this->row(1, 10, 0.5)];

        $totals = $this->report->summarize($rows);

        $this->assertSame(0.5, $totals['total_revaluation']);
        $this->assertSame(1, $totals['reval_lines']);
    }

    public function test_summarize_skips_revaluation_below_epsilon(): void
    {
        // 0.005 is below the 0.01 epsilon → not accumulated, not counted.
        $rows = [$this->row(1, 10, 0.005)];

        $totals = $this->report->summarize($rows);

        $this->assertSame(0.0, $totals['total_revaluation']);
        $this->assertSame(0, $totals['reval_lines']);
    }

    public function test_summarize_rounds_totals_to_2_decimal_places_for_currency_and_4_for_qty(): void
    {
        // Construct rows whose raw sums have > 2 decimal places (currency)
        // and > 4 decimal places (qty) so the round() calls actually trim
        // something. total_variance uses round(…, 4); total_value_diff uses
        // round(…, 2).
        $rows = [
            // qty 0.333333 + 0.333333 = 0.666666 → round(…, 4) = 0.6667.
            // val 33.333 + 16.667 = 50.000 → round(…, 2) = 50.00.
            $this->row(0.333333, 33.333),
            $this->row(0.333333, 16.667),
        ];

        $totals = $this->report->summarize($rows);

        // total_variance: 0.666666 → 0.6667 (4 dp).
        $this->assertSame(0.6667, $totals['total_variance']);
        // total_value_diff: 50.000 → 50.0 (PHP float; round(50.000, 2) = 50.0).
        $this->assertSame(50.0, $totals['total_value_diff']);
        // gain_value mirrors total_value_diff for all-gain inputs.
        $this->assertSame(50.0, $totals['gain_value']);
    }

    public function test_summarize_handles_null_properties_gracefully(): void
    {
        // A row with all three properties explicitly null. The service uses
        // `?? 0` on each property access, so null is treated as 0. The line
        // is counted in total_items (every row is) but contributes nothing
        // to variance/value/gain/loss/revaluation.
        $row = (object) [
            'variance_qty'       => null,
            'value_diff'         => null,
            'revaluation_amount' => null,
        ];

        $totals = $this->report->summarize([$row]);

        $this->assertSame(1, $totals['total_items']);
        $this->assertSame(0.0, $totals['total_variance']);
        $this->assertSame(0.0, $totals['total_value_diff']);
        $this->assertSame(0, $totals['gain_lines']);
        $this->assertSame(0, $totals['loss_lines']);
        $this->assertSame(0.0, $totals['gain_value']);
        $this->assertSame(0.0, $totals['loss_value']);
        $this->assertSame(0.0, $totals['total_revaluation']);
        $this->assertSame(0, $totals['reval_lines']);
    }

    public function test_summarize_handles_missing_revaluation_amount_property(): void
    {
        // A row WITHOUT the revaluation_amount field at all. The service
        // reads it via `?? 0`, which yields 0 for an unset property on a
        // stdClass (no warning in PHP 7.x/8.x — stdClass permits property
        // access on undefined names, returning null, which `?? 0` covers).
        // This exercises the forward-compatibility path: a row produced by
        // a pre-Phase-9 query (which didn't select revaluation_amount) must
        // not crash summarize().
        $row = (object) [
            'variance_qty' => 2,
            'value_diff'   => 20,
            // revaluation_amount intentionally absent.
        ];

        $totals = $this->report->summarize([$row]);

        $this->assertSame(1, $totals['total_items']);
        $this->assertSame(2.0, $totals['total_variance']);
        $this->assertSame(20.0, $totals['total_value_diff']);
        $this->assertSame(0.0, $totals['total_revaluation']);
        $this->assertSame(0, $totals['reval_lines']);
    }
}

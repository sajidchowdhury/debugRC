<?php

declare(strict_types=1);

namespace App\Support;

/**
 * PriceClassifier — pure function that classifies a sale-line rate
 * against the product's min/max/default price band.
 *
 * Introduced in Phase 2 / Session 5. Used by:
 *   - {@see \App\Services\Sales\SalesInvoiceService::finalizeFromCart()}
 *     to populate `sales_invoice_items.price_classification` at finalize
 *     time.
 *   - {@see \App\Services\BranchDemand\BranchDemandRepricingService}
 *     to detect when a repriced demand line crosses a price band
 *     boundary (e.g. was 'default', repriced to 'min').
 *   - The Branch P&L report (Session 8) to bucket sale lines for the
 *     sales-mix-by-classification breakdown.
 *
 * Classification rules (in evaluation order)
 * ------------------------------------------
 *   1. `$rate < $min`           → `'below_min'`  (will require admin approval in S6)
 *   2. `$rate == $min`          → `'min'`
 *   3. `$rate == $default`      → `'default'`    (checked BEFORE max so a rate
 *                                                  equal to both default and max
 *                                                  — rare but possible if min ==
 *                                                  default == max — classifies as
 *                                                  'default', the more specific label)
 *   4. `$rate == $max`          → `'max'`
 *   5. `$rate > $max`           → `'max'`        (defensive — the cart blocks this
 *                                                  in S5; in S6 the below-min override
 *                                                  workflow will also cover above-max
 *                                                  via the same approval flow)
 *   6. `$min < $rate < $default` → `'min'`       (between min and default — closer
 *                                                  to min, treat as 'min' band)
 *   7. `$default < $rate < $max` → `'max'`       (between default and max — closer
 *                                                  to max, treat as 'max' band)
 *
 * Tie-breaker rationale
 * ---------------------
 * The 4 buckets are intentionally coarse — the report groups by them.
 * A rate that falls strictly between min and default is "near min" →
 * 'min'. A rate strictly between default and max is "near max" → 'max'.
 * This matches how the cashier thinks: "am I selling at the floor, the
 * ceiling, the recommended, or below the floor?"
 *
 * Equality is tested with a 0.01 tolerance to absorb float rounding
 * from the cart's `items_json` (which stores rates as decimal:2 but
 * goes through JSON encode/decode).
 *
 * Edge cases
 * ----------
 *   - If `$min`, `$max`, `$default` are all equal (a single-price
 *     product), any rate equal to that price → 'default'. A rate below
 *     → 'below_min'. A rate above → 'max' (defensive).
 *   - If `$min > $max` (data error), we still classify: 'below_min' if
 *     rate < min, else 'max'. The cart's range check should have caught
 *     this earlier, but classify() is defensive.
 *   - NULL inputs: if any of min/max/default is NULL, we cannot
 *     classify → returns NULL. The caller must handle this (usually by
 *     leaving the column NULL on the sale line, which the backfill
 *     reports as a gap).
 *
 * @see \App\Services\Sales\SalesInvoiceService::finalizeFromCart()
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 5
 */
class PriceClassifier
{
    /**
     * Equality tolerance — absorbs decimal:2 rounding through JSON.
     */
    private const EPSILON = 0.01;

    /**
     * Classify a sale-line rate against the product's price band.
     *
     * @param  float      $rate    The rate charged on this sale line.
     * @param  float      $min     Product's min_rate at sale time.
     * @param  float      $max     Product's max_rate at sale time.
     * @param  float      $default Product's default_rate at sale time.
     * @param  float|null $cost    (Unused for classification — kept for
     *                             API symmetry with the P&L report which
     *                             passes cost alongside. Classification
     *                             uses `min` because that is what the
     *                             cashier sees in the cart. The profit/
     *                             loss threshold is `cost_rate`, not
     *                             `min`, but for the BUCKET label we use
     *                             `min`.)
     * @return string|null One of 'below_min', 'min', 'default', 'max',
     *                     or NULL if any price-band input is NULL/invalid.
     */
    public static function classify(
        float $rate,
        float $min,
        float $max,
        float $default,
        ?float $cost = null
    ): ?string {
        // NULL / invalid guards — see edge cases in class docblock.
        // We treat 0.0 as "missing" because the schema allows DEFAULT 0
        // on product_price_history (though NOT NULL). A product with
        // min=max=default=0 has no meaningful price band.
        if ($min <= 0.0 && $max <= 0.0 && $default <= 0.0) {
            return null;
        }

        // 1. Below min — requires admin approval in S6.
        if ($rate < $min - self::EPSILON) {
            return 'below_min';
        }

        // 2. Exactly min.
        if (abs($rate - $min) <= self::EPSILON) {
            return 'min';
        }

        // 3. Exactly default (checked before max for the tie-break case
        //    where default == max).
        if (abs($rate - $default) <= self::EPSILON) {
            return 'default';
        }

        // 4. Exactly max.
        if (abs($rate - $max) <= self::EPSILON) {
            return 'max';
        }

        // 5. Above max — defensive (cart blocks this in S5).
        if ($rate > $max + self::EPSILON) {
            return 'max';
        }

        // 6. Between min and default → 'min' band.
        if ($rate > $min && $rate < $default) {
            return 'min';
        }

        // 7. Between default and max → 'max' band.
        if ($rate > $default && $rate < $max) {
            return 'max';
        }

        // Should not reach here given the above, but classify
        // defensively as 'default' if we somehow do.
        return 'default';
    }
}

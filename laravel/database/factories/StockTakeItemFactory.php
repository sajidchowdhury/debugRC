<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Stock Take Item factory — Phase 12 (testing + monitoring).
 *
 * Generates a `stock_take_items` row. `stock_take_items` has NO timestamps
 * ($timestamps = false on the model). Callers MUST set:
 *   - stock_take_session_id (via forSession)
 *   - warehouse_id          (via forWarehouse)
 *   - product_id            (via forProduct)
 *   - branch_id             (via forBranch — Phase 8 denormalized, NOT NULL)
 *
 * `difference` is a GENERATED column (physical_qty − system_qty) — do NOT
 * set it on insert.
 */
class StockTakeItemFactory extends Factory
{
    /** @var string */
    protected $model = \App\Models\StockTakeItem::class;

    public function definition(): array
    {
        return [
            'stock_take_session_id' => null,
            'warehouse_id'          => null,
            'product_id'            => null,
            'branch_id'             => null,
            'system_qty'            => 0,
            'physical_qty'          => 0,
            'rate'                  => 10.00,
            // Phase 9: costing columns — system_rate is the setup snapshot,
            // post_rate is set at post time. Defaults to mirror rate.
            'system_rate'           => 10.00,
            'post_rate'             => null,
            'revaluation_amount'    => 0,
            'revaluation_line_id'   => null,
            'is_applied'            => false,
            'reason'                => null,
            'journal_line_id'       => null,
        ];
    }

    public function forSession(int $sid): static
    {
        return $this->state(fn (array $attributes) => [
            'stock_take_session_id' => $sid,
        ]);
    }

    public function forWarehouse(int $wid): static
    {
        return $this->state(fn (array $attributes) => [
            'warehouse_id' => $wid,
        ]);
    }

    public function forProduct(int $pid): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $pid,
        ]);
    }

    /**
     * Phase 8 denormalized branch_id — NOT NULL on the table.
     */
    public function forBranch(int $bid): static
    {
        return $this->state(fn (array $attributes) => [
            'branch_id' => $bid,
        ]);
    }

    /**
     * Set a variance of $diff (physical_qty = system_qty + $diff).
     * The `difference` GENERATED column will compute automatically.
     */
    public function withVariance(float $diff): static
    {
        return $this->state(function (array $attributes) use ($diff) {
            $systemQty = (float) ($attributes['system_qty'] ?? 0);
            return [
                'physical_qty' => $systemQty + $diff,
            ];
        });
    }

    /**
     * Mark the line as applied (post-time). Optionally link to a
     * journal_lines.id for per-line GL traceability (Phase 1).
     */
    public function applied(?int $jlId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'is_applied'      => true,
            'journal_line_id' => $jlId,
        ]);
    }
}

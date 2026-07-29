<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Stock Take Warehouse factory — Phase 12 (testing + monitoring).
 *
 * Generates a `stock_take_warehouses` row. The table has NO timestamps
 * ($timestamps = false on the model). Callers MUST set:
 *   - stock_take_session_id (via forSession)
 *   - warehouse_id          (via forWarehouse)
 *   - branch_id             (via forBranch — Phase 8 denormalized, NOT NULL)
 */
class StockTakeWarehouseFactory extends Factory
{
    /** @var string */
    protected $model = \App\Models\StockTakeWarehouse::class;

    public function definition(): array
    {
        return [
            'stock_take_session_id' => null,
            'warehouse_id'          => null,
            'branch_id'             => null,
            // Phase 8 denormalized mirror of the session's freeze_outbound flag
            // (set at insert, never updated). Default false — tests enable it
            // via the session factory's withFreezeOutbound() and the trigger
            // propagates it here at insert time.
            'freeze_outbound'       => false,
            'status'                => 'pending',
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
     * Mark the warehouse mid-count. Phase 7: 'recounting' is transient
     * (the service immediately flips it to 'counting' in the same
     * transaction), so the factory only exposes 'counting'.
     */
    public function counting(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'counting',
        ]);
    }

    /**
     * Mark the warehouse's count complete.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }
}

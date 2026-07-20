<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Warehouse factory — Branch Phase 7 testing.
 *
 * Generates Warehouse records tied to a branch.
 */
class WarehouseFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = \App\Models\Warehouse::class;

    /**
     * Unique sequence counter for warehouse_code uniqueness.
     */
    protected static int $sequence = 0;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        self::$sequence += 1;
        $suffix = strtoupper(substr(uniqid(), -6));

        return [
            'warehouse_code' => 'WH-' . $suffix,
            'warehouse_name' => 'Warehouse ' . $suffix,
            'branch_id'      => null, // must be set by caller
            'location'       => $this->faker->optional()->address(),
            'is_active'      => true,
            'created_by'     => null,
        ];
    }

    /**
     * Indicate that the warehouse belongs to a specific branch.
     */
    public function forBranch(int $branchId): static
    {
        return $this->state(fn (array $attributes) => [
            'branch_id' => $branchId,
        ]);
    }

    /**
     * Indicate that the warehouse is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}

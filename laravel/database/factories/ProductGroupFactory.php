<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ProductGroup factory — Phase 9 testing.
 */
class ProductGroupFactory extends Factory
{
    /** @var string */
    protected $model = \App\Models\ProductGroup::class;

    public function definition(): array
    {
        $suffix = strtoupper(substr(uniqid(), -6));

        return [
            'group_name' => 'Test Group ' . $suffix,
            'description' => $this->faker->optional()->sentence(),
            'sort_order' => 0,
            'is_active'  => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}

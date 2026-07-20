<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ProductCategory factory — Phase 9 testing.
 */
class ProductCategoryFactory extends Factory
{
    /** @var string */
    protected $model = \App\Models\ProductCategory::class;

    public function definition(): array
    {
        $suffix = strtoupper(substr(uniqid(), -6));

        return [
            'category_name' => 'Test Category ' . $suffix,
            'description'   => $this->faker->optional()->sentence(),
            'is_active'     => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}

<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Product factory — Phase 9 testing.
 *
 * Generates Product records tied to optional category/group. Uses uniqid()
 * for product_code uniqueness across PHP process restarts.
 */
class ProductFactory extends Factory
{
    /** @var string */
    protected $model = \App\Models\Product::class;

    public function definition(): array
    {
        $suffix = strtoupper(substr(uniqid(), -6));

        return [
            'product_code'    => 'PRD-' . $suffix,
            'product_name'    => 'Test Product ' . $suffix,
            'category_id'     => null,
            'group_id'        => null,
            'unit'            => $this->faker->randomElement(['Pcs', 'Carton', 'KG', 'Bag', 'Dobe', 'Set']),
            'purchase_rate'   => $this->faker->optional()->randomFloat(2, 1, 1000),
            'sales_rate'      => $this->faker->optional()->randomFloat(2, 1, 1500),
            'min_stock'       => 0,
            'max_stock'       => 0,
            'reorder_level'   => 0,
            'product_image'   => null,
            'is_active'       => true,
            'condition_state' => 'Good',
        ];
    }

    /**
     * Attach the product to a category.
     */
    public function forCategory(int $categoryId): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => $categoryId,
        ]);
    }

    /**
     * Attach the product to a group.
     */
    public function forGroup(int $groupId): static
    {
        return $this->state(fn (array $attributes) => [
            'group_id' => $groupId,
        ]);
    }

    /**
     * Mark the product as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}

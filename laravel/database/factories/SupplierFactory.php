<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Supplier factory — Phase 11 testing.
 *
 * Generates Supplier records tied to an optional branch.
 * Uses uniqid() for supplier_code uniqueness across PHP process restarts.
 */
class SupplierFactory extends Factory
{
    /** @var string */
    protected $model = \App\Models\Supplier::class;

    public function definition(): array
    {
        $suffix = strtoupper(substr(uniqid(), -6));

        return [
            'supplier_code'   => 'SUP-' . $suffix,
            'supplier_name'   => 'Test Supplier ' . $suffix,
            'phone'           => $this->faker->optional()->phoneNumber(),
            'mobile'          => $this->faker->optional()->phoneNumber(),
            'email'           => $this->faker->optional()->safeEmail(),
            'address'         => $this->faker->optional()->address(),
            'branch_id'       => null,
            'contact_person'  => $this->faker->optional()->name(),
            'opening_balance' => 0,
            'balance_type'    => $this->faker->randomElement(['debit', 'credit']),
            'is_active'       => true,
            'deleted_by'      => null,
        ];
    }

    /**
     * Attach the supplier to a specific branch.
     */
    public function forBranch(int $branchId): static
    {
        return $this->state(fn (array $attributes) => [
            'branch_id' => $branchId,
        ]);
    }

    /**
     * Mark the supplier as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}

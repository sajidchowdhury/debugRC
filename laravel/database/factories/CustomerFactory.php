<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Customer factory — Phase 10 testing.
 *
 * Generates Customer records tied to an optional branch + sales person.
 * Uses uniqid() for customer_code uniqueness across PHP process restarts.
 */
class CustomerFactory extends Factory
{
    /** @var string */
    protected $model = \App\Models\Customer::class;

    public function definition(): array
    {
        $suffix = strtoupper(substr(uniqid(), -6));

        return [
            'customer_code'   => 'CUS-' . $suffix,
            'customer_name'   => 'Test Customer ' . $suffix,
            'phone'           => $this->faker->optional()->phoneNumber(),
            'mobile'          => $this->faker->optional()->phoneNumber(),
            'email'           => $this->faker->optional()->safeEmail(),
            'address'         => $this->faker->optional()->address(),
            'branch_id'       => null,
            'sales_person_id' => null,
            'credit_limit'    => 0,
            'opening_balance' => 0,
            'balance_type'    => $this->faker->randomElement(['debit', 'credit']),
            'is_active'       => true,
            'deleted_by'      => null,
        ];
    }

    /**
     * Attach the customer to a specific branch.
     */
    public function forBranch(int $branchId): static
    {
        return $this->state(fn (array $attributes) => [
            'branch_id' => $branchId,
        ]);
    }

    /**
     * Assign a specific sales person (Employee) to the customer.
     */
    public function forSalesPerson(int $employeeId): static
    {
        return $this->state(fn (array $attributes) => [
            'sales_person_id' => $employeeId,
        ]);
    }

    /**
     * Mark the customer as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}

<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Branch factory — Branch Phase 7 testing.
 *
 * Generates realistic RC_ERP-style branches (Head Office, Patuatuli, etc.)
 * with unique codes per factory invocation.
 */
class BranchFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = \App\Models\Branch::class;

    /**
     * Unique sequence counter to ensure branch_code uniqueness across tests.
     */
    protected static int $sequence = 0;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        self::$sequence += 1;

        // Use uniqid() to guarantee uniqueness across PHP process restarts
        // (the static counter resets on each test run, so previous test data
        // left in the DB would collide with new factory-generated codes).
        $suffix = strtoupper(substr(uniqid(), -6));
        $name = 'Test Branch ' . $suffix;

        return [
            'branch_code' => 'TB-' . $suffix,
            'branch_name' => $name,
            'address'     => $this->faker->optional()->address(),
            'phone'       => $this->faker->optional()->phoneNumber(),
            'email'       => $this->faker->optional()->safeEmail(),
            'is_active'   => true,
            'created_by'  => null,
        ];
    }

    /**
     * Indicate that the branch is inactive / soft-deleted.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the branch was created by a specific user.
     */
    public function createdBy(?int $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $userId,
        ]);
    }
}

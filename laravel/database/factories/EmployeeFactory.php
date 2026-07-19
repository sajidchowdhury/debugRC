<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Employee factory — Branch Phase 7 testing.
 *
 * Generates employees tied to a branch with a specific role.
 * Roles mirror config/roles.php canonical list.
 */
class EmployeeFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = \App\Models\Employee::class;

    /**
     * Unique sequence counter for employee_code uniqueness.
     */
    protected static int $sequence = 1000;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        self::$sequence += 1;
        $suffix = strtoupper(substr(uniqid(), -6));

        return [
            'employee_code' => 'EMP-' . $suffix,
            'name'          => $this->faker->name(),
            'role'          => 'other',
            'branch_id'     => null, // must be set by caller
            'phone'         => $this->faker->optional()->phoneNumber(),
            'email'         => $this->faker->optional()->safeEmail(),
            'address'       => $this->faker->optional()->address(),
            'salary'        => $this->faker->randomFloat(2, 10000, 80000),
            'joining_date'  => $this->faker->optional()->dateTimeBetween('-5 years', 'now')?->format('Y-m-d'),
            'is_active'     => true,
            'deleted_by'    => null,
        ];
    }

    /**
     * Indicate that the employee is assigned to a specific branch.
     */
    public function forBranch(int $branchId): static
    {
        return $this->state(fn (array $attributes) => [
            'branch_id' => $branchId,
        ]);
    }

    /**
     * Indicate that the employee has a specific role.
     */
    public function withRole(string $role): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => $role,
        ]);
    }

    /**
     * Indicate that the employee is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}

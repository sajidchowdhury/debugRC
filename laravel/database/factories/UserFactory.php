<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * User factory — Branch Phase 7 testing.
 *
 * Generates User records tied to an Employee. The role is stored on the
 * Employee (per legacy schema), so to create a "user with role X", the
 * caller must first create an Employee with role X, then this factory
 * generates the linked User.
 */
class UserFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = \App\Models\User::class;

    /**
     * Unique sequence counter for username uniqueness.
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
            'employee_id'            => null, // must be set by caller
            'username'               => 'user_' . $suffix,
            'password_hash'          => Hash::make('password'),
            'is_active'              => true,
            'last_login'             => null,
            'last_login_ip'          => null,
            'failed_login_count'     => 0,
            'locked_until'           => null,
            'credential_version'     => 1,
            'telegram_user_id'       => null,
            'created_by'             => null,
        ];
    }

    /**
     * Indicate that the user is linked to a specific employee.
     */
    public function forEmployee(int $employeeId): static
    {
        return $this->state(fn (array $attributes) => [
            'employee_id' => $employeeId,
        ]);
    }

    /**
     * Indicate that the user is inactive / disabled.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}

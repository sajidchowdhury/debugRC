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

        $names = [
            'Head Office',
            'Patuatuli Branch',
            'Nowabpur Branch',
            'Tarabo Branch',
            'Dhanmondi Branch',
            'Uttara Branch',
        ];

        $name = $names[array_key_exists(self::$sequence - 1, $names)
            ? self::$sequence - 1
            : array_rand($names)] . ' #' . self::$sequence;

        // Generate an alphabetic prefix (HO, PT, NW, TR, DM, UT, ...) + sequence.
        $prefix = strtoupper(substr(preg_replace('/[^A-Z]/i', '', $name), 0, 2));

        return [
            'branch_code' => $prefix . '-' . str_pad((string) self::$sequence, 4, '0', STR_PAD_LEFT),
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

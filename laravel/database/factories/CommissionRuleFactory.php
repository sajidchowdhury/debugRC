<?php

namespace Database\Factories;

use App\Models\CommissionRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Commission Rule factory — Task 37 / API-4 testing.
 *
 * Default state is an INACTIVE flat rule with a closed effective_to,
 * to avoid the GiST EXCLUDE constraint that prevents overlapping active
 * open-ended rules per salesman. Use the `active()` state for a
 * currently-in-effect rule (effective_from = today, effective_to = NULL,
 * is_active = true) — only ONE active open-ended rule per salesman is
 * allowed by the DB.
 *
 * salesman_id is NOT auto-created — the test must provide a real
 * Employee (salesman) via forSalesman(), matching the existing test
 * pattern where dependencies are created explicitly.
 */
class CommissionRuleFactory extends Factory
{
    protected $model = CommissionRule::class;

    public function definition(): array
    {
        return [
            'salesman_id'    => null, // test must set via forSalesman()
            'rule_type'      => 'flat',
            'rate'           => $this->faker->randomFloat(4, 0.5, 3.0),
            'effective_from' => $this->faker->dateTimeBetween('-1 year', '-1 month')?->format('Y-m-d'),
            'effective_to'   => $this->faker->dateTimeBetween('-1 month', 'now')?->format('Y-m-d'),
            'is_active'      => false,
            'branch_id'      => null, // NULL = global rule (all branches)
            'notes'          => $this->faker->optional()->sentence(),
            'created_by'     => null,
        ];
    }

    /**
     * An active, currently-in-effect open-ended rule.
     * NOTE: only ONE active open-ended rule per salesman is allowed
     * (GiST EXCLUDE constraint). Use distinct salesmen across tests.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'effective_from' => now()->toDateString(),
            'effective_to'   => null,
            'is_active'      => true,
        ]);
    }

    /**
     * Scope the rule to a specific branch (non-global).
     */
    public function forBranch(int $branchId): static
    {
        return $this->state(fn (array $attributes) => [
            'branch_id' => $branchId,
        ]);
    }

    /**
     * Assign to a specific salesman.
     */
    public function forSalesman(int $salesmanId): static
    {
        return $this->state(fn (array $attributes) => [
            'salesman_id' => $salesmanId,
        ]);
    }
}

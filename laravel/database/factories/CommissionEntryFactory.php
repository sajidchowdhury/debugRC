<?php

namespace Database\Factories;

use App\Models\CommissionEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Commission Entry factory — Task 37 / API-4 testing.
 *
 * Default state is a 'calculated' (pending) positive entry. Use the
 * `confirmed()` or `paid()` states for those lifecycle stages.
 *
 * salesman_id / branch_id / commission_rule_id are NOT auto-created —
 * the test must provide real records via forSalesman()/forBranch()/
 * forRule(), matching the existing test pattern.
 */
class CommissionEntryFactory extends Factory
{
    protected $model = CommissionEntry::class;

    public function definition(): array
    {
        $commissionBase = $this->faker->randomFloat(2, 1000, 50000);
        $rate = $this->faker->randomFloat(4, 0.5, 3.0);
        $amount = round($commissionBase * $rate / 100, 2);

        return [
            'salesman_id'          => null, // test must set via forSalesman()
            'branch_id'            => null, // test must set via forBranch()
            'sales_invoice_id'     => null,
            'commission_rule_id'   => null, // test must set via forRule()
            'allocation_id'        => null,
            'sales_return_id'      => null,
            'invoice_total'        => $commissionBase,
            'commission_base'      => $commissionBase,
            'commission_rate'      => $rate,
            'commission_amount'    => $amount,
            'status'               => 'calculated',
            'entry_date'           => $this->faker->dateTimeBetween('-2 months', 'now')?->format('Y-m-d'),
            'journal_entry_id'     => null,
            'reversed_by_entry_id' => null,
            'is_reversed'          => false,
            'reversed_at'          => null,
            'reversed_by'          => null,
            'reverse_reason'       => null,
            'commission_period'    => now()->format('Y-m'),
            'notes'                => null,
            'created_by'           => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'confirmed',
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
        ]);
    }

    public function forPeriod(string $period): static
    {
        return $this->state(fn (array $attributes) => [
            'commission_period' => $period,
        ]);
    }

    public function forSalesman(int $salesmanId): static
    {
        return $this->state(fn (array $attributes) => [
            'salesman_id' => $salesmanId,
        ]);
    }

    public function forBranch(int $branchId): static
    {
        return $this->state(fn (array $attributes) => [
            'branch_id' => $branchId,
        ]);
    }

    public function forRule(int $ruleId): static
    {
        return $this->state(fn (array $attributes) => [
            'commission_rule_id' => $ruleId,
        ]);
    }
}

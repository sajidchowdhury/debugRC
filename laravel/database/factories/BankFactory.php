<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bank factory — Phase 13 testing.
 *
 * Generates Bank records for the `banks` cash-book table.
 * Uses uniqid() for account_number uniqueness across PHP process restarts.
 *
 * Bank has no required FK columns — bank_name is the only NOT NULL field
 * besides is_active (which defaults to true via DB).
 */
class BankFactory extends Factory
{
    /** @var string */
    protected $model = \App\Models\Bank::class;

    public function definition(): array
    {
        $suffix = strtoupper(substr(uniqid(), -6));

        return [
            'bank_name'      => 'Test Bank ' . $suffix,
            'account_number' => 'ACC-' . $suffix,
            'account_holder' => $this->faker->optional()->name(),
            'branch_name'    => $this->faker->optional()->city(),
            'balance'        => 0,
            'is_active'      => true,
            'ledger_id'      => null,
            'created_by'     => null,
            'deleted_by'     => null,
        ];
    }

    /**
     * Link the bank to a specific GL ledger (cash_book nature).
     */
    public function withLedger(int $ledgerId): static
    {
        return $this->state(fn (array $attributes) => [
            'ledger_id' => $ledgerId,
        ]);
    }

    /**
     * Set the bank's balance to a specific amount.
     */
    public function withBalance(float $balance): static
    {
        return $this->state(fn (array $attributes) => [
            'balance' => $balance,
        ]);
    }

    /**
     * Mark the bank as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}

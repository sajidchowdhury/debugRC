<?php

namespace Database\Factories;

use App\Models\Ledger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Ledger factory — Phase 15 testing.
 *
 * Generates Ledger records for the `ledgers` chart-of-accounts table.
 * Uses uniqid() for ledger_code uniqueness across PHP process restarts.
 *
 * Schema requirements (validated by the DB):
 *   - ledger_code      NOT NULL UNIQUE varchar(20)
 *   - ledger_name      NOT NULL varchar(100)
 *   - account_type     NOT NULL CHECK in Asset/Liability/Equity/Income/Expense
 *   - ledger_nature    nullable varchar(50)
 *   - is_active        NOT NULL default true
 *   - is_system        NOT NULL default false (Phase 15 migration)
 *   - normal_balance   NOT NULL default 'debit' CHECK in debit/credit (Phase 15)
 *   - parent_id        nullable int (default 0 in schema)
 *
 * States:
 *   - system()              — is_system=true (system ledger protection)
 *   - active()              — is_active=true (default)
 *   - inactive()            — is_active=false
 *   - withNature($nature)   — set ledger_nature (+ auto-fill account_type +
 *                             normal_balance from Ledger::natureMetadata())
 *   - withAccountType($type) — set account_type
 */
class LedgerFactory extends Factory
{
    /** @var string */
    protected $model = Ledger::class;

    public function definition(): array
    {
        $suffix = strtoupper(substr(uniqid(), -6));

        return [
            'ledger_code'         => 'L-T-' . $suffix,
            'ledger_name'         => 'Test Ledger ' . $suffix,
            'parent_id'           => null,
            'account_type'        => 'Asset',
            'ledger_nature'       => null,
            'is_control_account'  => false,
            'control_account_type' => null,
            'is_active'           => true,
            'is_system'           => false,
            'normal_balance'      => 'debit',
            'opening_balance'     => 0,
            'sort_order'          => 0,
            'description'         => null,
            'created_by'          => null,
            'deleted_by'          => null,
        ];
    }

    /**
     * Mark the ledger as a system ledger (protected from edit/delete).
     */
    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_system' => true,
        ]);
    }

    /**
     * Mark the ledger as active (default).
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Mark the ledger as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Set the ledger_nature. Auto-fills account_type and normal_balance
     * from Ledger::natureMetadata() if they haven't been explicitly set.
     */
    public function withNature(string $nature): static
    {
        return $this->state(function (array $attributes) use ($nature) {
            $state = ['ledger_nature' => $nature];

            $meta = Ledger::natureMetadata()[$nature] ?? null;
            if ($meta !== null) {
                // Only auto-fill if the test hasn't explicitly overridden
                // account_type or normal_balance at the factory call site.
                if (($attributes['account_type'] ?? 'Asset') === 'Asset' && $attributes['account_type'] ?? null) {
                    // respect explicit override
                } else {
                    $state['account_type'] = $meta['account_type'];
                }
                if (!isset($attributes['normal_balance']) || $attributes['normal_balance'] === 'debit') {
                    $state['normal_balance'] = $meta['normal_balance'];
                }
            }

            return $state;
        });
    }

    /**
     * Set the account_type.
     */
    public function withAccountType(string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'account_type' => $type,
        ]);
    }

    /**
     * Set the normal_balance.
     */
    public function withNormalBalance(string $balance): static
    {
        return $this->state(fn (array $attributes) => [
            'normal_balance' => $balance,
        ]);
    }
}

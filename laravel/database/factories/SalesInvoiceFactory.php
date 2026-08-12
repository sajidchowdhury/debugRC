<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sales Invoice factory — Phase 10/16 testing (LOW-WAVE-2-B2 G-294).
 *
 * Generates SalesInvoice records tied to an existing Customer + Branch.
 * Mirrors the column set used by `SalesInvoiceService::create()` at
 * `app/Services/Sales/SalesInvoiceService.php:177-200`, with sensible
 * test defaults for the lifecycle flags.
 *
 * Notes:
 *   - `due_amount` is a GENERATED ALWAYS AS (total_amount - paid_amount)
 *     STORED column (migration `2025_01_20_000000_add_generated_columns`).
 *     PostgreSQL auto-computes it; the factory does NOT set it explicitly
 *     (an explicit value would raise SQLSTATE 428C9 against the generated
 *     column constraint).
 *   - `created_at` / `updated_at` are auto-filled by Eloquent's timestamp
 *     handling on `factory->create()`.
 *   - `created_by` defaults to null — callers override with the
 *     authenticated user's id when the dashboard's per-user attribution
 *     needs to be exercised.
 *   - Uses uniqid() for invoice_code uniqueness across PHP process
 *     restarts (the table has a UNIQUE constraint on
 *     (invoice_code, invoice_date)).
 */
class SalesInvoiceFactory extends Factory
{
    /** @var string */
    protected $model = \App\Models\SalesInvoice::class;

    public function definition(): array
    {
        $suffix = strtoupper(substr(uniqid(), -8));

        return [
            'invoice_code'        => 'INV-' . $suffix,
            'invoice_date'        => now()->toDateString(),
            'customer_id'         => null, // must be set by caller
            'salesman_id'         => null,
            'sales_person'        => null,
            'branch_id'           => null, // must be set by caller
            'sub_total'           => 100,
            'discount_amount'     => 0,
            'transport_cost'      => 0,
            'total_amount'        => 100,
            'paid_amount'         => 0,
            // due_amount is GENERATED (total_amount - paid_amount) — NOT set here.
            'payment_mode'        => 'cash',
            'status'              => 'confirmed',
            'is_godown_prepared'  => false,
            'is_challan_issued'   => false,
            'is_reversed'         => false,
            'is_soft_hold'        => false,
            'call_a_day'          => false,
            'created_by'          => null,
        ];
    }

    /**
     * Attach the invoice to a specific customer + branch (the minimum
     * required FK pair for the row to be valid).
     */
    public function forCustomerBranch(int $customerId, int $branchId): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_id' => $customerId,
            'branch_id'   => $branchId,
        ]);
    }

    /**
     * Set the invoice_date explicitly (needed for partition routing + the
     * dashboard's date-range WHERE clauses).
     */
    public function onDate(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'invoice_date' => $date,
        ]);
    }

    /**
     * Mark the invoice as authored by a specific user (drives the
     * dashboard's `created_by = $userId` attribution queries).
     */
    public function createdBy(int $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $userId,
        ]);
    }

    /**
     * Mark the invoice as a draft (the initial state from
     * `SalesInvoiceService::create()`).
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    /**
     * Mark the invoice as reversed (excluded by the dashboard queries).
     */
    public function reversed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'      => 'reversed',
            'is_reversed' => true,
        ]);
    }
}

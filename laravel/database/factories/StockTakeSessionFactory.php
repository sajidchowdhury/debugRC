<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Stock Take Session factory — Phase 12 (testing + monitoring).
 *
 * Generates a `stock_take_sessions` row with the minimum fields the schema
 * requires. Callers MUST chain `forBranch(int $branchId)` to set the
 * branch_id (it is NOT NULL on the table) — the default state leaves it
 * null so that a missing forBranch() call surfaces as a schema error
 * rather than silently posting fake rows to branch_id=0.
 *
 * State methods mirror the session's lifecycle:
 *   counting() / submitted($by) / approved($by) / posted($jeId, $by)
 *   cancelled() / reversed($by, $reason) / withFreezeOutbound()
 *
 * The factory's `definition()` includes created_at + updated_at because
 * StockTakeSession has $timestamps = true.
 */
class StockTakeSessionFactory extends Factory
{
    /** @var string */
    protected $model = \App\Models\StockTakeSession::class;

    /**
     * Default state: a brand-new draft session with no branch. Tests MUST
     * chain forBranch() to satisfy the NOT NULL constraint.
     */
    public function definition(): array
    {
        return [
            'session_code'        => 'ST-' . strtoupper(substr(uniqid(), -8)),
            'session_date'        => today()->toDateString(),
            'branch_id'           => null,
            'status'              => 'draft',
            'journal_entry_id'    => null,
            'is_reversed'         => false,
            'reversed_at'         => null,
            'reversed_by'         => null,
            'reverse_reason'      => null,
            'frozen_at'           => null,
            'freeze_outbound'     => false,
            'count_snapshot'      => null,
            // Phase 4: approval workflow defaults (null).
            'submitted_by'        => null,
            'submitted_at'        => null,
            'approved_by'         => null,
            'approved_at'         => null,
            'approval_comments'   => null,
            // Phase 5: cycle-count scope.
            'count_scope'         => 'full',
            'count_scope_payload' => null,
            // Phase 10: reversal vs cancellation + re-open defaults.
            're_open_count'       => 0,
            'last_reopened_at'    => null,
            'last_reopened_by'    => null,
            'reversal_of_entry_id'=> null,
            'notes'               => null,
            'created_by'          => null,
            'created_at'          => now(),
            'updated_at'          => now(),
        ];
    }

    /**
     * Set the branch_id — REQUIRED on every call (NOT NULL column).
     */
    public function forBranch(int $branchId): static
    {
        return $this->state(fn (array $attributes) => [
            'branch_id' => $branchId,
        ]);
    }

    /**
     * status='counting'.
     */
    public function counting(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'counting',
        ]);
    }

    /**
     * status='submitted', with submitter + timestamp.
     */
    public function submitted(int $byUserId): static
    {
        return $this->state(fn (array $attributes) => [
            'status'       => 'submitted',
            'submitted_by' => $byUserId,
            'submitted_at' => now(),
        ]);
    }

    /**
     * status='approved', with approver + timestamp.
     */
    public function approved(int $byUserId): static
    {
        return $this->state(fn (array $attributes) => [
            'status'      => 'approved',
            'approved_by' => $byUserId,
            'approved_at' => now(),
        ]);
    }

    /**
     * status='posted', with optional journal_entry_id + posted_at.
     * The service sets journal_entry_id at post time; tests can pass it
     * explicitly here to wire the GL link without running the full post.
     */
    public function posted(?int $jeId = null, ?int $byUserId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status'           => 'posted',
            'journal_entry_id' => $jeId,
            // posted_at isn't a column on the model — only approved_at exists.
            // Phase 10 reversal_of_entry_id stays null at post time.
            'reversal_of_entry_id' => null,
            'approved_by'      => $byUserId ?? ($attributes['approved_by'] ?? null),
        ]);
    }

    /**
     * status='cancelled'. Cancellation is for draft/counting/submitted/approved
     * sessions only (Phase 10 distinction) — no GL impact, no reversal.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }

    /**
     * status='reversed' + is_reversed=true + reverse audit columns.
     * Phase 10: reversal is the terminal-ish state for POSTED sessions
     * (full stock + GL reversal). Re-openable up to max_reopens.
     */
    public function reversed(int $byUserId, string $reason = 'reversal'): static
    {
        return $this->state(fn (array $attributes) => [
            'status'         => 'reversed',
            'is_reversed'    => true,
            'reversed_at'    => now(),
            'reversed_by'    => $byUserId,
            'reverse_reason' => $reason,
        ]);
    }

    /**
     * Enable outbound freeze + record when the freeze took effect.
     */
    public function withFreezeOutbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'freeze_outbound' => true,
            'frozen_at'       => now(),
        ]);
    }
}

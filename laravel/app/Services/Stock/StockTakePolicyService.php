<?php

namespace App\Services\Stock;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Stock Take Policy Service — Phase 4 (Stock Take plan).
 *
 * Single source of truth for the approval-workflow configuration knobs.
 * Controllers and services NEVER read `stock_take_policies` directly — they
 * call this service. This keeps the policy semantics in one place and makes
 * the cache invalidation story trivial (one cache key).
 *
 * Policies are stored as jsonb in the `stock_take_policies` table (one row
 * per key). The service loads all rows once, caches them in memory for 5 min
 * under `stock_take_policies:all`, and exposes typed accessors.
 *
 * Policies (seeded by the Phase 4 migration):
 *   stock_take.require_approval          (bool)    — gate on/off
 *   stock_take.auto_approve_below_value  (numeric) — skip gate below this value
 *   stock_take.approver_roles            (array)   — roles that can approve
 *   stock_take.variance_threshold_block  (numeric) — force approval ≥ this value
 *   stock_take.recount_reset_to_system   (bool)    — Phase 7: reset physical_qty on recount
 *   stock_take.revaluation_epsilon       (numeric) — Phase 9: cost-drift revaluation threshold
 *   stock_take.max_reopens               (int)     — Phase 10: cap on re-opens after reversal
 *
 * The `approvalRequiredForVariance()` helper combines require_approval and
 * variance_threshold_block to answer: "given this total |gain|+|loss| value,
 * does this session need to go through approval?" This is the single
 * decision point used by StockTakeService::postSession.
 */
class StockTakePolicyService
{
    private const CACHE_KEY = 'stock_take_policies:all';
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Load all policy rows (key => decoded value). Cached.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            $rows = DB::table('stock_take_policies')->get();
            $out = [];
            foreach ($rows as $r) {
                $out[$r->key] = json_decode($r->value, true);
            }
            return $out;
        });
    }

    /**
     * Invalidate the in-memory cache. Call after any policy update so the
     * next read picks up the new value. The StockTakePolicyController
     * (admin settings screen) calls this after every write.
     */
    public function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * When true, counting sessions MUST be submitted and approved before
     * they can be posted (unless the variance value falls under
     * auto_approve_below_value, in which case postSession auto-approves
     * inline). Default false (backward compatible — pre-Phase-4 behaviour).
     */
    public function requireApproval(): bool
    {
        return (bool) ($this->all()['stock_take.require_approval'] ?? false);
    }

    /**
     * Sessions whose total |gain|+|loss| value is STRICTLY below this
     * threshold are auto-approved inline at post time (actor = system).
     * Only meaningful when requireApproval() is true. 0 disables
     * auto-approval (every session goes through the human gate).
     */
    public function autoApproveBelowValue(): float
    {
        return (float) ($this->all()['stock_take.auto_approve_below_value'] ?? 0);
    }

    /**
     * Roles permitted to approve a submitted stock-take session.
     * Default: ['admin', 'manager']. Order is irrelevant.
     *
     * @return array<int,string>
     */
    public function approverRoles(): array
    {
        $roles = $this->all()['stock_take.approver_roles'] ?? ['admin', 'manager'];
        if (!is_array($roles)) {
            $roles = ['admin', 'manager'];
        }
        return array_values(array_map('strval', $roles));
    }

    /**
     * When requireApproval() is false, sessions whose total |gain|+|loss|
     * value is ≥ this threshold are STILL forced through approval. 0
     * disables the threshold (no force-approve). This lets the business
     * run small counts without friction while still gating large
     * high-impact variances.
     */
    public function varianceThresholdBlock(): float
    {
        return (float) ($this->all()['stock_take.variance_threshold_block'] ?? 0);
    }

    /**
     * The single decision point: given the total |gain|+|loss| value of a
     * session's variance, does this session need to go through the human
     * approval gate before posting?
     *
     * Returns true when:
     *   - require_approval is on AND the value is NOT below the
     *     auto-approve threshold; OR
     *   - the value is ≥ variance_threshold_block (force-approve).
     *
     * @param float $totalVarianceValue  |gain| + |loss| in currency units.
     */
    public function approvalRequiredForVariance(float $totalVarianceValue): bool
    {
        $value = max(0.0, $totalVarianceValue);

        // Force-approve threshold (works regardless of require_approval).
        $forceThreshold = $this->varianceThresholdBlock();
        if ($forceThreshold > 0 && $value >= $forceThreshold) {
            return true;
        }

        // Standard gate.
        if ($this->requireApproval()) {
            $autoBelow = $this->autoApproveBelowValue();
            // Strictly below → auto-approve path (no human gate).
            if ($autoBelow > 0 && $value < $autoBelow) {
                return false;
            }
            return true;
        }

        return false;
    }

    /**
     * Phase 7: when true, recountWarehouse() resets physical_qty to
     * system_qty on every line of the recounted warehouse (counter starts
     * fresh). When false (default), the previous physical_qty is preserved
     * so the counter sees the prior count and adjusts. Either way, the
     * recount audit row captures the pre-recount snapshot.
     */
    public function recountResetToSystem(): bool
    {
        return (bool) ($this->all()['stock_take.recount_reset_to_system'] ?? false);
    }

    /**
     * Phase 9: minimum |post_rate - system_rate| delta (in currency units)
     * that triggers a revaluation adjusting entry at post time. When the
     * avg cost drifts by more than this epsilon between setup and post,
     * postSession posts an additional Dr/Cr Inventory/Inventory Revaluation
     * Expense line for (post_rate - system_rate) * physical_qty. Default
     * 0.01 (any non-trivial drift). Set to 0 to revalue on every post.
     */
    public function revaluationEpsilon(): float
    {
        return (float) ($this->all()['stock_take.revaluation_epsilon'] ?? 0.01);
    }

    /**
     * Phase 10: maximum number of times a reversed stock-take session can
     * be re-opened for correction + re-posting. Default 1 (one re-open per
     * session — prevents endless re-open/re-post churn). 0 forbids re-
     * opening entirely (reversed = hard terminal). The cap is enforced by
     * StockTakeService::reOpen, which throws when re_open_count >= max.
     */
    public function maxReopens(): int
    {
        return (int) ($this->all()['stock_take.max_reopens'] ?? 1);
    }

    /**
     * Does the given user role have the approve permission? Used by the
     * controller to gate the approve/reject routes (defence in depth —
     * the route middleware already restricts by role, but the service
     * also checks so a misconfigured middleware cannot bypass the gate).
     */
    public function isApproverRole(string $role): bool
    {
        return in_array($role, $this->approverRoles(), true);
    }
}

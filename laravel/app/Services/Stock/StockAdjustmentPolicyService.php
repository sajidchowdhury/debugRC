<?php

namespace App\Services\Stock;

use App\Models\StockAdjustment;
use App\Models\User;
use App\Services\Accounting\AccountingPeriodService;
use Illuminate\Support\Carbon;

/**
 * Stock Adjustment Policy Service — Phase 3 (Stock Adjustment plan).
 *
 * Single source of truth for the approval-workflow decision logic. The
 * controller and service NEVER read config/stock_adjustment.php directly —
 * they call this service. This keeps the policy semantics in one place.
 *
 * Configuration is loaded from config/stock_adjustment.php (overridable via
 * env). This is deliberately lighter than StockTakePolicyService's
 * DB-backed stock_take_policies table: Stock Adjustment is an infrequent,
 * accountant-driven tool, so a deploy-time config file is sufficient. If a
 * runtime-editable UI is needed later, a DB override layer can be added
 * without changing this service's public API.
 *
 * The central decision method is {@see requiresApproval()} — given an
 * adjustment (its total_amount + the config knobs), does it need to go
 * through the human approval gate before it can be confirmed?
 *
 * Decision matrix (mirrors the StockTake plan's approvalRequiredForVariance,
 * adapted to Stock Adjustment's total_amount):
 *
 *   require_approval = true:
 *     - total_amount < auto_approve_below_value  → NO approval (one-step confirm)
 *     - total_amount ≥ auto_approve_below_value  → approval required
 *
 *   require_approval = false:
 *     - total_amount < max_value_without_secondary_approval → NO approval
 *     - total_amount ≥ max_value_without_secondary_approval → approval required
 *       (force-approve threshold still gates high-impact corrections)
 *
 * @see config/stock_adjustment.php
 * @see StockAdjustmentService::submitAdjustment / approveAdjustment / confirmAdjustment
 */
class StockAdjustmentPolicyService
{
    public function __construct(
        private AccountingPeriodService $accountingPeriods
    ) {}

    /**
     * The central decision: does this adjustment need to go through the
     * human approval gate before it can be confirmed (posted to stock+GL)?
     *
     * Combines require_approval + auto_approve_below_value +
     * max_value_without_secondary_approval (force-approve threshold).
     *
     * @param StockAdjustment $adjustment  The draft being evaluated.
     */
    public function requiresApproval(StockAdjustment $adjustment): bool
    {
        $value = max(0.0, (float) $adjustment->total_amount);

        // Force-approve threshold — works regardless of require_approval.
        // Lets a tenant run small corrections without friction while still
        // gating large, high-impact adjustments.
        $forceThreshold = (float) config('stock_adjustment.max_value_without_secondary_approval', 50000);
        if ($forceThreshold > 0 && $value >= $forceThreshold) {
            return true;
        }

        if ((bool) config('stock_adjustment.require_approval', true)) {
            $autoBelow = (float) config('stock_adjustment.auto_approve_below_value', 1000);
            // Strictly below → auto-approve path (no human gate).
            if ($autoBelow > 0 && $value < $autoBelow) {
                return false;
            }
            return true;
        }

        return false;
    }

    /**
     * Does the given adjustment's total_amount fall below the auto-approve
     * threshold? Used by the UI to show the "can be confirmed in one step"
     * hint and by the service to decide whether a submit should auto-advance
     * to 'approved'.
     */
    public function isBelowAutoApproveThreshold(StockAdjustment $adjustment): bool
    {
        $autoBelow = (float) config('stock_adjustment.auto_approve_below_value', 1000);
        if ($autoBelow <= 0) {
            return false;
        }
        return (float) $adjustment->total_amount < $autoBelow;
    }

    /**
     * Can the given user submit a draft adjustment for approval?
     * Checks the submitter_roles config. (The route middleware already
     * gates by role:admin,accountant; this re-confirms so a misconfigured
     * middleware cannot bypass the gate.)
     */
    public function canSubmit(User $user): bool
    {
        $roles = config('stock_adjustment.submitter_roles', ['admin', 'accountant']);
        return $user->hasRole(...$roles);
    }

    /**
     * Can the given user approve a submitted adjustment?
     * Checks the approver_roles config. (Route middleware gates by
     * role:admin,manager; this re-confirms.)
     */
    public function canApprove(User $user): bool
    {
        $roles = config('stock_adjustment.approver_roles', ['admin', 'manager']);
        return $user->hasRole(...$roles);
    }

    /**
     * Can the given user confirm (post stock+GL) an approved adjustment?
     * Checks the confirmer_roles config. (Route middleware gates by
     * role:admin,accountant; this re-confirms.)
     */
    public function canConfirm(User $user): bool
    {
        $roles = config('stock_adjustment.confirmer_roles', ['admin', 'accountant']);
        return $user->hasRole(...$roles);
    }

    /**
     * Phase 6.1 — can the given user FORCE-confirm a decrease adjustment
     * past the pipeline-availability check?
     *
     * Force-confirm is the legitimate escape hatch for legacy-cleanup /
     * data-migration corrections that must post a decrease below the
     * sales-pipeline-reserved qty (e.g. writing off stock that was
     * invoiced-but-never-dispatched and is being reconciled out).
     *
     * Restricted to admin only — the override is logged as a distinct
     * 'force_confirm' audit action (Phase 4 vocab) so the bypass is
     * always visible in the audit timeline. Configurable via
     * `force_confirmer_roles` (default: ['admin']) so a tenant can add
     * 'manager' if their governance model allows it.
     */
    public function canForceConfirm(User $user): bool
    {
        $roles = config('stock_adjustment.force_confirmer_roles', ['admin']);
        return $user->hasRole(...$roles);
    }

    /**
     * Segregation of duties: the user who submitted an adjustment CANNOT
     * approve their own submission. Enforced by StockAdjustmentService::
     * approveAdjustment. Exposed here so the UI can hide the Approve button
     * from the submitter on the show page.
     */
    public function isSubmitter(User $approver, StockAdjustment $adjustment): bool
    {
        return $adjustment->submitted_by !== null
            && (int) $adjustment->submitted_by === (int) $approver->id;
    }

    /**
     * Is the given date inside a closed accounting period for the given
     * branch? Delegates to AccountingPeriodService::earliestOpenDate.
     *
     * Returns true when block_closed_period is on AND the branch has a
     * closed-through date AND the given date is strictly before the
     * earliest open date (the day after the close).
     *
     * @param int    $branchId
     * @param string $date  Y-m-d
     */
    public function isWithinClosedPeriod(int $branchId, string $date): bool
    {
        if (!(bool) config('stock_adjustment.block_closed_period', true)) {
            return false;
        }

        $earliestOpen = $this->accountingPeriods->earliestOpenDate($branchId);
        if ($earliestOpen === null) {
            return false; // no period closed for this branch → unrestricted
        }

        return Carbon::parse($date)->lt(Carbon::parse($earliestOpen));
    }

    /**
     * Convenience: the human-readable threshold for the create-form hint.
     * Returns the auto-approve threshold formatted, or null if approval is
     * off and no force-approve threshold is set.
     */
    public function approvalHint(): ?string
    {
        if ((bool) config('stock_adjustment.require_approval', true)) {
            $autoBelow = (float) config('stock_adjustment.auto_approve_below_value', 1000);
            if ($autoBelow > 0) {
                return 'Adjustments below Tk ' . number_format($autoBelow, 2)
                    . ' can be confirmed in one step; larger adjustments require admin/manager approval before posting.';
            }
            return 'All adjustments require admin/manager approval before posting.';
        }

        $force = (float) config('stock_adjustment.max_value_without_secondary_approval', 50000);
        if ($force > 0) {
            return 'Adjustments can be confirmed directly; adjustments ≥ Tk '
                . number_format($force, 2) . ' require admin/manager approval.';
        }

        return null;
    }
}

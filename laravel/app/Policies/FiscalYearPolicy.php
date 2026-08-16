<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FiscalYear;
use App\Models\User;

/**
 * FiscalYearPolicy — authorization policy for FiscalYear model.
 *
 * Created in Session 2. This policy is the APPLICATION-LAYER GUARANTEE
 * for the Q1 requirement: "after FY closes, NO user (not even super
 * admin) can see previous FY data through the UI."
 *
 * The single most important method here is viewHistoricalData(). It
 * ALWAYS returns false. This is the only ability in the entire
 * application that is hard-denied for everyone — including super admin.
 * The Gate::before() super-admin bypass in AppServiceProvider::boot()
 * is AMENDED to explicitly exclude this ability from the bypass list,
 * so even when `Gate::before()` returns true for a superadmin on every
 * other ability, it returns false for viewHistoricalData.
 *
 * Why a separate ability (not just `view`)?
 * -----------------------------------------
 * `view(User, FiscalYear)` returns true only when the FY status is
 * 'active'. This means a user CAN view the active FY's metadata (name,
 * start/end dates, status) via the /admin/fiscal-years management page
 * — they just can't view the FY's TRANSACTIONAL data once it's closed.
 *
 * `viewHistoricalData(User, FiscalYear)` is the gate that controls
 * access to closed/locked FY transactional data (sales, purchases,
 * stock, journals, etc.). It always returns false. The active-FY's
 * transactional data is accessible through the BelongsToFiscalYear
 * global scope (which silently filters by activeId()), NOT through
 * this gate.
 *
 * Ability summary
 * ---------------
 *   viewAny            — false for everyone (no list-of-FYs UI).
 *                        The /admin/fiscal-years route uses its own
 *                        controller-level auth, not this ability.
 *   view               — true only if $fy->status === 'active'.
 *   viewHistoricalData — ALWAYS false (hard deny, even for super admin).
 *   create             — superadmin, admin only.
 *   activate           — superadmin, admin only.
 *   close              — superadmin, admin, accountant.
 *   lock               — superadmin, admin only.
 *   unlock             — superadmin only (for locked FYs).
 *   reopen             — superadmin only (for closed/locked FYs).
 *
 * @see \App\Providers\AppServiceProvider::boot()  Gate::before() amendment
 * @see \App\Models\Concerns\BelongsToFiscalYear   global scope (read-block)
 * @see docs/IMPLEMENTATION_PLAN_FY_ISOLATION_AND_BRANCH_PNL.md Session 2
 */
class FiscalYearPolicy
{
    /**
     * Determine whether the user can view a list of fiscal years.
     *
     * Returns false — the /admin/fiscal-years management route uses
     * its own controller-level role middleware (not this ability) to
     * decide who sees the FY management UI. This ability exists only
     * for completeness; no code path calls it.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view a specific fiscal year's metadata.
     *
     * Returns true only for the active FY. Closed/locked FY metadata
     * is not viewable through this ability — but note that the
     * /admin/fiscal-years management page does display closed FYs in
     * a list (so the accountant can see "FY 2024 is closed, FY 2025
     * is active"). That list view is gated by route middleware, not
     * by this ability. This ability gates per-FY detail views.
     */
    public function view(User $user, FiscalYear $fy): bool
    {
        return $fy->status === 'active';
    }

    /**
     * Determine whether the user can view HISTORICAL (closed/locked)
     * transactional data for this fiscal year.
     *
     * ⚠️  THIS IS THE HARD READ-BLOCK. ALWAYS returns false.
     *
     * This is the single most important method in the entire Q1 phase.
     * Combined with the Gate::before() amendment in AppServiceProvider
     * that explicitly excludes this ability from the super-admin
     * bypass, it guarantees that NO user — not even super admin — can
     * read closed/locked FY transactional data through the UI.
     *
     * The BelongsToFiscalYear global scope enforces this at the
     * query layer; this method enforces it at the authorization
     * layer. The two together provide defense-in-depth.
     *
     * If a future business requirement needs to allow historical view
     * for a specific role (e.g., external auditor), this method must
     * be amended AND Gate::before() must be amended to NOT bypass
     * this ability for that role. Both changes must be made together.
     */
    public function viewHistoricalData(User $user, FiscalYear $fy): bool
    {
        return false;
    }

    /**
     * Determine whether the user can create a new fiscal year.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('superadmin', 'admin');
    }

    /**
     * Determine whether the user can update a fiscal year's metadata.
     *
     * Only draft FYs are editable (enforced in FiscalYearService, not
     * here — this method just checks role).
     */
    public function update(User $user, FiscalYear $fy): bool
    {
        return $user->hasRole('superadmin', 'admin');
    }

    /**
     * Determine whether the user can delete a fiscal year.
     *
     * Only super admin can delete, and only draft FYs (the service
     * layer enforces the draft-status check).
     */
    public function delete(User $user, FiscalYear $fy): bool
    {
        return $user->hasRole('superadmin');
    }

    /**
     * Determine whether the user can activate a fiscal year.
     */
    public function activate(User $user, FiscalYear $fy): bool
    {
        return $user->hasRole('superadmin', 'admin');
    }

    /**
     * Determine whether the user can close (year-end close) a fiscal year.
     */
    public function close(User $user, FiscalYear $fy): bool
    {
        return $user->hasRole('superadmin', 'admin', 'accountant');
    }

    /**
     * Determine whether the user can lock a fiscal year.
     */
    public function lock(User $user, FiscalYear $fy): bool
    {
        return $user->hasRole('superadmin', 'admin');
    }

    /**
     * Determine whether the user can unlock a locked fiscal year.
     *
     * Super admin only. Unlocking a locked FY is a sensitive operation
     * that should be rare and fully audited.
     */
    public function unlock(User $user, FiscalYear $fy): bool
    {
        return $user->hasRole('superadmin');
    }

    /**
     * Determine whether the user can reopen a closed/locked fiscal year.
     *
     * Super admin only. Reopening allows corrections to a previously
     * closed FY — must be used with extreme caution and is fully
     * audited via period_close_log.
     */
    public function reopen(User $user, FiscalYear $fy): bool
    {
        return $user->hasRole('superadmin');
    }
}

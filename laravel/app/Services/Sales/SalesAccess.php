<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sales Access — P0-8 Branch Isolation (defense-in-depth).
 *
 * Service-level helper that mirrors the legacy Helper::assertInvoiceAccessible
 * (legacy/app/helpers/Helper.php:238-246) and resolveBranchIdForWrite
 * (legacy/app/helpers/Helper.php:219-236).
 *
 * While the EnforceBranchIsolation middleware + BranchScope global scope
 * handle route-level and query-level isolation, this service provides
 * a defense-in-depth check callable from within the Sales services
 * (SalesInvoiceService, SalesChallanService, SalesReturnService,
 * CustomerPaymentService) — so even if a service method is called from
 * a non-HTTP context (Artisan command, test, future API), the branch
 * check still fires.
 *
 * Legacy behavior:
 *   - assertInvoiceAccessible: if canOverrideBranch() (admin) → return;
 *     else if invoiceBranchId !== sessionBranchId() → throw Exception.
 *   - resolveBranchIdForWrite: non-admins locked to invoice.branch_id
 *     on edit, session.branch_id on create; admins can override.
 *
 * Usage in a Sales service:
 *   app(SalesAccess::class)->assertBranchAccessible($invoice->branch_id);
 *   $branchId = app(SalesAccess::class)->resolveBranchIdForWrite($existingBranchId);
 */
class SalesAccess
{
    /**
     * Assert the authenticated user can access the given branch.
     * Non-admin users must match their session branch_id.
     * Admin/superadmin bypass (cross-branch access is audited by the
     * EnforceBranchIsolation middleware).
     *
     * @param int|null $recordBranchId The branch_id of the record being accessed.
     * @throws RuntimeException If non-admin and branch mismatch.
     */
    public function assertBranchAccessible(?int $recordBranchId): void
    {
        if (!Auth::check()) {
            return; // unauthenticated — let auth middleware handle.
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        // Admin/superadmin bypass.
        if ($user->isAdmin()) {
            return;
        }

        $sessionBranchId = (int) (session('branch_id') ?? $user->getBranchId() ?? 0);

        if ($recordBranchId !== null && $recordBranchId > 0 && $recordBranchId !== $sessionBranchId) {
            throw new RuntimeException(
                'You do not have access to records from another branch '
                . '(record branch: ' . $recordBranchId . ', your branch: ' . $sessionBranchId . ').'
            );
        }
    }

    /**
     * Assert the authenticated user is an admin or superadmin.
     *
     * Note: User::isAdmin() already covers both 'admin' and 'superadmin'
     * roles, so a separate isSuperadmin() check is not needed here.
     *
     * @throws RuntimeException If not authenticated or not admin-tier.
     */
    public function assertAdmin(): void
    {
        if (!Auth::check()) {
            throw new RuntimeException('Authentication required.');
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (!$user->isAdmin()) {
            throw new RuntimeException('Admin access required.');
        }
    }

    /**
     * Assert the user can dispatch (create) an invoice for the given branch.
     *
     * Unlike assertBranchAccessible() which blocks cross-branch READ access,
     * this method allows salesman/manager to CREATE an invoice dispatched
     * to any active branch. Business requirement: a salesman at branch A
     * can create an invoice dispatched to branch B when the customer's
     * products are not available locally. The invoice then appears on
     * branch B's warehouse manager dashboard, not branch A's.
     *
     * Only admin/superadmin/manager/salesman can finalize (enforced by
     * route middleware). This method simply validates that the target
     * branch exists — the actual role check is on the route.
     *
     * @param int $dispatchBranchId The branch_id the invoice will be dispatched to.
     * @throws RuntimeException If branch does not exist.
     */
    public function assertCanDispatchToBranch(int $dispatchBranchId): void
    {
        if ($dispatchBranchId <= 0) {
            throw new RuntimeException('Dispatch branch is required.');
        }

        // Validate the branch exists and is active.
        $branch = DB::table('branches')
            ->where('id', $dispatchBranchId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        if (!$branch) {
            throw new RuntimeException(
                'Cannot dispatch to branch ' . $dispatchBranchId . ' — branch not found or inactive.'
            );
        }

        // No cross-branch restriction — any salesman/manager can dispatch
        // to any active branch. The invoice will appear on that branch's
        // warehouse manager dashboard (filtered by BranchScope).
    }

    /**
     * Resolve the branch_id to use for a write operation.
     *
     * - Create (no existing branch): returns session branch_id for non-admins,
     *   or request branch_id for admins.
     * - Edit (existing branch): returns the existing branch_id (non-admins
     *   cannot change it; admins can override via the request input).
     *
     * @param int|null $existingBranchId The record's current branch_id (null for create).
     * @param int|null $requestBranchId The branch_id from the request input (admin override).
     * @return int
     */
    public function resolveBranchIdForWrite(?int $existingBranchId = null, ?int $requestBranchId = null): int
    {
        if (!Auth::check()) {
            return $requestBranchId ?? $existingBranchId ?? 0;
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $sessionBranchId = (int) (session('branch_id') ?? $user->getBranchId() ?? 0);

        // Admin/superadmin: can override.
        if ($user->isAdmin()) {
            return $requestBranchId ?? $existingBranchId ?? $sessionBranchId;
        }

        // Non-admin create: locked to session branch_id.
        if ($existingBranchId === null || $existingBranchId === 0) {
            return $sessionBranchId;
        }

        // Non-admin edit: locked to existing branch_id (cannot change).
        return $existingBranchId;
    }

    /**
     * Assert a specific record (by table + ID) is accessible to the
     * current user. Loads the record's branch_id from the DB and checks.
     *
     * @param string $table
     * @param int $recordId
     * @throws RuntimeException If record not found or branch mismatch.
     */
    public function assertRecordAccessible(string $table, int $recordId): void
    {
        $branchId = DB::table($table)->where('id', $recordId)->value('branch_id');
        if ($branchId === null) {
            throw new RuntimeException("Record {$recordId} not found in {$table}.");
        }
        $this->assertBranchAccessible((int) $branchId);
    }
}

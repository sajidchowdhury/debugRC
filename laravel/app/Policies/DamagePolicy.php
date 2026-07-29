<?php

namespace App\Policies;

use App\Models\DamageInvoice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Damage Policy — Phase 0 (Damage plan).
 *
 * Centralizes the role + branch rules for the Damage module. Mirrors the
 * legacy route_roles.php DamageController matrix:
 *   index/show/product-stock/export : admin, manager, warehouse_manager  (read)
 *   create/store                    : admin, manager, warehouse_manager  (write — draft only)
 *   confirm                         : admin, manager                     (posts stock + GL)
 *   cancel                          : admin, manager                     (reverses stock + GL)
 *
 * Each method returns true for the EXACT set of roles the corresponding
 * route middleware (`role:` in routes/web.php) already allows — so
 * `$this->authorize()` in the controller is defense-in-depth (the
 * middleware gates first; the policy re-confirms the same rule). This does
 * not change behavior; it makes the rules testable + discoverable in one
 * place and survives any future route loosening.
 *
 * Defense-in-depth layers for Damage:
 *   1. `role:` middleware on the route group (PRIMARY gate)
 *   2. This Policy via $this->authorize() in the controller
 *   3. `branch.isolation` middleware on show/confirm/cancel (resolves {id}
 *      → damage_invoices.branch_id via EnforceBranchIsolation)
 *   4. PostgreSQL RLS on damage_invoices (DB-enforced branch filter)
 *
 * Branch isolation is enforced BOTH here (model-context: damage's
 * branch_id vs user's session branch) AND by the `branch.isolation`
 * route middleware (request-context). RLS provides the final DB-level
 * guarantee.
 *
 * Role reference (User::getRole() reads from Employee):
 *   superadmin → bypasses everything (EnsureRole middleware)
 *   admin      → full access, cross-branch override (logged)
 *   manager    → full access within own branch (create + confirm + cancel)
 *   warehouse_manager → create + view only (CANNOT confirm/cancel — those
 *                       post or reverse GL + stock and need a manager)
 *   salesman, dispatcher, hr, user, accountant, other → NO access
 *
 * Phase 5 (Approval Workflow) will further gate `confirm` behind an
 * 'approved' status and a maker-checker flow; for now (Phase 0) the
 * role gate is the primary control.
 *
 * @see routes/web.php  admin/damages route groups
 * @see docs/DAMAGE_IMPLEMENTATION_PLAN.md  Phase 0
 */
class DamagePolicy
{
    use HandlesAuthorization;

    /**
     * View the index list (admin/manager/warehouse_manager).
     * No model binding (global list), so no branch check here — RLS + the
     * index query's branch scoping handle it.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * View a single damage detail (admin/manager/warehouse_manager).
     * Route: admin.damages.show — role:admin,manager,warehouse_manager
     * + branch.isolation.
     *
     * A non-admin can only view damages in their own session branch.
     */
    public function view(User $user, DamageInvoice $damage): bool
    {
        if (!$user->hasRole('admin', 'manager', 'warehouse_manager')) {
            return false;
        }

        return $this->sameBranch($user, $damage);
    }

    /**
     * Create / store a draft damage (no stock movement, no GL yet).
     * Route: admin.damages.create / store — role:admin,manager,warehouse_manager
     *
     * No branch.isolation on store (branch_id is derived from the warehouse
     * inside DamageService::createDamage, NOT taken from the request body,
     * so it cannot be forged).
     */
    public function create(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * AJAX: get product avg cost + qty for a warehouse (create-form helper).
     * Route: admin.damages.product-stock — role:admin,manager,warehouse_manager
     *
     * Branch check is enforced INSIDE the controller's getProductStock()
     * method (it needs the request's warehouse_id, which the policy does
     * not receive). This method only re-confirms the role.
     */
    public function viewProductStock(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Confirm a draft damage (apply stock OUT + post GL).
     * Route: admin.damages.{id}.confirm — role:admin,manager + branch.isolation.
     *
     * This is the most sensitive action — it posts to stock and GL
     * (Dr Damage Loss / Cr Inventory). Only admin + manager may perform it.
     * warehouse_manager (who can create drafts) is explicitly excluded so
     * that no single person can both create AND post a write-off
     * (segregation of duties — the core accountability control).
     *
     * Phase 5 (Approval Workflow) will additionally require the damage to
     * be in the 'approved' state before confirm can run.
     */
    public function confirm(User $user, DamageInvoice $damage): bool
    {
        if (!$user->hasRole('admin', 'manager')) {
            return false;
        }

        return $this->sameBranch($user, $damage);
    }

    /**
     * Cancel a damage (reverse stock + GL if confirmed, or mark draft cancelled).
     * Route: admin.damages.{id}.cancel — role:admin,manager + branch.isolation.
     *
     * Same role gate as confirm — cancelling a confirmed damage reverses
     * GL + stock, which is as sensitive as the original post.
     */
    public function cancel(User $user, DamageInvoice $damage): bool
    {
        if (!$user->hasRole('admin', 'manager')) {
            return false;
        }

        return $this->sameBranch($user, $damage);
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 3 — Photo / Evidence Attachments
    |--------------------------------------------------------------------------
    | Upload / delete / view evidence files. Mirrors the legacy create/store
    | role matrix (admin, manager, warehouse_manager may all upload evidence —
    | a warehouse_manager is usually the one on the floor photographing the
    | damaged stock). Confirm/cancel (which lock the evidence) stay admin/manager.
    |
    | Critical rule: attachments can only be added or removed while the damage
    | is in `draft`. Once confirmed, the evidence set is FROZEN for audit
    | integrity (you can't retroactively swap the photo that justified a
    | write-off). A cancelled/reversed damage keeps its attachments for the
    | audit trail (only a hard delete cascades to the files — see migration).
    */

    /**
     * Upload an evidence attachment to a draft damage.
     * Routes: admin.damages.{id}.attachments.store — role:admin,manager,warehouse_manager + branch.isolation.
     */
    public function uploadAttachment(User $user, DamageInvoice $damage): bool
    {
        if (!$user->hasRole('admin', 'manager', 'warehouse_manager')) {
            return false;
        }
        // Evidence is locked once the damage leaves draft.
        if (!$damage->isDraft()) {
            return false;
        }

        return $this->sameBranch($user, $damage);
    }

    /**
     * Delete an evidence attachment from a draft damage.
     * Routes: admin.damages.{id}.attachments.destroy — role:admin,manager,warehouse_manager + branch.isolation.
     */
    public function deleteAttachment(User $user, DamageInvoice $damage): bool
    {
        // Same gate as upload — draft only, same branch.
        return $this->uploadAttachment($user, $damage);
    }

    /**
     * View / download an evidence attachment (streamed via controller).
     * Routes: admin.damages.{id}.attachments.{att}.view / .download —
     *   role:admin,manager,warehouse_manager + branch.isolation.
     *
     * Broader than upload/delete: evidence may be viewed on a CONFIRMED or
     * CANCELLED damage (auditors / managers reviewing historical write-offs).
     * The draft-only lock applies to mutations, not reads.
     */
    public function viewAttachment(User $user, DamageInvoice $damage): bool
    {
        if (!$user->hasRole('admin', 'manager', 'warehouse_manager')) {
            return false;
        }

        return $this->sameBranch($user, $damage);
    }

    /**
     * Branch check: admin/superadmin may operate on any branch (the
     * cross-branch override is logged by EnforceBranchIsolation). All
     * other roles must match the damage's branch_id to their session
     * branch.
     */
    private function sameBranch(User $user, DamageInvoice $damage): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        $sessionBranchId = (int) (session('branch_id') ?? $user->getBranchId() ?? 0);

        return $sessionBranchId > 0
            && (int) $damage->branch_id === $sessionBranchId;
    }
}

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
 *   manager    → full access within own branch (create + submit + approve + confirm + cancel)
 *   warehouse_manager → create + submit + view only (CANNOT approve/confirm/cancel —
 *                       those post or reverse GL + stock and need a manager, AND
 *                       the maker-checker rule requires a different person to approve)
 *   salesman, dispatcher, hr, user, accountant, other → NO access
 *
 * Phase 5 (Approval Workflow) — implemented:
 *   - `confirm` now requires status='approved' (the maker-checker gate).
 *   - `submit` / `approve` / `reject` methods implement the state machine.
 *   - Segregation of duties: approve() / reject() throw if the actor is the
 *     same user who submitted (submitted_by). The auto-approve shortcut
 *     (submitter ∈ admin/manager AND total ≤ threshold) is the ONE exception.
 *
 * @see routes/web.php  admin/damages route groups
 * @see docs/DAMAGE_IMPLEMENTATION_PLAN.md  Phase 0 + Phase 5
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
     * Confirm an approved damage (apply stock OUT + post GL).
     * Route: admin.damages.{id}.confirm — role:admin,manager + branch.isolation.
     *
     * This is the most sensitive action — it posts to stock and GL
     * (Dr Damage Loss / Cr Inventory). Only admin + manager may perform it.
     * warehouse_manager (who can create + submit drafts) is explicitly
     * excluded so that no single person can both create AND post a write-off
     * (segregation of duties — the core accountability control).
     *
     * Phase 5: the damage MUST be in the `approved` state. The maker-checker
     * gate (submit → approve) runs before this; confirm is the final post.
     * The auto-approve shortcut (admin/manager submitter, total ≤ threshold)
     * still satisfies this because it transitions to `approved` at submit.
     */
    public function confirm(User $user, DamageInvoice $damage): bool
    {
        if (!$user->hasRole('admin', 'manager')) {
            return false;
        }
        // Phase 5 — confirm requires the approved state. A draft / submitted
        // damage must go through submitForApproval + approve first (unless
        // auto-approved at submit). The service re-checks this under a row
        // lock; this method gives a clean 403.
        if (!$damage->isApproved()) {
            return false;
        }

        return $this->sameBranch($user, $damage);
    }

    /**
     * Cancel a damage (reverse stock + GL if confirmed, or mark pre-confirm
     * cancelled). Route: admin.damages.{id}.cancel — role:admin,manager +
     * branch.isolation.
     *
     * Phase 5: cancellable states are draft / submitted / approved / confirmed.
     * `rejected` is terminal (cannot be cancelled — it was never posted).
     * `cancelled` is already terminal (the service throws).
     *
     * Same role gate as confirm — cancelling a confirmed damage reverses
     * GL + stock, which is as sensitive as the original post.
     */
    public function cancel(User $user, DamageInvoice $damage): bool
    {
        if (!$user->hasRole('admin', 'manager')) {
            return false;
        }
        // Phase 5 — rejected + cancelled are terminal.
        if ($damage->isRejected() || $damage->isCancelled()) {
            return false;
        }

        return $this->sameBranch($user, $damage);
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 5 — Approval Workflow (Maker-Checker + Threshold Escalation)
    |--------------------------------------------------------------------------
    | submit / approve / reject implement the state-machine gate between draft
    | and confirm. The maker-checker rule (approver ≠ submitter) is enforced
    | both here (clean 403) and in the service (throws under a row lock).
    |
    | submit  : admin/manager/warehouse_manager (same as create) + draft state
    |           + same-branch. The submitter becomes `submitted_by`.
    | approve : admin/manager only + submitted state + same-branch + approver ≠
    |           submitter. The auto-approve shortcut bypasses this for small
    |           admin/manager-submitted damages (handled in the service).
    | reject  : admin/manager only + submitted state + same-branch + rejecter ≠
    |           submitter + reason required (enforced in the controller).
    */

    /**
     * Submit a draft damage for approval (draft → submitted/approved).
     * Route: admin.damages.{id}.submit — role:admin,manager,warehouse_manager
     * + branch.isolation.
     *
     * Same role gate as create (the submitter is the maker). The service
     * enforces the photo + witness/accountable gates at submit time too
     * (not just at confirm) so the approver doesn't review an incomplete
     * submission. The auto-approve shortcut (admin/manager + total ≤
     * threshold) collapses submit+approve into one step.
     */
    public function submit(User $user, DamageInvoice $damage): bool
    {
        if (!$user->hasRole('admin', 'manager', 'warehouse_manager')) {
            return false;
        }
        if (!$damage->isDraft()) {
            return false;
        }

        return $this->sameBranch($user, $damage);
    }

    /**
     * Approve a submitted damage (submitted → approved).
     * Route: admin.damages.{id}.approve — role:admin,manager + branch.isolation.
     *
     * Segregation of duties: the approver CANNOT be the same user who
     * submitted (submitted_by). The service re-checks this under a row
     * lock; this method gives a clean 403.
     */
    public function approve(User $user, DamageInvoice $damage): bool
    {
        if (!$user->hasRole('admin', 'manager')) {
            return false;
        }
        if (!$damage->isSubmitted()) {
            return false;
        }
        // Maker-checker: the submitter cannot approve their own submission.
        if ((int) $damage->submitted_by === (int) $user->id) {
            return false;
        }

        return $this->sameBranch($user, $damage);
    }

    /**
     * Reject a submitted damage (submitted → rejected, terminal).
     * Route: admin.damages.{id}.reject — role:admin,manager + branch.isolation.
     *
     * Same segregation-of-duties rule as approve — the submitter cannot
     * reject their own submission (that's a cancel, not a reject). A
     * rejection reason is required (enforced in the controller validation).
     */
    public function reject(User $user, DamageInvoice $damage): bool
    {
        if (!$user->hasRole('admin', 'manager')) {
            return false;
        }
        if (!$damage->isSubmitted()) {
            return false;
        }
        if ((int) $damage->submitted_by === (int) $user->id) {
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

    /*
    |--------------------------------------------------------------------------
    | Phase 4 — Witness & Accountable Employee
    |--------------------------------------------------------------------------
    | Recovering the loss from the accountable employee posts a GL entry +
    | an employee_ledger debit (the employee owes the company). This is a
    | financial action — as sensitive as confirm/cancel — so it's gated to
    | admin/manager only, on a CONFIRMED damage with an accountable employee
    | and no prior recovery. The service (DamageService::postEmployeeRecovery)
    | re-checks all of these inside a lockForUpdate, so the policy is
    | defense-in-depth (a clear 403 instead of a 500).
    */

    /**
     * Post an employee recovery against a confirmed damage.
     * Route: admin.damages.{id}.recover — role:admin,manager + branch.isolation.
     *
     * Gates: admin/manager only + same-branch + damage MUST be confirmed +
     * have an accountable employee + no prior recovery. The service enforces
     * these again under a row lock; this method gives a clean 403.
     */
    public function recoverFromEmployee(User $user, DamageInvoice $damage): bool
    {
        if (!$user->hasRole('admin', 'manager')) {
            return false;
        }
        if (!$damage->isConfirmed()) {
            return false;
        }
        if (empty($damage->accountable_employee_id)) {
            return false;
        }
        // One-shot: no recovery may already exist.
        if ($damage->hasRecovery()) {
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

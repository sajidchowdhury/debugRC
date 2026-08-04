<?php

namespace App\Policies;

use App\Models\BranchDemand;
use App\Models\User;

/**
 * Branch Demand Policy — Phase 2 / 9 (Finance cluster — cross-branch supply).
 *
 * Centralizes the role rules for the branch-demand module. Each method
 * returns true for the EXACT set of roles the corresponding route
 * middleware already allows — `$this->authorize()` in the controller is
 * defense-in-depth (the middleware gated first; the policy re-confirms
 * the same rule). This does NOT change behavior; it makes the rules
 * testable + discoverable in one place.
 *
 * Branch Demands are CROSS-BRANCH documents (from_branch_id + to_branch_id
 * — the requester branch asks the supplier branch for products). The
 * branch check is therefore NOT a simple same-branch comparison; it's
 * done in-controller via `if ($demand->from_branch_id !== $branchId &&
 * $demand->to_branch_id !== $branchId) abort(403)`. This policy does NOT
 * replicate that check (it requires the request's session branch, which
 * is request-context, not model-context). The controller check remains
 * the primary gate; this policy is the role-only defense-in-depth layer.
 *
 * No `branch.isolation` middleware is applied to branch-demands routes
 * (the cross-branch nature makes the single-branch-id check
 * inappropriate — both branches can legitimately operate on a demand).
 *
 * Role reference (per routes/web.php L705-708 comment block):
 *   admin, manager              — Full access (incl. reprice + reverse + delete).
 *   warehouse_manager           — Create, send, confirm receipt, view (no reprice/reverse/delete).
 *   accountant                  — View, audit checklist, weekly report (no write).
 *   salesman, dispatcher, hr, user, other — NO access.
 *   superadmin → bypasses everything via EnsureRole middleware.
 *
 * NOTE: the resource routes (index, create, store, show, destroy) have
 * NO explicit `role:` middleware — only `auth` + `menu.permission:branchdemand`.
 * The role matrix below for those verbs mirrors the documented intended
 * matrix in the route group's comment block (routes/web.php L705-708).
 *
 * @see routes/web.php  admin/branch-demands route group (L709-752)
 */
class BranchDemandPolicy
{
    /**
     * View demand list / detail (index, show, pending, pending-receipt,
     * outstanding, ledger-history, settlement-preview, repricing-history,
     * AJAX helpers getBranches/getProducts/getWarehousesByBranch/
     * getWarehouseStock, per-demand {id}/audit).
     * Resource middleware: auth + menu.permission:branchdemand (no role:)
     * — intended matrix per route comment: admin, manager,
     * warehouse_manager, accountant.
     */
    public function view(User $user, BranchDemand $demand): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager', 'accountant');
    }

    /**
     * Create / store a new demand.
     * Resource middleware: auth + menu.permission:branchdemand (no role:)
     * — intended matrix per route comment: admin, manager,
     * warehouse_manager (accountant is view-only).
     */
    public function create(User $user): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Send a demand (draft → sent).
     * Route: admin.branch-demands.send — role:admin,manager,warehouse_manager
     */
    public function send(User $user, BranchDemand $demand): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Confirm receipt of a sent demand (sent → received).
     * Route: admin.branch-demands.confirm-receipt — role:admin,manager,warehouse_manager
     */
    public function confirmReceipt(User $user, BranchDemand $demand): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Reject a demand.
     * Route: admin.branch-demands.reject — role:admin,manager,warehouse_manager
     */
    public function reject(User $user, BranchDemand $demand): bool
    {
        return $user->hasRole('admin', 'manager', 'warehouse_manager');
    }

    /**
     * Reverse a sent/received demand (DESTRUCTIVE — reverses GL +
     * unmarks received).
     * Route: admin.branch-demands.reverse — role:admin,manager
     */
    public function reverse(User $user, BranchDemand $demand): bool
    {
        return $user->hasRole('admin', 'manager');
    }

    /**
     * Reprice a sent demand (Phase 7 — repricing workflow).
     * Route: admin.branch-demands.reprice — role:admin,manager
     */
    public function reprice(User $user, BranchDemand $demand): bool
    {
        return $user->hasRole('admin', 'manager');
    }

    /**
     * Delete (destroy) a demand.
     * Resource middleware: auth + menu.permission:branchdemand (no role:)
     * — intended matrix per route comment + gap G9: admin, manager only.
     */
    public function delete(User $user, BranchDemand $demand): bool
    {
        return $user->hasRole('admin', 'manager');
    }

    /**
     * View the audit checklist (Phase 8 — Audit & Accountability).
     * Route: admin.branch-demands.checklist — role:admin,manager,accountant
     * (No model binding — global dashboard. Method accepts a dummy
     * BranchDemand for signature compatibility with `authorize('audit',
     * BranchDemand::class)`.)
     */
    public function audit(User $user, ?BranchDemand $demand = null): bool
    {
        return $user->hasRole('admin', 'manager', 'accountant');
    }

    /**
     * Reconcile branch demands (Phase 8 — Audit & Accountability).
     * Route: admin.branch-demands.reconcile — role:admin,manager,accountant
     */
    public function reconcile(User $user, ?BranchDemand $demand = null): bool
    {
        return $user->hasRole('admin', 'manager', 'accountant');
    }
}

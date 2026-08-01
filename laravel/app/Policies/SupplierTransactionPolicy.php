<?php

namespace App\Policies;

use App\Models\SupplierPayment;
use App\Models\User;

/**
 * Supplier Transaction Policy — Phase 1 (Accounts Sub-Ledger).
 *
 * Centralizes the role rules for the supplier-transactions module. Like
 * CustomerPaymentPolicy, each method returns true for the EXACT roles the
 * route middleware already allows — $this->authorize() is
 * defense-in-depth. Branch isolation stays as route middleware
 * (branch.isolation on store/reverse) + the BranchScope global scope on
 * the SupplierPayment model (row-level filtering for non-admins).
 *
 * Role matrix (mirrors routes/web.php admin/supplier-transactions group):
 *   index / create / show / slip / audit / get-due / search:
 *     accountant, manager, admin
 *   store (create payment):
 *     accountant, manager, admin  (+ branch.isolation)
 *   reverse (cancel payment):
 *     accountant, manager, admin  (+ branch.isolation)
 *
 * @see routes/web.php  admin/supplier-transactions route group (L1297-1322)
 */
class SupplierTransactionPolicy
{
    /**
     * View payment list / detail / slip / audit (read-only actions).
     * Route middleware: role:accountant,manager,admin
     * No branch.isolation (read-only; BranchScope handles row-level).
     */
    public function view(User $user, SupplierPayment $payment): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * View the audit log page (read-only).
     * Route: admin.supplier-transactions.audit — role:accountant,manager,admin
     */
    public function viewAudit(User $user): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Create / store a supplier payment.
     * Route: admin.supplier-transactions.store — role:accountant,manager,admin
     * + branch.isolation (payment carries branch_id in the request body).
     */
    public function create(User $user): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Reverse / cancel a supplier payment.
     * Route: admin.supplier-transactions.reverse — role:accountant,manager,admin
     * + branch.isolation.
     */
    public function delete(User $user, SupplierPayment $payment): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Print a payment slip (read-only print view).
     * Route: admin.supplier-transactions.slip — role:accountant,manager,admin
     * No branch.isolation (read-only print view).
     */
    public function printSlip(User $user, SupplierPayment $payment): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }
}

<?php

namespace App\Policies;

use App\Models\EmployeeTransaction;
use App\Models\User;

/**
 * Employee Transaction Policy — Phase 2 (Accounts Sub-Ledger).
 *
 * Centralizes the role rules for the employee-transactions module. Like
 * CustomerPaymentPolicy and SupplierTransactionPolicy, each method returns
 * true for the EXACT roles the route middleware already allows —
 * $this->authorize() is defense-in-depth. Branch isolation stays as route
 * middleware (branch.isolation on store/reverse) + the BranchScope global
 * scope on the EmployeeTransaction model (row-level filtering for non-admins).
 *
 * Role matrix (mirrors routes/web.php admin/employee-transactions group):
 *   index / create / show / slip / audit / get-due / search:
 *     accountant, manager, admin
 *   store (create transaction):
 *     accountant, manager, admin  (+ branch.isolation)
 *   reverse (cancel transaction):
 *     accountant, manager, admin  (+ branch.isolation)
 *
 * @see routes/web.php  admin/employee-transactions route group (L1329-1354)
 */
class EmployeeTransactionPolicy
{
    /**
     * View transaction list / detail / slip / audit (read-only actions).
     * Route middleware: role:accountant,manager,admin
     * No branch.isolation (read-only; BranchScope handles row-level).
     */
    public function view(User $user, EmployeeTransaction $transaction): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * View the audit log page (read-only).
     * Route: admin.employee-transactions.audit — role:accountant,manager,admin
     */
    public function viewAudit(User $user): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Create / store an employee transaction.
     * Route: admin.employee-transactions.store — role:accountant,manager,admin
     * + branch.isolation (transaction carries branch_id in the request body).
     */
    public function create(User $user): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Reverse / cancel an employee transaction.
     * Route: admin.employee-transactions.reverse — role:accountant,manager,admin
     * + branch.isolation.
     */
    public function delete(User $user, EmployeeTransaction $transaction): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Print a transaction slip (read-only print view).
     * Route: admin.employee-transactions.slip — role:accountant,manager,admin
     * No branch.isolation (read-only print view).
     */
    public function printSlip(User $user, EmployeeTransaction $transaction): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }
}

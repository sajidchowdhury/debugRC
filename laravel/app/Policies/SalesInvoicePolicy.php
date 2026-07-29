<?php

namespace App\Policies;

use App\Models\SalesInvoice;
use App\Models\User;

/**
 * Sales Invoice Policy — Phase 6.
 *
 * Centralizes the role rules for the sales-invoice module that were
 * previously spread across `role:` middleware on routes/web.php. Each
 * method returns true for the EXACT set of roles the corresponding route
 * middleware already allows — so `$this->authorize()` in the controller
 * is defense-in-depth (the middleware gated first; the policy re-confirms
 * the same rule). This does NOT change behavior; it makes the rules
 * testable + discoverable in one place.
 *
 * Branch isolation (`branch.isolation` middleware) is NOT enforced here —
 * it stays as route middleware because it depends on the request's
 * branch_id vs the user's session branch, which is request-context (not
 * model-context). Each method below documents whether branch.isolation
 * also applies to the corresponding route, so the full rule is readable
 * from this file.
 *
 * Role reference (User::getRole() reads from Employee):
 *   salesman, accountant, warehouse_manager, manager, admin, superadmin
 *   (admin + superadmin via User::isAdmin(); manager via hasRole('manager'))
 *
 * @see routes/web.php  admin/sales-invoices route group (L698-735)
 * @see routes/web.php  admin/sales/finalize (L676-678)
 * @see routes/web.php  admin/sales-invoices/export-csv (L748-750)
 */
class SalesInvoicePolicy
{
    /**
     * View invoice list / detail (index, show, datatable, summary).
     * Route middleware: role:salesman,accountant,warehouse_manager,manager,admin
     * No branch.isolation (read-only; BranchScope global scope handles row-level).
     */
    public function view(User $user, SalesInvoice $invoice): bool
    {
        return $user->hasRole('salesman', 'accountant', 'warehouse_manager', 'manager', 'admin');
    }

    /**
     * Create / finalize a draft invoice from the cart.
     * Route: admin.sales.finalize — role:salesman,manager,admin
     * NO branch.isolation (intentional per BUG-53: a salesman at one branch
     * may finalize an invoice dispatched from a different branch).
     */
    public function create(User $user): bool
    {
        return $user->hasRole('salesman', 'manager', 'admin');
    }

    /**
     * Edit / update a draft invoice.
     * Route: admin.sales-invoices.edit / update — role:salesman,manager,admin
     * + branch.isolation (the user's session branch must match invoice.branch_id).
     */
    public function update(User $user, SalesInvoice $invoice): bool
    {
        return $user->hasRole('salesman', 'manager', 'admin');
    }

    /**
     * Cancel (delete) an invoice.
     * Route: admin.sales-invoices.cancel — role:salesman,manager,admin
     * + branch.isolation.
     */
    public function delete(User $user, SalesInvoice $invoice): bool
    {
        return $user->hasRole('salesman', 'manager', 'admin');
    }

    /**
     * Call-it-a-day (remove invoice from the daily collection list).
     * Route: admin.sales-invoices.call-it-a-day — role:salesman,accountant,manager,admin
     * + branch.isolation.
     */
    public function callItADay(User $user, SalesInvoice $invoice): bool
    {
        return $user->hasRole('salesman', 'accountant', 'manager', 'admin');
    }

    /**
     * Access the receive-payment modal for this invoice.
     * Route: admin.sales-invoices.receive-modal — role:salesman,accountant,manager,admin
     * No branch.isolation (the modal is read-only GET; the POST goes to
     * customer-payments.store which has its own branch.isolation).
     */
    public function receivePayment(User $user, SalesInvoice $invoice): bool
    {
        return $user->hasRole('salesman', 'accountant', 'manager', 'admin');
    }

    /**
     * Reverse a payment linked to this invoice (inline from the receive modal).
     * Route: admin.customer-payments.cancel — role:accountant,manager,admin
     * + branch.isolation. Listed here because the action is triggered from
     * the sales-invoices index page (Phase 3 inline reverse).
     */
    public function reversePayment(User $user, SalesInvoice $invoice): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Export invoices to CSV.
     * Route: admin.sales-invoices.export-csv — role:accountant,manager,admin
     * No branch.isolation (export respects BranchScope row-level filtering).
     *
     * F-30 (Phase 6 decision): salesman is intentionally EXCLUDED from CSV
     * export — confirmed as the intended Laravel behavior (see
     * docs/today-invoice-business-analysis.md §9.4).
     */
    public function exportCsv(User $user): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }
}

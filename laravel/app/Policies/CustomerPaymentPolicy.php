<?php

namespace App\Policies;

use App\Models\CustomerPayment;
use App\Models\User;

/**
 * Customer Payment Policy — Phase 6.
 *
 * Centralizes the role rules for the customer-payments module. Like
 * SalesInvoicePolicy, each method returns true for the EXACT roles the
 * route middleware already allows — `$this->authorize()` is
 * defense-in-depth. Branch isolation stays as route middleware.
 *
 * @see routes/web.php  admin/customer-payments route group (L802-820)
 */
class CustomerPaymentPolicy
{
    /**
     * View payment list / detail (index, create form, show).
     * Route middleware: role:salesman,accountant,manager,admin
     * No branch.isolation (read-only; BranchScope handles row-level).
     */
    public function view(User $user, CustomerPayment $payment): bool
    {
        return $user->hasRole('salesman', 'accountant', 'manager', 'admin');
    }

    /**
     * Create / store a payment (receive money).
     * Route: admin.customer-payments.store — role:salesman,accountant,manager,admin
     * + branch.isolation (payment carries branch_id in the request body).
     */
    public function create(User $user): bool
    {
        return $user->hasRole('salesman', 'accountant', 'manager', 'admin');
    }

    /**
     * Cancel / reverse a payment.
     * Route: admin.customer-payments.cancel — role:accountant,manager,admin
     * + branch.isolation.
     */
    public function delete(User $user, CustomerPayment $payment): bool
    {
        return $user->hasRole('accountant', 'manager', 'admin');
    }

    /**
     * Print a payment receipt.
     * Route: admin.customer-payments.print-receipt — role:salesman,accountant,manager,admin
     * No branch.isolation (read-only print view).
     */
    public function printReceipt(User $user, CustomerPayment $payment): bool
    {
        return $user->hasRole('salesman', 'accountant', 'manager', 'admin');
    }
}

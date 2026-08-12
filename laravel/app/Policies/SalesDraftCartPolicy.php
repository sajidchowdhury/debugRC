<?php

namespace App\Policies;

use App\Models\SalesDraftCart;
use App\Models\User;

/**
 * Sales Draft Cart Policy — Phase 8.1 (Sales cluster — per-user draft cart).
 *
 * Centralizes the role rules for the sales-draft-cart module. Each method
 * returns true for the EXACT set of roles the corresponding route
 * middleware already allows — `$this->authorize()` in the controller is
 * defense-in-depth (the middleware gated first; the policy re-confirms
 * the same rule). This does NOT change behavior; it makes the rules
 * testable + discoverable in one place.
 *
 * The cart is a transient draft (no GL impact) keyed by
 * (user_id, customer_id, branch_id). All cart operations are
 * `role:salesman,manager,admin` + `branch.isolation` (the prefix-group
 * middleware on routes/web.php L1082-1083 applies to all cart routes).
 * Other operational roles (warehouse_manager, dispatcher, hr, accountant,
 * user, other) have NO access to the cart — accountant has separate
 * read-only audit access via admin.sales.audit (handled by SalesInvoicePolicy).
 *
 * Branch isolation (`branch.isolation` middleware) is NOT enforced here —
 * it stays as route middleware (request-context). The cart's branch_id
 * is derived from the session, not the request body (per R6 uniqueness),
 * so branch.isolation mostly guards against session-tampering attempts.
 *
 * NOTE: per-row policy (e.g. "user X can only modify cart rows where
 * user_id = X") is NOT enforced here — the controller's `getCartForUser`
 * already filters by `auth()->id()` so a user can only see/modify their
 * own carts. This policy is the role-only defense-in-depth layer.
 *
 * Role reference (User::getRole() reads from Employee):
 *   salesman, manager, admin — have access (full cart CRUD).
 *   superadmin → bypasses everything via EnsureRole middleware.
 *
 * @see routes/web.php  admin/sales cart route group (L1082-1126)
 */
class SalesDraftCartPolicy
{
    /**
     * View the cart page / list drafts / search customer+product / fetch
     * customer-details / check availability (read-only AJAX helpers).
     * Routes: admin.sales.cart, cart.list-drafts, cart.customer-details,
     *   cart.search-customer, cart.search-product, cart.product-by-code,
     *   cart.availability — role:salesman,manager,admin + branch.isolation.
     */
    public function view(User $user, ?SalesDraftCart $cart = null): bool
    {
        return $user->hasRole('salesman', 'manager', 'admin');
    }

    /**
     * Load a cart for a specific customer (cart.load — initializes the
     * per-user-per-customer-per-branch draft row if not present).
     * Route: admin.sales.cart.load — role:salesman,manager,admin
     * + branch.isolation.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('salesman', 'manager', 'admin');
    }

    /**
     * Add / update / remove items in the cart, validate the cart, or
     * toggle soft-hold.
     * Routes: admin.sales.cart.{add,update,remove,validate,softHold} —
     *   role:salesman,manager,admin + branch.isolation.
     */
    public function update(User $user, ?SalesDraftCart $cart = null): bool
    {
        return $user->hasRole('salesman', 'manager', 'admin');
    }

    /**
     * Clear the cart (remove all items — does NOT delete the row, just
     * empties items_json).
     * Route: admin.sales.cart.clear — role:salesman,manager,admin
     * + branch.isolation.
     */
    public function delete(User $user, ?SalesDraftCart $cart = null): bool
    {
        return $user->hasRole('salesman', 'manager', 'admin');
    }

    /**
     * Clear alias — same gate as delete(), exposed under the conventional
     * clear() name matching the route name.
     */
    public function clear(User $user, ?SalesDraftCart $cart = null): bool
    {
        return $this->delete($user, $cart);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Sales\SalesCartService;
use App\Services\Stock\StockAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales Cart Controller — Phase 8.1.
 *
 * AJAX endpoints for the sales cart workspace. The cart is per-user-per-customer,
 * stored in sales_draft_carts (DB-backed, not session).
 *
 * The cart workspace page (admin/sales/cart) provides the UI for:
 *   - Selecting a customer
 *   - Adding products to the cart
 *   - Adjusting qty/rate per line
 *   - Checking stock availability
 *   - Finalizing the cart into an invoice (Phase 8.2)
 */
class SalesCartController extends Controller
{
    public function __construct(
        private SalesCartService $cartService,
        private StockAvailabilityService $availabilityService
    ) {}

    /**
     * Show the cart workspace page.
     */
    public function index(Request $request)
    {
        $customerId = $request->input('customer_id') ? (int) $request->input('customer_id') : null;
        $branchId = session('branch_id', 0);

        // R1: live search endpoints — dropdowns are no longer pre-populated.
        // We only need the *currently selected* customer record so the
        // customer <select> can render its label on first paint.
        $selectedCustomer = null;
        if ($customerId) {
            $selectedCustomer = Customer::active()->find($customerId);
        }

        $cartData = null;
        if ($customerId) {
            $cartData = $this->cartService->getCart(auth()->id(), $customerId, $branchId);
        }

        return view('admin.sales.cart', [
            'title' => 'Sales Cart',
            'selectedCustomer' => $selectedCustomer,
            'selectedCustomerId' => $customerId,
            'cartData' => $cartData,
            'branchId' => $branchId,
        ]);
    }

    /**
     * R1: AJAX live customer search — ported from Legacy `SalesController::search_customer`.
     *
     * RBAC: salesman/manager/admin (enforced by the route group middleware).
     * Rate limit: 90 req/min (matches Legacy guardJsonApi).
     *
     * GET /admin/sales/cart/search-customer?term=...&_q=...
     * Returns: [{id, customer_code, customer_name, shop_name, mobile, credit_limit}, ...]
     */
    public function searchCustomer(Request $request)
    {
        $term = trim((string) $request->input('term', ''));
        if ($term === '' || mb_strlen($term) < 1) {
            return response()->json([]);
        }

        // Use the existing scopeSearch() — full-text (tsvector + GIN) on PgSQL,
        // with ILIKE fallback on customer_name/code/mobile/phone.
        $rows = Customer::active()->search($term)
            ->orderBy('customer_name')
            ->limit(20)
            ->get([
                'id', 'customer_code', 'customer_name', 'shop_name',
                'mobile', 'phone', 'credit_limit',
            ]);

        // Shape the response so it matches Legacy searchCustomers() exactly:
        // - shop_name first for display ordering
        // - credit_limit as float
        return response()->json(
            $rows->map(fn ($c) => [
                'id'            => (int) $c->id,
                'customer_code' => (string) ($c->customer_code ?? ''),
                'customer_name' => (string) ($c->customer_name ?? ''),
                'shop_name'     => (string) ($c->shop_name ?? ''),
                'mobile'        => (string) ($c->mobile ?? ''),
                'credit_limit'  => (float) ($c->credit_limit ?? 0),
            ])->values()->all()
        );
    }

    /**
     * R1: AJAX live product search with branch stock — ported from Legacy
     * `SalesController::search_product`.
     *
     * RBAC: salesman/manager/admin.
     * Rate limit: 90 req/min.
     *
     * GET /admin/sales/cart/search-product?term=...&branch_id=...
     * Returns: [{id, product_code, product_name, default_rate, min_rate, max_rate, price, available_qty}, ...]
     */
    public function searchProduct(Request $request)
    {
        $term = trim((string) $request->input('term', ''));
        if ($term === '') {
            return response()->json([]);
        }

        $branchId = $this->resolveBranchIdForRead((int) $request->input('branch_id', 0));

        return response()->json(
            $this->availabilityService->searchProductsWithStock($term, $branchId)
        );
    }

    /**
     * R1: AJAX barcode-scanner exact-match lookup — ported from Legacy
     * `SalesController::product_by_code`.
     *
     * GET /admin/sales/cart/product-by-code?code=...&branch_id=...
     * Returns: {status: success|not_found|error, data?: {...}, message?: '...'}
     */
    public function productByCode(Request $request)
    {
        $code = trim((string) $request->input('code', ''));
        $branchId = $this->resolveBranchIdForRead((int) $request->input('branch_id', 0));

        if ($code === '') {
            return response()->json(['status' => 'error', 'message' => 'Product code required']);
        }

        $product = $this->availabilityService->findProductByExactCode($code, $branchId);
        if (!$product) {
            return response()->json(['status' => 'not_found', 'message' => 'No product with this code']);
        }

        return response()->json(['status' => 'success', 'data' => $product]);
    }

    // ───────────────────────────────────────────────────────────────
    // Branch resolution
    //
    // BUG-48 fix: this subclass previously redeclared resolveBranchIdForRead()
    // as `private` with a different signature, which caused PHP 8.4 to throw
    // "Access level … must be protected (as in class Controller) or weaker"
    // on every request to /admin/sales/cart. The base Controller's
    // resolveBranchIdForRead() is already correct (admin override + session
    // fallback) and is what every other controller in the app uses, so we
    // simply delete the override and inherit the parent.
    // ───────────────────────────────────────────────────────────────

    /**
     * R11: AJAX list all open draft carts for the current user (+ branch).
     *
     * Ported from Legacy `SalesController::list_draft_carts` +
     * `SalesCartOperationsTrait::listDraftCarts()`. Drives the
     * `#draft-tabs` dock in `cart.blade.php` so the cashier can see
     * every customer-cart they have in flight, switch between them
     * without losing items, and close any cart they no longer need.
     *
     * GET /admin/sales/cart/list-drafts
     * Returns: [{customer_id, label, shop_name, customer_name, mobile,
     *            item_count, subtotal, is_soft_hold, updated_at}, ...]
     *
     * Only non-empty carts are returned (empty carts don't earn a tab).
     * Sorted by item_count DESC then updated_at DESC.
     */
    public function listDrafts(Request $request)
    {
        $branchId = (int) session('branch_id', 0);

        return response()->json(
            $this->cartService->listCarts(auth()->id(), $branchId)
        );
    }

    /**
     * R14: AJAX live customer credit snapshot for the cart page.
     *
     * Ported from Legacy `SalesController::customer_details`. Returns
     * the customer's credit_limit, current AR balance (current_due),
     * and balance_left (= credit_limit − current_due) so the cart
     * blade can render an inline credit panel. The frontend combines
     * this with the cart subtotal to compute a projected new balance
     * — giving the cashier an early warning before finalize.
     *
     * RBAC: salesman/manager/admin.
     * Rate limit: 60 req/min (matches Legacy guardJsonApi limit for
     * sales/customer_details).
     *
     * GET /admin/sales/cart/customer-details?customer_id=...
     * Returns: {customer_id, customer_name, shop_name, mobile, address,
     *           credit_limit, current_due, due_left}
     */
    public function customerDetails(Request $request)
    {
        $customerId = (int) $request->input('customer_id', 0);
        if ($customerId <= 0) {
            return response()->json([
                'customer_id'   => 0,
                'customer_name' => '',
                'shop_name'     => '',
                'mobile'        => '',
                'address'       => '',
                'credit_limit'  => 0.0,
                'current_due'   => 0.0,
                'due_left'      => 0.0,
            ]);
        }

        return response()->json(
            $this->cartService->getCustomerDetails($customerId)
        );
    }

    /**
     * AJAX: Load the cart for a customer.
     */
    public function load(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
        ]);

        $customerId = (int) $request->input('customer_id');
        $branchId = session('branch_id', 0);

        return response()->json(
            $this->cartService->getCart(auth()->id(), $customerId, $branchId)
        );
    }

    /**
     * AJAX: Add a product to the cart.
     */
    public function add(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'product_id' => 'required|integer|exists:products,id',
            'qty' => 'required|numeric|min:0.001',
            'rate' => 'required|numeric|min:0',
        ]);

        $customerId = (int) $request->input('customer_id');
        $branchId = session('branch_id', 0);

        try {
            $result = $this->cartService->addItem(auth()->id(), $customerId, $branchId, [
                'product_id' => (int) $request->input('product_id'),
                'qty' => (float) $request->input('qty'),
                'rate' => (float) $request->input('rate'),
            ]);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * AJAX: Update a cart item (qty and/or rate).
     */
    public function update(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'product_id' => 'required|integer',
            'qty' => 'required|numeric|min:0.001',
            'rate' => 'nullable|numeric|min:0',
        ]);

        $customerId = (int) $request->input('customer_id');
        $branchId = session('branch_id', 0);
        $productId = (int) $request->input('product_id');
        $qty = (float) $request->input('qty');
        $rate = $request->input('rate') !== null ? (float) $request->input('rate') : null;

        try {
            $result = $this->cartService->updateItem(auth()->id(), $customerId, $branchId, $productId, $qty, $rate);
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * AJAX: Remove a product from the cart.
     */
    public function remove(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'product_id' => 'required|integer',
        ]);

        $customerId = (int) $request->input('customer_id');
        $branchId = session('branch_id', 0);
        $productId = (int) $request->input('product_id');

        $result = $this->cartService->removeItem(auth()->id(), $customerId, $branchId, $productId);
        return response()->json($result);
    }

    /**
     * AJAX: Clear the cart.
     */
    public function clear(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
        ]);

        $customerId = (int) $request->input('customer_id');
        // R6: pass branch_id (session) so clearCart targets the right
        // (user, customer, branch) cart row.
        $branchId = (int) session('branch_id', 0);
        $result = $this->cartService->clearCart(auth()->id(), $customerId, $branchId);
        return response()->json($result);
    }

    /**
     * AJAX: Validate the cart (hard gate before finalize).
     */
    public function validateCart(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
        ]);

        $customerId = (int) $request->input('customer_id');
        $branchId = session('branch_id', 0);

        $validation = $this->cartService->validateCart(auth()->id(), $customerId, $branchId);
        return response()->json($validation);
    }

    /**
     * AJAX: Set soft-hold on the cart.
     */
    public function softHold(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'soft_hold' => 'required|boolean',
        ]);

        $customerId = (int) $request->input('customer_id');
        $softHold = (bool) $request->input('soft_hold');
        // R6: pass branch_id (session) so setSoftHold targets the right
        // (user, customer, branch) cart row.
        $branchId = (int) session('branch_id', 0);

        $result = $this->cartService->setSoftHold(auth()->id(), $customerId, $softHold, $branchId);
        return response()->json($result);
    }

    /**
     * AJAX: Check product availability for the branch.
     */
    public function checkAvailability(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
        ]);

        $productId = (int) $request->input('product_id');
        $branchId = session('branch_id', 0);
        $excludeInvoiceId = $request->input('exclude_invoice_id') ? (int) $request->input('exclude_invoice_id') : null;

        $available = $this->availabilityService->getBranchAvailableQty($productId, $branchId, $excludeInvoiceId);
        $breakdown = $this->availabilityService->getBranchWarehouseBreakdown($productId, $branchId, $excludeInvoiceId);

        return response()->json([
            'product_id' => $productId,
            'branch_id' => $branchId,
            'available_qty' => round($available, 4),
            'warehouse_breakdown' => $breakdown,
        ]);
    }
}

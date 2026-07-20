<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesCartService;
use App\Services\Stock\StockAvailabilityService;
use Illuminate\Http\Request;

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

        $customers = \App\Models\Customer::active()->orderBy('customer_name')->limit(500)->get();
        $products = \App\Models\Product::active()->orderBy('product_name')->limit(500)->get();

        $cartData = null;
        if ($customerId) {
            $cartData = $this->cartService->getCart(auth()->id(), $customerId, $branchId);
        }

        return view('admin.sales.cart', [
            'title' => 'Sales Cart',
            'customers' => $customers,
            'products' => $products,
            'selectedCustomerId' => $customerId,
            'cartData' => $cartData,
            'branchId' => $branchId,
        ]);
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
        $result = $this->cartService->clearCart(auth()->id(), $customerId);
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

        $result = $this->cartService->setSoftHold(auth()->id(), $customerId, $softHold);
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

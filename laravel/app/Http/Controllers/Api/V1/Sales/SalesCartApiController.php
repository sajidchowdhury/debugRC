<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sales\StoreCartRequest;
use App\Http\Requests\Api\V1\Sales\UpdateCartRequest;
use App\Http\Resources\Api\V1\Sales\CartResource;
use App\Services\Sales\SalesCartService;
use App\Services\Stock\StockAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sales Cart API Controller — Mobile write endpoints.
 *
 * Endpoints:
 *   GET    /api/v1/sales/cart              Load cart for a customer
 *   POST   /api/v1/sales/cart              Add item to cart
 *   PUT    /api/v1/sales/cart              Update cart item
 *   DELETE /api/v1/sales/cart/{productId}  Remove item from cart
 *   POST   /api/v1/sales/cart/clear        Clear entire cart
 *   POST   /api/v1/sales/cart/validate     Validate cart (pre-finalize check)
 *   POST   /api/v1/sales/cart/soft-hold    Toggle soft-hold
 *   GET    /api/v1/sales/cart/availability Check product stock availability
 *
 * The cart is per-user-per-customer. The user_id is derived from the
 * authenticated API token (never from request body). Branch_id is
 * resolved from the user's session or explicit request (admins only).
 */
class SalesCartApiController extends Controller
{
    public function __construct(
        private SalesCartService $cartService,
        private StockAvailabilityService $availabilityService
    ) {}

    /**
     * Load the cart for a customer.
     *
     * GET /api/v1/sales/cart?customer_id=X
     */
    public function show(Request $request): JsonResponse
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
        ]);

        $userId = Auth::id();
        $customerId = (int) $request->input('customer_id');
        $branchId = $this->resolveBranchId($request);

        $cartData = $this->cartService->getCart($userId, $customerId, $branchId);

        return response()->json([
            'data' => new CartResource($cartData),
        ]);
    }

    /**
     * Add an item to the cart.
     *
     * POST /api/v1/sales/cart
     */
    public function store(StoreCartRequest $request): JsonResponse
    {
        $userId = Auth::id();
        $customerId = (int) $request->input('customer_id');
        $branchId = $this->resolveBranchId($request);

        try {
            $result = $this->cartService->addItem($userId, $customerId, $branchId, [
                'product_id' => (int) $request->input('product_id'),
                'qty'        => (float) $request->input('qty'),
                'rate'       => (float) $request->input('rate'),
            ]);

            return response()->json([
                'message' => $result['message'],
                'data'    => new CartResource($result['cart']),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Update a cart item (qty and/or rate).
     *
     * PUT /api/v1/sales/cart
     */
    public function update(UpdateCartRequest $request): JsonResponse
    {
        $userId = Auth::id();
        $customerId = (int) $request->input('customer_id');
        $branchId = $this->resolveBranchId($request);
        $productId = (int) $request->input('product_id');
        $qty = (float) $request->input('qty');
        $rate = $request->input('rate') !== null ? (float) $request->input('rate') : null;

        try {
            $result = $this->cartService->updateItem($userId, $customerId, $branchId, $productId, $qty, $rate);

            return response()->json([
                'message' => $result['message'],
                'data'    => new CartResource($result['cart']),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Remove a product from the cart.
     *
     * DELETE /api/v1/sales/cart/{productId}?customer_id=X
     */
    public function destroy(Request $request, int $productId): JsonResponse
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
        ]);

        $userId = Auth::id();
        $customerId = (int) $request->input('customer_id');

        $result = $this->cartService->removeItem($userId, $customerId, $this->resolveBranchId($request), $productId);

        return response()->json([
            'message' => $result['message'],
        ]);
    }

    /**
     * Clear the entire cart.
     *
     * POST /api/v1/sales/cart/clear
     */
    public function clear(Request $request): JsonResponse
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
        ]);

        $userId = Auth::id();
        $customerId = (int) $request->input('customer_id');

        $result = $this->cartService->clearCart($userId, $customerId);

        return response()->json(['message' => $result['message']]);
    }

    /**
     * Validate the cart (pre-finalize check).
     *
     * POST /api/v1/sales/cart/validate
     */
    public function validateCart(Request $request): JsonResponse
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
        ]);

        $userId = Auth::id();
        $customerId = (int) $request->input('customer_id');
        $branchId = $this->resolveBranchId($request);

        $validation = $this->cartService->validateCart($userId, $customerId, $branchId);

        return response()->json([
            'valid'        => $validation['valid'],
            'message'      => $validation['message'],
            'stock_errors' => $validation['stock_errors'],
            'rate_errors'  => $validation['rate_errors'],
        ]);
    }

    /**
     * Toggle soft-hold on the cart.
     *
     * POST /api/v1/sales/cart/soft-hold
     */
    public function softHold(Request $request): JsonResponse
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'soft_hold'   => 'required|boolean',
        ]);

        $userId = Auth::id();
        $customerId = (int) $request->input('customer_id');
        $softHold = (bool) $request->input('soft_hold');

        $result = $this->cartService->setSoftHold($userId, $customerId, $softHold);

        return response()->json(['message' => $result['message']]);
    }

    /**
     * Check product stock availability.
     *
     * GET /api/v1/sales/cart/availability?product_id=X
     */
    public function availability(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
        ]);

        $productId = (int) $request->input('product_id');
        $branchId = $this->resolveBranchId($request);
        $excludeInvoiceId = $request->input('exclude_invoice_id') ? (int) $request->input('exclude_invoice_id') : null;

        $available = $this->availabilityService->getBranchAvailableQty($productId, $branchId, $excludeInvoiceId);
        $breakdown = $this->availabilityService->getBranchWarehouseBreakdown($productId, $branchId, $excludeInvoiceId);

        return response()->json([
            'product_id'       => $productId,
            'branch_id'        => $branchId,
            'available_qty'    => round($available, 4),
            'warehouse_breakdown' => $breakdown,
        ]);
    }

    /**
     * Resolve branch_id for the request.
     * Non-admins: locked to their session/default branch.
     * Admins: can specify branch_id in the request.
     */
    private function resolveBranchId(Request $request): int
    {
        $user = Auth::user();
        $sessionBranchId = (int) (session('branch_id') ?? $user->getBranchId() ?? 0);

        if ($user->isAdmin()) {
            return (int) ($request->input('branch_id') ?? $sessionBranchId);
        }

        return $sessionBranchId;
    }
}

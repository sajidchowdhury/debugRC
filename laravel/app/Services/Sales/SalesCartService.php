<?php

namespace App\Services\Sales;

use App\Models\SalesDraftCart;
use App\Services\Stock\StockAvailabilityService;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sales Cart Service — Phase 8.1.
 *
 * Manages the per-user-per-customer draft cart stored in sales_draft_carts.
 * The cart is the pre-invoice state: salesman adds products, sets qty + rate,
 * checks availability via StockAvailabilityService.
 *
 * Cart item structure (stored in items_json):
 *   {
 *     product_id: int,
 *     product_name: string,
 *     qty: float,
 *     rate: float,
 *     total: float (qty × rate),
 *     min_rate: float|null (from price history),
 *     max_rate: float|null,
 *     default_rate: float|null,
 *     warehouse_id: int|null (assigned at godown, Phase 8.3)
 *   }
 *
 * Operations:
 *   - getCart: load cart for user + customer (with availability validation)
 *   - addItem: add product to cart (merge if same product + same rate)
 *   - updateItem: update qty or rate for a product
 *   - removeItem: remove a product from cart
 *   - clearCart: remove all items
 *   - validateCart: hard validation before finalize (stock availability + price range)
 *   - setSoftHold: mark cart as soft-hold (reserved for later)
 *
 * R4 (2026-07-21): every mutating operation now writes a SalesAuditLogger
 * event (cart_item_added / cart_item_updated / cart_item_removed /
 * cart_cleared) AFTER the cart is successfully persisted. The audit row
 * joins the same DB transaction as the cart save; if the save rolls back,
 * the audit row rolls back too (no orphan entries). Closes audit risk V4.
 */
class SalesCartService
{
    public function __construct(
        private StockAvailabilityService $availabilityService,
        private StockService $stockService,
        private SalesAuditLogger $auditLogger
    ) {}

    /**
     * Get the cart for a user + customer, with availability validation.
     *
     * @param int $userId
     * @param int $customerId
     * @param int|null $branchId
     * @param int|null $excludeInvoiceId (for editing existing invoices)
     * @return array{ cart: SalesDraftCart, items: array, subtotal: float, validation: array }
     */
    public function getCart(int $userId, int $customerId, ?int $branchId = null, ?int $excludeInvoiceId = null): array
    {
        $cart = SalesDraftCart::getOrCreate($userId, $customerId, $branchId);
        $items = $cart->items_json ?? [];

        // Enrich items with price range + availability.
        $items = $this->enrichItems($items, $branchId, $excludeInvoiceId);

        $subtotal = collect($items)->sum('total');

        return [
            'cart' => $cart,
            'items' => $items,
            'subtotal' => round($subtotal, 2),
            'validation' => $this->validateCartItems($items, $branchId, $excludeInvoiceId),
        ];
    }

    /**
     * Add a product to the cart. Merges if same product + same rate.
     *
     * @param int $userId
     * @param int $customerId
     * @param int $branchId
     * @param array $item { product_id, qty, rate, product_name? }
     * @param int|null $excludeInvoiceId
     * @return array{ status: string, message: string, cart: array }
     * @throws \InvalidArgumentException If qty/rate invalid.
     * @throws \RuntimeException If price out of range or insufficient stock.
     */
    public function addItem(int $userId, int $customerId, int $branchId, array $item, ?int $excludeInvoiceId = null): array
    {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = (float) ($item['qty'] ?? 0);
        $rate = (float) ($item['rate'] ?? 0);

        if ($productId <= 0) {
            throw new \InvalidArgumentException('product_id is required.');
        }
        if ($qty <= 0) {
            throw new \InvalidArgumentException('qty must be positive.');
        }
        if ($rate <= 0) {
            throw new \InvalidArgumentException('rate must be positive.');
        }

        // Validate price range (from product_price_history).
        $priceRange = $this->getProductPriceRange($productId);
        if ($priceRange && ($rate < $priceRange['min_rate'] - 0.01 || $rate > $priceRange['max_rate'] + 0.01)) {
            throw new \RuntimeException(
                "Rate {$rate} is out of allowed range ({$priceRange['min_rate']} - {$priceRange['max_rate']})."
            );
        }

        $cart = SalesDraftCart::getOrCreate($userId, $customerId, $branchId);
        $items = $cart->items_json ?? [];

        // Check if product already in cart with same rate → merge.
        $merged = false;
        foreach ($items as $idx => $cartItem) {
            if ((int) ($cartItem['product_id'] ?? 0) === $productId) {
                $existingRate = (float) ($cartItem['rate'] ?? 0);
                if (abs($existingRate - $rate) > 0.01) {
                    throw new \RuntimeException(
                        "Product already in cart at rate {$existingRate}. Remove it first or use the same rate."
                    );
                }
                $items[$idx]['qty'] = (float) $cartItem['qty'] + $qty;
                $items[$idx]['total'] = round($items[$idx]['qty'] * $existingRate, 2);
                $merged = true;
                break;
            }
        }

        if (!$merged) {
            $items[] = [
                'product_id' => $productId,
                'product_name' => $item['product_name'] ?? $this->getProductName($productId),
                'qty' => $qty,
                'rate' => $rate,
                'total' => round($qty * $rate, 2),
                'min_rate' => $priceRange['min_rate'] ?? null,
                'max_rate' => $priceRange['max_rate'] ?? null,
                'default_rate' => $priceRange['default_rate'] ?? null,
                'warehouse_id' => null,
            ];
        }

        // Validate availability before saving.
        $validation = $this->validateCartItems($items, $branchId, $excludeInvoiceId);
        if (!$validation['valid']) {
            throw new \RuntimeException($validation['message']);
        }

        $cart->items_json = $items;
        $cart->branch_id = $branchId;
        $cart->save();

        // R4: Audit log — cart_item_added (also fires on merge; the `merged`
        // flag lets auditors distinguish new-line vs same-rate qty bumps).
        $freshCart = $this->getCart($userId, $customerId, $branchId, $excludeInvoiceId);
        $this->auditLogger->cartItemAdded(
            $userId, $customerId, (int) $branchId,
            $productId, $qty, $rate,
            $merged,
            count($freshCart['items']),
            (float) $freshCart['subtotal']
        );

        return [
            'status' => 'success',
            'message' => $merged ? 'Quantity updated' : 'Item added to cart',
            'cart' => $freshCart,
        ];
    }

    /**
     * Update qty or rate for a product in the cart.
     *
     * @param int $userId
     * @param int $customerId
     * @param int $branchId
     * @param int $productId
     * @param float $qty
     * @param float|null $rate (null = keep existing rate)
     * @param int|null $excludeInvoiceId
     * @return array
     */
    public function updateItem(int $userId, int $customerId, int $branchId, int $productId, float $qty, ?float $rate = null, ?int $excludeInvoiceId = null): array
    {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('qty must be positive.');
        }

        $cart = SalesDraftCart::getOrCreate($userId, $customerId, $branchId);
        $items = $cart->items_json ?? [];

        $found = false;
        $oldQty = null;
        $oldRate = null;
        foreach ($items as $idx => $item) {
            if ((int) ($item['product_id'] ?? 0) === $productId) {
                $oldQty = (float) ($item['qty'] ?? 0);
                $oldRate = isset($item['rate']) ? (float) $item['rate'] : null;
                $items[$idx]['qty'] = $qty;
                if ($rate !== null) {
                    $items[$idx]['rate'] = $rate;
                }
                $items[$idx]['total'] = round($items[$idx]['qty'] * $items[$idx]['rate'], 2);
                $found = true;
                break;
            }
        }

        if (!$found) {
            throw new \RuntimeException("Product {$productId} not found in cart.");
        }

        // Validate availability.
        $validation = $this->validateCartItems($items, $branchId, $excludeInvoiceId);
        if (!$validation['valid']) {
            throw new \RuntimeException($validation['message']);
        }

        $cart->items_json = $items;
        $cart->save();

        // R4: Audit log — cart_item_updated (captures before/after qty + rate).
        $freshCart = $this->getCart($userId, $customerId, $branchId, $excludeInvoiceId);
        $this->auditLogger->cartItemUpdated(
            $userId, $customerId, (int) $branchId,
            $productId,
            $oldQty ?? 0.0, $qty,
            $oldRate, $rate,
            (float) $freshCart['subtotal']
        );

        return [
            'status' => 'success',
            'message' => 'Item updated',
            'cart' => $freshCart,
        ];
    }

    /**
     * Remove a product from the cart.
     */
    public function removeItem(int $userId, int $customerId, int $branchId, int $productId, ?int $excludeInvoiceId = null): array
    {
        $cart = SalesDraftCart::getOrCreate($userId, $customerId, $branchId);
        $items = $cart->items_json ?? [];

        // R4: capture the line being removed so the foregone revenue is auditable.
        $removedQty = 0.0;
        $removedRate = 0.0;
        foreach ($items as $item) {
            if ((int) ($item['product_id'] ?? 0) === $productId) {
                $removedQty = (float) ($item['qty'] ?? 0);
                $removedRate = (float) ($item['rate'] ?? 0);
                break;
            }
        }

        $items = array_values(array_filter($items, fn($item) => (int) ($item['product_id'] ?? 0) !== $productId));

        $cart->items_json = $items;
        $cart->save();

        // R4: Audit log — cart_item_removed.
        $freshCart = $this->getCart($userId, $customerId, $branchId, $excludeInvoiceId);
        $this->auditLogger->cartItemRemoved(
            $userId, $customerId, (int) $branchId,
            $productId, $removedQty, $removedRate,
            count($freshCart['items']),
            (float) $freshCart['subtotal']
        );

        return [
            'status' => 'success',
            'message' => 'Item removed',
            'cart' => $freshCart,
        ];
    }

    /**
     * Clear all items from the cart.
     */
    public function clearCart(int $userId, int $customerId, ?int $branchId = null): array
    {
        $cart = SalesDraftCart::getOrCreate($userId, $customerId, $branchId);

        // R4: capture count + value before clearing so a suspicious bulk-clear
        // (e.g. right before finalizing with a different cart) is auditable.
        $items = $cart->items_json ?? [];
        $itemsClearedCount = count($items);
        $itemsClearedValue = 0.0;
        foreach ($items as $item) {
            $itemsClearedValue += (float) ($item['qty'] ?? 0) * (float) ($item['rate'] ?? 0);
        }

        $cart->items_json = [];
        $cart->is_soft_hold = false;
        $cart->save();

        // R4: Audit log — cart_cleared. branch_id is taken from the cart row
        // (clearCart may be called without an explicit branchId, e.g. from the
        // mobile API destroy endpoint which only passes customer_id).
        $this->auditLogger->cartCleared(
            $userId, $customerId, (int) ($branchId ?? $cart->branch_id ?? 0),
            $itemsClearedCount, $itemsClearedValue
        );

        return ['status' => 'success', 'message' => 'Cart cleared'];
    }

    /**
     * Set soft-hold flag on the cart (reserved for later).
     *
     * R6 (2026-07-21): branch_id added to match the new 3-column unique key.
     * Null is normalized to 0 inside SalesDraftCart::getOrCreate().
     */
    public function setSoftHold(int $userId, int $customerId, bool $softHold, ?int $branchId = null): array
    {
        $cart = SalesDraftCart::getOrCreate($userId, $customerId, $branchId);
        $cart->is_soft_hold = $softHold;
        $cart->save();

        return ['status' => 'success', 'message' => $softHold ? 'Cart soft-held' : 'Soft-hold released'];
    }

    /**
     * Validate the cart for finalization (hard gate before creating invoice).
     *
     * @param int $userId
     * @param int $customerId
     * @param int $branchId
     * @param int|null $excludeInvoiceId
     * @return array{ valid: bool, message: string, stock_errors: array, rate_errors: array }
     */
    public function validateCart(int $userId, int $customerId, int $branchId, ?int $excludeInvoiceId = null): array
    {
        $cartData = $this->getCart($userId, $customerId, $branchId, $excludeInvoiceId);
        return $cartData['validation'];
    }

    /**
     * Validate cart items: check stock availability + price ranges.
     *
     * @param array $items
     * @param int|null $branchId
     * @param int|null $excludeInvoiceId
     * @return array{ valid: bool, message: string, stock_errors: array, rate_errors: array }
     */
    private function validateCartItems(array $items, ?int $branchId, ?int $excludeInvoiceId): array
    {
        $stockErrors = [];
        $rateErrors = [];

        if (empty($items)) {
            return ['valid' => true, 'message' => 'Cart is empty', 'stock_errors' => [], 'rate_errors' => []];
        }

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $qty = (float) ($item['qty'] ?? 0);
            $rate = (float) ($item['rate'] ?? 0);

            if ($productId <= 0) continue;

            // Check stock availability (branch-level).
            if ($branchId > 0) {
                $available = $this->availabilityService->getBranchAvailableQty($productId, $branchId, $excludeInvoiceId);
                if ($qty > $available + 0.0001) {
                    $stockErrors[] = [
                        'product_id' => $productId,
                        'product_name' => $item['product_name'] ?? "Product #{$productId}",
                        'requested' => $qty,
                        'available' => $available,
                        'shortfall' => $qty - $available,
                    ];
                }
            }

            // Check price range.
            $priceRange = $this->getProductPriceRange($productId);
            if ($priceRange && ($rate < $priceRange['min_rate'] - 0.01 || $rate > $priceRange['max_rate'] + 0.01)) {
                $rateErrors[] = [
                    'product_id' => $productId,
                    'product_name' => $item['product_name'] ?? "Product #{$productId}",
                    'rate' => $rate,
                    'min_rate' => $priceRange['min_rate'],
                    'max_rate' => $priceRange['max_rate'],
                ];
            }
        }

        $valid = empty($stockErrors) && empty($rateErrors);
        $messages = [];
        if (!empty($stockErrors)) {
            $messages[] = count($stockErrors) . ' product(s) with insufficient stock';
        }
        if (!empty($rateErrors)) {
            $messages[] = count($rateErrors) . ' product(s) with price out of range';
        }

        return [
            'valid' => $valid,
            'message' => $valid ? 'Cart is valid' : implode('; ', $messages),
            'stock_errors' => $stockErrors,
            'rate_errors' => $rateErrors,
        ];
    }

    /**
     * Enrich cart items with price range + availability data.
     */
    private function enrichItems(array $items, ?int $branchId, ?int $excludeInvoiceId): array
    {
        foreach ($items as &$item) {
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0) continue;

            // Add price range if missing.
            if (!isset($item['min_rate'])) {
                $priceRange = $this->getProductPriceRange($productId);
                if ($priceRange) {
                    $item['min_rate'] = $priceRange['min_rate'];
                    $item['max_rate'] = $priceRange['max_rate'];
                    $item['default_rate'] = $priceRange['default_rate'];
                }
            }

            // Add availability info.
            if ($branchId > 0) {
                $item['available_qty'] = $this->availabilityService->getBranchAvailableQty($productId, $branchId, $excludeInvoiceId);
            }

            // Recalculate total.
            $item['total'] = round((float) ($item['qty'] ?? 0) * (float) ($item['rate'] ?? 0), 2);
        }

        return $items;
    }

    /**
     * Get the current price range for a product (min/max/default from price history).
     *
     * @return array{ min_rate: float, max_rate: float, default_rate: float }|null
     */
    private function getProductPriceRange(int $productId): ?array
    {
        $price = DB::table('product_price_history')
            ->where('product_id', $productId)
            ->where('effective_from', '<=', today())
            ->where(function ($q) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', today());
            })
            ->orderBy('effective_from', 'desc')
            ->first();

        if (!$price) {
            // Fall back to product's sales_rate.
            $product = DB::table('products')->where('id', $productId)->first();
            if ($product) {
                return [
                    'min_rate' => (float) $product->sales_rate,
                    'max_rate' => (float) $product->sales_rate,
                    'default_rate' => (float) $product->sales_rate,
                ];
            }
            return null;
        }

        return [
            'min_rate' => (float) $price->min_rate,
            'max_rate' => (float) $price->max_rate,
            'default_rate' => (float) $price->default_rate,
        ];
    }

    /**
     * Get product name from DB.
     */
    private function getProductName(int $productId): string
    {
        return DB::table('products')->where('id', $productId)->value('product_name') ?? "Product #{$productId}";
    }
}

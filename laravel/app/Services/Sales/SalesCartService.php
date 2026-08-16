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
        private SalesAuditLogger $auditLogger,
        private BelowMinApprovalService $belowMinApproval
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
     * Session 6: if the rate is below the product's min_rate, the
     * caller MUST pass `below_min_override_id` — the audit-log row id
     * returned by BelowMinApprovalService::approve(). Without it, the
     * below-min rate is hard-blocked (legacy behavior). With it, the
     * line is added with `below_min_override_id` stored in items_json
     * and the rate-error is suppressed in validateCartItems().
     *
     * The `below_min_override_id` is propagated to
     * sales_invoice_items.below_min_override_id at finalize time by
     * SalesInvoiceService::finalizeFromCart().
     *
     * @param int $userId
     * @param int $customerId
     * @param int $branchId
     * @param array $item {
     *     product_id: int,
     *     qty: float,
     *     rate: float,
     *     product_name?: string,
     *     below_min_override_id?: int|null  (S6 — audit-log row id)
     * }
     * @param int|null $excludeInvoiceId
     * @return array{ status: string, message: string, cart: array }
     * @throws \InvalidArgumentException If qty/rate invalid.
     * @throws \RuntimeException If price out of range without override, or insufficient stock.
     */
    public function addItem(int $userId, int $customerId, int $branchId, array $item, ?int $excludeInvoiceId = null): array
    {
        $productId = (int) ($item['product_id'] ?? 0);
        $qty = (float) ($item['qty'] ?? 0);
        $rate = (float) ($item['rate'] ?? 0);
        $belowMinOverrideId = isset($item['below_min_override_id'])
            ? (int) $item['below_min_override_id']
            : null;

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
        // S6: if the rate is below min AND a below_min_override_id is
        // supplied, allow the line through (the override was already
        // audited by BelowMinApprovalService::approve()). Without the
        // override id, hard-block as before.
        //
        // Note: we still hard-block rates ABOVE max — there's no
        // "above-max override" flow (overcharging is not a business
        // case worth auditing; the customer just pays more).
        $priceRange = $this->getProductPriceRange($productId);
        if ($priceRange) {
            $belowMin = $rate < $priceRange['min_rate'] - 0.01;
            $aboveMax = $rate > $priceRange['max_rate'] + 0.01;

            if ($belowMin && $belowMinOverrideId === null) {
                throw new \RuntimeException(
                    "Rate {$rate} is below the minimum ({$priceRange['min_rate']}). "
                    . "An admin/manager override is required."
                );
            }
            if ($belowMin && $belowMinOverrideId !== null) {
                // Validate the override id points to a real audit row.
                // This is defense-in-depth: the JS only passes an id
                // returned by /admin/sales/below-min-approvals, but a
                // malicious client could send a fake id. We re-check
                // here so the cart never stores a dangling reference.
                if (!$this->belowMinApproval->isValidOverride($belowMinOverrideId)) {
                    throw new \RuntimeException(
                        "Invalid below-min override id {$belowMinOverrideId} — audit row not found."
                    );
                }
            }
            if ($aboveMax) {
                throw new \RuntimeException(
                    "Rate {$rate} is above the maximum ({$priceRange['max_rate']})."
                );
            }
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
                // S6: store the audit-log row id on the cart line so it
                // propagates to sales_invoice_items at finalize time.
                'below_min_override_id' => $belowMinOverrideId,
            ];
        } else {
            // S6: if merging into an existing line, preserve the
            // existing override id (the line was already approved at
            // the original rate; a qty bump doesn't need re-approval).
            // If the existing line had no override but the new add
            // supplies one (unusual — the rate is the same, so the
            // override status should match), prefer the existing
            // value to avoid surprising the cashier.
            // We do NOT set below_min_override_id here — it's already
            // on the line from the original add.
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
     * Session 6: if the rate is being changed to a below-min value,
     * the caller MUST supply `below_min_override_id`. If the rate is
     * unchanged (only qty change), the existing override id is
     * preserved. If the rate is changed to a within-range value, any
     * existing override id is cleared (the override is no longer
     * needed and should not propagate to the sale line).
     *
     * @param int $userId
     * @param int $customerId
     * @param int $branchId
     * @param int $productId
     * @param float $qty
     * @param float|null $rate (null = keep existing rate)
     * @param int|null $excludeInvoiceId
     * @param int|null $belowMinOverrideId (S6 — required if rate < min)
     * @return array
     */
    public function updateItem(int $userId, int $customerId, int $branchId, int $productId, float $qty, ?float $rate = null, ?int $excludeInvoiceId = null, ?int $belowMinOverrideId = null): array
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
                    // S6: handle override-id lifecycle on rate change.
                    $priceRange = $this->getProductPriceRange($productId);
                    if ($priceRange && $rate < $priceRange['min_rate'] - 0.01) {
                        // New rate is below min — require an override id.
                        if ($belowMinOverrideId === null) {
                            throw new \RuntimeException(
                                "Rate {$rate} is below the minimum ({$priceRange['min_rate']}). "
                                . "An admin/manager override is required."
                            );
                        }
                        if (!$this->belowMinApproval->isValidOverride($belowMinOverrideId)) {
                            throw new \RuntimeException(
                                "Invalid below-min override id {$belowMinOverrideId} — audit row not found."
                            );
                        }
                        $items[$idx]['below_min_override_id'] = $belowMinOverrideId;
                    } else {
                        // New rate is within range — clear any stale override id.
                        $items[$idx]['below_min_override_id'] = null;
                    }
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
     * List all open draft carts for a user (+ optional branch).
     *
     * R11 (2026-07-22): ported from Legacy
     * `SalesCartOperationsTrait::listDraftCarts()` — used by the
     * `#draft-tabs` dock in the cart blade to render one pill per
     * customer-cart with item-count badges.
     *
     * Only carts with at least one item are returned — empty carts
     * are not shown as tabs (matches Legacy behaviour where empty
     * session slots are skipped). Soft-held carts ARE included so
     * the user can see + resume them.
     *
     * Sorted by item_count DESC then updated_at DESC so the busiest
     * cart is leftmost (matches Legacy usort by item_count desc).
     *
     * @param int      $userId
     * @param int|null $branchId  If non-null, restrict to this branch
     *                              (R6: carts are branched).
     * @return list<array{
     *     customer_id:int,
     *     label:string,
     *     shop_name:string,
     *     customer_name:string,
     *     mobile:string,
     *     item_count:int,
     *     subtotal:float,
     *     is_soft_hold:bool,
     *     updated_at:?string
     * }>
     */
    public function listCarts(int $userId, ?int $branchId = null): array
    {
        $query = SalesDraftCart::query()
            ->where('user_id', $userId)
            ->whereNotNull('customer_id');

        if ($branchId !== null) {
            $query->where('branch_id', $branchId);
        }

        $rows = $query->orderByDesc('updated_at')->limit(50)->get();

        $result = [];
        foreach ($rows as $cart) {
            $items = $cart->items_json ?? [];
            if (!is_array($items)) {
                $items = [];
            }
            $itemCount = count($items);
            // Skip empty carts — they shouldn't appear as tabs.
            // (Matches Legacy: empty session slots are not listed.)
            if ($itemCount === 0) {
                continue;
            }

            $customerId = (int) $cart->customer_id;
            if ($customerId <= 0) {
                continue;
            }

            // Look up the customer once. Use a cheap DB::table query
            // (not Eloquent) to avoid model boot overhead per row.
            $cust = DB::table('customers')
                ->where('id', $customerId)
                ->first(['customer_name', 'shop_name', 'mobile']);

            $shop   = (string) ($cust->shop_name ?? '');
            $name   = (string) ($cust->customer_name ?? '');
            $mobile = (string) ($cust->mobile ?? '');

            $label = trim($shop !== '' ? $shop : $name);
            if ($mobile !== '') {
                $label = $label !== '' ? "{$label} · {$mobile}" : $mobile;
            }
            if ($label === '') {
                $label = "Customer #{$customerId}";
            }

            $subtotal = 0.0;
            foreach ($items as $item) {
                $subtotal += (float) ($item['total'] ?? (
                    (float) ($item['qty'] ?? 0) * (float) ($item['rate'] ?? 0)
                ));
            }

            $result[] = [
                'customer_id'   => $customerId,
                'label'         => $label,
                'shop_name'     => $shop,
                'customer_name' => $name,
                'mobile'        => $mobile,
                'item_count'    => $itemCount,
                'subtotal'      => round($subtotal, 2),
                'is_soft_hold'  => (bool) $cart->is_soft_hold,
                'updated_at'    => $cart->updated_at
                    ? $cart->updated_at->toIso8601String()
                    : null,
            ];
        }

        // Sort: item_count DESC, then updated_at DESC. Legacy usort
        // only used item_count; we add updated_at as a tiebreaker so
        // equally-busy carts surface the recently-touched one first.
        usort($result, function ($a, $b) {
            if ($b['item_count'] !== $a['item_count']) {
                return $b['item_count'] <=> $a['item_count'];
            }
            return ($b['updated_at'] ?? '') <=> ($a['updated_at'] ?? '');
        });

        return $result;
    }

    /**
     * R14: Get live customer credit snapshot for the cart page.
     *
     * Ported from Legacy `SalesModel::getCustomerDetails` (which calls
     * `Get_Customer_By_Id` + `Get_Customer_Due`). Returns the customer's
     * credit_limit, current AR balance (recent_due), and balance_left
     * (= credit_limit − recent_due) so the cart blade can render an
     * inline credit panel without waiting until finalize.
     *
     * The current balance is computed as
     *   SUM(debit) − SUM(credit) FROM customer_ledger
     *   WHERE customer_id = ? AND is_reversed = false
     *
     * `is_reversed = false` filters out reversed transactions (Legacy
     * had no `is_reversed` column on customer_ledger — Laravel added
     * it in migration 2025_01_02_000002; the R5/R10 fix made it the
     * canonical filter for "live" ledger rows). The Legacy SUM(CASE
     * WHEN debit>0 THEN debit ELSE -credit END) is mathematically
     * identical to SUM(debit) − SUM(credit) and was rewritten for
     * clarity + index friendliness.
     *
     * Returns an empty array (not null) when the customer is not
     * found — matches Legacy `customer_details` endpoint behaviour
     * (`$this->sendJson($data ?: [...defaults])`) so the frontend
     * can always render the panel with sane zeros.
     *
     * @return array{
     *     customer_id:int,
     *     customer_name:string,
     *     shop_name:string,
     *     mobile:string,
     *     address:string,
     *     credit_limit:float,
     *     current_due:float,
     *     due_left:float
     * }
     */
    public function getCustomerDetails(int $customerId): array
    {
        $cust = DB::table('customers')->where('id', $customerId)->first([
            'id', 'customer_name', 'shop_name', 'mobile', 'phone',
            'address', 'credit_limit',
        ]);

        if (!$cust) {
            return [
                'customer_id'   => $customerId,
                'customer_name' => '',
                'shop_name'     => '',
                'mobile'        => '',
                'address'       => '',
                'credit_limit'  => 0.0,
                'current_due'   => 0.0,
                'due_left'      => 0.0,
            ];
        }

        $creditLimit = (float) ($cust->credit_limit ?? 0);

        // Current AR balance = SUM(debit) − SUM(credit), excluding reversed rows.
        // Mirrors SalesInvoiceService::checkCreditLimit (L875–L879) exactly so
        // the live panel and the finalize-time check see the same number.
        $currentDue = (float) DB::table('customer_ledger')
            ->where('customer_id', $customerId)
            ->where('is_reversed', false)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as balance')
            ->value('balance');

        return [
            'customer_id'   => (int) $cust->id,
            'customer_name' => (string) ($cust->customer_name ?? ''),
            'shop_name'     => (string) ($cust->shop_name ?? ''),
            'mobile'        => (string) ($cust->mobile ?? $cust->phone ?? ''),
            'address'       => (string) ($cust->address ?? ''),
            'credit_limit'  => round($creditLimit, 2),
            'current_due'   => round($currentDue, 2),
            'due_left'      => round($creditLimit - $currentDue, 2),
        ];
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
     * Session 6: a line with `below_min_override_id` set is EXEMPT
     * from the below-min rate-error. The override was already
     * approved and audited; the rate is allowed to be below min.
     * Above-max rates are still hard-blocked (no above-max override).
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
            $belowMinOverrideId = isset($item['below_min_override_id'])
                ? (int) $item['below_min_override_id']
                : null;

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
            // S6: skip the below-min check if the line has an override id.
            // Above-max is always hard-blocked.
            $priceRange = $this->getProductPriceRange($productId);
            if ($priceRange) {
                $belowMin = $rate < $priceRange['min_rate'] - 0.01;
                $aboveMax = $rate > $priceRange['max_rate'] + 0.01;

                if ($aboveMax) {
                    $rateErrors[] = [
                        'product_id' => $productId,
                        'product_name' => $item['product_name'] ?? "Product #{$productId}",
                        'rate' => $rate,
                        'min_rate' => $priceRange['min_rate'],
                        'max_rate' => $priceRange['max_rate'],
                        'error_type' => 'above_max',
                    ];
                } elseif ($belowMin && $belowMinOverrideId === null) {
                    // Below min WITHOUT override — hard-block.
                    $rateErrors[] = [
                        'product_id' => $productId,
                        'product_name' => $item['product_name'] ?? "Product #{$productId}",
                        'rate' => $rate,
                        'min_rate' => $priceRange['min_rate'],
                        'max_rate' => $priceRange['max_rate'],
                        'error_type' => 'below_min_no_override',
                    ];
                }
                // Below min WITH override → no error (line is approved).
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
     *
     * Session 6: preserves the `below_min_override_id` field if set
     * on the stored cart line (it's added by addItem() when an admin
     * override is supplied). Old cart rows without the field get NULL.
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

            // S6: normalize below_min_override_id to int|null so the JS
            // and downstream finalize logic get a consistent type.
            $item['below_min_override_id'] = isset($item['below_min_override_id']) && $item['below_min_override_id']
                ? (int) $item['below_min_override_id']
                : null;

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

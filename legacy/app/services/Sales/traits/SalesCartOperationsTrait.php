<?php
// app/services/Sales/traits/SalesCartOperationsTrait.php — Phase 6 (extracted from SalesModel)

trait SalesCartOperationsTrait
{
    public function addToCart($data) { 

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $customer_id = $data['customer_id'] ?? 0;
    $product_id  = $data['product_id'] ?? 0;

    if (!$customer_id) {
        return ['status' => 'error', 'message' => 'Customer required'];
    }

    $qty  = isset($data['qty']) ? (float)$data['qty'] : 0;
    $rate = isset($data['rate']) ? (float)$data['rate'] : 0;
    $product_id = (int)$product_id;
    $branchId = $this->resolveCartBranchId($data);
    $excludeInvoiceId = $this->resolveCartExcludeInvoiceId($data);

    if ($qty <= 0 || $rate <= 0) {
        return ['status' => 'error', 'message' => 'Invalid qty or rate'];
    }

    $rangeCheck = $this->validateRateInRange($product_id, $rate);
    if (!$rangeCheck['valid']) {
        return [
            'status'       => 'error',
            'code'         => 'price_out_of_range',
            'message'      => $rangeCheck['message'] ?? 'Rate out of allowed range.',
            'min_rate'     => $rangeCheck['min_rate'] ?? null,
            'max_rate'     => $rangeCheck['max_rate'] ?? null,
            'default_rate' => $rangeCheck['default_rate'] ?? null,
        ];
    }

    $cartKey = 'sales_draft_carts';

    if (!isset($_SESSION[$cartKey][$customer_id])) {
        $_SESSION[$cartKey][$customer_id] = [];
    }

    $cart = $_SESSION[$cartKey][$customer_id];
    $merged = false;

    foreach ($cart as $idx => $item) {
        if ((int)($item['product_id'] ?? 0) === $product_id) {
            $existingRate = (float)($item['rate'] ?? 0);
            if (abs($existingRate - $rate) > 0.0001) {
                return [
                    'status'  => 'error',
                    'message' => sprintf(
                        'This product is already in the cart at Tk %.2f. Edit that line or remove it first.',
                        $existingRate
                    ),
                ];
            }
            $cart[$idx]['qty'] = (float)($item['qty'] ?? 0) + $qty;
            $cart[$idx]['total'] = round($cart[$idx]['qty'] * $existingRate, 2);
            $cart[$idx]['min_rate'] = $rangeCheck['min_rate'];
            $cart[$idx]['max_rate'] = $rangeCheck['max_rate'];
            $cart[$idx]['default_rate'] = $rangeCheck['default_rate'];
            $merged = true;
            break;
        }
    }

    if (!$merged) {
        $cart[] = [
            'product_id'   => $product_id,
            'product_name' => htmlspecialchars($data['product_name'] ?? ''),
            'qty'          => $qty,
            'rate'         => $rate,
            'total'        => round($qty * $rate, 2),
            'min_rate'     => $rangeCheck['min_rate'],
            'max_rate'     => $rangeCheck['max_rate'],
            'default_rate' => $rangeCheck['default_rate'],
        ];
    }

    if ($branchId > 0) {
        $validation = $this->buildCartValidationResult($cart, $branchId, $excludeInvoiceId);
        if (!$validation['valid']) {
            return [
                'status'       => 'error',
                'code'         => !empty($validation['rate_errors']) ? 'price_out_of_range' : 'insufficient_stock',
                'message'      => $validation['message'],
                'rate_errors'  => $validation['rate_errors'],
                'stock_errors' => $validation['stock_errors'],
            ];
        }
    }

    $_SESSION[$cartKey][$customer_id] = $cart;
    $this->persistDraftCartToDb((int)$customer_id, $cart);

    return [
        'status'  => 'success',
        'message' => $merged ? 'Quantity updated' : 'Item added to cart',
    ];


    }          

    public function loadCart($customer_id, int $branchId = 0, ?int $excludeInvoiceId = null) {
        $this->hydrateSessionCartFromDb((int)$customer_id);
        $cartKey = 'sales_draft_carts';
        $items = $_SESSION[$cartKey][$customer_id] ?? [];

        foreach ($items as &$item) {
            $pid = (int)($item['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $range = $this->getProductPriceRange($pid);
            if ($range) {
                $item['min_rate'] = $range['min_rate'];
                $item['max_rate'] = $range['max_rate'];
                $item['default_rate'] = $range['default_rate'];
            }
            $qty = (float)($item['qty'] ?? 0);
            $rate = (float)($item['rate'] ?? 0);
            $item['total'] = round($qty * $rate, 2);
        }
        unset($item);

        $_SESSION[$cartKey][$customer_id] = $items;
        $subtotal = array_sum(array_column($items, 'total'));

        $result = [
            'items'    => $items,
            'subtotal' => round($subtotal, 2),
        ];

        if ($branchId > 0) {
            $result['validation'] = $this->buildCartValidationResult($items, $branchId, $excludeInvoiceId);
        }

        return $result;
    }

    /**
     * Hard validation gate before finalize / update invoice.
     */
    public function validateCartForSubmit(array $data): array
    {
        $customerId = (int)($data['customer_id'] ?? 0);
        $branchId = $this->resolveCartBranchId($data);
        $excludeInvoiceId = $this->resolveCartExcludeInvoiceId($data);

        if ($customerId <= 0) {
            return ['status' => 'error', 'valid' => false, 'message' => 'Customer required'];
        }
        if ($branchId <= 0) {
            return ['status' => 'error', 'valid' => false, 'message' => 'Branch is required'];
        }

        $cart = $this->loadCart($customerId, $branchId, $excludeInvoiceId);
        $validation = $cart['validation'] ?? $this->buildCartValidationResult($cart['items'] ?? [], $branchId, $excludeInvoiceId);

        if (!$validation['valid']) {
            return [
                'status'       => 'error',
                'valid'        => false,
                'code'         => !empty($validation['rate_errors']) ? 'price_out_of_range' : 'insufficient_stock',
                'message'      => $validation['message'],
                'rate_errors'  => $validation['rate_errors'],
                'stock_errors' => $validation['stock_errors'],
            ];
        }

        return [
            'status'  => 'success',
            'valid'   => true,
            'message' => 'Cart is valid',
        ];
    }

    /**
     * Load draft edit cart from invoice lines — preserves stored rates (no range check on hydrate).
     */
    public function hydrateEditCart(int $customerId, array $invoiceItems): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if ($customerId <= 0) {
            return ['status' => 'error', 'message' => 'Customer required'];
        }

        $cartKey = 'sales_draft_carts';
        $cart = [];

        foreach ($invoiceItems as $row) {
            $productId = (int)($row['product_id'] ?? 0);
            $qty = (float)($row['qty'] ?? 0);
            $rate = (float)($row['rate'] ?? 0);
            if ($productId <= 0 || $qty <= 0 || $rate <= 0) {
                continue;
            }

            $item = [
                'product_id'   => $productId,
                'product_name' => htmlspecialchars((string)($row['product_name'] ?? '')),
                'qty'          => $qty,
                'rate'         => $rate,
                'total'        => round($qty * $rate, 2),
            ];

            $range = $this->getProductPriceRange($productId);
            if ($range) {
                $item['min_rate'] = $range['min_rate'];
                $item['max_rate'] = $range['max_rate'];
                $item['default_rate'] = $range['default_rate'];
            }

            $cart[] = $item;
        }

        $_SESSION[$cartKey][$customerId] = $cart;
        $this->persistDraftCartToDb($customerId, $cart);

        return [
            'status'      => 'success',
            'item_count'  => count($cart),
            'subtotal'    => round(array_sum(array_column($cart, 'total')), 2),
        ];
    }

    /**
     * List all open session draft carts (multi-customer POS).
     */
    public function listDraftCarts(): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $carts = $_SESSION['sales_draft_carts'] ?? [];
        $result = [];

        foreach ($carts as $customerId => $items) {
            $customerId = (int)$customerId;
            if ($customerId <= 0 || empty($items)) {
                continue;
            }

            $cust = $this->Get_Customer_By_Id($customerId);
            $shop = $cust['shop_name'] ?? '';
            $name = $cust['customer_name'] ?? '';
            $mobile = $cust['mobile'] ?? '';
            $label = trim($shop ?: $name);
            if ($mobile) {
                $label = $label ? "{$label} · {$mobile}" : $mobile;
            }
            if ($label === '') {
                $label = "Customer #{$customerId}";
            }

            $subtotal = array_sum(array_column($items, 'total'));
            $result[] = [
                'customer_id' => $customerId,
                'label'       => $label,
                'shop_name'   => $shop,
                'customer_name' => $name,
                'mobile'      => $mobile,
                'item_count'  => count($items),
                'subtotal'    => round($subtotal, 2),
            ];
        }

        usort($result, fn($a, $b) => ($b['item_count'] <=> $a['item_count']));

        return $result;
    }


 public function deleteFromCart($data) {
        $customer_id = $data['customer_id'] ?? 0;
        $index = $data['index'] ?? -1;
        if (!$customer_id || $index < 0) return ['status' => 'error', 'message' => 'Invalid data'];

        $cartKey = 'sales_draft_carts';
        if (isset($_SESSION[$cartKey][$customer_id][$index])) {
            unset($_SESSION[$cartKey][$customer_id][$index]);
            $_SESSION[$cartKey][$customer_id] = array_values($_SESSION[$cartKey][$customer_id]); // re-index
            $this->persistDraftCartToDb((int)$customer_id, $_SESSION[$cartKey][$customer_id]);
            return ['status' => 'success', 'message' => 'Item removed'];
        }
        return ['status' => 'error', 'message' => 'Item not found'];
    }

    public function updateCartItem($data) {
        $customer_id = $data['customer_id'] ?? 0;
        $index = (int)($data['index'] ?? -1);
        $qty = floatval($data['qty'] ?? 0);
        $rate = floatval($data['rate'] ?? 0);
        $branchId = $this->resolveCartBranchId($data);
        $excludeInvoiceId = $this->resolveCartExcludeInvoiceId($data);

        if (!$customer_id || $index < 0 || $qty <= 0 || $rate <= 0) {
            return ['status' => 'error', 'message' => 'Invalid qty or rate'];
        }

        $cartKey = 'sales_draft_carts';
        if (!isset($_SESSION[$cartKey][$customer_id][$index])) {
            return ['status' => 'error', 'message' => 'Item not found'];
        }

        $productId = (int)($_SESSION[$cartKey][$customer_id][$index]['product_id'] ?? 0);
        $rangeCheck = $this->validateRateInRange($productId, $rate);
        if (!$rangeCheck['valid']) {
            return [
                'status'       => 'error',
                'code'         => 'price_out_of_range',
                'message'      => $rangeCheck['message'] ?? 'Rate out of allowed range.',
                'min_rate'     => $rangeCheck['min_rate'] ?? null,
                'max_rate'     => $rangeCheck['max_rate'] ?? null,
                'default_rate' => $rangeCheck['default_rate'] ?? null,
            ];
        }

        $cart = $_SESSION[$cartKey][$customer_id];
        $cart[$index]['qty'] = $qty;
        $cart[$index]['rate'] = $rate;
        $cart[$index]['total'] = round($qty * $rate, 2);
        $cart[$index]['min_rate'] = $rangeCheck['min_rate'];
        $cart[$index]['max_rate'] = $rangeCheck['max_rate'];
        $cart[$index]['default_rate'] = $rangeCheck['default_rate'];

        if ($branchId > 0) {
            $validation = $this->buildCartValidationResult($cart, $branchId, $excludeInvoiceId);
            if (!$validation['valid']) {
                return [
                    'status'       => 'error',
                    'code'         => !empty($validation['rate_errors']) ? 'price_out_of_range' : 'insufficient_stock',
                    'message'      => $validation['message'],
                    'rate_errors'  => $validation['rate_errors'],
                    'stock_errors' => $validation['stock_errors'],
                ];
            }
        }

        $_SESSION[$cartKey][$customer_id] = $cart;
        $this->persistDraftCartToDb((int)$customer_id, $cart);

        return ['status' => 'success'];
    }

    // Clear session cart for a specific customer
    public function clearTabCart($data)
    {
        $customer_id = $data['customer_id'] ?? 0;
        
        if ($customer_id && isset($_SESSION['sales_draft_carts'][$customer_id])) {
            unset($_SESSION['sales_draft_carts'][$customer_id]);
        }
        $this->removeDraftCartFromDb((int)$customer_id);

        return ['status' => 'success'];
    }

    /** Alias used by SalesController::delete_tab_cart */
    public function deleteTabCart($data)
    {
        return $this->clearTabCart($data);
    }

    protected function usesDbDraftCarts(): bool
    {
        return defined('SALES_DB_DRAFT_CARTS') && SALES_DB_DRAFT_CARTS;
    }

    protected function dbDraftCartTableExists(): bool
    {
        try {
            $this->db->query("SHOW TABLES LIKE 'sales_draft_carts'");
            return (bool)$this->db->single();
        } catch (Throwable $e) {
            return false;
        }
    }

    protected function persistDraftCartToDb(int $customerId, array $items): void
    {
        if (!$this->usesDbDraftCarts() || !$this->dbDraftCartTableExists() || $customerId <= 0) {
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $branchId = (int)($_SESSION['branch_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $json = json_encode(array_values($items), JSON_UNESCAPED_UNICODE);
        $this->db->query("
            INSERT INTO sales_draft_carts (user_id, branch_id, customer_id, items_json)
            VALUES (:uid, :bid, :cid, :json)
            ON DUPLICATE KEY UPDATE items_json = VALUES(items_json), branch_id = VALUES(branch_id)
        ");
        $this->db->bind(':uid', $userId);
        $this->db->bind(':bid', $branchId);
        $this->db->bind(':cid', $customerId);
        $this->db->bind(':json', $json);
        $this->db->execute();
    }

    protected function removeDraftCartFromDb(int $customerId): void
    {
        if (!$this->usesDbDraftCarts() || !$this->dbDraftCartTableExists() || $customerId <= 0) {
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $this->db->query("
            DELETE FROM sales_draft_carts
            WHERE user_id = :uid AND customer_id = :cid
        ");
        $this->db->bind(':uid', $userId);
        $this->db->bind(':cid', $customerId);
        $this->db->execute();
    }

    protected function hydrateSessionCartFromDb(int $customerId): void
    {
        if (!$this->usesDbDraftCarts() || !$this->dbDraftCartTableExists() || $customerId <= 0) {
            return;
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!empty($_SESSION['sales_draft_carts'][$customerId])) {
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $this->db->query("
            SELECT items_json FROM sales_draft_carts
            WHERE user_id = :uid AND customer_id = :cid
            LIMIT 1
        ");
        $this->db->bind(':uid', $userId);
        $this->db->bind(':cid', $customerId);
        $row = $this->db->single();
        if (!$row || empty($row['items_json'])) {
            return;
        }

        $items = json_decode($row['items_json'], true);
        if (is_array($items) && $items !== []) {
            $_SESSION['sales_draft_carts'][$customerId] = $items;
        }
    }

}

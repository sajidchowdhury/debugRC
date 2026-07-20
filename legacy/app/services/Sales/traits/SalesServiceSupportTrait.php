<?php
// app/services/Sales/traits/SalesServiceSupportTrait.php — shared stock validation

require_once __DIR__ . '/../../Stock/StockService.php';

trait SalesServiceSupportTrait
{
    protected ?StockService $stockService = null;

    protected function stock(): StockService
    {
        if ($this->stockService === null) {
            $this->stockService = new StockService($this->db);
        }

        return $this->stockService;
    }

    protected function validateCartStockAvailability(array $items, int $branch_id, ?int $excludeInvoiceId = null): array
    {
        $qtyByProduct = [];
        foreach ($items as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0) + (float)($item['qty'] ?? 0);
        }

        return $this->stock()->assertBranchProductsAvailable($branch_id, $qtyByProduct, $excludeInvoiceId);
    }

    protected function getCustomerRunningBalance($customer_id)
    {
        return $this->Get_Customer_Now_Due($customer_id);
    }

    /**
     * @return array{min_rate: float, max_rate: float, default_rate: float}|null
     */
    protected function getProductPriceRange(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        require_once __DIR__ . '/../../../models/ProductModel.php';
        $price = (new ProductModel())->getCurrentPrice($productId);

        if (!$price || (float)($price['max_rate'] ?? 0) <= 0) {
            return null;
        }

        return [
            'min_rate'      => (float)$price['min_rate'],
            'max_rate'      => (float)$price['max_rate'],
            'default_rate'  => (float)$price['default_rate'],
        ];
    }

    /**
     * @return array{valid: bool, message?: string, min_rate?: float, max_rate?: float, default_rate?: float}
     */
    protected function validateRateInRange(int $productId, float $rate): array
    {
        $price = $this->getProductPriceRange($productId);

        if (!$price) {
            return ['valid' => false, 'message' => 'No price range configured for this product.'];
        }

        if ($rate < $price['min_rate'] || $rate > $price['max_rate']) {
            return [
                'valid'        => false,
                'code'         => 'price_out_of_range',
                'message'      => sprintf(
                    'Rate must be between Tk %.2f and Tk %.2f.',
                    $price['min_rate'],
                    $price['max_rate']
                ),
                'min_rate'     => $price['min_rate'],
                'max_rate'     => $price['max_rate'],
                'default_rate' => $price['default_rate'],
            ];
        }

        return [
            'valid'        => true,
            'min_rate'     => $price['min_rate'],
            'max_rate'     => $price['max_rate'],
            'default_rate' => $price['default_rate'],
        ];
    }

    /**
     * @return string[] error messages
     */
    protected function validateCartRatesInRange(array $items): array
    {
        $errors = [];
        foreach ($items as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $rate = (float)($item['rate'] ?? 0);
            if ($productId <= 0 || $rate <= 0) {
                continue;
            }

            $label = trim((string)($item['product_name'] ?? ''));
            if ($label === '') {
                $label = "Product #{$productId}";
            }

            $check = $this->validateRateInRange($productId, $rate);
            if (!$check['valid']) {
                $errors[] = "{$label}: " . ($check['message'] ?? 'Rate out of allowed range.');
            }
        }

        return $errors;
    }

    /**
     * Combined price-range + stock validation for cart checkout.
     *
     * @return array{valid: bool, rate_errors: string[], stock_errors: string[], message: string}
     */
    protected function buildCartValidationResult(array $items, int $branchId, ?int $excludeInvoiceId = null): array
    {
        if ($items === []) {
            return [
                'valid'         => false,
                'rate_errors'   => ['Cart is empty.'],
                'stock_errors'  => [],
                'message'       => 'Cart is empty.',
            ];
        }

        $rateErrors = $this->validateCartRatesInRange($items);
        $stockErrors = $branchId > 0
            ? $this->validateCartStockAvailability($items, $branchId, $excludeInvoiceId)
            : [];

        $allErrors = array_merge($rateErrors, $stockErrors);

        return [
            'valid'        => $allErrors === [],
            'rate_errors'  => $rateErrors,
            'stock_errors' => $stockErrors,
            'message'      => $allErrors === [] ? '' : implode(' ', $allErrors),
        ];
    }

    protected function resolveCartBranchId(array $data, int $fallback = 0): int
    {
        $branchId = (int)($data['branch_id'] ?? 0);
        if ($branchId > 0) {
            return $branchId;
        }

        if ($fallback > 0) {
            return $fallback;
        }

        return (int)($_SESSION['branch_id'] ?? 0);
    }

    protected function resolveCartExcludeInvoiceId(array $data): ?int
    {
        $id = (int)($data['exclude_invoice_id'] ?? 0);

        return $id > 0 ? $id : null;
    }
}

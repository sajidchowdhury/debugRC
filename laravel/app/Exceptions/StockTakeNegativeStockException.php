<?php

namespace App\Exceptions;

/**
 * Stock Take Negative Stock Exception — Phase 1.
 *
 * Thrown by StockTakeService::postSession() when the pre-check detects that
 * applying one or more shortage variances would drive warehouse_stock.qty
 * below zero (which would trigger the prevent_negative_stock() DB constraint
 * with a generic check_violation error).
 *
 * This exception carries the full list of offending products so the
 * controller can render a user-friendly 422 response with a product table
 * (product code, name, system qty, physical qty, current stock, shortage,
 * resulting qty) instead of a raw PostgreSQL error.
 *
 * The pre-check runs BEFORE any stock movement is applied, so the session
 * remains in its pre-post state (counting/draft) when this is thrown.
 */
class StockTakeNegativeStockException extends \RuntimeException
{
    /** @var array<int, array<string, mixed>> */
    private array $offendingProducts;

    /**
     * @param array<int, array<string, mixed>> $offendingProducts Each element:
     *   product_id, product_code, product_name, warehouse_id, system_qty,
     *   physical_qty, current_stock, shortage, resulting_qty
     */
    public function __construct(array $offendingProducts)
    {
        $this->offendingProducts = $offendingProducts;

        $count = count($offendingProducts);
        $first = $offendingProducts[0] ?? [];
        $more = $count > 1 ? ' (and ' . ($count - 1) . ' more)' : '';

        $firstDetail = '';
        if (!empty($first)) {
            $firstDetail = sprintf(
                ' First: %s (%s) — current stock %s, shortage %s would result in %s.',
                $first['product_code'] ?? '?',
                $first['product_name'] ?? '?',
                $first['current_stock'] ?? 0,
                $first['shortage'] ?? 0,
                $first['resulting_qty'] ?? 0
            );
        }

        parent::__construct(
            "Cannot post: {$count} product(s) would go negative.{$firstDetail}{$more} "
            . 'Receive stock or correct the count before posting.'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOffendingProducts(): array
    {
        return $this->offendingProducts;
    }
}

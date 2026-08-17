<?php

namespace Tests\Helpers;

use Illuminate\Support\Facades\DB;

/**
 * Product Phase 9 test helpers — direct table inserts for product-specific
 * dependencies that have NOT NULL columns + FK constraints the factory can't
 * easily satisfy.
 *
 * Used by:
 *  - tests/Unit/Product/ProductDeactivationUnitTest
 *  - tests/Feature/Product/ProductCrudTest
 *  - tests/Feature/Product/ProductAuditTest
 *  - tests/Feature/Product/ProductValidationTest
 */
trait InsertsProductDependencies
{
    use ResolvesActiveFiscalYear;
    /**
     * Insert a product_categories row with the minimum required columns.
     * Returns the category id.
     */
    protected function insertProductCategory(string $name = null, bool $isActive = true): int
    {
        $name = $name ?? 'Cat-' . substr(uniqid(), -6);

        return DB::table('product_categories')->insertGetId([
            'category_name' => $name,
            'description'   => 'Test category',
            'is_active'     => $isActive,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    /**
     * Insert a product_groups row with the minimum required columns.
     * Returns the group id.
     */
    protected function insertProductGroup(string $name = null, bool $isActive = true): int
    {
        $name = $name ?? 'Grp-' . substr(uniqid(), -6);

        return DB::table('product_groups')->insertGetId([
            'group_name' => $name,
            'description' => 'Test group',
            'sort_order' => 0,
            'is_active'  => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Insert a warehouse_stock row (qty > 0) for a product to simulate
     * existing stock-on-hand that should block deactivation.
     *
     * Requires a warehouse to exist (caller must create one).
     */
    protected function insertProductStock(int $warehouseId, int $productId, float $qty): void
    {
        DB::table('warehouse_stock')->insert([
            'warehouse_id' => $warehouseId,
            'product_id'   => $productId,
            'qty'          => $qty,
            'avg_cost'     => 10.00,
            'total_qty'    => $qty,
            'total_value'  => $qty * 10.00,
            'updated_at'   => now(),
        ]);
    }

    /**
     * Insert a sales_invoice_items row referencing a product.
     *
     * Builds the minimum chain: customer → sales_invoice → item.
     * Returns the sales_invoice_item id.
     */
    protected function insertSalesInvoiceItem(int $productId, int $branchId, string $invoiceStatus = 'confirmed'): int
    {
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUST-PI-' . substr(uniqid(), -6),
            'customer_name' => 'Product Item Customer',
            'branch_id'     => $branchId,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $invoiceId = DB::table('sales_invoices')->insertGetId([
            'invoice_code' => 'INV-PI-' . substr(uniqid(), -6),
            'invoice_date' => now()->toDateString(),
            'customer_id'  => $customerId,
            'branch_id'    => $branchId,
            'status'       => $invoiceStatus,
            'is_reversed'  => false,
            'fiscal_year_id' => $this->resolveActiveFiscalYearId(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return DB::table('sales_invoice_items')->insertGetId([
            'sales_invoice_id' => $invoiceId,
            'product_id'       => $productId,
            'qty'              => 1,
            'rate'             => 10.00,
            'condition_state'  => 'Good',
            'fiscal_year_id'  => $this->resolveActiveFiscalYearId(),
        ]);
    }

    /**
     * Insert a purchase_order_items row referencing a product.
     *
     * Builds the minimum chain: supplier → purchase_order → item.
     * Returns the purchase_order_item id.
     */
    protected function insertPurchaseOrderItem(int $productId, int $branchId, string $poStatus = 'draft'): int
    {
        $supplierId = DB::table('suppliers')->insertGetId([
            'supplier_code' => 'SUP-PI-' . substr(uniqid(), -6),
            'supplier_name' => 'Product PO Supplier',
            'branch_id'     => $branchId,
            'is_active'     => true,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $poId = DB::table('purchase_orders')->insertGetId([
            'po_code'    => 'PO-PI-' . substr(uniqid(), -6),
            'po_date'    => now()->toDateString(),
            'supplier_id' => $supplierId,
            'branch_id'  => $branchId,
            'status'     => $poStatus,
            'fiscal_year_id' => $this->resolveActiveFiscalYearId(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $poId,
            'product_id'        => $productId,
            'qty'               => 1,
            'received_qty'      => 0,
            'rate'              => 10.00,
            'fiscal_year_id'  => $this->resolveActiveFiscalYearId(),
        ]);
    }
}

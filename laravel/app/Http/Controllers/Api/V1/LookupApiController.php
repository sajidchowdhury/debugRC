<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Ledger;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 13 — lookup data API for mobile app dropdowns.
 *
 * Returns slim id+label records for active master-data entities so mobile
 * clients can populate pickers without pulling full records.
 *
 * Endpoints:
 *   GET /api/v1/lookups/branches    active branches (id + code + name)
 *   GET /api/v1/lookups/warehouses  warehouses (?branch_id=X filter)
 *   GET /api/v1/lookups/products    active products (id + code + name + price)
 *   GET /api/v1/lookups/customers   active customers (id + code + name)
 *   GET /api/v1/lookups/suppliers   active suppliers (id + code + name)
 *   GET /api/v1/lookups/ledgers     active ledgers (id + code + name + type)
 *
 * All endpoints require a Bearer token (ApiAuth middleware).
 */
class LookupApiController extends Controller
{
    public function branches(): JsonResponse
    {
        $rows = Branch::active()
            ->orderBy('branch_name')
            ->get(['id', 'branch_code', 'branch_name']);

        return response()->json(['data' => $rows]);
    }

    public function warehouses(Request $request): JsonResponse
    {
        $branchId = $request->integer('branch_id');

        $query = Warehouse::active()->orderBy('warehouse_name');

        if ($branchId > 0) {
            $query->where('branch_id', $branchId);
        }

        $rows = $query->get(['id', 'warehouse_code', 'warehouse_name', 'branch_id']);

        return response()->json(['data' => $rows]);
    }

    public function products(): JsonResponse
    {
        $rows = Product::active()
            ->orderBy('product_name')
            ->get(['id', 'product_code', 'product_name', 'unit', 'sales_rate']);

        return response()->json(['data' => $rows]);
    }

    public function customers(): JsonResponse
    {
        $rows = Customer::active()
            ->orderBy('customer_name')
            ->get(['id', 'customer_code', 'customer_name', 'mobile']);

        return response()->json(['data' => $rows]);
    }

    public function suppliers(): JsonResponse
    {
        $rows = Supplier::active()
            ->orderBy('supplier_name')
            ->get(['id', 'supplier_code', 'supplier_name', 'mobile']);

        return response()->json(['data' => $rows]);
    }

    public function ledgers(): JsonResponse
    {
        $rows = Ledger::active()
            ->orderBy('ledger_code')
            ->get(['id', 'ledger_code', 'ledger_name', 'account_type', 'ledger_nature']);

        return response()->json(['data' => $rows]);
    }
}

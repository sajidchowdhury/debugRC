<?php

namespace App\Http\Requests\Api\V1\WarehouseTransfer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Get pipeline-aware stock availability for a product at a
 * warehouse.
 *
 * Extracted from WarehouseTransferApiController::productStock() inline
 * validate() as part of MEDIUM-WAVE-2-C (G-208 / api-conventions.md G14).
 *
 * This is a GET endpoint whose inputs come from query string parameters
 * (?product_id= & ?warehouse_id=). FormRequest works identically for query
 * params + JSON bodies — Laravel's `$this->input(...)` reads from both.
 *
 * Authorization is handled by the outer `api.auth` route middleware (the
 * endpoint is read-only — no role restriction beyond "any authenticated API
 * user"). The FormRequest's authorize() returns true.
 */
class ProductStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Auth handled by api.auth route middleware.
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id'   => 'required|integer|exists:products,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
        ];
    }

    public function queryParameters(): array
    {
        return [
            'product_id' => [
                'description' => 'Product whose stock to query',
                'example'     => 10,
            ],
            'warehouse_id' => [
                'description' => 'Warehouse at which to check stock',
                'example'     => 2,
            ],
        ];
    }
}

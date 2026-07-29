<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 10 — Web Form Request for issuing a challan.
 *
 * Promotes the inline $request->validate() that used to live in
 * SalesChallanController::issueChallan into a typed Form Request.
 *
 * Rules:
 *   transport_name      — nullable|string|max:100
 *   transport_phone     — nullable|string|max:30
 *   vehicle_number      — nullable|string|max:50
 *   driver_name         — nullable|string|max:100
 *   transport_cost      — nullable|numeric|min:0
 *   notes               — nullable|string|max:500
 *   idempotency_token   — required|string|uuid (R3: idempotency — mirrors
 *                         the finalize / payment cache-key conventions.
 *                         A replay within 10 min redirects to the
 *                         originally-issued challan instead of throwing.)
 *
 * The branch-ownership + stock-availability checks for the warehouse
 * assignments were ALREADY enforced at godown prep (PrepareGodownWebRequest,
 * Phase 10). The issue endpoint does NOT re-accept warehouse_assignments —
 * the warehouse is locked at godown prep and read from the persisted
 * sales_invoice_items at issue time. So no WarehouseBelongsToBranch /
 * WarehouseHasStock rules are needed here.
 *
 * The invoice's godown-prepared state is verified in the controller's
 * challanForm (the GET) and the issueChallan service method (the POST).
 */
class IssueChallanWebRequest extends FormRequest
{
    public function authorize(): bool
    {
        // RBAC is handled by the route middleware (role:warehouse_manager,
        // dispatcher, manager, admin). Branch access for the invoice is
        // enforced by EnforceBranchIsolation + the SalesAccess service.
        return true;
    }

    public function rules(): array
    {
        return [
            'transport_name'    => ['nullable', 'string', 'max:100'],
            'transport_phone'   => ['nullable', 'string', 'max:30'],
            'vehicle_number'    => ['nullable', 'string', 'max:50'],
            'driver_name'       => ['nullable', 'string', 'max:100'],
            'transport_cost'    => ['nullable', 'numeric', 'min:0'],
            'notes'             => ['nullable', 'string', 'max:500'],
            'idempotency_token' => ['required', 'string', 'uuid'],
        ];
    }

    public function attributes(): array
    {
        return [
            'transport_name'    => 'transport name',
            'transport_phone'   => 'transport phone',
            'vehicle_number'    => 'vehicle number',
            'driver_name'       => 'driver name',
            'transport_cost'    => 'transport cost',
            'notes'             => 'notes',
            'idempotency_token' => 'idempotency token',
        ];
    }
}

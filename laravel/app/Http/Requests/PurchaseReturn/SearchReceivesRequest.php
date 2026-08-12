<?php

namespace App\Http\Requests\PurchaseReturn;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PURCHASING-API-1 (G-122) — Form Request for the AJAX searchReceives endpoint.
 *
 * Replaces the inline `$request->validate([...])` that was the lone outlier
 * in the Purchase module's write/read endpoints (every other Purchase endpoint
 * already used a dedicated FormRequest class — see Phase 7 refactor).
 *
 * Used by the Return create workspace's GRN typeahead. Returns a list of
 * confirmed non-reversed GRNs with at least one returnable item, matching
 * `$term` against receive_code or supplier_name. Branch-scoped for non-admins
 * (the controller resolves branch_id separately).
 *
 * Validation rules mirror the original inline rules exactly:
 *   - term: nullable|string|max:100
 *
 * Authorisation is deferred to route middleware (admin/manager/warehouse_manager
 * per routes/web.php:1020-1021) — same pattern as every other Purchase
 * FormRequest.
 */
class SearchReceivesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC handled by route middleware (admin, manager, warehouse_manager)
    }

    public function rules(): array
    {
        return [
            'term'      => 'nullable|string|max:100',
            'branch_id' => 'nullable|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'term.max'           => 'Search term must be 100 characters or fewer.',
            'term.string'        => 'Search term must be a string.',
            'branch_id.integer'  => 'Branch filter must be an integer ID.',
            'branch_id.min'      => 'Branch ID must be positive.',
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1\StockTake;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 11 — Create a stock-take session via the API.
 *
 * Mirrors the web controller's store() validation (StockTakeController@store).
 * The service (StockTakeService::createSession) does the deeper validation
 * (warehouse existence, scope-payload shape, freeze-outbound overlap check).
 */
class StoreSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_id'              => 'required|integer|exists:branches,id',
            'session_date'           => 'required|date',
            'warehouse_ids'          => 'required|array|min:1',
            'warehouse_ids.*'        => 'integer|exists:warehouses,id',
            'notes'                  => 'nullable|string|max:1000',
            'freeze_outbound'        => 'sometimes|boolean',
            'count_scope'            => 'sometimes|string|in:full,category,abc,group,ad_hoc,negative_only,zero_only',
            'count_scope_payload'    => 'nullable|array',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'branch_id'       => ['description' => 'Branch the session belongs to (RLS-scoped).', 'example' => 1],
            'session_date'    => ['description' => 'Count date (Y-m-d).', 'example' => '2025-08-15'],
            'warehouse_ids'   => ['description' => 'Warehouses to count (at least one).', 'example' => [1, 2]],
            'freeze_outbound' => ['description' => 'Lock outbound movements while counting. Default false.', 'example' => false],
            'count_scope'     => ['description' => 'Cycle-count scope. Default "full".', 'example' => 'full'],
        ];
    }
}

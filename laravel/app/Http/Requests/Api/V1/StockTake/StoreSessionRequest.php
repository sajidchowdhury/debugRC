<?php

namespace App\Http\Requests\Api\V1\StockTake;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Phase 11 — Create a stock-take session via the API.
 *
 * Mirrors the web controller's store() validation (StockTakeController@store).
 * The service (StockTakeService::createSession) does the deeper validation
 * (warehouse existence, scope-payload shape, freeze-outbound overlap check).
 *
 * Idempotency (PURCHASING-API-4, G7 Medium-risk): the client SHOULD
 * send an `idempotency_token` (UUID). If present, a retry within 5 min
 * returns the cached result instead of creating a duplicate draft
 * session. The field is `sometimes` (not `required`) for backward-compat
 * with deployed mobile clients. See api-conventions.md §11.1.
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
            'idempotency_token'      => 'sometimes|string|uuid',
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
            'idempotency_token' => ['description' => 'Client-generated UUID; if present, retries within 5 min return the cached result (PURCHASING-API-4)', 'example' => '550e8400-e29b-41d4-a716-446655440000'],
        ];
    }
}

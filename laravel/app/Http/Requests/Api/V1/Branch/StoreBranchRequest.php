<?php

namespace App\Http\Requests\Api\V1\Branch;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mobile API — Create a new branch (admin only).
 *
 * Extracted from BranchApiController::store() inline validate() to
 * centralize the contract (mirrors the Sales FormRequest convention).
 * Auth + role enforcement stays on the route middleware (api.auth:admin);
 * authorize() returns true because the middleware already gates writes.
 */
class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'branch_code' => 'required|string|max:20|regex:/^[A-Za-z0-9\-_.]+$/|unique:branches,branch_code',
            'branch_name' => 'required|string|max:100',
            'address'     => 'nullable|string',
            'phone'       => 'nullable|string|max:20',
            'email'       => 'nullable|email|max:100',
            'is_active'   => 'boolean',
        ];
    }

    public function bodyParameters(): array
    {
        return [
            'branch_code' => ['description' => 'Unique uppercase code (A-Z 0-9 - _ .)', 'example' => 'HO'],
            'branch_name' => ['description' => 'Human-readable branch name', 'example' => 'Head Office'],
            'address'     => ['description' => 'Street address', 'example' => '123 Main St, Dhaka'],
            'phone'       => ['description' => 'Contact phone', 'example' => '01712345678'],
            'email'       => ['description' => 'Contact email', 'example' => 'branch@example.com'],
            'is_active'   => ['description' => 'Whether the branch is operational', 'example' => true],
        ];
    }
}

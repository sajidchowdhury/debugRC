<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\SortsLists;
use App\Http\Resources\Api\V1\Branch\BranchResource;
use App\Http\Requests\Api\V1\Branch\StoreBranchRequest;
use App\Http\Requests\Api\V1\Branch\UpdateBranchRequest;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 13 — RESTful API for branches (mobile/AI sidecar).
 *
 * All endpoints require a Bearer token (ApiAuth middleware).
 * Write endpoints (POST/PUT/DELETE) additionally require an admin role
 * (set on the route via ->middleware('api.auth:admin')).
 *
 * Endpoints:
 *   GET    /api/v1/branches           paginated list + search
 *   GET    /api/v1/branches/{id}       single branch
 *   POST   /api/v1/branches            create
 *   PUT    /api/v1/branches/{id}       update
 *   DELETE /api/v1/branches/{id}       deactivate (soft-delete)
 */
class BranchApiController extends Controller
{
    use SortsLists;

    /**
     * List branches with pagination + optional search.
     *
     * Query params:
     *   ?search=   search term (matches branch_code or branch_name)
     *             — ?q= is accepted as a deprecated alias (G-193).
     *   ?page=     page number (default 1)
     *   ?per_page= page size (default 25, max 100)
     *   ?sort=     sort field (G-196). Whitelist:
     *              id, branch_code, branch_name, is_active, created_at.
     *              Unknown values silently fall back to the default (id).
     *   ?order=    asc|desc (G-196). Default: desc.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));
        // G-193 (MEDIUM): standardized on `search` (the majority param name
        // across all paginated API endpoints). `q` is kept as a backward-compat
        // alias for one release so existing mobile clients don't break.
        $search  = trim((string) $request->input('search', $request->input('q', '')));

        $query = Branch::query()->whereNull('deleted_at');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('branch_code', 'ILIKE', "%{$search}%")
                  ->orWhere('branch_name', 'ILIKE', "%{$search}%");
            });
        }

        // G-196 (MEDIUM): sort convention — ?sort=field&order=asc|desc with a
        // per-endpoint whitelist. Default `id desc` preserves the prior
        // behavior (single orderBy on id). See api-conventions.md §8.5.
        $query = $this->applySort(
            $query,
            ['id', 'branch_code', 'branch_name', 'is_active', 'created_at'],
            'id',
            'desc',
        );

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => BranchResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
        // G-189 (MEDIUM): pagination `meta` standardized across all paginated
        // API endpoints to {current_page, last_page, per_page, total}. The
        // non-portable `from`/`to` (firstItem/lastItem) keys were removed so
        // every list endpoint returns an identical shape — see api-conventions.md.
    }

    /**
     * Show one branch (including soft-deleted, for admin visibility).
     */
    public function show(int $id): JsonResponse
    {
        $branch = Branch::withTrashed()->find($id);

        if ($branch === null) {
            return $this->notFound("Branch {$id} not found.");
        }

        return response()->json(['data' => new BranchResource($branch)]);
    }

    /**
     * Create a new branch.
     */
    public function store(StoreBranchRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Normalize like BranchController: uppercase code, trim text fields.
        $validated['branch_code'] = strtoupper(trim($validated['branch_code']));
        $validated['branch_name'] = trim($validated['branch_name']);
        foreach (['phone', 'email', 'address'] as $f) {
            if (isset($validated[$f])) {
                $validated[$f] = trim($validated[$f]);
            }
        }

        if ($request->user()) {
            $validated['created_by'] = $request->user()->id;
        }

        try {
            $branch = Branch::create($validated);

            return response()->json([
                'data'    => new BranchResource($branch->fresh()),
                'message' => 'Branch created.',
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create branch.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update an existing branch.
     */
    public function update(UpdateBranchRequest $request, int $id): JsonResponse
    {
        $branch = Branch::find($id);

        if ($branch === null) {
            return $this->notFound("Branch {$id} not found.");
        }

        $validated = $request->validated();

        if (isset($validated['branch_code'])) {
            $validated['branch_code'] = strtoupper(trim($validated['branch_code']));
        }
        if (isset($validated['branch_name'])) {
            $validated['branch_name'] = trim($validated['branch_name']);
        }
        foreach (['phone', 'email', 'address'] as $f) {
            if (isset($validated[$f])) {
                $validated[$f] = trim($validated[$f]);
            }
        }

        try {
            $branch->update($validated);

            return response()->json([
                'data'    => new BranchResource($branch->fresh()),
                'message' => 'Branch updated.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to update branch.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Deactivate (soft-delete) a branch.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $branch = Branch::find($id);

        if ($branch === null) {
            return $this->notFound("Branch {$id} not found.");
        }

        // Reuse the same deactivation safety check as the web controller
        // by querying dependencies directly. We DON'T inject the
        // BranchController because that returns redirects — instead we
        // inline the same blockers logic here, JSON-style.
        $blockers = $this->collectDeactivationBlockers($branch);

        if ($blockers !== []) {
            return response()->json([
                'message'  => 'Cannot deactivate branch — outstanding dependencies.',
                'blockers' => $blockers,
            ], 400);
        }

        try {
            $branch->is_active = false;
            $branch->deleted_by = $request->user()?->id;
            $branch->save();
            $branch->delete();

            return response()->json([
                'message' => 'Branch deactivated.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to deactivate branch.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Inline the deactivation blockers from BranchController::canDeactivate()
     * so the API returns structured JSON instead of a redirect-with-error.
     *
     * @return array<int,string>
     */
    private function collectDeactivationBlockers(Branch $branch): array
    {
        $id     = $branch->id;
        $parts  = [];

        $warehouses = DB::table('warehouses')->where('branch_id', $id)->where('is_active', true)->count();
        $employees  = DB::table('employees')->where('branch_id', $id)->where('is_active', true)->count();
        $openInvoices = DB::table('sales_invoices')
            ->where('branch_id', $id)
            ->where('is_reversed', false)
            ->whereNotIn('status', ['cancelled', 'reversed'])
            ->count();

        if ($warehouses > 0)    $parts[] = "{$warehouses} active warehouse(s)";
        if ($employees > 0)     $parts[] = "{$employees} active employee(s)";
        if ($openInvoices > 0)  $parts[] = "{$openInvoices} open sales invoice(s)";

        return $parts;
    }

    private function notFound(string $detail): JsonResponse
    {
        return response()->json([
            'message' => 'Not Found.',
            'detail'  => $detail,
        ], 404);
    }
}

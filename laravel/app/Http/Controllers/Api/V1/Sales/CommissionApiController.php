<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Sales\CommissionEntryResource;
use App\Http\Resources\Api\V1\Sales\CommissionRuleResource;
use App\Http\Requests\Api\V1\Sales\StoreCommissionRuleRequest;
use App\Services\Sales\CommissionService;
use App\Services\Sales\SalesAccess;
use App\Models\CommissionRule;
use App\Models\CommissionEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Commission API Controller — Task 37.
 *
 * REST API endpoints for managing salesman commission rules and entries.
 * Supports:
 *   - Rule CRUD (create, list, show, update, deactivate)
 *   - Entry listing and per-salesman/period summaries
 *   - Period confirmation (month-end batch)
 *   - Branch summary reports
 *
 * All endpoints require Bearer token authentication and branch isolation.
 */
class CommissionApiController extends Controller
{
    public function __construct(
        private CommissionService $commissionService,
        private SalesAccess $salesAccess
    ) {}

    // ===================================================================
    // COMMISSION RULES
    // ===================================================================

    /**
     * List commission rules (with optional filtering).
     *
     * GET /api/v1/sales/commission/rules
     */
    public function listRules(Request $request): JsonResponse
    {
        $query = CommissionRule::with(['salesman', 'branch', 'tiers', 'productGroups.productGroup', 'targets']);

        if ($request->has('salesman_id')) {
            $query->where('salesman_id', $request->integer('salesman_id'));
        }

        if ($request->has('rule_type')) {
            $query->where('rule_type', $request->input('rule_type'));
        }

        if ($request->boolean('active_only')) {
            $query->active();
        }

        if ($request->has('branch_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('branch_id', $request->integer('branch_id'))
                    ->orWhereNull('branch_id');
            });
        }

        // G4 fix: clamp per_page to [1,100] like BranchApiController::index,
        // preventing an unbounded per_page from OOM-ing the serializer.
        $perPage = min(100, max(1, $request->integer('per_page', 25)));

        $rules = $query->orderBy('effective_from', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => CommissionRuleResource::collection($rules->items()),
            'meta' => [
                'current_page' => $rules->currentPage(),
                'last_page' => $rules->lastPage(),
                'per_page' => $rules->perPage(),
                'total' => $rules->total(),
            ],
        ]);
    }

    /**
     * Show a single commission rule with full details.
     *
     * GET /api/v1/sales/commission/rules/{id}
     */
    public function showRule(int $id): JsonResponse
    {
        $rule = CommissionRule::with([
            'salesman', 'branch', 'tiers', 'productGroups.productGroup', 'targets'
        ])->findOrFail($id);

        return response()->json([
            'data' => new CommissionRuleResource($rule),
        ]);
    }

    /**
     * Create a new commission rule.
     *
     * POST /api/v1/sales/commission/rules
     */
    public function storeRule(StoreCommissionRuleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $rule = $this->commissionService->createRule($validated);

        return response()->json([
            'data' => new CommissionRuleResource($rule->load(['salesman', 'tiers', 'productGroups', 'targets'])),
            'message' => 'Commission rule created successfully',
        ], 201);
    }

    /**
     * Deactivate a commission rule (set effective_to = today).
     *
     * POST /api/v1/sales/commission/rules/{id}/deactivate
     */
    public function deactivateRule(int $id): JsonResponse
    {
        $rule = CommissionRule::findOrFail($id);
        $rule->update([
            'effective_to' => now()->toDateString(),
            'is_active' => false,
        ]);

        return response()->json([
            'message' => 'Commission rule deactivated successfully',
        ]);
    }

    // ===================================================================
    // COMMISSION ENTRIES
    // ===================================================================

    /**
     * List commission entries (with optional filtering).
     *
     * GET /api/v1/sales/commission/entries
     */
    public function listEntries(Request $request): JsonResponse
    {
        $query = CommissionEntry::with(['salesman', 'salesInvoice', 'commissionRule', 'allocation', 'salesReturn']);

        if ($request->has('salesman_id')) {
            $query->where('salesman_id', $request->integer('salesman_id'));
        }

        if ($request->has('period')) {
            $query->where('commission_period', $request->input('period'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('from_date')) {
            $query->where('entry_date', '>=', $request->input('from_date'));
        }

        if ($request->has('to_date')) {
            $query->where('entry_date', '<=', $request->input('to_date'));
        }

        // G4 fix: clamp per_page to [1,100] (same as listRules).
        $perPage = min(100, max(1, $request->integer('per_page', 25)));

        $entries = $query->orderBy('entry_date', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => CommissionEntryResource::collection($entries->items()),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page' => $entries->lastPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
            ],
        ]);
    }

    // ===================================================================
    // SUMMARIES & REPORTS
    // ===================================================================

    /**
     * Get commission summary for a specific salesman and period.
     *
     * GET /api/v1/sales/commission/salesman-summary
     */
    public function salesmanSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'salesman_id' => 'required|integer|exists:employees,id',
            'period' => 'required|string|regex:/^\d{4}-\d{2}$/',
        ]);

        $summary = $this->commissionService->getSalesmanSummary(
            $validated['salesman_id'],
            $validated['period']
        );

        return response()->json(['data' => $summary]);
    }

    /**
     * Get commission summary for all salesmen in a branch/period.
     *
     * GET /api/v1/sales/commission/branch-summary
     */
    public function branchSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => 'required|string|regex:/^\d{4}-\d{2}$/',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $summary = $this->commissionService->getBranchSummary(
            $validated['branch_id'] ?? null,
            $validated['period']
        );

        return response()->json(['data' => $summary]);
    }

    // ===================================================================
    // CONFIRMATION
    // ===================================================================

    /**
     * Confirm all calculated commission entries for a period.
     *
     * POST /api/v1/sales/commission/confirm-period
     */
    public function confirmPeriod(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => 'required|string|regex:/^\d{4}-\d{2}$/',
        ]);

        $result = $this->commissionService->confirmPeriod(
            $validated['period'],
            auth()->id()
        );

        return response()->json([
            'data' => $result,
            'message' => $result['confirmed_count'] > 0
                ? "Confirmed {$result['confirmed_count']} commission entries totaling {$result['total_amount']}"
                : 'No pending commission entries found for this period',
        ]);
    }

}

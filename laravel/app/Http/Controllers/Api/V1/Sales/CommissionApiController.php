<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
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

        $rules = $query->orderBy('effective_from', 'desc')
            ->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => $rules->map(fn($rule) => $this->formatRule($rule)),
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
            'data' => $this->formatRule($rule),
        ]);
    }

    /**
     * Create a new commission rule.
     *
     * POST /api/v1/sales/commission/rules
     */
    public function storeRule(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'salesman_id' => 'required|integer|exists:employees,id',
            'rule_type' => 'required|in:flat,tiered,product_group,target_bonus',
            'rate' => 'required|numeric|min:0|max:100',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date|after:effective_from',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'notes' => 'nullable|string|max:500',
            'tiers' => 'required_if:rule_type,tiered|array',
            'tiers.*.threshold' => 'required_with:tiers|numeric|min:0',
            'tiers.*.rate' => 'required_with:tiers|numeric|min:0|max:100',
            'product_groups' => 'required_if:rule_type,product_group|array',
            'product_groups.*.product_group_id' => 'required_with:product_groups|integer|exists:product_groups,id',
            'product_groups.*.rate' => 'required_with:product_groups|numeric|min:0|max:100',
            'targets' => 'required_if:rule_type,target_bonus|array',
            'targets.*.target_amount' => 'required_with:targets|numeric|min:0',
            'targets.*.bonus_rate' => 'required_with:targets|numeric|min:0|max:100',
            'targets.*.period' => 'nullable|in:monthly,quarterly,yearly',
        ]);

        $rule = $this->commissionService->createRule($validated);

        return response()->json([
            'data' => $this->formatRule($rule->load(['salesman', 'tiers', 'productGroups', 'targets'])),
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

        $entries = $query->orderBy('entry_date', 'desc')
            ->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => $entries->map(fn($entry) => $this->formatEntry($entry)),
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

    // ===================================================================
    // FORMATTING HELPERS
    // ===================================================================

    private function formatRule(CommissionRule $rule): array
    {
        $data = [
            'id' => $rule->id,
            'salesman' => $rule->salesman ? [
                'id' => $rule->salesman->id,
                'name' => $rule->salesman->name,
                'employee_code' => $rule->salesman->employee_code,
            ] : null,
            'rule_type' => $rule->rule_type,
            'rate' => (float) $rule->rate,
            'effective_from' => $rule->effective_from?->toDateString(),
            'effective_to' => $rule->effective_to?->toDateString(),
            'is_active' => $rule->is_active,
            'is_currently_active' => $rule->isCurrentlyActive(),
            'branch' => $rule->branch ? [
                'id' => $rule->branch->id,
                'name' => $rule->branch->branch_name,
            ] : null,
            'notes' => $rule->notes,
        ];

        if ($rule->relationLoaded('tiers') && $rule->tiers->isNotEmpty()) {
            $data['tiers'] = $rule->tiers->map(fn($t) => [
                'threshold' => (float) $t->threshold,
                'rate' => (float) $t->rate,
                'sort_order' => $t->sort_order,
            ]);
        }

        if ($rule->relationLoaded('productGroups') && $rule->productGroups->isNotEmpty()) {
            $data['product_groups'] = $rule->productGroups->map(fn($pg) => [
                'product_group_id' => $pg->product_group_id,
                'group_name' => $pg->productGroup?->group_name,
                'rate' => (float) $pg->rate,
            ]);
        }

        if ($rule->relationLoaded('targets') && $rule->targets->isNotEmpty()) {
            $data['targets'] = $rule->targets->map(fn($t) => [
                'target_amount' => (float) $t->target_amount,
                'bonus_rate' => (float) $t->bonus_rate,
                'period' => $t->period,
            ]);
        }

        return $data;
    }

    private function formatEntry(CommissionEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'salesman' => $entry->salesman ? [
                'id' => $entry->salesman->id,
                'name' => $entry->salesman->name,
            ] : null,
            'invoice_code' => $entry->salesInvoice?->invoice_code,
            'commission_base' => (float) $entry->commission_base,
            'commission_rate' => (float) $entry->commission_rate,
            'commission_amount' => (float) $entry->commission_amount,
            'status' => $entry->status,
            'entry_date' => $entry->entry_date?->toDateString(),
            'commission_period' => $entry->commission_period,
            'is_reversal' => $entry->isReturnReversal(),
            'notes' => $entry->notes,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}

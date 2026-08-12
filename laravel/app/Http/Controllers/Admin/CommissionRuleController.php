<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sales\StoreCommissionRuleRequest;
use App\Services\Sales\CommissionService;
use App\Models\CommissionRule;
use App\Models\Employee;
use App\Models\Branch;
use App\Models\ProductGroup;
use Illuminate\Http\Request;

/**
 * Commission Rule Controller — HIGH-WAVE-2 (G-164 / G12).
 *
 * Browser-based UI for commission rule management. Mirrors the API endpoints
 * in App\Http\Controllers\Api\V1\Sales\CommissionApiController but renders
 * Blade views instead of JSON.
 *
 * RBAC: `role:admin,manager` middleware on the route group. Mirrors the
 * API's `api.auth:admin` for writes (createRule / deactivateRule) +
 * `api.auth:manager,admin` for reads (listRules / showRule). The store
 * action reuses the existing StoreCommissionRuleRequest FormRequest (same
 * validation as the API) + calls CommissionService::createRule() (same
 * business logic as the API — no duplication).
 *
 * Why this controller exists: G12 (MAJOR) — commission rules could ONLY
 * be created/deactivated via the API (admin bearer token). An accountant
 * or manager without API access couldn't configure commission rules.
 */
class CommissionRuleController extends Controller
{
    public function __construct(
        private CommissionService $commissionService
    ) {}

    /**
     * List commission rules with optional filtering — mirrors
     * CommissionApiController::listRules but renders a Blade view with
     * paginated rules + filter dropdowns.
     */
    public function index(Request $request)
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

        // Clamp per_page to [1,100] like the API (G4 fix).
        $perPage = min(100, max(1, $request->integer('per_page', 25)));

        $rules = $query->orderBy('effective_from', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        // Filter dropdowns.
        $salesmen = Employee::salesmen()->orderBy('name')->pluck('name', 'id');
        $branches = Branch::orderBy('branch_name')->pluck('branch_name', 'id');

        $title = 'Commission Rules';

        return view('admin.commission-rules.index', compact('rules', 'salesmen', 'branches', 'title'));
    }

    /**
     * Show the create-rule form. Loads salesmen + branches + product groups
     * for the dropdowns + JS-driven conditional sections.
     */
    public function create()
    {
        $salesmen = Employee::salesmen()->orderBy('name')->pluck('name', 'id');
        $branches = Branch::orderBy('branch_name')->pluck('branch_name', 'id');
        $productGroups = ProductGroup::orderBy('group_name')->pluck('group_name', 'id');

        $title = 'Create Commission Rule';

        return view('admin.commission-rules.create', compact('salesmen', 'branches', 'productGroups', 'title'));
    }

    /**
     * Store a new commission rule. Reuses StoreCommissionRuleRequest (same
     * validation as the API) + CommissionService::createRule (same business
     * logic as the API).
     */
    public function store(StoreCommissionRuleRequest $request)
    {
        $validated = $request->validated();
        $validated['created_by'] = auth()->id();

        $rule = $this->commissionService->createRule($validated);

        return redirect()
            ->route('admin.commission-rules.show', $rule)
            ->with('success', "Commission rule #{$rule->id} created successfully.");
    }

    /**
     * Show a single commission rule with full details (tiers, product
     * groups, targets, recent entries).
     */
    public function show(int $id)
    {
        $rule = CommissionRule::with([
            'salesman', 'branch',
            'tiers', 'productGroups.productGroup', 'targets',
            'entries' => function ($q) {
                $q->orderBy('entry_date', 'desc')->limit(20);
            },
        ])->findOrFail($id);

        $title = "Commission Rule #{$rule->id}";

        return view('admin.commission-rules.show', compact('rule', 'title'));
    }

    /**
     * Deactivate a commission rule (set effective_to = today, is_active
     * = false). Mirrors CommissionApiController::deactivateRule.
     */
    public function deactivate(int $id)
    {
        $rule = CommissionRule::findOrFail($id);
        $rule->update([
            'effective_to' => now()->toDateString(),
            'is_active' => false,
        ]);

        return redirect()
            ->route('admin.commission-rules.index')
            ->with('success', "Commission rule #{$rule->id} deactivated successfully.");
    }
}

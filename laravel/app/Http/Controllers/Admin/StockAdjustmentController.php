<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StockAdjustment;
use App\Models\Warehouse;
use App\Services\Stock\StockAdjustmentPolicyService;
use App\Services\Stock\StockAdjustmentService;
use App\Services\Stock\StockService;
use App\Services\Stock\UomConversionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stock Adjustment Controller — Phase 6.3.
 *
 * Two-phase flow:
 *   - create() / store(): create a draft adjustment (no stock movement, no GL)
 *   - confirm(): apply stock + post GL (status → confirmed)
 *   - cancel(): reverse stock + GL if confirmed, or just mark draft as cancelled
 *
 * Also provides:
 *   - index(): searchable/filterable list
 *   - show(): single adjustment detail with items + stock movements + GL journal
 *   - audit(): health-check report
 *
 * Phase 1 (Stock Adjustment plan — Authorization & Role Enforcement):
 *   - Role gating is enforced by `role:` middleware on the route groups
 *     (read: admin/manager/accountant; write: admin/accountant).
 *   - This controller adds defense-in-depth via $this->authorize() against
 *     StockAdjustmentPolicy on show/confirm/cancel/submit/approve/reject.
 *   - getProductRate() validates the requested warehouse belongs to the
 *     caller's session branch (G16 fix — warehouse_stock has no RLS because
 *     it has no branch_id column, so the endpoint itself must guard).
 *   - branch.isolation middleware on POST {id}/confirm|cancel|submit|approve|reject
 *     resolves {id} → stock_adjustments.branch_id (EnforceBranchIsolation::inferTableFromUri).
 *   - PostgreSQL RLS on stock_adjustments is the DB-level backstop.
 *
 * Phase 3 (Stock Adjustment plan — Approval Workflow & Maker-Checker):
 *   - submit/approve/reject endpoints added; the service enforces the
 *     lifecycle transitions and segregation of duties (approver ≠ submitter).
 *   - confirm() now passes confirm_reason to the service (G9 — was discarded).
 *   - The show view receives a `canApprove` / `requiresApproval` / `isSubmitter`
 *     flag set from the policy so the action buttons render correctly.
 */
class StockAdjustmentController extends Controller
{
    public function __construct(
        private StockAdjustmentService $adjustmentService,
        private StockService $stockService,
        private StockAdjustmentPolicyService $policy,
        private UomConversionService $uom  // Phase 5 — UOM dropdown data
    ) {}

    /**
     * List stock adjustments with filters.
     *
     * Phase 2: also accepts an `adjustment_category` filter (one of the
     * seven canonical categories) so the index page can be sliced by
     * opening_balance / data_migration / uom_correction / etc.
     * Phase 3: stats now include submitted + approved counts for the new
     * approval-workflow states.
     */
    public function index(Request $request)
    {
        $query = StockAdjustment::with(['warehouse.branch', 'items'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('adjustment_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('adjustment_date', '<=', $d))
            ->when($request->input('warehouse_id'), fn($q, $wid) => $q->where('warehouse_id', $wid))
            ->when($request->input('adjustment_type'), fn($q, $t) => $q->where('adjustment_type', $t))
            ->when($request->input('adjustment_category'), function ($q, $c) {
                // Only apply if the value is a known category (defensive —
                // never let an arbitrary string reach the WHERE clause).
                if (in_array($c, StockAdjustment::ADJUSTMENT_CATEGORIES, true)) {
                    $q->where('adjustment_category', $c);
                }
            })
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->orderBy('adjustment_date', 'desc')
            ->orderBy('id', 'desc');

        $adjustments = $query->paginate(25);

        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        // Phase 3 — include the new approval-workflow states in the stats.
        $stats = [
            'total'     => StockAdjustment::count(),
            'draft'     => StockAdjustment::where('status', 'draft')->count(),
            'submitted' => StockAdjustment::where('status', 'submitted')->count(),
            'approved'  => StockAdjustment::where('status', 'approved')->count(),
            'confirmed' => StockAdjustment::where('status', 'confirmed')->count(),
            'cancelled' => StockAdjustment::where('status', 'cancelled')->count(),
            'total_value' => StockAdjustment::where('status', 'confirmed')->sum('total_amount'),
        ];

        return view('admin.stock-adjustments.index', [
            'title' => 'Stock Adjustments',
            'adjustments' => $adjustments,
            'warehouses' => $warehouses,
            'branches' => $branches,
            'stats' => $stats,
            'categories' => StockAdjustment::ADJUSTMENT_CATEGORIES,
            'categoryLabels' => StockAdjustment::CATEGORY_LABELS,
            'statuses' => StockAdjustment::STATUSES,
            'statusLabels' => StockAdjustment::STATUS_LABELS,
            'filters' => $request->only([
                'from_date', 'to_date', 'warehouse_id', 'adjustment_type',
                'adjustment_category', 'status', 'branch_id',
            ]),
        ]);
    }

    /**
     * Show the create form.
     *
     * Phase 3: passes an approval-policy hint so the form can show the
     * "below Tk X can be confirmed in one step" guidance.
     */
    public function create()
    {
        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $products = \App\Models\Product::active()->orderBy('product_name')->limit(500)->get();

        return view('admin.stock-adjustments.create', [
            'title' => 'New Stock Adjustment',
            'warehouses' => $warehouses,
            'products' => $products,
            'categories' => StockAdjustment::ADJUSTMENT_CATEGORIES,
            'categoryLabels' => StockAdjustment::CATEGORY_LABELS,
            'approvalHint' => $this->policy->approvalHint(),
        ]);
    }

    /**
     * Store a new draft adjustment.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'adjustment_type' => 'required|in:increase,decrease',
            // Phase 2 — category is mandatory and must be a known value.
            'adjustment_category' => 'required|in:' . implode(',', StockAdjustment::ADJUSTMENT_CATEGORIES),
            'adjustment_date' => 'required|date',
            'reason' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty' => 'required|numeric|min:0.001',
            // Phase 5 — optional UOM the qty was entered in. When absent,
            // the service treats the qty as already in the base unit.
            'items.*.uom_id' => 'nullable|integer|exists:units_of_measure,id',
            'items.*.rate' => 'nullable|numeric|min:0',
            'items.*.reason' => 'nullable|string|max:500',
        ]);

        // Phase 1: defense-in-depth — role check for creating a draft.
        // (No model yet, so the Policy's create(User) method is used.)
        $this->authorize('create', StockAdjustment::class);

        try {
            $adjustment = $this->adjustmentService->createAdjustment([
                'warehouse_id' => $validated['warehouse_id'],
                'adjustment_type' => $validated['adjustment_type'],
                'adjustment_category' => $validated['adjustment_category'], // Phase 2
                'adjustment_date' => $validated['adjustment_date'],
                'reason' => $validated['reason'] ?? '',
                'items' => $validated['items'],
                'created_by' => auth()->id(),
            ]);

            return redirect()->route('admin.stock-adjustments.show', $adjustment)
                ->with('success', "Draft adjustment {$adjustment->adjustment_code} created. Review and confirm to apply.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Show adjustment details.
     *
     * Phase 3: passes approval-policy flags to the view so the action
     * buttons (Submit / Approve / Reject / Confirm) render correctly for
     * the current user and the adjustment's lifecycle state.
     */
    public function show(int $id)
    {
        $adjustment = StockAdjustment::with([
            'items.product', 'items.uom',  // Phase 5 — eager-load UOM for the items table
            'warehouse.branch', 'branch', 'journalEntry.lines.ledger',
            'submittedBy', 'approvedBy', 'confirmedBy',
            'auditLogs.actor',  // Phase 4 — audit timeline (RLS-scoped by branch)
        ])->findOrFail($id);

        // Phase 1: defense-in-depth — role + branch check via Policy.
        // (role: middleware already gated the route; RLS already filtered the
        // row. This re-confirms the same rule at the controller layer so the
        // intent is explicit and survives any future route loosening.)
        $this->authorize('view', $adjustment);

        // Get the stock transactions created by this adjustment.
        //
        // Phase 2 (G17 fix): opening-balance adjustments write their stock
        // movements with reference_type='opening_balance' rather than
        // 'stock_adjustment' (see StockAdjustmentService::confirmAdjustment).
        // Match both reference_types so the show page lists every movement
        // regardless of the adjustment's category.
        $stockTransactions = DB::table('stock_transactions as st')
            ->join('products as p', 'p.id', '=', 'st.product_id')
            ->whereIn('st.reference_type', ['stock_adjustment', 'opening_balance'])
            ->where('st.reference_id', $id)
            ->select('st.*', 'p.product_code', 'p.product_name')
            ->orderBy('st.id')
            ->get();

        // Phase 3 — policy flags for the action aside. show() has no
        // Request binding (the method signature is show(int $id)), so the
        // current user is resolved via the auth helper.
        $user = auth()->user();
        $requiresApproval = $this->policy->requiresApproval($adjustment);

        return view('admin.stock-adjustments.show', [
            'title' => 'Adjustment ' . $adjustment->adjustment_code,
            'adjustment' => $adjustment,
            'stockTransactions' => $stockTransactions,
            // Phase 4 — the audit timeline (chronological, RLS-scoped by branch).
            'auditLogs' => $adjustment->auditLogs,
            // Policy flags for the action buttons.
            'requiresApproval' => $requiresApproval,
            'canSubmit'  => $user ? $this->policy->canSubmit($user) : false,
            'canApprove' => $user ? $this->policy->canApprove($user) : false,
            'canConfirm' => $user ? $this->policy->canConfirm($user) : false,
            // Phase 6.1 — can the viewer force-confirm a decrease past the
            // pipeline-availability check? (admin only; surfaces the force
            // checkbox + force_reason textarea in the confirm Swal.)
            'canForceConfirm' => $user ? $this->policy->canForceConfirm($user) : false,
            'isSubmitter' => $user ? $this->policy->isSubmitter($user, $adjustment) : false,
        ]);
    }

    /**
     * Confirm an adjustment (apply stock + post GL).
     *
     * Phase 3: now passes confirm_reason to the service (G9 — was discarded).
     * The service enforces the approved-state requirement (or draft when
     * !requiresApproval).
     *
     * Phase 6.1: accepts an optional `force` flag + `force_reason` for
     * decrease adjustments that would dip below pipeline-available stock.
     * The service re-validates admin role + requires a non-empty reason
     * (defense-in-depth — the Policy is checked here, the service re-checks).
     */
    public function confirm(Request $request, int $id)
    {
        $request->validate([
            'confirm_reason' => 'nullable|string|max:500',
            // Phase 6.1 — force bypass of the pipeline-availability check.
            'force' => 'nullable|boolean',
            'force_reason' => 'nullable|string|max:1000',
        ]);

        // Phase 1: load first so the Policy can check role + branch.
        $adjustment = StockAdjustment::findOrFail($id);
        $this->authorize('confirm', $adjustment);

        $force = (bool) $request->input('force', false);
        $forceReason = $request->input('force_reason');

        // Phase 6.1 — defense-in-depth: if force is requested, the caller
        // must be an admin (the service re-checks, but surfacing the error
        // here gives a cleaner 403 than the service's RuntimeException).
        if ($force) {
            $user = $request->user();
            if (!$user || !$this->policy->canForceConfirm($user)) {
                return back()->with('error', 'Only an admin can force-confirm a decrease past the pipeline-availability check.');
            }
        }

        try {
            $adjustment = $this->adjustmentService->confirmAdjustment(
                $id,
                auth()->id(),
                $request->input('confirm_reason'),
                $force,
                $forceReason
            );

            $msg = $force
                ? "Adjustment {$adjustment->adjustment_code} force-confirmed (pipeline check bypassed). Stock updated + GL posted."
                : "Adjustment {$adjustment->adjustment_code} confirmed. Stock updated + GL posted.";

            return redirect()->route('admin.stock-adjustments.show', $adjustment)
                ->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Phase 3 — Submit a draft adjustment for approval (draft → submitted).
     * If the adjustment does not require approval (below auto-approve
     * threshold), the service auto-advances it to 'approved' inline.
     */
    public function submit(Request $request, int $id)
    {
        $request->validate([
            'comment' => 'nullable|string|max:1000',
        ]);

        $adjustment = StockAdjustment::findOrFail($id);
        $this->authorize('submit', $adjustment);

        try {
            $adjustment = $this->adjustmentService->submitAdjustment(
                $id,
                auth()->id(),
                $request->input('comment')
            );

            $msg = $adjustment->isApproved()
                ? "Adjustment {$adjustment->adjustment_code} auto-approved (below threshold) — ready to confirm."
                : "Adjustment {$adjustment->adjustment_code} submitted for approval.";

            return redirect()->route('admin.stock-adjustments.show', $adjustment)
                ->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Phase 3 — Approve a submitted adjustment (submitted → approved).
     * Only admin/manager (route middleware + Policy). The service enforces
     * segregation of duties (approver ≠ submitter).
     */
    public function approve(Request $request, int $id)
    {
        $request->validate([
            'comment' => 'required|string|max:1000',
        ]);

        $adjustment = StockAdjustment::findOrFail($id);
        $this->authorize('approve', $adjustment);

        try {
            $adjustment = $this->adjustmentService->approveAdjustment(
                $id,
                auth()->id(),
                $request->input('comment')
            );

            return redirect()->route('admin.stock-adjustments.show', $adjustment)
                ->with('success', "Adjustment {$adjustment->adjustment_code} approved — ready to confirm.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Phase 3 — Reject a submitted adjustment (submitted → draft).
     * Only admin/manager (route middleware + Policy). The adjustment returns
     * to draft with the rejection reason appended to approval_comments.
     */
    public function reject(Request $request, int $id)
    {
        $request->validate([
            'comment' => 'required|string|max:1000',
        ]);

        $adjustment = StockAdjustment::findOrFail($id);
        $this->authorize('reject', $adjustment);

        try {
            $adjustment = $this->adjustmentService->rejectAdjustment(
                $id,
                auth()->id(),
                $request->input('comment')
            );

            return redirect()->route('admin.stock-adjustments.show', $adjustment)
                ->with('success', "Adjustment {$adjustment->adjustment_code} rejected — returned to draft.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Cancel an adjustment (reverse stock + GL if confirmed).
     */
    public function cancel(Request $request, int $id)
    {
        $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        // Phase 1: load first so the Policy can check role + branch.
        $adjustment = StockAdjustment::findOrFail($id);
        $this->authorize('cancel', $adjustment);

        try {
            $adjustment = $this->adjustmentService->cancelAdjustment(
                $id,
                auth()->id(),
                $request->input('cancel_reason')
            );

            return redirect()->route('admin.stock-adjustments.show', $adjustment)
                ->with('success', "Adjustment {$adjustment->adjustment_code} cancelled.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * AJAX: get product avg cost for a warehouse (for the create form).
     *
     * Phase 1 (G16 fix): warehouse_stock has NO branch_id column and therefore
     * NO RLS policy. A non-admin could otherwise query any warehouse's stock
     * by passing an arbitrary warehouse_id. Here we explicitly assert the
     * requested warehouse belongs to the caller's session branch before
     * returning data. Admins bypass (their cross-branch access is logged by
     * EnforceBranchIsolation on the surrounding request context).
     */
    public function getProductRate(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
        ]);

        $warehouseId = (int) $request->input('warehouse_id');
        $productId = (int) $request->input('product_id');

        // Phase 1 (G16): branch-validate the warehouse for non-admin users.
        $user = $request->user();
        if ($user && !$user->isAdmin()) {
            $sessionBranchId = (int) (session('branch_id') ?? $user->getBranchId() ?? 0);
            $warehouseBranchId = (int) (Warehouse::find($warehouseId)?->branch_id ?? 0);
            if ($sessionBranchId <= 0 || $warehouseBranchId !== $sessionBranchId) {
                abort(403, 'You do not have access to stock for a warehouse outside your branch.');
            }
        }

        $rate = $this->stockService->getWarehouseAvgCost($warehouseId, $productId);

        $qty = $this->stockService->getWarehouseQty($warehouseId, $productId);

        return response()->json([
            'rate' => round($rate, 2),
            'available_qty' => round($qty, 4),
        ]);
    }

    /**
     * Phase 5 (UOM) — AJAX: get the available UOMs + conversion factors for a
     * product, for the create-form per-row UOM dropdown.
     *
     * Returns the base unit (factor 1, is_base=true) plus any
     * product_uom_conversions rows whose to_uom = the product's base unit.
     * The JS on the create form uses the `factor` to recompute the live
     * "Base qty" read-only display = qty_entered × factor.
     *
     * Same branch-validation as getProductRate is NOT needed here (this
     * endpoint returns no stock/financial data — only UOM metadata which is
     * global config). The product_id existence is validated by the rule below.
     */
    public function getProductUoms(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
        ]);

        $productId = (int) $request->input('product_id');

        $uoms = $this->uom->getProductUoms($productId);

        return response()->json([
            'product_id' => $productId,
            'uoms' => $uoms,
        ]);
    }

    /**
     * Audit checklist — health checks for all adjustments.
     */
    public function audit()
    {
        // Phase 1: defense-in-depth role check (route already gated by
        // role:admin,manager,accountant). The Policy's audit() method is a
        // plain role check (no model binding).
        $this->authorize('audit', StockAdjustment::class);

        $checks = $this->computeAuditChecks();

        return view('admin.stock-adjustments.audit', [
            'title' => 'Stock Adjustment Audit',
            'checks' => $checks,
        ]);
    }

    /**
     * Compute audit health checks.
     */
    private function computeAuditChecks(): array
    {
        $checks = [];

        // 1. Confirmed adjustments without GL journal.
        $missingGl = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM stock_adjustments
WHERE status = 'confirmed' AND journal_entry_id IS NULL
SQL);
        $checks[] = [
            'label' => 'Confirmed adjustments without GL journal entry',
            'count' => (int) $missingGl->cnt,
            'status' => $missingGl->cnt == 0 ? 'pass' : 'fail',
        ];

        // 2. Confirmed adjustments with unbalanced GL (should be 0 — DB trigger enforces).
        $unbalanced = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM (
    SELECT je.id, SUM(jl.debit) AS d, SUM(jl.credit) AS c
    FROM journal_entries je
    JOIN journal_lines jl ON jl.journal_entry_id = je.id
    WHERE je.reference_type = 'stock_adjustment'
    GROUP BY je.id
    HAVING SUM(jl.debit) <> SUM(jl.credit)
) x
SQL);
        $checks[] = [
            'label' => 'Unbalanced stock-adjustment journal entries',
            'count' => (int) $unbalanced->cnt,
            'status' => $unbalanced->cnt == 0 ? 'pass' : 'fail',
        ];

        // 3. Confirmed adjustments without stock transactions.
        //
        // Phase 2 (G17 fix): opening-balance adjustments write their stock
        // movements with reference_type='opening_balance'. A confirmed
        // adjustment is healthy if it has AT LEAST ONE stock_transactions row
        // under EITHER reference_type.
        $missingStock = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM stock_adjustments sa
WHERE sa.status = 'confirmed'
  AND NOT EXISTS (
    SELECT 1 FROM stock_transactions st
    WHERE st.reference_id = sa.id
      AND st.reference_type IN ('stock_adjustment','opening_balance')
  )
SQL);
        $checks[] = [
            'label' => 'Confirmed adjustments without stock transactions',
            'count' => (int) $missingStock->cnt,
            'status' => $missingStock->cnt == 0 ? 'pass' : 'fail',
        ];

        // 4. Stale drafts (>7 days old).
        $staleDrafts = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM stock_adjustments
WHERE status = 'draft' AND adjustment_date < (CURRENT_DATE - INTERVAL '7 days')
SQL);
        $checks[] = [
            'label' => 'Stale draft adjustments (>7 days old)',
            'count' => (int) $staleDrafts->cnt,
            'status' => $staleDrafts->cnt == 0 ? 'pass' : 'warn',
        ];

        return $checks;
    }
}

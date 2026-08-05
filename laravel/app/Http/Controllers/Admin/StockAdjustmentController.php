<?php

namespace App\Http\Controllers\Admin;

use App\Facades\CsvExporter;
use App\Http\Controllers\Concerns\WritesExportAuditLog;
use App\Http\Controllers\Controller;
use App\Models\StockAdjustment;
use App\Models\Warehouse;
use App\Services\Stock\StockAdjustmentAuditLogger;
use App\Services\Stock\StockAdjustmentAuditService;
use App\Services\Stock\StockAdjustmentPolicyService;
use App\Services\Stock\StockAdjustmentReconcileService;
use App\Services\Stock\StockAdjustmentService;
use App\Services\Stock\StockService;
use App\Services\Stock\UomConversionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

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
    use WritesExportAuditLog;

    public function __construct(
        private StockAdjustmentService $adjustmentService,
        private StockService $stockService,
        private StockAdjustmentPolicyService $policy,
        private UomConversionService $uom,  // Phase 5 — UOM dropdown data
        private StockAdjustmentReconcileService $reconcile,  // Phase 7 — drift detection
        private StockAdjustmentAuditService $auditService,   // Phase 8 — 7-section checklist
        private StockAdjustmentAuditLogger $auditLogger      // Phase 8 — logs 'print' action
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

        // Phase 8.5 — the reversing JE (if a confirmed adjustment was later
        // cancelled). journal_entries.reversal_of_entry_id links the reversal
        // to the original; we look it up directly (no Eloquent relation).
        // Shown on the show page as a second GL audit block alongside the
        // original, so an auditor sees both the posting and its rollback.
        $reversingJe = null;
        if ($adjustment->journal_entry_id) {
            $reversingJe = DB::table('journal_entries as je')
                ->leftJoin('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
                ->leftJoin('ledgers as l', 'l.id', '=', 'jl.ledger_id')
                ->where('je.reversal_of_entry_id', $adjustment->journal_entry_id)
                ->select('je.*', 'jl.debit', 'jl.credit', 'jl.memo', 'l.ledger_name', 'l.ledger_code')
                ->orderBy('je.id')
                ->orderBy('jl.id')
                ->get();
        }

        // Phase 3 — policy flags for the action aside. show() has no
        // Request binding (the method signature is show(int $id)), so the
        // current user is resolved via the auth helper.
        $user = auth()->user();
        $requiresApproval = $this->policy->requiresApproval($adjustment);

        return view('admin.stock-adjustments.show', [
            'title' => 'Adjustment ' . $adjustment->adjustment_code,
            'adjustment' => $adjustment,
            'stockTransactions' => $stockTransactions,
            // Phase 8.5 — reversing JE rows for the GL audit block (null when
            // the adjustment was never confirmed, or never cancelled).
            'reversingJe' => $reversingJe,
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
     * Audit checklist — Phase 8 alias. The flat 4-check `audit()` screen is
     * superseded by the rich 7-section `checklist()` below; this route is
     * kept (named `admin.stock-adjustments.audit`) for backward compat and
     * redirects to the canonical checklist route. Any old bookmarks / links
     * keep working.
     */
    public function audit()
    {
        $this->authorize('audit', StockAdjustment::class);
        return redirect()->route('admin.stock-adjustments.checklist');
    }

    /**
     * Phase 8 — 7-section integrity checklist (G4).
     *
     * Renders the sectioned health-check report backed by
     * StockAdjustmentAuditService. Branch-scoped for non-admins (RLS already
     * enforces this at the DB level; the service-level branch filter is
     * defense-in-depth + lets the view show the scope). The view is AJAX-free
     * — checks are computed server-side on each request (the "Re-run checks"
     * button just hits the route again). This is fine because the checklist
     * is an infrequent, accountant-driven tool and the queries are all
     * COUNT-first with lazy sample rows.
     */
    public function checklist()
    {
        $this->authorize('audit', StockAdjustment::class);

        $userBranchId = $this->getUserBranchId();
        $report = $this->auditService->runChecks($userBranchId);

        return view('admin.stock-adjustments.checklist', [
            'title'       => 'Stock Adjustment Checklist',
            'sections'    => $report['sections'],
            'summary'     => $report['summary'],
            'userBranchId' => $userBranchId,
        ]);
    }

    // ========================================================================
    // Phase 8 — UI Parity: CSV Export, Print Voucher
    // ========================================================================

    /**
     * Phase 8.1 (G2) — CSV export of the filtered adjustment list.
     *
     * Mirrors WarehouseTransferController::export(): same filter params as
     * index(), branch isolation (admin sees all; non-admin branch-locked),
     * cursor()-based streaming (memory-safe for large exports), BOM-prefixed
     * UTF-8 for Excel compatibility.
     *
     * Columns: Date, Code, Warehouse, Branch, Category, Type, Items, Total,
     * Status, Submitted/Approved/Confirmed by + at, Reversed?.
     *
     * REPORTS-AUDIT-4 (G-150 / csv-export.md G11): refactored to delegate
     * to CsvExporter::exportFromRows(). The prior docblock stated "No
     * audit-log row is written" — that exemption is removed: this export
     * now writes an export_audit_log row via the WritesExportAuditLog
     * trait (G-132 closure). The legacy per-record StockAdjustmentAuditLogger
     * vocabulary is unaffected (this is a bulk-list export, distinct from
     * the per-record stock_adjustment_id audit trail). Column order and
     * column labels preserved exactly.
     */
    public function export(Request $request)
    {
        $this->authorize('audit', StockAdjustment::class);

        $userBranchId = $this->getUserBranchId();

        $query = StockAdjustment::with(['warehouse.branch', 'items', 'submittedBy', 'approvedBy', 'confirmedBy'])
            ->when($userBranchId, fn ($q) => $q->where('branch_id', $userBranchId))
            ->when($request->input('from_date'), fn ($q, $d) => $q->where('adjustment_date', '>=', $d))
            ->when($request->input('to_date'), fn ($q, $d) => $q->where('adjustment_date', '<=', $d))
            ->when($request->input('warehouse_id'), fn ($q, $wid) => $q->where('warehouse_id', $wid))
            ->when($request->input('adjustment_type'), fn ($q, $t) => $q->where('adjustment_type', $t))
            ->when($request->input('adjustment_category'), function ($q, $c) {
                if (in_array($c, StockAdjustment::ADJUSTMENT_CATEGORIES, true)) {
                    $q->where('adjustment_category', $c);
                }
            })
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), fn ($q, $s) => $q->where('adjustment_code', 'ILIKE', "%{$s}%"))
            ->orderBy('adjustment_date', 'desc')
            ->orderBy('id', 'desc');

        $adjustments = $query->cursor();

        $categoryLabels = StockAdjustment::CATEGORY_LABELS;
        $statusLabels   = StockAdjustment::STATUS_LABELS;

        $headerRow = [
            'Date',
            'Code',
            'Warehouse',
            'Branch',
            'Category',
            'Type',
            'Items',
            'Total',
            'Status',
            'Submitted By',
            'Submitted At',
            'Approved By',
            'Approved At',
            'Confirmed By',
            'Confirmed At',
            'Reversed',
        ];

        $rowGenerator = $this->buildAdjustmentCsvRows($adjustments, $categoryLabels, $statusLabels);

        $filename = 'StockAdjustments_' . now()->format('Y-m-d_His');

        // Audit log: row count unknown (cursor() stream — we do not
        // pre-count). Pass 0; the audit row records that an export
        // happened, with the filter context.
        $this->logExport('stock_adjustments', [
            'branch_id' => $userBranchId,
            'from_date' => $request->input('from_date'),
            'to_date' => $request->input('to_date'),
            'warehouse_id' => $request->input('warehouse_id'),
            'adjustment_type' => $request->input('adjustment_type'),
            'adjustment_category' => $request->input('adjustment_category'),
            'status' => $request->input('status'),
            'search' => $request->input('search'),
        ], rowCount: 0, byteSize: 0);

        return CsvExporter::exportFromRows($filename, $headerRow, $rowGenerator);
    }

    /**
     * Build the row generator for the stock-adjustment CSV export.
     *
     * Extracted as a private method so the lint checker can validate the
     * export() method body (the linter cannot parse `yield` inside an
     * inline closure expression).
     *
     * @param  iterable<int, StockAdjustment> $adjustments
     * @param  array<string,string> $categoryLabels
     * @param  array<string,string> $statusLabels
     * @return \Generator<int, array<int,mixed>>
     */
    private function buildAdjustmentCsvRows(iterable $adjustments, array $categoryLabels, array $statusLabels): \Generator
    {
        $fmtDate = function ($d): string {
            return $d ? \Carbon\Carbon::parse($d)->format('d-m-Y') : '';
        };
        $fmtUser = function ($u): string {
            return $u ? ($u->username ?? $u->name ?? ('user #' . $u->id)) : '';
        };

        foreach ($adjustments as $a) {
            yield [
                $fmtDate($a->adjustment_date),
                $a->adjustment_code,
                $a->warehouse?->warehouse_name ?? '',
                $a->warehouse?->branch?->branch_name ?? '',
                $categoryLabels[$a->adjustment_category] ?? $a->adjustment_category,
                $a->adjustment_type,
                $a->items->count(),
                number_format((float) $a->total_amount, 2, '.', ''),
                $statusLabels[$a->status] ?? $a->status,
                $fmtUser($a->submittedBy),
                $fmtDate($a->submitted_at),
                $fmtUser($a->approvedBy),
                $fmtDate($a->approved_at),
                $fmtUser($a->confirmedBy),
                $fmtDate($a->confirmed_at),
                $a->is_reversed ? 'Yes' : 'No',
            ];
        }
    }

    /**
     * Phase 8.4 (G18) — Print voucher for a single adjustment.
     *
     * Renders a print-friendly voucher (print.blade.php) with: code, date,
     * warehouse+branch, category, type, reason, items table (product,
     * qty_entered+UOM, qty_base, rate, amount), totals, GL summary (JE# +
     * Dr/Cr lines), and a lifecycle signatures line (Prepared by / Approved
     * by / Posted by). A 'print' audit action is logged so the audit
     * timeline shows every time a voucher was printed (G4 forensic trail).
     *
     * Also eager-loads the reversing JE (if the adjustment was cancelled
     * after confirm) so the voucher can show both the original + reversing
     * GL blocks.
     */
    public function print(int $id)
    {
        $adjustment = StockAdjustment::with([
            'items.product', 'items.uom',
            'warehouse.branch', 'branch',
            'journalEntry.lines.ledger',
            'submittedBy', 'approvedBy', 'confirmedBy', 'createdBy',
        ])->findOrFail($id);

        $this->authorize('view', $adjustment);

        // The reversing JE (if the confirmed adjustment was later cancelled).
        // journal_entries.reversal_of_entry_id links the reversal to the
        // original; we look it up directly (no Eloquent relation on the model).
        $reversingJe = null;
        if ($adjustment->journal_entry_id) {
            $reversingJe = DB::table('journal_entries as je')
                ->leftJoin('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
                ->leftJoin('ledgers as l', 'l.id', '=', 'jl.ledger_id')
                ->where('je.reversal_of_entry_id', $adjustment->journal_entry_id)
                ->select('je.*', 'jl.debit', 'jl.credit', 'jl.memo', 'l.ledger_name', 'l.ledger_code')
                ->orderBy('je.id')
                ->orderBy('jl.id')
                ->get();
        }

        // Phase 8 — log the 'print' action (forensic: the audit timeline
        // shows every voucher print, with the actor + timestamp). Wrapped in
        // a try/catch so a logging failure never blocks the print (the data
        // is the source of truth; the audit row is the forensic record).
        try {
            $this->auditLogger->log($adjustment, 'print');
        } catch (\Throwable $e) {
            Log::warning('StockAdjustment print audit-log failed', [
                'adjustment_id' => $adjustment->id,
                'error' => $e->getMessage(),
            ]);
        }

        return view('admin.stock-adjustments.print', [
            'title' => 'Print ' . $adjustment->adjustment_code,
            'adjustment' => $adjustment,
            'reversingJe' => $reversingJe,
        ]);
    }

    // ========================================================================
    // Phase 7 — Reconciliation, Drift Detection & Data-Hygiene
    // ------------------------------------------------------------------------
    // Reconcile view + AJAX refresh + admin-only snapshot rebuild. The
    // reconcile page is module-agnostic (it checks the warehouse_stock ↔
    // stock_transactions invariant for the whole tenant) but is surfaced
    // under Stock Adjustment because that module is the bookkeeping-
    // correction tool — "find + fix stock drift" is an accountant job.
    // ========================================================================

    /**
     * Reconciliation page — renders the view; data is fetched via AJAX so a
     * slow drift query never blocks the page load. Branch-scoped for non-
     * admins (RLS already enforces this at the DB level; the service-level
     * branch filter is defense-in-depth + lets the view show the scope).
     */
    public function reconcile()
    {
        $this->authorize('audit', StockAdjustment::class);

        $userBranchId = $this->getUserBranchId();
        $warehouses = Warehouse::active()
            ->when($userBranchId, fn($q) => $q->where('branch_id', $userBranchId))
            ->orderBy('warehouse_name')
            ->get();

        return view('admin.stock-adjustments.reconcile', [
            'title' => 'Stock Reconciliation',
            'userBranchId' => $userBranchId,
            'warehouses' => $warehouses,
        ]);
    }

    /**
     * AJAX: run the drift computation and return JSON. Accepts an optional
     * warehouse_id scope (for the per-warehouse filter on the view). Non-
     * admins are branch-locked: a forged warehouse_id from another branch
     * is rejected by the service's branch filter (the warehouse doesn't
     * belong to their branch, so it returns no rows for it).
     */
    public function runReconcile(Request $request)
    {
        $this->authorize('audit', StockAdjustment::class);

        $validated = $request->validate([
            'warehouse_id' => 'nullable|integer|min:1',
        ]);

        $userBranchId = $this->getUserBranchId();
        $warehouseId = $validated['warehouse_id'] ?? null;

        try {
            $result = $this->reconcile->computeDrift($userBranchId, $warehouseId);
            return response()->json($result);
        } catch (\Throwable $e) {
            Log::error('StockAdjustment reconcile failed', [
                'error' => $e->getMessage(),
                'branch_id' => $userBranchId,
                'warehouse_id' => $warehouseId,
            ]);
            return response()->json([
                'error' => 'Failed to run reconciliation: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * AJAX (POST): rebuild the warehouse_stock snapshot from the ledger for
     * a given warehouse (or all warehouses when warehouse_id is null).
     *
     * ADMIN-ONLY — enforced by the route's role:admin middleware + a defense-
     * in-depth isAdmin() check here. The rebuild is destructive (DELETE +
     * INSERT) so it must not be reachable by an accountant, even though
     * accountants can VIEW the drift. Rebuilding is a maintenance op.
     */
    public function rebuildSnapshot(Request $request)
    {
        $this->authorize('audit', StockAdjustment::class);

        /** @var \App\Models\User $user */
        $user = auth()->user();
        if (!$user || !$user->isAdmin()) {
            return response()->json([
                'error' => 'Only administrators may rebuild the stock snapshot.',
            ], 403);
        }

        $validated = $request->validate([
            'warehouse_id' => 'nullable|integer|min:1',
        ]);

        $warehouseId = $validated['warehouse_id'] ?? null;

        try {
            $result = $this->reconcile->rebuildSnapshot($warehouseId);
            return response()->json([
                'success' => true,
                'rebuilt' => $result['rebuilt'],
                'message' => sprintf(
                    'Snapshot rebuilt for %s — %d row(s) recomputed from the ledger.',
                    $warehouseId ? "warehouse #{$warehouseId}" : 'all warehouses',
                    $result['rebuilt']
                ),
            ]);
        } catch (\Throwable $e) {
            Log::error('StockAdjustment rebuildSnapshot failed', [
                'error' => $e->getMessage(),
                'warehouse_id' => $warehouseId,
            ]);
            return response()->json([
                'error' => 'Failed to rebuild snapshot: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Resolve the current user's branch id (null for admins who see all).
     * Mirrors WarehouseTransferController::getUserBranchId.
     */
    private function getUserBranchId(): ?int
    {
        /** @var \App\Models\User|null $user */
        $user = auth()->user();
        if ($user && $user->isAdmin()) {
            return null;
        }
        return $user ? (int) (session('branch_id') ?? $user->getBranchId() ?? 0) : 0;
    }
}

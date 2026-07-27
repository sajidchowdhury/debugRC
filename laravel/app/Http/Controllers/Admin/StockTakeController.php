<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Exceptions\StockTakeNegativeStockException;
use App\Exceptions\WarehouseFrozenForCountException;
use App\Models\StockTakeAuditLog;
use App\Models\StockTakeSession;
use App\Models\User;
use App\Services\Stock\StockTakeHealthCheckService;
use App\Services\Stock\StockTakePolicyService;
use App\Services\Stock\StockTakeService;
use App\Services\Stock\AbcClassificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stock Take Controller — Phase 6.4.
 *
 * Workflow:
 *   - index: list sessions
 *   - create / store: create a new session (draft) with selected warehouses
 *   - show: session detail with warehouses + items + variance + GL + audit timeline
 *   - setupCounts: load products for a warehouse (AJAX or route)
 *   - count: enter physical counts for a warehouse
 *   - saveCounts: save the counts (AJAX or POST)
 *   - post: apply variances + post GL
 *   - cancel: reverse if posted, or just mark cancelled
 *   - checklist: Phase 2 global health-check screen (port of legacy StockTake/checklist)
 *   - audit: Phase 2 global audit-log screen (filterable by date/actor/action)
 */
class StockTakeController extends Controller
{
    public function __construct(
        private StockTakeService $stockTakeService,
        private StockTakeHealthCheckService $healthCheckService,
        private StockTakePolicyService $policyService,
        private AbcClassificationService $abcService
    ) {}

    public function index(Request $request)
    {
        $query = StockTakeSession::with(['branch', 'warehouses.warehouse'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('session_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('session_date', '<=', $d))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('session_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('session_date', 'desc')
            ->orderBy('id', 'desc');

        $sessions = $query->paginate(25);
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        $stats = [
            'total' => StockTakeSession::count(),
            'draft' => StockTakeSession::where('status', 'draft')->count(),
            'counting' => StockTakeSession::where('status', 'counting')->count(),
            // Phase 4: approval-workflow status counts.
            'submitted' => StockTakeSession::where('status', 'submitted')->count(),
            'approved' => StockTakeSession::where('status', 'approved')->count(),
            'posted' => StockTakeSession::where('status', 'posted')->count(),
            'cancelled' => StockTakeSession::where('status', 'cancelled')->count(),
        ];

        return view('admin.stock-take.index', [
            'title' => 'Stock Take Sessions',
            'sessions' => $sessions,
            'branches' => $branches,
            'stats' => $stats,
            'filters' => $request->only(['from_date', 'to_date', 'status', 'branch_id', 'search']),
        ]);
    }

    public function create()
    {
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();

        // Phase 5: cycle-count scope data for the create-form wizard.
        //   - categories + groups: multi-selects for the category/group scopes.
        //   - abc summary: per-class counts so the user can see how many
        //     products each ABC class contains before choosing the abc scope.
        //     Aggregated across all warehouses (null warehouseId) for the
        //     summary card; the per-warehouse breakdown is fetched live by the
        //     scope-preview AJAX endpoint when a warehouse is selected.
        $categories = DB::table('product_categories')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('category_name')
            ->select('id', 'category_name')
            ->get();
        $groups = DB::table('product_groups')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('group_name')
            ->select('id', 'group_name')
            ->get();
        $abcSummary = $this->abcService->getSummary(null);

        return view('admin.stock-take.create', [
            'title' => 'New Stock Take Session',
            'branches' => $branches,
            'warehouses' => $warehouses,
            'categories' => $categories,
            'groups' => $groups,
            'abcSummary' => $abcSummary,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|integer|exists:branches,id',
            'session_date' => 'required|date',
            'warehouse_ids' => 'required|array|min:1',
            'warehouse_ids.*' => 'integer|exists:warehouses,id',
            'notes' => 'nullable|string|max:1000',
            // Phase 3: optional outbound freeze. When on, the covered
            // warehouses are locked against outbound movements while the
            // session is active. Default off (backward compatible; use for
            // full annual counts, leave off for cycle counts).
            'freeze_outbound' => 'sometimes|boolean',
            // Phase 5: cycle-count scope. The payload is validated per-scope
            // by StockTakeService::validateCountScope (which also checks that
            // category/group/product ids exist + are active). Here we only
            // validate the scope string + the payload's array shape.
            'count_scope' => 'sometimes|string|in:full,category,abc,group,ad_hoc,negative_only,zero_only',
            'count_scope_payload' => 'nullable|array',
        ]);

        try {
            $session = $this->stockTakeService->createSession([
                'branch_id' => $validated['branch_id'],
                'session_date' => $validated['session_date'],
                'warehouse_ids' => $validated['warehouse_ids'],
                'notes' => $validated['notes'] ?? '',
                'freeze_outbound' => (bool) ($validated['freeze_outbound'] ?? false),
                'count_scope' => $validated['count_scope'] ?? 'full',
                'count_scope_payload' => $validated['count_scope_payload'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $msg = "Session {$session->session_code} created. Set up counts for each warehouse.";
            if ($session->freeze_outbound) {
                $msg .= ' Outbound movements are now FROZEN for the selected warehouses until this session is posted or cancelled.';
            }
            // Phase 5: remind the user what scope they picked so they don't
            // forget a cycle count is narrowed (not a full count).
            if (!$session->isFullCount()) {
                $msg .= ' Scope: ' . $this->stockTakeService->describeScope($session) . '.';
            }

            return redirect()->route('admin.stock-take.show', $session)
                ->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(int $id)
    {
        $session = StockTakeSession::with([
            'branch', 'warehouses.warehouse.branch', 'items.product', 'journalEntry.lines.ledger'
        ])->findOrFail($id);

        // Progress summary.
        $progress = $this->computeProgress($id);

        // Variance lines (items where physical ≠ system).
        $varianceLines = DB::table('stock_take_items as sti')
            ->join('products as p', 'p.id', '=', 'sti.product_id')
            ->join('warehouses as w', 'w.id', '=', 'sti.warehouse_id')
            ->where('sti.stock_take_session_id', $id)
            ->whereRaw('sti.physical_qty <> sti.system_qty')
            ->select('sti.*', 'p.product_code', 'p.product_name', 'p.unit', 'w.warehouse_name')
            ->orderBy('w.warehouse_name')
            ->orderBy('p.product_name')
            ->get();

        // Stock movements (if posted).
        $stockMovements = [];
        if ($session->isPosted() || $session->is_reversed) {
            $stockMovements = DB::table('stock_transactions as st')
                ->join('products as p', 'p.id', '=', 'st.product_id')
                ->where('st.reference_type', 'stock_take')
                ->where('st.reference_id', $id)
                ->select('st.*', 'p.product_code', 'p.product_name')
                ->orderBy('st.id')
                ->get();
        }

        // Phase 2: audit timeline + per-session health check.
        $auditLogs = StockTakeAuditLog::with(['actor', 'warehouse'])
            ->where('stock_take_session_id', $id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $healthCheck = $this->healthCheckService->runSessionChecks($id);

        // Phase 3: extract the stock-drift reconciliation warning from the
        // latest 'post' audit row (if the session has been posted). The post
        // audit payload carries `stock_drift` — the list of products whose
        // live qty drifted from the setup-time snapshot. Empty/absent when the
        // outbound freeze held for the full count.
        $stockDrift = [];
        if ($session->isPosted()) {
            $postLog = $auditLogs->firstWhere('action', 'post');
            if ($postLog && is_array($postLog->payload)) {
                $stockDrift = $postLog->payload['stock_drift'] ?? [];
            }
        }

        // Phase 4: approval-workflow context for the show page.
        //   - varianceValue: total |gain|+|loss| value (drives the gate).
        //   - approvalRequired: does this session need approval before post?
        //   - policy flags: require_approval, auto_approve_below_value,
        //     variance_threshold_block (for the policy info card).
        //   - canSubmit/canApprove/canReject/canPost: UI gate flags computed
        //     server-side (the blade trusts these, never re-derives).
        //   - submitterUser/approverUser: resolved User models for the
        //     approval-info card (may be null — submitted_by/approved_by are
        //     plain integers, not FKs, so a deleted user leaves null).
        $varianceValue = (float) ($progress['variance_value'] ?? 0);
        $approvalRequired = $this->policyService->approvalRequiredForVariance($varianceValue);
        $user = auth()->user();
        $userRole = $user?->getRole() ?? '';
        $isApproverRole = $this->policyService->isApproverRole($userRole);
        $allCompleted = $progress['total_wh'] > 0 && $progress['completed_wh'] === $progress['total_wh'];

        // canSubmit: counting + all warehouses completed + (approval is or
        // might be required for this session). Even when approval is not
        // strictly required right now, the counter may still submit for
        // review — so the button shows whenever the session is counting +
        // complete + the approval gate is globally enabled OR the variance
        // threshold would force it. When the gate is fully off and the
        // variance is small, the Post button shows directly instead.
        $canSubmit = $session->isCounting()
            && $allCompleted
            && (
                $this->policyService->requireApproval()
                || $this->policyService->varianceThresholdBlock() > 0
            );

        // canApprove / canReject: submitted + user has approver role +
        // user is NOT the submitter (segregation of duties). The service
        // re-checks all of these, but the UI hides the buttons when they
        // would certainly fail, to avoid dead clicks.
        $isSubmitter = $session->submitted_by !== null
            && $user !== null
            && (int) $session->submitted_by === (int) $user->id;
        $canApprove = $session->isSubmitted() && $isApproverRole && !$isSubmitter;
        $canReject = $session->isSubmitted() && $isApproverRole && !$isSubmitter;

        // canPost: approved (any role that can post) OR counting/draft when
        // approval is NOT required (legacy direct-post path). The service
        // makes the final call; this flag just controls button visibility.
        $canPost = ($session->isApproved()
                || (
                    in_array($session->status, ['counting', 'draft'], true)
                    && !$approvalRequired
                ))
            && $allCompleted;

        // Resolve submitter / approver users for the approval-info card.
        $submitterUser = $session->submitted_by
            ? User::with('employee')->find($session->submitted_by)
            : null;
        $approverUser = $session->approved_by
            ? User::with('employee')->find($session->approved_by)
            : null;

        return view('admin.stock-take.show', [
            'title' => 'Stock Take ' . $session->session_code,
            'session' => $session,
            'progress' => $progress,
            'varianceLines' => $varianceLines,
            'stockMovements' => $stockMovements,
            'auditLogs' => $auditLogs,
            'healthCheck' => $healthCheck,
            // Phase 3: freeze + reconciliation context for the show page.
            'stockDrift' => $stockDrift,
            // Phase 4: approval-workflow context.
            'varianceValue' => $varianceValue,
            'approvalRequired' => $approvalRequired,
            'requireApproval' => $this->policyService->requireApproval(),
            'autoApproveBelowValue' => $this->policyService->autoApproveBelowValue(),
            'varianceThresholdBlock' => $this->policyService->varianceThresholdBlock(),
            'approverRoles' => $this->policyService->approverRoles(),
            'canSubmit' => $canSubmit,
            'canApprove' => $canApprove,
            'canReject' => $canReject,
            'canPost' => $canPost,
            'isApproverRole' => $isApproverRole,
            'isSubmitter' => $isSubmitter,
            'submitterUser' => $submitterUser,
            'approverUser' => $approverUser,
            // Phase 5: cycle-count scope description (human-readable).
            'scopeDescription' => $this->stockTakeService->describeScope($session),
        ]);
    }

    /**
     * Setup counts for a warehouse — loads all products into stock_take_items.
     */
    public function setupCounts(int $sessionId, int $warehouseId)
    {
        try {
            $count = $this->stockTakeService->setupWarehouseCounts($sessionId, $warehouseId);
            return redirect()->route('admin.stock-take.count', [$sessionId, $warehouseId])
                ->with('success', "{$count} products loaded for counting.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Show the count form for a warehouse.
     */
    public function count(int $sessionId, int $warehouseId)
    {
        $session = StockTakeSession::with(['branch'])->findOrFail($sessionId);
        $warehouse = \App\Models\Warehouse::with('branch')->findOrFail($warehouseId);

        $items = DB::table('stock_take_items as sti')
            ->join('products as p', 'p.id', '=', 'sti.product_id')
            ->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id')
            ->where('sti.stock_take_session_id', $sessionId)
            ->where('sti.warehouse_id', $warehouseId)
            ->select('sti.*', 'p.product_code', 'p.product_name', 'p.unit', 'c.category_name')
            ->orderBy('c.category_name')
            ->orderBy('p.product_name')
            ->get();

        return view('admin.stock-take.count', [
            'title' => "Count — {$warehouse->warehouse_name}",
            'session' => $session,
            'warehouse' => $warehouse,
            'items' => $items,
        ]);
    }

    /**
     * Save the physical counts (POST).
     */
    public function saveCounts(Request $request, int $sessionId, int $warehouseId)
    {
        $validated = $request->validate([
            'counts' => 'required|array',
            'counts.*' => 'numeric',
            'reasons' => 'nullable|array',
            'reasons.*' => 'nullable|string|max:500',
        ]);

        try {
            $updated = $this->stockTakeService->saveCounts($sessionId, $warehouseId, $validated['counts']);

            // Save per-line reasons.
            if (!empty($validated['reasons'])) {
                foreach ($validated['reasons'] as $productId => $reason) {
                    DB::table('stock_take_items')
                        ->where('stock_take_session_id', $sessionId)
                        ->where('warehouse_id', $warehouseId)
                        ->where('product_id', (int) $productId)
                        ->update(['reason' => $reason]);
                }
            }

            return redirect()->route('admin.stock-take.show', $sessionId)
                ->with('success', "Counts saved for {$updated} products. Review variances and post when ready.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Post the session — apply variances + GL.
     */
    public function post(Request $request, int $id)
    {
        $request->validate([
            'post_reason' => 'nullable|string|max:500',
        ]);

        try {
            $session = $this->stockTakeService->postSession($id, auth()->id());
            return redirect()->route('admin.stock-take.show', $session)
                ->with('success', "Session {$session->session_code} posted. Variances applied + GL posted.");
        } catch (StockTakeNegativeStockException $e) {
            // Phase 1: friendly negative-stock error with the offending product list.
            // The session remains in its pre-post state (counting/draft) — no stock moved.
            return back()->withInput()
                ->with('error', $e->getMessage())
                ->with('negative_stock_products', $e->getOffendingProducts());
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Cancel the session.
     */
    public function cancel(Request $request, int $id)
    {
        $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        try {
            $session = $this->stockTakeService->cancelSession($id, auth()->id(), $request->input('cancel_reason'));
            return redirect()->route('admin.stock-take.show', $session)
                ->with('success', "Session {$session->session_code} cancelled.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Phase 4: Submit a counting session for approval.
     *
     * Transitions counting → submitted. The session is now locked for the
     * counter; only an approver (a different user with an approver role)
     * can move it forward (approve → post) or back (reject → counting).
     *
     * Defence in depth: the route middleware restricts by role, but the
     * service also validates state + warehouse completion, so a forged
     * request cannot submit an incomplete session.
     */
    public function submit(Request $request, int $id)
    {
        $request->validate([
            'submit_comments' => 'nullable|string|max:1000',
        ]);

        try {
            $session = $this->stockTakeService->submit($id, auth()->id());
            $msg = "Session {$session->session_code} submitted for approval. "
                . 'An approver will review it.';
            return redirect()->route('admin.stock-take.show', $session)
                ->with('success', $msg);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Phase 4: Approve a submitted session.
     *
     * Transitions submitted → approved. The approver CANNOT be the same
     * user who submitted (segregation of duties) — the service enforces
     * this. After approval, the session can be posted.
     *
     * Optional approval comments are stored on the session and surfaced
     * in the audit timeline.
     */
    public function approve(Request $request, int $id)
    {
        $request->validate([
            'approval_comments' => 'nullable|string|max:2000',
        ]);

        try {
            $session = $this->stockTakeService->approve(
                $id,
                auth()->id(),
                $request->input('approval_comments', '')
            );
            return redirect()->route('admin.stock-take.show', $session)
                ->with('success', "Session {$session->session_code} approved. You can now post it.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Phase 4: Reject a submitted session.
     *
     * Transitions submitted → counting. The session goes back to the
     * counter for re-count/correction. A rejection reason is required
     * (the counter needs to know what to fix).
     */
    public function reject(Request $request, int $id)
    {
        $request->validate([
            'rejection_reason' => 'required|string|max:2000',
        ]);

        try {
            $session = $this->stockTakeService->reject(
                $id,
                auth()->id(),
                $request->input('rejection_reason')
            );
            return redirect()->route('admin.stock-take.show', $session)
                ->with('success', "Session {$session->session_code} rejected and returned to counting.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Compute progress summary for a session.
     */
    private function computeProgress(int $sessionId): array
    {
        $wh = DB::table('stock_take_warehouses')
            ->where('stock_take_session_id', $sessionId)
            ->selectRaw("
                COUNT(*) as total_wh,
                SUM(CASE WHEN status IN ('counting','completed') THEN 1 ELSE 0 END) as counted_wh,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_wh
            ")
            ->first();

        $var = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sessionId)
            ->whereRaw('physical_qty <> system_qty')
            ->selectRaw("
                COUNT(*) as variance_lines,
                COALESCE(SUM(ABS(physical_qty - system_qty)), 0) as abs_qty_variance,
                COALESCE(SUM(ABS(physical_qty - system_qty) * COALESCE(rate, 0)), 0) as variance_value,
                COALESCE(SUM(CASE WHEN physical_qty > system_qty THEN (physical_qty - system_qty) * COALESCE(rate, 0) ELSE 0 END), 0) as gain_value,
                COALESCE(SUM(CASE WHEN physical_qty < system_qty THEN (system_qty - physical_qty) * COALESCE(rate, 0) ELSE 0 END), 0) as loss_value
            ")
            ->first();

        return [
            'total_wh' => (int) ($wh->total_wh ?? 0),
            'counted_wh' => (int) ($wh->counted_wh ?? 0),
            'completed_wh' => (int) ($wh->completed_wh ?? 0),
            'variance_lines' => (int) ($var->variance_lines ?? 0),
            'abs_qty_variance' => (float) ($var->abs_qty_variance ?? 0),
            'variance_value' => (float) ($var->variance_value ?? 0),
            'gain_value' => (float) ($var->gain_value ?? 0),
            'loss_value' => (float) ($var->loss_value ?? 0),
        ];
    }

    /**
     * Phase 2: Global health-check checklist screen (port of legacy
     * StockTake/checklist). Surfaces data-integrity, GL-alignment, and
     * operations checks across all in-scope sessions.
     *
     * Admins can optionally view all branches (RLS bypass); everyone else
     * sees only their branch (RLS-scoped, with the explicit branchId arg
     * omitted so the service lets RLS do the filtering).
     */
    public function checklist(Request $request)
    {
        $user = auth()->user();
        $isAdmin = $user?->isAdmin() ?? false;
        $viewAll = $isAdmin && $request->boolean('all_branches');
        // Admins viewing all branches → null (RLS bypass shows everything).
        // Everyone else → null too (RLS auto-scopes to their session branch).
        // Admins scoping to one branch → the active session branch_id.
        $branchId = ($isAdmin && !$viewAll)
            ? (int) (session('branch_id') ?: $user?->getBranchId() ?: 0)
            : null;

        $result = $this->healthCheckService->runHealthChecks($branchId);

        return view('admin.stock-take.checklist', [
            'title'    => 'Stock Take — Health Check',
            'sections' => $result['sections'],
            'summary'  => $result['summary'],
            'ranAt'    => $result['ran_at'],
            'missingSessionJournals' => $result['missing_session_journals'],
            'viewAllBranches' => $viewAll,
            'canViewAllBranches' => $isAdmin,
        ]);
    }

    /**
     * Phase 2: Global audit-log screen — every stock_take_audit_log row
     * across all in-scope sessions, filterable by date / actor / action /
     * session. RLS scopes rows by branch (admins see all when ?all_branches=1).
     */
    public function audit(Request $request)
    {
        $user = auth()->user();
        $isAdmin = $user?->isAdmin() ?? false;
        $viewAll = $isAdmin && $request->boolean('all_branches');

        $query = StockTakeAuditLog::with(['session', 'actor', 'warehouse'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('created_at', '>=', $d . ' 00:00:00'))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('created_at', '<=', $d . ' 23:59:59'))
            ->when($request->input('actor_id'), fn($q, $a) => $q->where('actor_id', (int) $a))
            ->when($request->input('action'), fn($q, $a) => $q->where('action', $a))
            ->when($request->input('session_id'), fn($q, $s) => $q->where('stock_take_session_id', (int) $s))
            ->when($request->input('search'), function ($q, $search) {
                // Search by session_code via a join to the session table.
                $q->whereHas('session', fn($sq) => $sq->where('session_code', 'ILIKE', "%{$search}%"));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $logs = $query->paginate(50)->withQueryString();

        // Distinct actions for the filter dropdown.
        $actionOptions = StockTakeAuditLog::select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->mapWithKeys(fn($a) => [$a => StockTakeAuditLog::actionLabel($a)])
            ->all();

        // Actors for the filter dropdown (only users who have audit rows).
        // Users have no `name` column — display label is `username` plus the
        // linked employee's name (joined via the employee relationship).
        $actorIds = StockTakeAuditLog::whereNotNull('actor_id')
            ->distinct()
            ->pluck('actor_id')
            ->all();
        $actors = $actorIds
            ? User::with('employee')->whereIn('id', $actorIds)->orderBy('username')->get()
            : collect();

        return view('admin.stock-take.audit', [
            'title'         => 'Stock Take — Audit Log',
            'logs'          => $logs,
            'actionOptions' => $actionOptions,
            'actors'        => $actors,
            'filters'       => $request->only(['from_date', 'to_date', 'actor_id', 'action', 'session_id', 'search']),
            'canViewAllBranches' => $isAdmin,
            'viewAllBranches'    => $viewAll,
        ]);
    }

    // ========================================================================
    // Phase 5 (Stock Take plan): Cycle count & ABC classification endpoints.
    // ========================================================================

    /**
     * Phase 5: AJAX product search for the ad_hoc scope's product picker.
     *
     * Returns active, non-deleted products matching the `q` term (code OR
     * name, ILIKE). Optionally scoped to a warehouse via ?warehouse_id= so
     * the picker can show current on-hand qty alongside each product. Output
     * is a select2-friendly {results: [{id, text, code, name, unit, stock}]}
     * plus a plain array fallback for non-select2 callers.
     *
     * Defence in depth: the route middleware restricts this to the stock-take
     * write roles (admin, manager, warehouse_manager). The query is read-only
     * and branch-agnostic (products are global master data); RLS on
     * warehouse_stock scopes on-hand rows by the warehouse's branch.
     */
    public function searchProducts(Request $request)
    {
        $request->validate([
            'q'            => 'nullable|string|max:80',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'limit'        => 'nullable|integer|min:1|max:100',
        ]);

        $q = trim((string) $request->input('q', ''));
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null;
        $limit = (int) ($request->input('limit', 30));

        $query = DB::table('products as p')
            ->where('p.is_active', true)
            ->whereNull('p.deleted_at')
            ->select('p.id', 'p.product_code', 'p.product_name', 'p.unit');

        if ($warehouseId) {
            $query->leftJoin('warehouse_stock as ws', function ($join) use ($warehouseId) {
                $join->on('ws.product_id', '=', 'p.id')
                     ->where('ws.warehouse_id', '=', $warehouseId);
            })->addSelect(DB::raw('COALESCE(ws.qty, 0) as stock'));
        } else {
            $query->addSelect(DB::raw('NULL as stock'));
        }

        if ($q !== '') {
            $query->where(function ($sq) use ($q) {
                $sq->where('p.product_code', 'ILIKE', "%{$q}%")
                   ->orWhere('p.product_name', 'ILIKE', "%{$q}%");
            });
        }

        $rows = $query->orderBy('p.product_name')->limit($limit)->get();

        $results = $rows->map(fn($r) => [
            'id'   => (int) $r->id,
            'text' => "{$r->product_code} — {$r->product_name}",
            'code' => $r->product_code,
            'name' => $r->product_name,
            'unit' => $r->unit,
            'stock' => $r->stock !== null ? (float) $r->stock : null,
        ])->values();

        return response()->json(['results' => $results]);
    }

    /**
     * Phase 5: AJAX scope preview — "if I set up a count with this scope +
     * payload for this warehouse, how many products would be loaded?"
     *
     * Lets the user sanity-check a cycle-count scope BEFORE creating the
     * session (so they don't create a session only to find the scope matches
     * 0 products). Runs the SAME buildScopedProductsQuery logic the setup
     * will use, via a transient in-memory validation (no session row written).
     *
     * Returns {count, scope, payload, sample: [{id, code, name, stock}]}
     * (sample = first 10 matches). Errors (invalid payload) return 422 with
     * the validation message.
     */
    public function previewScope(Request $request)
    {
        $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'count_scope'  => 'required|string|in:full,category,abc,group,ad_hoc,negative_only,zero_only',
            'count_scope_payload' => 'nullable|array',
        ]);

        try {
            $scope = $request->input('count_scope');
            $payload = $request->input('count_scope_payload');
            // Validate + normalize the payload the same way createSession will.
            $normalized = $this->stockTakeService->validateCountScope($scope, $payload);

            // Count + sample via the mirrored scoped-preview query. The count
            // MUST match setupWarehouseCounts' result, so scopedPreviewQuery
            // mirrors StockTakeService::buildScopedProductsQuery exactly.
            $count = $this->countScopedProducts($scope, $normalized, (int) $request->input('warehouse_id'));

            // Sample first 10 for the preview list.
            $sample = $this->sampleScopedProducts($scope, $normalized, (int) $request->input('warehouse_id'), 10);

            return response()->json([
                'count'   => $count,
                'scope'   => $scope,
                'payload' => $normalized,
                'sample'  => $sample,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Phase 5: ABC classification report screen.
     *
     * Shows the per-warehouse A/B/C distribution (product count + total
     * annual usage value + value share per class), the last-computed timestamp,
     * and a manual "Refresh now" action (calls AbcClassificationService::refresh).
     * Filterable by warehouse. RLS scopes warehouse_stock reads by branch.
     */
    public function abcReport(Request $request)
    {
        $request->validate([
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
        ]);

        $warehouseId = $request->filled('warehouse_id') ? (int) $request->input('warehouse_id') : null;
        $summary = $this->abcService->getSummary($warehouseId);

        // Per-warehouse breakdown table (for the report's main grid).
        $perWarehouse = DB::table('mv_product_abc_classification as abc')
            ->join('warehouses as w', 'w.id', '=', 'abc.warehouse_id')
            ->selectRaw('
                abc.warehouse_id,
                w.warehouse_name,
                abc.abc_class,
                COUNT(*) AS product_count,
                COALESCE(SUM(abc.annual_usage_value), 0) AS total_usage_value
            ')
            ->groupBy('abc.warehouse_id', 'w.warehouse_name', 'abc.abc_class')
            ->orderBy('abc.warehouse_id')
            ->orderBy('abc.abc_class')
            ->get();

        $warehouses = \App\Models\Warehouse::active()->orderBy('warehouse_name')->get(['id', 'warehouse_name']);

        // Top A-class products (the high-value movers worth cycle-counting
        // most frequently). Limited to 50 for the report.
        $topProducts = DB::table('mv_product_abc_classification as abc')
            ->join('products as p', 'p.id', '=', 'abc.product_id')
            ->join('warehouses as w', 'w.id', '=', 'abc.warehouse_id')
            ->when($warehouseId, fn($q, $wid) => $q->where('abc.warehouse_id', $wid))
            ->where('abc.abc_class', 'A')
            ->orderByDesc('abc.annual_usage_value')
            ->limit(50)
            ->select('p.product_code', 'p.product_name', 'w.warehouse_name', 'abc.annual_usage_value', 'abc.abc_class')
            ->get();

        return view('admin.stock-take.abc-report', [
            'title'         => 'Stock Take — ABC Classification',
            'summary'       => $summary,
            'perWarehouse'  => $perWarehouse,
            'topProducts'   => $topProducts,
            'warehouses'    => $warehouses,
            'selectedWarehouseId' => $warehouseId,
            'thresholdA'    => (float) ($this->policyService->all()['stock_take.abc_threshold_a'] ?? 0.80),
            'thresholdB'    => (float) ($this->policyService->all()['stock_take.abc_threshold_b'] ?? 0.95),
            'lookbackDays'  => (int) ($this->policyService->all()['stock_take.abc_lookback_days'] ?? 365),
        ]);
    }

    /**
     * Phase 5: manual "Refresh ABC classification now" action. Triggers
     * REFRESH MATERIALIZED VIEW CONCURRENTLY via the service. Admin/manager
     * only (route middleware). Redirects back to the ABC report with the
     * result (refreshed true/false + row count + computed_at).
     */
    public function refreshAbc()
    {
        $result = $this->abcService->refresh();
        if ($result['refreshed']) {
            return redirect()->route('admin.stock-take.abc-report')
                ->with('success', 'ABC classification refreshed. ' . $result['rows'] . ' products classified, computed at ' . $result['computed_at'] . '.');
        }
        return redirect()->route('admin.stock-take.abc-report')
            ->with('error', 'ABC refresh failed: ' . $result['error']);
    }

    /**
     * Phase 5: count how many products a scope+payload would load for a
     * warehouse. Mirrors StockTakeService::buildScopedProductsQuery exactly
     * so the preview count matches the actual setup count. Kept here (not in
     * the service) to avoid widening the service's public surface; the
     * service's setup path is the source of truth and this is a read-only
     * mirror for the preview UX.
     */
    private function countScopedProducts(string $scope, array $payload, int $warehouseId): int
    {
        return $this->scopedPreviewQuery($scope, $payload, $warehouseId)->count();
    }

    /**
     * Phase 5: return up to $limit sample products for the preview list.
     *
     * @return array<int, array{id: int, code: string, name: string, unit: string, stock: float|null}>
     */
    private function sampleScopedProducts(string $scope, array $payload, int $warehouseId, int $limit): array
    {
        $rows = $this->scopedPreviewQuery($scope, $payload, $warehouseId)
            ->limit($limit)
            ->get();

        return $rows->map(fn($r) => [
            'id'    => (int) $r->product_id,
            'code'  => $r->product_code,
            'name'  => $r->product_name,
            'unit'  => $r->unit,
            'stock' => $r->system_qty !== null ? (float) $r->system_qty : null,
        ])->values()->all();
    }

    /**
     * Phase 5: the shared scoped-product query builder for the preview UX.
     * Mirrors StockTakeService::buildScopedProductsQuery. If they ever
     * diverge, the preview count will mismatch the setup count — a test
     * should catch that. Returns the Builder (caller adds count()/get()/limit()).
     */
    private function scopedPreviewQuery(string $scope, array $payload, int $warehouseId)
    {
        $query = DB::table('products as p')
            ->leftJoin('warehouse_stock as ws', function ($join) use ($warehouseId) {
                $join->on('ws.product_id', '=', 'p.id')
                     ->where('ws.warehouse_id', '=', $warehouseId);
            })
            ->where('p.is_active', true)
            ->whereNull('p.deleted_at')
            ->select(
                'p.id as product_id',
                'p.product_code',
                'p.product_name',
                'p.unit',
                DB::raw('COALESCE(ws.qty, 0) as system_qty')
            );

        switch ($scope) {
            case 'category':
                $query->whereIn('p.category_id', $payload['category_ids'] ?? []);
                break;
            case 'group':
                $query->whereIn('p.group_id', $payload['group_ids'] ?? []);
                break;
            case 'abc':
                $classes = $payload['abc_classes'] ?? ['A', 'B', 'C'];
                $query->join('mv_product_abc_classification as abc', function ($join) use ($warehouseId) {
                    $join->on('abc.product_id', '=', 'p.id')
                         ->where('abc.warehouse_id', '=', $warehouseId);
                })->whereIn('abc.abc_class', $classes);
                break;
            case 'ad_hoc':
                $query->whereIn('p.id', $payload['product_ids'] ?? []);
                break;
            case 'negative_only':
                $query->whereRaw('COALESCE(ws.qty, 0) < -0.0001');
                break;
            case 'zero_only':
                $query->whereRaw('ABS(COALESCE(ws.qty, 0)) < 0.0001');
                break;
            case 'full':
            default:
                break;
        }

        return $query->orderBy('p.product_name');
    }
}

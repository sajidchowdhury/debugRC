<?php

namespace App\Http\Controllers\Api\V1\StockAdjustment;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\SortsLists;
use App\Http\Resources\Api\V1\StockAdjustment\StockAdjustmentResource;
use App\Http\Requests\Api\V1\StockAdjustment\StoreStockAdjustmentRequest;
use App\Models\StockAdjustment;
use App\Services\Stock\StockAdjustmentPolicyService;
use App\Services\Stock\StockAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stock Adjustment API Controller — Phase 9 (API Routes & Mobile Support).
 *
 * REST endpoints for mobile / AI-sidecar integration. Exposes the same
 * lifecycle the web controller does — create draft → submit → approve →
 * confirm → cancel — over JSON, with the SAME service + policy enforcement.
 *
 * Endpoints (all under /api/v1/stock-adjustments, behind api.auth +
 * set.api.branch so RLS on stock_adjustments filters by the caller's branch):
 *
 *   GET    /                  list (paginated + filtered)
 *   POST   /                  create draft (no stock movement, no GL)
 *   GET    /{id}              show detail (header + items + stock movements +
 *                             GL journal + reversing JE + audit timeline)
 *   POST   /{id}/submit       submit a draft for approval (draft → submitted,
 *                             or auto-advance to approved when below the
 *                             auto-approve threshold)
 *   POST   /{id}/approve      approve a submitted adjustment (admin/manager)
 *   POST   /{id}/reject       reject a submitted adjustment → draft (admin/manager)
 *   POST   /{id}/confirm      confirm = apply stock + post GL (approved → confirmed)
 *   POST   /{id}/cancel       cancel = reverse stock + GL if confirmed (any state)
 *
 * Parity with the web controller:
 *   - Every endpoint reuses StockAdjustmentService (createAdjustment,
 *     submitAdjustment, approveAdjustment, rejectAdjustment,
 *     confirmAdjustment, cancelAdjustment) — NO duplicate business logic.
 *   - Every endpoint authorizes via StockAdjustmentPolicyService (canSubmit,
 *     canApprove, canConfirm, canForceConfirm, isSubmitter) — same
 *     role + branch + lifecycle checks the web show page uses.
 *   - Phase 1 role gating: route-level `api.auth:admin,accountant,manager`
 *     on the whole group; submit/store also allowed for accountant;
 *     approve/reject additionally gated to `api.auth:admin,manager`.
 *   - Phase 1 branch isolation: set.api.branch sets the app.branch_id GUC
 *     so PostgreSQL RLS on stock_adjustments filters by the caller's
 *     branch. The service + DB::transaction is the transactional backstop.
 *   - Phase 3 maker-checker: the service enforces approver ≠ submitter.
 *   - Phase 5 UOM: create accepts per-item uom_id; the service looks up
 *     the conversion factor and writes qty_base + uom_factor.
 *   - Phase 6.1 force-confirm: confirm accepts optional `force` +
 *     `force_reason` for decrease adjustments past pipeline-available
 *     stock (admin only — Policy::canForceConfirm).
 *   - Phase 6.2 reversal safety: confirm writes stock_transaction_id +
 *     stock_transaction_date to each item; cancel reverses by exact row.
 *
 * Rate limits (applied at the route level, mirroring Warehouse Transfer):
 *   - Reads (index/show): 60 req/min
 *   - Writes (store/submit/approve/reject/confirm/cancel): 30 req/min
 *
 * Response envelope:
 *   - Success (single): {"data": {...resource...}, "message": "..."}
 *   - Success (list):   {"data": [...], "meta": {pagination}}
 *   - Success (write):  {"data": {...resource...}, "message": "..."}
 *   - Validation error: 422 {"message": "...", "errors": {...}}
 *   - Not found:        404 {"message": "Not Found.", "detail": "..."}
 *   - Forbidden:        403 {"message": "..."} (from Policy or role check)
 *   - Server error:     500 {"message": "...", "error": "..."}
 */
class StockAdjustmentApiController extends Controller
{
    use SortsLists;

    public function __construct(
        private StockAdjustmentService $adjustmentService,
        private StockAdjustmentPolicyService $policy,
    ) {}

    /**
     * List stock adjustments (paginated + filtered).
     *
     * GET /api/v1/stock-adjustments
     *
     * Query params (all optional):
     *   ?from_date=             adjustment_date >=
     *   ?to_date=               adjustment_date <=
     *   ?warehouse_id=          filter by warehouse
     *   ?adjustment_type=       increase|decrease
     *   ?adjustment_category=   one of the 7 canonical categories
     *   ?status=                draft|submitted|approved|confirmed|cancelled|rejected
     *   ?branch_id=             admin-only override (non-admins are RLS-locked
     *                          to their own branch)
     *   ?search=                ILIKE on adjustment_code
     *   ?per_page=              page size (default 25, max 100)
     *   ?page=                  page number
     *   ?include=               comma-separated eager-load opt-in for the
     *                          list payload. Allowed: items, items.product,
     *                          items.uom, warehouse.branch, branch, createdBy
     *                          (default: warehouse.branch + branch only —
     *                          keeps the list payload small; use show() for
     *                          the full detail with audit_logs + journal_entry)
     *   ?sort=                  sort field (G-196). Whitelist:
     *                          id, adjustment_code, adjustment_date,
     *                          adjustment_type, total_amount, status,
     *                          created_at. Unknown values silently fall
     *                          back to the default (adjustment_date desc,
     *                          id desc).
     *   ?order=                 asc|desc (G-196). Default: desc.
     *
     * Branch isolation: set.api.branch has set the app.branch_id GUC, so
     * RLS on stock_adjustments filters the query at the DB level for
     * non-admins. Admins see all branches.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));

        // Parse the ?include= param so the consumer can opt into heavy
        // relations on the list endpoint (default: header-only).
        $include = array_filter(explode(',', (string) $request->input('include', '')));
        $with = array_values(array_intersect(
            ['items', 'items.product', 'items.uom', 'warehouse.branch', 'branch', 'createdBy'],
            array_map(fn($i) => trim($i), $include),
        ));
        if ($with === []) {
            // Always include the warehouse + branch for a useful list row.
            $with = ['warehouse.branch', 'branch'];
        }

        $query = StockAdjustment::with($with)
            ->when($request->input('from_date'), fn($q, $d) => $q->where('adjustment_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('adjustment_date', '<=', $d))
            ->when($request->input('warehouse_id'), fn($q, $wid) => $q->where('warehouse_id', $wid))
            ->when($request->input('adjustment_type'), fn($q, $t) => $q->where('adjustment_type', $t))
            ->when($request->input('adjustment_category'), function ($q, $c) {
                // Defensive — only apply if the value is a known category
                // (the DB CHECK constraint would reject an unknown value
                // anyway, but we avoid a 500 by filtering here).
                if (in_array($c, StockAdjustment::ADJUSTMENT_CATEGORIES, true)) {
                    $q->where('adjustment_category', $c);
                }
            })
            ->when($request->input('status'), function ($q, $s) {
                if (in_array($s, StockAdjustment::STATUSES, true)) {
                    $q->where('status', $s);
                }
            })
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('adjustment_code', 'ILIKE', "%{$search}%");
            });

        // G-196 (MEDIUM): sort convention — ?sort=field&order=asc|desc with
        // a per-endpoint whitelist. Default `adjustment_date desc, id desc`
        // preserves the prior hard-coded behavior. See api-conventions.md §8.5.
        $query = $this->applySort(
            $query,
            ['id', 'adjustment_code', 'adjustment_date', 'adjustment_type', 'total_amount', 'status', 'created_at'],
            'adjustment_date',
            'desc',
        );

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => StockAdjustmentResource::collection($paginator),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Create a draft adjustment (no stock movement, no GL).
     *
     * POST /api/v1/stock-adjustments
     *
     * Body:
     *   warehouse_id:        int (required, must exist + belong to caller's branch)
     *   adjustment_type:     string (required, increase|decrease)
     *   adjustment_category: string (required, one of the 7 canonical categories)
     *   adjustment_date:     string (Y-m-d, required)
     *   reason:              string|null (optional, max 1000)
     *   items:               array (required, min 1)
     *     items[].product_id: int (required, exists)
     *     items[].qty:        numeric (required, min 0.001 — the ENTERED qty)
     *     items[].uom_id:     int|null (optional, Phase 5 — the UOM the qty
     *                         was entered in; omit = base unit)
     *     items[].rate:       numeric|null (optional — auto-filled from
     *                         warehouse avg_cost if omitted)
     *     items[].reason:     string|null (optional, per-line reason)
     *
     * The service:
     *   - resolves the caller's branch_id (RLS-correct write target),
     *   - generates the adjustment_code,
     *   - looks up UOM conversion factors (Phase 5) and writes qty_base,
     *   - writes header + items in a single DB::transaction,
     *   - writes a 'create' audit-log row (Phase 4).
     *
     * Returns 201 with the draft resource on success.
     */
    public function store(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Idempotency replay check (PURCHASING-API-3, G-088/G-089/G-090).
        // Only engages when the client sends an `idempotency_token`; a
        // retry within 5 min returns the cached result instead of
        // creating a duplicate draft adjustment. See api-conventions.md §11.1.
        $idempotencyToken = $validated['idempotency_token'] ?? null;
        if ($idempotencyToken !== null) {
            $cacheKey = 'api:stock_adjustment:' . $idempotencyToken;
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return response()->json(array_merge($cached, [
                    'idempotent_replay' => true,
                ]));
            }
        }

        // Phase 1: defense-in-depth — role check for creating a draft.
        // The route-level api.auth gate already ensured the caller has
        // an allowed role (admin/manager/accountant); this re-checks via
        // the Policy so the intent is explicit and survives any future
        // route loosening. (Policy::create just checks the role tier.)
        $user = Auth::user();
        if (!$user || !$this->policy->canSubmit($user)) {
            return $this->forbidden('You do not have permission to create a stock adjustment.');
        }

        try {
            $adjustment = $this->adjustmentService->createAdjustment([
                'warehouse_id' => $validated['warehouse_id'],
                'adjustment_type' => $validated['adjustment_type'],
                'adjustment_category' => $validated['adjustment_category'],
                'adjustment_date' => $validated['adjustment_date'],
                'reason' => $validated['reason'] ?? '',
                'items' => $validated['items'],
                'created_by' => $user?->id,
            ]);

            $adjustment->load([
                'items.product', 'items.uom',
                'warehouse.branch', 'branch', 'createdBy',
            ]);

            $result = [
                'data'    => new StockAdjustmentResource($adjustment),
                'message' => "Draft adjustment {$adjustment->adjustment_code} created. Submit for approval or confirm directly (if below the auto-approve threshold).",
            ];

            // Cache the result for 5 minutes (idempotency window).
            if ($idempotencyToken !== null) {
                Cache::put('api:stock_adjustment:' . $idempotencyToken, $result, now()->addMinutes(5));
            }

            return response()->json($result, 201);
        } catch (\Throwable $e) {
            Log::warning('StockAdjustment API store failed', [
                'user_id' => $user?->id,
                'error'   => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Failed to create adjustment.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Show a single adjustment with full detail.
     *
     * GET /api/v1/stock-adjustments/{id}
     *
     * Includes:
     *   - Header (warehouse, branch, category, lifecycle state, GL linkage)
     *   - Items with product + UOM (Phase 5) + reversal linkage (Phase 6.2)
     *   - Stock movements (stock_transactions rows created on confirm —
     *     both 'stock_adjustment' and 'opening_balance' reference_types)
     *   - GL journal entry lines (if confirmed)
     *   - Reversing journal entry (if cancelled-after-confirm — Phase 8.5)
     *   - Audit timeline (Phase 4 — every lifecycle action with actor + payload)
     *
     * Branch isolation: RLS on stock_adjustments means a non-admin caller
     * requesting another branch's adjustment gets a 404 (not a 403 — we
     * don't leak existence).
     */
    public function show(int $id): JsonResponse
    {
        $adjustment = StockAdjustment::with([
            'items.product', 'items.uom',
            'warehouse.branch', 'branch',
            'journalEntry.lines.ledger',
            'submittedBy', 'approvedBy', 'confirmedBy', 'createdBy',
            'auditLogs.actor',  // Phase 4 — audit timeline (RLS-scoped by branch)
        ])->find($id);

        if ($adjustment === null) {
            return $this->notFound("Stock adjustment {$id} not found.");
        }

        // Stock movements (Phase 2 G17 fix — match both reference_types so
        // opening-balance adjustments show their movements too).
        $stockMovements = DB::table('stock_transactions as st')
            ->join('products as p', 'p.id', '=', 'st.product_id')
            ->join('warehouses as w', 'w.id', '=', 'st.warehouse_id')
            ->whereIn('st.reference_type', ['stock_adjustment', 'opening_balance'])
            ->where('st.reference_id', $id)
            ->select(
                'st.id', 'st.product_id', 'st.warehouse_id', 'st.qty', 'st.rate',
                'st.transaction_type', 'st.is_reversed', 'st.transaction_date',
                'p.product_code', 'p.product_name', 'w.warehouse_name'
            )
            ->orderBy('st.id')
            ->get()
            ->map(fn($row) => [
                'id'               => $row->id,
                'product_id'       => $row->product_id,
                'product_code'     => $row->product_code,
                'product_name'     => $row->product_name,
                'warehouse_id'     => $row->warehouse_id,
                'warehouse_name'   => $row->warehouse_name,
                'qty'              => (float) $row->qty,
                'rate'             => (float) $row->rate,
                'transaction_type' => $row->transaction_type,
                'is_reversed'      => (bool) $row->is_reversed,
                'transaction_date' => $row->transaction_date,
            ])
            ->values();

        // Reversing JE (Phase 8.5) — the GL reversal if a confirmed
        // adjustment was later cancelled. Looked up directly (no Eloquent
        // relation on the model — read-only display block).
        $reversingJe = null;
        if ($adjustment->journal_entry_id) {
            $reversingJe = DB::table('journal_entries as je')
                ->leftJoin('journal_lines as jl', 'jl.journal_entry_id', '=', 'je.id')
                ->leftJoin('ledgers as l', 'l.id', '=', 'jl.ledger_id')
                ->where('je.reversal_of_entry_id', $adjustment->journal_entry_id)
                ->select('je.id', 'je.entry_date', 'je.memo',
                         'jl.debit', 'jl.credit', 'jl.memo as line_memo',
                         'l.id as ledger_id', 'l.ledger_name', 'l.ledger_code')
                ->orderBy('je.id')
                ->orderBy('jl.id')
                ->get()
                ->groupBy('id')
                ->map(fn($lines, $jeId) => [
                    'journal_entry_id' => (int) $jeId,
                    'entry_date'       => $lines->first()->entry_date,
                    'memo'             => $lines->first()->memo,
                    'lines'            => $lines->map(fn($l) => [
                        'ledger_id'   => $l->ledger_id,
                        'ledger_code' => $l->ledger_code,
                        'ledger_name' => $l->ledger_name,
                        'debit'       => $l->debit !== null ? (float) $l->debit : null,
                        'credit'      => $l->credit !== null ? (float) $l->credit : null,
                        'memo'        => $l->line_memo,
                    ])->values(),
                ])
                ->values();
        }

        $resource = (new StockAdjustmentResource($adjustment))->toArray(request());
        $resource['stock_movements'] = $stockMovements;
        $resource['reversing_journal_entries'] = $reversingJe;

        return response()->json(['data' => $resource]);
    }

    /**
     * Submit a draft adjustment for approval (draft → submitted).
     *
     * POST /api/v1/stock-adjustments/{id}/submit
     *
     * Body (optional):
     *   comment: string|null (max 1000 — appended to approval_comments)
     *
     * If the adjustment does not require approval (below the auto-approve
     * threshold — see StockAdjustmentPolicyService::requiresApproval),
     * the service auto-advances it to 'approved' inline and the response
     * message reflects that.
     *
     * The service enforces:
     *   - status must be 'draft' (can't submit a confirmed adjustment)
     *   - the caller is the submitter (recorded for maker-checker)
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'comment' => 'nullable|string|max:1000',
        ]);

        $adjustment = StockAdjustment::find($id);
        if ($adjustment === null) {
            return $this->notFound("Stock adjustment {$id} not found.");
        }

        // Phase 1: Policy check — role + the caller's authority to submit.
        $user = Auth::user();
        if (!$user || !$this->policy->canSubmit($user)) {
            return $this->forbidden('You do not have permission to submit adjustments.');
        }

        try {
            $adjustment = $this->adjustmentService->submitAdjustment(
                $id,
                $user->id,
                $request->input('comment')
            );
            $adjustment->load(['items.product', 'items.uom', 'warehouse.branch', 'branch', 'createdBy']);

            $msg = $adjustment->isApproved()
                ? "Adjustment {$adjustment->adjustment_code} auto-approved (below threshold) — ready to confirm."
                : "Adjustment {$adjustment->adjustment_code} submitted for approval.";

            return response()->json([
                'data'    => new StockAdjustmentResource($adjustment),
                'message' => $msg,
            ]);
        } catch (\Throwable $e) {
            return $this->serviceError('submit', $id, $e, $user?->id);
        }
    }

    /**
     * Approve a submitted adjustment (submitted → approved).
     *
     * POST /api/v1/stock-adjustments/{id}/approve
     *
     * Body (required):
     *   comment: string (required, max 1000 — the approval note)
     *
     * Role: admin/manager only (route-level api.auth:admin,manager).
     * The service enforces segregation of duties (approver ≠ submitter) —
     * a user who submitted an adjustment cannot approve their own submission.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'comment' => 'required|string|max:1000',
        ]);

        $adjustment = StockAdjustment::find($id);
        if ($adjustment === null) {
            return $this->notFound("Stock adjustment {$id} not found.");
        }

        $user = Auth::user();
        if (!$user || !$this->policy->canApprove($user)) {
            return $this->forbidden('You do not have permission to approve adjustments.');
        }

        // Phase 3: maker-checker — the submitter cannot approve their own.
        if ($this->policy->isSubmitter($user, $adjustment)) {
            return $this->forbidden('You cannot approve an adjustment you submitted (segregation of duties).');
        }

        try {
            $adjustment = $this->adjustmentService->approveAdjustment(
                $id,
                $user->id,
                $request->input('comment')
            );
            $adjustment->load(['items.product', 'items.uom', 'warehouse.branch', 'branch', 'createdBy']);

            return response()->json([
                'data'    => new StockAdjustmentResource($adjustment),
                'message' => "Adjustment {$adjustment->adjustment_code} approved — ready to confirm.",
            ]);
        } catch (\Throwable $e) {
            return $this->serviceError('approve', $id, $e, $user?->id);
        }
    }

    /**
     * Reject a submitted adjustment (submitted → draft).
     *
     * POST /api/v1/stock-adjustments/{id}/reject
     *
     * Body (required):
     *   comment: string (required, max 1000 — the rejection reason)
     *
     * Role: admin/manager only (route-level api.auth:admin,manager).
     * The adjustment returns to 'draft' with the rejection reason appended
     * to approval_comments (prefixed [REJECTED]) so the drafter can revise.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'comment' => 'required|string|max:1000',
        ]);

        $adjustment = StockAdjustment::find($id);
        if ($adjustment === null) {
            return $this->notFound("Stock adjustment {$id} not found.");
        }

        $user = Auth::user();
        if (!$user || !$this->policy->canApprove($user)) {
            return $this->forbidden('You do not have permission to reject adjustments.');
        }

        try {
            $adjustment = $this->adjustmentService->rejectAdjustment(
                $id,
                $user->id,
                $request->input('comment')
            );
            $adjustment->load(['items.product', 'items.uom', 'warehouse.branch', 'branch', 'createdBy']);

            return response()->json([
                'data'    => new StockAdjustmentResource($adjustment),
                'message' => "Adjustment {$adjustment->adjustment_code} rejected — returned to draft.",
            ]);
        } catch (\Throwable $e) {
            return $this->serviceError('reject', $id, $e, $user?->id);
        }
    }

    /**
     * Confirm an adjustment (apply stock + post GL).
     *
     * POST /api/v1/stock-adjustments/{id}/confirm
     *
     * Body (all optional):
     *   confirm_reason: string|null (max 500 — why the posting was done)
     *   force:          bool (default false — Phase 6.1, admin-only bypass
     *                   of the pipeline-availability check for a decrease
     *                   that would dip below available stock)
     *   force_reason:   string|null (required when force=true, max 1000)
     *
     * The service:
     *   - validates the adjustment is in a confirmable state (approved, or
     *     draft when !requiresApproval),
     *   - for decreases: checks pipeline-aware availability (Phase 6) and
     *     rejects unless force=true + admin + non-empty force_reason,
     *   - writes stock_transactions (with reference_type routed by category —
     *     'opening_balance' for opening-balance adjustments, else
     *     'stock_adjustment'),
     *   - updates warehouse_stock (qty + moving-avg cost),
     *   - posts the GL journal entry (Dr/Cr depending on increase/decrease),
     *   - writes the stock_transaction_id + stock_transaction_date to each
     *     item (Phase 6.2 reversal safety),
     *   - writes a 'confirm' (or 'force_confirm') audit-log row.
     *
     * Returns the confirmed adjustment with the journal_entry_id populated.
     */
    public function confirm(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'confirm_reason' => 'nullable|string|max:500',
            'force'          => 'nullable|boolean',
            'force_reason'   => 'nullable|string|max:1000',
        ]);

        $adjustment = StockAdjustment::find($id);
        if ($adjustment === null) {
            return $this->notFound("Stock adjustment {$id} not found.");
        }

        $user = Auth::user();
        if (!$user || !$this->policy->canConfirm($user)) {
            return $this->forbidden('You do not have permission to confirm adjustments.');
        }

        $force = (bool) $request->input('force', false);
        $forceReason = $request->input('force_reason');

        // Phase 6.1 — defense-in-depth: if force is requested, the caller
        // must be an admin (the service re-checks, but surfacing the error
        // here gives a clean 403 rather than the service's 500).
        if ($force && (!$user || !$this->policy->canForceConfirm($user))) {
            return $this->forbidden('Only an admin can force-confirm a decrease past the pipeline-availability check.');
        }

        try {
            $adjustment = $this->adjustmentService->confirmAdjustment(
                $id,
                $user->id,
                $request->input('confirm_reason'),
                $force,
                $forceReason
            );
            $adjustment->load([
                'items.product', 'items.uom', 'warehouse.branch', 'branch',
                'journalEntry.lines.ledger', 'createdBy',
            ]);

            $msg = $force
                ? "Adjustment {$adjustment->adjustment_code} force-confirmed (pipeline check bypassed). Stock updated + GL posted."
                : "Adjustment {$adjustment->adjustment_code} confirmed. Stock updated + GL posted.";

            return response()->json([
                'data'    => new StockAdjustmentResource($adjustment),
                'message' => $msg,
            ]);
        } catch (\Throwable $e) {
            return $this->serviceError('confirm', $id, $e, $user?->id);
        }
    }

    /**
     * Cancel an adjustment (reverse stock + GL if confirmed).
     *
     * POST /api/v1/stock-adjustments/{id}/cancel
     *
     * Body (required):
     *   cancel_reason: string (required, max 500 — G15: always stored)
     *
     * Behaviour by current status:
     *   - draft / submitted / approved: marks as cancelled (no stock/GL
     *     to reverse — nothing was posted yet).
     *   - confirmed: reverses the stock movements (by exact
     *     stock_transaction_id — Phase 6.2 reversal safety), reverses the
     *     GL journal entry (posts a reversing entry linked via
     *     reversal_of_entry_id), and marks is_reversed=true.
     *
     * The service writes a 'cancel' (or 'reverse') audit-log row.
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        $adjustment = StockAdjustment::find($id);
        if ($adjustment === null) {
            return $this->notFound("Stock adjustment {$id} not found.");
        }

        $user = Auth::user();
        if (!$user || !$this->policy->canConfirm($user)) {
            // Cancel is a write of the same tier as confirm (admin/accountant).
            return $this->forbidden('You do not have permission to cancel adjustments.');
        }

        try {
            $adjustment = $this->adjustmentService->cancelAdjustment(
                $id,
                $user->id,
                $request->input('cancel_reason')
            );
            $adjustment->load([
                'items.product', 'items.uom', 'warehouse.branch', 'branch',
                'journalEntry.lines.ledger', 'createdBy',
            ]);

            return response()->json([
                'data'    => new StockAdjustmentResource($adjustment),
                'message' => "Adjustment {$adjustment->adjustment_code} cancelled.",
            ]);
        } catch (\Throwable $e) {
            return $this->serviceError('cancel', $id, $e, $user?->id);
        }
    }

    // ------------------------------------------------------------------
    // Response helpers
    // ------------------------------------------------------------------

    /**
     * 404 JSON — used for not-found adjustments. RLS means a non-admin
     * requesting another branch's adjustment also lands here (we don't
     * leak existence with a 403).
     */
    private function notFound(string $detail): JsonResponse
    {
        return response()->json([
            'message' => 'Not Found.',
            'detail'  => $detail,
        ], 404);
    }

    /**
     * 403 JSON — used for Policy failures (role / branch / lifecycle).
     */
    private function forbidden(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
        ], 403);
    }

    /**
     * Uniform handler for service-layer RuntimeExceptions.
     *
     * The service throws RuntimeException for expected lifecycle failures
     * (wrong status, insufficient stock, etc.) — these are 422 (the
     * request was syntactically valid but the state transition is
     * illegal). Unexpected Throwables bubble up as 500.
     */
    private function serviceError(string $action, int $id, \Throwable $e, ?int $userId): JsonResponse
    {
        $isRuntime = $e instanceof \RuntimeException;

        Log::warning('StockAdjustment API ' . $action . ' failed', [
            'adjustment_id' => $id,
            'user_id'       => $userId,
            'error'         => $e->getMessage(),
            'runtime'       => $isRuntime,
        ]);

        $status = $isRuntime ? 422 : 500;

        return response()->json([
            'message' => $isRuntime
                ? $e->getMessage()
                : "Failed to {$action} adjustment {$id}.",
            'error' => $e->getMessage(),
        ], $status);
    }
}

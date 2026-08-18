<?php

namespace App\Http\Controllers\Api\V1\BranchDemand;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\SortsLists;
use App\Http\Resources\Api\V1\BranchDemand\BranchDemandResource;
use App\Http\Requests\Api\V1\BranchDemand\StoreBranchDemandRequest;
use App\Http\Requests\Api\V1\BranchDemand\SendBranchDemandRequest;
use App\Http\Requests\Api\V1\BranchDemand\RepriceBranchDemandRequest;
use App\Models\BranchDemand;
use App\Services\BranchDemand\BranchDemandService;
use App\Services\BranchDemand\BranchIntercompanyService;
use App\Services\BranchDemand\BranchDemandRepricingService;
use App\Services\BranchDemand\BranchDemandAuditService;
use App\Services\Stock\StockAvailabilityService;
use App\Services\Stock\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Branch Demand API Controller — Phase 10 (API Routes & Mobile Support).
 *
 * Endpoints:
 *   GET    /api/v1/branch-demands                       List demands (paginated, filterable)
 *   POST   /api/v1/branch-demands                       Create demand
 *   GET    /api/v1/branch-demands/{id}                   Show demand detail
 *   POST   /api/v1/branch-demands/{id}/send              Send goods with warehouse selection
 *   POST   /api/v1/branch-demands/{id}/confirm-receipt   Confirm receipt of goods
 *   POST   /api/v1/branch-demands/{id}/reverse           Reverse a sent/received demand
 *   POST   /api/v1/branch-demands/{id}/reject            Reject a pending demand
 *   DELETE /api/v1/branch-demands/{id}                   Delete a pending demand
 *   POST   /api/v1/branch-demands/{id}/reprice           Reprice a demand's total value
 *   GET    /api/v1/branch-demands/outstanding             Outstanding balances by branch
 *   GET    /api/v1/branch-demands/ledger-history          Branch ledger history
 *   GET    /api/v1/branch-demands/settlement-preview      FIFO settlement preview
 *   GET    /api/v1/branch-demands/{id}/audit              Full audit trail
 *   GET    /api/v1/branch-demands/warehouses/{branchId}   Warehouses for a branch
 *   GET    /api/v1/branch-demands/product-stock/{pid}/{bid} Product stock at branch warehouses
 *
 * Reuses the same service layer as the web controller — all Phase 1-8
 * protections are in force:
 *   - Phase 1: Cross-branch demand creation
 *   - Phase 2: Send with warehouse selection
 *   - Phase 3: GL posting (dual creditor + debtor journals)
 *   - Phase 4: FIFO settlement (bank payments + money transfers)
 *   - Phase 5: Receipt confirmation before reversal
 *   - Phase 6: Weekly audit report
 *   - Phase 7: Price range + repricing
 *   - Phase 8: Anti-gaming + audit trail
 *
 * Branch isolation: api.auth + set.api.branch middleware ensure that
 * non-admin users can only see/act on demands within their own branch.
 * RLS policies on branch_demands enforce this at the database level.
 *
 * Rate limits:
 *   - Reads (list/show/audit/outstanding/ledger/stock): 60 req/min
 *   - Writes (store/send/confirm/reverse/reject/delete/reprice): 30 req/min
 *
 * Role enforcement:
 *   - Read: any authenticated user
 *   - Create + store: admin, manager, warehouse_manager
 *   - Send + confirm-receipt: admin, manager, warehouse_manager
 *   - Reverse + reject: admin, manager
 *   - Reprice: admin, manager
 *   - Delete: admin, manager
 */
class BranchDemandApiController extends Controller
{
    use SortsLists;

    public function __construct(
        private BranchDemandService $demandService,
        private BranchIntercompanyService $intercompanyService,
        private BranchDemandRepricingService $repricingService,
        private BranchDemandAuditService $auditService,
        private StockService $stockService,
        private StockAvailabilityService $stockAvailabilityService,
    ) {}

    /**
     * Get the current user's branch ID from session/GUC.
     */
    private function currentBranchId(): int
    {
        // For API requests, the session may not be set (SetApiBranchContext
        // sets the GUC instead). Fall back to the authenticated user's branch_id.
        $fromSession = (int) session('branch_id', 0);
        if ($fromSession > 0) {
            return $fromSession;
        }

        $user = Auth::user();
        if ($user && method_exists($user, 'getBranchId')) {
            return (int) $user->getBranchId();
        }

        return 0;
    }

    // ===================== READS =====================

    /**
     * List branch demands with pagination and filters.
     *
     * GET /api/v1/branch-demands
     *
     * Query params:
     *   ?status=         filter by status (pending|received|rejected|reversed)
     *   ?direction=      outgoing (my demands) | incoming (demands for me)
     *   ?from_date=      filter by demand_date >=
     *   ?to_date=        filter by demand_date <=
     *   ?search=         search demand_code (ILIKE)
     *   ?per_page=       page size (default 25, max 100)
     *   ?page=           page number
     *   ?sort=           sort field (G-196). Whitelist:
     *                    id, demand_code, demand_date, status, total_value,
     *                    created_at. Unknown values silently fall back to
     *                    the default (demand_date desc, id desc).
     *   ?order=          asc|desc (G-196). Default: desc.
     */
    public function index(Request $request): JsonResponse
    {
        $branchId = $this->currentBranchId();
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));

        $query = BranchDemand::forBranch($branchId)
            ->with(['fromBranch', 'toBranch', 'items.product', 'createdBy']);

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('direction')) {
            if ($request->direction === 'outgoing') {
                $query->where('from_branch_id', $branchId);
            } elseif ($request->direction === 'incoming') {
                $query->where('to_branch_id', $branchId);
            }
        }
        if ($request->filled('from_date')) {
            $query->where('demand_date', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->where('demand_date', '<=', $request->to_date);
        }
        if ($request->filled('search')) {
            $query->where('demand_code', 'ILIKE', '%' . $request->search . '%');
        }

        // G-196 (MEDIUM): sort convention — ?sort=field&order=asc|desc with
        // a per-endpoint whitelist. Default `demand_date desc, id desc`
        // preserves the prior hard-coded behavior (orderByDesc on both
        // demand_date + id). See api-conventions.md §8.5.
        $query = $this->applySort(
            $query,
            ['id', 'demand_code', 'demand_date', 'status', 'total_value', 'created_at'],
            'demand_date',
            'desc',
        );

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => BranchDemandResource::collection($paginator),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Show a single demand with full detail.
     *
     * GET /api/v1/branch-demands/{id}
     *
     * Includes: header, items with product/warehouse details, settlement
     * trace, GL journal references, repricing history, anti-gaming flags.
     */
    public function show(int $id): JsonResponse
    {
        $demand = BranchDemand::with([
            'fromBranch', 'toBranch', 'items.product', 'items.fromWarehouse', 'items.toWarehouse',
            'warehouseTransfer', 'journalEntry', 'debtorJournalEntry',
            'createdBy', 'receivedBy', 'reversedBy',
            'moneyTransferSettlements', 'customerPaymentSettlements',
            'repricingAdjustments',
        ])->find($id);

        if ($demand === null) {
            return $this->notFound("Demand {$id} not found.");
        }

        // Branch isolation check (admins bypass — they can see any demand)
        $user = Auth::user();
        $branchId = $this->currentBranchId();
        $isAdmin = $user && method_exists($user, 'isAdmin') && $user->isAdmin();
        if (!$isAdmin && $demand->from_branch_id !== $branchId && $demand->to_branch_id !== $branchId) {
            return response()->json([
                'message' => 'Forbidden. You do not have access to this demand.',
            ], 403);
        }

        $resource = (new BranchDemandResource($demand))->toArray(request());

        // Add stock transactions for traceability
        if ($demand->isReceived() || $demand->isReversed()) {
            $stockTransactions = DB::table('stock_transactions as st')
                ->join('products as p', 'p.id', '=', 'st.product_id')
                ->join('warehouses as w', 'w.id', '=', 'st.warehouse_id')
                ->where('st.reference_id', $id)
                ->whereIn('st.reference_type', ['demand_send', 'demand_receive', 'demand_reversal'])
                ->select('st.id', 'st.product_id', 'st.warehouse_id', 'st.qty', 'st.rate',
                         'st.transaction_type', 'st.is_reversed',
                         'p.product_code', 'p.product_name', 'w.warehouse_name')
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
                ])
                ->toArray();

            $resource['stock_transactions'] = $stockTransactions;
        }

        // Add repricing history
        if ($demand->relationLoaded('repricingAdjustments')) {
            $resource['repricing_history'] = $demand->repricingAdjustments->map(fn($adj) => [
                'id'                => $adj->id,
                'original_total'    => (float) $adj->original_total_value,
                'new_total'         => (float) $adj->new_total_value,
                'adjustment_amount' => (float) $adj->adjustment_amount,
                'reason'            => $adj->reason,
                'approved_by'       => $adj->approved_by,
                'journal_entry_id'  => $adj->journal_entry_id,
                'created_at'        => $adj->created_at?->toIso8601String(),
            ])->toArray();
        }

        return response()->json(['data' => $resource]);
    }

    // ===================== WRITES =====================

    /**
     * Create a new branch demand.
     *
     * POST /api/v1/branch-demands
     *
     * Body:
     *   to_branch_id: int (required, must be different from user's branch)
     *   demand_date: string (Y-m-d, required)
     *   notes: string|null (optional, max 2000)
     *   items: array (required, min 1)
     *     items[].product_id: int (required, must exist)
     *     items[].qty: numeric (required, min 0.01)
     *     items[].notes: string|null (optional, max 500)
     */
    public function store(StoreBranchDemandRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Idempotency replay check (PURCHASING-API-3, G-088/G-089/G-090).
        // Only engages when the client sends an `idempotency_token`; a
        // retry within 5 min returns the cached result instead of
        // creating a duplicate demand + intercompany journals. See
        // api-conventions.md §11.1.
        $idempotencyToken = $validated['idempotency_token'] ?? null;
        if ($idempotencyToken !== null) {
            $cacheKey = 'api:branch_demand:' . $idempotencyToken;
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return response()->json(array_merge($cached, [
                    'idempotent_replay' => true,
                ]));
            }
        }

        // Ensure the to_branch_id is not the same as the user's branch
        $branchId = $this->currentBranchId();
        if ((int) $validated['to_branch_id'] === $branchId) {
            return response()->json([
                'message' => 'Cannot create a demand to your own branch.',
                'errors'  => ['to_branch_id' => ['Supplier branch must be different from your branch.']],
            ], 422);
        }

        try {
            $items = $validated['items'];
            unset($validated['items']);
            // Strip the idempotency_token before handing off to the service
            // (the service array-merges this into the model attributes).
            unset($validated['idempotency_token']);

            $validated['from_branch_id'] = $branchId;
            $validated['created_by'] = Auth::id();

            $demand = $this->demandService->createDemand($validated, $items);
            $demand->load(['fromBranch', 'toBranch', 'items.product', 'createdBy']);

            $result = [
                'data'    => new BranchDemandResource($demand),
                'message' => "Demand {$demand->demand_code} created successfully.",
            ];

            // Cache the result for 5 minutes (idempotency window).
            if ($idempotencyToken !== null) {
                Cache::put('api:branch_demand:' . $idempotencyToken, $result, now()->addMinutes(5));
            }

            return response()->json($result, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('BranchDemand API store failed', ['error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to create demand.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Send goods for a demand with warehouse selection.
     *
     * POST /api/v1/branch-demands/{id}/send
     *
     * Body:
     *   items: array (required, min 1)
     *     items[].id: int (required, demand item ID)
     *     items[].from_warehouse_id: int (required, supplier warehouse)
     *     items[].to_warehouse_id: int (required, requester warehouse)
     *
     * Requires: admin, manager, or warehouse_manager role.
     *
     * Idempotency (PURCHASING-API-4, G7 Medium-risk): if the client
     * sends an `idempotency_token`, a retry within 5 min returns the
     * cached result instead of re-sending the demand (which would
     * otherwise move stock + post GL a second time, or hit a 409).
     * The cache key is namespaced per demand id so the same token
     * reused across different demands does not collide. See
     * api-conventions.md §11.1.
     */
    public function send(SendBranchDemandRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        // Idempotency replay check (only when token is present).
        $idempotencyToken = $validated['idempotency_token'] ?? null;
        if ($idempotencyToken !== null) {
            $cacheKey = 'api:branch_demand_send:' . $id . ':' . $idempotencyToken;
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return response()->json(array_merge($cached, [
                    'idempotent_replay' => true,
                ]));
            }
        }

        try {
            $demand = $this->demandService->sendGoodsWithWarehouses(
                $id,
                $validated['items'],
                Auth::id()
            );

            $demand->load(['fromBranch', 'toBranch', 'items.product', 'items.fromWarehouse', 'items.toWarehouse', 'createdBy']);

            $result = [
                'data'    => new BranchDemandResource($demand),
                'message' => "Goods sent for demand {$demand->demand_code}. Stock moved + GL posted.",
            ];

            // Cache the result for 5 minutes (idempotency window).
            if ($idempotencyToken !== null) {
                Cache::put('api:branch_demand_send:' . $id . ':' . $idempotencyToken, $result, now()->addMinutes(5));
            }

            return response()->json($result);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('BranchDemand API send failed', ['demand_id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to send goods.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Confirm receipt of goods for a demand.
     *
     * POST /api/v1/branch-demands/{id}/confirm-receipt
     *
     * Body (optional):
     *   notes: string|null (optional receipt confirmation note)
     *
     * Phase 5 — Warehouse Manager Confirmation.
     * Sets received_at / received_by on the demand. Required before reversal.
     *
     * Requires: admin, manager, or warehouse_manager role.
     */
    public function confirmReceipt(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $demand = $this->demandService->confirmReceipt(
                $id,
                Auth::id(),
                $this->currentBranchId()
            );

            $demand->load(['fromBranch', 'toBranch', 'items.product', 'createdBy']);

            return response()->json([
                'data'    => new BranchDemandResource($demand),
                'message' => "Receipt confirmed for demand {$demand->demand_code}.",
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('BranchDemand API confirm-receipt failed', ['demand_id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to confirm receipt.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reverse a sent/received demand.
     *
     * POST /api/v1/branch-demands/{id}/reverse
     *
     * Body:
     *   reason: string (required, min 5, max 2000)
     *
     * Reverses stock movements, GL journals, and ledger entries.
     * Blocked until receipt is confirmed (Phase 5).
     *
     * Requires: admin or manager role.
     */
    public function reverse(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:2000',
        ]);

        try {
            $demand = $this->demandService->reverseDemand(
                $id,
                $validated['reason'],
                Auth::id()
            );

            $demand->load(['fromBranch', 'toBranch', 'items.product', 'createdBy']);

            return response()->json([
                'data'    => new BranchDemandResource($demand),
                'message' => "Demand {$demand->demand_code} reversed. Stock restored + GL reversed.",
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('BranchDemand API reverse failed', ['demand_id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to reverse demand.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reject a pending demand.
     *
     * POST /api/v1/branch-demands/{id}/reject
     *
     * Body:
     *   reason: string (required, min 5, max 2000)
     *
     * Requires: admin, manager, or warehouse_manager role.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:5|max:2000',
        ]);

        try {
            $demand = $this->demandService->rejectDemand(
                $id,
                $validated['reason'],
                Auth::id()
            );

            $demand->load(['fromBranch', 'toBranch', 'items.product', 'createdBy']);

            return response()->json([
                'data'    => new BranchDemandResource($demand),
                'message' => "Demand {$demand->demand_code} rejected.",
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('BranchDemand API reject failed', ['demand_id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to reject demand.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Delete a pending demand.
     *
     * DELETE /api/v1/branch-demands/{id}
     *
     * Only pending demands can be deleted.
     * Requires: admin or manager role.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $this->demandService->deleteDraftDemand($id);

            return response()->json([
                'message' => "Demand {$id} deleted successfully.",
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('BranchDemand API delete failed', ['demand_id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to delete demand.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Reprice a demand's total value.
     *
     * POST /api/v1/branch-demands/{id}/reprice
     *
     * Body:
     *   new_total_value: numeric (required, min 0)
     *   reason: string (required, min 10, max 1000)
     *   approved_by: int|null (optional, must exist in users table)
     *
     * Phase 7 — Price Range Handling & Repricing Logic.
     * Creates a repricing adjustment, posts GL adjustment journals,
     * and updates the demand's total_value.
     *
     * Requires: admin or manager role.
     */
    public function reprice(RepriceBranchDemandRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        // Idempotency replay check (PURCHASING-API-4, G7 Medium-risk).
        // Only engages when the client sends an `idempotency_token`; a
        // retry within 5 min returns the cached result instead of
        // posting a second GL adjustment journal. Cache key is namespaced
        // per demand id so the same token reused across different demands
        // does not collide. See api-conventions.md §11.1.
        $idempotencyToken = $validated['idempotency_token'] ?? null;
        if ($idempotencyToken !== null) {
            $cacheKey = 'api:branch_demand_reprice:' . $id . ':' . $idempotencyToken;
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return response()->json(array_merge($cached, [
                    'idempotent_replay' => true,
                ]));
            }
        }

        try {
            $repricing = $this->repricingService->createRepricingAdjustment(
                $id,
                (float) $validated['new_total_value'],
                $validated['reason'],
                isset($validated['approved_by']) ? (int) $validated['approved_by'] : null,
                Auth::id()
            );

            $demand = BranchDemand::with(['fromBranch', 'toBranch', 'items.product', 'createdBy'])
                ->find($id);

            $result = [
                'data'    => new BranchDemandResource($demand),
                'message' => "Demand repriced. Adjustment: " . number_format((float) $repricing->adjustment_amount, 2),
                'repricing' => [
                    'id'                => $repricing->id,
                    'original_total'    => (float) $repricing->original_total_value,
                    'new_total'         => (float) $repricing->new_total_value,
                    'adjustment_amount' => (float) $repricing->adjustment_amount,
                    'journal_entry_id'  => $repricing->journal_entry_id,
                ],
            ];

            // Cache the result for 5 minutes (idempotency window).
            if ($idempotencyToken !== null) {
                Cache::put('api:branch_demand_reprice:' . $id . ':' . $idempotencyToken, $result, now()->addMinutes(5));
            }

            return response()->json($result);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('BranchDemand API reprice failed', ['demand_id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to reprice demand.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    // ===================== JSON HELPERS =====================

    /**
     * Get outstanding intercompany balances for my branch.
     *
     * GET /api/v1/branch-demands/outstanding
     */
    public function outstanding(): JsonResponse
    {
        $branchId = $this->currentBranchId();
        $outstanding = $this->intercompanyService->getOutstandingByBranch($branchId);

        return response()->json(['data' => $outstanding]);
    }

    /**
     * Get branch ledger history between two branches.
     *
     * GET /api/v1/branch-demands/ledger-history
     *
     * Query params:
     *   ?partner_branch_id= int (required)
     *   ?date_from=         date (optional)
     *   ?date_to=           date (optional)
     */
    public function ledgerHistory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'partner_branch_id' => 'required|integer|exists:branches,id',
            'date_from'         => 'nullable|date',
            'date_to'           => 'nullable|date',
        ]);

        $branchId = $this->currentBranchId();
        $partnerBranchId = (int) $validated['partner_branch_id'];

        $debtorBranchId = min($branchId, $partnerBranchId);
        $creditorBranchId = max($branchId, $partnerBranchId);

        $history = $this->intercompanyService->getLedgerHistory(
            $debtorBranchId,
            $creditorBranchId,
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null
        );

        return response()->json(['data' => $history]);
    }

    /**
     * Preview which demands would be settled for a given amount.
     *
     * GET /api/v1/branch-demands/settlement-preview
     *
     * Query params:
     *   ?partner_branch_id= int (required)
     *   ?amount=            numeric (required, min 0.01)
     */
    public function settlementPreview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'partner_branch_id' => 'required|integer|exists:branches,id',
            'amount'            => 'required|numeric|min:0.01',
        ]);

        $branchId = $this->currentBranchId();
        $partnerBranchId = (int) $validated['partner_branch_id'];

        $debtorBranchId = min($branchId, $partnerBranchId);
        $creditorBranchId = max($branchId, $partnerBranchId);

        $preview = $this->intercompanyService->previewDemandSettlement(
            $debtorBranchId,
            $creditorBranchId,
            (float) $validated['amount']
        );

        return response()->json(['data' => $preview]);
    }

    /**
     * Get the full audit trail for a specific demand.
     *
     * GET /api/v1/branch-demands/{id}/audit
     *
     * Returns: stock trace, settlement trace, GL journal blocks,
     * anti-gaming flags, repricing history, chronological audit log.
     */
    public function audit(int $id): JsonResponse
    {
        try {
            $auditData = $this->auditService->getDemandAudit($id);

            return response()->json(['data' => $auditData]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('BranchDemand API audit failed', ['demand_id' => $id, 'error' => $e->getMessage()]);
            return response()->json([
                'message' => 'Failed to load audit data.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get warehouses for a specific branch.
     *
     * GET /api/v1/branch-demands/warehouses/{branchId}
     */
    public function warehouses(int $branchId): JsonResponse
    {
        $warehouses = DB::table('warehouses')
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('warehouse_name')
            ->get(['id', 'warehouse_code', 'warehouse_name']);

        return response()->json(['data' => $warehouses]);
    }

    /**
     * Get warehouse-wise product stock for a branch.
     *
     * GET /api/v1/branch-demands/product-stock/{productId}/{branchId}
     *
     * Returns: per-warehouse stock with physical, available, pipeline, avg_cost.
     */
    public function productStock(int $productId, int $branchId): JsonResponse
    {
        $warehouses = DB::table('warehouses')
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('warehouse_name')
            ->get(['id', 'warehouse_code', 'warehouse_name']);

        $result = [];
        foreach ($warehouses as $wh) {
            $physical = $this->stockService->getWarehouseQty($wh->id, $productId);
            $available = $this->stockAvailabilityService->getWarehouseAvailableQty($productId, $wh->id);
            $avgCost = $this->stockService->getWarehouseAvgCost($wh->id, $productId);

            $result[] = [
                'warehouse_id'   => $wh->id,
                'warehouse_code' => $wh->warehouse_code,
                'warehouse_name' => $wh->warehouse_name,
                'physical_qty'   => round($physical, 4),
                'available_qty'  => round($available, 4),
                'pipeline_qty'   => round($physical - $available, 4),
                'avg_cost'       => round($avgCost, 4),
            ];
        }

        return response()->json(['data' => $result]);
    }

    // ===================== HELPERS =====================

    /**
     * Return a 404 JSON response.
     */
    private function notFound(string $detail): JsonResponse
    {
        return response()->json([
            'message' => 'Not Found.',
            'detail'  => $detail,
        ], 404);
    }
}

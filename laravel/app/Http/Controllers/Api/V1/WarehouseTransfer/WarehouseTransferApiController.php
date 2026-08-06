<?php

namespace App\Http\Controllers\Api\V1\WarehouseTransfer;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\SortsLists;
use App\Http\Resources\Api\V1\WarehouseTransfer\WarehouseTransferResource;
use App\Http\Requests\Api\V1\WarehouseTransfer\StoreWarehouseTransferRequest;
use App\Http\Requests\Api\V1\WarehouseTransfer\ConfirmWarehouseTransferRequest;
use App\Http\Requests\Api\V1\WarehouseTransfer\CancelWarehouseTransferRequest;
use App\Http\Requests\Api\V1\WarehouseTransfer\ProductStockRequest;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Services\Stock\StockAvailabilityService;
use App\Services\Stock\StockService;
use App\Services\Stock\WarehouseTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Warehouse Transfer API Controller — Phase 8 (API Routes & Mobile Support).
 *
 * Endpoints:
 *   GET    /api/v1/warehouse-transfers              List transfers (paginated, filterable)
 *   POST   /api/v1/warehouse-transfers              Create draft transfer
 *   GET    /api/v1/warehouse-transfers/{id}          Show transfer detail
 *   POST   /api/v1/warehouse-transfers/{id}/confirm  Confirm draft → applies stock
 *   POST   /api/v1/warehouse-transfers/{id}/cancel   Cancel/reverse a transfer
 *   GET    /api/v1/warehouse-transfers/product-stock  Get pipeline-aware stock for a product
 *
 * Reuses the same WarehouseTransferService as the web controller —
 * all Phase 1 (same-branch enforcement), Phase 2 (pipeline-aware availability),
 * Phase 3 (reversal safety), and Phase 4 (audit trail) protections are in force.
 *
 * Branch isolation: api.auth + set.api.branch middleware ensure that
 * non-admin users can only see/act on transfers within their own branch.
 * RLS policies on warehouse_transfers (via WarehouseTransferBranchScope)
 * enforce this at the database level as well.
 *
 * Rate limits:
 *   - Reads (list/show/product-stock): 60 req/min
 *   - Writes (store/confirm/cancel): 30 req/min
 *
 * Role enforcement:
 *   - Read: any authenticated user (warehouse_manager/dispatcher/salesman/manager/admin)
 *   - Store: any authenticated user (can create a draft)
 *   - Confirm: manager/admin (destructive — applies stock movements)
 *   - Cancel: manager/admin (destructive — may reverse stock movements)
 */
class WarehouseTransferApiController extends Controller
{
    use SortsLists;

    public function __construct(
        private WarehouseTransferService $transferService,
        private StockService $stockService,
        private StockAvailabilityService $stockAvailabilityService
    ) {}

    /**
     * List warehouse transfers with pagination and filters.
     *
     * GET /api/v1/warehouse-transfers
     *
     * Query params:
     *   ?from_date=          filter by transfer_date >=
     *   ?to_date=            filter by transfer_date <=
     *   ?from_warehouse_id=  filter by source warehouse
     *   ?to_warehouse_id=    filter by destination warehouse
     *   ?status=             filter by status (draft|confirmed|cancelled)
     *   ?search=             search transfer_code (ILIKE)
     *   ?per_page=           page size (default 25, max 100)
     *   ?page=               page number
     *   ?sort=               sort field (G-196). Whitelist:
     *                        id, transfer_code, transfer_date, status,
     *                        created_at. Unknown values silently fall back
     *                        to the default (transfer_date desc, id desc).
     *   ?order=              asc|desc (G-196). Default: desc.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));

        $query = WarehouseTransfer::with(['fromWarehouse', 'toWarehouse', 'fromBranch', 'toBranch', 'items', 'createdBy'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('transfer_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('transfer_date', '<=', $d))
            ->when($request->input('from_warehouse_id'), fn($q, $wid) => $q->where('from_warehouse_id', $wid))
            ->when($request->input('to_warehouse_id'), fn($q, $wid) => $q->where('to_warehouse_id', $wid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('transfer_code', 'ILIKE', "%{$search}%");
            });

        // G-196 (MEDIUM): sort convention — ?sort=field&order=asc|desc with
        // a per-endpoint whitelist. Default `transfer_date desc, id desc`
        // preserves the prior hard-coded behavior. See api-conventions.md §8.5.
        $query = $this->applySort(
            $query,
            ['id', 'transfer_code', 'transfer_date', 'status', 'created_at'],
            'transfer_date',
            'desc',
        );

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => WarehouseTransferResource::collection($paginator),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Create a draft warehouse transfer (no stock movement, no GL).
     *
     * POST /api/v1/warehouse-transfers
     *
     * Body:
     *   from_warehouse_id: int (required, must belong to user's branch)
     *   to_warehouse_id: int (required, must belong to same branch as from)
     *   transfer_date: string (Y-m-d, required)
     *   notes: string|null (optional)
     *   items: array (required, min 1)
     *     items[].product_id: int (required, must exist)
     *     items[].qty: numeric (required, min 0.001, must be available at source)
     *     items[].rate: numeric|null (optional — auto-filled from avg_cost if omitted)
     *
     * Phase 1 enforcement: both warehouses must belong to the same branch.
     * Phase 2 enforcement: pipeline-aware availability check on each item.
     */
    public function store(StoreWarehouseTransferRequest $request): JsonResponse
    {
        $user = Auth::user();
        $userBranchId = $user ? (int) $user->getBranchId() : null;

        $validated = $request->validated();

        // Idempotency replay check (PURCHASING-API-3, G-088/G-089/G-090).
        // Only engages when the client sends an `idempotency_token`; a
        // retry within 5 min returns the cached result instead of
        // creating a duplicate draft transfer. See api-conventions.md §11.1.
        $idempotencyToken = $validated['idempotency_token'] ?? null;
        if ($idempotencyToken !== null) {
            $cacheKey = 'api:warehouse_transfer:' . $idempotencyToken;
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return response()->json(array_merge($cached, [
                    'idempotent_replay' => true,
                ]));
            }
        }

        // Phase 1: Controller-level same-branch guard (defense-in-depth)
        $fromWarehouse = Warehouse::findOrFail($validated['from_warehouse_id']);
        $toWarehouse = Warehouse::findOrFail($validated['to_warehouse_id']);

        if ((int) $fromWarehouse->branch_id !== (int) $toWarehouse->branch_id) {
            Log::warning('Cross-branch warehouse transfer rejected at API controller level', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id'   => $toWarehouse->id,
                'from_branch_id'    => $fromWarehouse->branch_id,
                'to_branch_id'      => $toWarehouse->branch_id,
                'user_id'           => $user?->id,
            ]);

            return response()->json([
                'message' => 'Both warehouses must belong to the same branch. Cross-branch transfers must go through Branch Demand.',
                'errors'  => ['to_warehouse_id' => ['Cross-branch transfers not allowed.']],
            ], 422);
        }

        try {
            $transfer = $this->transferService->createTransfer([
                'from_warehouse_id' => $validated['from_warehouse_id'],
                'to_warehouse_id'   => $validated['to_warehouse_id'],
                'transfer_date'     => $validated['transfer_date'],
                'notes'             => $validated['notes'] ?? '',
                'items'             => $validated['items'],
                'created_by'        => $user?->id,
            ]);

            $transfer->load(['fromWarehouse', 'toWarehouse', 'fromBranch', 'toBranch', 'items.product', 'createdBy']);

            $result = [
                'data'    => new WarehouseTransferResource($transfer),
                'message' => "Draft transfer {$transfer->transfer_code} created. Review and confirm to apply.",
            ];

            // Cache the result for 5 minutes (idempotency window).
            if ($idempotencyToken !== null) {
                Cache::put('api:warehouse_transfer:' . $idempotencyToken, $result, now()->addMinutes(5));
            }

            return response()->json($result, 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create transfer.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Show a single transfer with full detail.
     *
     * GET /api/v1/warehouse-transfers/{id}
     *
     * Includes: header, items with product details, warehouse/branch info,
     * stock movements (if confirmed/reversed), GL journal references.
     */
    public function show(int $id): JsonResponse
    {
        $transfer = WarehouseTransfer::with([
            'items.product', 'fromWarehouse.branch', 'toWarehouse.branch',
            'fromBranch', 'toBranch', 'createdBy',
            'journalEntry.lines.ledger', 'debtorJournalEntry.lines.ledger',
        ])->find($id);

        if ($transfer === null) {
            return $this->notFound("Transfer {$id} not found.");
        }

        // Include stock movements if confirmed or reversed
        $stockMovements = [];
        if ($transfer->isConfirmed() || $transfer->is_reversed) {
            $stockMovements = DB::table('stock_transactions as st')
                ->join('products as p', 'p.id', '=', 'st.product_id')
                ->join('warehouses as w', 'w.id', '=', 'st.warehouse_id')
                ->where('st.reference_type', 'warehouse_transfer')
                ->where('st.reference_id', $id)
                ->select('st.id', 'st.product_id', 'st.warehouse_id', 'st.qty', 'st.rate',
                         'st.transaction_type', 'st.is_reversed',
                         'p.product_code', 'p.product_name', 'w.warehouse_name')
                ->orderBy('st.id')
                ->get()
                ->map(fn($row) => [
                    'id'              => $row->id,
                    'product_id'      => $row->product_id,
                    'product_code'    => $row->product_code,
                    'product_name'    => $row->product_name,
                    'warehouse_id'    => $row->warehouse_id,
                    'warehouse_name'  => $row->warehouse_name,
                    'qty'             => (float) $row->qty,
                    'rate'            => (float) $row->rate,
                    'transaction_type' => $row->transaction_type,
                    'is_reversed'     => (bool) $row->is_reversed,
                ])
                ->toArray();
        }

        $resource = (new WarehouseTransferResource($transfer))->toArray(request());
        $resource['stock_movements'] = $stockMovements;

        return response()->json(['data' => $resource]);
    }

    /**
     * Confirm a draft transfer — applies stock movements (source OUT + dest IN).
     *
     * POST /api/v1/warehouse-transfers/{id}/confirm
     *
     * Body (optional):
     *   confirm_reason: string|null (optional note about confirmation)
     *
     * Same-branch transfers: NO GL journal (just inventory reallocation).
     * Cross-branch confirm is blocked by service + controller + DB trigger.
     *
     * Requires: manager or admin role (destructive — applies stock).
     */
    public function confirm(ConfirmWarehouseTransferRequest $request, int $id): JsonResponse
    {
        // Validation handled by ConfirmWarehouseTransferRequest (G-208 / MEDIUM-WAVE-2-C).
        // $request->validated() is available if needed; this action takes no
        // validated input beyond the optional confirm_reason.

        // Phase 1: Defense-in-depth — check branch before confirming
        $transfer = WarehouseTransfer::find($id);

        if ($transfer === null) {
            return $this->notFound("Transfer {$id} not found.");
        }

        if ((int) $transfer->from_branch_id !== (int) $transfer->to_branch_id) {
            Log::warning('Cross-branch warehouse transfer confirm rejected at API controller level', [
                'transfer_id'     => $id,
                'from_branch_id'  => $transfer->from_branch_id,
                'to_branch_id'    => $transfer->to_branch_id,
                'user_id'         => Auth::id(),
            ]);

            return response()->json([
                'message' => 'Cross-branch transfers are not allowed. Use Branch Demand instead.',
            ], 403);
        }

        try {
            $transfer = $this->transferService->confirmTransfer($id, Auth::id());
            $transfer->load(['fromWarehouse', 'toWarehouse', 'fromBranch', 'toBranch', 'items.product', 'createdBy']);

            return response()->json([
                'data'    => new WarehouseTransferResource($transfer),
                'message' => "Transfer {$transfer->transfer_code} confirmed. Stock moved (same-branch — no intercompany GL).",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to confirm transfer.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Cancel/reverse a transfer.
     *
     * POST /api/v1/warehouse-transfers/{id}/cancel
     *
     * Body (required for confirmed transfers):
     *   cancel_reason: string (required, max 500)
     *
     * If draft: marks as cancelled (no stock reversal needed).
     * If confirmed: reverses stock movements (dest IN first, then source OUT —
     * Phase 3 reversal safety) and reverses any GL journals.
     *
     * Demand-linked transfers cannot be cancelled via this API —
     * they must be cancelled through the Branch Demand module.
     *
     * Requires: manager or admin role (destructive — may reverse stock/GL).
     */
    public function cancel(CancelWarehouseTransferRequest $request, int $id): JsonResponse
    {
        // Validation handled by CancelWarehouseTransferRequest (G-208 / MEDIUM-WAVE-2-C).
        $cancelReason = $request->validated()['cancel_reason'];

        try {
            $transfer = $this->transferService->cancelTransfer($id, Auth::id(), $cancelReason);
            $transfer->load(['fromWarehouse', 'toWarehouse', 'fromBranch', 'toBranch', 'items.product', 'createdBy']);

            return response()->json([
                'data'    => new WarehouseTransferResource($transfer),
                'message' => "Transfer {$transfer->transfer_code} cancelled.",
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to cancel transfer.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get pipeline-aware stock availability for a product at a warehouse.
     *
     * GET /api/v1/warehouse-transfers/product-stock
     *
     * Query params:
     *   ?product_id=   int (required)
     *   ?warehouse_id= int (required)
     *
     * Returns:
     *   rate:          current avg_cost at the warehouse
     *   physical_qty:  actual stock quantity
     *   available_qty: physical minus sales pipeline (pipeline-aware)
     *   pipeline_qty:  quantity reserved by pending sales invoices
     *
     * Phase 2: Uses StockAvailabilityService which subtracts the sales
     * pipeline (open invoice dispatches) from physical qty.
     */
    public function productStock(ProductStockRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $warehouseId = (int) $validated['warehouse_id'];
        $productId   = (int) $validated['product_id'];

        $rate         = $this->stockService->getWarehouseAvgCost($warehouseId, $productId);
        $physicalQty  = $this->stockService->getWarehouseQty($warehouseId, $productId);
        $availableQty = $this->stockAvailabilityService->getWarehouseAvailableQty($productId, $warehouseId);
        $pipelineQty  = max(0.0, $physicalQty - $availableQty);

        return response()->json([
            'data' => [
                'product_id'    => $productId,
                'warehouse_id'  => $warehouseId,
                'rate'          => round($rate, 2),
                'physical_qty'  => round($physicalQty, 4),
                'available_qty' => round($availableQty, 4),
                'pipeline_qty'  => round($pipelineQty, 4),
            ],
        ]);
    }

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

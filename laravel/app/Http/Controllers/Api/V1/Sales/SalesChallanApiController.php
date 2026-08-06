<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sales\CancelSalesChallanRequest;
use App\Http\Requests\Api\V1\Sales\PrepareGodownRequest;
use App\Http\Requests\Api\V1\Sales\IssueChallanRequest;
use App\Http\Resources\Api\V1\Sales\SalesChallanResource;
use App\Models\SalesChallan;
use App\Models\SalesInvoice;
use App\Services\Sales\SalesChallanService;
use App\Services\Sales\SalesAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Sales Challan API Controller — Mobile write endpoints.
 *
 * The challan workflow is a two-step process:
 *   1. Prepare godown: assign warehouse_id to invoice items + dispatches
 *      (status: draft → confirmed, is_godown_prepared=true)
 *   2. Issue challan: stock OUT + COGS GL journal
 *      (creates sales_challan, is_challan_issued=true)
 *
 * Endpoints:
 *   GET    /api/v1/sales/challans                   List challans
 *   GET    /api/v1/sales/challans/{id}              Show challan detail
 *   POST   /api/v1/sales/challans/godown            Prepare godown (step 1)
 *   POST   /api/v1/sales/challans/issue             Issue challan (step 2)
 *   POST   /api/v1/sales/challans/{id}/cancel       Cancel/reverse challan
 *
 * R3: The issue endpoint is idempotent — the client must send a UUID
 * idempotency_token. If the same token is seen within 5 minutes, the
 * previous result is returned without creating a duplicate challan
 * (mirrors finalize and payment-create patterns).
 */
class SalesChallanApiController extends Controller
{
    public function __construct(
        private SalesChallanService $challanService,
        private SalesAccess $salesAccess
    ) {}

    /**
     * List challans with filters.
     *
     * GET /api/v1/sales/challans
     */
    public function index(Request $request): JsonResponse
    {
        $query = SalesChallan::with(['salesInvoice', 'items'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('challan_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('challan_date', '<=', $d))
            ->when($request->input('sales_invoice_id'), fn($q, $id) => $q->where('sales_invoice_id', $id))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('is_reversed'), fn($q, $r) => $q->where('is_reversed', (bool) $r))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('challan_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('challan_date', 'desc')
            ->orderBy('id', 'desc');

        $perPage = min((int) ($request->input('per_page', 25)), 100);
        $challans = $query->paginate($perPage);

        return response()->json([
            'data' => SalesChallanResource::collection($challans),
            'meta' => [
                'current_page' => $challans->currentPage(),
                'last_page'    => $challans->lastPage(),
                'per_page'     => $challans->perPage(),
                'total'        => $challans->total(),
            ],
        ]);
    }

    /**
     * Show a single challan with items.
     *
     * GET /api/v1/sales/challans/{id}
     */
    public function show(int $id): JsonResponse
    {
        $challan = SalesChallan::with(['salesInvoice', 'items'])
            ->findOrFail($id);

        $this->salesAccess->assertBranchAccessible($challan->branch_id);

        return response()->json([
            'data' => new SalesChallanResource($challan),
        ]);
    }

    /**
     * Prepare godown — assign warehouse to invoice items (step 1).
     *
     * POST /api/v1/sales/challans/godown
     *
     * Takes a sales_invoice_id and an array of warehouse assignments.
     * Invoice must be in draft status. After this, invoice status → confirmed.
     *
     * Idempotency (PURCHASING-API-4, G7 Medium-risk): if the client
     * sends an `idempotency_token`, a retry within 5 min returns the
     * cached result instead of re-running prepareGodown (which would
     * otherwise hit the "invoice not draft" 409 path on the second
     * call). The token is optional (`sometimes`) so already-deployed
     * mobile clients that omit it are not broken. See api-conventions.md §11.1.
     */
    public function godown(PrepareGodownRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Idempotency replay check (only when token is present).
        $idempotencyToken = $validated['idempotency_token'] ?? null;
        if ($idempotencyToken !== null) {
            $cacheKey = 'api:challan_godown:' . $idempotencyToken;
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return response()->json(array_merge($cached, [
                    'idempotent_replay' => true,
                ]));
            }
        }

        $invoiceId = (int) ($request->input('sales_invoice_id') ?? 0);
        if ($invoiceId <= 0) {
            return response()->json(['message' => 'sales_invoice_id is required.'], 422);
        }

        $invoice = SalesInvoice::findOrFail($invoiceId);
        $this->salesAccess->assertBranchAccessible($invoice->branch_id);

        try {
            $result = $this->challanService->prepareGodown(
                $invoiceId,
                $validated['assignments'],
                Auth::id()
            );

            $response = [
                'message' => 'Godown prepared successfully',
                'data'    => [
                    'invoice_id'         => $invoiceId,
                    'is_godown_prepared' => true,
                    'status'             => 'confirmed',
                    'assignments'        => $validated['assignments'],
                ],
            ];

            // Cache the result for 5 minutes (idempotency window).
            if ($idempotencyToken !== null) {
                Cache::put('api:challan_godown:' . $idempotencyToken, $response, now()->addMinutes(5));
            }

            return response()->json($response);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Issue challan — stock OUT + COGS GL (step 2).
     *
     * POST /api/v1/sales/challans/issue
     *
     * The invoice must already be godown-prepared (confirmed status).
     * This creates the sales_challan, moves stock OUT at avg_cost,
     * and posts the COGS journal entry (Dr COGS / Cr Inventory).
     *
     * R3: Idempotency — if the same idempotency_token was processed
     * within 5 min, the cached result is returned without creating a
     * second challan (prevents duplicate on network retry / double-tap).
     * Mirrors the finalize pattern in SalesInvoiceApiController::store
     * and the payment pattern in CustomerPaymentApiController::store.
     */
    public function issue(IssueChallanRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // R3: Idempotency check — must run BEFORE any branch-access
        // check or service call so a replay is fully side-effect-free.
        $cacheKey = 'api:challan:' . $validated['idempotency_token'];
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            // Return the original response, flagged as a replay.
            return response()->json(array_merge($cached, [
                'idempotent_replay' => true,
                'message' => 'Duplicate submission detected — returning the original result. No new challan was created.',
            ]));
        }

        $invoice = SalesInvoice::findOrFail($validated['sales_invoice_id']);
        $this->salesAccess->assertBranchAccessible($invoice->branch_id);

        try {
            $challan = $this->challanService->issueChallan(
                $validated['sales_invoice_id'],
                array_merge($validated, [
                    'created_by' => Auth::id(),
                ])
            );

            $result = [
                'message' => 'Challan issued successfully',
                'data'    => new SalesChallanResource(
                    $challan->load(['salesInvoice', 'items'])
                ),
            ];

            // R3: Cache the result for 5 minutes (idempotency window —
            // same TTL as the API finalize + API payment patterns).
            Cache::put($cacheKey, $result, now()->addMinutes(5));

            return response()->json($result, 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Cancel/reverse a challan (append-only reversal).
     *
     * POST /api/v1/sales/challans/{id}/cancel
     */
    public function cancel(CancelSalesChallanRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        $challan = SalesChallan::findOrFail($id);
        $this->salesAccess->assertBranchAccessible($challan->branch_id);

        try {
            $this->challanService->cancelChallan($id, Auth::id(), $validated['reason']);

            return response()->json([
                'message' => 'Challan cancelled successfully',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }
}

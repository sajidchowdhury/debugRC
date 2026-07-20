<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
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
     */
    public function godown(PrepareGodownRequest $request): JsonResponse
    {
        $validated = $request->validated();

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

            return response()->json([
                'message' => 'Godown prepared successfully',
                'data'    => [
                    'invoice_id'         => $invoiceId,
                    'is_godown_prepared' => true,
                    'status'             => 'confirmed',
                    'assignments'        => $validated['assignments'],
                ],
            ]);
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
     */
    public function issue(IssueChallanRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $invoice = SalesInvoice::findOrFail($validated['sales_invoice_id']);
        $this->salesAccess->assertBranchAccessible($invoice->branch_id);

        try {
            $challan = $this->challanService->issueChallan(
                $validated['sales_invoice_id'],
                array_merge($validated, [
                    'created_by' => Auth::id(),
                ])
            );

            return response()->json([
                'message' => 'Challan issued successfully',
                'data'    => new SalesChallanResource(
                    $challan->load(['salesInvoice', 'items'])
                ),
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Cancel/reverse a challan (append-only reversal).
     *
     * POST /api/v1/sales/challans/{id}/cancel
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

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

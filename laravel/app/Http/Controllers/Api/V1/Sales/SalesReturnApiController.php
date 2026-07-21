<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sales\StoreReturnRequest;
use App\Http\Resources\Api\V1\Sales\SalesReturnResource;
use App\Models\SalesReturn;
use App\Services\Sales\SalesReturnService;
use App\Services\Sales\SalesAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sales Return API Controller — Mobile write endpoints.
 *
 * Two-phase workflow:
 *   1. Create (status=created): no stock movement, no GL
 *   2. Confirm (status=confirmed): stock IN at ORIGINAL avg_cost + GL reversal
 *
 * CRITICAL: stock comes back at ORIGINAL cost (not current avg_cost)
 * per avg_cost_rule.md §3. This ensures COGS reversal matches exactly.
 *
 * Endpoints:
 *   GET    /api/v1/sales/returns                    List returns
 *   GET    /api/v1/sales/returns/{id}               Show return detail
 *   POST   /api/v1/sales/returns                    Create a return
 *   POST   /api/v1/sales/returns/{id}/confirm       Confirm return (stock IN + GL)
 *   POST   /api/v1/sales/returns/{id}/reverse       Reverse a confirmed return
 *   GET    /api/v1/sales/returns/invoice-details     Get invoice items for return form
 */
class SalesReturnApiController extends Controller
{
    public function __construct(
        private SalesReturnService $returnService,
        private SalesAccess $salesAccess
    ) {}

    /**
     * List sales returns with filters.
     *
     * GET /api/v1/sales/returns
     */
    public function index(Request $request): JsonResponse
    {
        $query = SalesReturn::with(['customer', 'items'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('return_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('return_date', '<=', $d))
            ->when($request->input('customer_id'), fn($q, $cid) => $q->where('customer_id', $cid))
            ->when($request->input('sales_invoice_id'), fn($q, $id) => $q->where('sales_invoice_id', $id))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->orderBy('return_date', 'desc')
            ->orderBy('id', 'desc');

        $perPage = min((int) ($request->input('per_page', 25)), 100);
        $returns = $query->paginate($perPage);

        return response()->json([
            'data' => SalesReturnResource::collection($returns),
            'meta' => [
                'current_page' => $returns->currentPage(),
                'last_page'    => $returns->lastPage(),
                'per_page'     => $returns->perPage(),
                'total'        => $returns->total(),
            ],
        ]);
    }

    /**
     * Show a single return with items.
     *
     * GET /api/v1/sales/returns/{id}
     */
    public function show(int $id): JsonResponse
    {
        $return = SalesReturn::with(['customer', 'items', 'salesInvoice'])
            ->findOrFail($id);

        $this->salesAccess->assertBranchAccessible($return->branch_id);

        return response()->json([
            'data' => new SalesReturnResource($return),
        ]);
    }

    /**
     * Create a sales return (status=created, no stock/GL).
     *
     * POST /api/v1/sales/returns
     */
    public function store(StoreReturnRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $invoice = \App\Models\SalesInvoice::findOrFail($validated['sales_invoice_id']);
        $this->salesAccess->assertBranchAccessible($invoice->branch_id);

        try {
            $return = $this->returnService->createReturn(array_merge($validated, [
                'branch_id'  => $invoice->branch_id,
                'created_by' => Auth::id(),
            ]));

            return response()->json([
                'message' => 'Sales return created successfully',
                'data'    => new SalesReturnResource(
                    $return->load(['customer', 'items'])
                ),
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Confirm a return — stock IN at ORIGINAL cost + GL reversal.
     *
     * POST /api/v1/sales/returns/{id}/confirm
     */
    public function confirm(int $id): JsonResponse
    {
        $return = SalesReturn::findOrFail($id);
        $this->salesAccess->assertBranchAccessible($return->branch_id);

        try {
            $return = $this->returnService->confirmReturn($id, Auth::id());

            return response()->json([
                'message' => 'Sales return confirmed successfully',
                'data'    => new SalesReturnResource(
                    $return->load(['customer', 'items'])
                ),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Reverse a confirmed return.
     *
     * POST /api/v1/sales/returns/{id}/reverse
     */
    public function reverse(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

        $return = SalesReturn::findOrFail($id);
        $this->salesAccess->assertBranchAccessible($return->branch_id);

        try {
            $this->returnService->reverseReturn($id, Auth::id(), $validated['reason']);

            return response()->json([
                'message' => 'Sales return reversed successfully',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Get invoice details for creating a return.
     *
     * GET /api/v1/sales/returns/invoice-details?sales_invoice_id=X
     *
     * Returns the invoice's items with their challan info and original_cost
     * so the mobile app can pre-populate the return form.
     */
    public function invoiceDetails(Request $request): JsonResponse
    {
        $request->validate([
            'sales_invoice_id' => 'required|integer|exists:sales_invoices,id',
        ]);

        $invoiceId = (int) $request->input('sales_invoice_id');

        $invoice = \App\Models\SalesInvoice::with(['items', 'customer'])
            ->findOrFail($invoiceId);

        $this->salesAccess->assertBranchAccessible($invoice->branch_id);

        // Get the challan's stock transactions for original_cost lookup.
        $challan = \App\Models\SalesChallan::where('sales_invoice_id', $invoiceId)
            ->where('is_reversed', false)
            ->first();

        $items = $invoice->items->map(function ($item) use ($challan) {
            $originalCost = 0;
            if ($challan) {
                // Look up the original avg_cost from the stock_transaction for this challan.
                $stockTx = \App\Models\StockTransaction::forReference('sales_challan', $challan->id)
                    ->forProductInWarehouse(
                        $item->warehouse_id ?? 0,
                        $item->product_id
                    )
                    ->notReversed()
                    ->first();
                $originalCost = $stockTx ? (float) $stockTx->rate : 0;
            }

            return [
                'product_id'    => $item->product_id,
                'product_name'  => $item->product?->product_name ?? "Product #{$item->product_id}",
                'qty'           => (float) $item->qty,
                'rate'          => (float) $item->rate,
                'original_cost' => $originalCost,
                'warehouse_id'  => $item->warehouse_id,
            ];
        });

        return response()->json([
            'data' => [
                'invoice_id'   => $invoice->id,
                'invoice_code' => $invoice->invoice_code,
                'customer'     => [
                    'id'   => $invoice->customer?->id,
                    'name' => $invoice->customer?->customer_name,
                ],
                'challan_id' => $challan?->id,
                'items'      => $items,
            ],
        ]);
    }
}

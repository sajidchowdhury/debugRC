<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sales\FinalizeInvoiceRequest;
use App\Http\Resources\Api\V1\Sales\SalesInvoiceResource;
use App\Models\SalesInvoice;
use App\Services\Sales\SalesInvoiceService;
use App\Services\Sales\SalesAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Sales Invoice API Controller — Mobile write endpoints.
 *
 * Endpoints:
 *   GET    /api/v1/sales/invoices                 List invoices (paginated, filterable)
 *   GET    /api/v1/sales/invoices/{id}            Show invoice detail
 *   POST   /api/v1/sales/invoices                 Finalize cart → draft invoice
 *   PUT    /api/v1/sales/invoices/{id}            Update draft invoice
 *   POST   /api/v1/sales/invoices/{id}/cancel     Cancel/reverse an invoice
 *   POST   /api/v1/sales/invoices/call-it-a-day   Batch mark invoices as called
 *   GET    /api/v1/sales/invoices/credit-check    Check customer credit limit
 *
 * The finalize endpoint is idempotent: the client must send a UUID
 * idempotency_token. If the same token is seen within 5 minutes,
 * the previous result is returned without creating a duplicate invoice.
 */
class SalesInvoiceApiController extends Controller
{
    public function __construct(
        private SalesInvoiceService $invoiceService,
        private SalesAccess $salesAccess
    ) {}

    /**
     * List invoices with pagination and filters.
     *
     * GET /api/v1/sales/invoices
     */
    public function index(Request $request): JsonResponse
    {
        $query = SalesInvoice::with(['customer', 'items'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('invoice_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('invoice_date', '<=', $d))
            ->when($request->input('customer_id'), fn($q, $cid) => $q->where('customer_id', $cid))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('invoice_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('invoice_date', 'desc')
            ->orderBy('id', 'desc');

        $perPage = min((int) ($request->input('per_page', 25)), 100);
        $invoices = $query->paginate($perPage);

        return response()->json([
            'data'  => SalesInvoiceResource::collection($invoices),
            'meta'  => [
                'current_page' => $invoices->currentPage(),
                'last_page'    => $invoices->lastPage(),
                'per_page'     => $invoices->perPage(),
                'total'        => $invoices->total(),
            ],
        ]);
    }

    /**
     * Show a single invoice with full detail.
     *
     * GET /api/v1/sales/invoices/{id}
     */
    public function show(int $id): JsonResponse
    {
        $invoice = SalesInvoice::with(['customer', 'items', 'dispatches', 'dispatchers'])
            ->findOrFail($id);

        // Branch isolation check.
        $this->salesAccess->assertBranchAccessible($invoice->branch_id);

        return response()->json([
            'data' => new SalesInvoiceResource($invoice),
        ]);
    }

    /**
     * Finalize a cart into a draft sales invoice.
     *
     * POST /api/v1/sales/invoices
     *
     * Idempotency: if the same idempotency_token was processed within 5 min,
     * returns the cached result (prevents duplicate on network retry).
     */
    public function store(FinalizeInvoiceRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Idempotency check.
        $cacheKey = 'api:finalize:' . $validated['idempotency_token'];
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return response()->json(array_merge($cached, [
                'idempotent_replay' => true,
            ]));
        }

        try {
            $invoice = $this->invoiceService->finalizeFromCart(array_merge($validated, [
                'created_by' => Auth::id(),
            ]));

            $result = [
                'message' => 'Invoice created successfully',
                'data'    => new SalesInvoiceResource(
                    $invoice->load(['customer', 'items', 'dispatches'])
                ),
            ];

            // Cache the result for 5 minutes (idempotency window).
            Cache::put($cacheKey, $result, now()->addMinutes(5));

            return response()->json($result, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Update a draft invoice (recalculates totals + GL).
     *
     * PUT /api/v1/sales/invoices/{id}
     *
     * G2 fix (2026-09-01): the previous version validated only
     * discount/transport/notes/is_soft_hold and never passed `items[]` to
     * SalesInvoiceService::updateInvoice — which then threw
     * "Cannot update: items list is empty." on every call (mobile invoice
     * edit was broken). The endpoint now accepts the full item list
     * (product_id + qty + rate per line) plus the credit-limit-override +
     * dispatcher fields the service reads, mirroring the web update flow.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            // Line items — REQUIRED (the service throws if empty).
            'items'                 => 'required|array|min:1',
            'items.*.product_id'    => 'required|integer|exists:products,id',
            'items.*.qty'           => 'required|numeric|min:0.01',
            'items.*.rate'          => 'required|numeric|min:0',
            'items.*.condition_state' => 'nullable|string|in:Good,Damage',

            // Header-level fields the service consumes.
            'invoice_date'          => 'nullable|date',
            'discount_amount'       => 'nullable|numeric|min:0',
            'transport_cost'        => 'nullable|numeric|min:0',
            'notes'                 => 'nullable|string|max:1000',
            'is_soft_hold'          => 'nullable|boolean',

            // Credit-limit override (parity with FinalizeInvoiceRequest).
            'credit_limit_override' => 'nullable|boolean',
            'override_reason'       => 'nullable|string|min:10|max:500',

            // Dispatchers to (re)assign after edit (old dispatchers are cleared).
            'dispatcher_ids'        => 'nullable|array',
            'dispatcher_ids.*'      => 'integer|exists:employees,id',
        ]);

        $invoice = SalesInvoice::findOrFail($id);
        $this->salesAccess->assertBranchAccessible($invoice->branch_id);

        if (!$invoice->isDraft()) {
            return response()->json([
                'message' => 'Only draft invoices can be updated.',
            ], 409);
        }

        try {
            $invoice = $this->invoiceService->updateInvoice($id, array_merge($validated, [
                'updated_by' => Auth::id(),
            ]));

            return response()->json([
                'message' => 'Invoice updated successfully',
                'data'    => new SalesInvoiceResource(
                    $invoice->load(['customer', 'items', 'dispatches'])
                ),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Cancel/reverse an invoice.
     *
     * POST /api/v1/sales/invoices/{id}/cancel
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

        $invoice = SalesInvoice::findOrFail($id);
        $this->salesAccess->assertBranchAccessible($invoice->branch_id);

        try {
            $this->invoiceService->cancelInvoice($id, Auth::id(), $validated['reason']);

            return response()->json([
                'message' => 'Invoice cancelled successfully',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Batch "Call It A Day" — mark selected invoices as called.
     *
     * POST /api/v1/sales/invoices/call-it-a-day
     */
    public function callItADay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_ids'   => 'required|array|min:1',
            'invoice_ids.*' => 'integer|exists:sales_invoices,id',
        ]);

        $branchId = $this->resolveBranchId($request);

        try {
            $result = $this->invoiceService->callItADay(
                $validated['invoice_ids'],
                Auth::id(),
                $branchId
            );

            return response()->json([
                'message'       => "{$result['updated_count']} invoice(s) marked as called",
                'updated_count' => $result['updated_count'],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Check customer credit limit before finalizing.
     *
     * GET /api/v1/sales/invoices/credit-check?customer_id=X&amount=Y
     */
    public function creditCheck(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'amount'      => 'required|numeric|min:0',
        ]);

        // Delegate to the service's internal credit check.
        $customerId = (int) $validated['customer_id'];
        $amount = (float) $validated['amount'];

        $customer = \App\Models\Customer::findOrFail($customerId);

        // Calculate current AR balance from customer_ledger.
        $currentBalance = (float) \Illuminate\Support\Facades\DB::table('customer_ledger')
            ->where('customer_id', $customerId)
            ->where('is_reversed', false)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as balance')
            ->value('balance');

        $creditLimit = (float) ($customer->credit_limit ?? 0);
        $projectedBalance = $currentBalance + $amount;
        $exceeds = $creditLimit > 0 && $projectedBalance > $creditLimit;

        return response()->json([
            'customer_id'       => $customerId,
            'current_balance'   => round($currentBalance, 2),
            'credit_limit'      => round($creditLimit, 2),
            'new_invoice_amount' => round($amount, 2),
            'projected_balance' => round($projectedBalance, 2),
            'exceeds_limit'     => $exceeds,
            'available_credit'  => round(max(0, $creditLimit - $currentBalance), 2),
        ]);
    }

    /**
     * Resolve branch_id from the authenticated user.
     */
    private function resolveBranchId(Request $request): int
    {
        $user = Auth::user();
        $sessionBranchId = (int) (session('branch_id') ?? $user->getBranchId() ?? 0);

        if ($user->isAdmin()) {
            return (int) ($request->input('branch_id') ?? $sessionBranchId);
        }

        return $sessionBranchId;
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sales\StorePaymentRequest;
use App\Http\Resources\Api\V1\Sales\CustomerPaymentResource;
use App\Models\CustomerPayment;
use App\Services\Sales\CustomerPaymentService;
use App\Services\Sales\SalesAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Customer Payment API Controller — Mobile write endpoints.
 *
 * Two-phase workflow:
 *   1. Create draft payment (no GL, no ledger, no allocation)
 *   2. Confirm payment (GL + customer_ledger + allocation + intercompany)
 *
 * Supports auto_confirm flag: set auto_confirm=true to create+confirm
 * in a single request (common pattern for mobile cash payments).
 *
 * Transaction types:
 *   - receive:   Customer paying us → Dr Bank/Cash / Cr AR
 *   - discount:  Discount allowed → Dr Sales Discount / Cr AR
 *   - write_off: Bad debt write-off → Dr Bad Debt Expense / Cr AR
 *   - payment:   Refund to customer → Dr AR / Cr Bank/Cash
 *
 * Endpoints:
 *   GET    /api/v1/sales/payments                        List payments
 *   GET    /api/v1/sales/payments/{id}                   Show payment detail
 *   POST   /api/v1/sales/payments                        Create payment (draft or auto-confirm)
 *   POST   /api/v1/sales/payments/{id}/confirm           Confirm draft payment
 *   POST   /api/v1/sales/payments/{id}/cancel            Cancel/reverse payment
 *   GET    /api/v1/sales/payments/outstanding-invoices   Get customer's outstanding invoices
 *
 * R2: The store endpoint is idempotent — the client must send a UUID
 * idempotency_token. If the same token is seen within 5 minutes, the
 * previous result is returned without creating a duplicate payment.
 */
class CustomerPaymentApiController extends Controller
{
    public function __construct(
        private CustomerPaymentService $paymentService,
        private SalesAccess $salesAccess
    ) {}

    /**
     * List customer payments with filters.
     *
     * GET /api/v1/sales/payments
     */
    public function index(Request $request): JsonResponse
    {
        $query = CustomerPayment::with(['customer', 'bank', 'allocations.invoice'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('payment_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('payment_date', '<=', $d))
            ->when($request->input('customer_id'), fn($q, $cid) => $q->where('customer_id', $cid))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('payment_mode'), fn($q, $m) => $q->where('payment_mode', $m))
            ->when($request->input('transaction_type'), fn($q, $t) => $q->where('transaction_type', $t))
            ->when($request->input('is_reversed'), fn($q, $r) => $q->where('is_reversed', (bool) $r))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('payment_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('payment_date', 'desc')
            ->orderBy('id', 'desc');

        $perPage = min((int) ($request->input('per_page', 25)), 100);
        $payments = $query->paginate($perPage);

        return response()->json([
            'data' => CustomerPaymentResource::collection($payments),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page'    => $payments->lastPage(),
                'per_page'     => $payments->perPage(),
                'total'        => $payments->total(),
            ],
        ]);
    }

    /**
     * Show a single payment with allocations.
     *
     * GET /api/v1/sales/payments/{id}
     */
    public function show(int $id): JsonResponse
    {
        $payment = CustomerPayment::with(['customer', 'bank', 'allocations.invoice'])
            ->findOrFail($id);

        $this->salesAccess->assertBranchAccessible($payment->branch_id);

        return response()->json([
            'data' => new CustomerPaymentResource($payment),
        ]);
    }

    /**
     * Create a customer payment.
     *
     * POST /api/v1/sales/payments
     *
     * If auto_confirm=true, the payment is created AND confirmed in one
     * request (GL + customer_ledger + allocations all posted). This is
     * the most common pattern for mobile cash payments where the draft
     * state is not needed.
     *
     * If auto_confirm=false (or omitted), only the draft payment is created.
     * The client must then call POST /payments/{id}/confirm separately.
     *
     * R2: Idempotency — if the same idempotency_token was processed
     * within 5 min, the cached result is returned without creating a
     * second payment (prevents duplicate on network retry / double-tap).
     * Mirrors the finalize pattern in SalesInvoiceApiController::store.
     */
    public function store(StorePaymentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // R2: Idempotency check — must run BEFORE any branch-access
        // check or service call so a replay is fully side-effect-free.
        $cacheKey = 'api:payment:' . $validated['idempotency_token'];
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            // Return the original response, flagged as a replay.
            return response()->json(array_merge($cached, [
                'idempotent_replay' => true,
                'message' => 'Duplicate submission detected — returning the original result. No new payment was created.',
            ]));
        }

        $this->salesAccess->assertBranchAccessible((int) $validated['branch_id']);

        try {
            // Step 1: Create the draft payment.
            $payment = $this->paymentService->createPayment(array_merge($validated, [
                'created_by' => Auth::id(),
            ]));

            $autoConfirm = (bool) ($validated['auto_confirm'] ?? false);
            $allocations = $validated['allocations'] ?? [];

            // Step 2: Auto-confirm if requested.
            if ($autoConfirm) {
                $payment = $this->paymentService->confirmPayment(
                    $payment->id,
                    Auth::id(),
                    $allocations
                );
            }

            $payment->load(['customer', 'bank', 'allocations.invoice']);

            $result = [
                'message' => $autoConfirm
                    ? 'Payment created and confirmed successfully'
                    : 'Draft payment created successfully',
                'data' => new CustomerPaymentResource($payment),
                'confirmed' => $autoConfirm,
            ];

            // R2: Cache the result for 5 minutes (idempotency window —
            // same TTL as the API finalize pattern).
            Cache::put($cacheKey, $result, now()->addMinutes(5));

            return response()->json($result, 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Confirm a draft payment — GL + customer_ledger + allocation + intercompany.
     *
     * POST /api/v1/sales/payments/{id}/confirm
     */
    public function confirm(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'allocations'                     => 'nullable|array',
            'allocations.*.invoice_id'        => 'required_with:allocations|integer|exists:sales_invoices,id',
            'allocations.*.allocated_amount'  => 'required_with:allocations|numeric|min:0.01',
        ]);

        $payment = CustomerPayment::findOrFail($id);
        $this->salesAccess->assertBranchAccessible($payment->branch_id);

        try {
            $payment = $this->paymentService->confirmPayment(
                $id,
                Auth::id(),
                $validated['allocations'] ?? []
            );

            return response()->json([
                'message' => 'Payment confirmed successfully',
                'data'    => new CustomerPaymentResource(
                    $payment->load(['customer', 'bank', 'allocations.invoice'])
                ),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Cancel/reverse a payment.
     *
     * POST /api/v1/sales/payments/{id}/cancel
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            // R27 (2026-07-22): min:5 parity with Legacy
            // SalesPaymentOperationsTrait::reverseCustomerPayment() —
            // `if (strlen($reason) < 5) { return error; }`.
            // (Previously min:10 — relaxed to match Legacy exactly.)
            'reason' => 'required|string|min:5|max:500',
        ]);

        $payment = CustomerPayment::findOrFail($id);
        $this->salesAccess->assertBranchAccessible($payment->branch_id);

        try {
            $this->paymentService->cancelPayment($id, Auth::id(), $validated['reason']);

            return response()->json([
                'message' => 'Payment cancelled successfully',
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    /**
     * Get outstanding invoices for a customer.
     *
     * GET /api/v1/sales/payments/outstanding-invoices?customer_id=X
     *
     * Returns invoices with due_amount > 0, ordered by invoice_date ASC
     * (FIFO allocation order).
     */
    public function outstandingInvoices(Request $request): JsonResponse
    {
        $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
        ]);

        $customerId = (int) $request->input('customer_id');

        $invoices = \App\Models\SalesInvoice::where('customer_id', $customerId)
            ->whereNotIn('status', ['cancelled'])
            ->where('is_reversed', false)
            ->whereColumn('paid_amount', '<', 'total_amount')
            ->orderBy('invoice_date', 'asc')
            ->orderBy('id', 'asc')
            ->get(['id', 'invoice_code', 'invoice_date', 'total_amount', 'paid_amount', 'due_amount', 'status']);

        return response()->json([
            'data' => $invoices->map(fn($inv) => [
                'id'            => $inv->id,
                'invoice_code'  => $inv->invoice_code,
                'invoice_date'  => $inv->invoice_date?->format('Y-m-d'),
                'total_amount'  => (float) $inv->total_amount,
                'paid_amount'   => (float) $inv->paid_amount,
                'due_amount'    => (float) $inv->due_amount,
                'status'        => $inv->status,
            ]),
        ]);
    }
}

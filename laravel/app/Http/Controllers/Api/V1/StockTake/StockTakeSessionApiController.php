<?php

namespace App\Http\Controllers\Api\V1\StockTake;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StockTake\ApproveSessionRequest;
use App\Http\Requests\Api\V1\StockTake\ImportCountsRequest;
use App\Http\Requests\Api\V1\StockTake\ReasonRequest;
use App\Http\Requests\Api\V1\StockTake\SaveCountsRequest;
use App\Http\Requests\Api\V1\StockTake\StoreSessionRequest;
use App\Http\Resources\Api\V1\StockTake\StockTakeSessionResource;
use App\Models\StockTakeSession;
use App\Services\Stock\StockTakePolicyService;
use App\Services\Stock\StockTakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Stock Take Session API Controller — Phase 11 (API + mobile foundation).
 *
 * Exposes the full stock-take lifecycle as a versioned REST API so a future
 * mobile count app + third-party integrations can drive the feature without
 * the web UI. Every web flow is reproducible here.
 *
 * Endpoints (all under /api/v1/stock-take, all behind api.auth + set.api.branch
 * + api.rate):
 *
 *   GET    /sessions                          List sessions (paginated + filtered)
 *   POST   /sessions                          Create a session (draft)
 *   GET    /sessions/{id}                     Show session detail
 *   POST   /sessions/{id}/setup/{warehouse}   Set up counts for a warehouse
 *   PUT    /sessions/{id}/counts/{warehouse}  Save physical counts for a warehouse
 *   POST   /sessions/{id}/import/{warehouse}  Import counts via CSV (multipart)
 *   POST   /sessions/{id}/submit              Submit for approval
 *   POST   /sessions/{id}/approve             Approve (segregation-of-duties enforced)
 *   POST   /sessions/{id}/reject              Reject (returns to counting)
 *   POST   /sessions/{id}/post                Post — apply variances + GL
 *   POST   /sessions/{id}/cancel              Cancel (draft/counting only — no reversal)
 *   POST   /sessions/{id}/reverse             Reverse (posted only — full stock + GL reversal)
 *   POST   /sessions/{id}/re-open             Re-open (reversed → counting; max_reopens cap)
 *
 * (The variance report + item-level reads live on StockTakeItemApiController.)
 *
 * Branch isolation: the set.api.branch middleware sets the app.branch_id GUC
 * (consumed by RLS) based on the authenticated user's branch. Non-admins see
 * only their own branch; admins see all. The service layer re-checks branch
 * access on writes (defense in depth).
 *
 * Rate limits: reads 60 req/min, writes 30 req/min (set on the routes).
 */
class StockTakeSessionApiController extends Controller
{
    public function __construct(
        private StockTakeService $stockTakeService,
        private StockTakePolicyService $policyService
    ) {}

    /**
     * List sessions with filters.
     *
     * GET /api/v1/stock-take/sessions
     */
    public function index(Request $request): JsonResponse
    {
        $query = StockTakeSession::with(['branch', 'warehouses'])
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('from_date'), fn($q, $d) => $q->where('session_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('session_date', '<=', $d))
            ->when($request->input('search'), function ($q, $s) {
                $q->where('session_code', 'ilike', "%{$s}%")
                  ->orWhere('notes', 'ilike', "%{$s}%");
            })
            ->orderBy('session_date', 'desc')
            ->orderBy('id', 'desc');

        $perPage = min((int) ($request->input('per_page', 25)), 100);
        $sessions = $query->paginate($perPage);

        return response()->json([
            'data' => StockTakeSessionResource::collection($sessions),
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'last_page'    => $sessions->lastPage(),
                'per_page'     => $sessions->perPage(),
                'total'        => $sessions->total(),
            ],
        ]);
    }

    /**
     * Create a new session (draft).
     *
     * POST /api/v1/stock-take/sessions
     *
     * Idempotency (PURCHASING-API-4, G7 Medium-risk): if the client
     * sends an `idempotency_token`, a retry within 5 min returns the
     * cached result instead of creating a duplicate draft session.
     * The token is optional (`sometimes`) so already-deployed mobile
     * clients that omit it are not broken. See api-conventions.md §11.1.
     */
    public function store(StoreSessionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Idempotency replay check (only when token is present).
        $idempotencyToken = $validated['idempotency_token'] ?? null;
        if ($idempotencyToken !== null) {
            $cacheKey = 'api:stock_take_session:' . $idempotencyToken;
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return response()->json(array_merge($cached, [
                    'idempotent_replay' => true,
                ]));
            }
        }

        try {
            $session = $this->stockTakeService->createSession([
                'branch_id'            => $validated['branch_id'],
                'session_date'         => $validated['session_date'],
                'warehouse_ids'        => $validated['warehouse_ids'],
                'notes'                => $validated['notes'] ?? '',
                'freeze_outbound'      => (bool) ($validated['freeze_outbound'] ?? false),
                'count_scope'          => $validated['count_scope'] ?? 'full',
                'count_scope_payload'  => $validated['count_scope_payload'] ?? null,
                'created_by'           => Auth::id(),
            ]);

            $result = [
                'message' => "Session {$session->session_code} created.",
                'data'    => new StockTakeSessionResource($session->load(['branch', 'warehouses'])),
            ];

            // Cache the result for 5 minutes (idempotency window).
            if ($idempotencyToken !== null) {
                Cache::put('api:stock_take_session:' . $idempotencyToken, $result, now()->addMinutes(5));
            }

            return response()->json($result, 201);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Show a single session with warehouses + items + variance context.
     *
     * GET /api/v1/stock-take/sessions/{id}
     */
    public function show(int $id): JsonResponse
    {
        $session = StockTakeSession::with(['branch', 'warehouses.warehouse', 'items.product'])
            ->findOrFail($id);

        return response()->json([
            'data' => new StockTakeSessionResource($session),
        ]);
    }

    /**
     * Set up counts for a warehouse (loads products into stock_take_items).
     *
     * POST /api/v1/stock-take/sessions/{id}/setup/{warehouse}
     *
     * Returns the number of product lines loaded.
     */
    public function setup(int $id, int $warehouseId): JsonResponse
    {
        try {
            $count = $this->stockTakeService->setupWarehouseCounts($id, $warehouseId);

            return response()->json([
                'message'     => "{$count} product lines loaded for counting.",
                'item_count'  => $count,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Save physical counts for a warehouse.
     *
     * PUT /api/v1/stock-take/sessions/{id}/counts/{warehouse}
     *
     * Body: { "counts": { "<product_id>": <qty>, ... }, "reasons": { ... } }
     */
    public function saveCounts(SaveCountsRequest $request, int $id, int $warehouseId): JsonResponse
    {
        $validated = $request->validated();

        try {
            $updated = $this->stockTakeService->saveCounts($id, $warehouseId, $validated['counts']);

            // Save per-line reasons (mirrors the web controller).
            if (!empty($validated['reasons'])) {
                foreach ($validated['reasons'] as $productId => $reason) {
                    DB::table('stock_take_items')
                        ->where('stock_take_session_id', $id)
                        ->where('warehouse_id', $warehouseId)
                        ->where('product_id', (int) $productId)
                        ->update(['reason' => $reason]);
                }
            }

            return response()->json([
                'message' => "{$updated} product count(s) saved.",
                'updated' => $updated,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Import counts via CSV (multipart upload).
     *
     * POST /api/v1/stock-take/sessions/{id}/import/{warehouse}
     *
     * Body: multipart form-data with field `csv_file` (file: CSV).
     * Columns: product_code, physical_qty [, reason].
     */
    public function importCounts(ImportCountsRequest $request, int $id, int $warehouseId): JsonResponse
    {
        $file = $request->file('csv_file');
        $raw = file_get_contents($file->getRealPath());
        if ($raw === false || $raw === '') {
            return response()->json(['message' => 'The uploaded CSV file is empty.'], 422);
        }

        // Strip BOM (Excel exports often start with one).
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        $parsed = $this->parseCsv($raw);
        if (isset($parsed['error'])) {
            return response()->json(['message' => $parsed['error']], 422);
        }

        try {
            $result = $this->stockTakeService->bulkUpsertCounts(
                $id,
                $warehouseId,
                $parsed['lines'],
                Auth::id(),
                'api_csv_import'
            );

            return response()->json([
                'message' => "CSV import: {$result['updated']} updated, {$result['skipped']} skipped.",
                'updated' => $result['updated'],
                'skipped' => $result['skipped'],
                'errors'  => $result['errors'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Submit a counting session for approval.
     *
     * POST /api/v1/stock-take/sessions/{id}/submit
     */
    public function submit(int $id): JsonResponse
    {
        try {
            $session = $this->stockTakeService->submit($id, Auth::id());

            return response()->json([
                'message' => "Session {$session->session_code} submitted for approval.",
                'data'    => new StockTakeSessionResource($session->load(['branch', 'warehouses'])),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Approve a submitted session (segregation of duties enforced by the service).
     *
     * POST /api/v1/stock-take/sessions/{id}/approve
     */
    public function approve(ApproveSessionRequest $request, int $id): JsonResponse
    {
        try {
            $session = $this->stockTakeService->approve(
                $id,
                Auth::id(),
                $request->input('approval_comments', '')
            );

            return response()->json([
                'message' => "Session {$session->session_code} approved. You can now post it.",
                'data'    => new StockTakeSessionResource($session->load(['branch', 'warehouses'])),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Reject a submitted session (returns to counting). Reason required.
     *
     * POST /api/v1/stock-take/sessions/{id}/reject
     */
    public function reject(ReasonRequest $request, int $id): JsonResponse
    {
        try {
            $session = $this->stockTakeService->reject($id, Auth::id(), $request->getReason());

            return response()->json([
                'message' => "Session {$session->session_code} rejected and returned to counting.",
                'data'    => new StockTakeSessionResource($session->load(['branch', 'warehouses'])),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Post the session — apply variances + post the GL journal entry.
     *
     * POST /api/v1/stock-take/sessions/{id}/post
     */
    public function post(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'post_reason' => 'nullable|string|max:500',
        ]);

        try {
            $session = $this->stockTakeService->postSession($id, Auth::id());

            return response()->json([
                'message' => "Session {$session->session_code} posted. Variances applied + GL entry created.",
                'data'    => new StockTakeSessionResource($session->load(['branch', 'warehouses'])),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Cancel a draft/counting/submitted/approved session (no reversal).
     *
     * POST /api/v1/stock-take/sessions/{id}/cancel
     */
    public function cancel(ReasonRequest $request, int $id): JsonResponse
    {
        try {
            $session = $this->stockTakeService->cancelSession($id, Auth::id(), $request->getReason());

            return response()->json([
                'message' => "Session {$session->session_code} cancelled.",
                'data'    => new StockTakeSessionResource($session->load(['branch', 'warehouses'])),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Reverse a POSTED session (full stock + GL reversal). Phase 10.
     *
     * POST /api/v1/stock-take/sessions/{id}/reverse
     */
    public function reverse(ReasonRequest $request, int $id): JsonResponse
    {
        try {
            $session = $this->stockTakeService->reverseSession($id, Auth::id(), $request->getReason());

            return response()->json([
                'message' => "Session {$session->session_code} reversed. Stock movements + GL entry undone.",
                'data'    => new StockTakeSessionResource($session->load(['branch', 'warehouses'])),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Re-open a REVERSED session for correction + re-posting. Phase 10.
     *
     * POST /api/v1/stock-take/sessions/{id}/re-open
     */
    public function reOpen(ReasonRequest $request, int $id): JsonResponse
    {
        try {
            $session = $this->stockTakeService->reOpen($id, Auth::id(), $request->getReason());

            $remaining = max(0, $this->policyService->maxReopens() - (int) $session->re_open_count);

            return response()->json([
                'message' => "Session {$session->session_code} re-opened. Reversal preserved as audit history; correct counts and re-post.",
                'data'    => new StockTakeSessionResource($session->load(['branch', 'warehouses'])),
                'reopens_remaining' => $remaining,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Parse a CSV string into product_code → physical_qty lines.
     * Mirrors StockTakeController::parseCsv (kept private there for the web).
     */
    private function parseCsv(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        if ($lines === false || count($lines) < 2) {
            return ['error' => 'CSV must have a header row and at least one data row.'];
        }
        $header = array_map(fn($h) => trim(strtolower(str_replace([' ', '-'], '_', $h))), str_getcsv(array_shift($lines)));
        $codeIdx = array_search('product_code', $header, true);
        $qtyIdx  = array_search('physical_qty', $header, true);
        if ($codeIdx === false || $qtyIdx === false) {
            return ['error' => 'CSV header must contain product_code and physical_qty columns (found: ' . implode(', ', $header) . ').'];
        }
        $reasonIdx = array_search('reason', $header, true);

        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $code = isset($cols[$codeIdx]) ? trim($cols[$codeIdx]) : '';
            $qty  = isset($cols[$qtyIdx]) ? trim($cols[$qtyIdx]) : '';
            $reason = ($reasonIdx !== false && isset($cols[$reasonIdx])) ? trim($cols[$reasonIdx]) : null;
            if ($code === '') {
                continue;
            }
            $out[] = [
                'code'   => $code,
                'qty'    => $qty,
                'reason' => $reason !== '' ? $reason : null,
            ];
        }
        if (empty($out)) {
            return ['error' => 'No data rows found in the CSV after the header.'];
        }
        return ['lines' => $out];
    }
}

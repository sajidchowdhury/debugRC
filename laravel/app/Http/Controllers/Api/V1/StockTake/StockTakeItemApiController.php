<?php

namespace App\Http\Controllers\Api\V1\StockTake;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StockTake\UpdateStockTakeItemRequest;
use App\Models\StockTakeSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stock Take Item API Controller — Phase 11 (API + mobile foundation).
 *
 * Provides per-item read access for the count screen + the variance view.
 * The count-save flow lives on the session controller (PUT /counts/{warehouse});
 * this controller is read-only + single-line update (autosave pattern).
 *
 * Endpoints (all under /api/v1/stock-take, behind api.auth + set.api.branch):
 *
 *   GET    /sessions/{id}/items                  List items for a session
 *                                                (?warehouse_id=X filter,
 *                                                 ?variance_only=1 for variance lines)
 *   GET    /sessions/{id}/items/{itemId}         Show a single item line
 *   PUT    /sessions/{id}/items/{itemId}         Update one item (autosave pattern)
 *   GET    /sessions/{id}/variance               Variance report (items with non-zero diff)
 *
 * The PUT single-item update uses the same StockTakeService::saveCounts path
 * as the bulk save (it just wraps a single product_id → qty pair), so the
 * service's guards (session in counting state, product in the warehouse's
 * item set, etc.) all fire identically.
 */
class StockTakeItemApiController extends Controller
{
    public function __construct(
        private \App\Services\Stock\StockTakeService $stockTakeService
    ) {}

    /**
     * List items for a session, optionally filtered by warehouse + variance-only.
     *
     * GET /api/v1/stock-take/sessions/{id}/items
     *
     * Query params:
     *   warehouse_id  — filter to one warehouse
     *   variance_only — 1 = only items with physical_qty <> system_qty
     *   per_page      — page size (default 100, max 500 — count screens are big)
     */
    public function index(Request $request, int $sessionId): JsonResponse
    {
        // Verify the session exists (RLS scopes by branch).
        StockTakeSession::findOrFail($sessionId);

        $query = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sessionId)
            ->join('products', 'products.id', '=', 'stock_take_items.product_id')
            ->select('stock_take_items.*', 'products.product_name', 'products.product_code');

        if ($request->filled('warehouse_id')) {
            $query->where('stock_take_items.warehouse_id', (int) $request->input('warehouse_id'));
        }
        if ($request->boolean('variance_only')) {
            $query->whereRaw('physical_qty <> system_qty');
        }

        $perPage = min((int) ($request->input('per_page', 100)), 500);
        $items = $query->orderBy('stock_take_items.warehouse_id')
            ->orderBy('products.product_code')
            ->paginate($perPage);

        // Wrap in a minimal resource-like shape. We use a collection wrapper
        // because the items are stdClass (from DB::table), not Eloquent models
        // — StockTakeItemResource works on either (it reads attributes via
        // property access).
        return response()->json([
            'data' => $items->getCollection()->map(fn ($i) => $this->itemToArray($i)),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'per_page'     => $items->perPage(),
                'total'        => $items->total(),
            ],
        ]);
    }

    /**
     * Show a single item line.
     *
     * GET /api/v1/stock-take/sessions/{id}/items/{itemId}
     */
    public function show(int $sessionId, int $itemId): JsonResponse
    {
        $item = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sessionId)
            ->where('id', $itemId)
            ->join('products', 'products.id', '=', 'stock_take_items.product_id')
            ->select('stock_take_items.*', 'products.product_name', 'products.product_code')
            ->first();

        if (!$item) {
            return response()->json(['message' => 'Item not found.'], 404);
        }

        return response()->json([
            'data' => $this->itemToArray($item),
        ]);
    }

    /**
     * Update a single item's physical_qty (autosave pattern for mobile).
     *
     * PUT /api/v1/stock-take/sessions/{id}/items/{itemId}
     *
     * Body: { "physical_qty": 48, "reason": "optional variance reason" }
     *
     * Routes through StockTakeService::saveCounts (single-item variant) so all
     * the service guards fire (session in counting state, product in the
     * warehouse's item set, etc.). Returns the updated line + the new
     * updated_at (for optimistic-concurrency control on the client).
     */
    public function update(UpdateStockTakeItemRequest $request, int $sessionId, int $itemId): JsonResponse
    {
        $validated = $request->validated();

        // Look up the item to get its warehouse_id + product_id (the service's
        // saveCounts is keyed by warehouse + product_id, not by item id).
        $item = DB::table('stock_take_items')
            ->where('id', $itemId)
            ->where('stock_take_session_id', $sessionId)
            ->first();

        if (!$item) {
            return response()->json(['message' => 'Item not found.'], 404);
        }

        try {
            $this->stockTakeService->saveCounts(
                $sessionId,
                $item->warehouse_id,
                [$item->product_id => $validated['physical_qty']]
            );

            // Save the optional reason.
            if (!empty($validated['reason'])) {
                DB::table('stock_take_items')
                    ->where('id', $itemId)
                    ->update(['reason' => $validated['reason'], 'updated_at' => now()]);
            }

            // Re-fetch the updated row.
            $updated = DB::table('stock_take_items')
                ->where('id', $itemId)
                ->join('products', 'products.id', '=', 'stock_take_items.product_id')
                ->select('stock_take_items.*', 'products.product_name', 'products.product_code')
                ->first();

            return response()->json([
                'message' => 'Count saved.',
                'data'    => $this->itemToArray($updated),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Variance report — items with non-zero difference, sorted by |difference| desc.
     *
     * GET /api/v1/stock-take/sessions/{id}/variance
     *
     * Returns the variance lines + a summary (total_gain, total_loss, net_value).
     * Builds the array directly (not via StockTakeVarianceResource) because the
     * query uses DB::table (stdClass rows, no Eloquent relations for whenLoaded).
     */
    public function variance(int $sessionId): JsonResponse
    {
        $session = StockTakeSession::findOrFail($sessionId);

        $items = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sessionId)
            ->whereRaw('physical_qty <> system_qty')
            ->join('products', 'products.id', '=', 'stock_take_items.product_id')
            ->select('stock_take_items.*', 'products.product_name', 'products.product_code')
            ->orderByRaw('ABS(difference) DESC')
            ->get();

        $data = $items->map(function ($i) {
            $systemQty = (float) ($i->system_qty ?? 0);
            $physicalQty = (float) ($i->physical_qty ?? 0);
            $difference = $physicalQty - $systemQty;
            $postRate = (float) ($i->post_rate ?? $i->rate ?? 0);

            return [
                'id'                  => $i->id,
                'product_id'          => $i->product_id,
                'product'             => [
                    'id'   => $i->product_id,
                    'name' => $i->product_name,
                    'code' => $i->product_code,
                ],
                'warehouse_id'        => $i->warehouse_id,
                'system_qty'          => $systemQty,
                'physical_qty'        => $physicalQty,
                'difference'          => round($difference, 6),
                'variance_type'       => $difference > 0 ? 'gain' : ($difference < 0 ? 'loss' : 'none'),
                'value_diff'          => round($difference * $postRate, 4),
                'system_rate'         => $i->system_rate !== null ? (float) $i->system_rate : null,
                'post_rate'           => $i->post_rate !== null ? (float) $i->post_rate : null,
                'revaluation_amount'  => (float) ($i->revaluation_amount ?? 0),
                'journal_line_id'     => $i->journal_line_id ?? null,
                'revaluation_line_id' => $i->revaluation_line_id ?? null,
            ];
        })->values();

        $totalGain = $items->where('difference', '>', 0)
            ->sum(fn ($i) => abs((float) $i->difference) * (float) ($i->post_rate ?? $i->rate ?? 0));
        $totalLoss = $items->where('difference', '<', 0)
            ->sum(fn ($i) => abs((float) $i->difference) * (float) ($i->post_rate ?? $i->rate ?? 0));

        return response()->json([
            'data' => $data,
            'meta' => [
                'session_id'     => $sessionId,
                'session_code'   => $session->session_code,
                'status'         => $session->status,
                'variance_lines' => $items->count(),
                'total_gain'     => round($totalGain, 4),
                'total_loss'     => round($totalLoss, 4),
                'net_value'      => round($totalGain - $totalLoss, 4),
            ],
        ]);
    }

    /**
     * Convert a DB::table row (stdClass) to the StockTakeItemResource shape.
     *
     * StockTakeItemResource reads via property access, which works on stdClass
     * — but the `product` nested object needs to be present for the
     * `whenLoaded('product')` branch. Since DB::table doesn't hydrate
     * relations, we synthesize the product object from the joined columns.
     */
    private function itemToArray($item): array
    {
        $systemQty = (float) ($item->system_qty ?? 0);
        $physicalQty = $item->physical_qty !== null ? (float) $item->physical_qty : null;
        $difference = $physicalQty !== null ? ($physicalQty - $systemQty) : null;
        $rate = (float) ($item->rate ?? 0);
        $valueDiff = $difference !== null ? round($difference * $rate, 4) : null;

        return [
            'id'                    => $item->id,
            'stock_take_session_id' => $item->stock_take_session_id,
            'warehouse_id'          => $item->warehouse_id,
            'product_id'            => $item->product_id,
            'product'               => [
                'id'   => $item->product_id,
                'name' => $item->product_name ?? null,
                'code' => $item->product_code ?? null,
            ],
            'system_qty'            => $systemQty,
            'physical_qty'          => $physicalQty,
            'difference'            => $difference,
            'has_variance'          => $difference !== null && abs($difference) > 0.000001,
            'value_diff'            => $valueDiff,
            'rate'                  => $rate,
            'system_rate'           => $this->numOrNull($item->system_rate ?? null),
            'post_rate'             => $this->numOrNull($item->post_rate ?? null),
            'revaluation_amount'    => (float) ($item->revaluation_amount ?? 0),
            'journal_line_id'       => $item->journal_line_id ?? null,
            'revaluation_line_id'   => $item->revaluation_line_id ?? null,
            'is_applied'            => (bool) ($item->is_applied ?? false),
            'reason'                => $item->reason ?? null,
            'updated_at'            => isset($item->updated_at) ? \Illuminate\Support\Carbon::parse($item->updated_at)->toIso8601String() : null,
        ];
    }

    private function numOrNull($v): ?float
    {
        return $v !== null ? (float) $v : null;
    }
}

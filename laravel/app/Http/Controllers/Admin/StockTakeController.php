<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StockTakeSession;
use App\Services\Stock\StockTakeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stock Take Controller — Phase 6.4.
 *
 * Workflow:
 *   - index: list sessions
 *   - create / store: create a new session (draft) with selected warehouses
 *   - show: session detail with warehouses + items + variance + GL
 *   - setupCounts: load products for a warehouse (AJAX or route)
 *   - count: enter physical counts for a warehouse
 *   - saveCounts: save the counts (AJAX or POST)
 *   - post: apply variances + post GL
 *   - cancel: reverse if posted, or just mark cancelled
 */
class StockTakeController extends Controller
{
    public function __construct(
        private StockTakeService $stockTakeService
    ) {}

    public function index(Request $request)
    {
        $query = StockTakeSession::with(['branch', 'warehouses.warehouse'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('session_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('session_date', '<=', $d))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('session_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('session_date', 'desc')
            ->orderBy('id', 'desc');

        $sessions = $query->paginate(25);
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        $stats = [
            'total' => StockTakeSession::count(),
            'draft' => StockTakeSession::where('status', 'draft')->count(),
            'counting' => StockTakeSession::where('status', 'counting')->count(),
            'posted' => StockTakeSession::where('status', 'posted')->count(),
            'cancelled' => StockTakeSession::where('status', 'cancelled')->count(),
        ];

        return view('admin.stock-take.index', [
            'title' => 'Stock Take Sessions',
            'sessions' => $sessions,
            'branches' => $branches,
            'stats' => $stats,
            'filters' => $request->only(['from_date', 'to_date', 'status', 'branch_id', 'search']),
        ]);
    }

    public function create()
    {
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();
        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();

        return view('admin.stock-take.create', [
            'title' => 'New Stock Take Session',
            'branches' => $branches,
            'warehouses' => $warehouses,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id' => 'required|integer|exists:branches,id',
            'session_date' => 'required|date',
            'warehouse_ids' => 'required|array|min:1',
            'warehouse_ids.*' => 'integer|exists:warehouses,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $session = $this->stockTakeService->createSession([
                'branch_id' => $validated['branch_id'],
                'session_date' => $validated['session_date'],
                'warehouse_ids' => $validated['warehouse_ids'],
                'notes' => $validated['notes'] ?? '',
                'created_by' => auth()->id(),
            ]);

            return redirect()->route('admin.stock-take.show', $session)
                ->with('success', "Session {$session->session_code} created. Set up counts for each warehouse.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(int $id)
    {
        $session = StockTakeSession::with([
            'branch', 'warehouses.warehouse.branch', 'items.product', 'journalEntry.lines.ledger'
        ])->findOrFail($id);

        // Progress summary.
        $progress = $this->computeProgress($id);

        // Variance lines (items where physical ≠ system).
        $varianceLines = DB::table('stock_take_items as sti')
            ->join('products as p', 'p.id', '=', 'sti.product_id')
            ->join('warehouses as w', 'w.id', '=', 'sti.warehouse_id')
            ->where('sti.stock_take_session_id', $id)
            ->whereRaw('sti.physical_qty <> sti.system_qty')
            ->select('sti.*', 'p.product_code', 'p.product_name', 'p.unit', 'w.warehouse_name')
            ->orderBy('w.warehouse_name')
            ->orderBy('p.product_name')
            ->get();

        // Stock movements (if posted).
        $stockMovements = [];
        if ($session->isPosted() || $session->is_reversed) {
            $stockMovements = DB::table('stock_transactions as st')
                ->join('products as p', 'p.id', '=', 'st.product_id')
                ->where('st.reference_type', 'stock_take')
                ->where('st.reference_id', $id)
                ->select('st.*', 'p.product_code', 'p.product_name')
                ->orderBy('st.id')
                ->get();
        }

        return view('admin.stock-take.show', [
            'title' => 'Stock Take ' . $session->session_code,
            'session' => $session,
            'progress' => $progress,
            'varianceLines' => $varianceLines,
            'stockMovements' => $stockMovements,
        ]);
    }

    /**
     * Setup counts for a warehouse — loads all products into stock_take_items.
     */
    public function setupCounts(int $sessionId, int $warehouseId)
    {
        try {
            $count = $this->stockTakeService->setupWarehouseCounts($sessionId, $warehouseId);
            return redirect()->route('admin.stock-take.count', [$sessionId, $warehouseId])
                ->with('success', "{$count} products loaded for counting.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Show the count form for a warehouse.
     */
    public function count(int $sessionId, int $warehouseId)
    {
        $session = StockTakeSession::with(['branch'])->findOrFail($sessionId);
        $warehouse = \App\Models\Warehouse::with('branch')->findOrFail($warehouseId);

        $items = DB::table('stock_take_items as sti')
            ->join('products as p', 'p.id', '=', 'sti.product_id')
            ->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id')
            ->where('sti.stock_take_session_id', $sessionId)
            ->where('sti.warehouse_id', $warehouseId)
            ->select('sti.*', 'p.product_code', 'p.product_name', 'p.unit', 'c.category_name')
            ->orderBy('c.category_name')
            ->orderBy('p.product_name')
            ->get();

        return view('admin.stock-take.count', [
            'title' => "Count — {$warehouse->warehouse_name}",
            'session' => $session,
            'warehouse' => $warehouse,
            'items' => $items,
        ]);
    }

    /**
     * Save the physical counts (POST).
     */
    public function saveCounts(Request $request, int $sessionId, int $warehouseId)
    {
        $validated = $request->validate([
            'counts' => 'required|array',
            'counts.*' => 'numeric',
            'reasons' => 'nullable|array',
            'reasons.*' => 'nullable|string|max:500',
        ]);

        try {
            $updated = $this->stockTakeService->saveCounts($sessionId, $warehouseId, $validated['counts']);

            // Save per-line reasons.
            if (!empty($validated['reasons'])) {
                foreach ($validated['reasons'] as $productId => $reason) {
                    DB::table('stock_take_items')
                        ->where('stock_take_session_id', $sessionId)
                        ->where('warehouse_id', $warehouseId)
                        ->where('product_id', (int) $productId)
                        ->update(['reason' => $reason]);
                }
            }

            return redirect()->route('admin.stock-take.show', $sessionId)
                ->with('success', "Counts saved for {$updated} products. Review variances and post when ready.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Post the session — apply variances + GL.
     */
    public function post(Request $request, int $id)
    {
        $request->validate([
            'post_reason' => 'nullable|string|max:500',
        ]);

        try {
            $session = $this->stockTakeService->postSession($id, auth()->id());
            return redirect()->route('admin.stock-take.show', $session)
                ->with('success', "Session {$session->session_code} posted. Variances applied + GL posted.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Cancel the session.
     */
    public function cancel(Request $request, int $id)
    {
        $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        try {
            $session = $this->stockTakeService->cancelSession($id, auth()->id(), $request->input('cancel_reason'));
            return redirect()->route('admin.stock-take.show', $session)
                ->with('success', "Session {$session->session_code} cancelled.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Compute progress summary for a session.
     */
    private function computeProgress(int $sessionId): array
    {
        $wh = DB::table('stock_take_warehouses')
            ->where('stock_take_session_id', $sessionId)
            ->selectRaw("
                COUNT(*) as total_wh,
                SUM(CASE WHEN status IN ('counting','completed') THEN 1 ELSE 0 END) as counted_wh,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_wh
            ")
            ->first();

        $var = DB::table('stock_take_items')
            ->where('stock_take_session_id', $sessionId)
            ->whereRaw('physical_qty <> system_qty')
            ->selectRaw("
                COUNT(*) as variance_lines,
                COALESCE(SUM(ABS(physical_qty - system_qty)), 0) as abs_qty_variance,
                COALESCE(SUM(ABS(physical_qty - system_qty) * COALESCE(rate, 0)), 0) as variance_value,
                COALESCE(SUM(CASE WHEN physical_qty > system_qty THEN (physical_qty - system_qty) * COALESCE(rate, 0) ELSE 0 END), 0) as gain_value,
                COALESCE(SUM(CASE WHEN physical_qty < system_qty THEN (system_qty - physical_qty) * COALESCE(rate, 0) ELSE 0 END), 0) as loss_value
            ")
            ->first();

        return [
            'total_wh' => (int) ($wh->total_wh ?? 0),
            'counted_wh' => (int) ($wh->counted_wh ?? 0),
            'completed_wh' => (int) ($wh->completed_wh ?? 0),
            'variance_lines' => (int) ($var->variance_lines ?? 0),
            'abs_qty_variance' => (float) ($var->abs_qty_variance ?? 0),
            'variance_value' => (float) ($var->variance_value ?? 0),
            'gain_value' => (float) ($var->gain_value ?? 0),
            'loss_value' => (float) ($var->loss_value ?? 0),
        ];
    }
}

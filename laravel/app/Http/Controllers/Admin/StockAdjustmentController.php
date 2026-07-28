<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StockAdjustment;
use App\Models\Warehouse;
use App\Services\Stock\StockAdjustmentService;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Stock Adjustment Controller — Phase 6.3.
 *
 * Two-phase flow:
 *   - create() / store(): create a draft adjustment (no stock movement, no GL)
 *   - confirm(): apply stock + post GL (status → confirmed)
 *   - cancel(): reverse stock + GL if confirmed, or just mark draft as cancelled
 *
 * Also provides:
 *   - index(): searchable/filterable list
 *   - show(): single adjustment detail with items + stock movements + GL journal
 *   - audit(): health-check report
 *
 * Phase 1 (Stock Adjustment plan — Authorization & Role Enforcement):
 *   - Role gating is enforced by `role:` middleware on the route groups
 *     (read: admin/manager/accountant; write: admin/accountant).
 *   - This controller adds defense-in-depth via $this->authorize() against
 *     StockAdjustmentPolicy on show/confirm/cancel.
 *   - getProductRate() validates the requested warehouse belongs to the
 *     caller's session branch (G16 fix — warehouse_stock has no RLS because
 *     it has no branch_id column, so the endpoint itself must guard).
 *   - branch.isolation middleware on POST {id}/confirm|cancel resolves {id}
 *     → stock_adjustments.branch_id (EnforceBranchIsolation::inferTableFromUri).
 *   - PostgreSQL RLS on stock_adjustments is the DB-level backstop.
 */
class StockAdjustmentController extends Controller
{
    public function __construct(
        private StockAdjustmentService $adjustmentService,
        private StockService $stockService
    ) {}

    /**
     * List stock adjustments with filters.
     */
    public function index(Request $request)
    {
        $query = StockAdjustment::with(['warehouse.branch', 'items'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('adjustment_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('adjustment_date', '<=', $d))
            ->when($request->input('warehouse_id'), fn($q, $wid) => $q->where('warehouse_id', $wid))
            ->when($request->input('adjustment_type'), fn($q, $t) => $q->where('adjustment_type', $t))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->orderBy('adjustment_date', 'desc')
            ->orderBy('id', 'desc');

        $adjustments = $query->paginate(25);

        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        $stats = [
            'total' => StockAdjustment::count(),
            'draft' => StockAdjustment::where('status', 'draft')->count(),
            'confirmed' => StockAdjustment::where('status', 'confirmed')->count(),
            'cancelled' => StockAdjustment::where('status', 'cancelled')->count(),
            'total_value' => StockAdjustment::where('status', 'confirmed')->sum('total_amount'),
        ];

        return view('admin.stock-adjustments.index', [
            'title' => 'Stock Adjustments',
            'adjustments' => $adjustments,
            'warehouses' => $warehouses,
            'branches' => $branches,
            'stats' => $stats,
            'filters' => $request->only(['from_date', 'to_date', 'warehouse_id', 'adjustment_type', 'status', 'branch_id']),
        ]);
    }

    /**
     * Show the create form.
     */
    public function create()
    {
        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $products = \App\Models\Product::active()->orderBy('product_name')->limit(500)->get();

        return view('admin.stock-adjustments.create', [
            'title' => 'New Stock Adjustment',
            'warehouses' => $warehouses,
            'products' => $products,
        ]);
    }

    /**
     * Store a new draft adjustment.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'adjustment_type' => 'required|in:increase,decrease',
            'adjustment_date' => 'required|date',
            'reason' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.rate' => 'nullable|numeric|min:0',
            'items.*.reason' => 'nullable|string|max:500',
        ]);

        // Phase 1: defense-in-depth — role check for creating a draft.
        // (No model yet, so the Policy's create(User) method is used.)
        $this->authorize('create', StockAdjustment::class);

        try {
            $adjustment = $this->adjustmentService->createAdjustment([
                'warehouse_id' => $validated['warehouse_id'],
                'adjustment_type' => $validated['adjustment_type'],
                'adjustment_date' => $validated['adjustment_date'],
                'reason' => $validated['reason'] ?? '',
                'items' => $validated['items'],
                'created_by' => auth()->id(),
            ]);

            return redirect()->route('admin.stock-adjustments.show', $adjustment)
                ->with('success', "Draft adjustment {$adjustment->adjustment_code} created. Review and confirm to apply.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Show adjustment details.
     */
    public function show(int $id)
    {
        $adjustment = StockAdjustment::with([
            'items.product', 'warehouse.branch', 'branch', 'journalEntry.lines.ledger'
        ])->findOrFail($id);

        // Phase 1: defense-in-depth — role + branch check via Policy.
        // (role: middleware already gated the route; RLS already filtered the
        // row. This re-confirms the same rule at the controller layer so the
        // intent is explicit and survives any future route loosening.)
        $this->authorize('view', $adjustment);

        // Get the stock transactions created by this adjustment.
        $stockTransactions = DB::table('stock_transactions as st')
            ->join('products as p', 'p.id', '=', 'st.product_id')
            ->where('st.reference_type', 'stock_adjustment')
            ->where('st.reference_id', $id)
            ->select('st.*', 'p.product_code', 'p.product_name')
            ->orderBy('st.id')
            ->get();

        return view('admin.stock-adjustments.show', [
            'title' => 'Adjustment ' . $adjustment->adjustment_code,
            'adjustment' => $adjustment,
            'stockTransactions' => $stockTransactions,
        ]);
    }

    /**
     * Confirm a draft adjustment (apply stock + post GL).
     */
    public function confirm(Request $request, int $id)
    {
        $request->validate([
            'confirm_reason' => 'nullable|string|max:500',
        ]);

        // Phase 1: load first so the Policy can check role + branch.
        $adjustment = StockAdjustment::findOrFail($id);
        $this->authorize('confirm', $adjustment);

        try {
            $adjustment = $this->adjustmentService->confirmAdjustment($id, auth()->id());

            return redirect()->route('admin.stock-adjustments.show', $adjustment)
                ->with('success', "Adjustment {$adjustment->adjustment_code} confirmed. Stock updated + GL posted.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Cancel an adjustment (reverse stock + GL if confirmed).
     */
    public function cancel(Request $request, int $id)
    {
        $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        // Phase 1: load first so the Policy can check role + branch.
        $adjustment = StockAdjustment::findOrFail($id);
        $this->authorize('cancel', $adjustment);

        try {
            $adjustment = $this->adjustmentService->cancelAdjustment(
                $id,
                auth()->id(),
                $request->input('cancel_reason')
            );

            return redirect()->route('admin.stock-adjustments.show', $adjustment)
                ->with('success', "Adjustment {$adjustment->adjustment_code} cancelled.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * AJAX: get product avg cost for a warehouse (for the create form).
     *
     * Phase 1 (G16 fix): warehouse_stock has NO branch_id column and therefore
     * NO RLS policy. A non-admin could otherwise query any warehouse's stock
     * by passing an arbitrary warehouse_id. Here we explicitly assert the
     * requested warehouse belongs to the caller's session branch before
     * returning data. Admins bypass (their cross-branch access is logged by
     * EnforceBranchIsolation on the surrounding request context).
     */
    public function getProductRate(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
        ]);

        $warehouseId = (int) $request->input('warehouse_id');
        $productId = (int) $request->input('product_id');

        // Phase 1 (G16): branch-validate the warehouse for non-admin users.
        $user = $request->user();
        if ($user && !$user->isAdmin()) {
            $sessionBranchId = (int) (session('branch_id') ?? $user->getBranchId() ?? 0);
            $warehouseBranchId = (int) (Warehouse::find($warehouseId)?->branch_id ?? 0);
            if ($sessionBranchId <= 0 || $warehouseBranchId !== $sessionBranchId) {
                abort(403, 'You do not have access to stock for a warehouse outside your branch.');
            }
        }

        $rate = $this->stockService->getWarehouseAvgCost($warehouseId, $productId);

        $qty = $this->stockService->getWarehouseQty($warehouseId, $productId);

        return response()->json([
            'rate' => round($rate, 2),
            'available_qty' => round($qty, 4),
        ]);
    }

    /**
     * Audit checklist — health checks for all adjustments.
     */
    public function audit()
    {
        // Phase 1: defense-in-depth role check (route already gated by
        // role:admin,manager,accountant). The Policy's audit() method is a
        // plain role check (no model binding).
        $this->authorize('audit', StockAdjustment::class);

        $checks = $this->computeAuditChecks();

        return view('admin.stock-adjustments.audit', [
            'title' => 'Stock Adjustment Audit',
            'checks' => $checks,
        ]);
    }

    /**
     * Compute audit health checks.
     */
    private function computeAuditChecks(): array
    {
        $checks = [];

        // 1. Confirmed adjustments without GL journal.
        $missingGl = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM stock_adjustments
WHERE status = 'confirmed' AND journal_entry_id IS NULL
SQL);
        $checks[] = [
            'label' => 'Confirmed adjustments without GL journal entry',
            'count' => (int) $missingGl->cnt,
            'status' => $missingGl->cnt == 0 ? 'pass' : 'fail',
        ];

        // 2. Confirmed adjustments with unbalanced GL (should be 0 — DB trigger enforces).
        $unbalanced = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM (
    SELECT je.id, SUM(jl.debit) AS d, SUM(jl.credit) AS c
    FROM journal_entries je
    JOIN journal_lines jl ON jl.journal_entry_id = je.id
    WHERE je.reference_type = 'stock_adjustment'
    GROUP BY je.id
    HAVING SUM(jl.debit) <> SUM(jl.credit)
) x
SQL);
        $checks[] = [
            'label' => 'Unbalanced stock-adjustment journal entries',
            'count' => (int) $unbalanced->cnt,
            'status' => $unbalanced->cnt == 0 ? 'pass' : 'fail',
        ];

        // 3. Confirmed adjustments without stock transactions.
        $missingStock = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM stock_adjustments sa
WHERE sa.status = 'confirmed'
  AND NOT EXISTS (
    SELECT 1 FROM stock_transactions st
    WHERE st.reference_type = 'stock_adjustment' AND st.reference_id = sa.id
  )
SQL);
        $checks[] = [
            'label' => 'Confirmed adjustments without stock transactions',
            'count' => (int) $missingStock->cnt,
            'status' => $missingStock->cnt == 0 ? 'pass' : 'fail',
        ];

        // 4. Stale drafts (>7 days old).
        $staleDrafts = DB::selectOne(<<<SQL
SELECT COUNT(*) AS cnt FROM stock_adjustments
WHERE status = 'draft' AND adjustment_date < (CURRENT_DATE - INTERVAL '7 days')
SQL);
        $checks[] = [
            'label' => 'Stale draft adjustments (>7 days old)',
            'count' => (int) $staleDrafts->cnt,
            'status' => $staleDrafts->cnt == 0 ? 'pass' : 'warn',
        ];

        return $checks;
    }
}

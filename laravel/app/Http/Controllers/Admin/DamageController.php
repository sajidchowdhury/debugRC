<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DamageInvoice;
use App\Services\Stock\DamageService;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Damage Controller — Phase 6.6.
 *
 * Two-phase flow (same as 6.3/6.4/6.5):
 *   - create / store: create a draft damage (no stock, no GL)
 *   - show: detail with items + stock movements + GL journal
 *   - confirm: apply stock OUT + post GL (Dr Damage Loss / Cr Inventory)
 *   - cancel: reverse if confirmed, or mark draft as cancelled
 */
class DamageController extends Controller
{
    public function __construct(
        private DamageService $damageService,
        private StockService $stockService
    ) {}

    public function index(Request $request)
    {
        $query = DamageInvoice::with(['warehouse.branch', 'items'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('damage_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('damage_date', '<=', $d))
            ->when($request->input('warehouse_id'), fn($q, $wid) => $q->where('warehouse_id', $wid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('damage_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('damage_date', 'desc')
            ->orderBy('id', 'desc');

        $damages = $query->paginate(25);

        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        $stats = [
            'total' => DamageInvoice::count(),
            'draft' => DamageInvoice::where('status', 'draft')->count(),
            'confirmed' => DamageInvoice::where('status', 'confirmed')->count(),
            'cancelled' => DamageInvoice::where('status', 'cancelled')->count(),
            'total_value' => DamageInvoice::where('status', 'confirmed')->sum('total_value'),
        ];

        return view('admin.damages.index', [
            'title' => 'Damages',
            'damages' => $damages,
            'warehouses' => $warehouses,
            'branches' => $branches,
            'stats' => $stats,
            'filters' => $request->only(['from_date', 'to_date', 'warehouse_id', 'status', 'branch_id', 'search']),
        ]);
    }

    public function create()
    {
        $warehouses = \App\Models\Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $products = \App\Models\Product::active()->orderBy('product_name')->limit(500)->get();

        return view('admin.damages.create', [
            'title' => 'New Damage Invoice',
            'warehouses' => $warehouses,
            'products' => $products,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'damage_date' => 'required|date',
            'reason' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.rate' => 'nullable|numeric|min:0',
        ]);

        try {
            $damage = $this->damageService->createDamage([
                'warehouse_id' => $validated['warehouse_id'],
                'damage_date' => $validated['damage_date'],
                'reason' => $validated['reason'] ?? '',
                'items' => $validated['items'],
                'created_by' => auth()->id(),
            ]);

            return redirect()->route('admin.damages.show', $damage)
                ->with('success', "Draft damage {$damage->damage_code} created. Review and confirm to apply.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(int $id)
    {
        $damage = DamageInvoice::with([
            'items.product', 'warehouse.branch', 'branch', 'journalEntry.lines.ledger'
        ])->findOrFail($id);

        $stockMovements = [];
        if ($damage->isConfirmed() || $damage->is_reversed) {
            $stockMovements = DB::table('stock_transactions as st')
                ->join('products as p', 'p.id', '=', 'st.product_id')
                ->where('st.reference_type', 'damage')
                ->where('st.reference_id', $id)
                ->select('st.*', 'p.product_code', 'p.product_name')
                ->orderBy('st.id')
                ->get();
        }

        return view('admin.damages.show', [
            'title' => 'Damage ' . $damage->damage_code,
            'damage' => $damage,
            'stockMovements' => $stockMovements,
        ]);
    }

    public function confirm(Request $request, int $id)
    {
        $request->validate([
            'confirm_reason' => 'nullable|string|max:500',
        ]);

        try {
            $damage = $this->damageService->confirmDamage($id, auth()->id());
            return redirect()->route('admin.damages.show', $damage)
                ->with('success', "Damage {$damage->damage_code} confirmed. Stock written off + GL posted.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function cancel(Request $request, int $id)
    {
        $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        try {
            $damage = $this->damageService->cancelDamage($id, auth()->id(), $request->input('cancel_reason'));
            return redirect()->route('admin.damages.show', $damage)
                ->with('success', "Damage {$damage->damage_code} cancelled.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * AJAX: get product stock + rate for a warehouse.
     */
    public function getProductStock(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
        ]);

        $rate = $this->stockService->getWarehouseAvgCost(
            (int) $request->input('warehouse_id'),
            (int) $request->input('product_id')
        );
        $qty = $this->stockService->getWarehouseQty(
            (int) $request->input('warehouse_id'),
            (int) $request->input('product_id')
        );

        return response()->json([
            'rate' => round($rate, 2),
            'available_qty' => round($qty, 4),
        ]);
    }
}

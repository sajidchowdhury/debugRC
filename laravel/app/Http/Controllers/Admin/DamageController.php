<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DamageInvoice;
use App\Models\DamageReason;
use App\Models\Warehouse;
use App\Models\Product;
use App\Models\Branch;
use App\Services\Stock\DamageIntegrityService;
use App\Services\Stock\DamageService;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
        private StockService $stockService,
        private DamageIntegrityService $integrityService
    ) {}

    public function index(Request $request)
    {
        // Phase 0 (Damage plan): defense-in-depth policy check behind the
        // role:admin,manager,warehouse_manager route middleware.
        $this->authorize('viewAny', DamageInvoice::class);

        $query = DamageInvoice::with(['warehouse.branch', 'items'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('damage_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('damage_date', '<=', $d))
            ->when($request->input('warehouse_id'), fn($q, $wid) => $q->where('warehouse_id', $wid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('damage_type'), fn($q, $t) => $q->where('damage_type', $t))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('damage_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('damage_date', 'desc')
            ->orderBy('id', 'desc');

        $damages = $query->paginate(25);

        $warehouses = Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $branches = Branch::active()->orderBy('branch_name')->get();

        $stats = [
            'total' => DamageInvoice::count(),
            'draft' => DamageInvoice::where('status', 'draft')->count(),
            'confirmed' => DamageInvoice::where('status', 'confirmed')->count(),
            'cancelled' => DamageInvoice::where('status', 'cancelled')->count(),
            'total_value' => DamageInvoice::where('status', 'confirmed')->sum('total_value'),
            // Phase 1 — per-type counts for the accountability dashboard.
            'missing_count' => DamageInvoice::where('damage_type', 'missing')->count(),
            'theft_count' => DamageInvoice::where('damage_type', 'theft')->count(),
        ];

        return view('admin.damages.index', [
            'title' => 'Damages',
            'damages' => $damages,
            'warehouses' => $warehouses,
            'branches' => $branches,
            'damageTypes' => DamageInvoice::DAMAGE_TYPES,
            'damageTypeLabels' => DamageInvoice::DAMAGE_TYPE_LABELS,
            'stats' => $stats,
            'filters' => $request->only(['from_date', 'to_date', 'warehouse_id', 'status', 'damage_type', 'branch_id', 'search']),
        ]);
    }

    public function create()
    {
        // Phase 0 (Damage plan): defense-in-depth policy check.
        $this->authorize('create', DamageInvoice::class);

        $warehouses = Warehouse::active()->with('branch')->orderBy('warehouse_name')->get();
        $products = Product::active()->orderBy('product_name')->limit(500)->get();

        // Phase 1 — load the reason taxonomy grouped by damage_type for the
        // type-filtered dropdown on the create form.
        $damageReasons = DamageReason::groupedByType();

        return view('admin.damages.create', [
            'title' => 'New Damage Invoice',
            'warehouses' => $warehouses,
            'products' => $products,
            'damageTypes' => DamageInvoice::DAMAGE_TYPES,
            'damageTypeLabels' => DamageInvoice::DAMAGE_TYPE_LABELS,
            'damageReasons' => $damageReasons,
        ]);
    }

    public function store(Request $request)
    {
        // Phase 0 (Damage plan): defense-in-depth policy check.
        $this->authorize('create', DamageInvoice::class);

        $validated = $request->validate([
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'damage_date' => 'required|date',
            // Phase 1 — damage_type is required and must be a known enum.
            'damage_type' => ['required', 'string', Rule::in(DamageInvoice::DAMAGE_TYPES)],
            // reason_code is optional but if supplied MUST be an active reason
            // belonging to the chosen damage_type (so the dropdown filter is
            // authoritative). DamageService re-validates this as a backstop.
            'reason_code' => [
                'nullable', 'string', 'max:50',
                Rule::exists('damage_reasons', 'reason_code')->where(function ($q) use ($request) {
                    $q->where('damage_type', $request->input('damage_type'))
                      ->where('is_active', true);
                }),
            ],
            'reason_detail' => 'nullable|string|max:2000',
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
                'damage_type' => $validated['damage_type'],
                'reason_code' => $validated['reason_code'] ?? '',
                'reason_detail' => $validated['reason_detail'] ?? '',
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
            'items.product', 'warehouse.branch', 'branch',
            'journalEntry.lines.ledger',
            // Phase 1 — eager-load the structured reason label for display.
            'reasonTaxonomy',
        ])->findOrFail($id);

        // Phase 0 (Damage plan): defense-in-depth policy check (same-branch
        // for non-admins). branch.isolation middleware already gated the
        // request; this re-confirms on the loaded model.
        $this->authorize('view', $damage);

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

        // Phase 2 — live-computed integrity panel (ports legacy
        // DamageAuditModel::runDamageChecks). Read-only, indexed lookups,
        // safe to run on every detail-page render. Surfaces drift between
        // the damage header, its items, stock_transactions and GL journal
        // so reconciliation issues are visible at a glance instead of
        // silently accumulating. Passes the already-eager-loaded $damage
        // model so the service doesn't re-query the header.
        $integrity = $this->integrityService->runChecks($damage);

        return view('admin.damages.show', [
            'title' => 'Damage ' . $damage->damage_code,
            'damage' => $damage,
            'stockMovements' => $stockMovements,
            'integrity' => $integrity,
        ]);
    }

    public function confirm(Request $request, int $id)
    {
        // Phase 0 (Damage plan): defense-in-depth policy check. Loads the
        // model first so the policy can verify same-branch for non-admins.
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('confirm', $damage);

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
        // Phase 0 (Damage plan): defense-in-depth policy check.
        $damage = DamageInvoice::findOrFail($id);
        $this->authorize('cancel', $damage);

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
        // Phase 0 (Damage plan): defense-in-depth policy check.
        $this->authorize('viewProductStock', DamageInvoice::class);

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

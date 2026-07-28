<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Rules\WarehouseBelongsToBranch;
use App\Services\Stock\WarehouseTransferService;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Warehouse Transfer Controller — Phase 6.5 + Phase 1 same-branch enforcement.
 *
 * Two-phase flow:
 *   - create / store: create a draft transfer (no stock, no GL)
 *   - show: detail with items + stock movements + GL journals
 *   - confirm: apply stock (source OUT + dest IN) — same-branch only, NO GL
 *   - cancel: reverse if confirmed, or mark draft as cancelled
 *
 * Phase 1 enforcement:
 *   - Same-branch only: both warehouses must belong to the same branch
 *   - Cross-branch transfers must go through Branch Demand module
 *   - Warehouse dropdown filtered by user's branch
 *   - WarehouseBelongsToBranch validation rule applied
 *   - Defense-in-depth: controller check + service check + DB trigger
 */
class WarehouseTransferController extends Controller
{
    public function __construct(
        private WarehouseTransferService $transferService,
        private StockService $stockService
    ) {}

    /**
     * Get the user's effective branch_id for filtering.
     * Admins see all branches; non-admins see their own branch.
     */
    private function getUserBranchId(): ?int
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        if ($user && $user->isAdmin()) {
            return null; // Admin: no branch restriction (see all)
        }
        return $user ? (int) (session('branch_id') ?? $user->getBranchId() ?? 0) : 0;
    }

    public function index(Request $request)
    {
        $userBranchId = $this->getUserBranchId();

        $query = WarehouseTransfer::with(['fromWarehouse.branch', 'toWarehouse.branch', 'items'])
            ->when($userBranchId, function ($q) use ($userBranchId) {
                // Non-admin: only see transfers where their branch is involved
                $q->where(function ($subQ) use ($userBranchId) {
                    $subQ->where('from_branch_id', $userBranchId)
                         ->orWhere('to_branch_id', $userBranchId);
                });
            })
            ->when($request->input('from_date'), fn($q, $d) => $q->where('transfer_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('transfer_date', '<=', $d))
            ->when($request->input('from_warehouse_id'), fn($q, $wid) => $q->where('from_warehouse_id', $wid))
            ->when($request->input('to_warehouse_id'), fn($q, $wid) => $q->where('to_warehouse_id', $wid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('transfer_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('transfer_date', 'desc')
            ->orderBy('id', 'desc');

        $transfers = $query->paginate(25);

        // Warehouse dropdown: filter by user's branch for non-admins
        $warehouses = Warehouse::active()->with('branch')
            ->when($userBranchId, fn($q) => $q->where('branch_id', $userBranchId))
            ->orderBy('warehouse_name')
            ->get();

        $stats = [
            'total' => WarehouseTransfer::when($userBranchId, function ($q) use ($userBranchId) {
                $q->where(function ($subQ) use ($userBranchId) {
                    $subQ->where('from_branch_id', $userBranchId)
                         ->orWhere('to_branch_id', $userBranchId);
                });
            })->count(),
            'draft' => WarehouseTransfer::where('status', 'draft')
                ->when($userBranchId, function ($q) use ($userBranchId) {
                    $q->where(function ($subQ) use ($userBranchId) {
                        $subQ->where('from_branch_id', $userBranchId)
                             ->orWhere('to_branch_id', $userBranchId);
                    });
                })->count(),
            'confirmed' => WarehouseTransfer::where('status', 'confirmed')
                ->when($userBranchId, function ($q) use ($userBranchId) {
                    $q->where(function ($subQ) use ($userBranchId) {
                        $subQ->where('from_branch_id', $userBranchId)
                             ->orWhere('to_branch_id', $userBranchId);
                    });
                })->count(),
            'cancelled' => WarehouseTransfer::where('status', 'cancelled')
                ->when($userBranchId, function ($q) use ($userBranchId) {
                    $q->where(function ($subQ) use ($userBranchId) {
                        $subQ->where('from_branch_id', $userBranchId)
                             ->orWhere('to_branch_id', $userBranchId);
                    });
                })->count(),
            'total_value' => (float) DB::table('warehouse_transfers')
                ->join('warehouse_transfer_items', 'warehouse_transfer_items.warehouse_transfer_id', '=', 'warehouse_transfers.id')
                ->where('warehouse_transfers.status', 'confirmed')
                ->whereNull('warehouse_transfers.deleted_at')
                ->when($userBranchId, function ($q) use ($userBranchId) {
                    $q->where(function ($subQ) use ($userBranchId) {
                        $subQ->where('warehouse_transfers.from_branch_id', $userBranchId)
                             ->orWhere('warehouse_transfers.to_branch_id', $userBranchId);
                    });
                })
                ->sum(DB::raw('warehouse_transfer_items.qty * warehouse_transfer_items.rate')),
        ];

        return view('admin.warehouse-transfers.index', [
            'title' => 'Warehouse Transfers',
            'transfers' => $transfers,
            'warehouses' => $warehouses,
            'stats' => $stats,
            'filters' => $request->only(['from_date', 'to_date', 'from_warehouse_id', 'to_warehouse_id', 'status', 'search']),
            'userBranchId' => $userBranchId,
        ]);
    }

    public function create()
    {
        $userBranchId = $this->getUserBranchId();

        // Phase 1: Only show warehouses belonging to user's branch (or all for admin)
        $warehouses = Warehouse::active()->with('branch')
            ->when($userBranchId, fn($q) => $q->where('branch_id', $userBranchId))
            ->orderBy('warehouse_name')
            ->get();

        $products = \App\Models\Product::active()->orderBy('product_name')->limit(500)->get();

        // Determine branch name for the banner
        $branchName = null;
        if ($userBranchId) {
            $branch = \App\Models\Branch::find($userBranchId);
            $branchName = $branch ? $branch->branch_name : null;
        }

        return view('admin.warehouse-transfers.create', [
            'title' => 'New Warehouse Transfer',
            'warehouses' => $warehouses,
            'products' => $products,
            'userBranchId' => $userBranchId,
            'branchName' => $branchName,
        ]);
    }

    public function store(Request $request)
    {
        $userBranchId = $this->getUserBranchId();

        // Phase 1: Add WarehouseBelongsToBranch validation rule for user's branch
        $validated = $request->validate([
            'from_warehouse_id' => [
                'required', 'integer', 'exists:warehouses,id',
                new WarehouseBelongsToBranch($userBranchId, 'branch'),
            ],
            'to_warehouse_id' => [
                'required', 'integer', 'exists:warehouses,id',
                'different:from_warehouse_id',
                new WarehouseBelongsToBranch($userBranchId, 'branch'),
            ],
            'transfer_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.rate' => 'nullable|numeric|min:0',
        ]);

        // Phase 1: Controller-level same-branch guard (defense-in-depth)
        $fromWarehouse = Warehouse::findOrFail($validated['from_warehouse_id']);
        $toWarehouse = Warehouse::findOrFail($validated['to_warehouse_id']);

        if ((int) $fromWarehouse->branch_id !== (int) $toWarehouse->branch_id) {
            Log::warning('Cross-branch warehouse transfer rejected at controller level', [
                'from_warehouse_id' => $fromWarehouse->id,
                'to_warehouse_id' => $toWarehouse->id,
                'from_branch_id' => $fromWarehouse->branch_id,
                'to_branch_id' => $toWarehouse->branch_id,
                'user_id' => auth()->id(),
            ]);
            return back()->withInput()->withErrors([
                'to_warehouse_id' => 'Both warehouses must belong to the same branch. Cross-branch transfers must go through Branch Demand.',
            ]);
        }

        try {
            $transfer = $this->transferService->createTransfer([
                'from_warehouse_id' => $validated['from_warehouse_id'],
                'to_warehouse_id' => $validated['to_warehouse_id'],
                'transfer_date' => $validated['transfer_date'],
                'notes' => $validated['notes'] ?? '',
                'items' => $validated['items'],
                'created_by' => auth()->id(),
            ]);

            return redirect()->route('admin.warehouse-transfers.show', $transfer)
                ->with('success', "Draft transfer {$transfer->transfer_code} created. Review and confirm to apply.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(int $id)
    {
        $transfer = WarehouseTransfer::with([
            'items.product', 'fromWarehouse.branch', 'toWarehouse.branch',
            'fromBranch', 'toBranch',
            'journalEntry.lines.ledger', 'debtorJournalEntry.lines.ledger'
        ])->findOrFail($id);

        $stockMovements = [];
        if ($transfer->isConfirmed() || $transfer->is_reversed) {
            $stockMovements = DB::table('stock_transactions as st')
                ->join('products as p', 'p.id', '=', 'st.product_id')
                ->join('warehouses as w', 'w.id', '=', 'st.warehouse_id')
                ->where('st.reference_type', 'warehouse_transfer')
                ->where('st.reference_id', $id)
                ->select('st.*', 'p.product_code', 'p.product_name', 'w.warehouse_name')
                ->orderBy('st.id')
                ->get();
        }

        return view('admin.warehouse-transfers.show', [
            'title' => 'Transfer ' . $transfer->transfer_code,
            'transfer' => $transfer,
            'stockMovements' => $stockMovements,
        ]);
    }

    public function confirm(Request $request, int $id)
    {
        $request->validate([
            'confirm_reason' => 'nullable|string|max:500',
        ]);

        // Phase 1: Defense-in-depth — check branch before confirming
        $transfer = WarehouseTransfer::findOrFail($id);
        if ((int) $transfer->from_branch_id !== (int) $transfer->to_branch_id) {
            Log::warning('Cross-branch warehouse transfer confirm rejected at controller level', [
                'transfer_id' => $id,
                'from_branch_id' => $transfer->from_branch_id,
                'to_branch_id' => $transfer->to_branch_id,
                'user_id' => auth()->id(),
            ]);
            return back()->withErrors([
                'error' => 'Cross-branch transfers are not allowed. Use Branch Demand instead.',
            ]);
        }

        try {
            $transfer = $this->transferService->confirmTransfer($id, auth()->id());
            $msg = "Transfer {$transfer->transfer_code} confirmed. Stock moved (same-branch — no intercompany GL).";
            return redirect()->route('admin.warehouse-transfers.show', $transfer)
                ->with('success', $msg);
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
            $transfer = $this->transferService->cancelTransfer($id, auth()->id(), $request->input('cancel_reason'));
            return redirect()->route('admin.warehouse-transfers.show', $transfer)
                ->with('success', "Transfer {$transfer->transfer_code} cancelled.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * AJAX: get product stock + rate for a warehouse (for the create form).
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

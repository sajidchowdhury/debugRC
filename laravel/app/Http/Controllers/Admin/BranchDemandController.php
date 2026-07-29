<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BranchDemand\StoreBranchDemandRequest;
use App\Http\Requests\BranchDemand\SendBranchDemandRequest;
use App\Http\Requests\BranchDemand\ReverseBranchDemandRequest;
use App\Http\Requests\BranchDemand\RejectBranchDemandRequest;
use App\Models\BranchDemand;
use App\Services\BranchDemand\BranchDemandService;
use App\Services\BranchDemand\BranchIntercompanyService;
use App\Services\Stock\StockAvailabilityService;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Branch Demand Controller — Phase 2.
 *
 * Web controller for the cross-branch demand lifecycle:
 *   - index:   Filtered list of demands (my demands + incoming)
 *   - pending:  Pending demands for my branch (supplier view)
 *   - create:   Create demand form
 *   - store:    Store new demand
 *   - show:     Full detail view (items, warehouse pickers, settlements, stock trace, GL)
 *   - send:     Send goods with warehouse selection
 *   - reverse:  Reverse a sent/received demand
 *   - delete:   Delete a pending demand
 *   - reject:   Reject a pending demand
 *   - JSON helpers: branches, products, warehouses, stock
 */
class BranchDemandController extends Controller
{
    public function __construct(
        private BranchDemandService $demandService,
        private BranchIntercompanyService $intercompanyService,
        private StockService $stockService,
        private StockAvailabilityService $stockAvailabilityService,
    ) {}

    /**
     * Get the current user's branch ID from session.
     */
    private function currentBranchId(): int
    {
        return (int) session('branch_id', 0);
    }

    /**
     * Get the current user ID.
     */
    private function currentUserId(): int
    {
        return (int) Auth::id();
    }

    // ===================== VIEWS =====================

    /**
     * List all demands involving my branch (both directions).
     */
    public function index(Request $request)
    {
        $branchId = $this->currentBranchId();
        $query = BranchDemand::forBranch($branchId)
            ->with(['fromBranch', 'toBranch', 'items.product', 'createdBy']);

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('direction')) {
            if ($request->direction === 'outgoing') {
                $query->where('from_branch_id', $branchId);
            } elseif ($request->direction === 'incoming') {
                $query->where('to_branch_id', $branchId);
            }
        }
        if ($request->filled('date_from')) {
            $query->where('demand_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('demand_date', '<=', $request->date_to);
        }
        if ($request->filled('search')) {
            $query->where('demand_code', 'ILIKE', '%' . $request->search . '%');
        }

        $demands = $query->orderByDesc('demand_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->appends($request->query());

        return view('admin.branch-demands.index', compact('demands'));
    }

    /**
     * List pending demands for my branch (supplier view — demands I need to fulfill).
     */
    public function pending(Request $request)
    {
        $branchId = $this->currentBranchId();
        $demands = BranchDemand::where('to_branch_id', $branchId)
            ->where('status', 'pending')
            ->where('is_reversed', false)
            ->with(['fromBranch', 'toBranch', 'items.product', 'createdBy'])
            ->orderBy('demand_date')
            ->orderBy('id')
            ->paginate(20);

        return view('admin.branch-demands.pending', compact('demands'));
    }

    /**
     * Show the create demand form.
     */
    public function create()
    {
        $branchId = $this->currentBranchId();
        $branches = DB::table('branches')
            ->where('is_active', true)
            ->where('id', '!=', $branchId)
            ->orderBy('branch_name')
            ->get();

        return view('admin.branch-demands.create', compact('branches'));
    }

    /**
     * Store a new branch demand.
     */
    public function store(StoreBranchDemandRequest $request)
    {
        try {
            $data = $request->validated();
            $data['from_branch_id'] = $this->currentBranchId();
            $data['created_by'] = $this->currentUserId();

            $items = $data['items'];
            unset($data['items']);

            $demand = $this->demandService->createDemand($data, $items);

            return redirect()
                ->route('admin.branch-demands.show', $demand->id)
                ->with('success', "Demand {$demand->demand_code} created successfully.");
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('BranchDemand store failed', ['error' => $e->getMessage()]);
            return back()->withInput()->withErrors(['error' => 'Failed to create demand. ' . $e->getMessage()]);
        }
    }

    /**
     * Show a single demand with full details.
     */
    public function show(int $id)
    {
        $branchId = $this->currentBranchId();

        $demand = BranchDemand::with([
            'fromBranch', 'toBranch', 'items.product', 'items.fromWarehouse', 'items.toWarehouse',
            'warehouseTransfer', 'journalEntry', 'debtorJournalEntry',
            'createdBy', 'receivedBy', 'reversedBy',
            'moneyTransferSettlements', 'customerPaymentSettlements',
        ])->findOrFail($id);

        // Authorization: user must be from one of the involved branches
        if ($demand->from_branch_id !== $branchId && $demand->to_branch_id !== $branchId) {
            abort(403, 'You do not have access to this demand.');
        }

        // Get stock transactions for traceability
        $stockTransactions = DB::table('stock_transactions')
            ->where('reference_id', $id)
            ->whereIn('reference_type', ['demand_send', 'demand_receive', 'demand_reversal'])
            ->with(['product', 'warehouse'])
            ->orderBy('id')
            ->get();

        // Determine user's role in this demand
        $isRequester = $demand->from_branch_id === $branchId;
        $isSupplier = $demand->to_branch_id === $branchId;

        // Get supplier warehouses for the send form
        $supplierWarehouses = [];
        $requesterWarehouses = [];
        if ($demand->isPending() && $isSupplier) {
            $supplierWarehouses = DB::table('warehouses')
                ->where('branch_id', $demand->to_branch_id)
                ->where('is_active', true)
                ->orderBy('warehouse_name')
                ->get();
            $requesterWarehouses = DB::table('warehouses')
                ->where('branch_id', $demand->from_branch_id)
                ->where('is_active', true)
                ->orderBy('warehouse_name')
                ->get();
        }

        return view('admin.branch-demands.show', compact(
            'demand', 'stockTransactions', 'isRequester', 'isSupplier',
            'supplierWarehouses', 'requesterWarehouses'
        ));
    }

    // ===================== ACTIONS =====================

    /**
     * Send goods for a demand (with warehouse selection).
     */
    public function send(SendBranchDemandRequest $request, int $id)
    {
        try {
            $demand = $this->demandService->sendGoodsWithWarehouses(
                $id,
                $request->validated()['items'],
                $this->currentUserId()
            );

            return redirect()
                ->route('admin.branch-demands.show', $id)
                ->with('success', "Goods sent for demand {$demand->demand_code}. Stock moved successfully.");
        } catch (\RuntimeException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('BranchDemand send failed', ['demand_id' => $id, 'error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Failed to send goods. ' . $e->getMessage()]);
        }
    }

    /**
     * Reverse a sent/received demand.
     */
    public function reverse(ReverseBranchDemandRequest $request, int $id)
    {
        try {
            $demand = $this->demandService->reverseDemand(
                $id,
                $request->validated()['reason'],
                $this->currentUserId()
            );

            return redirect()
                ->route('admin.branch-demands.show', $id)
                ->with('success', "Demand {$demand->demand_code} has been reversed. Stock restored.");
        } catch (\RuntimeException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('BranchDemand reverse failed', ['demand_id' => $id, 'error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Failed to reverse demand. ' . $e->getMessage()]);
        }
    }

    /**
     * Delete a pending demand.
     */
    public function destroy(int $id)
    {
        try {
            $this->demandService->deleteDraftDemand($id);

            return redirect()
                ->route('admin.branch-demands.index')
                ->with('success', 'Demand deleted successfully.');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('BranchDemand delete failed', ['demand_id' => $id, 'error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Failed to delete demand. ' . $e->getMessage()]);
        }
    }

    /**
     * Reject a pending demand.
     */
    public function reject(RejectBranchDemandRequest $request, int $id)
    {
        try {
            $demand = $this->demandService->rejectDemand(
                $id,
                $request->validated()['reason'],
                $this->currentUserId()
            );

            return redirect()
                ->route('admin.branch-demands.show', $id)
                ->with('success', "Demand {$demand->demand_code} has been rejected.");
        } catch (\RuntimeException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('BranchDemand reject failed', ['demand_id' => $id, 'error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Failed to reject demand. ' . $e->getMessage()]);
        }
    }

    // ===================== JSON HELPERS =====================

    /**
     * JSON: Get other active branches (for create form dropdown).
     */
    public function getBranches()
    {
        $branchId = $this->currentBranchId();

        $branches = DB::table('branches')
            ->where('is_active', true)
            ->where('id', '!=', $branchId)
            ->orderBy('branch_name')
            ->get(['id', 'branch_code', 'branch_name']);

        return response()->json($branches);
    }

    /**
     * JSON: Get active products (for item selection).
     */
    public function getProducts(Request $request)
    {
        $query = DB::table('products')
            ->where('is_active', true)
            ->orderBy('product_name');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('product_code', 'ILIKE', "%{$search}%")
                  ->orWhere('product_name', 'ILIKE', "%{$search}%");
            });
        }

        $products = $query->paginate(50, ['id', 'product_code', 'product_name', 'unit']);

        return response()->json($products);
    }

    /**
     * JSON: Get warehouses for a specific branch.
     */
    public function getWarehousesByBranch(int $branchId)
    {
        $warehouses = DB::table('warehouses')
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('warehouse_name')
            ->get(['id', 'warehouse_code', 'warehouse_name']);

        return response()->json($warehouses);
    }

    /**
     * JSON: Get warehouse-wise product stock (for availability checking).
     */
    public function getWarehouseStock(int $productId, int $branchId)
    {
        $warehouses = DB::table('warehouses')
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('warehouse_name')
            ->get(['id', 'warehouse_code', 'warehouse_name']);

        $result = [];
        foreach ($warehouses as $wh) {
            $physical = $this->stockService->getWarehouseQty($wh->id, $productId);
            $available = $this->stockAvailabilityService->getWarehouseAvailableQty($productId, $wh->id);
            $avgCost = $this->stockService->getWarehouseAvgCost($wh->id, $productId);

            $result[] = [
                'warehouse_id'   => $wh->id,
                'warehouse_code' => $wh->warehouse_code,
                'warehouse_name' => $wh->warehouse_name,
                'physical_qty'   => round($physical, 4),
                'available_qty'  => round($available, 4),
                'pipeline_qty'   => round($physical - $available, 4),
                'avg_cost'       => round($avgCost, 4),
            ];
        }

        return response()->json($result);
    }

    // ===================== INTERCOMPANY / BRANCH LEDGER =====================

    /**
     * JSON: Get outstanding amounts for my branch (intercompany balances).
     */
    public function getOutstanding()
    {
        $branchId = $this->currentBranchId();
        $outstanding = $this->intercompanyService->getOutstandingByBranch($branchId);

        return response()->json($outstanding);
    }

    /**
     * JSON: Get branch ledger history between two branches.
     */
    public function getLedgerHistory(Request $request)
    {
        $request->validate([
            'partner_branch_id' => 'required|integer|exists:branches,id',
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date',
        ]);

        $branchId = $this->currentBranchId();
        $partnerBranchId = (int) $request->partner_branch_id;

        // Determine debtor/creditor ordering
        $debtorBranchId = min($branchId, $partnerBranchId);
        $creditorBranchId = max($branchId, $partnerBranchId);

        $history = $this->intercompanyService->getLedgerHistory(
            $debtorBranchId,
            $creditorBranchId,
            $request->date_from,
            $request->date_to
        );

        return response()->json($history);
    }

    /**
     * JSON: Preview which demands would be settled for a given amount.
     */
    public function previewSettlement(Request $request)
    {
        $request->validate([
            'partner_branch_id' => 'required|integer|exists:branches,id',
            'amount'            => 'required|numeric|min:0.01',
        ]);

        $branchId = $this->currentBranchId();
        $partnerBranchId = (int) $request->partner_branch_id;

        $debtorBranchId = min($branchId, $partnerBranchId);
        $creditorBranchId = max($branchId, $partnerBranchId);

        $preview = $this->intercompanyService->previewDemandSettlement(
            $debtorBranchId,
            $creditorBranchId,
            (float) $request->amount
        );

        return response()->json($preview);
    }
}

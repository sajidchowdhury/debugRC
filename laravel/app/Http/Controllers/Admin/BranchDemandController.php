<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BranchDemand\StoreBranchDemandRequest;
use App\Http\Requests\BranchDemand\SendBranchDemandRequest;
use App\Http\Requests\BranchDemand\ReverseBranchDemandRequest;
use App\Http\Requests\BranchDemand\RejectBranchDemandRequest;
use App\Http\Requests\BranchDemand\ConfirmReceiptRequest;
use App\Http\Requests\BranchDemand\RepriceBranchDemandRequest;
use App\Models\BranchDemand;
use App\Services\BranchDemand\BranchDemandService;
use App\Services\BranchDemand\BranchIntercompanyService;
use App\Services\BranchDemand\BranchDemandRepricingService;
use App\Services\BranchDemand\BranchDemandAuditService;
use App\Services\Stock\StockAvailabilityService;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Branch Demand Controller — Phase 2 + Phase 5 + Phase 7 + Phase 8.
 *
 * Web controller for the cross-branch demand lifecycle:
 *   - index:   Filtered list of demands (my demands + incoming)
 *   - pending:  Pending demands for my branch (supplier view)
 *   - pendingReceipt:  Demands awaiting receipt confirmation (requester view, Phase 5)
 *   - create:   Create demand form
 *   - store:    Store new demand
 *   - show:     Full detail view (items, warehouse pickers, settlements, stock trace, GL)
 *   - send:     Send goods with warehouse selection
 *   - confirmReceipt:  Confirm receipt of goods (Phase 5)
 *   - reprice:  Reprice a demand's total value (Phase 7)
 *   - priceRangeComparison:  Price range audit (Phase 7)
 *   - checklist:  Audit checklist (Phase 8)
 *   - audit:   Per-demand audit trail (Phase 8)
 *   - reconcile:  Branch-level reconciliation (Phase 8)
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
        private BranchDemandRepricingService $repricingService,
        private BranchDemandAuditService $auditService,
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

    /**
     * List demands awaiting receipt confirmation for my branch (requester view).
     *
     * Phase 5 — Warehouse Manager Confirmation.
     *
     * Shows all demands where my branch is the requester (from_branch_id)
     * and goods have been sent (status='received') but the receiving
     * warehouse manager has not yet confirmed receipt (received_at IS NULL).
     *
     * The receiving branch's warehouse manager must acknowledge receipt
     * before any reversal can happen.
     */
    public function pendingReceipt(Request $request)
    {
        $branchId = $this->currentBranchId();

        $demands = BranchDemand::pendingReceiptForBranch($branchId)
            ->with(['fromBranch', 'toBranch', 'items.product', 'items.fromWarehouse', 'items.toWarehouse', 'createdBy'])
            ->orderByDesc('demand_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->appends($request->query());

        return view('admin.branch-demands.pending-receipt', compact('demands'));
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
     * Confirm receipt of goods for a demand.
     *
     * Phase 5 — Warehouse Manager Confirmation.
     *
     * Only the requesting branch's warehouse manager can confirm receipt.
     * This sets received_at and received_by on the demand, which is
     * required before any reversal can happen.
     */
    public function confirmReceipt(ConfirmReceiptRequest $request, int $id)
    {
        try {
            $demand = $this->demandService->confirmReceipt(
                $id,
                $this->currentUserId(),
                $this->currentBranchId()
            );

            return redirect()
                ->route('admin.branch-demands.show', $id)
                ->with('success', "Receipt confirmed for demand {$demand->demand_code}.");
        } catch (\RuntimeException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('BranchDemand receipt confirmation failed', ['demand_id' => $id, 'error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Failed to confirm receipt. ' . $e->getMessage()]);
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

    // ===================== REPRICING (Phase 7) =====================

    /**
     * Reprice a demand's total value.
     *
     * Phase 7 — Price Range Handling & Repricing Logic.
     *
     * Creates a repricing adjustment that:
     *   - Records the original and new total value
     *   - Posts GL adjustment journals (creditor + debtor)
     *   - Records branch ledger adjustment entries
     *   - Updates the demand's total_value
     *
     * Only received (non-reversed) demands can be repriced.
     * The new total value must not be less than the already-settled amount.
     */
    public function reprice(RepriceBranchDemandRequest $request, int $id)
    {
        try {
            $validated = $request->validated();

            $repricing = $this->repricingService->createRepricingAdjustment(
                $id,
                (float) $validated['new_total_value'],
                $validated['reason'],
                isset($validated['approved_by']) ? (int) $validated['approved_by'] : null,
                $this->currentUserId()
            );

            return redirect()
                ->route('admin.branch-demands.show', $id)
                ->with('success', "Demand repriced successfully. Adjustment: " . number_format((float) $repricing->adjustment_amount, 2));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('BranchDemand reprice failed', ['demand_id' => $id, 'error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Failed to reprice demand. ' . $e->getMessage()]);
        }
    }

    /**
     * Show the price range comparison view.
     *
     * Phase 7 — Price Range Handling & Repricing Logic.
     *
     * Shows all demand items where the current price range differs from the
     * locked price range at send time. This helps management identify:
     *   - Products where the price has changed since the demand was sent
     *   - The financial impact of the price change on outstanding balances
     *   - The margin variance (actual sale price vs locked cost)
     */
    public function priceRangeComparison(Request $request)
    {
        $branchId = $this->currentBranchId();
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $changes = $this->repricingService->getPriceRangeComparison(
            $branchId,
            $dateFrom,
            $dateTo
        );

        // Get out-of-range sales warnings
        $outOfRangeSales = $this->repricingService->getOutOfRangeSales(
            $branchId,
            $dateFrom,
            $dateTo
        );

        return view('admin.branch-demands.price-range-comparison', [
            'changes'          => $changes,
            'outOfRangeSales'  => $outOfRangeSales,
            'dateFrom'         => $dateFrom,
            'dateTo'           => $dateTo,
        ]);
    }

    /**
     * JSON: Get repricing history for a specific demand.
     *
     * Phase 7 — Price Range Handling & Repricing Logic.
     */
    public function getRepricingHistory(int $id)
    {
        $history = $this->repricingService->getRepricingHistory($id);

        return response()->json($history);
    }

    /**
     * JSON: Check if a sale price is within the locked price range for a demand item.
     *
     * Phase 7 — Price Range Handling & Repricing Logic.
     */
    public function checkSalePriceRange(Request $request)
    {
        $request->validate([
            'demand_item_id' => 'required|integer|exists:branch_demand_items,id',
            'sale_price'     => 'required|numeric|min:0',
        ]);

        $result = $this->repricingService->checkSalePriceRange(
            (int) $request->demand_item_id,
            (float) $request->sale_price
        );

        return response()->json($result);
    }

    // ===================== AUDIT & ACCOUNTABILITY (Phase 8) =====================

    /**
     * Show the audit checklist view.
     *
     * Phase 8 — Anti-Gaming & Accountability Controls.
     *
     * Runs all health checks and displays the results:
     *   - GL Journal Links: All received demands have both journal entries
     *   - Ledger Nature: interbranch_receivable / interbranch_payable accounts exist
     *   - Demand GL Alignment: No received demands have reversed journal entries
     *   - Journal Balance: Each journal entry has balanced Dr/Cr
     *   - Orphaned Settlements: No settlements reference reversed demands
     *   - Reversed with Open Settlements: No reversed demands have open settlements
     */
    public function checklist()
    {
        $checklist = $this->auditService->getChecklist();

        return view('admin.branch-demands.checklist', compact('checklist'));
    }

    /**
     * Show the full audit trail for a specific demand.
     *
     * Phase 8 — Anti-Gaming & Accountability Controls.
     *
     * Returns:
     *   - Stock trace, settlement trace, GL journal blocks
     *   - Anti-gaming flags for this demand
     *   - Chronological audit log
     *   - Repricing history
     */
    public function audit(int $id)
    {
        try {
            $auditData = $this->auditService->getDemandAudit($id);

            return view('admin.branch-demands.audit', compact('auditData'));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('BranchDemand audit failed', ['demand_id' => $id, 'error' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Failed to load audit data. ' . $e->getMessage()]);
        }
    }

    /**
     * Show the branch-level reconciliation view.
     *
     * Phase 8 — Anti-Gaming & Accountability Controls.
     *
     * Compares demand outstanding vs branch_ledger running balance
     * for each branch pair. Any discrepancy indicates a data integrity issue.
     */
    public function reconcile(Request $request)
    {
        $branchId = $this->currentBranchId();
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $reconciliation = $this->auditService->getReconciliation(
            $branchId,
            $dateFrom,
            $dateTo
        );

        // Get anti-gaming flags for the weekly report integration
        $antiGamingFlags = $this->auditService->getDemandAntiGamingFlags(
            $branchId,
            $dateFrom,
            $dateTo
        );

        return view('admin.branch-demands.reconcile', [
            'reconciliation'   => $reconciliation,
            'antiGamingFlags'  => $antiGamingFlags,
            'dateFrom'         => $dateFrom,
            'dateTo'           => $dateTo,
        ]);
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

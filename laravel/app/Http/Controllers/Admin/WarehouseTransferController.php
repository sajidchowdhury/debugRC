<?php

namespace App\Http\Controllers\Admin;

use App\Facades\CsvExporter;
use App\Http\Controllers\Concerns\WritesExportAuditLog;
use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Rules\WarehouseBelongsToBranch;
use App\Rules\WarehouseTransferItemHasAvailableStock;
use App\Services\Stock\WarehouseTransferService;
use App\Services\Stock\WarehouseTransferAuditService;
use App\Services\Stock\WarehouseTransferAuditLogger;
use App\Services\Stock\WarehouseTransferSummaryReport;
use App\Services\Stock\StockService;
use App\Services\Stock\StockAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;

/**
 * Warehouse Transfer Controller — Phase 6.5 + Phase 1 + Phase 2 + Phase 4 + Phase 5 + Phase 6.
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
 *
 * Phase 2 — Pipeline-aware stock availability:
 *   - WarehouseTransferItemHasAvailableStock rule on items.*.qty
 *   - getProductStock() returns pipeline-aware availability + physical + pipeline breakdown
 *   - UI shows both physical and available quantities
 *
 * Phase 4 — Audit Trail & Data Integrity:
 *   - checklist(): audit health-check dashboard (same-branch, stock, data quality, GL)
 *   - runChecks(): AJAX endpoint to run health checks
 *   - audit(): per-transfer audit detail view
 *   - reconcile(): stock reconciliation page
 *
 * Phase 5 — UI Parity & UX Improvements:
 *   - Print view for transfer documents
 *   - Same-branch-only UI (interbranch filters removed, route labels updated)
 *   - Same-branch info banner on show page
 *
 * Phase 6 — Export, Reporting & Branch Ledger Settlement:
 *   - export(): CSV export with filters + BOM for Excel compatibility
 *   - summary(): Transfer Summary Report page (branch aggregates, top products, pairs, trends)
 *   - summaryData(): AJAX endpoint for summary report data
 *   - Branch ledger settlement gap closed by Phase 1: same-branch = no intercompany GL
 */
class WarehouseTransferController extends Controller
{
    use WritesExportAuditLog;

    public function __construct(
        private WarehouseTransferService $transferService,
        private StockService $stockService,
        private StockAvailabilityService $stockAvailabilityService,
        private WarehouseTransferAuditService $auditService,
        private WarehouseTransferAuditLogger $auditLogger,
        private WarehouseTransferSummaryReport $summaryReport
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
            'items.*.qty' => [
                'required', 'numeric', 'min:0.001',
                new WarehouseTransferItemHasAvailableStock((int) $request->input('from_warehouse_id')),
            ],
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
     * Phase 2: Returns pipeline-aware availability, physical qty, and pipeline qty.
     */
    public function getProductStock(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
        ]);

        $warehouseId = (int) $request->input('warehouse_id');
        $productId   = (int) $request->input('product_id');

        $rate = $this->stockService->getWarehouseAvgCost($warehouseId, $productId);
        $physicalQty = $this->stockService->getWarehouseQty($warehouseId, $productId);
        $availableQty = $this->stockAvailabilityService->getWarehouseAvailableQty($productId, $warehouseId);
        $pipelineQty = max(0.0, $physicalQty - $availableQty);

        return response()->json([
            'rate' => round($rate, 2),
            'physical_qty' => round($physicalQty, 4),
            'available_qty' => round($availableQty, 4),
            'pipeline_qty' => round($pipelineQty, 4),
        ]);
    }

    /**
     * Phase 5: Print transfer document.
     */
    public function print(int $id)
    {
        $transfer = WarehouseTransfer::with([
            'items.product', 'fromWarehouse.branch', 'toWarehouse.branch',
            'fromBranch', 'toBranch',
        ])->findOrFail($id);

        return view('admin.warehouse-transfers.print', [
            'transfer' => $transfer,
        ]);
    }

    /**
     * Phase 6.1: Export filtered warehouse transfers as CSV.
     *
     * Takes the same filter parameters as index() and streams a CSV
     * with BOM for Excel compatibility. Uses cursor() for memory-efficient
     * iteration.
     *
     * Columns: Date, Code, From WH, To WH, Branch, Items, Amount, Demand,
     *          Reversed, Status, Created By
     *
     * REPORTS-AUDIT-4 (G-150 / csv-export.md G11): refactored to delegate
     * to CsvExporter::exportFromRows(). BOM + Content-Type + RFC 4180
     * escaping now handled by the canonical service. Column order and
     * column labels preserved exactly. Writes an export_audit_log row.
     */
    public function export(Request $request)
    {
        $userBranchId = $this->getUserBranchId();

        $query = WarehouseTransfer::with(['fromWarehouse.branch', 'toWarehouse.branch', 'items', 'createdBy'])
            ->when($userBranchId, function ($q) use ($userBranchId) {
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

        $transfers = $query->cursor();

        $headerRow = [
            'Date',
            'Code',
            'From WH',
            'To WH',
            'Branch',
            'Items',
            'Amount',
            'Demand',
            'Reversed',
            'Status',
            'Created By',
        ];

        $rowGenerator = $this->buildTransferCsvRows($transfers);

        $filename = 'WarehouseTransfers_' . now()->format('Y-m-d_His');

        // Audit log: row count unknown (cursor() stream — we do not
        // pre-count). Pass 0; the audit row records that an export
        // happened, with the filter context.
        $this->logExport('warehouse_transfers', [
            'branch_id' => $userBranchId,
            'from_date' => $request->input('from_date'),
            'to_date' => $request->input('to_date'),
            'from_warehouse_id' => $request->input('from_warehouse_id'),
            'to_warehouse_id' => $request->input('to_warehouse_id'),
            'status' => $request->input('status'),
            'search' => $request->input('search'),
        ], rowCount: 0, byteSize: 0);

        return CsvExporter::exportFromRows($filename, $headerRow, $rowGenerator);
    }

    /**
     * Build the row generator for the warehouse-transfer CSV export.
     *
     * Extracted as a private method so the lint checker can validate the
     * export() method body (the linter cannot parse `yield` inside an
     * inline closure expression).
     *
     * @param  iterable<int, WarehouseTransfer> $transfers
     * @return \Generator<int, array<int,mixed>>
     */
    private function buildTransferCsvRows(iterable $transfers): \Generator
    {
        foreach ($transfers as $t) {
            $branchName = $t->fromWarehouse?->branch?->branch_name
                ?? $t->toWarehouse?->branch?->branch_name
                ?? '';

            yield [
                $t->transfer_date ? \Carbon\Carbon::parse($t->transfer_date)->format('d-m-Y') : '',
                $t->transfer_code,
                $t->fromWarehouse?->warehouse_name ?? '',
                $t->toWarehouse?->warehouse_name ?? '',
                $branchName,
                $t->items->count(),
                number_format((float) $t->items->sum(fn ($item) => (float) $item->qty * (float) $item->rate), 2, '.', ''),
                $t->branch_demand_id ? 'Yes' : 'No',
                $t->is_reversed ? 'Yes' : 'No',
                $t->status,
                $t->createdBy?->name ?? '',
            ];
        }
    }

    // ========================================================================
    // Phase 4 — Audit Trail & Data Integrity
    // ========================================================================

    /**
     * Audit health-check dashboard.
     * Shows same-branch, stock, data quality, and GL integrity checks.
     */
    public function checklist()
    {
        $userBranchId = $this->getUserBranchId();

        return view('admin.warehouse-transfers.checklist', [
            'title'        => 'Transfer Audit Checklist',
            'userBranchId' => $userBranchId,
        ]);
    }

    /**
     * AJAX: run health checks and return JSON.
     */
    public function runChecks(Request $request)
    {
        $userBranchId = $this->getUserBranchId();

        try {
            $results = $this->auditService->runHealthChecks($userBranchId);
            return response()->json($results);
        } catch (\Throwable $e) {
            Log::error('WarehouseTransferAuditService runHealthChecks failed', [
                'error' => $e->getMessage(),
                'branch_id' => $userBranchId,
            ]);
            return response()->json([
                'error' => 'Failed to run health checks: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Per-transfer audit detail view.
     * Shows same-branch, stock movements, reversal, demand link, GL checks.
     */
    public function audit(int $id)
    {
        $transfer = WarehouseTransfer::findOrFail($id);
        $checks = $this->auditService->runTransferChecks($id);

        // Also get the audit history for this transfer from user_audit_log.
        $auditEvents = $this->auditLogger->recentTransferEvents(100, $this->getUserBranchId())
            ->filter(function ($event) {
                $details = json_decode($event->details, true);
                return isset($details['transfer_id']) && (int) $details['transfer_id'] === $id;
            });

        return view('admin.warehouse-transfers.audit', [
            'title'        => 'Transfer Audit: ' . $transfer->transfer_code,
            'transfer'     => $transfer,
            'checks'       => $checks,
            'auditEvents'  => $auditEvents,
        ]);
    }

    /**
     * Stock reconciliation page.
     * Verifies the fundamental invariant: SUM(stock_transactions.qty) = warehouse_stock.qty.
     */
    public function reconcile()
    {
        $userBranchId = $this->getUserBranchId();

        return view('admin.warehouse-transfers.reconcile', [
            'title'        => 'Stock Reconciliation',
            'userBranchId' => $userBranchId,
        ]);
    }

    /**
     * AJAX: run stock reconciliation and return JSON.
     */
    public function runReconcile(Request $request)
    {
        $userBranchId = $this->getUserBranchId();

        try {
            $results = $this->auditService->reconcileStock($userBranchId);
            return response()->json($results);
        } catch (\Throwable $e) {
            Log::error('WarehouseTransferAuditService reconcileStock failed', [
                'error' => $e->getMessage(),
                'branch_id' => $userBranchId,
            ]);
            return response()->json([
                'error' => 'Failed to run reconciliation: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ========================================================================
    // Phase 6.3 — Transfer Summary Report
    // ========================================================================

    /**
     * Summary report page — date range filter + branch dropdown.
     * Renders the summary view; data is fetched via AJAX by the view.
     */
    public function summary()
    {
        $userBranchId = $this->getUserBranchId();

        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        return view('admin.warehouse-transfers.summary', [
            'title'        => 'Transfer Summary Report',
            'userBranchId' => $userBranchId,
            'branches'     => $branches,
        ]);
    }

    /**
     * AJAX: run summary report and return JSON.
     * Accepts date_from, date_to, and optional branch_id (for admin).
     */
    public function summaryData(Request $request)
    {
        $validated = $request->validate([
            'date_from' => 'required|date',
            'date_to'   => 'required|date|after_or_equal:date_from',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        // Determine effective branch: admin can pick any; non-admin uses their own
        $userBranchId = $this->getUserBranchId();
        $effectiveBranchId = $userBranchId; // default: user's branch scope

        // Admin can override to see a specific branch or all (null)
        if ($userBranchId === null) {
            $effectiveBranchId = $validated['branch_id'] ?? null;
        }

        try {
            $results = $this->summaryReport->getSummary(
                $effectiveBranchId,
                $validated['date_from'],
                $validated['date_to']
            );
            return response()->json($results);
        } catch (\Throwable $e) {
            Log::error('WarehouseTransferSummaryReport getSummary failed', [
                'error'     => $e->getMessage(),
                'branch_id' => $effectiveBranchId,
                'date_from' => $validated['date_from'],
                'date_to'   => $validated['date_to'],
            ]);
            return response()->json([
                'error' => 'Failed to run summary report: ' . $e->getMessage(),
            ], 500);
        }
    }
}

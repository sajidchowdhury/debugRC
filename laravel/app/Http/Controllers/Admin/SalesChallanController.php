<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesChallan;
use App\Models\SalesInvoice;
use App\Services\Sales\SalesChallanService;
use App\Services\Stock\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales Challan Controller — Phase 8.3.
 *
 * Two-step flow:
 * - prepareGodown: assign warehouses to invoice items (draft → confirmed)
 * - issueChallan: stock OUT + GL Dr COGS / Cr Inventory (confirmed → challan_issued)
 * - cancel: reverse stock + GL
 */
class SalesChallanController extends Controller
{
    public function __construct(
        private SalesChallanService $challanService,
        private StockService $stockService
    ) {}

    public function index(Request $request)
    {
        $query = SalesChallan::with(['salesInvoice.customer', 'branch', 'salesInvoice.items'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('challan_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('challan_date', '<=', $d))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('challan_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('challan_date', 'desc')
            ->orderBy('id', 'desc');

        $challans = $query->paginate(25);
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        $stats = [
            'total' => SalesChallan::count(),
            'active' => SalesChallan::where('is_reversed', false)->count(),
            'reversed' => SalesChallan::where('is_reversed', true)->count(),
            'total_cogs' => SalesChallan::where('is_reversed', false)->sum('issue_cost'),
        ];

        return view('admin.sales-challans.index', [
            'title' => 'Sales Challans',
            'challans' => $challans,
            'branches' => $branches,
            'stats' => $stats,
            'filters' => $request->only(['from_date', 'to_date', 'branch_id', 'search']),
        ]);
    }

    /**
     * Show the godown prep form for a draft invoice.
     */
    public function godown(Request $request, int $invoiceId)
    {
        $invoice = SalesInvoice::with(['items.product', 'dispatches', 'customer', 'branch'])
            ->findOrFail($invoiceId);

        if (!$invoice->isDraft()) {
            return redirect()->route('admin.sales-invoices.show', $invoice)
                ->with('error', "Invoice is not draft (status: {$invoice->status}).");
        }

        // Get warehouses for the branch.
        $warehouses = \App\Models\Warehouse::active()
            ->where('branch_id', $invoice->branch_id)
            ->orderBy('warehouse_name')
            ->get();

        // Get stock availability per product per warehouse.
        $availability = [];
        foreach ($invoice->items as $item) {
            $rows = DB::table('warehouse_stock as ws')
                ->join('warehouses as w', 'w.id', '=', 'ws.warehouse_id')
                ->where('ws.product_id', $item->product_id)
                ->where('w.branch_id', $invoice->branch_id)
                ->where('w.is_active', true)
                ->select('ws.warehouse_id', 'w.warehouse_name', 'ws.qty', 'ws.avg_cost')
                ->get();
            $availability[$item->product_id] = $rows;
        }

        return view('admin.sales-challans.godown', [
            'title' => 'Godown Prep — ' . $invoice->invoice_code,
            'invoice' => $invoice,
            'warehouses' => $warehouses,
            'availability' => $availability,
        ]);
    }

    /**
     * Save godown prep (assign warehouses).
     */
    public function storeGodown(Request $request, int $invoiceId)
    {
        $validated = $request->validate([
            'warehouse_assignments' => 'required|array',
            'warehouse_assignments.*' => 'required|integer|exists:warehouses,id',
        ]);

        try {
            $invoice = $this->challanService->prepareGodown(
                $invoiceId,
                $validated['warehouse_assignments'],
                auth()->id()
            );

            return redirect()->route('admin.sales-challans.challan-form', $invoiceId)
                ->with('success', "Godown prepared for invoice {$invoice->invoice_code}. Ready to issue challan.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    /**
     * Show the challan issue form.
     */
    public function challanForm(int $invoiceId)
    {
        $invoice = SalesInvoice::with(['items.product', 'items.warehouse', 'dispatches', 'customer', 'branch'])
            ->findOrFail($invoiceId);

        if (!$invoice->is_godown_prepared) {
            return redirect()->route('admin.sales-challans.godown', $invoiceId)
                ->with('error', 'Prepare godown first before issuing challan.');
        }

        // Get avg_cost for each item (the COGS rate).
        foreach ($invoice->items as $item) {
            $item->avg_cost = $this->stockService->getWarehouseAvgCost($item->warehouse_id, $item->product_id);
            $item->cogs_amount = (float) $item->qty * (float) $item->avg_cost;
        }

        $totalCogs = $invoice->items->sum('cogs_amount');

        return view('admin.sales-challans.issue', [
            'title' => 'Issue Challan — ' . $invoice->invoice_code,
            'invoice' => $invoice,
            'totalCogs' => $totalCogs,
        ]);
    }

    /**
     * Issue the challan (stock OUT + GL COGS).
     */
    public function issueChallan(Request $request, int $invoiceId)
    {
        $validated = $request->validate([
            'transport_name' => 'nullable|string|max:100',
            'transport_phone' => 'nullable|string|max:30',
            'vehicle_number' => 'nullable|string|max:50',
            'driver_name' => 'nullable|string|max:100',
            'transport_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $challan = $this->challanService->issueChallan($invoiceId, [
                'transport_name' => $validated['transport_name'] ?? null,
                'transport_phone' => $validated['transport_phone'] ?? null,
                'vehicle_number' => $validated['vehicle_number'] ?? null,
                'driver_name' => $validated['driver_name'] ?? null,
                'transport_cost' => $validated['transport_cost'] ?? 0,
                'notes' => $validated['notes'] ?? '',
                'created_by' => auth()->id(),
            ]);

            return redirect()->route('admin.sales-challans.show', $challan)
                ->with('success', "Challan {$challan->challan_code} issued. Stock moved + COGS posted.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(int $id)
    {
        $challan = SalesChallan::with([
            'salesInvoice.items.product', 'salesInvoice.customer', 'branch',
            'journalEntry.lines.ledger'
        ])->findOrFail($id);

        $stockMovements = DB::table('stock_transactions as st')
            ->join('products as p', 'p.id', '=', 'st.product_id')
            ->join('warehouses as w', 'w.id', '=', 'st.warehouse_id')
            ->where('st.reference_type', 'sales_challan')
            ->where('st.reference_id', $id)
            ->select('st.*', 'p.product_code', 'p.product_name', 'w.warehouse_name')
            ->orderBy('st.id')
            ->get();

        return view('admin.sales-challans.show', [
            'title' => 'Challan ' . $challan->challan_code,
            'challan' => $challan,
            'stockMovements' => $stockMovements,
        ]);
    }

    /**
     * Cancel a challan (reverse stock + GL).
     */
    public function cancel(Request $request, int $id)
    {
        $request->validate([
            'cancel_reason' => 'required|string|max:500',
        ]);

        try {
            $challan = $this->challanService->cancelChallan($id, auth()->id(), $request->input('cancel_reason'));
            return redirect()->route('admin.sales-challans.show', $challan)
                ->with('success', "Challan {$challan->challan_code} cancelled. Stock + GL reversed.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}

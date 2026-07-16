<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesReturn;
use App\Services\Sales\SalesReturnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sales Return Controller — Phase 8.5.
 *
 * Two-phase: create (no GL) → confirm (stock IN at ORIGINAL cost + GL) → reverse.
 */
class SalesReturnController extends Controller
{
    public function __construct(
        private SalesReturnService $returnService
    ) {}

    public function index(Request $request)
    {
        $query = SalesReturn::with(['salesInvoice', 'customer', 'branch', 'items'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('return_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('return_date', '<=', $d))
            ->when($request->input('customer_id'), fn($q, $cid) => $q->where('customer_id', $cid))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('return_code', 'ILIKE', "%{$search}%");
            })
            ->orderBy('return_date', 'desc')
            ->orderBy('id', 'desc');

        $returns = $query->paginate(25);

        $customers = \App\Models\Customer::active()->orderBy('customer_name')->limit(500)->get();
        $branches = \App\Models\Branch::active()->orderBy('branch_name')->get();

        $stats = [
            'total' => SalesReturn::count(),
            'created' => SalesReturn::where('status', 'created')->count(),
            'confirmed' => SalesReturn::where('status', 'confirmed')->count(),
            'reversed' => SalesReturn::where('status', 'reversed')->count(),
            'total_value' => SalesReturn::where('status', 'confirmed')->sum('total_amount'),
        ];

        return view('admin.sales-returns.index', [
            'title' => 'Sales Returns',
            'returns' => $returns,
            'customers' => $customers,
            'branches' => $branches,
            'stats' => $stats,
            'filters' => $request->only(['from_date', 'to_date', 'customer_id', 'branch_id', 'status', 'search']),
        ]);
    }

    public function create(Request $request)
    {
        $invoice = null;
        $invoiceId = $request->input('invoice_id');
        if ($invoiceId) {
            $invoice = \App\Models\SalesInvoice::with(['items.product', 'items.warehouse', 'customer', 'branch'])
                ->where('is_challan_issued', true)
                ->where('is_reversed', false)
                ->findOrFail($invoiceId);
        }

        // Get challan-issued invoices for the selector.
        $invoices = \App\Models\SalesInvoice::with(['customer', 'branch'])
            ->where('is_challan_issued', true)
            ->where('is_reversed', false)
            ->orderBy('invoice_date', 'desc')
            ->limit(100)
            ->get();

        return view('admin.sales-returns.create', [
            'title' => 'New Sales Return',
            'invoices' => $invoices,
            'invoice' => $invoice,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'sales_invoice_id' => 'required|integer|exists:sales_invoices,id',
            'return_date' => 'required|date',
            'reason' => 'nullable|string|max:1000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.warehouse_id' => 'required|integer|exists:warehouses,id',
            'items.*.qty' => 'required|numeric|min:0.001',
            'items.*.rate' => 'nullable|numeric|min:0',
            'items.*.sales_invoice_item_id' => 'nullable|integer',
        ]);

        try {
            $return = $this->returnService->createReturn([
                'sales_invoice_id' => $validated['sales_invoice_id'],
                'return_date' => $validated['return_date'],
                'reason' => $validated['reason'] ?? '',
                'items' => $validated['items'],
                'created_by' => auth()->id(),
            ]);

            return redirect()->route('admin.sales-returns.show', $return)
                ->with('success', "Return {$return->return_code} created. Confirm to apply stock + GL.");
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(int $id)
    {
        $return = SalesReturn::with([
            'items.product', 'items.warehouse', 'salesInvoice.customer', 'customer', 'branch',
            'journalEntry.lines.ledger', 'cogsJournalEntry.lines.ledger',
        ])->findOrFail($id);

        $stockMovements = [];
        if ($return->isConfirmed() || $return->is_reversed) {
            $stockMovements = DB::table('stock_transactions as st')
                ->join('products as p', 'p.id', '=', 'st.product_id')
                ->where('st.reference_type', 'sales_return')
                ->where('st.reference_id', $id)
                ->select('st.*', 'p.product_code', 'p.product_name')
                ->orderBy('st.id')
                ->get();
        }

        $customerLedgerEntries = [];
        if ($return->isConfirmed()) {
            $customerLedgerEntries = DB::table('customer_ledger')
                ->where('reference_type', 'sales_return')
                ->where('reference_id', $id)
                ->orderBy('id')
                ->get();
        }

        return view('admin.sales-returns.show', [
            'title' => 'Return ' . $return->return_code,
            'return' => $return,
            'stockMovements' => $stockMovements,
            'customerLedgerEntries' => $customerLedgerEntries,
        ]);
    }

    public function confirm(Request $request, int $id)
    {
        $request->validate([
            'confirm_reason' => 'nullable|string|max:500',
        ]);

        try {
            $return = $this->returnService->confirmReturn($id, auth()->id());
            return redirect()->route('admin.sales-returns.show', $return)
                ->with('success', "Return {$return->return_code} confirmed. Stock restored at original cost + GL posted.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function reverse(Request $request, int $id)
    {
        $request->validate([
            'reverse_reason' => 'required|string|max:500',
        ]);

        try {
            $return = $this->returnService->reverseReturn($id, auth()->id(), $request->input('reverse_reason'));
            return redirect()->route('admin.sales-returns.show', $return)
                ->with('success', "Return {$return->return_code} reversed.");
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * AJAX: Get invoice details for return form pre-fill.
     */
    public function getInvoiceDetails(Request $request)
    {
        $request->validate([
            'invoice_id' => 'required|integer|exists:sales_invoices,id',
        ]);

        $invoice = \App\Models\SalesInvoice::with(['items.product', 'items.warehouse', 'customer', 'branch'])
            ->where('is_challan_issued', true)
            ->where('is_reversed', false)
            ->findOrFail($request->input('invoice_id'));

        // Calculate returnable_qty for each item.
        $items = $invoice->items->map(function ($item) {
            $alreadyReturned = (float) DB::table('sales_return_items as sri')
                ->join('sales_returns as sr', 'sr.id', '=', 'sri.sales_return_id')
                ->where('sri.sales_invoice_item_id', $item->id)
                ->whereIn('sr.status', ['created', 'confirmed'])
                ->where('sr.is_reversed', false)
                ->sum('sri.qty');

            return [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_code' => $item->product?->product_code,
                'product_name' => $item->product?->product_name,
                'qty' => (float) $item->qty,
                'already_returned' => $alreadyReturned,
                'returnable_qty' => max(0, (float) $item->qty - $alreadyReturned),
                'rate' => (float) $item->rate,
                'warehouse_id' => $item->warehouse_id,
                'warehouse_name' => $item->warehouse?->warehouse_name,
            ];
        })->filter(fn($i) => $i['returnable_qty'] > 0.0001)->values();

        return response()->json([
            'invoice' => [
                'id' => $invoice->id,
                'invoice_code' => $invoice->invoice_code,
                'customer_id' => $invoice->customer_id,
                'customer_name' => $invoice->customer?->customer_name,
                'branch_id' => $invoice->branch_id,
            ],
            'items' => $items,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Archive\Services\ArchiveService;
use Illuminate\Http\Request;

/**
 * Archive Controller — Phase 12.
 *
 * Unified historical search. Users search for invoices, customers, or
 * ledger entries and the ArchiveService determines whether the data is
 * in PostgreSQL (operational) or legacy MySQL (archive).
 *
 * The UI displays results from both sources using the same DTOs.
 * Users see a badge indicating whether a record is from the archive.
 */
class ArchiveController extends Controller
{
    public function __construct(
        private ArchiveService $archiveService
    ) {}

    /**
     * Archive search page.
     */
    public function index(Request $request)
    {
        $search = $request->input('search', '');
        $type = $request->input('type', 'invoice'); // invoice, customer, ledger
        $results = null;
        $archiveAvailable = $this->archiveService->isArchiveAvailable();

        if ($search) {
            $results = match ($type) {
                'invoice' => $this->archiveService->searchInvoices($search),
                'customer' => $this->archiveService->searchCustomers($search),
                default => $this->archiveService->searchInvoices($search),
            };
        }

        return view('admin.archive.index', [
            'title' => 'Historical Archive Search',
            'search' => $search,
            'type' => $type,
            'results' => $results,
            'archiveAvailable' => $archiveAvailable,
        ]);
    }

    /**
     * View customer ledger history (PG + archive merged).
     */
    public function customerLedger(Request $request, int $customerId)
    {
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        $entries = $this->archiveService->getCustomerLedger($customerId, $fromDate, $toDate);
        $customer = \App\Models\Customer::find($customerId);

        return view('admin.archive.customer-ledger', [
            'title' => 'Customer Ledger History — ' . ($customer?->customer_name ?? "#{$customerId}"),
            'customer' => $customer,
            'entries' => $entries,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
        ]);
    }

    /**
     * View supplier ledger history.
     */
    public function supplierLedger(Request $request, int $supplierId)
    {
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        $entries = $this->archiveService->getSupplierLedger($supplierId, $fromDate, $toDate);
        $supplier = \App\Models\Supplier::find($supplierId);

        return view('admin.archive.supplier-ledger', [
            'title' => 'Supplier Ledger History — ' . ($supplier?->supplier_name ?? "#{$supplierId}"),
            'supplier' => $supplier,
            'entries' => $entries,
            'fromDate' => $fromDate,
            'toDate' => $toDate,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SalesInvoice;
use App\Models\SalesChallan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

/**
 * CSV Export Controller — Invoices + Challans.
 *
 * Provides streaming CSV export endpoints for the sales invoice
 * and challan index pages. Uses the same filter parameters as the
 * index() methods so users can filter first, then export exactly
 * what they see.
 *
 * Ported from legacy:
 *   - SalesController::export() → invoices CSV
 *   - ChallanController::export() → challans CSV
 *
 * All CSVs include BOM (0xEF 0xBB 0xBF) for Excel compatibility.
 */
class CsvExportController extends Controller
{
    /**
     * Export filtered sales invoices as CSV.
     *
     * Columns: Invoice Code, Date, Customer, Mobile, Branch,
     * Salesman, Total Amount, Paid, Due, Status, Godown, Challan
     */
    public function exportInvoices(Request $request)
    {
        $query = SalesInvoice::with(['customer', 'branch', 'salesman'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('invoice_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('invoice_date', '<=', $d))
            ->when($request->input('customer_id'), fn($q, $cid) => $q->where('customer_id', $cid))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('status'), fn($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('invoice_code', 'ILIKE', "%{$search}%");
            })
            ->whereNull('deleted_at')
            ->orderBy('invoice_date', 'desc')
            ->orderBy('id', 'desc');

        $invoices = $query->cursor();

        $filename = 'Invoices_' . now()->format('Y-m-d_His') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        $callback = function () use ($invoices) {
            $output = fopen('php://output', 'w');

            // BOM for Excel UTF-8 compatibility
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Header row
            fputcsv($output, [
                'Invoice Code',
                'Date',
                'Customer',
                'Mobile',
                'Branch',
                'Salesman',
                'Total Amount',
                'Paid',
                'Due',
                'Status',
                'Godown Prepared',
                'Challan Issued',
            ]);

            foreach ($invoices as $inv) {
                fputcsv($output, [
                    $inv->invoice_code,
                    $inv->invoice_date ? \Carbon\Carbon::parse($inv->invoice_date)->format('d-m-Y') : '',
                    $inv->customer?->customer_name ?? '',
                    $inv->customer?->mobile ?? $inv->customer?->phone ?? '',
                    $inv->branch?->branch_name ?? '',
                    $inv->salesman?->name ?? '',
                    number_format((float) $inv->total_amount, 2, '.', ''),
                    number_format((float) $inv->paid_amount, 2, '.', ''),
                    number_format((float) $inv->due_amount, 2, '.', ''),
                    $inv->status,
                    $inv->is_godown_prepared ? 'Yes' : 'No',
                    $inv->is_challan_issued ? 'Yes' : 'No',
                ]);
            }

            fclose($output);
        };

        return Response::stream($callback, 200, $headers);
    }

    /**
     * Export filtered sales challans as CSV.
     *
     * Columns: Challan Code, Date, Invoice No, Customer, Mobile,
     * Branch, Salesman, COGS, Transport, Status
     */
    public function exportChallans(Request $request)
    {
        $query = SalesChallan::with(['salesInvoice.customer', 'branch', 'salesInvoice.salesman'])
            ->when($request->input('from_date'), fn($q, $d) => $q->where('challan_date', '>=', $d))
            ->when($request->input('to_date'), fn($q, $d) => $q->where('challan_date', '<=', $d))
            ->when($request->input('branch_id'), fn($q, $bid) => $q->where('branch_id', $bid))
            ->when($request->input('search'), function ($q, $search) {
                $q->where('challan_code', 'ILIKE', "%{$search}%");
            })
            ->whereNull('deleted_at')
            ->orderBy('challan_date', 'desc')
            ->orderBy('id', 'desc');

        $challans = $query->cursor();

        $filename = 'Challans_' . now()->format('Y-m-d_His') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        $callback = function () use ($challans) {
            $output = fopen('php://output', 'w');

            // BOM for Excel UTF-8 compatibility
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Header row
            fputcsv($output, [
                'Challan Code',
                'Challan Date',
                'Invoice No',
                'Customer',
                'Mobile',
                'Branch',
                'Salesman',
                'COGS (Issue Cost)',
                'Transport Cost',
                'Is Reversed',
            ]);

            foreach ($challans as $ch) {
                fputcsv($output, [
                    $ch->challan_code,
                    $ch->challan_date ? \Carbon\Carbon::parse($ch->challan_date)->format('d-m-Y') : '',
                    $ch->salesInvoice?->invoice_code ?? '',
                    $ch->salesInvoice?->customer?->customer_name ?? '',
                    $ch->salesInvoice?->customer?->mobile ?? $ch->salesInvoice?->customer?->phone ?? '',
                    $ch->branch?->branch_name ?? '',
                    $ch->salesInvoice?->salesman?->name ?? '',
                    number_format((float) ($ch->issue_cost ?? 0), 2, '.', ''),
                    number_format((float) ($ch->transport_cost ?? 0), 2, '.', ''),
                    $ch->is_reversed ? 'Yes' : 'No',
                ]);
            }

            fclose($output);
        };

        return Response::stream($callback, 200, $headers);
    }
}

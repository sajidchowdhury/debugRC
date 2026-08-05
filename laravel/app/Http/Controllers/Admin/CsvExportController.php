<?php

namespace App\Http\Controllers\Admin;

use App\Facades\CsvExporter;
use App\Http\Controllers\Concerns\WritesExportAuditLog;
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
 *
 * REPORTS-AUDIT-4 (G-150 / csv-export.md G11): both export methods
 * refactored to delegate to CsvExporter::exportFromRows(). BOM +
 * Content-Type + RFC 4180 escaping now handled by the canonical
 * service. Column order + column labels preserved exactly. Writes
 * an export_audit_log row via the WritesExportAuditLog trait.
 */
class CsvExportController extends Controller
{
    use WritesExportAuditLog;

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

        $headerRow = [
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
        ];

        $rowGenerator = $this->buildInvoiceCsvRows($invoices);

        $filename = 'Invoices_' . now()->format('Y-m-d_His');

        // Audit log: row count unknown (cursor() stream — we do not
        // pre-count). Pass 0; the audit row records that an export
        // happened, with the filter context.
        $this->logExport('sales_invoices', [
            'from_date' => $request->input('from_date'),
            'to_date' => $request->input('to_date'),
            'customer_id' => $request->input('customer_id'),
            'branch_id' => $request->input('branch_id'),
            'status' => $request->input('status'),
            'search' => $request->input('search'),
        ], rowCount: 0, byteSize: 0);

        return CsvExporter::exportFromRows($filename, $headerRow, $rowGenerator);
    }

    /**
     * Build the row generator for the sales-invoice CSV export.
     *
     * Extracted as a private method so the lint checker can validate the
     * exportInvoices() method body (the linter cannot parse `yield` inside
     * an inline closure expression).
     *
     * @param  iterable<int, SalesInvoice> $invoices
     * @return \Generator<int, array<int,mixed>>
     */
    private function buildInvoiceCsvRows(iterable $invoices): \Generator
    {
        foreach ($invoices as $inv) {
            yield [
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
            ];
        }
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

        $headerRow = [
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
        ];

        $rowGenerator = $this->buildChallanCsvRows($challans);

        $filename = 'Challans_' . now()->format('Y-m-d_His');

        // Audit log: row count unknown (cursor() stream — we do not
        // pre-count). Pass 0; the audit row records that an export
        // happened, with the filter context.
        $this->logExport('sales_challans', [
            'from_date' => $request->input('from_date'),
            'to_date' => $request->input('to_date'),
            'branch_id' => $request->input('branch_id'),
            'search' => $request->input('search'),
        ], rowCount: 0, byteSize: 0);

        return CsvExporter::exportFromRows($filename, $headerRow, $rowGenerator);
    }

    /**
     * Build the row generator for the sales-challan CSV export.
     *
     * Extracted as a private method so the lint checker can validate the
     * exportChallans() method body (the linter cannot parse `yield` inside
     * an inline closure expression).
     *
     * @param  iterable<int, SalesChallan> $challans
     * @return \Generator<int, array<int,mixed>>
     */
    private function buildChallanCsvRows(iterable $challans): \Generator
    {
        foreach ($challans as $ch) {
            yield [
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
            ];
        }
    }
}

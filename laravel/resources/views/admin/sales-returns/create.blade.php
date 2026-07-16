@extends('layouts.admin')

@section('content')
@php
    $today  = now()->format('Y-m-d');
    $oldDate = old('return_date', $today);
    $isAgainstInvoice = !empty($invoice);

    // Pre-compute invoice items (raw PHP arrays — @json in JS will encode them once).
    // Mirrors the shape returned by SalesReturnController::getInvoiceDetails() so the same JS rendering
    // codepath handles both pre-set and AJAX-loaded scenarios. We additionally look up ORIGINAL avg_cost
    // from the challan's stock_transaction (ref='sales_challan') so the user sees the COGS reversal cost.
    $invoiceItems = [];
    $invoiceMeta  = null;
    if ($isAgainstInvoice) {
        // Find the active challan for this invoice.
        $challan = \Illuminate\Support\Facades\DB::table('sales_challans')
            ->where('sales_invoice_id', $invoice->id)
            ->where('is_reversed', false)
            ->first();

        // Pre-load original_cost per (product_id, warehouse_id) from the challan's stock_transactions.
        $origCostMap = [];
        if ($challan) {
            $rows = \Illuminate\Support\Facades\DB::table('stock_transactions')
                ->where('reference_type', 'sales_challan')
                ->where('reference_id', $challan->id)
                ->where('is_reversed', false)
                ->select('product_id', 'warehouse_id', 'rate')
                ->get();
            foreach ($rows as $row) {
                $origCostMap[(int) $row->product_id . ':' . (int) $row->warehouse_id] = (float) $row->rate;
            }
        }

        $invoiceItems = $invoice->items->map(function ($item) use ($origCostMap) {
            $alreadyReturned = \Illuminate\Support\Facades\DB::table('sales_return_items as sri')
                ->join('sales_returns as sr', 'sr.id', '=', 'sri.sales_return_id')
                ->where('sri.sales_invoice_item_id', $item->id)
                ->whereIn('sr.status', ['created', 'confirmed'])
                ->where('sr.is_reversed', false)
                ->sum('sri.qty');

            $origCost = $origCostMap[(int) $item->product_id . ':' . (int) $item->warehouse_id] ?? 0;

            return [
                'id'               => $item->id,
                'product_id'       => $item->product_id,
                'product_code'     => $item->product?->product_code,
                'product_name'     => $item->product?->product_name,
                'qty'              => (float) $item->qty,
                'already_returned' => (float) $alreadyReturned,
                'returnable_qty'   => (float) $item->qty - (float) $alreadyReturned,
                'rate'             => (float) $item->rate,
                'original_cost'    => $origCost,
                'warehouse_id'     => $item->warehouse_id,
                'warehouse_name'   => $item->warehouse?->warehouse_name,
            ];
        })->filter(fn ($i) => $i['returnable_qty'] > 0.0001)->values()->all();

        $invoiceMeta = [
            'id'            => $invoice->id,
            'invoice_code'  => $invoice->invoice_code,
            'customer_id'   => $invoice->customer_id,
            'customer_name' => $invoice->customer?->customer_name,
            'branch_id'     => $invoice->branch_id,
        ];
    }
@endphp

<div class="container-fluid py-2">
    {{-- Hero header (orange gradient — goods coming back) --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#ea580c,#d97706);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-rotate-left me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Return goods from a customer against a challan-issued invoice. Created first — confirm to apply stock IN + GL.
            </p>
        </div>
        <div>
            <a href="{{ route('admin.sales-returns.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Critical info banner --}}
    <div class="alert alert-warning border-warning d-flex align-items-start mb-3" role="alert">
        <i class="fas fa-circle-exclamation me-2 fa-lg text-warning"></i>
        <div>
            <strong class="text-warning">Stock will be returned at the ORIGINAL avg_cost from the challan</strong>
            (NOT the current avg_cost). This preserves COGS integrity — the COGS reversal matches the original sale exactly,
            and the avg_cost is restored to its pre-sale value.
            <hr class="my-2">
            <span class="small">
                <strong>GL posts (on confirm):</strong>
                <span class="badge bg-secondary-subtle text-secondary me-1">Dr Sales Return</span>
                <span class="badge bg-secondary-subtle text-secondary me-1">Cr Accounts Receivable</span>
                (revenue reversal at sales rate) +
                <span class="badge bg-secondary-subtle text-secondary me-1">Dr Inventory</span>
                <span class="badge bg-secondary-subtle text-secondary me-1">Cr COGS</span>
                (at original avg_cost). Customer ledger is <em>credited</em> (customer owes less).
            </span>
        </div>
    </div>

    @if (session('error'))
        <div class="alert alert-danger mb-3">
            <i class="fas fa-triangle-exclamation me-1"></i> {{ session('error') }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.sales-returns.store') }}" id="returnForm">
        @csrf

        {{-- Hidden: sales_invoice_id --}}
        <input type="hidden" name="sales_invoice_id" id="sales_invoice_id"
               value="{{ $isAgainstInvoice ? $invoice->id : old('sales_invoice_id') }}">

        {{-- Header card --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-warning"></i> Return header</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    {{-- Invoice selector (only if not already pre-selected) --}}
                    @if (!$isAgainstInvoice)
                        <div class="col-12">
                            <label class="form-label" for="invoice_select">
                                Sales Invoice (challan-issued) <span class="text-danger">*</span>
                            </label>
                            <select id="invoice_select" name="invoice_select" class="form-select select2">
                                <option value="">Select a challan-issued invoice to load its returnable items…</option>
                                @foreach ($invoices as $inv)
                                    <option value="{{ $inv->id }}"
                                        {{ (string) old('sales_invoice_id') === (string) $inv->id ? 'selected' : '' }}>
                                        {{ $inv->invoice_code }} —
                                        @if ($inv->customer){{ $inv->customer->customer_name }}@endif
                                        @if ($inv->branch) · {{ $inv->branch->branch_name }}@endif
                                        · {{ \Carbon\Carbon::parse($inv->invoice_date)->format('d M Y') }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text small">
                                <i class="fas fa-circle-info me-1"></i>
                                Only challan-issued, non-reversed invoices are listed. Selecting one will lock customer/branch
                                and pre-fill items with returnable qty (sold − already returned).
                            </div>
                        </div>
                    @endif

                    {{-- Return date --}}
                    <div class="col-md-4">
                        <label class="form-label" for="return_date">
                            Return date <span class="text-danger">*</span>
                        </label>
                        <input type="date" id="return_date" name="return_date"
                               class="form-control @error('return_date') is-invalid @enderror"
                               required value="{{ $oldDate }}">
                        @error('return_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Customer (display-only — locked to invoice) --}}
                    <div class="col-md-4">
                        <label class="form-label text-muted">Customer</label>
                        <div class="form-control bg-light" id="customerDisplay">
                            <span class="text-muted">— select an invoice —</span>
                        </div>
                    </div>

                    {{-- Branch (display-only — locked to invoice) --}}
                    <div class="col-md-4">
                        <label class="form-label text-muted">Branch</label>
                        <div class="form-control bg-light" id="branchDisplay">
                            <span class="text-muted">— select an invoice —</span>
                        </div>
                    </div>

                    {{-- Reason --}}
                    <div class="col-12">
                        <label class="form-label" for="reason">Reason</label>
                        <textarea id="reason" name="reason" rows="2" class="form-control"
                                  placeholder="Why is this stock being returned? e.g. damaged, wrong item, expired, customer cancelled">{{ old('reason') }}</textarea>
                        @error('reason') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- Items table --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">
                    <i class="fas fa-table-list me-1 text-warning"></i> Items to return
                    <span class="badge bg-warning-subtle text-warning ms-1" id="itemsCount">0</span>
                </h2>
                <div class="text-muted small">
                    <i class="fas fa-circle-info me-1"></i>
                    Qty ≤ returnable. Sales rate = revenue reversal. <strong>Original cost = COGS reversal + stock IN.</strong>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0" id="itemsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th>Warehouse</th>
                                <th class="text-end">Qty Sold</th>
                                <th class="text-end">Already Returned</th>
                                <th class="text-end">Returnable</th>
                                <th class="text-end" style="min-width:110px;">Qty to Return</th>
                                <th class="text-end">Sales Rate (Tk)</th>
                                <th class="text-end" style="background:#fff7ed;">
                                    <span class="text-warning" title="ORIGINAL avg_cost from the challan">
                                        <i class="fas fa-circle-exclamation me-1"></i>Original Cost (Tk)
                                    </span>
                                </th>
                                <th class="text-end">Revenue (Tk)</th>
                                <th class="text-end">COGS (Tk)</th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            {{-- Rows injected by JS --}}
                        </tbody>
                        <tbody id="itemsEmpty">
                            <tr>
                                <td colspan="10" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    <span id="emptyMsg">Select an invoice above to load returnable items.</span>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="table-warning fw-bold">
                                <td colspan="8" class="text-end">Totals</td>
                                <td class="text-end" id="grandRevenue">Tk 0.00</td>
                                <td class="text-end" id="grandCogs">Tk 0.00</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        {{-- Action buttons --}}
        <div class="d-flex gap-2 justify-content-end mb-4">
            <a href="{{ route('admin.sales-returns.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-times me-1"></i> Cancel
            </a>
            <button type="submit" class="btn btn-warning" id="submitBtn">
                <i class="fas fa-save me-1"></i> Create Return
            </button>
        </div>
    </form>
</div>

@push('scripts')
<script>
$(function () {
    var PRESET_ITEMS = @json($invoiceItems);
    var PRESET_META  = @json($invoiceMeta);
    var AJAX_URL     = "{{ route('admin.sales-returns.invoice-details') }}";

    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmt(n, dp) {
        dp = (typeof dp === 'undefined') ? 2 : dp;
        return Number(n).toLocaleString('en-US', { minimumFractionDigits: dp, maximumFractionDigits: dp });
    }

    // Render items into the table.
    window.renderItems = function (data) {
        var $body = $('#itemsBody');
        $body.empty();

        if (!data || !data.items || data.items.length === 0) {
            $('#itemsEmpty').show().find('#emptyMsg')
                .text('No returnable items on this invoice (all items fully returned already).');
            $('#itemsCount').text('0');
            $('#grandRevenue').text('Tk 0.00');
            $('#grandCogs').text('Tk 0.00');
            return;
        }
        $('#itemsEmpty').hide();

        $.each(data.items, function (idx, it) {
            // original_cost may be missing if the AJAX endpoint doesn't return it.
            // Display gracefully: show '—' (the service will look it up on confirm).
            var hasOrigCost = (typeof it.original_cost !== 'undefined') && (Number(it.original_cost) > 0);
            var origCost = hasOrigCost ? Number(it.original_cost) : 0;
            var origCostCell = hasOrigCost
                ? '<span class="text-warning fw-bold">' + fmt(origCost, 2) + '</span>'
                : '<span class="text-muted" title="Will be looked up from the challan on confirm">—</span>';

            var $tr = $(
                '<tr data-idx="' + idx + '" data-returnable="' + it.returnable_qty + '" data-rate="' + it.rate + '" data-origcost="' + origCost + '">' +
                    '<td>' +
                        '<span class="fw-semibold">' + escapeHtml(it.product_name || 'Product #' + it.product_id) + '</span>' +
                        '<div class="small text-muted">' + escapeHtml(it.product_code || '') + '</div>' +
                    '</td>' +
                    '<td>' +
                        '<span class="small">' + escapeHtml(it.warehouse_name || ('#' + it.warehouse_id)) + '</span>' +
                    '</td>' +
                    '<td class="text-end">' + fmt(it.qty, 4) + '</td>' +
                    '<td class="text-end">' + fmt(it.already_returned, 4) + '</td>' +
                    '<td class="text-end fw-bold text-warning bg-warning-subtle">' + fmt(it.returnable_qty, 4) + '</td>' +
                    '<td class="text-end">' +
                        '<input type="number" class="form-control form-control-sm text-end qty-input" ' +
                               'name="items[' + idx + '][qty]" ' +
                               'min="0.001" max="' + it.returnable_qty + '" step="0.001" ' +
                               'value="' + it.returnable_qty + '" required>' +
                        '<input type="hidden" name="items[' + idx + '][product_id]" value="' + it.product_id + '">' +
                        '<input type="hidden" name="items[' + idx + '][warehouse_id]" value="' + it.warehouse_id + '">' +
                        '<input type="hidden" name="items[' + idx + '][rate]" value="' + it.rate + '">' +
                        '<input type="hidden" name="items[' + idx + '][sales_invoice_item_id]" value="' + it.id + '">' +
                    '</td>' +
                    '<td class="text-end">' + fmt(it.rate, 2) + '</td>' +
                    '<td class="text-end" style="background:#fff7ed;">' + origCostCell + '</td>' +
                    '<td class="text-end revenue-cell">0.00</td>' +
                    '<td class="text-end cogs-cell">0.00</td>' +
                '</tr>'
            );
            $body.append($tr);
        });

        $('#itemsCount').text(data.items.length);
        recomputeTotals();
    };

    // Compute revenue + COGS per line + grand totals.
    function recomputeTotals() {
        var grandRev  = 0;
        var grandCogs = 0;
        $('#itemsBody tr').each(function () {
            var $tr  = $(this);
            var qty  = parseFloat($tr.find('.qty-input').val()) || 0;
            var rate = parseFloat($tr.data('rate')) || 0;
            var cost = parseFloat($tr.data('origcost')) || 0;
            var rev  = qty * rate;
            var cogs = qty * cost;
            $tr.find('.revenue-cell').text(fmt(rev, 2));
            $tr.find('.cogs-cell').text(fmt(cogs, 2));
            grandRev  += rev;
            grandCogs += cogs;
        });
        $('#grandRevenue').text('Tk ' + fmt(grandRev, 2));
        $('#grandCogs').text('Tk ' + fmt(grandCogs, 2));
    }

    // Validate qty ≤ returnable on input.
    $(document).on('input', '.qty-input', function () {
        var $inp = $(this);
        var max  = parseFloat($inp.attr('max'));
        var val  = parseFloat($inp.val());
        if (isNaN(val) || val < 0) {
            $inp.val(0);
        } else if (val > max) {
            $inp.val(max);
            Swal.fire({
                icon: 'warning',
                title: 'Qty exceeds returnable',
                text: 'Maximum returnable qty is ' + max + '.',
                toast: true, timer: 2500, position: 'top-end'
            });
        }
        recomputeTotals();
    });

    // Invoice selector change → AJAX load.
    $('#invoice_select').on('change', function () {
        var invoiceId = $(this).val();
        if (!invoiceId) {
            $('#itemsBody').empty();
            $('#itemsEmpty').show().find('#emptyMsg').text('Select an invoice above to load returnable items.');
            $('#itemsCount').text('0');
            $('#grandRevenue').text('Tk 0.00');
            $('#grandCogs').text('Tk 0.00');
            $('#sales_invoice_id').val('');
            $('#customerDisplay').html('<span class="text-muted">— select an invoice —</span>');
            $('#branchDisplay').html('<span class="text-muted">— select an invoice —</span>');
            return;
        }

        $('#sales_invoice_id').val(invoiceId);
        $('#itemsEmpty').hide();
        $('#itemsBody').html(
            '<tr><td colspan="10" class="text-center text-muted py-4">' +
            '<i class="fas fa-spinner fa-spin me-1"></i> Loading returnable items…</td></tr>'
        );

        $.getJSON(AJAX_URL, { invoice_id: invoiceId })
            .done(function (data) {
                $('#customerDisplay').html('<strong>' + escapeHtml(data.invoice.customer_name || '—') + '</strong>');
                $('#branchDisplay').html('<strong>#' + escapeHtml(data.invoice.branch_id || '—') + '</strong>');
                window.renderItems(data);
            })
            .fail(function (xhr) {
                var msg = 'Failed to load invoice details.';
                if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                Swal.fire({ icon: 'error', title: 'Error', text: msg });
                $('#itemsBody').empty();
                $('#itemsEmpty').show().find('#emptyMsg').text(msg);
            });
    });

    // Pre-set: render immediately if an invoice was pre-selected via ?invoice_id=.
    if (PRESET_META && PRESET_ITEMS) {
        $('#customerDisplay').html('<strong>' + escapeHtml(PRESET_META.customer_name || '—') + '</strong>');
        $('#branchDisplay').html('<strong>#' + escapeHtml(PRESET_META.branch_id || '—') + '</strong>');
        window.renderItems({ items: PRESET_ITEMS });
    }

    // Submit guard: ensure at least one item with qty > 0.
    $('#returnForm').on('submit', function (e) {
        var any = false;
        $('#itemsBody .qty-input').each(function () {
            if ((parseFloat($(this).val()) || 0) > 0) { any = true; return false; }
        });
        if (!any) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'No items selected',
                text: 'Add at least one item with qty > 0 to create a return.'
            });
            return false;
        }
        var $btn = $('#submitBtn');
        $btn.prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-1"></i> Creating…');
        return true;
    });
});
</script>
@endpush
@endsection

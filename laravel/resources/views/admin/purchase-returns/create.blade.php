@extends('layouts.admin')

@section('content')
@php
    $today       = now()->format('Y-m-d');
    $oldDate     = old('return_date', $today);
    $isAgainstGrn = !empty($receive);

    // Pre-compute GRN items JSON for the JS builder (when returning against a pre-selected GRN).
    // Mirrors the shape returned by PurchaseReturnController::getReceiveDetails() so the same
    // JS rendering codepath handles both pre-set and AJAX-loaded scenarios.
    $receiveItemsJson = '[]';
    $receiveMetaJson  = 'null';
    if ($isAgainstGrn) {
        $items = $receive->items->map(function ($item) {
            $alreadyReturned = \Illuminate\Support\Facades\DB::table('purchase_return_items')
                ->where('purchase_receive_item_id', $item->id)
                ->whereIn('purchase_return_id', function ($q) {
                    $q->select('id')->from('purchase_returns')
                      ->where('status', 'confirmed')
                      ->where('is_reversed', false);
                })
                ->sum('qty');

            return [
                'id'                => $item->id,
                'product_id'        => $item->product_id,
                'product_code'      => $item->product?->product_code,
                'product_name'      => $item->product?->product_name,
                'received_qty'      => (float) $item->qty,
                'already_returned'  => (float) $alreadyReturned,
                'returnable_qty'    => (float) $item->qty - (float) $alreadyReturned,
                'rate'              => (float) $item->rate,
                'warehouse_id'      => $item->warehouse_id,
            ];
        })->filter(fn ($i) => $i['returnable_qty'] > 0.0001)->values()->all();

        $receiveItemsJson = json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $receiveMetaJson  = json_encode([
            'id'             => $receive->id,
            'receive_code'   => $receive->receive_code,
            'supplier_id'    => $receive->supplier_id,
            'supplier_name'  => $receive->supplier?->supplier_name,
            'branch_id'      => $receive->branch_id,
        ], JSON_UNESCAPED_UNICODE);
    }

    // Warehouses JSON for the per-line warehouse select2 in JS.
    $warehousesJson = json_encode(
        $warehouses->map(fn ($w) => [
            'id'   => $w->id,
            'code' => $w->warehouse_code,
            'name' => $w->warehouse_name,
            'branch' => $w->branch?->branch_name,
        ])->values()->all(),
        JSON_UNESCAPED_UNICODE
    );
@endphp

<div class="container-fluid py-2">
    {{-- Hero header (amber — goods going back) --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#d97706,#b45309);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-truck-arrow-right me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Return goods to a supplier against a confirmed GRN. Draft created first — confirm to apply stock OUT + GL + supplier ledger.
            </p>
        </div>
        <div>
            <a href="{{ route('admin.purchase-returns.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Info banner --}}
    <div class="alert alert-warning d-flex align-items-start mb-3" role="alert">
        <i class="fas fa-circle-info me-2 fa-lg"></i>
        <div>
            <strong>Select a confirmed GRN to return goods to the supplier.</strong>
            Stock leaves at the <em>ORIGINAL receive rate</em> (not current avg_cost).
            GL posts <em>Dr Accounts Payable / Cr Inventory</em>.
            Supplier ledger is <em>debited</em> (we owe the supplier less).
        </div>
    </div>

    {{-- If against GRN: GRN info banner --}}
    @if ($isAgainstGrn)
        <div class="alert alert-primary d-flex align-items-start mb-3" role="alert">
            <i class="fas fa-link me-2 fa-lg"></i>
            <div>
                <strong>Returning against GRN:</strong>
                <a href="{{ route('admin.purchase-receives.show', $receive) }}" class="alert-link">{{ $receive->receive_code }}</a>
                ·
                @if ($receive->supplier){{ $receive->supplier->supplier_name }}@endif
                @if ($receive->branch) · {{ $receive->branch->branch_name }}@endif
                · Dated {{ \Carbon\Carbon::parse($receive->receive_date)->format('d M Y') }}
                <div class="small text-muted mt-1">
                    Supplier and branch are locked to the GRN. Per-line qty (≤ returnable_qty) and warehouse can be adjusted. Rate is locked to the original receive rate.
                </div>
            </div>
        </div>
    @endif

    @if (session('error'))
        <div class="alert alert-danger mb-3">
            <i class="fas fa-triangle-exclamation me-1"></i> {{ session('error') }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.purchase-returns.store') }}" id="returnForm">
        @csrf

        {{-- Hidden: purchase_receive_id --}}
        @if ($isAgainstGrn)
            <input type="hidden" name="purchase_receive_id" id="purchase_receive_id" value="{{ $receive->id }}">
        @else
            <input type="hidden" name="purchase_receive_id" id="purchase_receive_id" value="{{ old('purchase_receive_id') }}">
        @endif

        {{-- Header card --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-warning"></i> Return header</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    {{-- GRN selector (only if not already pre-selected) --}}
                    @if (!$isAgainstGrn)
                        <div class="col-12">
                            <label class="form-label" for="grn_select">
                                GRN (Goods Receipt Note) <span class="text-danger">*</span>
                            </label>
                            <select id="grn_select" name="grn_select" class="form-select select2">
                                <option value="">Select a confirmed GRN to load its returnable items…</option>
                                @foreach ($receives as $rcv)
                                    <option value="{{ $rcv->id }}"
                                        {{ (string) old('purchase_receive_id') === (string) $rcv->id ? 'selected' : '' }}>
                                        {{ $rcv->receive_code }} —
                                        @if ($rcv->supplier){{ $rcv->supplier->supplier_name }}@endif
                                        @if ($rcv->branch) · {{ $rcv->branch->branch_name }}@endif
                                        · {{ \Carbon\Carbon::parse($rcv->receive_date)->format('d M Y') }}
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text small">
                                <i class="fas fa-circle-info me-1"></i>
                                Only confirmed GRNs (not reversed) are listed. Selecting one will lock supplier/branch and pre-fill items with returnable qty.
                            </div>
                        </div>
                    @endif

                    {{-- Return date --}}
                    <div class="col-md-6">
                        <label class="form-label" for="return_date">
                            Return date <span class="text-danger">*</span>
                        </label>
                        <input type="date" id="return_date" name="return_date"
                               class="form-control @error('return_date') is-invalid @enderror"
                               required value="{{ $oldDate }}">
                        @error('return_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Supplier (display-only when GRN locked) --}}
                    <div class="col-md-3">
                        <label class="form-label text-muted">Supplier</label>
                        <div class="form-control bg-light" id="supplierDisplay">
                            <span class="text-muted">— select a GRN —</span>
                        </div>
                    </div>

                    {{-- Branch (display-only when GRN locked) --}}
                    <div class="col-md-3">
                        <label class="form-label text-muted">Branch</label>
                        <div class="form-control bg-light" id="branchDisplay">
                            <span class="text-muted">— select a GRN —</span>
                        </div>
                    </div>

                    {{-- Reason --}}
                    <div class="col-12">
                        <label class="form-label" for="reason">Reason</label>
                        <textarea id="reason" name="reason" rows="2" class="form-control"
                                  placeholder="Why is this stock being returned? e.g. defective, wrong item, expired, excess, quality issue">{{ old('reason') }}</textarea>
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
                    Qty ≤ returnable. Rate locked to original receive rate.
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle mb-0" id="itemsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th class="text-end">Received</th>
                                <th class="text-end">Already returned</th>
                                <th class="text-end">Returnable</th>
                                <th class="text-end" style="min-width:120px;">Qty to return</th>
                                <th class="text-end">Rate (Tk)</th>
                                <th style="min-width:200px;">Warehouse</th>
                                <th class="text-end">Amount (Tk)</th>
                            </tr>
                        </thead>
                        <tbody id="itemsBody">
                            {{-- Rows injected by JS --}}
                        </tbody>
                        <tbody id="itemsEmpty">
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    <span id="emptyMsg">Select a GRN above to load returnable items.</span>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="table-warning fw-bold">
                                <td colspan="7" class="text-end">Total amount</td>
                                <td class="text-end" id="grandTotal">Tk 0.00</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        {{-- Action buttons --}}
        <div class="d-flex gap-2 justify-content-end mb-4">
            <a href="{{ route('admin.purchase-returns.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-times me-1"></i> Cancel
            </a>
            <button type="submit" class="btn btn-warning" id="submitBtn">
                <i class="fas fa-save me-1"></i> Create Draft Return
            </button>
        </div>
    </form>
</div>

@push('scripts')
<script>
$(function () {
    var WAREHOUSES = @json($warehousesJson);
    var PRESET_ITEMS = @json($receiveItemsJson);
    var PRESET_META  = @json($receiveMetaJson);
    var AJAX_URL     = "{{ route('admin.purchase-returns.receive-details') }}";

    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    function warehouseOptions(selectedId) {
        var html = '';
        for (var i = 0; i < WAREHOUSES.length; i++) {
            var w = WAREHOUSES[i];
            var sel = (parseInt(selectedId) === parseInt(w.id)) ? ' selected' : '';
            html += '<option value="' + w.id + '"' + sel + '>' +
                    w.code + ' — ' + w.name +
                    (w.branch ? ' (' + w.branch + ')' : '') +
                    '</option>';
        }
        return html;
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
                .text('No returnable items on this GRN (all items fully returned already).');
            $('#itemsCount').text('0');
            $('#grandTotal').text('Tk 0.00');
            return;
        }
        $('#itemsEmpty').hide();

        $.each(data.items, function (idx, it) {
            var $tr = $(
                '<tr data-idx="' + idx + '" data-returnable="' + it.returnable_qty + '" data-rate="' + it.rate + '">' +
                    '<td>' +
                        '<span class="fw-semibold">' + escapeHtml(it.product_name || 'Product #' + it.product_id) + '</span>' +
                        '<div class="small text-muted">' + escapeHtml(it.product_code || '') + '</div>' +
                    '</td>' +
                    '<td class="text-end">' + fmt(it.received_qty, 4) + '</td>' +
                    '<td class="text-end">' + fmt(it.already_returned, 4) + '</td>' +
                    '<td class="text-end fw-bold text-warning bg-warning-subtle">' + fmt(it.returnable_qty, 4) + '</td>' +
                    '<td class="text-end">' +
                        '<input type="number" class="form-control form-control-sm text-end qty-input" ' +
                               'name="items[' + idx + '][qty]" ' +
                               'min="0.001" max="' + it.returnable_qty + '" step="0.001" ' +
                               'value="' + it.returnable_qty + '" required>' +
                        '<input type="hidden" name="items[' + idx + '][product_id]" value="' + it.product_id + '">' +
                        '<input type="hidden" name="items[' + idx + '][rate]" value="' + it.rate + '">' +
                        '<input type="hidden" name="items[' + idx + '][purchase_receive_item_id]" value="' + it.id + '">' +
                    '</td>' +
                    '<td class="text-end rate-cell">' + fmt(it.rate, 2) + '</td>' +
                    '<td>' +
                        '<select class="form-select form-select-sm select2 wh-select" name="items[' + idx + '][warehouse_id]" required>' +
                            warehouseOptions(it.warehouse_id) +
                        '</select>' +
                    '</td>' +
                    '<td class="text-end amount-cell">0.00</td>' +
                '</tr>'
            );
            $body.append($tr);
        });

        $('#itemsCount').text(data.items.length);

        // Initialize Select2 on the freshly added warehouse selects.
        $body.find('.select2').each(function () {
            $(this).select2({ theme: 'bootstrap-5', width: '100%' });
        });

        recomputeTotals();
    };

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Compute amount per line + grand total.
    function recomputeTotals() {
        var grand = 0;
        $('#itemsBody tr').each(function () {
            var $tr   = $(this);
            var qty   = parseFloat($tr.find('.qty-input').val()) || 0;
            var rate  = parseFloat($tr.data('rate')) || 0;
            var amt   = qty * rate;
            $tr.find('.amount-cell').text(fmt(amt, 2));
            grand += amt;
        });
        $('#grandTotal').text('Tk ' + fmt(grand, 2));
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

    // GRN selector change → AJAX load.
    $('#grn_select').on('change', function () {
        var receiveId = $(this).val();
        if (!receiveId) {
            $('#itemsBody').empty();
            $('#itemsEmpty').show().find('#emptyMsg').text('Select a GRN above to load returnable items.');
            $('#itemsCount').text('0');
            $('#grandTotal').text('Tk 0.00');
            $('#purchase_receive_id').val('');
            $('#supplierDisplay').html('<span class="text-muted">— select a GRN —</span>');
            $('#branchDisplay').html('<span class="text-muted">— select a GRN —</span>');
            return;
        }

        $('#purchase_receive_id').val(receiveId);
        $('#itemsEmpty').hide();
        $('#itemsBody').html(
            '<tr><td colspan="8" class="text-center text-muted py-4">' +
            '<i class="fas fa-spinner fa-spin me-1"></i> Loading returnable items…</td></tr>'
        );

        $.getJSON(AJAX_URL, { receive_id: receiveId })
            .done(function (data) {
                $('#supplierDisplay').html('<strong>' + escapeHtml(data.receive.supplier_name || '—') + '</strong>');
                $('#branchDisplay').html('<strong>#' + escapeHtml(data.receive.branch_id || '—') + '</strong>');
                window.renderItems(data);
            })
            .fail(function (xhr) {
                var msg = 'Failed to load GRN details.';
                if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                Swal.fire({ icon: 'error', title: 'Error', text: msg });
                $('#itemsBody').empty();
                $('#itemsEmpty').show().find('#emptyMsg').text(msg);
            });
    });

    // Pre-set: render immediately if a GRN was pre-selected via ?receive_id=.
    if (PRESET_META && PRESET_ITEMS) {
        $('#supplierDisplay').html('<strong>' + escapeHtml(PRESET_META.supplier_name || '—') + '</strong>');
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

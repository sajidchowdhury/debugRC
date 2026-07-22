@extends('layouts.admin')

@push('css')
<link rel="stylesheet" href="/assets/css/purchase-index.css">
<link rel="stylesheet" href="/assets/css/purchase-order-form.css">
@endpush

@section('content')
@php
    $today    = now()->format('Y-m-d');
    $oldDate  = old('receive_date', $today);
    $isAgainstPo = !empty($po);

    // For "receive against PO" dropdown (when not pre-filled via ?po_id=),
    // list POs that can still receive (status sent/partial).
    $availablePos = [];
    if (!$isAgainstPo) {
        $availablePos = \App\Models\PurchaseOrder::with(['supplier'])
            ->whereIn('status', ['sent', 'partial'])
            ->orderBy('po_date', 'desc')
            ->orderBy('id', 'desc')
            ->limit(300)
            ->get();
    }

    // Pre-compute PO items JSON for the JS builder (when receiving against PO).
    $poItemsJson = '[]';
    if ($isAgainstPo) {
        $poItemsJson = json_encode(
            $po->items->filter(fn($i) => $i->remainingQty() > 0.0001)->map(fn($i) => [
                'purchase_order_item_id' => $i->id,
                'product_id'             => $i->product_id,
                'product_code'           => $i->product?->product_code,
                'product_name'           => $i->product?->product_name,
                'remaining_qty'          => $i->remainingQty(),
                'qty'                    => $i->remainingQty(),
                'rate'                   => (float) $i->rate,
                'warehouse_id'           => $po->warehouse_id,
            ])->values()->all(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );
    }

    $branchName = $isAgainstPo
        ? ($po->branch?->branch_name ?? (auth()->user()?->branch?->branch_name ?? 'Branch'))
        : (auth()->user()?->branch?->branch_name ?? 'Branch');
@endphp

<div class="purch-index-app purch-po-form-app container-fluid py-2">
    {{-- ─── Hero ──────────────────────────────────────────────────── --}}
    <header class="purch-index-hero">
        <div>
            <h1><i class="fas fa-dolly me-2"></i>New purchase receive</h1>
            <p>Record goods received from supplier — linked to PO or direct purchase.</p>
            <span class="purch-index-tag"><i class="fas fa-building me-1"></i>{{ e($branchName) }}</span>
            <span class="purch-index-tag is-alt"><i class="fas fa-warehouse me-1"></i>Stock in</span>
        </div>
        <div class="purch-index-hero-actions">
            <a href="{{ route('admin.purchase-receives.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i>List
            </a>
        </div>
    </header>

    {{-- Info banner --}}
    <div class="alert alert-success d-flex align-items-start mb-3" role="alert">
        <i class="fas fa-circle-info me-2 fa-lg"></i>
        <div>
            <strong>GRN receives stock into inventory.</strong>
            On confirm: <em>stock IN</em> (avg_cost recalculated),
            <em>GL posts Dr Inventory / Cr AP</em>,
            <em>supplier ledger credited</em>.
            @if ($isAgainstPo)
                The linked PO's <code>received_qty</code> will also be updated.
            @endif
        </div>
    </div>

    {{-- If against PO: PO info banner --}}
    @if ($isAgainstPo)
        <div class="alert alert-primary d-flex align-items-start mb-3" role="alert">
            <i class="fas fa-link me-2 fa-lg"></i>
            <div>
                <strong>Receiving against PO:</strong>
                <a href="{{ route('admin.purchase-orders.show', $po) }}" class="alert-link">{{ $po->po_code }}</a>
                ·
                @if ($po->supplier){{ $po->supplier->supplier_name }}@endif
                @if ($po->branch) · {{ $po->branch->branch_name }}@endif
                · Dated {{ \Carbon\Carbon::parse($po->po_date)->format('d M Y') }}
                <div class="small text-muted mt-1">
                    Supplier, branch and products are locked to the PO. You can adjust qty to receive (≤ remaining), rate, and per-item warehouse.
                </div>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.purchase-receives.store') }}" id="receiveForm">
        @csrf

        {{-- Hidden: purchase_order_id (only set when against PO) --}}
        @if ($isAgainstPo)
            <input type="hidden" name="purchase_order_id" value="{{ $po->id }}">
        @else
            <input type="hidden" name="purchase_order_id" id="po_id_hidden" value="{{ old('purchase_order_id') }}">
        @endif

        <div class="purch-po-form-layout">
        {{-- Header card --}}
        <section class="purch-po-form-card">
            <div class="purch-po-form-card-head"><i class="fas fa-info-circle me-1"></i> GRN header</div>
            <div class="purch-po-form-card-body">
                <div class="row g-3">
                    {{-- Receive against PO section (only if NOT already against a PO) --}}
                    @if (!$isAgainstPo)
                        <div class="col-12">
                            <div class="border rounded-3 p-3 bg-light-subtle">
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <h3 class="h6 mb-0">
                                        <i class="fas fa-link me-1 text-primary"></i> Receive against PO
                                        <small class="text-muted fw-normal">(optional)</small>
                                    </h3>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               id="directReceiveToggle" autocomplete="off"
                                               {{ old('direct_receive') ? 'checked' : '' }}>
                                        <label class="form-check-label small fw-semibold" for="directReceiveToggle">
                                            <i class="fas fa-bolt me-1"></i> Direct receive (no PO)
                                        </label>
                                    </div>
                                </div>
                                <select id="po_select" name="po_select" class="form-select">
                                    <option value="">Select a purchase order to load its items…</option>
                                    @foreach ($availablePos as $p)
                                        <option value="{{ $p->id }}"
                                            {{ (string) old('purchase_order_id') === (string) $p->id ? 'selected' : '' }}>
                                            {{ $p->po_code }} —
                                            @if ($p->supplier){{ $p->supplier->supplier_name }}@endif
                                            · {{ \Carbon\Carbon::parse($p->po_date)->format('d M Y') }}
                                            · {{ ucfirst($p->status) }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text small">
                                    <i class="fas fa-circle-info me-1"></i>
                                    Only POs with status <code>sent</code> or <code>partial</code> are listed.
                                    Selecting one will lock supplier/branch and pre-fill items with remaining qty.
                                </div>
                            </div>
                        </div>
                    @endif

                    @if ($isAgainstPo)
                        {{-- ============================================================ --}}
                        {{-- Against-PO mode: supplier/branch/warehouse are LOCKED to PO --}}
                        {{-- Show as read-only info panels (not disabled selects).      --}}
                        {{-- Only date + notes are editable. Hidden inputs keep the     --}}
                        {{-- required form data flowing to the Form Request.            --}}
                        {{-- ============================================================ --}}

                        {{-- Hidden inputs (submit supplier_id, branch_id, warehouse_id from PO) --}}
                        <input type="hidden" name="supplier_id"  value="{{ $po->supplier_id }}">
                        <input type="hidden" name="branch_id"    value="{{ $po->branch_id }}">
                        <input type="hidden" name="warehouse_id" id="warehouse_id" value="{{ $po->warehouse_id }}">

                        {{-- Supplier (read-only) --}}
                        <div class="col-md-4">
                            <label class="form-label text-muted small">Supplier</label>
                            <div class="form-control bg-light-subtle">
                                @if ($po->supplier)
                                    <i class="fas fa-truck me-1 text-muted"></i>
                                    <span class="fw-semibold">{{ $po->supplier->supplier_name }}</span>
                                    <div class="small text-muted">{{ $po->supplier->supplier_code }}</div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </div>
                        </div>

                        {{-- Branch (read-only) --}}
                        <div class="col-md-4">
                            <label class="form-label text-muted small">Branch</label>
                            <div class="form-control bg-light-subtle">
                                @if ($po->branch)
                                    <i class="fas fa-building me-1 text-muted"></i>
                                    <span class="fw-semibold">{{ $po->branch->branch_name }}</span>
                                    <div class="small text-muted">{{ $po->branch->branch_code }}</div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </div>
                        </div>

                        {{-- Default warehouse (read-only hint; actual selection is per-line in items table) --}}
                        <div class="col-md-4">
                            <label class="form-label text-muted small">Default warehouse (from PO)</label>
                            <div class="form-control bg-light-subtle">
                                @if ($po->warehouse)
                                    <i class="fas fa-warehouse me-1 text-muted"></i>
                                    <span class="fw-semibold">{{ $po->warehouse->warehouse_name }}</span>
                                    <div class="small text-muted">{{ $po->warehouse->warehouse_code }}</div>
                                @else
                                    <span class="text-muted">— per-line selection required</span>
                                @endif
                            </div>
                            <div class="form-text small">
                                <i class="fas fa-circle-info me-1"></i>
                                Pick the actual receiving warehouse per product line below.
                            </div>
                        </div>

                        {{-- Receive date (editable) --}}
                        <div class="col-md-4">
                            <label class="form-label" for="receive_date">
                                Receive date <span class="text-danger">*</span>
                            </label>
                            <input type="date" id="receive_date" name="receive_date"
                                   class="form-control @error('receive_date') is-invalid @enderror"
                                   required value="{{ $oldDate }}">
                            @error('receive_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        {{-- Notes (editable) --}}
                        <div class="col-12">
                            <label class="form-label" for="notes">Notes</label>
                            <textarea id="notes" name="notes" rows="2" class="form-control"
                                      placeholder="Optional — internal note, supplier challan ref, vehicle no, etc.">{{ old('notes') }}</textarea>
                            @error('notes') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>
                    @else
                        {{-- ============================================================ --}}
                        {{-- Direct-receive mode (no PO): full editable header.          --}}
                        {{-- ============================================================ --}}

                        {{-- Supplier --}}
                        <div class="col-md-4">
                            <label class="form-label" for="supplier_id">Supplier</label>
                            <select id="supplier_id" name="supplier_id"
                                    class="form-select @error('supplier_id') is-invalid @enderror">
                                <option value="">Select supplier</option>
                                @foreach ($suppliers as $s)
                                    <option value="{{ $s->id }}"
                                        {{ (string) old('supplier_id') === (string) $s->id ? 'selected' : '' }}>
                                        {{ $s->supplier_code }} — {{ $s->supplier_name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('supplier_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>

                        {{-- Branch --}}
                        <div class="col-md-4">
                            <label class="form-label" for="branch_id">Branch</label>
                            <select id="branch_id" name="branch_id"
                                    class="form-select @error('branch_id') is-invalid @enderror">
                                <option value="">Select branch</option>
                                @foreach ($branches as $b)
                                    <option value="{{ $b->id }}"
                                        {{ (string) old('branch_id') === (string) $b->id ? 'selected' : '' }}>
                                        {{ $b->branch_code }} — {{ $b->branch_name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('branch_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>

                        {{-- Warehouse (header — default for new direct lines) --}}
                        <div class="col-md-4">
                            <label class="form-label" for="warehouse_id">
                                Default warehouse <span class="text-danger">*</span>
                            </label>
                            <select id="warehouse_id" name="warehouse_id"
                                    class="form-select @error('warehouse_id') is-invalid @enderror" required>
                                <option value="">Select warehouse</option>
                                @foreach ($warehouses as $wh)
                                    <option value="{{ $wh->id }}"
                                        {{ (string) old('warehouse_id') === (string) $wh->id ? 'selected' : '' }}>
                                        {{ $wh->warehouse_code }} — {{ $wh->warehouse_name }}
                                        @if ($wh->branch) ({{ $wh->branch->branch_name }}) @endif
                                    </option>
                                @endforeach
                            </select>
                            @error('warehouse_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="form-text small">
                                <i class="fas fa-circle-info me-1"></i>
                                Default warehouse for items. Per-line warehouse can be overridden in the items table.
                            </div>
                        </div>

                        {{-- Receive date --}}
                        <div class="col-md-4">
                            <label class="form-label" for="receive_date">
                                Receive date <span class="text-danger">*</span>
                            </label>
                            <input type="date" id="receive_date" name="receive_date"
                                   class="form-control @error('receive_date') is-invalid @enderror"
                                   required value="{{ $oldDate }}">
                            @error('receive_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        {{-- Notes --}}
                        <div class="col-12">
                            <label class="form-label" for="notes">Notes</label>
                            <textarea id="notes" name="notes" rows="2" class="form-control"
                                      placeholder="Optional — internal note, supplier challan ref, vehicle no, etc.">{{ old('notes') }}</textarea>
                            @error('notes') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>
                    @endif
                </div>
            </div>
        </section>

        {{-- Items table --}}
        <section class="purch-po-form-card purch-po-items-card">
            <div class="purch-po-form-card-head d-flex justify-content-between align-items-center">
                <span>
                    <i class="fas fa-table-list me-1"></i> Items
                    <span class="badge bg-success-subtle text-success ms-1" id="itemCount">0</span>
                </span>
                <button type="button" class="btn btn-sm btn-success d-none" id="addItemBtn">
                    <i class="fas fa-plus me-1"></i> Add item
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0" id="itemsTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width:32%;">Product</th>
                            <th class="text-end" style="width:11%;">Qty to receive</th>
                            <th class="text-end" style="width:11%;">Rate (Tk)</th>
                            <th style="width:18%;">Warehouse</th>
                            <th class="text-end" style="width:13%;">Amount (Tk)</th>
                            <th class="text-center" style="width:5%;"></th>
                        </tr>
                    </thead>
                    <tbody id="itemsBody">
                        {{-- Rows injected by JS --}}
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td colspan="4" class="text-end small text-muted">Sub-total</td>
                            <td class="text-end fw-bold" id="subTotal">0.00</td>
                            <td></td>
                        </tr>
                        <tr class="table-light">
                            <td colspan="4" class="text-end small">
                                <label class="form-label mb-0 me-2">Discount (Tk)</label>
                                <input type="number" min="0" step="0.01" name="discount_amount"
                                       id="discountAmount" class="form-control form-control-sm d-inline-block text-end"
                                       style="width:140px;" value="{{ old('discount_amount', 0) }}">
                            </td>
                            <td class="text-end text-danger" id="discountDisplay">−0.00</td>
                            <td></td>
                        </tr>
                        <tr class="table-light">
                            <td colspan="4" class="text-end small">
                                <label class="form-label mb-0 me-2">Tax (Tk)</label>
                                <input type="number" min="0" step="0.01" name="tax_amount"
                                       id="taxAmount" class="form-control form-control-sm d-inline-block text-end"
                                       style="width:140px;" value="{{ old('tax_amount', 0) }}">
                            </td>
                            <td class="text-end" id="taxDisplay">+0.00</td>
                            <td></td>
                        </tr>
                        <tr class="table-success fw-bold">
                            <td colspan="4" class="text-end">Total amount</td>
                            <td class="text-end" id="totalAmount">0.00</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="small text-muted px-3 py-2 mb-0">
                Search by product name or code · at least one line required
            </p>
            <div class="p-3">
                <div class="text-danger small d-none" id="itemsError">
                    <i class="fas fa-exclamation-circle me-1"></i> Add at least one item with a product and qty &gt; 0.
                </div>
            </div>
        </section>
        </div>{{-- /.purch-po-form-layout --}}

        {{-- ─── Footer (total + actions) ──────────────────────────── --}}
        <div class="purch-po-form-footer">
            <div class="purch-po-total-label">
                Total: <span id="totalAmountFooter">0.00</span>
            </div>
            <div class="purch-po-form-actions">
                <a href="{{ route('admin.purchase-receives.index') }}" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-success" id="submitBtn">
                    <i class="fas fa-file-pen me-1"></i> Create Draft GRN
                </button>
            </div>
        </div>
    </form>
</div>

{{-- Hidden warehouse options template (Phase 3 — kept for per-line warehouse select) --}}
<template id="warehouseOptionsTpl">
    <option value="">Select warehouse</option>
    @foreach ($warehouses as $wh)
        <option value="{{ $wh->id }}">{{ $wh->warehouse_code }} — {{ $wh->warehouse_name }}</option>
    @endforeach
</template>

@push('scripts')
<script>
window.GRN_FORM_BOOT = {
    poDetailsUrl: @json(route('admin.purchase-receives.po-details')),
    productSearchUrl: @json(route('admin.purchase-orders.search-products')),
    csrf: @json(csrf_token()),
    isAgainstPo: @json($isAgainstPo),
    poItems: {!! $poItemsJson !!},
    directReceiveOld: @json((bool) old('direct_receive')),
    poIdOld: @json(old('purchase_order_id')),
    itemsOld: @json(old('items')),
    hasItemsOld: @json(is_array(old('items'))),
};
</script>
<script>
$(function () {
    var boot = window.GRN_FORM_BOOT || {};
    var poDetailsUrl  = boot.poDetailsUrl;
    var productSearchUrl = boot.productSearchUrl;
    var $form         = $('#receiveForm');
    var $tbody        = $('#itemsBody');
    var $headerWh     = $('#warehouse_id');
    var $subTotal     = $('#subTotal');
    var $totalAmount  = $('#totalAmount');
    var $totalAmountFooter = $('#totalAmountFooter');
    var $discountIn   = $('#discountAmount');
    var $taxIn        = $('#taxAmount');
    var $discountDisp = $('#discountDisplay');
    var $taxDisp      = $('#taxDisplay');
    var $itemCount    = $('#itemCount');
    var $itemsError   = $('#itemsError');
    var $addItemBtn   = $('#addItemBtn');
    var $poSelect     = $('#po_select');
    var $poIdHidden   = $('#po_id_hidden');
    var $supplierSel  = $('#supplier_id');
    var $branchSel    = $('#branch_id');
    var $directToggle = $('#directReceiveToggle');
    var rowIndex      = 0;
    var productSearchCache = {}; // rowId -> { id, name, code }

    @if ($isAgainstPo)
        // ====== Against PO: pre-fill items from $po ======
        var poItems = {!! $poItemsJson !!};
        $addItemBtn.addClass('d-none');
        poItems.forEach(function (item) { buildRow(rowIndex++, item, true); });
        recomputeTotals();
    @else
        // ====== Direct mode default ======
        $addItemBtn.removeClass('d-none');

        // Helper: lock a select (disable + add hidden mirror so value still submits)
        function lockSelect($sel, name, val) {
            $sel.prop('disabled', true).val(val).trigger('change');
            // Remove any existing mirror, then add fresh one
            $('#mirror_' + name).remove();
            $('<input>').attr({
                type: 'hidden',
                id: 'mirror_' + name,
                name: name,
                value: val
            }).insertAfter($sel);
        }
        function unlockSelect($sel, name) {
            $sel.prop('disabled', false);
            $('#mirror_' + name).remove();
        }

        // Direct toggle handler
        $directToggle.on('change', function () {
            if ($(this).is(':checked')) {
                // Direct mode: disable PO select, clear items, enable supplier/branch
                $poSelect.prop('disabled', true).val('').trigger('change');
                $poIdHidden.val('');
                unlockSelect($supplierSel, 'supplier_id');
                unlockSelect($branchSel, 'branch_id');
                $supplierSel.val('').trigger('change');
                $branchSel.val('').trigger('change');
                $addItemBtn.removeClass('d-none');
                // Clear items
                $tbody.empty();
                buildRow(rowIndex++);
                recomputeTotals();
            } else {
                // PO mode: enable PO select
                $poSelect.prop('disabled', false).trigger('change');
            }
        });

        // If direct toggle was checked on POST error, keep it
        @if (old('direct_receive'))
            $directToggle.trigger('change');
        @endif

        // PO select change: AJAX load po-details
        $poSelect.on('change', function () {
            var poId = $(this).val();
            $poIdHidden.val(poId);
            if (!poId) {
                unlockSelect($supplierSel, 'supplier_id');
                unlockSelect($branchSel, 'branch_id');
                $supplierSel.val('').trigger('change');
                $branchSel.val('').trigger('change');
                $tbody.empty();
                buildRow(rowIndex++);
                recomputeTotals();
                return;
            }
            // Fetch PO details
            $.ajax({
                url: poDetailsUrl,
                type: 'GET',
                data: { po_id: poId },
                dataType: 'json'
            }).done(function (data) {
                // Lock supplier + branch (visible select disabled + hidden mirror input ensures value submits)
                lockSelect($supplierSel, 'supplier_id', data.po.supplier_id);
                lockSelect($branchSel,   'branch_id',   data.po.branch_id);
                if (data.po.warehouse_id) {
                    $headerWh.val(data.po.warehouse_id).trigger('change');
                }
                // Clear + rebuild items
                $tbody.empty();
                rowIndex = 0;
                data.items.forEach(function (item) {
                    buildRow(rowIndex++, {
                        purchase_order_item_id: item.purchase_order_item_id,
                        product_id: item.product_id,
                        product_code: item.product_code,
                        product_name: item.product_name,
                        remaining_qty: item.remaining_qty,
                        qty: item.remaining_qty,
                        rate: item.rate,
                        warehouse_id: data.po.warehouse_id
                    }, true);
                });
                $addItemBtn.addClass('d-none');
                recomputeTotals();
            }).fail(function (xhr) {
                var msg = xhr.responseJSON?.error || 'Failed to load PO details.';
                Swal.fire({ icon: 'error', title: 'PO load failed', text: msg });
                $poSelect.val('').trigger('change');
                $poIdHidden.val('');
            });
        });

        // If form was re-rendered with old purchase_order_id (after validation error), reload items
        @if (old('purchase_order_id'))
            $poSelect.val('{{ old("purchase_order_id") }}').trigger('change');
        @endif

        // If neither direct toggle nor PO selected: seed one empty row
        @if (!old('purchase_order_id') && !old('direct_receive') && !old('items'))
            buildRow(rowIndex++);
        @endif
    @endif

    // ====== Re-populate old items on validation error (only if no PO was loaded) ======
    @if (!$isAgainstPo && old('items') && !old('purchase_order_id'))
        var oldItems = @json(old('items'));
        $tbody.empty();
        rowIndex = 0;
        oldItems.forEach(function (item) {
            // Phase 3 — use typeahead; product name unknown after redirect,
            // so seed empty search box + hidden product_id. User re-searches.
            var $tr = buildRow(rowIndex++, {
                product_id: item.product_id,
                product_name: '',
                product_code: '',
                qty: item.qty,
                rate: item.rate,
                warehouse_id: item.warehouse_id
            });
            // After buildRow, the warehouse select needs to be set explicitly
            // (since initial.warehouse_id is honored but we want to be sure).
            if (item.warehouse_id) $tr.find('.wh-select').val(item.warehouse_id);
            recomputeRow($tr);
        });
    @endif

    // ====== Item row builder ======
    function buildRow(idx, initial, locked) {
        initial = initial || {};
        locked  = !!locked;
        var $tr = $('<tr>').attr('data-row', idx);

        // ---- Product cell (Phase 3: custom typeahead, not Select2) ----
        var $tdProduct = $('<td class="purch-po-product-cell">');
        var $prodHidden = $('<input>').attr({ type: 'hidden', name: 'items[' + idx + '][product_id]', class: 'product-id-input' });
        var $poItemHidden = $('<input>').attr({ type: 'hidden', name: 'items[' + idx + '][purchase_order_item_id]', class: 'po-item-id-input' });

        if (locked && initial.product_id) {
            // Read-only display (when receiving against PO)
            $prodHidden.val(initial.product_id);
            $poItemHidden.val(initial.purchase_order_item_id || '');
            var $disp = $('<div>').addClass('fw-semibold small')
                .text(initial.product_name || ('Product #' + initial.product_id));
            if (initial.product_code) {
                $disp.append('<div class="small text-muted">' + escapeHtml(initial.product_code) + '</div>');
            }
            if (initial.remaining_qty !== undefined) {
                $disp.append('<div class="small text-muted">Remaining: <span class="text-danger">' +
                    Number(initial.remaining_qty).toFixed(4) + '</span></div>');
            }
            $tdProduct.append($disp).append($prodHidden).append($poItemHidden);
        } else {
            // Custom text-input typeahead — reuses search-products endpoint from Phase 2.
            var $search = $('<input type="text">').attr({
                class: 'form-control form-control-sm product-search',
                placeholder: 'Search product name or code…',
                autocomplete: 'off',
                'data-row-id': idx
            });
            if (initial.product_id && initial.product_name) {
                $search.val(initial.product_name + ' (' + (initial.product_code || '') + ')');
                $prodHidden.val(initial.product_id);
            }
            var $dropdown = $('<div>').attr({
                class: 'purch-po-product-dropdown product-dropdown',
                id: 'grn-dropdown-' + idx
            });
            $tdProduct.append($search).append($dropdown).append($prodHidden).append($poItemHidden);
        }

        // ---- Qty input ----
        var $qty = $('<input>').attr({
            type: 'number',
            name: 'items[' + idx + '][qty]',
            class: 'form-control form-control-sm text-end qty-input',
            min: '0.001',
            step: '0.001',
            required: true,
            placeholder: '0.000'
        });
        if (initial.qty !== undefined) $qty.val(initial.qty);

        // ---- Rate input ----
        var $rate = $('<input>').attr({
            type: 'number',
            name: 'items[' + idx + '][rate]',
            class: 'form-control form-control-sm text-end rate-input',
            min: '0',
            step: '0.01',
            required: true,
            placeholder: '0.00'
        });
        if (initial.rate !== undefined) $rate.val(Number(initial.rate).toFixed(2));

        // ---- Warehouse select (native — no Select2) ----
        var $wh = $('<select>').attr({
            name: 'items[' + idx + '][warehouse_id]',
            class: 'form-select form-select-sm wh-select',
            required: true
        }).append($($('#warehouseOptionsTpl').html()).clone());
        if (initial.warehouse_id) $wh.val(initial.warehouse_id);

        // ---- Amount (display only) ----
        var $amt = $('<input>').attr({
            type: 'text',
            class: 'form-control form-control-sm text-end amount-input bg-light',
            readonly: true
        });
        $amt.val('0.00');

        // ---- Remove button ----
        var $rm = $('<button>').attr({
            type: 'button',
            class: 'btn btn-sm btn-outline-danger remove-row',
            title: 'Remove item'
        }).html('<i class="fas fa-trash"></i>');

        $tr.append($tdProduct)
           .append($('<td class="text-end">').append($qty))
           .append($('<td class="text-end">').append($rate))
           .append($('<td>').append($wh))
           .append($('<td class="text-end">').append($amt))
           .append($('<td class="text-center">').append($rm));

        $tbody.append($tr);

        // Wire events — product typeahead (only if dynamic, not locked)
        if (!locked) {
            bindProductSearch($tr, idx);
        }
        $qty.on('input',  function () { recomputeRow($tr); });
        $rate.on('input', function () { recomputeRow($tr); });
        $rm.on('click',   function () {
            $tr.remove();
            recomputeTotals();
        });

        // Initial compute
        recomputeRow($tr);
        return $tr;
    }

    // ====== Product typeahead (Phase 3 — custom, reuses search-products endpoint) ======
    function bindProductSearch($tr, rowId) {
        var $search = $tr.find('.product-search');
        if (!$search.length) return;
        var $dropdown = $('#grn-dropdown-' + rowId);
        var _debounce = null;

        $search.on('input', function () {
            if (_debounce) clearTimeout(_debounce);
            _debounce = setTimeout(function () { searchProduct($search, rowId); }, 250);
        });
        $search.on('focus', function () {
            if ($dropdown.children().length) $dropdown.show();
        });
    }

    function searchProduct($input, rowId) {
        var term = ($input.val() || '').trim();
        var $dropdown = $('#grn-dropdown-' + rowId);
        if (!$dropdown.length) return;
        if (term.length < 1) { $dropdown.hide().empty(); return; }

        $.ajax({
            url: productSearchUrl,
            method: 'GET',
            data: { term: term },
            dataType: 'json'
        }).done(function (rows) {
            if (!Array.isArray(rows)) rows = (rows && rows.data) ? rows.data : [];
            if (!rows.length) {
                $dropdown.html('<div class="p-2 text-muted small">No products found</div>');
                $dropdown.show();
                return;
            }
            var html = '';
            rows.forEach(function (p) {
                productSearchCache[rowId + ':' + p.id] = p;
                html += '<button type="button" class="dropdown-item"' +
                    ' data-row-id="' + rowId + '"' +
                    ' data-product-id="' + escapeHtml(p.id) + '"' +
                    ' data-product-name="' + escapeHtml(p.product_name) + '"' +
                    ' data-product-code="' + escapeHtml(p.product_code || '') + '">' +
                        '<strong>' + escapeHtml(p.product_name) + '</strong>' +
                        ' <span class="text-muted">(' + escapeHtml(p.product_code || '') + ')</span>' +
                    '</button>';
            });
            $dropdown.html(html).show();
        }).fail(function () {
            $dropdown.html('<div class="p-2 text-danger small">Search failed</div>').show();
        });
    }

    function selectProduct(rowId, productId, productName, productCode) {
        var $row = $('tr[data-row="' + rowId + '"]');
        $row.find('.product-search').val(productName + ' (' + productCode + ')');
        $row.find('.product-id-input').val(productId);
        $('#grn-dropdown-' + rowId).hide().empty();
        recomputeRow($row);
        $row.find('.qty-input').focus();
    }

    // Delegated click handler for dropdown items (bound once on document).
    $(document).off('click', '.purch-po-product-dropdown .dropdown-item')
               .on('click', '.purch-po-product-dropdown .dropdown-item', function () {
        selectProduct($(this).data('row-id'), $(this).data('product-id'),
                      $(this).data('product-name'), $(this).data('product-code'));
    });
    // Outside click → close all dropdowns.
    $(document).off('click.grnProductDropdown')
               .on('click.grnProductDropdown', function (e) {
        if (!$(e.target).closest('.product-search, .product-dropdown').length) {
            $('.product-dropdown').hide();
        }
    });

    function recomputeRow($tr) {
        var qty  = parseFloat($tr.find('.qty-input').val())  || 0;
        var rate = parseFloat($tr.find('.rate-input').val()) || 0;
        var amt  = qty * rate;
        $tr.find('.amount-input').val(amt.toFixed(2));
        recomputeTotals();
    }

    function recomputeTotals() {
        var sub = 0, rows = 0;
        $tbody.find('tr').each(function () {
            var a = parseFloat($(this).find('.amount-input').val()) || 0;
            sub += a;
            rows++;
        });
        var disc = parseFloat($discountIn.val()) || 0;
        var tax  = parseFloat($taxIn.val())      || 0;
        var tot  = sub - disc + tax;

        $subTotal.text(sub.toFixed(2));
        $discountDisp.text('−' + disc.toFixed(2));
        $taxDisp.text('+' + tax.toFixed(2));
        $totalAmount.text(tot.toFixed(2));
        if ($totalAmountFooter.length) $totalAmountFooter.text(tot.toFixed(2));
        $itemCount.text(rows);
        $itemsError.toggleClass('d-none', rows > 0);
    }

    // Recompute when discount/tax changes
    $discountIn.on('input', recomputeTotals);
    $taxIn.on('input', recomputeTotals);

    // ====== Add item button ======
    $addItemBtn.on('click', function () { buildRow(rowIndex++); });

    // ====== Submit guard ======
    $form.on('submit', function (e) {
        var rows = $tbody.find('tr').length;
        if (rows === 0) {
            e.preventDefault();
            $itemsError.removeClass('d-none');
            Swal.fire({
                icon: 'error',
                title: 'No items',
                text: 'Add at least one product line before creating the GRN.',
                confirmButtonText: 'OK'
            });
            return false;
        }
        var invalid = 0;
        $tbody.find('tr').each(function () {
            var pid = $(this).find('.product-id-input').val();
            var qty = parseFloat($(this).find('.qty-input').val());
            var wh  = $(this).find('.wh-select').val();
            if (!pid || !qty || qty <= 0 || !wh) invalid++;
        });
        if (invalid > 0) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Incomplete items',
                text: invalid + ' row(s) are missing a product, warehouse, or have qty ≤ 0. Please fix or remove them.',
                confirmButtonText: 'OK'
            });
            return false;
        }
        $('#submitBtn').prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-1"></i> Saving…');
    });

    // ====== Helpers ======
    function escapeHtml(str) {
        if (str === undefined || str === null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
});
</script>
@endpush
@endsection

<x-layouts.erp :title="$title" :tabs="[
    ['label' => 'Dashboard', 'href' => route('dashboard')],
    ['label' => 'Invoices', 'href' => route('admin.sales-invoices.index')],
    ['label' => 'Challans', 'href' => route('admin.sales-challans.index')],
    ['label' => 'UI Preview', 'href' => route('ui-preview')],
]">
@php
    // Pre-compute per-product availability helpers
    $availability = $availability ?? [];

    $availForProduct = function (int $productId) use ($availability) {
        return $availability[$productId] ?? collect();
    };

    $totalAvailForProduct = function (int $productId) use ($availability) {
        $rows = $availability[$productId] ?? collect();
        return (float) $rows->sum->qty;
    };

    $invoiceTotal = (float) ($invoice->total_amount ?? 0);

    // Pre-compute disabled state for the submit button (must NOT use @if inside
    // an <x-*> component tag — the '>' in $warehouses->isEmpty() breaks Blade's
    // ComponentTagCompiler regex, orphaning the closing tag's endif).
    $warehousesEmpty = $warehouses->isEmpty();
@endphp

<div class="space-y-6">
    {{-- Hero header (amber/orange gradient — showcase spec) --}}
    <div class="bg-gradient-to-r from-amber-500 via-amber-600 to-orange-500 rounded-xl p-6 shadow-lg">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h1 class="text-2xl font-bold text-white">গোডাউন কপি প্রস্তুতি</h1>
                <p class="text-amber-100 text-sm mt-1">{{ $title }}</p>
                <p class="text-amber-200 text-xs mt-0.5">Step 1 of 2 — Assign a source warehouse to each invoice line</p>
            </div>
            <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="inline-flex items-center gap-1.5 bg-white/20 backdrop-blur-sm hover:bg-white/30 text-white rounded-lg px-3 py-2 text-xs font-medium transition-colors">
                <x-erp.icon name="arrow-left" class="size-4" /> Back to invoice
            </a>
        </div>
        {{-- 4-step workflow indicator --}}
        <div class="mt-6">
            <x-erp.step-indicator :steps="[
                ['label' => 'Invoice', 'label_bn' => 'চালান', 'icon' => 'file-text', 'state' => 'done'],
                ['label' => 'Godown Prep', 'label_bn' => 'গোডাউন প্রস্তুতি', 'icon' => 'warehouse', 'state' => 'active'],
                ['label' => 'Challan Issue', 'label_bn' => 'চালান ইস্যু', 'icon' => 'truck', 'state' => 'pending'],
                ['label' => 'Completed', 'label_bn' => 'সম্পন্ন', 'icon' => 'check-circle', 'state' => 'pending'],
            ]" />
        </div>
    </div>

    {{-- Invoice summary card --}}
    <x-erp.left-accent-card accent="amber" icon="file-text" title="Invoice Summary" title-bn="চালান সারসংক্ষেপ">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="text-muted small">Invoice code</div>
                <div class="fw-semibold">
                    <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="text-decoration-none">
                        {{ $invoice->invoice_code }}
                    </a>
                </div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Invoice date</div>
                <div class="fw-semibold">
                    {{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y') }}
                </div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Customer</div>
                <div class="fw-semibold">
                    @if ($invoice->customer)
                        {{ $invoice->customer->customer_name }}
                        <div class="small text-muted">{{ $invoice->customer->customer_code }}</div>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Branch</div>
                <div class="fw-semibold">
                    @if ($invoice->branch)
                        {{ $invoice->branch->branch_name }}
                        <span class="small text-muted">({{ $invoice->branch->branch_code }})</span>
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Total amount</div>
                <div class="fw-semibold text-amber-700">Tk {{ number_format($invoiceTotal, 2) }}</div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Line items</div>
                <div class="fw-semibold">{{ number_format($invoice->items->count()) }}</div>
            </div>
        </div>
    </x-erp.left-accent-card>

    {{-- Info banner --}}
    @if ($warehouses->isEmpty())
        <x-erp.warning-callout title="No active warehouses" title-bn="কোনো সক্রিয় গুদাম নেই">
            <p>No active warehouses configured for this branch. Please add warehouses before assigning godown.</p>
        </x-erp.warning-callout>
    @else
        <x-erp.warning-callout title="Assign warehouses" title-bn="গুদাম নির্বাচন করুন">
            <p>Assign a warehouse for each product. Stock availability shown per warehouse for this branch. Stock does not move yet — that happens at challan issue.</p>
        </x-erp.warning-callout>
    @endif

    {{-- Godown assignment form --}}
    <form method="POST" action="{{ route('admin.sales-challans.storeGodown', $invoice) }}">
        @csrf
        <x-erp.left-accent-card accent="cyan" icon="warehouse" title="Godown Assignment" title-bn="গুদাম বরাদ্দ" body-class="!p-0">
            <x-slot:actions>
                <span class="badge bg-primary-subtle text-primary">
                    {{ $invoice->items->count() }} line(s)
                </span>
            </x-slot:actions>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="min-width:240px;">Product</th>
                                <th class="text-end">Qty needed</th>
                                <th style="min-width:260px;">Warehouse</th>
                                <th style="min-width:280px;">Available qty (per warehouse)</th>
                                <th class="text-end">Avg cost (Tk)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoice->items as $item)
                                @php
                                    $rows = $availForProduct($item->product_id);
                                    $totalAvail = $totalAvailForProduct($item->product_id);
                                    $short = $totalAvail < (float) $item->qty;
                                @endphp
                                <tr class="{{ $short ? 'table-warning' : '' }}">
                                    <td>
                                        @if ($item->product)
                                            <div class="fw-semibold">{{ $item->product->product_name }}</div>
                                            <div class="small text-muted">{{ $item->product->product_code }}</div>
                                        @else
                                            <span class="text-muted">Product #{{ $item->product_id }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end fw-semibold">{{ number_format((float) $item->qty, 2) }}</td>
                                    <td>
                                        @if ($warehouses->isEmpty())
                                            <span class="badge bg-danger-subtle text-danger">
                                                <i class="fas fa-ban me-1"></i>No warehouses
                                            </span>
                                        @else
                                            <select name="warehouse_assignments[{{ $item->id }}]"
                                                    class="form-select form-select-sm warehouse-select"
                                                    data-item-id="{{ $item->id }}"
                                                    data-product-id="{{ $item->product_id }}"
                                                    data-qty="{{ $item->qty }}"
                                                    required>
                                                <option value="">— select warehouse —</option>
                                                @foreach ($warehouses as $w)
                                                    @php
                                                        $row = $rows->firstWhere('warehouse_id', $w->id);
                                                        $wQty = $row ? (float) $row->qty : 0.0;
                                                        $wCost = $row ? (float) $row->avg_cost : 0.0;
                                                    @endphp
                                                    <option value="{{ $w->id }}"
                                                            data-qty="{{ $wQty }}"
                                                            data-avg-cost="{{ $wCost }}"
                                                            @if ($wQty < (float) $item->qty) disabled @endif>
                                                        {{ $w->warehouse_name }}
                                                        · {{ number_format($wQty, 2) }} on hand
                                                        @if ($wQty < (float) $item->qty) · insufficient @endif
                                                    </option>
                                                @endforeach
                                            </select>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($rows->isEmpty())
                                            <span class="badge bg-danger-subtle text-danger">
                                                <i class="fas fa-triangle-exclamation me-1"></i>No stock in any warehouse
                                            </span>
                                        @else
                                            <ul class="list-unstyled mb-0 small">
                                                @foreach ($rows as $row)
                                                    <li>
                                                        <span class="text-muted">{{ $row->warehouse_name }}:</span>
                                                        <span class="fw-semibold
                                                              {{ (float) $row->qty >= (float) $item->qty ? 'text-success' : 'text-danger' }}">
                                                            {{ number_format((float) $row->qty, 2) }}
                                                        </span>
                                                        <span class="text-muted">@ {{ number_format((float) $row->avg_cost, 2) }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if ($rows->isNotEmpty())
                                            <span class="avg-cost-display text-muted" id="avg-cost-{{ $item->id }}">—</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
        </x-erp.left-accent-card>

        {{-- Sticky action bar --}}
        <x-erp.sticky-action-bar>
            <x-erp.outline-button href="{{ route('admin.sales-invoices.show', $invoice) }}">Cancel / বাতিল</x-erp.outline-button>
            <x-erp.primary-button accent="amber" icon="save" type="submit" :disabled="$warehousesEmpty">
                Confirm Godown Assignment
            </x-erp.primary-button>
        </x-erp.sticky-action-bar>
    </form>
</div>

</x-layouts.erp>

@push('scripts')
<script>
$(function () {
    $('.warehouse-select').select2({ theme: 'bootstrap-5', width: '100%' });

    // On warehouse change, show that warehouse's avg_cost next to the row.
    $('.warehouse-select').on('select2:select', function (e) {
        var $sel = $(this);
        var itemId = $sel.data('item-id');
        var opt = $sel.find('option:selected');
        var avgCost = opt.data('avg-cost') || 0;
        var disp = $('#avg-cost-' + itemId);
        if (disp.length) {
            disp.removeClass('text-muted')
                .addClass('fw-semibold')
                .text(parseFloat(avgCost).toFixed(2));
        }
    });

    // Pre-fill displays if any option is already selected (e.g. on back-with-input).
    $('.warehouse-select').each(function () {
        var $sel = $(this);
        var itemId = $sel.data('item-id');
        var opt = $sel.find('option:selected');
        if (opt.val()) {
            var avgCost = opt.data('avg-cost') || 0;
            var disp = $('#avg-cost-' + itemId);
            if (disp.length) {
                disp.removeClass('text-muted')
                    .addClass('fw-semibold')
                    .text(parseFloat(avgCost).toFixed(2));
            }
        }
    });

    // Intercept submit if any row has insufficient stock selected (defensive).
    $('form').on('submit', function (e) {
        var ok = true;
        $('.warehouse-select').each(function () {
            if (!$(this).val()) ok = false;
        });
        if (!ok) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Incomplete assignment',
                text: 'Please assign a warehouse to every line item before confirming.',
                confirmButtonColor: '#d97706'
            });
        }
    });
});
</script>
@endpush

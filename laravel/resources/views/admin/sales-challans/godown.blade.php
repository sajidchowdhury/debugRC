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
    // an <x-*> component tag — raw <button> used below).
    $warehousesEmpty = $warehouses->isEmpty();
@endphp

<div class="space-y-6">
    <!-- Breadcrumb -->
    <nav aria-label="Breadcrumb" class="text-xs text-gray-500 flex items-center gap-1.5 flex-wrap">
        <a href="{{ route('dashboard') }}" class="hover:text-amber-700 transition-colors">Sales</a>
        <x-erp.icon name="chevron-right" class="size-3 text-gray-400" />
        <a href="{{ route('admin.sales-challans.index') }}" class="hover:text-amber-700 transition-colors">Challan</a>
        <x-erp.icon name="chevron-right" class="size-3 text-gray-400" />
        <span class="text-amber-800 font-medium">Godown Preparation</span>
    </nav>

    <!-- Hero header (amber/orange gradient — showcase PAGE 3) -->
    <div class="bg-gradient-to-r from-amber-500 via-amber-600 to-orange-500 rounded-xl p-6 shadow-lg">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h1 class="text-2xl font-bold text-white">গোডাউন কপি প্রস্তুতি / Godown Preparation</h1>
                <p class="text-amber-100 text-sm mt-1">{{ $title }}</p>
                <p class="text-amber-200 text-xs mt-0.5">Step 2 of 4 — Assign a source warehouse to each invoice line</p>
            </div>
            <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="inline-flex items-center gap-1.5 bg-white/20 backdrop-blur-sm hover:bg-white/30 text-white rounded-lg px-3 py-2 text-xs font-medium transition-colors">
                <i class="fas fa-arrow-left"></i> Back to invoice
            </a>
        </div>

        <!-- 4-step workflow indicator (Phase 1: uses <x-erp.journey-stepper>) -->
        <x-erp.journey-stepper :current="2" />
    </div>

    <!-- Invoice summary card (orange left accent — matches template PAGE 3) -->
    <div class="bg-white rounded-xl shadow-sm border-l-4 border-l-orange-500 p-4">
        <div class="pb-2 mb-3 border-b border-gray-100">
            <h3 class="text-lg font-medium">
                <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="hover:text-amber-700 transition-colors">
                    {{ $invoice->invoice_code }}
                </a>
            </h3>
            <p class="text-sm text-gray-500">
                {{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y') }}
                —
                @if ($invoice->customer){{ $invoice->customer->customer_name }}@else—@endif
            </p>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div>
                <span class="text-gray-500 block text-xs">Customer</span>
                <span class="font-medium">
                    @if ($invoice->customer)
                        {{ $invoice->customer->customer_name }}
                        <span class="text-xs text-gray-500">{{ $invoice->customer->customer_code }}</span>
                    @else
                        —
                    @endif
                </span>
            </div>
            <div>
                <span class="text-gray-500 block text-xs">Branch</span>
                <span class="font-medium">
                    @if ($invoice->branch)
                        {{ $invoice->branch->branch_name }}
                        <span class="text-xs text-gray-500">({{ $invoice->branch->branch_code }})</span>
                    @else
                        —
                    @endif
                </span>
            </div>
            <div>
                <span class="text-gray-500 block text-xs">Line items</span>
                <span class="font-medium">{{ number_format($invoice->items->count()) }}</span>
            </div>
            <div>
                <span class="text-gray-500 block text-xs">Total amount</span>
                <span class="font-bold text-amber-900">Tk {{ number_format($invoiceTotal, 2) }}</span>
            </div>
        </div>
    </div>

    <!-- Info / warning banner -->
    @if ($warehousesEmpty)
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 flex items-start gap-3">
            <i class="fas fa-triangle-exclamation text-red-600 mt-0.5"></i>
            <div>
                <p class="font-medium text-red-800">No active warehouses / কোনো সক্রিয় গুদাম নেই</p>
                <p class="text-sm text-red-700 mt-1">No active warehouses configured for this branch. Please add warehouses before assigning godown.</p>
            </div>
        </div>
    @else
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 flex items-start gap-3">
            <i class="fas fa-circle-info text-amber-600 mt-0.5"></i>
            <div>
                <p class="font-medium text-amber-800">Assign warehouses / গুদাম নির্বাচন করুন</p>
                <p class="text-sm text-amber-700 mt-1">Assign a warehouse for each product. Stock availability shown per warehouse for this branch. Stock does not move yet — that happens at challan issue.</p>
            </div>
        </div>
    @endif

    <!-- Godown assignment form -->
    <form method="POST" action="{{ route('admin.sales-challans.storeGodown', $invoice) }}">
        @csrf

        <!-- Warehouse assignment card (matches template PAGE 3 table) -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-amber-100 flex items-center justify-between flex-wrap gap-2">
                <div>
                    <h3 class="text-base flex items-center gap-2 font-medium">
                        <i class="fas fa-warehouse text-amber-600"></i>
                        Warehouse Assignment
                    </h3>
                    <p class="text-xs text-gray-500">Enter the warehouse name as filled by dispatcher</p>
                </div>
                <span class="bg-amber-100 border border-amber-300 text-amber-700 rounded-full px-2 py-0.5 text-xs font-medium">
                    {{ $invoice->items->count() }} line(s)
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-amber-50/50">
                            <th class="px-4 py-3 text-left font-medium">Product</th>
                            <th class="px-4 py-3 text-center font-medium">Ordered Qty</th>
                            <th class="px-4 py-3 text-left font-medium">Warehouse</th>
                            <th class="px-4 py-3 text-left font-medium">Available Stock</th>
                            <th class="px-4 py-3 text-right font-medium">Avg Cost (Tk)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoice->items as $item)
                            @php
                                $rows = $availForProduct($item->product_id);
                                $totalAvail = $totalAvailForProduct($item->product_id);
                                $short = $totalAvail < (float) $item->qty;
                            @endphp
                            <tr class="hover:bg-amber-50/30 border-b border-gray-100">
                                <td class="px-4 py-3">
                                    @if ($item->product)
                                        <div class="font-medium">{{ $item->product->product_name }}</div>
                                        <div class="text-xs text-gray-500">{{ $item->product->product_code }}</div>
                                    @else
                                        <span class="text-gray-500">Product #{{ $item->product_id }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-center font-semibold">{{ number_format((float) $item->qty, 2) }}</td>
                                <td class="px-4 py-3">
                                    @if ($warehouses->isEmpty())
                                        <span class="bg-red-100 text-red-700 border border-red-300 font-semibold text-xs rounded-full px-2 py-0.5 inline-flex items-center gap-1">
                                            <i class="fas fa-ban"></i> No warehouses
                                        </span>
                                    @else
                                        <select name="warehouse_assignments[{{ $item->id }}]"
                                                class="form-select form-select-sm warehouse-select"
                                                style="width:240px;"
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
                                <td class="px-4 py-3">
                                    @if ($rows->isEmpty())
                                        <span class="bg-red-100 text-red-700 border border-red-300 font-semibold text-xs rounded-full px-2 py-0.5 inline-flex items-center gap-1">
                                            <i class="fas fa-triangle-exclamation"></i> No stock in any warehouse
                                        </span>
                                    @else
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($rows as $row)
                                                @php
                                                    $rowQty = (float) $row->qty;
                                                    $sufficient = $rowQty >= (float) $item->qty;
                                                    $hasAny = $rowQty > 0;
                                                @endphp
                                                @if ($sufficient)
                                                    <span class="bg-green-100 text-green-700 border border-green-300 font-semibold text-xs rounded-full px-2 py-0.5 inline-flex items-center gap-1">
                                                        <i class="fas fa-check"></i>
                                                        {{ $row->warehouse_name }}: {{ number_format($rowQty, 2) }}
                                                    </span>
                                                @elseif ($hasAny)
                                                    <span class="bg-yellow-100 text-yellow-700 border border-yellow-300 font-semibold text-xs rounded-full px-2 py-0.5 inline-flex items-center gap-1">
                                                        <i class="fas fa-triangle-exclamation"></i>
                                                        {{ $row->warehouse_name }}: {{ number_format($rowQty, 2) }}
                                                    </span>
                                                @else
                                                    <span class="bg-red-100 text-red-700 border border-red-300 font-semibold text-xs rounded-full px-2 py-0.5 inline-flex items-center gap-1">
                                                        <i class="fas fa-ban"></i>
                                                        {{ $row->warehouse_name }}: 0
                                                    </span>
                                                @endif
                                                <span class="text-xs text-gray-500 self-center">
                                                    @ {{ number_format((float) $row->avg_cost, 2) }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($rows->isNotEmpty())
                                        <span class="avg-cost-display text-muted" id="avg-cost-{{ $item->id }}">—</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Sticky save bar (matches template PAGE 3) -->
        <div class="flex gap-3 sticky bottom-4 bg-white/80 backdrop-blur-sm py-4 px-4 border-t rounded-t-lg shadow-lg mt-4 items-center justify-end flex-wrap">
            <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="border border-gray-200 hover:bg-gray-50 rounded-lg px-4 py-2 text-sm">
                Cancel / বাতিল
            </a>
            <button type="submit"
                    @if ($warehousesEmpty) disabled @endif
                    class="bg-amber-500 hover:bg-amber-600 text-white gap-2 rounded-lg px-4 py-2 font-medium inline-flex items-center text-sm shadow-md disabled:bg-gray-300 disabled:cursor-not-allowed">
                <i class="fas fa-save"></i>
                Save Godown Copy
            </button>
        </div>
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

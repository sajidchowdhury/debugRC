<x-layouts.erp :title="$title" :tabs="[
    ['label' => 'Dashboard', 'href' => route('dashboard')],
    ['label' => 'Invoices', 'href' => route('admin.sales-invoices.index')],
    ['label' => 'Challans', 'href' => route('admin.sales-challans.index')],
    ['label' => 'UI Preview', 'href' => route('ui-preview')],
]">
@php
    $totalCogs = (float) ($totalCogs ?? 0);
    $invoiceTotal = (float) ($invoice->total_amount ?? 0);
    $transportCost = (float) old('transport_cost', 0);
    $grandTotal = $invoiceTotal + $transportCost;
@endphp

<div class="space-y-6">
    <!-- Hero header (amber/orange gradient — showcase PAGE 4) -->
    <div class="bg-gradient-to-r from-amber-500 via-amber-600 to-orange-500 rounded-xl p-6 shadow-lg">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h1 class="text-2xl font-bold text-white">চালানপত্র ইস্যু / Issue Challan</h1>
                <p class="text-amber-100 text-sm mt-1">{{ $title }}</p>
                <p class="text-amber-200 text-xs mt-0.5">Step 4 of 4 — Issue the delivery challan. Stock moves OUT and GL posts Dr COGS / Cr Inventory.</p>
            </div>
            <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="inline-flex items-center gap-1.5 bg-white/20 backdrop-blur-sm hover:bg-white/30 text-white rounded-lg px-3 py-2 text-xs font-medium transition-colors">
                <i class="fas fa-arrow-left"></i> Back to invoice
            </a>
        </div>

        <!-- 4-step workflow indicator (Invoice ✓, Godown Prep ✓, Challan Issue active, Completed pending) -->
        <div class="mt-6 flex items-center gap-2 flex-wrap">
            <!-- 1. Invoice (done) -->
            <div class="flex items-center gap-2">
                <div class="size-8 rounded-full bg-green-100 border-2 border-green-400 text-green-600 flex items-center justify-center">
                    <i class="fas fa-check text-xs"></i>
                </div>
                <span class="text-xs font-medium text-green-600">Invoice ✓</span>
            </div>
            <div class="w-4 h-0.5 bg-gray-300"></div>

            <!-- 2. Godown Prep (done) -->
            <div class="flex items-center gap-2">
                <div class="size-8 rounded-full bg-green-100 border-2 border-green-400 text-green-600 flex items-center justify-center">
                    <i class="fas fa-check text-xs"></i>
                </div>
                <span class="text-xs font-medium text-green-600">Godown Prep ✓</span>
            </div>
            <div class="w-4 h-0.5 bg-gray-300"></div>

            <!-- 3. Challan Issue (active) -->
            <div class="flex items-center gap-2">
                <div class="size-8 rounded-full bg-amber-500 border-2 border-amber-600 text-white flex items-center justify-center">
                    <i class="fas fa-truck text-xs"></i>
                </div>
                <span class="text-xs font-medium text-amber-700">Challan Issue</span>
            </div>
            <div class="w-4 h-0.5 bg-gray-300"></div>

            <!-- 4. Completed (pending) -->
            <div class="flex items-center gap-2">
                <div class="size-8 rounded-full bg-gray-100 border-2 border-gray-300 text-gray-400 flex items-center justify-center">
                    <i class="fas fa-check-circle text-xs"></i>
                </div>
                <span class="text-xs font-medium text-gray-400">Completed</span>
            </div>
        </div>
    </div>

    <!-- Invoice summary card (cyan left accent — matches template PAGE 4) -->
    <div class="bg-white rounded-xl shadow-sm border-l-4 border-l-cyan-500 p-4">
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
                    @if ($invoice->customer){{ $invoice->customer->customer_name }}@else—@endif
                </span>
            </div>
            <div>
                <span class="text-gray-500 block text-xs">Branch</span>
                <span class="font-medium">
                    @if ($invoice->branch){{ $invoice->branch->branch_name }}@else—@endif
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

    <!-- COGS preview card (green left accent — display only, outside form) -->
    <div class="bg-white rounded-xl shadow-sm border-l-4 border-l-green-500 overflow-hidden">
        <div class="px-4 py-3 border-b border-green-100 flex items-center justify-between flex-wrap gap-2">
            <div>
                <h3 class="text-base flex items-center gap-2 font-medium">
                    <i class="fas fa-money-bill text-green-600"></i>
                    COGS Preview — Cost of Goods Sold
                </h3>
                <p class="text-xs text-gray-500">ক্রয় মূল্য প্রাক্কলন — Will be posted as journal entry</p>
            </div>
            <span class="bg-green-100 border border-green-300 text-green-700 rounded-full px-2 py-0.5 text-xs font-medium">
                {{ $invoice->items->count() }} line(s)
            </span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-green-50/50">
                        <th class="px-4 py-3 text-left font-medium">Product</th>
                        <th class="px-4 py-3 text-left font-medium">Warehouse</th>
                        <th class="px-4 py-3 text-center font-medium">Qty</th>
                        <th class="px-4 py-3 text-right font-medium">Avg Cost (Tk)</th>
                        <th class="px-4 py-3 text-right font-medium">COGS Amount (Tk)</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($invoice->items as $item)
                        <tr class="hover:bg-green-50/30 border-b border-gray-100">
                            <td class="px-4 py-3">
                                @if ($item->product)
                                    <div class="font-medium">{{ $item->product->product_name }}</div>
                                    <div class="text-xs text-gray-500">{{ $item->product->product_code }}</div>
                                @else
                                    <span class="text-gray-500">Product #{{ $item->product_id }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if ($item->warehouse)
                                    <span class="border rounded-full px-2 py-0.5 text-xs inline-flex items-center gap-1">
                                        <i class="fas fa-warehouse text-gray-500"></i>
                                        {{ $item->warehouse->warehouse_name }}
                                    </span>
                                @else
                                    <span class="bg-red-100 text-red-700 border border-red-300 font-semibold text-xs rounded-full px-2 py-0.5 inline-flex items-center gap-1">
                                        — unassigned —
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center">{{ number_format((float) $item->qty, 2) }}</td>
                            <td class="px-4 py-3 text-right">{{ number_format((float) ($item->avg_cost ?? 0), 2) }}</td>
                            <td class="px-4 py-3 text-right font-semibold">
                                Tk {{ number_format((float) ($item->cogs_amount ?? 0), 2) }}
                            </td>
                        </tr>
                    @endforeach
                    <tr class="bg-green-50/30 font-bold">
                        <td class="px-4 py-3 text-right" colspan="4">Total COGS</td>
                        <td class="px-4 py-3 text-right text-green-700">
                            Tk {{ number_format($totalCogs, 2) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Issue challan form: transport details + sticky issue button -->
    <form method="POST" action="{{ route('admin.sales-challans.issueChallan', $invoice) }}" id="issueForm">
        @csrf

        <!-- Idempotency token (UUID v4) — mirrors the finalize / payment patterns.
             Server caches redirect target + success message keyed by this token
             for 10 minutes so a duplicate submission returns the original challan
             instead of throwing "Challan already issued for this invoice." -->
        <input type="hidden" name="idempotency_token" id="idempotencyToken"
               value="{{ old('idempotency_token', (string) \Illuminate\Support\Str::uuid()) }}">

        <!-- Transport details card (amber left accent — matches template PAGE 4) -->
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-l-amber-500 p-4">
            <div class="pb-2 mb-3 border-b border-amber-100">
                <h3 class="text-base flex items-center gap-2 font-medium">
                    <i class="fas fa-truck text-amber-600"></i>
                    Transport Details
                </h3>
                <p class="text-xs text-gray-500">পরিবহন তথ্য</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm font-medium text-gray-500 block mb-1" for="transport_name">Transport Name / পরিবহন নাম</label>
                    <input type="text" id="transport_name" name="transport_name"
                           class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-full outline-none focus:ring-2 focus:ring-amber-300"
                           maxlength="100"
                           value="{{ old('transport_name') }}" placeholder="e.g. Sundarban Paribahan">
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-500 block mb-1" for="transport_phone">Transport Phone / ফোন</label>
                    <input type="text" id="transport_phone" name="transport_phone"
                           class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-full outline-none focus:ring-2 focus:ring-amber-300"
                           maxlength="30"
                           value="{{ old('transport_phone') }}" placeholder="01XXXXXXXXX">
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-500 block mb-1" for="vehicle_number">Vehicle Number / গাড়ি নং</label>
                    <input type="text" id="vehicle_number" name="vehicle_number"
                           class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-full outline-none focus:ring-2 focus:ring-amber-300"
                           maxlength="50"
                           value="{{ old('vehicle_number') }}" placeholder="e.g. DHK-METRO-12-3456">
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-500 block mb-1" for="driver_name">Driver Name / ড্রাইভার নাম</label>
                    <input type="text" id="driver_name" name="driver_name"
                           class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-full outline-none focus:ring-2 focus:ring-amber-300"
                           maxlength="100"
                           value="{{ old('driver_name') }}" placeholder="Driver full name">
                </div>
                <div>
                    <label class="text-sm font-medium text-gray-500 block mb-1" for="transport_cost">Transport Cost (Tk) / পরিবহন খরচ</label>
                    <input type="number" step="0.01" min="0" id="transport_cost" name="transport_cost"
                           class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-full outline-none focus:ring-2 focus:ring-amber-300"
                           value="{{ old('transport_cost', '0') }}">
                </div>
                <div class="md:col-span-2">
                    <label class="text-sm font-medium text-gray-500 block mb-1" for="notes">Notes / মন্তব্য</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="500"
                              class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-full outline-none focus:ring-2 focus:ring-amber-300"
                              placeholder="Optional internal note (max 500 chars)">{{ old('notes') }}</textarea>
                </div>
            </div>

            <!-- Total preview sub-box -->
            <div class="mt-4 bg-amber-50 rounded-lg p-3 border border-amber-200">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <div>
                        <span class="text-gray-500 block text-xs">Invoice Total</span>
                        <span class="font-semibold">Tk {{ number_format($invoiceTotal, 2) }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 block text-xs">Total COGS</span>
                        <span class="font-semibold text-green-700">Tk {{ number_format($totalCogs, 2) }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 block text-xs">Transport</span>
                        <span class="font-semibold">Tk {{ number_format($transportCost, 2) }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 block text-xs">Grand Total</span>
                        <span class="font-bold text-amber-900">Tk {{ number_format($grandTotal, 2) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Warning box (matches template PAGE 4) -->
        <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 flex items-start gap-3 mt-6">
            <i class="fas fa-triangle-exclamation text-amber-600 mt-0.5"></i>
            <div>
                <p class="font-medium text-amber-800">Important: This action is irreversible / এই কাজটি ফিরিয়ে আনা যাবে না</p>
                <p class="text-sm text-amber-700 mt-1">
                    Issuing the challan will: <strong>deduct stock</strong>, <strong>post a COGS journal entry</strong> (Dr COGS / Cr Inventory), and optionally <strong>record transport details</strong>.
                    This action cannot be undone from this screen — to reverse, use the <em>Cancel Challan</em> action on the challan detail page.
                </p>
            </div>
        </div>

        <!-- Sticky issue button bar (matches template PAGE 4) -->
        <div class="flex gap-3 sticky bottom-4 bg-white/80 backdrop-blur-sm py-4 px-4 border-t rounded-t-lg shadow-lg mt-4 items-center justify-end flex-wrap">
            <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="border border-gray-200 hover:bg-gray-50 rounded-lg px-4 py-2 text-sm">
                Cancel / বাতিল
            </a>
            <button type="submit"
                    class="bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white gap-2 min-w-[200px] shadow-md rounded-lg px-4 py-2 font-medium inline-flex items-center justify-center text-sm">
                <i class="fas fa-paper-plane"></i>
                Issue Challan
            </button>
        </div>
    </form>
</div>

</x-layouts.erp>

@push('scripts')
<script>
$(function () {
    $('#issueForm').on('submit', function (e) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Issue this challan?',
            html: '<p class="mb-1">This will <strong>move stock OUT</strong> and post a <strong>GL entry (Dr COGS / Cr Inventory)</strong>.</p>' +
                  '<p class="mb-0 text-muted small">Total COGS: Tk {{ number_format($totalCogs, 2) }}</p>',
            showCancelButton: true,
            confirmButtonColor: '#d97706',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fas fa-paper-plane"></i> Yes, issue challan',
            cancelButtonText: 'Cancel'
        }).then(function (res) {
            if (res.isConfirmed) {
                e.target.submit();
            }
        });
    });
});
</script>
@endpush

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
    $itemCount = (int) $invoice->items->count();
    $invoiceDate = $invoice->invoice_date
        ? \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y')
        : '—';
    $customerName = $invoice->customer?->customer_name ?? '—';
    $customerMobile = $invoice->customer?->mobile ?? '';
    $salesmanName = $invoice->sales_person ?? ($invoice->salesman?->name ?? '');
    $branchName = $invoice->branch?->branch_name ?? 'Branch';

    // Phase 4: build a dispatch-lookup map [product_id => dispatched_ctn]
    // so the CTN input can pre-fill from the persisted dispatch row (or
    // from old('dispatched_ctn.{item_id}') on back-with-input).
    $dispatchCtnByProduct = [];
    foreach ($invoice->dispatches as $disp) {
        $dispatchCtnByProduct[(int) $disp->product_id] = (float) ($disp->dispatched_ctn ?? 0);
    }

    // Phase 4: CTN pre-fill helper — old() takes priority (back-with-input),
    // then the persisted dispatch row, then empty string.
    $ctnForItem = function ($item) use ($dispatchCtnByProduct) {
        $oldVal = old('dispatched_ctn.' . $item->id);
        if ($oldVal !== null && $oldVal !== '') {
            return $oldVal;
        }
        $persisted = $dispatchCtnByProduct[(int) $item->product_id] ?? 0;
        return $persisted > 0 ? number_format($persisted, 2, '.', '') : '';
    };

    // pcs_per_carton — Project B's products table has NO pcs_per_carton
    // column. Default to 1 so "Fill all CTN" = qty / 1 = qty (functional
    // but 1:1). When a pcs_per_carton column is added, this default can be
    // replaced with $item->product->pcs_per_carton ?? 1.
    $pcsPerCartonForItem = function ($item): float {
        return 1.0;
    };

    // Computed CTN (carton count) = qty / pcs_per_carton. Shown read-only in
    // the "CTN" column of the dispatch-items table (matches reference layout).
    $computedCtnForItem = function ($item) use ($pcsPerCartonForItem): float {
        $pcs = $pcsPerCartonForItem($item);
        return $pcs > 0 ? ((float) $item->qty / $pcs) : 0.0;
    };

    // Display status derived from the boolean workflow flags so the pill
    // reflects the actual pipeline stage regardless of the literal status
    // column (which may be 'draft' or 'confirmed' on this screen).
    $displayStatus = $invoice->is_challan_issued
        ? App\Support\StatusPalette::CHALLAN_ISSUED
        : ($invoice->is_godown_prepared
            ? App\Support\StatusPalette::GODOWN_PREPARED
            : App\Support\StatusPalette::DRAFT);

    // Phase 5: edit-godown mode — invoice is godown-prepared but not yet
    // challan-issued, so the user may re-enter this screen and change
    // warehouse assignments / dispatchers / CTN before issuing.
    $isEditGodown = (bool) $invoice->is_godown_prepared && !(bool) $invoice->is_challan_issued;

    // Pre-compute disabled state for the submit button (must NOT use @if inside
    // an <x-*> component tag — raw <button> used below).
    $warehousesEmpty = $warehouses->isEmpty();

    // Phase 6: transport-cost preview components. The live total preview
    // (#invoice-total-display) = sub_total + transport − discount. The
    // transport input defaults to the invoice's current transport_cost
    // (set at sales-invoice creation OR a prior godown save).
    $subTotal = (float) ($invoice->sub_total ?? 0);
    $discountAmount = (float) ($invoice->discount_amount ?? 0);
    $transportCostDefault = (float) ($invoice->transport_cost ?? 0);

    // Status text for the hero "Warehouse dispatch" pill + summary card.
    $pipelineLabel = $invoice->is_challan_issued
        ? 'Challan issued'
        : ($invoice->is_godown_prepared ? 'Godown prepared' : 'Pending godown');
@endphp

<div class="space-y-6 challan-scope">
    <!-- Breadcrumb -->
    <nav aria-label="Breadcrumb" class="text-xs text-gray-500 flex items-center gap-1.5 flex-wrap">
        <a href="{{ route('dashboard') }}" class="hover:text-amber-700 transition-colors">Sales</a>
        <x-erp.icon name="chevron-right" class="size-3 text-gray-400" />
        <a href="{{ route('admin.sales-challans.index') }}" class="hover:text-amber-700 transition-colors">Challan</a>
        <x-erp.icon name="chevron-right" class="size-3 text-gray-400" />
        <span class="text-amber-800 font-medium">Godown Preparation</span>
    </nav>

    {{-- Hero header — orange→purple gradient (matches reference design) --}}
    <div class="rounded-xl p-6 shadow-lg bg-gradient-to-r from-amber-600 via-orange-500 to-indigo-500">
        <div class="flex items-start justify-between flex-wrap gap-4">
            <div class="flex items-start gap-4">
                <div class="bg-white/20 backdrop-blur-sm rounded-xl size-14 flex items-center justify-center text-white shrink-0">
                    <x-erp.icon name="package" class="size-7" />
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-white">Godown &amp; Challan</h1>
                    <div class="flex items-center gap-2.5 flex-wrap mt-1.5">
                        <span class="bg-white/20 rounded-full px-3 py-1 text-sm font-mono text-white">{{ $invoice->invoice_code }}</span>
                        <span class="text-amber-50 text-sm font-medium flex items-center gap-1">
                            <x-erp.icon name="map-pin" class="size-3.5" />
                            {{ $branchName }}
                        </span>
                        <span class="inline-flex items-center gap-1 bg-amber-800/80 text-white rounded-full px-2.5 py-0.5 text-xs font-medium">
                            <x-erp.icon name="warehouse" class="size-3" />
                            {{ $pipelineLabel }}
                        </span>
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('admin.sales-challans.index') }}"
                   class="inline-flex items-center gap-1.5 bg-white text-gray-700 hover:bg-gray-100 rounded-lg px-3 py-2 text-xs font-semibold transition-colors shadow-sm">
                    <x-erp.icon name="arrow-left" class="size-3.5" /> List
                </a>
            </div>
        </div>

        {{-- 3-step workflow indicator (Invoice → Godown → Challan) --}}
        <x-erp.journey-stepper :current="2" :steps="[
            ['label' => 'Invoice', 'label_bn' => 'চালান',     'icon' => 'file-text'],
            ['label' => 'Godown',  'label_bn' => 'গোডাউন',    'icon' => 'warehouse'],
            ['label' => 'Challan', 'label_bn' => 'চালানপত্র',  'icon' => 'truck'],
        ]" />
    </div>

    {{-- 4-card summary grid --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
        <x-erp.stat-card label="CUSTOMER" label-bn="ক্রেতা"
                         :value="$customerName"
                         accent="amber" icon="users">
            @if ($customerMobile)
                <span class="font-mono">{{ $customerMobile }}</span>
            @else
                <span class="text-gray-400">— no mobile —</span>
            @endif
        </x-erp.stat-card>

        <x-erp.stat-card label="INVOICE DATE" label-bn="চালান তারিখ"
                         :value="$invoiceDate"
                         accent="amber" icon="clock">
            @if ($salesmanName)
                {{ $salesmanName }}
            @else
                <span class="text-gray-400">— no salesman —</span>
            @endif
        </x-erp.stat-card>

        <x-erp.stat-card label="ITEMS" label-bn="আইটেম"
                         :value="(string) $itemCount"
                         accent="amber" icon="box">
            line{{ $itemCount === 1 ? '' : 's' }}
        </x-erp.stat-card>

        <x-erp.stat-card label="INVOICE TOTAL" label-bn="মোট"
                         :value="'Tk ' . number_format($invoiceTotal, 2)"
                         accent="green" icon="banknote">
            <x-erp.status-pill :status="$displayStatus" />
        </x-erp.stat-card>
    </div>

    {{-- Empty state — no active warehouses --}}
    @if ($warehousesEmpty)
        <x-erp.left-accent-card accent="red" icon="warehouse" title="No active warehouses" title-bn="কোনো সক্রিয় গুদাম নেই">
            <p class="text-sm text-gray-600">No active warehouses configured for this branch. Please add warehouses before assigning godown.</p>
            <p class="text-xs text-gray-500 mt-2">Please add warehouses before assigning godown.</p>
            <div class="mt-3">
                <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-amber-700 hover:text-amber-800 transition-colors">
                    <x-erp.icon name="arrow-left" class="size-3.5" /> Back to dashboard
                </a>
            </div>
        </x-erp.left-accent-card>
    @endif

    {{-- edit-godown policy callout (cyan ties to the godown_prepared stage) --}}
    @if ($isEditGodown)
        <div class="bg-cyan-50 border border-cyan-200 rounded-lg p-4 flex items-start gap-3">
            <i class="fas fa-pen-to-square text-cyan-600 mt-0.5"></i>
            <div>
                <p class="font-medium text-cyan-800">Edit-godown mode / গোডাউন সম্পাদনা</p>
                <p class="text-sm text-cyan-700 mt-1">
                    This godown was already prepared. You may change warehouse assignments, dispatchers, or carton counts — changes re-assign the dispatch rows in place (no duplicates). Stock does <strong>not</strong> move until the challan is issued. Availability shown is pipeline-aware (physical &minus; other open invoices), excluding this invoice's own reservation.
                </p>
            </div>
        </div>
    @endif

    <!-- Godown assignment form -->
    <form method="POST" action="{{ route('admin.sales-challans.storeGodown', $invoice) }}">
        @csrf

        {{-- Empty state — no invoice items --}}
        @if ($invoice->items->isEmpty())
            <x-erp.left-accent-card accent="red" icon="inbox" title="No invoice items" title-bn="কোনো আইটেম নেই">
                <p class="text-sm text-gray-600">This invoice has no line items. Add items to the invoice first before preparing the godown copy.</p>
                <div class="mt-3">
                    <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-amber-700 hover:text-amber-800 transition-colors">
                        <x-erp.icon name="arrow-left" class="size-3.5" /> Back to invoice
                    </a>
                </div>
            </x-erp.left-accent-card>
        @else

        {{-- ===== Delivery details section ===== --}}
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 bg-amber-50 border-b border-amber-100 flex items-center gap-2">
                <x-erp.icon name="users" class="size-5 text-amber-600" />
                <h3 class="text-base font-medium text-gray-800">Delivery details</h3>
            </div>
            <div class="p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-3 text-sm">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 pb-2">
                    <span class="text-gray-500">Customer</span>
                    <span class="font-medium text-gray-800 text-right">{{ $customerName }}</span>
                </div>
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 pb-2">
                    <span class="text-gray-500">Mobile</span>
                    <span class="font-mono text-gray-800 text-right">{{ $customerMobile ?: '—' }}</span>
                </div>
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 pb-2">
                    <span class="text-gray-500">Invoice date</span>
                    <span class="font-medium text-gray-800 text-right">{{ $invoiceDate }}</span>
                </div>
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 pb-2">
                    <span class="text-gray-500">Salesman</span>
                    <span class="font-medium text-gray-800 text-right">{{ $salesmanName ?: '—' }}</span>
                </div>
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 pb-2">
                    <span class="text-gray-500">Branch</span>
                    <span class="font-medium text-gray-800 text-right">{{ $branchName }}</span>
                </div>
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 pb-2">
                    <span class="text-gray-500">Invoice total</span>
                    <span class="font-semibold text-gray-800 text-right">Tk {{ number_format($invoiceTotal, 2) }}</span>
                </div>
            </div>
        </div>

        {{-- ===== Transport cost section ===== --}}
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 bg-amber-50 border-b border-amber-100 flex items-center justify-between flex-wrap gap-2">
                <div class="flex items-center gap-2">
                    <x-erp.icon name="truck" class="size-5 text-amber-600" />
                    <h3 class="text-base font-medium text-gray-800">Transport cost</h3>
                </div>
                <span class="bg-amber-100 border border-amber-300 text-amber-700 rounded-full px-2 py-0.5 text-xs font-medium">
                    editable at godown
                </span>
            </div>
            <div class="p-4">
                <div class="flex items-end gap-3 flex-wrap">
                    <div class="flex-1 min-w-[180px] sm:max-w-xs">
                        <label class="text-sm font-medium text-gray-500 block mb-1" for="godown_transport_cost">Amount (Tk) / পরিবহন খরচ</label>
                        <input type="number" step="0.01" min="0" id="godown_transport_cost" name="transport_cost"
                               class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-full outline-none focus:ring-2 focus:ring-amber-300"
                               value="{{ old('transport_cost', number_format($transportCostDefault, 2, '.', '')) }}"
                               data-sub-total="{{ $subTotal }}"
                               data-discount="{{ $discountAmount }}"
                               inputmode="decimal">
                    </div>
                    <button type="button" id="chApplyTransport"
                            class="inline-flex items-center gap-1.5 bg-amber-400 hover:bg-amber-500 text-white rounded-lg px-4 py-2 text-sm font-medium transition-colors shadow-sm min-h-[40px]">
                        <x-erp.icon name="check" class="size-4" /> Apply
                    </button>
                </div>
                {{-- Live total preview: #invoice-total-display = sub_total + transport − discount --}}
                <div class="mt-3 bg-amber-50 rounded-lg p-3 border border-amber-200">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                        <div>
                            <span class="text-gray-500 block text-xs">Sub Total</span>
                            <span class="font-semibold">Tk {{ number_format($subTotal, 2) }}</span>
                        </div>
                        <div>
                            <span class="text-gray-500 block text-xs">Discount</span>
                            <span class="font-semibold text-red-600">− Tk {{ number_format($discountAmount, 2) }}</span>
                        </div>
                        <div>
                            <span class="text-gray-500 block text-xs">Transport</span>
                            <span class="font-semibold" id="godown_transport_display">Tk {{ number_format($transportCostDefault, 2) }}</span>
                        </div>
                        <div>
                            <span class="text-gray-500 block text-xs">Grand Total</span>
                            <span class="font-bold text-amber-900" id="invoice-total-display">Tk {{ number_format($subTotal - $discountAmount + $transportCostDefault, 2) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ===== Dispatch items section (warehouse assignment table) ===== --}}
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 bg-amber-50 border-b border-amber-100 flex items-center justify-between flex-wrap gap-2">
                <div class="flex items-center gap-2">
                    <x-erp.icon name="package" class="size-5 text-amber-600" />
                    <h3 class="text-base font-medium text-gray-800">Dispatch items</h3>
                </div>
                <span class="bg-amber-100 border border-amber-300 text-amber-700 rounded-full px-2 py-0.5 text-xs font-medium">
                    {{ $invoice->items->count() }} line(s)
                </span>
            </div>

            {{-- Bulk tools bar — Apply warehouse to all + Fill all CTN + progress bar --}}
            @if (!$warehouses->isEmpty())
            <div class="bg-amber-50/40 border-b border-amber-100 px-4 py-3 flex items-center gap-3 flex-wrap">
                <div class="flex items-center gap-2">
                    <x-erp.icon name="warehouse" class="size-4 text-amber-600" />
                    <label for="chBulkWarehouse" class="text-xs font-medium text-gray-600 whitespace-nowrap">Apply warehouse to all</label>
                </div>
                <select id="chBulkWarehouse" class="form-select form-select-sm" style="width:200px;">
                    <option value="">— Choose warehouse —</option>
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}">{{ $w->warehouse_name }}</option>
                    @endforeach
                </select>
                <button type="button" id="chApplyBulkWarehouse"
                        aria-label="Apply the selected warehouse to all invoice lines"
                        class="inline-flex items-center gap-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded-lg px-3 py-1.5 text-xs font-medium transition-colors shadow-sm min-h-[36px]">
                    <x-erp.icon name="check" class="size-3.5" /> Apply
                </button>
                <span class="text-gray-300" aria-hidden="true">|</span>
                <button type="button" id="chFillAllCtn"
                        aria-label="Fill carton count for all lines based on qty divided by pieces per carton"
                        class="inline-flex items-center gap-1.5 border border-gray-200 hover:bg-gray-50 text-gray-700 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors min-h-[36px]"
                        title="Compute CTN = qty / pcs_per_carton for every row">
                    <x-erp.icon name="package" class="size-3.5" /> Fill all CTN
                </button>

                {{-- Warehouse-assignment progress bar --}}
                <div class="flex items-center gap-2 ml-auto min-w-[180px]">
                    <div class="flex-1 bg-gray-200 rounded-full h-2 overflow-hidden" role="progressbar"
                         aria-valuenow="0" aria-valuemin="0" aria-valuemax="{{ $itemCount }}" aria-label="Warehouse assignment progress">
                        <div id="chAssignProgressBar" class="bg-gradient-to-r from-amber-500 to-green-500 h-full rounded-full transition-all duration-300" style="width: 0%;"></div>
                    </div>
                    <span id="chAssignProgressLabel" class="text-xs text-gray-500 whitespace-nowrap">0 / {{ $itemCount }}</span>
                </div>
            </div>
            @endif

            {{-- Desktop (≥ sm): editable table with BLACK header row.
                 All form fields live here; on mobile this container is
                 display:none but the inputs still submit their values. --}}
            <div class="hidden sm:block overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-black text-white">
                            <th class="px-3 py-3 text-center font-medium w-12">SL</th>
                            <th class="px-4 py-3 text-left font-medium">Product</th>
                            <th class="px-3 py-3 text-center font-medium">Ordered</th>
                            <th class="px-3 py-3 text-center font-medium">CTN</th>
                            <th class="px-4 py-3 text-left font-medium">Warehouse <span class="text-red-400">*</span></th>
                            <th class="px-3 py-3 text-center font-medium">Available</th>
                            <th class="px-3 py-3 text-center font-medium">Demand (locked)</th>
                            <th class="px-3 py-3 text-center font-medium">Disp. CTN</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoice->items as $item)
                            @php
                                $rows = $availForProduct($item->product_id);
                                $totalAvail = $totalAvailForProduct($item->product_id);
                                $short = $totalAvail < (float) $item->qty;
                                $selectedWid = old('warehouse_assignments.' . $item->id, (string) ($item->warehouse_id ?? ''));
                                $computedCtn = $computedCtnForItem($item);
                            @endphp
                            <tr class="hover:bg-amber-50/30 border-b border-gray-100">
                                {{-- SL --}}
                                <td class="px-3 py-3 text-center text-gray-500">{{ $loop->iteration }}</td>
                                {{-- Product --}}
                                <td class="px-4 py-3">
                                    @if ($item->product)
                                        <div class="font-medium text-gray-800">{{ $item->product->product_name }}</div>
                                        <div class="text-xs text-gray-500">{{ $item->product->product_code }}</div>
                                    @else
                                        <span class="text-gray-500">Product #{{ $item->product_id }}</span>
                                    @endif
                                </td>
                                {{-- Ordered --}}
                                <td class="px-3 py-3 text-center font-semibold">{{ number_format((float) $item->qty, 2) }}</td>
                                {{-- CTN (computed read-only carton count) --}}
                                <td class="px-3 py-3 text-center text-gray-600">{{ number_format($computedCtn, 2) }}</td>
                                {{-- Warehouse * (select + live stock badge) --}}
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
                                                data-pcs-per-carton="{{ $pcsPerCartonForItem($item) }}"
                                                data-is-godown-prepared="{{ $isEditGodown ? 1 : 0 }}"
                                                data-persisted-warehouse-id="{{ $item->warehouse_id ?? '' }}"
                                                required>
                                            <option value="">— select warehouse —</option>
                                            @foreach ($warehouses as $w)
                                                @php
                                                    $row = $rows->firstWhere('warehouse_id', $w->id);
                                                    $wAvail = $row ? (float) $row->qty : 0.0;
                                                    $wPhys  = $row ? (float) $row->physical_qty : 0.0;
                                                    $wPipe  = $row ? (float) $row->pipeline_qty : 0.0;
                                                    $wCost  = $row ? (float) $row->avg_cost : 0.0;
                                                    $isSelected = (string) $w->id === (string) $selectedWid;
                                                    $insufficient = !$isSelected && ($wAvail < (float) $item->qty);
                                                @endphp
                                                <option value="{{ $w->id }}"
                                                        data-qty="{{ $wAvail }}"
                                                        data-available="{{ $wAvail }}"
                                                        data-physical="{{ $wPhys }}"
                                                        data-avg-cost="{{ $wCost }}"
                                                        @if ($isSelected) selected @endif
                                                        @if ($insufficient) disabled @endif
                                                        title="available {{ number_format($wAvail, 2) }} · physical {{ number_format($wPhys, 2) }} · pipeline {{ number_format($wPipe, 2) }}">
                                                    {{ $w->warehouse_name }}
                                                    · {{ number_format($wAvail, 2) }} avail
                                                    @if ($insufficient) · insufficient @endif
                                                </option>
                                            @endforeach
                                        </select>
                                        <span id="stock-badge-{{ $item->id }}"
                                              class="stock-badge mt-1.5 inline-flex items-center gap-1 text-xs font-semibold rounded-full px-2 py-0.5 border bg-gray-50 text-gray-500 border-gray-200"
                                              role="status" aria-live="polite"
                                              data-aria-label="Stock status for this line">
                                            <i class="fas fa-circle-question"></i>
                                            <span class="stock-badge-text">Select warehouse</span>
                                        </span>
                                    @endif
                                </td>
                                {{-- Available (selected warehouse's available qty; JS-updated) --}}
                                <td class="px-3 py-3 text-center">
                                    <span id="available-{{ $item->id }}" class="available-display text-gray-500">—</span>
                                    {{-- hidden avg-cost anchor kept for JS parity (no visible column) --}}
                                    <span id="avg-cost-{{ $item->id }}" class="hidden avg-cost-display"></span>
                                </td>
                                {{-- Demand (locked) --}}
                                <td class="px-3 py-3 text-center">
                                    <span class="inline-flex items-center gap-1 text-gray-600">
                                        <i class="fas fa-lock text-gray-400 text-xs"></i>
                                        {{ number_format((float) $item->qty, 2) }}
                                    </span>
                                </td>
                                {{-- Disp. CTN (editable) --}}
                                <td class="px-3 py-3 text-center">
                                    <input type="number" step="0.01" min="0"
                                           class="form-control form-control-sm dispatched-ctn-input text-center"
                                           name="dispatched_ctn[{{ $item->id }}]"
                                           value="{{ $ctnForItem($item) }}"
                                           placeholder="CTN"
                                           style="width:80px;"
                                           title="Carton packing count for this line (invoice demand qty stays fixed)">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Mobile (< sm): read-only card list. The editable form fields
                 live in the hidden desktop table above (they still submit). --}}
            <div class="sm:hidden space-y-3 p-4" aria-label="Invoice items — mobile card view">
                @foreach ($invoice->items as $item)
                    @php
                        $rows = $availForProduct($item->product_id);
                        $totalAvail = $totalAvailForProduct($item->product_id);
                        $selectedWid = old('warehouse_assignments.' . $item->id, (string) ($item->warehouse_id ?? ''));
                        $selectedWh = $selectedWid ? $warehouses->firstWhere('id', (int) $selectedWid) : null;
                        $rowForSelected = $selectedWh ? $rows->firstWhere('warehouse_id', $selectedWh->id) : null;
                        $availForSelected = $rowForSelected ? (float) $rowForSelected->qty : 0.0;
                        $demand = (float) $item->qty;
                        $isSufficient = $availForSelected >= $demand && $availForSelected > 0;
                        $isShort = $availForSelected > 0 && $availForSelected < $demand;
                        $isOut = $selectedWh && $availForSelected <= 0;
                    @endphp
                    <x-erp.left-accent-card accent="amber" icon="package" :title="($item->product?->product_name ?: 'Product #' . $item->product_id)" :titleBn="$item->product?->product_code">
                        <div class="space-y-2.5 text-sm">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-gray-500 text-xs">SL</span>
                                <span class="font-semibold">{{ $loop->iteration }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-gray-500 text-xs">Ordered</span>
                                <span class="font-semibold">{{ number_format($demand, 2) }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-gray-500 text-xs">CTN</span>
                                <span class="text-gray-600">{{ number_format($computedCtnForItem($item), 2) }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-gray-500 text-xs">Warehouse</span>
                                @if ($selectedWh)
                                    <span class="inline-flex items-center gap-1 bg-amber-50 border border-amber-200 rounded-lg px-2 py-0.5 text-xs font-medium">
                                        <i class="fas fa-warehouse text-amber-600"></i> {{ $selectedWh->warehouse_name }}
                                    </span>
                                @else
                                    <span class="bg-red-100 text-red-700 border border-red-300 text-xs rounded-full px-2 py-0.5 inline-flex items-center gap-1">
                                        <i class="fas fa-ban"></i> unassigned
                                    </span>
                                @endif
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-gray-500 text-xs">Available</span>
                                <span class="text-gray-600">{{ $selectedWh ? number_format($availForSelected, 2) : '—' }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-gray-500 text-xs">Demand (locked)</span>
                                <span class="inline-flex items-center gap-1 text-gray-600">
                                    <i class="fas fa-lock text-gray-400 text-xs"></i> {{ number_format($demand, 2) }}
                                </span>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-gray-500 text-xs">Stock status</span>
                                @if (!$selectedWh)
                                    <span class="text-xs text-gray-500">— select warehouse —</span>
                                @elseif ($isSufficient)
                                    <span class="bg-green-100 text-green-700 border border-green-300 text-xs rounded-full px-2 py-0.5 inline-flex items-center gap-1">
                                        <i class="fas fa-check"></i> {{ number_format($availForSelected, 2) }} avail
                                    </span>
                                @elseif ($isShort)
                                    <span class="bg-yellow-100 text-yellow-700 border border-yellow-300 text-xs rounded-full px-2 py-0.5 inline-flex items-center gap-1">
                                        <i class="fas fa-triangle-exclamation"></i> short ({{ number_format($availForSelected, 2) }})
                                    </span>
                                @elseif ($isOut)
                                    <span class="bg-red-100 text-red-700 border border-red-300 text-xs rounded-full px-2 py-0.5 inline-flex items-center gap-1">
                                        <i class="fas fa-ban"></i> no stock
                                    </span>
                                @endif
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-gray-500 text-xs">Disp. CTN</span>
                                <span class="font-medium">{{ $ctnForItem($item) ?: '—' }}</span>
                            </div>
                        </div>
                    </x-erp.left-accent-card>
                @endforeach
            </div>
        </div>

        {{-- ===== Dispatcher(s) section ===== --}}
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 bg-amber-50 border-b border-amber-100 flex items-center justify-between flex-wrap gap-2">
                <div class="flex items-center gap-2">
                    <x-erp.icon name="users" class="size-5 text-amber-600" />
                    <h3 class="text-base font-medium text-gray-800">
                        Dispatcher(s)
                        <span class="text-red-500" title="Required">*</span>
                    </h3>
                </div>
                <span class="bg-amber-100 border border-amber-300 text-amber-700 rounded-full px-2 py-0.5 text-xs font-medium" id="dispatcher-count-badge">
                    {{ $invoice->dispatchers->count() }} selected
                </span>
            </div>
            <div class="p-4">
                {{-- loading skeleton shown while Select2 AJAX fetches the dispatcher list. --}}
                <div id="dispatcher-loading" class="space-y-2 mb-3" aria-hidden="true">
                    <div class="ch-skeleton h-10 w-full"></div>
                    <div class="ch-skeleton h-3 w-3/4"></div>
                </div>
                <select id="dispatcher_id" name="dispatcher_id[]" multiple
                        class="form-select"
                        data-invoice-id="{{ $invoice->id }}"
                        data-ajax-url="{{ route('admin.sales-challans.dispatchers') }}"
                        required>
                    @foreach ($invoice->dispatchers as $dispatcher)
                        <option value="{{ $dispatcher->id }}" selected>{{ $dispatcher->name }}@if($dispatcher->employee_code) ({{ $dispatcher->employee_code }})@endif</option>
                    @endforeach
                </select>
                {{-- empty state shown if the AJAX fetch returns zero dispatchers. --}}
                <div id="dispatcher-empty" class="hidden mt-3 bg-amber-50 border border-amber-200 rounded-lg p-3 flex items-start gap-2">
                    <i class="fas fa-user-slash text-amber-600 mt-0.5"></i>
                    <div>
                        <p class="font-medium text-amber-800 text-sm">No dispatchers found / কোনো ডিসপ্যাচার নেই</p>
                        <p class="text-xs text-amber-700 mt-0.5">No active dispatcher-role employees in this invoice's branch. Add employees with the dispatcher role first.</p>
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-2 flex items-start gap-1.5">
                    <i class="fas fa-circle-info text-amber-500 mt-0.5"></i>
                    <span>Dispatchers are filtered to active employees with the dispatcher role in this invoice's branch. Type to search by name, code, or phone.</span>
                </p>
            </div>
        </div>

        {{-- ===== Bottom action bar (Back / Save godown / Finalize challan) ===== --}}
        <x-erp.sticky-action-bar variant="phase8" align="between">
            <a href="{{ route('admin.sales-invoices.show', $invoice) }}"
               class="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                <x-erp.icon name="arrow-left" class="size-4" /> Back
            </a>
            <div class="flex items-center gap-3 flex-wrap">
                <span id="ctrl-s-hint" class="hidden md:inline-flex items-center gap-1 text-xs text-gray-400">
                    <kbd class="px-1.5 py-0.5 bg-gray-100 border border-gray-300 rounded text-[10px] font-mono shadow-sm">Ctrl</kbd>
                    <span>+</span>
                    <kbd class="px-1.5 py-0.5 bg-gray-100 border border-gray-300 rounded text-[10px] font-mono shadow-sm">S</kbd>
                    <span>to save</span>
                </span>
                <button type="submit" id="btn-save-godown"
                        class="inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white shadow-md transition-all bg-gradient-to-r from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 disabled:opacity-50 disabled:pointer-events-none min-w-[180px]"
                        @if ($warehousesEmpty) disabled @endif>
                    <x-erp.icon name="save" class="size-4" /> Save godown
                </button>
                <a href="{{ route('admin.sales-challans.issue', $invoice) }}"
                   class="inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white shadow-md transition-all bg-gradient-to-r from-amber-400 to-yellow-500 hover:from-amber-500 hover:to-yellow-600 min-w-[180px]">
                    <x-erp.icon name="check-circle" class="size-4" /> Finalize challan
                </a>
            </div>
        </x-erp.sticky-action-bar>
    </form>
    @endif {{-- end of @else (has items) --}}
</div>

</x-layouts.erp>

@push('scripts')
<script>
$(function () {
    $('.warehouse-select').select2({ theme: 'bootstrap-5', width: '100%' });

    // Phase 7 — Live stock badge for the SELECTED warehouse.
    // Colors: green (avail ≥ demand), amber (0 < avail < demand),
    // red (avail = 0), blue (reserved — godown-prepared + persisted wh).
    // Also drives the per-row "Available" column display (#available-{id}).
    function updateStockBadge($sel) {
        var itemId = $sel.data('item-id');
        var $badge = $('#stock-badge-' + itemId);

        var demand = parseFloat($sel.data('qty')) || 0;
        var isGodownPrepared = parseInt($sel.data('is-godown-prepared'), 10) === 1;
        var persistedWid = String($sel.data('persisted-warehouse-id') || '');
        var opt = $sel.find('option:selected');
        var wid = opt.val();
        var available = parseFloat(opt.data('available')) || 0;
        var physical = parseFloat(opt.data('physical')) || 0;

        // Update the "Available" column display (— when no warehouse chosen).
        var $avail = $('#available-' + itemId);
        if ($avail.length) {
            $avail.text(wid ? available.toFixed(2) : '—');
        }

        if (!$badge.length) return;

        var $icon = $badge.find('i');
        var $text = $badge.find('.stock-badge-text');

        // Shared shell; state classes appended per branch.
        var base = 'stock-badge mt-1.5 inline-flex items-center gap-1 text-xs font-semibold rounded-full px-2 py-0.5 border';

        if (!wid) {
            $badge.attr('class', base + ' bg-gray-50 text-gray-500 border-gray-200')
                  .attr('aria-label', 'No warehouse selected');
            $icon.attr('class', 'fas fa-circle-question');
            $text.text('Select warehouse');
            return;
        }

        // Reserved (blue) — edit-godown mode + this is the persisted
        // warehouse (stock already held for this invoice).
        if (isGodownPrepared && persistedWid === String(wid)) {
            $badge.attr('class', base + ' bg-blue-100 text-blue-700 border-blue-300')
                  .attr('aria-label', 'Reserved — stock already held for this invoice. Available ' + available.toFixed(2) + ', physical ' + physical.toFixed(2));
            $icon.attr('class', 'fas fa-lock');
            $text.text('Reserved · ' + available.toFixed(2) + ' avail');
            return;
        }

        if (available >= demand && available > 0) {
            $badge.attr('class', base + ' bg-green-100 text-green-700 border-green-300')
                  .attr('aria-label', 'In stock — available ' + available.toFixed(2) + ' meets demand ' + demand.toFixed(2));
            $icon.attr('class', 'fas fa-check');
            $text.text('In stock · ' + available.toFixed(2));
        } else if (available > 0) {
            $badge.attr('class', base + ' bg-yellow-100 text-yellow-700 border-yellow-300')
                  .attr('aria-label', 'Short — available ' + available.toFixed(2) + ' below demand ' + demand.toFixed(2));
            $icon.attr('class', 'fas fa-triangle-exclamation');
            $text.text('Short · ' + available.toFixed(2) + ' / ' + demand.toFixed(2));
        } else {
            $badge.attr('class', base + ' bg-red-100 text-red-700 border-red-300')
                  .attr('aria-label', 'No stock available in this warehouse');
            $icon.attr('class', 'fas fa-ban');
            $text.text('No stock');
        }
    }

    // On warehouse change, update the avg-cost anchor + live stock badge +
    // Available column (Phase 7).
    $('.warehouse-select').on('select2:select', function (e) {
        var $sel = $(this);
        var itemId = $sel.data('item-id');
        var opt = $sel.find('option:selected');
        var avgCost = opt.data('avg-cost') || 0;
        var disp = $('#avg-cost-' + itemId);
        if (disp.length) {
            disp.text(parseFloat(avgCost).toFixed(2));
        }
        updateStockBadge($sel);
    });

    // Phase 7 — also refresh badge on programmatic clear / unselect
    // (covers bulk-unset and any non-select path).
    $('.warehouse-select').on('select2:unselect change', function () {
        updateStockBadge($(this));
    });

    // Pre-fill displays if any option is already selected (e.g. on
    // back-with-input or edit-godown re-entry). Also renders the initial
    // badge state (Phase 7) + Available column.
    $('.warehouse-select').each(function () {
        var $sel = $(this);
        var itemId = $sel.data('item-id');
        var opt = $sel.find('option:selected');
        if (opt.val()) {
            var avgCost = opt.data('avg-cost') || 0;
            var disp = $('#avg-cost-' + itemId);
            if (disp.length) {
                disp.text(parseFloat(avgCost).toFixed(2));
            }
        }
        updateStockBadge($sel); // Phase 7: initial badge + available render
    });

    // Phase 4 — Warehouse-assignment progress bar.
    // Updates on any warehouse-select change (manual or bulk-apply).
    function updateAssignProgress() {
        var total = $('.warehouse-select').length;
        var set = 0;
        $('.warehouse-select').each(function () {
            if ($(this).val()) set++;
        });
        var pct = total > 0 ? Math.round((set / total) * 100) : 0;
        $('#chAssignProgressBar').css('width', pct + '%');
        $('#chAssignProgressLabel').text(set + ' / ' + total);
        $('#chAssignProgressBar').closest('[role="progressbar"]').attr('aria-valuenow', set);
    }
    // Wire up + initial render.
    $('.warehouse-select').on('change select2:select select2:unselect', updateAssignProgress);
    updateAssignProgress();

    // Phase 4 — Bulk: Apply warehouse to all rows.
    $('#chApplyBulkWarehouse').on('click', function () {
        var wid = $('#chBulkWarehouse').val();
        if (!wid) {
            Swal.fire({
                icon: 'info',
                title: 'Choose a warehouse',
                text: 'Please pick a warehouse from the dropdown first.',
                confirmButtonColor: '#d97706'
            });
            return;
        }
        $('.warehouse-select').each(function () {
            // Only set if the warehouse is not disabled for this row.
            var $opt = $(this).find('option[value="' + wid + '"]');
            if ($opt.length && !$opt.prop('disabled')) {
                $(this).val(wid).trigger('change.select2').trigger('select2:select');
            }
        });
        updateAssignProgress();
    });

    // Phase 4 — Bulk: Fill all CTN.
    // Computes CTN = qty / pcs_per_carton for every row. Project B has
    // no pcs_per_carton column (defaults to 1 → CTN = qty). When the
    // column is added, data-pcs-per-carton will carry the real value.
    $('#chFillAllCtn').on('click', function () {
        $('.warehouse-select').each(function () {
            var itemId = $(this).data('item-id');
            var qty = parseFloat($(this).data('qty')) || 0;
            var pcsPerCarton = parseFloat($(this).data('pcs-per-carton')) || 1;
            var ctn = pcsPerCarton > 0 ? (qty / pcsPerCarton) : 0;
            var $input = $('input[name="dispatched_ctn[' + itemId + ']"]');
            if ($input.length) {
                $input.val(ctn.toFixed(2));
            }
        });
        Swal.fire({
            icon: 'success',
            title: 'CTN filled',
            text: 'All carton counts computed from ordered qty.',
            timer: 1500,
            showConfirmButton: false,
            toast: true,
            position: 'top-end'
        });
    });

    // Phase 6 — Live total preview on transport cost input.
    // #invoice-total-display = sub_total + transport − discount.
    function formatTk(v) {
        return 'Tk ' + Number(v).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    function updateGodownTotalPreview() {
        var $inp = $('#godown_transport_cost');
        if (!$inp.length) return;
        var sub = parseFloat($inp.data('sub-total')) || 0;
        var disc = parseFloat($inp.data('discount')) || 0;
        var transport = parseFloat($inp.val()) || 0;
        var total = sub + transport - disc;
        $('#invoice-total-display').text(formatTk(total));
        $('#godown_transport_display').text(formatTk(transport));
    }
    $('#godown_transport_cost').on('input', updateGodownTotalPreview);
    updateGodownTotalPreview(); // initial render

    // Apply button — re-runs the preview + confirms with a toast.
    $('#chApplyTransport').on('click', function () {
        updateGodownTotalPreview();
        Swal.fire({
            icon: 'success',
            title: 'Transport applied',
            text: 'Grand total updated.',
            timer: 1200,
            showConfirmButton: false,
            toast: true,
            position: 'top-end'
        });
    });

    // Phase 3 — Dispatcher multi-select (Select2 AJAX).
    var $dispatcherSelect = $('#dispatcher_id');
    var dispatcherAjaxUrl = $dispatcherSelect.data('ajax-url');
    var dispatcherInvoiceId = $dispatcherSelect.data('invoice-id');

    $dispatcherSelect.select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: '— select dispatcher(s) —',
        allowClear: false,
        minimumInputLength: 0,
        ajax: {
            url: dispatcherAjaxUrl,
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return {
                    invoice_id: dispatcherInvoiceId,
                    q: params.term || ''
                };
            },
            processResults: function (data) {
                var hasResults = !!(data.results && data.results.length);
                var alreadySelected = ($dispatcherSelect.val() || []).length > 0;
                $('#dispatcher-empty').toggleClass('hidden', hasResults || alreadySelected);
                return { results: data.results || [] };
            },
            cache: true
        }
    });

    // hide the loading skeleton once Select2 has initialised.
    $('#dispatcher-loading').addClass('hidden');

    // Live-update the "N selected" badge on add/remove.
    $dispatcherSelect.on('change select2:select select2:unselect', function () {
        var count = ($(this).val() || []).length;
        $('#dispatcher-count-badge').text(count + (count === 1 ? ' selected' : ' selected'));
        $('#dispatcher-empty').toggleClass('hidden', count > 0);
    });

    // Phase 8 — Godown save: Swal2 confirmation → loading → native submit.
    $('form').on('submit', function (e) {
        e.preventDefault();

        // --- Guard 1: every line has a warehouse ---
        var warehouseOk = true;
        $('.warehouse-select').each(function () {
            if (!$(this).val()) warehouseOk = false;
        });
        if (!warehouseOk) {
            Swal.fire({
                icon: 'warning',
                title: 'Incomplete assignment',
                text: 'Please assign a warehouse to every line item before confirming.',
                confirmButtonColor: '#d97706'
            });
            return;
        }

        // --- Guard 2: at least one dispatcher ---
        var dispatcherCount = ($dispatcherSelect.val() || []).length;
        if (dispatcherCount < 1) {
            Swal.fire({
                icon: 'warning',
                title: 'Dispatcher required',
                text: 'Please select at least one dispatcher for this delivery before saving the godown copy.',
                confirmButtonColor: '#d97706'
            });
            return;
        }

        // --- Phase 8: confirmation → loading → submit ---
        var lineCount = $('.warehouse-select').length;
        var transportVal = parseFloat($('#godown_transport_cost').val()) || 0;
        Swal.fire({
            icon: 'question',
            title: 'Save godown copy?',
            html: '<p class="mb-1">You are about to save <strong>' + lineCount + '</strong> warehouse assignment(s)' +
                  (transportVal > 0 ? ' with transport <strong>Tk ' + transportVal.toFixed(2) + '</strong>' : '') +
                  '.</p>' +
                  '<p class="mb-0 text-muted small">You will proceed to the <strong>Issue Challan</strong> step next. ' +
                  'You can re-edit the godown copy later if needed.</p>',
            showCancelButton: true,
            confirmButtonColor: '#d97706',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fas fa-save"></i> Save & proceed',
            cancelButtonText: 'Cancel'
        }).then(function (res) {
            if (!res.isConfirmed) return;
            Swal.fire({
                title: 'Saving godown copy…',
                html: '<span class="text-muted small">Reserving stock assignments</span>',
                timer: 8000,
                timerProgressBar: true,
                didOpen: function () { Swal.showLoading(); },
                showConfirmButton: false
            });
            // Native submit — bypasses this jQuery handler (no re-entry).
            e.target.submit();
        });
    });

    // Phase 7 — Ctrl/Cmd+S → Save godown (desktop only).
    $(document).on('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            if (!$('#ctrl-s-hint').is(':visible')) return; // mobile no-op
            var $btn = $('#btn-save-godown');
            if ($btn.length && !$btn.prop('disabled')) {
                $btn.trigger('click');
            }
        }
    });
});
</script>
@endpush

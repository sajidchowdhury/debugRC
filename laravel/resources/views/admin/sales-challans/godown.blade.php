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
    $customerAddress = $invoice->customer?->address
        ?? ($invoice->customer?->billing_address
            ?? ($invoice->delivery_address ?? '—'));

    // build a dispatch-lookup map [product_id => dispatched_ctn] so the CTN
    // input can pre-fill from the persisted dispatch row (or from
    // old('dispatched_ctn.{item_id}') on back-with-input).
    $dispatchCtnByProduct = [];
    foreach ($invoice->dispatches as $disp) {
        $dispatchCtnByProduct[(int) $disp->product_id] = (float) ($disp->dispatched_ctn ?? 0);
    }

    // CTN pre-fill helper — old() takes priority (back-with-input),
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
    // column. Default to 1 so "Fill all CTN" = qty / 1 = qty.
    $pcsPerCartonForItem = function ($item): float {
        return 1.0;
    };

    // Computed CTN (carton count) = qty / pcs_per_carton. Shown read-only in
    // the "CTN" column of the dispatch-items table (matches legacy layout).
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

    // edit-godown mode — invoice is godown-prepared but not yet
    // challan-issued, so the user may re-enter this screen and change
    // warehouse assignments / dispatchers / CTN before issuing.
    $isEditGodown = (bool) $invoice->is_godown_prepared && !(bool) $invoice->is_challan_issued;

    // godownReady mirrors the legacy `isGodownReady` flag — controls whether
    // the "Finalize challan" button is enabled (legacy disables it until the
    // godown copy has been saved at least once).
    $godownReady = (bool) $invoice->is_godown_prepared;

    // Pre-compute disabled state for the submit button (must NOT use @if inside
    // an <x-*> component tag — raw <button> used below).
    $warehousesEmpty = $warehouses->isEmpty();

    // Transport cost — edited at godown; defaults to the invoice's current
    // transport_cost (set at sales-invoice creation OR a prior godown save).
    $subTotal = (float) ($invoice->sub_total ?? 0);
    $discountAmount = (float) ($invoice->discount_amount ?? 0);
    $transportCostDefault = (float) ($invoice->transport_cost ?? 0);

    // Status text for the hero "Warehouse dispatch" tag + summary card pill.
    $pipelineLabel = $invoice->is_challan_issued
        ? 'Challan completed'
        : ($invoice->is_godown_prepared ? 'Godown issued' : 'Pending godown');
    // Legacy-style status pill class (pending/godown/done).
    $statusPillClass = $invoice->is_challan_issued
        ? 'bg-emerald-100 text-emerald-800'
        : ($invoice->is_godown_prepared
            ? 'bg-blue-100 text-blue-800'
            : 'bg-amber-100 text-amber-800');
@endphp

{{--
  Godown & Challan page — Tailwind port of the legacy CodeIgniter
  `challan/create.php` view (Bootstrap). Visual parity with the lagachy
  "Godown Prep" screen. All Laravel form field names (warehouse_assignments[],
  dispatched_ctn[], dispatcher_id[], transport_cost) + JS hooks preserved.
--}}
<div class="challan-scope pb-24">
    {{-- Breadcrumb --}}
    <nav aria-label="Breadcrumb" class="text-xs text-gray-500 flex items-center gap-1.5 flex-wrap mb-3">
        <a href="{{ route('dashboard') }}" class="hover:text-amber-700 transition-colors">Sales</a>
        <x-erp.icon name="chevron-right" class="size-3 text-gray-400" />
        <a href="{{ route('admin.sales-challans.index') }}" class="hover:text-amber-700 transition-colors">Challan</a>
        <x-erp.icon name="chevron-right" class="size-3 text-gray-400" />
        <span class="text-amber-800 font-medium">Godown Preparation</span>
    </nav>

    {{-- ===== HERO (amber-600 → indigo-600, 135deg gradient — legacy parity) ===== --}}
    <header class="flex justify-between items-start gap-4 flex-wrap mb-4 px-5 py-4 rounded-2xl text-white shadow-[0_12px_32px_rgba(217,119,6,0.28)] bg-gradient-to-br from-amber-600 to-indigo-600">
        <div>
            <h1 class="text-xl font-bold flex items-center gap-2 m-0">
                <i class="fas fa-dolly"></i> Godown &amp; Challan
            </h1>
            <p class="mt-1 text-sm opacity-95 flex items-center gap-1.5 flex-wrap">
                <span class="inline-block px-2 py-0.5 bg-white/20 rounded font-bold">{{ $invoice->invoice_code }}</span>
                <span>·</span>
                <span>{{ $branchName }}</span>
            </p>
            <span class="inline-flex items-center gap-1 mt-1.5 px-2 py-0.5 bg-white/20 rounded text-xs font-semibold">
                <i class="fas fa-map-marker-alt"></i> Warehouse dispatch
            </span>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0 flex-wrap justify-end">
            <a href="{{ route('admin.sales-challans.index') }}"
               class="inline-flex items-center gap-1.5 bg-white text-gray-700 hover:bg-gray-100 rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors shadow-sm">
                <x-erp.icon name="arrow-left" class="size-3.5" /> List
            </a>
        </div>
    </header>

    {{-- edit-godown policy callout (blue — godown_prepared stage) --}}
    @if ($isEditGodown)
        <div class="flex items-start gap-2.5 mb-4 px-4 py-3 bg-blue-50 border border-blue-200 rounded-xl text-sm text-gray-800">
            <i class="fas fa-lock text-blue-600 mt-0.5"></i>
            <div>
                <strong>Godown saved</strong> — warehouses and dispatchers are locked.
                Adjust <strong>transport</strong> or <strong>CTN</strong> as needed, then use <strong>Save godown</strong> to update.
                After that, <strong>Finalize challan</strong> deducts stock.
                Stock shown as <em>reserved</em> for this invoice; it is not deducted until the challan is finalized.
            </div>
        </div>
    @endif

    {{-- ===== STEPS (separate white card, centered — legacy parity) ===== --}}
    <div class="flex items-center justify-center gap-0 mb-4 px-4 py-3 bg-white border border-stone-200 rounded-2xl">
        @php
            // Step state resolver (legacy is-done/is-active logic).
            $stepStates = [
                1 => 'done',                                                              // Invoice always done here
                2 => $godownReady ? 'done' : 'active',                                    // Godown
                3 => $invoice->is_challan_issued ? 'done' : ($godownReady ? 'active' : 'pending'), // Challan
            ];
            $dotClass = [
                'done'    => 'bg-emerald-600 border-emerald-600 text-white',
                'active'  => 'bg-amber-600/15 border-amber-600 text-amber-700',
                'pending' => 'bg-stone-100 border-stone-200 text-stone-500',
            ];
            $labelClass = [
                'done'    => 'text-gray-800',
                'active'  => 'text-gray-800',
                'pending' => 'text-stone-500',
            ];
            $lineDone = 'bg-emerald-600';
            $linePending = 'bg-stone-200';
        @endphp
        {{-- Step 1: Invoice (done) --}}
        <div class="flex flex-col items-center gap-1 min-w-[72px]">
            <span class="size-8 rounded-full flex items-center justify-center text-xs font-bold border-2 {{ $dotClass[$stepStates[1]] }}">
                <x-erp.icon name="check" class="size-4" />
            </span>
            <span class="text-[0.7rem] font-semibold {{ $labelClass[$stepStates[1]] }}">Invoice</span>
        </div>
        <div class="flex-1 max-w-12 h-0.5 mx-1 mb-4 rounded-full {{ $stepStates[2] === 'done' ? $lineDone : $linePending }}"></div>
        {{-- Step 2: Godown --}}
        <div class="flex flex-col items-center gap-1 min-w-[72px]">
            <span class="size-8 rounded-full flex items-center justify-center text-xs font-bold border-2 {{ $dotClass[$stepStates[2]] }}">
                @if ($stepStates[2] === 'done')<x-erp.icon name="check" class="size-4" />@else 2 @endif
            </span>
            <span class="text-[0.7rem] font-semibold {{ $labelClass[$stepStates[2]] }}">Godown</span>
        </div>
        <div class="flex-1 max-w-12 h-0.5 mx-1 mb-4 rounded-full {{ $stepStates[3] === 'done' || $stepStates[3] === 'active' ? $lineDone : $linePending }}"></div>
        {{-- Step 3: Challan --}}
        <div class="flex flex-col items-center gap-1 min-w-[72px]">
            <span class="size-8 rounded-full flex items-center justify-center text-xs font-bold border-2 {{ $dotClass[$stepStates[3]] }}">
                @if ($stepStates[3] === 'done')<x-erp.icon name="check" class="size-4" />@else 3 @endif
            </span>
            <span class="text-[0.7rem] font-semibold {{ $labelClass[$stepStates[3]] }}">Challan</span>
        </div>
    </div>

    {{-- ===== SUMMARY (4-col grid, plain white cards — legacy parity) ===== --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2.5 mb-4">
        {{-- Customer --}}
        <div class="px-3 py-2.5 bg-white border border-stone-200 rounded-xl">
            <span class="block text-[0.68rem] font-semibold uppercase tracking-wide text-stone-500">Customer</span>
            <span class="block text-sm font-bold mt-0.5 text-amber-700">{{ $customerName }}</span>
            <span class="block text-xs text-stone-500 mt-0.5">
                @if ($customerMobile) <span class="font-mono">{{ $customerMobile }}</span> @else — no mobile — @endif
            </span>
        </div>
        {{-- Invoice date --}}
        <div class="px-3 py-2.5 bg-white border border-stone-200 rounded-xl">
            <span class="block text-[0.68rem] font-semibold uppercase tracking-wide text-stone-500">Invoice date</span>
            <span class="block text-sm font-bold mt-0.5 text-amber-700">{{ $invoiceDate }}</span>
            <span class="block text-xs text-stone-500 mt-0.5">
                @if ($salesmanName) {{ $salesmanName }} @else — no salesman — @endif
            </span>
        </div>
        {{-- Items --}}
        <div class="px-3 py-2.5 bg-white border border-stone-200 rounded-xl">
            <span class="block text-[0.68rem] font-semibold uppercase tracking-wide text-stone-500">Items</span>
            <span class="block text-sm font-bold mt-0.5 text-amber-700">{{ $itemCount }}</span>
            <span class="block text-xs text-stone-500 mt-0.5">line{{ $itemCount === 1 ? '' : 's' }}</span>
        </div>
        {{-- Invoice total (highlight card — amber→indigo tint) --}}
        <div class="px-3 py-2.5 bg-gradient-to-br from-amber-600/[0.08] to-indigo-600/[0.06] border border-amber-600/30 rounded-xl">
            <span class="block text-[0.68rem] font-semibold uppercase tracking-wide text-stone-500">Invoice total</span>
            <span class="block text-sm font-bold mt-0.5" id="challan-invoice-total-display">Tk {{ number_format($invoiceTotal, 2) }}</span>
            <span class="block text-xs mt-0.5">
                <span class="inline-block px-1.5 py-0.5 rounded-full text-[0.7rem] font-bold {{ $statusPillClass }}">{{ $pipelineLabel }}</span>
            </span>
        </div>
    </div>

    {{-- Empty state — no active warehouses --}}
    @if ($warehousesEmpty)
        <x-erp.left-accent-card accent="red" icon="warehouse" title="No active warehouses" title-bn="কোনো সক্রিয় গুদাম নেই">
            <p class="text-sm text-gray-600">No active warehouses configured for this branch. Please add warehouses before assigning godown.</p>
            <div class="mt-3">
                <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-amber-700 hover:text-amber-800 transition-colors">
                    <x-erp.icon name="arrow-left" class="size-3.5" /> Back to dashboard
                </a>
            </div>
        </x-erp.left-accent-card>
    @endif

    {{-- ===== FORM ===== --}}
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

        {{-- ===== Delivery details panel ===== --}}
        <section class="mb-4 bg-white border border-stone-200 rounded-2xl overflow-hidden shadow-[0_1px_4px_rgba(28,25,23,0.05)]">
            <div class="flex items-center gap-2 px-4 py-2.5 bg-[#fffbeb] border-b border-stone-200 font-bold text-sm text-gray-800">
                <i class="fas fa-user text-amber-600"></i>
                <span>Delivery details</span>
            </div>
            <div class="p-4">
                <p class="text-sm text-stone-500 mb-0 flex items-start gap-1.5">
                    <i class="fas fa-location-dot mt-0.5 text-stone-400"></i>
                    <span>{{ $customerAddress }}</span>
                </p>
            </div>
        </section>

        {{-- ===== Transport cost panel (input + helper only — legacy parity) ===== --}}
        <section class="mb-4 bg-white border border-stone-200 rounded-2xl overflow-hidden shadow-[0_1px_4px_rgba(28,25,23,0.05)]">
            <div class="flex items-center gap-2 px-4 py-2.5 bg-[#fffbeb] border-b border-stone-200 font-bold text-sm text-gray-800">
                <i class="fas fa-truck text-amber-600"></i>
                <span>Transport cost</span>
            </div>
            <div class="p-4">
                <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-start">
                    <div class="md:col-span-4 lg:col-span-3">
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="godown_transport_cost">Amount (Tk)</label>
                        <input type="number" step="0.01" min="0" id="godown_transport_cost" name="transport_cost"
                               class="form-control w-full font-semibold min-h-[44px] border border-stone-300 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-amber-400"
                               value="{{ old('transport_cost', number_format($transportCostDefault, 2, '.', '')) }}"
                               data-sub-total="{{ $subTotal }}"
                               data-discount="{{ $discountAmount }}"
                               inputmode="decimal">
                    </div>
                    <div class="md:col-span-8">
                        <p class="text-xs text-stone-500 mt-0 mb-0 md:pt-7">
                            Saved with <strong>Save godown</strong> (updates invoice total).
                            You can change it again before finalizing the challan.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        {{-- ===== Dispatch items panel ===== --}}
        <section class="mb-4 bg-white border border-stone-200 rounded-2xl overflow-hidden shadow-[0_1px_4px_rgba(28,25,23,0.05)]">
            <div class="flex items-center gap-2 px-4 py-2.5 bg-[#fffbeb] border-b border-stone-200 font-bold text-sm text-gray-800">
                <i class="fas fa-boxes-stacked text-amber-600"></i>
                <span>Dispatch items</span>
                <span class="ml-auto inline-block px-2 py-0.5 text-xs font-semibold bg-stone-200 text-stone-700 rounded-full">{{ $itemCount }}</span>
            </div>

            {{-- Bulk tools bar (cream bg — legacy parity) --}}
            @if (!$warehouses->isEmpty())
            <div class="px-4 py-3 bg-[#fffbeb] border-b border-stone-200">
                <div class="flex flex-wrap items-center gap-2">
                    <label for="chBulkWarehouse" class="text-xs font-semibold text-gray-700 whitespace-nowrap m-0 flex items-center gap-1">
                        <i class="fas fa-layer-group text-amber-600"></i> Apply warehouse to all
                    </label>
                    <select id="chBulkWarehouse" class="form-select form-select-sm min-w-[160px] max-w-[220px]">
                        <option value="">— Choose warehouse —</option>
                        @foreach ($warehouses as $w)
                            <option value="{{ $w->id }}">{{ $w->warehouse_name }}</option>
                        @endforeach
                    </select>
                    <button type="button" id="chApplyBulkWarehouse"
                            class="inline-flex items-center gap-1.5 bg-amber-400 hover:bg-amber-500 text-white rounded-lg px-3 py-1.5 text-xs font-semibold transition-colors min-h-[36px]">
                        <x-erp.icon name="check" class="size-3.5" /> Apply
                    </button>
                    <button type="button" id="chFillAllCtn"
                            class="inline-flex items-center gap-1.5 border border-stone-300 hover:bg-stone-50 text-gray-700 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors min-h-[36px]"
                            title="Copy order CTN to dispatch CTN column">
                        <i class="fas fa-clone"></i> Fill all CTN
                    </button>
                </div>
                <div class="flex items-center gap-2.5 mt-2">
                    <div class="flex-1 h-1.5 bg-stone-200 rounded-full overflow-hidden max-w-[280px]" role="progressbar"
                         aria-valuenow="0" aria-valuemin="0" aria-valuemax="{{ $itemCount }}" aria-label="Warehouse assignment progress">
                        <div id="chAssignProgressBar" class="h-full w-0 bg-gradient-to-r from-amber-600 to-indigo-600 rounded-full transition-all duration-200"></div>
                    </div>
                    <span id="chAssignProgressLabel" class="text-xs text-stone-500 whitespace-nowrap">0 / 0 warehouses set</span>
                </div>
            </div>
            @endif

            {{-- Items table (stone-900 header — legacy parity) --}}
            <div class="overflow-x-auto max-h-[min(55vh,520px)] overflow-y-auto">
                <table class="w-full text-sm border-collapse" id="godownItemsTable">
                    <thead class="sticky top-0 z-10">
                        <tr>
                            <th class="px-3 py-2 text-left font-semibold whitespace-nowrap bg-stone-900 text-white border border-stone-800">SL</th>
                            <th class="px-3 py-2 text-left font-semibold whitespace-nowrap bg-stone-900 text-white border border-stone-800">Product</th>
                            <th class="px-3 py-2 text-right font-semibold whitespace-nowrap bg-stone-900 text-white border border-stone-800">Ordered</th>
                            <th class="px-3 py-2 text-right font-semibold whitespace-nowrap bg-stone-900 text-white border border-stone-800">CTN</th>
                            <th class="px-3 py-2 text-left font-semibold whitespace-nowrap bg-stone-900 text-white border border-stone-800">Warehouse <span class="text-red-400">*</span></th>
                            <th class="px-3 py-2 text-right font-semibold whitespace-nowrap bg-stone-900 text-white border border-stone-800">{{ $isEditGodown ? 'Reserved' : 'Available' }}</th>
                            <th class="px-3 py-2 text-right font-semibold whitespace-nowrap bg-stone-900 text-white border border-stone-800">Demand (locked)</th>
                            <th class="px-3 py-2 text-right font-semibold whitespace-nowrap bg-stone-900 text-white border border-stone-800">Disp. CTN <span class="text-stone-400 font-normal">(editable)</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoice->items as $i => $item)
                            @php
                                $rows = $availForProduct($item->product_id);
                                $totalAvail = $totalAvailForProduct($item->product_id);
                                $selectedWid = old('warehouse_assignments.' . $item->id, (string) ($item->warehouse_id ?? ''));
                                $computedCtn = $computedCtnForItem($item);
                            @endphp
                            <tr class="border-b border-stone-100 hover:bg-amber-50/30" data-item-id="{{ $item->id }}">
                                {{-- SL --}}
                                <td class="px-3 py-2.5 border border-stone-100">{{ $loop->iteration }}</td>
                                {{-- Product --}}
                                <td class="px-3 py-2.5 border border-stone-100 font-semibold text-gray-800">
                                    @if ($item->product)
                                        {{ $item->product->product_name }}
                                        <div class="text-xs text-stone-500 font-normal">{{ $item->product->product_code }}</div>
                                    @else
                                        Product #{{ $item->product_id }}
                                    @endif
                                </td>
                                {{-- Ordered --}}
                                <td class="px-3 py-2.5 text-right border border-stone-100">{{ number_format((float) $item->qty, 2) }}</td>
                                {{-- CTN (computed) --}}
                                <td class="px-3 py-2.5 text-right border border-stone-100">{{ number_format($computedCtn, 2) }}</td>
                                {{-- Warehouse * --}}
                                <td class="px-3 py-2.5 border border-stone-100">
                                    @if ($warehouses->isEmpty())
                                        <span class="bg-red-100 text-red-700 border border-red-300 font-semibold text-xs rounded px-2 py-0.5 inline-flex items-center gap-1">
                                            <i class="fas fa-ban"></i> No warehouses
                                        </span>
                                    @else
                                        <select name="warehouse_assignments[{{ $item->id }}]"
                                                class="form-select form-select-sm warehouse-select w-full min-w-[200px]"
                                                data-item-id="{{ $item->id }}"
                                                data-product-id="{{ $item->product_id }}"
                                                data-qty="{{ $item->qty }}"
                                                data-pcs-per-carton="{{ $pcsPerCartonForItem($item) }}"
                                                data-is-godown-prepared="{{ $isEditGodown ? 1 : 0 }}"
                                                data-persisted-warehouse-id="{{ $item->warehouse_id ?? '' }}"
                                                required>
                                            <option value="">Select warehouse</option>
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
                                    @endif
                                </td>
                                {{-- Available / Reserved (stock badge — legacy parity) --}}
                                <td class="px-3 py-2.5 text-right border border-stone-100">
                                    <span id="stock-badge-{{ $item->id }}"
                                          class="stock-badge inline-block px-2 py-1 rounded text-xs font-bold bg-stone-100 text-stone-500"
                                          role="status" aria-live="polite"
                                          data-aria-label="Stock status for this line">—</span>
                                    <span id="avg-cost-{{ $item->id }}" class="hidden avg-cost-display"></span>
                                </td>
                                {{-- Demand (locked) — legacy `challan-qty-locked` styling --}}
                                <td class="px-3 py-2.5 text-right border border-stone-100">
                                    <span class="inline-block px-2 py-1 font-bold bg-stone-100 rounded border border-dashed border-stone-300">{{ number_format((float) $item->qty, 2) }}</span>
                                </td>
                                {{-- Disp. CTN (editable) --}}
                                <td class="px-3 py-2.5 text-right border border-stone-100">
                                    <input type="number" step="0.01" min="0"
                                           class="form-control form-control-sm dispatched-ctn-input text-center w-20 inline-block"
                                           name="dispatched_ctn[{{ $item->id }}]"
                                           value="{{ $ctnForItem($item) }}"
                                           placeholder="CTN"
                                           title="Adjust cartons for packing; invoice demand qty stays fixed">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- ===== Dispatcher(s) panel ===== --}}
        <section class="mb-4 bg-white border border-stone-200 rounded-2xl overflow-hidden shadow-[0_1px_4px_rgba(28,25,23,0.05)]">
            <div class="flex items-center gap-2 px-4 py-2.5 bg-[#fffbeb] border-b border-stone-200 font-bold text-sm text-gray-800">
                <i class="fas fa-users text-amber-600"></i>
                <span>Dispatcher(s)</span>
                <span class="text-red-500">*</span>
                <span class="ml-auto inline-block px-2 py-0.5 text-xs font-semibold bg-amber-100 border border-amber-300 text-amber-800 rounded-full" id="dispatcher-count-badge">{{ $invoice->dispatchers->count() }} selected</span>
            </div>
            <div class="p-4">
                {{-- loading skeleton --}}
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
                <div id="dispatcher-empty" class="hidden mt-3 bg-amber-50 border border-amber-200 rounded-lg p-3 flex items-start gap-2">
                    <i class="fas fa-user-slash text-amber-600 mt-0.5"></i>
                    <div>
                        <p class="font-medium text-amber-800 text-sm">No dispatchers found</p>
                        <p class="text-xs text-amber-700 mt-0.5">No active dispatcher-role employees in this invoice's branch.</p>
                    </div>
                </div>
                <p class="text-xs text-stone-500 mt-2 mb-0">Select one or more warehouse dispatchers for this delivery.</p>
            </div>
        </section>

        {{-- ===== BOTTOM ACTION BAR (fixed, justify-end — legacy parity) ===== --}}
        <footer class="fixed bottom-0 inset-x-0 z-40 flex flex-wrap items-center justify-end gap-2 px-4 py-3 bg-white/96 backdrop-blur-sm border-t border-stone-200 shadow-[0_-8px_24px_rgba(28,25,23,0.1)] no-print">
            <a href="{{ route('admin.sales-invoices.show', $invoice) }}"
               class="inline-flex items-center justify-center gap-2 rounded-[10px] border border-stone-300 bg-white hover:bg-stone-50 text-gray-700 px-4 py-2.5 text-sm font-semibold transition-colors min-h-[44px]">
                <x-erp.icon name="arrow-left" class="size-4" /> Back
            </a>
            <button type="submit" id="btn-save-godown"
                    class="inline-flex items-center justify-center gap-2 rounded-xl px-5 py-3 text-sm font-bold text-white shadow-md transition-all bg-gradient-to-br from-indigo-600 to-indigo-500 hover:from-indigo-700 hover:to-indigo-600 disabled:opacity-50 disabled:pointer-events-none min-h-[48px]"
                    @if ($warehousesEmpty) disabled @endif>
                <i class="fas fa-save"></i> {{ $isEditGodown ? 'Update CTN' : 'Save godown' }}
            </button>
            @if ($godownReady)
                <a href="{{ route('admin.sales-challans.challan-form', $invoice) }}"
                   class="inline-flex items-center justify-center gap-2 rounded-xl px-5 py-3 text-sm font-bold text-white shadow-md transition-all bg-gradient-to-br from-amber-600 to-amber-700 hover:from-amber-700 hover:to-amber-800 min-h-[48px]"
                   title="Issue stock and complete challan">
                    <i class="fas fa-check-double"></i> Finalize challan
                </a>
            @else
                <button type="button" disabled title="Save godown copy first"
                        class="inline-flex items-center justify-center gap-2 rounded-xl px-5 py-3 text-sm font-bold text-white min-h-[48px] opacity-50 cursor-not-allowed bg-gradient-to-br from-amber-600 to-amber-700">
                    <i class="fas fa-check-double"></i> Finalize challan
                </button>
            @endif
            <span id="ctrl-s-hint" class="hidden md:inline-flex items-center gap-1 text-xs text-stone-500 ml-1">
                <kbd class="px-1 py-0.5 bg-stone-100 border border-stone-300 rounded text-[10px] font-mono">Ctrl</kbd>+<kbd class="px-1 py-0.5 bg-stone-100 border border-stone-300 rounded text-[10px] font-mono">S</kbd> save godown
            </span>
        </footer>
    </form>
    @endif {{-- end of @else (has items) --}}
</div>

</x-layouts.erp>

@push('scripts')
<script>
$(function () {
    $('.warehouse-select').select2({ theme: 'bootstrap-5', width: '100%' });

    // Live stock badge for the SELECTED warehouse (legacy is-ok/is-low/is-none/is-reserved).
    // Colors: green (avail ≥ demand), amber (0 < avail < demand),
    // red (avail = 0), blue (reserved — godown-prepared + persisted wh).
    function updateStockBadge($sel) {
        var itemId = $sel.data('item-id');
        var $badge = $('#stock-badge-' + itemId);
        if (!$badge.length) return;

        var demand = parseFloat($sel.data('qty')) || 0;
        var isGodownPrepared = parseInt($sel.data('is-godown-prepared'), 10) === 1;
        var persistedWid = String($sel.data('persisted-warehouse-id') || '');
        var opt = $sel.find('option:selected');
        var wid = opt.val();
        var available = parseFloat(opt.data('available')) || 0;
        var physical = parseFloat(opt.data('physical')) || 0;

        var base = 'stock-badge inline-block px-2 py-1 rounded text-xs font-bold';

        if (!wid) {
            $badge.attr('class', base + ' bg-stone-100 text-stone-500')
                  .attr('aria-label', 'No warehouse selected').text('—');
            return;
        }

        // Reserved (blue) — edit-godown mode + persisted warehouse.
        if (isGodownPrepared && persistedWid === String(wid)) {
            $badge.attr('class', base + ' bg-blue-100 text-blue-800')
                  .attr('aria-label', 'Reserved — available ' + available.toFixed(2) + ', physical ' + physical.toFixed(2))
                  .text(numberFmt(available) + ' reserved');
            return;
        }

        if (available >= demand && available > 0) {
            $badge.attr('class', base + ' bg-emerald-100 text-emerald-800')
                  .attr('aria-label', 'In stock — available ' + available.toFixed(2) + ' meets demand ' + demand.toFixed(2))
                  .text(numberFmt(available) + ' avail');
        } else if (available > 0) {
            $badge.attr('class', base + ' bg-amber-100 text-amber-800')
                  .attr('aria-label', 'Short — available ' + available.toFixed(2) + ' below demand ' + demand.toFixed(2))
                  .text(numberFmt(available) + ' short');
        } else {
            $badge.attr('class', base + ' bg-red-100 text-red-800')
                  .attr('aria-label', 'No stock available in this warehouse')
                  .text('No stock');
        }
    }

    function numberFmt(v) {
        return Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // On warehouse change, update avg-cost anchor + live stock badge.
    $('.warehouse-select').on('select2:select', function () {
        var $sel = $(this);
        var opt = $sel.find('option:selected');
        var avgCost = opt.data('avg-cost') || 0;
        $('#avg-cost-' + $sel.data('item-id')).text(parseFloat(avgCost).toFixed(2));
        updateStockBadge($sel);
    });
    $('.warehouse-select').on('select2:unselect change', function () {
        updateStockBadge($(this));
    });

    // Initial badge render (back-with-input / edit-godown re-entry).
    $('.warehouse-select').each(function () {
        var opt = $(this).find('option:selected');
        if (opt.val()) {
            $('#avg-cost-' + $(this).data('item-id')).text(parseFloat(opt.data('avg-cost') || 0).toFixed(2));
        }
        updateStockBadge($(this));
    });

    // Warehouse-assignment progress bar.
    function updateAssignProgress() {
        var total = $('.warehouse-select').length;
        var set = 0;
        $('.warehouse-select').each(function () { if ($(this).val()) set++; });
        var pct = total > 0 ? Math.round((set / total) * 100) : 0;
        $('#chAssignProgressBar').css('width', pct + '%');
        $('#chAssignProgressLabel').text(set + ' / ' + total + ' warehouses set');
        $('#chAssignProgressBar').closest('[role="progressbar"]').attr('aria-valuenow', set);
    }
    $('.warehouse-select').on('change select2:select select2:unselect', updateAssignProgress);
    updateAssignProgress();

    // Bulk: Apply warehouse to all rows.
    $('#chApplyBulkWarehouse').on('click', function () {
        var wid = $('#chBulkWarehouse').val();
        if (!wid) {
            Swal.fire({ icon: 'info', title: 'Choose a warehouse', text: 'Please pick a warehouse from the dropdown first.', confirmButtonColor: '#d97706' });
            return;
        }
        $('.warehouse-select').each(function () {
            var $opt = $(this).find('option[value="' + wid + '"]');
            if ($opt.length && !$opt.prop('disabled')) {
                $(this).val(wid).trigger('change.select2').trigger('select2:select');
            }
        });
        updateAssignProgress();
    });

    // Bulk: Fill all CTN = qty / pcs_per_carton.
    $('#chFillAllCtn').on('click', function () {
        $('.warehouse-select').each(function () {
            var itemId = $(this).data('item-id');
            var qty = parseFloat($(this).data('qty')) || 0;
            var pcsPerCarton = parseFloat($(this).data('pcs-per-carton')) || 1;
            var ctn = pcsPerCarton > 0 ? (qty / pcsPerCarton) : 0;
            var $input = $('input[name="dispatched_ctn[' + itemId + ']"]');
            if ($input.length) $input.val(ctn.toFixed(2));
        });
        Swal.fire({ icon: 'success', title: 'CTN filled', text: 'All carton counts computed from ordered qty.', timer: 1500, showConfirmButton: false, toast: true, position: 'top-end' });
    });

    // Transport cost — live invoice-total preview (updates #challan-invoice-total-display).
    function formatTk(v) {
        return 'Tk ' + Number(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function updateGodownTotalPreview() {
        var $inp = $('#godown_transport_cost');
        if (!$inp.length) return;
        var sub = parseFloat($inp.data('sub-total')) || 0;
        var disc = parseFloat($inp.data('discount')) || 0;
        var transport = parseFloat($inp.val()) || 0;
        $('#challan-invoice-total-display').text(formatTk(sub + transport - disc));
    }
    $('#godown_transport_cost').on('input', updateGodownTotalPreview);
    updateGodownTotalPreview();

    // Dispatcher multi-select (Select2 AJAX).
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
            data: function (params) { return { invoice_id: dispatcherInvoiceId, q: params.term || '' }; },
            processResults: function (data) {
                var hasResults = !!(data.results && data.results.length);
                var alreadySelected = ($dispatcherSelect.val() || []).length > 0;
                $('#dispatcher-empty').toggleClass('hidden', hasResults || alreadySelected);
                return { results: data.results || [] };
            },
            cache: true
        }
    });
    $('#dispatcher-loading').addClass('hidden');

    $dispatcherSelect.on('change select2:select select2:unselect', function () {
        var count = ($(this).val() || []).length;
        $('#dispatcher-count-badge').text(count + ' selected');
        $('#dispatcher-empty').toggleClass('hidden', count > 0);
    });

    // Godown save: Swal2 confirmation → loading → native submit.
    $('form').on('submit', function (e) {
        e.preventDefault();

        // Guard 1: every line has a warehouse.
        var warehouseOk = true;
        $('.warehouse-select').each(function () { if (!$(this).val()) warehouseOk = false; });
        if (!warehouseOk) {
            Swal.fire({ icon: 'warning', title: 'Incomplete assignment', text: 'Please assign a warehouse to every line item before confirming.', confirmButtonColor: '#d97706' });
            return;
        }

        // Guard 2: at least one dispatcher.
        if (($dispatcherSelect.val() || []).length < 1) {
            Swal.fire({ icon: 'warning', title: 'Dispatcher required', text: 'Please select at least one dispatcher for this delivery before saving the godown copy.', confirmButtonColor: '#d97706' });
            return;
        }

        var lineCount = $('.warehouse-select').length;
        var transportVal = parseFloat($('#godown_transport_cost').val()) || 0;
        Swal.fire({
            icon: 'question',
            title: 'Save godown copy?',
            html: '<p class="mb-1">You are about to save <strong>' + lineCount + '</strong> warehouse assignment(s)' +
                  (transportVal > 0 ? ' with transport <strong>Tk ' + transportVal.toFixed(2) + '</strong>' : '') +
                  '.</p><p class="mb-0 text-muted small">You will proceed to the <strong>Issue Challan</strong> step next.</p>',
            showCancelButton: true,
            confirmButtonColor: '#d97706',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fas fa-save"></i> Save & proceed',
            cancelButtonText: 'Cancel'
        }).then(function (res) {
            if (!res.isConfirmed) return;
            Swal.fire({ title: 'Saving godown copy…', html: '<span class="text-muted small">Reserving stock assignments</span>', timer: 8000, timerProgressBar: true, didOpen: function () { Swal.showLoading(); }, showConfirmButton: false });
            e.target.submit();
        });
    });

    // Ctrl/Cmd+S → Save godown (desktop only).
    $(document).on('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
            e.preventDefault();
            if (!$('#ctrl-s-hint').is(':visible')) return;
            var $btn = $('#btn-save-godown');
            if ($btn.length && !$btn.prop('disabled')) $btn.trigger('click');
        }
    });
});
</script>
@endpush

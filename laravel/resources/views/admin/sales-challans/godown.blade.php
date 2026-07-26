<x-layouts.erp :title="$title" :tabs="[]">
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

  Top-bar (hero) intentionally mirrors admin/sales-challans/issue.blade.php
  (pure orange gradient + icon box + bilingual title + journey-stepper inside)
  so every page in the Challan module shares the identical header treatment.
  Body sections use the polished SectionCard pattern (amber-50 header strip
  with icon chip) consistent with the rest of the RC ERP design system.
--}}
<div class="space-y-5 challan-scope pb-24">
    {{-- ===== HERO (pure orange gradient — parity with issue.blade.php) ===== --}}
    <div class="bg-gradient-to-r from-orange-400 to-orange-500 rounded-xl p-4 md:p-6 shadow-lg">
        <div class="flex items-start justify-between flex-wrap gap-4">
            <div class="flex items-start gap-4">
                <div class="bg-white/20 backdrop-blur-sm rounded-xl size-14 flex items-center justify-center text-white shrink-0">
                    <x-erp.icon name="warehouse" class="size-7" />
                </div>
                <div>
                    <div class="flex items-center gap-3 flex-wrap mt-1">
                        <h3 class="text-2xl font-bold text-white m-0">Godown &amp; Challan</h3>
                        <span class="bg-white/20 rounded-full px-3 py-1 text-sm font-mono text-white">{{ $invoice->invoice_code }}</span>
                    </div>
                    <p class="text-amber-100 text-sm mt-1.5 flex items-center gap-2 flex-wrap">
                        <span class="inline-flex items-center gap-1">
                            <x-erp.icon name="map-pin" class="size-3.5" />
                            {{ $branchName }}
                        </span>
                        <span class="text-amber-200">·</span>
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('admin.sales-challans.index') }}"
                   class="inline-flex items-center gap-1.5 bg-white/20 backdrop-blur-sm hover:bg-white/30 text-white rounded-lg px-3 py-2 text-xs font-medium transition-colors">
                    <x-erp.icon name="arrow-left" class="size-3.5" /> Back to list
                </a>
                <a href="{{ route('admin.sales-invoices.show', $invoice) }}"
                   class="inline-flex items-center gap-1.5 bg-white text-amber-700 hover:bg-amber-50 rounded-lg px-3 py-2 text-xs font-semibold transition-colors shadow-sm">
                    <x-erp.icon name="file-text" class="size-3.5" /> View invoice
                </a>
            </div>
        </div>

        {{-- 4-step workflow indicator (inside hero — parity with issue page) --}}
        <x-erp.journey-stepper :current="2" />
    </div>

    {{-- ===== SUMMARY (4-col grid, icon-chip cards — Next.js parity) ===== --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {{-- Customer --}}
        <div class="group rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md">
            <div class="flex items-start justify-between gap-2">
                <div class="flex flex-col gap-1 min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 m-0">Customer</p>
                    <p class="text-base font-bold leading-snug break-words text-slate-800 m-0">{{ $customerName }}</p>
                </div>
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
                    <x-erp.icon name="users" class="size-[18px]" />
                </span>
            </div>
            <div class="mt-2.5">
                @if ($customerMobile)
                    <p class="font-mono text-xs text-slate-500 m-0">{{ $customerMobile }}</p>
                @else
                    <p class="text-xs text-slate-400 m-0">— no mobile —</p>
                @endif
            </div>
        </div>
        {{-- Invoice date --}}
        <div class="group rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md">
            <div class="flex items-start justify-between gap-2">
                <div class="flex flex-col gap-1 min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 m-0">Invoice date</p>
                    <p class="text-base font-bold leading-snug break-words text-slate-800 m-0">{{ $invoiceDate }}</p>
                </div>
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
                    <x-erp.icon name="clock" class="size-[18px]" />
                </span>
            </div>
            <div class="mt-2.5">
                @if ($salesmanName)
                    <p class="text-xs text-slate-500 m-0"><span class="text-slate-400">Salesman:</span> {{ $salesmanName }}</p>
                @else
                    <p class="text-xs text-slate-400 m-0">— no salesman —</p>
                @endif
            </div>
        </div>
        {{-- Items --}}
        <div class="group rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md">
            <div class="flex items-start justify-between gap-2">
                <div class="flex flex-col gap-1 min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 m-0">Items</p>
                    <p class="text-base font-bold leading-snug break-words text-slate-800 m-0">{{ $itemCount }}</p>
                </div>
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                    <x-erp.icon name="box" class="size-[18px]" />
                </span>
            </div>
            <div class="mt-2.5">
                <p class="text-xs text-slate-500 m-0">line{{ $itemCount === 1 ? '' : 's' }} on this invoice</p>
            </div>
        </div>
        {{-- Invoice total (highlight card with status pill) --}}
        <div class="group rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition-shadow hover:shadow-md">
            <div class="flex items-start justify-between gap-2">
                <div class="flex flex-col gap-1 min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 m-0">Invoice total</p>
                    <p class="text-base font-bold leading-snug break-words text-slate-800 m-0" id="challan-invoice-total-display">Tk {{ number_format($invoiceTotal, 2) }}</p>
                </div>
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-rose-50 text-rose-600">
                    <x-erp.icon name="banknote" class="size-[18px]" />
                </span>
            </div>
            <div class="mt-2.5">
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $statusPillClass }}">{{ $pipelineLabel }}</span>
            </div>
        </div>
    </div>

    {{-- edit-godown policy callout (blue — godown_prepared stage) --}}
    @if ($isEditGodown)
        <div class="flex items-start gap-2.5 px-4 py-3 bg-blue-50 border border-blue-200 rounded-xl text-sm text-gray-800">
            <i class="fas fa-lock text-blue-600 mt-0.5"></i>
            <div>
                <strong>Godown saved</strong> — warehouses and dispatchers are locked.
                Adjust <strong>transport</strong> or <strong>CTN</strong> as needed, then use <strong>Save godown</strong> to update.
                After that, <strong>Finalize challan</strong> deducts stock.
                Stock shown as <em>reserved</em> for this invoice; it is not deducted until the challan is finalized.
            </div>
        </div>
    @endif

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
        <section class="mb-5 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center gap-2.5 border-b border-amber-200 bg-amber-50 px-4 py-3 md:px-5">
                <span class="flex size-8 items-center justify-center rounded-md bg-amber-100 text-amber-700">
                    <x-erp.icon name="users" class="size-[18px]" />
                </span>
                <h5 class="text-sm font-semibold text-amber-900 m-0">Delivery details</h5>
            </div>
            <div class="p-4 md:p-5">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 m-0">
                    <div class="flex flex-col gap-0.5">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 m-0">Customer</dt>
                        <dd class="text-sm font-medium text-slate-800 m-0">{{ $customerName }}</dd>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 m-0">Mobile</dt>
                        <dd class="text-sm font-medium text-slate-800 font-mono m-0">@if ($customerMobile) {{ $customerMobile }} @else — @endif</dd>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 m-0">Salesman</dt>
                        <dd class="text-sm font-medium text-slate-800 m-0">@if ($salesmanName) {{ $salesmanName }} @else — @endif</dd>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-400 m-0">Address</dt>
                        <dd class="text-sm font-medium text-slate-800 m-0">{{ $customerAddress }}</dd>
                    </div>
                </dl>
            </div>
        </section>

        {{-- ===== Transport cost panel ===== --}}
        <section class="mb-5 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center gap-2.5 border-b border-amber-200 bg-amber-50 px-4 py-3 md:px-5">
                <span class="flex size-8 items-center justify-center rounded-md bg-amber-100 text-amber-700">
                    <x-erp.icon name="truck" class="size-[18px]" />
                </span>
                <h5 class="text-sm font-semibold text-amber-900 m-0">Transport cost</h5>
            </div>
            <div class="p-4 md:p-5">
                <div class="flex flex-col gap-4 md:flex-row md:items-end md:gap-6">
                    <div class="flex flex-col gap-1.5 md:w-56">
                        <label class="text-xs font-medium text-slate-500" for="godown_transport_cost">Amount (Tk)</label>
                        <input type="number" step="0.01" min="0" id="godown_transport_cost" name="transport_cost"
                               class="w-full h-10 font-mono border border-slate-300 rounded-md px-3 text-sm outline-none focus:ring-2 focus:ring-amber-400 focus:border-amber-400"
                               value="{{ old('transport_cost', number_format($transportCostDefault, 2, '.', '')) }}"
                               data-sub-total="{{ $subTotal }}"
                               data-discount="{{ $discountAmount }}"
                               inputmode="decimal">
                    </div>
                    <button type="button" id="chApplyTransport"
                            class="inline-flex h-10 items-center gap-2 rounded-md bg-yellow-400 text-slate-900 shadow-sm hover:bg-yellow-500 hover:text-slate-900 px-4 text-sm font-medium transition-colors">
                        <x-erp.icon name="check" class="size-4" /> Apply
                    </button>
                    <p class="text-xs text-slate-500 md:flex-1 m-0">
                        <span class="text-slate-400">Saved with</span>
                        <span class="font-medium text-slate-700">Save godown</span>
                        <span class="text-slate-400">or</span>
                        <span class="font-medium text-slate-700">Update CTN</span>
                        <span class="text-slate-400">(updates invoice total). You can change it again before finalize challan.</span>
                    </p>
                </div>
            </div>
        </section>

        {{-- ===== Dispatch items panel ===== --}}
        <section class="mb-5 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center gap-2.5 border-b border-amber-200 bg-amber-50 px-4 py-3 md:px-5">
                <span class="flex size-8 items-center justify-center rounded-md bg-amber-100 text-amber-700">
                    <x-erp.icon name="package" class="size-[18px]" />
                </span>
                <h5 class="text-sm font-semibold text-amber-900 m-0">Dispatch items <span class="ml-0.5 text-red-500">*</span></h5>
                <div class="ml-auto">
                    <span class="inline-flex items-center rounded-md bg-slate-800 px-2.5 py-1 text-xs font-medium text-white">{{ $itemCount }} line{{ $itemCount === 1 ? '' : 's' }}</span>
                </div>
            </div>

            {{-- Bulk tools bar --}}
            @if (!$warehouses->isEmpty())
            <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50/80 px-4 py-3 md:flex-row md:items-center md:px-5">
                <div class="flex items-center gap-2 text-sm font-medium text-slate-600">
                    <x-erp.icon name="warehouse" class="size-4 text-slate-400" />
                    <span>Apply warehouse to all</span>
                </div>
                <select id="chBulkWarehouse" class="form-select form-select-sm h-9 w-full bg-white md:w-56 min-w-[160px]">
                    <option value="">— Choose warehouse —</option>
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}">{{ $w->warehouse_name }}</option>
                    @endforeach
                </select>
                <button type="button" id="chApplyBulkWarehouse"
                        class="inline-flex h-9 items-center gap-2 rounded-md bg-yellow-400 text-slate-900 shadow-sm hover:bg-yellow-500 hover:text-slate-900 px-4 text-sm font-medium transition-colors">
                    <x-erp.icon name="check" class="size-4" /> Apply
                </button>
                <button type="button" id="chFillAllCtn"
                        class="inline-flex h-9 items-center gap-2 rounded-md border border-slate-300 bg-white text-slate-700 hover:bg-slate-50 px-4 text-sm font-medium transition-colors"
                        title="Copy order CTN to dispatch CTN column">
                    <i class="fas fa-clone"></i> Fill all CTN
                </button>
                <div class="ml-auto flex items-center gap-3 md:w-44 md:flex-col md:items-end md:gap-1">
                    <span id="chAssignProgressLabel" class="text-xs font-medium text-slate-500 whitespace-nowrap">0 / 0 warehouses set</span>
                    <div class="h-1.5 w-24 md:w-full rounded-full bg-slate-200 overflow-hidden" role="progressbar"
                         aria-valuenow="0" aria-valuemin="0" aria-valuemax="{{ $itemCount }}" aria-label="Warehouse assignment progress">
                        <div id="chAssignProgressBar" class="h-full w-0 bg-gradient-to-r from-amber-500 to-orange-500 rounded-full transition-all duration-200"></div>
                    </div>
                </div>
            </div>
            @endif

            {{-- Items table (black header — Next.js parity) --}}
            <div class="overflow-x-auto">
                <table class="w-full text-sm border-collapse min-w-[860px]" id="godownItemsTable">
                    <thead>
                        <tr>
                            <th class="bg-black px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-white first:pl-5">SL</th>
                            <th class="bg-black px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-white">Product</th>
                            <th class="bg-black px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-white">Ordered</th>
                            <th class="bg-black px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-white">CTN</th>
                            <th class="bg-black px-3 py-3 text-left text-xs font-semibold uppercase tracking-wide text-white">Warehouse <span class="text-red-400">*</span></th>
                            <th class="bg-black px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-white">{{ $isEditGodown ? 'Reserved' : 'Available' }}</th>
                            <th class="bg-black px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-white">Demand (locked)</th>
                            <th class="bg-black px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-white">Disp. CTN</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoice->items as $i => $item)
                            @php
                                $rows = $availForProduct($item->product_id);
                                $totalAvail = $totalAvailForProduct($item->product_id);
                                $selectedWid = old('warehouse_assignments.' . $item->id, (string) ($item->warehouse_id ?? ''));
                                $computedCtn = $computedCtnForItem($item);
                                $isOdd = $loop->iteration % 2 === 0;
                            @endphp
                            <tr class="border-b border-slate-100 {{ $isOdd ? 'bg-slate-50/60' : '' }} hover:bg-amber-50/40 transition-colors" data-item-id="{{ $item->id }}">
                                {{-- SL --}}
                                <td class="px-3 py-3 pl-5 text-center text-sm font-medium text-slate-600">{{ $loop->iteration }}</td>
                                {{-- Product --}}
                                <td class="px-3 py-3">
                                    <div class="flex flex-col">
                                        @if ($item->product)
                                            <span class="text-sm font-semibold text-slate-800">{{ $item->product->product_name }}</span>
                                            <span class="font-mono text-[11px] text-slate-400">{{ $item->product->product_code }}</span>
                                        @else
                                            <span class="text-sm font-semibold text-slate-800">Product #{{ $item->product_id }}</span>
                                        @endif
                                    </div>
                                </td>
                                {{-- Ordered --}}
                                <td class="px-3 py-3 text-center text-sm tabular-nums text-slate-700">{{ number_format((float) $item->qty, 2) }}</td>
                                {{-- CTN (computed) --}}
                                <td class="px-3 py-3 text-center text-sm tabular-nums text-slate-700">{{ number_format($computedCtn, 2) }}</td>
                                {{-- Warehouse * --}}
                                <td class="px-3 py-3">
                                    @if ($warehouses->isEmpty())
                                        <span class="inline-flex items-center gap-1 bg-red-100 text-red-700 border border-red-300 font-semibold text-xs rounded px-2 py-1">
                                            <i class="fas fa-ban"></i> No warehouses
                                        </span>
                                    @else
                                        <select name="warehouse_assignments[{{ $item->id }}]"
                                                class="form-select form-select-sm warehouse-select w-full min-w-[180px]"
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
                                {{-- Available / Reserved (stock badge) --}}
                                <td class="px-3 py-3 text-center text-sm tabular-nums text-slate-500">
                                    <span id="stock-badge-{{ $item->id }}"
                                          class="stock-badge inline-block px-2 py-1 rounded text-xs font-bold bg-slate-100 text-slate-500"
                                          role="status" aria-live="polite"
                                          data-aria-label="Stock status for this line">—</span>
                                    <span id="avg-cost-{{ $item->id }}" class="hidden avg-cost-display"></span>
                                </td>
                                {{-- Demand (locked) --}}
                                <td class="px-3 py-3 text-center">
                                    <span class="inline-flex items-center gap-1.5 rounded-md bg-slate-100 px-2 py-1 text-sm font-semibold tabular-nums text-slate-700">
                                        <i class="fas fa-lock text-slate-400" style="font-size:0.7rem;"></i>
                                        {{ number_format((float) $item->qty, 2) }}
                                    </span>
                                </td>
                                {{-- Disp. CTN (editable) --}}
                                <td class="px-3 py-3">
                                    <input type="number" step="0.01" min="0"
                                           class="form-control form-control-sm dispatched-ctn-input h-9 w-20 text-center font-mono tabular-nums border border-slate-300 rounded-md px-2"
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
        <section class="mb-5 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center gap-2.5 border-b border-amber-200 bg-amber-50 px-4 py-3 md:px-5">
                <span class="flex size-8 items-center justify-center rounded-md bg-amber-100 text-amber-700">
                    <x-erp.icon name="users" class="size-[18px]" />
                </span>
                <h2 class="text-sm font-semibold text-amber-900 m-0">Dispatcher(s) <span class="ml-0.5 text-red-500">*</span></h2>
                <div class="ml-auto">
                    <span class="inline-flex items-center rounded-md bg-slate-800 px-2.5 py-1 text-xs font-medium text-white" id="dispatcher-count-badge">{{ $invoice->dispatchers->count() }} selected</span>
                </div>
            </div>
            <div class="p-4 md:p-5">
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
                        <p class="font-medium text-amber-800 text-sm m-0">No dispatchers found</p>
                        <p class="text-xs text-amber-700 mt-0.5 m-0">No active dispatcher-role employees in this invoice's branch.</p>
                    </div>
                </div>
                <p class="text-xs text-slate-500 mt-2 mb-0">Select one or more warehouse dispatchers for this delivery.</p>
            </div>
        </section>

        {{-- ===== BOTTOM ACTION BAR (fixed, Next.js parity) ===== --}}
        <footer class="fixed bottom-0 inset-x-0 z-40 border-t border-slate-200 bg-white px-4 py-3 shadow-[0_-4px_16px_rgba(15,23,42,0.08)] no-print">
            <div class="mx-auto flex max-w-[1600px] flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <a href="{{ route('admin.sales-invoices.show', $invoice) }}"
                   class="inline-flex items-center justify-center gap-2 self-start rounded-md px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition-colors min-h-[40px]">
                    <x-erp.icon name="arrow-left" class="size-4" /> Back
                </a>
                <div class="flex flex-wrap items-center gap-2 sm:gap-3">
                    <button type="submit" id="btn-save-godown"
                            class="inline-flex h-10 items-center gap-2 rounded-md bg-indigo-600 text-white shadow-md hover:bg-indigo-700 px-4 text-sm font-medium transition-colors disabled:opacity-50 disabled:pointer-events-none"
                            @if ($warehousesEmpty) disabled @endif>
                        <x-erp.icon name="save" class="size-4" /> {{ $isEditGodown ? 'Update CTN' : 'Save godown' }}
                    </button>
                    <span id="ctrl-s-hint" class="hidden sm:inline-flex items-center gap-1 rounded-md bg-slate-900 px-2 py-1 text-[10px] font-medium text-slate-300">
                        <kbd class="font-sans">Ctrl</kbd><span>+</span><kbd class="font-sans">S</kbd>
                    </span>
                    @if ($godownReady)
                        <a href="{{ route('admin.sales-challans.challan-form', $invoice) }}"
                           class="inline-flex h-10 items-center gap-2 rounded-md bg-amber-500 text-white shadow-md hover:bg-amber-600 px-4 text-sm font-medium transition-colors min-h-[40px]"
                           title="Issue stock and complete challan">
                            <i class="fas fa-check-double"></i> Finalize challan
                        </a>
                    @else
                        <button type="button" disabled title="Save godown copy first"
                                class="inline-flex h-10 items-center gap-2 rounded-md bg-amber-500 text-white px-4 text-sm font-medium opacity-50 cursor-not-allowed min-h-[40px]">
                            <i class="fas fa-check-double"></i> Finalize challan
                        </button>
                    @endif
                </div>
            </div>
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
            $badge.attr('class', base + ' bg-slate-100 text-slate-500')
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

    // Transport "Apply" button — toast confirmation (visual only; value is saved on form submit).
    $('#chApplyTransport').on('click', function () {
        var v = parseFloat($('#godown_transport_cost').val()) || 0;
        Swal.fire({ icon: 'success', title: 'Transport cost applied', text: 'Tk ' + v.toFixed(2) + ' will be saved with the godown entry.', timer: 1600, showConfirmButton: false, toast: true, position: 'top-end' });
    });

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

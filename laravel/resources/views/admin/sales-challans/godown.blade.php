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

    // Phase 4: pcs_per_carton — Project B's products table has NO
    // pcs_per_carton column (confirmed in Phase 4 code inspection).
    // Default to 1 so "Fill all CTN" = qty / 1 = qty (functional but
    // 1:1). When a pcs_per_carton column is added (carry-forward to
    // Phase 11 or a master-data phase), this default can be replaced
    // with $item->product->pcs_per_carton ?? 1.
    $pcsPerCartonForItem = function ($item): float {
        return 1.0;
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

    {{-- Hero header (amber/orange gradient — Phase 2 parity with Project A) --}}
    <div class="bg-gradient-to-r from-amber-500 via-amber-600 to-orange-500 rounded-xl p-6 shadow-lg">
        <div class="flex items-start justify-between flex-wrap gap-4">
            <div class="flex items-start gap-4">
                <div class="bg-white/20 backdrop-blur-sm rounded-xl size-14 flex items-center justify-center text-white shrink-0">
                    <x-erp.icon name="warehouse" class="size-7" />
                </div>
                <div>
                    <p class="text-amber-100 text-xs font-medium uppercase tracking-wider">গোডাউন কপি / Godown Preparation</p>
                    <div class="flex items-center gap-3 flex-wrap mt-1">
                        <h1 class="text-2xl font-bold text-white">Godown &amp; Challan</h1>
                        <span class="bg-white/20 rounded-full px-3 py-1 text-sm font-mono text-white">{{ $invoice->invoice_code }}</span>
                    </div>
                    <p class="text-amber-100 text-sm mt-1.5 flex items-center gap-2 flex-wrap">
                        <span class="inline-flex items-center gap-1">
                            <x-erp.icon name="map-pin" class="size-3.5" />
                            {{ $branchName }}
                        </span>
                        <span class="text-amber-200">·</span>
                        <span>Step 2 of 4 — Assign a source warehouse to each invoice line</span>
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

        {{-- 4-step workflow indicator (Phase 1: uses <x-erp.journey-stepper>) --}}
        <x-erp.journey-stepper :current="2" />
    </div>

    {{-- 4-card summary grid (Phase 2 — parity with Project A's summary row) --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
        <x-erp.stat-card label="Customer" label-bn="ক্রেতা"
                         :value="$customerName"
                         accent="amber" icon="users">
            @if ($customerMobile)
                <span class="font-mono">{{ $customerMobile }}</span>
            @else
                <span class="text-gray-400">— no mobile —</span>
            @endif
        </x-erp.stat-card>

        <x-erp.stat-card label="Invoice date" label-bn="চালান তারিখ"
                         :value="$invoiceDate"
                         accent="amber" icon="clock">
            @if ($salesmanName)
                <span class="text-gray-600">Salesman:</span> {{ $salesmanName }}
            @else
                <span class="text-gray-400">— no salesman —</span>
            @endif
        </x-erp.stat-card>

        <x-erp.stat-card label="Items" label-bn="আইটেম"
                         :value="(string) $itemCount"
                         accent="amber" icon="box">
            line{{ $itemCount === 1 ? '' : 's' }} on this invoice
        </x-erp.stat-card>

        <x-erp.stat-card label="Invoice total" label-bn="মোট"
                         :value="'Tk ' . number_format($invoiceTotal, 2)"
                         accent="green" icon="banknote">
            <x-erp.status-pill :status="$displayStatus" />
        </x-erp.stat-card>
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

    {{-- Phase 5: edit-godown policy callout (cyan ties to the godown_prepared stage) --}}
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

            {{-- Phase 4: Bulk tools bar — Apply warehouse to all + Fill all CTN + progress bar --}}
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
                        class="inline-flex items-center gap-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded-lg px-3 py-1.5 text-xs font-medium transition-colors shadow-sm">
                    <x-erp.icon name="check" class="size-3.5" /> Apply
                </button>
                <span class="text-gray-300">|</span>
                <button type="button" id="chFillAllCtn"
                        class="inline-flex items-center gap-1.5 border border-gray-200 hover:bg-gray-50 text-gray-700 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors"
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

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-amber-50/50">
                            <th class="px-4 py-3 text-left font-medium">Product</th>
                            <th class="px-4 py-3 text-center font-medium">Ordered Qty</th>
                            <th class="px-4 py-3 text-left font-medium">Warehouse</th>
                            <th class="px-4 py-3 text-left font-medium">Available Stock</th>
                            <th class="px-4 py-3 text-right font-medium">Avg Cost (Tk)</th>
                            <th class="px-4 py-3 text-center font-medium">Disp. CTN <span class="text-gray-400 font-normal">(editable)</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoice->items as $item)
                            @php
                                $rows = $availForProduct($item->product_id);
                                $totalAvail = $totalAvailForProduct($item->product_id);
                                $short = $totalAvail < (float) $item->qty;
                                // Phase 5: persisted warehouse (edit-godown re-entry)
                                // or old() on back-with-input.
                                $selectedWid = old('warehouse_assignments.' . $item->id, (string) ($item->warehouse_id ?? ''));
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
                                                data-pcs-per-carton="{{ $pcsPerCartonForItem($item) }}"
                                                data-is-godown-prepared="{{ $isEditGodown ? 1 : 0 }}"
                                                data-persisted-warehouse-id="{{ $item->warehouse_id ?? '' }}"
                                                required>
                                            <option value="">— select warehouse —</option>
                                            @foreach ($warehouses as $w)
                                                @php
                                                    $row = $rows->firstWhere('warehouse_id', $w->id);
                                                    $wAvail = $row ? (float) $row->qty : 0.0;          // pipeline-aware available
                                                    $wPhys  = $row ? (float) $row->physical_qty : 0.0;
                                                    $wPipe  = $row ? (float) $row->pipeline_qty : 0.0;
                                                    $wCost  = $row ? (float) $row->avg_cost : 0.0;
                                                    $isSelected = (string) $w->id === (string) $selectedWid;
                                                    // Phase 5: never disable the currently-persisted warehouse
                                                    // (so re-save keeps it selectable even if pipeline-tight).
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
                                        {{-- Phase 7: per-row live stock badge. Reflects the SELECTED
                                             warehouse's availability vs this line's demand. Colors:
                                             green (≥ demand), amber (0 < avail < demand), red (avail = 0),
                                             blue (reserved — godown-prepared + persisted warehouse).
                                             Icon + text in every state so color is never the only signal. --}}
                                        <span id="stock-badge-{{ $item->id }}"
                                              class="stock-badge mt-1.5 inline-flex items-center gap-1 text-xs font-semibold rounded-full px-2 py-0.5 border bg-gray-50 text-gray-500 border-gray-200"
                                              role="status" aria-live="polite"
                                              data-aria-label="Stock status for this line">
                                            <i class="fas fa-circle-question"></i>
                                            <span class="stock-badge-text">Select warehouse</span>
                                        </span>
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
                                                    · phys {{ number_format((float) $row->physical_qty, 2) }}
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
                                {{-- Phase 4: Disp. CTN input (carton packing count, editable) --}}
                                <td class="px-4 py-3 text-center">
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
        </div>

        {{-- Dispatcher assignment card (Phase 3 — multi-select via Select2 AJAX) --}}
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-amber-100 flex items-center justify-between flex-wrap gap-2">
                <div>
                    <h3 class="text-base flex items-center gap-2 font-medium">
                        <x-erp.icon name="users" class="size-5 text-amber-600" />
                        Dispatcher(s)
                        <span class="text-red-500" title="Required">*</span>
                    </h3>
                    <p class="text-xs text-gray-500">ডেলিভারির জন্য কমপক্ষে একজন ডিসপ্যাচার নির্বাচন করুন</p>
                </div>
                <span class="bg-amber-100 border border-amber-300 text-amber-700 rounded-full px-2 py-0.5 text-xs font-medium" id="dispatcher-count-badge">
                    {{ $invoice->dispatchers->count() }} selected
                </span>
            </div>
            <div class="p-4">
                <select id="dispatcher_id" name="dispatcher_id[]" multiple
                        class="form-select"
                        data-invoice-id="{{ $invoice->id }}"
                        data-ajax-url="{{ route('admin.sales-challans.dispatchers') }}"
                        required>
                    @foreach ($invoice->dispatchers as $dispatcher)
                        <option value="{{ $dispatcher->id }}" selected>{{ $dispatcher->name }}@if($dispatcher->employee_code) ({{ $dispatcher->employee_code }})@endif</option>
                    @endforeach
                </select>
                <p class="text-xs text-gray-500 mt-2 flex items-start gap-1.5">
                    <i class="fas fa-circle-info text-amber-500 mt-0.5"></i>
                    <span>Dispatchers are filtered to active employees with the dispatcher role in this invoice's branch. Type to search by name, code, or phone.</span>
                </p>
            </div>
        </div>

        {{-- Phase 6: Transport cost card + live total preview (A7, U12).
            Transport cost is edited at godown; a change posts a customer_ledger
            'invoice_adjustment' delta immediately. The matching GL entry is
            posted at challan issue (deferred, mirrors Project A). --}}
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-amber-100 flex items-center justify-between flex-wrap gap-2">
                <div>
                    <h3 class="text-base flex items-center gap-2 font-medium">
                        <i class="fas fa-truck text-amber-600"></i>
                        Transport Cost
                    </h3>
                    <p class="text-xs text-gray-500">পরিবহন খরচ — customer ledger posts now; GL posts at challan issue</p>
                </div>
                <span class="bg-amber-100 border border-amber-300 text-amber-700 rounded-full px-2 py-0.5 text-xs font-medium">
                    editable at godown
                </span>
            </div>
            <div class="p-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 items-start">
                    <div>
                        <label class="text-sm font-medium text-gray-500 block mb-1" for="godown_transport_cost">Transport Cost (Tk) / পরিবহন খরচ</label>
                        <input type="number" step="0.01" min="0" id="godown_transport_cost" name="transport_cost"
                               class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-full outline-none focus:ring-2 focus:ring-amber-300"
                               value="{{ old('transport_cost', number_format($transportCostDefault, 2, '.', '')) }}"
                               data-sub-total="{{ $subTotal }}"
                               data-discount="{{ $discountAmount }}"
                               inputmode="decimal">
                        <p class="text-xs text-gray-500 mt-1.5 flex items-start gap-1.5">
                            <i class="fas fa-circle-info text-amber-500 mt-0.5"></i>
                            <span>Editing transport here posts a <strong>customer ledger</strong> adjustment immediately. The matching <strong>GL entry</strong> is posted when the challan is issued.</span>
                        </p>
                    </div>
                    <div class="md:col-span-2">
                        {{-- Live total preview: #invoice-total-display = sub_total + transport − discount --}}
                        <div class="bg-amber-50 rounded-lg p-3 border border-amber-200">
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
            </div>
        </div>

        <!-- Sticky save bar (matches template PAGE 3) -->
        <div class="flex gap-3 sticky bottom-4 bg-white/80 backdrop-blur-sm py-4 px-4 border-t rounded-t-lg shadow-lg mt-4 items-center justify-end flex-wrap">
            {{-- Phase 7: Ctrl+S hint — desktop only (hidden md:inline).
                 Also doubles as the JS visibility gate for the shortcut:
                 on mobile the hint is display:none, so Ctrl+S is a no-op. --}}
            <span id="ctrl-s-hint" class="hidden md:inline-flex items-center gap-1 text-xs text-gray-400 mr-auto">
                <kbd class="px-1.5 py-0.5 bg-gray-100 border border-gray-300 rounded text-[10px] font-mono shadow-sm">Ctrl</kbd>
                <span>+</span>
                <kbd class="px-1.5 py-0.5 bg-gray-100 border border-gray-300 rounded text-[10px] font-mono shadow-sm">S</kbd>
                <span>to save</span>
            </span>
            <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="border border-gray-200 hover:bg-gray-50 rounded-lg px-4 py-2 text-sm">
                Cancel / বাতিল
            </a>
            <button type="submit"
                    id="btn-save-godown"
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

    // Phase 7 — Live stock badge for the SELECTED warehouse.
    // Colors: green (avail ≥ demand), amber (0 < avail < demand),
    // red (avail = 0), blue (reserved — godown-prepared + persisted wh).
    // Icon + text in every state so color is never the only signal.
    function updateStockBadge($sel) {
        var itemId = $sel.data('item-id');
        var $badge = $('#stock-badge-' + itemId);
        if (!$badge.length) return;

        var demand = parseFloat($sel.data('qty')) || 0;
        var isGodownPrepared = parseInt($sel.data('is-godown-prepared'), 10) === 1;
        var persistedWid = String($sel.data('persisted-warehouse-id') || '');
        var opt = $sel.find('option:selected');
        var wid = opt.val();
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

        var available = parseFloat(opt.data('available')) || 0;
        var physical = parseFloat(opt.data('physical')) || 0;

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

    // On warehouse change, show that warehouse's avg_cost next to the row
    // AND update the live stock badge (Phase 7).
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
        updateStockBadge($sel);
    });

    // Phase 7 — also refresh badge on programmatic clear / unselect
    // (covers bulk-unset and any non-select path).
    $('.warehouse-select').on('select2:unselect change', function () {
        updateStockBadge($(this));
    });

    // Pre-fill displays if any option is already selected (e.g. on
    // back-with-input or edit-godown re-entry). Also renders the initial
    // badge state (Phase 7), including the empty "Select warehouse" shell.
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
        updateStockBadge($sel); // Phase 7: initial badge render
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

    // Phase 3 — Dispatcher multi-select (Select2 AJAX).
    // Pre-filled <option> tags carry already-selected dispatchers (from
    // $invoice->dispatchers); the AJAX endpoint returns the full list of
    // active dispatcher-role employees for the invoice's branch so the
    // user can search/add more.
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
                return { results: data.results || [] };
            },
            cache: true
        }
    });

    // Live-update the "N selected" badge on add/remove.
    $dispatcherSelect.on('change select2:select select2:unselect', function () {
        var count = ($(this).val() || []).length;
        $('#dispatcher-count-badge').text(count + (count === 1 ? ' selected' : ' selected'));
    });

    // Intercept submit if any row has insufficient stock selected (defensive),
    // OR if no dispatcher is selected.
    $('form').on('submit', function (e) {
        var warehouseOk = true;
        $('.warehouse-select').each(function () {
            if (!$(this).val()) warehouseOk = false;
        });

        var dispatcherCount = ($dispatcherSelect.val() || []).length;

        if (!warehouseOk) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Incomplete assignment',
                text: 'Please assign a warehouse to every line item before confirming.',
                confirmButtonColor: '#d97706'
            });
            return;
        }

        if (dispatcherCount < 1) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Dispatcher required',
                text: 'Please select at least one dispatcher for this delivery before saving the godown copy.',
                confirmButtonColor: '#d97706'
            });
            return;
        }
    });

    // Phase 7 — Ctrl/Cmd+S → Save godown (desktop only).
    // The #ctrl-s-hint span is `hidden md:inline-flex`, so on mobile it is
    // display:none and jQuery :visible returns false → shortcut is a no-op.
    // Also guards against a disabled save button (no active warehouses).
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

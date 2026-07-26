<x-layouts.erp :title="$title" :hero="true" :tabs="[
    ['label' => 'Dashboard', 'href' => route('dashboard')],
    ['label' => 'Invoices', 'href' => route('admin.sales-invoices.index')],
    ['label' => 'Challans', 'href' => route('admin.sales-challans.index')],
    ['label' => 'UI Preview', 'href' => route('ui-preview')],
]">
@php
    $totalCogs = (float) ($totalCogs ?? 0);
    // Phase 6: transport is now finalized at godown preparation, so
    // invoice.total_amount already INCLUDES transport_cost. Render the
    // preview as merchandise (sub_total − discount) + transport = grand
    // total (= total_amount), with no double-counting.
    $invoiceTotal = (float) ($invoice->sub_total ?? 0) - (float) ($invoice->discount_amount ?? 0);
    $transportCost = (float) ($invoice->transport_cost ?? 0);
    $grandTotal = $invoiceTotal + $transportCost;

    // Phase 2 — pre-compute summary-card values (parity with godown screen)
    $itemCount = (int) $invoice->items->count();
    $invoiceDate = $invoice->invoice_date
        ? \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y')
        : '—';
    $customerName = $invoice->customer?->customer_name ?? '—';
    $customerMobile = $invoice->customer?->mobile ?? '';
    $salesmanName = $invoice->sales_person ?? ($invoice->salesman?->name ?? '');
    $branchName = $invoice->branch?->branch_name ?? 'Branch';

    // Display status derived from boolean workflow flags so the pill reflects
    // the actual pipeline stage (issue screen → godown_prepared → cyan).
    $displayStatus = $invoice->is_challan_issued
        ? App\Support\StatusPalette::CHALLAN_ISSUED
        : ($invoice->is_godown_prepared
            ? App\Support\StatusPalette::GODOWN_PREPARED
            : App\Support\StatusPalette::DRAFT);

    // Phase 8 — Reverse Challan button (shown when the challan is already
    // issued AND the user is admin/superadmin/manager, matching the cancel
    // route's role:manager,admin middleware). $invoice->challan is the latest
    // SalesChallan for this invoice (HasOne, eager-loaded in the controller).
    // Non-admin viewers see neither the Issue button (already issued) nor the
    // Reverse button — just the Back button.
    $isIssued = (bool) $invoice->is_challan_issued;
    $userRole = auth()->user()?->getRole() ?? 'user';
    $canReverse = $isIssued && in_array($userRole, ['admin', 'superadmin', 'manager'])
        && $invoice->challan !== null;
    $challanModel = $canReverse ? $invoice->challan : null;
@endphp

<div class="space-y-6 challan-scope">
    <!-- Breadcrumb -->
    <nav aria-label="Breadcrumb" class="text-xs text-gray-500 flex items-center gap-1.5 flex-wrap">
        <a href="{{ route('dashboard') }}" class="hover:text-amber-700 transition-colors">Sales</a>
        <x-erp.icon name="chevron-right" class="size-3 text-gray-400" />
        <a href="{{ route('admin.sales-challans.index') }}" class="hover:text-amber-700 transition-colors">Challan</a>
        <x-erp.icon name="chevron-right" class="size-3 text-gray-400" />
        <span class="text-amber-800 font-medium">Issue Challan</span>
    </nav>

    {{-- Hero header (pure orange gradient — Phase 11 polish to match lagachy reference design) --}}
    <div class="bg-gradient-to-r from-orange-400 to-orange-500 rounded-xl p-6 shadow-lg">
        <div class="flex items-start justify-between flex-wrap gap-4">
            <div class="flex items-start gap-4">
                <div class="bg-white/20 backdrop-blur-sm rounded-xl size-14 flex items-center justify-center text-white shrink-0">
                    <x-erp.icon name="truck" class="size-7" />
                </div>
                <div>
                    <p class="text-amber-100 text-xs font-medium uppercase tracking-wider">চালানপত্র ইস্যু / Issue Challan</p>
                    <div class="flex items-center gap-3 flex-wrap mt-1">
                        <h1 class="text-2xl font-bold text-white">Issue Challan</h1>
                        <span class="bg-white/20 rounded-full px-3 py-1 text-sm font-mono text-white">{{ $invoice->invoice_code }}</span>
                    </div>
                    <p class="text-amber-100 text-sm mt-1.5 flex items-center gap-2 flex-wrap">
                        <span class="inline-flex items-center gap-1">
                            <x-erp.icon name="map-pin" class="size-3.5" />
                            {{ $branchName }}
                        </span>
                        <span class="text-amber-200">·</span>
                        <span>Step 3 of 4 — Issue the delivery challan. Stock moves OUT and GL posts Dr COGS / Cr Inventory.</span>
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
        <x-erp.journey-stepper :current="3" />
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

    {{-- Phase 11: Empty state — no invoice items (left-accent-card with red accent) --}}
    @if ($invoice->items->isEmpty())
        <x-erp.left-accent-card accent="red" icon="inbox" title="No invoice items" title-bn="কোনো আইটেম নেই">
            <p class="text-sm text-gray-600">This invoice has no line items. Add items to the invoice first before issuing the challan.</p>
            <div class="mt-3">
                <a href="{{ route('admin.sales-invoices.show', $invoice) }}" class="inline-flex items-center gap-1.5 text-sm font-medium text-amber-700 hover:text-amber-800 transition-colors">
                    <x-erp.icon name="arrow-left" class="size-3.5" /> Back to invoice
                </a>
            </div>
        </x-erp.left-accent-card>
    @else

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
        {{-- Phase 10 (U16, U24): responsive items layout.
             Desktop (≥ sm): the COGS table. Hidden on mobile.
             Mobile (< sm): a read-only card list (<x-erp.left-accent-card>).
             The issue screen has NO editable fields in the items table
             (warehouse is locked at godown prep, qty/cogs are computed),
             so the cards are a pure display equivalent. --}}
        <div class="hidden sm:block overflow-x-auto">
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
                                    {{-- Phase 5 / U6: warehouse locked as a read-only span + hidden input.
                                         Matches Project A's finalize-lock behaviour: the warehouse was
                                         chosen at godown prep and cannot be changed at issue time. --}}
                                    <span class="inline-flex items-center gap-2 bg-amber-50 border border-amber-200 rounded-lg px-3 py-1.5 text-sm font-medium">
                                        <i class="fas fa-lock text-amber-600 text-xs"></i>
                                        <i class="fas fa-warehouse text-amber-600"></i>
                                        {{ $item->warehouse->warehouse_name }}
                                    </span>
                                    {{-- Hidden input preserves the assigned warehouse_id in the form
                                         payload for markup parity. The issue endpoint ignores this
                                         field (warehouse is already persisted at godown prep). --}}
                                    <input type="hidden" name="warehouse_id[{{ $item->id }}]" value="{{ $item->warehouse_id }}">
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

        {{-- Phase 10 (U16, U24): mobile card list (< sm). Each invoice line
             as a <x-erp.left-accent-card> mirroring the COGS table row.
             Read-only — the issue screen has no editable item fields. --}}
        <div class="sm:hidden space-y-3 p-4" aria-label="COGS breakdown — mobile card view">
            @foreach ($invoice->items as $item)
                <x-erp.left-accent-card accent="green" icon="package" :title="($item->product?->product_name ?: 'Product #' . $item->product_id)" :titleBn="$item->product?->product_code">
                    <div class="space-y-2.5 text-sm">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-gray-500 text-xs">Warehouse</span>
                            @if ($item->warehouse)
                                <span class="inline-flex items-center gap-1 bg-amber-50 border border-amber-200 rounded-lg px-2 py-0.5 text-xs font-medium">
                                    <i class="fas fa-lock text-amber-600 text-xs"></i>
                                    <i class="fas fa-warehouse text-amber-600"></i> {{ $item->warehouse->warehouse_name }}
                                </span>
                            @else
                                <span class="bg-red-100 text-red-700 border border-red-300 text-xs rounded-full px-2 py-0.5 inline-flex items-center gap-1">
                                    — unassigned —
                                </span>
                            @endif
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-gray-500 text-xs">Qty</span>
                            <span class="font-semibold">{{ number_format((float) $item->qty, 2) }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-gray-500 text-xs">Avg Cost</span>
                            <span>{{ number_format((float) ($item->avg_cost ?? 0), 2) }}</span>
                        </div>
                        <div class="flex items-center justify-between gap-2 pt-1 border-t border-gray-100">
                            <span class="text-gray-500 text-xs font-medium">COGS</span>
                            <span class="font-bold text-green-700">Tk {{ number_format((float) ($item->cogs_amount ?? 0), 2) }}</span>
                        </div>
                    </div>
                </x-erp.left-accent-card>
            @endforeach
            {{-- Total COGS footer card --}}
            <x-erp.left-accent-card accent="green" :strong="true" title="Total COGS" titleBn="মোট">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-gray-500">Sum of all lines</span>
                    <span class="text-lg font-bold text-green-700">Tk {{ number_format($totalCogs, 2) }}</span>
                </div>
            </x-erp.left-accent-card>
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
                    <label class="text-sm font-medium text-gray-500 block mb-1" for="transport_cost">Transport Cost (Tk) / পরিবহন খরচ <span class="text-xs font-normal text-gray-400">(set at godown)</span></label>
                    <input type="number" step="0.01" min="0" id="transport_cost" name="transport_cost"
                           class="border border-gray-200 rounded-lg px-3 py-2 text-sm w-full outline-none bg-gray-50 cursor-not-allowed"
                           value="{{ number_format((float) ($invoice->transport_cost ?? 0), 2, '.', '') }}"
                           readonly>
                    <p class="text-xs text-gray-500 mt-1">Transport cost is finalized at godown preparation. To change it, go back to the godown screen.</p>
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
                        <span class="text-gray-500 block text-xs">Invoice Total <span class="text-gray-400 font-normal">(excl. transport)</span></span>
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

        {{-- Phase 8: unified sticky action bar (replaces inline issue button bar).
             Uses <x-erp.sticky-action-bar> for parity with the godown screen.
             Layout: [spacer] [Cancel] [Issue Challan | Reverse Challan].
             The <x-layouts.erp> wrapper (sidebar + topbar) is UNCHANGED.
             Mobile: buttons stack full-width (flex-col w-full md:flex-row). --}}
        <x-erp.sticky-action-bar variant="phase8" align="between">
            {{-- Left-side spacer keeps the buttons right-aligned on desktop
                 (justify-between with one empty left child). On mobile the
                 button-group is full-width so the spacer collapses. --}}
            <span class="hidden md:inline-flex items-center gap-1.5 text-xs text-gray-400">
                <i class="fas fa-circle-info text-amber-500"></i>
                @if ($isIssued)
                    Challan issued — irreversible
                @else
                    Stock moves OUT on issue
                @endif
            </span>
            <div class="flex flex-col w-full md:flex-row md:w-auto gap-3">
                <x-erp.outline-button href="{{ route('admin.sales-invoices.show', $invoice) }}" icon="arrow-left" class="w-full md:w-auto">
                    Cancel / বাতিল
                </x-erp.outline-button>

                {{-- Phase 8: Reverse Challan button (issued + admin/manager only).
                     The button is type="button" — it does NOT submit the issue
                     form. JS opens a Swal2 textarea-prompt; on confirm it sets
                     the hidden reason field in #reverseForm (a SIBLING form,
                     placed after </form> below to avoid nested-form invalid
                     HTML) and submits it to the cancel route. --}}
                @if ($canReverse && $challanModel)
                    <button type="button"
                            id="btn-reverse-challan"
                            aria-label="Reverse this challan — opens a prompt for a cancellation reason"
                            class="inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold text-white shadow-md transition-colors bg-red-500 hover:bg-red-600 disabled:opacity-50 disabled:pointer-events-none w-full md:w-auto min-h-[44px]">
                        <i class="fas fa-rotate-left" aria-hidden="true"></i>
                        <span>Reverse Challan</span>
                    </button>
                @endif

                @if (! $isIssued)
                    <x-erp.gradient-button icon="paper-plane" type="submit" id="btn-issue-challan" class="w-full md:w-auto">
                        Issue Challan
                    </x-erp.gradient-button>
                @endif
            </div>
        </x-erp.sticky-action-bar>
    </form>

    {{-- Phase 8: Reverse form — a SIBLING of #issueForm (NOT nested), so HTML
         stays valid. Invisible (no visible children); JS populates the hidden
         reason field + submits when the Swal2 reverse prompt confirms. --}}
    @if ($canReverse && $challanModel)
        <form method="POST" action="{{ route('admin.sales-challans.cancel', $challanModel) }}" id="reverseForm" aria-hidden="true" style="display:none;">
            @csrf
            <input type="hidden" name="cancel_reason" id="reverseReasonInput" value="">
        </form>
    @endif
    @endif {{-- end of @else (has items) — Phase 11 empty-state guard --}}
</div>

</x-layouts.erp>

@push('scripts')
<script>
$(function () {
    // Phase 8 — Issue flow: warning → confirm → loading → native submit.
    // e.target.submit() is the native DOM submit (bypasses this handler).
    // The server redirects to the challan show page on success; the success
    // flash renders as a banner there. A print-options success modal is
    // deferred to Phase 9 (which owns show.blade.php + the print buttons).
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
            if (!res.isConfirmed) return;
            Swal.fire({
                title: 'Issuing challan…',
                html: '<span class="text-muted small">Moving stock + posting COGS journal entry</span>',
                timer: 10000,
                timerProgressBar: true,
                didOpen: function () { Swal.showLoading(); },
                showConfirmButton: false
            });
            e.target.submit(); // native — no handler re-entry
        });
    });

    // Phase 8 — Reverse flow (mirrors show.blade.php's #cancelBtn pattern).
    // Only wired when the #btn-reverse-challan button exists (issued + admin).
    var $reverseBtn = $('#btn-reverse-challan');
    if ($reverseBtn.length) {
        $reverseBtn.on('click', function () {
            Swal.fire({
                icon: 'warning',
                title: 'Reverse this challan?',
                html: '<p class="mb-2">This will <strong>reverse stock movements</strong> and <strong>reverse the GL journal entry</strong>.</p>' +
                      '<p class="mb-2 text-muted small">A reason is required (min 5 chars, max 500).</p>' +
                      '<textarea id="reverseReason" class="form-control" placeholder="Enter reversal reason..." maxlength="500" rows="3"></textarea>',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-rotate-left"></i> Yes, reverse challan',
                cancelButtonText: 'Keep challan',
                preConfirm: function () {
                    var reason = $('#reverseReason').val().trim();
                    if (reason.length < 5) {
                        Swal.showValidationMessage('A reversal reason of at least 5 characters is required.');
                        return false;
                    }
                    return reason;
                }
            }).then(function (res) {
                if (!res.isConfirmed) return;
                $('#reverseReasonInput').val(res.value);
                Swal.fire({
                    title: 'Reversing challan…',
                    html: '<span class="text-muted small">Reversing stock + GL</span>',
                    timer: 10000,
                    timerProgressBar: true,
                    didOpen: function () { Swal.showLoading(); },
                    showConfirmButton: false
                });
                $('#reverseForm').submit();
            });
        });
    }
});
</script>
@endpush

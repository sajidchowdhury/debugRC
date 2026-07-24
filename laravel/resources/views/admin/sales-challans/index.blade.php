<x-layouts.erp :title="$title ?? 'Challans'" :tabs="[
    ['label' => 'Dashboard', 'href' => route('dashboard')],
    ['label' => 'Invoices', 'href' => route('admin.sales-invoices.index')],
    ['label' => 'Challans', 'href' => route('admin.sales-challans.index'), 'active' => true],
    ['label' => 'UI Preview', 'href' => route('ui-preview')],
]">
@php
    // Defaults for filter controls
    $filters = array_merge([
        'from_date' => '',
        'to_date'   => '',
        'branch_id' => '',
        'search'    => '',
    ], is_array($filters ?? null) ? $filters : []);

    $stats = array_merge([
        'total'           => 0,
        'active'          => 0,
        'reversed'        => 0,
        'total_cogs'      => 0,
        'pending_godown'  => 0,
        'pending_challan' => 0,
    ], is_array($stats ?? null) ? $stats : []);

    // Determine active tab. Default = 'pending_godown' (the WM's primary queue).
    $activeTab = request()->input('tab', 'pending_godown');
    if (!in_array($activeTab, ['pending_godown', 'pending_challan', 'issued'], true)) {
        $activeTab = 'pending_godown';
    }

    $invoiceLineCount = function ($invoice) {
        return $invoice->relationLoaded('items') ? $invoice->items->count() : 0;
    };
    $invoiceTotalQty = function ($invoice) {
        if (!$invoice->relationLoaded('items')) return 0;
        return $invoice->items->sum('qty');
    };
@endphp

<div class="space-y-6">

    {{-- ============================================================ --}}
    {{-- 1. HERO HEADER (amber/orange gradient + journey stepper)     --}}
    {{-- ============================================================ --}}
    <div class="bg-gradient-to-r from-amber-500 via-amber-600 to-orange-500 rounded-xl p-6 shadow-lg relative overflow-hidden">
        {{-- decorative blurred circle --}}
        <div class="absolute -right-10 -top-10 size-40 bg-white/10 rounded-full blur-2xl"></div>
        <div class="absolute -left-6 -bottom-12 size-32 bg-orange-300/20 rounded-full blur-2xl"></div>

        <div class="relative flex items-center justify-between flex-wrap gap-4">
            <div>
                <h1 class="text-2xl font-bold text-white flex items-center gap-2">
                    <i class="fas fa-truck-ramp-box"></i>
                    গোডাউন ও চালান
                </h1>
                <p class="text-amber-100 text-sm mt-1">
                    {{ $title }} — Warehouse workflow queue · ওয়্যারহাউজ ওয়ার্কফ্লো
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('admin.sales-invoices.index') }}" class="inline-flex items-center gap-1.5 bg-white/20 backdrop-blur-sm hover:bg-white/30 text-white rounded-lg px-3 py-2 text-xs font-medium transition-colors">
                    <x-erp.icon name="file-text" class="size-4" />
                    Invoices
                </a>
                <a href="{{ route('admin.sales-challans.export-csv') }}" id="csvExportBtn" target="_blank" class="inline-flex items-center gap-1.5 bg-white/20 backdrop-blur-sm hover:bg-white/30 text-white rounded-lg px-3 py-2 text-xs font-medium transition-colors">
                    <x-erp.icon name="download" class="size-4" />
                    Export CSV
                </a>
            </div>
        </div>

        {{-- Journey stepper --}}
        <div class="relative mt-6">
            <x-erp.journey-stepper />
        </div>
    </div>

    {{-- ============================================================ --}}
    {{-- 2. STAT CARDS (border-l-4 accent, showcase spec)             --}}
    {{-- ============================================================ --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <x-erp.stat-card label="Pending Godown" label-bn="গোডাউন বাকি" :value="number_format((int) ($stats['pending_godown'] ?? 0))" accent="amber" icon="warehouse" />
        <x-erp.stat-card label="Pending Challan" label-bn="চালান বাকি" :value="number_format((int) ($stats['pending_challan'] ?? 0))" accent="orange" icon="truck" />
        <x-erp.stat-card label="Issued (Active)" label-bn="ইস্যুকৃত" :value="number_format((int) ($stats['active'] ?? 0))" accent="green" icon="check-circle" />
        <x-erp.stat-card label="Total COGS" label-bn="মোট ক্রয়মূল্য" :value="'৳' . number_format((float) ($stats['total_cogs'] ?? 0), 0)" accent="cyan" icon="banknote" />
    </div>

    {{-- ============================================================ --}}
    {{-- 3. FILTER FORM (white rounded card, showcase spec)           --}}
    {{-- ============================================================ --}}
    <div class="bg-white rounded-xl shadow-sm border border-amber-100 p-4">
        <div class="flex items-center gap-2 mb-3">
            <div class="size-8 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center">
                <x-erp.icon name="search" class="size-4" />
            </div>
            <div>
                <h3 class="font-semibold text-amber-900 text-sm">Filters / ফিল্টার</h3>
                <p class="text-xs text-gray-500">Search invoices, challans, customers — narrow the workflow queue</p>
            </div>
        </div>
        <form method="GET" action="{{ route('admin.sales-challans.index') }}" class="grid grid-cols-1 md:grid-cols-12 gap-2 items-end">
            <input type="hidden" name="tab" value="{{ $activeTab }}">
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-600 mb-1" for="from_date">From date <span class="text-gray-400">(issued)</span></label>
                <input type="date" id="from_date" name="from_date"
                       class="w-full rounded-md border border-amber-200 bg-amber-50/40 px-2.5 py-1.5 text-sm text-gray-800 outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-200"
                       value="{{ $filters['from_date'] }}">
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-medium text-gray-600 mb-1" for="to_date">To date <span class="text-gray-400">(issued)</span></label>
                <input type="date" id="to_date" name="to_date"
                       class="w-full rounded-md border border-amber-200 bg-amber-50/40 px-2.5 py-1.5 text-sm text-gray-800 outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-200"
                       value="{{ $filters['to_date'] }}">
            </div>
            <div class="md:col-span-5">
                <label class="block text-xs font-medium text-gray-600 mb-1" for="search">Search <span class="text-gray-400">(invoice / challan / customer)</span></label>
                <div class="relative">
                    <x-erp.icon name="search" class="size-4 text-amber-400 absolute left-2.5 top-1/2 -translate-y-1/2" />
                    <input type="text" id="search" name="search"
                           class="w-full rounded-md border border-amber-200 bg-amber-50/40 pl-8 pr-2.5 py-1.5 text-sm text-gray-800 outline-none focus:border-amber-400 focus:ring-2 focus:ring-amber-200"
                           placeholder="INV-2025-... / CH-... / customer name" value="{{ $filters['search'] }}">
                </div>
            </div>
            <div class="md:col-span-3 flex gap-2 justify-end">
                <button type="submit" class="inline-flex items-center gap-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded-md px-3 py-1.5 text-xs font-medium transition-colors shadow-sm">
                    <x-erp.icon name="filter" class="size-4" /> Filter
                </button>
                <a href="{{ route('admin.sales-challans.index') }}" class="inline-flex items-center gap-1.5 bg-white border border-amber-200 hover:bg-amber-50 text-amber-700 rounded-md px-3 py-1.5 text-xs font-medium transition-colors">
                    <x-erp.icon name="x" class="size-4" /> Clear
                </a>
            </div>
        </form>
    </div>

    {{-- ============================================================ --}}
    {{-- 4. STATUS FILTER CHIPS (replaces Bootstrap nav-tabs)         --}}
    {{-- ============================================================ --}}
    <div class="flex flex-wrap gap-2">
        @php
            $chipDefs = [
                'pending_godown'  => [
                    'label'   => 'Pending Godown Prep',
                    'label_bn'=> 'গোডাউন বাকি',
                    'icon'    => 'warehouse',
                    'count'   => (int) ($stats['pending_godown'] ?? 0),
                    'active_cls'   => 'bg-amber-500 text-white border-amber-500 shadow-sm',
                    'inactive_cls' => 'bg-white text-amber-700 border-amber-200 hover:bg-amber-50',
                    'badge_active' => 'bg-white/30 text-white',
                    'badge_inactive'=> 'bg-amber-100 text-amber-700',
                ],
                'pending_challan' => [
                    'label'   => 'Pending Challan Issue',
                    'label_bn'=> 'চালান বাকি',
                    'icon'    => 'truck',
                    'count'   => (int) ($stats['pending_challan'] ?? 0),
                    'active_cls'   => 'bg-orange-500 text-white border-orange-500 shadow-sm',
                    'inactive_cls' => 'bg-white text-orange-700 border-orange-200 hover:bg-orange-50',
                    'badge_active' => 'bg-white/30 text-white',
                    'badge_inactive'=> 'bg-orange-100 text-orange-700',
                ],
                'issued'          => [
                    'label'   => 'Issued Challans',
                    'label_bn'=> 'ইস্যুকৃত চালান',
                    'icon'    => 'check-circle',
                    'count'   => (int) ($stats['active'] ?? 0),
                    'active_cls'   => 'bg-green-600 text-white border-green-600 shadow-sm',
                    'inactive_cls' => 'bg-white text-green-700 border-green-200 hover:bg-green-50',
                    'badge_active' => 'bg-white/30 text-white',
                    'badge_inactive'=> 'bg-green-100 text-green-700',
                ],
            ];
        @endphp

        <ul class="nav nav-tabs border-0 d-flex flex-wrap gap-2" id="challanTabs" role="tablist" style="gap:0.5rem">
            @foreach ($chipDefs as $tabKey => $chip)
                <li class="nav-item" role="presentation">
                    <button type="button"
                            role="tab"
                            class="nav-link rounded-full px-4 py-1.5 text-xs font-medium border inline-flex items-center gap-1.5 transition-all {{ $activeTab === $tabKey ? $chip['active_cls'] : $chip['inactive_cls'] }}"
                            id="{{ str_replace('_', '-', $tabKey) }}-tab"
                            data-bs-toggle="tab"
                            data-bs-target="#{{ str_replace('_', '-', $tabKey) }}"
                            onclick="switchTab('{{ $tabKey }}')"
                            aria-selected="{{ $activeTab === $tabKey ? 'true' : 'false' }}">
                        <x-erp.icon name="{{ $chip['icon'] }}" class="size-3.5" />
                        <span>{{ $chip['label'] }}</span>
                        <span class="text-[10px] opacity-80 hidden sm:inline">/ {{ $chip['label_bn'] }}</span>
                        <span class="rounded-full px-1.5 py-0.5 text-[10px] font-bold {{ $activeTab === $tabKey ? $chip['badge_active'] : $chip['badge_inactive'] }}">
                            {{ number_format($chip['count']) }}
                        </span>
                    </button>
                </li>
            @endforeach
        </ul>
    </div>

    {{-- ============================================================ --}}
    {{-- 5. TAB CONTENT (3 panels, showcase-styled tables)            --}}
    {{-- ============================================================ --}}
    <div class="tab-content">

        {{-- ========================================================= --}}
        {{-- TAB 1: Pending Godown Prep                                 --}}
        {{-- ========================================================= --}}
        <div class="tab-pane fade {{ $activeTab === 'pending_godown' ? 'show active' : '' }}"
             id="pending-godown" role="tabpanel">

            <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-amber-100">
                {{-- Card header --}}
                <div class="px-6 py-4 border-b border-amber-100 flex items-center justify-between flex-wrap gap-2 bg-gradient-to-r from-amber-50/60 to-orange-50/40">
                    <h3 class="font-semibold text-amber-900 flex items-center gap-2 text-sm">
                        <span class="size-8 rounded-lg bg-amber-100 text-amber-600 flex items-center justify-center">
                            <x-erp.icon name="warehouse" class="size-4" />
                        </span>
                        Invoices awaiting godown prep
                        <span class="text-xs text-gray-400 font-normal hidden md:inline">/ গোডাউন প্রস্তুতি বাকি</span>
                    </h3>
                    <span class="inline-flex items-center gap-1 bg-amber-100 text-amber-700 rounded-full px-2.5 py-0.5 text-xs font-medium">
                        <x-erp.icon name="list" class="size-3" />
                        {{ $pendingGodown->count() }} shown
                    </span>
                </div>
                <p class="px-6 pt-3 text-xs text-gray-500">
                    Freshly finalized invoices from salesmen. Assign a warehouse to each line, then save to advance the invoice to “Pending Challan Issue”.
                </p>

                {{-- Table with sticky header + custom-scroll --}}
                <div class="max-h-96 overflow-y-auto custom-scroll">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 z-10">
                            <tr class="bg-amber-50/90 backdrop-blur-sm border-b border-amber-200">
                                <th class="px-4 py-3 text-left font-semibold text-amber-800 text-xs uppercase tracking-wide">Invoice</th>
                                <th class="px-4 py-3 text-left font-semibold text-amber-800 text-xs uppercase tracking-wide">Date</th>
                                <th class="px-4 py-3 text-left font-semibold text-amber-800 text-xs uppercase tracking-wide">Customer</th>
                                <th class="px-4 py-3 text-left font-semibold text-amber-800 text-xs uppercase tracking-wide">Branch</th>
                                <th class="px-4 py-3 text-center font-semibold text-amber-800 text-xs uppercase tracking-wide">Lines</th>
                                <th class="px-4 py-3 text-right font-semibold text-amber-800 text-xs uppercase tracking-wide">Qty</th>
                                <th class="px-4 py-3 text-right font-semibold text-amber-800 text-xs uppercase tracking-wide">Amount</th>
                                <th class="px-4 py-3 text-center font-semibold text-amber-800 text-xs uppercase tracking-wide">Hold</th>
                                <th class="px-4 py-3 text-right font-semibold text-amber-800 text-xs uppercase tracking-wide">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($pendingGodown as $inv)
                                <tr class="hover:bg-amber-50/40 border-b border-gray-100 transition-colors">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.sales-invoices.show', $inv) }}" class="font-semibold text-amber-900 hover:text-amber-600 hover:underline">
                                            {{ $inv->invoice_code }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-600 whitespace-nowrap">
                                        {{ \Carbon\Carbon::parse($inv->invoice_date)->format('d M Y') }}
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($inv->customer)
                                            <span class="font-medium text-gray-800">{{ $inv->customer->customer_name }}</span>
                                            <span class="text-xs text-gray-400 block">{{ $inv->customer->customer_code }}</span>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($inv->branch)
                                            <span class="inline-flex items-center gap-1 bg-gray-100 text-gray-700 border border-gray-200 rounded-full px-2 py-0.5 text-xs font-medium">
                                                {{ $inv->branch->branch_code }}
                                            </span>
                                            <span class="text-[10px] text-gray-400 block mt-0.5">{{ $inv->branch->branch_name }}</span>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="bg-gray-100 rounded-full px-2 py-0.5 text-xs text-gray-700">{{ $invoiceLineCount($inv) }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-700">{{ number_format((float) $invoiceTotalQty($inv), 2) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-gray-900">৳{{ number_format((float) $inv->total_amount, 2) }}</td>
                                    <td class="px-4 py-3 text-center">
                                        @if (!empty($inv->is_soft_hold))
                                            <span class="inline-flex items-center gap-1 bg-amber-100 text-amber-800 border border-amber-300 rounded-full px-2 py-0.5 text-[10px] font-medium" title="Soft-hold: do not dispatch yet">
                                                <i class="fas fa-pause"></i> Hold
                                            </span>
                                        @else
                                            <span class="text-gray-300 text-xs">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <a href="{{ route('admin.sales-challans.godown', $inv->id) }}"
                                           class="inline-flex items-center gap-1.5 bg-amber-500 hover:bg-amber-600 text-white h-8 px-2.5 text-xs rounded-md font-medium transition-colors shadow-sm"
                                           title="Prepare godown copy">
                                            <x-erp.icon name="warehouse" class="size-3.5" />
                                            Prepare Godown
                                            <x-erp.icon name="chevron-right" class="size-3" />
                                        </a>
                                        <a href="{{ route('admin.sales-invoices.show', $inv) }}"
                                           class="inline-flex items-center justify-center bg-white border border-gray-200 hover:bg-amber-50 text-gray-600 hover:text-amber-700 size-8 text-xs rounded-md transition-colors ml-1"
                                           title="View invoice">
                                            <x-erp.icon name="eye" class="size-3.5" />
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center py-12 text-gray-400">
                                        <div class="flex flex-col items-center gap-2">
                                            <div class="size-14 rounded-full bg-amber-50 flex items-center justify-center">
                                                <x-erp.icon name="inbox" class="size-7 text-amber-300" />
                                            </div>
                                            <p class="text-sm font-medium text-gray-500">No invoices awaiting godown prep</p>
                                            <p class="text-xs text-gray-400">New finalized invoices from salesmen will appear here.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ========================================================= --}}
        {{-- TAB 2: Pending Challan Issue                                --}}
        {{-- ========================================================= --}}
        <div class="tab-pane fade {{ $activeTab === 'pending_challan' ? 'show active' : '' }}"
             id="pending-challan" role="tabpanel">

            <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-orange-100">
                {{-- Card header --}}
                <div class="px-6 py-4 border-b border-orange-100 flex items-center justify-between flex-wrap gap-2 bg-gradient-to-r from-orange-50/60 to-amber-50/40">
                    <h3 class="font-semibold text-orange-900 flex items-center gap-2 text-sm">
                        <span class="size-8 rounded-lg bg-orange-100 text-orange-600 flex items-center justify-center">
                            <x-erp.icon name="truck" class="size-4" />
                        </span>
                        Invoices awaiting challan issue
                        <span class="text-xs text-gray-400 font-normal hidden md:inline">/ চালান ইস্যু বাকি</span>
                    </h3>
                    <span class="inline-flex items-center gap-1 bg-orange-100 text-orange-700 rounded-full px-2.5 py-0.5 text-xs font-medium">
                        <x-erp.icon name="list" class="size-3" />
                        {{ $pendingChallan->count() }} shown
                    </span>
                </div>
                <p class="px-6 pt-3 text-xs text-gray-500">
                    Godown prep complete (warehouses assigned). Issue the challan to move stock OUT and post COGS.
                </p>

                {{-- Table with sticky header + custom-scroll --}}
                <div class="max-h-96 overflow-y-auto custom-scroll">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 z-10">
                            <tr class="bg-orange-50/90 backdrop-blur-sm border-b border-orange-200">
                                <th class="px-4 py-3 text-left font-semibold text-orange-800 text-xs uppercase tracking-wide">Invoice</th>
                                <th class="px-4 py-3 text-left font-semibold text-orange-800 text-xs uppercase tracking-wide">Godown Date</th>
                                <th class="px-4 py-3 text-left font-semibold text-orange-800 text-xs uppercase tracking-wide">Customer</th>
                                <th class="px-4 py-3 text-left font-semibold text-orange-800 text-xs uppercase tracking-wide">Branch</th>
                                <th class="px-4 py-3 text-center font-semibold text-orange-800 text-xs uppercase tracking-wide">Lines</th>
                                <th class="px-4 py-3 text-right font-semibold text-orange-800 text-xs uppercase tracking-wide">Qty</th>
                                <th class="px-4 py-3 text-right font-semibold text-orange-800 text-xs uppercase tracking-wide">Amount</th>
                                <th class="px-4 py-3 text-center font-semibold text-orange-800 text-xs uppercase tracking-wide">Hold</th>
                                <th class="px-4 py-3 text-right font-semibold text-orange-800 text-xs uppercase tracking-wide">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($pendingChallan as $inv)
                                <tr class="hover:bg-orange-50/40 border-b border-gray-100 transition-colors">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.sales-invoices.show', $inv) }}" class="font-semibold text-orange-900 hover:text-orange-600 hover:underline">
                                            {{ $inv->invoice_code }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-600 whitespace-nowrap">
                                        @if ($inv->godown_prepared_at)
                                            {{ \Carbon\Carbon::parse($inv->godown_prepared_at)->format('d M Y, H:i') }}
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($inv->customer)
                                            <span class="font-medium text-gray-800">{{ $inv->customer->customer_name }}</span>
                                            <span class="text-xs text-gray-400 block">{{ $inv->customer->customer_code }}</span>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($inv->branch)
                                            <span class="inline-flex items-center gap-1 bg-gray-100 text-gray-700 border border-gray-200 rounded-full px-2 py-0.5 text-xs font-medium">
                                                {{ $inv->branch->branch_code }}
                                            </span>
                                            <span class="text-[10px] text-gray-400 block mt-0.5">{{ $inv->branch->branch_name }}</span>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        <span class="bg-gray-100 rounded-full px-2 py-0.5 text-xs text-gray-700">{{ $invoiceLineCount($inv) }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-700">{{ number_format((float) $invoiceTotalQty($inv), 2) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold text-gray-900">৳{{ number_format((float) $inv->total_amount, 2) }}</td>
                                    <td class="px-4 py-3 text-center">
                                        @if (!empty($inv->is_soft_hold))
                                            <span class="inline-flex items-center gap-1 bg-amber-100 text-amber-800 border border-amber-300 rounded-full px-2 py-0.5 text-[10px] font-medium" title="Soft-hold: do not dispatch yet">
                                                <i class="fas fa-pause"></i> Hold
                                            </span>
                                        @else
                                            <span class="text-gray-300 text-xs">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <a href="{{ route('admin.sales-challans.challan-form', $inv->id) }}"
                                           class="inline-flex items-center gap-1.5 bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white h-8 px-2.5 text-xs rounded-md font-medium transition-colors shadow-md"
                                           title="Issue challan (stock OUT + COGS)">
                                            <x-erp.icon name="truck" class="size-3.5" />
                                            Issue Challan
                                            <x-erp.icon name="chevron-right" class="size-3" />
                                        </a>
                                        <a href="{{ route('admin.sales-invoices.show', $inv) }}"
                                           class="inline-flex items-center justify-center bg-white border border-gray-200 hover:bg-orange-50 text-gray-600 hover:text-orange-700 size-8 text-xs rounded-md transition-colors ml-1"
                                           title="View invoice">
                                            <x-erp.icon name="eye" class="size-3.5" />
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center py-12 text-gray-400">
                                        <div class="flex flex-col items-center gap-2">
                                            <div class="size-14 rounded-full bg-orange-50 flex items-center justify-center">
                                                <x-erp.icon name="inbox" class="size-7 text-orange-300" />
                                            </div>
                                            <p class="text-sm font-medium text-gray-500">No invoices awaiting challan issue</p>
                                            <p class="text-xs text-gray-400">Prepare godown on pending invoices to advance them here.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- ========================================================= --}}
        {{-- TAB 3: Issued Challans (history + pagination)              --}}
        {{-- ========================================================= --}}
        <div class="tab-pane fade {{ $activeTab === 'issued' ? 'show active' : '' }}"
             id="issued" role="tabpanel">

            <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-green-100">
                {{-- Card header --}}
                <div class="px-6 py-4 border-b border-green-100 flex items-center justify-between flex-wrap gap-2 bg-gradient-to-r from-green-50/60 to-amber-50/40">
                    <h3 class="font-semibold text-green-900 flex items-center gap-2 text-sm">
                        <span class="size-8 rounded-lg bg-green-100 text-green-600 flex items-center justify-center">
                            <x-erp.icon name="check-circle" class="size-4" />
                        </span>
                        Issued challans history
                        <span class="text-xs text-gray-400 font-normal hidden md:inline">/ ইস্যুকৃত চালান</span>
                    </h3>
                    <span class="inline-flex items-center gap-1 bg-green-100 text-green-700 rounded-full px-2.5 py-0.5 text-xs font-medium">
                        <x-erp.icon name="check-circle" class="size-3" />
                        {{ number_format((int) ($stats['active'] ?? 0)) }} active
                    </span>
                </div>
                <p class="px-6 pt-3 text-xs text-gray-500">
                    Challans that have been issued (stock moved OUT, COGS posted). Filter by date range above.
                </p>

                {{-- Table with sticky header + custom-scroll --}}
                <div class="max-h-96 overflow-y-auto custom-scroll">
                    <table class="w-full text-sm" id="dataTable">
                        <thead class="sticky top-0 z-10">
                            <tr class="bg-green-50/90 backdrop-blur-sm border-b border-green-200">
                                <th class="px-4 py-3 text-left font-semibold text-green-800 text-xs uppercase tracking-wide">Code</th>
                                <th class="px-4 py-3 text-left font-semibold text-green-800 text-xs uppercase tracking-wide">Date</th>
                                <th class="px-4 py-3 text-left font-semibold text-green-800 text-xs uppercase tracking-wide">Invoice</th>
                                <th class="px-4 py-3 text-left font-semibold text-green-800 text-xs uppercase tracking-wide">Customer</th>
                                <th class="px-4 py-3 text-left font-semibold text-green-800 text-xs uppercase tracking-wide">Branch</th>
                                <th class="px-4 py-3 text-right font-semibold text-green-800 text-xs uppercase tracking-wide">COGS</th>
                                <th class="px-4 py-3 text-left font-semibold text-green-800 text-xs uppercase tracking-wide">Transport</th>
                                <th class="px-4 py-3 text-center font-semibold text-green-800 text-xs uppercase tracking-wide">Status</th>
                                <th class="px-4 py-3 text-right font-semibold text-green-800 text-xs uppercase tracking-wide">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($challans as $ch)
                                <tr class="hover:bg-green-50/40 border-b border-gray-100 transition-colors {{ $ch->is_reversed ? 'bg-red-50/30' : '' }}">
                                    <td class="px-4 py-3">
                                        <a href="{{ route('admin.sales-challans.show', $ch) }}" class="font-semibold text-green-900 hover:text-green-600 hover:underline">
                                            {{ $ch->challan_code }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-600 whitespace-nowrap">
                                        {{ \Carbon\Carbon::parse($ch->challan_date)->format('d M Y') }}
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($ch->salesInvoice)
                                            <a href="{{ route('admin.sales-invoices.show', $ch->salesInvoice) }}" class="text-amber-700 hover:text-amber-900 hover:underline font-medium">
                                                {{ $ch->salesInvoice->invoice_code }}
                                            </a>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        @if ($ch->salesInvoice && $ch->salesInvoice->customer)
                                            <span class="font-medium text-gray-800">{{ $ch->salesInvoice->customer->customer_name }}</span>
                                            <span class="text-xs text-gray-400 block">{{ $ch->salesInvoice->customer->customer_code }}</span>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-xs text-gray-700">
                                        @if ($ch->branch)
                                            {{ $ch->branch->branch_name }}
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold text-gray-900">৳{{ number_format((float) $ch->issue_cost, 2) }}</td>
                                    <td class="px-4 py-3 text-xs">
                                        @if (!empty($ch->transport_name) || !empty($ch->vehicle_number))
                                            @if (!empty($ch->transport_name))
                                                <div class="flex items-center gap-1 text-gray-700">
                                                    <x-erp.icon name="truck" class="size-3 text-gray-400" />
                                                    {{ $ch->transport_name }}
                                                </div>
                                            @endif
                                            @if (!empty($ch->vehicle_number))
                                                <div class="flex items-center gap-1 text-gray-500 mt-0.5">
                                                    <x-erp.icon name="map-pin" class="size-3 text-gray-400" />
                                                    {{ $ch->vehicle_number }}
                                                </div>
                                            @endif
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-center">
                                        @if ($ch->is_reversed)
                                            <span class="inline-flex items-center gap-1 bg-red-100 text-red-700 border border-red-300 rounded-full px-2 py-0.5 text-[10px] font-medium">
                                                <x-erp.icon name="rotate-ccw" class="size-3" />
                                                Reversed
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 bg-green-100 text-green-700 border border-green-300 rounded-full px-2 py-0.5 text-[10px] font-medium">
                                                <x-erp.icon name="check-circle" class="size-3" />
                                                Active
                                            </span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <a href="{{ route('admin.sales-challans.show', $ch) }}"
                                           class="inline-flex items-center gap-1.5 bg-white border border-green-300 text-green-700 hover:bg-green-50 h-8 px-2.5 text-xs rounded-md font-medium transition-colors"
                                           title="View challan">
                                            <x-erp.icon name="eye" class="size-3.5" />
                                            View
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center py-12 text-gray-400">
                                        <div class="flex flex-col items-center gap-2">
                                            <div class="size-14 rounded-full bg-green-50 flex items-center justify-center">
                                                <x-erp.icon name="inbox" class="size-7 text-green-300" />
                                            </div>
                                            <p class="text-sm font-medium text-gray-500">No issued challans found</p>
                                            <p class="text-xs text-gray-400">Try adjusting the filters above.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Pagination --}}
                @if ($challans->hasPages())
                    <div class="px-6 py-3 border-t border-amber-100 bg-amber-50/30">
                        {{ $challans->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

</x-layouts.erp>

@push('scripts')
<script>
$(function () {
    // DataTables only on the issued-challans table (large history).
    if ($('#dataTable').length) {
        $('#dataTable').DataTable({
            paging: false,
            info: false,
            ordering: true,
            dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
            language: { search: 'Filter issued:', emptyTable: 'No issued challans on this page.' }
        });
    }

    // Tab persistence: when user clicks a tab, update the hidden ?tab= input
    // and the URL so refresh keeps them on the same tab.
    window.switchTab = function (tabName) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabName);
        window.history.replaceState({}, '', url.toString());
        // Also update the hidden input in the filter form so a filter submit
        // preserves the active tab.
        $('input[name="tab"]').val(tabName);
    };

    // CSV export: pass current filter params to export URL
    $('#csvExportBtn').on('click', function(e) {
        e.preventDefault();
        const params = new URLSearchParams();
        const fields = ['from_date', 'to_date', 'branch_id', 'search'];
        fields.forEach(f => {
            const val = $(`[name="${f}"]`).val();
            if (val && val !== '') params.set(f, val);
        });
        window.open($(this).attr('href') + '?' + params.toString(), '_blank');
    });
});
</script>
@endpush

<x-layouts.erp :title="$title ?? 'Challans'" :hero="true">
@php
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

    $branchName = session('branch_name', auth()->user()?->employee?->branch?->branch_name ?? 'Branch');
@endphp

<style>
    .sc-app { font-size: 0.875rem; }

    /* Hero */
    .sc-hero {
        display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;
        padding: 0.75rem 1rem; border-radius: 0.75rem;
        background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%);
        color: #fff; margin-bottom: 0.75rem;
    }
    .sc-hero h1 { font-size: 1.125rem; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
    .sc-hero-sub { font-size: 0.7rem; opacity: 0.85; margin-top: 0.125rem; }
    .sc-hero-actions { display: flex; align-items: center; gap: 0.375rem; flex-wrap: wrap; }
    .sc-hero-actions .btn { font-size: 0.7rem; padding: 0.25rem 0.625rem; }

    /* Journey */
    .sc-journey { display: flex; align-items: center; gap: 0.25rem; margin-top: 0.375rem; }
    .sc-journey-step {
        display: inline-flex; align-items: center; gap: 0.25rem;
        font-size: 0.625rem; font-weight: 600; opacity: 0.6;
        padding: 0.125rem 0.5rem; border-radius: 9999px; background: rgba(255,255,255,0.15);
    }
    .sc-journey-step.is-active { opacity: 1; background: rgba(255,255,255,0.3); }
    .sc-journey-arrow { font-size: 0.5rem; opacity: 0.5; }

    /* Status chips */
    .sc-chips { display: flex; flex-wrap: wrap; gap: 0.375rem; margin-bottom: 0.75rem; }
    .sc-chip {
        display: inline-flex; align-items: center; gap: 0.375rem;
        padding: 0.3rem 0.75rem; border-radius: 9999px; font-size: 0.7rem; font-weight: 600;
        border: 1.5px solid transparent; cursor: pointer; transition: all 0.15s;
        background: #fff; color: #78716c;
    }
    .sc-chip:hover { box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
    .sc-chip .chip-count {
        font-size: 0.6rem; font-weight: 800; padding: 0 0.375rem; border-radius: 9999px;
        min-width: 1.25rem; text-align: center; line-height: 1.4;
    }
    .sc-chip-chip-amber { border-color: #fbbf24; color: #92400e; }
    .sc-chip-chip-amber .chip-count { background: #fef3c7; color: #92400e; }
    .sc-chip-chip-amber.active { background: #f59e0b; color: #fff; border-color: #f59e0b; }
    .sc-chip-chip-amber.active .chip-count { background: rgba(255,255,255,0.3); color: #fff; }
    .sc-chip-chip-orange { border-color: #fb923c; color: #9a3412; }
    .sc-chip-chip-orange .chip-count { background: #ffedd5; color: #9a3412; }
    .sc-chip-chip-orange.active { background: #f97316; color: #fff; border-color: #f97316; }
    .sc-chip-chip-orange.active .chip-count { background: rgba(255,255,255,0.3); color: #fff; }
    .sc-chip-chip-green { border-color: #4ade80; color: #166534; }
    .sc-chip-chip-green .chip-count { background: #dcfce7; color: #166534; }
    .sc-chip-chip-green.active { background: #16a34a; color: #fff; border-color: #16a34a; }
    .sc-chip-chip-green.active .chip-count { background: rgba(255,255,255,0.3); color: #fff; }

    /* Results card */
    .sc-card {
        background: #fff; border-radius: 0.5rem; border: 1px solid #e5e7eb;
        overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.04);
    }
    .sc-card-head {
        display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem;
        padding: 0.5rem 0.75rem; border-bottom: 1px solid #f3f4f6; background: #fafaf9;
    }
    .sc-card-title { font-size: 0.75rem; font-weight: 600; color: #44403c; display: flex; align-items: center; gap: 0.375rem; }
    .sc-card-meta { font-size: 0.65rem; color: #9ca3af; }

    /* Table */
    .sc-table { width: 100%; font-size: 0.75rem; border-collapse: collapse; }
    .sc-table thead th {
        padding: 0.375rem 0.5rem; font-size: 0.625rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.05em; color: #78716c;
        background: #f9fafb; border-bottom: 1px solid #e5e7eb; white-space: nowrap;
        position: sticky; top: 0; z-index: 2;
    }
    .sc-table thead.thead-amber th { color: #92400e; background: #fffbeb; border-bottom-color: #fde68a; }
    .sc-table thead.thead-orange th { color: #9a3412; background: #fff7ed; border-bottom-color: #fed7aa; }
    .sc-table thead.thead-green th { color: #166534; background: #f0fdf4; border-bottom-color: #bbf7d0; }
    .sc-table tbody td { padding: 0.375rem 0.5rem; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
    .sc-table tbody tr:hover { background: #fefce8; }
    .sc-table tbody tr.row-orange:hover { background: #fff7ed; }
    .sc-table tbody tr.row-green:hover { background: #f0fdf4; }
    .sc-table tbody tr.row-reversed { background: #fef2f2; }
    .sc-table .text-link { font-weight: 600; color: #b45309; text-decoration: none; }
    .sc-table .text-link:hover { color: #92400e; text-decoration: underline; }
    .sc-table .text-link-orange { font-weight: 600; color: #c2410c; text-decoration: none; }
    .sc-table .text-link-orange:hover { color: #9a3412; text-decoration: underline; }
    .sc-table .text-link-green { font-weight: 600; color: #15803d; text-decoration: none; }
    .sc-table .text-link-green:hover { color: #166534; text-decoration: underline; }

    /* Badges */
    .sc-badge {
        display: inline-flex; align-items: center; gap: 0.25rem;
        padding: 0.1rem 0.4rem; border-radius: 9999px; font-size: 0.6rem; font-weight: 600;
        border: 1px solid transparent;
    }
    .sc-badge-amber { background: #fef3c7; color: #92400e; border-color: #fde68a; }
    .sc-badge-green { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
    .sc-badge-red { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
    .sc-badge-gray { background: #f3f4f6; color: #6b7280; border-color: #e5e7eb; }
    .sc-badge-branch { background: #f3f4f6; color: #4b5563; border-color: #d1d5db; }

    /* Action buttons */
    .sc-btn {
        display: inline-flex; align-items: center; gap: 0.25rem;
        padding: 0.25rem 0.5rem; border-radius: 0.375rem; font-size: 0.65rem; font-weight: 600;
        transition: all 0.15s; text-decoration: none; white-space: nowrap;
    }
    .sc-btn-amber { background: #f59e0b; color: #fff; box-shadow: 0 1px 2px rgba(245,158,11,0.3); }
    .sc-btn-amber:hover { background: #d97706; color: #fff; }
    .sc-btn-orange { background: linear-gradient(135deg, #f59e0b, #f97316); color: #fff; box-shadow: 0 1px 3px rgba(249,115,22,0.3); }
    .sc-btn-orange:hover { background: linear-gradient(135deg, #d97706, #ea580c); color: #fff; }
    .sc-btn-indigo { background: #6366f1; color: #fff; box-shadow: 0 1px 2px rgba(99,102,241,0.3); }
    .sc-btn-indigo:hover { background: #4f46e5; color: #fff; }
    .sc-btn-ghost { background: #fff; color: #6b7280; border: 1px solid #e5e7eb; }
    .sc-btn-ghost:hover { background: #f9fafb; color: #b45309; }
    .sc-btn-outline-green { background: #fff; color: #16a34a; border: 1px solid #86efac; }
    .sc-btn-outline-green:hover { background: #f0fdf4; color: #15803d; }

    /* Empty state */
    .sc-empty { text-align: center; padding: 2rem 1rem; color: #9ca3af; }
    .sc-empty-icon { font-size: 1.5rem; margin-bottom: 0.375rem; opacity: 0.4; }
    .sc-empty-text { font-size: 0.75rem; font-weight: 500; color: #6b7280; }
    .sc-empty-sub { font-size: 0.65rem; color: #9ca3af; }

    /* Scroll container */
    .sc-scroll { max-height: 24rem; overflow-y: auto; }

    /* Pagination */
    .sc-pagination { padding: 0.5rem 0.75rem; border-top: 1px solid #f3f4f6; background: #fafaf9; }

    /* Mobile card fallback */
    .sc-mobile-cards { display: none; }
    @media (max-width: 767.98px) {
        .sc-table { display: none; }
        .sc-scroll { max-height: none; overflow: visible; }
        .sc-mobile-cards { display: block; }
        .sc-mobile-card {
            background: #fff; border: 1px solid #e5e7eb; border-radius: 0.5rem;
            padding: 0.625rem; margin-bottom: 0.5rem; font-size: 0.75rem;
        }
        .sc-mobile-card-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.375rem; }
        .sc-mobile-card-row { display: flex; justify-content: space-between; padding: 0.125rem 0; font-size: 0.7rem; }
        .sc-mobile-card-row .label { color: #9ca3af; }
        .sc-mobile-card-actions { display: flex; gap: 0.375rem; margin-top: 0.375rem; flex-wrap: wrap; }
    }

    @media (max-width: 575.98px) {
        .sc-hero { padding: 0.5rem 0.75rem; }
        .sc-hero h1 { font-size: 1rem; }
        .sc-journey { display: none; }
    }
</style>

<div class="sc-app">

    {{-- Hero header --}}
    <div class="sc-hero">
        <div>
            <h1><i class="fas fa-truck-ramp-box"></i> {{ $title }}</h1>
            <div class="sc-hero-sub">গোডাউন ও চালান · <i class="fas fa-map-marker-alt"></i> {{ e($branchName) }}</div>
            <div class="sc-journey">
                <span class="sc-journey-step is-active"><span>1</span> Godown</span>
                <i class="fas fa-chevron-right sc-journey-arrow"></i>
                <span class="sc-journey-step"><span>2</span> Challan</span>
                <i class="fas fa-chevron-right sc-journey-arrow"></i>
                <span class="sc-journey-step"><span>3</span> Issued</span>
            </div>
        </div>
        <div class="sc-hero-actions">
            <a href="{{ route('admin.sales-invoices.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-file-text me-1"></i>Invoices
            </a>
            <a href="{{ route('admin.sales-challans.export-csv') }}" id="csvExportBtn" target="_blank" class="btn btn-light btn-sm" title="Export CSV">
                <i class="fas fa-file-csv me-1"></i>Export
            </a>
        </div>
    </div>

    {{-- Status filter chips --}}
    <div class="sc-chips">
        @php
            $chipDefs = [
                'pending_godown'  => ['label' => 'Pending Godown', 'icon' => 'fa-warehouse', 'count' => (int) ($stats['pending_godown'] ?? 0), 'color' => 'amber'],
                'pending_challan' => ['label' => 'Pending Challan', 'icon' => 'fa-truck', 'count' => (int) ($stats['pending_challan'] ?? 0), 'color' => 'orange'],
                'issued'          => ['label' => 'Issued Challans', 'icon' => 'fa-check-circle', 'count' => (int) ($stats['active'] ?? 0), 'color' => 'green'],
            ];
        @endphp

        <ul class="nav nav-tabs border-0 d-flex flex-wrap gap-2" id="challanTabs" role="tablist" style="gap:0.375rem">
            @foreach ($chipDefs as $tabKey => $chip)
                <li class="nav-item" role="presentation">
                    <button type="button" role="tab"
                            class="nav-link sc-chip sc-chip-chip-{{ $chip['color'] }} {{ $activeTab === $tabKey ? 'active' : '' }}"
                            id="{{ str_replace('_', '-', $tabKey) }}-tab"
                            data-bs-toggle="tab"
                            data-bs-target="#{{ str_replace('_', '-', $tabKey) }}"
                            onclick="switchTab('{{ $tabKey }}')"
                            aria-selected="{{ $activeTab === $tabKey ? 'true' : 'false' }}">
                        <i class="fas {{ $chip['icon'] }}" style="font-size:0.6rem"></i>
                        <span>{{ $chip['label'] }}</span>
                        <span class="chip-count">{{ number_format($chip['count']) }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    </div>

    {{-- Tab content --}}
    <div class="tab-content">

        {{-- TAB 1: Pending Godown --}}
        <div class="tab-pane fade {{ $activeTab === 'pending_godown' ? 'show active' : '' }}" id="pending-godown" role="tabpanel">
            <div class="sc-card">
                <div class="sc-card-head">
                    <span class="sc-card-title"><i class="fas fa-warehouse text-amber-500"></i> Awaiting godown prep <span class="sc-card-meta">· Step 1: print blank → Step 2: assign warehouse</span></span>
                    <span class="sc-badge sc-badge-amber">{{ $pendingGodown->count() }} invoices</span>
                </div>
                <div class="sc-scroll">
                    <table class="sc-table">
                        <thead class="thead-amber">
                            <tr>
                                <th>Invoice</th><th>Date</th><th>Customer</th><th>Branch</th>
                                <th class="text-center">Lines</th><th class="text-end">Qty</th><th class="text-end">Amount</th>
                                <th class="text-center">Hold</th><th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($pendingGodown as $inv)
                                <tr>
                                    <td><a href="{{ route('admin.sales-invoices.show', $inv) }}" class="text-link">{{ $inv->invoice_code }}</a></td>
                                    <td class="text-xs text-gray-500 whitespace-nowrap">{{ \Carbon\Carbon::parse($inv->invoice_date)->format('d M') }}</td>
                                    <td>
                                        @if ($inv->customer)
                                            <span class="font-medium text-gray-800">{{ $inv->customer->customer_name }}</span>
                                        @else <span class="text-gray-300">—</span> @endif
                                    </td>
                                    <td>
                                        @if ($inv->branch)
                                            <span class="sc-badge sc-badge-branch">{{ $inv->branch->branch_code }}</span>
                                        @else <span class="text-gray-300">—</span> @endif
                                    </td>
                                    <td class="text-center"><span class="sc-badge sc-badge-gray">{{ $invoiceLineCount($inv) }}</span></td>
                                    <td class="text-end text-gray-700">{{ number_format((float) $invoiceTotalQty($inv), 2) }}</td>
                                    <td class="text-end font-semibold text-gray-900">৳{{ number_format((float) $inv->total_amount, 2) }}</td>
                                    <td class="text-center">
                                        @if (!empty($inv->is_soft_hold))
                                            <span class="sc-badge sc-badge-amber"><i class="fas fa-pause" style="font-size:0.5rem"></i> Hold</span>
                                        @else <span class="text-gray-300">—</span> @endif
                                    </td>
                                    <td class="text-end whitespace-nowrap">
                                        @if (!empty($inv->is_blank_godown_printed))
                                            <a href="{{ route('admin.sales-challans.godown', $inv->id) }}" class="sc-btn sc-btn-amber" title="Prepare godown (Step 2)">
                                                <i class="fas fa-warehouse"></i> Godown
                                            </a>
                                            <span class="sc-badge sc-badge-green" title="Blank printed"><i class="fas fa-check" style="font-size:0.45rem"></i></span>
                                        @else
                                            <a href="{{ route('admin.sales-challans.blank-godown-form', $inv->id) }}" class="sc-btn sc-btn-indigo" title="Print blank godown (Step 1)">
                                                <i class="fas fa-print"></i> Print
                                            </a>
                                        @endif
                                        <a href="{{ route('admin.sales-invoices.show', $inv) }}" class="sc-btn sc-btn-ghost" title="View invoice"><i class="fas fa-eye"></i></a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="sc-empty">
                                    <div class="sc-empty-icon"><i class="fas fa-inbox"></i></div>
                                    <div class="sc-empty-text">No invoices awaiting godown prep</div>
                                    <div class="sc-empty-sub">New finalized invoices will appear here</div>
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{-- Mobile cards --}}
                <div class="sc-mobile-cards" style="padding:0.5rem">
                    @forelse ($pendingGodown as $inv)
                        <div class="sc-mobile-card">
                            <div class="sc-mobile-card-head">
                                <a href="{{ route('admin.sales-invoices.show', $inv) }}" class="text-link font-semibold">{{ $inv->invoice_code }}</a>
                                @if ($inv->branch)<span class="sc-badge sc-badge-branch">{{ $inv->branch->branch_code }}</span>@endif
                            </div>
                            @if ($inv->customer)<div class="sc-mobile-card-row"><span class="label">Customer</span><span>{{ $inv->customer->customer_name }}</span></div>@endif
                            <div class="sc-mobile-card-row"><span class="label">Amount</span><span class="font-semibold">৳{{ number_format((float) $inv->total_amount, 2) }}</span></div>
                            <div class="sc-mobile-card-actions">
                                @if (!empty($inv->is_blank_godown_printed))
                                    <a href="{{ route('admin.sales-challans.godown', $inv->id) }}" class="sc-btn sc-btn-amber"><i class="fas fa-warehouse"></i> Godown</a>
                                @else
                                    <a href="{{ route('admin.sales-challans.blank-godown-form', $inv->id) }}" class="sc-btn sc-btn-indigo"><i class="fas fa-print"></i> Print</a>
                                @endif
                                <a href="{{ route('admin.sales-invoices.show', $inv) }}" class="sc-btn sc-btn-ghost"><i class="fas fa-eye"></i></a>
                            </div>
                        </div>
                    @empty
                        <div class="sc-empty"><div class="sc-empty-icon"><i class="fas fa-inbox"></i></div><div class="sc-empty-text">No invoices awaiting godown prep</div></div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- TAB 2: Pending Challan Issue --}}
        <div class="tab-pane fade {{ $activeTab === 'pending_challan' ? 'show active' : '' }}" id="pending-challan" role="tabpanel">
            <div class="sc-card">
                <div class="sc-card-head">
                    <span class="sc-card-title"><i class="fas fa-truck text-orange-500"></i> Awaiting challan issue <span class="sc-card-meta">· Godown done, issue to move stock OUT + COGS</span></span>
                    <span class="sc-badge sc-badge-amber">{{ $pendingChallan->count() }} invoices</span>
                </div>
                <div class="sc-scroll">
                    <table class="sc-table">
                        <thead class="thead-orange">
                            <tr>
                                <th>Invoice</th><th>Godown Date</th><th>Customer</th><th>Branch</th>
                                <th class="text-center">Lines</th><th class="text-end">Qty</th><th class="text-end">Amount</th>
                                <th class="text-center">Hold</th><th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($pendingChallan as $inv)
                                <tr class="row-orange">
                                    <td><a href="{{ route('admin.sales-invoices.show', $inv) }}" class="text-link-orange">{{ $inv->invoice_code }}</a></td>
                                    <td class="text-xs text-gray-500 whitespace-nowrap">
                                        @if ($inv->godown_prepared_at) {{ \Carbon\Carbon::parse($inv->godown_prepared_at)->format('d M, H:i') }}
                                        @else <span class="text-gray-300">—</span> @endif
                                    </td>
                                    <td>
                                        @if ($inv->customer)
                                            <span class="font-medium text-gray-800">{{ $inv->customer->customer_name }}</span>
                                        @else <span class="text-gray-300">—</span> @endif
                                    </td>
                                    <td>
                                        @if ($inv->branch)
                                            <span class="sc-badge sc-badge-branch">{{ $inv->branch->branch_code }}</span>
                                        @else <span class="text-gray-300">—</span> @endif
                                    </td>
                                    <td class="text-center"><span class="sc-badge sc-badge-gray">{{ $invoiceLineCount($inv) }}</span></td>
                                    <td class="text-end text-gray-700">{{ number_format((float) $invoiceTotalQty($inv), 2) }}</td>
                                    <td class="text-end font-semibold text-gray-900">৳{{ number_format((float) $inv->total_amount, 2) }}</td>
                                    <td class="text-center">
                                        @if (!empty($inv->is_soft_hold))
                                            <span class="sc-badge sc-badge-amber"><i class="fas fa-pause" style="font-size:0.5rem"></i> Hold</span>
                                        @else <span class="text-gray-300">—</span> @endif
                                    </td>
                                    <td class="text-end whitespace-nowrap">
                                        <a href="{{ route('admin.sales-challans.challan-form', $inv->id) }}" class="sc-btn sc-btn-orange" title="Issue challan">
                                            <i class="fas fa-truck"></i> Issue
                                        </a>
                                        <a href="{{ route('admin.sales-invoices.show', $inv) }}" class="sc-btn sc-btn-ghost" title="View invoice"><i class="fas fa-eye"></i></a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="sc-empty">
                                    <div class="sc-empty-icon"><i class="fas fa-inbox"></i></div>
                                    <div class="sc-empty-text">No invoices awaiting challan issue</div>
                                    <div class="sc-empty-sub">Prepare godown on pending invoices to advance them</div>
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{-- Mobile cards --}}
                <div class="sc-mobile-cards" style="padding:0.5rem">
                    @forelse ($pendingChallan as $inv)
                        <div class="sc-mobile-card">
                            <div class="sc-mobile-card-head">
                                <a href="{{ route('admin.sales-invoices.show', $inv) }}" class="text-link-orange font-semibold">{{ $inv->invoice_code }}</a>
                                @if ($inv->branch)<span class="sc-badge sc-badge-branch">{{ $inv->branch->branch_code }}</span>@endif
                            </div>
                            @if ($inv->customer)<div class="sc-mobile-card-row"><span class="label">Customer</span><span>{{ $inv->customer->customer_name }}</span></div>@endif
                            <div class="sc-mobile-card-row"><span class="label">Amount</span><span class="font-semibold">৳{{ number_format((float) $inv->total_amount, 2) }}</span></div>
                            <div class="sc-mobile-card-actions">
                                <a href="{{ route('admin.sales-challans.challan-form', $inv->id) }}" class="sc-btn sc-btn-orange"><i class="fas fa-truck"></i> Issue</a>
                                <a href="{{ route('admin.sales-invoices.show', $inv) }}" class="sc-btn sc-btn-ghost"><i class="fas fa-eye"></i></a>
                            </div>
                        </div>
                    @empty
                        <div class="sc-empty"><div class="sc-empty-icon"><i class="fas fa-inbox"></i></div><div class="sc-empty-text">No invoices awaiting challan issue</div></div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- TAB 3: Issued Challans --}}
        <div class="tab-pane fade {{ $activeTab === 'issued' ? 'show active' : '' }}" id="issued" role="tabpanel">
            <div class="sc-card">
                <div class="sc-card-head">
                    <span class="sc-card-title"><i class="fas fa-check-circle text-green-500"></i> Issued challans <span class="sc-card-meta">· Stock moved OUT, COGS posted</span></span>
                    <span class="sc-badge sc-badge-green">{{ number_format((int) ($stats['active'] ?? 0)) }} active</span>
                </div>
                <div class="sc-scroll">
                    <table class="sc-table" id="dataTable">
                        <thead class="thead-green">
                            <tr>
                                <th>Challan</th><th>Date</th><th>Invoice</th><th>Customer</th><th>Branch</th>
                                <th class="text-end">COGS</th><th>Transport</th><th class="text-center">Status</th><th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($challans as $ch)
                                <tr class="row-green {{ $ch->is_reversed ? 'row-reversed' : '' }}">
                                    <td><a href="{{ route('admin.sales-challans.show', $ch) }}" class="text-link-green">{{ $ch->challan_code }}</a></td>
                                    <td class="text-xs text-gray-500 whitespace-nowrap">{{ \Carbon\Carbon::parse($ch->challan_date)->format('d M') }}</td>
                                    <td>
                                        @if ($ch->salesInvoice)
                                            <a href="{{ route('admin.sales-invoices.show', $ch->salesInvoice) }}" class="text-link" style="color:#b45309">{{ $ch->salesInvoice->invoice_code }}</a>
                                        @else <span class="text-gray-300">—</span> @endif
                                    </td>
                                    <td>
                                        @if ($ch->salesInvoice && $ch->salesInvoice->customer)
                                            <span class="font-medium text-gray-800">{{ $ch->salesInvoice->customer->customer_name }}</span>
                                        @else <span class="text-gray-300">—</span> @endif
                                    </td>
                                    <td class="text-xs text-gray-600">
                                        @if ($ch->branch) {{ $ch->branch->branch_name }} @else <span class="text-gray-300">—</span> @endif
                                    </td>
                                    <td class="text-end font-semibold text-gray-900">৳{{ number_format((float) $ch->issue_cost, 2) }}</td>
                                    <td class="text-xs">
                                        @if (!empty($ch->transport_name) || !empty($ch->vehicle_number))
                                            @if (!empty($ch->transport_name)) <span class="text-gray-700"><i class="fas fa-truck text-gray-400" style="font-size:0.55rem"></i> {{ $ch->transport_name }}</span> @endif
                                            @if (!empty($ch->vehicle_number)) <span class="text-gray-500 block" style="font-size:0.6rem"><i class="fas fa-map-pin text-gray-400" style="font-size:0.5rem"></i> {{ $ch->vehicle_number }}</span> @endif
                                        @else <span class="text-gray-300">—</span> @endif
                                    </td>
                                    <td class="text-center">
                                        @if ($ch->is_reversed)
                                            <span class="sc-badge sc-badge-red"><i class="fas fa-rotate-ccw" style="font-size:0.5rem"></i> Reversed</span>
                                        @else
                                            <span class="sc-badge sc-badge-green"><i class="fas fa-check-circle" style="font-size:0.5rem"></i> Active</span>
                                        @endif
                                    </td>
                                    <td class="text-end whitespace-nowrap">
                                        <a href="{{ route('admin.sales-challans.show', $ch) }}" class="sc-btn sc-btn-outline-green" title="View challan"><i class="fas fa-eye"></i> View</a>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="sc-empty">
                                    <div class="sc-empty-icon"><i class="fas fa-inbox"></i></div>
                                    <div class="sc-empty-text">No issued challans found</div>
                                    <div class="sc-empty-sub">Try adjusting the filters</div>
                                </td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {{-- Mobile cards --}}
                <div class="sc-mobile-cards" style="padding:0.5rem">
                    @forelse ($challans as $ch)
                        <div class="sc-mobile-card" style="{{ $ch->is_reversed ? 'background:#fef2f2' : '' }}">
                            <div class="sc-mobile-card-head">
                                <a href="{{ route('admin.sales-challans.show', $ch) }}" class="text-link-green font-semibold">{{ $ch->challan_code }}</a>
                                @if ($ch->is_reversed)<span class="sc-badge sc-badge-red">Reversed</span>@else<span class="sc-badge sc-badge-green">Active</span>@endif
                            </div>
                            @if ($ch->salesInvoice)<div class="sc-mobile-card-row"><span class="label">Invoice</span><a href="{{ route('admin.sales-invoices.show', $ch->salesInvoice) }}" class="text-link">{{ $ch->salesInvoice->invoice_code }}</a></div>@endif
                            @if ($ch->salesInvoice && $ch->salesInvoice->customer)<div class="sc-mobile-card-row"><span class="label">Customer</span><span>{{ $ch->salesInvoice->customer->customer_name }}</span></div>@endif
                            <div class="sc-mobile-card-row"><span class="label">COGS</span><span class="font-semibold">৳{{ number_format((float) $ch->issue_cost, 2) }}</span></div>
                            <div class="sc-mobile-card-actions">
                                <a href="{{ route('admin.sales-challans.show', $ch) }}" class="sc-btn sc-btn-outline-green"><i class="fas fa-eye"></i> View</a>
                            </div>
                        </div>
                    @empty
                        <div class="sc-empty"><div class="sc-empty-icon"><i class="fas fa-inbox"></i></div><div class="sc-empty-text">No issued challans found</div></div>
                    @endforelse
                </div>
                @if ($challans->hasPages())
                    <div class="sc-pagination">{{ $challans->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>

</x-layouts.erp>

@push('scripts')
<script>
$(function () {
    if ($('#dataTable').length) {
        $('#dataTable').DataTable({
            paging: false, info: false, ordering: true,
            dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
            language: { search: 'Filter:', emptyTable: 'No issued challans on this page.' }
        });
    }

    window.switchTab = function (tabName) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tabName);
        window.history.replaceState({}, '', url.toString());
        $('input[name="tab"]').val(tabName);
    };

    $('#csvExportBtn').on('click', function(e) {
        e.preventDefault();
        const params = new URLSearchParams();
        ['from_date', 'to_date', 'branch_id', 'search'].forEach(f => {
            const val = $(`[name="${f}"]`).val();
            if (val && val !== '') params.set(f, val);
        });
        window.open($(this).attr('href') + '?' + params.toString(), '_blank');
    });
});
</script>
@endpush

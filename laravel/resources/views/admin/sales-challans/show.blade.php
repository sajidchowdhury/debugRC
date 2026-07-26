<x-layouts.erp :title="'Challan ' . $challan->challan_code" :tabs="[
    ['label' => 'Dashboard', 'href' => route('dashboard')],
    ['label' => 'Invoices', 'href' => route('admin.sales-invoices.index')],
    ['label' => 'Challans', 'href' => route('admin.sales-challans.index'), 'active' => true],
    ['label' => 'UI Preview', 'href' => route('ui-preview')],
]">
@php
    $challan = $challan ?? null;
    $stockMovements = $stockMovements ?? collect();

    // Status badge helper
    $statusBadge = function (bool $large = false) use ($challan): string {
        $cls = $large ? ' fs-5' : ' fs-6';
        if ($challan->is_reversed) {
            return '<span class="badge bg-danger-subtle text-danger' . $cls . '">'
                . '<i class="fas fa-rotate-left me-1"></i>Reversed</span>';
        }
        return '<span class="badge bg-success-subtle text-success' . $cls . '">'
            . '<i class="fas fa-circle-check me-1"></i>Active</span>';
    };

    // GL journal lines totals
    $glDebitTotal = 0.0;
    $glCreditTotal = 0.0;
    if ($challan->journalEntry && $challan->journalEntry->lines) {
        foreach ($challan->journalEntry->lines as $line) {
            $glDebitTotal  += (float) $line->debit;
            $glCreditTotal += (float) $line->credit;
        }
    }

    // Reverse-by user name lookup (best effort)
    $reversedByName = null;
    if ($challan->reversed_by) {
        $u = \App\Models\Employee::find($challan->reversed_by);
        if ($u) {
            $reversedByName = $u->name ?? ('Employee #' . $challan->reversed_by);
        } else {
            $reversedByName = 'User #' . $challan->reversed_by;
        }
    }

    // Created-by user name lookup (best effort)
    $createdByName = null;
    if ($challan->created_by) {
        $u = \App\Models\Employee::find($challan->created_by);
        if ($u) {
            $createdByName = $u->name ?? ('Employee #' . $challan->created_by);
        } else {
            $createdByName = 'User #' . $challan->created_by;
        }
    }

    $inv = $challan->salesInvoice;
    $cogsTotal = (float) ($challan->issue_cost ?? 0);
@endphp

@php
    // Eager-load the challan's own line items (COGS rows) so the breakdown table
    // below doesn't trigger N+1 queries when it touches ->product / ->warehouse.
    $challan->loadMissing(['items.product', 'items.warehouse']);
@endphp

<div class="space-y-6">

    {{-- Breadcrumb --}}
    <nav aria-label="Breadcrumb" class="text-xs text-gray-500 flex items-center gap-1.5 flex-wrap">
        <a href="{{ route('dashboard') }}" class="hover:text-amber-700 transition-colors">Sales</a>
        <x-erp.icon name="chevron-right" class="size-3 text-gray-400" />
        <a href="{{ route('admin.sales-challans.index') }}" class="hover:text-amber-700 transition-colors">Challan</a>
        <x-erp.icon name="chevron-right" class="size-3 text-gray-400" />
        <span class="text-amber-800 font-medium">{{ $challan->challan_code }}</span>
    </nav>

    {{-- Hero header (amber/orange gradient — showcase spec) --}}
    <div class="bg-gradient-to-r from-amber-500 via-amber-600 to-orange-500 rounded-xl p-6 shadow-lg">
        <div class="flex items-start justify-between flex-wrap gap-4">
            <div class="flex items-start gap-4">
                <div class="bg-white/20 backdrop-blur-sm rounded-xl size-14 flex items-center justify-center text-white text-2xl shrink-0">
                    <i class="fas fa-truck-fast"></i>
                </div>
                <div>
                    <p class="text-amber-100 text-xs font-medium uppercase tracking-wider">চালানপত্র / Delivery Challan</p>
                    <div class="flex items-center gap-3 flex-wrap mt-1">
                        <h1 class="text-2xl font-bold text-white">Challan {{ $challan->challan_code }}</h1>
                        {!! $statusBadge() !!}
                    </div>
                    <p class="text-amber-100 text-sm mt-1.5 flex items-center gap-2 flex-wrap">
                        @if ($inv)
                            <a href="{{ route('admin.sales-invoices.show', $inv) }}" class="text-white hover:underline font-medium">{{ $inv->invoice_code }}</a>
                        @endif
                        @if ($inv && $inv->customer)
                            <span class="text-amber-200">·</span><span>{{ $inv->customer->customer_name }}</span>
                        @endif
                        @if ($challan->branch)
                            <span class="text-amber-200">·</span><span>{{ $challan->branch->branch_name }}</span>
                        @endif
                        <span class="text-amber-200">·</span>
                        <span><i class="far fa-calendar me-1"></i>{{ \Carbon\Carbon::parse($challan->challan_date)->format('d M Y') }}</span>
                    </p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('admin.sales-challans.print-challan', $challan->id) }}" target="_blank"
                   class="inline-flex items-center gap-1.5 bg-white text-amber-700 hover:bg-amber-50 rounded-lg px-3 py-2 text-xs font-semibold transition-colors shadow-sm">
                    <i class="fas fa-print"></i> Print Challan / প্রিন্ট
                </a>
                <a href="{{ route('admin.sales-challans.index') }}"
                   class="inline-flex items-center gap-1.5 bg-white/20 backdrop-blur-sm hover:bg-white/30 text-white rounded-lg px-3 py-2 text-xs font-medium transition-colors">
                    <i class="fas fa-arrow-left"></i> Back / ফিরে
                </a>
            </div>
        </div>

        {{-- 4-step workflow indicator (Phase 1: uses <x-erp.journey-stepper>; Receipt = step 4, all done) --}}
        <x-erp.journey-stepper :current="4" />
    </div>

    {{-- Phase 9: Print Center — centralized print access (Challan / Godown / Invoice) --}}
    <div class="bg-white border border-amber-200 rounded-xl shadow-sm overflow-hidden no-print">
        <div class="bg-gradient-to-r from-amber-50 to-orange-50 border-b border-amber-100 px-5 py-3 flex items-center gap-3">
            <div class="size-9 rounded-lg bg-amber-500 text-white flex items-center justify-center shrink-0">
                <i class="fas fa-print"></i>
            </div>
            <div class="min-w-0">
                <p class="font-semibold text-amber-900 text-sm">Print Center / প্রিন্ট সেন্টার</p>
                <p class="text-xs text-amber-700">Open a printable copy in a new tab.</p>
            </div>
        </div>
        <div class="p-5">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <x-erp.outline-button icon="printer" href="{{ route('admin.sales-challans.print-challan', $challan->id) }}" target="_blank" rel="noopener" aria-label="Print challan — opens in a new tab" class="w-full justify-center !border-amber-300 !text-amber-800 hover:!bg-amber-50 min-h-[44px]">
                    Print Challan / চালান
                </x-erp.outline-button>
                @if ($inv)
                    <x-erp.outline-button icon="printer" href="{{ route('admin.sales-invoices.print-godown', $inv->id) }}" target="_blank" rel="noopener" aria-label="Print godown copy — opens in a new tab" class="w-full justify-center !border-cyan-300 !text-cyan-800 hover:!bg-cyan-50 min-h-[44px]">
                        Print Godown Copy / গোডাউন
                    </x-erp.outline-button>
                    <x-erp.outline-button icon="printer" href="{{ route('admin.sales-invoices.print-invoice', $inv->id) }}" target="_blank" rel="noopener" aria-label="Print invoice copy — opens in a new tab" class="w-full justify-center !border-gray-300 !text-gray-800 hover:!bg-gray-50 min-h-[44px]">
                        Print Invoice Copy / ইনভয়েস
                    </x-erp.outline-button>
                @else
                    <div class="sm:col-span-2 inline-flex items-center justify-center gap-2 rounded-lg border border-dashed border-gray-200 px-4 py-2 text-sm text-gray-400" role="status">
                        <i class="fas fa-link-slash" aria-hidden="true"></i>
                        Godown &amp; Invoice copies unavailable — no linked invoice.
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Reversal alert (red callout) --}}
    @if ($challan->is_reversed)
        <div class="bg-red-50 border border-red-200 rounded-xl p-4 flex items-start gap-3">
            <div class="size-9 rounded-full bg-red-100 text-red-600 flex items-center justify-center shrink-0">
                <i class="fas fa-rotate-left"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="font-semibold text-red-800">This challan has been reversed / এই চালানটি বাতিল করা হয়েছে</p>
                <p class="text-sm text-red-700 mt-0.5">Stock movements and the GL journal entry have been reversed.</p>
                <div class="text-xs text-red-600 mt-2 flex items-center gap-3 flex-wrap">
                    @if ($challan->reversed_at)
                        <span><i class="far fa-clock me-1"></i>{{ \Carbon\Carbon::parse($challan->reversed_at)->format('d M Y, H:i') }}</span>
                    @endif
                    @if ($reversedByName)
                        <span><i class="far fa-user me-1"></i>{{ $reversedByName }}</span>
                    @endif
                </div>
                @if (!empty($challan->reverse_reason))
                    <div class="mt-2 text-sm">
                        <span class="text-red-600 font-medium">Reason / কারণ:</span>
                        <em class="text-red-800">{{ $challan->reverse_reason }}</em>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- Info cards row: challan meta, transport details, COGS summary --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Card 1: Challan meta --}}
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-l-amber-500 p-4">
            <div class="pb-2 mb-3 border-b border-amber-100">
                <h3 class="text-base font-medium text-amber-900 flex items-center gap-2">
                    <i class="fas fa-file-circle-check text-amber-600"></i>
                    Challan Details
                </h3>
                <p class="text-xs text-gray-500">চালানের বিস্তারিত</p>
            </div>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-2">
                    <dt class="text-gray-500">Code / কোড</dt>
                    <dd>
                        <span class="bg-amber-50 text-amber-800 border border-amber-200 rounded-full px-2 py-0.5 text-xs font-medium">{{ $challan->challan_code }}</span>
                    </dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-gray-500">Date / তারিখ</dt>
                    <dd class="font-medium">{{ \Carbon\Carbon::parse($challan->challan_date)->format('d M Y') }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-gray-500">Invoice / চালান</dt>
                    <dd class="text-right">
                        @if ($inv)
                            <a href="{{ route('admin.sales-invoices.show', $inv) }}" class="text-amber-700 hover:underline font-medium">{{ $inv->invoice_code }}</a>
                            <div class="text-xs text-gray-400">{{ \Carbon\Carbon::parse($inv->invoice_date)->format('d M Y') }}</div>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-gray-500">Customer / ক্রেতা</dt>
                    <dd class="text-right">
                        @if ($inv && $inv->customer)
                            <div class="font-medium">{{ $inv->customer->customer_name }}</div>
                            <div class="text-xs text-gray-400">{{ $inv->customer->customer_code }}</div>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-gray-500">Branch / শাখা</dt>
                    <dd class="text-right">
                        @if ($challan->branch)
                            <span class="font-medium">{{ $challan->branch->branch_name }}</span>
                            <span class="text-xs text-gray-400">({{ $challan->branch->branch_code }})</span>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </dd>
                </div>
                @if ($createdByName)
                <div class="flex justify-between gap-2">
                    <dt class="text-gray-500">Issued by / ইস্যুকারী</dt>
                    <dd class="font-medium">{{ $createdByName }}</dd>
                </div>
                @endif
            </dl>
        </div>

        {{-- Card 2: Transport details --}}
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-l-orange-500 p-4">
            <div class="pb-2 mb-3 border-b border-orange-100">
                <h3 class="text-base font-medium text-orange-900 flex items-center gap-2">
                    <i class="fas fa-truck text-orange-600"></i>
                    Transport Details
                </h3>
                <p class="text-xs text-gray-500">পরিবহন তথ্য</p>
            </div>
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-2">
                    <dt class="text-gray-500">Transport / পরিবহন</dt>
                    <dd class="font-medium">{{ $challan->transport_name ?: '—' }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-gray-500">Vehicle / গাড়ি</dt>
                    <dd class="font-medium">{{ $challan->vehicle_number ?: '—' }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-gray-500">Driver / ড্রাইভার</dt>
                    <dd class="font-medium">{{ $challan->driver_name ?: '—' }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt class="text-gray-500">Phone / মোবাইল</dt>
                    <dd class="font-medium">{{ $challan->transport_phone ?: '—' }}</dd>
                </div>
                <div class="flex justify-between gap-2 pt-2 border-t border-orange-50">
                    <dt class="text-gray-500">Transport Cost / পরিবহন খরচ</dt>
                    <dd class="font-semibold text-orange-700">Tk {{ number_format((float) ($challan->transport_cost ?? 0), 2) }}</dd>
                </div>
            </dl>
        </div>

        {{-- Card 3: COGS summary --}}
        <div class="bg-white rounded-xl shadow-sm border-l-4 border-l-green-500 p-4">
            <div class="pb-2 mb-3 border-b border-green-100">
                <h3 class="text-base font-medium text-green-900 flex items-center gap-2">
                    <i class="fas fa-coins text-green-600"></i>
                    COGS Summary
                </h3>
                <p class="text-xs text-gray-500">ক্রয়মূল্য সারসংক্ষেপ</p>
            </div>
            <div class="space-y-3">
                <div class="bg-green-50 rounded-lg p-3 border border-green-200 text-center">
                    <p class="text-xs text-green-700 font-medium">Total COGS / মোট ক্রয়মূল্য</p>
                    <p class="text-2xl font-bold text-green-800 mt-1">Tk {{ number_format($cogsTotal, 2) }}</p>
                </div>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-2">
                        <dt class="text-gray-500">Challan items</dt>
                        <dd class="font-medium">{{ $challan->items ? $challan->items->count() : 0 }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-gray-500">Stock movements</dt>
                        <dd class="font-medium">{{ $stockMovements->count() }}</dd>
                    </div>
                    @if ($challan->journalEntry)
                    <div class="flex justify-between gap-2">
                        <dt class="text-gray-500">GL Journal</dt>
                        <dd>
                            <span class="bg-gray-100 text-gray-700 rounded-full px-2 py-0.5 text-xs font-medium">{{ $challan->journalEntry->entry_no }}</span>
                        </dd>
                    </div>
                    @endif
                </dl>
            </div>
        </div>
    </div>

    {{-- Challan items (COGS breakdown) table --}}
    @if ($challan->items && $challan->items->isNotEmpty())
        <div class="bg-white rounded-xl shadow-sm overflow-hidden border-l-4 border-l-amber-500">
            <div class="px-4 py-3 border-b border-amber-100 flex items-center justify-between flex-wrap gap-2">
                <div>
                    <h3 class="text-base font-medium flex items-center gap-2">
                        <i class="fas fa-box text-amber-600"></i>
                        Challan Items — COGS Breakdown
                    </h3>
                    <p class="text-xs text-gray-500">চালানের পণ্য ও ক্রয়মূল্য</p>
                </div>
                <span class="bg-amber-50 text-amber-700 border border-amber-200 rounded-full px-2 py-0.5 text-xs font-medium">
                    {{ $challan->items->count() }} item(s)
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-amber-50/50">
                            <th class="px-4 py-3 text-left font-medium w-12">#</th>
                            <th class="px-4 py-3 text-left font-medium">Product / পণ্য</th>
                            <th class="px-4 py-3 text-left font-medium">Warehouse / গোডাউন</th>
                            <th class="px-4 py-3 text-center font-medium">Qty / পরিমাণ</th>
                            <th class="px-4 py-3 text-right font-medium">Rate (Tk)</th>
                            <th class="px-4 py-3 text-right font-medium">COGS (Tk)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($challan->items as $idx => $item)
                            <tr class="hover:bg-amber-50/30 border-b border-gray-100">
                                <td class="px-4 py-3 text-center font-medium text-gray-500">{{ $idx + 1 }}</td>
                                <td class="px-4 py-3">
                                    @if ($item->product)
                                        <div class="font-medium">{{ $item->product->product_name }}</div>
                                        <div class="text-xs text-gray-500">{{ $item->product->product_code }}</div>
                                    @else
                                        <span class="text-gray-400">Product #{{ $item->product_id }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if ($item->warehouse)
                                        <span class="border rounded-full px-2 py-0.5 text-xs inline-flex items-center gap-1">
                                            <i class="fas fa-warehouse text-gray-400"></i>{{ $item->warehouse->warehouse_name }}
                                        </span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-center font-semibold">{{ number_format((float) $item->qty, 2) }}</td>
                                <td class="px-4 py-3 text-right">{{ number_format((float) $item->issue_rate, 2) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-amber-800">
                                    Tk {{ number_format((float) ($item->cogs_amount ?? ((float) $item->qty * (float) $item->issue_rate)), 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-amber-50/40 font-bold">
                            <td class="px-4 py-3 text-right" colspan="5">Total COGS / মোট ক্রয়মূল্য</td>
                            <td class="px-4 py-3 text-right text-amber-800">Tk {{ number_format($cogsTotal, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @endif

    {{-- Two-column section: stock movements + GL journal (left), status & actions (right) --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Left column --}}
        <div class="lg:col-span-2 space-y-4">

            {{-- Stock movements card --}}
            @if ($stockMovements->isNotEmpty())
                <div class="bg-white rounded-xl shadow-sm overflow-hidden border-l-4 border-l-cyan-500">
                    <div class="px-4 py-3 border-b border-cyan-100 flex items-center justify-between flex-wrap gap-2">
                        <div>
                            <h3 class="text-base font-medium flex items-center gap-2">
                                <i class="fas fa-boxes-stacked text-cyan-600"></i>
                                Stock Movements
                            </h3>
                            <p class="text-xs text-gray-500">স্টক মুভমেন্ট — Stock OUT</p>
                        </div>
                        <span class="bg-cyan-50 text-cyan-700 border border-cyan-200 rounded-full px-2 py-0.5 text-xs font-medium">
                            {{ $stockMovements->count() }} tx(s)
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-cyan-50/50">
                                    <th class="px-3 py-2 text-left font-medium">Date</th>
                                    <th class="px-3 py-2 text-left font-medium">TX #</th>
                                    <th class="px-3 py-2 text-left font-medium">Product / পণ্য</th>
                                    <th class="px-3 py-2 text-left font-medium">Warehouse</th>
                                    <th class="px-3 py-2 text-right font-medium">Qty (OUT)</th>
                                    <th class="px-3 py-2 text-right font-medium">Rate</th>
                                    <th class="px-3 py-2 text-right font-medium">Value (Tk)</th>
                                    <th class="px-3 py-2 text-center font-medium">Reversed?</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($stockMovements as $mv)
                                    @php
                                        $mvQty = (float) ($mv->qty ?? 0);
                                        $mvCost = (float) ($mv->avg_cost ?? $mv->unit_cost ?? 0);
                                        $mvValue = abs($mvQty) * $mvCost;
                                        $mvReversed = !empty($mv->is_reversed);
                                    @endphp
                                    <tr class="border-b border-gray-100 {{ $mvReversed ? 'bg-red-50/40' : 'hover:bg-cyan-50/30' }}">
                                        <td class="px-3 py-2 text-xs text-nowrap">
                                            @if (!empty($mv->transaction_date))
                                                {{ \Carbon\Carbon::parse($mv->transaction_date)->format('d M Y') }}
                                            @elseif (!empty($mv->created_at))
                                                {{ \Carbon\Carbon::parse($mv->created_at)->format('d M Y') }}
                                            @else
                                                <span class="text-gray-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 text-xs">
                                            @if (!empty($mv->transaction_no))
                                                <span class="bg-gray-100 text-gray-700 rounded-full px-2 py-0.5">{{ $mv->transaction_no }}</span>
                                            @else
                                                <span class="text-gray-400">#{{ $mv->id }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">
                                            <div class="font-medium">{{ $mv->product_name }}</div>
                                            <div class="text-xs text-gray-500">{{ $mv->product_code }}</div>
                                        </td>
                                        <td class="px-3 py-2 text-xs">{{ $mv->warehouse_name }}</td>
                                        <td class="px-3 py-2 text-right text-red-600 font-semibold">− {{ number_format(abs($mvQty), 2) }}</td>
                                        <td class="px-3 py-2 text-right">{{ number_format($mvCost, 2) }}</td>
                                        <td class="px-3 py-2 text-right font-semibold">{{ number_format($mvValue, 2) }}</td>
                                        <td class="px-3 py-2 text-center">
                                            @if ($mvReversed)
                                                <span class="bg-red-100 text-red-700 border border-red-300 rounded-full px-2 py-0.5 text-xs">
                                                    <i class="fas fa-rotate-left me-0.5"></i>Yes
                                                </span>
                                            @else
                                                <span class="text-gray-400 text-xs">No</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- GL Journal Entry card --}}
            @if ($challan->journalEntry)
                @php $je = $challan->journalEntry; @endphp
                <div class="bg-white rounded-xl shadow-sm overflow-hidden border-l-4 border-l-green-500">
                    <div class="px-4 py-3 border-b border-green-100 flex items-center justify-between flex-wrap gap-2">
                        <div>
                            <h3 class="text-base font-medium flex items-center gap-2">
                                <i class="fas fa-book text-green-600"></i>
                                GL Journal Entry
                            </h3>
                            <p class="text-xs text-gray-500">জিএল জার্নাল — Dr COGS / Cr Inventory</p>
                        </div>
                        @if (!empty($je->is_reversed))
                            <span class="bg-red-100 text-red-700 border border-red-300 rounded-full px-2 py-0.5 text-xs font-medium">
                                <i class="fas fa-rotate-left me-0.5"></i>Reversed
                            </span>
                        @endif
                    </div>
                    <div class="p-4 grid grid-cols-1 sm:grid-cols-3 gap-3 border-b border-gray-100">
                        <div>
                            <p class="text-xs text-gray-500 mb-1">JE #</p>
                            <span class="bg-gray-100 text-gray-700 rounded-full px-2 py-0.5 text-xs font-medium">{{ $je->entry_no }}</span>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Entry date / তারিখ</p>
                            <span class="text-sm font-medium">
                                @if ($je->entry_date)
                                    {{ \Carbon\Carbon::parse($je->entry_date)->format('d M Y') }}
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </span>
                        </div>
                        <div>
                            <p class="text-xs text-gray-500 mb-1">Description</p>
                            <span class="text-sm">{!! nl2br(e($je->description ?: '—')) !!}</span>
                        </div>
                    </div>
                    @if ($je->lines && $je->lines->isNotEmpty())
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="bg-green-50/50">
                                        <th class="px-4 py-3 text-left font-medium">Ledger / হিসাব</th>
                                        <th class="px-4 py-3 text-right font-medium">Debit (Tk)</th>
                                        <th class="px-4 py-3 text-right font-medium">Credit (Tk)</th>
                                        <th class="px-4 py-3 text-left font-medium">Memo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($je->lines as $line)
                                        <tr class="hover:bg-green-50/30 border-b border-gray-100">
                                            <td class="px-4 py-3">
                                                @if ($line->ledger)
                                                    <div class="font-medium">{{ $line->ledger->ledger_name }}</div>
                                                    <div class="text-xs text-gray-500">{{ $line->ledger->ledger_code }}</div>
                                                @else
                                                    <span class="text-gray-400">Ledger #{{ $line->ledger_id }}</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 text-right">
                                                @if ((float) $line->debit > 0)
                                                    <span class="font-semibold text-green-700">{{ number_format((float) $line->debit, 2) }}</span>
                                                @else
                                                    <span class="text-gray-400">—</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 text-right">
                                                @if ((float) $line->credit > 0)
                                                    <span class="font-semibold text-orange-700">{{ number_format((float) $line->credit, 2) }}</span>
                                                @else
                                                    <span class="text-gray-400">—</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 text-xs text-gray-600">{{ $line->memo ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="bg-green-50/40 font-bold">
                                        <td class="px-4 py-3 text-right">Totals / সর্বমোট</td>
                                        <td class="px-4 py-3 text-right text-green-700">Tk {{ number_format($glDebitTotal, 2) }}</td>
                                        <td class="px-4 py-3 text-right text-orange-700">Tk {{ number_format($glCreditTotal, 2) }}</td>
                                        <td class="px-4 py-3">
                                            @if (abs($glDebitTotal - $glCreditTotal) < 0.01)
                                                <span class="bg-green-100 text-green-700 border border-green-300 rounded-full px-2 py-0.5 text-xs">
                                                    <i class="fas fa-check me-0.5"></i>Balanced
                                                </span>
                                            @else
                                                <span class="bg-red-100 text-red-700 border border-red-300 rounded-full px-2 py-0.5 text-xs">
                                                    <i class="fas fa-triangle-exclamation me-0.5"></i>Unbalanced
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    @else
                        <p class="text-gray-500 text-sm p-4 mb-0">No journal lines.</p>
                    @endif
                </div>
            @endif

            {{-- Notes card --}}
            @if (!empty($challan->notes))
                <div class="bg-white rounded-xl shadow-sm border-l-4 border-l-yellow-500 p-4">
                    <div class="pb-2 mb-2 border-b border-yellow-100">
                        <h3 class="text-base font-medium flex items-center gap-2">
                            <i class="fas fa-sticky-note text-yellow-600"></i>
                            Notes / নোট
                        </h3>
                    </div>
                    <p class="text-sm text-gray-700 whitespace-pre-line">{{ $challan->notes }}</p>
                </div>
            @endif
        </div>

        {{-- Right column: status & actions (sticky) --}}
        <div>
            <div class="sticky-top" style="top:80px;">
                <div class="bg-white rounded-xl shadow-sm border-l-4 border-l-orange-500 p-4">
                    <div class="pb-2 mb-3 border-b border-orange-100">
                        <h3 class="text-base font-medium flex items-center gap-2">
                            <i class="fas fa-clipboard-list text-orange-600"></i>
                            Status & Actions
                        </h3>
                        <p class="text-xs text-gray-500">অবস্থা ও অ্যাকশন</p>
                    </div>

                    <div class="space-y-2 mb-3">
                        <p class="text-xs text-gray-500">Current status</p>
                        <div>{!! $statusBadge(true) !!}</div>
                    </div>

                    <hr class="my-3 border-gray-100">

                    <div class="space-y-2">
                        <a href="{{ route('admin.sales-challans.print-challan', $challan->id) }}" target="_blank"
                           class="inline-flex items-center justify-center gap-1.5 w-full bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white rounded-lg px-3 py-2 text-sm font-medium shadow-sm">
                            <i class="fas fa-print"></i> Print Challan / প্রিন্ট
                        </a>
                        <a href="{{ route('admin.sales-challans.index') }}"
                           class="inline-flex items-center justify-center gap-1.5 w-full border border-gray-200 hover:bg-gray-50 text-gray-700 rounded-lg px-3 py-2 text-sm">
                            <i class="fas fa-list"></i> All Challans / তালিকা
                        </a>
                        @if ($inv)
                            <a href="{{ route('admin.sales-invoices.show', $inv) }}"
                               class="inline-flex items-center justify-center gap-1.5 w-full border border-gray-200 hover:bg-gray-50 text-gray-700 rounded-lg px-3 py-2 text-sm">
                                <i class="fas fa-file-invoice-dollar"></i> View Invoice / চালান
                            </a>
                        @endif
                    </div>

                    @if ($challan->is_reversed)
                        <hr class="my-3 border-gray-100">
                        <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm text-gray-600">
                            <i class="fas fa-lock me-1"></i>
                            This challan is reversed — no further actions available.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Sticky action bar: Cancel (only for non-reversed challans) --}}
    @if (! $challan->is_reversed)
        <div class="sticky bottom-4 bg-white/85 backdrop-blur-sm border border-amber-200 rounded-xl shadow-lg p-4 flex items-center justify-between flex-wrap gap-3">
            <div class="flex items-start gap-3 flex-1 min-w-0">
                <div class="size-9 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center shrink-0">
                    <i class="fas fa-triangle-exclamation"></i>
                </div>
                <div class="text-sm">
                    <p class="font-medium text-amber-900">Cancel reverses stock + GL / বাতিল স্টক ও জিএল ফিরিয়ে আনে</p>
                    <p class="text-xs text-gray-600">Cancelling this challan will reverse the stock OUT movements and reverse the GL journal entry. A reason is required.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('admin.sales-challans.cancel', $challan) }}" id="cancelForm">
                @csrf
                <input type="hidden" name="cancel_reason" id="cancelReasonInput" value="">
                <button type="button" class="inline-flex items-center gap-1.5 border-2 border-red-500 text-red-600 hover:bg-red-50 rounded-lg px-4 py-2 text-sm font-semibold transition-colors" id="cancelBtn">
                    <i class="fas fa-ban"></i> Cancel Challan / চালান বাতিল
                </button>
            </form>
        </div>
    @endif

</div>

</x-layouts.erp>

@push('scripts')
<script>
$(function () {
    $('#cancelBtn').on('click', function () {
        Swal.fire({
            icon: 'warning',
            title: 'Cancel this challan?',
            html: '<p class="mb-2">This will <strong>reverse stock movements</strong> and <strong>reverse the GL journal entry</strong>.</p>' +
                  '<p class="mb-2 text-muted small">A reason is required (max 500 chars).</p>' +
                  '<textarea id="cancelReason" class="form-control" placeholder="Enter cancellation reason..." maxlength="500" rows="3"></textarea>',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fas fa-ban"></i> Yes, cancel challan',
            cancelButtonText: 'Keep challan',
            preConfirm: function () {
                var reason = $('#cancelReason').val().trim();
                if (!reason) {
                    Swal.showValidationMessage('A cancellation reason is required.');
                    return false;
                }
                return reason;
            }
        }).then(function (res) {
            if (res.isConfirmed) {
                $('#cancelReasonInput').val(res.value);
                $('#cancelForm').submit();
            }
        });
    });
});
</script>
@endpush

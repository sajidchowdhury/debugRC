<x-layouts.erp :title="'Invoice ' . $invoice->invoice_code" :tabs="[
    ['label' => 'Dashboard', 'href' => route('dashboard')],
    ['label' => 'Invoices', 'href' => route('admin.sales-invoices.index')],
    ['label' => 'Challans', 'href' => route('admin.sales-challans.index')],
    ['label' => 'UI Preview', 'href' => route('ui-preview')],
]">
@php
    $statusBadge = function (bool $large = false) use ($invoice): string {
        $cls = $large ? ' fs-5' : ' fs-6';
        return [
            'draft'     => '<span class="badge bg-warning-subtle text-warning' . $cls . '"><i class="fas fa-pen-to-square me-1"></i>Draft</span>',
            'confirmed' => '<span class="badge bg-success-subtle text-success' . $cls . '"><i class="fas fa-circle-check me-1"></i>Confirmed</span>',
            'cancelled' => '<span class="badge bg-secondary-subtle text-secondary' . $cls . '"><i class="fas fa-ban me-1"></i>Cancelled</span>',
            'reversed'  => '<span class="badge bg-danger-subtle text-danger' . $cls . '"><i class="fas fa-rotate-left me-1"></i>Reversed</span>',
        ][$invoice->status] ?? '<span class="badge bg-light text-dark' . $cls . '">' . e($invoice->status) . '</span>';
    };

    // GL journal lines totals
    $glDebitTotal = 0.0;
    $glCreditTotal = 0.0;
    if ($invoice->journalEntry && $invoice->journalEntry->lines) {
        foreach ($invoice->journalEntry->lines as $line) {
            $glDebitTotal  += (float) $line->debit;
            $glCreditTotal += (float) $line->credit;
        }
    }

    // Dispatch totals
    $totalOrdered    = 0.0;
    $totalDispatched = 0.0;
    foreach ($invoice->dispatches as $d) {
        $totalOrdered    += (float) $d->ordered_qty;
        $totalDispatched += (float) $d->dispatched_qty;
    }

    // Reverse-by user name lookup (best effort)
    $reversedByName = null;
    if ($invoice->reversed_by) {
        $u = \App\Models\Employee::find($invoice->reversed_by);
        if ($u) {
            $reversedByName = $u->name ?? ('Employee #' . $invoice->reversed_by);
        } else {
            $reversedByName = 'User #' . $invoice->reversed_by;
        }
    }

    // Created-by user name lookup (best effort)
    $createdByName = null;
    if ($invoice->created_by) {
        $u = \App\Models\Employee::find($invoice->created_by);
        if ($u) {
            $createdByName = $u->name ?? ('Employee #' . $invoice->created_by);
        } else {
            $createdByName = 'User #' . $invoice->created_by;
        }
    }
@endphp

<div class="space-y-6">
    {{-- Hero header (amber/orange gradient — showcase spec) --}}
    <div class="bg-gradient-to-r from-amber-500 via-amber-600 to-orange-500 rounded-xl p-6 shadow-lg">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <div class="flex items-center gap-3 flex-wrap">
                    <h1 class="text-2xl font-bold text-white">Invoice {{ $invoice->invoice_code }}</h1>
                    {!! $statusBadge() !!}
                </div>
                <p class="text-amber-100 text-sm mt-1">
                    @if ($invoice->customer){{ $invoice->customer->customer_name }}@endif
                    @if ($invoice->branch) · {{ $invoice->branch->branch_name }}@endif
                    · {{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y') }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" class="inline-flex items-center gap-1.5 bg-white/20 backdrop-blur-sm hover:bg-white/30 text-white rounded-lg px-3 py-2 text-xs font-medium transition-colors" onclick="window.print()">
                    <x-erp.icon name="printer" class="size-4" /> Print
                </button>
                <a href="{{ route('admin.sales-invoices.index') }}" class="inline-flex items-center gap-1.5 bg-white/20 backdrop-blur-sm hover:bg-white/30 text-white rounded-lg px-3 py-2 text-xs font-medium transition-colors">
                    <x-erp.icon name="arrow-left" class="size-4" /> Back to list
                </a>
            </div>
        </div>
    </div>

    {{-- Reversal / Cancel alert --}}
    @if ($invoice->is_reversed || $invoice->isCancelled())
        <div class="alert alert-danger d-flex align-items-start mb-3" role="alert">
            <i class="fas fa-rotate-left me-2 fa-lg text-danger"></i>
            <div class="w-100">
                <strong>This invoice has been {{ $invoice->status === 'cancelled' ? 'cancelled' : 'reversed' }}.</strong>
                @if ($invoice->reversed_at)
                    <div class="small text-muted mt-1">
                        <i class="fas fa-clock me-1"></i>
                        {{ \Carbon\Carbon::parse($invoice->reversed_at)->format('d M Y, H:i') }}
                        @if ($reversedByName)
                            · <i class="fas fa-user me-1"></i>{{ $reversedByName }}
                        @endif
                    </div>
                @endif
                @if (!empty($invoice->reverse_reason))
                    <div class="mt-1">
                        <span class="text-muted">Reason:</span>
                        <em>{{ $invoice->reverse_reason }}</em>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="row g-3">
        {{-- Left: main details --}}
        <div class="col-lg-8">
            {{-- Invoice details card --}}
            <x-erp.left-accent-card accent="amber" icon="file-text" title="Invoice details" title-bn="চালানের বিস্তারিত" class="mb-3">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">Invoice code</dt>
                        <dd class="col-sm-9">
                            <span class="badge bg-secondary-subtle text-secondary">{{ $invoice->invoice_code }}</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Invoice date</dt>
                        <dd class="col-sm-9">{{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y') }}</dd>

                        <dt class="col-sm-3 text-muted">Customer</dt>
                        <dd class="col-sm-9">
                            @if ($invoice->customer)
                                <strong>{{ $invoice->customer->customer_name }}</strong>
                                <span class="text-muted">({{ $invoice->customer->customer_code }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Branch</dt>
                        <dd class="col-sm-9">
                            @if ($invoice->branch)
                                {{ $invoice->branch->branch_name }}
                                <span class="text-muted small">({{ $invoice->branch->branch_code }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Salesman</dt>
                        <dd class="col-sm-9">
                            @if ($invoice->salesman)
                                {{ $invoice->salesman->name ?? '—' }}
                                @if (!empty($invoice->sales_person))
                                    <span class="text-muted small">· {{ $invoice->sales_person }}</span>
                                @endif
                            @elseif (!empty($invoice->sales_person))
                                {{ $invoice->sales_person }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        @if ($invoice->dispatchers && $invoice->dispatchers->count() > 0)
                        <dt class="col-sm-3 text-muted">Dispatchers</dt>
                        <dd class="col-sm-9">
                            @foreach ($invoice->dispatchers as $idx => $dispatcher)
                                @if ($idx > 0)<span class="text-muted">, </span>@endif
                                <span class="badge bg-info-subtle text-info">
                                    <i class="fas fa-truck me-1"></i>{{ $dispatcher->name }}
                                </span>
                            @endforeach
                        </dd>
                        @endif

                        <dt class="col-sm-3 text-muted">Payment mode</dt>
                        <dd class="col-sm-9">
                            <span class="badge bg-light text-dark">{{ ucfirst($invoice->payment_mode ?: '—') }}</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Sub-total</dt>
                        <dd class="col-sm-9">Tk {{ number_format((float) $invoice->sub_total, 2) }}</dd>

                        <dt class="col-sm-3 text-muted">Discount</dt>
                        <dd class="col-sm-9 text-danger">− Tk {{ number_format((float) $invoice->discount_amount, 2) }}</dd>

                        <dt class="col-sm-3 text-muted">Transport cost</dt>
                        <dd class="col-sm-9">+ Tk {{ number_format((float) $invoice->transport_cost, 2) }}</dd>

                        <dt class="col-sm-3 text-muted">Total amount</dt>
                        <dd class="col-sm-9">
                            <strong class="fs-5 text-amber-700">Tk {{ number_format((float) $invoice->total_amount, 2) }}</strong>
                        </dd>

                        <dt class="col-sm-3 text-muted">Paid</dt>
                        <dd class="col-sm-9 text-success">Tk {{ number_format((float) $invoice->paid_amount, 2) }}</dd>

                        <dt class="col-sm-3 text-muted">Due</dt>
                        <dd class="col-sm-9">
                            @if ((float) $invoice->due_amount > 0.01)
                                <span class="text-danger fw-semibold">Tk {{ number_format((float) $invoice->due_amount, 2) }}</span>
                            @else
                                <span class="text-success">Tk 0.00 (fully paid)</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Status</dt>
                        <dd class="col-sm-9">{!! $statusBadge() !!}</dd>

                        <dt class="col-sm-3 text-muted">Soft hold</dt>
                        <dd class="col-sm-9">
                            @if ($invoice->is_soft_hold)
                                <span class="badge bg-danger-subtle text-danger"><i class="fas fa-hand me-1"></i>Yes</span>
                            @else
                                <span class="text-muted">No</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Notes</dt>
                        <dd class="col-sm-9">{!! nl2br(e($invoice->notes ?: '—')) !!}</dd>

                        <dt class="col-sm-3 text-muted">Created by</dt>
                        <dd class="col-sm-9 small text-muted">
                            @if ($createdByName){{ $createdByName }}@else— @endif
                            @if ($invoice->created_at) · {{ $invoice->created_at->format('d M Y, H:i') }}@endif
                        </dd>
                    </dl>
            </x-erp.left-accent-card>

            {{-- Items table --}}
            <x-erp.left-accent-card accent="orange" icon="package" title="Items" title-bn="পণ্য" class="mb-3" body-class="!p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Qty</th>
                                    <th class="text-end">Rate (Tk)</th>
                                    <th class="text-end">Amount (Tk)</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($invoice->items as $item)
                                    <tr>
                                        <td>
                                            @if ($item->product)
                                                <span class="fw-semibold">{{ $item->product->product_name }}</span>
                                                <div class="small text-muted">{{ $item->product->product_code }}</div>
                                            @else
                                                <span class="text-muted">Product #{{ $item->product_id }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ number_format((float) $item->qty, 4) }}</td>
                                        <td class="text-end">{{ number_format((float) $item->rate, 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $item->amount, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">No items.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td colspan="3" class="text-end">Sub-total</td>
                                    <td class="text-end">Tk {{ number_format((float) $invoice->sub_total, 2) }}</td>
                                </tr>
                                <tr class="table-light">
                                    <td colspan="3" class="text-end text-danger">− Discount</td>
                                    <td class="text-end text-danger">Tk {{ number_format((float) $invoice->discount_amount, 2) }}</td>
                                </tr>
                                <tr class="table-light">
                                    <td colspan="3" class="text-end">+ Transport</td>
                                    <td class="text-end">Tk {{ number_format((float) $invoice->transport_cost, 2) }}</td>
                                </tr>
                                <tr class="fw-bold bg-amber-50">
                                    <td colspan="3" class="text-end text-amber-800">Total amount</td>
                                    <td class="text-end text-amber-800">Tk {{ number_format((float) $invoice->total_amount, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
            </x-erp.left-accent-card>

            {{-- Dispatches table --}}
            @if ($invoice->dispatches->isNotEmpty())
                <x-erp.left-accent-card accent="cyan" icon="truck" title="Dispatches" title-bn="ডিসপ্যাচ" class="mb-3" body-class="!p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-end">Ordered Qty</th>
                                        <th class="text-end">Dispatched Qty</th>
                                        <th class="text-end">Remaining</th>
                                        <th>Warehouse</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($invoice->dispatches as $d)
                                        @php
                                            $remaining = max(0, (float) $d->ordered_qty - (float) $d->dispatched_qty);
                                            $fullyDispatched = $remaining <= 0.0001 && (float) $d->ordered_qty > 0;
                                        @endphp
                                        <tr class="{{ $fullyDispatched ? 'table-success' : '' }}">
                                            <td>
                                                @if ($d->product)
                                                    <span class="fw-semibold">{{ $d->product->product_name }}</span>
                                                    <div class="small text-muted">{{ $d->product->product_code }}</div>
                                                @else
                                                    <span class="text-muted">Product #{{ $d->product_id }}</span>
                                                @endif
                                            </td>
                                            <td class="text-end">{{ number_format((float) $d->ordered_qty, 4) }}</td>
                                            <td class="text-end">{{ number_format((float) $d->dispatched_qty, 4) }}</td>
                                            <td class="text-end">
                                                @if ($remaining > 0.0001)
                                                    <span class="text-danger fw-semibold">{{ number_format($remaining, 4) }}</span>
                                                @else
                                                    <span class="text-success fw-semibold">0.0000</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if (!empty($d->warehouse_id))
                                                    <span class="small">Wh #{{ $d->warehouse_id }}</span>
                                                @else
                                                    <span class="text-muted small">— not assigned —</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($fullyDispatched)
                                                    <span class="badge bg-success-subtle text-success">
                                                        <i class="fas fa-circle-check me-1"></i>Dispatched
                                                    </span>
                                                @elseif ((float) $d->dispatched_qty > 0)
                                                    <span class="badge bg-warning-subtle text-warning">
                                                        <i class="fas fa-circle-half-stroke me-1"></i>Partial
                                                    </span>
                                                @else
                                                    <span class="badge bg-info-subtle text-info">
                                                        <i class="fas fa-clock me-1"></i>Pending
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <div class="px-4 py-2 small text-muted border-top">
                            {{ number_format($totalDispatched, 4) }} / {{ number_format($totalOrdered, 4) }} dispatched
                        </div>
                </x-erp.left-accent-card>
            @endif

            {{-- GL Journal Entry card --}}
            @if ($invoice->journalEntry)
                @php $je = $invoice->journalEntry; @endphp
                <x-erp.left-accent-card accent="green" icon="banknote" title="GL Journal Entry" title-bn="জিএল জার্নাল" class="mb-3">
                    <x-slot:actions>
                        @if (!empty($je->is_reversed))
                            <span class="badge bg-danger-subtle text-danger">
                                <i class="fas fa-rotate-left me-1"></i>Reversed
                            </span>
                        @endif
                    </x-slot:actions>
                        <dl class="row mb-3">
                            <dt class="col-sm-3 text-muted">JE #</dt>
                            <dd class="col-sm-9">
                                <span class="badge bg-secondary-subtle text-secondary">{{ $je->entry_no }}</span>
                            </dd>
                            <dt class="col-sm-3 text-muted">Entry date</dt>
                            <dd class="col-sm-9">
                                @if ($je->entry_date)
                                    {{ \Carbon\Carbon::parse($je->entry_date)->format('d M Y') }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </dd>
                            <dt class="col-sm-3 text-muted">Description</dt>
                            <dd class="col-sm-9">{!! nl2br(e($je->description ?: '—')) !!}</dd>
                        </dl>

                        @if ($je->lines && $je->lines->isNotEmpty())
                            <div class="table-responsive">
                                <table class="table table-sm table-striped table-hover align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Ledger</th>
                                            <th class="text-end">Debit (Tk)</th>
                                            <th class="text-end">Credit (Tk)</th>
                                            <th>Memo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($je->lines as $line)
                                            <tr>
                                                <td>
                                                    @if ($line->ledger)
                                                        <span class="fw-semibold">{{ $line->ledger->ledger_name }}</span>
                                                        <div class="small text-muted">{{ $line->ledger->ledger_code }}</div>
                                                    @else
                                                        <span class="text-muted">Ledger #{{ $line->ledger_id }}</span>
                                                    @endif
                                                </td>
                                                <td class="text-end">
                                                    @if ((float) $line->debit > 0)
                                                        {{ number_format((float) $line->debit, 2) }}
                                                    @else
                                                        <span class="text-muted">—</span>
                                                    @endif
                                                </td>
                                                <td class="text-end">
                                                    @if ((float) $line->credit > 0)
                                                        {{ number_format((float) $line->credit, 2) }}
                                                    @else
                                                        <span class="text-muted">—</span>
                                                    @endif
                                                </td>
                                                <td class="small">{{ $line->memo ?: '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-light fw-bold">
                                            <td class="text-end">Totals</td>
                                            <td class="text-end">Tk {{ number_format($glDebitTotal, 2) }}</td>
                                            <td class="text-end">Tk {{ number_format($glCreditTotal, 2) }}</td>
                                            <td>
                                                @if (abs($glDebitTotal - $glCreditTotal) < 0.01)
                                                    <span class="badge bg-success-subtle text-success">
                                                        <i class="fas fa-check me-1"></i>Balanced
                                                    </span>
                                                @else
                                                    <span class="badge bg-danger-subtle text-danger">
                                                        <i class="fas fa-triangle-exclamation me-1"></i>Unbalanced
                                                    </span>
                                                @endif
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        @else
                            <p class="text-muted small mb-0">No journal lines.</p>
                        @endif
                </x-erp.left-accent-card>
            @endif

            {{-- Customer Ledger Entries card --}}
            @if ($customerLedgerEntries->isNotEmpty())
                <x-erp.left-accent-card accent="yellow" icon="users" title="Customer Ledger Entries" title-bn="ক্রেতা হিসাব" class="mb-3" body-class="!p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th class="text-end">Debit (Tk)</th>
                                        <th class="text-end">Credit (Tk)</th>
                                        <th class="text-end">Balance (Tk)</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($customerLedgerEntries as $le)
                                        <tr>
                                            <td class="text-nowrap small">
                                                @if (!empty($le->transaction_date))
                                                    {{ \Carbon\Carbon::parse($le->transaction_date)->format('d M Y') }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">{{ $le->transaction_type }}</span>
                                            </td>
                                            <td class="text-end">
                                                @if ((float) $le->debit > 0)
                                                    {{ number_format((float) $le->debit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if ((float) $le->credit > 0)
                                                    {{ number_format((float) $le->credit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end fw-semibold">{{ number_format((float) $le->balance, 2) }}</td>
                                            <td class="small">{{ $le->description ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                </x-erp.left-accent-card>
            @endif
        </div>

        {{-- Right: aside --}}
        <div class="col-lg-4">
            {{-- Status card (large) --}}
            <x-erp.left-accent-card accent="amber" icon="file-text" title="Status" title-bn="অবস্থা" class="mb-3">
                    <div class="text-center">
                        <div class="mb-2">{!! $statusBadge(true) !!}</div>
                        <div class="small text-muted">
                            @switch($invoice->status)
                                @case('draft')
                                    Draft invoice — GL posted, stock not yet moved. Can be cancelled.
                                    @break
                                @case('confirmed')
                                    Confirmed invoice — ready for godown preparation (Phase 8.3).
                                    @break
                                @case('cancelled')
                                    Cancelled — GL & customer ledger reversed. No further action.
                                    @break
                                @case('reversed')
                                    Reversed — GL & customer ledger reversed.
                                    @break
                            @endswitch
                        </div>
                    </div>
            </x-erp.left-accent-card>

            {{-- Workflow progress card --}}
            <x-erp.left-accent-card accent="orange" icon="clipboard-list" title="Workflow progress" title-bn="কর্মপ্রবাহ" class="mb-3">
                    <ul class="list-unstyled mb-0">
                        {{-- Step 1: Invoice Created --}}
                        <li class="d-flex align-items-start mb-3">
                            <span class="rounded-circle d-flex align-items-center justify-content-center me-3 text-white flex-shrink-0 size-8 bg-green-500">
                                <i class="fas fa-check"></i>
                            </span>
                            <div>
                                <div class="fw-semibold">Invoice Created</div>
                                <div class="small text-muted">
                                    {{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d M Y') }}
                                    · GL + customer ledger posted
                                </div>
                            </div>
                        </li>

                        {{-- Step 2: Godown Prepared --}}
                        <li class="d-flex align-items-start mb-3">
                            <span class="rounded-circle d-flex align-items-center justify-content-center me-3 text-white flex-shrink-0 size-8 {{ $invoice->is_godown_prepared ? 'bg-green-500' : 'bg-gray-300' }}">
                                @if ($invoice->is_godown_prepared)
                                    <i class="fas fa-check"></i>
                                @else
                                    <i class="fas fa-warehouse text-gray-600"></i>
                                @endif
                            </span>
                            <div>
                                <div class="fw-semibold">Godown Prepared</div>
                                <div class="small">
                                    @if ($invoice->is_godown_prepared)
                                        <span class="badge bg-success-subtle text-success">Done</span>
                                        @if (!empty($invoice->godown_prepared_at))
                                            <span class="text-muted ms-1">
                                                {{ \Carbon\Carbon::parse($invoice->godown_prepared_at)->format('d M Y') }}
                                            </span>
                                        @endif
                                    @else
                                        <span class="badge bg-warning-subtle text-warning">Pending</span>
                                        <span class="text-muted ms-1 small">Phase 8.3</span>
                                    @endif
                                </div>
                            </div>
                        </li>

                        {{-- Step 3: Challan Issued --}}
                        <li class="d-flex align-items-start mb-3">
                            <span class="rounded-circle d-flex align-items-center justify-content-center me-3 text-white flex-shrink-0 size-8 {{ $invoice->is_challan_issued ? 'bg-green-500' : 'bg-gray-300' }}">
                                @if ($invoice->is_challan_issued)
                                    <i class="fas fa-check"></i>
                                @else
                                    <i class="fas fa-truck text-gray-600"></i>
                                @endif
                            </span>
                            <div>
                                <div class="fw-semibold">Challan Issued</div>
                                <div class="small">
                                    @if ($invoice->is_challan_issued)
                                        <span class="badge bg-success-subtle text-success">Done</span>
                                        @if (!empty($invoice->challan_issued_at))
                                            <span class="text-muted ms-1">
                                                {{ \Carbon\Carbon::parse($invoice->challan_issued_at)->format('d M Y') }}
                                            </span>
                                        @endif
                                    @else
                                        <span class="badge bg-warning-subtle text-warning">Pending</span>
                                        <span class="text-muted ms-1 small">Phase 8.3 · stock OUT</span>
                                    @endif
                                </div>
                            </div>
                        </li>

                        {{-- Step 4: Payment Received --}}
                        <li class="d-flex align-items-start">
                            <span class="rounded-circle d-flex align-items-center justify-content-center me-3 text-white flex-shrink-0 size-8 {{ (float) $invoice->due_amount < 0.01 ? 'bg-green-500' : 'bg-gray-300' }}">
                                @if ((float) $invoice->due_amount < 0.01)
                                    <i class="fas fa-check"></i>
                                @else
                                    <i class="fas fa-taka-sign text-gray-600"></i>
                                @endif
                            </span>
                            <div>
                                <div class="fw-semibold">Payment Received</div>
                                <div class="small">
                                    @if ((float) $invoice->due_amount < 0.01)
                                        <span class="badge bg-success-subtle text-success">Done</span>
                                        <span class="text-muted ms-1 small">Tk {{ number_format((float) $invoice->paid_amount, 2) }}</span>
                                    @else
                                        <span class="badge bg-warning-subtle text-warning">Pending</span>
                                        <span class="text-muted ms-1 small">Phase 8.4 · due Tk {{ number_format((float) $invoice->due_amount, 2) }}</span>
                                    @endif
                                </div>
                            </div>
                        </li>
                    </ul>
            </x-erp.left-accent-card>

            {{-- Actions card --}}
            <x-erp.left-accent-card accent="cyan" icon="save" title="Actions" title-bn="অ্যাকশন" class="mb-3">
                    <div class="d-grid gap-2">
                    {{-- Draft: Edit invoice (P1-1) --}}
                    @if ($invoice->isDraft() && auth()->user()->hasRole('salesman', 'manager', 'admin', 'superadmin'))
                        <a href="{{ route('admin.sales-invoices.edit', $invoice->id) }}" class="btn btn-primary w-100">
                            <i class="fas fa-pen-to-square me-1"></i> Edit Invoice
                        </a>
                        <div class="alert alert-info small mb-0">
                            <i class="fas fa-circle-info me-1"></i>
                            Editing a draft invoice reverses the old GL + customer ledger and posts a new one. Items, qty, rate, discount, and transport can be changed.
                        </div>
                    @endif

                    {{-- P1-6: Print Invoice (customer copy) --}}
                    <a href="{{ route('admin.sales-invoices.print-invoice', $invoice->id) }}" class="btn btn-outline-primary w-100" target="_blank">
                        <i class="fas fa-print me-1"></i> Print Invoice
                    </a>

                    {{-- BUG-52: Print Godown Copy + Print Blank Godown — WM/admin only --}}
                    @if (auth()->user()->hasRole('warehouse_manager', 'manager', 'admin', 'superadmin'))
                        <a href="{{ route('admin.sales-invoices.print-godown', $invoice->id) }}" class="btn btn-outline-secondary w-100" target="_blank">
                            <i class="fas fa-warehouse me-1"></i> Print Godown Copy
                        </a>
                        @if (!empty($invoice->is_blank_godown_printed))
                            {{-- Already printed → re-print the read-only view --}}
                            <a href="{{ route('admin.sales-invoices.print-blank-godown', $invoice->id) }}" class="btn btn-outline-warning w-100" target="_blank">
                                <i class="fas fa-pen me-1"></i> Re-print Blank Godown
                            </a>
                        @else
                            {{-- Not yet printed → go through the Step 1 flow (dispatcher required) --}}
                            <a href="{{ route('admin.sales-challans.blank-godown-form', $invoice->id) }}" class="btn btn-outline-warning w-100">
                                <i class="fas fa-pen me-1"></i> Print Blank Godown
                            </a>
                        @endif
                    @endif

                    {{-- BUG-52: Sales → Warehouse handoff buttons (state-aware) --}}
                    @php
                        $canWarehouse = auth()->user()->hasRole('warehouse_manager', 'dispatcher', 'manager', 'admin', 'superadmin');
                        $isConfirmedNoGodown = $invoice->isConfirmed() && !$invoice->is_godown_prepared && !$invoice->is_reversed;
                        $isGodownNoChallan   = $invoice->is_godown_prepared && !$invoice->is_challan_issued && !$invoice->is_reversed;
                    @endphp
                    @if ($canWarehouse && $isConfirmedNoGodown)
                        <a href="{{ route('admin.sales-challans.godown', $invoice->id) }}" class="btn btn-info w-100 text-white">
                            <i class="fas fa-warehouse me-1"></i> Prepare Godown Copy
                        </a>
                        <div class="alert alert-info small mb-0">
                            <i class="fas fa-circle-info me-1"></i>
                            Assign a source warehouse to each line item. Stock does not move yet — that happens at challan issue.
                        </div>
                    @endif
                    @if ($canWarehouse && $isGodownNoChallan)
                        <a href="{{ route('admin.sales-challans.challan-form', $invoice->id) }}" class="btn btn-success w-100">
                            <i class="fas fa-truck me-1"></i> Issue Challan
                        </a>
                        <div class="alert alert-warning small mb-0">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Issuing the challan moves stock OUT of the warehouse at avg_cost and posts GL Dr COGS / Cr Inventory.
                        </div>
                    @endif

                    {{-- Draft: Cancel invoice --}}
                    @if ($invoice->isDraft() && auth()->user()->hasRole('salesman', 'manager', 'admin', 'superadmin'))
                        <form method="POST" action="{{ route('admin.sales-invoices.cancel', $invoice->id) }}" id="cancelForm">
                            @csrf
                            <input type="hidden" name="cancel_reason" id="cancelReasonInput" value="">
                            <button type="button" class="btn btn-outline-danger w-100" id="cancelBtn">
                                <i class="fas fa-ban me-1"></i> Cancel Invoice
                            </button>
                        </form>
                        <div class="alert alert-warning small mb-0">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Cancelling a draft invoice reverses the GL entry and customer ledger. Stock has not moved yet.
                        </div>
                    @endif

                    {{-- BUG-52: Confirmed-invoice info --}}
                    @if ($invoice->isConfirmed() && !$invoice->is_reversed)
                        @if (!$invoice->is_godown_prepared)
                            <div class="alert alert-info small mb-0">
                                <i class="fas fa-circle-info me-1"></i>
                                <strong>Awaiting godown prep.</strong>
                                @if ($canWarehouse)
                                    Click <em>Prepare Godown Copy</em> above to assign warehouses.
                                @else
                                    The warehouse manager will prepare the godown copy next.
                                @endif
                            </div>
                        @elseif (!$invoice->is_challan_issued)
                            <div class="alert alert-info small mb-0">
                                <i class="fas fa-circle-info me-1"></i>
                                <strong>Godown prepared — awaiting challan issue.</strong>
                                @if ($canWarehouse)
                                    Click <em>Issue Challan</em> above to dispatch stock.
                                @else
                                    The warehouse manager will issue the challan next.
                                @endif
                            </div>
                        @else
                            <div class="alert alert-success small mb-0">
                                <i class="fas fa-circle-check me-1"></i>
                                <strong>Challan issued.</strong> Stock has moved OUT. Awaiting customer payment.
                            </div>
                        @endif
                    @endif

                    {{-- Cancelled / reversed --}}
                    @if ($invoice->isCancelled() || $invoice->isReversed())
                        <div class="alert alert-secondary small mb-0">
                            <i class="fas fa-ban me-1"></i>
                            This invoice is {{ $invoice->status }} and cannot be modified further.
                        </div>
                    @endif
                    </div>
            </x-erp.left-accent-card>
        </div>
    </div>
</div>

</x-layouts.erp>

@push('scripts')
<script>
$(function () {
    // ====== Cancel Invoice (SweetAlert2 prompt for reason — required) ======
    $('#cancelBtn').on('click', function (e) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Cancel this invoice?',
            html: '<p class="text-muted small mb-2">This action reverses the GL entry and customer ledger. It cannot be undone. Please provide a reason:</p>' +
                  '<textarea id="swalCancelReason" class="form-control" rows="3" placeholder="Reason for cancellation" maxlength="500"></textarea>',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-ban"></i> Cancel Invoice',
            cancelButtonText: 'Keep Invoice',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
            focusConfirm: false,
            preConfirm: function () {
                var reason = $('#swalCancelReason').val();
                if (!reason || !reason.trim()) {
                    Swal.showValidationMessage('A cancellation reason is required.');
                    return false;
                }
                return reason.trim();
            }
        }).then(function (result) {
            if (result.isConfirmed && result.value) {
                $('#cancelReasonInput').val(result.value);
                $('#cancelForm').submit();
            }
        });
    });
});
</script>
@endpush

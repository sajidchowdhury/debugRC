@extends('layouts.admin')

@section('content')
@php
    $r = $return;

    // Phase 8.5 status badges: created=warning, confirmed=info, reversed=danger.
    $statusBadge = function (bool $large = false) use ($r): string {
        $cls = $large ? ' fs-5' : ' fs-6';
        return [
            'created'   => '<span class="badge bg-warning text-dark' . $cls . '"><i class="fas fa-pen-to-square me-1"></i>Created</span>',
            'confirmed' => '<span class="badge bg-info text-dark' . $cls . '"><i class="fas fa-circle-check me-1"></i>Confirmed</span>',
            'reversed'  => '<span class="badge bg-danger' . $cls . '"><i class="fas fa-rotate-left me-1"></i>Reversed</span>',
        ][$r->status] ?? '<span class="badge bg-light text-dark' . $cls . '">' . e($r->status) . '</span>';
    };

    // Revenue reversal journal entry + lines.
    $revJe          = $r->journalEntry;
    $revJeLines     = $revJe ? $revJe->lines : collect();
    $revDebitTotal  = $revJeLines->sum(fn ($l) => (float) $l->debit);
    $revCreditTotal = $revJeLines->sum(fn ($l) => (float) $l->credit);

    // COGS reversal journal entry + lines.
    $cogsJe          = $r->cogsJournalEntry;
    $cogsJeLines     = $cogsJe ? $cogsJe->lines : collect();
    $cogsDebitTotal  = $cogsJeLines->sum(fn ($l) => (float) $l->debit);
    $cogsCreditTotal = $cogsJeLines->sum(fn ($l) => (float) $l->credit);

    // Warehouse lookup for stock-movements table (st only joins products, not warehouses).
    $warehouseMap = \App\Models\Warehouse::pluck('warehouse_name', 'id');

    $hasStockMovements   = !empty($stockMovements) && is_countable($stockMovements) && count($stockMovements) > 0;
    $hasCustomerLedger   = !empty($customerLedgerEntries) && is_countable($customerLedgerEntries) && count($customerLedgerEntries) > 0;

    // Derived: gross margin reversal (revenue − cogs).
    $grossMargin = (float) $r->total_amount - (float) $r->cogs_amount;
@endphp

<div class="container-fluid py-2">
    {{-- Hero header (orange gradient) --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#ea580c,#d97706);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-rotate-left me-2"></i>Return {{ $r->return_code }}
                {!! $statusBadge() !!}
            </h1>
            <p class="mb-0 small opacity-75">
                @if ($r->customer){{ $r->customer->customer_name }}@endif
                @if ($r->branch) · {{ $r->branch->branch_name }}@endif
                · {{ \Carbon\Carbon::parse($r->return_date)->format('d M Y') }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.sales-returns.print-slip', $r->id) }}" class="btn btn-outline-light btn-sm" target="_blank">
                <i class="fas fa-print me-1"></i> Print Slip
            </a>
            <a href="{{ route('admin.sales-returns.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Reversal alert --}}
    @if ($r->is_reversed)
        <div class="alert alert-danger d-flex align-items-start mb-3" role="alert">
            <i class="fas fa-rotate-left me-2 fa-lg"></i>
            <div>
                <strong>This return has been reversed.</strong>
                <div class="mt-1">
                    @if ($r->reversed_at)
                        <span class="me-3"><i class="fas fa-clock me-1"></i>
                            {{ \Carbon\Carbon::parse($r->reversed_at)->format('d M Y H:i') }}
                        </span>
                    @endif
                    @if ($r->reversed_by)
                        <span class="me-3"><i class="fas fa-user me-1"></i>User #{{ $r->reversed_by }}</span>
                    @endif
                </div>
                @if ($r->reverse_reason)
                    <div class="mt-1"><span class="text-muted">Reason:</span>
                        <em>{{ $r->reverse_reason }}</em>
                    </div>
                @endif
                <div class="small text-muted mt-1">
                    Stock movements, both GL journals (revenue + COGS), and customer ledger entries have been reversed.
                </div>
            </div>
        </div>
    @endif

    <div class="row g-3">
        {{-- Left: main details --}}
        <div class="col-lg-8">
            {{-- Return details card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-warning"></i> Return details</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">Return code</dt>
                        <dd class="col-sm-9">
                            <span class="badge bg-secondary-subtle text-secondary">{{ $r->return_code }}</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Return date</dt>
                        <dd class="col-sm-9">{{ \Carbon\Carbon::parse($r->return_date)->format('d M Y') }}</dd>

                        <dt class="col-sm-3 text-muted">Invoice</dt>
                        <dd class="col-sm-9">
                            @if ($r->salesInvoice)
                                <a href="{{ route('admin.sales-invoices.show', $r->salesInvoice) }}"
                                   class="text-decoration-none">
                                    <span class="badge bg-secondary-subtle text-secondary">
                                        {{ $r->salesInvoice->invoice_code }}
                                    </span>
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Customer</dt>
                        <dd class="col-sm-9">
                            @if ($r->customer)
                                <strong>{{ $r->customer->customer_name }}</strong>
                                <span class="text-muted">({{ $r->customer->customer_code }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Branch</dt>
                        <dd class="col-sm-9">
                            @if ($r->branch)
                                {{ $r->branch->branch_name }}
                                <span class="text-muted small">({{ $r->branch->branch_code }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Reason</dt>
                        <dd class="col-sm-9">{!! nl2br(e($r->reason ?: '—')) !!}</dd>

                        <dt class="col-sm-3 text-muted">Revenue total</dt>
                        <dd class="col-sm-9">
                            <strong class="text-warning fs-5">Tk {{ number_format((float) $r->total_amount, 2) }}</strong>
                            <span class="text-muted small">(Dr Sales Return / Cr AR)</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">COGS total</dt>
                        <dd class="col-sm-9">
                            <strong class="text-danger fs-5">Tk {{ number_format((float) $r->cogs_amount, 2) }}</strong>
                            <span class="text-muted small">(Dr Inventory / Cr COGS @ original avg_cost)</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Created by</dt>
                        <dd class="col-sm-9 small text-muted">
                            @if ($r->created_by) User #{{ $r->created_by }} @else — @endif
                            @if ($r->created_at) · {{ $r->created_at->format('d M Y H:i') }} @endif
                        </dd>
                    </dl>
                </div>
            </div>

            {{-- Items table --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-table-list me-1 text-warning"></i> Items
                        <span class="badge bg-warning-subtle text-warning ms-1">{{ $r->items->count() }}</span>
                    </h2>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th>Warehouse</th>
                                    <th class="text-end">Qty</th>
                                    <th class="text-end">Sales Rate (Tk)</th>
                                    <th class="text-end" style="background:#fff7ed;">
                                        <span class="text-warning" title="ORIGINAL avg_cost from the challan">
                                            <i class="fas fa-circle-exclamation me-1"></i>Original Cost (Tk)
                                        </span>
                                    </th>
                                    <th class="text-end">Revenue (Tk)</th>
                                    <th class="text-end">COGS (Tk)</th>
                                    <th class="text-center">Condition</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($r->items as $item)
                                    @php
                                        $itemRevenue = (float) $item->qty * (float) $item->rate;
                                        $itemCogs    = (float) $item->qty * (float) $item->original_cost;
                                    @endphp
                                    <tr>
                                        <td>
                                            @if ($item->product)
                                                <span class="fw-semibold">{{ $item->product->product_name }}</span>
                                                <div class="small text-muted">{{ $item->product->product_code }}</div>
                                            @else
                                                <span class="text-muted">Product #{{ $item->product_id }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($item->warehouse)
                                                {{ $item->warehouse->warehouse_name }}
                                                <div class="small text-muted">{{ $item->warehouse->warehouse_code }}</div>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ number_format((float) $item->qty, 4) }}</td>
                                        <td class="text-end">{{ number_format((float) $item->rate, 2) }}</td>
                                        <td class="text-end fw-bold text-warning" style="background:#fff7ed;">
                                            {{ number_format((float) $item->original_cost, 2) }}
                                        </td>
                                        <td class="text-end">{{ number_format($itemRevenue, 2) }}</td>
                                        <td class="text-end text-muted">{{ number_format($itemCogs, 2) }}</td>
                                        <td class="text-center">
                                            {{-- Phase 5.1 — use SalesReturnItem condition helpers (isGood/isDamage/conditionLabel). --}}
                                            @if ($item->isDamage())
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle"
                                                      title="Damaged — auto written off via linked damage invoice (net zero stock movement)">
                                                    <i class="fas fa-triangle-exclamation me-1"></i>{{ $item->conditionLabel() }}
                                                </span>
                                            @else
                                                <span class="badge bg-success-subtle text-success border border-success-subtle"
                                                      title="Good — stock IN at original cost + COGS reversal + revenue reversal">
                                                    <i class="fas fa-check me-1"></i>{{ $item->conditionLabel() }}
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">No items.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="table-warning fw-bold">
                                    <td colspan="5" class="text-end">Totals</td>
                                    <td class="text-end">Tk {{ number_format((float) $r->total_amount, 2) }}</td>
                                    <td class="text-end">Tk {{ number_format((float) $r->cogs_amount, 2) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Stock Movements card (only if confirmed/reversed + has movements) --}}
            @if (($r->isConfirmed() || $r->is_reversed) && $hasStockMovements)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-boxes-stacked me-1 text-warning"></i> Stock movements
                            <span class="badge bg-warning-subtle text-warning ms-1">{{ count($stockMovements) }}</span>
                        </h2>
                        <span class="small text-muted">IN at original avg_cost from the challan</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>TX#</th>
                                        <th>Product</th>
                                        <th>Warehouse</th>
                                        <th class="text-end">Qty</th>
                                        <th class="text-end">Rate (Tk)</th>
                                        <th class="text-end">Value (Tk)</th>
                                        <th class="text-center">Reversed?</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($stockMovements as $st)
                                        @php
                                            $qty = (float) $st->qty;
                                            // Returns = stock IN → qty is positive. Highlight in green.
                                            $qtyClass = $qty >= 0 ? 'text-success fw-bold' : 'text-danger fw-bold';
                                            $whName = $warehouseMap[$st->warehouse_id] ?? ('#' . $st->warehouse_id);
                                        @endphp
                                        <tr class="{{ !empty($st->is_reversed) ? 'table-warning' : '' }}">
                                            <td class="text-nowrap small">
                                                {{ \Carbon\Carbon::parse($st->transaction_date)->format('d M Y') }}
                                            </td>
                                            <td><span class="badge bg-light text-dark">#{{ $st->id }}</span></td>
                                            <td>
                                                <span class="fw-semibold">{{ $st->product_name }}</span>
                                                <div class="small text-muted">{{ $st->product_code }}</div>
                                            </td>
                                            <td>
                                                <span class="small">{{ $whName }}</span>
                                            </td>
                                            <td class="text-end {{ $qtyClass }}">
                                                {{ $qty > 0 ? '+' : '' }}{{ number_format($qty, 4) }}
                                                <span class="badge bg-success-subtle text-success ms-1">IN</span>
                                            </td>
                                            <td class="text-end text-warning fw-bold" title="Original avg_cost from challan">
                                                {{ number_format((float) $st->rate, 2) }}
                                            </td>
                                            <td class="text-end">{{ number_format((float) $st->total_value, 2) }}</td>
                                            <td class="text-center">
                                                @if (!empty($st->is_reversed))
                                                    <span class="badge bg-danger">
                                                        <i class="fas fa-rotate-left me-1"></i>Yes
                                                    </span>
                                                @else
                                                    <span class="badge bg-light text-muted">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Phase 5.2 — Linked damage write-offs card (only if Damage-condition lines with linked damage invoices). --}}
            @php
                $linkedDamageInvoices = $r->items
                    ->filter(fn ($i) => $i->isDamage() && $i->damageInvoice)
                    ->map(fn ($i) => $i->damageInvoice)
                    ->unique('id')
                    ->values();
            @endphp
            @if ($linkedDamageInvoices->isNotEmpty())
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-triangle-exclamation me-1 text-danger"></i> Linked damage write-offs
                            <span class="badge bg-danger-subtle text-danger ms-1">{{ $linkedDamageInvoices->count() }}</span>
                        </h2>
                        <span class="small text-muted">Auto-created for Damage lines (stock OUT + GL Dr Damage Loss / Cr Inventory)</span>
                    </div>
                    <div class="card-body">
                        @foreach ($linkedDamageInvoices as $di)
                            @php
                                $diItems = $r->items->filter(fn ($i) => $i->damage_invoice_id === $di->id);
                            @endphp
                            <div class="border rounded-3 p-3 {{ $loop->last ? '' : 'mb-3' }}">
                                <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
                                    <div>
                                        <a href="{{ route('admin.damages.show', $di) }}" class="text-decoration-none fw-bold">
                                            <i class="fas fa-link me-1"></i>{{ $di->damage_code }}
                                        </a>
                                        @if (!empty($di->is_reversed))
                                            <span class="badge bg-danger ms-1">
                                                <i class="fas fa-rotate-left me-1"></i>Reversed
                                            </span>
                                        @endif
                                    </div>
                                    <div class="small text-muted">
                                        @if ($di->warehouse)
                                            <i class="fas fa-warehouse me-1"></i>{{ $di->warehouse->warehouse_name }}
                                        @endif
                                        @if ($di->damage_date)
                                            · <i class="fas fa-calendar me-1"></i>{{ \Carbon\Carbon::parse($di->damage_date)->format('d M Y') }}
                                        @endif
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-sm table-striped align-middle mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Product</th>
                                                <th class="text-end">Qty</th>
                                                <th class="text-end">Rate (Tk)</th>
                                                <th class="text-end">Value (Tk)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($diItems as $item)
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
                                                    <td class="text-end">{{ number_format((float) $item->qty * (float) $item->rate, 2) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                        <tfoot>
                                            <tr class="table-light fw-bold">
                                                <td class="text-end" colspan="3">Damage write-off total</td>
                                                <td class="text-end">Tk {{ number_format((float) $di->total_value, 2) }}</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                @if ($di->reason)
                                    <div class="small text-muted mt-2">
                                        <i class="fas fa-comment me-1"></i>{!! nl2br(e($di->reason)) !!}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Revenue Reversal GL card (only if confirmed + has JE) --}}
            @if ($r->isConfirmed() && $revJe)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-book me-1 text-primary"></i> Revenue Reversal GL
                            <span class="badge bg-primary-subtle text-primary ms-1">Dr Sales Return / Cr AR</span>
                        </h2>
                        @if ($revJe->is_reversed)
                            <span class="badge bg-danger">
                                <i class="fas fa-rotate-left me-1"></i>Entry reversed
                            </span>
                        @endif
                    </div>
                    <div class="card-body">
                        <dl class="row mb-3 small">
                            <dt class="col-sm-2 text-muted">JE#</dt>
                            <dd class="col-sm-4">
                                <span class="badge bg-secondary-subtle text-secondary">{{ $revJe->entry_no }}</span>
                            </dd>
                            <dt class="col-sm-2 text-muted">Entry date</dt>
                            <dd class="col-sm-4">{{ \Carbon\Carbon::parse($revJe->entry_date)->format('d M Y') }}</dd>
                            <dt class="col-sm-2 text-muted">Description</dt>
                            <dd class="col-sm-10">{{ $revJe->description ?: '—' }}</dd>
                        </dl>

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
                                    @foreach ($revJeLines as $line)
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
                                                {{ (float) $line->debit > 0 ? number_format((float) $line->debit, 2) : '—' }}
                                            </td>
                                            <td class="text-end">
                                                {{ (float) $line->credit > 0 ? number_format((float) $line->credit, 2) : '—' }}
                                            </td>
                                            <td class="small">{{ $line->memo ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td class="text-end">Total</td>
                                        <td class="text-end">{{ number_format($revDebitTotal, 2) }}</td>
                                        <td class="text-end">{{ number_format($revCreditTotal, 2) }}</td>
                                        <td>
                                            @if (abs($revDebitTotal - $revCreditTotal) < 0.01)
                                                <span class="badge bg-success">
                                                    <i class="fas fa-check me-1"></i>Balanced
                                                </span>
                                            @else
                                                <span class="badge bg-danger">
                                                    <i class="fas fa-triangle-exclamation me-1"></i>Out by
                                                    {{ number_format(abs($revDebitTotal - $revCreditTotal), 2) }}
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- COGS Reversal GL card (only if confirmed + has JE) --}}
            @if ($r->isConfirmed() && $cogsJe)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-book me-1 text-danger"></i> COGS Reversal GL
                            <span class="badge bg-danger-subtle text-danger ms-1">Dr Inventory / Cr COGS</span>
                        </h2>
                        @if ($cogsJe->is_reversed)
                            <span class="badge bg-danger">
                                <i class="fas fa-rotate-left me-1"></i>Entry reversed
                            </span>
                        @endif
                    </div>
                    <div class="card-body">
                        <dl class="row mb-3 small">
                            <dt class="col-sm-2 text-muted">JE#</dt>
                            <dd class="col-sm-4">
                                <span class="badge bg-secondary-subtle text-secondary">{{ $cogsJe->entry_no }}</span>
                            </dd>
                            <dt class="col-sm-2 text-muted">Entry date</dt>
                            <dd class="col-sm-4">{{ \Carbon\Carbon::parse($cogsJe->entry_date)->format('d M Y') }}</dd>
                            <dt class="col-sm-2 text-muted">Description</dt>
                            <dd class="col-sm-10">{{ $cogsJe->description ?: '—' }}</dd>
                        </dl>

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
                                    @foreach ($cogsJeLines as $line)
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
                                                {{ (float) $line->debit > 0 ? number_format((float) $line->debit, 2) : '—' }}
                                            </td>
                                            <td class="text-end">
                                                {{ (float) $line->credit > 0 ? number_format((float) $line->credit, 2) : '—' }}
                                            </td>
                                            <td class="small">{{ $line->memo ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td class="text-end">Total</td>
                                        <td class="text-end">{{ number_format($cogsDebitTotal, 2) }}</td>
                                        <td class="text-end">{{ number_format($cogsCreditTotal, 2) }}</td>
                                        <td>
                                            @if (abs($cogsDebitTotal - $cogsCreditTotal) < 0.01)
                                                <span class="badge bg-success">
                                                    <i class="fas fa-check me-1"></i>Balanced
                                                </span>
                                            @else
                                                <span class="badge bg-danger">
                                                    <i class="fas fa-triangle-exclamation me-1"></i>Out by
                                                    {{ number_format(abs($cogsDebitTotal - $cogsCreditTotal), 2) }}
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Customer Ledger Entries card (only if confirmed + has entries) --}}
            @if ($r->isConfirmed() && $hasCustomerLedger)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-users me-1 text-primary"></i> Customer ledger entries
                            <span class="badge bg-primary-subtle text-primary ms-1">{{ count($customerLedgerEntries) }}</span>
                        </h2>
                        <span class="small text-muted">Credit = customer owes less</span>
                    </div>
                    <div class="card-body p-0">
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
                                    @foreach ($customerLedgerEntries as $cl)
                                        <tr>
                                            <td class="text-nowrap small">
                                                {{ \Carbon\Carbon::parse($cl->transaction_date)->format('d M Y') }}
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">{{ ucfirst(str_replace('_', ' ', $cl->transaction_type)) }}</span>
                                            </td>
                                            <td class="text-end">
                                                {{ (float) $cl->debit > 0 ? number_format((float) $cl->debit, 2) : '—' }}
                                            </td>
                                            <td class="text-end text-success">
                                                {{-- Returns credit the customer (they owe less) → highlight credit in green. --}}
                                                {{ (float) $cl->credit > 0 ? number_format((float) $cl->credit, 2) : '—' }}
                                            </td>
                                            <td class="text-end">{{ number_format((float) $cl->balance, 2) }}</td>
                                            <td class="small">{{ $cl->description ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Right: actions aside --}}
        <div class="col-lg-4">
            {{-- Status & actions card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-gear me-1 text-secondary"></i> Status &amp; actions</h2>
                </div>
                <div class="card-body">
                    <div class="text-center mb-3">
                        <div class="text-muted small mb-1">Current status</div>
                        <div class="mb-2">{!! $statusBadge(true) !!}</div>
                        <div class="text-muted small">
                            @if ($r->salesInvoice)
                                <i class="fas fa-link me-1"></i>Against invoice
                                <a href="{{ route('admin.sales-invoices.show', $r->salesInvoice) }}"
                                   class="text-decoration-none">{{ $r->salesInvoice->invoice_code }}</a>
                            @endif
                        </div>
                    </div>

                    {{-- CONFIRM (created only) --}}
                    @if ($r->isCreated())
                        <form method="POST" action="{{ route('admin.sales-returns.confirm', $r) }}"
                              id="confirmForm">
                            @csrf
                            <button type="button" class="btn btn-info w-100 mb-2" id="confirmBtn">
                                <i class="fas fa-circle-check me-1"></i> Confirm Return
                            </button>
                        </form>
                        <div class="alert alert-warning small mb-3">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Confirming will <strong>restore stock at the original avg_cost</strong> (NOT current),
                            <strong>post two GL journals</strong> (Dr Sales Return / Cr AR + Dr Inventory / Cr COGS),
                            and <strong>credit the customer ledger</strong> (customer owes less).
                        </div>
                    @endif

                    {{-- REVERSE (confirmed only) --}}
                    @if ($r->isConfirmed())
                        <form method="POST" action="{{ route('admin.sales-returns.reverse', $r) }}"
                              id="reverseForm">
                            @csrf
                            <input type="hidden" name="reverse_reason" id="reverseReasonField" value="">
                            <button type="button" class="btn btn-outline-danger w-100" id="reverseBtn"
                                    data-reverse-preview-url="{{ route('admin.sales-returns.reverse-preview', $r) }}">
                                <i class="fas fa-rotate-left me-1"></i> Reverse Return
                            </button>
                        </form>
                        <div class="alert alert-danger small mt-2 mb-0">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Reversing will <strong>undo stock movements</strong> (stock OUT at original cost),
                            <strong>reverse both GL journal entries</strong>, and
                            <strong>reverse customer ledger entries</strong>. A reason is required.
                        </div>
                    @endif

                    @if ($r->isCreated())
                        <div class="alert alert-secondary small mt-3 mb-0">
                            <i class="fas fa-pen-to-square me-1"></i>
                            This return is in <strong>created</strong> state — no stock movement, no GL, no customer ledger
                            posted yet. Confirm to apply.
                        </div>
                    @endif

                    @if ($r->isConfirmed())
                        <div class="alert alert-info small mt-3 mb-0">
                            <i class="fas fa-circle-check me-1"></i>
                            <strong>Stock restored at original cost.</strong> Both GL journals posted. Customer ledger credited.
                        </div>
                    @endif

                    @if ($r->isReversed())
                        <div class="alert alert-secondary small mb-0">
                            <i class="fas fa-ban me-1"></i>
                            This return is reversed and cannot be modified further.
                        </div>
                    @endif
                </div>
            </div>

            {{-- Revenue vs COGS summary card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-scale-balanced me-1 text-warning"></i> Revenue vs COGS</h2>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">
                            <i class="fas fa-arrow-down me-1 text-warning"></i>Revenue reversal
                        </span>
                        <strong class="text-warning">Tk {{ number_format((float) $r->total_amount, 2) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-2 border-bottom">
                        <span class="text-muted">
                            <i class="fas fa-arrow-down me-1 text-danger"></i>COGS reversal
                        </span>
                        <strong class="text-danger">Tk {{ number_format((float) $r->cogs_amount, 2) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-2">
                        <span class="text-muted">
                            <i class="fas fa-equals me-1 text-success"></i>Gross margin reversed
                        </span>
                        <strong class="text-success">Tk {{ number_format($grossMargin, 2) }}</strong>
                    </div>
                    <div class="small text-muted mt-2 pt-2 border-top">
                        <i class="fas fa-circle-info me-1"></i>
                        Margin = revenue − COGS. Negative margin reversal means the original sale was loss-making;
                        positive means profit-making.
                    </div>
                </div>
            </div>

            {{-- Quick facts card --}}
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-muted"></i> Quick facts</h2>
                </div>
                <div class="card-body small">
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Items</span>
                        <strong>{{ $r->items->count() }}</strong>
                    </div>
                    {{-- Phase 5.3 — Good/Damage breakdown (only when damage > 0), mirroring Purchase Return's Quick facts. --}}
                    @php
                        $goodCount   = $r->items->filter(fn ($i) => !$i->isDamage())->count();
                        $damageCount = $r->items->filter(fn ($i) => $i->isDamage())->count();
                        $goodQty     = (float) $r->items->filter(fn ($i) => !$i->isDamage())->sum(fn ($i) => (float) $i->qty);
                        $damageQty   = (float) $r->items->filter(fn ($i) => $i->isDamage())->sum(fn ($i) => (float) $i->qty);
                    @endphp
                    @if ($damageCount > 0)
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <span class="text-muted">
                                <i class="fas fa-check text-success me-1"></i>Good lines
                            </span>
                            <strong>
                                {{ $goodCount }} <span class="text-muted small">({{ number_format($goodQty, 4) }} units · stock IN)</span>
                            </strong>
                        </div>
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <span class="text-muted">
                                <i class="fas fa-triangle-exclamation text-danger me-1"></i>Damage lines
                            </span>
                            <strong>
                                {{ $damageCount }} <span class="text-muted small">({{ number_format($damageQty, 4) }} units · written off)</span>
                            </strong>
                        </div>
                    @endif
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Revenue total</span>
                        <strong>Tk {{ number_format((float) $r->total_amount, 2) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">COGS total</span>
                        <strong>Tk {{ number_format((float) $r->cogs_amount, 2) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Stock movements</span>
                        <strong>{{ is_countable($stockMovements) ? count($stockMovements) : 0 }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Customer ledger</span>
                        <strong>{{ is_countable($customerLedgerEntries) ? count($customerLedgerEntries) : 0 }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Revenue GL journal</span>
                        @if ($revJe)
                            <strong>{{ $revJe->entry_no }}</strong>
                        @else
                            <span class="text-muted">Not posted</span>
                        @endif
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">COGS GL journal</span>
                        @if ($cogsJe)
                            <strong>{{ $cogsJe->entry_no }}</strong>
                        @else
                            <span class="text-muted">Not posted</span>
                        @endif
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Reversed</span>
                        @if ($r->is_reversed)
                            <span class="badge bg-danger">Yes</span>
                        @else
                            <span class="text-muted">No</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    // ====== Confirm (created → confirmed) ======
    $('#confirmBtn').on('click', function () {
        Swal.fire({
            icon: 'warning',
            title: 'Confirm this sales return?',
            html: '<p class="text-start">Confirming will <strong>restore stock at the ORIGINAL avg_cost</strong> ' +
                  '(not current avg_cost), <strong>post two GL journals</strong> ' +
                  '(Dr Sales Return / Cr AR + Dr Inventory / Cr COGS), ' +
                  'and <strong>credit the customer ledger</strong> (customer owes less).</p>' +
                  '<p class="text-start text-muted small mb-0">This action cannot be undone from the return — ' +
                  'reversing will undo all postings.</p>',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check"></i> Confirm Return',
            confirmButtonColor: '#0ea5e9',
            cancelButtonText: 'Keep draft',
            reverseButtons: true
        }).then(function (result) {
            if (result.isConfirmed) {
                var $btn = $('#confirmBtn');
                $btn.prop('disabled', true)
                    .html('<i class="fas fa-spinner fa-spin me-1"></i> Confirming…');
                $('#confirmForm').submit();
            }
        });
    });

    // ====== Reverse (confirmed → reversed) — Phase 6.2 pre-check UX ======
    // Before opening the reason dialog, AJAX-fetch the reverse-preview. If
    // blocked (insufficient stock), show a friendly error Swal listing every
    // shortage instead of a mid-transaction RuntimeException. If clear,
    // proceed to the normal reason-textarea Swal with a compact preview.
    $('#reverseBtn').on('click', function () {
        var previewUrl = $(this).data('reverse-preview-url');
        if (!previewUrl) {
            Swal.fire('Error', 'Reverse-preview URL missing.', 'error');
            return;
        }

        // 1. Loading state while we fetch the pre-check.
        Swal.fire({
            title: 'Checking stock availability…',
            allowOutsideClick: false,
            didOpen: function () { Swal.showLoading(); }
        });

        $.ajax({
            url: previewUrl,
            method: 'GET',
            dataType: 'json'
        }).done(function (resp) {
            Swal.close();

            // 2. Blocked — show every shortage, no confirm button.
            if (!resp || resp.can_reverse === false) {
                var msgs = (resp && resp.block_messages && resp.block_messages.length)
                    ? resp.block_messages
                    : ['This return cannot be reversed right now.'];
                var listHtml = '<ul class="text-start mb-0 ps-3">' +
                    msgs.map(function (m) {
                        return '<li class="small mb-1">' + $('<div>').text(m).html() + '</li>';
                    }).join('') +
                    '</ul>';
                Swal.fire({
                    icon: 'error',
                    title: 'Cannot reverse — stock shortage',
                    html: '<p class="text-start mb-2">The following stock movements cannot be reversed because the warehouse no longer has enough on hand:</p>' +
                          listHtml +
                          '<p class="text-start text-muted small mt-2 mb-0">Adjust stock (e.g. via a stock transfer in) or remove the blocking sale, then try again.</p>',
                    confirmButtonText: 'Close',
                    confirmButtonColor: '#dc3545'
                });
                return;
            }

            // 3. Clear — build a compact preview summary for the reason dialog.
            var p = (resp && resp.preview) || {};
            var stockCount = (p.stock_movements && p.stock_movements.length) || 0;
            var ledgerCount = (p.customer_ledger && p.customer_ledger.length) || 0;
            var glCount = (p.gl_journals && p.gl_journals.length) || 0;
            var dmgCount = (p.linked_damage_invoices && p.linked_damage_invoices.length) || 0;
            var previewHtml =
                '<p class="text-start">This will <strong>reverse stock movements</strong> (stock OUT at original cost), ' +
                '<strong>reverse both GL journal entries</strong> (revenue + COGS), and ' +
                '<strong>reverse customer ledger entries</strong>. A reason is required.</p>' +
                '<div class="text-start small text-muted border-top pt-2 mb-2">' +
                '<i class="fas fa-list-check me-1"></i>Will reverse: ' +
                stockCount + ' stock movement' + (stockCount === 1 ? '' : 's') +
                (glCount ? ', ' + glCount + ' GL journal' + (glCount === 1 ? '' : 's') : '') +
                (ledgerCount ? ', ' + ledgerCount + ' ledger entr' + (ledgerCount === 1 ? 'y' : 'ies') : '') +
                (dmgCount ? ', ' + dmgCount + ' linked damage write-off' + (dmgCount === 1 ? '' : 's') : '') +
                '.</div>';

            Swal.fire({
                icon: 'warning',
                title: 'Reverse this confirmed return?',
                html: previewHtml,
                input: 'textarea',
                inputLabel: 'Reverse reason (required)',
                inputPlaceholder: 'Why is this return being reversed?',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-rotate-left"></i> Reverse Return',
                confirmButtonColor: '#dc3545',
                cancelButtonText: 'Keep',
                reverseButtons: true,
                inputValidator: function (value) {
                    if (!value || !value.trim()) {
                        return 'A reverse reason is required.';
                    }
                    return null;
                }
            }).then(function (result) {
                if (result.isConfirmed) {
                    $('#reverseReasonField').val(result.value.trim());
                    var $btn = $('#reverseBtn');
                    $btn.prop('disabled', true)
                        .html('<i class="fas fa-spinner fa-spin me-1"></i> Reversing…');
                    $('#reverseForm').submit();
                }
            });
        }).fail(function (xhr) {
            var msg = 'Could not load the reverse preview.';
            if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
            Swal.fire({
                icon: 'error',
                title: 'Preview failed',
                text: msg
            });
        });
    });
});
</script>
@endpush
@endsection

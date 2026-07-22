@extends('layouts.admin')

@push('css')
<link rel="stylesheet" href="/assets/css/purchase-return-index.css">
<link rel="stylesheet" href="/assets/css/purchase-index.css">
<link rel="stylesheet" href="/assets/css/purchase-order-details.css">
@endpush

@section('content')
@php
    $r = $return;

    // Status badges — Phase 7.3 uses warning/info/secondary for purchase returns.
    $statusBadge = function (bool $large = false) use ($r): string {
        $cls = $large ? ' fs-5' : ' fs-6';
        return [
            'draft'     => '<span class="badge bg-warning text-dark' . $cls . '"><i class="fas fa-pen-to-square me-1"></i>Draft</span>',
            'confirmed' => '<span class="badge bg-info text-dark' . $cls . '"><i class="fas fa-circle-check me-1"></i>Confirmed</span>',
            'cancelled' => '<span class="badge bg-secondary' . $cls . '"><i class="fas fa-ban me-1"></i>Cancelled</span>',
        ][$r->status] ?? '<span class="badge bg-light text-dark' . $cls . '">' . e($r->status) . '</span>';
    };

    // Journal entry + lines
    $je          = $r->journalEntry;
    $jeLines     = $je ? $je->lines : collect();
    $debitTotal  = $jeLines->sum(fn ($l) => (float) $l->debit);
    $creditTotal = $jeLines->sum(fn ($l) => (float) $l->credit);

    // Warehouse lookup for stock-movements table (st only joins products, not warehouses).
    $warehouseMap = \App\Models\Warehouse::pluck('warehouse_name', 'id');

    $hasStockMovements = !empty($stockMovements) && is_countable($stockMovements) && count($stockMovements) > 0;
    $hasSupplierLedger = !empty($supplierLedgerEntries) && is_countable($supplierLedgerEntries) && count($supplierLedgerEntries) > 0;
@endphp

<div class="purchase-return-app container-fluid py-2">
    {{-- Hero header (amber) --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#d97706,#b45309);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-truck-arrow-right me-2"></i>Return {{ $r->return_code }}
                {!! $statusBadge() !!}
            </h1>
            <p class="mb-0 small opacity-75">
                @if ($r->supplier){{ $r->supplier->supplier_name }}@endif
                @if ($r->branch) · {{ $r->branch->branch_name }}@endif
                · {{ \Carbon\Carbon::parse($r->return_date)->format('d M Y') }}
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            {{-- Phase 6: Slip button — opens printable slip in a new tab --}}
            <a href="{{ route('admin.purchase-returns.slip', $r) }}" target="_blank"
               class="btn btn-outline-light btn-sm" id="printSlipBtn"
               title="Print return slip">
                <i class="fas fa-print me-1"></i> Slip
            </a>
            <a href="{{ route('admin.purchase-returns.index') }}" class="btn btn-outline-light btn-sm">
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

                        <dt class="col-sm-3 text-muted">GRN code</dt>
                        <dd class="col-sm-9">
                            @if ($r->purchaseReceive)
                                <a href="{{ route('admin.purchase-receives.show', $r->purchaseReceive) }}"
                                   class="text-decoration-none">
                                    <span class="badge bg-secondary-subtle text-secondary">
                                        {{ $r->purchaseReceive->receive_code }}
                                    </span>
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Supplier</dt>
                        <dd class="col-sm-9">
                            @if ($r->supplier)
                                <strong>{{ $r->supplier->supplier_name }}</strong>
                                <span class="text-muted">({{ $r->supplier->supplier_code }})</span>
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

                        <dt class="col-sm-3 text-muted">Total amount</dt>
                        <dd class="col-sm-9">
                            <strong class="text-warning fs-5">Tk {{ number_format((float) $r->total_amount, 2) }}</strong>
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
                                    <th class="text-end">Rate (Tk)</th>
                                    <th class="text-end">Amount (Tk)</th>
                                    <th class="text-center">Condition</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($r->items as $item)
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
                                            @if ($item->warehouse && !$item->isDamage())
                                                {{ $item->warehouse->warehouse_name }}
                                                <div class="small text-muted">{{ $item->warehouse->warehouse_code }}</div>
                                            @elseif ($item->isDamage())
                                                <span class="text-muted">—</span>
                                                <div class="small text-muted">N/A (Damage)</div>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ number_format((float) $item->qty, 4) }}</td>
                                        <td class="text-end">{{ number_format((float) $item->rate, 2) }}</td>
                                        <td class="text-end">{{ number_format((float) $item->amount(), 2) }}</td>
                                        <td class="text-center">
                                            @if ($item->isDamage())
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle"
                                                      title="Supplier claim only — no stock movement">
                                                    <i class="fas fa-triangle-exclamation me-1"></i>Damage
                                                </span>
                                            @else
                                                <span class="badge bg-success-subtle text-success border border-success-subtle"
                                                      title="Stock OUT + GL + supplier ledger">
                                                    <i class="fas fa-check me-1"></i>Good
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">No items.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="table-warning fw-bold">
                                    <td colspan="4" class="text-end">Total amount</td>
                                    <td class="text-end">Tk {{ number_format((float) $r->total_amount, 2) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Stock Movements card (only if confirmed + has movements) --}}
            @if (($r->isConfirmed() || $r->is_reversed) && $hasStockMovements)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-boxes-stacked me-1 text-warning"></i> Stock movements
                            <span class="badge bg-warning-subtle text-warning ms-1">{{ count($stockMovements) }}</span>
                        </h2>
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
                                            // Returns = stock OUT → qty is negative. Highlight in red.
                                            $qtyClass = $qty < 0 ? 'text-danger fw-bold' : 'text-success fw-bold';
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
                                            </td>
                                            <td class="text-end">{{ number_format((float) $st->rate, 2) }}</td>
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

            {{-- GL Journal Entry card (only if confirmed + has JE) --}}
            @if ($r->isConfirmed() && $je)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-book me-1 text-primary"></i> GL Journal Entry
                        </h2>
                        @if ($je->is_reversed)
                            <span class="badge bg-danger">
                                <i class="fas fa-rotate-left me-1"></i>Entry reversed
                            </span>
                        @endif
                    </div>
                    <div class="card-body">
                        <dl class="row mb-3 small">
                            <dt class="col-sm-2 text-muted">JE#</dt>
                            <dd class="col-sm-4">
                                <span class="badge bg-secondary-subtle text-secondary">{{ $je->entry_no }}</span>
                            </dd>
                            <dt class="col-sm-2 text-muted">Entry date</dt>
                            <dd class="col-sm-4">{{ \Carbon\Carbon::parse($je->entry_date)->format('d M Y') }}</dd>
                            <dt class="col-sm-2 text-muted">Description</dt>
                            <dd class="col-sm-10">{{ $je->description ?: '—' }}</dd>
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
                                    @foreach ($jeLines as $line)
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
                                        <td class="text-end">{{ number_format($debitTotal, 2) }}</td>
                                        <td class="text-end">{{ number_format($creditTotal, 2) }}</td>
                                        <td>
                                            @if (abs($debitTotal - $creditTotal) < 0.01)
                                                <span class="badge bg-success">
                                                    <i class="fas fa-check me-1"></i>Balanced
                                                </span>
                                            @else
                                                <span class="badge bg-danger">
                                                    <i class="fas fa-triangle-exclamation me-1"></i>Out by
                                                    {{ number_format(abs($debitTotal - $creditTotal), 2) }}
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

            {{-- Supplier Ledger Entries card (only if confirmed + has entries) --}}
            @if ($r->isConfirmed() && $hasSupplierLedger)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-truck me-1 text-primary"></i> Supplier ledger entries
                            <span class="badge bg-primary-subtle text-primary ms-1">{{ count($supplierLedgerEntries) }}</span>
                        </h2>
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
                                    @foreach ($supplierLedgerEntries as $sl)
                                        <tr>
                                            <td class="text-nowrap small">
                                                {{ \Carbon\Carbon::parse($sl->transaction_date)->format('d M Y') }}
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">{{ ucfirst(str_replace('_', ' ', $sl->transaction_type)) }}</span>
                                            </td>
                                            <td class="text-end">
                                                {{-- Returns debit the supplier (we owe less) → highlight debit in amber. --}}
                                                {{ (float) $sl->debit > 0 ? number_format((float) $sl->debit, 2) : '—' }}
                                            </td>
                                            <td class="text-end text-success">
                                                {{ (float) $sl->credit > 0 ? number_format((float) $sl->credit, 2) : '—' }}
                                            </td>
                                            <td class="text-end">{{ number_format((float) $sl->balance, 2) }}</td>
                                            <td class="small">{{ $sl->description ?: '—' }}</td>
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
                            @if ($r->purchaseReceive)
                                <i class="fas fa-link me-1"></i>Against GRN
                                <a href="{{ route('admin.purchase-receives.show', $r->purchaseReceive) }}"
                                   class="text-decoration-none">{{ $r->purchaseReceive->receive_code }}</a>
                            @endif
                        </div>
                    </div>

                    {{-- CONFIRM (draft only) --}}
                    @if ($r->isDraft())
                        <form method="POST" action="{{ route('admin.purchase-returns.confirm', $r) }}"
                              id="confirmForm">
                            @csrf
                            <button type="button" class="btn btn-info w-100 mb-2" id="confirmBtn">
                                <i class="fas fa-circle-check me-1"></i> Confirm Return
                            </button>
                        </form>
                        <div class="alert alert-warning small mb-3">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Confirming will <strong>return stock to supplier</strong> (OUT at original rate),
                            <strong>post GL</strong> (Dr AP / Cr Inventory), and
                            <strong>update supplier ledger</strong> (debit).
                        </div>
                    @endif

                    {{-- CANCEL (draft or confirmed) --}}
                    @if ($r->isDraft() || $r->isConfirmed())
                        <form method="POST" action="{{ route('admin.purchase-returns.cancel', $r) }}"
                              id="cancelForm">
                            @csrf
                            <input type="hidden" name="cancel_reason" id="cancelReasonField" value="">
                            <button type="button" class="btn btn-outline-danger w-100" id="cancelBtn">
                                <i class="fas fa-ban me-1"></i>
                                @if ($r->isConfirmed())
                                    Cancel &amp; reverse
                                @else
                                    Cancel draft
                                @endif
                            </button>
                        </form>
                        @if ($r->isConfirmed())
                            <div class="alert alert-danger small mt-2 mb-0">
                                <i class="fas fa-triangle-exclamation me-1"></i>
                                Cancelling a confirmed return <strong>reverses stock movements, the GL journal entry,
                                and supplier ledger entries</strong>. A reason is required.
                            </div>
                        @endif
                    @endif

                    @if ($r->isCancelled())
                        <div class="alert alert-secondary small mb-0">
                            <i class="fas fa-ban me-1"></i>
                            This return is cancelled and cannot be modified further.
                        </div>
                    @endif

                    @if ($r->isConfirmed())
                        <div class="alert alert-info small mt-3 mb-0">
                            <i class="fas fa-circle-check me-1"></i>
                            <strong>Stock returned.</strong> GL posted (Dr AP / Cr Inventory). Supplier ledger debited.
                        </div>
                    @endif
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
                    @php
                        $goodCount = $r->items->filter(fn($i) => !$i->isDamage())->count();
                        $damageCount = $r->items->filter(fn($i) => $i->isDamage())->count();
                        $goodQty = (float) $r->items->filter(fn($i) => !$i->isDamage())->sum(fn($i) => (float) $i->qty);
                        $damageQty = (float) $r->items->filter(fn($i) => $i->isDamage())->sum(fn($i) => (float) $i->qty);
                    @endphp
                    @if ($damageCount > 0)
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <span class="text-muted">
                                <i class="fas fa-check text-success me-1"></i>Good lines
                            </span>
                            <strong>
                                {{ $goodCount }} <span class="text-muted small">({{ number_format($goodQty, 4) }} units · stock OUT)</span>
                            </strong>
                        </div>
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <span class="text-muted">
                                <i class="fas fa-triangle-exclamation text-danger me-1"></i>Damage lines
                            </span>
                            <strong>
                                {{ $damageCount }} <span class="text-muted small">({{ number_format($damageQty, 4) }} units · no stock move)</span>
                            </strong>
                        </div>
                    @endif
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Total amount</span>
                        <strong>Tk {{ number_format((float) $r->total_amount, 2) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Stock movements</span>
                        <strong>{{ is_countable($stockMovements) ? count($stockMovements) : 0 }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Supplier ledger</span>
                        <strong>{{ is_countable($supplierLedgerEntries) ? count($supplierLedgerEntries) : 0 }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">GL journal</span>
                        @if ($je)
                            <strong>{{ $je->entry_no }}</strong>
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
    // ====== Phase 6: Print slip button now opens the real slip route in a new tab — no JS handler needed. ======

    // ====== Confirm (draft → confirmed) ======
    $('#confirmBtn').on('click', function () {
        Swal.fire({
            icon: 'warning',
            title: 'Confirm this purchase return?',
            html: '<p class="text-start">Confirming will <strong>return stock to the supplier</strong> ' +
                  '(OUT at the original receive rate), <strong>post GL</strong> (Dr Accounts Payable / Cr Inventory), ' +
                  'and <strong>update the supplier ledger</strong> (debit — we owe the supplier less).</p>' +
                  '<p class="text-start text-muted small mb-0">This action cannot be undone from the return — ' +
                  'cancelling will reverse all postings.</p>',
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

    // ====== Cancel (draft or confirmed) ======
    $('#cancelBtn').on('click', function () {
        var isConfirmed = @json($r->isConfirmed());
        var title = isConfirmed ? 'Cancel & reverse this return?' : 'Cancel this draft return?';
        var html  = isConfirmed
            ? '<p class="text-start">This will <strong>reverse stock movements, the GL journal entry, ' +
              'and supplier ledger entries</strong>. A reason is required.</p>'
            : '<p class="text-start">The draft will be marked cancelled. A reason is required.</p>';

        Swal.fire({
            icon: 'warning',
            title: title,
            html: html,
            input: 'textarea',
            inputLabel: 'Cancel reason (required)',
            inputPlaceholder: 'Why is this return being cancelled?',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-ban"></i> Cancel Return',
            confirmButtonColor: '#dc3545',
            cancelButtonText: 'Keep',
            reverseButtons: true,
            inputValidator: function (value) {
                if (!value || !value.trim()) {
                    return 'A cancel reason is required.';
                }
                return null;
            }
        }).then(function (result) {
            if (result.isConfirmed) {
                $('#cancelReasonField').val(result.value.trim());
                var $btn = $('#cancelBtn');
                $btn.prop('disabled', true)
                    .html('<i class="fas fa-spinner fa-spin me-1"></i> Cancelling…');
                $('#cancelForm').submit();
            }
        });
    });
});
</script>
@endpush
@endsection

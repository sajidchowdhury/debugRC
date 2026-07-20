@extends('layouts.admin')

@section('content')
@php
    $listUrl  = url('/admin/stock/transactions');
    $stockUrl = url('/admin/stock');

    $refColors = [
        'purchase_receive'  => 'bg-success',
        'purchase_return'   => 'bg-warning text-dark',
        'sales_challan'     => 'bg-danger',
        'sales_return'      => 'bg-info text-dark',
        'stock_adjustment'  => 'bg-secondary',
        'stock_take'        => 'bg-dark',
        'warehouse_transfer'=> 'bg-primary',
        'damage'            => 'bg-danger',
        'branch_demand'     => 'bg-info text-dark',
        'opening_balance'   => 'bg-light text-dark border',
        'reversal'          => 'bg-warning text-dark',
    ];

    $tx = $transaction;
    $refKey   = $tx->reference_type;
    $refLabel = $referenceTypeLabels[$refKey] ?? ucwords(str_replace('_', ' ', $refKey));
    $refColor = $refColors[$refKey] ?? 'bg-secondary';

    $isIn      = (float) $tx->qty > 0;
    $isOut     = (float) $tx->qty < 0;
    $qtyClass  = $isIn ? 'text-success' : ($isOut ? 'text-danger' : 'text-muted');
    $qtySign   = $isIn ? '+' . number_format($tx->qty, 2) : number_format($tx->qty, 2);

    $isReversalRow = !empty($tx->reversal_of_transaction_id); // this TX reverses another
    $wasReversed   = (bool) $tx->is_reversed;                 // this TX was reversed
@endphp

<div class="container-fluid py-2">

    {{-- ===================== HERO HEADER ===================== --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white" style="background:linear-gradient(135deg,#7c2d12,#ea580c);">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-receipt me-2"></i>
                Stock Transaction #{{ $tx->id }}
            </h1>
            <p class="mb-0 opacity-75">
                {{ $refLabel }} ·
                {{ \Carbon\Carbon::parse($tx->transaction_date)->format('d M Y') }}
            </p>
        </div>
        <div class="d-flex flex-wrap gap-1">
            <a href="{{ $listUrl }}" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to ledger
            </a>
        </div>
    </header>

    {{-- ===================== REVERSAL BANNER ===================== --}}
    @if ($wasReversed)
        <div class="alert alert-danger d-flex align-items-start gap-2" role="alert">
            <i class="fas fa-triangle-exclamation fa-lg mt-1"></i>
            <div class="flex-grow-1">
                <strong>This transaction was reversed.</strong>
                <div class="mt-1 small">
                    @if ($tx->reversed_at)
                        <span class="me-3"><i class="fas fa-clock me-1"></i>
                            Reversed on {{ \Carbon\Carbon::parse($tx->reversed_at)->format('d M Y H:i') }}
                        </span>
                    @endif
                    @if ($tx->reversed_by)
                        <span class="me-3"><i class="fas fa-user me-1"></i>By: {{ $tx->reversed_by }}</span>
                    @endif
                </div>
                @if ($tx->reverse_reason)
                    <div class="mt-1">
                        <i class="fas fa-quote-left me-1 text-muted"></i>
                        {{ $tx->reverse_reason }}
                    </div>
                @endif
                @if ($reversal)
                    <div class="mt-2">
                        <a href="{{ $stockUrl . '/' . $reversal->id }}" class="btn btn-sm btn-outline-danger">
                            <i class="fas fa-arrow-right me-1"></i> View reversal transaction #{{ $reversal->id }}
                        </a>
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if ($isReversalRow)
        <div class="alert alert-info d-flex align-items-start gap-2" role="alert">
            <i class="fas fa-rotate-left fa-lg mt-1"></i>
            <div class="flex-grow-1">
                <strong>This is a reversal of transaction #{{ $tx->reversal_of_transaction_id }}.</strong>
                <div class="mt-1 small text-muted">
                    Reversal rows carry the opposite-signed quantity of the original entry, neutralising
                    its effect on inventory and average cost.
                </div>
                <div class="mt-2">
                    <a href="{{ $stockUrl . '/' . $tx->reversal_of_transaction_id }}" class="btn btn-sm btn-outline-info">
                        <i class="fas fa-arrow-left me-1"></i> View original transaction #{{ $tx->reversal_of_transaction_id }}
                    </a>
                </div>
            </div>
        </div>
    @endif

    {{-- ===================== DETAIL CARD ===================== --}}
    <div class="row g-3">
        {{-- Main details --}}
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0"><i class="fas fa-circle-info me-2"></i>Transaction Details</h2>
                    <span class="badge {{ $refColor }}">{{ $refLabel }}</span>
                </div>
                <div class="card-body">
                    <table class="table table-borderless table-sm mb-0 align-middle">
                        <tbody>
                            <tr>
                                <th style="width:180px;" class="text-muted">Transaction #</th>
                                <td class="fw-semibold">{{ $tx->id }}</td>
                            </tr>
                            <tr>
                                <th class="text-muted">Date</th>
                                <td>{{ \Carbon\Carbon::parse($tx->transaction_date)->format('d M Y') }}</td>
                            </tr>
                            <tr>
                                <th class="text-muted">Product</th>
                                <td>
                                    <div class="fw-semibold">{{ $tx->product->product_name ?? '—' }}</div>
                                    <div class="text-muted small">
                                        Code: <span class="badge bg-light text-dark border">{{ $tx->product->product_code ?? '—' }}</span>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">Warehouse</th>
                                <td>
                                    <div class="fw-semibold">{{ $tx->warehouse->warehouse_name ?? '—' }}</div>
                                    <div class="text-muted small">
                                        Branch: {{ $tx->warehouse->branch->branch_name ?? '—' }}
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">Movement type</th>
                                <td><span class="badge {{ $refColor }}">{{ $refLabel }}</span></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Reference</th>
                                <td>
                                    @if ($tx->reference_id)
                                        <span class="badge bg-light text-dark border">
                                            {{ $refKey }} #{{ $tx->reference_id }}
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">Quantity</th>
                                <td>
                                    <span class="fw-bold fs-5 {{ $qtyClass }}">{{ $qtySign }}</span>
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis ms-2">
                                        {{ $isIn ? 'IN' : ($isOut ? 'OUT' : 'NEUTRAL') }}
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">Rate</th>
                                <td class="text-nowrap">{{ number_format($tx->rate, 2) }} <span class="text-muted small">Tk/unit</span></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Total value</th>
                                <td class="text-nowrap fw-semibold">{{ number_format($tx->total_value, 2) }} <span class="text-muted small">Tk</span></td>
                            </tr>
                            <tr>
                                <th class="text-muted">Notes</th>
                                <td>
                                    @if ($tx->notes)
                                        <span>{{ $tx->notes }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Audit sidebar --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white py-2">
                    <h2 class="h6 mb-0"><i class="fas fa-clock-rotate-left me-2"></i>Audit</h2>
                </div>
                <div class="card-body">
                    <table class="table table-borderless table-sm mb-0 align-middle">
                        <tbody>
                            <tr>
                                <th class="text-muted">Created by</th>
                                <td class="text-end">{{ $tx->created_by ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th class="text-muted">Created at</th>
                                <td class="text-end text-nowrap small">
                                    @if ($tx->created_at)
                                        {{ \Carbon\Carbon::parse($tx->created_at)->format('d M Y H:i') }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <th class="text-muted">Status</th>
                                <td class="text-end">
                                    @if ($tx->is_reversed)
                                        <span class="badge bg-danger-subtle text-danger">
                                            <i class="fas fa-rotate-left me-1"></i>Reversed
                                        </span>
                                    @else
                                        <span class="badge bg-success-subtle text-success">
                                            <i class="fas fa-check me-1"></i>Active
                                        </span>
                                    @endif
                                </td>
                            </tr>
                            @if ($isReversalRow)
                                <tr>
                                    <th class="text-muted">Reversal of</th>
                                    <td class="text-end">
                                        <a href="{{ $stockUrl . '/' . $tx->reversal_of_transaction_id }}" class="text-decoration-none">
                                            #{{ $tx->reversal_of_transaction_id }}
                                        </a>
                                    </td>
                                </tr>
                            @endif
                            @if ($tx->branch_demand_item_id)
                                <tr>
                                    <th class="text-muted">Branch demand item</th>
                                    <td class="text-end">#{{ $tx->branch_demand_item_id }}</td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== REVERSAL DETAILS CARD ===================== --}}
    @if ($wasReversed)
        <div class="card border-0 shadow-sm mt-3 border-start border-danger border-4">
            <div class="card-header bg-white py-2">
                <h2 class="h6 mb-0 text-danger"><i class="fas fa-rotate-left me-2"></i>Reversal Information</h2>
            </div>
            <div class="card-body">
                <table class="table table-borderless table-sm mb-0 align-middle">
                    <tbody>
                        <tr>
                            <th style="width:200px;" class="text-muted">Reversed at</th>
                            <td>
                                @if ($tx->reversed_at)
                                    {{ \Carbon\Carbon::parse($tx->reversed_at)->format('d M Y H:i') }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Reversed by</th>
                            <td>{{ $tx->reversed_by ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Reason</th>
                            <td>{{ $tx->reverse_reason ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Reversal transaction</th>
                            <td>
                                @if ($reversal)
                                    <a href="{{ $stockUrl . '/' . $reversal->id }}" class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-arrow-right me-1"></i> View #{{ $reversal->id }}
                                    </a>
                                @else
                                    <span class="text-muted">Reversal row not found.</span>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection

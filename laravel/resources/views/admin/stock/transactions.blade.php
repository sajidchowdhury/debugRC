@extends('layouts.admin')

@section('content')
@php
    // Reference-type → Bootstrap color class map (solid badges).
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

    $stockUrl = url('/admin/stock');
    $listUrl  = $stockUrl . '/transactions';
@endphp

<div class="container-fluid py-2">

    {{-- ===================== HERO HEADER ===================== --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white" style="background:linear-gradient(135deg,#1e3a8a,#3b82f6);">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-arrow-right-arrow-left me-2"></i>
                Stock Transactions
            </h1>
            <p class="mb-0 opacity-75">Immutable inventory ledger — every stock movement.</p>
        </div>
        <div class="d-flex flex-wrap gap-1">
            <a href="{{ url('/admin/stock/warehouse-stock') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-warehouse me-1"></i> Warehouse Stock
            </a>
            <a href="{{ $listUrl }}" class="btn btn-light btn-sm" title="Reset filters">
                <i class="fas fa-rotate me-1"></i> Reset
            </a>
        </div>
    </header>

    {{-- ===================== SUMMARY STATS ===================== --}}
    @php
        $totalIn  = 0;
        $totalOut = 0;
        foreach ($transactions as $tx) {
            if ($tx->qty > 0) {
                $totalIn  += (float) $tx->qty;
            } else {
                $totalOut += abs((float) $tx->qty);
            }
        }
    @endphp
    <div class="row g-2 mb-3">
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:#1e3a8a;"><i class="fas fa-list"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">{{ number_format($transactions->total()) }}</div>
                        <div class="text-muted small">Transactions (filtered)</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:#16a34a;"><i class="fas fa-arrow-down"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1 text-success">+{{ number_format($totalIn, 2) }}</div>
                        <div class="text-muted small">IN qty (this page)</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:#dc2626;"><i class="fas fa-arrow-up"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1 text-danger">-{{ number_format($totalOut, 2) }}</div>
                        <div class="text-muted small">OUT qty (this page)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== FILTER PANEL ===================== --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-2">
            <h2 class="h6 mb-0"><i class="fas fa-filter me-2"></i>Filters</h2>
        </div>
        <div class="card-body py-3">
            <form method="GET" action="{{ $listUrl }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">Warehouse</label>
                    <select name="warehouse_id" class="form-select form-select-sm select2">
                        <option value="">All warehouses</option>
                        @foreach ($warehouses as $w)
                            <option value="{{ $w->id }}" @selected(($filters['warehouse_id'] ?? '') == $w->id)>
                                {{ $w->branch->branch_name ?? '—' }} — {{ $w->warehouse_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">Product</label>
                    <select name="product_id" class="form-select form-select-sm select2">
                        <option value="">All products</option>
                        @foreach ($products as $p)
                            <option value="{{ $p->id }}" @selected(($filters['product_id'] ?? '') == $p->id)>
                                {{ $p->product_code }} — {{ $p->product_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">Movement type</label>
                    <select name="reference_type" class="form-select form-select-sm select2">
                        <option value="">All types</option>
                        @foreach ($referenceTypes as $key => $label)
                            <option value="{{ $key }}" @selected(($filters['reference_type'] ?? '') === $key)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">From date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm"
                           value="{{ $filters['from_date'] ?? '' }}">
                </div>
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">To date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm"
                           value="{{ $filters['to_date'] ?? '' }}">
                </div>
                <div class="col-sm-6 col-md-2 d-flex align-items-center pt-3">
                    <div class="form-check">
                        <input type="checkbox" name="show_reversed" value="1" class="form-check-input" id="showReversed"
                               @checked(($filters['show_reversed'] ?? '0') == '1' || ($filters['show_reversed'] ?? false) === true)>
                        <label class="form-check-label small" for="showReversed">Include reversed</label>
                    </div>
                </div>
                <div class="col-sm-6 col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                    <a href="{{ $listUrl }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-eraser me-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- ===================== TRANSACTIONS TABLE ===================== --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0"><i class="fas fa-clock-rotate-left me-2"></i>Ledger ({{ number_format($transactions->total()) }})</h2>
            <a href="#" class="btn btn-outline-secondary btn-sm disabled" aria-disabled="true" title="Placeholder — not yet wired">
                <i class="fas fa-file-csv me-1"></i> Export CSV
            </a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="dataTable" class="table table-sm table-striped table-hover align-middle mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>TX#</th>
                            <th>Product</th>
                            <th>Warehouse</th>
                            <th>Type</th>
                            <th>Ref#</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Value</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($transactions as $tx)
                            @php
                                $refKey   = $tx->reference_type;
                                $refLabel = $referenceTypes[$refKey] ?? ucwords(str_replace('_', ' ', $refKey));
                                $refColor = $refColors[$refKey] ?? 'bg-secondary';
                                $qtyClass = $tx->qty > 0 ? 'text-success' : ($tx->qty < 0 ? 'text-danger' : '');
                                $qtySign  = $tx->qty > 0 ? '+' . number_format($tx->qty, 2) : number_format($tx->qty, 2);
                            @endphp
                            <tr>
                                <td class="text-nowrap small">
                                    {{ \Carbon\Carbon::parse($tx->transaction_date)->format('d M Y') }}
                                </td>
                                <td>
                                    <a href="{{ $stockUrl . '/' . $tx->id }}" class="fw-semibold text-decoration-none">
                                        #{{ $tx->id }}
                                    </a>
                                </td>
                                <td>
                                    <div class="fw-semibold">{{ $tx->product->product_name ?? '—' }}</div>
                                    <div class="text-muted small">{{ $tx->product->product_code ?? '' }}</div>
                                </td>
                                <td>
                                    <div>{{ $tx->warehouse->warehouse_name ?? '—' }}</div>
                                    <div class="text-muted small">{{ $tx->warehouse->branch->branch_name ?? '' }}</div>
                                </td>
                                <td>
                                    <span class="badge {{ $refColor }}">{{ $refLabel }}</span>
                                </td>
                                <td class="text-muted small">
                                    @if ($tx->reference_id)
                                        {{ $refKey }} #{{ $tx->reference_id }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-end text-nowrap fw-semibold {{ $qtyClass }}">{{ $qtySign }}</td>
                                <td class="text-end text-nowrap">{{ number_format($tx->rate, 2) }}</td>
                                <td class="text-end text-nowrap">{{ number_format($tx->total_value, 2) }}</td>
                                <td class="text-center">
                                    @if ($tx->is_reversed)
                                        <span class="badge bg-danger-subtle text-danger" title="Reversed">
                                            <i class="fas fa-rotate-left me-1"></i>Reversed
                                        </span>
                                    @else
                                        <span class="badge bg-success-subtle text-success" title="Active">
                                            <i class="fas fa-check me-1"></i>Active
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">
                                    <i class="fas fa-folder-open fa-2x mb-2 d-block opacity-50"></i>
                                    No stock transactions match the current filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($transactions->hasPages())
            <div class="card-footer bg-white py-2">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="text-muted small">
                        Showing {{ $transactions->firstItem() ?? 0 }}–{{ $transactions->lastItem() ?? 0 }}
                        of {{ number_format($transactions->total()) }}
                    </div>
                    <div>{{ $transactions->links() }}</div>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    $('.select2').select2({
        theme: 'bootstrap-5',
        width: '100%',
        allowClear: true,
        placeholder: function () {
            return $(this).find('option:first-child').text();
        }
    });

    $('#dataTable').DataTable({
        paging: false,
        searching: true,
        info: false,
        order: [],
        dom: '<"row"<"col-md-6 text-start"f><"col-md-6 text-end"l>>rtip'
    });
})();
</script>
@endpush

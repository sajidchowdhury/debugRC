@extends('layouts.admin')

@php
    $warehouses = \App\Models\Warehouse::orderBy('warehouse_name')->get();
    $rows       = method_exists($data, 'getCollection') ? $data->getCollection() : $data;
    $totalIn    = $rows->where('qty', '>', 0)->sum('qty');
    $totalOut   = abs($rows->where('qty', '<', 0)->sum('qty'));
    $netQty     = $rows->sum('qty');
@endphp

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-route me-2 text-primary"></i> Product Movement</h2>
            <p class="text-muted mb-0 small">Chronological stock ledger for one or all SKUs — {{ \Carbon\Carbon::parse($meta['from_date'])->format('d M Y') }} → {{ \Carbon\Carbon::parse($meta['to_date'])->format('d M Y') }}.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.reports.productMovement') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">From date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm"
                           value="{{ old('from_date', request('from_date', $meta['from_date'] ?? '')) }}">
                </div>
                <div class="col-sm-6 col-md-2">
                    <label class="form-label small mb-1">To date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm"
                           value="{{ old('to_date', request('to_date', $meta['to_date'] ?? '')) }}">
                </div>
                <div class="col-sm-12 col-md-3">
                    <label class="form-label small mb-1">Product</label>
                    <select name="product_id" class="form-select form-select-sm select2-product">
                        <option value="">All products</option>
                        @foreach ($products as $p)
                            <option value="{{ $p->id }}" @selected((int) old('product_id', request('product_id')) === $p->id)>{{ $p->product_code }} — {{ $p->product_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">Warehouse (optional)</label>
                    <select name="warehouse_id" class="form-select form-select-sm">
                        <option value="">All warehouses</option>
                        @foreach ($warehouses as $w)
                            <option value="{{ $w->id }}" @selected((int) old('warehouse_id', request('warehouse_id')) === $w->id)>{{ $w->warehouse_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Summary cards --}}
    <div class="row g-2 mb-3">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-arrow-down text-success me-1"></i> Total IN Qty</span>
                    <strong class="text-success fs-6">{{ number_format($totalIn, 2) }}</strong>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-arrow-up text-danger me-1"></i> Total OUT Qty</span>
                    <strong class="text-danger fs-6">{{ number_format($totalOut, 2) }}</strong>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-equals text-primary me-1"></i> Net Qty</span>
                    <strong class="text-primary fs-6">{{ number_format($netQty, 2) }}</strong>
                </div>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="moveTable" class="table table-sm table-striped table-hover mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Warehouse</th>
                            <th class="text-center">Type</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Rate</th>
                            <th class="text-end">Total Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data as $r)
                            <tr>
                                <td>{{ \Carbon\Carbon::parse($r->transaction_date)->format('d M Y') }}</td>
                                <td>
                                    <code>{{ $r->product_code }}</code><br>
                                    <small class="text-muted">{{ $r->product_name }}</small>
                                </td>
                                <td>{{ $r->warehouse_name }}</td>
                                <td class="text-center">
                                    <span class="badge bg-secondary">{{ str_replace('_', ' ', $r->reference_type) }}</span>
                                </td>
                                <td class="text-end {{ $r->qty < 0 ? 'text-danger fw-semibold' : 'text-success fw-semibold' }}">
                                    {{ $r->qty >= 0 ? '+' : '' }}{{ number_format($r->qty, 2) }}
                                </td>
                                <td class="text-end">{{ number_format($r->rate, 2) }}</td>
                                <td class="text-end">{{ number_format($r->total_value, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-muted text-center py-3">No stock movements found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if (method_exists($data, 'hasPages') && $data->hasPages())
            <div class="card-footer bg-white py-2">{{ $data->links() }}</div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function() {
    $('.select2-product').select2({
        theme: 'bootstrap-5',
        width: '100%',
        placeholder: '— select product —',
        allowClear: true,
    });
});
</script>
@endpush

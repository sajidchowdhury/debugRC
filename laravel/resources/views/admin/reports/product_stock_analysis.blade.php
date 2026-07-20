@extends('layouts.admin')

@php
    $warehouses = \App\Models\Warehouse::orderBy('warehouse_name')->get();
@endphp

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-microscope me-2 text-primary"></i> Product Stock Analysis</h2>
            <p class="text-muted mb-0 small">On-hand stock valuation (avg cost basis) from the <code>mv_stock_valuation</code> materialized view.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
            <span class="badge bg-info text-dark fs-6">
                <i class="fas fa-bolt me-1"></i> Source: Materialized View
            </span>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.reports.productStockAnalysis') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected((int) old('branch_id', request('branch_id')) === $b->id)>{{ $b->branch_name }}</option>
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
                <div class="card-body py-2 d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:38px;height:38px;background:#14b8a6;"><i class="fas fa-boxes-stacked"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">{{ number_format($totals['total_qty'], 2) }}</div>
                        <small class="text-muted">Total on-hand qty</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:38px;height:38px;background:#6366f1;"><i class="fas fa-coins"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1 text-primary">Tk {{ number_format($totals['total_value'], 2) }}</div>
                        <small class="text-muted">Total stock value</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2 d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:38px;height:38px;background:#64748b;"><i class="fas fa-list"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">{{ number_format($data->count()) }}</div>
                        <small class="text-muted">Line items</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="stockTable" class="table table-sm table-striped table-hover mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Product</th>
                            <th>Warehouse</th>
                            <th>Branch</th>
                            <th class="text-end">On-Hand Qty</th>
                            <th class="text-end">Avg Cost</th>
                            <th class="text-end">Stock Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data as $r)
                            <tr>
                                <td>
                                    <code>{{ $r->product_code }}</code><br>
                                    <span class="fw-semibold">{{ $r->product_name }}</span>
                                </td>
                                <td>{{ $r->warehouse_name ?? '—' }}</td>
                                <td>{{ $r->branch_name ?? '—' }}</td>
                                <td class="text-end {{ $r->on_hand_qty <= 0 ? 'text-danger fw-semibold' : '' }}">{{ number_format($r->on_hand_qty, 2) }}</td>
                                <td class="text-end">{{ number_format($r->avg_cost, 2) }}</td>
                                <td class="text-end fw-semibold">{{ number_format($r->stock_value, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted text-center py-3">No stock records.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td colspan="3" class="text-end">TOTAL</td>
                            <td class="text-end">{{ number_format($totals['total_qty'], 2) }}</td>
                            <td></td>
                            <td class="text-end">{{ number_format($totals['total_value'], 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function() {
    $('#stockTable').DataTable({
        paging: true,
        pageLength: 50,
        searching: true,
        ordering: true,
        info: false,
        order: [[5, 'desc']]
    });
});
</script>
@endpush

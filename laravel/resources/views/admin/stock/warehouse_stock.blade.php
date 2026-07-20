@extends('layouts.admin')

@section('content')
@php
    $listUrl     = url('/admin/stock/transactions');
    $currentUrl  = url('/admin/stock/warehouse-stock');

    // Count zero-stock items on the current page (qty <= 0).
    $zeroStockCount = 0;
    $pageQtySum     = 0;
    $pageValueSum   = 0;
    $pageWarehouses = [];
    foreach ($stock as $row) {
        if ((float) $row->qty <= 0) {
            $zeroStockCount++;
        }
        $pageQtySum      += (float) $row->qty;
        $pageValueSum    += (float) $row->stock_value;
        $pageWarehouses[$row->warehouse_id] = true;
    }
@endphp

<div class="container-fluid py-2">

    {{-- ===================== HERO HEADER ===================== --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white" style="background:linear-gradient(135deg,#0f766e,#14b8a6);">
        <div>
            <h1 class="h3 mb-1">
                <i class="fas fa-warehouse me-2"></i>
                Warehouse Stock
            </h1>
            <p class="mb-0 opacity-75">Current on-hand balances with moving-average cost.</p>
        </div>
        <div class="d-flex flex-wrap gap-1">
            <a href="{{ $listUrl }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-right-arrow-left me-1"></i> Transactions
            </a>
            <a href="{{ $currentUrl }}" class="btn btn-light btn-sm" title="Reset filters">
                <i class="fas fa-rotate me-1"></i> Reset
            </a>
        </div>
    </header>

    {{-- ===================== SUMMARY CARDS ===================== --}}
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:#0f766e;"><i class="fas fa-taka-sign"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">Tk {{ number_format((float) ($totals['total_value'] ?? 0), 2) }}</div>
                        <div class="text-muted small">Total Stock Value</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:#6366f1;"><i class="fas fa-boxes-stacked"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">{{ number_format($stock->total()) }}</div>
                        <div class="text-muted small">Product Rows (filtered)</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:#3b82f6;"><i class="fas fa-warehouse"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">{{ $warehouses->count() }}</div>
                        <div class="text-muted small">Warehouses (master)</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:#f59e0b;"><i class="fas fa-triangle-exclamation"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">{{ $zeroStockCount }}</div>
                        <div class="text-muted small">Zero-stock items (page)</div>
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
            <form method="GET" action="{{ $currentUrl }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-4">
                    <label class="form-label small mb-1">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm select2">
                        <option value="">All branches</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" @selected(($filters['branch_id'] ?? '') == $b->id)>
                                {{ $b->branch_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-sm-6 col-md-4">
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
                <div class="col-sm-6 col-md-2 d-flex align-items-center pt-3">
                    <div class="form-check">
                        <input type="checkbox" name="zero_stock" value="1" class="form-check-input" id="zeroStock"
                               @checked(($filters['zero_stock'] ?? '0') == '1' || ($filters['zero_stock'] ?? false) === true)>
                        <label class="form-check-label small" for="zeroStock">Include zero-stock</label>
                    </div>
                </div>
                <div class="col-sm-6 col-md-2 d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                    <a href="{{ $currentUrl }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-eraser me-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- ===================== STOCK TABLE ===================== --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0"><i class="fas fa-list-ol me-2"></i>On-hand Balances ({{ number_format($stock->total()) }})</h2>
            <small class="text-muted">
                <i class="fas fa-circle-info me-1"></i>
                Moving-average cost — see <code>avg_cost_rule.md</code>
            </small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="dataTable" class="table table-sm table-striped table-hover align-middle mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Branch</th>
                            <th>Warehouse</th>
                            <th>Product Code</th>
                            <th>Product Name</th>
                            <th>Unit</th>
                            <th class="text-end">On-hand Qty</th>
                            <th class="text-end">Avg Cost</th>
                            <th class="text-end">Stock Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($stock as $row)
                            @php
                                $isLow = (float) $row->qty <= 0;
                            @endphp
                            <tr class="{{ $isLow ? 'table-warning' : '' }}">
                                <td>{{ $row->branch_name ?? '—' }}</td>
                                <td>{{ $row->warehouse_name ?? '—' }}</td>
                                <td>
                                    <span class="badge bg-light text-dark border">{{ $row->product_code ?? '—' }}</span>
                                </td>
                                <td class="fw-semibold">{{ $row->product_name ?? '—' }}</td>
                                <td class="text-muted">{{ $row->unit ?? '' }}</td>
                                <td class="text-end text-nowrap fw-semibold {{ $isLow ? 'text-warning-emphasis' : '' }}">
                                    {{ number_format((float) $row->qty, 4) }}
                                </td>
                                <td class="text-end text-nowrap">{{ number_format((float) $row->avg_cost, 2) }}</td>
                                <td class="text-end text-nowrap">{{ number_format((float) $row->stock_value, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <i class="fas fa-folder-open fa-2x mb-2 d-block opacity-50"></i>
                                    No on-hand stock balances match the current filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($stock->isNotEmpty())
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="5" class="text-end">Totals (page)</td>
                                <td class="text-end text-nowrap">{{ number_format($pageQtySum, 4) }}</td>
                                <td class="text-end text-muted">—</td>
                                <td class="text-end text-nowrap">Tk {{ number_format($pageValueSum, 2) }}</td>
                            </tr>
                            <tr class="table-active">
                                <td colspan="5" class="text-end">Global on-hand (qty &gt; 0)</td>
                                <td class="text-end text-nowrap">{{ number_format((float) ($totals['total_qty'] ?? 0), 4) }}</td>
                                <td class="text-end text-muted">—</td>
                                <td class="text-end text-nowrap">Tk {{ number_format((float) ($totals['total_value'] ?? 0), 2) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        @if ($stock->hasPages())
            <div class="card-footer bg-white py-2">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="text-muted small">
                        Showing {{ $stock->firstItem() ?? 0 }}–{{ $stock->lastItem() ?? 0 }}
                        of {{ number_format($stock->total()) }}
                    </div>
                    <div>{{ $stock->links() }}</div>
                </div>
            </div>
        @endif
    </div>

    {{-- ===================== FOOTNOTE ===================== --}}
    <p class="text-muted small mt-2 mb-0">
        <i class="fas fa-circle-info me-1"></i>
        Stock valued at moving-average cost. See <code>avg_cost_rule.md</code> for costing methodology.
    </p>
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

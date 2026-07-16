@extends('layouts.admin')

@section('content')
@php
    // Defaults for filter controls
    $filters = array_merge([
        'from_date'   => '',
        'to_date'     => '',
        'supplier_id' => '',
        'branch_id'   => '',
        'status'      => '',
        'search'      => '',
    ], is_array($filters ?? null) ? $filters : []);

    $stats = array_merge([
        'total'       => 0,
        'draft'       => 0,
        'sent'        => 0,
        'partial'     => 0,
        'received'    => 0,
        'cancelled'   => 0,
        'total_value' => 0,
    ], $stats ?? []);

    $statusBadge = function (string $status): string {
        return [
            'draft'     => '<span class="badge bg-warning-subtle text-warning"><i class="fas fa-pen-to-square me-1"></i>Draft</span>',
            'sent'      => '<span class="badge bg-info-subtle text-info"><i class="fas fa-paper-plane me-1"></i>Sent</span>',
            'partial'   => '<span class="badge bg-primary-subtle text-primary"><i class="fas fa-circle-half-stroke me-1"></i>Partial</span>',
            'received'  => '<span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i>Received</span>',
            'cancelled' => '<span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-ban me-1"></i>Cancelled</span>',
        ][$status] ?? '<span class="badge bg-light text-dark">' . e($status) . '</span>';
    };
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#2563eb,#1d4ed8);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-cart-shopping me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Procurement workflow — draft → sent → partial → received. No stock movement or GL posting until GRN.
            </p>
        </div>
        <div>
            <a href="{{ route('admin.purchase-orders.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New PO
            </a>
        </div>
    </header>

    {{-- Stats cards: 7 cards --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#2563eb;">
                        <i class="fas fa-list"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['total']) }}</div>
                        <div class="text-muted small">Total</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#d97706;">
                        <i class="fas fa-pen-to-square"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['draft']) }}</div>
                        <div class="text-muted small">Draft</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0ea5e9;">
                        <i class="fas fa-paper-plane"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['sent']) }}</div>
                        <div class="text-muted small">Sent</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#4f46e5;">
                        <i class="fas fa-circle-half-stroke"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['partial']) }}</div>
                        <div class="text-muted small">Partial</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#16a34a;">
                        <i class="fas fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['received']) }}</div>
                        <div class="text-muted small">Received</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#64748b;">
                        <i class="fas fa-ban"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['cancelled']) }}</div>
                        <div class="text-muted small">Cancelled</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#7c3aed;">
                        <i class="fas fa-taka-sign"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['total_value'], 2) }}</div>
                        <div class="text-muted small">Total value (ex. cancelled)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.purchase-orders.index') }}" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="from_date">From date</label>
                    <input type="date" id="from_date" name="from_date" class="form-control form-control-sm"
                           value="{{ $filters['from_date'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="to_date">To date</label>
                    <input type="date" id="to_date" name="to_date" class="form-control form-control-sm"
                           value="{{ $filters['to_date'] }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="supplier_id">Supplier</label>
                    <select id="supplier_id" name="supplier_id" class="form-select form-select-sm select2">
                        <option value="">All suppliers</option>
                        @foreach ($suppliers as $s)
                            <option value="{{ $s->id }}"
                                {{ (string) $filters['supplier_id'] === (string) $s->id ? 'selected' : '' }}>
                                {{ $s->supplier_code }} — {{ $s->supplier_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="branch_id">Branch</label>
                    <select id="branch_id" name="branch_id" class="form-select form-select-sm select2">
                        <option value="">All branches</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}"
                                {{ (string) $filters['branch_id'] === (string) $b->id ? 'selected' : '' }}>
                                {{ $b->branch_code }} — {{ $b->branch_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="status">Status</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="">All statuses</option>
                        <option value="draft"     {{ $filters['status'] === 'draft' ? 'selected' : '' }}>Draft</option>
                        <option value="sent"      {{ $filters['status'] === 'sent' ? 'selected' : '' }}>Sent</option>
                        <option value="partial"   {{ $filters['status'] === 'partial' ? 'selected' : '' }}>Partial</option>
                        <option value="received"  {{ $filters['status'] === 'received' ? 'selected' : '' }}>Received</option>
                        <option value="cancelled" {{ $filters['status'] === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small text-muted mb-1" for="search">Search</label>
                    <input type="text" id="search" name="search" class="form-control form-control-sm"
                           placeholder="PO code" value="{{ $filters['search'] }}">
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <a href="{{ route('admin.purchase-orders.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-eraser me-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- POs table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0" id="dataTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Date</th>
                            <th>Supplier</th>
                            <th>Branch</th>
                            <th>Warehouse</th>
                            <th class="text-end">Items</th>
                            <th class="text-end">Total (Tk)</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pos as $po)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.purchase-orders.show', $po) }}"
                                       class="fw-semibold text-decoration-none">
                                        {{ $po->po_code }}
                                    </a>
                                </td>
                                <td class="text-nowrap small">
                                    {{ \Carbon\Carbon::parse($po->po_date)->format('d M Y') }}
                                </td>
                                <td>
                                    @if ($po->supplier)
                                        <span class="fw-semibold">{{ $po->supplier->supplier_name }}</span>
                                        <div class="small text-muted">{{ $po->supplier->supplier_code }}</div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($po->branch)
                                        {{ $po->branch->branch_name }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($po->warehouse)
                                        <span class="fw-semibold">{{ $po->warehouse->warehouse_name }}</span>
                                        <div class="small text-muted">{{ $po->warehouse->warehouse_code }}</div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ number_format($po->items->count()) }}</td>
                                <td class="text-end">{{ number_format((float) $po->total_amount, 2) }}</td>
                                <td>{!! $statusBadge($po->status) !!}</td>
                                <td class="text-center text-nowrap">
                                    <a href="{{ route('admin.purchase-orders.show', $po) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No purchase orders found. Try adjusting filters or
                                    <a href="{{ route('admin.purchase-orders.create') }}">create a new one</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            {{ $pos->links() }}
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    // DataTables on the visible rows only (server-side pagination handles page size).
    $('#dataTable').DataTable({
        paging: false,
        info: false,
        ordering: true,
        dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
        language: { search: 'Filter rows:', emptyTable: 'No purchase orders on this page.' }
    });
});
</script>
@endpush
@endsection

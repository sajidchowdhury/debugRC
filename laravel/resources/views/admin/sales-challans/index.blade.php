@extends('layouts.admin')

@section('content')
@php
    // Defaults for filter controls
    $filters = array_merge([
        'from_date' => '',
        'to_date'   => '',
        'branch_id' => '',
        'search'    => '',
    ], is_array($filters ?? null) ? $filters : []);

    $stats = array_merge([
        'total'      => 0,
        'active'     => 0,
        'reversed'   => 0,
        'total_cogs' => 0,
    ], $stats ?? []);
@endphp

<div class="container-fluid py-2">
    {{-- Hero header (purple/indigo = revenue movement) --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#4f46e5);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-truck me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Sales challans move stock OUT at avg_cost and post GL Dr COGS / Cr Inventory.
                Reverse a challan to undo stock + GL.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.sales-invoices.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-file-invoice-dollar me-1"></i> Invoices
            </a>
        </div>
    </header>

    {{-- Stats cards: 4 cards --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#7c3aed;">
                        <i class="fas fa-list"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['total']) }}</div>
                        <div class="text-muted small">Total challans</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#16a34a;">
                        <i class="fas fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['active']) }}</div>
                        <div class="text-muted small">Active</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#dc2626;">
                        <i class="fas fa-rotate-left"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['reversed']) }}</div>
                        <div class="text-muted small">Reversed</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#4f46e5;">
                        <i class="fas fa-taka-sign"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['total_cogs'], 2) }}</div>
                        <div class="text-muted small">Total COGS (active)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.sales-challans.index') }}" class="row g-2 align-items-end">
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
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="search">Search</label>
                    <input type="text" id="search" name="search" class="form-control form-control-sm"
                           placeholder="Challan code" value="{{ $filters['search'] }}">
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <a href="{{ route('admin.sales-challans.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-eraser me-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Challans table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0" id="dataTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Date</th>
                            <th>Invoice Code</th>
                            <th>Customer</th>
                            <th>Branch</th>
                            <th class="text-end">COGS Amount (Tk)</th>
                            <th>Transport</th>
                            <th class="text-center">Reversed?</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($challans as $ch)
                            <tr class="{{ $ch->is_reversed ? 'table-danger' : '' }}">
                                <td>
                                    <a href="{{ route('admin.sales-challans.show', $ch) }}"
                                       class="fw-semibold text-decoration-none">
                                        {{ $ch->challan_code }}
                                    </a>
                                </td>
                                <td class="text-nowrap small">
                                    {{ \Carbon\Carbon::parse($ch->challan_date)->format('d M Y') }}
                                </td>
                                <td>
                                    @if ($ch->salesInvoice)
                                        <a href="{{ route('admin.sales-invoices.show', $ch->salesInvoice) }}"
                                           class="text-decoration-none">
                                            {{ $ch->salesInvoice->invoice_code }}
                                        </a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($ch->salesInvoice && $ch->salesInvoice->customer)
                                        <span class="fw-semibold">{{ $ch->salesInvoice->customer->customer_name }}</span>
                                        <div class="small text-muted">{{ $ch->salesInvoice->customer->customer_code }}</div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($ch->branch)
                                        {{ $ch->branch->branch_name }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-end fw-semibold">
                                    Tk {{ number_format((float) $ch->issue_cost, 2) }}
                                </td>
                                <td class="small">
                                    @if (!empty($ch->transport_name) || !empty($ch->vehicle_number))
                                        @if (!empty($ch->transport_name))
                                            <i class="fas fa-truck-front me-1 text-muted"></i>{{ $ch->transport_name }}
                                        @endif
                                        @if (!empty($ch->vehicle_number))
                                            <div class="text-muted">
                                                <i class="fas fa-car me-1"></i>{{ $ch->vehicle_number }}
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($ch->is_reversed)
                                        <span class="badge bg-danger-subtle text-danger">
                                            <i class="fas fa-rotate-left me-1"></i>Reversed
                                        </span>
                                    @else
                                        <span class="badge bg-success-subtle text-success">
                                            <i class="fas fa-circle-check me-1"></i>Active
                                        </span>
                                    @endif
                                </td>
                                <td class="text-center text-nowrap">
                                    <a href="{{ route('admin.sales-challans.show', $ch) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No sales challans found. Try adjusting filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            {{ $challans->links() }}
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    $('#dataTable').DataTable({
        paging: false,
        info: false,
        ordering: true,
        dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
        language: { search: 'Filter rows:', emptyTable: 'No sales challans on this page.' }
    });
});
</script>
@endpush
@endsection

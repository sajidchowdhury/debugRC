@extends('layouts.admin')

@section('content')
@php
    // Defaults for filter controls
    $filters = array_merge([
        'from_date'         => '',
        'to_date'           => '',
        'from_warehouse_id' => '',
        'to_warehouse_id'   => '',
        'status'            => '',
        'interbranch'       => '',
        'search'            => '',
    ], is_array($filters ?? null) ? $filters : []);

    $stats = array_merge([
        'total'       => 0,
        'draft'       => 0,
        'confirmed'   => 0,
        'cancelled'   => 0,
        'interbranch' => 0,
        'total_value' => 0,
    ], $stats ?? []);

    $statusBadge = function (string $status): string {
        return [
            'draft'     => '<span class="badge bg-warning-subtle text-warning"><i class="fas fa-pen-to-square me-1"></i>Draft</span>',
            'confirmed' => '<span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i>Confirmed</span>',
            'cancelled' => '<span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-ban me-1"></i>Cancelled</span>',
        ][$status] ?? '<span class="badge bg-light text-dark">' . e($status) . '</span>';
    };

    $interbranchBadge = function (bool $isInterbranch): string {
        return $isInterbranch
            ? '<span class="badge bg-info-subtle text-info"><i class="fas fa-arrow-right-arrow-left me-1"></i>Interbranch</span>'
            : '<span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-warehouse me-1"></i>Same branch</span>';
    };
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#ea580c,#d97706);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-right-left me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Cross-warehouse transfers — same-branch reallocates stock; cross-branch posts intercompany GL.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.warehouse-transfers.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New Transfer
            </a>
            <a href="{{ route('admin.warehouse-transfers.checklist') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clipboard-check me-1"></i> Audit Checklist
            </a>
            <a href="{{ route('admin.warehouse-transfers.reconcile') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-balance-scale me-1"></i> Reconcile
            </a>
        </div>
    </header>

    {{-- Stats cards (6) --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#ea580c;">
                        <i class="fas fa-list"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['total']) }}</div>
                        <div class="text-muted small">Total</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-2">
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
        <div class="col-sm-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#16a34a;">
                        <i class="fas fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['confirmed']) }}</div>
                        <div class="text-muted small">Confirmed</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-2">
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
        <div class="col-sm-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0ea5e9;">
                        <i class="fas fa-arrow-right-arrow-left"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['interbranch']) }}</div>
                        <div class="text-muted small">Interbranch</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#7c3aed;">
                        <i class="fas fa-taka-sign"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0 text-truncate" title="Tk {{ number_format((float) $stats['total_value'], 2) }}">
                            Tk {{ number_format((float) $stats['total_value'], 2) }}
                        </div>
                        <div class="text-muted small">Confirmed value</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.warehouse-transfers.index') }}" class="row g-2 align-items-end">
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
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="from_warehouse_id">From warehouse</label>
                    <select id="from_warehouse_id" name="from_warehouse_id" class="form-select form-select-sm select2">
                        <option value="">All</option>
                        @foreach ($warehouses as $wh)
                            <option value="{{ $wh->id }}"
                                {{ (string) $filters['from_warehouse_id'] === (string) $wh->id ? 'selected' : '' }}>
                                {{ $wh->warehouse_code }} — {{ $wh->warehouse_name }}
                                @if ($wh->branch) ({{ $wh->branch->branch_name }}) @endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="to_warehouse_id">To warehouse</label>
                    <select id="to_warehouse_id" name="to_warehouse_id" class="form-select form-select-sm select2">
                        <option value="">All</option>
                        @foreach ($warehouses as $wh)
                            <option value="{{ $wh->id }}"
                                {{ (string) $filters['to_warehouse_id'] === (string) $wh->id ? 'selected' : '' }}>
                                {{ $wh->warehouse_code }} — {{ $wh->warehouse_name }}
                                @if ($wh->branch) ({{ $wh->branch->branch_name }}) @endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="status">Status</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="draft"     {{ $filters['status'] === 'draft' ? 'selected' : '' }}>Draft</option>
                        <option value="confirmed" {{ $filters['status'] === 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                        <option value="cancelled" {{ $filters['status'] === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="interbranch">Interbranch</label>
                    <select id="interbranch" name="interbranch" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="yes" {{ $filters['interbranch'] === 'yes' ? 'selected' : '' }}>Yes (cross-branch)</option>
                        <option value="no"  {{ $filters['interbranch'] === 'no'  ? 'selected' : '' }}>No (same branch)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted mb-1" for="search">Search transfer code</label>
                    <input type="text" id="search" name="search" class="form-control form-control-sm"
                           placeholder="e.g. WT-2025-0001"
                           value="{{ $filters['search'] }}">
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <a href="{{ route('admin.warehouse-transfers.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-eraser me-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Transfers table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0" id="dataTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Date</th>
                            <th>From warehouse</th>
                            <th>To warehouse</th>
                            <th class="text-center">Interbranch?</th>
                            <th class="text-end">Items</th>
                            <th class="text-end">Total (Tk)</th>
                            <th>Status</th>
                            <th class="text-center">Reversed?</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($transfers as $t)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.warehouse-transfers.show', $t) }}"
                                       class="fw-semibold text-decoration-none">
                                        {{ $t->transfer_code }}
                                    </a>
                                </td>
                                <td class="text-nowrap small">
                                    {{ \Carbon\Carbon::parse($t->transfer_date)->format('d M Y') }}
                                </td>
                                <td>
                                    @if ($t->fromWarehouse)
                                        <span class="fw-semibold">{{ $t->fromWarehouse->warehouse_name }}</span>
                                        @if ($t->fromWarehouse->branch)
                                            <div class="small text-muted">
                                                <i class="fas fa-building me-1"></i>{{ $t->fromWarehouse->branch->branch_name }}
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($t->toWarehouse)
                                        <span class="fw-semibold">{{ $t->toWarehouse->warehouse_name }}</span>
                                        @if ($t->toWarehouse->branch)
                                            <div class="small text-muted">
                                                <i class="fas fa-building me-1"></i>{{ $t->toWarehouse->branch->branch_name }}
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center">{!! $interbranchBadge((bool) $t->is_interbranch) !!}</td>
                                <td class="text-end">{{ number_format($t->items->count()) }}</td>
                                <td class="text-end">{{ number_format((float) $t->total_amount, 2) }}</td>
                                <td>{!! $statusBadge($t->status) !!}</td>
                                <td class="text-center">
                                    @if ($t->is_reversed)
                                        <span class="badge bg-danger-subtle text-danger">
                                            <i class="fas fa-rotate-left me-1"></i>Yes
                                        </span>
                                    @else
                                        <span class="badge bg-light text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center text-nowrap">
                                    <a href="{{ route('admin.warehouse-transfers.show', $t) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No warehouse transfers found. Try adjusting filters or
                                    <a href="{{ route('admin.warehouse-transfers.create') }}">create a new one</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            {{ $transfers->links() }}
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
        language: { search: 'Filter rows:', emptyTable: 'No warehouse transfers on this page.' }
    });
});
</script>
@endpush
@endsection

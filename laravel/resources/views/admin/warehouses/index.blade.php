@extends('layouts.admin')

@section('content')
@php
    $stats = $stats ?? ['active' => 0, 'by_branch' => []];
    $showDeleted = $showDeleted ?? false;
    // Resolve branch names for by_branch breakdown
    $byBranchBreakdown = [];
    if (!empty($stats['by_branch'])) {
        foreach ($stats['by_branch'] as $branchId => $count) {
            $branch = \App\Models\Branch::withTrashed()->find($branchId);
            $byBranchBreakdown[] = [
                'name' => $branch ? $branch->branch_name : ('Branch #' . $branchId),
                'count' => $count,
            ];
        }
    }
@endphp

<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#b45309,#d97706);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-warehouse me-2"></i>{{ $showDeleted ? 'Inactive warehouses' : 'Warehouse network' }}</h1>
            <p class="mb-0 small opacity-75">
                {{ $showDeleted
                    ? 'Restore locations when ready — ensure stock is cleared before deactivating active sites.'
                    : 'Stock SSOT locations tied to branches — godown, challan, transfers, and adjustments.' }}
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if ($showDeleted)
                <a href="{{ route('admin.warehouses.index') }}" class="btn btn-light btn-sm">
                    <i class="fas fa-warehouse me-1"></i> Active list
                </a>
            @else
                <a href="{{ route('admin.warehouses.index', ['deleted' => 1]) }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-box-archive me-1"></i> Inactive
                </a>
            @endif
            <a href="{{ route('admin.warehouses.audit') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
            <a href="{{ route('admin.warehouses.print') }}" target="_blank" class="btn btn-outline-light btn-sm" title="Open a print-friendly directory view in a new tab">
                <i class="fas fa-print me-1"></i> Print
            </a>
            <a href="{{ route('admin.warehouses.export') }}" class="btn btn-outline-light btn-sm" id="btnExportCsv" title="Download all warehouses as a CSV file">
                <i class="fas fa-file-csv me-1"></i> Export CSV
            </a>
            <a href="{{ route('admin.warehouses.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New warehouse
            </a>
        </div>
    </header>

    @if (!$showDeleted)
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#d97706;">
                        <i class="fas fa-warehouse"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['active'] ?? 0)) }}</div>
                        <div class="text-muted small">Active sites</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small mb-2"><i class="fas fa-building me-1"></i> By branch</div>
                    @if (empty($byBranchBreakdown))
                        <span class="text-muted small">No active warehouses yet.</span>
                    @else
                        <div class="d-flex flex-wrap gap-2">
                            @foreach ($byBranchBreakdown as $row)
                                <span class="badge bg-info-subtle text-info fs-6">
                                    {{ $row['name'] }}: {{ $row['count'] }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="warehouseTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Warehouse</th>
                            <th>Branch</th>
                            <th class="d-none d-lg-table-cell">Location</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $wh)
                            <tr>
                                <td><span class="badge bg-secondary-subtle text-secondary">{{ $wh->warehouse_code }}</span></td>
                                <td>
                                    <a href="{{ route('admin.warehouses.show', $wh) }}" class="fw-semibold text-decoration-none text-reset">
                                        <i class="fas fa-warehouse me-1 text-warning"></i>{{ $wh->warehouse_name }}
                                    </a>
                                </td>
                                <td>
                                    @if ($wh->branch)
                                        <a href="{{ route('admin.branches.show', $wh->branch) }}" class="text-decoration-none">
                                            <span class="badge bg-info-subtle text-info">
                                                <i class="fas fa-building me-1"></i>{{ $wh->branch->branch_name }}
                                            </span>
                                        </a>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="d-none d-lg-table-cell small text-muted">
                                    {{ $wh->location ? \Illuminate\Support\Str::limit($wh->location, 60) : '—' }}
                                </td>
                                <td>
                                    @if ($wh->is_active)
                                        <span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i>Active</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-circle-xmark me-1"></i>Inactive</span>
                                    @endif
                                </td>
                                <td class="text-center text-nowrap">
                                    <a href="{{ route('admin.warehouses.show', $wh) }}" class="btn btn-sm btn-outline-secondary" title="View">
                                        <i class="fas fa-warehouse"></i>
                                    </a>
                                    <a href="{{ route('admin.warehouses.edit', $wh) }}" class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    @if ($showDeleted)
                                        <form method="POST" action="{{ route('admin.warehouses.restore', $wh) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Restore">
                                                <i class="fas fa-rotate-left"></i>
                                            </button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.warehouses.destroy', $wh) }}" class="d-inline"
                                              onsubmit="return confirm('Deactivate this warehouse? Stock must be moved or zero first.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Deactivate">
                                                <i class="fas fa-power-off"></i>
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No warehouses found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            {{ $items->links() }}
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function() {
    $('#warehouseTable').DataTable({
        paging: false,
        info: false,
        ordering: true,
        dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
        language: { search: 'Filter:', emptyTable: 'No warehouses found.' }
    });
});
</script>
@endpush
@endsection

@extends('layouts.admin')

@section('content')
@php
    $stats = $stats ?? ['active' => 0, 'total_employees' => 0, 'total_warehouses' => 0];
    $showDeleted = $showDeleted ?? false;
@endphp

<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0891b2,#06b6d4);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-sitemap me-2"></i>{{ $showDeleted ? 'Inactive branches' : 'Branch network' }}</h1>
            <p class="mb-0 small opacity-75">
                {{ $showDeleted
                    ? 'Restore locations when ready to operate again.'
                    : 'Organize locations, warehouses, and teams across your Remote Center ERP footprint.' }}
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if ($showDeleted)
                <a href="{{ route('admin.branches.index') }}" class="btn btn-light btn-sm">
                    <i class="fas fa-building me-1"></i> Active
                </a>
            @else
                <a href="{{ route('admin.branches.index', ['deleted' => 1]) }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-box-archive me-1"></i> Inactive
                </a>
            @endif
            <a href="{{ route('admin.branches.audit') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
            <button type="button" class="btn btn-outline-light btn-sm" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print
            </button>
            <a href="{{ route('admin.branches.export') }}" class="btn btn-outline-light btn-sm" id="btnExportCsv" title="Download all branches as a CSV file">
                <i class="fas fa-file-csv me-1"></i> Export CSV
            </a>
            <a href="{{ route('admin.branches.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New branch
            </a>
        </div>
    </header>

    @if (!$showDeleted)
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0891b2;">
                        <i class="fas fa-building"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['active'] ?? 0)) }}</div>
                        <div class="text-muted small">Active branches</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#4f46e5;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['total_employees'] ?? 0)) }}</div>
                        <div class="text-muted small">Team members (all branches)</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#d97706;">
                        <i class="fas fa-warehouse"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['total_warehouses'] ?? 0)) }}</div>
                        <div class="text-muted small">Warehouses (all branches)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="branchTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Branch</th>
                            <th class="d-none d-lg-table-cell">Contact</th>
                            <th class="d-none d-lg-table-cell">Address</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $branch)
                            <tr>
                                <td><span class="badge bg-secondary-subtle text-secondary">{{ $branch->branch_code }}</span></td>
                                <td>
                                    <a href="{{ route('admin.branches.show', $branch) }}" class="fw-semibold text-decoration-none text-reset">
                                        {{ $branch->branch_name }}
                                    </a>
                                </td>
                                <td class="d-none d-lg-table-cell small">
                                    @if ($branch->phone)
                                        <div><i class="fas fa-phone me-1 text-muted"></i>{{ $branch->phone }}</div>
                                    @endif
                                    @if ($branch->email)
                                        <div><i class="fas fa-envelope me-1 text-muted"></i>{{ $branch->email }}</div>
                                    @endif
                                    @if (!$branch->phone && !$branch->email)
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="d-none d-lg-table-cell small text-muted">
                                    {{ $branch->address ? \Illuminate\Support\Str::limit($branch->address, 60) : '—' }}
                                </td>
                                <td>
                                    @if ($branch->is_active)
                                        <span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i>Active</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-circle-xmark me-1"></i>Inactive</span>
                                    @endif
                                </td>
                                <td class="text-center text-nowrap">
                                    <a href="{{ route('admin.branches.show', $branch) }}" class="btn btn-sm btn-outline-secondary" title="View">
                                        <i class="fas fa-sitemap"></i>
                                    </a>
                                    <a href="{{ route('admin.branches.edit', $branch) }}" class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    @if ($showDeleted)
                                        <form method="POST" action="{{ route('admin.branches.restore', $branch) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Restore">
                                                <i class="fas fa-rotate-left"></i>
                                            </button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.branches.destroy', $branch) }}" class="d-inline"
                                              onsubmit="return confirm('Deactivate this branch? Reassign warehouses/employees first.');">
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
                                    No branches found.
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
    $('#branchTable').DataTable({
        paging: false,
        info: false,
        ordering: true,
        dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
        language: { search: 'Filter:', emptyTable: 'No branches found.' }
    });
});
</script>
@endpush
@endsection

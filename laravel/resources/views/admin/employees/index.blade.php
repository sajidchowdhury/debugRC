@extends('layouts.admin')

@php
    /**
     * @var \App\Models\Employee[] $items
     * @var array $stats
     * @var bool $showDeleted
     * @var string $routePrefix
     */
    $roleColors = [
        'superadmin'         => 'danger',
        'admin'              => 'primary',
        'manager'            => 'info',
        'accountant'         => 'success',
        'salesman'           => 'warning',
        'warehouse_manager'  => 'secondary',
        'dispatcher'         => 'light',
        'hr'                 => 'dark',
        'user'               => 'secondary',
        'other'              => 'secondary',
    ];
    $roleLabels = collect(config('roles'))->mapWithKeys(fn ($r, $k) => [$k => $r['label']])->toArray();

    $byBranch = $stats['by_branch'] ?? collect();
    $branchNames = \App\Models\Branch::whereIn('id', $byBranch->keys())->pluck('branch_name', 'id');
@endphp

@section('content')

<div class="container-fluid py-2">

    {{-- ==================== HERO HEADER ==================== --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-id-badge me-2"></i>{{ $showDeleted ? 'Inactive employees' : 'Employee directory' }}</h1>
            <p class="mb-0 small opacity-75">
                {{ $showDeleted
                    ? 'Soft-deleted records — restore when appropriate.'
                    : 'Staff master data, branches, roles, and linked system users.' }}
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if ($showDeleted)
                <a href="{{ route($routePrefix . '.index') }}" class="btn btn-light btn-sm">
                    <i class="fas fa-users me-1"></i> Active
                </a>
            @else
                <a href="{{ route($routePrefix . '.index', ['deleted' => 1]) }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-box-archive me-1"></i> Inactive
                </a>
                <a href="{{ route($routePrefix . '.audit') }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-clock-rotate-left me-1"></i> Audit
                </a>
                <a href="{{ route($routePrefix . '.export') }}" class="btn btn-outline-light btn-sm" title="Download all employees as a CSV file">
                    <i class="fas fa-file-csv me-1"></i> Export CSV
                </a>
                <a href="{{ route($routePrefix . '.create') }}" class="btn btn-light btn-sm">
                    <i class="fas fa-plus me-1"></i> New employee
                </a>
            @endif
        </div>
    </header>

    {{-- ==================== STAT CARDS ==================== --}}
    @if (! $showDeleted)
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#16a34a;">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['active'] ?? 0)) }}</div>
                        <div class="text-muted small">Active employees</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#64748b;">
                        <i class="fas fa-user-slash"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['inactive'] ?? 0)) }}</div>
                        <div class="text-muted small">Inactive (deleted)</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#4f46e5;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['total'] ?? 0)) }}</div>
                        <div class="text-muted small">Total (incl. deleted)</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0ea5e9;">
                        <i class="fas fa-sitemap"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ $byBranch->count() }}</div>
                        <div class="text-muted small">Branches w/ staff</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- By-branch breakdown --}}
    @if ($byBranch->isNotEmpty())
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white border-bottom fw-semibold">
            <i class="fas fa-sitemap me-2 text-info"></i>Active staff by branch
        </div>
        <div class="card-body py-2">
            <div class="d-flex flex-wrap gap-2">
                @foreach ($byBranch as $branchId => $count)
                    <span class="badge rounded-pill bg-light text-dark border px-3 py-2">
                        <i class="fas fa-building me-1 text-muted"></i>
                        {{ $branchNames[$branchId] ?? ('Branch #' . $branchId) }}
                        <span class="badge bg-primary ms-1">{{ $count }}</span>
                    </span>
                @endforeach
            </div>
        </div>
    </div>
    @endif
    @endif

    {{-- ==================== DIRECTORY PANEL ==================== --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center">
            <span class="fw-semibold">
                <i class="fas fa-list me-2 text-primary"></i>
                {{ $showDeleted ? 'Inactive records' : 'Directory' }}
            </span>
            <span class="text-muted small">{{ $items->total() }} total</span>
        </div>
        <div class="card-body">
            <table class="table table-hover align-middle mb-0" id="employeesTable">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Role</th>
                        <th class="d-none d-lg-table-cell">Branch</th>
                        <th class="d-none d-md-table-cell">Phone</th>
                        <th class="d-none d-md-table-cell">Email</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $emp)
                        @php
                            $roleColor = $roleColors[$emp->role] ?? 'secondary';
                            $roleLabel = $roleLabels[$emp->role] ?? ucfirst(str_replace('_', ' ', $emp->role ?? ''));
                        @endphp
                        <tr>
                            <td>
                                <span class="badge bg-light text-dark border">{{ $emp->employee_code }}</span>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    @if ($emp->photo)
                                        <img src="{{ Illuminate\Support\Facades\Storage::disk('public')->url($emp->photo) }}"
                                             alt="" class="rounded-circle"
                                             style="width:32px;height:32px;object-fit:cover;">
                                    @else
                                        <span class="rounded-circle bg-light text-muted d-inline-flex align-items-center justify-content-center"
                                              style="width:32px;height:32px;">
                                            <i class="fas fa-user"></i>
                                        </span>
                                    @endif
                                    <a href="{{ route($routePrefix . '.show', $emp) }}" class="text-decoration-none fw-semibold">
                                        {{ $emp->name }}
                                    </a>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-{{ $roleColor }}">{{ $roleLabel }}</span>
                            </td>
                            <td class="d-none d-lg-table-cell">{{ $emp->branch?->branch_name ?? '—' }}</td>
                            <td class="d-none d-md-table-cell">{{ $emp->phone ?: '—' }}</td>
                            <td class="d-none d-md-table-cell">{{ $emp->email ?: '—' }}</td>
                            <td class="text-center">
                                @if ($emp->trashed())
                                    <span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-circle-xmark me-1"></i>Inactive</span>
                                @elseif ($emp->is_active)
                                    <span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i>Active</span>
                                @else
                                    <span class="badge bg-warning-subtle text-warning"><i class="fas fa-circle-pause me-1"></i>Disabled</span>
                                @endif
                            </td>
                            <td class="text-center text-nowrap">
                                <a href="{{ route($routePrefix . '.show', $emp) }}" class="btn btn-sm btn-outline-secondary" title="View">
                                    <i class="fas fa-circle-info"></i>
                                </a>
                                <a href="{{ route($routePrefix . '.account', $emp) }}" class="btn btn-sm btn-outline-info" title="Account hub">
                                    <i class="fas fa-id-card-clip"></i>
                                </a>
                                @if ($showDeleted)
                                    <form method="POST" action="{{ route($routePrefix . '.restore', $emp) }}" class="d-inline"
                                          onsubmit="return confirm('Restore this employee?');">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Restore">
                                            <i class="fas fa-rotate-left"></i>
                                        </button>
                                    </form>
                                @else
                                    <a href="{{ route($routePrefix . '.edit', $emp) }}" class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    <form method="POST" action="{{ route($routePrefix . '.destroy', $emp) }}" class="d-inline"
                                          onsubmit="return confirm('Deactivate this employee?');">
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
                            <td colspan="8" class="text-center text-muted py-5">
                                <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                No employees found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($items->hasPages())
        <div class="card-footer bg-white">
            {{ $items->links() }}
        </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
$(function () {
    if (!$.fn.DataTable) return;
    $('#employeesTable').DataTable({
        paging: false,
        info: false,
        ordering: true,
        searching: true,
        dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
        language: { search: 'Filter:', emptyTable: 'No employees found.' },
        columnDefs: [{ orderable: false, targets: [-1] }]
    });
});
</script>
@endpush
@endsection

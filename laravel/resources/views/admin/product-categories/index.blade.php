@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white" style="background:linear-gradient(135deg,#6366f1,#818cf8);">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-tags me-2"></i>{{ $showDeleted ? 'Inactive categories' : 'Product categories' }}</h1>
            <p class="mb-0 opacity-75">Group SKUs for filters, reports, and catalog organization.</p>
        </div>
        <div class="d-flex flex-wrap gap-1">
            @if ($showDeleted)
                <a href="{{ route($routePrefix . '.index') }}" class="btn btn-light btn-sm">Active</a>
            @else
                <a href="{{ route($routePrefix . '.index', ['deleted' => 1]) }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-box-archive me-1"></i> Inactive ({{ $stats['inactive'] ?? 0 }})
                </a>
            @endif
            <a href="{{ route('admin.products.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-boxes me-1"></i> Products
            </a>
            <a href="{{ route($routePrefix . '.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New category
            </a>
        </div>
    </header>

    @if (!$showDeleted)
    {{-- Stats cards --}}
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:#6366f1;"><i class="fas fa-tags"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">{{ $stats['active'] ?? 0 }}</div>
                        <div class="text-muted small">Active categories</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:#64748b;"><i class="fas fa-moon"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">{{ $stats['inactive'] ?? 0 }}</div>
                        <div class="text-muted small">Inactive</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-2">
                    <span class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:42px;height:42px;background:#0f766e;"><i class="fas fa-box"></i></span>
                    <div>
                        <div class="fs-5 fw-bold lh-1">{{ $stats['total'] ?? 0 }}</div>
                        <div class="text-muted small">Total</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Category</th>
                            <th>Description</th>
                            <th class="text-center">Products</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $cat)
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center"
                                              style="width:32px;height:32px;">
                                            <i class="fas fa-tag"></i>
                                        </span>
                                        <div>
                                            <div class="fw-semibold">{{ $cat->category_name }}</div>
                                            <small class="text-muted">ID #{{ $cat->id }}</small>
                                        </div>
                                    </div>
                                </td>
                                <td><small class="text-muted">{{ $cat->description ?? '—' }}</small></td>
                                <td class="text-center">
                                    @php $count = $cat->products?->count() ?? 0; @endphp
                                    @if ($count > 0)
                                        <span class="badge bg-light text-dark border"><i class="fas fa-box me-1"></i>{{ $count }}</span>
                                    @else
                                        <span class="text-muted small">0</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($showDeleted)
                                        <span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-moon me-1"></i> Inactive</span>
                                    @elseif ($cat->is_active)
                                        <span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i> Active</span>
                                    @else
                                        <span class="badge bg-warning-subtle text-warning"><i class="fas fa-circle me-1"></i> Off</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <div class="d-flex gap-1 justify-content-center">
                                        @if ($showDeleted)
                                            <form method="POST" action="{{ route($routePrefix . '.restore', $cat->id) }}" class="d-inline" onsubmit="return confirm('Restore this category?')">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-success" title="Restore">
                                                    <i class="fas fa-rotate-left"></i>
                                                </button>
                                            </form>
                                        @else
                                            <a href="{{ route($routePrefix . '.edit', $cat->id) }}" class="btn btn-sm btn-outline-primary" title="Edit">
                                                <i class="fas fa-pen"></i>
                                            </a>
                                            <form method="POST" action="{{ route($routePrefix . '.destroy', $cat->id) }}" class="d-inline" onsubmit="return confirm('Deactivate this category?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Deactivate">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No categories found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($items->hasPages())
        <div class="card-footer bg-white">
            {{ $items->links() }}
        </div>
        @endif
    </div>
</div>
@endsection

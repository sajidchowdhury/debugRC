@extends('layouts.admin')

@section('content')
@php
    $trashed = $item->trashed();
@endphp

<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#b45309,#d97706);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-warehouse me-2"></i>{{ $item->warehouse_name }}</h1>
            <p class="mb-0 small opacity-75">
                Stock SSOT hub
                @if ($item->branch)
                    · Branch:
                    <a href="{{ route('admin.branches.show', $item->branch) }}" class="text-white fw-semibold">
                        {{ $item->branch->branch_name }}
                    </a>
                @endif
            </p>
            <span class="badge bg-white text-dark mt-2">
                @if ($item->is_active)
                    <i class="fas fa-circle-check text-success"></i> Active
                @else
                    <i class="fas fa-circle-xmark text-secondary"></i> Inactive
                @endif
                · {{ $item->warehouse_code }}
            </span>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.warehouses.edit', $item) }}" class="btn btn-light btn-sm">
                <i class="fas fa-pen me-1"></i> Edit
            </a>
            <a href="{{ route('admin.warehouses.audit') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
            <a href="{{ route('admin.warehouses.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> All sites
            </a>
        </div>
    </header>

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#d97706;">
                        <i class="fas fa-warehouse"></i>
                    </div>
                    <div>
                        <div class="h6 mb-0">{{ $item->warehouse_code }}</div>
                        <div class="text-muted small">Warehouse code</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0891b2;">
                        <i class="fas fa-building"></i>
                    </div>
                    <div>
                        <div class="h6 mb-0">
                            @if ($item->branch)
                                <a href="{{ route('admin.branches.show', $item->branch) }}" class="text-decoration-none text-reset">
                                    {{ $item->branch->branch_name }}
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </div>
                        <div class="text-muted small">Branch</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#475569;">
                        <i class="fas fa-location-dot"></i>
                    </div>
                    <div>
                        <div class="h6 mb-0 text-truncate" style="max-width:140px;">{{ $item->location ?: '—' }}</div>
                        <div class="text-muted small">Location</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0f766e;">
                        <i class="fas fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="h6 mb-0">
                            @if ($item->is_active)
                                <span class="badge bg-success-subtle text-success">Active</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            @endif
                        </div>
                        <div class="text-muted small">Status</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-warning"></i> Warehouse details</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">Code</dt>
                        <dd class="col-sm-9"><span class="badge bg-secondary-subtle text-secondary">{{ $item->warehouse_code }}</span></dd>

                        <dt class="col-sm-3 text-muted">Name</dt>
                        <dd class="col-sm-9">{{ $item->warehouse_name }}</dd>

                        <dt class="col-sm-3 text-muted">Branch</dt>
                        <dd class="col-sm-9">
                            @if ($item->branch)
                                <a href="{{ route('admin.branches.show', $item->branch) }}" class="text-decoration-none">
                                    {{ $item->branch->branch_name }} ({{ $item->branch->branch_code }})
                                </a>
                            @else
                                <span class="text-muted">No branch linked.</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Location</dt>
                        <dd class="col-sm-9">{!! nl2br(e($item->location)) !!}</dd>

                        <dt class="col-sm-3 text-muted">Status</dt>
                        <dd class="col-sm-9">
                            @if ($item->is_active)
                                <span class="badge bg-success-subtle text-success">Active</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Created</dt>
                        <dd class="col-sm-9">{{ optional($item->created_at)->format('Y-m-d H:i') }}</dd>

                        <dt class="col-sm-3 text-muted">Updated</dt>
                        <dd class="col-sm-9">{{ optional($item->updated_at)->format('Y-m-d H:i') }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-gear me-1 text-secondary"></i> Actions</h2>
                </div>
                <div class="card-body d-grid gap-2">
                    <a href="{{ route('admin.warehouses.edit', $item) }}" class="btn btn-outline-primary">
                        <i class="fas fa-pen me-1"></i> Edit warehouse
                    </a>
                    @if ($trashed)
                        <form method="POST" action="{{ route('admin.warehouses.restore', $item) }}">
                            @csrf
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-rotate-left me-1"></i> Restore
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.warehouses.destroy', $item) }}"
                              onsubmit="return confirm('Deactivate this warehouse? Stock must be moved or zero first.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger w-100">
                                <i class="fas fa-power-off me-1"></i> Deactivate
                            </button>
                        </form>
                    @endif
                    @if ($item->branch)
                        <a href="{{ route('admin.branches.show', $item->branch) }}" class="btn btn-outline-secondary">
                            <i class="fas fa-sitemap me-1"></i> Branch hub
                        </a>
                    @endif
                    <a href="{{ route('admin.warehouses.audit') }}" class="btn btn-outline-secondary">
                        <i class="fas fa-clock-rotate-left me-1"></i> View audit log
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

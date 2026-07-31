@extends('layouts.admin')

@section('content')
@php
    $trashed = $item->trashed();
    $employees = $item->employees ?? collect();
    $warehouses = $item->warehouses ?? collect();
@endphp

<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0891b2,#06b6d4);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-sitemap me-2"></i>{{ $item->branch_name }}</h1>
            <p class="mb-0 small opacity-75">Branch hub — locations, team, and contact details at a glance.</p>
            <span class="badge bg-white text-dark mt-2">
                @if ($item->is_active)
                    <i class="fas fa-circle-check text-success"></i> Active
                @else
                    <i class="fas fa-circle-xmark text-secondary"></i> Inactive
                @endif
                · {{ $item->branch_code }}
            </span>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.branches.edit', $item) }}" class="btn btn-light btn-sm">
                <i class="fas fa-pen me-1"></i> Edit
            </a>
            <a href="{{ route('admin.branches.audit') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
            <a href="{{ route('admin.branches.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> All branches
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
                        <div class="h4 mb-0">{{ $warehouses->count() }}</div>
                        <div class="text-muted small">Warehouses</div>
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
                        <div class="h4 mb-0">{{ $employees->count() }}</div>
                        <div class="text-muted small">Team members</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0891b2;">
                        <i class="fas fa-phone"></i>
                    </div>
                    <div>
                        <div class="h6 mb-0">{{ $item->phone ?: '—' }}</div>
                        <div class="text-muted small">Phone</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#475569;">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div>
                        <div class="h6 mb-0 text-truncate" style="max-width:140px;">{{ $item->email ?: '—' }}</div>
                        <div class="text-muted small">Email</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-info"></i> Branch details</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted">Code</dt>
                        <dd class="col-sm-8"><span class="badge bg-secondary-subtle text-secondary">{{ $item->branch_code }}</span></dd>

                        <dt class="col-sm-4 text-muted">Name</dt>
                        <dd class="col-sm-8">{{ $item->branch_name }}</dd>

                        <dt class="col-sm-4 text-muted">Phone</dt>
                        <dd class="col-sm-8">{{ $item->phone ?: '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Email</dt>
                        <dd class="col-sm-8">{{ $item->email ?: '—' }}</dd>

                        <dt class="col-sm-4 text-muted">Address</dt>
                        <dd class="col-sm-8">{!! nl2br(e($item->address)) !!}</dd>

                        <dt class="col-sm-4 text-muted">Status</dt>
                        <dd class="col-sm-8">
                            @if ($item->is_active)
                                <span class="badge bg-success-subtle text-success">Active</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            @endif
                        </dd>

                        <dt class="col-sm-4 text-muted">Created</dt>
                        <dd class="col-sm-8">{{ optional($item->created_at)->format('Y-m-d H:i') }}</dd>
                    </dl>

                    <hr>

                    <div class="d-grid gap-2">
                        <a href="{{ route('admin.branches.edit', $item) }}" class="btn btn-outline-primary">
                            <i class="fas fa-pen me-1"></i> Edit branch
                        </a>
                        @if ($trashed)
                            <form method="POST" action="{{ route('admin.branches.restore', $item) }}">
                                @csrf
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="fas fa-rotate-left me-1"></i> Restore
                                </button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.branches.destroy', $item) }}"
                                  onsubmit="return confirm('Deactivate this branch? Reassign warehouses/employees first.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger w-100">
                                    <i class="fas fa-power-off me-1"></i> Deactivate
                                </button>
                            </form>
                        @endif
                        <a href="{{ route('admin.branches.audit') }}" class="btn btn-outline-secondary">
                            <i class="fas fa-clock-rotate-left me-1"></i> View audit log
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0"><i class="fas fa-warehouse me-1 text-warning"></i> Warehouses</h2>
                    <span class="badge bg-secondary-subtle text-secondary">{{ $warehouses->count() }}</span>
                </div>
                <div class="card-body">
                    @if ($warehouses->isEmpty())
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-warehouse fa-2x mb-2 d-block opacity-50"></i>
                            <p class="small mb-0">No warehouses on this branch.</p>
                        </div>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach ($warehouses as $wh)
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <a href="{{ route('admin.warehouses.show', $wh) }}" class="text-decoration-none fw-semibold">
                                        <i class="fas fa-warehouse me-1 text-warning"></i>{{ $wh->warehouse_name }}
                                    </a>
                                    <span class="badge bg-secondary-subtle text-secondary">{{ $wh->warehouse_code }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0"><i class="fas fa-users me-1 text-primary"></i> Team</h2>
                    <span class="badge bg-secondary-subtle text-secondary">{{ $employees->count() }}</span>
                </div>
                <div class="card-body">
                    @if ($employees->isEmpty())
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-users fa-2x mb-2 d-block opacity-50"></i>
                            <p class="small mb-0">No employees on this branch.</p>
                        </div>
                    @else
                        <ul class="list-group list-group-flush">
                            @foreach ($employees as $emp)
                                <li class="list-group-item px-0">
                                    <div class="fw-semibold">{{ $emp->name ?? $emp->name ?? ('#' . $emp->id) }}</div>
                                    @if (!empty($emp->employee_code))
                                        <div class="small text-muted">{{ $emp->employee_code }}</div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

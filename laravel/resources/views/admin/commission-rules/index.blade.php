@extends('layouts.admin')

@section('title', $title)

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#4f46e5);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-percent me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Configure commission rules per salesman. Supports 4 rule types:
                flat, tiered, product_group, target_bonus.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.commission-rules.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> Create New Rule
            </a>
        </div>
    </header>

    {{-- Flash success --}}
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-circle-check me-1"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.commission-rules.index') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">Salesman</label>
                    <select name="salesman_id" class="form-select form-select-sm">
                        <option value="">All salesmen</option>
                        @foreach ($salesmen as $id => $name)
                            <option value="{{ $id }}" @selected(request('salesman_id') == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">Rule type</label>
                    <select name="rule_type" class="form-select form-select-sm">
                        <option value="">All types</option>
                        <option value="flat"          @selected(request('rule_type') === 'flat')>Flat</option>
                        <option value="tiered"        @selected(request('rule_type') === 'tiered')>Tiered</option>
                        <option value="product_group" @selected(request('rule_type') === 'product_group')>Product Group</option>
                        <option value="target_bonus"  @selected(request('rule_type') === 'target_bonus')>Target Bonus</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        @foreach ($branches as $id => $name)
                            <option value="{{ $id }}" @selected(request('branch_id') == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-center">
                    <div class="form-check">
                        <input type="checkbox" name="active_only" value="1" class="form-check-input" id="active_only"
                               @checked(request('active_only'))>
                        <label class="form-check-label small" for="active_only">Active only</label>
                    </div>
                </div>
                <div class="col-md-1 d-grid">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Rules table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="text-center" style="width:60px;">ID</th>
                            <th>Salesman</th>
                            <th>Type</th>
                            <th class="text-end">Rate</th>
                            <th>Effective</th>
                            <th>Branch</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rules as $rule)
                            <tr>
                                <td class="text-center text-muted small">#{{ $rule->id }}</td>
                                <td>
                                    <div class="fw-semibold">{{ $rule->salesman?->name ?? '—' }}</div>
                                    @if ($rule->salesman?->employee_code)
                                        <small class="text-muted">{{ $rule->salesman->employee_code }}</small>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $typeBadge = [
                                            'flat'          => '<span class="badge bg-primary">Flat</span>',
                                            'tiered'        => '<span class="badge bg-info text-dark">Tiered</span>',
                                            'product_group' => '<span class="badge bg-warning text-dark">Product Group</span>',
                                            'target_bonus'  => '<span class="badge bg-success">Target Bonus</span>',
                                        ][$rule->rule_type] ?? '<span class="badge bg-secondary">' . e($rule->rule_type) . '</span>';
                                    @endphp
                                    {!! $typeBadge !!}
                                </td>
                                <td class="text-end font-monospace">
                                    @if ($rule->rule_type === 'flat' || $rule->rate > 0)
                                        {{ number_format((float) $rule->rate, 4) }}%
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    <small>
                                        {{ $rule->effective_from?->format('Y-m-d') ?? '—' }}
                                        @if ($rule->effective_to)
                                            <br><span class="text-muted">to {{ $rule->effective_to->format('Y-m-d') }}</span>
                                        @else
                                            <br><span class="text-muted">open-ended</span>
                                        @endif
                                    </small>
                                </td>
                                <td>
                                    @if ($rule->branch_id)
                                        <span class="badge bg-light text-dark border">{{ $rule->branch?->branch_name ?? "Branch #{$rule->branch_id}" }}</span>
                                    @else
                                        <span class="badge bg-light text-muted">All branches</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($rule->is_active)
                                        <span class="badge bg-success-subtle text-success">
                                            <i class="fas fa-circle-check me-1"></i> Active
                                        </span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary">
                                            <i class="fas fa-circle me-1"></i> Inactive
                                        </span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <div class="d-flex gap-1 justify-content-center">
                                        <a href="{{ route('admin.commission-rules.show', $rule) }}"
                                           class="btn btn-sm btn-outline-primary" title="Show details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        @if ($rule->is_active)
                                            <form method="POST"
                                                  action="{{ route('admin.commission-rules.deactivate', $rule) }}"
                                                  class="d-inline"
                                                  onsubmit="return confirm('Deactivate commission rule #{{ $rule->id }}? This sets effective_to = today and is_active = false.');">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Deactivate">
                                                    <i class="fas fa-power-off"></i>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No commission rules found matching the filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($rules->hasPages())
            <div class="card-footer bg-light">
                {{ $rules->links() }}
            </div>
        @endif
    </div>
</div>
@endsection

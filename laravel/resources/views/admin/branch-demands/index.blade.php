@extends('layouts.admin')

@section('title', 'Branch Demands')

@push('css')
<link rel="stylesheet" href="/assets/css/branch-demand.css">
@endpush

@section('content')
<div class="bd-demand-app container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-right-left me-2"></i>My Demands</h1>
            <p class="mb-0 small opacity-75">
                Demands created by my branch (requester view). To see demands from other branches, go to Pending.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.branch-demands.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New Demand
            </a>
            <a href="{{ route('admin.branch-demands.pending') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-inbox me-1"></i> Pending
            </a>
            <a href="{{ route('admin.branch-demands.pending-receipt') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clipboard-check me-1"></i> Pending Receipt
            </a>
            <a href="{{ route('admin.branch-demands.weekly-report') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-chart-bar me-1"></i> Weekly Report
            </a>
            <a href="{{ route('admin.branch-demands.checklist') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clipboard-check me-1"></i> Audit Checklist
            </a>
        </div>
    </header>

    {{-- Filters --}}
    <div class="card shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.branch-demands.index') }}" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label small mb-0">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="received" {{ request('status') === 'received' ? 'selected' : '' }}>Received</option>
                        <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Rejected</option>
                        <option value="reversed" {{ request('status') === 'reversed' ? 'selected' : '' }}>Reversed</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Direction</label>
                    <select name="direction" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="outgoing" {{ request('direction') === 'outgoing' ? 'selected' : '' }}>Outgoing (My Demands)</option>
                        <option value="incoming" {{ request('direction') === 'incoming' ? 'selected' : '' }}>Incoming (For Me)</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">From</label>
                    <input type="date" name="date_from" value="{{ request('date_from') }}" class="form-control form-control-sm">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">To</label>
                    <input type="date" name="date_to" value="{{ request('date_to') }}" class="form-control form-control-sm">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Search</label>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="Demand code..." class="form-control form-control-sm">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search me-1"></i>Filter</button>
                    <a href="{{ route('admin.branch-demands.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Demands table --}}
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="bd-index-table table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Demand Code</th>
                            <th>Date</th>
                            <th>From (Requester)</th>
                            <th>To (Supplier)</th>
                            <th>Items</th>
                            <th>Total Value</th>
                            <th>Status</th>
                            <th>Receipt</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($demands as $demand)
                        <tr>
                            <td>
                                <a href="{{ route('admin.branch-demands.show', $demand->id) }}" class="fw-semibold text-decoration-none">
                                    {{ $demand->demand_code }}
                                </a>
                            </td>
                            <td>{{ $demand->demand_date ? $demand->demand_date->format('d M Y') : '-' }}</td>
                            <td>{{ $demand->fromBranch->branch_name ?? 'N/A' }}</td>
                            <td>{{ $demand->toBranch->branch_name ?? 'N/A' }}</td>
                            <td>
                                <span class="badge bg-light text-dark">{{ $demand->items->count() }}</span>
                            </td>
                            <td class="fw-semibold">{{ $demand->total_value ? number_format((float) $demand->total_value, 2) : '-' }}</td>
                            <td><x-branch-demand.status-badge :status="$demand->status" :received-at="$demand->received_at" /></td>
                            <td>
                                @if($demand->received_at)
                                    <span class="text-success small"><i class="fas fa-check me-1"></i>{{ $demand->received_at->format('d M H:i') }}</span>
                                @elseif($demand->status === 'received')
                                    <span class="text-warning small"><i class="fas fa-clock me-1"></i>Pending</span>
                                @else
                                    <span class="text-muted small">-</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <a href="{{ route('admin.branch-demands.show', $demand->id) }}" class="btn btn-outline-primary btn-sm" title="View Details">
                                    <i class="fas fa-eye"></i>
                                </a>
                                @if($demand->status === 'received' && !$demand->is_reversed)
                                <a href="{{ route('admin.branch-demands.audit', $demand->id) }}" class="btn btn-outline-info btn-sm" title="Audit Trail">
                                    <i class="fas fa-search"></i>
                                </a>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="bd-empty-state text-center text-muted py-4">
                                <i class="fas fa-inbox me-1"></i> No branch demands found.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Pagination --}}
    <div class="mt-3">
        {{ $demands->withQueryString()->links() }}
    </div>
</div>
@endsection

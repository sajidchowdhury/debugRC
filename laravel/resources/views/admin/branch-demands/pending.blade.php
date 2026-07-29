@extends('layouts.admin')

@section('title', 'Pending Demands')

@push('css')
<link rel="stylesheet" href="/assets/css/branch-demand.css">
@endpush

@section('content')
<div class="bd-demand-app container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0284c7,#0369a1);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-inbox me-2"></i>Pending Demands</h1>
            <p class="mb-0 small opacity-75">
                Demands from other branches that need your warehouse to supply goods.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.branch-demands.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-list me-1"></i> All Demands
            </a>
            <a href="{{ route('admin.branch-demands.pending-receipt') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clipboard-check me-1"></i> Pending Receipt
            </a>
        </div>
    </header>

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
                            <th>Items</th>
                            <th>Status</th>
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
                            <td>
                                <span class="badge bg-light text-dark">{{ $demand->items->count() }} item(s)</span>
                            </td>
                            <td><x-branch-demand.status-badge :status="$demand->status" /></td>
                            <td class="text-center">
                                <a href="{{ route('admin.branch-demands.show', $demand->id) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-eye me-1"></i> View & Send
                                </a>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="bd-empty-state text-center text-muted py-4">
                                <i class="fas fa-check-circle me-1"></i> No pending demands for your branch.
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

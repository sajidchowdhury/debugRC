@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0f766e,#14b8a6);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-list-alt me-2"></i>Shadow Mode Comparisons</h1>
            <p class="mb-0 small opacity-75">All comparison results between Laravel and Legacy Warehouse Transfer data</p>
        </div>
        <div>
            <a href="{{ route('admin.shadow-mode.index') }}" class="btn btn-sm btn-light">
                <i class="fas fa-arrow-left me-1"></i>Dashboard
            </a>
        </div>
    </header>

    {{-- Filter bar --}}
    <div class="card mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.shadow-mode.comparisons') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">From Date</label>
                    <input type="date" name="from_date" value="{{ $fromDate }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">To Date</label>
                    <input type="date" name="to_date" value="{{ $toDate }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All ({{ $statusCounts['all'] }})</option>
                        <option value="match" {{ $statusFilter === 'match' ? 'selected' : '' }}>Match ({{ $statusCounts['match'] }})</option>
                        <option value="diff" {{ $statusFilter === 'diff' ? 'selected' : '' }}>Diff ({{ $statusCounts['diff'] }})</option>
                        <option value="missing_legacy" {{ $statusFilter === 'missing_legacy' ? 'selected' : '' }}>Missing Legacy ({{ $statusCounts['missing_legacy'] }})</option>
                        <option value="error" {{ $statusFilter === 'error' ? 'selected' : '' }}>Error ({{ $statusCounts['error'] }})</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Operation</label>
                    <select name="operation" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="create" {{ $operationFilter === 'create' ? 'selected' : '' }}>Create</option>
                        <option value="confirm" {{ $operationFilter === 'confirm' ? 'selected' : '' }}>Confirm</option>
                        <option value="cancel" {{ $operationFilter === 'cancel' ? 'selected' : '' }}>Cancel</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        <i class="fas fa-filter me-1"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Comparisons table --}}
    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Laravel Transfer</th>
                            <th>Legacy Transfer</th>
                            <th>Operation</th>
                            <th>Mode</th>
                            <th>Status</th>
                            <th>Total Checks</th>
                            <th>Match</th>
                            <th>Diff</th>
                            <th>Branch</th>
                            <th>Compared At</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($comparisons as $c)
                        <tr class="{{ $c->diff_status === 'match' ? '' : ($c->diff_status === 'diff' ? 'table-danger' : 'table-warning') }}">
                            <td>{{ $c->id }}</td>
                            <td>
                                <a href="{{ route('admin.warehouse-transfers.show', $c->laravel_transfer_id) }}">
                                    {{ $c->laravel_transfer_code }}
                                </a>
                            </td>
                            <td>
                                @if($c->legacy_transfer_code)
                                    {{ $c->legacy_transfer_code }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td><span class="badge bg-info">{{ $c->operation }}</span></td>
                            <td><span class="badge bg-secondary">{{ $c->mode }}</span></td>
                            <td>
                                @switch($c->diff_status)
                                    @case('match')
                                        <span class="badge bg-success"><i class="fas fa-check me-1"></i>Match</span>
                                        @break
                                    @case('diff')
                                        <span class="badge bg-danger"><i class="fas fa-times me-1"></i>Diff</span>
                                        @break
                                    @case('missing_legacy')
                                        <span class="badge bg-warning text-dark"><i class="fas fa-question me-1"></i>Missing</span>
                                        @break
                                    @case('error')
                                        <span class="badge bg-secondary"><i class="fas fa-exclamation me-1"></i>Error</span>
                                        @break
                                    @default
                                        <span class="badge bg-secondary">{{ $c->diff_status }}</span>
                                @endswitch
                            </td>
                            <td>{{ $c->total_checks }}</td>
                            <td class="text-success">{{ $c->match_count }}</td>
                            <td class="{{ $c->diff_count > 0 ? 'text-danger fw-bold' : '' }}">{{ $c->diff_count }}</td>
                            <td>{{ $c->branch_id }}</td>
                            <td>{{ $c->compared_at }}</td>
                            <td>
                                <a href="{{ route('admin.shadow-mode.detail', $c->id) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-eye me-1"></i>Detail
                                </a>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="12" class="text-center text-muted py-4">
                                No comparison records found for the selected date range and filters.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if($comparisons->hasPages())
        <div class="card-footer d-flex justify-content-center">
            {{ $comparisons->links() }}
        </div>
        @endif
    </div>
</div>
@endsection

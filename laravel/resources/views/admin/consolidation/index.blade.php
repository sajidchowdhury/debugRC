@extends('layouts.admin')

@section('title', 'Consolidation — Remote Center ERP')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h4 class="mb-0"><i class="fas fa-layer-group me-2"></i>Consolidation & Intercompany</h4>
                <a href="{{ route('admin.consolidation.create') }}" class="btn btn-primary">
                    <i class="fas fa-play me-1"></i> Run Consolidation
                </a>
            </div>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-start border-primary border-4 h-100">
                <div class="card-body">
                    <div class="text-muted small">Active Elimination Rules</div>
                    <div class="h3 mb-0">{{ $activeRules }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-start border-warning border-4 h-100">
                <div class="card-body">
                    <div class="text-muted small">Draft Runs</div>
                    <div class="h3 mb-0">{{ $draftRuns }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-start border-success border-4 h-100">
                <div class="card-body">
                    <div class="text-muted small">Total Runs</div>
                    <div class="h3 mb-0">{{ $runs->total() }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-start border-info border-4 h-100">
                <div class="card-body">
                    <div class="text-muted small">Quick Links</div>
                    <div class="d-flex gap-2 flex-wrap mt-1">
                        <a href="{{ route('admin.consolidation.consolidated-tb') }}" class="btn btn-sm btn-outline-primary">Consolidated TB</a>
                        <a href="{{ route('admin.consolidation.consolidated-bs') }}" class="btn btn-sm btn-outline-primary">Consolidated BS</a>
                        <a href="{{ route('admin.consolidation.consolidated-pnl') }}" class="btn btn-sm btn-outline-primary">Consolidated P&L</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Consolidation Runs Table --}}
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Consolidation Runs</h5>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.consolidation.reconciliation') }}" class="btn btn-sm btn-outline-info">
                    <i class="fas fa-exchange-alt me-1"></i> IC Reconciliation
                </a>
                <a href="{{ route('admin.consolidation.rules') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-cogs me-1"></i> Elimination Rules
                </a>
                <a href="{{ route('admin.consolidation.companies') }}" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-building me-1"></i> Companies
                </a>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Run Code</th>
                            <th>Name</th>
                            <th>Period</th>
                            <th>Status</th>
                            <th>Entries</th>
                            <th>Total Elimination</th>
                            <th>Created By</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($runs as $run)
                        <tr>
                            <td>
                                <a href="{{ route('admin.consolidation.show', $run) }}" class="fw-bold">
                                    {{ $run->run_code }}
                                </a>
                            </td>
                            <td>{{ $run->name }}</td>
                            <td>{{ $run->period_from->format('d M Y') }} — {{ $run->period_to->format('d M Y') }}</td>
                            <td>
                                @php
                                    $statusClass = match($run->status) {
                                        'draft' => 'bg-warning text-dark',
                                        'posted' => 'bg-success',
                                        'reversed' => 'bg-secondary',
                                        default => 'bg-light text-dark'
                                    };
                                @endphp
                                <span class="badge {{ $statusClass }}">{{ ucfirst($run->status) }}</span>
                            </td>
                            <td>{{ $run->eliminationEntries()->count() }}</td>
                            <td class="text-end">{{ number_format($run->getTotalEliminationAmount(), 2) }}</td>
                            <td>{{ $run->creator?->name ?? 'System' }}</td>
                            <td>{{ $run->created_at->format('d M Y H:i') }}</td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="{{ route('admin.consolidation.show', $run) }}" class="btn btn-outline-primary" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    @if($run->isDraft())
                                    <form method="POST" action="{{ route('admin.consolidation.post', $run) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-success" title="Post"
                                                onclick="return confirm('Post this consolidation run? This will create elimination journal entries.')">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.consolidation.destroy', $run) }}" class="d-inline">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger" title="Delete"
                                                onclick="return confirm('Delete this draft consolidation run?')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                No consolidation runs yet. Click "Run Consolidation" to create one.
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
        {{ $runs->withQueryString()->links() }}
    </div>
</div>
@endsection

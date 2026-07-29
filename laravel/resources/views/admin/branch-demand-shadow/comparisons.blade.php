@extends('layouts.app')

@section('title', 'Shadow Demand Comparisons')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Shadow Demand Comparisons</h1>
        <a href="{{ route('admin.branch-demand-shadow.index') }}" class="btn btn-outline-secondary">&larr; Dashboard</a>
    </div>

    {{-- Filters --}}
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">From</label>
                    <input type="date" name="from_date" value="{{ $fromDate }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">To</label>
                    <input type="date" name="to_date" value="{{ $toDate }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All ({{ $statusCounts['all'] }})</option>
                        <option value="match" {{ $statusFilter === 'match' ? 'selected' : '' }}>Match ({{ $statusCounts['match'] }})</option>
                        <option value="diff" {{ $statusFilter === 'diff' ? 'selected' : '' }}>Diff ({{ $statusCounts['diff'] }})</option>
                        <option value="missing_legacy" {{ $statusFilter === 'missing_legacy' ? 'selected' : '' }}>Missing Legacy ({{ $statusCounts['missing_legacy'] }})</option>
                        <option value="error" {{ $statusFilter === 'error' ? 'selected' : '' }}>Error ({{ $statusCounts['error'] }})</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Operation</label>
                    <select name="operation" class="form-select">
                        <option value="">All</option>
                        <option value="create" {{ $operationFilter === 'create' ? 'selected' : '' }}>Create</option>
                        <option value="send" {{ $operationFilter === 'send' ? 'selected' : '' }}>Send</option>
                        <option value="confirm_receipt" {{ $operationFilter === 'confirm_receipt' ? 'selected' : '' }}>Confirm Receipt</option>
                        <option value="reverse" {{ $operationFilter === 'reverse' ? 'selected' : '' }}>Reverse</option>
                        <option value="settle" {{ $operationFilter === 'settle' ? 'selected' : '' }}>Settle</option>
                        <option value="reprice" {{ $operationFilter === 'reprice' ? 'selected' : '' }}>Reprice</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary">Filter</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Results Table --}}
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Operation</th>
                            <th>Demand Code</th>
                            <th>From Branch</th>
                            <th>To Branch</th>
                            <th>Status</th>
                            <th>Mode</th>
                            <th>Compared At</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($comparisons as $c)
                            <tr>
                                <td><a href="{{ route('admin.branch-demand-shadow.detail', $c->id) }}">{{ $c->id }}</a></td>
                                <td>{{ $c->operation }}</td>
                                <td>{{ $c->demand_code ?? $c->branch_demand_id }}</td>
                                <td>{{ $c->from_branch_id }}</td>
                                <td>{{ $c->to_branch_id }}</td>
                                <td>
                                    @php
                                        $badgeClass = match($c->diff_status) {
                                            'match' => 'bg-success',
                                            'diff' => 'bg-danger',
                                            'missing_legacy' => 'bg-warning',
                                            'error' => 'bg-secondary',
                                            default => 'bg-secondary',
                                        };
                                    @endphp
                                    <span class="badge {{ $badgeClass }}">{{ $c->diff_status }}</span>
                                </td>
                                <td><span class="badge bg-info">{{ $c->shadow_mode }}</span></td>
                                <td>{{ $c->compared_at }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $comparisons->withQueryString()->links() }}
        </div>
    </div>
</div>
@endsection

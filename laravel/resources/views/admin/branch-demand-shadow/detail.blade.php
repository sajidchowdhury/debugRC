@extends('layouts.app')

@section('title', 'Shadow Comparison Detail')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Comparison #{{ $comparison->id }}</h1>
        <a href="{{ route('admin.branch-demand-shadow.comparisons') }}" class="btn btn-outline-secondary">&larr; Comparisons</a>
    </div>

    {{-- Summary --}}
    <div class="card mb-4">
        <div class="card-header"><h5 class="mb-0">Summary</h5></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <table class="table table-sm">
                        <tr><th>Operation</th><td>{{ $comparison->operation }}</td></tr>
                        <tr><th>Demand ID</th><td>{{ $comparison->branch_demand_id }}</td></tr>
                        <tr><th>Demand Code</th><td>{{ $comparison->demand_code ?? 'N/A' }}</td></tr>
                        <tr><th>From Branch</th><td>{{ $comparison->from_branch_id }}</td></tr>
                        <tr><th>To Branch</th><td>{{ $comparison->to_branch_id }}</td></tr>
                    </table>
                </div>
                <div class="col-md-4">
                    <table class="table table-sm">
                        <tr><th>Diff Status</th><td>
                            @php
                                $badgeClass = match($comparison->diff_status) {
                                    'match' => 'bg-success',
                                    'diff' => 'bg-danger',
                                    'missing_legacy' => 'bg-warning',
                                    'error' => 'bg-secondary',
                                    default => 'bg-secondary',
                                };
                            @endphp
                            <span class="badge {{ $badgeClass }} fs-6">{{ $comparison->diff_status }}</span>
                        </td></tr>
                        <tr><th>Shadow Mode</th><td><span class="badge bg-info">{{ $comparison->shadow_mode }}</span></td></tr>
                        <tr><th>Compared At</th><td>{{ $comparison->compared_at }}</td></tr>
                        <tr><th>Compared By</th><td>{{ $comparison->compared_by ?? 'System' }}</td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Diff Details --}}
    @if(!empty($diffDetails))
        <div class="card mb-4 border-danger">
            <div class="card-header bg-danger text-white"><h5 class="mb-0">Differences Found</h5></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Laravel Value</th>
                                <th>Legacy Value</th>
                                <th>Tolerance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($diffDetails as $diff)
                                <tr>
                                    <td>{{ $diff['field'] ?? 'N/A' }}</td>
                                    <td>{{ is_array($diff['laravel'] ?? null) ? json_encode($diff['laravel']) : ($diff['laravel'] ?? 'N/A') }}</td>
                                    <td>{{ is_array($diff['legacy'] ?? null) ? json_encode($diff['legacy']) : ($diff['legacy'] ?? 'N/A') }}</td>
                                    <td>{{ $diff['tolerance'] ?? 'N/A' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    {{-- Laravel vs Legacy Data --}}
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white"><h5 class="mb-0">Laravel Data</h5></div>
                <div class="card-body">
                    <pre class="bg-light p-3 rounded"><code>{{ json_encode($laravelData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</code></pre>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-warning"><h5 class="mb-0">Legacy Data</h5></div>
                <div class="card-body">
                    @if($legacyData)
                        <pre class="bg-light p-3 rounded"><code>{{ json_encode($legacyData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</code></pre>
                    @else
                        <p class="text-muted">No legacy data available.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Laravel Demand Link --}}
    @if($laravelDemand)
        <div class="mt-3">
            <a href="{{ route('admin.branch-demands.show', $laravelDemand->id) }}" class="btn btn-outline-primary">
                View Demand {{ $laravelDemand->demand_code }}
            </a>
        </div>
    @endif
</div>
@endsection

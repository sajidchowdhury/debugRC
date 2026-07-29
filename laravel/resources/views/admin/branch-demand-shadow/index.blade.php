@extends('layouts.app')

@section('title', 'Branch Demand Shadow Mode')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Branch Demand Shadow Mode</h1>
        <div>
            @if($enabled)
                <span class="badge bg-{{ $mode === 'active' ? 'success' : ($mode === 'passive' ? 'warning' : 'secondary') }}">
                    {{ ucfirst($mode) }}
                </span>
            @else
                <span class="badge bg-secondary">Disabled</span>
            @endif
        </div>
    </div>

    @if(!$enabled)
        <div class="alert alert-info">
            <strong>Shadow mode is disabled.</strong> Enable it by setting <code>BRANCH_DEMAND_SHADOW_ENABLED=true</code> in your <code>.env</code> file.
        </div>
    @endif

    {{-- Summary Stats --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <h5 class="card-title text-muted">Total</h5>
                    <p class="display-6">{{ $summary['total'] }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-success">
                <div class="card-body">
                    <h5 class="card-title text-success">Match</h5>
                    <p class="display-6 text-success">{{ $summary['match'] }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-danger">
                <div class="card-body">
                    <h5 class="card-title text-danger">Diff</h5>
                    <p class="display-6 text-danger">{{ $summary['diff'] }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-warning">
                <div class="card-body">
                    <h5 class="card-title text-warning">Missing Legacy</h5>
                    <p class="display-6 text-warning">{{ $summary['missing_legacy'] }}</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Cutover Readiness --}}
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Cutover Readiness</h5>
            <a href="{{ route('admin.branch-demand-shadow.cutover') }}" class="btn btn-sm btn-outline-primary">Details</a>
        </div>
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <p>Consecutive clean days: <strong>{{ $cutover['consecutive_clean_days'] }}</strong> / {{ $cutover['threshold'] }}</p>
                    <div class="progress" style="height: 24px;">
                        <div class="progress-bar {{ $cutover['cutover_ready'] ? 'bg-success' : 'bg-warning' }}"
                             style="width: {{ min(100, ($cutover['consecutive_clean_days'] / $cutover['threshold']) * 100) }}%">
                            {{ $cutover['consecutive_clean_days'] }} / {{ $cutover['threshold'] }}
                        </div>
                    </div>
                </div>
                <div class="col-md-6 text-center">
                    @if($cutover['cutover_ready'])
                        <div class="alert alert-success mb-0">
                            <strong>READY FOR CUTOVER</strong><br>
                            <small>Zero diffs for {{ $cutover['consecutive_clean_days'] }} consecutive days</small>
                        </div>
                    @else
                        <div class="alert alert-warning mb-0">
                            <strong>NOT READY</strong><br>
                            <small>{{ $cutover['remaining_days'] }} more clean days needed</small>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Recent Diffs --}}
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Recent Diffs</h5>
            <a href="{{ route('admin.branch-demand-shadow.comparisons', ['status' => 'diff']) }}" class="btn btn-sm btn-outline-danger">View All</a>
        </div>
        <div class="card-body">
            @if($recentDiffs->isEmpty())
                <p class="text-muted text-center mb-0">No diffs found in the last 7 days.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Operation</th>
                                <th>Demand</th>
                                <th>Status</th>
                                <th>Compared At</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentDiffs as $diff)
                                <tr>
                                    <td><a href="{{ route('admin.branch-demand-shadow.detail', $diff->id) }}">{{ $diff->id }}</a></td>
                                    <td>{{ $diff->operation }}</td>
                                    <td>{{ $diff->demand_code ?? $diff->branch_demand_id }}</td>
                                    <td><span class="badge bg-danger">{{ $diff->diff_status }}</span></td>
                                    <td>{{ $diff->compared_at }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- Actions --}}
    <div class="card">
        <div class="card-header"><h5 class="mb-0">Actions</h5></div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.branch-demand-shadow.run-comparison') }}" class="d-inline">
                @csrf
                <input type="hidden" name="from_date" value="{{ now()->subDay()->format('Y-m-d') }}">
                <input type="hidden" name="to_date" value="{{ now()->format('Y-m-d') }}">
                <button type="submit" class="btn btn-primary" @if(!$enabled) disabled @endif>
                    Run Comparison (Yesterday)
                </button>
            </form>
            <form method="POST" action="{{ route('admin.branch-demand-shadow.run-comparison') }}" class="d-inline ms-2">
                @csrf
                <input type="hidden" name="from_date" value="{{ now()->subDays(7)->format('Y-m-d') }}">
                <input type="hidden" name="to_date" value="{{ now()->format('Y-m-d') }}">
                <button type="submit" class="btn btn-outline-primary" @if(!$enabled) disabled @endif>
                    Run Comparison (Last 7 Days)
                </button>
            </form>
            <form method="POST" action="{{ route('admin.branch-demand-shadow.purge') }}" class="d-inline ms-2">
                @csrf
                <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Purge old comparison records?')">
                    Purge Old Records
                </button>
            </form>
        </div>
    </div>
</div>
@endsection

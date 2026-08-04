@extends('layouts.app')

@section('title', 'Branch Demand Cutover Readiness')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Branch Demand Cutover Readiness</h1>
        <a href="{{ route('admin.branch-demand-shadow.index') }}" class="btn btn-outline-secondary">&larr; Dashboard</a>
    </div>

    {{-- Readiness Status --}}
    <div class="card mb-4 {{ $readiness['cutover_ready'] ? 'border-success' : 'border-warning' }}">
        <div class="card-header {{ $readiness['cutover_ready'] ? 'bg-success text-white' : 'bg-warning' }}">
            <h5 class="mb-0">Cutover Status: {{ $readiness['cutover_ready'] ? 'READY' : 'NOT READY' }}</h5>
        </div>
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <p>Consecutive clean days: <strong>{{ $readiness['consecutive_clean_days'] }}</strong> / {{ $readiness['threshold'] }}</p>
                    <div class="progress" style="height: 30px;">
                        <div class="progress-bar {{ $readiness['cutover_ready'] ? 'bg-success' : 'bg-warning' }}"
                             style="width: {{ min(100, ($readiness['consecutive_clean_days'] / $readiness['threshold']) * 100) }}%">
                            {{ $readiness['consecutive_clean_days'] }} / {{ $readiness['threshold'] }}
                        </div>
                    </div>
                    @if(!$readiness['cutover_ready'])
                        <p class="mt-2 text-muted">{{ $readiness['remaining_days'] }} more consecutive clean days needed.</p>
                    @endif
                </div>
                <div class="col-md-6 text-center">
                    @if($readiness['cutover_ready'])
                        <div class="alert alert-success mb-0">
                            <h4 class="alert-heading">CUTOVER READY</h4>
                            <p>The Branch Demand module has achieved zero diffs for {{ $readiness['consecutive_clean_days'] }} consecutive days. You may proceed with cutover.</p>
                        </div>
                    @else
                        <div class="alert alert-warning mb-0">
                            <h4 class="alert-heading">NOT READY</h4>
                            <p>Continue monitoring. {{ $readiness['remaining_days'] }} more clean days required.</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Daily Log --}}
    <div class="card">
        <div class="card-header"><h5 class="mb-0">Daily Cutover Log (Last 14 Days)</h5></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Total Compared</th>
                            <th>Match</th>
                            <th>Diff</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($dailyLogs as $log)
                            {{-- FINANCE-2 (G-014): property names aligned with the actual --}}
                            {{-- `shadow_cutover_log` schema: is_clean_day, comparisons_total, --}}
                            {{-- comparisons_match, comparisons_diff (was: is_clean, --}}
                            {{-- total_compared, match_count, diff_count). --}}
                            <tr class="{{ $log->is_clean_day ? 'table-success' : 'table-danger' }}">
                                <td>{{ $log->check_date }}</td>
                                <td>{{ $log->comparisons_total }}</td>
                                <td>{{ $log->comparisons_match }}</td>
                                <td>{{ $log->comparisons_diff }}</td>
                                <td>
                                    @if($log->is_clean_day)
                                        <span class="badge bg-success">Clean</span>
                                    @else
                                        <span class="badge bg-danger">Has Diffs</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        @if($dailyLogs->isEmpty())
                            <tr><td colspan="5" class="text-center text-muted">No cutover logs yet.</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

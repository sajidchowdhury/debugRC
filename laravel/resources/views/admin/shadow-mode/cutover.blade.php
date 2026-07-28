@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0f766e,#14b8a6);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-flag-checkered me-2"></i>Cutover Readiness Report</h1>
            <p class="mb-0 small opacity-75">Phase 7.3 — Track consecutive zero-diff days for production cutover</p>
        </div>
        <div>
            <a href="{{ route('admin.shadow-mode.index') }}" class="btn btn-sm btn-light">
                <i class="fas fa-arrow-left me-1"></i>Dashboard
            </a>
        </div>
    </header>

    {{-- Cutover readiness banner --}}
    <div class="card mb-4 border-{{ $readiness['cutover_ready'] ? 'success' : 'info' }}">
        <div class="card-body text-center">
            @if($readiness['cutover_ready'])
                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                <h3 class="text-success">CUTOVER IS READY!</h3>
                <p class="text-muted">{{ $readiness['consecutive_clean_days'] }} consecutive zero-diff days (threshold: {{ $readiness['threshold'] }})</p>
                <div class="alert alert-success">
                    <strong>Next step:</strong> Schedule the cutover date with stakeholders. Disable legacy WarehouseTransfer module and enable Laravel as the sole production system.
                </div>
            @else
                <i class="fas fa-clock fa-4x text-info mb-3"></i>
                <h3 class="text-info">Cutover Not Yet Ready</h3>
                <p class="text-muted">{{ $readiness['consecutive_clean_days'] }} / {{ $readiness['threshold'] }} consecutive zero-diff days</p>
                <div class="alert alert-info">
                    <strong>{{ $readiness['remaining_days'] }} more clean days required.</strong> Continue running shadow mode comparisons daily until the threshold is met.
                </div>
            @endif
        </div>
    </div>

    {{-- Progress bar --}}
    <div class="card mb-4">
        <div class="card-header"><i class="fas fa-chart-line me-2"></i>Cutover Progress</div>
        <div class="card-body">
            <div class="progress mb-3" style="height: 40px;">
                @php
                    $progress = min(100, ($readiness['consecutive_clean_days'] / $readiness['threshold']) * 100);
                @endphp
                <div class="progress-bar {{ $readiness['cutover_ready'] ? 'bg-success' : 'bg-info' }}"
                     style="width: {{ $progress }}%"
                     role="progressbar">
                    {{ $readiness['consecutive_clean_days'] }} / {{ $readiness['threshold'] }}
                </div>
            </div>
            <div class="row text-center">
                <div class="col-md-3">
                    <h6>Threshold</h6>
                    <p class="fs-5 fw-bold">{{ $readiness['threshold'] }} days</p>
                </div>
                <div class="col-md-3">
                    <h6>Consecutive Clean</h6>
                    <p class="fs-5 fw-bold text-success">{{ $readiness['consecutive_clean_days'] }} days</p>
                </div>
                <div class="col-md-3">
                    <h6>Remaining</h6>
                    <p class="fs-5 fw-bold text-warning">{{ $readiness['remaining_days'] }} days</p>
                </div>
                <div class="col-md-3">
                    <h6>Status</h6>
                    <p class="fs-5 fw-bold {{ $readiness['cutover_ready'] ? 'text-success' : 'text-info' }}">
                        {{ $readiness['cutover_ready'] ? 'READY' : 'NOT READY' }}
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- Daily logs table --}}
    <div class="card">
        <div class="card-header"><i class="fas fa-calendar me-2"></i>Daily Cutover Log (Last 14 Days)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Match</th>
                            <th>Diff</th>
                            <th>Missing</th>
                            <th>Error</th>
                            <th>Clean?</th>
                            <th>Consecutive</th>
                            <th>Ready?</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($dailyLogs as $log)
                        <tr class="{{ $log->is_clean_day ? '' : 'table-danger' }}">
                            <td>{{ $log->check_date }}</td>
                            <td>{{ $log->comparisons_total }}</td>
                            <td class="text-success">{{ $log->comparisons_match }}</td>
                            <td class="{{ $log->comparisons_diff > 0 ? 'text-danger fw-bold' : '' }}">{{ $log->comparisons_diff }}</td>
                            <td class="text-warning">{{ $log->comparisons_missing_legacy }}</td>
                            <td>{{ $log->comparisons_error }}</td>
                            <td>
                                @if($log->is_clean_day)
                                    <i class="fas fa-check text-success"></i>
                                @else
                                    <i class="fas fa-times text-danger"></i>
                                @endif
                            </td>
                            <td>
                                <span class="badge bg-{{ $log->consecutive_clean_days >= $readiness['threshold'] ? 'success' : 'info' }}">
                                    {{ $log->consecutive_clean_days }}
                                </span>
                            </td>
                            <td>
                                @if($log->cutover_ready)
                                    <span class="badge bg-success">Ready</span>
                                @else
                                    <span class="badge bg-secondary">Not yet</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                No daily cutover logs yet. Run a batch comparison first.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Cutover procedure --}}
    <div class="card mt-4">
        <div class="card-header"><i class="fas fa-rocket me-2"></i>Cutover Procedure</div>
        <div class="card-body">
            <h6>When cutover readiness is achieved:</h6>
            <ol>
                <li><strong>Notify stakeholders</strong> — inform branch managers, IT, and finance teams of the cutover date.</li>
                <li><strong>Schedule maintenance window</strong> — plan a 2-4 hour window for the switch.</li>
                <li><strong>Disable legacy module</strong> — set <code>SHADOW_MODE_ENABLED=false</code> and remove the legacy WarehouseTransfer menu item.</li>
                <li><strong>Enable Laravel as primary</strong> — the WarehouseTransfer module is already the primary in passive/active mode.</li>
                <li><strong>Remove legacy database dependency</strong> — after 30 days of stable operation, disconnect the archive MySQL connection.</li>
                <li><strong>Archive shadow comparison data</strong> — export <code>shadow_transfer_comparisons</code> and <code>shadow_cutover_log</code> for audit records.</li>
            </ol>
            <h6 class="mt-3">Rollback plan:</h6>
            <ol>
                <li>If critical diffs are found during shadow mode, set <code>SHADOW_MODE_MODE=off</code> immediately.</li>
                <li>Re-enable the legacy WarehouseTransfer module as the primary.</li>
                <li>Investigate the root cause of the diffs.</li>
                <li>Fix the issue and restart shadow mode from PASSIVE.</li>
            </ol>
        </div>
    </div>
</div>
@endsection

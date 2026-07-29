@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0f766e,#14b8a6);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-clone me-2"></i>Shadow Mode Dashboard</h1>
            <p class="mb-0 small opacity-75">Phase 7.3 — Compare Laravel vs Legacy Warehouse Transfer data for cutover readiness</p>
        </div>
        <div>
            <a href="{{ route('admin.warehouse-transfers.index') }}" class="btn btn-sm btn-light">
                <i class="fas fa-arrow-left me-1"></i>Back to Transfers
            </a>
            <a href="{{ route('admin.shadow-mode.cutover') }}" class="btn btn-sm btn-outline-light ms-1">
                <i class="fas fa-flag-checkered me-1"></i>Cutover Readiness
            </a>
        </div>
    </header>

    {{-- Mode status banner --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex align-items-center gap-3">
                <div class="flex-shrink-0">
                    @if($enabled)
                        <span class="badge bg-{{ $mode === 'active' ? 'success' : ($mode === 'passive' ? 'warning' : 'secondary') }} fs-6">
                            <i class="fas fa-power-off me-1"></i>{{ ucfirst($mode) }} Mode
                        </span>
                    @else
                        <span class="badge bg-secondary fs-6">
                            <i class="fas fa-power-off me-1"></i>Disabled
                        </span>
                    @endif
                </div>
                <div class="flex-grow-1">
                    @if(!$enabled)
                        <p class="text-muted mb-0">Shadow mode is <strong>disabled</strong>. Set <code>SHADOW_MODE_ENABLED=true</code> in <code>.env</code> and <code>SHADOW_MODE_MODE=passive</code> to enable.</p>
                    @elseif($mode === 'off')
                        <p class="text-muted mb-0">Shadow mode is <strong>enabled but OFF</strong>. No comparisons are running. Switch to <strong>passive</strong> or <strong>active</strong> mode to begin.</p>
                    @elseif($mode === 'passive')
                        <p class="text-warning mb-0"><i class="fas fa-info-circle me-1"></i><strong>Passive mode</strong>: Laravel is primary. After each transfer operation, the legacy result is read and compared. Diffs logged but operations not blocked.</p>
                    @elseif($mode === 'active')
                        <p class="text-success mb-0"><i class="fas fa-check-circle me-1"></i><strong>Active mode</strong>: Both systems process every operation. Legacy is the gold reference. Diffs trigger alerts.</p>
                    @endif
                </div>
                <div class="flex-shrink-0">
                    <form method="POST" action="{{ route('admin.shadow-mode.toggle-mode') }}">
                        @csrf
                        <div class="btn-group btn-group-sm">
                            <button type="submit" name="mode" value="off" class="btn btn-outline-secondary">Off</button>
                            <button type="submit" name="mode" value="passive" class="btn btn-outline-warning">Passive</button>
                            <button type="submit" name="mode" value="active" class="btn btn-outline-success">Active</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- 7-Day Summary Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card text-center h-100">
                <div class="card-body">
                    <h5 class="card-title text-muted">Total Comparisons</h5>
                    <p class="card-text fs-4 fw-bold">{{ $summary['total'] }}</p>
                    <p class="small text-muted">{{ $summary['from_date'] }} → {{ $summary['to_date'] }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center h-100 border-success">
                <div class="card-body">
                    <h5 class="card-title text-success"><i class="fas fa-check me-1"></i>Match</h5>
                    <p class="card-text fs-4 fw-bold text-success">{{ $summary['match'] }}</p>
                    @if($summary['total'] > 0)
                        <p class="small text-muted">{{ round(($summary['match'] / $summary['total']) * 100, 1) }}% match rate</p>
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center h-100 border-danger">
                <div class="card-body">
                    <h5 class="card-title text-danger"><i class="fas fa-times me-1"></i>Diffs</h5>
                    <p class="card-text fs-4 fw-bold text-danger">{{ $summary['diff'] }}</p>
                    <p class="small text-muted">Requires investigation</p>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center h-100 border-warning">
                <div class="card-body">
                    <h5 class="card-title text-warning"><i class="fas fa-question me-1"></i>Missing Legacy</h5>
                    <p class="card-text fs-4 fw-bold text-warning">{{ $summary['missing_legacy'] }}</p>
                    <p class="small text-muted">Legacy data not found</p>
                </div>
            </div>
        </div>
    </div>

    {{-- Cutover Progress --}}
    <div class="card mb-4">
        <div class="card-header bg-dark text-white">
            <i class="fas fa-flag-checkered me-2"></i>Cutover Progress
        </div>
        <div class="card-body">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="flex-grow-1">
                    <div class="progress" style="height: 30px;">
                        @php
                            $progress = min(100, ($cutover['consecutive_clean_days'] / $cutover['threshold']) * 100);
                        @endphp
                        <div class="progress-bar {{ $cutover['cutover_ready'] ? 'bg-success' : 'bg-info' }}"
                             style="width: {{ $progress }}%"
                             role="progressbar"
                             aria-valuenow="{{ $cutover['consecutive_clean_days'] }}"
                             aria-valuemin="0"
                             aria-valuemax="{{ $cutover['threshold'] }}">
                            {{ $cutover['consecutive_clean_days'] }} / {{ $cutover['threshold'] }} days
                        </div>
                    </div>
                </div>
                <div class="flex-shrink-0">
                    @if($cutover['cutover_ready'])
                        <span class="badge bg-success fs-6"><i class="fas fa-check me-1"></i>CUTOVER READY</span>
                    @else
                        <span class="badge bg-info fs-6">{{ $cutover['remaining_days'] }} days remaining</span>
                    @endif
                </div>
            </div>
            <p class="text-muted small mb-0">
                <strong>Threshold:</strong> {{ $cutover['threshold'] }} consecutive zero-diff days required for cutover.
                <strong>Current:</strong> {{ $cutover['consecutive_clean_days'] }} consecutive clean days.
                @if(!$cutover['cutover_ready'])
                    Need {{ $cutover['remaining_days'] }} more clean days before cutover can proceed.
                @endif
            </p>
        </div>
    </div>

    {{-- Action buttons --}}
    <div class="d-flex gap-2 mb-4">
        <form method="POST" action="{{ route('admin.shadow-mode.run-comparison') }}">
            @csrf
            <input type="hidden" name="from_date" value="{{ now()->subDay()->format('Y-m-d') }}">
            <input type="hidden" name="to_date" value="{{ now()->format('Y-m-d') }}">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-play me-1"></i>Run Comparison (Yesterday → Today)
            </button>
        </form>
        <a href="{{ route('admin.shadow-mode.comparisons') }}" class="btn btn-outline-primary">
            <i class="fas fa-list me-1"></i>View All Comparisons
        </a>
        <a href="{{ route('admin.shadow-mode.cutover') }}" class="btn btn-outline-success">
            <i class="fas fa-flag-checkered me-1"></i>Cutover Report
        </a>
        <form method="POST" action="{{ route('admin.shadow-mode.purge') }}">
            @csrf
            <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="fas fa-trash me-1"></i>Purge Old Records
            </button>
        </form>
    </div>

    {{-- Recent diffs --}}
    @if($recentDiffs->count() > 0)
    <div class="card">
        <div class="card-header bg-danger text-white">
            <i class="fas fa-exclamation-triangle me-2"></i>Recent Diffs (Last 10)
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>Transfer</th>
                            <th>Operation</th>
                            <th>Status</th>
                            <th>Checks</th>
                            <th>Match</th>
                            <th>Diff</th>
                            <th>Compared At</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentDiffs as $diff)
                        <tr class="table-danger">
                            <td>{{ $diff->id }}</td>
                            <td>
                                <a href="{{ route('admin.warehouse-transfers.show', $diff->laravel_transfer_id) }}">
                                    {{ $diff->laravel_transfer_code }}
                                </a>
                            </td>
                            <td><span class="badge bg-info">{{ $diff->operation }}</span></td>
                            <td>
                                @if($diff->diff_status === 'diff')
                                    <span class="badge bg-danger">Diff</span>
                                @elseif($diff->diff_status === 'missing_legacy')
                                    <span class="badge bg-warning text-dark">Missing Legacy</span>
                                @else
                                    <span class="badge bg-secondary">{{ $diff->diff_status }}</span>
                                @endif
                            </td>
                            <td>{{ $diff->total_checks }}</td>
                            <td class="text-success">{{ $diff->match_count }}</td>
                            <td class="text-danger fw-bold">{{ $diff->diff_count }}</td>
                            <td>{{ $diff->compared_at }}</td>
                            <td>
                                <a href="{{ route('admin.shadow-mode.detail', $diff->id) }}" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-eye me-1"></i>Detail
                                </a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @elseif($enabled && $mode !== 'off')
    <div class="card">
        <div class="card-body text-center text-success">
            <i class="fas fa-check-circle fa-3x mb-2"></i>
            <h5>No diffs detected!</h5>
            <p class="text-muted">All recent comparisons show zero diffs. Cutover progress is tracking correctly.</p>
        </div>
    </div>
    @endif

    {{-- Operations breakdown --}}
    @if($summary['total'] > 0)
    <div class="card mt-4">
        <div class="card-header">
            <i class="fas fa-chart-pie me-2"></i>Operations Breakdown
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col-md-4">
                    <h6>Create</h6>
                    <p class="fs-5 fw-bold">{{ $summary['by_operation']['create'] ?? 0 }}</p>
                </div>
                <div class="col-md-4">
                    <h6>Confirm</h6>
                    <p class="fs-5 fw-bold">{{ $summary['by_operation']['confirm'] ?? 0 }}</p>
                </div>
                <div class="col-md-4">
                    <h6>Cancel</h6>
                    <p class="fs-5 fw-bold">{{ $summary['by_operation']['cancel'] ?? 0 }}</p>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Configuration reference --}}
    <div class="card mt-4">
        <div class="card-header">
            <i class="fas fa-cog me-2"></i>Configuration Reference
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>Environment Variables</h6>
                    <table class="table table-sm">
                        <tbody>
                            <tr><td><code>SHADOW_MODE_ENABLED</code></td><td>{{ config('shadow_mode.enabled') ? 'true' : 'false' }}</td></tr>
                            <tr><td><code>SHADOW_MODE_MODE</code></td><td>{{ config('shadow_mode.mode') }}</td></tr>
                            <tr><td><code>SHADOW_CUTOVER_DAYS</code></td><td>{{ config('shadow_mode.cutover.consecutive_days_zero_diff') }}</td></tr>
                            <tr><td><code>SHADOW_MODE_LEGACY_CONNECTION</code></td><td>{{ config('shadow_mode.legacy_connection') }}</td></tr>
                            <tr><td><code>SHADOW_ALERT_LOG_CHANNEL</code></td><td>{{ config('shadow_mode.alerts.log_channel') }}</td></tr>
                            <tr><td><code>SHADOW_ALERT_EMAIL</code></td><td>{{ config('shadow_mode.alerts.notify_email') ?: 'Not set' }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <h6>Tolerance Thresholds</h6>
                    <table class="table table-sm">
                        <tbody>
                            <tr><td>Qty tolerance</td><td>{{ config('shadow_mode.cutover.max_tolerance_qty') }}</td></tr>
                            <tr><td>Rate tolerance</td><td>{{ config('shadow_mode.cutover.max_tolerance_rate') }}</td></tr>
                            <tr><td>Amount tolerance</td><td>{{ config('shadow_mode.cutover.max_tolerance_amount') }}</td></tr>
                        </tbody>
                    </table>
                    <h6 class="mt-3">Comparison Scope</h6>
                    <ul class="list-unstyled small">
                        @foreach(config('shadow_mode.comparison_scope') as $scope => $enabled)
                        <li>
                            @if($enabled)
                                <i class="fas fa-check text-success me-1"></i>
                            @else
                                <i class="fas fa-times text-muted me-1"></i>
                            @endif
                            {{ $scope }}
                        </li>
                        @endforeach
                    </ul>
                </div>
            </div>
            <p class="text-muted small mb-0">
                Config file: <code>config/shadow_mode.php</code> &middot;
                Artisan command: <code>php artisan shadow:compare-transfers</code>
            </p>
        </div>
    </div>
</div>
@endsection

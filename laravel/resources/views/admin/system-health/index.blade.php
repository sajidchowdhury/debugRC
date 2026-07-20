@extends('layouts.admin')

@section('content')
@php
    /**
     * @var array $database
     * @var array $redis
     * @var array $application
     * @var array $modules
     * @var \Illuminate\Support\Collection $recentAudit
     * @var \Illuminate\Support\Collection $recentLogins
     * @var array $testSuite
     * @var array $queue
     * @var array $cache
     */

    $statusCls = fn(string $status): string => match ($status) {
        'healthy'   => 'bg-success-subtle text-success',
        'degraded'  => 'bg-warning-subtle text-warning',
        'critical'  => 'bg-danger-subtle text-danger',
        'missing'   => 'bg-secondary-subtle text-secondary',
        default     => 'bg-light text-muted',
    };
    $statusIcon = fn(string $status): string => match ($status) {
        'healthy'   => 'fa-circle-check text-success',
        'degraded'  => 'fa-triangle-exclamation text-warning',
        'critical'  => 'fa-circle-xmark text-danger',
        'missing'   => 'fa-circle-question text-secondary',
        default     => 'fa-circle text-muted',
    };
@endphp

{{-- Optional auto-refresh every 60 seconds --}}
<meta http-equiv="refresh" content="60">

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0d9488,#14b8a6);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-heart-pulse me-2"></i>System health dashboard</h1>
            <p class="mb-0 small opacity-75">Live snapshot of database, cache, queue, application, and module health. Auto-refreshes every 60s.</p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-light text-dark"><i class="fas fa-clock me-1"></i> Generated {{ $generatedAt->format('d M Y, H:i:s') }}</span>
            <a href="{{ route('admin.system-health.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-rotate me-1"></i> Refresh
            </a>
        </div>
    </header>

    {{-- Top-level status pills --}}
    <div class="row g-3 mb-3">
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <i class="fas {{ $statusIcon($database['status'] ?? 'critical') }} fa-2x me-3"></i>
                    <div>
                        <div class="text-muted small">Database</div>
                        <div class="fw-bold text-uppercase {{ 'text-' . ($database['status'] === 'healthy' ? 'success' : ($database['status'] === 'critical' ? 'danger' : 'warning')) }}">
                            {{ $database['status'] ?? 'unknown' }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <i class="fas {{ $statusIcon($redis['status'] ?? 'critical') }} fa-2x me-3"></i>
                    <div>
                        <div class="text-muted small">Redis</div>
                        <div class="fw-bold text-uppercase {{ 'text-' . ($redis['status'] === 'healthy' ? 'success' : ($redis['status'] === 'critical' ? 'danger' : 'warning')) }}">
                            {{ $redis['status'] ?? 'unknown' }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <i class="fas {{ $statusIcon($application['status'] ?? 'critical') }} fa-2x me-3"></i>
                    <div>
                        <div class="text-muted small">Application</div>
                        <div class="fw-bold text-uppercase {{ 'text-' . ($application['status'] === 'healthy' ? 'success' : ($application['status'] === 'critical' ? 'danger' : 'warning')) }}">
                            {{ $application['status'] ?? 'unknown' }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <i class="fas {{ $statusIcon($queue['status'] ?? 'critical') }} fa-2x me-3"></i>
                    <div>
                        <div class="text-muted small">Queue</div>
                        <div class="fw-bold text-uppercase {{ 'text-' . ($queue['status'] === 'healthy' ? 'success' : ($queue['status'] === 'critical' ? 'danger' : 'warning')) }}">
                            {{ $queue['status'] ?? 'unknown' }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Main grid --}}
    <div class="row g-3">
        {{-- Database health --}}
        <div class="col-lg-6 col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <h5 class="mb-0"><i class="fas fa-database me-2 text-primary"></i>Database</h5>
                    <span class="badge {{ $statusCls($database['status'] ?? 'critical') }}">{{ ucfirst($database['status'] ?? 'unknown') }}</span>
                </div>
                <div class="card-body small">
                    @if (!empty($database['error']))
                        <div class="alert alert-danger py-2 mb-2 small mb-3">{{ $database['error'] }}</div>
                    @endif
                    <dl class="row mb-0">
                        <dt class="col-5 text-muted">Connection</dt>
                        <dd class="col-7">{{ ($database['connected'] ?? false) ? 'Connected' : 'Disconnected' }}</dd>

                        <dt class="col-5 text-muted">Tables</dt>
                        <dd class="col-7">{{ number_format($database['table_count'] ?? 0) }}</dd>

                        <dt class="col-5 text-muted">Total rows (est.)</dt>
                        <dd class="col-7">{{ number_format($database['total_rows'] ?? 0) }}</dd>

                        <dt class="col-5 text-muted">DB size</dt>
                        <dd class="col-7">{{ $database['db_size'] ?? 'unknown' }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        {{-- Redis health --}}
        <div class="col-lg-6 col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <h5 class="mb-0"><i class="fas fa-bolt me-2 text-danger"></i>Redis</h5>
                    <span class="badge {{ $statusCls($redis['status'] ?? 'critical') }}">{{ ucfirst($redis['status'] ?? 'unknown') }}</span>
                </div>
                <div class="card-body small">
                    @if (!empty($redis['error']))
                        <div class="alert alert-danger py-2 mb-2 small mb-3">{{ $redis['error'] }}</div>
                    @endif
                    <dl class="row mb-0">
                        <dt class="col-5 text-muted">Connection</dt>
                        <dd class="col-7">{{ ($redis['connected'] ?? false) ? 'Connected' : 'Disconnected' }}</dd>

                        <dt class="col-5 text-muted">Version</dt>
                        <dd class="col-7">{{ $redis['version'] ?? '—' }}</dd>

                        <dt class="col-5 text-muted">Memory</dt>
                        <dd class="col-7">{{ $redis['memory'] ?? '—' }}</dd>

                        <dt class="col-5 text-muted">Clients</dt>
                        <dd class="col-7">{{ number_format($redis['clients'] ?? 0) }}</dd>

                        <dt class="col-5 text-muted">Keyspace hits</dt>
                        <dd class="col-7">{{ number_format($redis['hits'] ?? 0) }}</dd>

                        <dt class="col-5 text-muted">Keyspace misses</dt>
                        <dd class="col-7">{{ number_format($redis['misses'] ?? 0) }}</dd>

                        <dt class="col-5 text-muted">Hit ratio</dt>
                        <dd class="col-7">
                            @if (isset($redis['hit_ratio']))
                                <span class="badge {{ $redis['hit_ratio'] >= 90 ? 'bg-success-subtle text-success' : ($redis['hit_ratio'] >= 75 ? 'bg-warning-subtle text-warning' : 'bg-danger-subtle text-danger') }}">
                                    {{ $redis['hit_ratio'] }}%
                                </span>
                            @else
                                —
                            @endif
                        </dd>
                    </dl>
                </div>
            </div>
        </div>

        {{-- Application health --}}
        <div class="col-lg-6 col-xl-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <h5 class="mb-0"><i class="fas fa-server me-2 text-info"></i>Application</h5>
                    <span class="badge {{ $statusCls($application['status'] ?? 'critical') }}">{{ ucfirst($application['status'] ?? 'unknown') }}</span>
                </div>
                <div class="card-body small">
                    <dl class="row mb-0">
                        <dt class="col-5 text-muted">Laravel</dt>
                        <dd class="col-7">{{ $application['laravel'] ?? '—' }}</dd>

                        <dt class="col-5 text-muted">PHP</dt>
                        <dd class="col-7">{{ $application['php'] ?? '—' }}</dd>

                        <dt class="col-5 text-muted">Memory usage</dt>
                        <dd class="col-7">{{ $application['memory_usage'] ?? '—' }}</dd>

                        <dt class="col-5 text-muted">Memory peak</dt>
                        <dd class="col-7">{{ $application['memory_peak'] ?? '—' }}</dd>

                        <dt class="col-5 text-muted">Disk free</dt>
                        <dd class="col-7">{{ $application['disk_free'] ?? '—' }} / {{ $application['disk_total'] ?? '—' }}</dd>

                        <dt class="col-5 text-muted">Disk usage</dt>
                        <dd class="col-7">
                            <span class="badge {{ ($application['disk_usage_pct'] ?? 0) >= 90 ? 'bg-danger-subtle text-danger' : (($application['disk_usage_pct'] ?? 0) >= 75 ? 'bg-warning-subtle text-warning' : 'bg-success-subtle text-success') }}">
                                {{ $application['disk_usage_pct'] ?? 0 }}%
                            </span>
                        </dd>

                        <dt class="col-5 text-muted">App uptime</dt>
                        <dd class="col-7">{{ $application['uptime'] ?? '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        {{-- Module health --}}
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-cubes me-2 text-primary"></i>Master-data module health</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Module</th>
                                    <th>Table</th>
                                    <th class="text-end">Active</th>
                                    <th class="text-end">Inactive</th>
                                    <th class="text-end">Total</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($modules as $module)
                                    <tr>
                                        <td>
                                            <a href="{{ route($module['route'] . '.index') }}" class="text-decoration-none fw-semibold">
                                                {{ $module['label'] }}
                                            </a>
                                        </td>
                                        <td><code>{{ $module['table'] }}</code></td>
                                        <td class="text-end text-success fw-semibold">{{ number_format($module['active']) }}</td>
                                        <td class="text-end text-danger">{{ number_format($module['inactive']) }}</td>
                                        <td class="text-end fw-bold">{{ number_format($module['total']) }}</td>
                                        <td>
                                            <span class="badge {{ $statusCls($module['status']) }}">
                                                <i class="fas {{ $statusIcon($module['status']) }} me-1"></i>{{ ucfirst($module['status']) }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Recent audit activity --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <h5 class="mb-0"><i class="fas fa-clock-rotate-left me-2 text-primary"></i>Recent master-data activity</h5>
                    <a href="{{ route('admin.audit.index') }}" class="btn btn-sm btn-outline-primary">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr><th>When</th><th>User</th><th>Action</th><th>Table</th><th>Record</th></tr>
                            </thead>
                            <tbody>
                                @forelse ($recentAudit as $row)
                                    <tr>
                                        <td class="text-nowrap small">{{ \Carbon\Carbon::parse($row->created_at)->format('d M H:i') }}</td>
                                        <td class="small">{{ $row->performed_by_name ?? ('#' . ($row->user_id ?? 0)) }}</td>
                                        <td class="small">{{ ucfirst(str_replace('master_data_', '', $row->action)) }}</td>
                                        <td class="small"><code>{{ $row->target_table ?? '—' }}</code></td>
                                        <td class="small">{{ $row->target_id ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center text-muted py-4">No recent activity.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Recent login activity --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-right-to-bracket me-2 text-info"></i>Recent login activity</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr><th>When</th><th>User</th><th>Action</th><th>IP</th></tr>
                            </thead>
                            <tbody>
                                @forelse ($recentLogins as $row)
                                    @php
                                        $loginCls = match($row->action) {
                                            'login_success' => 'bg-success-subtle text-success',
                                            'login_failed'  => 'bg-danger-subtle text-danger',
                                            'logout'        => 'bg-secondary-subtle text-secondary',
                                            default         => 'bg-light text-muted',
                                        };
                                    @endphp
                                    <tr>
                                        <td class="text-nowrap small">{{ \Carbon\Carbon::parse($row->created_at)->format('d M H:i') }}</td>
                                        <td class="small">{{ $row->performed_by_name ?? ('#' . ($row->user_id ?? 0)) }}</td>
                                        <td><span class="badge {{ $loginCls }}">{{ str_replace('_', ' ', $row->action) }}</span></td>
                                        <td class="small text-muted">{{ $row->ip_address ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-center text-muted py-4">No recent logins.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Test suite summary --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-vial me-2 text-success"></i>Test suite summary</h5>
                </div>
                <div class="card-body small">
                    <dl class="row mb-0">
                        <dt class="col-5 text-muted">Total tests</dt>
                        <dd class="col-7 fw-bold">{{ number_format($testSuite['total'] ?? 0) }}</dd>

                        <dt class="col-5 text-muted">Assertions</dt>
                        <dd class="col-7">{{ $testSuite['assertions'] > 0 ? number_format($testSuite['assertions']) : '—' }}</dd>

                        <dt class="col-5 text-muted">Last run</dt>
                        <dd class="col-7">{{ $testSuite['last_run'] ?? '—' }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        {{-- Queue + Cache --}}
        <div class="col-lg-6">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white d-flex align-items-center justify-content-between">
                            <h6 class="mb-0"><i class="fas fa-list-check me-2 text-warning"></i>Queue</h6>
                            <span class="badge {{ $statusCls($queue['status'] ?? 'critical') }}">{{ ucfirst($queue['status'] ?? 'unknown') }}</span>
                        </div>
                        <div class="card-body small">
                            @if (!($queue['table_exists'] ?? false))
                                <p class="text-muted mb-2 small">Queue table not present — using <code>redis</code>/<code>sync</code> driver.</p>
                            @endif
                            <dl class="row mb-0">
                                <dt class="col-6 text-muted">Failed jobs</dt>
                                <dd class="col-6">{{ number_format($queue['failed_jobs'] ?? 0) }}</dd>

                                <dt class="col-6 text-muted">Pending jobs</dt>
                                <dd class="col-6">{{ number_format($queue['pending_jobs'] ?? 0) }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white d-flex align-items-center justify-content-between">
                            <h6 class="mb-0"><i class="fas fa-database me-2 text-secondary"></i>Cache</h6>
                            <span class="badge {{ $statusCls($cache['status'] ?? 'critical') }}">{{ ucfirst($cache['status'] ?? 'unknown') }}</span>
                        </div>
                        <div class="card-body small">
                            <dl class="row mb-0">
                                <dt class="col-5 text-muted">Driver</dt>
                                <dd class="col-7"><code>{{ $cache['driver'] ?? 'unknown' }}</code></dd>

                                <dt class="col-5 text-muted">Size</dt>
                                <dd class="col-7">{{ $cache['size_hint'] ?? '—' }}</dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

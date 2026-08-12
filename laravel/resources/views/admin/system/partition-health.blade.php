@extends('layouts.admin')

@section('content')
@php
    /**
     * @var \Illuminate\Support\Collection $alerts
     * @var array           $alertCounts
     * @var int             $totalAlerts
     * @var int             $criticalCount
     * @var int             $warningCount
     * @var \Illuminate\Support\Collection $partmanConfigs
     * @var \Illuminate\Support\Collection $partitionCounts
     * @var int             $partitionedTables
     * @var int             $totalPartitions
     * @var \Illuminate\Support\Collection $largestPartitions
     * @var \Illuminate\Support\Collection $staleVacuumStats
     * @var \Illuminate\Support\Collection $defaultPartitionIssues
     * @var \Illuminate\Support\Collection $missingFuturePartitions
     * @var \Illuminate\Support\Collection $unusedBrinIndexes
     * @var string          $status
     * @var \Illuminate\Support\Carbon $generatedAt
     */

    $statusCls = fn(string $s): string => match ($s) {
        'healthy'   => 'bg-success-subtle text-success',
        'degraded'  => 'bg-warning-subtle text-warning',
        'critical'  => 'bg-danger-subtle text-danger',
        default     => 'bg-secondary-subtle text-secondary',
    };
    $statusIcon = fn(string $s): string => match ($s) {
        'healthy'   => 'fa-circle-check text-success',
        'degraded'  => 'fa-triangle-exclamation text-warning',
        'critical'  => 'fa-circle-xmark text-danger',
        default     => 'fa-circle-question text-secondary',
    };
    $severityCls = fn(string $sev): string => match (strtoupper($sev)) {
        'CRITICAL' => 'bg-danger-subtle text-danger',
        'WARNING'  => 'bg-warning-subtle text-warning',
        'INFO'     => 'bg-secondary-subtle text-secondary',
        default    => 'bg-light text-muted',
    };
    $severityIcon = fn(string $sev): string => match (strtoupper($sev)) {
        'CRITICAL' => 'fa-circle-xmark',
        'WARNING'  => 'fa-triangle-exclamation',
        'INFO'     => 'fa-circle-info',
        default    => 'fa-circle',
    };

    $showResolved = (bool) (request()->query('show_resolved', false));
@endphp

{{-- Auto-refresh every 60 seconds --}}
<meta http-equiv="refresh" content="60">

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0d9488,#14b8a6);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-table-cells-large me-2"></i>Partition health dashboard</h1>
            <p class="mb-0 small opacity-75">
                Operational health of the {{ $partitionedTables }} partitioned parents
                ({{ number_format($totalPartitions) }} child partitions). Auto-refreshes every 60s.
            </p>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-light text-dark">
                <i class="fas fa-clock me-1"></i> Generated {{ $generatedAt->format('d M Y, H:i:s') }}
            </span>
            <a href="{{ route('admin.system.partition-health') }}" class="btn btn-light btn-sm">
                <i class="fas fa-rotate me-1"></i> Refresh
            </a>
        </div>
    </header>

    {{-- Top-level status pills --}}
    <div class="row g-3 mb-3">
        <div class="col-md-2 col-sm-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <i class="fas {{ $statusIcon($status) }} fa-2x me-3"></i>
                    <div>
                        <div class="text-muted small">Overall</div>
                        <div class="fw-bold text-uppercase {{ 'text-' . ($status === 'healthy' ? 'success' : ($status === 'critical' ? 'danger' : 'warning')) }}">
                            {{ $status }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <i class="fas fa-bell fa-2x me-3 text-secondary"></i>
                    <div>
                        <div class="text-muted small">Total alerts</div>
                        <div class="fw-bold fs-5">{{ number_format($totalAlerts) }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <i class="fas fa-circle-xmark fa-2x me-3 text-danger"></i>
                    <div>
                        <div class="text-muted small">Critical</div>
                        <div class="fw-bold fs-5 text-danger">{{ number_format($criticalCount) }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <i class="fas fa-triangle-exclamation fa-2x me-3 text-warning"></i>
                    <div>
                        <div class="text-muted small">Warning</div>
                        <div class="fw-bold fs-5 text-warning">{{ number_format($warningCount) }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <i class="fas fa-table fa-2x me-3 text-primary"></i>
                    <div>
                        <div class="text-muted small">Partitioned tables</div>
                        <div class="fw-bold fs-5">{{ number_format($partitionedTables) }}</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-2 col-sm-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <i class="fas fa-layer-group fa-2x me-3 text-info"></i>
                    <div>
                        <div class="text-muted small">Total partitions</div>
                        <div class="fw-bold fs-5">{{ number_format($totalPartitions) }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Main grid --}}
    <div class="row g-3">

        {{-- Alerts table --}}
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <h5 class="mb-0">
                        <i class="fas fa-bell me-2 text-warning"></i>Health-check alerts
                        <span class="badge bg-secondary-subtle text-secondary ms-1">{{ number_format($totalAlerts) }} unresolved</span>
                    </h5>
                    <div class="btn-group btn-group-sm" role="group">
                        <a href="{{ route('admin.system.partition-health') }}"
                           class="btn {{ $showResolved ? 'btn-outline-secondary' : 'btn-secondary' }}">Unresolved only</a>
                        <a href="{{ route('admin.system.partition-health', ['show_resolved' => 1]) }}"
                           class="btn {{ $showResolved ? 'btn-secondary' : 'btn-outline-secondary' }}">Show resolved</a>
                    </div>
                </div>
                <div class="card-body p-0">
                    @if ($alerts->isEmpty())
                        <div class="text-center text-muted py-5">
                            <i class="fas fa-circle-check fa-3x mb-2 text-success opacity-50"></i>
                            <p class="mb-0">No unresolved alerts. The partition layer is healthy.</p>
                            @if (!\Illuminate\Support\Facades\Schema::hasTable('partition_health_alerts'))
                                <p class="mt-2 small text-muted">
                                    <i class="fas fa-circle-info me-1"></i>
                                    <code>partition_health_alerts</code> table not found — Phase 8.3 migration has not run yet.
                                </p>
                            @endif
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Severity</th>
                                        <th>Check</th>
                                        <th>Table</th>
                                        <th>Details</th>
                                        <th>Created</th>
                                        <th>Resolved</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($alerts as $a)
                                        <tr>
                                            <td>
                                                <span class="badge {{ $severityCls($a->severity ?? '') }}">
                                                    <i class="fas {{ $severityIcon($a->severity ?? '') }} me-1"></i>{{ strtoupper($a->severity ?? 'INFO') }}
                                                </span>
                                            </td>
                                            <td class="small"><code>{{ $a->check_name ?? '—' }}</code></td>
                                            <td class="small"><code>{{ $a->table_name ?? '—' }}</code></td>
                                            <td class="small">{{ $a->details ?? '—' }}</td>
                                            <td class="text-nowrap small">
                                                @if (!empty($a->created_at))
                                                    {{ \Carbon\Carbon::parse($a->created_at)->format('d M Y, H:i') }}
                                                @else — @endif
                                            </td>
                                            <td class="text-nowrap small">
                                                @if (!empty($a->resolved_at))
                                                    <span class="badge bg-success-subtle text-success">
                                                        <i class="fas fa-check me-1"></i>
                                                        {{ \Carbon\Carbon::parse($a->resolved_at)->format('d M H:i') }}
                                                    </span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Default partition issues (CRITICAL) --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100 @if($defaultPartitionIssues->isNotEmpty()) border-start border-danger border-4 @endif">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <h5 class="mb-0"><i class="fas fa-shield-halved me-2 text-danger"></i>Default partition check</h5>
                    <span class="badge {{ $defaultPartitionIssues->isEmpty() ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }}">
                        {{ $defaultPartitionIssues->count() }} issue(s)
                    </span>
                </div>
                <div class="card-body p-0">
                    @if ($defaultPartitionIssues->isEmpty())
                        <div class="text-center text-muted py-4 small">
                            <i class="fas fa-circle-check me-1 text-success"></i>
                            No rows in any default partition.
                            @if (!$largestPartitions->isEmpty() && $defaultPartitionIssues->isEmpty())
                                {{-- view exists and returned nothing --}}
                            @else
                                <br><span class="text-muted"><code>v_default_partition_check</code> view not available.</span>
                            @endif
                        </div>
                    @else
                        <div class="alert alert-danger rounded-0 mb-0 small py-2">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Data is landing in <code>_default</code> partitions — partition key ranges are missing or misaligned.
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Parent</th>
                                        <th>Default partition</th>
                                        <th class="text-end">Rows</th>
                                        <th class="text-end">Size</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($defaultPartitionIssues as $r)
                                        <tr>
                                            <td class="small"><code>{{ $r->parent ?? '—' }}</code></td>
                                            <td class="small"><code>{{ $r->default_partition ?? '—' }}</code></td>
                                            <td class="text-end small fw-bold text-danger">{{ number_format($r->row_count ?? 0) }}</td>
                                            <td class="text-end small">{{ ($r->size_bytes ?? 0) ? \Illuminate\Support\Number::fileSize($r->size_bytes) : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Missing future partitions (CRITICAL) --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100 @if($missingFuturePartitions->isNotEmpty()) border-start border-danger border-4 @endif">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <h5 class="mb-0"><i class="fas fa-calendar-xmark me-2 text-danger"></i>Missing future partitions</h5>
                    <span class="badge {{ $missingFuturePartitions->isEmpty() ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }}">
                        {{ $missingFuturePartitions->count() }} table(s)
                    </span>
                </div>
                <div class="card-body p-0">
                    @if ($missingFuturePartitions->isEmpty())
                        <div class="text-center text-muted py-4 small">
                            <i class="fas fa-circle-check me-1 text-success"></i>
                            All parents have ≥ 3 months of future partitions.
                        </div>
                    @else
                        <div class="alert alert-danger rounded-0 mb-0 small py-2">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            These parents have &lt; 3 months of premade partitions. Run <code>SELECT partman.run_maintenance_proc();</code> immediately.
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Parent</th>
                                        <th>Last partition</th>
                                        <th class="text-end">Months ahead</th>
                                        <th class="text-end">Missing</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($missingFuturePartitions as $r)
                                        <tr>
                                            <td class="small"><code>{{ $r->parent ?? '—' }}</code></td>
                                            <td class="small">{{ $r->last_partition_date ?? '—' }}</td>
                                            <td class="text-end small fw-bold {{ ($r->months_ahead ?? 0) < 1 ? 'text-danger' : 'text-warning' }}">
                                                {{ $r->months_ahead ?? '—' }}
                                            </td>
                                            <td class="text-end small">{{ $r->missing_count ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Per-table partition summary --}}
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="fas fa-layer-group me-2 text-primary"></i>Per-table partition summary</h5>
                </div>
                <div class="card-body p-0">
                    @if ($partitionCounts->isEmpty() && $partmanConfigs->isEmpty())
                        <div class="text-center text-muted py-4 small">
                            No partitioned tables found. Either Phase 1–7 migrations have not run, or
                            <code>pg_inherits</code> returned no rows.
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Parent table</th>
                                        <th class="text-end">Partition count</th>
                                        <th>Retention</th>
                                        <th class="text-end">Premake</th>
                                        <th>Last maintenance</th>
                                        <th>Auto maint.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php
                                        // Index partman configs by parent_table for the join.
                                        $pcByKey = collect([]);
                                        foreach ($partmanConfigs as $pc) {
                                            $key = $pc->parent_table ?? '';
                                            // Normalise: strip schema prefix to match the public-only
                                            // parent_table from pg_inherits.
                                            $key = preg_replace('/^public\./', '', $key);
                                            $pcByKey[$key] = $pc;
                                        }
                                    @endphp
                                    @foreach ($partitionCounts as $row)
                                        @php
                                            $parent = $row->parent_table ?? '';
                                            $pcfg   = $pcByKey[$parent] ?? null;
                                            $pcount = (int) ($row->partition_count ?? 0);
                                            $tooManyPartitions = $pcount > 80;
                                            $retentionMissing  = $pcfg && ($pcfg->retention === null || $pcfg->retention === '');
                                            $staleMaint        = $pcfg && !empty($pcfg->last_maintenance)
                                                && \Carbon\Carbon::parse($pcfg->last_maintenance)->diffInHours(now()) > 24;
                                        @endphp
                                        <tr class="{{ $tooManyPartitions || $retentionMissing || $staleMaint ? 'table-warning' : '' }}">
                                            <td><code>{{ $parent }}</code></td>
                                            <td class="text-end fw-bold {{ $tooManyPartitions ? 'text-danger' : '' }}">
                                                {{ number_format($pcount) }}
                                                @if ($tooManyPartitions)
                                                    <i class="fas fa-triangle-exclamation text-danger ms-1"
                                                       title="> 80 partitions — consider consolidation"></i>
                                                @endif
                                            </td>
                                            <td class="small">
                                                @if (!$pcfg)
                                                    <span class="text-muted">not in partman</span>
                                                @elseif ($retentionMissing)
                                                    <span class="badge bg-warning-subtle text-warning">
                                                        <i class="fas fa-triangle-exclamation me-1"></i>MISSING
                                                    </span>
                                                @else
                                                    <code>{{ $pcfg->retention }}</code>
                                                @endif
                                            </td>
                                            <td class="text-end small">{{ $pcfg->premake ?? '—' }}</td>
                                            <td class="text-nowrap small">
                                                @if (!$pcfg)
                                                    <span class="text-muted">—</span>
                                                @elseif (empty($pcfg->last_maintenance))
                                                    <span class="text-muted">never</span>
                                                @elseif ($staleMaint)
                                                    <span class="text-warning fw-semibold">
                                                        {{ \Carbon\Carbon::parse($pcfg->last_maintenance)->diffForHumans() }}
                                                    </span>
                                                @else
                                                    {{ \Carbon\Carbon::parse($pcfg->last_maintenance)->diffForHumans() }}
                                                @endif
                                            </td>
                                            <td class="small">
                                                @if ($pcfg && !empty($pcfg->automatic_maintenance))
                                                    <span class="badge bg-success-subtle text-success">{{ $pcfg->automatic_maintenance }}</span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Largest partitions --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <h5 class="mb-0"><i class="fas fa-database me-2 text-info"></i>Largest partitions (top 20)</h5>
                    <span class="badge bg-secondary-subtle text-secondary">{{ $largestPartitions->count() }}</span>
                </div>
                <div class="card-body p-0">
                    @if ($largestPartitions->isEmpty())
                        <div class="text-center text-muted py-4 small">
                            <code>v_partition_sizes</code> view not available — Phase 8.5 migration has not run yet.
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Parent</th>
                                        <th>Partition</th>
                                        <th class="text-end">Size</th>
                                        <th class="text-end">Seq scans</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($largestPartitions as $r)
                                        <tr>
                                            <td class="small"><code>{{ $r->parent ?? '—' }}</code></td>
                                            <td class="small"><code>{{ $r->child ?? '—' }}</code></td>
                                            <td class="text-end small fw-semibold">
                                                {{ $r->size_pretty ?? (($r->size_bytes ?? 0) ? \Illuminate\Support\Number::fileSize($r->size_bytes) : '—') }}
                                            </td>
                                            <td class="text-end small">
                                                @if (($r->seq_scans ?? 0) > 0)
                                                    <span class="text-warning fw-semibold">{{ number_format($r->seq_scans) }}</span>
                                                @else
                                                    <span class="text-muted">0</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Stale VACUUM stats --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100 @if($staleVacuumStats->isNotEmpty()) border-start border-warning border-4 @endif">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <h5 class="mb-0"><i class="fas fa-broom me-2 text-warning"></i>Stale VACUUM / high dead tuples</h5>
                    <span class="badge {{ $staleVacuumStats->isEmpty() ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">
                        {{ $staleVacuumStats->count() }} partition(s)
                    </span>
                </div>
                <div class="card-body p-0">
                    @if ($staleVacuumStats->isEmpty())
                        <div class="text-center text-muted py-4 small">
                            <i class="fas fa-circle-check me-1 text-success"></i>
                            No partitions with stale VACUUM (&gt; 7 days) or &gt; 100k dead tuples.
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Parent</th>
                                        <th>Partition</th>
                                        <th class="text-end">Dead tuples</th>
                                        <th class="text-end">Stale (days)</th>
                                        <th>Last vacuum</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($staleVacuumStats as $r)
                                        <tr>
                                            <td class="small"><code>{{ $r->parent ?? '—' }}</code></td>
                                            <td class="small"><code>{{ $r->child ?? '—' }}</code></td>
                                            <td class="text-end small fw-bold {{ ($r->n_dead_tup ?? 0) > 100000 ? 'text-danger' : '' }}">
                                                {{ number_format($r->n_dead_tup ?? 0) }}
                                            </td>
                                            <td class="text-end small {{ ($r->stale_days ?? 0) > 7 ? 'text-warning fw-semibold' : '' }}">
                                                {{ number_format((float) ($r->stale_days ?? 0), 1) }}
                                            </td>
                                            <td class="text-nowrap small">
                                                @if (!empty($r->last_vacuum))
                                                    {{ \Carbon\Carbon::parse($r->last_vacuum)->diffForHumans() }}
                                                @elseif (!empty($r->last_autovacuum))
                                                    <span class="text-muted">auto: {{ \Carbon\Carbon::parse($r->last_autovacuum)->diffForHumans() }}</span>
                                                @else
                                                    <span class="text-muted">never</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- BRIN unused indexes --}}
        <div class="col-12">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white d-flex align-items-center justify-content-between">
                    <h5 class="mb-0">
                        <i class="fas fa-magnifying-glass-chart me-2 text-secondary"></i>Unused BRIN indexes
                        <span class="small text-muted fw-normal ms-2">idx_scan = 0 — planner is not using these BRIN indexes</span>
                    </h5>
                    <span class="badge bg-secondary-subtle text-secondary">{{ $unusedBrinIndexes->count() }} index(es)</span>
                </div>
                <div class="card-body p-0">
                    @if ($unusedBrinIndexes->isEmpty())
                        <div class="text-center text-muted py-4 small">
                            <i class="fas fa-circle-check me-1 text-success"></i>
                            All BRIN indexes have been scanned at least once — or no BRIN indexes exist yet.
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Schema</th>
                                        <th>Table</th>
                                        <th>Index</th>
                                        <th class="text-end">idx_scan</th>
                                        <th class="text-end">idx_tup_read</th>
                                        <th class="text-end">idx_tup_fetch</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($unusedBrinIndexes as $r)
                                        <tr>
                                            <td class="small"><code>{{ $r->schemaname ?? '—' }}</code></td>
                                            <td class="small"><code>{{ $r->table_name ?? '—' }}</code></td>
                                            <td class="small"><code>{{ $r->index_name ?? '—' }}</code></td>
                                            <td class="text-end small">
                                                <span class="badge bg-secondary-subtle text-secondary">{{ number_format($r->idx_scan ?? 0) }}</span>
                                            </td>
                                            <td class="text-end small">{{ number_format($r->idx_tup_read ?? 0) }}</td>
                                            <td class="text-end small">{{ number_format($r->idx_tup_fetch ?? 0) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>

    </div>{{-- /.row --}}
</div>{{-- /.container-fluid --}}
@endsection

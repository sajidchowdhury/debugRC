@extends('layouts.admin')

@section('content')
@php
    $filters = array_merge([
        'from_date'  => '',
        'to_date'    => '',
        'actor_id'   => '',
        'action'     => '',
        'session_id' => '',
        'search'     => '',
    ], is_array($filters ?? null) ? $filters : []);

    $statusIcon = [
        'create'        => 'fa-plus',
        'setup'         => 'fa-list-ol',
        'save_count'    => 'fa-floppy-disk',
        'mark_complete' => 'fa-check',
        'submit'        => 'fa-paper-plane',
        'approve'       => 'fa-thumbs-up',
        'reject'        => 'fa-thumbs-down',
        'post'          => 'fa-circle-check',
        'reverse'       => 'fa-rotate-left',
        're_open'       => 'fa-lock-open',
        'delete'        => 'fa-trash',
        'cancel'        => 'fa-ban',
    ];
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#4f46e5);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-clock-rotate-left me-2"></i>{{ $title }}
            </h1>
            <p class="mb-0 small opacity-75">
                {{ $logs->total() }} event(s) total
                @if ($viewAllBranches)
                    · <i class="fas fa-globe me-1"></i>All branches
                @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            @if ($canViewAllBranches)
                <a href="?{{ http_build_query(array_merge($filters, ['all_branches' => $viewAllBranches ? 0 : 1])) }}"
                   class="btn btn-outline-light btn-sm">
                    <i class="fas fa-{{ $viewAllBranches ? 'building' : 'globe' }} me-1"></i>
                    {{ $viewAllBranches ? 'My branch only' : 'All branches' }}
                </a>
            @endif
            <a href="{{ route('admin.stock-take.checklist') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clipboard-check me-1"></i> Health check
            </a>
            <a href="{{ route('admin.stock-take.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to sessions
            </a>
        </div>
    </header>

    {{-- Filter card --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white">
            <h2 class="h6 mb-0"><i class="fas fa-filter me-1 text-primary"></i> Filters</h2>
        </div>
        <div class="card-body">
            <form method="GET" action="{{ route('admin.stock-take.audit') }}" class="row g-2 align-items-end">
                @if ($canViewAllBranches)
                    <input type="hidden" name="all_branches" value="{{ $viewAllBranches ? 1 : 0 }}">
                @endif
                <div class="col-6 col-md-2">
                    <label class="form-label small">From date</label>
                    <input type="date" name="from_date" value="{{ $filters['from_date'] }}" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small">To date</label>
                    <input type="date" name="to_date" value="{{ $filters['to_date'] }}" class="form-control form-control-sm">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small">Action</label>
                    <select name="action" class="form-select form-select-sm">
                        <option value="">All actions</option>
                        @foreach ($actionOptions as $value => $label)
                            <option value="{{ $value }}" @selected($filters['action'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small">Actor</label>
                    <select name="actor_id" class="form-select form-select-sm">
                        <option value="">All actors</option>
                        @foreach ($actors as $u)
                            <option value="{{ $u->id }}" @selected((string) $filters['actor_id'] === (string) $u->id)>
                                {{ $u->username }}@if($u->employee?->name) — {{ $u->employee->name }}@endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small">Session ID</label>
                    <input type="number" name="session_id" value="{{ $filters['session_id'] }}" class="form-control form-control-sm" placeholder="e.g. 42">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small">Search session code</label>
                    <input type="text" name="search" value="{{ $filters['search'] }}" class="form-control form-control-sm" placeholder="ST-...">
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end">
                    <a href="{{ route('admin.stock-take.audit') }}{{ $canViewAllBranches ? '?all_branches=' . ($viewAllBranches ? 1 : 0) : '' }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-eraser me-1"></i> Clear
                    </a>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-magnifying-glass me-1"></i> Apply
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Audit log table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if ($logs->isEmpty())
                <div class="text-muted small py-5 text-center">
                    <i class="fas fa-inbox d-block mb-2 fs-2 opacity-50"></i>
                    No audit events match your filters.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 160px;">When</th>
                                <th style="width: 170px;">Action</th>
                                <th style="width: 140px;">Actor</th>
                                <th>Session</th>
                                <th style="width: 150px;">Transition</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($logs as $log)
                                @php
                                    $color = \App\Models\StockTakeAuditLog::actionColor($log->action);
                                    $isCritical = \App\Models\StockTakeAuditLog::isCritical($log->action);
                                @endphp
                                <tr>
                                    <td class="small">
                                        <div class="fw-semibold">{{ optional($log->created_at)->format('Y-m-d H:i') }}</div>
                                        <div class="text-muted">{{ optional($log->created_at)->format('M j, Y') }}</div>
                                    </td>
                                    <td>
                                        <span class="badge bg-{{ $color }}-subtle text-{{ $color }}">
                                            @if ($isCritical)<i class="fas fa-star me-1"></i>@endif
                                            <i class="fas {{ $statusIcon[$log->action] ?? 'fa-circle' }} me-1"></i>
                                            {{ \App\Models\StockTakeAuditLog::actionLabel($log->action) }}
                                        </span>
                                    </td>
                                    <td class="small">
                                        @if ($log->actor)
                                            <div class="fw-semibold">{{ $log->actor->username }}</div>
                                            @if ($log->actor->employee?->name)
                                                <div class="text-muted">{{ $log->actor->employee->name }}</div>
                                            @endif
                                        @else
                                            <span class="text-muted">System</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        @if ($log->session)
                                            <a href="{{ route('admin.stock-take.show', $log->session->id) }}" class="fw-semibold">
                                                {{ $log->session->session_code }}
                                            </a>
                                            <div class="text-muted">{{ optional($log->session->session_date)->format('Y-m-d') }}</div>
                                        @else
                                            <span class="text-muted">Session #{{ $log->stock_take_session_id }}</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        @if ($log->from_status || $log->to_status)
                                            <span class="badge bg-secondary-subtle text-secondary">{{ $log->from_status ?? '—' }}</span>
                                            <i class="fas fa-arrow-right mx-1 text-muted small"></i>
                                            <span class="badge bg-secondary-subtle text-secondary">{{ $log->to_status ?? '—' }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        @if ($log->warehouse)
                                            <span class="badge bg-light text-dark me-1"><i class="fas fa-warehouse me-1"></i>{{ $log->warehouse->warehouse_name }}</span>
                                        @endif
                                        @if (is_array($log->payload) && !empty($log->payload))
                                            @foreach (array_slice($log->payload, 0, 4, true) as $k => $v)
                                                @if (is_array($v))
                                                    <span class="text-muted">{{ $k }}: {{ count($v) }} item(s)</span>
                                                @else
                                                    <span class="text-muted">{{ $k }}: <strong>{{ is_bool($v) ? ($v ? 'true' : 'false') : $v }}</strong></span>
                                                @endif
                                                @if (!$loop->last)<span class="text-muted mx-1">·</span>@endif
                                            @endforeach
                                            @if (count($log->payload) > 4)
                                                <span class="text-muted">· +{{ count($log->payload) - 4 }} more</span>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="card-footer bg-white">
                    {{ $logs->links() }}
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

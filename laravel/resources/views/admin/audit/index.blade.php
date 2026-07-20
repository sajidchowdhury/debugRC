@extends('layouts.admin')

@section('content')
@php
    $f = $filters ?? [
        'table' => '', 'action' => '', 'user_id' => null,
        'from' => '', 'to' => '', 'record_id' => '', 'search' => '',
    ];
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#a855f7);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-clock-rotate-left me-2"></i>Global audit log</h1>
            <p class="mb-0 small opacity-75">Cross-module audit trail for every master-data create / update / delete / restore action.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.audit.export', request()->query()) }}" class="btn btn-light btn-sm" title="Download the filtered audit log as CSV">
                <i class="fas fa-file-csv me-1"></i> Export CSV
            </a>
            <a href="{{ route('admin.audit.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-rotate me-1"></i> Reset filters
            </a>
        </div>
    </header>

    {{-- Filter form --}}
    <form method="GET" action="{{ route('admin.audit.index') }}" class="mb-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="row g-2 align-items-end">
                    <div class="col-md-2">
                        <label class="form-label small text-muted mb-1">Table</label>
                        <select name="table" class="form-select form-select-sm">
                            <option value="">All tables</option>
                            @foreach ($tables as $table)
                                <option value="{{ $table }}" @selected($f['table'] === $table)>{{ $table }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted mb-1">Action</label>
                        <select name="action" class="form-select form-select-sm">
                            <option value="">All actions</option>
                            @foreach ($actions as $action)
                                <option value="{{ $action }}" @selected($f['action'] === $action)>
                                    {{ ucfirst(str_replace('master_data_', '', $action)) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted mb-1">User</label>
                        <select name="user_id" class="form-select form-select-sm">
                            <option value="">All users</option>
                            @foreach ($users as $uid => $name)
                                <option value="{{ $uid }}" @selected((string) $f['user_id'] === (string) $uid)>
                                    #{{ $uid }} — {{ $name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small text-muted mb-1">From</label>
                        <input type="date" name="from" class="form-control form-control-sm" value="{{ $f['from'] }}">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small text-muted mb-1">To</label>
                        <input type="date" name="to" class="form-control form-control-sm" value="{{ $f['to'] }}">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small text-muted mb-1">Record ID</label>
                        <input type="text" name="record_id" class="form-control form-control-sm" value="{{ $f['record_id'] }}" placeholder="e.g. 42">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small text-muted mb-1">Search details</label>
                        <div class="input-group input-group-sm">
                            <input type="text" name="search" class="form-control form-control-sm" value="{{ $f['search'] }}" placeholder="ILIKE search in JSON">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i></button>
                        </div>
                    </div>
                    <div class="col-md-1 d-grid">
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Apply</button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    {{-- Summary chips --}}
    <div class="d-flex gap-2 flex-wrap mb-3">
        <span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-list me-1"></i> {{ $auditLogs->total() }} entries</span>
        @if (!empty($f['table']))    <span class="badge bg-primary-subtle text-primary">table: {{ $f['table'] }}</span> @endif
        @if (!empty($f['action']))   <span class="badge bg-info-subtle text-info">action: {{ $f['action'] }}</span> @endif
        @if (!empty($f['user_id']))  <span class="badge bg-success-subtle text-success">user: #{{ $f['user_id'] }}</span> @endif
        @if (!empty($f['from']))     <span class="badge bg-warning-subtle text-warning">from: {{ $f['from'] }}</span> @endif
        @if (!empty($f['to']))       <span class="badge bg-warning-subtle text-warning">to: {{ $f['to'] }}</span> @endif
        @if (!empty($f['record_id']))<span class="badge bg-dark-subtle text-dark">record: {{ $f['record_id'] }}</span> @endif
        @if (!empty($f['search']))   <span class="badge bg-danger-subtle text-danger">q: "{{ $f['search'] }}"</span> @endif
    </div>

    {{-- Audit table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="auditTable">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>When</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Table</th>
                            <th>Record</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($auditLogs as $log)
                            @php
                                $action = (string) ($log->action ?? '');
                                $cls = str_contains($action, 'created') ? 'bg-success-subtle text-success'
                                     : (str_contains($action, 'updated') ? 'bg-primary-subtle text-primary'
                                     : (str_contains($action, 'deleted') ? 'bg-danger-subtle text-danger'
                                     : (str_contains($action, 'restored') ? 'bg-info-subtle text-info'
                                     : 'bg-warning-subtle text-warning')));
                                $performerName = $log->performed_by_name ?? ($log->username ?? ('#' . ($log->user_id ?? 0)));
                            @endphp
                            <tr>
                                <td class="text-nowrap small">
                                    <a href="{{ route('admin.audit.show', $log->id) }}" class="text-decoration-none fw-semibold">#{{ $log->id }}</a>
                                </td>
                                <td class="text-nowrap small">
                                    @if ($log->created_at)
                                        {{ \Carbon\Carbon::parse($log->created_at)->format('d M Y, H:i') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="small">{{ $performerName }}</td>
                                <td>
                                    <span class="badge {{ $cls }}">{{ ucfirst(str_replace('master_data_', '', $action ?: 'unknown')) }}</span>
                                </td>
                                <td class="small"><code>{{ $log->target_table ?? '—' }}</code></td>
                                <td class="small">{{ $log->target_id ?? '—' }}</td>
                                <td class="small text-muted">{{ $log->ip_address ?? 'unknown' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No audit entries match the current filters.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            {{ $auditLogs->links() }}
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function() {
    $('#auditTable').DataTable({
        paging: false,
        info: false,
        searching: false,
        order: [[0, 'desc']],
        language: { emptyTable: 'No audit entries found.' }
    });
});
</script>
@endpush
@endsection

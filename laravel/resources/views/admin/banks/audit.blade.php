@extends('layouts.admin')

@section('content')
@php
    $countCreated = 0; $countUpdated = 0; $countOther = 0;
    foreach ($auditLogs as $log) {
        $action = (string) ($log->action ?? '');
        if (str_contains($action, 'created')) $countCreated++;
        elseif (str_contains($action, 'updated')) $countUpdated++;
        else $countOther++;
    }
@endphp

<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0f766e,#14b8a6);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-clock-rotate-left me-2"></i>Bank audit trail</h1>
            <p class="mb-0 small opacity-75">Creates, updates, status changes, deactivations, and restores — bank events.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.banks.create') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-plus me-1"></i> New account
            </a>
            <a href="{{ route('admin.banks.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Accounts
            </a>
        </div>
    </header>

    <div class="d-flex gap-2 flex-wrap mb-3">
        <span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-list me-1"></i> {{ $auditLogs->total() }} entries</span>
        <span class="badge bg-success-subtle text-success"><i class="fas fa-plus me-1"></i> {{ $countCreated }} created</span>
        <span class="badge bg-primary-subtle text-primary"><i class="fas fa-pen me-1"></i> {{ $countUpdated }} updated</span>
        <span class="badge bg-warning-subtle text-warning"><i class="fas fa-toggle-on me-1"></i> {{ $countOther }} status</span>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="auditTable">
                    <thead class="table-light">
                        <tr>
                            <th>When</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Bank ID</th>
                            <th>Details</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($auditLogs as $log)
                            @php
                                $action = (string) ($log->action ?? '');
                                $cls = str_contains($action, 'created') ? 'bg-success-subtle text-success'
                                     : (str_contains($action, 'updated') ? 'bg-primary-subtle text-primary'
                                     : 'bg-warning-subtle text-warning');
                            @endphp
                            <tr>
                                <td class="text-nowrap small">{{ $log->created_at ?? '—' }}</td>
                                <td>{{ $log->performed_by_name ?? ('#' . ($log->user_id ?? 0)) }}</td>
                                <td><span class="badge {{ $cls }}">{{ ucfirst($action ?: 'unknown') }}</span></td>
                                <td>
                                    @if (!empty($log->target_id))
                                        <a href="{{ route('admin.banks.show', $log->target_id) }}" class="fw-semibold text-decoration-none">
                                            #{{ $log->target_id }}
                                        </a>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="small">
                                    @if (!empty($log->details))
                                        <code class="text-muted">{{ is_string($log->details) ? $log->details : json_encode($log->details) }}</code>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="small text-muted">{{ $log->ip_address ?? 'unknown' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No bank audit logs yet.
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
        order: [[0, 'desc']],
        dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
        language: { search: 'Filter logs:', emptyTable: 'No bank audit logs found.' }
    });
});
</script>
@endpush
@endsection

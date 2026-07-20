@extends('layouts.admin')

@php
    /** @var \Illuminate\Pagination\LengthAwarePaginator $auditLogs */
    /** @var string $routePrefix */
    /** @var string $label */

    // Action classification (the AuditableMasterData trait stores action as
    // master_data_{created|updated|deleted|restored}).
    $countCreated = $countUpdated = $countDeleted = $countRestored = $countOther = 0;
    foreach ($auditLogs as $log) {
        $a = (string) ($log->action ?? '');
        if (str_contains($a, 'created'))  { $countCreated++; }
        elseif (str_contains($a, 'updated'))  { $countUpdated++; }
        elseif (str_contains($a, 'deleted'))  { $countDeleted++; }
        elseif (str_contains($a, 'restored')) { $countRestored++; }
        else { $countOther++; }
    }

    // Use closures (assigned to variables) so the view is safe to render
    // multiple times in the same PHP request lifecycle.
    $empAuditClass = function (string $action): string {
        return match (true) {
            str_contains($action, 'created')  => 'success',
            str_contains($action, 'updated')  => 'primary',
            str_contains($action, 'deleted')  => 'danger',
            str_contains($action, 'restored') => 'warning',
            default                            => 'secondary',
        };
    };

    $empAuditLabel = function (string $action): string {
        return match (true) {
            str_contains($action, 'created')  => 'Created',
            str_contains($action, 'updated')  => 'Updated',
            str_contains($action, 'deleted')  => 'Deleted',
            str_contains($action, 'restored') => 'Restored',
            default                            => ucfirst($action ?: 'event'),
        };
    };

    $empAuditDetails = function ($raw) use ($empAuditClass, $empAuditLabel): string {
        if (empty($raw)) {
            return '<span class="text-muted">—</span>';
        }
        $data = is_string($raw) ? json_decode($raw, true) : (array) $raw;
        if (! is_array($data) || empty($data)) {
            return '<span class="text-muted">—</span>';
        }
        $parts = [];
        $new = $data['new'] ?? null;
        $old = $data['old'] ?? null;
        $rid = $data['record_id'] ?? null;

        if ($rid !== null) {
            $parts[] = '<span class="badge bg-light text-dark border">ID #' . e((string) $rid) . '</span>';
        }
        if (is_array($new) && ! empty($new['name'])) {
            $parts[] = '<strong>Name:</strong> ' . e((string) $new['name']);
        } elseif (is_array($old) && ! empty($old['name'])) {
            $parts[] = '<strong>Name:</strong> ' . e((string) $old['name']);
        }
        // Surface the changed fields (keys of $old) for update events
        if (is_array($old) && ! empty($old)) {
            $fields = array_keys($old);
            $parts[] = '<span class="text-muted small">Fields: ' . e(implode(', ', array_slice($fields, 0, 6))) .
                       (count($fields) > 6 ? ' +' . (count($fields) - 6) : '') . '</span>';
        }
        return $parts ? '<div class="d-flex flex-wrap gap-2">' . implode(' ', $parts) . '</div>' : '<span class="text-muted">—</span>';
    };
@endphp

@section('content')

<div class="container-fluid py-2">

    {{-- ==================== HERO HEADER ==================== --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-clock-rotate-left me-2"></i>Employee audit trail</h1>
            <p class="mb-0 small opacity-75">Creates, updates, deletes, restores — latest employee master-data events.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route($routePrefix . '.create') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-plus me-1"></i> New
            </a>
            <a href="{{ route($routePrefix . '.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Directory
            </a>
        </div>
    </header>

    {{-- ==================== SUMMARY CHIPS ==================== --}}
    <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="badge bg-light text-dark border px-3 py-2">
            <i class="fas fa-list me-1"></i> {{ $auditLogs->total() }} total entries
        </span>
        <span class="badge bg-success-subtle text-success border px-3 py-2">
            <i class="fas fa-plus me-1"></i> {{ $countCreated }} created
        </span>
        <span class="badge bg-primary-subtle text-primary border px-3 py-2">
            <i class="fas fa-pen me-1"></i> {{ $countUpdated }} updated
        </span>
        <span class="badge bg-danger-subtle text-danger border px-3 py-2">
            <i class="fas fa-trash me-1"></i> {{ $countDeleted }} deleted
        </span>
        <span class="badge bg-warning-subtle text-warning border px-3 py-2">
            <i class="fas fa-rotate-left me-1"></i> {{ $countRestored }} restored
        </span>
        @if ($countOther)
        <span class="badge bg-secondary-subtle text-secondary border px-3 py-2">
            <i class="fas fa-ellipsis me-1"></i> {{ $countOther }} other
        </span>
        @endif
    </div>

    {{-- ==================== AUDIT TABLE ==================== --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom fw-semibold">
            <i class="fas fa-list me-2 text-primary"></i>Audit entries
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="auditTable">
                    <thead class="table-light">
                        <tr>
                            <th>When</th>
                            <th>Performed by</th>
                            <th>Action</th>
                            <th>Record ID</th>
                            <th>Details</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($auditLogs as $log)
                            @php
                                $action = (string) ($log->action ?? '');
                                $details = $log->details ?? null;
                                $rawDecoded = is_string($details) ? json_decode($details, true) : (array) $details;
                                $recordId = $rawDecoded['record_id'] ?? null;
                            @endphp
                            <tr>
                                <td>
                                    <small class="text-nowrap">
                                        @if (! empty($log->created_at))
                                            {{ \Illuminate\Support\Carbon::parse($log->created_at)->format('M j, Y g:i A') }}
                                        @else
                                            —
                                        @endif
                                    </small>
                                </td>
                                <td>
                                    <span class="badge rounded-pill bg-light text-dark border">
                                        @if (! empty($log->user_id))
                                            User #{{ (int) $log->user_id }}
                                        @else
                                            <span class="text-muted">system</span>
                                        @endif
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-{{ $empAuditClass($action) }}">
                                        {{ $empAuditLabel($action) }}
                                    </span>
                                </td>
                                <td>
                                    @if ($recordId !== null)
                                        <strong>{{ e((string) $recordId) }}</strong>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>{!! $empAuditDetails($details) !!}</td>
                                <td>
                                    <small class="text-muted">{{ $log->ip_address ?: '—' }}</small>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No employee audit logs yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($auditLogs->hasPages())
        <div class="card-footer bg-white">
            {{ $auditLogs->links() }}
        </div>
        @endif
    </div>

    <div class="text-muted small mt-2">
        <i class="fas fa-database me-1"></i>
        Source: <code>user_audit_log</code> table · filter: <code>details-&gt;'table' = 'employees'</code>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    if (!$.fn.DataTable) return;
    // Only init when there are real data rows (not the empty-state colspan row)
    if ($('#auditTable tbody tr').length < 1) return;
    if ($('#auditTable tbody tr:first td').length === 1) return; // empty-state row
    $('#auditTable').DataTable({
        paging: false,
        info: false,
        order: [[0, 'desc']],
        dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
        language: { search: 'Filter:', emptyTable: 'No audit entries found.' },
        columnDefs: [{ orderable: false, targets: [4, 5] }]
    });
});
</script>
@endpush
@endsection

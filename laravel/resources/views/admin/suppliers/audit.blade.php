@extends('layouts.admin')

@section('content')

@push('css')
<style>
    .md-hero {
        background: linear-gradient(135deg, #b45309 0%, #d97706 100%);
        color: #fff; border-radius: .75rem; padding: 1.25rem 1.5rem;
        display: flex; justify-content: space-between; flex-wrap: wrap;
        gap: 1rem; align-items: center; margin-bottom: 1rem;
    }
    .md-hero h1 { font-size: 1.5rem; margin: 0 0 .25rem; font-weight: 700; }
    .md-hero p  { margin: 0; opacity: .9; font-size: .9rem; }
    .md-hero-actions { display: flex; gap: .5rem; flex-wrap: wrap; }

    .md-audit-summary { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .md-audit-chip {
        display: inline-flex; align-items: center; gap: .35rem;
        padding: .3rem .7rem; border-radius: 1rem; font-size: .8rem; font-weight: 600;
        background: #f1f5f9; color: #475569;
    }
    .md-audit-chip.created { background: #dcfce7; color: #166534; }
    .md-audit-chip.updated { background: #dbeafe; color: #1e40af; }
    .md-audit-chip.status  { background: #fef3c7; color: #92400e; }

    .md-panel { background: #fff; border: 1px solid #e7eaf0; border-radius: .65rem; box-shadow: 0 1px 2px rgba(15,23,42,.04); overflow: hidden; }
    .md-panel-body { padding: 0; }
    .md-table th { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: #64748b; padding: .75rem 1rem; }
    .md-table td { padding: .65rem 1rem; vertical-align: middle; }
    .md-audit-action { display: inline-block; padding: .15rem .55rem; border-radius: 1rem; font-size: .75rem; font-weight: 600; }
    .md-audit-action.created   { background: #dcfce7; color: #166534; }
    .md-audit-action.updated   { background: #dbeafe; color: #1e40af; }
    .md-audit-action.deleted   { background: #fee2e2; color: #991b1b; }
    .md-audit-action.restored  { background: #dcfce7; color: #166534; }
    .md-audit-action.other     { background: #f1f5f9; color: #475569; }
    .md-audit-meta-foot { padding: .65rem 1rem; border-top: 1px solid #eef2f6; background: #fafbfd; font-size: .8rem; color: #6b7280; }
</style>
@endpush

@php
    $countCreated = $countUpdated = $countStatus = 0;
    foreach ($auditLogs as $log) {
        $action = (string) ($log->action ?? '');
        if (str_contains($action, 'created'))      $countCreated++;
        elseif (str_contains($action, 'updated'))  $countUpdated++;
        elseif (str_contains($action, 'deleted') || str_contains($action, 'restored')) $countStatus++;
    }
@endphp

<div class="md-hero">
    <div>
        <h1><i class="fas fa-clock-rotate-left me-2"></i>Supplier audit trail</h1>
        <p>Creates, updates, deletions, and restores — recent supplier events from <code>user_audit_log</code>.</p>
    </div>
    <div class="md-hero-actions">
        <a href="{{ route("{$routePrefix}.create") }}" class="btn btn-outline-light btn-sm">
            <i class="fas fa-plus me-1"></i> New supplier
        </a>
        <a href="{{ route("{$routePrefix}.index") }}" class="btn btn-light btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Directory
        </a>
    </div>
</div>

<div class="md-audit-summary">
    <span class="md-audit-chip"><i class="fas fa-list"></i> {{ $auditLogs->total() }} entries</span>
    <span class="md-audit-chip created"><i class="fas fa-plus"></i> {{ $countCreated }} created</span>
    <span class="md-audit-chip updated"><i class="fas fa-pen"></i> {{ $countUpdated }} updated</span>
    <span class="md-audit-chip status"><i class="fas fa-toggle-on"></i> {{ $countStatus }} status</span>
</div>

<div class="md-panel">
    <div class="md-panel-body">
        <div class="table-responsive">
            <table class="table table-borderless align-middle mb-0 md-table" id="auditTable">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Record ID</th>
                        <th>Details</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($auditLogs as $log)
                        @php
                            $action     = (string) ($log->action ?? '');
                            $actionCls  = str_contains($action, 'created')  ? 'created'
                                        : (str_contains($action, 'updated') ? 'updated'
                                        : (str_contains($action, 'deleted') ? 'deleted'
                                        : (str_contains($action, 'restored') ? 'restored' : 'other')));
                            $details    = json_decode($log->details ?? '{}', true) ?: [];
                            $recordId   = $details['record_id'] ?? null;
                            $tableName  = $details['table'] ?? '';
                            $old        = $details['old'] ?? [];
                            $new        = $details['new'] ?? [];
                        @endphp
                        <tr>
                            <td><small class="text-nowrap">{{ $log->created_at ?? '' }}</small></td>
                            <td>{{ $log->user_id ?? '—' }}</td>
                            <td><span class="md-audit-action {{ $actionCls }}">{{ $action }}</span></td>
                            <td>
                                @if (is_numeric($recordId) && $recordId > 0)
                                    #{{ (int) $recordId }}
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if (! empty($new) || ! empty($old))
                                    <details>
                                        <summary class="text-muted small">{{ $tableName }} · {{ count($new ?: []) }} changed</summary>
                                        @if (! empty($old))
                                            <div class="mt-1"><strong class="small text-muted">Old:</strong> <code class="d-block small">{{ json_encode($old, JSON_PRETTY_PRINT) }}</code></div>
                                        @endif
                                        @if (! empty($new))
                                            <div class="mt-1"><strong class="small text-muted">New:</strong> <code class="d-block small">{{ json_encode($new, JSON_PRETTY_PRINT) }}</code></div>
                                        @endif
                                    </details>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td><small class="text-muted">{{ $log->ip_address ?? 'unknown' }}</small></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="fas fa-inbox fa-2x mb-2 opacity-50 d-block"></i>
                                No supplier audit logs yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="md-audit-meta-foot">
        <i class="fas fa-file-lines me-1"></i>
        Stored in <code>user_audit_log</code> · Filtered by table <code>suppliers</code> · {{ $auditLogs->total() }} entries on {{ $auditLogs->lastPage() }} page(s).
    </div>
</div>

<div class="mt-3">
    {{ $auditLogs->links() }}
</div>

@push('scripts')
<script>
$(function () {
    const hasRows = $('#auditTable tbody tr').length > 0
                 && $('#auditTable tbody tr td').length > 1;
    if (! hasRows) return;

    $('#auditTable').DataTable({
        pageLength: 50,
        order: [[0, 'desc']],
        dom: '<"row mb-2"<"col-sm-6"l><"col-sm-6 text-end"f>>rtip',
        language: {
            emptyTable: 'No supplier audit logs found yet.',
            search: 'Filter logs:'
        }
    });
});
</script>
@endpush

@endsection

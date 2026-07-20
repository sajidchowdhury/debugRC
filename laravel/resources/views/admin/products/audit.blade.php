@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white" style="background:linear-gradient(135deg,#0f766e,#14b8a6);">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-clock-rotate-left me-2"></i>Product audit trail</h1>
            <p class="mb-0 opacity-75">Creates, updates, deletes, restores, price changes — last 300 product events.</p>
        </div>
        <div class="d-flex flex-wrap gap-1">
            <a href="{{ route($routePrefix . '.create') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-plus me-1"></i> New product
            </a>
            <a href="{{ route($routePrefix . '.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Catalog
            </a>
        </div>
    </header>

    {{-- Summary chips --}}
    <div class="d-flex flex-wrap gap-2 mb-3">
        <span class="badge bg-light text-dark border rounded-pill px-3 py-2">
            <i class="fas fa-list me-1"></i> {{ $auditLogs->total() }} entries
        </span>
    </div>

    {{-- Audit table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="auditTable">
                    <thead class="table-light">
                        <tr>
                            <th>When</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Record</th>
                            <th>Details</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($auditLogs as $log)
                            @php
                                $details = json_decode($log->details ?? 'null', true);
                                $action  = $log->action ?? '';
                                $label   = match(true) {
                                    str_contains($action, 'created')  => 'Created',
                                    str_contains($action, 'updated')  => 'Updated',
                                    str_contains($action, 'deleted')  => 'Deactivated',
                                    str_contains($action, 'restored') => 'Restored',
                                    default                            => ucfirst($action),
                                };
                                $cls = match(true) {
                                    str_contains($action, 'created')  => 'bg-success-subtle text-success',
                                    str_contains($action, 'updated')  => 'bg-primary-subtle text-primary',
                                    str_contains($action, 'deleted')  => 'bg-danger-subtle text-danger',
                                    str_contains($action, 'restored') => 'bg-warning-subtle text-warning',
                                    default                            => 'bg-secondary-subtle text-secondary',
                                };
                            @endphp
                            <tr>
                                <td><small class="text-nowrap">{{ \Carbon\Carbon::parse($log->created_at)->format('d M Y h:i A') }}</small></td>
                                <td><span class="badge rounded-pill bg-light text-dark border">#{{ $log->user_id ?? '—' }}</span></td>
                                <td><span class="badge {{ $cls }}">{{ $label }}</span></td>
                                <td>
                                    @if (!empty($details['record_id']))
                                        <strong>#{{ $details['record_id'] }}</strong>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="small">
                                    @php
                                        $parts = [];
                                        $old = $details['old'] ?? null;
                                        $new = $details['new'] ?? null;
                                        if (is_array($new)) {
                                            foreach (['product_name','product_code'] as $f) {
                                                if (!empty($new[$f])) $parts[] = '<strong>'.$f.':</strong> '.e($new[$f]);
                                            }
                                            if (!empty($new['min_rate']) && !empty($new['max_rate'])) {
                                                $parts[] = '<strong>Range:</strong> Tk '.number_format((float)$new['min_rate'],2).' – '.number_format((float)$new['max_rate'],2);
                                            }
                                            if (empty($parts)) {
                                                $changes = array_keys($new);
                                                $parts[] = '<em>Fields:</em> '.e(implode(', ', array_slice($changes, 0, 5)));
                                            }
                                        }
                                    @endphp
                                    @if (!empty($parts))
                                        {!! implode('<br>', $parts) !!}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td><small class="text-muted">{{ $log->ip_address ?? 'unknown' }}</small></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No product audit logs yet.
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
@endsection

@push('scripts')
<script>
$(function() {
    // Only enable DataTables if there are enough rows to warrant it
    const rows = $('#auditTable tbody tr').length;
    if (rows > 1) {
        $('#auditTable').DataTable({
            pageLength: 50,
            order: [[0, 'desc']],
            dom: '<"row mb-2"<"col-sm-6"l><"col-sm-6 text-end"f>>rtip'
        });
    }
});
</script>
@endpush

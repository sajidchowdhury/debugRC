@php
    /**
     * Security audit partial — shows recent login history, failed attempts,
     * and lockout events for the user. Inline on the show page.
     *
     * Phase 14.
     *
     * @var \App\Models\User $item
     */
    $events = isset($events) ? $events : (method_exists($item, 'auditHistory') ? $item->auditHistory(10) : collect());
@endphp

<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
        <h2 class="h6 mb-0"><i class="fas fa-shield-halved me-1 text-info"></i> Recent security events</h2>
        <a href="{{ route('admin.users.security', $item) }}" class="btn btn-sm btn-outline-info">
            <i class="fas fa-arrow-right me-1"></i> Full audit
        </a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>When</th>
                        <th>Action</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($events as $log)
                        @php
                            $action = (string) ($log->action ?? '');
                            $cls = str_contains($action, 'success') ? 'bg-success-subtle text-success'
                                 : (str_contains($action, 'failed') ? 'bg-danger-subtle text-danger'
                                 : (str_contains($action, 'locked') ? 'bg-warning-subtle text-warning'
                                 : 'bg-secondary-subtle text-secondary'));
                        @endphp
                        <tr>
                            <td class="text-nowrap small">{{ $log->created_at ?? '—' }}</td>
                            <td><span class="badge {{ $cls }}">{{ $action ?: '—' }}</span></td>
                            <td class="small text-muted">{{ $log->ip_address ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted py-3">
                                <i class="fas fa-inbox mb-1 d-block opacity-50"></i>
                                No recent security events.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

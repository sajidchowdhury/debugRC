@extends('layouts.admin')

@section('content')
@php
    $summary = $summary ?? ['logins' => 0, 'failed_logins' => 0, 'lockouts' => 0, 'password_changes' => 0];
@endphp

<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#1e3a8a,#3b82f6);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-shield-halved me-2"></i>Security audit — {{ $item->username }}</h1>
            <p class="mb-0 small opacity-75">
                Login history, failed attempts, lockouts, and password changes for
                <strong>{{ optional($item->employee)->name ?? 'unknown employee' }}</strong>.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.users.show', $item) }}" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to user
            </a>
        </div>
    </header>

    {{-- Summary cards --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#16a34a;">
                        <i class="fas fa-arrow-right-to-bracket"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ (int) ($summary['logins'] ?? 0) }}</div>
                        <div class="text-muted small">Successful logins</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#dc2626;">
                        <i class="fas fa-xmark"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ (int) ($summary['failed_logins'] ?? 0) }}</div>
                        <div class="text-muted small">Failed attempts</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#d97706;">
                        <i class="fas fa-lock"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ (int) ($summary['lockouts'] ?? 0) }}</div>
                        <div class="text-muted small">Lockout events</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0ea5e9;">
                        <i class="fas fa-key"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ (int) ($summary['password_changes'] ?? 0) }}</div>
                        <div class="text-muted small">Password resets</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Events table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h2 class="h6 mb-0"><i class="fas fa-list me-1 text-secondary"></i> Security event log</h2>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="securityTable">
                    <thead class="table-light">
                        <tr>
                            <th>When</th>
                            <th>Action</th>
                            <th>IP</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($securityEvents as $log)
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
                                <td class="small">
                                    @if (!empty($log->details))
                                        <code class="text-muted">{{ is_string($log->details) ? $log->details : json_encode($log->details) }}</code>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No security events recorded.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function() {
    $('#securityTable').DataTable({
        paging: false,
        info: false,
        order: [[0, 'desc']],
        dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
        language: { search: 'Filter:', emptyTable: 'No security events found.' }
    });
});
</script>
@endpush
@endsection

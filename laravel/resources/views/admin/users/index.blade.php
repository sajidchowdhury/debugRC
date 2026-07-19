@extends('layouts.admin')

@section('content')
@php
    $stats = $stats ?? ['active' => 0, 'locked' => 0, 'total' => 0, 'telegram' => 0];
    $showDeleted = $showDeleted ?? false;
@endphp

<div class="container-fluid py-2">
    {{-- Hero --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#1e3a8a,#3b82f6);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-users me-2"></i>{{ $showDeleted ? 'Inactive user accounts' : 'User accounts' }}</h1>
            <p class="mb-0 small opacity-75">
                {{ $showDeleted
                    ? 'Restore deactivated login accounts.'
                    : 'Login accounts tied to employees — RBAC, lockouts, password resets, and security audit.' }}
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if ($showDeleted)
                <a href="{{ route('admin.users.index') }}" class="btn btn-light btn-sm">
                    <i class="fas fa-user-shield me-1"></i> Active
                </a>
            @else
                <a href="{{ route('admin.users.index', ['deleted' => 1]) }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-box-archive me-1"></i> Inactive
                </a>
            @endif
            <a href="{{ route('admin.users.audit') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
            <a href="{{ route('admin.users.export') }}" class="btn btn-outline-light btn-sm" title="Download all users as a CSV file">
                <i class="fas fa-file-csv me-1"></i> Export CSV
            </a>
            <a href="{{ route('admin.users.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New user
            </a>
        </div>
    </header>

    {{-- Stats cards (hidden when viewing trashed) --}}
    @if (!$showDeleted)
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#16a34a;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['active'] ?? 0)) }}</div>
                        <div class="text-muted small">Active users</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#dc2626;">
                        <i class="fas fa-lock"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['locked'] ?? 0)) }}</div>
                        <div class="text-muted small">Locked accounts</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#475569;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['total'] ?? 0)) }}</div>
                        <div class="text-muted small">Total accounts</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0ea5e9;">
                        <i class="fab fa-telegram"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['telegram'] ?? 0)) }}</div>
                        <div class="text-muted small">Telegram linked</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Table panel --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <table class="table table-hover align-middle mb-0" id="userTable">
                <thead class="table-light">
                    <tr>
                        <th>Username</th>
                        <th>Employee</th>
                        <th class="d-none d-lg-table-cell">Branch</th>
                        <th class="d-none d-md-table-cell">Last login</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $user)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="rounded-circle bg-primary-subtle text-primary d-inline-flex align-items-center justify-content-center fw-bold"
                                          style="width:36px;height:36px;">
                                        {{ strtoupper(substr($user->username ?? '?', 0, 1)) }}
                                    </span>
                                    <a href="{{ route('admin.users.show', $user) }}" class="fw-semibold text-decoration-none text-reset">
                                        {{ $user->username }}
                                    </a>
                                    @if ($user->telegram_user_id)
                                        <span class="badge bg-info-subtle text-info" title="Telegram linked"><i class="fab fa-telegram"></i></span>
                                    @endif
                                </div>
                            </td>
                            <td>
                                @if ($user->employee)
                                    <span class="fw-semibold">{{ $user->employee->name }}</span>
                                    <div class="small text-muted">{{ $user->employee->employee_code ?? '—' }}</div>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="d-none d-lg-table-cell">{{ optional($user->employee)->branch?->branch_name ?? '—' }}</td>
                            <td class="d-none d-md-table-cell small">
                                @if ($user->last_login)
                                    {{ $user->last_login->format('Y-m-d H:i') }}
                                    @if ($user->last_login_ip)
                                        <div class="text-muted">{{ $user->last_login_ip }}</div>
                                    @endif
                                @else
                                    <span class="text-muted">never</span>
                                @endif
                            </td>
                            <td>
                                @if (!$user->is_active)
                                    <span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-circle-xmark me-1"></i>Inactive</span>
                                @elseif ($user->isLocked())
                                    <span class="badge bg-danger-subtle text-danger"><i class="fas fa-lock me-1"></i>Locked</span>
                                @else
                                    <span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i>Active</span>
                                @endif
                            </td>
                            <td class="text-center text-nowrap">
                                <a href="{{ route('admin.users.show', $user) }}" class="btn btn-sm btn-outline-secondary" title="View">
                                    <i class="fas fa-circle-info"></i>
                                </a>
                                <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm btn-outline-primary" title="Edit">
                                    <i class="fas fa-pen"></i>
                                </a>
                                @if ($showDeleted)
                                    <form method="POST" action="{{ route('admin.users.restore', $user) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Restore">
                                            <i class="fas fa-rotate-left"></i>
                                        </button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.users.destroy', $user) }}" class="d-inline"
                                          onsubmit="return confirm('Deactivate this user account?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Deactivate">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                No user accounts found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white">
            {{ $items->links() }}
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function() {
    $('#userTable').DataTable({
        paging: false,
        info: false,
        ordering: true,
        searching: true,
        dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
        language: { search: 'Filter:', emptyTable: 'No user accounts found.' }
    });
});
</script>
@endpush
@endsection

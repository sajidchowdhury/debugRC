@extends('layouts.admin')

@section('content')
@php
    $trashed = $item->trashed();
    $newPassword = session('new_password');
@endphp

<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#1e3a8a,#3b82f6);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-user me-2"></i>{{ $item->username }}</h1>
            <p class="mb-0 small opacity-75">
                @if ($item->employee)
                    <strong>{{ $item->employee->name }}</strong>
                    @if ($item->employee->branch)
                        · {{ $item->employee->branch->branch_name }}
                    @endif
                @else
                    No linked employee
                @endif
            </p>
            <span class="badge bg-white text-dark mt-2">
                @if ($item->is_active && !$item->isLocked())
                    <i class="fas fa-circle-check text-success"></i> Active
                @elseif ($item->isLocked())
                    <i class="fas fa-lock text-danger"></i> Locked
                @elseif (!$item->is_active)
                    <i class="fas fa-circle-xmark text-secondary"></i> Inactive
                @endif
                · credential v{{ $item->credential_version ?? 1 }}
            </span>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.users.edit', $item) }}" class="btn btn-light btn-sm">
                <i class="fas fa-pen me-1"></i> Edit
            </a>
            <a href="{{ route('admin.users.security', $item) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-shield-halved me-1"></i> Security
            </a>
            <a href="{{ route('admin.users.audit') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
            <a href="{{ route('admin.users.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Users
            </a>
        </div>
    </header>

    @if ($newPassword)
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="fas fa-key me-2"></i>
        <strong>New temporary password:</strong>
        <code class="fs-5 user-select-all">{{ $newPassword }}</code>
        <div class="small mt-1">Share this with the user via a secure out-of-band channel. It will not be shown again.</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#16a34a;">
                        <i class="fas fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="h6 mb-0">
                            @if ($item->is_active)
                                <span class="text-success">Active</span>
                            @else
                                <span class="text-secondary">Inactive</span>
                            @endif
                        </div>
                        <div class="text-muted small">Account status</div>
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
                        <div class="h6 mb-0">
                            @if ($item->isLocked())
                                <span class="text-danger">Locked</span>
                            @else
                                <span class="text-muted">Unlocked</span>
                            @endif
                        </div>
                        <div class="text-muted small">
                            @if ($item->locked_until)
                                Until {{ $item->locked_until->format('Y-m-d H:i') }}
                            @else
                                No lockout
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#4f46e5;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <div class="h6 mb-0">
                            @if ($item->last_login)
                                {{ $item->last_login->format('Y-m-d H:i') }}
                            @else
                                <span class="text-muted">never</span>
                            @endif
                        </div>
                        <div class="text-muted small">Last login</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0ea5e9;">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div>
                        <div class="h6 mb-0">
                            <span class="text-muted">{{ $item->unreadNotificationsCount ?? 0 }}</span>
                        </div>
                        <div class="text-muted small">Unread notifications</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-primary"></i> Account details</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">Username</dt>
                        <dd class="col-sm-9">
                            <code>{{ $item->username }}</code>
                        </dd>

                        <dt class="col-sm-3 text-muted">Employee</dt>
                        <dd class="col-sm-9">
                            @if ($item->employee)
                                @if ($item->employee->id)
                                    <a href="{{ route('admin.employees.show', $item->employee) }}" class="text-decoration-none">
                                        {{ $item->employee->name }}
                                    </a>
                                @else
                                    {{ $item->employee->name }}
                                @endif
                                <div class="small text-muted">{{ $item->employee->employee_code ?? '—' }}</div>
                            @else
                                <span class="text-muted">No linked employee</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Branch</dt>
                        <dd class="col-sm-9">{{ optional($item->employee)->branch?->branch_name ?? '—' }}</dd>

                        <dt class="col-sm-3 text-muted">Role</dt>
                        <dd class="col-sm-9">
                            @if ($item->employee && $item->employee->role)
                                <span class="badge bg-primary-subtle text-primary">{{ $item->employee->role }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Status</dt>
                        <dd class="col-sm-9">
                            @if (!$item->is_active)
                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            @elseif ($item->isLocked())
                                <span class="badge bg-danger-subtle text-danger">Locked</span>
                            @else
                                <span class="badge bg-success-subtle text-success">Active</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Failed logins</dt>
                        <dd class="col-sm-9">{{ (int) $item->failed_login_count }}</dd>

                        <dt class="col-sm-3 text-muted">Credential version</dt>
                        <dd class="col-sm-9">{{ (int) $item->credential_version }} <span class="text-muted small">(bumped on password/role change → invalidates sessions)</span></dd>

                        <dt class="col-sm-3 text-muted">Last login</dt>
                        <dd class="col-sm-9">
                            @if ($item->last_login)
                                {{ $item->last_login->format('Y-m-d H:i:s') }}
                                @if ($item->last_login_ip)
                                    <span class="text-muted">from {{ $item->last_login_ip }}</span>
                                @endif
                            @else
                                <span class="text-muted">never</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Created</dt>
                        <dd class="col-sm-9">{{ optional($item->created_at)->format('Y-m-d H:i') }}</dd>

                        <dt class="col-sm-3 text-muted">Updated</dt>
                        <dd class="col-sm-9">{{ optional($item->updated_at)->format('Y-m-d H:i') }}</dd>
                    </dl>
                </div>
            </div>

            {{-- Security audit partial --}}
            @include('admin.users._security_audit', ['item' => $item])
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-gear me-1 text-secondary"></i> Actions</h2>
                </div>
                <div class="card-body d-grid gap-2">
                    <a href="{{ route('admin.users.edit', $item) }}" class="btn btn-outline-primary">
                        <i class="fas fa-pen me-1"></i> Edit user
                    </a>
                    <a href="{{ route('admin.users.security', $item) }}" class="btn btn-outline-info">
                        <i class="fas fa-shield-halved me-1"></i> Full security audit
                    </a>

                    {{-- Unlock button partial --}}
                    @include('admin.users._unlock_button', ['item' => $item])

                    {{-- Reset password partial --}}
                    @include('admin.users._reset_password', ['item' => $item])

                    @if ($trashed)
                        <form method="POST" action="{{ route('admin.users.restore', $item) }}">
                            @csrf
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-rotate-left me-1"></i> Restore
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.users.destroy', $item) }}"
                              onsubmit="return confirm('Deactivate this user account? Active sessions within 5 minutes will block this.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger w-100">
                                <i class="fas fa-power-off me-1"></i> Deactivate
                            </button>
                        </form>
                    @endif
                    <a href="{{ route('admin.users.audit') }}" class="btn btn-outline-secondary">
                        <i class="fas fa-clock-rotate-left me-1"></i> View audit log
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

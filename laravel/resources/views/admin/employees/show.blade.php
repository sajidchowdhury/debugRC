@extends('layouts.admin')

@php
    /** @var \App\Models\Employee $item */
    /** @var string $routePrefix */
    $roleColors = [
        'superadmin'         => 'danger',
        'admin'              => 'primary',
        'manager'            => 'info',
        'accountant'         => 'success',
        'salesman'           => 'warning',
        'warehouse_manager'  => 'secondary',
        'dispatcher'         => 'light',
        'hr'                 => 'dark',
        'user'               => 'secondary',
        'other'              => 'secondary',
    ];
    $roleLabels = collect(config('roles'))->mapWithKeys(fn ($r, $k) => [$k => $r['label']])->toArray();
    $roleColor = $roleColors[$item->role] ?? 'secondary';
    $roleLabel = $roleLabels[$item->role] ?? ucfirst(str_replace('_', ' ', $item->role));
    $photoUrl  = $item->photo
        ? Illuminate\Support\Facades\Storage::disk('public')->url($item->photo)
        : null;
    $user = $item->user;
@endphp

@section('content')

<div class="container-fluid py-2">

    {{-- ==================== HERO HEADER ==================== --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-id-card-clip me-2"></i>{{ $item->name }}</h1>
            <p class="mb-1 small opacity-75">Workforce profile and linked system user.</p>
            <span class="badge bg-light text-dark me-1">
                @if ($item->trashed())
                    <i class="fas fa-circle-xmark text-secondary me-1"></i> Inactive
                @elseif ($item->is_active)
                    <i class="fas fa-circle-check text-success me-1"></i> Active
                @else
                    <i class="fas fa-circle-pause text-warning me-1"></i> Disabled
                @endif
                · {{ $item->employee_code }}
            </span>
            <span class="badge bg-light text-dark">
                <i class="fas fa-user-tag me-1"></i> {{ $roleLabel }}
            </span>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route($routePrefix . '.edit', $item) }}" class="btn btn-light btn-sm">
                <i class="fas fa-pen me-1"></i> Edit
            </a>
            <a href="{{ route($routePrefix . '.account', $item) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-id-card-clip me-1"></i> Account
            </a>
            <a href="{{ route($routePrefix . '.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </header>

    <div class="row g-3">

        {{-- ==================== PROFILE CARD ==================== --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center">
                    @if ($photoUrl)
                        <img src="{{ $photoUrl }}" alt="Photo"
                             class="rounded-circle mb-3 shadow-sm"
                             style="width:128px;height:128px;object-fit:cover;">
                    @else
                        <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center mb-3 shadow-sm"
                             style="width:128px;height:128px;font-size:3rem;font-weight:bold;">
                            {{ strtoupper(substr($item->name ?? '?', 0, 1)) }}
                        </div>
                    @endif
                    <h4 class="mb-1">{{ $item->name }}</h4>
                    <div class="mb-2">
                        <span class="badge bg-light text-dark border me-1">{{ $item->employee_code }}</span>
                        <span class="badge bg-{{ $roleColor }}">{{ $roleLabel }}</span>
                    </div>
                    <div class="text-muted small">
                        <i class="fas fa-sitemap me-1"></i>{{ $item->branch?->branch_name ?? '—' }}
                    </div>
                </div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted"><i class="fas fa-phone me-2"></i> Phone</span>
                        <strong>{{ $item->phone ?: '—' }}</strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted"><i class="fas fa-envelope me-2"></i> Email</span>
                        <strong class="text-truncate" style="max-width:180px;">{{ $item->email ?: '—' }}</strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted"><i class="fas fa-wallet me-2"></i> Salary</span>
                        <strong>৳ {{ number_format((float) ($item->salary ?? 0), 2) }}</strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted"><i class="fas fa-calendar-day me-2"></i> Joining date</span>
                        <strong>{{ optional($item->joining_date)?->format('M j, Y') ?? '—' }}</strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span class="text-muted"><i class="fas fa-circle-question me-2"></i> Status</span>
                        @if ($item->trashed())
                            <span class="badge bg-secondary">Inactive (deleted)</span>
                        @elseif ($item->is_active)
                            <span class="badge bg-success">Active</span>
                        @else
                            <span class="badge bg-warning text-dark">Disabled</span>
                        @endif
                    </li>
                </ul>
                @if ($item->address)
                <div class="card-footer bg-white">
                    <div class="text-muted small mb-1"><i class="fas fa-location-dot me-1"></i> Address</div>
                    <div class="small">{{ $item->address }}</div>
                </div>
                @endif
            </div>
        </div>

        {{-- ==================== LINKED USER + AUDIT METADATA ==================== --}}
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom fw-semibold">
                    <i class="fas fa-user-shield me-2 text-info"></i>Linked system user
                </div>
                <div class="card-body">
                    @if ($user)
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="text-muted small mb-1">Username</div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-light text-dark border">{{ $user->username }}</span>
                                    @if ($user->is_active)
                                        <span class="badge bg-success-subtle text-success"><i class="fas fa-check me-1"></i>Active</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-minus me-1"></i>Inactive</span>
                                    @endif
                                    @if ($user->isLocked())
                                        <span class="badge bg-danger-subtle text-danger"><i class="fas fa-lock me-1"></i>Locked</span>
                                    @endif
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small mb-1">Last login</div>
                                <div>
                                    @if ($user->last_login)
                                        <i class="fas fa-clock text-muted me-1"></i>
                                        {{ $user->last_login->format('M j, Y g:i A') }}
                                        @if ($user->last_login_ip)
                                            <span class="text-muted small">· {{ $user->last_login_ip }}</span>
                                        @endif
                                    @else
                                        <span class="text-muted">Never logged in</span>
                                    @endif
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small mb-1">Failed login attempts</div>
                                <div>{{ (int) $user->failed_login_count }}</div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small mb-1">Telegram linked</div>
                                <div>
                                    @if ($user->telegram_user_id)
                                        <span class="badge bg-info-subtle text-info"><i class="fab fa-telegram me-1"></i>{{ $user->telegram_user_id }}</span>
                                    @else
                                        <span class="text-muted">Not linked</span>
                                    @endif
                                </div>
                            </div>
                            <div class="col-12 mt-3">
                                <a href="{{ route($routePrefix . '.account', $item) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-id-card-clip me-1"></i> Open account hub
                                </a>
                            </div>
                        </div>
                    @else
                        <div class="text-center py-4">
                            <i class="fas fa-user-plus d-block mb-2" style="font-size:2.4rem;opacity:.4;"></i>
                            <p class="text-muted">This employee does not have a system user account yet.</p>
                            <p class="text-muted small">Phase 4 (read-only): user provisioning will be wired up in a later phase.</p>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Audit metadata --}}
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom fw-semibold">
                    <i class="fas fa-clock-rotate-left me-2 text-secondary"></i>Record metadata
                </div>
                <div class="card-body">
                    <div class="row g-3 small">
                        <div class="col-md-6">
                            <span class="text-muted d-block">Created at</span>
                            <strong>{{ optional($item->created_at)?->format('M j, Y g:i A') ?? '—' }}</strong>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted d-block">Last updated</span>
                            <strong>{{ optional($item->updated_at)?->format('M j, Y g:i A') ?? '—' }}</strong>
                        </div>
                        @if ($item->trashed())
                        <div class="col-md-6">
                            <span class="text-muted d-block">Deleted at</span>
                            <strong>{{ optional($item->deleted_at)?->format('M j, Y g:i A') ?? '—' }}</strong>
                        </div>
                        <div class="col-md-6">
                            <span class="text-muted d-block">Deleted by</span>
                            <strong>{{ $item->deleted_by ? ('User #' . $item->deleted_by) : '—' }}</strong>
                        </div>
                        @endif
                    </div>
                    <a href="{{ route($routePrefix . '.audit') }}" class="btn btn-sm btn-outline-secondary mt-3">
                        <i class="fas fa-list me-1"></i> View full audit log
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

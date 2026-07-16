@extends('layouts.admin')

@php
    /** @var \App\Models\Employee $item */
    /** @var array $salarySummary */
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
            <p class="mb-1 small opacity-75">Workforce profile and system login — one place to manage identity and access.</p>
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
                <i class="fas fa-pen me-1"></i> Edit profile
            </a>
            <a href="{{ route($routePrefix . '.show', $item) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-eye me-1"></i> View
            </a>
            <a href="{{ route($routePrefix . '.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Directory
            </a>
        </div>
    </header>

    <div class="row g-3">

        {{-- ==================== PROFILE / CONTACT CARD ==================== --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        @if ($photoUrl)
                            <img src="{{ $photoUrl }}" alt="" class="rounded-circle"
                                 style="width:80px;height:80px;object-fit:cover;">
                        @else
                            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center"
                                 style="width:80px;height:80px;font-size:1.8rem;font-weight:bold;">
                                {{ strtoupper(substr($item->name ?? '?', 0, 1)) }}
                            </div>
                        @endif
                        <div>
                            <h5 class="mb-1">{{ $item->name }}</h5>
                            <div class="mb-1">
                                <span class="badge bg-light text-dark border me-1">{{ $item->employee_code }}</span>
                                <span class="badge bg-{{ $roleColor }}">{{ $roleLabel }}</span>
                            </div>
                        </div>
                    </div>

                    <h6 class="text-muted text-uppercase small mb-3">
                        <i class="fas fa-address-card me-1"></i>Contact &amp; placement
                    </h6>

                    @if ($item->branch)
                        <div class="mb-2">
                            <i class="fas fa-sitemap text-muted me-2"></i>{{ $item->branch->branch_name }}
                        </div>
                    @endif
                    @if ($item->phone)
                        <div class="mb-2">
                            <i class="fas fa-phone text-muted me-2"></i>{{ $item->phone }}
                        </div>
                    @endif
                    @if ($item->email)
                        <div class="mb-2">
                            <i class="fas fa-envelope text-muted me-2"></i>{{ $item->email }}
                        </div>
                    @endif
                    @if ($item->joining_date)
                        <div class="mb-2">
                            <i class="fas fa-calendar-day text-muted me-2"></i>
                            Joined {{ $item->joining_date->format('M j, Y') }}
                        </div>
                    @endif
                    @if ($item->address)
                        <div class="mb-2">
                            <i class="fas fa-location-dot text-muted me-2"></i>{{ $item->address }}
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- ==================== SYSTEM LOGIN + SALARY ==================== --}}
        <div class="col-lg-8">

            {{-- System login panel --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white border-bottom fw-semibold d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-user-shield me-2 text-info"></i>System login</span>
                    @if ($user)
                        @if ($user->is_active)
                            <span class="badge bg-success-subtle text-success"><i class="fas fa-check me-1"></i>Active account</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-minus me-1"></i>Inactive account</span>
                        @endif
                    @endif
                </div>
                <div class="card-body">
                    @if (! $user)
                        <div class="text-center py-4">
                            <i class="fas fa-user-plus d-block mb-2" style="font-size:2.4rem;opacity:.4;"></i>
                            <p class="mb-1">This employee does not have a system user account yet.</p>
                            <p class="text-muted small mb-3">User provisioning is wired up in a later phase.</p>
                            <span class="badge bg-secondary">No login</span>
                        </div>
                    @else
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="text-muted small mb-1">Username</div>
                                <span class="badge bg-light text-dark border">{{ $user->username }}</span>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small mb-1">Account status</div>
                                @if ($user->is_active)
                                    <span class="badge bg-success-subtle text-success">Active</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                                @endif
                                @if ($user->isLocked())
                                    <span class="badge bg-danger-subtle text-danger ms-1"><i class="fas fa-lock me-1"></i>Locked</span>
                                @endif
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small mb-1">Last login</div>
                                @if ($user->last_login)
                                    <div>{{ $user->last_login->format('M j, Y g:i A') }}</div>
                                @else
                                    <span class="text-muted">Never logged in</span>
                                @endif
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small mb-1">Last login IP</div>
                                <div>{{ $user->last_login_ip ?: '—' }}</div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small mb-1">Failed login attempts</div>
                                <div>{{ (int) $user->failed_login_count }}</div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small mb-1">Credential version</div>
                                <div>{{ (int) $user->credential_version }}</div>
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small mb-1">Telegram linked</div>
                                @if ($user->telegram_user_id)
                                    <span class="badge bg-info-subtle text-info"><i class="fab fa-telegram me-1"></i>{{ $user->telegram_user_id }}</span>
                                @else
                                    <span class="text-muted">Not linked</span>
                                @endif
                            </div>
                            <div class="col-md-6">
                                <div class="text-muted small mb-1">User created</div>
                                <div>{{ optional($user->created_at)?->format('M j, Y') ?? '—' }}</div>
                            </div>
                        </div>

                        <div class="alert alert-info small mt-3 mb-0">
                            <i class="fas fa-info-circle me-1"></i>
                            <strong>Read-only in Phase 4.</strong>
                            Reset-link, unlock, and permission-management quick actions will be wired up in Phase 11
                            (User Management module).
                        </div>
                    @endif
                </div>
            </div>

            {{-- Salary / advance summary --}}
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom fw-semibold">
                    <i class="fas fa-wallet me-2 text-success"></i>Salary &amp; advance summary
                    <span class="badge bg-light text-secondary border ms-2">Phase 4: read-only</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-sm-6 col-lg-3">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="text-muted small">Base salary</div>
                                <div class="h5 mb-0 mt-1">৳ {{ number_format((float) ($salarySummary['base_salary'] ?? 0), 2) }}</div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="text-muted small">Paid this month</div>
                                <div class="h5 mb-0 mt-1">৳ {{ number_format((float) ($salarySummary['paid_this_month'] ?? 0), 2) }}</div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="text-muted small">Advances outstanding</div>
                                <div class="h5 mb-0 mt-1">৳ {{ number_format((float) ($salarySummary['advances_outstanding'] ?? 0), 2) }}</div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-lg-3">
                            <div class="border rounded-3 p-3 h-100">
                                <div class="text-muted small">Last paid</div>
                                <div class="h5 mb-0 mt-1">{{ $salarySummary['last_paid_date'] ?? '—' }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-secondary small mt-3 mb-0">
                        <i class="fas fa-clock-rotate-left me-1"></i>
                        Full transaction history (payroll disbursements, salary adjustments, advance repayments)
                        will be added in <strong>Phase 9 (Payroll &amp; Advances)</strong>.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection

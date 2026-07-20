@extends('layouts.admin')

@section('content')
<div class="py-3">
    {{-- Welcome header --}}
    <div class="mb-4">
        <h2 class="mb-1">
            <i class="fas fa-tachometer-alt me-2 text-primary"></i>Dashboard
        </h2>
        <p class="text-muted mb-0">
            Welcome back, <strong>{{ session('employee_name', Auth::user()->username) }}</strong>
            — {{ session('role') }} @if (session('branch_name')) · {{ session('branch_name') }} @endif
        </p>
    </div>

    {{-- Stats cards --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small">Customers</div>
                            <div class="h3 mb-0 mt-1">{{ number_format($stats['customers'] ?? 0) }}</div>
                        </div>
                        <i class="fas fa-users fa-2x text-primary opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small">Products</div>
                            <div class="h3 mb-0 mt-1">{{ number_format($stats['products'] ?? 0) }}</div>
                        </div>
                        <i class="fas fa-box fa-2x text-success opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small">Invoices Today</div>
                            <div class="h3 mb-0 mt-1">{{ number_format($stats['invoices_today'] ?? 0) }}</div>
                        </div>
                        <i class="fas fa-file-invoice fa-2x text-warning opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small">Pending Challans</div>
                            <div class="h3 mb-0 mt-1">{{ number_format($stats['pending_challans'] ?? 0) }}</div>
                        </div>
                        <i class="fas fa-truck fa-2x text-info opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Phase 3 notice --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h5 class="card-title">
                <i class="fas fa-info-circle text-info me-2"></i>
                Laravel Migration — Phase 3 Active
            </h5>
            <p class="card-text text-muted">
                This dashboard is served by the new Laravel application.
                The legacy PHP app is still running for all ERP modules.
                Modules will be progressively ported in upcoming phases.
            </p>
            <div class="row g-2">
                <div class="col-auto">
                    <span class="badge bg-success">Auth: Laravel</span>
                </div>
                <div class="col-auto">
                    <span class="badge bg-success">Session: Shared (Redis)</span>
                </div>
                <div class="col-auto">
                    <span class="badge bg-success">DB: PostgreSQL</span>
                </div>
                <div class="col-auto">
                    <span class="badge bg-warning text-dark">Modules: Legacy PHP</span>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

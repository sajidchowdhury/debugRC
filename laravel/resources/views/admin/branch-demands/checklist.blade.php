@extends('layouts.admin')

@section('title', 'Branch Demand Audit Checklist')

@push('css')
<link rel="stylesheet" href="/assets/css/branch-demand.css">
@endpush

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h4 class="mb-1"><i class="fas fa-clipboard-check me-2"></i>Branch Demand — Audit Checklist</h4>
            <p class="text-muted">Phase 8 — Anti-Gaming & Accountability Controls. All health checks run against the current database state.</p>
        </div>
    </div>

    <div class="row">
        @foreach($checklist as $key => $check)
        <div class="col-md-6 col-xl-4 mb-4">
            <div class="card border-{{ $check['status'] === 'pass' ? 'success' : ($check['status'] === 'warning' ? 'warning' : 'danger') }} h-100">
                <div class="card-header bg-{{ $check['status'] === 'pass' ? 'success' : ($check['status'] === 'warning' ? 'warning' : 'danger') }} text-white d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-{{ $check['status'] === 'pass' ? 'check-circle' : ($check['status'] === 'warning' ? 'exclamation-triangle' : 'times-circle') }} me-2"></i>{{ $check['name'] }}</span>
                    <span class="badge bg-light text-{{ $check['status'] === 'pass' ? 'success' : ($check['status'] === 'warning' ? 'warning' : 'danger') }}">
                        {{ strtoupper($check['status']) }}
                    </span>
                </div>
                <div class="card-body">
                    <p class="card-text">{{ $check['message'] }}</p>
                    @if($check['count'] > 0)
                        <p class="text-muted small">Affected records: <strong>{{ $check['count'] }}</strong></p>
                    @endif
                    @if(!empty($check['details']) && is_array($check['details']) && count($check['details']) > 0)
                        <div class="mt-2">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#details-{{ $key }}">
                                Show Details
                            </button>
                            <div class="collapse mt-2" id="details-{{ $key }}">
                                <pre class="bg-light p-2 rounded small" style="max-height: 200px; overflow-y: auto;">{{ json_encode($check['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
    </div>

    <div class="row mt-3">
        <div class="col-12">
            <a href="{{ route('admin.branch-demands.reconcile') }}" class="btn btn-outline-primary">
                <i class="fas fa-balance-scale me-1"></i> Go to Reconciliation
            </a>
            <a href="{{ route('admin.branch-demands.index') }}" class="btn btn-outline-secondary ms-2">
                <i class="fas fa-arrow-left me-1"></i> Back to Demands
            </a>
        </div>
    </div>
</div>
@endsection

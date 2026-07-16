@extends('layouts.admin')

@php
    $passed = collect($checks)->where('status', 'pass')->count();
    $total  = count($checks);
@endphp

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-clipboard-check me-2 text-primary"></i> Sales Audit Checklist</h2>
            <p class="text-muted mb-0 small">Invoice, payment, and dispatch control checks — {{ \Carbon\Carbon::parse($meta['from_date'])->format('d M Y') }} → {{ \Carbon\Carbon::parse($meta['to_date'])->format('d M Y') }}.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
            <button onclick="window.location.reload()" class="btn btn-outline-primary btn-sm">
                <i class="fas fa-rotate me-1"></i> Re-run checks
            </button>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.reports.salesAuditChecklist') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">From date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm"
                           value="{{ old('from_date', request('from_date', $meta['from_date'] ?? '')) }}">
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">To date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm"
                           value="{{ old('to_date', request('to_date', $meta['to_date'] ?? '')) }}">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Summary banner --}}
    <div class="alert {{ $passed === $total ? 'alert-success' : 'alert-warning' }} d-flex justify-content-between align-items-center">
        <span>
            <i class="fas {{ $passed === $total ? 'fa-check-circle' : 'fa-exclamation-triangle' }} me-1"></i>
            <strong>{{ $passed }} of {{ $total }}</strong> checks passed.
        </span>
        <span>
            <span class="badge bg-success me-1">PASS: {{ collect($checks)->where('status', 'pass')->count() }}</span>
            <span class="badge bg-warning text-dark me-1">WARN: {{ collect($checks)->where('status', 'warn')->count() }}</span>
            <span class="badge bg-danger">FAIL: {{ collect($checks)->where('status', 'fail')->count() }}</span>
        </span>
    </div>

    {{-- Checks list --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-2">
            <i class="fas fa-list-check me-1"></i> Control checks
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <th width="80">Status</th>
                        <th>Check</th>
                        <th class="text-end" width="120">Count</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($checks as $check)
                        <tr>
                            <td>
                                @if ($check['status'] === 'pass')
                                    <span class="badge bg-success"><i class="fas fa-check"></i> PASS</span>
                                @elseif ($check['status'] === 'warn')
                                    <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle"></i> WARN</span>
                                @else
                                    <span class="badge bg-danger"><i class="fas fa-times"></i> FAIL</span>
                                @endif
                            </td>
                            <td>{{ $check['label'] }}</td>
                            <td class="text-end fw-semibold {{ $check['count'] > 0 ? 'text-danger' : 'text-success' }}">{{ number_format($check['count']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-muted text-center py-3">No checks available.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

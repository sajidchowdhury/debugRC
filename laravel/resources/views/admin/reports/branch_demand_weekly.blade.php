@extends('layouts.admin')

@php
    $statusBadge = [
        'draft'       => 'secondary',
        'pending'     => 'warning',
        'approved'    => 'info',
        'in_transit'  => 'primary',
        'received'    => 'success',
        'cancelled'   => 'danger',
    ];
@endphp

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-share-nodes me-2 text-primary"></i> Branch Demand — Weekly</h2>
            <p class="text-muted mb-0 small">Inter-branch demand, settlement &amp; floor stock — {{ \Carbon\Carbon::parse($meta['from_date'])->format('d M Y') }} → {{ \Carbon\Carbon::parse($meta['to_date'])->format('d M Y') }}.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.reports.branchDemandWeekly') }}" class="row g-2 align-items-end">
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

    {{-- Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Demand Code</th>
                            <th>Demand Date</th>
                            <th>From Branch</th>
                            <th>To Branch</th>
                            <th class="text-center">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($data as $r)
                            <tr>
                                <td><code>{{ $r->demand_code }}</code></td>
                                <td>{{ \Carbon\Carbon::parse($r->demand_date)->format('d M Y') }}</td>
                                <td><i class="fas fa-building me-1 text-muted"></i>{{ $r->from_branch }}</td>
                                <td><i class="fas fa-arrow-right me-1 text-muted"></i>{{ $r->to_branch }}</td>
                                <td class="text-center">
                                    <span class="badge bg-{{ $statusBadge[$r->status] ?? 'secondary' }}-subtle text-{{ $statusBadge[$r->status] ?? 'secondary' }}">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted text-center py-3">No branch demand records in the selected period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if (method_exists($data, 'hasPages') && $data->hasPages())
            <div class="card-footer bg-white py-2">{{ $data->links() }}</div>
        @endif
    </div>
</div>
@endsection

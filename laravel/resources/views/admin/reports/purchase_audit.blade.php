@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-truck me-2 text-primary"></i> Purchase Audit Checklist</h2>
            <p class="text-muted mb-0 small">GRN, supplier ledger, and purchase posting integrity.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
        </div>
    </div>

    {{-- Filter form (placeholder) --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.reports.purchaseAudit') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">From date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm"
                           value="{{ old('from_date', request('from_date', $meta['from_date'] ?? '')) }}" disabled>
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">To date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm"
                           value="{{ old('to_date', request('to_date', $meta['to_date'] ?? '')) }}" disabled>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-secondary btn-sm w-100" disabled>
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Coming soon notice --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <div class="display-1 text-muted mb-3"><i class="fas fa-tools"></i></div>
            <h3 class="h5 text-muted">Purchase Audit Checklist — coming in Phase 7</h3>
            <p class="text-muted mb-0">
                The full purchase audit checklist will verify GRN-vs-invoice matching, supplier ledger integrity, and
                purchase journal posting accuracy. Implementation is scheduled for Phase 7.
            </p>
        </div>
    </div>
</div>
@endsection

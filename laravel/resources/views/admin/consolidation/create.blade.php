@extends('layouts.admin')

@section('title', 'Run Consolidation — Remote Center ERP')

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('admin.consolidation.index') }}">Consolidation</a></li>
                    <li class="breadcrumb-item active">Run Consolidation</li>
                </ol>
            </nav>
            <h4 class="mb-0"><i class="fas fa-play me-2"></i>Run Consolidation</h4>
            <p class="text-muted">Calculate elimination entries for intercompany balances in a given period.</p>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <form method="POST" action="{{ route('admin.consolidation.store') }}">
                @csrf
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Consolidation Parameters</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label">Run Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="name" class="form-control"
                                       value="{{ old('name', 'Consolidation ' . now()->format('M Y')) }}" required>
                            </div>
                            <div class="col-md-3">
                                <label for="period_from" class="form-label">Period From <span class="text-danger">*</span></label>
                                <input type="date" name="period_from" id="period_from" class="form-control"
                                       value="{{ old('period_from', now()->startOfYear()->format('Y-m-d')) }}" required>
                            </div>
                            <div class="col-md-3">
                                <label for="period_to" class="form-label">Period To <span class="text-danger">*</span></label>
                                <input type="date" name="period_to" id="period_to" class="form-control"
                                       value="{{ old('period_to', now()->format('Y-m-d')) }}" required>
                            </div>
                            <div class="col-md-6">
                                <label for="fiscal_year_id" class="form-label">Fiscal Year</label>
                                <select name="fiscal_year_id" id="fiscal_year_id" class="form-select">
                                    <option value="">— Select Fiscal Year —</option>
                                    @foreach($fiscalYears as $fy)
                                    <option value="{{ $fy->id }}" {{ old('fiscal_year_id') == $fy->id ? 'selected' : '' }}>
                                        {{ $fy->fiscal_year_code }} ({{ $fy->start_date->format('d M Y') }} — {{ $fy->end_date->format('d M Y') }})
                                    </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="company_ids" class="form-label">Companies to Include</label>
                                <select name="company_ids[]" id="company_ids" class="form-select" multiple>
                                    @foreach($companies as $company)
                                    <option value="{{ $company->id }}" {{ $company->is_consolidation_parent ? 'selected' : '' }}>
                                        {{ $company->company_name }} ({{ $company->company_code }})
                                    </option>
                                    @endforeach
                                </select>
                                <div class="form-text">Leave empty to include all companies.</div>
                            </div>
                            <div class="col-12">
                                <label for="notes" class="form-label">Notes</label>
                                <textarea name="notes" id="notes" class="form-control" rows="2"
                                          placeholder="Optional notes about this consolidation run">{{ old('notes') }}</textarea>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-calculator me-1"></i> Calculate Eliminations
                        </button>
                        <a href="{{ route('admin.consolidation.index') }}" class="btn btn-secondary ms-2">Cancel</a>
                    </div>
                </div>
            </form>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Active Elimination Rules</h5>
                </div>
                <div class="card-body">
                    @forelse($rules as $rule)
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                        <div>
                            <div class="fw-bold small">{{ $rule->rule_code }}</div>
                            <div class="text-muted small">{{ $rule->rule_name }}</div>
                        </div>
                        <span class="badge bg-info">{{ $rule->rule_type }}</span>
                    </div>
                    @empty
                    <p class="text-muted small">No active elimination rules. Create rules first.</p>
                    @endforelse
                    <a href="{{ route('admin.consolidation.rules') }}" class="btn btn-sm btn-outline-secondary mt-2 w-100">
                        Manage Rules
                    </a>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-header">
                    <h5 class="mb-0">How It Works</h5>
                </div>
                <div class="card-body">
                    <ol class="mb-0 ps-3">
                        <li class="mb-1">Define elimination rules for intercompany accounts</li>
                        <li class="mb-1">Run consolidation for a period</li>
                        <li class="mb-1">Review the calculated elimination entries</li>
                        <li class="mb-1">Post the consolidation to create elimination journal entries</li>
                        <li class="mb-1">View consolidated financial statements (TB, BS, P&L)</li>
                        <li>Reverse if needed (undo the elimination entries)</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

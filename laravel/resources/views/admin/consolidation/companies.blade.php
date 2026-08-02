@extends('layouts.admin')

@section('title', 'Companies — Remote Center ERP')

@section('content')
<div class="container-fluid py-4">
    {{-- Breadcrumb --}}
    <div class="row mb-3">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('admin.consolidation.index') }}">Consolidation</a></li>
                    <li class="breadcrumb-item active">Companies</li>
                </ol>
            </nav>
            <div class="d-flex justify-content-between align-items-center">
                <h4 class="mb-0"><i class="fas fa-building me-2"></i>Companies</h4>
                <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#createCompanyForm" aria-expanded="false" aria-controls="createCompanyForm">
                    <i class="fas fa-plus me-1"></i> Create New Company
                </button>
            </div>
        </div>
    </div>

    {{-- Create New Company Form (collapsible) --}}
    <div class="collapse mb-4" id="createCompanyForm">
        <div class="card border-primary">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-plus-circle me-1"></i> New Company</h5>
            </div>
            <form method="POST" action="{{ route('admin.consolidation.companies.store') }}">
                @csrf
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="company_code" class="form-label">Company Code <span class="text-danger">*</span></label>
                            <input type="text" name="company_code" id="company_code" class="form-control"
                                   value="{{ old('company_code') }}" placeholder="e.g. RC-HO" required>
                        </div>
                        <div class="col-md-4">
                            <label for="company_name" class="form-label">Company Name <span class="text-danger">*</span></label>
                            <input type="text" name="company_name" id="company_name" class="form-control"
                                   value="{{ old('company_name') }}" placeholder="e.g. Remote Center Holdings" required>
                        </div>
                        <div class="col-md-4">
                            <label for="legal_name" class="form-label">Legal Name</label>
                            <input type="text" name="legal_name" id="legal_name" class="form-control"
                                   value="{{ old('legal_name') }}" placeholder="e.g. Remote Center Holdings Ltd.">
                        </div>
                        <div class="col-md-4">
                            <label for="tax_id" class="form-label">Tax ID</label>
                            <input type="text" name="tax_id" id="tax_id" class="form-control"
                                   value="{{ old('tax_id') }}" placeholder="e.g. BIN-123456789">
                        </div>
                        <div class="col-md-4">
                            <label for="currency_code" class="form-label">Currency <span class="text-danger">*</span></label>
                            <select name="currency_code" id="currency_code" class="form-select" required>
                                <option value="">— Select Currency —</option>
                                <option value="BDT" {{ old('currency_code') === 'BDT' ? 'selected' : '' }}>BDT — Bangladeshi Taka</option>
                                <option value="USD" {{ old('currency_code') === 'USD' ? 'selected' : '' }}>USD — US Dollar</option>
                                <option value="EUR" {{ old('currency_code') === 'EUR' ? 'selected' : '' }}>EUR — Euro</option>
                                <option value="GBP" {{ old('currency_code') === 'GBP' ? 'selected' : '' }}>GBP — British Pound</option>
                                <option value="INR" {{ old('currency_code') === 'INR' ? 'selected' : '' }}>INR — Indian Rupee</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="parent_company_id" class="form-label">Parent Company</label>
                            <select name="parent_company_id" id="parent_company_id" class="form-select">
                                <option value="">— None (Top-level) —</option>
                                @foreach($companies as $company)
                                <option value="{{ $company->id }}" {{ old('parent_company_id') == $company->id ? 'selected' : '' }}>
                                    {{ $company->company_name }} ({{ $company->company_code }})
                                </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="ownership_percentage" class="form-label">Ownership %</label>
                            <input type="number" name="ownership_percentage" id="ownership_percentage" class="form-control"
                                   value="{{ old('ownership_percentage', 100) }}" min="0" max="100" step="0.01">
                        </div>
                        <div class="col-md-4">
                            <label for="registration_number" class="form-label">Registration Number</label>
                            <input type="text" name="registration_number" id="registration_number" class="form-control"
                                   value="{{ old('registration_number') }}" placeholder="e.g. C-123456">
                        </div>
                        <div class="col-md-4">
                            <label for="address" class="form-label">Address</label>
                            <input type="text" name="address" id="address" class="form-control"
                                   value="{{ old('address') }}" placeholder="e.g. 123 Business Ave, Dhaka">
                        </div>
                        <div class="col-md-4">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="text" name="phone" id="phone" class="form-control"
                                   value="{{ old('phone') }}" placeholder="e.g. +880-2-1234567">
                        </div>
                        <div class="col-md-4">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" name="email" id="email" class="form-control"
                                   value="{{ old('email') }}" placeholder="e.g. info@company.com">
                        </div>
                        <div class="col-md-4">
                            <div class="form-check mt-2">
                                <input type="checkbox" name="is_consolidation_parent" value="1" class="form-check-input" id="is_consolidation_parent"
                                       {{ old('is_consolidation_parent') ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_consolidation_parent">Consolidation Parent</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-check mt-2">
                                <input type="checkbox" name="is_active" value="1" class="form-check-input" id="company_is_active"
                                       {{ old('is_active', 1) ? 'checked' : '' }}>
                                <label class="form-check-label" for="company_is_active">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Company
                    </button>
                    <button type="button" class="btn btn-secondary ms-2" data-bs-toggle="collapse" data-bs-target="#createCompanyForm">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Companies Table --}}
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">All Companies</h5>
            <span class="badge bg-secondary">{{ $companies->count() }} companies</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Legal Name</th>
                            <th>Tax ID</th>
                            <th>Currency</th>
                            <th>Parent</th>
                            <th class="text-end">Ownership %</th>
                            <th class="text-center">Branches</th>
                            <th class="text-center">Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($companies as $company)
                        <tr class="{{ $company->is_active ? '' : 'table-secondary opacity-75' }}">
                            <td>
                                <span class="fw-bold">{{ $company->company_code }}</span>
                            </td>
                            <td>{{ $company->company_name }}</td>
                            <td class="text-muted">{{ $company->legal_name ?? '—' }}</td>
                            <td><small>{{ $company->tax_id ?? '—' }}</small></td>
                            <td>
                                <span class="badge bg-info">{{ $company->currency_code }}</span>
                            </td>
                            <td>
                                @if($company->parentCompany)
                                    {{ $company->parentCompany->company_name }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">{{ number_format($company->ownership_percentage, 2) }}%</td>
                            <td class="text-center">
                                <span class="badge bg-secondary">{{ $company->branches()->count() }}</span>
                            </td>
                            <td class="text-center">
                                @if($company->is_active)
                                    <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Active</span>
                                @else
                                    <span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i>Inactive</span>
                                @endif
                                @if($company->is_consolidation_parent)
                                    <span class="badge bg-warning text-dark"><i class="fas fa-crown me-1"></i>Parent</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                No companies defined. Click "Create New Company" to add one.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

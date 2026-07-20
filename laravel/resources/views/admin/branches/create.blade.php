@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0891b2,#06b6d4);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-plus me-2"></i>New branch</h1>
            <p class="mb-0 small opacity-75">Add a location for warehouses, employees, and stock.</p>
        </div>
        <div>
            <a href="{{ route('admin.branches.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-building me-1 text-info"></i> Branch details</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.branches.store') }}">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="branch_code">Branch code <span class="text-danger">*</span></label>
                                <input type="text" id="branch_code" name="branch_code" class="form-control @error('branch_code') is-invalid @enderror"
                                       required placeholder="e.g. BR-DHK-01" value="{{ old('branch_code') }}">
                                @error('branch_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="branch_name">Branch name <span class="text-danger">*</span></label>
                                <input type="text" id="branch_name" name="branch_name" class="form-control @error('branch_name') is-invalid @enderror"
                                       required placeholder="e.g. Dhaka Main Office" value="{{ old('branch_name') }}">
                                @error('branch_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="phone">Phone</label>
                                <input type="text" id="phone" name="phone" class="form-control @error('phone') is-invalid @enderror"
                                       placeholder="+880 …" value="{{ old('phone') }}">
                                @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="email">Email</label>
                                <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                       placeholder="branch@company.com" value="{{ old('email') }}">
                                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="address">Full address</label>
                                <textarea id="address" name="address" class="form-control @error('address') is-invalid @enderror" rows="3"
                                          placeholder="Street, area, city">{{ old('address') }}</textarea>
                                @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="hidden" name="is_active" value="0">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                                           {{ old('is_active', 1) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_active">Active</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-check me-1"></i> Create branch
                            </button>
                            <a href="{{ route('admin.branches.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h3 class="h6"><i class="fas fa-lightbulb me-1 text-warning"></i> Tips</h3>
                    <ul class="small text-muted mb-0 ps-3">
                        <li>Use a clear branch code (e.g. <code>BR-DHK-01</code>) — it appears on challans and reports.</li>
                        <li>Deactivating a branch requires no active warehouses, employees, open invoices, or pending demands.</li>
                        <li>RC_ERP ships with 4 default branches: Head Office, Patuatuli, Nowabpur, Tarabo.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

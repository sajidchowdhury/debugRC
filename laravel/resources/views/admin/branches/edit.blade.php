@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0891b2,#06b6d4);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-pen-to-square me-2"></i>Edit branch</h1>
            <p class="mb-0 small opacity-75"><strong>{{ $item->branch_name }}</strong> · {{ $item->branch_code }}</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.branches.show', $item) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-sitemap me-1"></i> View
            </a>
            <a href="{{ route('admin.branches.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
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
                    <form method="POST" action="{{ route('admin.branches.update', $item) }}">
                        @csrf
                        @method('PUT')
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="branch_code">Branch code <span class="text-danger">*</span></label>
                                <input type="text" id="branch_code" name="branch_code" class="form-control @error('branch_code') is-invalid @enderror"
                                       required value="{{ old('branch_code', $item->branch_code) }}">
                                @error('branch_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="branch_name">Branch name <span class="text-danger">*</span></label>
                                <input type="text" id="branch_name" name="branch_name" class="form-control @error('branch_name') is-invalid @enderror"
                                       required value="{{ old('branch_name', $item->branch_name) }}">
                                @error('branch_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="phone">Phone</label>
                                <input type="text" id="phone" name="phone" class="form-control @error('phone') is-invalid @enderror"
                                       value="{{ old('phone', $item->phone) }}">
                                @error('phone') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="email">Email</label>
                                <input type="email" id="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                       value="{{ old('email', $item->email) }}">
                                @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="address">Full address</label>
                                <textarea id="address" name="address" class="form-control @error('address') is-invalid @enderror" rows="3">{{ old('address', $item->address) }}</textarea>
                                @error('address') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="hidden" name="is_active" value="0">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                                           {{ old('is_active', $item->is_active ? 1 : 0) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_active">Active</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-save me-1"></i> Save changes
                            </button>
                            <a href="{{ route('admin.branches.show', $item) }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h3 class="h6"><i class="fas fa-circle-info me-1 text-info"></i> Snapshot</h3>
                    <dl class="row small mb-0">
                        <dt class="col-5 text-muted">Code</dt>
                        <dd class="col-7">{{ $item->branch_code }}</dd>
                        <dt class="col-5 text-muted">Status</dt>
                        <dd class="col-7">
                            @if ($item->is_active)
                                <span class="badge bg-success-subtle text-success">Active</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            @endif
                        </dd>
                        <dt class="col-5 text-muted">Warehouses</dt>
                        <dd class="col-7">{{ $item->warehouses ? $item->warehouses->count() : 0 }}</dd>
                        <dt class="col-5 text-muted">Employees</dt>
                        <dd class="col-7">{{ $item->employees ? $item->employees->count() : 0 }}</dd>
                        <dt class="col-5 text-muted">Created</dt>
                        <dd class="col-7">{{ optional($item->created_at)->format('Y-m-d') }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

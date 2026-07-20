@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#b45309,#d97706);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-plus me-2"></i>New warehouse</h1>
            <p class="mb-0 small opacity-75">Add a stock location tied to a branch.</p>
        </div>
        <div>
            <a href="{{ route('admin.warehouses.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-warehouse me-1 text-warning"></i> Warehouse details</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.warehouses.store') }}">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="warehouse_code">Warehouse code <span class="text-danger">*</span></label>
                                <input type="text" id="warehouse_code" name="warehouse_code" class="form-control @error('warehouse_code') is-invalid @enderror"
                                       required placeholder="e.g. WH-DHK-01" value="{{ old('warehouse_code') }}">
                                @error('warehouse_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="warehouse_name">Warehouse name <span class="text-danger">*</span></label>
                                <input type="text" id="warehouse_name" name="warehouse_name" class="form-control @error('warehouse_name') is-invalid @enderror"
                                       required placeholder="e.g. Main Godown" value="{{ old('warehouse_name') }}">
                                @error('warehouse_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="branch_id">Branch <span class="text-danger">*</span></label>
                                <select id="branch_id" name="branch_id" class="form-select select2 @error('branch_id') is-invalid @enderror" required>
                                    <option value="">Select branch</option>
                                    @foreach ($branches as $branch)
                                        <option value="{{ $branch->id }}"
                                            {{ (int) old('branch_id') === (int) $branch->id ? 'selected' : '' }}>
                                            {{ $branch->branch_code }} — {{ $branch->branch_name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="location">Location</label>
                                <textarea id="location" name="location" class="form-control @error('location') is-invalid @enderror" rows="3"
                                          placeholder="Street, area, city">{{ old('location') }}</textarea>
                                @error('location') <div class="invalid-feedback">{{ $message }}</div> @enderror
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
                                <i class="fas fa-check me-1"></i> Create warehouse
                            </button>
                            <a href="{{ route('admin.warehouses.index') }}" class="btn btn-outline-secondary">Cancel</a>
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
                        <li>Each warehouse belongs to exactly one branch.</li>
                        <li>Stock must be moved or adjusted to zero before a warehouse can be deactivated.</li>
                        <li>Warehouse codes appear on godown lists, challans, and stock transfer docs.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function() {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });
});
</script>
@endpush
@endsection

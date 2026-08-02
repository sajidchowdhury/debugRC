@extends('layouts.admin')

@section('title', $title)

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-pen me-2"></i>Edit Asset {{ $asset->asset_code }}</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.fixed-assets.index') }}">Fixed Assets</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.fixed-assets.show', $asset) }}">{{ $asset->asset_code }}</a></li>
                    <li class="breadcrumb-item active">Edit</li>
                </ol>
            </nav>
        </div>
        <a href="{{ route('admin.fixed-assets.show', $asset) }}" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Cancel
        </a>
    </div>

    <form method="POST" action="{{ route('admin.fixed-assets.update', $asset) }}">
        @csrf @method('PUT')
        <div class="row">
            {{-- Asset Details --}}
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Asset Details</h6></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Description <span class="text-danger">*</span></label>
                                <input type="text" name="description" class="form-control" value="{{ old('description', $asset->description) }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Category <span class="text-danger">*</span></label>
                                <select name="category" class="form-select" required>
                                    @foreach (\App\Models\FixedAsset::categoryOptions() as $key => $label)
                                    <option value="{{ $key }}" {{ old('category', $asset->category) === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Acquisition Date <span class="text-danger">*</span></label>
                                <input type="date" name="acquisition_date" class="form-control" value="{{ old('acquisition_date', $asset->acquisition_date->format('Y-m-d')) }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Acquisition Cost <span class="text-danger">*</span></label>
                                <input type="number" name="acquisition_cost" class="form-control" value="{{ old('acquisition_cost', $asset->acquisition_cost) }}" step="0.01" min="0.01" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Salvage Value</label>
                                <input type="number" name="salvage_value" class="form-control" value="{{ old('salvage_value', $asset->salvage_value) }}" step="0.01" min="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Serial Number</label>
                                <input type="text" name="serial_number" class="form-control" value="{{ old('serial_number', $asset->serial_number) }}" maxlength="100">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Location</label>
                                <input type="text" name="location" class="form-control" value="{{ old('location', $asset->location) }}" maxlength="255">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Warranty Expiry</label>
                                <input type="text" name="warranty_expiry" class="form-control" value="{{ old('warranty_expiry', $asset->warranty_expiry) }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="2">{{ old('notes', $asset->notes) }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Depreciation & Ledger --}}
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>Depreciation</h6></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Method <span class="text-danger">*</span></label>
                                <select name="depreciation_method" class="form-select" id="depMethod" required>
                                    @foreach (\App\Models\FixedAsset::methodOptions() as $key => $label)
                                    <option value="{{ $key }}" {{ old('depreciation_method', $asset->depreciation_method) === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Useful Life (months) <span class="text-danger">*</span></label>
                                <input type="number" name="useful_life_months" class="form-control" value="{{ old('useful_life_months', $asset->useful_life_months) }}" min="1" required>
                            </div>
                            <div class="col-md-6" id="dbRateGroup">
                                <label class="form-label">DB Rate (%)</label>
                                <input type="number" name="declining_balance_rate" class="form-control" value="{{ old('declining_balance_rate', $asset->declining_balance_rate) }}" step="0.01" min="1" max="100">
                            </div>
                            <div class="col-md-6" id="unitsGroup" style="display:none;">
                                <label class="form-label">Est. Total Units</label>
                                <input type="number" name="total_estimated_units" class="form-control" value="{{ old('total_estimated_units', $asset->total_estimated_units) }}" step="0.01" min="0">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-book me-2"></i>Ledger Mapping</h6></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Asset Account <span class="text-danger">*</span></label>
                                <select name="asset_ledger_id" class="form-select" required>
                                    @foreach ($assetLedgers as $ledger)
                                    <option value="{{ $ledger->id }}" {{ old('asset_ledger_id', $asset->asset_ledger_id) == $ledger->id ? 'selected' : '' }}>
                                        {{ $ledger->ledger_code }} — {{ $ledger->ledger_name }}
                                    </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Accum. Depreciation Account <span class="text-danger">*</span></label>
                                <select name="dep_ledger_id" class="form-select" required>
                                    @foreach ($depLedgers as $ledger)
                                    <option value="{{ $ledger->id }}" {{ old('dep_ledger_id', $asset->dep_ledger_id) == $ledger->id ? 'selected' : '' }}>
                                        {{ $ledger->ledger_code }} — {{ $ledger->ledger_name }}
                                    </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Depreciation Expense Account</label>
                                <select name="dep_expense_ledger_id" class="form-select">
                                    <option value="">Auto-resolve (L-0903)</option>
                                    @foreach ($expenseLedgers as $ledger)
                                    <option value="{{ $ledger->id }}" {{ old('dep_expense_ledger_id', $asset->dep_expense_ledger_id) == $ledger->id ? 'selected' : '' }}>
                                        {{ $ledger->ledger_code }} — {{ $ledger->ledger_name }}
                                    </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Branch <span class="text-danger">*</span></label>
                                <select name="branch_id" class="form-select" required>
                                    @foreach ($branches as $branch)
                                    <option value="{{ $branch->id }}" {{ old('branch_id', $asset->branch_id) == $branch->id ? 'selected' : '' }}>{{ $branch->branch_name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Update Asset
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
document.getElementById('depMethod').addEventListener('change', function() {
    const method = this.value;
    document.getElementById('dbRateGroup').style.display = method === 'declining_balance' ? '' : 'none';
    document.getElementById('unitsGroup').style.display = method === 'units_of_production' ? '' : 'none';
});
document.getElementById('depMethod').dispatchEvent(new Event('change'));
</script>
@endpush
@endsection

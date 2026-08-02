@extends('layouts.admin')

@section('title', $title)

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-building me-2"></i>Fixed Asset Register</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item">Accounting</li>
                    <li class="breadcrumb-item active">Fixed Assets</li>
                </ol>
            </nav>
        </div>
        <a href="{{ route('admin.fixed-assets.create') }}" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i> Register Asset
        </a>
    </div>

    {{-- Summary Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-muted small">Total Acquisition Cost</div>
                    <h4 class="mb-0 text-primary">৳ {{ number_format($totalCost, 2) }}</h4>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-muted small">Accumulated Depreciation</div>
                    <h4 class="mb-0 text-warning">৳ {{ number_format($totalDep, 2) }}</h4>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-muted small">Net Book Value</div>
                    <h4 class="mb-0 text-success">৳ {{ number_format($totalNBV, 2) }}</h4>
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.fixed-assets.index') }}" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="disposed" {{ request('status') === 'disposed' ? 'selected' : '' }}>Disposed</option>
                        <option value="fully_depreciated" {{ request('status') === 'fully_depreciated' ? 'selected' : '' }}>Fully Depreciated</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Category</label>
                    <select name="category" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach (\App\Models\FixedAsset::categoryOptions() as $key => $label)
                        <option value="{{ $key }}" {{ request('category') === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Branch</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}" {{ request('branch_id') == $branch->id ? 'selected' : '' }}>{{ $branch->branch_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Asset code, description, serial..." value="{{ request('search') }}">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-sm btn-outline-primary me-1"><i class="fas fa-search me-1"></i>Filter</button>
                    <a href="{{ route('admin.fixed-assets.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Asset Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Asset Code</th>
                            <th>Description</th>
                            <th>Category</th>
                            <th>Acquisition Date</th>
                            <th class="text-end">Cost</th>
                            <th class="text-end">Accum. Dep.</th>
                            <th class="text-end">Net Book Value</th>
                            <th>Status</th>
                            <th>Branch</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($assets as $asset)
                        <tr>
                            <td>
                                <a href="{{ route('admin.fixed-assets.show', $asset) }}" class="fw-semibold text-decoration-none">
                                    {{ $asset->asset_code }}
                                </a>
                            </td>
                            <td>{{ Str::limit($asset->description, 40) }}</td>
                            <td><span class="badge bg-light text-dark">{{ $asset->getCategoryLabel() }}</span></td>
                            <td>{{ $asset->acquisition_date->format('d M Y') }}</td>
                            <td class="text-end">৳ {{ number_format($asset->acquisition_cost, 2) }}</td>
                            <td class="text-end">৳ {{ number_format($asset->accumulated_depreciation, 2) }}</td>
                            <td class="text-end fw-semibold">৳ {{ number_format($asset->net_book_value, 2) }}</td>
                            <td>{!! $asset->getStatusBadge() !!}</td>
                            <td>{{ $asset->branch?->branch_name ?? '-' }}</td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm">
                                    <a href="{{ route('admin.fixed-assets.show', $asset) }}" class="btn btn-outline-primary" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    @if (!$asset->isDisposed())
                                    <a href="{{ route('admin.fixed-assets.edit', $asset) }}" class="btn btn-outline-secondary" title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    @endif
                                    @if ($asset->canBeDisposed())
                                    <a href="{{ route('admin.fixed-assets.dispose-form', $asset) }}" class="btn btn-outline-danger" title="Dispose">
                                        <i class="fas fa-hand-holding-usd"></i>
                                    </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                <i class="fas fa-building fa-2x mb-2 d-block"></i>
                                No fixed assets registered yet.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            {{ $assets->withQueryString()->links() }}
        </div>
    </div>
</div>
@endsection

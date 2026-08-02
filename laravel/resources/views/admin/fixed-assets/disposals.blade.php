@extends('layouts.admin')

@section('title', $title)

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-hand-holding-usd me-2"></i>Asset Disposals</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.fixed-assets.index') }}">Fixed Assets</a></li>
                    <li class="breadcrumb-item active">Disposals</li>
                </ol>
            </nav>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-muted small">Total Proceeds</div>
                    <h5 class="mb-0 text-success">৳ {{ number_format($totalProceeds, 2) }}</h5>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-muted small">Total Gains</div>
                    <h5 class="mb-0 text-primary">৳ {{ number_format($totalGains, 2) }}</h5>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-muted small">Total Losses</div>
                    <h5 class="mb-0 text-danger">৳ {{ number_format($totalLosses, 2) }}</h5>
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small">Type</label>
                    <select name="disposal_type" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach (\App\Models\AssetDisposal::disposalTypeOptions() as $key => $label)
                        <option value="{{ $key }}" {{ request('disposal_type') === $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">From</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="{{ request('date_from') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">To</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="{{ request('date_to') }}">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-search me-1"></i>Filter</button>
                    <a href="{{ route('admin.fixed-assets.disposals') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Disposals Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Disposal Code</th>
                            <th>Asset</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th class="text-end">Proceeds</th>
                            <th class="text-end">Book Value</th>
                            <th class="text-end">Accum. Dep.</th>
                            <th>Gain/Loss</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($disposals as $disposal)
                        <tr>
                            <td>
                                <a href="{{ route('admin.fixed-assets.show-disposal', $disposal) }}" class="fw-semibold">
                                    {{ $disposal->disposal_code }}
                                </a>
                            </td>
                            <td>
                                <a href="{{ route('admin.fixed-assets.show', $disposal->fixedAsset) }}">{{ $disposal->fixedAsset?->asset_code }}</a>
                                <div class="small text-muted">{{ Str::limit($disposal->fixedAsset?->description ?? '', 30) }}</div>
                            </td>
                            <td>{{ $disposal->getDisposalTypeLabel() }}</td>
                            <td>{{ $disposal->disposal_date->format('d M Y') }}</td>
                            <td class="text-end">৳ {{ number_format($disposal->disposal_proceeds, 2) }}</td>
                            <td class="text-end">৳ {{ number_format($disposal->book_value_at_disposal, 2) }}</td>
                            <td class="text-end">৳ {{ number_format($disposal->accumulated_depreciation_at_disposal, 2) }}</td>
                            <td>
                                @if ($disposal->gain_loss_type !== 'none')
                                    {!! $disposal->getGainLossBadge() !!}
                                    <span class="fw-semibold {{ $disposal->isGain() ? 'text-success' : 'text-danger' }}">
                                        ৳ {{ number_format($disposal->gain_loss_amount, 2) }}
                                    </span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <a href="{{ route('admin.fixed-assets.show-disposal', $disposal) }}" class="btn btn-sm btn-outline-primary" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="9" class="text-center text-muted py-4">No asset disposals found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            {{ $disposals->withQueryString()->links() }}
        </div>
    </div>
</div>
@endsection

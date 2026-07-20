@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white" style="background:linear-gradient(135deg,#0f766e,#14b8a6);">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-chart-line me-2"></i>Price history</h1>
            <p class="mb-0 opacity-75">
                {{ $product->product_name }}
                <span class="badge bg-light text-dark ms-1">{{ $product->product_code }}</span>
            </p>
        </div>
        <div class="d-flex flex-wrap gap-1">
            <a href="{{ route($routePrefix . '.edit', $product->id) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-pen me-1"></i> Edit product
            </a>
            <a href="{{ route($routePrefix . '.show', $product->id) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-eye me-1"></i> Details
            </a>
            <a href="{{ route($routePrefix . '.index') }}" class="btn btn-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Catalog
            </a>
        </div>
    </header>

    @if ($currentPrice)
    <div class="card border-0 shadow-sm mb-3" style="background:linear-gradient(135deg,#10b981,#059669);">
        <div class="card-body text-white d-flex justify-content-between align-items-center">
            <div>
                <div class="small opacity-75">Current selling range</div>
                <div class="fs-3 fw-bold">Tk {{ number_format((float) $currentPrice->min_rate, 2) }} – {{ number_format((float) $currentPrice->max_rate, 2) }}</div>
                <div class="small">Suggested default: <strong>Tk {{ number_format((float) $currentPrice->default_rate, 2) }}</strong></div>
            </div>
            <i class="fas fa-tag fa-3x opacity-50"></i>
        </div>
    </div>
    @else
    <div class="alert alert-warning border-0 rounded-3 mb-3">
        <i class="fas fa-triangle-exclamation me-1"></i> No selling price set yet — add a min / max / default range below.
    </div>
    @endif

    {{-- Add price form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h6 class="text-muted text-uppercase border-bottom pb-2 mb-3">
                <i class="fas fa-plus-circle me-1"></i> Add new price range
            </h6>
            <form method="POST" action="{{ route($routePrefix . '.addPrice', $product->id) }}">
                @csrf
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-semibold">Min rate (Tk) <span class="text-danger">*</span></label>
                        <input type="number" name="min_rate" class="form-control @error('min_rate') is-invalid @enderror"
                               step="0.01" min="0.01" required placeholder="120.00" value="{{ old('min_rate') }}">
                        @error('min_rate') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-semibold">Max rate (Tk) <span class="text-danger">*</span></label>
                        <input type="number" name="max_rate" class="form-control @error('max_rate') is-invalid @enderror"
                               step="0.01" min="0.01" required placeholder="130.00" value="{{ old('max_rate') }}">
                        @error('max_rate') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-semibold">Default / suggested (Tk) <span class="text-danger">*</span></label>
                        <input type="number" name="default_rate" class="form-control @error('default_rate') is-invalid @enderror"
                               step="0.01" min="0.01" required placeholder="125.00" value="{{ old('default_rate') }}">
                        @error('default_rate') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label small fw-semibold">Effective from</label>
                        <input type="date" name="effective_from" class="form-control" value="{{ old('effective_from', now()->toDateString()) }}">
                        <div class="form-text">Defaults to today.</div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Save range</button>
                        <a href="{{ route($routePrefix . '.show', $product->id) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                    <div class="col-12">
                        <p class="small text-muted mb-0">
                            <i class="fas fa-info-circle me-1"></i>
                            Rule: <strong>min ≤ default ≤ max</strong>. Becomes active from the effective date. The previous current range will be closed out automatically.
                        </p>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- History table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h6 class="mb-0"><i class="fas fa-history me-1"></i> Change log <span class="text-muted fw-normal small">(newest first)</span></h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Effective from</th>
                            <th>Effective to</th>
                            <th class="text-end">Min</th>
                            <th class="text-end">Max</th>
                            <th class="text-end">Default</th>
                            <th>Recorded</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($history as $i => $ph)
                            @php $isCurrent = $i === 0 && $ph->effective_to === null; @endphp
                            <tr class="{{ $isCurrent ? 'table-success' : '' }}">
                                <td>
                                    <strong>{{ $ph->effective_from?->format('d M Y') }}</strong>
                                    @if ($isCurrent)
                                        <span class="badge bg-success-subtle text-success ms-1"><span class="dot"></span> Current</span>
                                    @endif
                                </td>
                                <td>@if ($ph->effective_to) {{ $ph->effective_to->format('d M Y') }} @else <span class="text-muted">present</span> @endif</td>
                                <td class="text-end">Tk {{ number_format((float) $ph->min_rate, 2) }}</td>
                                <td class="text-end">Tk {{ number_format((float) $ph->max_rate, 2) }}</td>
                                <td class="text-end"><strong>Tk {{ number_format((float) $ph->default_rate, 2) }}</strong></td>
                                <td><small class="text-muted">{{ $ph->created_at?->format('d M Y h:i A') }}</small></td>
                                <td class="text-center">
                                    <form method="POST" action="{{ route($routePrefix . '.deletePrice', [$product->id, $ph->id]) }}"
                                          class="d-inline" onsubmit="return confirm('Delete this price entry?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No price history yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white small text-muted">
            <i class="fas fa-info-circle me-1"></i>
            Top row is the active range on the catalog. Deleting a price entry cannot be undone.
        </div>
    </div>
</div>
@endsection

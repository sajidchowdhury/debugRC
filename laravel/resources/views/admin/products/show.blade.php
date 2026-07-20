@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white" style="background:linear-gradient(135deg,#0f766e,#14b8a6);">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-box-open me-2"></i>{{ $item->product_name }}</h1>
            <p class="mb-0 opacity-75">
                <span class="badge bg-light text-dark">{{ $item->product_code }}</span>
                @if ($item->trashed())
                    <span class="badge bg-warning text-dark ms-1"><i class="fas fa-moon me-1"></i> Inactive</span>
                @elseif ($item->is_active)
                    <span class="badge bg-light text-success ms-1"><i class="fas fa-circle-check me-1"></i> Active</span>
                @else
                    <span class="badge bg-light text-secondary ms-1"><i class="fas fa-circle me-1"></i> Inactive</span>
                @endif
            </p>
        </div>
        <div class="d-flex flex-wrap gap-1">
            @if ($item->trashed())
                <form method="POST" action="{{ route($routePrefix . '.restore', $item->id) }}" class="d-inline" onsubmit="return confirm('Restore this product?')">
                    @csrf
                    <button type="submit" class="btn btn-light btn-sm">
                        <i class="fas fa-rotate-left me-1"></i> Restore
                    </button>
                </form>
            @else
                <a href="{{ route($routePrefix . '.edit', $item->id) }}" class="btn btn-light btn-sm">
                    <i class="fas fa-pen me-1"></i> Edit
                </a>
            @endif
            <a href="{{ route($routePrefix . '.priceHistory', $item->id) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-tag me-1"></i> Price history
            </a>
            <a href="{{ route($routePrefix . '.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </header>

    <div class="row g-3">
        {{-- Details card --}}
        <div class="col-12 col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="row g-3">
                        {{-- Image --}}
                        <div class="col-12 col-md-4 text-center">
                            @if ($item->product_image)
                                <img src="{{ asset('storage/' . $item->product_image) }}" class="img-fluid rounded shadow-sm" style="max-height:200px;" alt="">
                            @else
                                <div class="rounded bg-light d-flex align-items-center justify-content-center" style="height:200px;">
                                    <i class="fas fa-image fa-3x text-muted"></i>
                                </div>
                            @endif
                        </div>

                        {{-- Identity --}}
                        <div class="col-12 col-md-8">
                            <h6 class="text-uppercase text-muted border-bottom pb-2 mb-3">Identity</h6>
                            <dl class="row mb-0 small">
                                <dt class="col-5 text-muted">Product code</dt>
                                <dd class="col-7 fw-semibold">{{ $item->product_code }}</dd>

                                <dt class="col-5 text-muted">Product name</dt>
                                <dd class="col-7 fw-semibold">{{ $item->product_name }}</dd>

                                <dt class="col-5 text-muted">Category</dt>
                                <dd class="col-7">
                                    @if ($item->category)
                                        <span class="badge bg-primary-subtle text-primary"><i class="fas fa-tag me-1"></i>{{ $item->category->category_name }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </dd>

                                <dt class="col-5 text-muted">Group</dt>
                                <dd class="col-7">
                                    @if ($item->group)
                                        <span class="badge bg-purple-subtle text-purple"><i class="fas fa-globe me-1"></i>{{ $item->group->group_name }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </dd>

                                <dt class="col-5 text-muted">Unit</dt>
                                <dd class="col-7 fw-semibold">{{ $item->unit }}</dd>

                                <dt class="col-5 text-muted">Condition</dt>
                                <dd class="col-7">
                                    @if ($item->condition_state)
                                        <span class="badge {{ $item->condition_state === 'Good' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }}">{{ $item->condition_state }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </dd>
                            </dl>
                        </div>
                    </div>

                    <hr class="my-4">

                    <h6 class="text-uppercase text-muted border-bottom pb-2 mb-3">Pricing &amp; stock</h6>
                    <div class="row g-3 mb-2">
                        <div class="col-6 col-md-3">
                            <div class="border rounded p-2 text-center h-100">
                                <div class="text-muted small">Purchase rate</div>
                                <div class="fs-5 fw-bold text-info">Tk {{ number_format((float) $item->purchase_rate, 2) }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="border rounded p-2 text-center h-100">
                                <div class="text-muted small">Sales rate</div>
                                <div class="fs-5 fw-bold text-success">Tk {{ number_format((float) $item->sales_rate, 2) }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="border rounded p-2 text-center h-100">
                                <div class="text-muted small">Min stock</div>
                                <div class="fs-5 fw-bold">{{ number_format((float) $item->min_stock, 2) }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="border rounded p-2 text-center h-100">
                                <div class="text-muted small">Max stock</div>
                                <div class="fs-5 fw-bold">{{ number_format((float) $item->max_stock, 2) }}</div>
                            </div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="border rounded p-2 text-center h-100">
                                <div class="text-muted small">Reorder</div>
                                <div class="fs-5 fw-bold text-warning">{{ number_format((float) $item->reorder_level, 2) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Price history table --}}
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="fas fa-history me-1"></i> Price history</h6>
                    <a href="{{ route($routePrefix . '.priceHistory', $item->id) }}" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-plus me-1"></i> Manage prices
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Effective from</th>
                                    <th>Effective to</th>
                                    <th class="text-end">Min</th>
                                    <th class="text-end">Max</th>
                                    <th class="text-end">Default</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($item->priceHistory as $ph)
                                    <tr>
                                        <td>{{ $ph->effective_from?->format('d M Y') }}</td>
                                        <td>@if ($ph->effective_to) {{ $ph->effective_to->format('d M Y') }} @else <span class="text-muted">present</span> @endif</td>
                                        <td class="text-end">Tk {{ number_format((float) $ph->min_rate, 2) }}</td>
                                        <td class="text-end">Tk {{ number_format((float) $ph->max_rate, 2) }}</td>
                                        <td class="text-end"><strong>Tk {{ number_format((float) $ph->default_rate, 2) }}</strong></td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">No price history yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Aside --}}
        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="text-uppercase text-muted mb-3">Metadata</h6>
                    <dl class="row small mb-0">
                        <dt class="col-5 text-muted">Created</dt>
                        <dd class="col-7">{{ $item->created_at?->format('d M Y h:i A') }}</dd>

                        <dt class="col-5 text-muted">Updated</dt>
                        <dd class="col-7">{{ $item->updated_at?->format('d M Y h:i A') }}</dd>

                        @if ($item->deleted_at)
                        <dt class="col-5 text-muted">Deactivated</dt>
                        <dd class="col-7 text-danger">{{ $item->deleted_at?->format('d M Y h:i A') }}</dd>
                        @endif
                    </dl>

                    @if ($item->priceHistory->isNotEmpty())
                        @php $cp = $item->currentPrice(); @endphp
                        @if ($cp)
                        <hr>
                        <div class="text-center">
                            <div class="text-muted small">Current effective range</div>
                            <div class="fs-4 fw-bold text-success">Tk {{ number_format((float) $cp->min_rate, 2) }} – {{ number_format((float) $cp->max_rate, 2) }}</div>
                            <div class="text-muted small">Default: Tk {{ number_format((float) $cp->default_rate, 2) }}</div>
                        </div>
                        @endif
                    @endif

                    <hr>
                    <div class="d-grid gap-2">
                        <a href="{{ route($routePrefix . '.edit', $item->id) }}" class="btn btn-sm btn-primary">
                            <i class="fas fa-pen me-1"></i> Edit product
                        </a>
                        <a href="{{ route($routePrefix . '.priceHistory', $item->id) }}" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-chart-line me-1"></i> Price history
                        </a>
                        <a href="{{ route($routePrefix . '.index') }}" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Back to catalog
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@extends('layouts.admin')

@section('content')
<div class="container-fluid py-4">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">
                <i class="fas fa-triangle-exclamation text-warning me-2"></i>Avg-Cost Drift
            </h2>
            <p class="text-muted mb-0">
                Replay verification results — investigate each drift before Phase 6.2 sign-off.
            </p>
        </div>
        <div>
            <a href="{{ route('admin.stock.transactions') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to Stock
            </a>
        </div>
    </div>

    {{-- Info banner --}}
    <div class="alert alert-info">
        <i class="fas fa-info-circle me-2"></i>
        <strong>How to use this page:</strong>
        Run <code>php artisan stock:replay-verify</code> on the VPS to populate this table.
        Each row shows a (warehouse, product) where the replay's computed qty or avg_cost
        diverges from the live <code>warehouse_stock</code>. Investigate each, add notes,
        and mark as <strong>Investigated</strong> or <strong>Resolved</strong>.
        Phase 6.2 sign-off requires <strong>zero open drift rows</strong>.
    </div>

    {{-- Stats cards --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small">Total drift rows</div>
                            <div class="h3 mb-0 mt-1">{{ number_format($stats['total']) }}</div>
                        </div>
                        <i class="fas fa-database fa-2x text-secondary opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small">Open</div>
                            <div class="h3 mb-0 mt-1 text-danger">{{ number_format($stats['open']) }}</div>
                        </div>
                        <i class="fas fa-circle-exclamation fa-2x text-danger opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small">Investigated</div>
                            <div class="h3 mb-0 mt-1 text-warning">{{ number_format($stats['investigated']) }}</div>
                        </div>
                        <i class="fas fa-magnifying-glass fa-2x text-warning opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small">Resolved</div>
                            <div class="h3 mb-0 mt-1 text-success">{{ number_format($stats['resolved']) }}</div>
                        </div>
                        <i class="fas fa-circle-check fa-2x text-success opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Sign-off banner --}}
    @if ($stats['open'] == 0 && $stats['total'] > 0)
        <div class="alert alert-success">
            <i class="fas fa-circle-check me-2"></i>
            <strong>All drift rows resolved!</strong> Phase 6.2 replay verification is ready for sign-off.
        </div>
    @elseif ($stats['open'] > 0)
        <div class="alert alert-danger">
            <i class="fas fa-circle-exclamation me-2"></i>
            <strong>{{ $stats['open'] }} open drift row(s)</strong> must be investigated + resolved before Phase 6.2 sign-off.
        </div>
    @else
        <div class="alert alert-secondary">
            <i class="fas fa-clock me-2"></i>
            No replay has been run yet. Run <code>php artisan stock:replay-verify</code> on the VPS to populate this table.
        </div>
    @endif

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.stock.drift') }}" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label small mb-0">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="open" @selected(request('status') === 'open')>Open</option>
                        <option value="investigated" @selected(request('status') === 'investigated')>Investigated</option>
                        <option value="resolved" @selected(request('status') === 'resolved')>Resolved</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Warehouse</label>
                    <select name="warehouse_id" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach ($warehouses as $wh)
                            <option value="{{ $wh->id }}" @selected((string) request('warehouse_id') === (string) $wh->id)>
                                {{ $wh->branch->branch_name ?? '' }} — {{ $wh->warehouse_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <a href="{{ route('admin.stock.drift') }}" class="btn btn-outline-secondary btn-sm">Clear</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Drift table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white">
            <h5 class="mb-0"><i class="fas fa-table me-2"></i>Drift Rows ({{ $drifts->total() }} total)</h5>
        </div>
        <div class="card-body p-0">
            @if ($drifts->isEmpty())
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-inbox fa-3x mb-3 opacity-25"></i>
                    <p>No drift rows found. Run <code>php artisan stock:replay-verify</code> to populate.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Warehouse</th>
                                <th>Product</th>
                                <th class="text-end">Live Qty</th>
                                <th class="text-end">Shadow Qty</th>
                                <th class="text-end">Qty Drift</th>
                                <th class="text-end">Live Avg</th>
                                <th class="text-end">Shadow Avg</th>
                                <th class="text-end">Cost Drift</th>
                                <th>Last TX</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($drifts as $d)
                                <tr>
                                    <td>{{ $d->id }}</td>
                                    <td>
                                        <small class="text-muted">{{ $d->branch_name ?? '—' }}</small><br>
                                        {{ $d->warehouse_name ?? 'WH#' . $d->warehouse_id }}
                                    </td>
                                    <td>
                                        <small class="text-muted">{{ $d->product_code ?? '—' }}</small><br>
                                        {{ $d->product_name ?? 'Prod#' . $d->product_id }}
                                    </td>
                                    <td class="text-end">{{ number_format($d->live_qty, 4) }}</td>
                                    <td class="text-end">{{ number_format($d->shadow_qty, 4) }}</td>
                                    <td class="text-end text-danger fw-bold">{{ number_format($d->qty_drift, 4) }}</td>
                                    <td class="text-end">{{ number_format($d->live_avg_cost, 2) }}</td>
                                    <td class="text-end">{{ number_format($d->shadow_avg_cost, 2) }}</td>
                                    <td class="text-end text-danger fw-bold">{{ number_format($d->cost_drift, 2) }}</td>
                                    <td>
                                        @if ($d->last_transaction_id)
                                            <a href="{{ route('admin.stock.show', $d->last_transaction_id) }}" class="text-decoration-none">
                                                #{{ $d->last_transaction_id }}
                                            </a><br>
                                            <small class="text-muted">{{ $referenceTypeLabels[$d->last_reference_type] ?? $d->last_reference_type }} #{{ $d->last_reference_id }}</small>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>
                                        @switch($d->status)
                                            @case('open')
                                                <span class="badge bg-danger">Open</span>
                                                @break
                                            @case('investigated')
                                                <span class="badge bg-warning text-dark">Investigated</span>
                                                @break
                                            @case('resolved')
                                                <span class="badge bg-success">Resolved</span>
                                                @break
                                        @endswitch
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                                data-bs-target="#driftModal{{ $d->id }}">
                                            <i class="fas fa-pen"></i>
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
        @if ($drifts->hasPages())
            <div class="card-footer bg-white">
                {{ $drifts->links() }}
            </div>
        @endif
    </div>

    {{-- Investigation notes (if any drift has notes) --}}
    @if ($drifts->contains(fn($d) => !empty($d->investigation_notes)))
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-clipboard me-2"></i>Investigation Notes</h5>
            </div>
            <div class="card-body">
                @foreach ($drifts as $d)
                    @if (!empty($d->investigation_notes))
                        <div class="mb-3">
                            <strong>Drift #{{ $d->id }}</strong>
                            (WH {{ $d->warehouse_name ?? $d->warehouse_id }}, Prod {{ $d->product_code ?? $d->product_id }}):
                            <br>
                            <span class="text-muted">{{ $d->investigation_notes }}</span>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif
</div>

{{-- Modals for updating drift status --}}
@foreach ($drifts as $d)
    <div class="modal fade" id="driftModal{{ $d->id }}" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('admin.stock.drift.update', $d->id) }}">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-triangle-exclamation text-warning me-2"></i>
                            Drift #{{ $d->id }}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Warehouse / Product</label>
                            <div class="form-control-plaintext">
                                {{ $d->warehouse_name ?? 'WH#' . $d->warehouse_id }} —
                                {{ $d->product_code ?? '' }} {{ $d->product_name ?? 'Prod#' . $d->product_id }}
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-6">
                                <label class="form-label">Live Qty</label>
                                <div class="form-control-plaintext">{{ number_format($d->live_qty, 4) }}</div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Shadow Qty</label>
                                <div class="form-control-plaintext">{{ number_format($d->shadow_qty, 4) }}</div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-6">
                                <label class="form-label">Live Avg Cost</label>
                                <div class="form-control-plaintext">{{ number_format($d->live_avg_cost, 2) }}</div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Shadow Avg Cost</label>
                                <div class="form-control-plaintext">{{ number_format($d->shadow_avg_cost, 2) }}</div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" required>
                                <option value="open" @selected($d->status === 'open')>Open</option>
                                <option value="investigated" @selected($d->status === 'investigated')>Investigated</option>
                                <option value="resolved" @selected($d->status === 'resolved')>Resolved</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Investigation Notes</label>
                            <textarea name="investigation_notes" class="form-control" rows="4"
                                      placeholder="What caused this drift? What was the fix?">{{ $d->investigation_notes }}</textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Update
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endforeach
@endsection

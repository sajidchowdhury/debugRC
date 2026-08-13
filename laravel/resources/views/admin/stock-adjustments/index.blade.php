@extends('layouts.admin')

@section('content')
@php
    // Defaults for filter controls
    $filters = array_merge([
        'from_date' => '',
        'to_date' => '',
        'warehouse_id' => '',
        'adjustment_type' => '',
        'adjustment_category' => '',
        'status' => '',
        'branch_id' => '',
    ], is_array($filters ?? null) ? $filters : []);

    $stats = array_merge([
        'total' => 0,
        'draft' => 0,
        'submitted' => 0,
        'approved' => 0,
        'confirmed' => 0,
        'cancelled' => 0,
        'total_value' => 0,
    ], $stats ?? []);

    // Phase 3 — status badge now covers all six lifecycle states.
    $statusBadge = function (string $status): string {
        return [
            'draft'     => '<span class="badge bg-warning-subtle text-warning"><i class="fas fa-pen-to-square me-1"></i>Draft</span>',
            'submitted' => '<span class="badge bg-info-subtle text-info"><i class="fas fa-paper-plane me-1"></i>Submitted</span>',
            'approved'  => '<span class="badge bg-primary-subtle text-primary"><i class="fas fa-circle-check me-1"></i>Approved</span>',
            'confirmed' => '<span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i>Confirmed</span>',
            'cancelled' => '<span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-ban me-1"></i>Cancelled</span>',
            'rejected'  => '<span class="badge bg-danger-subtle text-danger"><i class="fas fa-circle-xmark me-1"></i>Rejected</span>',
        ][$status] ?? '<span class="badge bg-light text-dark">' . e($status) . '</span>';
    };

    $typeBadge = function (string $type): string {
        if ($type === 'increase') {
            return '<span class="badge bg-success-subtle text-success"><i class="fas fa-arrow-up me-1"></i>Increase</span>';
        }
        if ($type === 'decrease') {
            return '<span class="badge bg-danger-subtle text-danger"><i class="fas fa-arrow-down me-1"></i>Decrease</span>';
        }
        return '<span class="badge bg-light text-dark">' . e($type) . '</span>';
    };

    // Phase 2 — category badge helper. Delegates to the model's central map so
    // badge styles stay consistent across index / show / future audit views.
    $categoryLabels = $categoryLabels ?? \App\Models\StockAdjustment::CATEGORY_LABELS;
    $categories     = $categories     ?? \App\Models\StockAdjustment::ADJUSTMENT_CATEGORIES;
    $categoryBadge  = function (string $cat) use ($categoryLabels): string {
        $meta = \App\Models\StockAdjustment::CATEGORY_BADGES[$cat]
            ?? ['cls' => 'bg-light text-muted', 'icon' => 'fa-ellipsis'];
        $label = e($categoryLabels[$cat] ?? ucfirst(str_replace('_', ' ', $cat)));
        return '<span class="badge ' . e($meta['cls']) . '">'
            . '<i class="fas ' . e($meta['icon']) . ' me-1"></i>' . $label
            . '</span>';
    };
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0f766e,#0891b2);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-scale-balanced me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Two-phase stock adjustments — draft → confirm posts stock + GL; cancel reverses everything.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.stock-adjustments.reconcile') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-scale-balanced me-1"></i> Reconciliation
            </a>
            {{-- Phase 8 — Checklist (supersedes the flat Audit screen; the
                 audit route still redirects here for backward compat). --}}
            <a href="{{ route('admin.stock-adjustments.checklist') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clipboard-check me-1"></i> Checklist
            </a>
            {{-- Phase 8.1 (G2) — CSV export with the current filters. The
                 export route reads the same query params as index(). --}}
            <a href="{{ route('admin.stock-adjustments.export', $filters) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-file-csv me-1"></i> Export CSV
            </a>
            <a href="{{ route('admin.stock-adjustments.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New Adjustment
            </a>
        </div>
    </header>

    {{-- Stats cards --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0f766e;">
                        <i class="fas fa-list"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['total']) }}</div>
                        <div class="text-muted small">Total</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#d97706;">
                        <i class="fas fa-pen-to-square"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['draft']) }}</div>
                        <div class="text-muted small">Draft</div>
                    </div>
                </div>
            </div>
        </div>
        {{-- Phase 3 — Pending Approval (submitted + approved) worklist card --}}
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0ea5e9;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['submitted'] + $stats['approved'])) }}</div>
                        <div class="text-muted small">Pending approval</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#16a34a;">
                        <i class="fas fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['confirmed']) }}</div>
                        <div class="text-muted small">Confirmed</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#64748b;">
                        <i class="fas fa-ban"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['cancelled']) }}</div>
                        <div class="text-muted small">Cancelled</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#7c3aed;">
                        <i class="fas fa-taka-sign"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['total_value'], 2) }}</div>
                        <div class="text-muted small">Total value (confirmed)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.stock-adjustments.index') }}" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="from_date">From date</label>
                    <input type="date" id="from_date" name="from_date" class="form-control form-control-sm"
                           value="{{ $filters['from_date'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="to_date">To date</label>
                    <input type="date" id="to_date" name="to_date" class="form-control form-control-sm"
                           value="{{ $filters['to_date'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="warehouse_id">Warehouse</label>
                    <select id="warehouse_id" name="warehouse_id" class="form-select form-select-sm select2">
                        <option value="">All warehouses</option>
                        @foreach ($warehouses as $wh)
                            <option value="{{ $wh->id }}"
                                {{ (string) $filters['warehouse_id'] === (string) $wh->id ? 'selected' : '' }}>
                                {{ $wh->warehouse_code }} — {{ $wh->warehouse_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small text-muted mb-1" for="branch_id">Branch</label>
                    <select id="branch_id" name="branch_id" class="form-select form-select-sm select2">
                        <option value="">All</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}"
                                {{ (string) $filters['branch_id'] === (string) $b->id ? 'selected' : '' }}>
                                {{ $b->branch_code }} — {{ $b->branch_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small text-muted mb-1" for="adjustment_type">Type</label>
                    <select id="adjustment_type" name="adjustment_type" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="increase" {{ $filters['adjustment_type'] === 'increase' ? 'selected' : '' }}>Increase</option>
                        <option value="decrease" {{ $filters['adjustment_type'] === 'decrease' ? 'selected' : '' }}>Decrease</option>
                    </select>
                </div>
                {{-- Phase 2 — category filter --}}
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="adjustment_category">Category</label>
                    <select id="adjustment_category" name="adjustment_category" class="form-select form-select-sm select2">
                        <option value="">All categories</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat }}" {{ $filters['adjustment_category'] === $cat ? 'selected' : '' }}>
                                {{ $categoryLabels[$cat] ?? ucfirst(str_replace('_', ' ', $cat)) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small text-muted mb-1" for="status">Status</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="draft"     {{ $filters['status'] === 'draft' ? 'selected' : '' }}>Draft</option>
                        <option value="submitted" {{ $filters['status'] === 'submitted' ? 'selected' : '' }}>Submitted</option>
                        <option value="approved"  {{ $filters['status'] === 'approved' ? 'selected' : '' }}>Approved</option>
                        <option value="confirmed" {{ $filters['status'] === 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                        <option value="cancelled" {{ $filters['status'] === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                        <option value="rejected"  {{ $filters['status'] === 'rejected' ? 'selected' : '' }}>Rejected</option>
                    </select>
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <a href="{{ route('admin.stock-adjustments.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-eraser me-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Adjustments table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0" id="dataTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Date</th>
                            <th>Warehouse</th>
                            <th>Type</th>
                            <th>Category</th>
                            <th class="text-end">Items</th>
                            <th class="text-end">Total (Tk)</th>
                            <th>Status</th>
                            <th>Reversed?</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($adjustments as $adj)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.stock-adjustments.show', $adj) }}"
                                       class="fw-semibold text-decoration-none">
                                        {{ $adj->adjustment_code }}
                                    </a>
                                </td>
                                <td class="text-nowrap small">
                                    {{ \Carbon\Carbon::parse($adj->adjustment_date)->format('d M Y') }}
                                </td>
                                <td>
                                    @if ($adj->warehouse)
                                        <span class="fw-semibold">{{ $adj->warehouse->warehouse_name }}</span>
                                        @if ($adj->warehouse->branch)
                                            <div class="small text-muted">
                                                <i class="fas fa-building me-1"></i>{{ $adj->warehouse->branch->branch_name }}
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>{!! $typeBadge($adj->adjustment_type) !!}</td>
                                {{-- Phase 2 — category badge --}}
                                <td>{!! $categoryBadge($adj->adjustment_category ?? 'other') !!}</td>
                                <td class="text-end">{{ number_format($adj->items->count()) }}</td>
                                <td class="text-end">{{ number_format((float) $adj->total_amount, 2) }}</td>
                                <td>{!! $statusBadge($adj->status) !!}</td>
                                <td>
                                    @if ($adj->is_reversed)
                                        <span class="badge bg-danger-subtle text-danger">
                                            <i class="fas fa-rotate-left me-1"></i>Reversed
                                        </span>
                                    @else
                                        <span class="badge bg-light text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center text-nowrap">
                                    <a href="{{ route('admin.stock-adjustments.show', $adj) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            {{-- NOTE: Hidden from DataTables (colspan rows trigger
                                tn/18 'Incorrect column count'). DataTables shows its
                                own empty message via language.emptyTable. --}}
                            <tr class="d-none">
                                <td colspan="10" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No stock adjustments found. Try adjusting filters or
                                    <a href="{{ route('admin.stock-adjustments.create') }}">create a new one</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            {{ $adjustments->links() }}
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    // DataTables on the visible rows only (server-side pagination handles page size).
    $('#dataTable').DataTable({
        paging: false,
        info: false,
        ordering: true,
        dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
        language: {
            search: 'Filter rows:',
            emptyTable: 'No stock adjustments found. Try adjusting filters or ' +
                '<a href="{{ route('admin.stock-adjustments.create') }}">create a new one</a>.'
        }
    });
});
</script>
@endpush
@endsection

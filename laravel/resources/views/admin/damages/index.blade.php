@extends('layouts.admin')

@section('content')
@php
    // Defaults for filter controls
    $filters = array_merge([
        'from_date'     => '',
        'to_date'       => '',
        'warehouse_id'  => '',
        'status'        => '',
        'branch_id'     => '',
        'search'        => '',
    ], is_array($filters ?? null) ? $filters : []);

    $stats = array_merge([
        'total'      => 0,
        'draft'      => 0,
        'confirmed'  => 0,
        'cancelled'  => 0,
        'total_value'=> 0,
    ], $stats ?? []);

    // Damages = financial loss → red/danger theme.
    // draft = warning, confirmed = danger, cancelled = secondary (per task spec)
    $statusBadge = function (string $status): string {
        return [
            'draft'     => '<span class="badge bg-warning-subtle text-warning"><i class="fas fa-pen-to-square me-1"></i>Draft</span>',
            'confirmed' => '<span class="badge bg-danger-subtle text-danger"><i class="fas fa-triangle-exclamation me-1"></i>Confirmed</span>',
            'cancelled' => '<span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-ban me-1"></i>Cancelled</span>',
        ][$status] ?? '<span class="badge bg-light text-dark">' . e($status) . '</span>';
    };
@endphp

<div class="container-fluid py-2">
    {{-- Hero header (red = loss) --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#dc2626,#b91c1c);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-triangle-exclamation me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Damaged stock write-offs — draft → confirm posts stock OUT + GL (Dr Damage Loss / Cr Inventory); cancel reverses.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.damages.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New Damage
            </a>
        </div>
    </header>

    {{-- Stats cards --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#475569;">
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
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#dc2626;">
                        <i class="fas fa-triangle-exclamation"></i>
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
                         style="width:48px;height:48px;background:#dc2626;">
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
            <form method="GET" action="{{ route('admin.damages.index') }}" class="row g-2 align-items-end">
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
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="branch_id">Branch</label>
                    <select id="branch_id" name="branch_id" class="form-select form-select-sm select2">
                        <option value="">All branches</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}"
                                {{ (string) $filters['branch_id'] === (string) $b->id ? 'selected' : '' }}>
                                {{ $b->branch_code }} — {{ $b->branch_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="status">Status</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="">All statuses</option>
                        <option value="draft"     {{ $filters['status'] === 'draft' ? 'selected' : '' }}>Draft</option>
                        <option value="confirmed" {{ $filters['status'] === 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                        <option value="cancelled" {{ $filters['status'] === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="search">Search code</label>
                    <input type="text" id="search" name="search" class="form-control form-control-sm"
                           placeholder="e.g. DMG-2024-001"
                           value="{{ $filters['search'] }}">
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end">
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <a href="{{ route('admin.damages.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-eraser me-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Damages table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0" id="dataTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Date</th>
                            <th>Warehouse</th>
                            <th class="text-end">Items</th>
                            <th class="text-end">Total (Tk)</th>
                            <th>Status</th>
                            <th>Reversed?</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($damages as $dmg)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.damages.show', $dmg) }}"
                                       class="fw-semibold text-decoration-none">
                                        {{ $dmg->damage_code }}
                                    </a>
                                </td>
                                <td class="text-nowrap small">
                                    {{ \Carbon\Carbon::parse($dmg->damage_date)->format('d M Y') }}
                                </td>
                                <td>
                                    @if ($dmg->warehouse)
                                        <span class="fw-semibold">{{ $dmg->warehouse->warehouse_name }}</span>
                                        @if ($dmg->warehouse->branch)
                                            <div class="small text-muted">
                                                <i class="fas fa-building me-1"></i>{{ $dmg->warehouse->branch->branch_name }}
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ number_format($dmg->items->count()) }}</td>
                                <td class="text-end">{{ number_format((float) $dmg->total_value, 2) }}</td>
                                <td>{!! $statusBadge($dmg->status) !!}</td>
                                <td>
                                    @if ($dmg->is_reversed)
                                        <span class="badge bg-danger-subtle text-danger">
                                            <i class="fas fa-rotate-left me-1"></i>Reversed
                                        </span>
                                    @else
                                        <span class="badge bg-light text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center text-nowrap">
                                    <a href="{{ route('admin.damages.show', $dmg) }}"
                                       class="btn btn-sm btn-outline-danger" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No damage invoices found. Try adjusting filters or
                                    <a href="{{ route('admin.damages.create') }}">create a new one</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            {{ $damages->links() }}
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    // DataTables on visible rows only (server-side pagination handles page size).
    $('#dataTable').DataTable({
        paging: false,
        info: false,
        ordering: true,
        dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
        language: { search: 'Filter rows:', emptyTable: 'No damage invoices on this page.' }
    });
});
</script>
@endpush
@endsection

@extends('layouts.admin')

@section('content')
@php
    // Defaults for filter controls.
    $filters = array_merge([
        'from_date'  => '',
        'to_date'    => '',
        'status'     => '',
        'branch_id'  => '',
        'search'     => '',
    ], is_array($filters ?? null) ? $filters : []);

    $stats = array_merge([
        'total'     => 0,
        'draft'     => 0,
        'counting'  => 0,
        'posted'    => 0,
        'cancelled' => 0,
    ], $stats ?? []);

    $statusBadge = function (string $status): string {
        return [
            'draft'     => '<span class="badge bg-warning-subtle text-warning"><i class="fas fa-pen-to-square me-1"></i>Draft</span>',
            'counting'  => '<span class="badge bg-info-subtle text-info"><i class="fas fa-clipboard-list me-1"></i>Counting</span>',
            'posted'    => '<span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i>Posted</span>',
            'cancelled' => '<span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-ban me-1"></i>Cancelled</span>',
        ][$status] ?? '<span class="badge bg-light text-dark">' . e($status) . '</span>';
    };

    // Bulk-aggregate variance stats per session in ONE query (avoid N+1).
    $sessionIds = $sessions->pluck('id')->all();
    $varStats = \Illuminate\Support\Facades\DB::table('stock_take_items')
        ->whereIn('stock_take_session_id', $sessionIds)
        ->whereRaw('physical_qty <> system_qty')
        ->selectRaw('
            stock_take_session_id,
            COUNT(*)                                   AS variance_lines,
            SUM(ABS(difference) * COALESCE(rate, 0))   AS variance_value
        ')
        ->groupBy('stock_take_session_id')
        ->get()
        ->keyBy('stock_take_session_id');
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#4f46e5);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-clipboard-check me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Multi-warehouse stock take — create a session, count each warehouse, post variances + GL.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.stock-take.checklist') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clipboard-check me-1"></i> Health Check
            </a>
            <a href="{{ route('admin.stock-take.audit') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit Log
            </a>
            <a href="{{ route('admin.stock-take.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New Session
            </a>
        </div>
    </header>

    {{-- Stats cards --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#4f46e5;">
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
                         style="width:48px;height:48px;background:#0ea5e9;">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['counting']) }}</div>
                        <div class="text-muted small">Counting</div>
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
                        <div class="h4 mb-0">{{ number_format((int) $stats['posted']) }}</div>
                        <div class="text-muted small">Posted</div>
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
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.stock-take.index') }}" class="row g-2 align-items-end">
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
                    <label class="form-label small text-muted mb-1" for="status">Status</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="">All statuses</option>
                        <option value="draft"     {{ $filters['status'] === 'draft' ? 'selected' : '' }}>Draft</option>
                        <option value="counting"  {{ $filters['status'] === 'counting' ? 'selected' : '' }}>Counting</option>
                        <option value="posted"    {{ $filters['status'] === 'posted' ? 'selected' : '' }}>Posted</option>
                        <option value="cancelled" {{ $filters['status'] === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-3">
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
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="search">Search code</label>
                    <input type="text" id="search" name="search" class="form-control form-control-sm"
                           placeholder="e.g. ST-2024-001"
                           value="{{ $filters['search'] }}">
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <a href="{{ route('admin.stock-take.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-eraser me-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Sessions table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0" id="dataTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Date</th>
                            <th>Branch</th>
                            <th class="text-center">Warehouses</th>
                            <th>Status</th>
                            <th class="text-end">Variance Lines</th>
                            <th class="text-end">Variance Value (Tk)</th>
                            <th class="text-center">Reversed?</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sessions as $session)
                            @php
                                $stat = $varStats->get($session->id);
                                $varianceLines = $stat ? (int) $stat->variance_lines : 0;
                                $varianceValue = $stat ? (float) $stat->variance_value : 0;
                            @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('admin.stock-take.show', $session->id) }}"
                                       class="fw-semibold text-decoration-none">
                                        {{ $session->session_code }}
                                    </a>
                                </td>
                                <td class="text-nowrap small">
                                    {{ \Carbon\Carbon::parse($session->session_date)->format('d M Y') }}
                                </td>
                                <td>
                                    @if ($session->branch)
                                        <span class="fw-semibold">{{ $session->branch->branch_name }}</span>
                                        <div class="small text-muted">{{ $session->branch->branch_code }}</div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-primary-subtle text-primary">
                                        {{ $session->warehouses->count() }}
                                    </span>
                                </td>
                                <td>{!! $statusBadge($session->status) !!}</td>
                                <td class="text-end">
                                    @if ($varianceLines > 0)
                                        <span class="badge bg-warning-subtle text-warning">{{ $varianceLines }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($varianceValue > 0)
                                        <span class="fw-semibold">{{ number_format($varianceValue, 2) }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if ($session->is_reversed)
                                        <span class="badge bg-danger-subtle text-danger">
                                            <i class="fas fa-rotate-left me-1"></i>Yes
                                        </span>
                                    @else
                                        <span class="badge bg-light text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center text-nowrap">
                                    <a href="{{ route('admin.stock-take.show', $session->id) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No stock take sessions found. Try adjusting filters or
                                    <a href="{{ route('admin.stock-take.create') }}">create a new one</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            {{ $sessions->links() }}
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
        language: { search: 'Filter rows:', emptyTable: 'No stock take sessions on this page.' }
    });
});
</script>
@endpush
@endsection

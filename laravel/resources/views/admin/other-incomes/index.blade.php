@extends('layouts.admin')

@section('content')
@php
    // Defaults for filter controls
    $filters = array_merge([
        'date_from'     => '',
        'date_to'       => '',
        'payment_mode'  => 'all',
        'status'        => 'all',
        'branch_id'     => '',
        'search'        => '',
    ], is_array($filters ?? null) ? $filters : []);

    $stats = array_merge([
        'total'        => 0,
        'total_amount' => 0,
        'cash'         => 0,
        'bank'         => 0,
        'today'        => 0,
        'this_month'   => 0,
        'reversed'     => 0,
    ], $stats ?? []);

    // Payment mode badge helper
    $modeBadge = function (string $mode): string {
        return [
            'cash' => '<span class="badge bg-secondary"><i class="fas fa-money-bill me-1"></i>Cash</span>',
            'bank' => '<span class="badge bg-primary"><i class="fas fa-university me-1"></i>Bank</span>',
        ][$mode] ?? '<span class="badge bg-light text-dark">' . e($mode) . '</span>';
    };
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#16a34a,#15803d);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-arrow-trend-up me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Record miscellaneous income — interest, rent, commission, etc. Each posts GL (Dr Cash/Bank, Cr Income) on creation.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.other-incomes.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New Income
            </a>
            <a href="{{ route('admin.other-incomes.audit') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit Trail
            </a>
        </div>
    </header>

    {{-- Stats cards: 7 cards --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#16a34a;">
                        <i class="fas fa-list"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['total']) }}</div>
                        <div class="text-muted small">Total incomes</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#15803d;">
                        <i class="fas fa-taka-sign"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['total_amount'], 2) }}</div>
                        <div class="text-muted small">Total amount</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#64748b;">
                        <i class="fas fa-money-bill"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['cash'], 2) }}</div>
                        <div class="text-muted small">Cash</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#2563eb;">
                        <i class="fas fa-university"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['bank'], 2) }}</div>
                        <div class="text-muted small">Bank</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#059669;">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['today'], 2) }}</div>
                        <div class="text-muted small">Today</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0891b2;">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['this_month'], 2) }}</div>
                        <div class="text-muted small">This Month</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#dc2626;">
                        <i class="fas fa-ban"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['reversed']) }}</div>
                        <div class="text-muted small">Reversed</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.other-incomes.index') }}" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="date_from">From date</label>
                    <input type="date" id="date_from" name="date_from" class="form-control form-control-sm"
                           value="{{ $filters['date_from'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="date_to">To date</label>
                    <input type="date" id="date_to" name="date_to" class="form-control form-control-sm"
                           value="{{ $filters['date_to'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="payment_mode">Payment mode</label>
                    <select id="payment_mode" name="payment_mode" class="form-select form-select-sm">
                        <option value="all"  {{ $filters['payment_mode'] === 'all' ? 'selected' : '' }}>All modes</option>
                        <option value="cash"  {{ $filters['payment_mode'] === 'cash' ? 'selected' : '' }}>Cash</option>
                        <option value="bank"  {{ $filters['payment_mode'] === 'bank' ? 'selected' : '' }}>Bank</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="status">Status</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="all"     {{ $filters['status'] === 'all' ? 'selected' : '' }}>All statuses</option>
                        <option value="active"   {{ $filters['status'] === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="reversed" {{ $filters['status'] === 'reversed' ? 'selected' : '' }}>Reversed</option>
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
                    <label class="form-label small text-muted mb-1" for="search">Search</label>
                    <input type="text" id="search" name="search" class="form-control form-control-sm"
                           placeholder="Code / Type" value="{{ $filters['search'] }}">
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <a href="{{ route('admin.other-incomes.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-eraser me-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Incomes table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0" id="dataTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Income Type</th>
                            <th class="text-end">Amount (Tk)</th>
                            <th>Payment Mode</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($incomes as $i)
                            <tr class="{{ $i->is_reversed ? 'table-danger' : '' }}">
                                <td>
                                    <a href="{{ route('admin.other-incomes.show', ['id' => $i->id]) }}"
                                       class="fw-semibold text-decoration-none">
                                        {{ $i->income_code }}
                                    </a>
                                </td>
                                <td class="text-nowrap small">
                                    {{ \Carbon\Carbon::parse($i->income_date)->format('d M Y') }}
                                </td>
                                <td>
                                    @if ($i->branch)
                                        {{ $i->branch->branch_name }}
                                    @else
                                        <span class="text-muted">&mdash;</span>
                                    @endif
                                </td>
                                <td>{{ $i->income_type ?: '&mdash;' }}</td>
                                <td class="text-end fw-semibold">Tk {{ number_format((float) $i->amount, 2) }}</td>
                                <td>{!! $modeBadge($i->payment_mode) !!}</td>
                                <td>
                                    @if ($i->is_reversed)
                                        <span class="badge bg-danger">
                                            <i class="fas fa-rotate-left me-1"></i>Reversed
                                        </span>
                                    @else
                                        <span class="badge bg-success">
                                            <i class="fas fa-check me-1"></i>Active
                                        </span>
                                    @endif
                                </td>
                                <td class="text-center text-nowrap">
                                    <a href="{{ route('admin.other-incomes.show', ['id' => $i->id]) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="{{ route('admin.other-incomes.slip', ['id' => $i->id]) }}"
                                       class="btn btn-sm btn-outline-primary" title="Print slip" target="_blank">
                                        <i class="fas fa-print"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No other incomes found. Try adjusting filters or
                                    <a href="{{ route('admin.other-incomes.create') }}">record a new income</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if (method_exists($incomes, 'links'))
            <div class="card-footer bg-white">
                {{ $incomes->links() }}
            </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
$(function () {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    // Client-side DataTable for ordering and quick search on current page
    $('#dataTable').DataTable({
        paging: false,
        info: false,
        ordering: true,
        dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
        language: { search: 'Filter rows:', emptyTable: 'No other incomes on this page.' }
    });
});
</script>
@endpush
@endsection

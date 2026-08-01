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
        'cash_total'   => 0,
        'bank_total'   => 0,
        'today'        => 0,
        'this_month'   => 0,
        'reversed'     => 0,
    ], $stats ?? []);

    $showReversed = $showReversed ?? false;

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
            style="background: linear-gradient(135deg,#dc2626,#b91c1c);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-arrow-trend-down me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Other expenses — debit operating expense, credit cash/bank. Each posts GL on creation.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.other-expenses.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New Expense
            </a>
            <a href="{{ route('admin.other-expenses.audit') }}" class="btn btn-outline-light btn-sm">
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
                         style="width:48px;height:48px;background:#dc2626;">
                        <i class="fas fa-list"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['total']) }}</div>
                        <div class="text-muted small">Total expenses</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#b91c1c;">
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
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['cash_total'], 2) }}</div>
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
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['bank_total'], 2) }}</div>
                        <div class="text-muted small">Bank</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0891b2;">
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
                         style="width:48px;height:48px;background:#16a34a;">
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
                         style="width:48px;height:48px;background:#7c3aed;">
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
            <form method="GET" action="{{ route('admin.other-expenses.index') }}" class="row g-2 align-items-end">
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
                        <option value="cash" {{ $filters['payment_mode'] === 'cash' ? 'selected' : '' }}>Cash</option>
                        <option value="bank" {{ $filters['payment_mode'] === 'bank' ? 'selected' : '' }}>Bank</option>
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
                    <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <a href="{{ route('admin.other-expenses.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-eraser me-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Expenses table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0" id="oeTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Date</th>
                            <th>Branch</th>
                            <th>Expense Type</th>
                            <th class="text-end">Amount (Tk)</th>
                            <th>Payment Mode</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($expenses as $e)
                            <tr class="{{ $e->is_reversed ? 'table-danger' : '' }}">
                                <td>
                                    <a href="{{ route('admin.other-expenses.show', ['id' => $e->id]) }}"
                                       class="fw-semibold text-decoration-none">
                                        {{ $e->expense_code }}
                                    </a>
                                </td>
                                <td class="text-nowrap small">
                                    {{ \Carbon\Carbon::parse($e->expense_date)->format('d M Y') }}
                                </td>
                                <td>
                                    @if ($e->branch)
                                        {{ $e->branch->branch_name }}
                                    @else
                                        <span class="text-muted">&mdash;</span>
                                    @endif
                                </td>
                                <td>{{ $e->expense_type ?: '&mdash;' }}</td>
                                <td class="text-end fw-semibold">Tk {{ number_format((float) $e->amount, 2) }}</td>
                                <td>{!! $modeBadge($e->payment_mode) !!}</td>
                                <td>
                                    @if ($e->is_reversed)
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
                                    <a href="{{ route('admin.other-expenses.show', ['id' => $e->id]) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="{{ route('admin.other-expenses.slip', ['id' => $e->id]) }}"
                                       class="btn btn-sm btn-outline-primary" title="Print slip" target="_blank">
                                        <i class="fas fa-print"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No expenses found. Try adjusting filters or
                                    <a href="{{ route('admin.other-expenses.create') }}">record a new expense</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if (method_exists($expenses, 'links'))
            <div class="card-footer bg-white">
                {{ $expenses->links() }}
            </div>
        @endif
    </div>
</div>

{{-- Boot config for OtherExpense.js --}}
<script>
    window.OE_BOOT = {
        baseUrl: '{{ url("/") }}/',
        csrf_token: '{{ csrf_token() }}',
        showReversed: {{ $showReversed ? 'true' : 'false' }},
        routes: {
            'index': '{{ route("admin.other-expenses.index") }}',
            'show': '{{ route("admin.other-expenses.show", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'reverse': '{{ route("admin.other-expenses.reverse", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'slip': '{{ route("admin.other-expenses.slip", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
        },
    };
</script>

@push('scripts')
<script src="/assets/js/OtherExpense.js?v={{ filemtime(public_path('assets/js/OtherExpense.js')) }}"></script>
<script>
$(function () {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });
    // DataTable initialization is handled by OtherExpense.js initIndex()
    // to avoid "Cannot reinitialise DataTable" and "Incorrect column count" warnings.
});
</script>
@endpush
@endsection

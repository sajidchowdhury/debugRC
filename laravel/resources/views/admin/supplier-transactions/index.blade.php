@extends('layouts.admin')

@section('content')
@php
    // Defaults for filter controls
    $filters = array_merge([
        'date_from'        => '',
        'date_to'          => '',
        'supplier_id'      => '',
        'branch_id'        => '',
        'payment_mode'     => 'all',
        'transaction_type' => 'all',
        'status'           => 'all',
        'search'           => '',
    ], is_array($filters ?? null) ? $filters : []);

    $stats = array_merge([
        'total'        => 0,
        'total_amount' => 0,
        'cash'         => 0,
        'bank'         => 0,
        'reversed'     => 0,
        'payments'     => 0,
        'advances'     => 0,
        'receives'     => 0,
        'out_today'    => 0,
        'out_month'    => 0,
    ], $stats ?? []);

    // Payment mode badge helper
    $modeBadge = function (string $mode): string {
        return [
            'cash'            => '<span class="badge bg-secondary"><i class="fas fa-money-bill me-1"></i>Cash</span>',
            'bank'            => '<span class="badge bg-primary"><i class="fas fa-university me-1"></i>Bank</span>',
            'mobile_banking' => '<span class="badge bg-info"><i class="fas fa-mobile-screen me-1"></i>Mobile</span>',
            'cheque'          => '<span class="badge bg-warning text-dark"><i class="fas fa-money-check me-1"></i>Cheque</span>',
            'adjustment'      => '<span class="badge bg-dark"><i class="fas fa-sliders me-1"></i>Adjustment</span>',
        ][$mode] ?? '<span class="badge bg-light text-dark">' . e($mode) . '</span>';
    };

    // Transaction type badge helper
    $typeBadge = function (string $type): string {
        return [
            'payment' => '<span class="badge bg-success"><i class="fas fa-money-bill-transfer me-1"></i>Payment</span>',
            'advance' => '<span class="badge" style="background:#0d9488;color:#fff;"><i class="fas fa-forward me-1"></i>Advance</span>',
            'receive' => '<span class="badge" style="background:#7c3aed;color:#fff;"><i class="fas fa-truck-ramp-box me-1"></i>Receive</span>',
        ][$type] ?? '<span class="badge bg-light text-dark">' . e($type) . '</span>';
    };
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0d9488,#059669);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-file-invoice-dollar me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Supplier transactions — payments, advances, and credit receives. Each posts GL + supplier ledger on confirm.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.supplier-transactions.create', ['transaction_type' => 'payment']) }}" class="btn btn-light btn-sm">
                <i class="fas fa-money-bill-transfer me-1"></i> Payment
            </a>
            <a href="{{ route('admin.supplier-transactions.create', ['transaction_type' => 'advance']) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-forward me-1"></i> Advance
            </a>
            <a href="{{ route('admin.supplier-transactions.create', ['transaction_type' => 'receive']) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-truck-ramp-box me-1"></i> Receive
            </a>
            @if (!$showReversed)
                <a href="{{ route('admin.supplier-transactions.index', ['status' => 'reversed']) }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-rotate-left me-1"></i> Reversed
                </a>
            @else
                <a href="{{ route('admin.supplier-transactions.index') }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-list me-1"></i> Active
                </a>
            @endif
        </div>
    </header>

    {{-- Stats cards: 7 cards --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0d9488;">
                        <i class="fas fa-list"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['total']) }}</div>
                        <div class="text-muted small">Total payments</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#059669;">
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
                         style="width:48px;height:48px;background:#0d9488;">
                        <i class="fas fa-money-bill-transfer"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['payments'], 2) }}</div>
                        <div class="text-muted small">Payments</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#7c3aed;">
                        <i class="fas fa-truck-ramp-box"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['receives'], 2) }}</div>
                        <div class="text-muted small">Credit receives</div>
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
                        <div class="text-muted small">Cash total</div>
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
                        <div class="text-muted small">Bank total</div>
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
                        <div class="text-muted small">Reversed count</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.supplier-transactions.index') }}" class="row g-2 align-items-end">
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
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="supplier_id">Supplier</label>
                    <select id="supplier_id" name="supplier_id" class="form-select form-select-sm select2">
                        <option value="">All suppliers</option>
                        @foreach ($suppliers as $s)
                            <option value="{{ $s->id }}"
                                {{ (string) $filters['supplier_id'] === (string) $s->id ? 'selected' : '' }}>
                                {{ $s->supplier_code }} — {{ $s->supplier_name }}
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
                    <label class="form-label small text-muted mb-1" for="payment_mode">Payment mode</label>
                    <select id="payment_mode" name="payment_mode" class="form-select form-select-sm">
                        <option value="all" {{ $filters['payment_mode'] === 'all' ? 'selected' : '' }}>All modes</option>
                        <option value="cash"            {{ $filters['payment_mode'] === 'cash' ? 'selected' : '' }}>Cash</option>
                        <option value="bank"            {{ $filters['payment_mode'] === 'bank' ? 'selected' : '' }}>Bank</option>
                        <option value="mobile_banking"  {{ $filters['payment_mode'] === 'mobile_banking' ? 'selected' : '' }}>Mobile Banking</option>
                        <option value="cheque"          {{ $filters['payment_mode'] === 'cheque' ? 'selected' : '' }}>Cheque</option>
                        <option value="adjustment"      {{ $filters['payment_mode'] === 'adjustment' ? 'selected' : '' }}>Adjustment</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="transaction_type">Type</label>
                    <select id="transaction_type" name="transaction_type" class="form-select form-select-sm">
                        <option value="all"    {{ $filters['transaction_type'] === 'all' ? 'selected' : '' }}>All types</option>
                        <option value="payment" {{ $filters['transaction_type'] === 'payment' ? 'selected' : '' }}>Payment</option>
                        <option value="advance" {{ $filters['transaction_type'] === 'advance' ? 'selected' : '' }}>Advance</option>
                        <option value="receive" {{ $filters['transaction_type'] === 'receive' ? 'selected' : '' }}>Receive</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="status">Status</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="all"     {{ $filters['status'] === 'all' ? 'selected' : '' }}>All</option>
                        <option value="active"  {{ $filters['status'] === 'active' ? 'selected' : '' }}>Active</option>
                        <option value="reversed" {{ $filters['status'] === 'reversed' ? 'selected' : '' }}>Reversed</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small text-muted mb-1" for="search">Search</label>
                    <input type="text" id="search" name="search" class="form-control form-control-sm"
                           placeholder="Code" value="{{ $filters['search'] }}">
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <a href="{{ route('admin.supplier-transactions.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-eraser me-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Payments table (Blade-rendered, client-side DataTable for sort/filter) --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0" id="dataTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Date</th>
                            <th>Supplier</th>
                            <th>Branch</th>
                            <th>Type</th>
                            <th>Mode</th>
                            <th class="text-end">Amount (Tk)</th>
                            <th>Reversed?</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($payments as $p)
                            <tr class="{{ $p->is_reversed ? 'table-danger' : '' }}">
                                <td>
                                    <a href="{{ route('admin.supplier-transactions.show', $p) }}"
                                       class="fw-semibold text-decoration-none">
                                        {{ $p->payment_code }}
                                    </a>
                                    @if (!empty($p->reference_no))
                                        <div class="small text-muted">Ref: {{ $p->reference_no }}</div>
                                    @endif
                                </td>
                                <td class="text-nowrap small">
                                    {{ \Carbon\Carbon::parse($p->payment_date)->format('d M Y') }}
                                </td>
                                <td>
                                    @if ($p->supplier)
                                        <span class="fw-semibold">{{ $p->supplier->supplier_name }}</span>
                                        <div class="small text-muted">{{ $p->supplier->supplier_code }}</div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($p->branch)
                                        {{ $p->branch->branch_name }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>{!! $typeBadge($p->transaction_type ?? 'payment') !!}</td>
                                <td>{!! $modeBadge($p->payment_mode) !!}</td>
                                <td class="text-end fw-semibold">Tk {{ number_format((float) $p->amount, 2) }}</td>
                                <td>
                                    @if ($p->is_reversed)
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
                                    <a href="{{ route('admin.supplier-transactions.show', $p) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    @if (!$p->is_reversed)
                                        <button type="button" class="btn btn-sm btn-outline-danger js-supp-reverse"
                                                data-payment-id="{{ $p->id }}"
                                                data-payment-code="{{ $p->payment_code }}"
                                                title="Reverse payment">
                                            <i class="fas fa-rotate-left"></i>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No payments found. Try adjusting filters or
                                    <a href="{{ route('admin.supplier-transactions.create') }}">record a new transaction</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            {{ $payments->links() }}
        </div>
    </div>
</div>

{{-- Boot config for SupplierTransaction.js reverse functionality --}}
<script>
    window.ST_BOOT = {
        baseUrl: '{{ url("/") }}/',
        showReversed: {{ $showReversed ? 'true' : 'false' }},
        csrf_token: '{{ csrf_token() }}',
        routes: {
            'index': '{{ route("admin.supplier-transactions.index") }}',
            'show': '{{ rtrim(route("admin.supplier-transactions.show", ["id" => "{id}"]), "}") }}'.replace('{id}', ''),
            'reverse': '{{ route("admin.supplier-transactions.reverse", ["id" => "{id}"]) }}'.replace('{id}', ''),
            'search': '{{ route("admin.supplier-transactions.search") }}',
            'get-due': '{{ route("admin.supplier-transactions.get-due") }}',
            'supplier-show': '{{ url("/admin/suppliers") }}/',
        },
    };
</script>

@push('scripts')
<link rel="stylesheet" href="/assets/css/supplier-transaction-theme.css">
<script src="/assets/js/SupplierTransaction.js"></script>
<script>
$(function () {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    // Client-side DataTable for ordering and quick search on current page
    $('#dataTable').DataTable({
        paging: false,
        info: false,
        ordering: true,
        dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
        language: { search: 'Filter rows:', emptyTable: 'No payments on this page.' }
    });
});
</script>
@endpush
@endsection

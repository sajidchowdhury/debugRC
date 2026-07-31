@extends('layouts.admin')

@section('content')
@php
    // Defaults for filter controls
    $filters = array_merge([
        'from_date'     => '',
        'to_date'       => '',
        'transfer_type' => 'all',
        'search'        => '',
    ], is_array($filters ?? null) ? $filters : []);

    $stats = array_merge([
        'total'        => 0,
        'total_amount' => 0,
        'cash_to_bank' => 0,
        'bank_to_cash' => 0,
        'bank_to_bank' => 0,
        'cash_to_cash' => 0,
        'reversed'     => 0,
    ], $stats ?? []);

    // Transfer type badge helper
    $typeBadge = function (string $type): string {
        return [
            'cash_to_bank' => '<span class="badge bg-primary"><i class="fas fa-university me-1"></i>Cash to Bank</span>',
            'bank_to_cash' => '<span class="badge bg-secondary"><i class="fas fa-money-bill me-1"></i>Bank to Cash</span>',
            'cash_to_cash' => '<span class="badge bg-success"><i class="fas fa-money-bill-transfer me-1"></i>Cash to Cash</span>',
            'bank_to_bank' => '<span class="badge bg-info"><i class="fas fa-exchange-alt me-1"></i>Bank to Bank</span>',
        ][$type] ?? '<span class="badge bg-light text-dark">' . e($type) . '</span>';
    };

    // Transfer type label helper
    $typeLabel = function (string $type): string {
        return [
            'cash_to_bank' => 'Cash to Bank',
            'bank_to_cash' => 'Bank to Cash',
            'cash_to_cash' => 'Cash to Cash',
            'bank_to_bank' => 'Bank to Bank',
        ][$type] ?? ucfirst(str_replace('_', ' ', $type));
    };
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0d9488,#059669);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-exchange-alt me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Money transfers between cash and bank accounts across branches. Each posts GL + cash/bank ledger on creation.
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.money-transfers.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New Transfer
            </a>
            <a href="{{ route('admin.money-transfers.audit') }}" class="btn btn-outline-light btn-sm">
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
                         style="width:48px;height:48px;background:#0d9488;">
                        <i class="fas fa-list"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['total']) }}</div>
                        <div class="text-muted small">Total transfers</div>
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
                         style="width:48px;height:48px;background:#2563eb;">
                        <i class="fas fa-university"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['cash_to_bank'], 2) }}</div>
                        <div class="text-muted small">Cash to Bank</div>
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
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['bank_to_cash'], 2) }}</div>
                        <div class="text-muted small">Bank to Cash</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0891b2;">
                        <i class="fas fa-exchange-alt"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['bank_to_bank'], 2) }}</div>
                        <div class="text-muted small">Bank to Bank</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#16a34a;">
                        <i class="fas fa-money-bill-transfer"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['cash_to_cash'], 2) }}</div>
                        <div class="text-muted small">Cash to Cash</div>
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
            <form method="GET" action="{{ route('admin.money-transfers.index') }}" class="row g-2 align-items-end">
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
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="transfer_type">Transfer type</label>
                    <select id="transfer_type" name="transfer_type" class="form-select form-select-sm">
                        <option value="all"           {{ $filters['transfer_type'] === 'all' ? 'selected' : '' }}>All types</option>
                        <option value="cash_to_bank"  {{ $filters['transfer_type'] === 'cash_to_bank' ? 'selected' : '' }}>Cash to Bank</option>
                        <option value="bank_to_cash"  {{ $filters['transfer_type'] === 'bank_to_cash' ? 'selected' : '' }}>Bank to Cash</option>
                        <option value="cash_to_cash"  {{ $filters['transfer_type'] === 'cash_to_cash' ? 'selected' : '' }}>Cash to Cash</option>
                        <option value="bank_to_bank"  {{ $filters['transfer_type'] === 'bank_to_bank' ? 'selected' : '' }}>Bank to Bank</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="search">Search</label>
                    <input type="text" id="search" name="search" class="form-control form-control-sm"
                           placeholder="Code" value="{{ $filters['search'] }}">
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <a href="{{ route('admin.money-transfers.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-eraser me-1"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Transfers table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0" id="dataTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Date</th>
                            <th>Type</th>
                            <th>From Branch</th>
                            <th>To Branch</th>
                            <th>From Bank</th>
                            <th>To Bank</th>
                            <th class="text-end">Amount (Tk)</th>
                            <th>Reversed?</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($transfers as $t)
                            <tr class="{{ $t->is_reversed ? 'table-danger' : '' }}">
                                <td>
                                    <a href="{{ route('admin.money-transfers.show', ['money_transfer' => $t->id]) }}"
                                       class="fw-semibold text-decoration-none">
                                        {{ $t->transfer_code }}
                                    </a>
                                </td>
                                <td class="text-nowrap small">
                                    {{ \Carbon\Carbon::parse($t->transfer_date)->format('d M Y') }}
                                </td>
                                <td>{!! $typeBadge($t->transfer_type) !!}</td>
                                <td>
                                    @if ($t->fromBranch)
                                        {{ $t->fromBranch->branch_name }}
                                    @else
                                        <span class="text-muted">&mdash;</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($t->toBranch)
                                        {{ $t->toBranch->branch_name }}
                                    @else
                                        <span class="text-muted">&mdash;</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($t->fromBank)
                                        {{ $t->fromBank->bank_name }}
                                    @else
                                        <span class="text-muted">&mdash;</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($t->toBank)
                                        {{ $t->toBank->bank_name }}
                                    @else
                                        <span class="text-muted">&mdash;</span>
                                    @endif
                                </td>
                                <td class="text-end fw-semibold">Tk {{ number_format((float) $t->amount, 2) }}</td>
                                <td>
                                    @if ($t->is_reversed)
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
                                    <a href="{{ route('admin.money-transfers.show', ['money_transfer' => $t->id]) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View details">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="{{ route('admin.money-transfers.slip', $t->id) }}"
                                       class="btn btn-sm btn-outline-primary" title="Print slip" target="_blank">
                                        <i class="fas fa-print"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No transfers found. Try adjusting filters or
                                    <a href="{{ route('admin.money-transfers.create') }}">record a new transfer</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if (method_exists($transfers, 'links'))
            <div class="card-footer bg-white">
                {{ $transfers->links() }}
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
        language: { search: 'Filter rows:', emptyTable: 'No transfers on this page.' }
    });
});
</script>
@endpush
@endsection

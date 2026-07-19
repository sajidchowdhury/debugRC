@extends('layouts.admin')

@section('content')
@php
    $stats = $stats ?? ['active' => 0, 'total_balance' => 0];
    $showDeleted = $showDeleted ?? false;
@endphp

<div class="container-fluid py-2">
    {{-- Hero --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0f766e,#14b8a6);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-building-columns me-2"></i>{{ $showDeleted ? 'Inactive bank accounts' : 'Bank accounts' }}</h1>
            <p class="mb-0 small opacity-75">
                {{ $showDeleted
                    ? 'Restore accounts for customer payments, transfers, and other income/expense.'
                    : 'Cash book bank accounts — balances updated by receipts, transfers, and accounting entries.' }}
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if ($showDeleted)
                <a href="{{ route('admin.banks.index') }}" class="btn btn-light btn-sm">
                    <i class="fas fa-university me-1"></i> Active
                </a>
            @else
                <a href="{{ route('admin.banks.index', ['deleted' => 1]) }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-box-archive me-1"></i> Inactive
                </a>
            @endif
            <a href="{{ route('admin.banks.audit') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
            <a href="{{ route('admin.banks.export') }}" class="btn btn-outline-light btn-sm" title="Download all bank accounts as a CSV file">
                <i class="fas fa-file-csv me-1"></i> Export CSV
            </a>
            <a href="{{ route('admin.banks.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New account
            </a>
        </div>
    </header>

    {{-- Stats cards (hidden when viewing trashed) --}}
    @if (!$showDeleted)
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0f766e;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['active'] ?? 0)) }}</div>
                        <div class="text-muted small">Active accounts</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#d97706;">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk {{ number_format((float) ($stats['total_balance'] ?? 0), 2) }}</div>
                        <div class="text-muted small">Total balance (active)</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#475569;">
                        <i class="fas fa-book"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ $label }}</div>
                        <div class="text-muted small">Cash book / GL control</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Table panel --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <table class="table table-hover align-middle mb-0" id="bankTable">
                <thead class="table-light">
                    <tr>
                        <th>Bank</th>
                        <th>Account #</th>
                        <th>Holder</th>
                        <th class="d-none d-lg-table-cell">Branch</th>
                        <th class="text-end">Balance</th>
                        <th class="d-none d-md-table-cell">GL ledger</th>
                        <th>Status</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $bank)
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="rounded-circle bg-success-subtle text-success d-inline-flex align-items-center justify-content-center fw-bold"
                                          style="width:36px;height:36px;">
                                        {{ strtoupper(substr($bank->bank_name ?? '?', 0, 1)) }}
                                    </span>
                                    <a href="{{ route('admin.banks.show', $bank) }}" class="fw-semibold text-decoration-none text-reset">
                                        {{ $bank->bank_name }}
                                    </a>
                                </div>
                            </td>
                            <td>
                                @if ($bank->account_number)
                                    <span class="badge bg-secondary-subtle text-secondary">{{ $bank->account_number }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>{{ $bank->account_holder ?: '—' }}</td>
                            <td class="d-none d-lg-table-cell">{{ $bank->branch_name ?: '—' }}</td>
                            <td class="text-end fw-semibold">Tk {{ number_format((float) ($bank->balance ?? 0), 2) }}</td>
                            <td class="d-none d-md-table-cell">
                                @if ($bank->ledger)
                                    <span class="badge bg-info-subtle text-info">
                                        <i class="fas fa-book me-1"></i>{{ $bank->ledger->ledger_code }}
                                    </span>
                                    <div class="small text-muted">{{ $bank->ledger->ledger_name }}</div>
                                @else
                                    <span class="text-muted small">—</span>
                                @endif
                            </td>
                            <td>
                                @if ($bank->is_active)
                                    <span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i>Active</span>
                                @else
                                    <span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-circle-xmark me-1"></i>Inactive</span>
                                @endif
                            </td>
                            <td class="text-center text-nowrap">
                                <a href="{{ route('admin.banks.show', $bank) }}" class="btn btn-sm btn-outline-secondary" title="View">
                                    <i class="fas fa-circle-info"></i>
                                </a>
                                <a href="{{ route('admin.banks.edit', $bank) }}" class="btn btn-sm btn-outline-primary" title="Edit">
                                    <i class="fas fa-pen"></i>
                                </a>
                                @if ($showDeleted)
                                    <form method="POST" action="{{ route('admin.banks.restore', $bank) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-success" title="Restore">
                                            <i class="fas fa-rotate-left"></i>
                                        </button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.banks.destroy', $bank) }}" class="d-inline"
                                          onsubmit="return confirm('Deactivate this bank account?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Deactivate">
                                            <i class="fas fa-power-off"></i>
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">
                                <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                No bank accounts found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white">
            {{ $items->links() }}
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function() {
    $('#bankTable').DataTable({
        paging: false,
        info: false,
        ordering: true,
        searching: true,
        dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
        language: { search: 'Filter:', emptyTable: 'No bank accounts found.' }
    });
});
</script>
@endpush
@endsection

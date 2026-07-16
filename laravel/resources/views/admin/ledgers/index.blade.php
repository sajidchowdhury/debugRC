@extends('layouts.admin')

@section('content')
@php
    $stats = $stats ?? ['active' => 0, 'control_accounts' => 0, 'by_type' => []];
    $showDeleted = $showDeleted ?? false;
    $byType = $stats['by_type'] ?? [];
    $typeBadge = [
        'Asset'     => 'bg-primary-subtle text-primary',
        'Liability' => 'bg-danger-subtle text-danger',
        'Equity'    => 'bg-dark-subtle text-dark',
        'Income'    => 'bg-success-subtle text-success',
        'Expense'   => 'bg-warning-subtle text-warning',
    ];
@endphp

<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-book me-2"></i>{{ $showDeleted ? 'Inactive ledgers' : 'Chart of Accounts' }}</h1>
            <p class="mb-0 small opacity-75">
                {{ $showDeleted
                    ? 'Deactivated accounts — restore with Activate when safe (no journal conflicts).'
                    : 'General ledger heads for double-entry posting, trial balance, and automated journals.' }}
            </p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @if ($showDeleted)
                <a href="{{ route('admin.ledgers.index') }}" class="btn btn-light btn-sm">
                    <i class="fas fa-book me-1"></i> Active
                </a>
            @else
                <a href="{{ route('admin.ledgers.index', ['deleted' => 1]) }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-box-archive me-1"></i> Inactive
                </a>
            @endif
            <a href="{{ route('admin.ledgers.audit') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
            <a href="{{ route('admin.ledgers.create') }}" class="btn btn-light btn-sm">
                <i class="fas fa-plus me-1"></i> New ledger
            </a>
        </div>
    </header>

    @if (!$showDeleted)
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3">
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
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#4f46e5;">
                        <i class="fas fa-sitemap"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) ($stats['control_accounts'] ?? 0)) }}</div>
                        <div class="text-muted small">Control accounts</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small mb-2"><i class="fas fa-layer-group me-1"></i> By account type</div>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach (['Asset','Liability','Equity','Income','Expense'] as $type)
                            <span class="badge {{ $typeBadge[$type] }} fs-6">
                                {{ $type }}: {{ $byType[$type] ?? 0 }}
                            </span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h2 class="h6 mb-0"><i class="fas fa-list me-1 text-primary"></i> Accounts</h2>
            <select id="filterAccountType" class="form-select form-select-sm" style="max-width:200px;">
                <option value="">All account types</option>
                @foreach (['Asset','Liability','Equity','Income','Expense'] as $type)
                    <option value="{{ $type }}">{{ $type }}</option>
                @endforeach
            </select>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="ledgerTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code · Account</th>
                            <th class="d-none d-lg-table-cell">Parent</th>
                            <th>Type</th>
                            <th class="d-none d-xl-table-cell">Nature</th>
                            <th class="d-none d-lg-table-cell">Flags</th>
                            <th class="text-end">Opening</th>
                            <th>Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $ledger)
                            @php
                                $depth = 0;
                                $p = $ledger->parent;
                                while ($p) { $depth++; $p = $p->parent; }
                                $indent = $depth * 20;
                            @endphp
                            <tr data-account-type="{{ $ledger->account_type }}">
                                <td>
                                    <div class="d-flex align-items-center" style="padding-left: {{ $indent }}px;">
                                        <span class="badge bg-secondary-subtle text-secondary me-2">{{ $ledger->ledger_code }}</span>
                                        <a href="{{ route('admin.ledgers.show', $ledger) }}" class="fw-semibold text-decoration-none text-reset">
                                            {{ $ledger->ledger_name }}
                                        </a>
                                    </div>
                                </td>
                                <td class="d-none d-lg-table-cell">
                                    @if ($ledger->parent)
                                        <a href="{{ route('admin.ledgers.show', $ledger->parent) }}" class="text-decoration-none small">
                                            {{ $ledger->parent->ledger_name }}
                                        </a>
                                    @else
                                        <span class="text-muted">— top-level —</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $typeBadge[$ledger->account_type] ?? 'bg-secondary-subtle text-secondary' }}">
                                        {{ $ledger->account_type }}
                                    </span>
                                </td>
                                <td class="d-none d-xl-table-cell">
                                    @if ($ledger->ledger_nature)
                                        <span class="small text-muted">{{ str_replace('_', ' ', $ledger->ledger_nature) }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="d-none d-lg-table-cell">
                                    @if ($ledger->is_control_account)
                                        <span class="badge bg-success-subtle text-success me-1">
                                            <i class="fas fa-shield-halved me-1"></i>Control
                                        </span>
                                        @if ($ledger->control_account_type)
                                            <span class="badge bg-info-subtle text-info">{{ $ledger->control_account_type }}</span>
                                        @endif
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                                <td class="text-end">Tk {{ number_format((float) ($ledger->opening_balance ?? 0), 2) }}</td>
                                <td>
                                    @if ($ledger->is_active)
                                        <span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i>Active</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-circle-xmark me-1"></i>Inactive</span>
                                    @endif
                                </td>
                                <td class="text-center text-nowrap">
                                    <a href="{{ route('admin.ledgers.show', $ledger) }}" class="btn btn-sm btn-outline-secondary" title="View">
                                        <i class="fas fa-book-open"></i>
                                    </a>
                                    <a href="{{ route('admin.ledgers.edit', $ledger) }}" class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="fas fa-pen"></i>
                                    </a>
                                    @if ($showDeleted)
                                        <form method="POST" action="{{ route('admin.ledgers.restore', $ledger) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Restore">
                                                <i class="fas fa-rotate-left"></i>
                                            </button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.ledgers.destroy', $ledger) }}" class="d-inline"
                                              onsubmit="return confirm('Deactivate this ledger? Blocked if journal history or sole critical nature exists.');">
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
                                    No ledgers found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            {{ $items->links() }}
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function() {
    var table = $('#ledgerTable').DataTable({
        paging: false,
        info: false,
        ordering: true,
        dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
        language: { search: 'Filter:', emptyTable: 'No ledgers found.' }
    });

    $('#filterAccountType').on('change', function() {
        var val = $(this).val();
        table.search('').draw();
        if (val) {
            table.column(2).search(val).draw();
        } else {
            table.column(2).search('').draw();
        }
    });
});
</script>
@endpush
@endsection

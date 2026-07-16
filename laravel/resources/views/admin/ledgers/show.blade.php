@extends('layouts.admin')

@section('content')
@php
    $trashed = $item->trashed();
    $typeBadge = [
        'Asset'     => 'bg-primary-subtle text-primary',
        'Liability' => 'bg-danger-subtle text-danger',
        'Equity'    => 'bg-dark-subtle text-dark',
        'Income'    => 'bg-success-subtle text-success',
        'Expense'   => 'bg-warning-subtle text-warning',
    ];

    // Journal lines summary (best-effort — model relation may not be loaded)
    $lineCount = 0; $totalDebit = 0; $totalCredit = 0;
    if (method_exists($item, 'journalLines')) {
        try {
            $lines = $item->journalLines;
            $lineCount = $lines->count();
            $totalDebit = (float) $lines->sum('debit');
            $totalCredit = (float) $lines->sum('credit');
        } catch (\Throwable $e) {
            // journal_lines table may not exist yet — fall back to 0
        }
    }
    $netBalance = $totalDebit - $totalCredit;
@endphp

<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-book-open me-2"></i>{{ $item->ledger_name }}</h1>
            <p class="mb-0 small opacity-75">GL account hub — balance from journal lines and account details.</p>
            <span class="badge bg-white text-dark mt-2">
                @if ($item->is_active)
                    <i class="fas fa-circle-check text-success"></i> Active
                @else
                    <i class="fas fa-circle-xmark text-secondary"></i> Inactive
                @endif
                · {{ $item->ledger_code }}
            </span>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.ledgers.edit', $item) }}" class="btn btn-light btn-sm">
                <i class="fas fa-pen me-1"></i> Edit
            </a>
            <a href="{{ route('admin.ledgers.audit') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
            <a href="{{ route('admin.ledgers.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Chart of accounts
            </a>
        </div>
    </header>

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0f766e;">
                        <i class="fas fa-scale-balanced"></i>
                    </div>
                    <div>
                        <div class="h5 mb-0">Tk {{ number_format(abs($netBalance), 2) }}</div>
                        <div class="text-muted small">{{ $netBalance >= 0 ? 'Debit' : 'Credit' }} balance</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#d97706;">
                        <i class="fas fa-arrow-down"></i>
                    </div>
                    <div>
                        <div class="h5 mb-0">Tk {{ number_format($totalDebit, 2) }}</div>
                        <div class="text-muted small">Total debits</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#4f46e5;">
                        <i class="fas fa-arrow-up"></i>
                    </div>
                    <div>
                        <div class="h5 mb-0">Tk {{ number_format($totalCredit, 2) }}</div>
                        <div class="text-muted small">Total credits</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#475569;">
                        <i class="fas fa-file-invoice"></i>
                    </div>
                    <div>
                        <div class="h5 mb-0">{{ number_format($lineCount) }}</div>
                        <div class="text-muted small">Journal lines</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-primary"></i> Account details</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">Code</dt>
                        <dd class="col-sm-9"><span class="badge bg-secondary-subtle text-secondary">{{ $item->ledger_code }}</span></dd>

                        <dt class="col-sm-3 text-muted">Name</dt>
                        <dd class="col-sm-9">{{ $item->ledger_name }}</dd>

                        <dt class="col-sm-3 text-muted">Account type</dt>
                        <dd class="col-sm-9">
                            <span class="badge {{ $typeBadge[$item->account_type] ?? 'bg-secondary-subtle text-secondary' }}">
                                {{ $item->account_type }}
                            </span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Nature</dt>
                        <dd class="col-sm-9">{{ $item->ledger_nature ? str_replace('_', ' ', $item->ledger_nature) : '—' }}</dd>

                        <dt class="col-sm-3 text-muted">Parent</dt>
                        <dd class="col-sm-9">
                            @if ($item->parent)
                                <a href="{{ route('admin.ledgers.show', $item->parent) }}" class="text-decoration-none">
                                    {{ $item->parent->ledger_name }} ({{ $item->parent->ledger_code }})
                                </a>
                            @else
                                <span class="text-muted">Top-level account</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Control account</dt>
                        <dd class="col-sm-9">
                            @if ($item->is_control_account)
                                <span class="badge bg-success-subtle text-success">
                                    <i class="fas fa-shield-halved me-1"></i>Yes
                                </span>
                                @if ($item->control_account_type)
                                    <span class="badge bg-info-subtle text-info ms-1">{{ $item->control_account_type }}</span>
                                @endif
                            @else
                                <span class="text-muted">No</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Opening balance</dt>
                        <dd class="col-sm-9 fw-semibold">Tk {{ number_format((float) $item->opening_balance, 2) }}</dd>

                        <dt class="col-sm-3 text-muted">Sort order</dt>
                        <dd class="col-sm-9">{{ $item->sort_order ?? 0 }}</dd>

                        <dt class="col-sm-3 text-muted">Status</dt>
                        <dd class="col-sm-9">
                            @if ($item->is_active)
                                <span class="badge bg-success-subtle text-success">Active</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Created</dt>
                        <dd class="col-sm-9">{{ optional($item->created_at)->format('Y-m-d H:i') }}</dd>
                    </dl>
                </div>
            </div>

            @if ($item->children && $item->children->isNotEmpty())
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-sitemap me-1 text-primary"></i> Child accounts</h2>
                </div>
                <ul class="list-group list-group-flush">
                    @foreach ($item->children as $child)
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <a href="{{ route('admin.ledgers.show', $child) }}" class="text-decoration-none fw-semibold">
                                {{ $child->ledger_name }}
                            </a>
                            <span class="badge bg-secondary-subtle text-secondary">{{ $child->ledger_code }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
            @endif
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-gear me-1 text-secondary"></i> Actions</h2>
                </div>
                <div class="card-body d-grid gap-2">
                    <a href="{{ route('admin.ledgers.edit', $item) }}" class="btn btn-outline-primary">
                        <i class="fas fa-pen me-1"></i> Edit account
                    </a>
                    @if ($trashed)
                        <form method="POST" action="{{ route('admin.ledgers.restore', $item) }}">
                            @csrf
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-rotate-left me-1"></i> Restore
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.ledgers.destroy', $item) }}"
                              onsubmit="return confirm('Deactivate this ledger? Blocked if journal history or sole critical nature exists.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger w-100">
                                <i class="fas fa-power-off me-1"></i> Deactivate
                            </button>
                        </form>
                    @endif
                    <a href="{{ route('admin.ledgers.audit') }}" class="btn btn-outline-secondary">
                        <i class="fas fa-clock-rotate-left me-1"></i> View audit log
                    </a>
                </div>
            </div>

            @if (in_array($item->ledger_nature, App\Models\Ledger::criticalNatures()))
            <div class="card border-0 shadow-sm mt-3 border-start border-success border-4">
                <div class="card-body">
                    <h3 class="h6 mb-1"><i class="fas fa-shield-halved me-1 text-success"></i> Critical nature</h3>
                    <p class="small text-muted mb-0">
                        This ledger carries the critical nature <code>{{ $item->ledger_nature }}</code>.
                        The posting engine expects exactly one active ledger with this nature — deactivation
                        is blocked while no replacement exists.
                    </p>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection

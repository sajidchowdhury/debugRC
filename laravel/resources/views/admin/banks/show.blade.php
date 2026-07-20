@extends('layouts.admin')

@section('content')
@php
    $trashed = $item->trashed();
@endphp

<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0f766e,#14b8a6);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-building-columns me-2"></i>{{ $item->bank_name }}</h1>
            <p class="mb-0 small opacity-75">
                Bank hub — cash book balance, GL mapping, and account details.
            </p>
            <span class="badge bg-white text-dark mt-2">
                @if ($item->is_active)
                    <i class="fas fa-circle-check text-success"></i> Active
                @else
                    <i class="fas fa-circle-xmark text-secondary"></i> Inactive
                @endif
                · {{ $item->account_number ?: 'No account #' }}
            </span>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.banks.edit', $item) }}" class="btn btn-light btn-sm">
                <i class="fas fa-pen me-1"></i> Edit
            </a>
            <a href="{{ route('admin.banks.audit') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-clock-rotate-left me-1"></i> Audit
            </a>
            <a href="{{ route('admin.banks.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Accounts
            </a>
        </div>
    </header>

    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#d97706;">
                        <i class="fas fa-wallet"></i>
                    </div>
                    <div>
                        <div class="h5 mb-0">Tk {{ number_format((float) $item->balance, 2) }}</div>
                        <div class="text-muted small">Current balance</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#0f766e;">
                        <i class="fas fa-hashtag"></i>
                    </div>
                    <div>
                        <div class="h6 mb-0">{{ $item->account_number ?: '—' }}</div>
                        <div class="text-muted small">Account number</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#4f46e5;">
                        <i class="fas fa-user"></i>
                    </div>
                    <div>
                        <div class="h6 mb-0">{{ $item->account_holder ?: '—' }}</div>
                        <div class="text-muted small">Account holder</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#475569;">
                        <i class="fas fa-book"></i>
                    </div>
                    <div>
                        <div class="h6 mb-0">
                            @if ($item->ledger)
                                <a href="{{ route('admin.ledgers.show', $item->ledger) }}" class="text-decoration-none">
                                    {{ $item->ledger->ledger_code }}
                                </a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </div>
                        <div class="text-muted small">GL ledger</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-success"></i> Account details</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">Bank name</dt>
                        <dd class="col-sm-9">{{ $item->bank_name }}</dd>

                        <dt class="col-sm-3 text-muted">Account number</dt>
                        <dd class="col-sm-9">{{ $item->account_number ?: '—' }}</dd>

                        <dt class="col-sm-3 text-muted">Account holder</dt>
                        <dd class="col-sm-9">{{ $item->account_holder ?: '—' }}</dd>

                        <dt class="col-sm-3 text-muted">Branch</dt>
                        <dd class="col-sm-9">{{ $item->branch_name ?: '—' }}</dd>

                        <dt class="col-sm-3 text-muted">Balance</dt>
                        <dd class="col-sm-9 fw-semibold">Tk {{ number_format((float) $item->balance, 2) }}</dd>

                        <dt class="col-sm-3 text-muted">GL ledger</dt>
                        <dd class="col-sm-9">
                            @if ($item->ledger)
                                <a href="{{ route('admin.ledgers.show', $item->ledger) }}" class="text-decoration-none">
                                    {{ $item->ledger->ledger_code }} — {{ $item->ledger->ledger_name }}
                                </a>
                            @elseif ($item->ledgerMapping && $item->ledgerMapping->ledger)
                                <a href="{{ route('admin.ledgers.show', $item->ledgerMapping->ledger) }}" class="text-decoration-none">
                                    {{ $item->ledgerMapping->ledger->ledger_code }} — {{ $item->ledgerMapping->ledger->ledger_name }}
                                </a>
                                <span class="badge bg-warning-subtle text-warning ms-1">via mapping</span>
                            @else
                                <span class="text-muted">No GL ledger linked.</span>
                            @endif
                        </dd>

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

                        <dt class="col-sm-3 text-muted">Updated</dt>
                        <dd class="col-sm-9">{{ optional($item->updated_at)->format('Y-m-d H:i') }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-gear me-1 text-secondary"></i> Actions</h2>
                </div>
                <div class="card-body d-grid gap-2">
                    <a href="{{ route('admin.banks.edit', $item) }}" class="btn btn-outline-primary">
                        <i class="fas fa-pen me-1"></i> Edit account
                    </a>
                    @if ($trashed)
                        <form method="POST" action="{{ route('admin.banks.restore', $item) }}">
                            @csrf
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-rotate-left me-1"></i> Restore
                            </button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.banks.destroy', $item) }}"
                              onsubmit="return confirm('Deactivate this bank account?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger w-100">
                                <i class="fas fa-power-off me-1"></i> Deactivate
                            </button>
                        </form>
                    @endif
                    <a href="{{ route('admin.banks.audit') }}" class="btn btn-outline-secondary">
                        <i class="fas fa-clock-rotate-left me-1"></i> View audit log
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

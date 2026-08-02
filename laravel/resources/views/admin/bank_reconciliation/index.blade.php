@extends('layouts.admin')
@section('title', $title)

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="fas fa-university me-2"></i> Bank Reconciliation</h4>
        <a href="{{ route('admin.bank-reconciliation.create') }}" class="btn btn-primary btn-sm">
            <i class="fas fa-plus me-1"></i> New Reconciliation
        </a>
    </div>

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.bank-reconciliation.index') }}" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1">Bank</label>
                    <select name="bank_id" class="form-select form-select-sm">
                        <option value="">All Banks</option>
                        @foreach($banks as $bank)
                            <option value="{{ $bank->id }}" {{ request('bank_id') == $bank->id ? 'selected' : '' }}>
                                {{ $bank->bank_name }} ({{ $bank->account_number }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        @foreach(\App\Models\BankReconciliation::statusOptions() as $key => $label)
                            <option value="{{ $key }}" {{ request('status') == $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Reconciliations Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Bank</th>
                            <th>Period</th>
                            <th>Statement Bal</th>
                            <th>System Bal</th>
                            <th>Difference</th>
                            <th>Progress</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reconciliations as $recon)
                        <tr>
                            <td>
                                <a href="{{ route('admin.bank-reconciliation.show', $recon) }}" class="fw-semibold text-decoration-none">
                                    {{ $recon->reconciliation_code }}
                                </a>
                            </td>
                            <td>{{ $recon->bank?->bank_name }} <small class="text-muted">({{ $recon->bank?->account_number }})</small></td>
                            <td>{{ $recon->period_from->format('d M Y') }} — {{ $recon->period_to->format('d M Y') }}</td>
                            <td class="text-end">{{ number_format($recon->statement_closing_balance, 2) }}</td>
                            <td class="text-end">{{ number_format($recon->system_closing_balance, 2) }}</td>
                            <td class="text-end">
                                @if(abs($recon->difference) < 0.01)
                                    <span class="text-success fw-bold">0.00</span>
                                @else
                                    <span class="text-danger fw-bold">{{ number_format($recon->difference, 2) }}</span>
                                @endif
                            </td>
                            <td>
                                <div class="progress" style="height: 18px; min-width: 80px;">
                                    <div class="progress-bar {{ $recon->getMatchProgressPct() >= 100 ? 'bg-success' : 'bg-info' }}"
                                         style="width: {{ $recon->getMatchProgressPct() }}%">
                                        {{ $recon->getMatchProgressPct() }}%
                                    </div>
                                </div>
                                <small class="text-muted">{{ $recon->matched_lines }}/{{ $recon->total_statement_lines }}</small>
                            </td>
                            <td>
                                @php
                                    $statusColors = ['draft' => 'secondary', 'in_progress' => 'info', 'completed' => 'success', 'reversed' => 'warning'];
                                @endphp
                                <span class="badge bg-{{ $statusColors[$recon->status] ?? 'secondary' }}">
                                    {{ ucfirst(str_replace('_', ' ', $recon->status)) }}
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('admin.bank-reconciliation.show', $recon) }}" class="btn btn-outline-primary btn-sm py-0 px-2">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                No bank reconciliations found. <a href="{{ route('admin.bank-reconciliation.create') }}">Create one</a>.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-2">
        {{ $reconciliations->withQueryString()->links() }}
    </div>
</div>
@endsection

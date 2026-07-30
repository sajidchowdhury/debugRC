@extends('layouts.admin')

@section('content')
@php
    $modeBadge = function (string $mode) use ($transaction): string {
        $cls = ' fs-6';
        return [
            'cash'            => '<span class="badge bg-secondary' . $cls . '"><i class="fas fa-money-bill me-1"></i>Cash</span>',
            'bank'            => '<span class="badge bg-primary' . $cls . '"><i class="fas fa-university me-1"></i>Bank</span>',
            'mobile_banking' => '<span class="badge bg-info' . $cls . '"><i class="fas fa-mobile-screen me-1"></i>Mobile</span>',
            'cheque'          => '<span class="badge bg-warning text-dark' . $cls . '"><i class="fas fa-money-check me-1"></i>Cheque</span>',
            'adjustment'      => '<span class="badge bg-dark' . $cls . '"><i class="fas fa-sliders me-1"></i>Adjustment</span>',
        ][$mode] ?? '<span class="badge bg-light text-dark' . $cls . '">' . e($mode) . '</span>';
    };

    $statusBadge = function (bool $large = false) use ($transaction): string {
        $cls = $large ? ' fs-5' : ' fs-6';
        if ($transaction->is_reversed) {
            return '<span class="badge bg-danger' . $cls . '"><i class="fas fa-rotate-left me-1"></i>Reversed</span>';
        }
        return '<span class="badge bg-success' . $cls . '"><i class="fas fa-check me-1"></i>Active</span>';
    };

    $typeBadge = function () use ($transaction): string {
        $type = $transaction->transaction_type ?? 'advance';
        $badges = [
            'advance'    => '<span class="badge bg-success fs-6"><i class="fas fa-hand-holding-dollar me-1"></i>Employee Advance</span>',
            'loan'       => '<span class="badge bg-warning text-dark fs-6"><i class="fas fa-landmark me-1"></i>Employee Loan</span>',
            'salary'     => '<span class="badge bg-primary fs-6"><i class="fas fa-money-bills me-1"></i>Salary Payment</span>',
            'repayment'  => '<span class="badge fs-6" style="background:#0d9488;color:#fff;"><i class="fas fa-arrow-rotate-left me-1"></i>Employee Repayment</span>',
            'deduction'  => '<span class="badge bg-purple fs-6" style="background:#7c3aed;color:#fff;"><i class="fas fa-minus-circle me-1"></i>Deduction</span>',
            'adjustment' => '<span class="badge bg-dark fs-6"><i class="fas fa-sliders me-1"></i>Adjustment</span>',
        ];
        return $badges[$type] ?? '<span class="badge bg-light text-dark fs-6">' . e($type) . '</span>';
    };

    $typeGradients = [
        'advance'    => 'linear-gradient(135deg,#d97706,#b45309)',
        'loan'       => 'linear-gradient(135deg,#d97706,#b45309)',
        'salary'     => 'linear-gradient(135deg,#d97706,#b45309)',
        'adjustment' => 'linear-gradient(135deg,#d97706,#b45309)',
        'repayment'  => 'linear-gradient(135deg,#059669,#0d9488)',
        'deduction'  => 'linear-gradient(135deg,#059669,#0d9488)',
    ];
    $heroGradient = $typeGradients[$transaction->transaction_type ?? 'advance'] ?? $typeGradients['advance'];

    $typeIcons = [
        'advance'    => 'fa-hand-holding-dollar',
        'loan'       => 'fa-landmark',
        'salary'     => 'fa-money-bills',
        'repayment'  => 'fa-arrow-rotate-left',
        'deduction'  => 'fa-minus-circle',
        'adjustment' => 'fa-sliders',
    ];
    $heroIcon = $typeIcons[$transaction->transaction_type ?? 'advance'] ?? $typeIcons['advance'];
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: {{ $heroGradient }};">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas {{ $heroIcon }} me-2"></i>{{ $transaction->getTransactionTypeLabel() }} {{ $transaction->transaction_code }}
                {!! $statusBadge() !!}
                {!! $typeBadge() !!}
            </h1>
            <p class="mb-0 small opacity-75">
                @if ($transaction->employee){{ $transaction->employee->employee_name }}@endif
                @if ($transaction->branch) · {{ $transaction->branch->branch_name }}@endif
                · {{ \Carbon\Carbon::parse($transaction->transaction_date)->format('d M Y') }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.employee-transactions.slip', $transaction->id) }}" class="btn btn-outline-light btn-sm" target="_blank">
                <i class="fas fa-print me-1"></i> Print Slip
            </a>
            <a href="{{ route('admin.employee-transactions.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Reversal alert --}}
    @if ($transaction->is_reversed)
        <div class="alert alert-danger d-flex align-items-start mb-3" role="alert">
            <i class="fas fa-rotate-left me-2 fa-lg text-danger"></i>
            <div class="flex-grow-1">
                <strong>This transaction has been reversed.</strong>
                <div class="mt-1 small">
                    @if ($transaction->reversed_at)
                        <span class="me-3"><i class="fas fa-calendar me-1"></i>
                            Reversed at: {{ \Carbon\Carbon::parse($transaction->reversed_at)->format('d M Y H:i') }}
                        </span>
                    @endif
                    @if ($transaction->reversed_by)
                        <span class="me-3"><i class="fas fa-user me-1"></i>
                            By: User #{{ $transaction->reversed_by }}
                        </span>
                    @endif
                </div>
                @if (!empty($transaction->reverse_reason))
                    <div class="mt-1">
                        <span class="text-muted">Reason:</span>
                        <em>{{ $transaction->reverse_reason }}</em>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="row g-3">
        {{-- Left: main details --}}
        <div class="col-lg-8">
            {{-- Transaction details card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-circle-info me-1 text-warning"></i> Transaction details
                    </h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">Transaction code</dt>
                        <dd class="col-sm-9">
                            <span class="badge bg-secondary-subtle text-secondary">{{ $transaction->transaction_code }}</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Transaction type</dt>
                        <dd class="col-sm-9">
                            {!! $typeBadge() !!}
                            <span class="text-muted small ms-2">{{ $transaction->getGlDescription() }}</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Transaction date</dt>
                        <dd class="col-sm-9">
                            {{ \Carbon\Carbon::parse($transaction->transaction_date)->format('d M Y') }}
                        </dd>

                        <dt class="col-sm-3 text-muted">Employee</dt>
                        <dd class="col-sm-9">
                            @if ($transaction->employee)
                                <strong>{{ $transaction->employee->employee_name }}</strong>
                                <span class="text-muted">({{ $transaction->employee->employee_code }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Branch</dt>
                        <dd class="col-sm-9">
                            @if ($transaction->branch)
                                {{ $transaction->branch->branch_name }}
                                <span class="text-muted small">({{ $transaction->branch->branch_code }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Payment mode</dt>
                        <dd class="col-sm-9">{!! $modeBadge($transaction->payment_mode) !!}</dd>

                        @if ($transaction->isBankMode() || $transaction->bank)
                            <dt class="col-sm-3 text-muted">Bank</dt>
                            <dd class="col-sm-9">
                                @if ($transaction->bank)
                                    <strong>{{ $transaction->bank->bank_name }}</strong>
                                    @if (!empty($transaction->bank->bank_code))
                                        <span class="text-muted small">({{ $transaction->bank->bank_code }})</span>
                                    @endif
                                    @if (!empty($transaction->bank->account_no))
                                        <div class="small text-muted">A/C: {{ $transaction->bank->account_no }}</div>
                                    @endif
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </dd>
                        @endif

                        @if ($transaction->collectedBy)
                            <dt class="col-sm-3 text-muted">Collected by</dt>
                            <dd class="col-sm-9">
                                {{ $transaction->collectedBy->name ?? $transaction->collectedBy->employee_code ?? 'Employee #' . $transaction->collected_by }}
                            </dd>
                        @endif

                        <dt class="col-sm-3 text-muted">Amount</dt>
                        <dd class="col-sm-9">
                            @if ($transaction->isOutflow())
                                <strong class="text-danger fs-5">Tk {{ number_format((float) $transaction->amount, 2) }}</strong>
                                <span class="text-muted small ms-1">(outflow)</span>
                            @else
                                <strong class="text-success fs-5">Tk {{ number_format((float) $transaction->amount, 2) }}</strong>
                                <span class="text-muted small ms-1">(inflow)</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Description</dt>
                        <dd class="col-sm-9">{!! nl2br(e($transaction->description ?: '—')) !!}</dd>

                        <dt class="col-sm-3 text-muted">Created by</dt>
                        <dd class="col-sm-9 small text-muted">
                            @if ($transaction->created_by) User #{{ $transaction->created_by }} @else — @endif
                            @if ($transaction->created_at) · {{ $transaction->created_at->format('d M Y H:i') }} @endif
                        </dd>
                    </dl>
                </div>
            </div>

            {{-- GL Journal Entry card --}}
            @if ($transaction->journalEntry)
                @php
                    $je           = $transaction->journalEntry;
                    $jeTotalDr    = 0;
                    $jeTotalCr    = 0;
                    foreach ($je->lines as $line) {
                        $jeTotalDr += (float) $line->debit;
                        $jeTotalCr += (float) $line->credit;
                    }
                @endphp
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-book me-1 text-primary"></i> GL Journal Entry
                        </h2>
                        @if ($je->is_reversed)
                            <span class="badge bg-danger-subtle text-danger">
                                <i class="fas fa-rotate-left me-1"></i>Reversed
                            </span>
                        @endif
                    </div>
                    <div class="card-body">
                        <dl class="row mb-3 small">
                            <dt class="col-sm-3 text-muted">JE #</dt>
                            <dd class="col-sm-9">
                                <span class="badge bg-secondary-subtle text-secondary">{{ $je->entry_no }}</span>
                            </dd>
                            <dt class="col-sm-3 text-muted">Date</dt>
                            <dd class="col-sm-9">
                                {{ \Carbon\Carbon::parse($je->entry_date)->format('d M Y') }}
                            </dd>
                            <dt class="col-sm-3 text-muted">Description</dt>
                            <dd class="col-sm-9">{{ $je->description ?: '—' }}</dd>
                            @if (!empty($je->source))
                                <dt class="col-sm-3 text-muted">Source</dt>
                                <dd class="col-sm-9">{{ $je->source }}</dd>
                            @endif
                        </dl>
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Ledger</th>
                                        <th class="text-end">Debit (Tk)</th>
                                        <th class="text-end">Credit (Tk)</th>
                                        <th>Memo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($je->lines as $line)
                                        <tr>
                                            <td>
                                                @if ($line->ledger)
                                                    <span class="fw-semibold">{{ $line->ledger->ledger_name }}</span>
                                                    @if (!empty($line->ledger->ledger_code))
                                                        <div class="small text-muted">{{ $line->ledger->ledger_code }}</div>
                                                    @endif
                                                @else
                                                    <span class="text-muted">Ledger #{{ $line->ledger_id }}</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if ((float) $line->debit > 0)
                                                    {{ number_format((float) $line->debit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if ((float) $line->credit > 0)
                                                    {{ number_format((float) $line->credit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="small text-muted">{{ $line->memo ?: '—' }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-3">No lines.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td class="text-end">Total</td>
                                        <td class="text-end">{{ number_format($jeTotalDr, 2) }}</td>
                                        <td class="text-end">{{ number_format($jeTotalCr, 2) }}</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Employee Ledger Entries card --}}
            @if ($employeeLedgerEntries->isNotEmpty())
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-list me-1 text-warning"></i> Employee ledger entries
                            <span class="badge bg-warning-subtle text-warning ms-1">
                                {{ $employeeLedgerEntries->count() }}
                            </span>
                        </h2>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th class="text-end">Debit (Tk)</th>
                                        <th class="text-end">Credit (Tk)</th>
                                        <th class="text-end">Balance (Tk)</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($employeeLedgerEntries as $ele)
                                        <tr>
                                            <td class="small text-nowrap">
                                                @if (!empty($ele->transaction_date))
                                                    {{ \Carbon\Carbon::parse($ele->transaction_date)->format('d M Y') }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">{{ $ele->transaction_type ?? '—' }}</span>
                                            </td>
                                            <td class="text-end">
                                                @if (!empty($ele->debit) && (float) $ele->debit > 0)
                                                    {{ number_format((float) $ele->debit, 2) }}
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if (!empty($ele->credit) && (float) $ele->credit > 0)
                                                    <span class="text-success">
                                                        {{ number_format((float) $ele->credit, 2) }}
                                                    </span>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td class="text-end fw-semibold">
                                                @if (!empty($ele->running_balance))
                                                    {{ number_format((float) $ele->running_balance, 2) }}
                                                @elseif (!empty($ele->balance))
                                                    {{ number_format((float) $ele->balance, 2) }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="small text-muted">
                                                {{ $ele->description ?: '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Right: aside actions --}}
        <div class="col-lg-4">
            {{-- Status card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-flag me-1 text-warning"></i> Status</h2>
                </div>
                <div class="card-body text-center">
                    <div class="mb-2">{!! $statusBadge(true) !!}</div>
                    <div class="small text-muted">
                        @if ($transaction->is_reversed)
                            Reversed — GL and employee ledger have been backed out.
                        @else
                            Active — GL posted, employee ledger updated.
                        @endif
                    </div>
                </div>
            </div>

            {{-- Amount card (highlighted) --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body text-center text-white"
                     style="background: {{ $heroGradient }};">
                    <div class="small text-uppercase opacity-75">
                        @if ($transaction->isOutflow())
                            {{ $transaction->getTransactionTypeLabel() }} amount
                        @else
                            {{ $transaction->getTransactionTypeLabel() }} amount
                        @endif
                    </div>
                    <div class="display-6 fw-bold my-2">
                        Tk {{ number_format((float) $transaction->amount, 2) }}
                    </div>
                    <div class="small opacity-75 mt-1">
                        <i class="fas fa-calendar me-1"></i>
                        {{ \Carbon\Carbon::parse($transaction->transaction_date)->format('d M Y') }}
                    </div>
                </div>
            </div>

            {{-- Employee payable card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-taka-sign me-1 text-info"></i> Employee payable</h2>
                </div>
                <div class="card-body text-center">
                    <div class="small text-muted">Current balance due</div>
                    <div class="h4 fw-bold {{ $employeeDue > 0 ? 'text-danger' : 'text-success' }}">
                        Tk {{ number_format((float) $employeeDue, 2) }}
                    </div>
                    <div class="small text-muted">
                        @if ($employeeDue > 0)
                            <i class="fas fa-arrow-up me-1"></i> We owe this employee
                        @elseif ($employeeDue < 0)
                            <i class="fas fa-arrow-down me-1"></i> Advance balance (employee owes us)
                        @else
                            <i class="fas fa-check me-1"></i> Settled
                        @endif
                    </div>
                </div>
            </div>

            {{-- GL Summary card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-calculator me-1 text-primary"></i> GL Summary</h2>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">GL rule</span>
                        <strong class="small">{{ $transaction->getGlDescription() }}</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">Sub-ledger</span>
                        <strong class="small">
                            @if ($transaction->isOutflow())
                                Debit (increase payable)
                            @else
                                Credit (reduce payable)
                            @endif
                        </strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">Bank book</span>
                        <strong class="small">
                            @if ($transaction->isBankMode() && $transaction->isOutflow())
                                Decrease
                            @else
                                No change
                            @endif
                        </strong>
                    </div>
                    @if ($transaction->journalEntry)
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted small">JE #</span>
                            <span class="badge bg-secondary-subtle text-secondary">{{ $transaction->journalEntry->entry_no }}</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Actions card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-bolt me-1 text-warning"></i> Actions</h2>
                </div>
                <div class="card-body d-grid gap-2">
                    {{-- Print slip (dedicated print view) --}}
                    <a href="{{ route('admin.employee-transactions.slip', $transaction->id) }}" class="btn btn-outline-primary w-100" target="_blank">
                        <i class="fas fa-print me-1"></i> Print Slip
                    </a>

                    @if (! $transaction->is_reversed && $canReverse)
                        <button type="button" class="btn btn-outline-danger w-100" id="reverseBtn">
                            <i class="fas fa-rotate-left me-1"></i> Reverse Transaction
                        </button>
                        <div class="alert alert-warning small mb-0">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Reversing backs out the GL journal entry and employee ledger.
                            Bank balance will be restored (if bank mode). This action cannot be undone.
                        </div>
                    @else
                        <div class="alert alert-secondary small mb-0">
                            <i class="fas fa-ban me-1"></i>
                            @if ($transaction->is_reversed)
                                This transaction is already reversed. No further actions available.
                            @else
                                This transaction cannot be reversed.
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Boot config for EmployeeTransaction.js --}}
<script>
    window.ET_BOOT = {
        baseUrl: '{{ url("/") }}/',
        csrf_token: '{{ csrf_token() }}',
        transactionId: {{ $transaction->id }},
        transactionCode: '{{ $transaction->transaction_code }}',
        routes: {
            'index': '{{ route("admin.employee-transactions.index") }}',
            'show': '{{ rtrim(route("admin.employee-transactions.show", ["id" => "{id}"]), "}") }}'.replace('{id}', ''),
            'reverse': '{{ route("admin.employee-transactions.reverse", ["id" => "{id}"]) }}'.replace('{id}', ''),
            'slip': '{{ route("admin.employee-transactions.slip", ["id" => "{id}"]) }}'.replace('{id}', ''),
            'employee-show': '{{ url("/admin/employees") }}/',
        },
    };
</script>

@push('scripts')
<script src="/assets/js/EmployeeTransaction.js"></script>
<script>
$(function () {
    // ====== Reverse transaction (SweetAlert2 prompt for reason — required) ======
    $('#reverseBtn').on('click', function (e) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Reverse this transaction?',
            html: '<p class="text-muted small mb-2">This will reverse the GL entry and employee ledger. ' +
                  'Bank balance will be restored (if bank mode). This action cannot be undone. Please provide a reason:</p>' +
                  '<textarea id="swalReverseReason" class="form-control" rows="3" ' +
                  'placeholder="Reason for reversal" maxlength="500"></textarea>',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-rotate-left"></i> Reverse Transaction',
            cancelButtonText: 'Keep Transaction',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
            focusConfirm: false,
            preConfirm: function () {
                var reason = $('#swalReverseReason').val();
                if (!reason || !reason.trim()) {
                    Swal.showValidationMessage('A reversal reason is required.');
                    return false;
                }
                return reason;
            }
        }).then(function (result) {
            if (result.isConfirmed && result.value) {
                // Submit via AJAX
                $.ajax({
                    url: '/admin/employee-transactions/' + window.ET_BOOT.transactionId + '/reverse?XTransformPort=' + window.ET_BOOT.baseUrl.split('/').pop(),
                    method: 'POST',
                    data: {
                        _token: window.ET_BOOT.csrf_token,
                        reverse_reason: result.value
                    },
                    success: function (resp) {
                        if (resp.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Reversed!',
                                text: resp.message || 'Transaction reversed successfully.',
                                timer: 2000,
                                showConfirmButton: false
                            }).then(function () {
                                location.reload();
                            });
                        } else {
                            Swal.fire('Error', resp.message || 'Failed to reverse.', 'error');
                        }
                    },
                    error: function (xhr) {
                        var msg = xhr.responseJSON?.message || 'An error occurred.';
                        Swal.fire('Error', msg, 'error');
                    }
                });
            }
        });
    });
});
</script>
@endpush
@endsection

@extends('layouts.admin')

@section('content')
@php
    $statusBadge = function (bool $large = false) use ($expense): string {
        $cls = $large ? ' fs-5' : ' fs-6';
        if ($expense->is_reversed) {
            return '<span class="badge bg-danger' . $cls . '"><i class="fas fa-rotate-left me-1"></i>Reversed</span>';
        }
        return '<span class="badge bg-success' . $cls . '"><i class="fas fa-check me-1"></i>Active</span>';
    };

    $modeBadge = function (string $mode): string {
        return [
            'cash' => '<span class="badge bg-secondary fs-6"><i class="fas fa-money-bill me-1"></i>Cash</span>',
            'bank' => '<span class="badge bg-primary fs-6"><i class="fas fa-university me-1"></i>Bank</span>',
        ][$mode] ?? '<span class="badge bg-light text-dark fs-6">' . e($mode) . '</span>';
    };
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#dc2626,#b91c1c);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-arrow-trend-down me-2"></i>Other Expense {{ $expense->expense_code }}
                {!! $statusBadge() !!}
            </h1>
            <p class="mb-0 small opacity-75">
                @if ($expense->branch){{ $expense->branch->branch_name }}@endif
                &middot; {{ \Carbon\Carbon::parse($expense->expense_date)->format('d M Y') }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.other-expenses.slip', ['id' => $expense->id]) }}" class="btn btn-outline-light btn-sm" target="_blank">
                <i class="fas fa-print me-1"></i> Print Slip
            </a>
            <a href="{{ route('admin.other-expenses.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Reversal alert --}}
    @if ($expense->is_reversed)
        <div class="alert alert-danger d-flex align-items-start mb-3" role="alert">
            <i class="fas fa-rotate-left me-2 fa-lg text-danger"></i>
            <div class="flex-grow-1">
                <strong>This expense has been reversed.</strong>
                <div class="mt-1 small">
                    @if ($expense->reversed_at)
                        <span class="me-3"><i class="fas fa-calendar me-1"></i>
                            Reversed at: {{ \Carbon\Carbon::parse($expense->reversed_at)->format('d M Y H:i') }}
                        </span>
                    @endif
                    @if ($expense->reversed_by)
                        <span class="me-3"><i class="fas fa-user me-1"></i>
                            By: User #{{ $expense->reversed_by }}
                        </span>
                    @endif
                </div>
                @if (!empty($expense->reverse_reason))
                    <div class="mt-1">
                        <span class="text-muted">Reason:</span>
                        <em>{{ $expense->reverse_reason }}</em>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="row g-3">
        {{-- Left: main details --}}
        <div class="col-lg-8">
            {{-- Expense details card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-circle-info me-1 text-danger"></i> Expense details
                    </h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">Expense code</dt>
                        <dd class="col-sm-9">
                            <span class="badge bg-secondary-subtle text-secondary">{{ $expense->expense_code }}</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Expense date</dt>
                        <dd class="col-sm-9">
                            {{ \Carbon\Carbon::parse($expense->expense_date)->format('d M Y') }}
                        </dd>

                        <dt class="col-sm-3 text-muted">Branch</dt>
                        <dd class="col-sm-9">
                            @if ($expense->branch)
                                <strong>{{ $expense->branch->branch_name }}</strong>
                                <span class="text-muted small">({{ $expense->branch->branch_code }})</span>
                            @else
                                <span class="text-muted">&mdash;</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Expense type</dt>
                        <dd class="col-sm-9">
                            @if ($expense->expense_type)
                                {{ $expense->expense_type }}
                            @else
                                <span class="text-muted">&mdash;</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Amount</dt>
                        <dd class="col-sm-9">
                            <strong class="text-danger fs-5">Tk {{ number_format((float) $expense->amount, 2) }}</strong>
                        </dd>

                        <dt class="col-sm-3 text-muted">Payment mode</dt>
                        <dd class="col-sm-9">
                            {!! $modeBadge($expense->payment_mode) !!}
                        </dd>

                        @if ($expense->payment_mode === 'bank')
                            <dt class="col-sm-3 text-muted">Bank</dt>
                            <dd class="col-sm-9">
                                @if ($expense->bank)
                                    <strong>{{ $expense->bank->bank_name }}</strong>
                                    @if (!empty($expense->bank->bank_code))
                                        <span class="text-muted small">({{ $expense->bank->bank_code }})</span>
                                    @endif
                                    @if (!empty($expense->bank->account_no))
                                        <div class="small text-muted">A/C: {{ $expense->bank->account_no }}</div>
                                    @endif
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </dd>
                        @endif

                        <dt class="col-sm-3 text-muted">Description</dt>
                        <dd class="col-sm-9">{!! nl2br(e($expense->description ?: '&mdash;')) !!}</dd>

                        <dt class="col-sm-3 text-muted">Created by</dt>
                        <dd class="col-sm-9 small text-muted">
                            @if ($expense->created_by) User #{{ $expense->created_by }} @else &mdash; @endif
                            @if ($expense->created_at) &middot; {{ $expense->created_at->format('d M Y H:i') }} @endif
                        </dd>
                    </dl>
                </div>
            </div>

            {{-- GL Journal Entry card --}}
            @if ($expense->journalEntry)
                @php
                    $je           = $expense->journalEntry;
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
                            <dd class="col-sm-9">{{ $je->description ?: '&mdash;' }}</dd>
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
                                                    <span class="text-muted">&mdash;</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if ((float) $line->credit > 0)
                                                    {{ number_format((float) $line->credit, 2) }}
                                                @else
                                                    <span class="text-muted">&mdash;</span>
                                                @endif
                                            </td>
                                            <td class="small text-muted">{{ $line->memo ?: '&mdash;' }}</td>
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
        </div>

        {{-- Right: aside actions --}}
        <div class="col-lg-4">
            {{-- Status card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-flag me-1 text-danger"></i> Status</h2>
                </div>
                <div class="card-body text-center">
                    <div class="mb-2">{!! $statusBadge(true) !!}</div>
                    <div class="small text-muted">
                        @if ($expense->is_reversed)
                            Reversed — GL and cash/bank ledgers have been backed out.
                        @else
                            Active — GL posted, cash/bank ledger updated.
                        @endif
                    </div>
                </div>
            </div>

            {{-- Amount card (highlighted) --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body text-center text-white"
                     style="background: linear-gradient(135deg,#dc2626,#b91c1c);">
                    <div class="small text-uppercase opacity-75">Expense amount</div>
                    <div class="display-6 fw-bold my-2">
                        Tk {{ number_format((float) $expense->amount, 2) }}
                    </div>
                    <div class="small opacity-75 mt-1">
                        <i class="fas fa-calendar me-1"></i>
                        {{ \Carbon\Carbon::parse($expense->expense_date)->format('d M Y') }}
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
                        <strong class="small">Dr Operating Expense / Cr {{ $expense->payment_mode === 'bank' ? 'Bank' : 'Cash' }}</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">Debit</span>
                        <strong class="small">
                            @if ($expense->ledger)
                                {{ $expense->ledger->ledger_name }}
                            @else
                                Operating Expense
                            @endif
                        </strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">Credit</span>
                        <strong class="small">
                            @if ($expense->payment_mode === 'bank' && $expense->bank)
                                {{ $expense->bank->bank_name }}
                            @else
                                Cash in Hand
                            @endif
                        </strong>
                    </div>
                    @if ($expense->journalEntry)
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted small">JE #</span>
                            <span class="badge bg-secondary-subtle text-secondary">{{ $expense->journalEntry->entry_no }}</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Reversal / Actions card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-bolt me-1 text-danger"></i> Actions</h2>
                </div>
                <div class="card-body d-grid gap-2">
                    {{-- Print slip --}}
                    <a href="{{ route('admin.other-expenses.slip', ['id' => $expense->id]) }}" class="btn btn-outline-primary w-100" target="_blank">
                        <i class="fas fa-print me-1"></i> Print Slip
                    </a>

                    @if ($canReverse && ! $expense->is_reversed)
                        <button type="button" class="btn btn-outline-danger w-100" id="reverseBtn">
                            <i class="fas fa-rotate-left me-1"></i> Reverse Expense
                        </button>
                        <div class="alert alert-warning small mb-0">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Reversing backs out the GL journal entry and cash/bank ledger. This action cannot be undone.
                        </div>
                        {{-- Reversal reason input --}}
                        <div id="reverseSection" class="d-none">
                            <label class="form-label small text-muted mb-1" for="reverse_reason">Reversal reason <span class="text-danger">*</span></label>
                            <textarea id="reverse_reason" class="form-control form-control-sm" rows="2"
                                      placeholder="Enter reason for reversal" maxlength="500"></textarea>
                            <button type="button" class="btn btn-danger btn-sm w-100 mt-2" id="confirmReverseBtn">
                                <i class="fas fa-rotate-left me-1"></i> Confirm Reversal
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-1" id="cancelReverseBtn">
                                Cancel
                            </button>
                        </div>
                    @elseif ($expense->is_reversed)
                        {{-- Reversal details --}}
                        @if (!empty($expense->reverse_reason))
                            <div class="alert alert-secondary small mb-0">
                                <i class="fas fa-comment me-1"></i>
                                <strong>Reversal reason:</strong> {{ $expense->reverse_reason }}
                            </div>
                        @endif
                        <div class="alert alert-secondary small mb-0">
                            <i class="fas fa-ban me-1"></i>
                            This expense is already reversed. No further actions available.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Boot config for reversal JS --}}
@php
    $expenseId = $expense->id;
    $expenseCode = $expense->expense_code;
    $reverseUrl = route('admin.other-expenses.reverse', ['id' => '__ID__']);
    $csrfToken = csrf_token();
@endphp
<script>
    window.OE_BOOT = {
        baseUrl: '{{ url("/") }}/',
        csrf_token: '{{ $csrfToken }}',
        expenseId: {{ $expenseId }},
        expenseCode: '{{ $expenseCode }}',
        routes: {
            'index': '{{ route("admin.other-expenses.index") }}',
            'show': '{{ route("admin.other-expenses.show", ["id" => "__ID__"]) }}'.replace('__ID__', ''),
            'reverse': '{{ $reverseUrl }}'.replace('__ID__', {{ $expenseId }}),
            'slip': '{{ route("admin.other-expenses.slip", ["id" => "__ID__"]) }}'.replace('__ID__', ''),
        },
    };
</script>

@push('scripts')
<script>
$(function () {
    // ====== Reverse expense ======
    var $reverseBtn = $('#reverseBtn');
    var $reverseSection = $('#reverseSection');
    var $confirmReverseBtn = $('#confirmReverseBtn');
    var $cancelReverseBtn = $('#cancelReverseBtn');
    var $reverseReason = $('#reverse_reason');

    if (!$reverseBtn.length) return;

    $reverseBtn.on('click', function (e) {
        e.preventDefault();
        $reverseSection.removeClass('d-none');
        $reverseBtn.addClass('d-none');
        $reverseReason.focus();
    });

    $cancelReverseBtn.on('click', function () {
        $reverseSection.addClass('d-none');
        $reverseBtn.removeClass('d-none');
        $reverseReason.val('');
    });

    $confirmReverseBtn.on('click', function () {
        var reason = $reverseReason.val();
        if (!reason || !reason.trim()) {
            Swal.fire({
                icon: 'warning',
                title: 'Reason required',
                text: 'Please provide a reason for the reversal.',
            });
            return;
        }

        Swal.fire({
            icon: 'warning',
            title: 'Reverse this expense?',
            html: '<p class="text-muted small mb-2">This will reverse the GL entry and cash/bank ledger. This action cannot be undone.</p>' +
                  '<p class="small"><strong>Reason:</strong> ' + $('<span>').text(reason).html() + '</p>',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-rotate-left"></i> Confirm Reversal',
            cancelButtonText: 'Keep Expense',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
        }).then(function (result) {
            if (result.isConfirmed) {
                $.ajax({
                    url: window.OE_BOOT.routes.reverse,
                    method: 'POST',
                    data: {
                        _token: window.OE_BOOT.csrf_token,
                        reverse_reason: reason
                    },
                    success: function (resp) {
                        if (resp.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Reversed!',
                                text: resp.message || 'Expense reversed successfully.',
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
                        var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'An error occurred.';
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

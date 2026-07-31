@extends('layouts.admin')

@section('content')
@php
    $expense      = $expense ?? null;
    $canReverse   = $canReverse ?? false;
    $glLines      = $expense->journalEntry->lines ?? collect();
    $journalEntry = $expense->journalEntry ?? null;

    $statusBadge = function (bool $large = false) use ($expense): string {
        $cls = $large ? ' fs-5' : ' fs-6';
        if ($expense->is_reversed) {
            return '<span class="badge bg-danger' . $cls . '"><i class="fas fa-rotate-left me-1"></i>Reversed</span>';
        }
        return '<span class="badge bg-success' . $cls . '"><i class="fas fa-check me-1"></i>Active</span>';
    };

    $modeBadge = function (string $mode): string {
        return [
            'cash'            => '<span class="badge bg-secondary fs-6"><i class="fas fa-money-bill me-1"></i>Cash</span>',
            'bank'            => '<span class="badge bg-primary fs-6"><i class="fas fa-university me-1"></i>Bank</span>',
            'mobile_banking' => '<span class="badge bg-info fs-6"><i class="fas fa-mobile-screen me-1"></i>Mobile</span>',
            'cheque'          => '<span class="badge bg-warning text-dark fs-6"><i class="fas fa-money-check me-1"></i>Cheque</span>',
        ][$mode] ?? '<span class="badge bg-light text-dark fs-6">' . e($mode) . '</span>';
    };
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#b91c1c,#991b1b);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-arrow-down me-2"></i>Other Expense {{ $expense->expense_code }}
                {!! $statusBadge() !!}
            </h1>
            <p class="mb-0 small opacity-75">
                {{ $expense->expense_date ? $expense->expense_date->format('d M Y') : '—' }}
                &middot; {!! $modeBadge($expense->payment_mode) !!}
            </p>
        </div>
        <div class="d-flex gap-2">
            @if($canReverse)
                <button class="btn btn-outline-light btn-sm" onclick="openReverseModal()">
                    <i class="fas fa-undo-alt me-1"></i> Reverse
                </button>
            @endif
            <a href="{{ route('admin.other-expenses.slip', $expense->id) }}" target="_blank" class="btn btn-outline-light btn-sm">
                <i class="fas fa-print me-1"></i> Print Slip
            </a>
            <a href="{{ route('admin.other-expenses.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Reversal alert --}}
    @if($expense->is_reversed)
        <div class="alert alert-danger d-flex align-items-start mb-3" role="alert">
            <i class="fas fa-rotate-left me-2 fa-lg text-danger"></i>
            <div class="flex-grow-1">
                <strong>This expense has been reversed.</strong>
                <div class="mt-1 small">
                    @if($expense->reversed_at)
                        <span class="me-3"><i class="fas fa-calendar me-1"></i>
                            Reversed at: {{ $expense->reversed_at->format('d M Y H:i') }}
                        </span>
                    @endif
                    @if($expense->reversed_by)
                        <span class="me-3"><i class="fas fa-user me-1"></i>
                            By: User #{{ $expense->reversed_by }}
                        </span>
                    @endif
                </div>
                @if($expense->reverse_reason)
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

                        <dt class="col-sm-3 text-muted">Expense type</dt>
                        <dd class="col-sm-9">{{ $expense->expense_type ?? 'General' }}</dd>

                        <dt class="col-sm-3 text-muted">Branch</dt>
                        <dd class="col-sm-9">
                            @if($expense->branch)
                                <strong>{{ $expense->branch->branch_name }}</strong>
                                <span class="text-muted small">({{ $expense->branch->branch_code }})</span>
                            @else
                                <span class="text-muted">&mdash;</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Date</dt>
                        <dd class="col-sm-9">
                            {{ $expense->expense_date ? $expense->expense_date->format('d M Y') : '—' }}
                        </dd>

                        <dt class="col-sm-3 text-muted">Payment mode</dt>
                        <dd class="col-sm-9">{!! $modeBadge($expense->payment_mode) !!}</dd>

                        @if($expense->bank)
                        <dt class="col-sm-3 text-muted">Bank</dt>
                        <dd class="col-sm-9">
                            <strong>{{ $expense->bank->bank_name }}</strong>
                            @if(!empty($expense->bank->account_no))
                                <div class="small text-muted">A/C: {{ $expense->bank->account_no }}</div>
                            @endif
                        </dd>
                        @endif

                        <dt class="col-sm-3 text-muted">Amount</dt>
                        <dd class="col-sm-9">
                            <strong class="text-danger fs-5">Tk {{ number_format((float) $expense->amount, 2) }}</strong>
                        </dd>

                        @if($expense->description)
                        <dt class="col-sm-3 text-muted">Description</dt>
                        <dd class="col-sm-9">{!! nl2br(e($expense->description)) !!}</dd>
                        @endif

                        <dt class="col-sm-3 text-muted">Created by</dt>
                        <dd class="col-sm-9 small text-muted">
                            @if($expense->createdBy) {{ $expense->createdBy->name }} @else System @endif
                            @if($expense->created_at) &middot; {{ $expense->created_at->format('d M Y H:i') }} @endif
                        </dd>
                    </dl>
                </div>
            </div>

            {{-- GL Journal Entry card --}}
            @if($journalEntry)
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white d-flex align-items-center">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-book me-1 text-primary"></i> GL Journal Entry
                    </h2>
                    <span class="badge bg-success-subtle text-success ms-auto">
                        <i class="fas fa-check me-1"></i>Balanced
                    </span>
                </div>
                <div class="card-body">
                    <div class="alert alert-info py-2 px-3 mb-3" style="font-size:0.82rem;">
                        <i class="fas fa-info-circle me-1"></i>
                        {{ $expense->getGlDescription() }}
                        &middot; Journal #{{ $journalEntry->entry_no ?? $journalEntry->id }}
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Ledger</th>
                                    <th>Code</th>
                                    <th class="text-end">Debit</th>
                                    <th class="text-end">Credit</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $totalDr = 0; $totalCr = 0; @endphp
                                @foreach($glLines as $line)
                                <tr>
                                    <td>{{ $line->ledger->ledger_name ?? '—' }}</td>
                                    <td style="font-family:ui-monospace,monospace; font-size:0.82rem; color:#64748b;">{{ $line->ledger->ledger_code ?? '—' }}</td>
                                    <td class="text-end">
                                        @if($line->debit_amount > 0)
                                            <span class="text-danger fw-semibold">{{ number_format($line->debit_amount, 2) }}</span>
                                            @php $totalDr += $line->debit_amount; @endphp
                                        @else
                                            <span class="text-muted">&mdash;</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if($line->credit_amount > 0)
                                            <span class="text-success fw-semibold">{{ number_format($line->credit_amount, 2) }}</span>
                                            @php $totalCr += $line->credit_amount; @endphp
                                        @else
                                            <span class="text-muted">&mdash;</span>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                                <tr class="table-light fw-bold">
                                    <td colspan="2" class="text-end">Total</td>
                                    <td class="text-end text-danger">{{ number_format($totalDr, 2) }}</td>
                                    <td class="text-end text-success">{{ number_format($totalCr, 2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif
        </div>

        {{-- Right: amount card --}}
        <div class="col-lg-4">
            {{-- Amount card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body text-center p-4 rounded-3 text-white" style="background: linear-gradient(135deg,#b91c1c,#991b1b);">
                    <div class="small text-uppercase opacity-75 mb-1">Expense Amount</div>
                    <div class="h2 fw-bold mb-1">Tk {{ number_format((float) $expense->amount, 2) }}</div>
                    <div class="small opacity-75">
                        @if($expense->payment_mode === 'cash') Paid in Cash
                        @elseif($expense->payment_mode === 'bank') Via Bank Transfer
                        @elseif($expense->payment_mode === 'mobile_banking') Via Mobile Banking
                        @elseif($expense->payment_mode === 'cheque') Via Cheque
                        @else {{ ucfirst($expense->payment_mode) }}
                        @endif
                    </div>
                </div>
            </div>

            {{-- Quick Info card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-clock me-1 text-secondary"></i> Quick Info
                    </h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-muted small">Created</dt>
                        <dd class="col-sm-8 small">
                            {{ $expense->created_at ? $expense->created_at->format('d M Y, h:i A') : '—' }}
                        </dd>
                        <dt class="col-sm-4 text-muted small">Created By</dt>
                        <dd class="col-sm-8 small">
                            {{ $expense->createdBy->name ?? 'System' }}
                        </dd>
                        @if($expense->is_reversed)
                        <dt class="col-sm-4 text-muted small">Reversed By</dt>
                        <dd class="col-sm-8 small">
                            {{ $expense->reversedBy->name ?? 'System' }}
                        </dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Reverse Modal --}}
<div class="modal fade" id="reverseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle text-danger me-2"></i>Reverse Expense</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">This will reverse the GL journal entry for <strong>{{ $expense->expense_code }}</strong>. This action cannot be undone.</p>
                <textarea id="reverseReason" class="form-control" rows="3" placeholder="Enter reason for reversal (required)..."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger btn-sm" onclick="submitReverse()">Confirm Reverse</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
window.OE_BOOT = {
    expenseId: {{ $expense->id }},
    expenseCode: '{{ $expense->expense_code }}',
    routes: {
        reverse: '{{ route("admin.other-expenses.reverse", ["id" => "__ID__"]) }}',
        index: '{{ route("admin.other-expenses.index") }}',
    }
};

function openReverseModal() {
    var modal = new bootstrap.Modal(document.getElementById('reverseModal'));
    document.getElementById('reverseReason').value = '';
    modal.show();
}

function submitReverse() {
    var reason = document.getElementById('reverseReason').value.trim();
    if (!reason || reason.length < 3) {
        Swal.fire('Validation Error', 'Please provide a reason (at least 3 characters).', 'warning');
        return;
    }

    var url = OE_BOOT.routes.reverse.replace('__ID__', OE_BOOT.expenseId);

    Swal.fire({
        title: 'Confirm Reversal?',
        html: 'Reverse expense <strong>{{ $expense->expense_code }}</strong>?<br>This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#b91c1c',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, Reverse',
    }).then(function(result) {
        if (!result.isConfirmed) return;

        $.ajax({
            url: url,
            method: 'POST',
            data: {
                _token: '{{ csrf_token() }}',
                reverse_reason: reason,
            },
            dataType: 'json',
            success: function(resp) {
                var modal = bootstrap.Modal.getInstance(document.getElementById('reverseModal'));
                if (modal) modal.hide();
                Swal.fire({
                    icon: 'success',
                    title: 'Reversed!',
                    text: resp.message || 'Expense reversed successfully.',
                    confirmButtonColor: '#b91c1c',
                }).then(function() { window.location.reload(); });
            },
            error: function(xhr) {
                var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Something went wrong.';
                Swal.fire('Error', msg, 'error');
            }
        });
    });
}
</script>
@endpush
@endsection

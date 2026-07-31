@extends('layouts.admin')

@section('content')
@php
    $je = $journal->journalEntry;
    $jeTotalDr = 0;
    $jeTotalCr = 0;
    if ($je) {
        foreach ($je->lines as $line) {
            $jeTotalDr += (float) $line->debit;
            $jeTotalCr += (float) $line->credit;
        }
    }
@endphp

<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#6366f1,#4f46e5);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-book me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Manual journal detail — {!! $journal->getStatusBadge() !!}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.manual-journals.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
            @if ($canReverse)
                <button type="button" class="btn btn-danger btn-sm" id="reverseBtn">
                    <i class="fas fa-rotate-left me-1"></i> Reverse
                </button>
            @endif
        </div>
    </header>

    {{-- Header card --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="small text-muted">Journal code</div>
                    <div class="fw-semibold">{{ $journal->journal_code }}</div>
                </div>
                <div class="col-md-3">
                    <div class="small text-muted">Date</div>
                    <div>{{ \Carbon\Carbon::parse($journal->journal_date)->format('d M Y') }}</div>
                </div>
                <div class="col-md-3">
                    <div class="small text-muted">Branch</div>
                    <div>{{ $journal->branch ? $journal->branch->branch_name : '—' }}</div>
                </div>
                <div class="col-md-3">
                    <div class="small text-muted">Status</div>
                    <div>{!! $journal->getStatusBadge() !!}</div>
                </div>
                <div class="col-12">
                    <div class="small text-muted">Description</div>
                    <div>{{ $journal->description ?: '—' }}</div>
                </div>
                @if ($journal->status === 'reversed')
                    <div class="col-md-4">
                        <div class="small text-muted">Reversed at</div>
                        <div>{{ $journal->reversed_at ? \Carbon\Carbon::parse($journal->reversed_at)->format('d M Y H:i') : '—' }}</div>
                    </div>
                    <div class="col-md-8">
                        <div class="small text-muted">Reversal reason</div>
                        <div class="text-danger">{{ $journal->reverse_reason ?: '—' }}</div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- GL Journal Entry card --}}
    @if ($je)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0">
                    <i class="fas fa-book me-1 text-primary"></i> GL Journal Entry
                    <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $je->entry_no }}</span>
                    @if ($je->is_reversed)
                        <span class="badge bg-danger-subtle text-danger ms-1"><i class="fas fa-rotate-left me-1"></i>Reversed</span>
                    @endif
                </h2>
            </div>
            <div class="card-body p-0">
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
                                <tr><td colspan="4" class="text-center text-muted py-3">No lines.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td class="text-end">Total</td>
                                <td class="text-end text-success">{{ number_format($jeTotalDr, 2) }}</td>
                                <td class="text-end text-danger">{{ number_format($jeTotalCr, 2) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    @else
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body text-center text-muted py-4">
                <i class="fas fa-info-circle me-1"></i>
                This journal is a draft — no GL entry has been posted yet.
            </div>
        </div>
    @endif
</div>

<script>
    window.MJ_BOOT = {
        csrf_token: '{{ csrf_token() }}',
        journalId: {{ $journal->id }},
        journalCode: '{{ $journal->journal_code }}',
        entryNo: '{{ $je?->entry_no ?? '' }}',
        routes: {
            'reverse': '{{ route("admin.manual-journals.reverse", ["id" => $journal->id]) }}',
            'index': '{{ route("admin.manual-journals.index") }}',
        },
    };
</script>

@push('scripts')
<script>
$(function () {
    $('#reverseBtn').on('click', function (e) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'Reverse this journal?',
            html: '<p class="text-muted small mb-2">This will reverse the GL entry. This action cannot be undone. Please provide a reason:</p>' +
                  '<textarea id="swalReverseReason" class="form-control" rows="3" placeholder="Reason for reversal" maxlength="500"></textarea>',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-rotate-left"></i> Reverse',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
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
                $.ajax({
                    url: window.MJ_BOOT.routes.reverse,
                    method: 'POST',
                    data: { _token: window.MJ_BOOT.csrf_token, reverse_reason: result.value },
                    success: function (resp) {
                        if (resp.status === 'success') {
                            Swal.fire({ icon: 'success', title: 'Reversed!', text: resp.message, timer: 2000, showConfirmButton: false })
                                .then(function () { location.reload(); });
                        } else {
                            Swal.fire('Error', resp.message || 'Failed to reverse.', 'error');
                        }
                    },
                    error: function (xhr) {
                        Swal.fire('Error', xhr.responseJSON?.message || 'An error occurred.', 'error');
                    }
                });
            }
        });
    });
});
</script>
@endpush
@endsection

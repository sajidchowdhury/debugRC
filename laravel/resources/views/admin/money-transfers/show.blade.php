@extends('layouts.admin')

@section('content')
@php
    $typeBadge = function () use ($transfer): string {
        $badges = [
            'cash_to_bank' => '<span class="badge bg-primary fs-6"><i class="fas fa-university me-1"></i>Cash to Bank</span>',
            'bank_to_cash' => '<span class="badge bg-secondary fs-6"><i class="fas fa-money-bill me-1"></i>Bank to Cash</span>',
            'cash_to_cash' => '<span class="badge bg-success fs-6"><i class="fas fa-money-bill-transfer me-1"></i>Cash to Cash</span>',
            'bank_to_bank' => '<span class="badge bg-info fs-6"><i class="fas fa-exchange-alt me-1"></i>Bank to Bank</span>',
        ];
        return $badges[$transfer->transfer_type] ?? '<span class="badge bg-light text-dark fs-6">' . e($transfer->transfer_type) . '</span>';
    };

    $statusBadge = function (bool $large = false) use ($transfer): string {
        $cls = $large ? ' fs-5' : ' fs-6';
        if ($transfer->is_reversed) {
            return '<span class="badge bg-danger' . $cls . '"><i class="fas fa-rotate-left me-1"></i>Reversed</span>';
        }
        return '<span class="badge bg-success' . $cls . '"><i class="fas fa-check me-1"></i>Active</span>';
    };

    $typeLabel = function (string $type): string {
        return [
            'cash_to_bank' => 'Cash to Bank',
            'bank_to_cash' => 'Bank to Cash',
            'cash_to_cash' => 'Cash to Cash',
            'bank_to_bank' => 'Bank to Bank',
        ][$type] ?? ucfirst(str_replace('_', ' ', $type));
    };

    $glInfo = function (string $type): string {
        return [
            'cash_to_bank' => 'Dr Bank · Cr Cash',
            'bank_to_cash' => 'Dr Cash · Cr Bank',
            'cash_to_cash' => 'Dr Cash (to branch) · Cr Cash (from branch)',
            'bank_to_bank' => 'Dr Bank (to) · Cr Bank (from)',
        ][$type] ?? '—';
    };
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0d9488,#059669);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-exchange-alt me-2"></i>Money Transfer {{ $transfer->transfer_code }}
                {!! $statusBadge() !!}
                {!! $typeBadge() !!}
            </h1>
            <p class="mb-0 small opacity-75">
                @if ($transfer->fromBranch){{ $transfer->fromBranch->branch_name }}@endif
                &rarr;
                @if ($transfer->toBranch){{ $transfer->toBranch->branch_name }}@endif
                &middot; {{ \Carbon\Carbon::parse($transfer->transfer_date)->format('d M Y') }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.money-transfers.slip', $transfer->id) }}" class="btn btn-outline-light btn-sm" target="_blank">
                <i class="fas fa-print me-1"></i> Print Slip
            </a>
            <a href="{{ route('admin.money-transfers.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Reversal alert --}}
    @if ($transfer->is_reversed)
        <div class="alert alert-danger d-flex align-items-start mb-3" role="alert">
            <i class="fas fa-rotate-left me-2 fa-lg text-danger"></i>
            <div class="flex-grow-1">
                <strong>This transfer has been reversed.</strong>
                <div class="mt-1 small">
                    @if ($transfer->reversed_at)
                        <span class="me-3"><i class="fas fa-calendar me-1"></i>
                            Reversed at: {{ \Carbon\Carbon::parse($transfer->reversed_at)->format('d M Y H:i') }}
                        </span>
                    @endif
                    @if ($transfer->reversed_by)
                        <span class="me-3"><i class="fas fa-user me-1"></i>
                            By: User #{{ $transfer->reversed_by }}
                        </span>
                    @endif
                </div>
                @if (!empty($transfer->reverse_reason))
                    <div class="mt-1">
                        <span class="text-muted">Reason:</span>
                        <em>{{ $transfer->reverse_reason }}</em>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="row g-3">
        {{-- Left: main details --}}
        <div class="col-lg-8">
            {{-- Transfer details card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-circle-info me-1 text-success"></i> Transfer details
                    </h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">Transfer code</dt>
                        <dd class="col-sm-9">
                            <span class="badge bg-secondary-subtle text-secondary">{{ $transfer->transfer_code }}</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Transfer type</dt>
                        <dd class="col-sm-9">
                            {!! $typeBadge() !!}
                            <span class="text-muted small ms-2">{{ $glInfo($transfer->transfer_type) }}</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Transfer date</dt>
                        <dd class="col-sm-9">
                            {{ \Carbon\Carbon::parse($transfer->transfer_date)->format('d M Y') }}
                        </dd>

                        <dt class="col-sm-3 text-muted">From branch</dt>
                        <dd class="col-sm-9">
                            @if ($transfer->fromBranch)
                                <strong>{{ $transfer->fromBranch->branch_name }}</strong>
                                <span class="text-muted small">({{ $transfer->fromBranch->branch_code }})</span>
                            @else
                                <span class="text-muted">&mdash;</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">To branch</dt>
                        <dd class="col-sm-9">
                            @if ($transfer->toBranch)
                                <strong>{{ $transfer->toBranch->branch_name }}</strong>
                                <span class="text-muted small">({{ $transfer->toBranch->branch_code }})</span>
                            @else
                                <span class="text-muted">&mdash;</span>
                            @endif
                        </dd>

                        @if (in_array($transfer->transfer_type, ['bank_to_cash', 'bank_to_bank']))
                            <dt class="col-sm-3 text-muted">From bank</dt>
                            <dd class="col-sm-9">
                                @if ($transfer->fromBank)
                                    <strong>{{ $transfer->fromBank->bank_name }}</strong>
                                    @if (!empty($transfer->fromBank->bank_code))
                                        <span class="text-muted small">({{ $transfer->fromBank->bank_code }})</span>
                                    @endif
                                    @if (!empty($transfer->fromBank->account_no))
                                        <div class="small text-muted">A/C: {{ $transfer->fromBank->account_no }}</div>
                                    @endif
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </dd>
                        @endif

                        @if (in_array($transfer->transfer_type, ['cash_to_bank', 'bank_to_bank']))
                            <dt class="col-sm-3 text-muted">To bank</dt>
                            <dd class="col-sm-9">
                                @if ($transfer->toBank)
                                    <strong>{{ $transfer->toBank->bank_name }}</strong>
                                    @if (!empty($transfer->toBank->bank_code))
                                        <span class="text-muted small">({{ $transfer->toBank->bank_code }})</span>
                                    @endif
                                    @if (!empty($transfer->toBank->account_no))
                                        <div class="small text-muted">A/C: {{ $transfer->toBank->account_no }}</div>
                                    @endif
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </dd>
                        @endif

                        <dt class="col-sm-3 text-muted">Amount</dt>
                        <dd class="col-sm-9">
                            <strong class="text-success fs-5">Tk {{ number_format((float) $transfer->amount, 2) }}</strong>
                        </dd>

                        <dt class="col-sm-3 text-muted">Notes</dt>
                        <dd class="col-sm-9">{!! nl2br(e($transfer->notes ?: '&mdash;')) !!}</dd>

                        <dt class="col-sm-3 text-muted">Created by</dt>
                        <dd class="col-sm-9 small text-muted">
                            @if ($transfer->created_by) User #{{ $transfer->created_by }} @else &mdash; @endif
                            @if ($transfer->created_at) &middot; {{ $transfer->created_at->format('d M Y H:i') }} @endif
                        </dd>
                    </dl>
                </div>
            </div>

            {{-- GL Journal Entry card --}}
            @if ($transfer->journalEntry)
                @php
                    $je           = $transfer->journalEntry;
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

            {{-- Cash Ledger Entries card --}}
            @if (isset($cashLedgerEntries) && $cashLedgerEntries->isNotEmpty())
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-money-bill me-1 text-success"></i> Cash ledger entries
                            <span class="badge bg-success-subtle text-success ms-1">
                                {{ $cashLedgerEntries->count() }}
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
                                    @foreach ($cashLedgerEntries as $cle)
                                        <tr>
                                            <td class="small text-nowrap">
                                                @if (!empty($cle->transaction_date))
                                                    {{ \Carbon\Carbon::parse($cle->transaction_date)->format('d M Y') }}
                                                @else
                                                    &mdash;
                                                @endif
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark">{{ $cle->transaction_type ?? '&mdash;' }}</span>
                                            </td>
                                            <td class="text-end">
                                                @if (!empty($cle->debit) && (float) $cle->debit > 0)
                                                    {{ number_format((float) $cle->debit, 2) }}
                                                @else
                                                    <span class="text-muted">&mdash;</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @if (!empty($cle->credit) && (float) $cle->credit > 0)
                                                    <span class="text-success">{{ number_format((float) $cle->credit, 2) }}</span>
                                                @else
                                                    <span class="text-muted">&mdash;</span>
                                                @endif
                                            </td>
                                            <td class="text-end fw-semibold">
                                                @if (!empty($cle->running_balance))
                                                    {{ number_format((float) $cle->running_balance, 2) }}
                                                @elseif (!empty($cle->balance))
                                                    {{ number_format((float) $cle->balance, 2) }}
                                                @else
                                                    &mdash;
                                                @endif
                                            </td>
                                            <td class="small text-muted">{{ $cle->description ?: '&mdash;' }}</td>
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
                    <h2 class="h6 mb-0"><i class="fas fa-flag me-1 text-success"></i> Status</h2>
                </div>
                <div class="card-body text-center">
                    <div class="mb-2">{!! $statusBadge(true) !!}</div>
                    <div class="small text-muted">
                        @if ($transfer->is_reversed)
                            Reversed — GL and cash/bank ledgers have been backed out.
                        @else
                            Active — GL posted, cash/bank ledgers updated.
                        @endif
                    </div>
                </div>
            </div>

            {{-- Amount card (highlighted) --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body text-center text-white"
                     style="background: linear-gradient(135deg,#0d9488,#059669);">
                    <div class="small text-uppercase opacity-75">Transfer amount</div>
                    <div class="display-6 fw-bold my-2">
                        Tk {{ number_format((float) $transfer->amount, 2) }}
                    </div>
                    <div class="small opacity-75 mt-1">
                        <i class="fas fa-calendar me-1"></i>
                        {{ \Carbon\Carbon::parse($transfer->transfer_date)->format('d M Y') }}
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
                        <strong class="small">{{ $glInfo($transfer->transfer_type) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">Cash ledger</span>
                        <strong class="small">
                            @if (in_array($transfer->transfer_type, ['cash_to_bank', 'cash_to_cash']))
                                Credit (reduce cash)
                            @elseif (in_array($transfer->transfer_type, ['bank_to_cash']))
                                Debit (increase cash)
                            @else
                                No change
                            @endif
                        </strong>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted small">Bank book</span>
                        <strong class="small">
                            @if (in_array($transfer->transfer_type, ['cash_to_bank', 'bank_to_bank']))
                                Debit (increase bank)
                            @elseif (in_array($transfer->transfer_type, ['bank_to_cash']))
                                Credit (decrease bank)
                            @else
                                No change
                            @endif
                        </strong>
                    </div>
                    @if ($transfer->journalEntry)
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted small">JE #</span>
                            <span class="badge bg-secondary-subtle text-secondary">{{ $transfer->journalEntry->entry_no }}</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Reversal / Actions card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-bolt me-1 text-success"></i> Actions</h2>
                </div>
                <div class="card-body d-grid gap-2">
                    {{-- Print slip --}}
                    <a href="{{ route('admin.money-transfers.slip', $transfer->id) }}" class="btn btn-outline-primary w-100" target="_blank">
                        <i class="fas fa-print me-1"></i> Print Slip
                    </a>

                    @if (! $transfer->is_reversed)
                        <button type="button" class="btn btn-outline-danger w-100" id="reverseBtn">
                            <i class="fas fa-rotate-left me-1"></i> Reverse Transfer
                        </button>
                        <div class="alert alert-warning small mb-0">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Reversing backs out the GL journal entry, cash/bank ledgers, and bank book balance.
                            This action cannot be undone.
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
                    @else
                        {{-- Reversal details --}}
                        @if (!empty($transfer->reverse_reason))
                            <div class="alert alert-secondary small mb-0">
                                <i class="fas fa-comment me-1"></i>
                                <strong>Reversal reason:</strong> {{ $transfer->reverse_reason }}
                            </div>
                        @endif
                        <div class="alert alert-secondary small mb-0">
                            <i class="fas fa-ban me-1"></i>
                            This transfer is already reversed. No further actions available.
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Boot config for reversal JS --}}
<script>
    window.MT_BOOT = {
        baseUrl: '{{ url("/") }}/',
        csrf_token: '{{ csrf_token() }}',
        transferId: {{ $transfer->id }},
        transferCode: '{{ $transfer->transfer_code }}',
        routes: {
            'index': '{{ route("admin.money-transfers.index") }}',
            'show': '{{ rtrim(route("admin.money-transfers.show", ["money_transfer" => "{id}"]), "}") }}'.replace('{id}', ''),
            'reverse': '{{ url("/admin/money-transfers") }}/' + {{ $transfer->id }} + '/reverse',
            'slip': '{{ route("admin.money-transfers.slip", ["id" => "{id}"]) }}'.replace('{id}', ''),
        },
    };
</script>

@push('scripts')
<script>
$(function () {
    // ====== Reverse transfer ======
    var $reverseBtn = $('#reverseBtn');
    var $reverseSection = $('#reverseSection');
    var $confirmReverseBtn = $('#confirmReverseBtn');
    var $cancelReverseBtn = $('#cancelReverseBtn');
    var $reverseReason = $('#reverse_reason');

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
            title: 'Reverse this transfer?',
            html: '<p class="text-muted small mb-2">This will reverse the GL entry, cash/bank ledgers, and bank book balance. This action cannot be undone.</p>' +
                  '<p class="small"><strong>Reason:</strong> ' + $('<span>').text(reason).html() + '</p>',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-rotate-left"></i> Confirm Reversal',
            cancelButtonText: 'Keep Transfer',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
        }).then(function (result) {
            if (result.isConfirmed) {
                $.ajax({
                    url: window.MT_BOOT.routes.reverse,
                    method: 'POST',
                    data: {
                        _token: window.MT_BOOT.csrf_token,
                        reverse_reason: reason
                    },
                    success: function (resp) {
                        if (resp.status === 'success') {
                            Swal.fire({
                                icon: 'success',
                                title: 'Reversed!',
                                text: resp.message || 'Transfer reversed successfully.',
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

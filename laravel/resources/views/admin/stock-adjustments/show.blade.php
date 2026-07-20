@extends('layouts.admin')

@section('content')
@php
    $adj = $adjustment;

    $statusBadge = function () use ($adj): string {
        return [
            'draft'     => '<span class="badge bg-warning-subtle text-warning fs-6"><i class="fas fa-pen-to-square me-1"></i>Draft</span>',
            'confirmed' => '<span class="badge bg-success-subtle text-success fs-6"><i class="fas fa-circle-check me-1"></i>Confirmed</span>',
            'cancelled' => '<span class="badge bg-secondary-subtle text-secondary fs-6"><i class="fas fa-ban me-1"></i>Cancelled</span>',
        ][$adj->status] ?? '<span class="badge bg-light text-dark fs-6">' . e($adj->status) . '</span>';
    };

    $typeBadge = function () use ($adj): string {
        if ($adj->isIncrease()) {
            return '<span class="badge bg-success-subtle text-success"><i class="fas fa-arrow-up me-1"></i>Increase</span>';
        }
        if ($adj->isDecrease()) {
            return '<span class="badge bg-danger-subtle text-danger"><i class="fas fa-arrow-down me-1"></i>Decrease</span>';
        }
        return '<span class="badge bg-light text-dark">' . e($adj->adjustment_type) . '</span>';
    };

    $je           = $adj->journalEntry;
    $jeLines      = $je ? $je->lines : collect();
    $debitTotal   = $jeLines->sum(fn ($l) => (float) $l->debit);
    $creditTotal  = $jeLines->sum(fn ($l) => (float) $l->credit);
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0f766e,#0891b2);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-scale-balanced me-2"></i>{{ $title }}
                {!! $statusBadge() !!}
                @if ($adj->is_reversed)
                    <span class="badge bg-danger ms-1"><i class="fas fa-rotate-left me-1"></i>Reversed</span>
                @endif
            </h1>
            <p class="mb-0 small opacity-75">
                @if ($adj->warehouse)
                    {{ $adj->warehouse->warehouse_name }}
                    @if ($adj->warehouse->branch) · {{ $adj->warehouse->branch->branch_name }} @endif
                @endif
            </p>
        </div>
        <div>
            <a href="{{ route('admin.stock-adjustments.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Reversal banner --}}
    @if ($adj->is_reversed)
        <div class="alert alert-danger d-flex align-items-center mb-3" role="alert">
            <i class="fas fa-rotate-left me-2 fa-lg"></i>
            <div>
                <strong>This adjustment has been reversed.</strong>
                @if ($adj->reversed_at)
                    Reversed on {{ \Carbon\Carbon::parse($adj->reversed_at)->format('d M Y H:i') }}
                @endif
                @if ($adj->reversed_by)
                    · by user #{{ $adj->reversed_by }}
                @endif
                @if ($adj->reverse_reason)
                    · Reason: <em>{{ $adj->reverse_reason }}</em>
                @endif
            </div>
        </div>
    @endif

    <div class="row g-3">
        {{-- Left: main details --}}
        <div class="col-lg-8">
            {{-- Adjustment details card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-primary"></i> Adjustment details</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">Code</dt>
                        <dd class="col-sm-9">
                            <span class="badge bg-secondary-subtle text-secondary">{{ $adj->adjustment_code }}</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Date</dt>
                        <dd class="col-sm-9">{{ \Carbon\Carbon::parse($adj->adjustment_date)->format('d M Y') }}</dd>

                        <dt class="col-sm-3 text-muted">Warehouse</dt>
                        <dd class="col-sm-9">
                            @if ($adj->warehouse)
                                <strong>{{ $adj->warehouse->warehouse_name }}</strong>
                                <span class="text-muted">({{ $adj->warehouse->warehouse_code }})</span>
                                @if ($adj->warehouse->branch)
                                    <div class="small text-muted">
                                        <i class="fas fa-building me-1"></i>{{ $adj->warehouse->branch->branch_name }}
                                    </div>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Branch</dt>
                        <dd class="col-sm-9">
                            @if ($adj->branch)
                                {{ $adj->branch->branch_name }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Type</dt>
                        <dd class="col-sm-9">{!! $typeBadge() !!}</dd>

                        <dt class="col-sm-3 text-muted">Status</dt>
                        <dd class="col-sm-9">{!! $statusBadge() !!}</dd>

                        <dt class="col-sm-3 text-muted">Reason</dt>
                        <dd class="col-sm-9">{!! nl2br(e($adj->reason ?: '—')) !!}</dd>

                        <dt class="col-sm-3 text-muted">Total amount</dt>
                        <dd class="col-sm-9"><strong>Tk {{ number_format((float) $adj->total_amount, 2) }}</strong></dd>

                        <dt class="col-sm-3 text-muted">Created</dt>
                        <dd class="col-sm-9 small text-muted">
                            {{ optional($adj->created_at)->format('Y-m-d H:i') }}
                            @if ($adj->created_by) · by user #{{ $adj->created_by }} @endif
                        </dd>
                    </dl>
                </div>
            </div>

            {{-- Items table --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-table-list me-1 text-primary"></i> Items
                        <span class="badge bg-primary-subtle text-primary ms-1">{{ $adj->items->count() }}</span>
                    </h2>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Qty</th>
                                    <th class="text-end">Rate (Tk)</th>
                                    <th class="text-end">Amount (Tk)</th>
                                    <th>Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($adj->items as $item)
                                    <tr>
                                        <td>
                                            @if ($item->product)
                                                <span class="fw-semibold">{{ $item->product->product_name }}</span>
                                                <div class="small text-muted">{{ $item->product->product_code }}</div>
                                            @else
                                                <span class="text-muted">Product #{{ $item->product_id }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ number_format((float) $item->qty, 4) }}</td>
                                        <td class="text-end">{{ number_format((float) $item->rate, 2) }}</td>
                                        <td class="text-end">{{ number_format($item->amount(), 2) }}</td>
                                        <td class="small">{{ $item->reason ?: '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center text-muted py-4">No items.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td colspan="3" class="text-end">Total</td>
                                    <td class="text-end">Tk {{ number_format((float) $adj->total_amount, 2) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Stock movements (only if confirmed) --}}
            @if ($adj->isConfirmed() && $stockTransactions->isNotEmpty())
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-boxes-stacked me-1 text-info"></i> Stock movements
                            <span class="badge bg-info-subtle text-info ms-1">{{ $stockTransactions->count() }}</span>
                        </h2>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>TX#</th>
                                        <th>Product</th>
                                        <th class="text-end">Qty</th>
                                        <th class="text-end">Rate (Tk)</th>
                                        <th class="text-end">Value (Tk)</th>
                                        <th class="text-center">Reversed?</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($stockTransactions as $st)
                                        @php
                                            $qty = (float) $st->qty;
                                            $qtyClass = $qty < 0 ? 'text-danger fw-bold' : 'text-success fw-bold';
                                        @endphp
                                        <tr>
                                            <td class="text-nowrap small">
                                                {{ \Carbon\Carbon::parse($st->transaction_date)->format('d M Y') }}
                                            </td>
                                            <td><span class="badge bg-light text-dark">#{{ $st->id }}</span></td>
                                            <td>
                                                <span class="fw-semibold">{{ $st->product_name }}</span>
                                                <div class="small text-muted">{{ $st->product_code }}</div>
                                            </td>
                                            <td class="text-end {{ $qtyClass }}">
                                                {{ $qty > 0 ? '+' : '' }}{{ number_format($qty, 4) }}
                                            </td>
                                            <td class="text-end">{{ number_format((float) $st->rate, 2) }}</td>
                                            <td class="text-end">{{ number_format((float) $st->total_value, 2) }}</td>
                                            <td class="text-center">
                                                @if (!empty($st->is_reversed))
                                                    <span class="badge bg-danger-subtle text-danger">
                                                        <i class="fas fa-rotate-left me-1"></i>Yes
                                                    </span>
                                                @else
                                                    <span class="badge bg-light text-muted">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- GL Journal Entry (only if confirmed + has JE) --}}
            @if ($adj->isConfirmed() && $je)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-book me-1 text-primary"></i> GL Journal Entry
                        </h2>
                        @if ($je->is_reversed)
                            <span class="badge bg-danger-subtle text-danger">
                                <i class="fas fa-rotate-left me-1"></i>Entry reversed
                            </span>
                        @endif
                    </div>
                    <div class="card-body">
                        <dl class="row mb-3 small">
                            <dt class="col-sm-2 text-muted">JE#</dt>
                            <dd class="col-sm-4"><span class="badge bg-secondary-subtle text-secondary">{{ $je->entry_no }}</span></dd>
                            <dt class="col-sm-2 text-muted">Entry date</dt>
                            <dd class="col-sm-4">{{ \Carbon\Carbon::parse($je->entry_date)->format('d M Y') }}</dd>
                            <dt class="col-sm-2 text-muted">Description</dt>
                            <dd class="col-sm-10">{{ $je->description ?: '—' }}</dd>
                        </dl>

                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Ledger</th>
                                        <th class="text-end">Debit (Tk)</th>
                                        <th class="text-end">Credit (Tk)</th>
                                        <th>Memo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($jeLines as $line)
                                        <tr>
                                            <td>
                                                @if ($line->ledger)
                                                    <span class="fw-semibold">{{ $line->ledger->ledger_name }}</span>
                                                    <div class="small text-muted">{{ $line->ledger->ledger_code }}</div>
                                                @else
                                                    <span class="text-muted">Ledger #{{ $line->ledger_id }}</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                {{ (float) $line->debit > 0 ? number_format((float) $line->debit, 2) : '—' }}
                                            </td>
                                            <td class="text-end">
                                                {{ (float) $line->credit > 0 ? number_format((float) $line->credit, 2) : '—' }}
                                            </td>
                                            <td class="small">{{ $line->memo ?: '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td class="text-end">Total</td>
                                        <td class="text-end">{{ number_format($debitTotal, 2) }}</td>
                                        <td class="text-end">{{ number_format($creditTotal, 2) }}</td>
                                        <td>
                                            @if (abs($debitTotal - $creditTotal) < 0.01)
                                                <span class="badge bg-success-subtle text-success">
                                                    <i class="fas fa-check me-1"></i>Balanced
                                                </span>
                                            @else
                                                <span class="badge bg-danger-subtle text-danger">
                                                    <i class="fas fa-triangle-exclamation me-1"></i>Out by
                                                    {{ number_format(abs($debitTotal - $creditTotal), 2) }}
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            @endif
        </div>

        {{-- Right: actions aside --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-gear me-1 text-secondary"></i> Status &amp; actions</h2>
                </div>
                <div class="card-body">
                    <div class="text-center mb-3">
                        <div class="text-muted small mb-1">Current status</div>
                        <div class="mb-2">{!! $statusBadge() !!}</div>
                        <div class="text-muted small">
                            Type: {!! $typeBadge() !!}
                        </div>
                    </div>

                    {{-- CONFIRM (draft only) --}}
                    @if ($adj->isDraft())
                        <form method="POST" action="{{ route('admin.stock-adjustments.confirm', $adj) }}"
                              id="confirmForm">
                            @csrf
                            <input type="hidden" name="confirm_reason" id="confirmReasonField" value="">
                            <button type="button" class="btn btn-success w-100 mb-2" id="confirmBtn">
                                <i class="fas fa-circle-check me-1"></i> Confirm adjustment
                            </button>
                        </form>
                        <div class="alert alert-info small mb-3">
                            <i class="fas fa-circle-info me-1"></i>
                            Confirming will <strong>apply stock movements</strong> and <strong>post the GL journal entry</strong>.
                        </div>
                    @endif

                    {{-- CANCEL (draft or confirmed) --}}
                    @if ($adj->isDraft() || $adj->isConfirmed())
                        <form method="POST" action="{{ route('admin.stock-adjustments.cancel', $adj) }}"
                              id="cancelForm">
                            @csrf
                            <input type="hidden" name="cancel_reason" id="cancelReasonField" value="">
                            <button type="button" class="btn btn-outline-danger w-100" id="cancelBtn">
                                <i class="fas fa-ban me-1"></i>
                                @if ($adj->isConfirmed())
                                    Cancel &amp; reverse
                                @else
                                    Cancel draft
                                @endif
                            </button>
                        </form>
                        @if ($adj->isConfirmed())
                            <div class="alert alert-warning small mt-2 mb-0">
                                <i class="fas fa-triangle-exclamation me-1"></i>
                                Cancelling a confirmed adjustment <strong>reverses the stock movements and the GL entry</strong>.
                                A reason is required.
                            </div>
                        @endif
                    @endif

                    @if ($adj->isCancelled())
                        <div class="alert alert-secondary small mb-0">
                            <i class="fas fa-ban me-1"></i>
                            This adjustment is cancelled and cannot be modified further.
                        </div>
                    @endif
                </div>
            </div>

            {{-- Quick facts card --}}
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-muted"></i> Quick facts</h2>
                </div>
                <div class="card-body small">
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Items</span>
                        <strong>{{ $adj->items->count() }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Total amount</span>
                        <strong>Tk {{ number_format((float) $adj->total_amount, 2) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Stock transactions</span>
                        <strong>{{ $stockTransactions->count() }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">GL journal</span>
                        @if ($je)
                            <strong>{{ $je->entry_no }}</strong>
                        @else
                            <span class="text-muted">Not posted</span>
                        @endif
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Reversed</span>
                        @if ($adj->is_reversed)
                            <span class="badge bg-danger-subtle text-danger">Yes</span>
                        @else
                            <span class="text-muted">No</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    // ====== Confirm (draft → confirmed) ======
    $('#confirmBtn').on('click', function () {
        Swal.fire({
            icon: 'question',
            title: 'Confirm this adjustment?',
            html: '<p class="text-start">This will <strong>apply stock movements</strong> and <strong>post the GL journal entry</strong>.</p>',
            input: 'textarea',
            inputLabel: 'Optional confirm reason',
            inputPlaceholder: 'e.g. Approved by manager after physical count.',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check"></i> Confirm',
            confirmButtonColor: '#198754',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        }).then(function (result) {
            if (result.isConfirmed) {
                $('#confirmReasonField').val(result.value || '');
                var $btn = $('#confirmBtn');
                $btn.prop('disabled', true)
                    .html('<i class="fas fa-spinner fa-spin me-1"></i> Confirming…');
                $('#confirmForm').submit();
            }
        });
    });

    // ====== Cancel (draft or confirmed) ======
    $('#cancelBtn').on('click', function () {
        var isConfirmed = @json($adj->isConfirmed());
        var title = isConfirmed ? 'Cancel & reverse this adjustment?' : 'Cancel this draft?';
        var html  = isConfirmed
            ? '<p class="text-start">This will <strong>reverse the stock movements and the GL journal entry</strong>. A reason is required.</p>'
            : '<p class="text-start">The draft will be marked cancelled. A reason is required.</p>';

        Swal.fire({
            icon: 'warning',
            title: title,
            html: html,
            input: 'textarea',
            inputLabel: 'Cancel reason (required)',
            inputPlaceholder: 'Why is this adjustment being cancelled?',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-ban"></i> Cancel adjustment',
            confirmButtonColor: '#dc3545',
            cancelButtonText: 'Keep',
            reverseButtons: true,
            inputValidator: function (value) {
                if (!value || !value.trim()) {
                    return 'A cancel reason is required.';
                }
                return null;
            }
        }).then(function (result) {
            if (result.isConfirmed) {
                $('#cancelReasonField').val(result.value.trim());
                var $btn = $('#cancelBtn');
                $btn.prop('disabled', true)
                    .html('<i class="fas fa-spinner fa-spin me-1"></i> Cancelling…');
                $('#cancelForm').submit();
            }
        });
    });
});
</script>
@endpush
@endsection

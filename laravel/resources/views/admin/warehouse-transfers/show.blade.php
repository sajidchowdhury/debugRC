@extends('layouts.admin')

@section('content')
@php
    $t = $transfer; // alias for brevity

    $statusBadge = function (bool $large = false) use ($t): string {
        $size = $large ? 'fs-5' : 'fs-6';
        return [
            'draft'     => '<span class="badge bg-warning-subtle text-warning ' . $size . '"><i class="fas fa-pen-to-square me-1"></i>Draft</span>',
            'confirmed' => '<span class="badge bg-success-subtle text-success ' . $size . '"><i class="fas fa-circle-check me-1"></i>Confirmed</span>',
            'cancelled' => '<span class="badge bg-secondary-subtle text-secondary ' . $size . '"><i class="fas fa-ban me-1"></i>Cancelled</span>',
        ][$t->status] ?? '<span class="badge bg-light text-dark ' . $size . '">' . e($t->status) . '</span>';
    };

    $interbranchBadge = function () use ($t): string {
        return $t->is_interbranch
            ? '<span class="badge bg-info-subtle text-info"><i class="fas fa-arrow-right-arrow-left me-1"></i>Interbranch</span>'
            : '<span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-warehouse me-1"></i>Same branch</span>';
    };

    // Normalize stockMovements to a Collection (controller returns [] when not confirmed)
    $stockMovements = collect($stockMovements);

    // From-branch (creditor) journal
    $creditorJe       = $t->journalEntry;
    $creditorLines    = $creditorJe ? $creditorJe->lines : collect();
    $creditorDr       = $creditorLines->sum(fn ($l) => (float) $l->debit);
    $creditorCr       = $creditorLines->sum(fn ($l) => (float) $l->credit);

    // To-branch (debtor) journal
    $debtorJe         = $t->debtorJournalEntry;
    $debtorLines      = $debtorJe ? $debtorJe->lines : collect();
    $debtorDr         = $debtorLines->sum(fn ($l) => (float) $l->debit);
    $debtorCr         = $debtorLines->sum(fn ($l) => (float) $l->credit);
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#ea580c,#d97706);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-right-left me-2"></i>{{ $title }}
                {!! $statusBadge() !!}
                {!! $interbranchBadge() !!}
                @if ($t->is_reversed)
                    <span class="badge bg-danger ms-1"><i class="fas fa-rotate-left me-1"></i>Reversed</span>
                @endif
            </h1>
            <p class="mb-0 small opacity-75">
                @if ($t->fromWarehouse)
                    {{ $t->fromWarehouse->warehouse_name }}
                    @if ($t->fromWarehouse->branch) · {{ $t->fromWarehouse->branch->branch_name }} @endif
                @endif
                <i class="fas fa-arrow-right mx-2"></i>
                @if ($t->toWarehouse)
                    {{ $t->toWarehouse->warehouse_name }}
                    @if ($t->toWarehouse->branch) · {{ $t->toWarehouse->branch->branch_name }} @endif
                @endif
            </p>
        </div>
        <div>
            <a href="{{ route('admin.warehouse-transfers.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Reversal banner --}}
    @if ($t->is_reversed)
        <div class="alert alert-danger d-flex align-items-center mb-3" role="alert">
            <i class="fas fa-rotate-left me-2 fa-lg"></i>
            <div>
                <strong>This transfer has been reversed.</strong>
                @if ($t->reversed_at)
                    Reversed on {{ \Carbon\Carbon::parse($t->reversed_at)->format('d M Y H:i') }}
                @endif
                @if ($t->reversed_by)
                    · by user #{{ $t->reversed_by }}
                @endif
                @if ($t->reverse_reason)
                    · Reason: <em>{{ $t->reverse_reason }}</em>
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
                    <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-primary"></i> Transfer details</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">Code</dt>
                        <dd class="col-sm-9">
                            <span class="badge bg-secondary-subtle text-secondary">{{ $t->transfer_code }}</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Date</dt>
                        <dd class="col-sm-9">{{ \Carbon\Carbon::parse($t->transfer_date)->format('d M Y') }}</dd>

                        <dt class="col-sm-3 text-muted">From warehouse</dt>
                        <dd class="col-sm-9">
                            @if ($t->fromWarehouse)
                                <strong>{{ $t->fromWarehouse->warehouse_name }}</strong>
                                <span class="text-muted">({{ $t->fromWarehouse->warehouse_code }})</span>
                                @if ($t->fromWarehouse->branch)
                                    <div class="small text-muted">
                                        <i class="fas fa-building me-1"></i>{{ $t->fromWarehouse->branch->branch_name }}
                                    </div>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">To warehouse</dt>
                        <dd class="col-sm-9">
                            @if ($t->toWarehouse)
                                <strong>{{ $t->toWarehouse->warehouse_name }}</strong>
                                <span class="text-muted">({{ $t->toWarehouse->warehouse_code }})</span>
                                @if ($t->toWarehouse->branch)
                                    <div class="small text-muted">
                                        <i class="fas fa-building me-1"></i>{{ $t->toWarehouse->branch->branch_name }}
                                    </div>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Interbranch?</dt>
                        <dd class="col-sm-9">
                            {!! $interbranchBadge() !!}
                            @if ($t->is_interbranch && $t->fromBranch && $t->toBranch)
                                <span class="small text-muted ms-1">
                                    ({{ $t->fromBranch->branch_name }} → {{ $t->toBranch->branch_name }})
                                </span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Total amount</dt>
                        <dd class="col-sm-9"><strong>Tk {{ number_format((float) $t->total_amount, 2) }}</strong></dd>

                        <dt class="col-sm-3 text-muted">Notes</dt>
                        <dd class="col-sm-9">{!! nl2br(e($t->notes ?: '—')) !!}</dd>

                        <dt class="col-sm-3 text-muted">Created</dt>
                        <dd class="col-sm-9 small text-muted">
                            {{ optional($t->created_at)->format('Y-m-d H:i') }}
                            @if ($t->created_by) · by user #{{ $t->created_by }} @endif
                        </dd>
                    </dl>
                </div>
            </div>

            {{-- Items table --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-table-list me-1 text-primary"></i> Items
                        <span class="badge bg-primary-subtle text-primary ms-1">{{ $t->items->count() }}</span>
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
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($t->items as $item)
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
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">No items.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td colspan="3" class="text-end">Total</td>
                                    <td class="text-end">Tk {{ number_format((float) $t->total_amount, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Stock movements (only if confirmed) --}}
            @if ($t->isConfirmed() && $stockMovements->isNotEmpty())
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-boxes-stacked me-1 text-info"></i> Stock movements
                            <span class="badge bg-info-subtle text-info ms-1">{{ $stockMovements->count() }}</span>
                        </h2>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>TX#</th>
                                        <th>Warehouse</th>
                                        <th>Product</th>
                                        <th class="text-end">Qty</th>
                                        <th class="text-end">Rate (Tk)</th>
                                        <th class="text-end">Value (Tk)</th>
                                        <th class="text-center">Reversed?</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($stockMovements as $st)
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
                                                <span class="fw-semibold">{{ $st->warehouse_name }}</span>
                                            </td>
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

            {{-- From-Branch (Creditor) GL Journal Entry — interbranch only --}}
            @if ($t->isConfirmed() && $t->is_interbranch && $creditorJe)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-book me-1 text-primary"></i>
                            From-Branch GL Journal <span class="text-muted small">(Creditor)</span>
                        </h2>
                        @if ($creditorJe->is_reversed)
                            <span class="badge bg-danger-subtle text-danger">
                                <i class="fas fa-rotate-left me-1"></i>Entry reversed
                            </span>
                        @endif
                    </div>
                    <div class="card-body">
                        <dl class="row mb-3 small">
                            <dt class="col-sm-2 text-muted">JE#</dt>
                            <dd class="col-sm-4">
                                <span class="badge bg-secondary-subtle text-secondary">{{ $creditorJe->entry_no }}</span>
                            </dd>
                            <dt class="col-sm-2 text-muted">Entry date</dt>
                            <dd class="col-sm-4">
                                {{ \Carbon\Carbon::parse($creditorJe->entry_date)->format('d M Y') }}
                            </dd>
                            <dt class="col-sm-2 text-muted">Description</dt>
                            <dd class="col-sm-10">{{ $creditorJe->description ?: '—' }}</dd>
                            <dt class="col-sm-2 text-muted">Branch</dt>
                            <dd class="col-sm-4">
                                @if ($t->fromBranch)
                                    <i class="fas fa-building me-1 text-muted"></i>{{ $t->fromBranch->branch_name }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </dd>
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
                                    @foreach ($creditorLines as $line)
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
                                        <td class="text-end">{{ number_format($creditorDr, 2) }}</td>
                                        <td class="text-end">{{ number_format($creditorCr, 2) }}</td>
                                        <td>
                                            @if (abs($creditorDr - $creditorCr) < 0.01)
                                                <span class="badge bg-success-subtle text-success">
                                                    <i class="fas fa-check me-1"></i>Balanced
                                                </span>
                                            @else
                                                <span class="badge bg-danger-subtle text-danger">
                                                    <i class="fas fa-triangle-exclamation me-1"></i>Out by
                                                    {{ number_format(abs($creditorDr - $creditorCr), 2) }}
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <div class="alert alert-info small mt-3 mb-0">
                            <i class="fas fa-circle-info me-1"></i>
                            From-branch (creditor) posts <strong>Dr Due-to-Branch / Cr Inventory</strong> at transfer value.
                        </div>
                    </div>
                </div>
            @endif

            {{-- To-Branch (Debtor) GL Journal Entry — interbranch only --}}
            @if ($t->isConfirmed() && $t->is_interbranch && $debtorJe)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-book me-1 text-primary"></i>
                            To-Branch GL Journal <span class="text-muted small">(Debtor)</span>
                        </h2>
                        @if ($debtorJe->is_reversed)
                            <span class="badge bg-danger-subtle text-danger">
                                <i class="fas fa-rotate-left me-1"></i>Entry reversed
                            </span>
                        @endif
                    </div>
                    <div class="card-body">
                        <dl class="row mb-3 small">
                            <dt class="col-sm-2 text-muted">JE#</dt>
                            <dd class="col-sm-4">
                                <span class="badge bg-secondary-subtle text-secondary">{{ $debtorJe->entry_no }}</span>
                            </dd>
                            <dt class="col-sm-2 text-muted">Entry date</dt>
                            <dd class="col-sm-4">
                                {{ \Carbon\Carbon::parse($debtorJe->entry_date)->format('d M Y') }}
                            </dd>
                            <dt class="col-sm-2 text-muted">Description</dt>
                            <dd class="col-sm-10">{{ $debtorJe->description ?: '—' }}</dd>
                            <dt class="col-sm-2 text-muted">Branch</dt>
                            <dd class="col-sm-4">
                                @if ($t->toBranch)
                                    <i class="fas fa-building me-1 text-muted"></i>{{ $t->toBranch->branch_name }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </dd>
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
                                    @foreach ($debtorLines as $line)
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
                                        <td class="text-end">{{ number_format($debtorDr, 2) }}</td>
                                        <td class="text-end">{{ number_format($debtorCr, 2) }}</td>
                                        <td>
                                            @if (abs($debtorDr - $debtorCr) < 0.01)
                                                <span class="badge bg-success-subtle text-success">
                                                    <i class="fas fa-check me-1"></i>Balanced
                                                </span>
                                            @else
                                                <span class="badge bg-danger-subtle text-danger">
                                                    <i class="fas fa-triangle-exclamation me-1"></i>Out by
                                                    {{ number_format(abs($debtorDr - $debtorCr), 2) }}
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <div class="alert alert-info small mt-3 mb-0">
                            <i class="fas fa-circle-info me-1"></i>
                            To-branch (debtor) posts <strong>Dr Inventory / Cr Due-from-Branch</strong> at transfer value.
                        </div>
                    </div>
                </div>
            @endif

            {{-- Same-branch info banner --}}
            @if ($t->isConfirmed() && !$t->is_interbranch)
                <div class="alert alert-secondary d-flex align-items-center">
                    <i class="fas fa-warehouse me-2 fa-lg"></i>
                    <div>
                        <strong>Same-branch transfer — no GL journal posted.</strong>
                        Inventory was reallocated within the same branch; only stock movements were applied.
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
                        <div class="mb-2">{!! $statusBadge(true) !!}</div>
                        <div class="text-muted small">
                            Type: {!! $interbranchBadge() !!}
                        </div>
                    </div>

                    {{-- Intercompany indicator (cross-branch only) --}}
                    @if ($t->is_interbranch && $t->fromBranch && $t->toBranch)
                        <div class="alert alert-info small mb-3">
                            <div class="fw-semibold mb-1">
                                <i class="fas fa-arrow-right-arrow-left me-1"></i> Intercompany
                            </div>
                            <div>
                                {{ $t->fromBranch->branch_name }}
                                <i class="fas fa-arrow-right mx-1"></i>
                                {{ $t->toBranch->branch_name }}
                            </div>
                            <div class="mt-1">
                                @if ($t->isConfirmed())
                                    <span class="badge bg-success-subtle text-success">
                                        <i class="fas fa-check me-1"></i>Intercompany GL posted
                                    </span>
                                @else
                                    <span class="badge bg-light text-muted">
                                        <i class="fas fa-clock me-1"></i>GL will be posted on confirm
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endif

                    {{-- CONFIRM (draft only) --}}
                    @if ($t->isDraft())
                        <form method="POST" action="{{ route('admin.warehouse-transfers.confirm', $t) }}"
                              id="confirmForm">
                            @csrf
                            <input type="hidden" name="confirm_reason" id="confirmReasonField" value="">
                            <button type="button" class="btn btn-success w-100 mb-2" id="confirmBtn">
                                <i class="fas fa-circle-check me-1"></i> Confirm transfer
                            </button>
                        </form>
                        <div class="alert alert-info small mb-3">
                            <i class="fas fa-circle-info me-1"></i>
                            Confirming will <strong>move stock</strong>
                            (source OUT + destination IN)@if ($t->is_interbranch)
                                and <strong>post intercompany GL</strong> (two journal entries)
                            @endif
                            .
                        </div>
                    @endif

                    {{-- CANCEL (draft or confirmed) --}}
                    @if ($t->isDraft() || $t->isConfirmed())
                        <form method="POST" action="{{ route('admin.warehouse-transfers.cancel', $t) }}"
                              id="cancelForm">
                            @csrf
                            <input type="hidden" name="cancel_reason" id="cancelReasonField" value="">
                            <button type="button" class="btn btn-outline-danger w-100" id="cancelBtn">
                                <i class="fas fa-ban me-1"></i>
                                @if ($t->isConfirmed())
                                    Cancel &amp; reverse
                                @else
                                    Cancel draft
                                @endif
                            </button>
                        </form>
                        @if ($t->isConfirmed())
                            <div class="alert alert-warning small mt-2 mb-0">
                                <i class="fas fa-triangle-exclamation me-1"></i>
                                Cancelling a confirmed transfer <strong>reverses stock movements
                                @if ($t->is_interbranch)
                                    and both GL journal entries
                                @endif
                                </strong>. A reason is required.
                            </div>
                        @endif
                    @endif

                    @if ($t->isCancelled())
                        <div class="alert alert-secondary small mb-0">
                            <i class="fas fa-ban me-1"></i>
                            This transfer is cancelled and cannot be modified further.
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
                        <strong>{{ $t->items->count() }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Total amount</span>
                        <strong>Tk {{ number_format((float) $t->total_amount, 2) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Interbranch</span>
                        @if ($t->is_interbranch)
                            <span class="badge bg-info-subtle text-info">Yes</span>
                        @else
                            <span class="text-muted">No</span>
                        @endif
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Stock transactions</span>
                        <strong>{{ $stockMovements->count() }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Creditor JE (from-branch)</span>
                        @if ($creditorJe)
                            <strong>{{ $creditorJe->entry_no }}</strong>
                        @else
                            <span class="text-muted">Not posted</span>
                        @endif
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Debtor JE (to-branch)</span>
                        @if ($debtorJe)
                            <strong>{{ $debtorJe->entry_no }}</strong>
                        @else
                            <span class="text-muted">Not posted</span>
                        @endif
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Reversed</span>
                        @if ($t->is_reversed)
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
        var isInterbranch = @json($t->is_interbranch);
        var html = isInterbranch
            ? '<p class="text-start">This will <strong>move stock</strong> (source OUT + destination IN) and <strong>post intercompany GL</strong> (two journal entries — Due-to/Due-from-Branch).</p>'
            : '<p class="text-start">This will <strong>move stock</strong> (source OUT + destination IN). No GL is posted for same-branch transfers.</p>';

        Swal.fire({
            icon: 'question',
            title: 'Confirm this transfer?',
            html: html,
            input: 'textarea',
            inputLabel: 'Optional confirm reason',
            inputPlaceholder: 'e.g. Approved by warehouse manager.',
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
        var isConfirmed  = @json($t->isConfirmed());
        var isInterbranch = @json($t->is_interbranch);
        var title = isConfirmed ? 'Cancel & reverse this transfer?' : 'Cancel this draft?';
        var html  = isConfirmed
            ? (isInterbranch
                ? '<p class="text-start">This will <strong>reverse the stock movements and both GL journal entries</strong>. A reason is required.</p>'
                : '<p class="text-start">This will <strong>reverse the stock movements</strong>. A reason is required.</p>')
            : '<p class="text-start">The draft will be marked cancelled. A reason is required.</p>';

        Swal.fire({
            icon: 'warning',
            title: title,
            html: html,
            input: 'textarea',
            inputLabel: 'Cancel reason (required)',
            inputPlaceholder: 'Why is this transfer being cancelled?',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-ban"></i> Cancel transfer',
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

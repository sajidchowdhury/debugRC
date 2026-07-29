@extends('layouts.admin')

@section('content')
@php
    $adj = $adjustment;

    // Phase 3 — status badge now covers all six lifecycle states.
    // Delegates to the model's central STATUS_BADGES map so the index,
    // show header, and lifecycle stepper stay consistent.
    $statusBadge = function () use ($adj): string {
        return [
            'draft'     => '<span class="badge bg-warning-subtle text-warning fs-6"><i class="fas fa-pen-to-square me-1"></i>Draft</span>',
            'submitted' => '<span class="badge bg-info-subtle text-info fs-6"><i class="fas fa-paper-plane me-1"></i>Submitted</span>',
            'approved'  => '<span class="badge bg-primary-subtle text-primary fs-6"><i class="fas fa-circle-check me-1"></i>Approved</span>',
            'confirmed' => '<span class="badge bg-success-subtle text-success fs-6"><i class="fas fa-circle-check me-1"></i>Confirmed</span>',
            'cancelled' => '<span class="badge bg-secondary-subtle text-secondary fs-6"><i class="fas fa-ban me-1"></i>Cancelled</span>',
            'rejected'  => '<span class="badge bg-danger-subtle text-danger fs-6"><i class="fas fa-circle-xmark me-1"></i>Rejected</span>',
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

    // Phase 3 — policy flags from the controller.
    $requiresApproval = $requiresApproval ?? false;
    $canSubmit  = $canSubmit  ?? false;
    $canApprove = $canApprove ?? false;
    $canConfirm = $canConfirm ?? false;
    $isSubmitter = $isSubmitter ?? false;

    // Lifecycle stepper state — which steps are done / current / pending.
    $stepState = [
        'draft'     => $adj->isDraft() ? 'current' : ($adj->status === 'cancelled' ? 'skipped' : 'done'),
        'submitted' => $adj->isSubmitted() ? 'current' : ($adj->isApproved() || $adj->isConfirmed() ? 'done' : ($adj->isDraft() && $requiresApproval ? 'pending' : 'skipped')),
        'approved'  => $adj->isApproved() ? 'current' : ($adj->isConfirmed() ? 'done' : ($adj->isSubmitted() ? 'pending' : 'skipped')),
        'confirmed' => $adj->isConfirmed() ? 'current' : ($adj->isApproved() ? 'pending' : ($adj->isDraft() && !$requiresApproval ? 'pending' : 'skipped')),
    ];
    $stepLabels = [
        'draft'     => ['Draft',     'fa-pen-to-square'],
        'submitted' => ['Submitted', 'fa-paper-plane'],
        'approved'  => ['Approved',  'fa-circle-check'],
        'confirmed' => ['Confirmed', 'fa-circle-check'],
    ];
    $stepCls = [
        'done'    => 'bg-success-subtle text-success border-success-subtle',
        'current' => 'bg-primary text-white border-primary',
        'pending' => 'bg-light text-muted border-light',
        'skipped' => 'bg-light text-muted border-light opacity-50',
    ];

    // Helper: format a timestamp + user-id attribution line.
    $fmtAttribution = function ($at, $by): string {
        if (!$at && !$by) return '<span class="text-muted">—</span>';
        $parts = [];
        if ($at) $parts[] = \Carbon\Carbon::parse($at)->format('d M Y H:i');
        if ($by) $parts[] = 'user #' . e($by);
        return implode(' · ', $parts);
    };
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
            <a href="{{ route('admin.stock-adjustments.print', $adj->id) }}"
               target="_blank" class="btn btn-light btn-sm">
                <i class="fas fa-print me-1"></i> Print
            </a>
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

    {{-- Phase 3 — lifecycle stepper (Draft → Submitted → Approved → Confirmed) --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <div class="d-flex align-items-center flex-wrap gap-1">
                @foreach (['draft','submitted','approved','confirmed'] as $i => $step)
                    @php
                        $state = $stepState[$step];
                        [$label, $icon] = $stepLabels[$step];
                        $cls = $stepCls[$state];
                    @endphp
                    <div class="d-flex align-items-center flex-shrink-0">
                        <div class="badge rounded-pill border px-3 py-2 {{ $cls }}">
                            <i class="fas {{ $icon }} me-1"></i>{{ $label }}
                            @if ($state === 'current')
                                <span class="ms-1 small opacity-75">(current)</span>
                            @endif
                        </div>
                        @if ($i < 3)
                            <i class="fas fa-chevron-right mx-1 text-muted small"></i>
                        @endif
                    </div>
                @endforeach
                @if ($adj->isCancelled())
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-3 py-2 ms-2">
                        <i class="fas fa-ban me-1"></i>Cancelled
                    </span>
                @endif
            </div>
            @if ($requiresApproval && $adj->isDraft())
                <div class="small text-muted mt-2 mb-0">
                    <i class="fas fa-circle-info me-1"></i>
                    This adjustment requires approval before posting (value ≥ auto-approve threshold).
                    Submit it for an admin/manager to review.
                </div>
            @elseif (!$requiresApproval && $adj->isDraft())
                <div class="small text-muted mt-2 mb-0">
                    <i class="fas fa-circle-info me-1"></i>
                    This adjustment is below the approval threshold — you can confirm it directly in one step.
                </div>
            @endif
        </div>
    </div>

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

                        {{-- Phase 2 — adjustment category badge + reference_type hint --}}
                        <dt class="col-sm-3 text-muted">Category</dt>
                        <dd class="col-sm-9">
                            {!! $adj->categoryBadge() !!}
                            @if ($adj->isOpenBalance())
                                <div class="small text-muted mt-1">
                                    <i class="fas fa-circle-info me-1"></i>
                                    Opening-balance ledger reference: <code>reference_type = opening_balance</code>
                                </div>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Status</dt>
                        <dd class="col-sm-9">{!! $statusBadge() !!}</dd>

                        {{-- Phase 3 — approval-workflow attribution trail (G9) --}}
                        @if ($adj->submitted_by || $adj->submitted_at)
                            <dt class="col-sm-3 text-muted">Submitted</dt>
                            <dd class="col-sm-9 small">{!! $fmtAttribution($adj->submitted_at, $adj->submitted_by) !!}</dd>
                        @endif
                        @if ($adj->approved_by || $adj->approved_at)
                            <dt class="col-sm-3 text-muted">Approved</dt>
                            <dd class="col-sm-9 small">{!! $fmtAttribution($adj->approved_at, $adj->approved_by) !!}</dd>
                        @endif
                        @if ($adj->confirmed_by || $adj->confirmed_at)
                            <dt class="col-sm-3 text-muted">Confirmed</dt>
                            <dd class="col-sm-9 small">
                                {!! $fmtAttribution($adj->confirmed_at, $adj->confirmed_by) !!}
                                @if ($adj->confirm_reason)
                                    <div class="small text-muted mt-1">
                                        <i class="fas fa-comment me-1"></i>{{ $adj->confirm_reason }}
                                    </div>
                                @endif
                            </dd>
                        @endif
                        @if ($adj->cancel_reason)
                            <dt class="col-sm-3 text-muted">Cancel reason</dt>
                            <dd class="col-sm-9 small">
                                <span class="badge bg-secondary-subtle text-secondary me-1"><i class="fas fa-ban"></i></span>
                                {{ $adj->cancel_reason }}
                            </dd>
                        @endif
                        @if ($adj->approval_comments)
                            <dt class="col-sm-3 text-muted">Approval trail</dt>
                            <dd class="col-sm-9">
                                <pre class="small text-muted bg-light rounded p-2 mb-0" style="white-space:pre-wrap;word-break:break-word;">{{ $adj->approval_comments }}</pre>
                            </dd>
                        @endif

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
                                    <th class="text-end">Qty entered</th>
                                    <th class="text-end">Base qty</th>
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
                                        {{-- Phase 5 — show the entered qty + its UOM, and the converted base qty. --}}
                                        <td class="text-end">
                                            {{ number_format($item->enteredQty(), 4) }}
                                            @if ($item->uom)
                                                <span class="small text-muted">{{ $item->uom->code }}</span>
                                            @elseif ($item->product)
                                                <span class="small text-muted">{{ $item->product->unit }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            {{ number_format($item->baseQty(), 4) }}
                                            @if ($item->product)
                                                <span class="small text-muted">{{ $item->product->unit }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end">{{ number_format((float) $item->rate, 2) }}</td>
                                        <td class="text-end">{{ number_format($item->amount(), 2) }}</td>
                                        <td class="small">{{ $item->reason ?: '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">No items.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                            <tfoot>
                                <tr class="table-light fw-bold">
                                    <td colspan="4" class="text-end">Total</td>
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

            {{-- Phase 8.5 — Reversing GL Journal Entry (shown when a confirmed
                adjustment was cancelled, rolling back the original JE). Mirrors
                the original JE block above so an auditor sees both the posting
                and its rollback side-by-side. --}}
            @if (isset($reversingJe) && $reversingJe && $reversingJe->isNotEmpty())
                @php
                    $reversalGroups = $reversingJe->groupBy('id');
                @endphp
                <div class="card border-0 shadow-sm mb-3 border-start border-danger border-4">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-rotate-left me-1 text-danger"></i> Reversing GL Journal Entry
                        </h2>
                        <span class="badge bg-danger-subtle text-danger">
                            <i class="fas fa-rotate-left me-1"></i>Rollback of the original posting
                        </span>
                    </div>
                    <div class="card-body">
                        @foreach ($reversalGroups as $jeId => $rows)
                            @php
                                $first = $rows->first();
                                $revDebit  = $rows->sum(fn ($r) => (float) $r->debit);
                                $revCredit = $rows->sum(fn ($r) => (float) $r->credit);
                            @endphp
                            <dl class="row mb-3 small">
                                <dt class="col-sm-2 text-muted">JE#</dt>
                                <dd class="col-sm-4"><span class="badge bg-secondary-subtle text-secondary">{{ $first->entry_no }}</span></dd>
                                <dt class="col-sm-2 text-muted">Entry date</dt>
                                <dd class="col-sm-4">{{ \Carbon\Carbon::parse($first->entry_date)->format('d M Y') }}</dd>
                                @if ($first->reverse_reason)
                                    <dt class="col-sm-2 text-muted">Reason</dt>
                                    <dd class="col-sm-10"><em>{{ $first->reverse_reason }}</em></dd>
                                @endif
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
                                        @foreach ($rows as $r)
                                            <tr>
                                                <td>
                                                    @if ($r->ledger_name)
                                                        <span class="fw-semibold">{{ $r->ledger_name }}</span>
                                                        <div class="small text-muted">{{ $r->ledger_code }}</div>
                                                    @else
                                                        <span class="text-muted">Ledger #{{ $r->ledger_id }}</span>
                                                    @endif
                                                </td>
                                                <td class="text-end">{{ (float) $r->debit > 0 ? number_format((float) $r->debit, 2) : '—' }}</td>
                                                <td class="text-end">{{ (float) $r->credit > 0 ? number_format((float) $r->credit, 2) : '—' }}</td>
                                                <td class="small">{{ $r->memo ?: '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-light fw-bold">
                                            <td class="text-end">Total</td>
                                            <td class="text-end">{{ number_format($revDebit, 2) }}</td>
                                            <td class="text-end">{{ number_format($revCredit, 2) }}</td>
                                            <td>
                                                @if (abs($revDebit - $revCredit) < 0.01)
                                                    <span class="badge bg-success-subtle text-success"><i class="fas fa-check me-1"></i>Balanced</span>
                                                @else
                                                    <span class="badge bg-danger-subtle text-danger"><i class="fas fa-triangle-exclamation me-1"></i>Out by {{ number_format(abs($revDebit - $revCredit), 2) }}</span>
                                                @endif
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Audit timeline (Phase 4) --}}
            @if (isset($auditLogs) && $auditLogs->isNotEmpty())
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-clock-rotate-left me-1 text-secondary"></i> Audit timeline
                        </h2>
                        <span class="badge bg-secondary-subtle text-secondary">{{ $auditLogs->count() }} event(s)</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            @foreach ($auditLogs as $log)
                                <div class="list-group-item px-3 py-2">
                                    <div class="d-flex align-items-start">
                                        <div class="me-3">
                                            {!! \App\Models\StockAdjustmentAuditLog::actionBadge($log->action) !!}
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="small">
                                                @if ($log->actor)
                                                    <strong>{{ $log->actor->username ?? ('User #' . $log->actor_id) }}</strong>
                                                @elseif ($log->actor_id)
                                                    <strong>User #{{ $log->actor_id }}</strong>
                                                @else
                                                    <strong>System</strong>
                                                @endif
                                                @if ($log->actor_role)
                                                    <span class="badge bg-light text-muted ms-1">{{ $log->actor_role }}</span>
                                                @endif
                                                &mdash; <span class="text-muted">{{ \Carbon\Carbon::parse($log->created_at)->format('d M Y, H:i') }}</span>
                                            </div>
                                            @if (!empty($log->payload))
                                                <div class="small text-muted mt-1">
                                                    @foreach ($log->payload as $k => $v)
                                                        <span class="badge bg-light text-dark me-1 mb-1">{{ $k }}: {{ is_array($v) ? json_encode($v) : (is_bool($v) ? ($v ? 'true' : 'false') : $v) }}</span>
                                                    @endforeach
                                                </div>
                                            @endif
                                            <div class="small text-muted">
                                                @if ($log->ip_address)
                                                    <i class="fas fa-network-wired me-1"></i>{{ $log->ip_address }}
                                                @endif
                                                @if ($log->user_agent)
                                                    <span class="ms-2" title="{{ e($log->user_agent) }}"><i class="fas fa-globe me-1"></i>{{ \Illuminate\Support\Str::limit($log->user_agent, 40) }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
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

                    {{-- ============================================================ --}}
                    {{-- Phase 3 — lifecycle-aware action buttons                    --}}
                    {{-- ============================================================ --}}

                    {{-- DRAFT: either Submit-for-Approval (needs approval) or Confirm-direct (below threshold) --}}
                    @if ($adj->isDraft() && $requiresApproval && $canSubmit)
                        <form method="POST" action="{{ route('admin.stock-adjustments.submit', $adj) }}" id="submitForm">
                            @csrf
                            <input type="hidden" name="comment" id="submitCommentField" value="">
                            <button type="button" class="btn btn-primary w-100 mb-2" id="submitBtn">
                                <i class="fas fa-paper-plane me-1"></i> Submit for approval
                            </button>
                        </form>
                        <div class="alert alert-info small mb-3">
                            <i class="fas fa-circle-info me-1"></i>
                            Submitting routes this to an <strong>admin/manager</strong> for approval before stock + GL are posted.
                        </div>
                    @elseif ($adj->isDraft() && !$requiresApproval && $canConfirm)
                        <form method="POST" action="{{ route('admin.stock-adjustments.confirm', $adj) }}" id="confirmForm">
                            @csrf
                            <input type="hidden" name="confirm_reason" id="confirmReasonField" value="">
                            <input type="hidden" name="force" id="forceField" value="">
                            <input type="hidden" name="force_reason" id="forceReasonField" value="">
                            <button type="button" class="btn btn-success w-100 mb-2" id="confirmBtn">
                                <i class="fas fa-circle-check me-1"></i> Confirm &amp; post (one step)
                            </button>
                        </form>
                        <div class="alert alert-success small mb-3">
                            <i class="fas fa-circle-check me-1"></i>
                            Below the approval threshold — confirming posts <strong>stock movements + GL</strong> immediately.
                        </div>
                    @elseif ($adj->isDraft() && $requiresApproval && !$canSubmit)
                        <div class="alert alert-warning small mb-3">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            This adjustment requires approval, but you don't have the submit permission. Contact an admin/accountant.
                        </div>
                    @endif

                    {{-- SUBMITTED: Approve / Reject (approver only, not the submitter) --}}
                    @if ($adj->isSubmitted() && $canApprove && !$isSubmitter)
                        <form method="POST" action="{{ route('admin.stock-adjustments.approve', $adj) }}" id="approveForm" class="d-inline-block w-100 mb-2">
                            @csrf
                            <input type="hidden" name="comment" id="approveCommentField" value="">
                            <button type="button" class="btn btn-success w-100" id="approveBtn">
                                <i class="fas fa-circle-check me-1"></i> Approve
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.stock-adjustments.reject', $adj) }}" id="rejectForm" class="d-inline-block w-100 mb-2">
                            @csrf
                            <input type="hidden" name="comment" id="rejectCommentField" value="">
                            <button type="button" class="btn btn-outline-danger w-100" id="rejectBtn">
                                <i class="fas fa-circle-xmark me-1"></i> Reject
                            </button>
                        </form>
                        <div class="alert alert-info small mb-3">
                            <i class="fas fa-circle-info me-1"></i>
                            Approving lets the drafter confirm + post. Rejecting returns it to draft with your comment.
                        </div>
                    @elseif ($adj->isSubmitted() && $isSubmitter)
                        <div class="alert alert-secondary small mb-3">
                            <i class="fas fa-clock me-1"></i>
                            <strong>Awaiting approval.</strong>
                            You submitted this adjustment — you cannot approve your own submission (segregation of duties).
                        </div>
                    @elseif ($adj->isSubmitted() && !$canApprove)
                        <div class="alert alert-secondary small mb-3">
                            <i class="fas fa-clock me-1"></i>
                            <strong>Awaiting approval</strong> by an admin/manager.
                        </div>
                    @endif

                    {{-- APPROVED: Confirm & Post --}}
                    @if ($adj->isApproved() && $canConfirm)
                        <form method="POST" action="{{ route('admin.stock-adjustments.confirm', $adj) }}" id="confirmForm">
                            @csrf
                            <input type="hidden" name="confirm_reason" id="confirmReasonField" value="">
                            <input type="hidden" name="force" id="forceField" value="">
                            <input type="hidden" name="force_reason" id="forceReasonField" value="">
                            <button type="button" class="btn btn-success w-100 mb-2" id="confirmBtn">
                                <i class="fas fa-circle-check me-1"></i> Confirm &amp; post
                            </button>
                        </form>
                        <div class="alert alert-info small mb-3">
                            <i class="fas fa-circle-info me-1"></i>
                            Confirming will <strong>apply stock movements</strong> and <strong>post the GL journal entry</strong>.
                        </div>
                    @elseif ($adj->isApproved() && !$canConfirm)
                        <div class="alert alert-warning small mb-3">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Approved, but you don't have the confirm permission. Contact an admin/accountant to post it.
                        </div>
                    @endif

                    {{-- CANCEL (any non-terminal state: draft / submitted / approved / confirmed) --}}
                    @if (!$adj->isCancelled())
                        <form method="POST" action="{{ route('admin.stock-adjustments.cancel', $adj) }}" id="cancelForm">
                            @csrf
                            <input type="hidden" name="cancel_reason" id="cancelReasonField" value="">
                            <button type="button" class="btn btn-outline-danger w-100" id="cancelBtn">
                                <i class="fas fa-ban me-1"></i>
                                @if ($adj->isConfirmed())
                                    Cancel &amp; reverse
                                @else
                                    Cancel adjustment
                                @endif
                            </button>
                        </form>
                        @if ($adj->isConfirmed())
                            <div class="alert alert-warning small mt-2 mb-0">
                                <i class="fas fa-triangle-exclamation me-1"></i>
                                Cancelling a confirmed adjustment <strong>reverses the stock movements and the GL entry</strong>.
                                A reason is required.
                            </div>
                        @else
                            <div class="alert alert-secondary small mt-2 mb-0">
                                <i class="fas fa-circle-info me-1"></i>
                                A cancel reason is required and will be recorded.
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
    // ====== Phase 3 — Submit for approval (draft → submitted/approved) ======
    $('#submitBtn').on('click', function () {
        Swal.fire({
            icon: 'question',
            title: 'Submit this adjustment for approval?',
            html: '<p class="text-start">An <strong>admin/manager</strong> will review and approve before stock + GL are posted.</p>',
            input: 'textarea',
            inputLabel: 'Optional note for the approver',
            inputPlaceholder: 'e.g. Opening-balance upload for WH-01, verified against physical count.',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-paper-plane"></i> Submit',
            confirmButtonColor: '#0d6efd',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        }).then(function (result) {
            if (result.isConfirmed) {
                $('#submitCommentField').val(result.value || '');
                var $btn = $('#submitBtn');
                $btn.prop('disabled', true)
                    .html('<i class="fas fa-spinner fa-spin me-1"></i> Submitting…');
                $('#submitForm').submit();
            }
        });
    });

    // ====== Phase 3 — Approve (submitted → approved) ======
    $('#approveBtn').on('click', function () {
        Swal.fire({
            icon: 'question',
            title: 'Approve this adjustment?',
            html: '<p class="text-start">Once approved, the drafter can confirm and post stock + GL.</p>',
            input: 'textarea',
            inputLabel: 'Approval comment (required)',
            inputPlaceholder: 'e.g. Verified against the physical count sheet — approved.',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check"></i> Approve',
            confirmButtonColor: '#198754',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
            inputValidator: function (value) {
                if (!value || !value.trim()) {
                    return 'An approval comment is required.';
                }
                return null;
            }
        }).then(function (result) {
            if (result.isConfirmed) {
                $('#approveCommentField').val(result.value.trim());
                var $btn = $('#approveBtn');
                $btn.prop('disabled', true)
                    .html('<i class="fas fa-spinner fa-spin me-1"></i> Approving…');
                $('#approveForm').submit();
            }
        });
    });

    // ====== Phase 3 — Reject (submitted → draft) ======
    $('#rejectBtn').on('click', function () {
        Swal.fire({
            icon: 'warning',
            title: 'Reject this adjustment?',
            html: '<p class="text-start">The adjustment returns to <strong>draft</strong> so the drafter can revise and re-submit.</p>',
            input: 'textarea',
            inputLabel: 'Rejection reason (required)',
            inputPlaceholder: 'e.g. Qty mismatch on line 2 — please re-verify against the count sheet.',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-circle-xmark"></i> Reject',
            confirmButtonColor: '#dc3545',
            cancelButtonText: 'Keep',
            reverseButtons: true,
            inputValidator: function (value) {
                if (!value || !value.trim()) {
                    return 'A rejection reason is required.';
                }
                return null;
            }
        }).then(function (result) {
            if (result.isConfirmed) {
                $('#rejectCommentField').val(result.value.trim());
                var $btn = $('#rejectBtn');
                $btn.prop('disabled', true)
                    .html('<i class="fas fa-spinner fa-spin me-1"></i> Rejecting…');
                $('#rejectForm').submit();
            }
        });
    });

    // ====== Confirm (approved → confirmed, or draft → confirmed when below threshold) ======
    // Phase 6.1 — when the viewer can force-confirm (admin) AND the adjustment
    // is a decrease, the Swal shows an optional "force past pipeline" checkbox
    // + a force_reason textarea (required when the checkbox is checked). The
    // force path bypasses the pipeline-availability check + is logged as a
    // distinct 'force_confirm' audit action.
    $('#confirmBtn').on('click', function () {
        var canForce   = @json($canForceConfirm ?? false);
        var isDecrease = @json($adj->isDecrease());
        var showForce  = canForce && isDecrease;

        var html =
            '<p class="text-start">This will <strong>apply stock movements</strong> and <strong>post the GL journal entry</strong>.</p>' +
            '<div class="text-start mt-2">' +
              '<label class="form-label small fw-semibold">Optional confirm reason</label>' +
              '<textarea id="swalConfirmReason" class="form-control form-control-sm" rows="2" placeholder="e.g. Approved by manager after physical count."></textarea>' +
            '</div>';

        if (showForce) {
            html +=
                '<div class="alert alert-warning small mt-3 mb-2 text-start">' +
                  '<i class="fas fa-triangle-exclamation me-1"></i>' +
                  'This is a <strong>decrease</strong>. Stock is normally blocked from dropping below the ' +
                  'sales-pipeline-reserved qty. You can <strong>force</strong> past that check with a reason ' +
                  '(logged as a distinct audit action).' +
                '</div>' +
                '<div class="form-check text-start ms-1">' +
                  '<input class="form-check-input" type="checkbox" id="swalForce">' +
                  '<label class="form-check-label small fw-semibold" for="swalForce">' +
                    'Force — bypass pipeline-availability check' +
                  '</label>' +
                '</div>' +
                '<div class="text-start mt-2" id="swalForceReasonWrap" style="display:none;">' +
                  '<label class="form-label small fw-semibold">Force reason <span class="text-danger">*</span></label>' +
                  '<textarea id="swalForceReason" class="form-control form-control-sm" rows="2" placeholder="Why is this decrease being forced past the pipeline check?"></textarea>' +
                '</div>';
        }

        Swal.fire({
            icon: 'question',
            title: 'Confirm this adjustment?',
            html: html,
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check"></i> Confirm',
            confirmButtonColor: '#198754',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
            didOpen: function (el) {
                // Toggle the force_reason textarea visibility when the
                // checkbox state changes.
                var $cb = $('#swalForce', el);
                $cb.on('change', function () {
                    $('#swalForceReasonWrap').toggle(this.checked);
                });
            },
            preConfirm: function () {
                var reason = $('#swalConfirmReason').val() || '';
                var force = false;
                var forceReason = '';
                if (showForce) {
                    force = $('#swalForce').is(':checked');
                    forceReason = ($('#swalForceReason').val() || '').trim();
                    if (force && forceReason === '') {
                        Swal.showValidationMessage('A force reason is required to bypass the pipeline-availability check.');
                        return false;
                    }
                }
                return { reason: reason, force: force, forceReason: forceReason };
            }
        }).then(function (result) {
            if (result.isConfirmed) {
                var v = result.value || { reason: '', force: false, forceReason: '' };
                $('#confirmReasonField').val(v.reason);
                $('#forceField').val(v.force ? '1' : '');
                $('#forceReasonField').val(v.forceReason);
                var $btn = $('#confirmBtn');
                $btn.prop('disabled', true)
                    .html('<i class="fas fa-spinner fa-spin me-1"></i> Confirming…');
                $('#confirmForm').submit();
            }
        });
    });

    // ====== Cancel (any non-terminal state) ======
    $('#cancelBtn').on('click', function () {
        var isConfirmed = @json($adj->isConfirmed());
        var title = isConfirmed ? 'Cancel & reverse this adjustment?' : 'Cancel this adjustment?';
        var html  = isConfirmed
            ? '<p class="text-start">This will <strong>reverse the stock movements and the GL journal entry</strong>. A reason is required.</p>'
            : '<p class="text-start">The adjustment will be marked cancelled. A reason is required and will be recorded.</p>';

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

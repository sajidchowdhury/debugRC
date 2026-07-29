@extends('layouts.admin')

@section('content')
@php
    $dmg = $damage;

    // Damages = loss → confirmed = danger (red), draft = warning, cancelled = secondary.
    $statusBadge = function () use ($dmg): string {
        return [
            'draft'     => '<span class="badge bg-warning-subtle text-warning fs-6"><i class="fas fa-pen-to-square me-1"></i>Draft</span>',
            'confirmed' => '<span class="badge bg-danger-subtle text-danger fs-6"><i class="fas fa-triangle-exclamation me-1"></i>Confirmed</span>',
            'cancelled' => '<span class="badge bg-secondary-subtle text-secondary fs-6"><i class="fas fa-ban me-1"></i>Cancelled</span>',
        ][$dmg->status] ?? '<span class="badge bg-light text-dark fs-6">' . e($dmg->status) . '</span>';
    };

    $je           = $dmg->journalEntry;
    $jeLines      = $je ? $je->lines : collect();
    $debitTotal   = $jeLines->sum(fn ($l) => (float) $l->debit);
    $creditTotal  = $jeLines->sum(fn ($l) => (float) $l->credit);
    $hasMovements = is_iterable($stockMovements) && count($stockMovements) > 0;

    // Phase 0 (Damage plan): only admin/manager may confirm or cancel a
    // damage (they post/reverse stock + GL). warehouse_manager can create
    // drafts and view, but cannot post — so hide the action buttons and
    // show an explanatory note instead of letting them click into a 403.
    $canPost = auth()->check() && auth()->user()->hasRole('admin', 'manager');

    // Phase 1 — damage type badge + structured reason label.
    $typeLabels = \App\Models\DamageInvoice::DAMAGE_TYPE_LABELS;
    $typeBadges = \App\Models\DamageInvoice::DAMAGE_TYPE_BADGE_CLASSES;
    $typeIcons  = \App\Models\DamageInvoice::DAMAGE_TYPE_ICONS;
    $typeBadge = function () use ($dmg, $typeLabels, $typeBadges, $typeIcons): string {
        $t    = $dmg->damage_type ?? 'other';
        $cls  = $typeBadges[$t] ?? 'bg-light text-dark';
        $icon = $typeIcons[$t]  ?? 'fa-circle-question';
        $lbl  = $typeLabels[$t] ?? $t;
        return '<span class="badge ' . $cls . ' fs-6"><i class="fas ' . $icon . ' me-1"></i>' . e($lbl) . '</span>';
    };
    // Structured reason (from the eager-loaded reasonTaxonomy relation).
    $reasonTax  = $dmg->reasonTaxonomy;
    $reasonLbl  = $reasonTax ? $reasonTax->label : null;
@endphp

<div class="container-fluid py-2">
    {{-- Hero header (red = loss) --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#dc2626,#b91c1c);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-triangle-exclamation me-2"></i>{{ $title }}
                {!! $statusBadge() !!}
                {{-- Phase 1 — damage type badge (prominent, next to status) --}}
                {!! $typeBadge() !!}
                @if ($dmg->is_reversed)
                    <span class="badge bg-light text-danger ms-1"><i class="fas fa-rotate-left me-1"></i>Reversed</span>
                @endif
            </h1>
            <p class="mb-0 small opacity-75">
                @if ($dmg->warehouse)
                    {{ $dmg->warehouse->warehouse_name }}
                    @if ($dmg->warehouse->branch) · {{ $dmg->warehouse->branch->branch_name }} @endif
                @endif
            </p>
        </div>
        <div>
            <a href="{{ route('admin.damages.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Reversal banner --}}
    @if ($dmg->is_reversed)
        <div class="alert alert-danger d-flex align-items-center mb-3" role="alert">
            <i class="fas fa-rotate-left me-2 fa-lg"></i>
            <div>
                <strong>This damage invoice has been reversed.</strong>
                @if ($dmg->reversed_at)
                    Reversed on {{ \Carbon\Carbon::parse($dmg->reversed_at)->format('d M Y H:i') }}
                @endif
                @if ($dmg->reversed_by)
                    · by user #{{ $dmg->reversed_by }}
                @endif
                @if ($dmg->reverse_reason)
                    · Reason: <em>{{ $dmg->reverse_reason }}</em>
                @endif
            </div>
        </div>
    @endif

    <div class="row g-3">
        {{-- Left: main details --}}
        <div class="col-lg-8">
            {{-- Damage details card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-danger"></i> Damage details</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">Code</dt>
                        <dd class="col-sm-9">
                            <span class="badge bg-secondary-subtle text-secondary">{{ $dmg->damage_code }}</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Date</dt>
                        <dd class="col-sm-9">{{ \Carbon\Carbon::parse($dmg->damage_date)->format('d M Y') }}</dd>

                        <dt class="col-sm-3 text-muted">Warehouse</dt>
                        <dd class="col-sm-9">
                            @if ($dmg->warehouse)
                                <strong>{{ $dmg->warehouse->warehouse_name }}</strong>
                                <span class="text-muted">({{ $dmg->warehouse->warehouse_code }})</span>
                                @if ($dmg->warehouse->branch)
                                    <div class="small text-muted">
                                        <i class="fas fa-building me-1"></i>{{ $dmg->warehouse->branch->branch_name }}
                                    </div>
                                @endif
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Branch</dt>
                        <dd class="col-sm-9">
                            @if ($dmg->branch)
                                {{ $dmg->branch->branch_name }}
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        {{-- Phase 1 — Damage type (prominent) --}}
                        <dt class="col-sm-3 text-muted">Damage type</dt>
                        <dd class="col-sm-9">{!! $typeBadge() !!}</dd>

                        {{-- Phase 1 — structured reason (label from taxonomy) --}}
                        <dt class="col-sm-3 text-muted">Reason</dt>
                        <dd class="col-sm-9">
                            @if ($reasonLbl)
                                <span class="fw-semibold">{{ $reasonLbl }}</span>
                                @if ($dmg->reason_code)
                                    <code class="small text-muted ms-1">{{ $dmg->reason_code }}</code>
                                @endif
                            @elseif ($dmg->reason_code)
                                <code>{{ $dmg->reason_code }}</code>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        {{-- Phase 1 — reason details (structured context) --}}
                        <dt class="col-sm-3 text-muted">Reason details</dt>
                        <dd class="col-sm-9">{!! nl2br(e($dmg->reason_detail ?: '—')) !!}</dd>

                        <dt class="col-sm-3 text-muted">Total value</dt>
                        <dd class="col-sm-9"><strong class="text-danger">Tk {{ number_format((float) $dmg->total_value, 2) }}</strong></dd>

                        <dt class="col-sm-3 text-muted">Created</dt>
                        <dd class="col-sm-9 small text-muted">
                            {{ optional($dmg->created_at)->format('Y-m-d H:i') }}
                            @if ($dmg->created_by) · by user #{{ $dmg->created_by }} @endif
                        </dd>

                        {{-- Legacy free-text reason (kept for back-compat) --}}
                        @if ($dmg->reason)
                            <dt class="col-sm-3 text-muted">Additional notes</dt>
                            <dd class="col-sm-9">{!! nl2br(e($dmg->reason)) !!}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            {{-- Items table --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-table-list me-1 text-danger"></i> Damaged items
                        <span class="badge bg-danger-subtle text-danger ms-1">{{ $dmg->items->count() }}</span>
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
                                @forelse ($dmg->items as $item)
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
                                    <td colspan="3" class="text-end">Total damage value</td>
                                    <td class="text-end text-danger">Tk {{ number_format((float) $dmg->total_value, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Stock movements (only if confirmed and present) --}}
            @if ($dmg->isConfirmed() && $hasMovements)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-boxes-stacked me-1 text-danger"></i> Stock movements
                            <span class="badge bg-danger-subtle text-danger ms-1">{{ count($stockMovements) }}</span>
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
                                    @foreach ($stockMovements as $st)
                                        @php
                                            $qty = (float) $st->qty;
                                            // Damages are stock OUT → negative/red. Positive = green (reversal in).
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
            @if ($dmg->isConfirmed() && $je)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-book me-1 text-danger"></i> GL Journal Entry
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
                        <div class="mb-2 fs-5">{!! $statusBadge() !!}</div>
                        <div class="text-muted small">
                            Total value: <strong class="text-danger">Tk {{ number_format((float) $dmg->total_value, 2) }}</strong>
                        </div>
                    </div>

                    {{-- CONFIRM (draft only) — admin/manager only --}}
                    @if ($dmg->isDraft() && $canPost)
                        <form method="POST" action="{{ route('admin.damages.confirm', $dmg) }}"
                              id="confirmForm">
                            @csrf
                            <input type="hidden" name="confirm_reason" id="confirmReasonField" value="">
                            <button type="button" class="btn btn-danger w-100 mb-2" id="confirmBtn">
                                <i class="fas fa-circle-check me-1"></i> Confirm damage
                            </button>
                        </form>
                        <div class="alert alert-warning small mb-3">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            Confirming will <strong>write off stock</strong> (OUT) and <strong>post a GL loss</strong>
                            (Dr Damage Loss / Cr Inventory).
                        </div>
                    @endif

                    {{-- CANCEL (draft or confirmed) — admin/manager only --}}
                    @if (($dmg->isDraft() || $dmg->isConfirmed()) && $canPost)
                        <form method="POST" action="{{ route('admin.damages.cancel', $dmg) }}"
                              id="cancelForm">
                            @csrf
                            <input type="hidden" name="cancel_reason" id="cancelReasonField" value="">
                            <button type="button" class="btn btn-outline-danger w-100" id="cancelBtn">
                                <i class="fas fa-ban me-1"></i>
                                @if ($dmg->isConfirmed())
                                    Cancel &amp; reverse
                                @else
                                    Cancel draft
                                @endif
                            </button>
                        </form>
                        @if ($dmg->isConfirmed())
                            <div class="alert alert-danger small mt-2 mb-0">
                                <i class="fas fa-triangle-exclamation me-1"></i>
                                Cancelling a confirmed damage <strong>reverses the stock write-off and the GL entry</strong>.
                                A reason is required.
                            </div>
                        @endif
                    @endif

                    {{-- Phase 0 (Damage plan): warehouse_manager sees this note instead of
                        the confirm/cancel buttons (which would 403). --}}
                    @if (($dmg->isDraft() || $dmg->isConfirmed()) && !$canPost)
                        <div class="alert alert-info small mb-0">
                            <i class="fas fa-circle-info me-1"></i>
                            @if ($dmg->isDraft())
                                This draft damage must be <strong>confirmed</strong> by a manager or admin
                                to write off stock and post the GL loss.
                            @else
                                This confirmed damage can only be <strong>cancelled/reversed</strong> by a
                                manager or admin.
                            @endif
                        </div>
                    @endif

                    @if ($dmg->isCancelled())
                        <div class="alert alert-secondary small mb-0">
                            <i class="fas fa-ban me-1"></i>
                            This damage invoice is cancelled and cannot be modified further.
                        </div>
                    @endif
                </div>
            </div>

            {{-- Reversal info card (if reversed) --}}
            @if ($dmg->is_reversed)
                <div class="card border-danger shadow-sm mb-3">
                    <div class="card-header bg-danger-subtle">
                        <h2 class="h6 mb-0 text-danger">
                            <i class="fas fa-rotate-left me-1"></i> Reversal info
                        </h2>
                    </div>
                    <div class="card-body small">
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <span class="text-muted">Reversed at</span>
                            <strong>
                                @if ($dmg->reversed_at)
                                    {{ \Carbon\Carbon::parse($dmg->reversed_at)->format('d M Y H:i') }}
                                @else
                                    —
                                @endif
                            </strong>
                        </div>
                        <div class="d-flex justify-content-between py-1 border-bottom">
                            <span class="text-muted">Reversed by</span>
                            <strong>{{ $dmg->reversed_by ? ('User #' . $dmg->reversed_by) : '—' }}</strong>
                        </div>
                        <div class="py-1">
                            <span class="text-muted d-block">Reason</span>
                            <span>{{ $dmg->reverse_reason ?: '—' }}</span>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Quick facts card --}}
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-muted"></i> Quick facts</h2>
                </div>
                <div class="card-body small">
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Items</span>
                        <strong>{{ $dmg->items->count() }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Total value</span>
                        <strong class="text-danger">Tk {{ number_format((float) $dmg->total_value, 2) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Stock transactions</span>
                        <strong>{{ is_iterable($stockMovements) ? count($stockMovements) : 0 }}</strong>
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
                        @if ($dmg->is_reversed)
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
            icon: 'warning',
            title: 'Confirm this damage?',
            html: '<p class="text-start">Confirming will <strong>write off stock</strong> and <strong>post a GL loss</strong> (Dr Damage Loss / Cr Inventory).</p>',
            input: 'textarea',
            inputLabel: 'Optional confirm reason',
            inputPlaceholder: 'e.g. Approved by manager after physical inspection.',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check"></i> Confirm',
            confirmButtonColor: '#dc2626',
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
        var isConfirmed = @json($dmg->isConfirmed());
        var title = isConfirmed ? 'Cancel & reverse this damage?' : 'Cancel this draft?';
        var html  = isConfirmed
            ? '<p class="text-start">This will <strong>reverse the stock write-off and the GL journal entry</strong>. A reason is required.</p>'
            : '<p class="text-start">The draft will be marked cancelled. A reason is required.</p>';

        Swal.fire({
            icon: 'warning',
            title: title,
            html: html,
            input: 'textarea',
            inputLabel: 'Cancel reason (required)',
            inputPlaceholder: 'Why is this damage being cancelled?',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-ban"></i> Cancel damage',
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

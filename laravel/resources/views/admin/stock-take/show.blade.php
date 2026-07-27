@extends('layouts.admin')

@section('content')
@php
    $statusBadge = function () use ($session): string {
        return [
            'draft'     => '<span class="badge bg-warning-subtle text-warning fs-6"><i class="fas fa-pen-to-square me-1"></i>Draft</span>',
            'counting'  => '<span class="badge bg-info-subtle text-info fs-6"><i class="fas fa-clipboard-list me-1"></i>Counting</span>',
            // Phase 4: approval-workflow states.
            'submitted' => '<span class="badge bg-primary-subtle text-primary fs-6"><i class="fas fa-paper-plane me-1"></i>Submitted</span>',
            'approved'  => '<span class="badge bg-teal-subtle text-teal fs-6" style="background:#d1f5e6;color:#0d6e51;"><i class="fas fa-thumbs-up me-1"></i>Approved</span>',
            'posted'    => '<span class="badge bg-success-subtle text-success fs-6"><i class="fas fa-circle-check me-1"></i>Posted</span>',
            'cancelled' => '<span class="badge bg-secondary-subtle text-secondary fs-6"><i class="fas fa-ban me-1"></i>Cancelled</span>',
        ][$session->status] ?? '<span class="badge bg-light text-dark fs-6">' . e($session->status) . '</span>';
    };

    $whStatusBadge = function (string $s): string {
        return [
            'pending'   => '<span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-hourglass-half me-1"></i>Pending</span>',
            'counting'  => '<span class="badge bg-info-subtle text-info"><i class="fas fa-clipboard-list me-1"></i>Counting</span>',
            'completed' => '<span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i>Completed</span>',
        ][$s] ?? '<span class="badge bg-light text-dark">' . e($s) . '</span>';
    };

    // Group session items by warehouse_id for per-warehouse stats.
    $itemsByWh = $session->items->groupBy('warehouse_id');
    $whStats = [];
    foreach ($session->warehouses as $wh) {
        $items = $itemsByWh->get($wh->warehouse_id, collect());
        $varianceItems = $items->filter(fn ($i) => abs((float) $i->physical_qty - (float) $i->system_qty) > 0.0001);
        $whStats[$wh->warehouse_id] = [
            'saved_lines'    => $items->count(),
            'variance_lines' => $varianceItems->count(),
            'net_impact'     => $items->sum(fn ($i) => (float) $i->difference * (float) $i->rate),
        ];
    }

    // GL journal entry totals.
    $je          = $session->journalEntry;
    $jeLines     = $je ? $je->lines : collect();
    $debitTotal  = $jeLines->sum(fn ($l) => (float) $l->debit);
    $creditTotal = $jeLines->sum(fn ($l) => (float) $l->credit);

    // Phase 4: $canPost, $canSubmit, $canApprove, $canReject, $approvalRequired,
    // $requireApproval, $autoApproveBelowValue, $varianceThresholdBlock,
    // $approverRoles, $isApproverRole, $isSubmitter, $submitterUser,
    // $approverUser, $varianceValue are all passed by the controller
    // (StockTakeController::show) — do NOT recompute them here. The
    // controller is the single source of truth for the approval-gate UI
    // flags so the blade never disagrees with the service-layer guards.
    $allCompleted = $progress['total_wh'] > 0 && $progress['completed_wh'] === $progress['total_wh'];
    $canCancel    = ! $session->isCancelled();
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#4f46e5);">
        <div>
            <h1 class="h4 mb-1">
                <i class="fas fa-clipboard-check me-2"></i>{{ $title }}
                {!! $statusBadge() !!}
                @if ($session->is_reversed)
                    <span class="badge bg-danger ms-1"><i class="fas fa-rotate-left me-1"></i>Reversed</span>
                @endif
            </h1>
            <p class="mb-0 small opacity-75">
                @if ($session->branch)
                    <i class="fas fa-building me-1"></i>{{ $session->branch->branch_name }}
                    · {{ $session->warehouses->count() }} warehouse(s)
                @endif
            </p>
        </div>
        <div>
            <a href="{{ route('admin.stock-take.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Reversal alert --}}
    @if ($session->is_reversed)
        <div class="alert alert-danger d-flex align-items-center mb-3" role="alert">
            <i class="fas fa-rotate-left me-2 fa-lg"></i>
            <div>
                <strong>This session has been reversed.</strong>
                @if ($session->reversed_at)
                    Reversed on {{ \Carbon\Carbon::parse($session->reversed_at)->format('d M Y H:i') }}
                @endif
                @if ($session->reversed_by)
                    · by user #{{ $session->reversed_by }}
                @endif
                @if ($session->reverse_reason)
                    · Reason: <em>{{ $session->reverse_reason }}</em>
                @endif
            </div>
        </div>
    @endif

    {{-- Phase 3: Outbound-freeze status banner --}}
    @if ($session->freeze_outbound)
        @php
            $activelyFreezing = $session->isActivelyFreezing();
            $frozenWarehouses = $session->warehouses->pluck('warehouse')->filter();
        @endphp
        <div class="alert {{ $activelyFreezing ? 'alert-warning' : 'alert-secondary' }} d-flex align-items-start mb-3" role="alert">
            <i class="fas fa-snowflake me-2 fa-lg mt-1"></i>
            <div class="flex-grow-1">
                <strong>
                    @if ($activelyFreezing)
                        Outbound movements are FROZEN for this session's warehouses.
                    @else
                        Outbound freeze was active during the count (now released).
                    @endif
                </strong>
                <div class="small mt-1">
                    @if ($session->frozen_at)
                        Frozen since {{ \Carbon\Carbon::parse($session->frozen_at)->format('d M Y H:i') }}.
                    @endif
                    @if ($frozenWarehouses->isNotEmpty())
                        Warehouses:
                        @foreach ($frozenWarehouses as $wh)
                            <span class="badge bg-secondary-subtle text-secondary ms-1">{{ $wh->warehouse_name }}</span>
                        @endforeach
                    @endif
                </div>
                <div class="small text-muted mt-1">
                    @if ($activelyFreezing)
                        Sales, transfers out, adjustments out, and damages are blocked for these warehouses until this session is posted or cancelled.
                    @else
                        The freeze was released when the session reached its terminal state. Stock movements are allowed again.
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Phase 3: Stock-drift reconciliation warning (shown after a post if any
         product's live qty drifted from the setup-time snapshot) --}}
    @php
        $driftCount = is_array($stockDrift ?? null) ? count($stockDrift ?? []) : 0;
    @endphp
    @if ($driftCount > 0)
        <div class="alert alert-warning d-flex align-items-start mb-3" role="alert">
            <i class="fas fa-triangle-exclamation me-2 fa-lg mt-1"></i>
            <div class="flex-grow-1">
                <strong>Stock moved during the count — {{ $driftCount }} product(s) drifted from the snapshot.</strong>
                <div class="small text-muted mt-1">
                    These products had their live <code>warehouse_stock.qty</code> change between setup and post
                    (inbound receipts while frozen, or any movement while unfrozen). The variance was still applied
                    against the original snapshot <code>system_qty</code>; review the lines below for accuracy.
                </div>
                <div class="table-responsive mt-2 mb-0">
                    <table class="table table-sm table-bordered mb-0 align-middle" style="max-height: 240px; overflow-y: auto;">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th>Warehouse</th>
                                <th class="text-end">Snapshot qty</th>
                                <th class="text-end">Live qty at post</th>
                                <th class="text-end">Delta</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($stockDrift as $d)
                                <tr>
                                    <td>
                                        <span class="fw-semibold">{{ $d['product_code'] ?? '?' }}</span>
                                        — {{ $d['product_name'] ?? '?' }}
                                    </td>
                                    <td>{{ $d['warehouse_name'] ?? '?' }}</td>
                                    <td class="text-end">{{ number_format($d['snapshot_qty'] ?? 0, 4) }}</td>
                                    <td class="text-end">{{ number_format($d['live_qty'] ?? 0, 4) }}</td>
                                    <td class="text-end">
                                        @php
                                            $delta = (float) ($d['delta'] ?? 0);
                                            $cls = $delta > 0 ? 'text-success' : ($delta < 0 ? 'text-danger' : '');
                                        @endphp
                                        <span class="{{ $cls }}">{{ ($delta > 0 ? '+' : '') . number_format($delta, 4) }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif

    <div class="row g-3">
        {{-- ====== Left: main details ====== --}}
        <div class="col-lg-8">
            {{-- Session details card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-circle-info me-1 text-primary"></i> Session details</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-3 text-muted">Session code</dt>
                        <dd class="col-sm-9">
                            <span class="badge bg-secondary-subtle text-secondary">{{ $session->session_code }}</span>
                        </dd>

                        <dt class="col-sm-3 text-muted">Session date</dt>
                        <dd class="col-sm-9">{{ \Carbon\Carbon::parse($session->session_date)->format('d M Y') }}</dd>

                        <dt class="col-sm-3 text-muted">Branch</dt>
                        <dd class="col-sm-9">
                            @if ($session->branch)
                                <strong>{{ $session->branch->branch_name }}</strong>
                                <span class="text-muted">({{ $session->branch->branch_code }})</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </dd>

                        <dt class="col-sm-3 text-muted">Status</dt>
                        <dd class="col-sm-9">{!! $statusBadge() !!}</dd>

                        <dt class="col-sm-3 text-muted">Notes</dt>
                        <dd class="col-sm-9">{!! nl2br(e($session->notes ?: '—')) !!}</dd>

                        <dt class="col-sm-3 text-muted">Created</dt>
                        <dd class="col-sm-9 small text-muted">
                            {{ optional($session->created_at)->format('Y-m-d H:i') }}
                            @if ($session->created_by) · by user #{{ $session->created_by }} @endif
                        </dd>
                    </dl>
                </div>
            </div>

            {{-- Warehouses card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h2 class="h6 mb-0">
                        <i class="fas fa-warehouse me-1 text-primary"></i> Warehouses
                        <span class="badge bg-primary-subtle text-primary ms-1">{{ $session->warehouses->count() }}</span>
                    </h2>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Warehouse</th>
                                    <th>Branch</th>
                                    <th>Status</th>
                                    <th class="text-end">Saved Lines</th>
                                    <th class="text-end">Variance Lines</th>
                                    <th class="text-end">Net Impact (Tk)</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($session->warehouses as $wh)
                                    @php
                                        $stat = $whStats[$wh->warehouse_id] ?? ['saved_lines' => 0, 'variance_lines' => 0, 'net_impact' => 0];
                                        $net = (float) $stat['net_impact'];
                                    @endphp
                                    <tr>
                                        <td>
                                            @if ($wh->warehouse)
                                                <span class="fw-semibold">{{ $wh->warehouse->warehouse_name }}</span>
                                                <div class="small text-muted">{{ $wh->warehouse->warehouse_code }}</div>
                                            @else
                                                <span class="text-muted">Warehouse #{{ $wh->warehouse_id }}</span>
                                            @endif
                                        </td>
                                        <td class="small">
                                            @if ($wh->warehouse && $wh->warehouse->branch)
                                                {{ $wh->warehouse->branch->branch_name }}
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td>{!! $whStatusBadge($wh->status) !!}</td>
                                        <td class="text-end">{{ number_format((int) $stat['saved_lines']) }}</td>
                                        <td class="text-end">
                                            @if ($stat['variance_lines'] > 0)
                                                <span class="badge bg-warning-subtle text-warning">{{ $stat['variance_lines'] }}</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @if (abs($net) > 0.01)
                                                <span class="fw-semibold {{ $net < 0 ? 'text-danger' : 'text-success' }}">
                                                    {{ $net < 0 ? '-' : '+' }}{{ number_format(abs($net), 2) }}
                                                </span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-center text-nowrap">
                                            @if ($wh->status === 'pending')
                                                <a href="{{ route('admin.stock-take.setup', [$session->id, $wh->warehouse_id]) }}"
                                                   class="btn btn-sm btn-outline-primary" title="Setup counts">
                                                    <i class="fas fa-wand-magic-sparkles me-1"></i> Setup Counts
                                                </a>
                                            @else
                                                <a href="{{ route('admin.stock-take.count', [$session->id, $wh->warehouse_id]) }}"
                                                   class="btn btn-sm btn-outline-secondary" title="Enter counts">
                                                    <i class="fas fa-pen-to-square me-1"></i> Count
                                                </a>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                            No warehouses attached to this session.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Variance Lines card (only if any) --}}
            @if (! empty($varianceLines) && $varianceLines->isNotEmpty())
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">
                            <i class="fas fa-triangle-exclamation me-1 text-warning"></i> Variance lines
                            <span class="badge bg-warning-subtle text-warning ms-1">{{ $varianceLines->count() }}</span>
                        </h2>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped table-hover align-middle mb-0" id="varianceTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Warehouse</th>
                                        <th>Product</th>
                                        <th>Unit</th>
                                        <th class="text-end">System Qty</th>
                                        <th class="text-end">Physical Qty</th>
                                        <th class="text-end">Difference</th>
                                        <th class="text-end">Rate (Tk)</th>
                                        <th class="text-end">Variance Value (Tk)</th>
                                        <th>Reason</th>
                                        <th class="text-center">Applied?</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php $vTotalQty = 0; $vTotalValue = 0; @endphp
                                    @foreach ($varianceLines as $v)
                                        @php
                                            $diff  = (float) $v->difference;
                                            $rate  = (float) $v->rate;
                                            $value = $diff * $rate;
                                            $vTotalQty   += $diff;
                                            $vTotalValue += $value;
                                            $diffClass   = $diff < 0 ? 'text-danger fw-bold' : ($diff > 0 ? 'text-success fw-bold' : 'text-muted');
                                            $valueClass  = $value < 0 ? 'text-danger fw-bold' : ($value > 0 ? 'text-success fw-bold' : 'text-muted');
                                        @endphp
                                        <tr>
                                            <td>{{ $v->warehouse_name }}</td>
                                            <td>
                                                <span class="fw-semibold">{{ $v->product_name }}</span>
                                                <div class="small text-muted">{{ $v->product_code }}</div>
                                            </td>
                                            <td class="small">{{ $v->unit ?: '—' }}</td>
                                            <td class="text-end">{{ number_format((float) $v->system_qty, 4) }}</td>
                                            <td class="text-end">{{ number_format((float) $v->physical_qty, 4) }}</td>
                                            <td class="text-end {{ $diffClass }}">
                                                {{ $diff > 0 ? '+' : '' }}{{ number_format($diff, 4) }}
                                            </td>
                                            <td class="text-end">{{ number_format($rate, 2) }}</td>
                                            <td class="text-end {{ $valueClass }}">
                                                {{ $value > 0 ? '+' : '' }}{{ number_format($value, 2) }}
                                            </td>
                                            <td class="small">{{ $v->reason ?: '—' }}</td>
                                            <td class="text-center">
                                                @if (! empty($v->is_applied))
                                                    <span class="badge bg-success-subtle text-success">
                                                        <i class="fas fa-check me-1"></i>Yes
                                                    </span>
                                                @else
                                                    <span class="badge bg-light text-muted">No</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="table-light fw-bold">
                                        <td colspan="5" class="text-end">Totals</td>
                                        <td class="text-end {{ $vTotalQty < 0 ? 'text-danger' : 'text-success' }}">
                                            {{ $vTotalQty > 0 ? '+' : '' }}{{ number_format($vTotalQty, 4) }}
                                        </td>
                                        <td></td>
                                        <td class="text-end {{ $vTotalValue < 0 ? 'text-danger' : 'text-success' }}">
                                            {{ $vTotalValue > 0 ? '+' : '' }}{{ number_format($vTotalValue, 2) }}
                                        </td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Stock movements card (only if posted) --}}
            @if ($session->isPosted() && ! empty($stockMovements) && $stockMovements->isNotEmpty())
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
                                                <span class="fw-semibold">{{ $st->product_name }}</span>
                                                <div class="small text-muted">{{ $st->product_code }}</div>
                                            </td>
                                            <td class="text-end {{ $qtyClass }}">
                                                {{ $qty > 0 ? '+' : '' }}{{ number_format($qty, 4) }}
                                            </td>
                                            <td class="text-end">{{ number_format((float) $st->rate, 2) }}</td>
                                            <td class="text-end">{{ number_format((float) $st->total_value, 2) }}</td>
                                            <td class="text-center">
                                                @if (! empty($st->is_reversed))
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

            {{-- GL Journal Entry card (only if posted + has JE) --}}
            @if ($session->isPosted() && $je)
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
                            <dd class="col-sm-4">
                                <span class="badge bg-secondary-subtle text-secondary">{{ $je->entry_no }}</span>
                            </dd>
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

        {{-- ====== Right: progress + actions aside ====== --}}
        <div class="col-lg-4">
            {{-- Progress card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-chart-line me-1 text-primary"></i> Progress summary</h2>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Total warehouses</span>
                        <strong>{{ (int) $progress['total_wh'] }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Counted (in progress or done)</span>
                        <strong>{{ (int) $progress['counted_wh'] }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Completed</span>
                        <strong class="text-success">{{ (int) $progress['completed_wh'] }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Variance lines</span>
                        @if ($progress['variance_lines'] > 0)
                            <span class="badge bg-warning-subtle text-warning">{{ (int) $progress['variance_lines'] }}</span>
                        @else
                            <strong class="text-muted">0</strong>
                        @endif
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Total variance (Tk)</span>
                        <strong>{{ number_format((float) $progress['variance_value'], 2) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">
                            <i class="fas fa-arrow-up text-success me-1"></i>Gain value
                        </span>
                        <strong class="text-success">{{ number_format((float) $progress['gain_value'], 2) }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">
                            <i class="fas fa-arrow-down text-danger me-1"></i>Loss value
                        </span>
                        <strong class="text-danger">{{ number_format((float) $progress['loss_value'], 2) }}</strong>
                    </div>

                    {{-- Progress bar --}}
                    @if ($progress['total_wh'] > 0)
                        @php
                            $pct = (int) round(($progress['completed_wh'] / $progress['total_wh']) * 100);
                        @endphp
                        <div class="mt-3">
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span>Completion</span>
                                <span>{{ $pct }}%</span>
                            </div>
                            <div class="progress" style="height:8px;">
                                <div class="progress-bar bg-success" role="progressbar"
                                     style="width: {{ $pct }}%;"
                                     aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Actions card --}}
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-gear me-1 text-secondary"></i> Status &amp; actions</h2>
                </div>
                <div class="card-body">
                    <div class="text-center mb-3">
                        <div class="text-muted small mb-1">Current status</div>
                        <div class="mb-2">{!! $statusBadge() !!}</div>
                    </div>

                    {{-- Phase 4: SUBMIT (counting + all completed + approval gate enabled) --}}
                    @if ($canSubmit)
                        <form method="POST" action="{{ route('admin.stock-take.submit', $session->id) }}" id="submitForm">
                            @csrf
                            <button type="button" class="btn btn-primary w-100 mb-2" id="submitBtn">
                                <i class="fas fa-paper-plane me-1"></i> Submit for approval
                            </button>
                        </form>
                        <div class="alert alert-info small mb-3">
                            <i class="fas fa-circle-info me-1"></i>
                            Submitting sends this session to an <strong>approver</strong>. You will not be able to
                            edit counts after submission. An approver (a different user) will review and approve or reject it.
                            @if ($varianceValue > 0)
                                <br>Variance value: <strong>{{ number_format($varianceValue, 2) }}</strong>.
                            @endif
                        </div>
                    @endif

                    {{-- Phase 4: APPROVE / REJECT (submitted + approver + not submitter) --}}
                    @if ($session->isSubmitted())
                        @if ($canApprove || $canReject)
                            <form method="POST" action="{{ route('admin.stock-take.approve', $session->id) }}" id="approveForm" class="mb-2">
                                @csrf
                                <div class="mb-2">
                                    <label class="form-label small mb-1">Approval comments (optional)</label>
                                    <textarea name="approval_comments" id="approvalCommentsField"
                                              class="form-control form-control-sm" rows="2"
                                              maxlength="2000" placeholder="Add a note for the audit trail…"></textarea>
                                </div>
                                <button type="button" class="btn btn-success w-100 mb-2" id="approveBtn">
                                    <i class="fas fa-thumbs-up me-1"></i> Approve &amp; enable posting
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.stock-take.reject', $session->id) }}" id="rejectForm">
                                @csrf
                                <div class="mb-2">
                                    <label class="form-label small mb-1">Rejection reason (required)</label>
                                    <textarea name="rejection_reason" id="rejectionReasonField"
                                              class="form-control form-control-sm" rows="2"
                                              maxlength="2000" required
                                              placeholder="Tell the counter what to fix…"></textarea>
                                </div>
                                <button type="button" class="btn btn-outline-warning w-100" id="rejectBtn">
                                    <i class="fas fa-rotate-left me-1"></i> Reject &amp; return to counter
                                </button>
                            </form>
                        @elseif ($isSubmitter)
                            <div class="alert alert-warning small mb-0">
                                <i class="fas fa-lock me-1"></i>
                                You submitted this session — <strong>you cannot approve your own count</strong>
                                (segregation of duties). Another approver must review it.
                            </div>
                        @elseif (! $isApproverRole)
                            <div class="alert alert-secondary small mb-0">
                                <i class="fas fa-clock me-1"></i>
                                Awaiting approval from an approver
                                (roles: <strong>{{ implode(', ', $approverRoles) }}</strong>).
                            </div>
                        @endif
                    @endif

                    {{-- Phase 4: POST (approved, or counting/draft when approval NOT required) --}}
                    @if ($canPost)
                        <form method="POST" action="{{ route('admin.stock-take.post', $session->id) }}" id="postForm">
                            @csrf
                            <input type="hidden" name="post_reason" id="postReasonField" value="">
                            <button type="button" class="btn btn-success w-100 mb-2" id="postBtn">
                                <i class="fas fa-circle-check me-1"></i> Post Session
                            </button>
                        </form>
                        <div class="alert alert-info small mb-3">
                            <i class="fas fa-circle-info me-1"></i>
                            Posting will <strong>apply variances</strong> to stock and <strong>post the GL journal entry</strong>.
                            Cannot be undone — cancellations will reverse it.
                            @if ($session->isApproved())
                                <br>Session was approved — posting is now unlocked.
                            @endif
                        </div>
                    @elseif ($session->isSubmitted())
                        {{-- Submitted but not yet approved — post is blocked. --}}
                        <div class="alert alert-secondary small mb-3">
                            <i class="fas fa-lock me-1"></i>
                            Posting is <strong>locked</strong> until an approver approves this session.
                        </div>
                    @elseif (($session->isDraft() || $session->isCounting()) && ! $allCompleted)
                        <div class="alert alert-warning small mb-3">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            All warehouses must be <strong>completed</strong> before posting or submitting.
                            Currently {{ (int) $progress['completed_wh'] }} / {{ (int) $progress['total_wh'] }} completed.
                        </div>
                    @elseif (($session->isDraft() || $session->isCounting()) && $approvalRequired && ! $canSubmit)
                        {{-- Approval required but the gate flags didn't enable Submit (edge case) --}}
                        <div class="alert alert-warning small mb-3">
                            <i class="fas fa-triangle-exclamation me-1"></i>
                            This session <strong>requires approval</strong> before posting
                            (variance value {{ number_format($varianceValue, 2) }} meets the threshold).
                            Complete all warehouses, then submit for approval.
                        </div>
                    @endif

                    {{-- CANCEL (any non-cancelled status) --}}
                    @if ($canCancel)
                        <form method="POST" action="{{ route('admin.stock-take.cancel', $session->id) }}" id="cancelForm">
                            @csrf
                            <input type="hidden" name="cancel_reason" id="cancelReasonField" value="">
                            <button type="button" class="btn btn-outline-danger w-100" id="cancelBtn">
                                <i class="fas fa-ban me-1"></i>
                                @if ($session->isPosted())
                                    Cancel &amp; reverse
                                @else
                                    Cancel session
                                @endif
                            </button>
                        </form>
                        @if ($session->isPosted())
                            <div class="alert alert-warning small mt-2 mb-0">
                                <i class="fas fa-triangle-exclamation me-1"></i>
                                Cancelling a posted session <strong>reverses the stock movements and the GL entry</strong>.
                                A reason is required.
                            </div>
                        @endif
                    @endif

                    @if ($session->isCancelled())
                        <div class="alert alert-secondary small mb-0">
                            <i class="fas fa-ban me-1"></i>
                            This session is cancelled and cannot be modified further.
                        </div>
                    @endif

                    @if ($session->isPosted() && ! $session->is_reversed)
                        <div class="alert alert-success small mb-0">
                            <i class="fas fa-circle-check me-1"></i>
                            This session is posted. Variance has been applied to stock + GL.
                        </div>
                    @endif
                </div>
            </div>

            {{-- Phase 4: Approval-info card (submitted/approved sessions) --}}
            @if ($session->isSubmitted() || $session->isApproved() || $session->submitted_by || $session->approved_by)
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white">
                        <h2 class="h6 mb-0"><i class="fas fa-user-check me-1 text-primary"></i> Approval workflow</h2>
                    </div>
                    <div class="card-body small">
                        {{-- Policy summary line --}}
                        <div class="alert alert-light border mb-3 py-2">
                            @if ($requireApproval)
                                <i class="fas fa-shield-halved me-1 text-primary"></i>
                                <strong>Approval gate: ON</strong>.
                                Counters must submit; an approver (roles: <strong>{{ implode(', ', $approverRoles) }}</strong>) must approve before posting.
                                @if ($autoApproveBelowValue > 0)
                                    <br><small class="text-muted">Auto-approval below variance value {{ number_format($autoApproveBelowValue, 2) }}.</small>
                                @endif
                            @else
                                <i class="fas fa-shield me-1 text-muted"></i>
                                <strong>Approval gate: OFF</strong>.
                                Counters can post directly.
                                @if ($varianceThresholdBlock > 0)
                                    <br><small class="text-muted">Force-approval when variance value ≥ {{ number_format($varianceThresholdBlock, 2) }}.</small>
                                @endif
                            @endif
                            @if ($varianceValue > 0 || $session->isCounting() || $session->isSubmitted() || $session->isApproved())
                                <br><small>Variance value: <strong>{{ number_format($varianceValue, 2) }}</strong>
                                    @if ($approvalRequired)
                                        <span class="text-danger">— approval required</span>
                                    @else
                                        <span class="text-success">— approval not required</span>
                                    @endif
                                </small>
                            @endif
                        </div>

                        {{-- Submitter info --}}
                        @if ($session->submitted_by)
                            <div class="d-flex justify-content-between py-1 border-bottom">
                                <span class="text-muted"><i class="fas fa-paper-plane me-1"></i>Submitted by</span>
                                <strong>
                                    @if ($submitterUser)
                                        {{ $submitterUser->username }}
                                        @if ($submitterUser->employee?->employee_name)
                                            <span class="text-muted small">({{ $submitterUser->employee->employee_name }})</span>
                                        @endif
                                    @else
                                        <span class="text-muted">user #{{ $session->submitted_by }}</span>
                                    @endif
                                </strong>
                            </div>
                            @if ($session->submitted_at)
                                <div class="d-flex justify-content-between py-1 border-bottom">
                                    <span class="text-muted">Submitted at</span>
                                    <strong>{{ $session->submitted_at->format('d M Y H:i') }}</strong>
                                </div>
                            @endif
                        @endif

                        {{-- Approver info --}}
                        @if ($session->approved_by)
                            <div class="d-flex justify-content-between py-1 border-bottom">
                                <span class="text-muted"><i class="fas fa-thumbs-up me-1"></i>Approved by</span>
                                <strong>
                                    @if ($approverUser)
                                        {{ $approverUser->username }}
                                        @if ($approverUser->employee?->employee_name)
                                            <span class="text-muted small">({{ $approverUser->employee->employee_name }})</span>
                                        @endif
                                    @else
                                        <span class="text-muted">user #{{ $session->approved_by }}</span>
                                    @endif
                                </strong>
                            </div>
                            @if ($session->approved_at)
                                <div class="d-flex justify-content-between py-1 border-bottom">
                                    <span class="text-muted">Approved at</span>
                                    <strong>{{ $session->approved_at->format('d M Y H:i') }}</strong>
                                </div>
                            @endif
                        @elseif ($session->isApproved() && ! $session->approved_by)
                            {{-- Auto-approved (system) --}}
                            <div class="d-flex justify-content-between py-1 border-bottom">
                                <span class="text-muted"><i class="fas fa-robot me-1"></i>Approved by</span>
                                <strong><span class="badge bg-light text-dark">SYSTEM (auto)</span></strong>
                            </div>
                        @endif

                        {{-- Approval / rejection comments --}}
                        @if ($session->approval_comments)
                            <div class="mt-2">
                                <div class="text-muted small mb-1">
                                    @if ($session->isApproved() || $session->isPosted())
                                        <i class="fas fa-comment me-1"></i>Approval comments
                                    @else
                                        <i class="fas fa-comment-dots me-1"></i>Comments
                                    @endif
                                </div>
                                <div class="bg-light rounded p-2 small border">{!! nl2br(e($session->approval_comments)) !!}</div>
                            </div>
                        @endif
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
                        <span class="text-muted">Items counted</span>
                        <strong>{{ $session->items->count() }}</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">GL journal</span>
                        @if ($je)
                            <strong>{{ $je->entry_no }}</strong>
                        @else
                            <span class="text-muted">Not posted</span>
                        @endif
                    </div>
                    <div class="d-flex justify-content-between py-1 border-bottom">
                        <span class="text-muted">Reversed</span>
                        @if ($session->is_reversed)
                            <span class="badge bg-danger-subtle text-danger">Yes</span>
                        @else
                            <span class="text-muted">No</span>
                        @endif
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span class="text-muted">Created</span>
                        <strong class="small">{{ optional($session->created_at)->format('Y-m-d H:i') }}</strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ====== Phase 2: Per-session health-check checklist ====== --}}
    @php
        $hcSummary = $healthCheck['summary'] ?? ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
        $hcItems   = $healthCheck['items'] ?? [];
        $hcReady   = $healthCheck['ready_to_post'] ?? false;
    @endphp
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">
                <i class="fas fa-clipboard-check me-1 text-primary"></i> Session health check
            </h2>
            <span class="small text-muted">
                {{ $hcSummary['pass'] }} pass ·
                {{ $hcSummary['warn'] }} warn ·
                {{ $hcSummary['fail'] }} fail ·
                {{ $hcSummary['info'] }} info
            </span>
        </div>
        <div class="card-body p-0">
            <ul class="list-group list-group-flush">
                @foreach ($hcItems as $it)
                    @php
                        $badgeClass = [
                            'pass' => 'bg-success-subtle text-success',
                            'warn' => 'bg-warning-subtle text-warning',
                            'fail' => 'bg-danger-subtle text-danger',
                            'info' => 'bg-info-subtle text-info',
                        ][$it['status']] ?? 'bg-light text-muted';
                        $icon = [
                            'pass' => 'fa-circle-check',
                            'warn' => 'fa-triangle-exclamation',
                            'fail' => 'fa-circle-xmark',
                            'info' => 'fa-circle-info',
                        ][$it['status']] ?? 'fa-circle';
                    @endphp
                    <li class="list-group-item d-flex align-items-start gap-3 py-2">
                        <i class="fas {{ $icon }} mt-1"></i>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between gap-2 flex-wrap">
                                <strong>{{ $it['title'] }}</strong>
                                <span class="badge {{ $badgeClass }}">{{ strtoupper($it['status']) }}</span>
                            </div>
                            <div class="small text-muted">{{ $it['expected'] }}</div>
                            @if (!empty($it['detail']))
                                <div class="small fw-semibold">{{ $it['detail'] }}</div>
                            @endif
                        </div>
                    </li>
                @endforeach
                @if (empty($hcItems))
                    <li class="list-group-item text-muted small py-3">No checks available for this session.</li>
                @endif
            </ul>
            @if ($hcReady)
                <div class="alert alert-success rounded-0 mb-0 small">
                    <i class="fas fa-circle-check me-1"></i> This session is <strong>ready to post</strong> — all warehouses complete, no blocking failures.
                </div>
            @endif
        </div>
    </div>

    {{-- ====== Phase 2: Audit timeline (chronological log of every action) ====== --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <h2 class="h6 mb-0">
                <i class="fas fa-clock-rotate-left me-1 text-primary"></i> Audit timeline
            </h2>
            <span class="small text-muted">{{ $auditLogs->count() }} event(s) · <a href="{{ route('admin.stock-take.audit', ['session_id' => $session->id]) }}">View in global audit log</a></span>
        </div>
        <div class="card-body p-0">
            @if ($auditLogs->isEmpty())
                <div class="text-muted small py-4 text-center">No audit events recorded yet.</div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 160px;">When</th>
                                <th style="width: 180px;">Action</th>
                                <th style="width: 140px;">Actor</th>
                                <th style="width: 140px;">Transition</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($auditLogs as $log)
                                @php
                                    $color = \App\Models\StockTakeAuditLog::actionColor($log->action);
                                    $isCritical = \App\Models\StockTakeAuditLog::isCritical($log->action);
                                @endphp
                                <tr>
                                    <td class="small">
                                        <div class="fw-semibold">{{ optional($log->created_at)->format('Y-m-d H:i') }}</div>
                                    </td>
                                    <td>
                                        <span class="badge bg-{{ $color }}-subtle text-{{ $color }}">
                                            @if ($isCritical)<i class="fas fa-star me-1"></i>@endif
                                            {{ \App\Models\StockTakeAuditLog::actionLabel($log->action) }}
                                        </span>
                                    </td>
                                    <td class="small">
                                        @if ($log->actor)
                                            {{ $log->actor->username }}
                                            @if ($log->actor->employee?->name)
                                                <div class="text-muted">{{ $log->actor->employee->name }}</div>
                                            @endif
                                        @else
                                            <span class="text-muted">System</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        @if ($log->from_status || $log->to_status)
                                            <span class="badge bg-secondary-subtle text-secondary">{{ $log->from_status ?? '—' }}</span>
                                            <i class="fas fa-arrow-right mx-1 text-muted small"></i>
                                            <span class="badge bg-secondary-subtle text-secondary">{{ $log->to_status ?? '—' }}</span>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        @if ($log->warehouse)
                                            <span class="badge bg-light text-dark me-1"><i class="fas fa-warehouse me-1"></i>{{ $log->warehouse->warehouse_name }}</span>
                                        @endif
                                        @if (is_array($log->payload) && !empty($log->payload))
                                            @foreach ($log->payload as $k => $v)
                                                @if (is_array($v))
                                                    <span class="text-muted">{{ $k }}: {{ count($v) }} item(s)</span>
                                                @else
                                                    <span class="text-muted">{{ $k }}: <strong>{{ is_bool($v) ? ($v ? 'true' : 'false') : $v }}</strong></span>
                                                @endif
                                                @if (!$loop->last)<span class="text-muted mx-1">·</span>@endif
                                            @endforeach
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    // ====== POST session ======
    @if ($canPost)
        $('#postBtn').on('click', function () {
            Swal.fire({
                icon: 'question',
                title: 'Post this session?',
                html: '<p class="text-start">This will <strong>apply stock variances</strong> and <strong>post the GL journal entry</strong>. The action cannot be undone — a cancellation will reverse it.</p>',
                input: 'textarea',
                inputLabel: 'Optional post reason',
                inputPlaceholder: 'e.g. Approved by manager after audit review.',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-check"></i> Post Session',
                confirmButtonColor: '#198754',
                cancelButtonText: 'Keep draft',
                reverseButtons: true
            }).then(function (result) {
                if (result.isConfirmed) {
                    $('#postReasonField').val(result.value || '');
                    var $btn = $('#postBtn');
                    $btn.prop('disabled', true)
                        .html('<i class="fas fa-spinner fa-spin me-1"></i> Posting…');
                    $('#postForm').submit();
                }
            });
        });
    @endif

    // ====== Phase 4: SUBMIT for approval ======
    @if ($canSubmit)
        $('#submitBtn').on('click', function () {
            Swal.fire({
                icon: 'question',
                title: 'Submit for approval?',
                html: '<p class="text-start">This sends the session to an <strong>approver</strong>. '
                    + 'You will not be able to edit counts after submission. '
                    + 'An approver (a different user) will review and approve or reject it.</p>',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-paper-plane"></i> Submit',
                confirmButtonColor: '#0d6efd',
                cancelButtonText: 'Keep counting',
                reverseButtons: true
            }).then(function (result) {
                if (result.isConfirmed) {
                    var $btn = $('#submitBtn');
                    $btn.prop('disabled', true)
                        .html('<i class="fas fa-spinner fa-spin me-1"></i> Submitting…');
                    $('#submitForm').submit();
                }
            });
        });
    @endif

    // ====== Phase 4: APPROVE submitted session ======
    @if ($canApprove)
        $('#approveBtn').on('click', function () {
            Swal.fire({
                icon: 'success',
                title: 'Approve this session?',
                html: '<p class="text-start">Approving unlocks <strong>posting</strong>. '
                    + 'The counter will be notified that their count was accepted.</p>',
                input: 'textarea',
                inputLabel: 'Approval comments (optional)',
                inputPlaceholder: 'Add a note for the audit trail…',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-thumbs-up"></i> Approve',
                confirmButtonColor: '#198754',
                cancelButtonText: 'Cancel',
                reverseButtons: true
            }).then(function (result) {
                if (result.isConfirmed) {
                    $('#approvalCommentsField').val(result.value || '');
                    var $btn = $('#approveBtn');
                    $btn.prop('disabled', true)
                        .html('<i class="fas fa-spinner fa-spin me-1"></i> Approving…');
                    $('#approveForm').submit();
                }
            });
        });
    @endif

    // ====== Phase 4: REJECT submitted session ======
    @if ($canReject)
        $('#rejectBtn').on('click', function () {
            Swal.fire({
                icon: 'warning',
                title: 'Reject and return to counter?',
                html: '<p class="text-start">The session goes back to <strong>counting</strong> for re-count / correction. '
                    + 'A <strong>rejection reason is required</strong> so the counter knows what to fix.</p>',
                input: 'textarea',
                inputLabel: 'Rejection reason (required)',
                inputPlaceholder: 'Tell the counter what to fix…',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-rotate-left"></i> Reject',
                confirmButtonColor: '#fd7e14',
                cancelButtonText: 'Keep submitted',
                reverseButtons: true,
                inputValidator: function (value) {
                    if (!value || !value.trim()) {
                        return 'A rejection reason is required.';
                    }
                    return null;
                }
            }).then(function (result) {
                if (result.isConfirmed) {
                    $('#rejectionReasonField').val(result.value.trim());
                    var $btn = $('#rejectBtn');
                    $btn.prop('disabled', true)
                        .html('<i class="fas fa-spinner fa-spin me-1"></i> Rejecting…');
                    $('#rejectForm').submit();
                }
            });
        });
    @endif

    // ====== CANCEL session ======
    @if ($canCancel)
        $('#cancelBtn').on('click', function () {
            var isPosted = @json($session->isPosted());
            var title = isPosted ? 'Cancel & reverse this session?' : 'Cancel this session?';
            var html  = isPosted
                ? '<p class="text-start">This will <strong>reverse the stock movements and the GL journal entry</strong>. A reason is required.</p>'
                : '<p class="text-start">The session will be marked cancelled. A reason is required.</p>';

            Swal.fire({
                icon: 'warning',
                title: title,
                html: html,
                input: 'textarea',
                inputLabel: 'Cancel reason (required)',
                inputPlaceholder: 'Why is this session being cancelled?',
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-ban"></i> Cancel session',
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
    @endif

    // Optional: DataTables on the variance lines table.
    @if (! empty($varianceLines) && $varianceLines->isNotEmpty())
        $('#varianceTable').DataTable({
            paging: false,
            info: false,
            ordering: true,
            dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
            language: { search: 'Filter variance lines:' }
        });
    @endif
});
</script>
@endpush
@endsection

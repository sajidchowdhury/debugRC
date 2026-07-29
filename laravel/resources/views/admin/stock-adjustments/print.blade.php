@php
    // Set $branchCode BEFORE @extends so layouts.print can resolve the
    // branch color for the company header + toolbar. Falls back to HO red
    // when the adjustment has no branch (data-quality smell — surfaced by
    // the Phase 8 checklist §5).
    $branchCode = $adjustment->branch?->branch_code ?? null;
@endphp
@extends('layouts.print')

@section('print_content')
@php
    $adj = $adjustment;
    $je  = $adj->journalEntry;
    $jeLines = $je ? $je->lines : collect();
    $debitTotal  = $jeLines->sum(fn ($l) => (float) $l->debit);
    $creditTotal = $jeLines->sum(fn ($l) => (float) $l->credit);

    // Reversing JE rows (flattened: one row per journal_line). Grouped by
    // JE id in the view so a multi-line reversal renders as one block.
    $reversingJeRows = $reversingJe ?? collect();
    $hasReversing = $reversingJeRows->isNotEmpty();

    $categoryLabels = \App\Models\StockAdjustment::CATEGORY_LABELS;
    $statusLabels   = \App\Models\StockAdjustment::STATUS_LABELS;

    $fmtDate = function ($d): string {
        if (!$d) return '—';
        try { return \Carbon\Carbon::parse($d)->format('d M Y'); } catch (\Throwable $e) { return (string) $d; }
    };
    $fmtUser = function ($u): string {
        if (!$u) return '';
        return $u->username ?? $u->name ?? ('user #' . $u->id);
    };
@endphp

<div class="print-page">
    {{-- Watermark for cancelled / reversed --}}
    @if ($adj->isCancelled() || $adj->is_reversed)
        <div class="watermark position-absolute top-50 start-50 translate-middle text-uppercase fw-bold"
             style="font-size:4rem;transform:rotate(-30deg);pointer-events:none;z-index:0;">
            {{ $adj->is_reversed ? 'REVERSED' : 'CANCELLED' }}
        </div>
    @endif

    {{-- Company header --}}
    <div class="company-header d-flex justify-content-between align-items-start">
        <div>
            <div class="company-name">{{ config('app.name', 'RC ERP') }}</div>
            <div class="text-muted small">
                {{ $adj->warehouse?->branch?->branch_name ?? '—' }}
            </div>
        </div>
        <div class="text-end">
            <div class="doc-title">Stock Adjustment Voucher</div>
            <div class="text-muted small">{{ $statusLabels[$adj->status] ?? ucfirst($adj->status) }}</div>
        </div>
    </div>

    {{-- Meta grid --}}
    <div class="meta-grid">
        <div>
            <div class="meta-label">Voucher #</div>
            <div class="meta-value">{{ $adj->adjustment_code }}</div>
        </div>
        <div>
            <div class="meta-label">Date</div>
            <div class="meta-value">{{ $fmtDate($adj->adjustment_date) }}</div>
        </div>
        <div>
            <div class="meta-label">Warehouse</div>
            <div class="meta-value">{{ $adj->warehouse?->warehouse_name ?? '—' }}</div>
        </div>
        <div>
            <div class="meta-label">Branch</div>
            <div class="meta-value">{{ $adj->warehouse?->branch?->branch_name ?? '—' }}</div>
        </div>
        <div>
            <div class="meta-label">Category</div>
            <div class="meta-value">{{ $categoryLabels[$adj->adjustment_category] ?? str_replace('_', ' ', $adj->adjustment_category) }}</div>
        </div>
        <div>
            <div class="meta-label">Type</div>
            <div class="meta-value">
                @if ($adj->isIncrease()) Increase (+)
                @elseif ($adj->isDecrease()) Decrease (−)
                @else {{ $adj->adjustment_type }} @endif
            </div>
        </div>
    </div>

    @if ($adj->reason)
        <div class="mb-3">
            <div class="meta-label">Reason</div>
            <div class="small">{{ $adj->reason }}</div>
        </div>
    @endif

    {{-- Items table --}}
    <div class="table-responsive">
        <table class="table table-sm table-bordered items-table mb-0 align-middle">
            <thead>
                <tr>
                    <th style="width:30px;">#</th>
                    <th>Product</th>
                    <th class="text-end">Qty Entered</th>
                    <th class="text-end">Qty (Base)</th>
                    <th class="text-end">Rate (Tk)</th>
                    <th class="text-end">Amount (Tk)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($adj->items as $i => $item)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td>
                            <div class="fw-semibold">{{ $item->product?->product_name ?? ('Product #' . $item->product_id) }}</div>
                            <div class="small text-muted">{{ $item->product?->product_code ?? '' }}</div>
                            @if ($item->reason)
                                <div class="small text-muted fst-italic">{{ $item->reason }}</div>
                            @endif
                        </td>
                        <td class="text-end">
                            {{ number_format($item->enteredQty(), 4) }}
                            <span class="small text-muted">{{ $item->uom?->code ?? $item->product?->unit ?? '' }}</span>
                        </td>
                        <td class="text-end">
                            {{ number_format($item->baseQty(), 4) }}
                            <span class="small text-muted">{{ $item->product?->unit ?? '' }}</span>
                        </td>
                        <td class="text-end">{{ number_format((float) $item->rate, 2) }}</td>
                        <td class="text-end fw-semibold">{{ number_format($item->amount(), 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr class="table-light fw-bold">
                    <td colspan="5" class="text-end">Total</td>
                    <td class="text-end">{{ number_format((float) $adj->total_amount, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    {{-- GL summary (original JE) --}}
    @if ($je)
        <div class="mt-4">
            <h3 class="h6 mb-2"><i class="fas fa-book me-1"></i> GL Journal Entry</h3>
            <div class="small text-muted mb-2">
                JE# <span class="fw-semibold">{{ $je->entry_no }}</span>
                · dated {{ $fmtDate($je->entry_date) }}
                @if ($je->is_reversed) · <span class="text-danger fw-semibold">reversed</span> @endif
            </div>
            <table class="table table-sm table-bordered mb-0 align-middle" style="font-size:.85rem;">
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
                                {{ $line->ledger?->ledger_name ?? ('Ledger #' . $line->ledger_id) }}
                                <div class="small text-muted">{{ $line->ledger?->ledger_code ?? '' }}</div>
                            </td>
                            <td class="text-end">{{ (float) $line->debit > 0 ? number_format((float) $line->debit, 2) : '—' }}</td>
                            <td class="text-end">{{ (float) $line->credit > 0 ? number_format((float) $line->credit, 2) : '—' }}</td>
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
                                <span class="badge bg-success-subtle text-success">Balanced</span>
                            @else
                                <span class="badge bg-danger-subtle text-danger">Out by {{ number_format(abs($debitTotal - $creditTotal), 2) }}</span>
                            @endif
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @else
        <div class="mt-4 text-muted small">
            <i class="fas fa-info-circle me-1"></i>
            No GL journal entry (adjustment not confirmed, or zero-amount).
        </div>
    @endif

    {{-- Reversing JE block (if cancelled after confirm) --}}
    @if ($hasReversing)
        @php
            // Group the flat rows by JE id so each reversal renders as one block.
            $reversalGroups = $reversingJeRows->groupBy('id');
        @endphp
        <div class="mt-4">
            <h3 class="h6 mb-2 text-danger"><i class="fas fa-rotate-left me-1"></i> Reversing Journal Entry</h3>
            @foreach ($reversalGroups as $jeId => $rows)
                @php
                    $first = $rows->first();
                    $revDebit  = $rows->sum(fn ($r) => (float) $r->debit);
                    $revCredit = $rows->sum(fn ($r) => (float) $r->credit);
                @endphp
                <div class="small text-muted mb-2">
                    JE# <span class="fw-semibold">{{ $first->entry_no }}</span>
                    · dated {{ $fmtDate($first->entry_date) }}
                    · reason: <em>{{ $first->reverse_reason ?: '—' }}</em>
                </div>
                <table class="table table-sm table-bordered mb-2 align-middle" style="font-size:.85rem;">
                    <thead class="table-light">
                        <tr>
                            <th>Ledger</th>
                            <th class="text-end">Debit (Tk)</th>
                            <th class="text-end">Credit (Tk)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr>
                                <td>{{ $r->ledger_name ?? ('Ledger #' . $r->ledger_id) }}</td>
                                <td class="text-end">{{ (float) $r->debit > 0 ? number_format((float) $r->debit, 2) : '—' }}</td>
                                <td class="text-end">{{ (float) $r->credit > 0 ? number_format((float) $r->credit, 2) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-bold">
                            <td class="text-end">Total</td>
                            <td class="text-end">{{ number_format($revDebit, 2) }}</td>
                            <td class="text-end">{{ number_format($revCredit, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            @endforeach
        </div>
    @endif

    {{-- Signatures --}}
    <div class="signature-section mt-5">
        <div class="signature-box">
            <div class="small text-muted mb-4">Prepared by</div>
            <div class="fw-semibold small">{{ $fmtUser($adj->createdBy) }}</div>
            <div class="small text-muted">{{ $fmtDate($adj->created_at) }}</div>
        </div>
        <div class="signature-box">
            <div class="small text-muted mb-4">Approved by</div>
            <div class="fw-semibold small">{{ $fmtUser($adj->approvedBy) }}</div>
            <div class="small text-muted">{{ $fmtDate($adj->approved_at) }}</div>
        </div>
        <div class="signature-box">
            <div class="small text-muted mb-4">Posted by</div>
            <div class="fw-semibold small">{{ $fmtUser($adj->confirmedBy) }}</div>
            <div class="small text-muted">{{ $fmtDate($adj->confirmed_at) }}</div>
        </div>
    </div>

    {{-- Footer note --}}
    <div class="mt-4 pt-2 border-top small text-muted d-flex justify-content-between">
        <span>Generated by RC ERP · {{ now()->format('d M Y, H:i') }}</span>
        <span>Voucher {{ $adj->adjustment_code }}</span>
    </div>
</div>
@endsection

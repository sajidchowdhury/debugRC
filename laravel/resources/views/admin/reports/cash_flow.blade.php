@extends('layouts.admin')

@php
    $op  = $sections['operating'] ?? [];
    $inv = $sections['investing'] ?? [];
    $fin = $sections['financing'] ?? [];
    $fromDate = \Carbon\Carbon::parse($meta['from_date'])->format('d M Y');
    $toDate   = \Carbon\Carbon::parse($meta['to_date'])->format('d M Y');
@endphp

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-water me-2 text-primary"></i> Cash Flow Statement</h2>
            <p class="text-muted mb-0 small">Indirect method &mdash; {{ $fromDate }} &rarr; {{ $toDate }}.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
            </a>
            <a href="{{ route('admin.reports.cashFlow', array_merge(request()->query(), ['export' => 'csv'])) }}" class="btn btn-outline-success btn-sm">
                <i class="fas fa-file-csv me-1"></i> CSV
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.reports.cashFlow') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">From date</label>
                    <input type="date" name="from_date" class="form-control form-control-sm"
                           value="{{ old('from_date', request('from_date', $meta['from_date'] ?? '')) }}">
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">To date</label>
                    <input type="date" name="to_date" class="form-control form-control-sm"
                           value="{{ old('to_date', request('to_date', $meta['to_date'] ?? '')) }}">
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">Branch (optional)</label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        @foreach (\App\Models\Branch::active()->orderBy('branch_name')->get() as $b)
                            <option value="{{ $b->id }}" @selected((int) old('branch_id', request('branch_id', $meta['branch_id'] ?? '')) === $b->id)>{{ $b->branch_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Summary cards --}}
    <div class="row g-2 mb-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Operating Cash</small>
                    <div class="fs-5 fw-bold {{ ($totals['operating_cash'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                        Tk {{ number_format($totals['operating_cash'], 2) }}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Investing Cash</small>
                    <div class="fs-5 fw-bold {{ ($totals['investing_cash'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                        Tk {{ number_format($totals['investing_cash'], 2) }}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Financing Cash</small>
                    <div class="fs-5 fw-bold {{ ($totals['financing_cash'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                        Tk {{ number_format($totals['financing_cash'], 2) }}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-2">
                    <small class="text-muted d-block">Net Cash Change</small>
                    <div class="fs-5 fw-bold {{ ($totals['net_cash_change'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                        Tk {{ number_format($totals['net_cash_change'], 2) }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════
         SECTION 1: Cash Flow from Operating Activities
         ══════════════════════════════════════════════════════════════════ --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
            <span><i class="fas fa-gears me-1 text-primary"></i> <strong>{{ $op['label'] ?? 'Cash Flow from Operating Activities' }}</strong></span>
            <span class="fw-bold {{ ($op['net'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                Tk {{ number_format($op['net'] ?? 0, 2) }}
            </span>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-striped mb-0">
                <tbody>
                    {{-- Net Profit --}}
                    <tr>
                        <td class="ps-3">
                            <strong>Net Profit</strong> <small class="text-muted">(from P&L)</small>
                        </td>
                        <td class="text-end fw-semibold {{ ($op['net_profit'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}" width="180">
                            {{ ($op['net_profit'] ?? 0) >= 0 ? '+' : '' }}Tk {{ number_format($op['net_profit'] ?? 0, 2) }}
                        </td>
                    </tr>

                    {{-- Depreciation (add back) --}}
                    <tr>
                        <td class="ps-3">
                            <strong>(+) Depreciation &amp; Amortization</strong>
                            <small class="text-muted">(non-cash expense added back)</small>
                        </td>
                        <td class="text-end fw-semibold {{ ($op['depreciation'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}" width="180">
                            {{ ($op['depreciation'] ?? 0) >= 0 ? '+' : '' }}Tk {{ number_format($op['depreciation'] ?? 0, 2) }}
                        </td>
                    </tr>

                    {{-- Depreciation detail rows --}}
                    @if (!empty($op['dep_rows']) && count($op['dep_rows']) > 0)
                        @foreach ($op['dep_rows'] as $depRow)
                            <tr class="small text-muted">
                                <td class="ps-5">{{ $depRow->ledger_code }} &mdash; {{ $depRow->ledger_name }}</td>
                                <td class="text-end" width="180">Tk {{ number_format($depRow->net_amount, 2) }}</td>
                            </tr>
                        @endforeach
                    @endif

                    {{-- Working capital adjustments --}}
                    <tr>
                        <td class="ps-3"><strong>Changes in Working Capital:</strong></td>
                        <td width="180"></td>
                    </tr>
                    @foreach ($op['wc_adjustments'] ?? [] as $wc)
                        <tr>
                            <td class="ps-4">
                                @if ($wc->change >= 0)
                                    (−) Increase in {{ $wc->label }}
                                @else
                                    (+) Decrease in {{ $wc->label }}
                                @endif
                                <small class="text-muted">
                                    (Opening: Tk {{ number_format($wc->opening, 2) }} &rarr; Closing: Tk {{ number_format($wc->closing, 2) }})
                                </small>
                            </td>
                            <td class="text-end fw-semibold {{ $wc->adjustment >= 0 ? 'text-success' : 'text-danger' }}" width="180">
                                {{ $wc->adjustment >= 0 ? '+' : '' }}Tk {{ number_format($wc->adjustment, 2) }}
                            </td>
                        </tr>
                        {{-- Detail rows for each WC category --}}
                        @if ($wc->detail_rows->count() > 0)
                            @foreach ($wc->detail_rows as $detail)
                                @if (abs($detail->closing_balance - $detail->opening_balance) > 0.005)
                                    <tr class="small text-muted">
                                        <td class="ps-5">{{ $detail->ledger_code }} &mdash; {{ $detail->ledger_name }}</td>
                                        <td class="text-end" width="180">
                                            Tk {{ number_format($detail->closing_balance - $detail->opening_balance, 2) }}
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        @endif
                    @endforeach
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td class="ps-3">Net Cash from Operating Activities</td>
                        <td class="text-end {{ ($op['net'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}" width="180">
                            Tk {{ number_format($op['net'] ?? 0, 2) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════
         SECTION 2: Cash Flow from Investing Activities
         ══════════════════════════════════════════════════════════════════ --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
            <span><i class="fas fa-building me-1 text-warning"></i> <strong>{{ $inv['label'] ?? 'Cash Flow from Investing Activities' }}</strong></span>
            <span class="fw-bold {{ ($inv['net'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                Tk {{ number_format($inv['net'] ?? 0, 2) }}
            </span>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-striped mb-0">
                <tbody>
                    @forelse ($inv['rows'] ?? [] as $row)
                        <tr>
                            <td class="ps-3">
                                @if ($row->net_amount < 0)
                                    (−) Purchase of {{ $row->ledger_name }}
                                @else
                                    (+) Sale / Disposal of {{ $row->ledger_name }}
                                @endif
                                <small class="text-muted">({{ $row->ledger_code }})</small>
                            </td>
                            <td class="text-end fw-semibold {{ $row->net_amount >= 0 ? 'text-success' : 'text-danger' }}" width="180">
                                {{ $row->net_amount >= 0 ? '+' : '' }}Tk {{ number_format($row->net_amount, 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="ps-3 text-muted fst-italic">No investing activity in this period.</td>
                            <td class="text-end text-muted" width="180">&mdash;</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td class="ps-3">Net Cash from Investing Activities</td>
                        <td class="text-end {{ ($inv['net'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}" width="180">
                            Tk {{ number_format($inv['net'] ?? 0, 2) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════
         SECTION 3: Cash Flow from Financing Activities
         ══════════════════════════════════════════════════════════════════ --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
            <span><i class="fas fa-landmark me-1 text-info"></i> <strong>{{ $fin['label'] ?? 'Cash Flow from Financing Activities' }}</strong></span>
            <span class="fw-bold {{ ($fin['net'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                Tk {{ number_format($fin['net'] ?? 0, 2) }}
            </span>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-striped mb-0">
                <tbody>
                    @forelse ($fin['rows'] ?? [] as $row)
                        <tr>
                            <td class="ps-3">
                                @if ($row->net_amount > 0)
                                    (+) Proceeds from {{ $row->ledger_name }}
                                @else
                                    (−) Repayment / Drawings of {{ $row->ledger_name }}
                                @endif
                                <small class="text-muted">({{ $row->ledger_code }})</small>
                            </td>
                            <td class="text-end fw-semibold {{ $row->net_amount >= 0 ? 'text-success' : 'text-danger' }}" width="180">
                                {{ $row->net_amount >= 0 ? '+' : '' }}Tk {{ number_format($row->net_amount, 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td class="ps-3 text-muted fst-italic">No financing activity in this period.</td>
                            <td class="text-end text-muted" width="180">&mdash;</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td class="ps-3">Net Cash from Financing Activities</td>
                        <td class="text-end {{ ($fin['net'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}" width="180">
                            Tk {{ number_format($fin['net'] ?? 0, 2) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════
         SECTION 4: Net Cash Movement
         ══════════════════════════════════════════════════════════════════ --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-primary text-white py-2">
            <i class="fas fa-money-bill-trend-up me-1"></i> <strong>Net Cash Movement</strong>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <tbody>
                    <tr>
                        <td class="ps-3">Opening Cash Balance</td>
                        <td class="text-end fw-semibold" width="180">Tk {{ number_format($totals['cash_opening'], 2) }}</td>
                    </tr>
                    <tr>
                        <td class="ps-3">
                            Net Increase / (Decrease) in Cash
                            <small class="text-muted d-block ps-3">
                                Operating Tk {{ number_format($totals['operating_cash'], 2) }}
                                + Investing Tk {{ number_format($totals['investing_cash'], 2) }}
                                + Financing Tk {{ number_format($totals['financing_cash'], 2) }}
                            </small>
                        </td>
                        <td class="text-end fw-bold {{ $totals['net_cash_change'] >= 0 ? 'text-success' : 'text-danger' }}" width="180">
                            {{ $totals['net_cash_change'] >= 0 ? '+' : '' }}Tk {{ number_format($totals['net_cash_change'], 2) }}
                        </td>
                    </tr>
                    <tr class="table-primary fw-bold">
                        <td class="ps-3">Closing Cash Balance</td>
                        <td class="text-end" width="180">Tk {{ number_format($totals['cash_closing'], 2) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Integrity check banner --}}
    <div class="alert {{ $checks['plugs_to_gl_cash'] ? 'alert-success' : 'alert-warning' }} d-flex justify-content-between align-items-center py-2">
        <span>
            <i class="fas {{ $checks['plugs_to_gl_cash'] ? 'fa-check-circle' : 'fa-exclamation-triangle' }} me-1"></i>
            @if ($checks['plugs_to_gl_cash'])
                Cash flow reconciles with GL cash/bank movement.
            @else
                Cash flow does <strong>not</strong> fully reconcile with GL cash/bank movement.
            @endif
        </span>
        <span class="fw-bold">
            Plug Difference: Tk {{ number_format(abs($totals['plug_difference']), 2) }}
        </span>
    </div>

    {{-- GL cash movement detail --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-2">
            <i class="fas fa-university me-1 text-primary"></i>
            <strong>GL Cash / Bank Movement (Verification)</strong>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <tbody>
                    <tr>
                        <td class="ps-3">Actual GL cash/bank movement in period</td>
                        <td class="text-end fw-semibold {{ $totals['net_cash_movement'] >= 0 ? 'text-success' : 'text-danger' }}" width="180">
                            Tk {{ number_format($totals['net_cash_movement'], 2) }}
                        </td>
                    </tr>
                    <tr>
                        <td class="ps-3">Calculated cash movement (Operating + Investing + Financing)</td>
                        <td class="text-end fw-semibold {{ $totals['net_cash_change'] >= 0 ? 'text-success' : 'text-danger' }}" width="180">
                            Tk {{ number_format($totals['net_cash_change'], 2) }}
                        </td>
                    </tr>
                    <tr>
                        <td class="ps-3">Difference</td>
                        <td class="text-end fw-semibold {{ abs($totals['plug_difference']) < 0.01 ? 'text-success' : 'text-danger' }}" width="180">
                            Tk {{ number_format($totals['plug_difference'], 2) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Footer note --}}
    <p class="text-muted small mb-0">
        <i class="fas fa-info-circle me-1"></i>
        This cash flow statement uses the <strong>indirect method</strong>: starting from net profit, adjusted for non-cash items
        (depreciation) and working-capital changes. A near-zero plug difference confirms reconciliation with the actual
        GL cash/bank movement. Tolerance: Tk 0.01.
    </p>
</div>

{{-- Print-friendly styles --}}
@push('styles')
<style>
@media print {
    .btn, .form-select, .form-control, form { display: none !important; }
    .card { border: 1px solid #dee2e6 !important; box-shadow: none !important; }
    .card-header { background: #f8f9fa !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .table-primary { background: #cfe2ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .alert { border: 1px solid !important; }
    .text-success { color: #198754 !important; }
    .text-danger { color: #dc3545 !important; }
}
</style>
@endpush
@endsection

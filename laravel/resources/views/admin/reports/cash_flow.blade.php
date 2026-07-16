@extends('layouts.admin')

@php
    $op = $sections['operating'] ?? [];
@endphp

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-water me-2 text-primary"></i> Cash Flow Statement (Indirect Method)</h2>
            <p class="text-muted mb-0 small">From net profit, adjusted for working-capital changes — plugs to GL cash/bank movement.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.reports.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Reports
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

    {{-- Operating Activities section --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-2">
            <i class="fas fa-gears me-1 text-primary"></i>
            <strong>{{ $op['label'] ?? 'Operating Activities' }}</strong>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-striped mb-0">
                <tbody>
                    <tr>
                        <td>Net Profit (from P&amp;L)</td>
                        <td class="text-end fw-semibold {{ ($op['net_profit'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ ($op['net_profit'] ?? 0) >= 0 ? '+' : '' }}Tk {{ number_format($op['net_profit'] ?? 0, 2) }}
                        </td>
                    </tr>
                    <tr>
                        <td>(+) Decrease / (−) Increase in Accounts Receivable</td>
                        <td class="text-end {{ ($op['ar_change'] ?? 0) <= 0 ? 'text-success' : 'text-danger' }}">
                            {{ ($op['ar_change'] ?? 0) >= 0 ? '−' : '+' }}Tk {{ number_format(abs($op['ar_change'] ?? 0), 2) }}
                        </td>
                    </tr>
                    <tr>
                        <td>(+) Increase / (−) Decrease in Accounts Payable</td>
                        <td class="text-end {{ ($op['ap_change'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ ($op['ap_change'] ?? 0) >= 0 ? '+' : '−' }}Tk {{ number_format(abs($op['ap_change'] ?? 0), 2) }}
                        </td>
                    </tr>
                    <tr>
                        <td>(−) Increase / (+) Decrease in Inventory</td>
                        <td class="text-end {{ ($op['inv_change'] ?? 0) <= 0 ? 'text-success' : 'text-danger' }}">
                            {{ ($op['inv_change'] ?? 0) >= 0 ? '−' : '+' }}Tk {{ number_format(abs($op['inv_change'] ?? 0), 2) }}
                        </td>
                    </tr>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td>Net Operating Cash</td>
                        <td class="text-end {{ ($op['net'] ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                            Tk {{ number_format($op['net'] ?? 0, 2) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- GL cash movement (the plug) --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white py-2">
            <i class="fas fa-university me-1 text-primary"></i>
            <strong>Net Cash Movement (from GL Cash/Bank ledgers)</strong>
        </div>
        <div class="card-body py-2 d-flex justify-content-between align-items-center">
            <span class="text-muted small">Total movement of all cash &amp; bank ledgers in the period.</span>
            <span class="fw-bold fs-5 {{ $totals['net_cash_movement'] >= 0 ? 'text-success' : 'text-danger' }}">
                Tk {{ number_format($totals['net_cash_movement'], 2) }}
            </span>
        </div>
    </div>

    {{-- Plug difference --}}
    <div class="alert {{ $checks['plugs_to_gl_cash'] ? 'alert-success' : 'alert-danger' }} d-flex justify-content-between align-items-center">
        <span>
            <i class="fas {{ $checks['plugs_to_gl_cash'] ? 'fa-check-circle' : 'fa-exclamation-triangle' }} me-1"></i>
            Plug Difference (Operating Cash − GL Cash Movement)
        </span>
        <span class="fw-bold fs-5">Tk {{ number_format($totals['plug_difference'], 2) }}</span>
    </div>

    <p class="text-muted small mb-0">
        <i class="fas fa-info-circle me-1"></i>
        A near-zero plug difference indicates the indirect-method cash flow reconciles with the actual GL cash/bank movement.
        Tolerance: Tk 0.01.
    </p>
</div>
@endsection

@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">

    {{-- Hero header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h4 mb-1"><i class="fas fa-building-columns me-2 text-primary"></i> Balance Sheet</h2>
            <p class="text-muted mb-0 small">Assets = Liabilities + Equity as of {{ \Carbon\Carbon::parse($meta['as_of_date'])->format('d M Y') }}.</p>
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
            <form method="GET" action="{{ route('admin.reports.balanceSheet') }}" class="row g-2 align-items-end">
                <div class="col-sm-6 col-md-3">
                    <label class="form-label small mb-1">As of date</label>
                    <input type="date" name="as_of_date" class="form-control form-control-sm"
                           value="{{ old('as_of_date', request('as_of_date', $meta['as_of_date'] ?? '')) }}">
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
                <div class="col-sm-6 col-md-3 d-flex align-items-center pb-1">
                    <div class="form-check">
                        <input type="checkbox" name="include_zero" value="1" class="form-check-input" id="incZero"
                               @checked(old('include_zero', request('include_zero', false)))>
                        <label class="form-check-label small" for="incZero">Include zero-balance ledgers</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-play me-1"></i> Run
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Balance check banner --}}
    <div class="alert {{ $checks['balanced'] ? 'alert-success' : 'alert-danger' }} d-flex justify-content-between align-items-center py-2 mb-3">
        <span>
            <i class="fas {{ $checks['balanced'] ? 'fa-check-circle' : 'fa-exclamation-triangle' }} me-1"></i>
            {{ $checks['balanced'] ? 'Balance sheet is balanced.' : 'Balance sheet is OUT OF BALANCE.' }}
        </span>
        <span class="fw-bold">
            Variance: Tk {{ number_format(abs($totals['total_assets'] - $totals['total_liabilities_equity']), 2) }}
        </span>
    </div>

    {{-- Two-column layout --}}
    <div class="row g-3">
        {{-- Assets --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-primary text-white py-2">
                    <i class="fas fa-coins me-1"></i> Assets
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Code</th>
                                    <th>Ledger</th>
                                    <th class="text-end">Total Dr</th>
                                    <th class="text-end">Net Dr</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($assets as $a)
                                    <tr>
                                        <td><code>{{ $a->ledger_code }}</code></td>
                                        <td>{{ $a->ledger_name }}</td>
                                        <td class="text-end">{{ number_format($a->total_debit, 2) }}</td>
                                        <td class="text-end fw-semibold">{{ number_format($a->net_debit, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted text-center py-2">No assets.</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot class="table-primary fw-bold">
                                <tr>
                                    <td colspan="3" class="text-end">Total Assets</td>
                                    <td class="text-end">{{ number_format($totals['total_assets'], 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Liabilities + Equity --}}
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-warning text-dark py-2">
                    <i class="fas fa-money-bill-wave me-1"></i> Liabilities
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Code</th>
                                    <th>Ledger</th>
                                    <th class="text-end">Total Cr</th>
                                    <th class="text-end">Net Cr</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($liabilities as $l)
                                    <tr>
                                        <td><code>{{ $l->ledger_code }}</code></td>
                                        <td>{{ $l->ledger_name }}</td>
                                        <td class="text-end">{{ number_format($l->total_credit, 2) }}</td>
                                        <td class="text-end fw-semibold">{{ number_format($l->net_credit, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted text-center py-2">No liabilities.</td></tr>
                                @endforelse
                            </tbody>
                            <tfoot class="table-warning fw-bold">
                                <tr>
                                    <td colspan="3" class="text-end">Total Liabilities</td>
                                    <td class="text-end">{{ number_format($totals['total_liabilities'], 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-info text-white py-2">
                    <i class="fas fa-landmark me-1"></i> Equity
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Code</th>
                                    <th>Ledger</th>
                                    <th class="text-end">Net Cr</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($equity as $e)
                                    <tr>
                                        <td><code>{{ $e->ledger_code }}</code></td>
                                        <td>{{ $e->ledger_name }}</td>
                                        <td class="text-end">{{ number_format($e->net_credit, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted text-center py-2">No equity ledgers.</td></tr>
                                @endforelse
                                <tr class="table-light">
                                    <td colspan="2"><em>Current Period Result</em><br><small class="text-muted">(unclosed income − expense)</small></td>
                                    <td class="text-end fw-semibold">{{ number_format($current_period_result, 2) }}</td>
                                </tr>
                            </tbody>
                            <tfoot class="table-info fw-bold">
                                <tr>
                                    <td colspan="2" class="text-end">Total Equity + Period Result</td>
                                    <td class="text-end">{{ number_format($totals['total_equity'], 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Bottom totals --}}
    <div class="row g-3 mt-1">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm bg-primary-subtle">
                <div class="card-body py-2 d-flex justify-content-between">
                    <span class="fw-semibold">Total Assets</span>
                    <span class="fw-bold fs-5">Tk {{ number_format($totals['total_assets'], 2) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm bg-info-subtle">
                <div class="card-body py-2 d-flex justify-content-between">
                    <span class="fw-semibold">Total Liabilities + Equity</span>
                    <span class="fw-bold fs-5">Tk {{ number_format($totals['total_liabilities_equity'], 2) }}</span>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

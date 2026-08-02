@extends('layouts.admin')

@section('title', 'Consolidated Trial Balance — Remote Center ERP')

@section('content')
<div class="container-fluid py-2">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item"><a href="{{ route('admin.consolidation.index') }}">Consolidation</a></li>
                    <li class="breadcrumb-item active">Consolidated Trial Balance</li>
                </ol>
            </nav>
            <h2 class="h4 mb-1"><i class="fas fa-scale-balanced me-2 text-primary"></i> Consolidated Trial Balance</h2>
            <p class="text-muted mb-0 small">
                From {{ \Carbon\Carbon::parse($meta['from_date'])->format('d-m-Y') }} to {{ \Carbon\Carbon::parse($meta['to_date'])->format('d-m-Y') }}
                @if(!empty($meta['company_id'])) &middot; Company #{{ $meta['company_id'] }} @endif
            </p>
        </div>
        <div class="d-flex gap-2">
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-print me-1"></i> Print
            </button>
            <a href="{{ route('admin.consolidation.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" action="{{ route('admin.consolidation.consolidated-tb') }}" class="row g-2 align-items-end">
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
                    <label class="form-label small mb-1">Company</label>
                    <select name="company_id" class="form-select form-select-sm">
                        <option value="">All companies</option>
                        @foreach(\App\Models\Company::active()->orderBy('company_code')->get() as $c)
                            <option value="{{ $c->id }}" @selected((string) old('company_id', request('company_id', $meta['company_id'] ?? '')) === (string) $c->id)>
                                {{ $c->company_name }} ({{ $c->company_code }})
                            </option>
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

    {{-- Integrity checks badges --}}
    @if(isset($checks))
    <div class="d-flex gap-2 mb-3 flex-wrap">
        @foreach($checks as $key => $value)
            @php
                $isCheck = is_bool($value);
                $isPassed = $isCheck ? $value : false;
            @endphp
            @if($isCheck)
            <span class="badge {{ $isPassed ? 'bg-success' : 'bg-danger' }} py-2 px-3">
                <i class="fas fa-{{ $isPassed ? 'check' : 'times' }} me-1"></i>
                {{ ucwords(str_replace('_', ' ', $key)) }}
            </span>
            @elseif(is_numeric($value) && str_contains($key, 'diff'))
            <span class="badge {{ abs($value) < 0.01 ? 'bg-success' : 'bg-danger' }} py-2 px-3">
                <i class="fas fa-{{ abs($value) < 0.01 ? 'check' : 'times' }} me-1"></i>
                {{ ucwords(str_replace('_', ' ', $key)) }}: {{ number_format(abs($value), 2) }}
            </span>
            @endif
        @endforeach
    </div>
    @endif

    {{-- Main Consolidated Trial Balance Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size: 0.85rem;">
                    <thead>
                        <tr class="table-dark">
                            <th style="min-width: 80px;">Code</th>
                            <th style="min-width: 200px;">Name</th>
                            <th style="min-width: 100px;">Type</th>
                            <th class="text-end" style="min-width: 130px;">Total Debit</th>
                            <th class="text-end" style="min-width: 130px;">Total Credit</th>
                            <th class="text-end" style="min-width: 130px;">Elim. Debit</th>
                            <th class="text-end" style="min-width: 130px;">Elim. Credit</th>
                            <th class="text-end" style="min-width: 140px;">Consolidated Debit</th>
                            <th class="text-end" style="min-width: 140px;">Consolidated Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($data as $row)
                        <tr>
                            <td>
                                <span class="fw-bold">{{ $row->ledger_code ?? $row->code ?? '' }}</span>
                            </td>
                            <td>{{ $row->ledger_name ?? $row->name ?? '' }}</td>
                            <td>
                                @php
                                    $typeBadge = match($row->account_type ?? $row->type ?? '') {
                                        'asset' => 'bg-primary',
                                        'liability' => 'bg-warning text-dark',
                                        'equity' => 'bg-info',
                                        'income', 'revenue' => 'bg-success',
                                        'expense' => 'bg-danger',
                                        default => 'bg-secondary'
                                    };
                                @endphp
                                <span class="badge {{ $typeBadge }}">{{ ucfirst($row->account_type ?? $row->type ?? '') }}</span>
                            </td>
                            <td class="text-end">
                                @if(($row->total_debit ?? 0) > 0.005)
                                    {{ number_format($row->total_debit, 2) }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if(($row->total_credit ?? 0) > 0.005)
                                    {{ number_format($row->total_credit, 2) }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if(($row->elimination_debit ?? 0) > 0.005)
                                    {{ number_format($row->elimination_debit, 2) }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if(($row->elimination_credit ?? 0) > 0.005)
                                    {{ number_format($row->elimination_credit, 2) }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end fw-semibold">
                                @if(($row->consolidated_debit ?? 0) > 0.005)
                                    {{ number_format($row->consolidated_debit, 2) }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end fw-semibold">
                                @if(($row->consolidated_credit ?? 0) > 0.005)
                                    {{ number_format($row->consolidated_credit, 2) }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                No data available for the selected period. Run a consolidation first.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                    @if($data->count() > 0)
                    <tfoot class="table-dark fw-bold">
                        <tr>
                            <td colspan="3" class="text-end">Totals</td>
                            <td class="text-end">{{ number_format($totals['total_debit'] ?? 0, 2) }}</td>
                            <td class="text-end">{{ number_format($totals['total_credit'] ?? 0, 2) }}</td>
                            <td class="text-end">{{ number_format($totals['elimination_debit'] ?? 0, 2) }}</td>
                            <td class="text-end">{{ number_format($totals['elimination_credit'] ?? 0, 2) }}</td>
                            <td class="text-end">{{ number_format($totals['consolidated_debit'] ?? 0, 2) }}</td>
                            <td class="text-end">{{ number_format($totals['consolidated_credit'] ?? 0, 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>
    </div>

    {{-- Integrity checks detail section --}}
    @if(isset($checks) && !empty($checks))
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-header bg-light py-2">
            <h6 class="mb-0"><i class="fas fa-shield-halved me-1"></i> Integrity Checks</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Check</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($checks as $key => $value)
                        <tr>
                            <td>{{ ucwords(str_replace('_', ' ', $key)) }}</td>
                            <td class="text-center">
                                @if(is_bool($value))
                                    <span class="badge {{ $value ? 'bg-success' : 'bg-danger' }}">
                                        <i class="fas fa-{{ $value ? 'check' : 'times' }}"></i>
                                        {{ $value ? 'Pass' : 'Fail' }}
                                    </span>
                                @else
                                    <span class="badge {{ abs($value) < 0.01 ? 'bg-success' : 'bg-danger' }}">
                                        <i class="fas fa-{{ abs($value) < 0.01 ? 'check' : 'times' }}"></i>
                                        {{ abs($value) < 0.01 ? 'Pass' : 'Fail' }}
                                    </span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if(is_bool($value))
                                    {{ $value ? 'Yes' : 'No' }}
                                @else
                                    {{ number_format(abs($value), 2) }}
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

    <div class="mt-2 text-muted small">
        {{ $data->count() }} accounts shown &middot; Generated {{ now()->format('d-m-Y H:i') }}
    </div>
</div>

@section('head_meta')
<style>
@media print {
    .card { border: 1px solid #ddd !important; }
    .btn, .form-check, form { display: none !important; }
    .badge { border: 1px solid currentColor !important; }
    .table th, .table td { font-size: 0.75rem !important; padding: 2px 4px !important; }
    .table-active td, .table-light td { background: #f0f0f0 !important; -webkit-print-color-adjust: exact; }
    .table-dark td, .table-dark th { background: #333 !important; color: #fff !important; -webkit-print-color-adjust: exact; }
}
</style>
@endsection
@endsection

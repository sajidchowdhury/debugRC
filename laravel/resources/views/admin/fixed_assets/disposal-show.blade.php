@extends('layouts.admin')

@section('title', $title)

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-hand-holding-usd me-2"></i>Disposal {{ $disposal->disposal_code }}</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.fixed-assets.index') }}">Fixed Assets</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.fixed-assets.disposals') }}">Disposals</a></li>
                    <li class="breadcrumb-item active">{{ $disposal->disposal_code }}</li>
                </ol>
            </nav>
        </div>
        <a href="{{ route('admin.fixed-assets.disposals') }}" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Back to Disposals
        </a>
    </div>

    {{-- Summary Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-muted small">Disposal Proceeds</div>
                    <h5 class="mb-0 text-success">৳ {{ number_format($disposal->disposal_proceeds, 2) }}</h5>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-muted small">Book Value at Disposal</div>
                    <h5 class="mb-0 text-warning">৳ {{ number_format($disposal->book_value_at_disposal, 2) }}</h5>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-muted small">Gain/Loss</div>
                    <h5 class="mb-0">
                        {!! $disposal->getGainLossBadge() !!}
                        @if ($disposal->gain_loss_type !== 'none')
                        <span class="{{ $disposal->isGain() ? 'text-success' : 'text-danger' }}">৳ {{ number_format($disposal->gain_loss_amount, 2) }}</span>
                        @else
                        <span class="text-muted">—</span>
                        @endif
                    </h5>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-muted small">Disposal Type</div>
                    <h5 class="mb-0">{{ $disposal->getDisposalTypeLabel() }}</h5>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            {{-- Disposal Details --}}
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Disposal Details</h6></div>
                <div class="card-body">
                    <table class="table table-borderless table-sm">
                        <tr><td class="text-muted" style="width:200px">Disposal Code</td><td class="fw-semibold">{{ $disposal->disposal_code }}</td></tr>
                        <tr><td class="text-muted">Asset</td><td><a href="{{ route('admin.fixed-assets.show', $disposal->fixedAsset) }}">{{ $disposal->fixedAsset?->asset_code }}</a> — {{ $disposal->fixedAsset?->description }}</td></tr>
                        <tr><td class="text-muted">Disposal Type</td><td>{{ $disposal->getDisposalTypeLabel() }}</td></tr>
                        <tr><td class="text-muted">Disposal Date</td><td>{{ $disposal->disposal_date->format('d M Y') }}</td></tr>
                        <tr><td class="text-muted">Disposal Proceeds</td><td>৳ {{ number_format($disposal->disposal_proceeds, 2) }}</td></tr>
                        <tr><td class="text-muted">Book Value at Disposal</td><td>৳ {{ number_format($disposal->book_value_at_disposal, 2) }}</td></tr>
                        <tr><td class="text-muted">Accumulated Depreciation</td><td>৳ {{ number_format($disposal->accumulated_depreciation_at_disposal, 2) }}</td></tr>
                        <tr><td class="text-muted">Gain/Loss</td>
                            <td>
                                @if ($disposal->gain_loss_type === 'gain')
                                    <span class="text-success fw-semibold">Gain of ৳ {{ number_format($disposal->gain_loss_amount, 2) }}</span>
                                @elseif ($disposal->gain_loss_type === 'loss')
                                    <span class="text-danger fw-semibold">Loss of ৳ {{ number_format($disposal->gain_loss_amount, 2) }}</span>
                                @else
                                    <span class="text-muted">Break Even</span>
                                @endif
                            </td>
                        </tr>
                        <tr><td class="text-muted">Proceeds Account</td><td>{{ $disposal->proceedsLedger?->ledger_code ?? 'N/A' }} — {{ $disposal->proceedsLedger?->ledger_name ?? 'N/A' }}</td></tr>
                        <tr><td class="text-muted">Gain/Loss Account</td><td>{{ $disposal->gainLossLedger?->ledger_code ?? 'N/A' }} — {{ $disposal->gainLossLedger?->ledger_name ?? 'N/A' }}</td></tr>
                        @if ($disposal->journalEntry)
                        <tr><td class="text-muted">Journal Entry</td><td>{{ $disposal->journalEntry->entry_no }}</td></tr>
                        @endif
                        @if ($disposal->reason)
                        <tr><td class="text-muted">Reason</td><td>{{ $disposal->reason }}</td></tr>
                        @endif
                        @if ($disposal->notes)
                        <tr><td class="text-muted">Notes</td><td>{{ $disposal->notes }}</td></tr>
                        @endif
                        <tr><td class="text-muted">Created By</td><td>{{ $disposal->creator?->name ?? 'N/A' }}</td></tr>
                        <tr><td class="text-muted">Created At</td><td>{{ $disposal->created_at->format('d M Y H:i') }}</td></tr>
                    </table>
                </div>
            </div>

            {{-- Journal Entry Details --}}
            @if ($disposal->journalEntry)
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-book me-2"></i>Journal Entry Details</h6></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Account</th>
                                    <th class="text-end">Debit</th>
                                    <th class="text-end">Credit</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $je = $disposal->journalEntry;
                                    $lines = \App\Models\Accounting\JournalLine::where('journal_entry_id', $je->id)
                                        ->with('ledger')->get();
                                @endphp
                                @foreach ($lines as $line)
                                <tr>
                                    <td>{{ $line->ledger?->ledger_code }} — {{ $line->ledger?->ledger_name }}</td>
                                    <td class="text-end">{{ $line->debit > 0 ? '৳ ' . number_format($line->debit, 2) : '' }}</td>
                                    <td class="text-end">{{ $line->credit > 0 ? '৳ ' . number_format($line->credit, 2) : '' }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th>Total</th>
                                    <th class="text-end">৳ {{ number_format($lines->sum('debit'), 2) }}</th>
                                    <th class="text-end">৳ {{ number_format($lines->sum('credit'), 2) }}</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection

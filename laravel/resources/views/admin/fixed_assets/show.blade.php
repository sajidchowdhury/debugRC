@extends('layouts.admin')

@section('title', $title)

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-building me-2"></i>{{ $asset->asset_code }}</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.fixed-assets.index') }}">Fixed Assets</a></li>
                    <li class="breadcrumb-item active">{{ $asset->asset_code }}</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2">
            @if (!$asset->isDisposed())
            <a href="{{ route('admin.fixed-assets.edit', $asset) }}" class="btn btn-outline-secondary">
                <i class="fas fa-pen me-1"></i> Edit
            </a>
            @endif
            @if ($asset->canBeDisposed())
            <a href="{{ route('admin.fixed-assets.dispose-form', $asset) }}" class="btn btn-outline-danger">
                <i class="fas fa-hand-holding-usd me-1"></i> Dispose
            </a>
            @endif
            <a href="{{ route('admin.fixed-assets.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    {{-- Summary Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-muted small">Acquisition Cost</div>
                    <h5 class="mb-0 text-primary">৳ {{ number_format($asset->acquisition_cost, 2) }}</h5>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-muted small">Accumulated Depreciation</div>
                    <h5 class="mb-0 text-warning">৳ {{ number_format($asset->accumulated_depreciation, 2) }}</h5>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-muted small">Net Book Value</div>
                    <h5 class="mb-0 text-success">৳ {{ number_format($asset->net_book_value, 2) }}</h5>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="text-muted small">Status</div>
                    <h5 class="mb-0">{!! $asset->getStatusBadge() !!}</h5>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        {{-- Asset Details --}}
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Asset Details</h6>
                </div>
                <div class="card-body">
                    <table class="table table-borderless table-sm">
                        <tr><td class="text-muted" style="width:200px">Asset Code</td><td class="fw-semibold">{{ $asset->asset_code }}</td></tr>
                        <tr><td class="text-muted">Description</td><td>{{ $asset->description }}</td></tr>
                        <tr><td class="text-muted">Category</td><td><span class="badge bg-light text-dark">{{ $asset->getCategoryLabel() }}</span></td></tr>
                        <tr><td class="text-muted">Acquisition Date</td><td>{{ $asset->acquisition_date->format('d M Y') }}</td></tr>
                        <tr><td class="text-muted">Acquisition Cost</td><td>৳ {{ number_format($asset->acquisition_cost, 2) }}</td></tr>
                        <tr><td class="text-muted">Salvage Value</td><td>৳ {{ number_format($asset->salvage_value, 2) }}</td></tr>
                        <tr><td class="text-muted">Depreciation Method</td><td>{{ $asset->getMethodLabel() }}</td></tr>
                        <tr><td class="text-muted">Useful Life</td><td>{{ $asset->useful_life_months }} months ({{ $asset->getUsefulLifeYears() }} years)</td></tr>
                        @if ($asset->depreciation_method === 'declining_balance')
                        <tr><td class="text-muted">Declining Balance Rate</td><td>{{ $asset->declining_balance_rate }}% p.a.</td></tr>
                        @endif
                        @if ($asset->depreciation_method === 'units_of_production')
                        <tr><td class="text-muted">Total Estimated Units</td><td>{{ number_format($asset->total_estimated_units) }}</td></tr>
                        <tr><td class="text-muted">Units Produced to Date</td><td>{{ number_format($asset->units_produced_to_date) }}</td></tr>
                        @endif
                        <tr><td class="text-muted">Depreciation %</td><td>{{ $asset->getDepreciationPercentage() }}%</td></tr>
                        <tr><td class="text-muted">Last Depreciation</td><td>{{ $asset->last_depreciation_date ? $asset->last_depreciation_date->format('d M Y') : 'N/A' }}</td></tr>
                        <tr><td class="text-muted">Serial Number</td><td>{{ $asset->serial_number ?? 'N/A' }}</td></tr>
                        <tr><td class="text-muted">Location</td><td>{{ $asset->location ?? 'N/A' }}</td></tr>
                        <tr><td class="text-muted">Warranty Expiry</td><td>{{ $asset->warranty_expiry ?? 'N/A' }}</td></tr>
                        <tr><td class="text-muted">Branch</td><td>{{ $asset->branch?->branch_name ?? 'N/A' }}</td></tr>
                        @if ($asset->notes)
                        <tr><td class="text-muted">Notes</td><td>{{ $asset->notes }}</td></tr>
                        @endif
                    </table>
                </div>
            </div>

            {{-- Ledger Mapping --}}
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-book me-2"></i>Ledger Mapping</h6></div>
                <div class="card-body">
                    <table class="table table-borderless table-sm">
                        <tr><td class="text-muted" style="width:200px">Asset Account</td><td>{{ $asset->assetLedger?->ledger_code }} — {{ $asset->assetLedger?->ledger_name }}</td></tr>
                        <tr><td class="text-muted">Accum. Depreciation</td><td>{{ $asset->depLedger?->ledger_code }} — {{ $asset->depLedger?->ledger_name }}</td></tr>
                        <tr><td class="text-muted">Depreciation Expense</td><td>{{ $asset->depExpenseLedger?->ledger_code ?? 'Auto' }} — {{ $asset->depExpenseLedger?->ledger_name ?? 'Resolved from nature' }}</td></tr>
                    </table>
                </div>
            </div>

            {{-- Depreciation Schedule --}}
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>Depreciation History</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Period</th>
                                    <th>Method</th>
                                    <th class="text-end">Opening BV</th>
                                    <th class="text-end">Depreciation</th>
                                    <th class="text-end">Closing BV</th>
                                    <th>Status</th>
                                    <th>JE</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($asset->depreciationSchedules as $schedule)
                                <tr>
                                    <td>{{ $schedule->period_from->format('M Y') }}</td>
                                    <td><span class="badge bg-light text-dark">{{ ucfirst(str_replace('_', ' ', $schedule->depreciation_method)) }}</span></td>
                                    <td class="text-end">৳ {{ number_format($schedule->opening_book_value, 2) }}</td>
                                    <td class="text-end fw-semibold">৳ {{ number_format($schedule->depreciation_amount, 2) }}</td>
                                    <td class="text-end">৳ {{ number_format($schedule->closing_book_value, 2) }}</td>
                                    <td>
                                        @if ($schedule->isPosted())
                                        <span class="badge bg-success">Posted</span>
                                        @elseif ($schedule->isPending())
                                        <span class="badge bg-warning text-dark">Pending</span>
                                        @else
                                        <span class="badge bg-danger">Reversed</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($schedule->journal_entry_id)
                                        <span class="small text-muted">{{ \App\Models\Accounting\JournalEntry::find($schedule->journal_entry_id)?->entry_no }}</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if ($schedule->isPending())
                                        <form method="POST" action="{{ route('admin.fixed-assets.post-single-depreciation', $schedule) }}" class="d-inline">
                                            @csrf @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Post" onclick="return confirm('Post this depreciation entry?')">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </form>
                                        @elseif ($schedule->isPosted())
                                        <button type="button" class="btn btn-sm btn-outline-danger" title="Reverse" data-bs-toggle="modal" data-bs-target="#reverseModal{{ $schedule->id }}">
                                            <i class="fas fa-undo"></i>
                                        </button>
                                        @endif
                                    </td>
                                </tr>

                                {{-- Reverse Modal --}}
                                @if ($schedule->isPosted())
                                <div class="modal fade" id="reverseModal{{ $schedule->id }}" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST" action="{{ route('admin.fixed-assets.reverse-depreciation', $schedule) }}">
                                                @csrf @method('PATCH')
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Reverse Depreciation</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <p>Reverse depreciation of ৳ {{ number_format($schedule->depreciation_amount, 2) }} for {{ $schedule->period_from->format('M Y') }}?</p>
                                                    <label class="form-label">Reason <span class="text-danger">*</span></label>
                                                    <input type="text" name="reason" class="form-control" required maxlength="500">
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                    <button type="submit" class="btn btn-danger">Reverse</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                @endif
                                @empty
                                <tr><td colspan="8" class="text-center text-muted py-3">No depreciation entries yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Projected Depreciation --}}
            @if (!empty($projectedDepreciation))
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-forward me-2"></i>Projected Depreciation (Next 12 Months)</h6></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Period</th>
                                    <th class="text-end">Opening BV</th>
                                    <th class="text-end">Depreciation</th>
                                    <th class="text-end">Closing BV</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($projectedDepreciation as $proj)
                                <tr>
                                    <td>{{ \Carbon\Carbon::parse($proj['period_from'])->format('M Y') }}</td>
                                    <td class="text-end">৳ {{ number_format($proj['opening_book_value'], 2) }}</td>
                                    <td class="text-end">৳ {{ number_format($proj['depreciation_amount'], 2) }}</td>
                                    <td class="text-end">৳ {{ number_format($proj['closing_book_value'], 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif

            {{-- Disposals --}}
            @if ($asset->disposals->count() > 0)
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-hand-holding-usd me-2"></i>Disposal Records</h6></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Code</th>
                                    <th>Type</th>
                                    <th>Date</th>
                                    <th class="text-end">Proceeds</th>
                                    <th class="text-end">Book Value</th>
                                    <th>Gain/Loss</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($asset->disposals as $disposal)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.fixed-assets.show-disposal', $disposal) }}">{{ $disposal->disposal_code }}</a>
                                    </td>
                                    <td>{{ $disposal->getDisposalTypeLabel() }}</td>
                                    <td>{{ $disposal->disposal_date->format('d M Y') }}</td>
                                    <td class="text-end">৳ {{ number_format($disposal->disposal_proceeds, 2) }}</td>
                                    <td class="text-end">৳ {{ number_format($disposal->book_value_at_disposal, 2) }}</td>
                                    <td>{!! $disposal->getGainLossBadge() !!} ৳ {{ number_format($disposal->gain_loss_amount, 2) }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>
@endsection

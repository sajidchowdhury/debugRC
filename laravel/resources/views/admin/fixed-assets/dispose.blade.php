@extends('layouts.admin')

@section('title', $title)

@section('content')
<div class="container-fluid py-4">
    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-hand-holding-usd me-2"></i>Dispose Asset {{ $asset->asset_code }}</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.fixed-assets.index') }}">Fixed Assets</a></li>
                    <li class="breadcrumb-item"><a href="{{ route('admin.fixed-assets.show', $asset) }}">{{ $asset->asset_code }}</a></li>
                    <li class="breadcrumb-item active">Dispose</li>
                </ol>
            </nav>
        </div>
        <a href="{{ route('admin.fixed-assets.show', $asset) }}" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i> Cancel
        </a>
    </div>

    {{-- Asset Summary --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <div class="text-muted small">Asset</div>
                    <div class="fw-semibold">{{ $asset->asset_code }} — {{ $asset->description }}</div>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Acquisition Cost</div>
                    <div class="fw-semibold">৳ {{ number_format($asset->acquisition_cost, 2) }}</div>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Accumulated Depreciation</div>
                    <div class="fw-semibold text-warning">৳ {{ number_format($asset->accumulated_depreciation, 2) }}</div>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Net Book Value</div>
                    <div class="fw-semibold text-success">৳ {{ number_format($asset->net_book_value, 2) }}</div>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Salvage Value</div>
                    <div class="fw-semibold">৳ {{ number_format($asset->salvage_value, 2) }}</div>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.fixed-assets.store-disposal') }}">
        @csrf
        <input type="hidden" name="fixed_asset_id" value="{{ $asset->id }}">

        <div class="row">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-hand-holding-usd me-2"></i>Disposal Details</h6></div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Disposal Type <span class="text-danger">*</span></label>
                                <select name="disposal_type" class="form-select" id="disposalType" required>
                                    @foreach (\App\Models\AssetDisposal::disposalTypeOptions() as $key => $label)
                                    <option value="{{ $key }}" {{ old('disposal_type') === $key ? 'selected' : '' }}>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Disposal Date <span class="text-danger">*</span></label>
                                <input type="date" name="disposal_date" class="form-control" value="{{ old('disposal_date', now()->format('Y-m-d')) }}" required>
                            </div>
                            <div class="col-md-4" id="proceedsGroup">
                                <label class="form-label">Disposal Proceeds</label>
                                <input type="number" name="disposal_proceeds" class="form-control" value="{{ old('disposal_proceeds', 0) }}" step="0.01" min="0">
                            </div>
                            <div class="col-md-6" id="proceedsLedgerGroup">
                                <label class="form-label">Proceeds Account (Cash/Bank)</label>
                                <select name="proceeds_ledger_id" class="form-select">
                                    <option value="">Auto-resolve (Cash/Bank)</option>
                                    @foreach ($cashBankLedgers as $ledger)
                                    <option value="{{ $ledger->id }}" {{ old('proceeds_ledger_id') == $ledger->id ? 'selected' : '' }}>
                                        {{ $ledger->ledger_code }} — {{ $ledger->ledger_name }}
                                    </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Gain/Loss Account</label>
                                <select name="gain_loss_ledger_id" class="form-select">
                                    <option value="">Auto-resolve</option>
                                    <optgroup label="Income (Gain)">
                                        @foreach ($incomeLedgers as $ledger)
                                        <option value="{{ $ledger->id }}" {{ old('gain_loss_ledger_id') == $ledger->id ? 'selected' : '' }}>
                                            {{ $ledger->ledger_code }} — {{ $ledger->ledger_name }}
                                        </option>
                                        @endforeach
                                    </optgroup>
                                    <optgroup label="Expense (Loss)">
                                        @foreach ($expenseLedgers as $ledger)
                                        <option value="{{ $ledger->id }}" {{ old('gain_loss_ledger_id') == $ledger->id ? 'selected' : '' }}>
                                            {{ $ledger->ledger_code }} — {{ $ledger->ledger_name }}
                                        </option>
                                        @endforeach
                                    </optgroup>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Reason</label>
                                <input type="text" name="reason" class="form-control" value="{{ old('reason') }}" maxlength="500" placeholder="Reason for disposal...">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="2">{{ old('notes') }}</textarea>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Preview: Estimated Gain/Loss --}}
                <div class="card border-0 shadow-sm mb-4 border-start border-4 border-info">
                    <div class="card-body">
                        <h6 class="mb-2"><i class="fas fa-calculator me-2"></i>Estimated Gain/Loss</h6>
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div class="text-muted small">Book Value at Disposal</div>
                                <div class="fw-semibold">৳ {{ number_format($asset->net_book_value, 2) }}</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">Estimated Proceeds</div>
                                <div class="fw-semibold" id="estProceeds">৳ 0.00</div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-muted small">Estimated Gain/(Loss)</div>
                                <div class="fw-semibold" id="estGainLoss">৳ ({{ number_format($asset->net_book_value, 2) }})</div>
                            </div>
                        </div>
                        <div class="small text-muted mt-2">
                            <i class="fas fa-info-circle me-1"></i>
                            Final calculation will be based on the actual proceeds and accumulated depreciation at the disposal date.
                        </div>
                    </div>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to dispose of this asset? This action will post journal entries and cannot be easily undone.')">
                        <i class="fas fa-hand-holding-usd me-1"></i> Dispose Asset
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
const disposalType = document.getElementById('disposalType');
const proceedsGroup = document.getElementById('proceedsGroup');
const proceedsLedgerGroup = document.getElementById('proceedsLedgerGroup');
const proceedsInput = document.querySelector('input[name="disposal_proceeds"]');
const estProceedsEl = document.getElementById('estProceeds');
const estGainLossEl = document.getElementById('estGainLoss');
const bookValue = {{ $asset->net_book_value }};

function updateDisposalUI() {
    const type = disposalType.value;
    if (type === 'write_off' || type === 'donation') {
        proceedsGroup.style.display = 'none';
        proceedsLedgerGroup.style.display = 'none';
        proceedsInput.value = 0;
    } else {
        proceedsGroup.style.display = '';
        proceedsLedgerGroup.style.display = '';
    }
    updateEstimate();
}

function updateEstimate() {
    const proceeds = parseFloat(proceedsInput.value) || 0;
    const gainLoss = proceeds - bookValue;
    estProceedsEl.textContent = '৳ ' + proceeds.toFixed(2);
    if (gainLoss >= 0) {
        estGainLossEl.textContent = '৳ ' + gainLoss.toFixed(2);
        estGainLossEl.className = 'fw-semibold text-success';
    } else {
        estGainLossEl.textContent = '৳ (' + Math.abs(gainLoss).toFixed(2) + ')';
        estGainLossEl.className = 'fw-semibold text-danger';
    }
}

disposalType.addEventListener('change', updateDisposalUI);
proceedsInput.addEventListener('input', updateEstimate);
updateDisposalUI();
</script>
@endpush
@endsection

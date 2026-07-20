@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0f766e,#14b8a6);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-pen-to-square me-2"></i>Edit bank account</h1>
            <p class="mb-0 small opacity-75">
                <strong>{{ $item->bank_name }}</strong> · {{ $item->account_number ?: 'No account #' }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.banks.show', $item) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-circle-info me-1"></i> View
            </a>
            <a href="{{ route('admin.banks.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </header>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-building-columns me-1 text-success"></i> Account details</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.banks.update', $item) }}">
                        @csrf
                        @method('PUT')
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="bank_name">Bank name <span class="text-danger">*</span></label>
                                <input type="text" id="bank_name" name="bank_name" class="form-control @error('bank_name') is-invalid @enderror"
                                       required value="{{ old('bank_name', $item->bank_name) }}">
                                @error('bank_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="account_number">Account number</label>
                                <input type="text" id="account_number" name="account_number" class="form-control @error('account_number') is-invalid @enderror"
                                       value="{{ old('account_number', $item->account_number) }}">
                                @error('account_number') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="account_holder">Account holder</label>
                                <input type="text" id="account_holder" name="account_holder" class="form-control @error('account_holder') is-invalid @enderror"
                                       value="{{ old('account_holder', $item->account_holder) }}">
                                @error('account_holder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="branch_name">Branch name</label>
                                <input type="text" id="branch_name" name="branch_name" class="form-control @error('branch_name') is-invalid @enderror"
                                       value="{{ old('branch_name', $item->branch_name) }}">
                                @error('branch_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="balance">Balance (Tk)</label>
                                <input type="number" step="0.01" id="balance" name="balance" class="form-control @error('balance') is-invalid @enderror"
                                       value="{{ old('balance', $item->balance) }}">
                                @error('balance') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text small">Balance updates as you post payments — direct edits are unusual.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="ledger_id">Linked GL ledger</label>
                                @php
                                    $currentLedgerId = old('ledger_id', $item->ledger_id ?? optional($item->ledgerMapping)->ledger_id);
                                @endphp
                                <select id="ledger_id" name="ledger_id" class="form-select select2 @error('ledger_id') is-invalid @enderror">
                                    <option value="">— Default bank control —</option>
                                    @foreach ($ledgers as $ledger)
                                        <option value="{{ $ledger->id }}"
                                            {{ (int) $currentLedgerId === (int) $ledger->id ? 'selected' : '' }}>
                                            {{ $ledger->ledger_code }} — {{ $ledger->ledger_name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('ledger_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="hidden" name="is_active" value="0">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                                           {{ old('is_active', $item->is_active ? 1 : 0) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_active">Active</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-save me-1"></i> Save changes
                            </button>
                            <a href="{{ route('admin.banks.show', $item) }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h3 class="h6"><i class="fas fa-wallet me-1 text-warning"></i> Snapshot</h3>
                    <dl class="row small mb-0">
                        <dt class="col-5 text-muted">Status</dt>
                        <dd class="col-7">
                            @if ($item->is_active)
                                <span class="badge bg-success-subtle text-success">Active</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            @endif
                        </dd>
                        <dt class="col-5 text-muted">Balance</dt>
                        <dd class="col-7">Tk {{ number_format((float) $item->balance, 2) }}</dd>
                        @if ($item->ledger)
                            <dt class="col-5 text-muted">GL ledger</dt>
                            <dd class="col-7">
                                <a href="{{ route('admin.ledgers.show', $item->ledger) }}" class="text-decoration-none">
                                    {{ $item->ledger->ledger_code }}
                                </a>
                            </dd>
                        @endif
                        <dt class="col-5 text-muted">Created</dt>
                        <dd class="col-7">{{ optional($item->created_at)->format('Y-m-d') }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function() {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });
});
</script>
@endpush
@endsection

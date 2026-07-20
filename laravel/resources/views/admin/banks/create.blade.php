@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#0f766e,#14b8a6);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-plus me-2"></i>New bank account</h1>
            <p class="mb-0 small opacity-75">Add a cash book account for customer payments, transfers, and other income/expense.</p>
        </div>
        <div>
            <a href="{{ route('admin.banks.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
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
                    <form method="POST" action="{{ route('admin.banks.store') }}">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="bank_name">Bank name <span class="text-danger">*</span></label>
                                <input type="text" id="bank_name" name="bank_name" class="form-control @error('bank_name') is-invalid @enderror"
                                       required placeholder="e.g. Dutch-Bangla Bank"
                                       value="{{ old('bank_name') }}">
                                @error('bank_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="account_number">Account number</label>
                                <input type="text" id="account_number" name="account_number" class="form-control @error('account_number') is-invalid @enderror"
                                       value="{{ old('account_number') }}">
                                @error('account_number') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="account_holder">Account holder</label>
                                <input type="text" id="account_holder" name="account_holder" class="form-control @error('account_holder') is-invalid @enderror"
                                       value="{{ old('account_holder') }}">
                                @error('account_holder') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="branch_name">Branch name</label>
                                <input type="text" id="branch_name" name="branch_name" class="form-control @error('branch_name') is-invalid @enderror"
                                       placeholder="e.g. Gulshan"
                                       value="{{ old('branch_name') }}">
                                @error('branch_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="balance">Opening balance (Tk)</label>
                                <input type="number" step="0.01" id="balance" name="balance" class="form-control @error('balance') is-invalid @enderror"
                                       value="{{ old('balance', '0') }}">
                                @error('balance') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text small">Balance updates as you post payments — leave 0 for new accounts.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="ledger_id">Linked GL ledger</label>
                                <select id="ledger_id" name="ledger_id" class="form-select select2 @error('ledger_id') is-invalid @enderror">
                                    <option value="">— Default bank control —</option>
                                    @foreach ($ledgers as $ledger)
                                        <option value="{{ $ledger->id }}"
                                            {{ (int) old('ledger_id') === (int) $ledger->id ? 'selected' : '' }}>
                                            {{ $ledger->ledger_code }} — {{ $ledger->ledger_name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('ledger_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text small">Only GL ledgers of nature <code>cash_bank</code> are listed.</div>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input type="hidden" name="is_active" value="0">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" {{ old('is_active', 1) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_active">Active</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-check me-1"></i> Create account
                            </button>
                            <a href="{{ route('admin.banks.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h3 class="h6"><i class="fas fa-lightbulb me-1 text-warning"></i> Tips</h3>
                    <ul class="small text-muted mb-0 ps-3">
                        <li>New accounts are <strong>active</strong> by default.</li>
                        <li>Balance is updated by customer payments, money transfers, and other income/expense — not edited here.</li>
                        <li>Assigning a GL ledger now keeps the cash book reconciled to the right control account.</li>
                    </ul>
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

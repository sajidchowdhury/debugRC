@extends('layouts.admin')
@section('title', $title)

@section('content')
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0"><i class="fas fa-plus-circle me-2"></i> New Bank Reconciliation</h4>
        <a href="{{ route('admin.bank-reconciliation.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
    </div>

    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-light py-2">
                    <h6 class="mb-0"><i class="fas fa-info-circle me-1"></i> Select Bank Account & Period</h6>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.bank-reconciliation.store') }}">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Bank Account <span class="text-danger">*</span></label>
                                <select name="bank_id" class="form-select" required>
                                    <option value="">— Select Bank —</option>
                                    @foreach($banks as $bank)
                                        <option value="{{ $bank->id }}" {{ old('bank_id') == $bank->id ? 'selected' : '' }}>
                                            {{ $bank->bank_name }} ({{ $bank->account_number }})
                                            @if($bank->ledgerMapping?->ledger)
                                                — GL: {{ $bank->ledgerMapping->ledger->ledger_code }}
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                                @error('bank_id') <small class="text-danger">{{ $message }}</small> @enderror
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Period From <span class="text-danger">*</span></label>
                                <input type="date" name="period_from" class="form-control" value="{{ old('period_from', now()->startOfMonth()->format('Y-m-d')) }}" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Period To <span class="text-danger">*</span></label>
                                <input type="date" name="period_to" class="form-control" value="{{ old('period_to', now()->format('Y-m-d')) }}" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Statement Opening Balance</label>
                                <input type="number" name="statement_opening_balance" class="form-control" step="0.01" value="{{ old('statement_opening_balance', '0.00') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Statement Closing Balance</label>
                                <input type="number" name="statement_closing_balance" class="form-control" step="0.01" value="{{ old('statement_closing_balance', '0.00') }}">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="2" maxlength="500">{{ old('notes') }}</textarea>
                            </div>
                            <div class="col-12 text-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-check me-1"></i> Create Reconciliation
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-plus me-2"></i>New ledger account</h1>
            <p class="mb-0 small opacity-75">Add a GL head for reports and automated journal posting.</p>
        </div>
        <div>
            <a href="{{ route('admin.ledgers.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Chart of accounts
            </a>
        </div>
    </header>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white">
                    <h2 class="h6 mb-0"><i class="fas fa-book me-1 text-primary"></i> Account details</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.ledgers.store') }}">
                        @csrf
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="ledger_code">Ledger code <span class="text-danger">*</span></label>
                                <input type="text" id="ledger_code" name="ledger_code" class="form-control @error('ledger_code') is-invalid @enderror"
                                       required placeholder="e.g. 1010" value="{{ old('ledger_code') }}">
                                @error('ledger_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="ledger_name">Ledger name <span class="text-danger">*</span></label>
                                <input type="text" id="ledger_name" name="ledger_name" class="form-control @error('ledger_name') is-invalid @enderror"
                                       required placeholder="e.g. Cash in Bank — DBBL"
                                       value="{{ old('ledger_name') }}">
                                @error('ledger_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="parent_id">Parent account</label>
                                <select id="parent_id" name="parent_id" class="form-select select2 @error('parent_id') is-invalid @enderror">
                                    <option value="">— Top-level —</option>
                                    @foreach ($parents as $parent)
                                        <option value="{{ $parent->id }}"
                                            {{ (int) old('parent_id') === (int) $parent->id ? 'selected' : '' }}>
                                            {{ $parent->ledger_code }} — {{ $parent->ledger_name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('parent_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text small">Only active top-level ledgers are listed.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="account_type">Account type <span class="text-danger">*</span></label>
                                <select id="account_type" name="account_type" class="form-select @error('account_type') is-invalid @enderror" required>
                                    <option value="">Select type</option>
                                    @foreach ($accountTypes as $type)
                                        <option value="{{ $type }}" {{ old('account_type') === $type ? 'selected' : '' }}>{{ $type }}</option>
                                    @endforeach
                                </select>
                                @error('account_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="ledger_nature">Ledger nature</label>
                                <select id="ledger_nature" name="ledger_nature" class="form-select select2 @error('ledger_nature') is-invalid @enderror">
                                    <option value="">— None —</option>
                                    @foreach ($natures as $nature)
                                        <option value="{{ $nature }}" {{ old('ledger_nature') === $nature ? 'selected' : '' }}>
                                            {{ str_replace('_', ' ', $nature) }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('ledger_nature') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text small">
                                    Critical natures (cash_bank, ar, ap, inventory, sales, cogs, retained_earnings) must resolve to exactly one active ledger.
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="opening_balance">Opening balance (Tk)</label>
                                <input type="number" step="0.01" id="opening_balance" name="opening_balance"
                                       class="form-control @error('opening_balance') is-invalid @enderror"
                                       value="{{ old('opening_balance', '0') }}">
                                @error('opening_balance') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="sort_order">Sort order</label>
                                <input type="number" id="sort_order" name="sort_order"
                                       class="form-control @error('sort_order') is-invalid @enderror"
                                       value="{{ old('sort_order', '0') }}">
                                @error('sort_order') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="control_account_type">Control account type</label>
                                <input type="text" id="control_account_type" name="control_account_type"
                                       class="form-control @error('control_account_type') is-invalid @enderror"
                                       placeholder="e.g. ar, ap, inventory"
                                       value="{{ old('control_account_type') }}">
                                @error('control_account_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input type="hidden" name="is_control_account" value="0">
                                    <input class="form-check-input" type="checkbox" id="is_control_account" name="is_control_account" value="1"
                                           {{ old('is_control_account') ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_control_account">Control account (group sub-ledgers)</label>
                                </div>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input type="hidden" name="is_active" value="0">
                                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                                           {{ old('is_active', 1) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_active">Active</label>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-check me-1"></i> Create ledger
                            </button>
                            <a href="{{ route('admin.ledgers.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <h3 class="h6"><i class="fas fa-lightbulb me-1 text-warning"></i> Quick reference</h3>
                    <ul class="small text-muted mb-0 ps-3">
                        <li><strong>Asset</strong> — what you own (cash, AR, inventory).</li>
                        <li><strong>Liability</strong> — what you owe (AP, loans).</li>
                        <li><strong>Equity</strong> — owner capital + retained earnings.</li>
                        <li><strong>Income</strong> — revenue / sales.</li>
                        <li><strong>Expense</strong> — COGS + operating expenses.</li>
                    </ul>
                </div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h3 class="h6"><i class="fas fa-shield-halved me-1 text-success"></i> Control accounts</h3>
                    <p class="small text-muted mb-0">
                        Mark a ledger as a control account if it groups sub-ledgers (e.g. customers under AR, suppliers under AP).
                        Set the matching <code>control_account_type</code> so postings resolve correctly.
                    </p>
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

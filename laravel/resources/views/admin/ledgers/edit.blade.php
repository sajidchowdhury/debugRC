@extends('layouts.admin')

@section('content')
<div class="container-fluid py-2">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#4f46e5,#7c3aed);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-pen-to-square me-2"></i>Edit ledger</h1>
            <p class="mb-0 small opacity-75">
                <strong>{{ $item->ledger_name }}</strong> · {{ $item->ledger_code }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.ledgers.show', $item) }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-book-open me-1"></i> View
            </a>
            <a href="{{ route('admin.ledgers.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back
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
                    <form method="POST" action="{{ route('admin.ledgers.update', $item) }}">
                        @csrf
                        @method('PUT')
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="ledger_code">Ledger code <span class="text-danger">*</span></label>
                                <input type="text" id="ledger_code" name="ledger_code" class="form-control @error('ledger_code') is-invalid @enderror"
                                       required value="{{ old('ledger_code', $item->ledger_code) }}">
                                @error('ledger_code') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" for="ledger_name">Ledger name <span class="text-danger">*</span></label>
                                <input type="text" id="ledger_name" name="ledger_name" class="form-control @error('ledger_name') is-invalid @enderror"
                                       required value="{{ old('ledger_name', $item->ledger_name) }}">
                                @error('ledger_name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="parent_id">Parent account</label>
                                <select id="parent_id" name="parent_id" class="form-select select2 @error('parent_id') is-invalid @enderror">
                                    <option value="">— Top-level —</option>
                                    @foreach ($parents as $parent)
                                        @if ((int) $parent->id !== (int) $item->id)
                                            <option value="{{ $parent->id }}"
                                                {{ (int) old('parent_id', $item->parent_id) === (int) $parent->id ? 'selected' : '' }}>
                                                {{ $parent->ledger_code }} — {{ $parent->ledger_name }}
                                            </option>
                                        @endif
                                    @endforeach
                                </select>
                                @error('parent_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="account_type">Account type <span class="text-danger">*</span></label>
                                <select id="account_type" name="account_type" class="form-select @error('account_type') is-invalid @enderror" required>
                                    <option value="">Select type</option>
                                    @foreach ($accountTypes as $type)
                                        <option value="{{ $type }}" {{ old('account_type', $item->account_type) === $type ? 'selected' : '' }}>{{ $type }}</option>
                                    @endforeach
                                </select>
                                @error('account_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="ledger_nature">Ledger nature</label>
                                <select id="ledger_nature" name="ledger_nature" class="form-select select2 @error('ledger_nature') is-invalid @enderror">
                                    <option value="">— None —</option>
                                    @foreach ($natures as $nature)
                                        <option value="{{ $nature }}" {{ old('ledger_nature', $item->ledger_nature) === $nature ? 'selected' : '' }}>
                                            {{ str_replace('_', ' ', $nature) }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('ledger_nature') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="opening_balance">Opening balance (Tk)</label>
                                <input type="number" step="0.01" id="opening_balance" name="opening_balance"
                                       class="form-control @error('opening_balance') is-invalid @enderror"
                                       value="{{ old('opening_balance', $item->opening_balance) }}">
                                @error('opening_balance') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="sort_order">Sort order</label>
                                <input type="number" id="sort_order" name="sort_order"
                                       class="form-control @error('sort_order') is-invalid @enderror"
                                       value="{{ old('sort_order', $item->sort_order) }}">
                                @error('sort_order') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="control_account_type">Control account type</label>
                                <input type="text" id="control_account_type" name="control_account_type"
                                       class="form-control @error('control_account_type') is-invalid @enderror"
                                       value="{{ old('control_account_type', $item->control_account_type) }}">
                                @error('control_account_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input type="hidden" name="is_control_account" value="0">
                                    <input class="form-check-input" type="checkbox" id="is_control_account" name="is_control_account" value="1"
                                           {{ old('is_control_account', $item->is_control_account ? 1 : 0) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_control_account">Control account</label>
                                </div>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
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
                            <a href="{{ route('admin.ledgers.show', $item) }}" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h3 class="h6"><i class="fas fa-circle-info me-1 text-primary"></i> Snapshot</h3>
                    <dl class="row small mb-0">
                        <dt class="col-5 text-muted">Code</dt>
                        <dd class="col-7">{{ $item->ledger_code }}</dd>
                        <dt class="col-5 text-muted">Type</dt>
                        <dd class="col-7">{{ $item->account_type }}</dd>
                        <dt class="col-5 text-muted">Nature</dt>
                        <dd class="col-7">{{ $item->ledger_nature ? str_replace('_', ' ', $item->ledger_nature) : '—' }}</dd>
                        <dt class="col-5 text-muted">Parent</dt>
                        <dd class="col-7">{{ $item->parent ? $item->parent->ledger_name : 'Top level' }}</dd>
                        <dt class="col-5 text-muted">Opening</dt>
                        <dd class="col-7">Tk {{ number_format((float) $item->opening_balance, 2) }}</dd>
                        <dt class="col-5 text-muted">Status</dt>
                        <dd class="col-7">
                            @if ($item->is_active)
                                <span class="badge bg-success-subtle text-success">Active</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                            @endif
                        </dd>
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

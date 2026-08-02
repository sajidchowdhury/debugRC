@extends('layouts.admin')

@section('title', 'Elimination Rules — Remote Center ERP')

@section('content')
<div class="container-fluid py-4">
    {{-- Breadcrumb --}}
    <div class="row mb-3">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="{{ route('admin.consolidation.index') }}">Consolidation</a></li>
                    <li class="breadcrumb-item active">Elimination Rules</li>
                </ol>
            </nav>
            <div class="d-flex justify-content-between align-items-center">
                <h4 class="mb-0"><i class="fas fa-cogs me-2"></i>Elimination Rules</h4>
                <button class="btn btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#createRuleForm" aria-expanded="false" aria-controls="createRuleForm">
                    <i class="fas fa-plus me-1"></i> Create New Rule
                </button>
            </div>
        </div>
    </div>

    {{-- Create New Rule Form (collapsible) --}}
    <div class="collapse mb-4" id="createRuleForm">
        <div class="card border-primary">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-plus-circle me-1"></i> New Elimination Rule</h5>
            </div>
            <form method="POST" action="{{ route('admin.consolidation.rules.store') }}">
                @csrf
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label for="rule_code" class="form-label">Rule Code <span class="text-danger">*</span></label>
                            <input type="text" name="rule_code" id="rule_code" class="form-control"
                                   value="{{ old('rule_code') }}" placeholder="e.g. ELIM-IC-001" required>
                        </div>
                        <div class="col-md-4">
                            <label for="rule_name" class="form-label">Rule Name <span class="text-danger">*</span></label>
                            <input type="text" name="rule_name" id="rule_name" class="form-control"
                                   value="{{ old('rule_name') }}" placeholder="e.g. Intercompany Receivable/Payable" required>
                        </div>
                        <div class="col-md-4">
                            <label for="rule_type" class="form-label">Rule Type <span class="text-danger">*</span></label>
                            <select name="rule_type" id="rule_type" class="form-select" required>
                                <option value="">— Select Type —</option>
                                <option value="balance" {{ old('rule_type') === 'balance' ? 'selected' : '' }}>Balance</option>
                                <option value="revenue" {{ old('rule_type') === 'revenue' ? 'selected' : '' }}>Revenue</option>
                                <option value="investment" {{ old('rule_type') === 'investment' ? 'selected' : '' }}>Investment</option>
                                <option value="dividend" {{ old('rule_type') === 'dividend' ? 'selected' : '' }}>Dividend</option>
                                <option value="custom" {{ old('rule_type') === 'custom' ? 'selected' : '' }}>Custom</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="description" class="form-label">Description</label>
                            <textarea name="description" id="description" class="form-control" rows="2"
                                      placeholder="Optional description of this elimination rule">{{ old('description') }}</textarea>
                        </div>

                        @php
                            $ledgers = \App\Models\Ledger::active()->orderBy('account_type')->orderBy('ledger_code')->get();
                        @endphp

                        <div class="col-md-6">
                            <label for="debit_ledger_id" class="form-label">Debit Ledger <span class="text-danger">*</span></label>
                            <select name="debit_ledger_id" id="debit_ledger_id" class="form-select" required>
                                <option value="">— Select Debit Ledger —</option>
                                @foreach($ledgers as $ledger)
                                <option value="{{ $ledger->id }}" {{ old('debit_ledger_id') == $ledger->id ? 'selected' : '' }}>
                                    {{ $ledger->ledger_code }} — {{ $ledger->ledger_name }} ({{ $ledger->account_type }})
                                </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="credit_ledger_id" class="form-label">Credit Ledger <span class="text-danger">*</span></label>
                            <select name="credit_ledger_id" id="credit_ledger_id" class="form-select" required>
                                <option value="">— Select Credit Ledger —</option>
                                @foreach($ledgers as $ledger)
                                <option value="{{ $ledger->id }}" {{ old('credit_ledger_id') == $ledger->id ? 'selected' : '' }}>
                                    {{ $ledger->ledger_code }} — {{ $ledger->ledger_name }} ({{ $ledger->account_type }})
                                </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="elimination_debit_ledger_id" class="form-label">Elimination Dr Ledger <span class="text-danger">*</span></label>
                            <select name="elimination_debit_ledger_id" id="elimination_debit_ledger_id" class="form-select" required>
                                <option value="">— Select Elimination Debit Ledger —</option>
                                @foreach($ledgers as $ledger)
                                <option value="{{ $ledger->id }}" {{ old('elimination_debit_ledger_id') == $ledger->id ? 'selected' : '' }}>
                                    {{ $ledger->ledger_code }} — {{ $ledger->ledger_name }} ({{ $ledger->account_type }})
                                </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="elimination_credit_ledger_id" class="form-label">Elimination Cr Ledger <span class="text-danger">*</span></label>
                            <select name="elimination_credit_ledger_id" id="elimination_credit_ledger_id" class="form-select" required>
                                <option value="">— Select Elimination Credit Ledger —</option>
                                @foreach($ledgers as $ledger)
                                <option value="{{ $ledger->id }}" {{ old('elimination_credit_ledger_id') == $ledger->id ? 'selected' : '' }}>
                                    {{ $ledger->ledger_code }} — {{ $ledger->ledger_name }} ({{ $ledger->account_type }})
                                </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check mt-2">
                                <input type="checkbox" name="is_active" value="1" class="form-check-input" id="is_active"
                                       {{ old('is_active', 1) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="sort_order" class="form-label">Sort Order</label>
                            <input type="number" name="sort_order" id="sort_order" class="form-control"
                                   value="{{ old('sort_order', 0) }}" min="0">
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i> Save Rule
                    </button>
                    <button type="button" class="btn btn-secondary ms-2" data-bs-toggle="collapse" data-bs-target="#createRuleForm">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Rules Table --}}
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">All Elimination Rules</h5>
            <span class="badge bg-secondary">{{ $rules->count() }} rules</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Rule Code</th>
                            <th>Rule Name</th>
                            <th>Type</th>
                            <th>Debit Ledger</th>
                            <th>Credit Ledger</th>
                            <th>Elim. Dr Ledger</th>
                            <th>Elim. Cr Ledger</th>
                            <th class="text-center">Active</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($rules as $rule)
                        <tr class="{{ $rule->is_active ? '' : 'table-secondary opacity-75' }}">
                            <td>
                                <span class="fw-bold">{{ $rule->rule_code }}</span>
                            </td>
                            <td>{{ $rule->rule_name }}</td>
                            <td>
                                @php
                                    $typeBadge = match($rule->rule_type) {
                                        'balance' => 'bg-primary',
                                        'revenue' => 'bg-success',
                                        'investment' => 'bg-info',
                                        'dividend' => 'bg-warning text-dark',
                                        'custom' => 'bg-secondary',
                                        default => 'bg-light text-dark'
                                    };
                                @endphp
                                <span class="badge {{ $typeBadge }}">{{ ucfirst($rule->rule_type) }}</span>
                            </td>
                            <td>
                                <small class="text-muted">{{ $rule->debitLedger?->ledger_code }}</small>
                                {{ $rule->debitLedger?->ledger_name ?? '—' }}
                            </td>
                            <td>
                                <small class="text-muted">{{ $rule->creditLedger?->ledger_code }}</small>
                                {{ $rule->creditLedger?->ledger_name ?? '—' }}
                            </td>
                            <td>
                                <small class="text-muted">{{ $rule->eliminationDebitLedger?->ledger_code }}</small>
                                {{ $rule->eliminationDebitLedger?->ledger_name ?? '—' }}
                            </td>
                            <td>
                                <small class="text-muted">{{ $rule->eliminationCreditLedger?->ledger_code }}</small>
                                {{ $rule->eliminationCreditLedger?->ledger_name ?? '—' }}
                            </td>
                            <td class="text-center">
                                <form method="POST" action="{{ route('admin.consolidation.rules.toggle', $rule) }}" class="d-inline">
                                    @csrf @method('PATCH')
                                    <button type="submit" class="btn btn-sm {{ $rule->is_active ? 'btn-outline-success' : 'btn-outline-danger' }}"
                                            title="{{ $rule->is_active ? 'Click to deactivate' : 'Click to activate' }}">
                                        <i class="fas {{ $rule->is_active ? 'fa-check-circle' : 'fa-times-circle' }}"></i>
                                        {{ $rule->is_active ? 'Active' : 'Inactive' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                No elimination rules defined. Click "Create New Rule" to add one.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

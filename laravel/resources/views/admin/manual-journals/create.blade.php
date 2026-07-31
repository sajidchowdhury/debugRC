@extends('layouts.admin')

@section('content')
@php
    $ledgersGrouped = $ledgers->groupBy('account_type');
@endphp

<div class="container-fluid py-2" id="manualJournalCreate">
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#6366f1,#4f46e5);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-book me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Create a manual journal entry. Debits must equal credits to post. Draft saves without posting to GL.
            </p>
        </div>
        <a href="{{ route('admin.manual-journals.index') }}" class="btn btn-outline-light btn-sm">
            <i class="fas fa-arrow-left me-1"></i> Back to list
        </a>
    </header>

    <form method="POST" action="{{ route('admin.manual-journals.store') }}" id="manualJournalForm" novalidate>
        @csrf

        {{-- Header row: date + branch + description --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold" for="journal_date">Journal Date <span class="text-danger">*</span></label>
                        <input type="date" id="journal_date" name="journal_date" class="form-control" value="{{ old('journal_date', $today) }}" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold" for="branch_id">Branch <span class="text-danger">*</span></label>
                        <select id="branch_id" name="branch_id" class="form-select select2" required>
                            <option value="">— Select —</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}" {{ (string) old('branch_id', $userBranchId) === (string) $b->id ? 'selected' : '' }}>
                                    {{ $b->branch_code }} — {{ $b->branch_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold" for="description">Description</label>
                        <input type="text" id="description" name="description" class="form-control"
                               value="{{ old('description') }}" placeholder="Optional description / memo" maxlength="1000">
                    </div>
                </div>
            </div>
        </div>

        {{-- Lines --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0"><i class="fas fa-list me-1 text-primary"></i> Journal Lines</h2>
                <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddLine">
                    <i class="fas fa-plus me-1"></i> Add line
                </button>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" id="linesTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40%;">Ledger</th>
                            <th class="text-end" style="width:15%;">Debit (Tk)</th>
                            <th class="text-end" style="width:15%;">Credit (Tk)</th>
                            <th style="width:25%;">Line description</th>
                            <th class="text-center" style="width:5%;"></th>
                        </tr>
                    </thead>
                    <tbody id="linesBody"></tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td class="text-end">Totals</td>
                            <td class="text-end" id="totalDebitCell">0.00</td>
                            <td class="text-end" id="totalCreditCell">0.00</td>
                            <td>
                                <span id="balanceBadge" class="badge bg-danger">Out of balance</span>
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- Hidden lines JSON + status + submit --}}
        <input type="hidden" name="lines" id="linesInput" value="">
        <input type="hidden" name="status" id="statusInput" value="post">

        <div class="d-flex gap-2 justify-content-end mb-4">
            <a href="{{ route('admin.manual-journals.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="button" class="btn btn-outline-secondary" id="btnSaveDraft">
                <i class="fas fa-save me-1"></i> Save as draft
            </button>
            <button type="submit" class="btn btn-success" id="btnSubmit" disabled>
                <i class="fas fa-check me-1"></i> Post journal
            </button>
        </div>
    </form>
</div>

{{-- Line row template --}}
<template id="lineRowTemplate">
    <tr class="mj-line-row">
        <td>
            <select class="form-select form-select-sm mj-ledger">
                <option value="">— Select ledger —</option>
                @foreach ($ledgersGrouped as $type => $group)
                    <optgroup label="{{ $type }}">
                        @foreach ($group as $l)
                            <option value="{{ $l->id }}">{{ $l->ledger_code }} — {{ $l->ledger_name }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </td>
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end mj-debit" placeholder="0.00"></td>
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end mj-credit" placeholder="0.00"></td>
        <td><input type="text" class="form-control form-control-sm mj-line-desc" placeholder="Optional memo"></td>
        <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger mj-remove" title="Remove line">
                <i class="fas fa-times"></i>
            </button>
        </td>
    </tr>
</template>

<script>
    window.MJ_BOOT = {
        csrf_token: '{{ csrf_token() }}',
    };
</script>

@push('scripts')
<link rel="stylesheet" href="/assets/css/manual-journal-theme.css?v={{ filemtime(public_path('assets/css/manual-journal-theme.css')) }}">
<script src="/assets/js/manual-journal.js?v={{ filemtime(public_path('assets/js/manual-journal.js')) }}"></script>
@endpush
@endsection

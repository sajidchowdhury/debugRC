@extends('layouts.admin')

@section('content')
@php
    $today          = $today ?? now()->format('Y-m-d');
    $oldDate        = old('expense_date', $today);
    $oldBranch      = old('branch_id', session('branch_id'));
    $oldLedgerId    = old('ledger_id');
    $oldExpenseType = old('expense_type');
    $oldMode        = old('payment_mode', 'cash');
    $oldBankId      = old('bank_id');
    $oldAmount      = old('amount');
    $oldDescription = old('description');

    // GL preview labels from controller
    $glDrLabel = $glPreviewLabels['dr'] ?? 'Operating Expense';
    $glCrLabel = $glPreviewLabels['cr'] ?? 'Cash';
@endphp

<style>
    .oe-create-page {
        --oe-primary: #dc2626;
        --oe-primary-dark: #b91c1c;
        --oe-primary-light: #fecaca;
        --oe-glow: rgba(220,38,38,0.12);
        --oe-surface: #ffffff;
        --oe-border: #e2e8f0;
        --oe-text: #0f172a;
        --oe-muted: #64748b;
        --oe-radius: 1rem;
    }

    /* Hero */
    .oe-hero {
        background: linear-gradient(135deg, var(--oe-primary), var(--oe-primary-dark));
        border-radius: var(--oe-radius);
        padding: 1.75rem 2rem;
        color: #fff;
        box-shadow: 0 14px 40px rgba(220,38,38,0.2);
        margin-bottom: 1.5rem;
        position: relative;
        overflow: hidden;
    }
    .oe-hero::after {
        content: '';
        position: absolute;
        top: -50%;
        right: -10%;
        width: 300px;
        height: 300px;
        border-radius: 50%;
        background: rgba(255,255,255,0.06);
        pointer-events: none;
    }
    .oe-hero h1 { font-size: 1.4rem; font-weight: 700; margin-bottom: 0.3rem; }
    .oe-hero .oe-subtitle { font-size: 0.82rem; opacity: 0.85; }

    /* Step progress bar */
    .oe-step-bar {
        display: flex;
        align-items: center;
        gap: 0;
        margin-bottom: 1.5rem;
        background: var(--oe-surface);
        border: 1px solid var(--oe-border);
        border-radius: var(--oe-radius);
        padding: 1rem 1.5rem;
        box-shadow: 0 1px 4px rgba(15,23,42,0.04);
    }
    .oe-step-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex: 1;
    }
    .oe-step-num {
        width: 28px; height: 28px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.75rem; font-weight: 700;
        background: #f1f5f9; color: var(--oe-muted);
        flex-shrink: 0;
        transition: all 0.3s;
    }
    .oe-step-item.active .oe-step-num {
        background: var(--oe-primary); color: #fff;
        box-shadow: 0 2px 8px rgba(220,38,38,0.3);
    }
    .oe-step-item.done .oe-step-num {
        background: var(--oe-primary-light); color: var(--oe-primary-dark);
    }
    .oe-step-text {
        font-size: 0.78rem; font-weight: 600; color: var(--oe-muted);
        transition: color 0.3s;
    }
    .oe-step-item.active .oe-step-text { color: var(--oe-text); }
    .oe-step-item.done .oe-step-text { color: var(--oe-primary-dark); }
    .oe-step-connector {
        width: 40px; height: 2px;
        background: #e2e8f0;
        margin: 0 0.5rem;
        border-radius: 1px;
        flex-shrink: 0;
    }

    /* Section cards */
    .oe-section-card {
        background: var(--oe-surface);
        border: 1px solid var(--oe-border);
        border-radius: var(--oe-radius);
        box-shadow: 0 1px 4px rgba(15,23,42,0.04);
        margin-bottom: 1.25rem;
        overflow: hidden;
        transition: box-shadow 0.25s, border-color 0.25s;
    }
    .oe-section-card:hover { box-shadow: 0 4px 16px rgba(15,23,42,0.07); }
    .oe-section-card:focus-within { border-color: var(--oe-primary-light); }
    .oe-section-header {
        background: #f8fafc;
        border-bottom: 1px solid var(--oe-border);
        padding: 0.85rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }
    .oe-section-header h2 { font-size: 0.88rem; font-weight: 700; margin: 0; color: var(--oe-text); }
    .oe-section-icon {
        width: 34px; height: 34px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.85rem; color: #fff;
        flex-shrink: 0;
    }
    .oe-section-body { padding: 1.25rem; }
    .oe-section-badge {
        margin-left: auto;
        font-size: 0.68rem;
        font-weight: 600;
        padding: 0.2rem 0.55rem;
        border-radius: 999px;
        letter-spacing: 0.02em;
    }

    /* Form fields */
    .oe-field-group { position: relative; }
    .oe-field-group label {
        font-size: 0.76rem;
        font-weight: 600;
        color: var(--oe-muted);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 0.3rem;
        display: block;
    }
    .oe-field-group label .oe-required { color: #dc2626; margin-left: 2px; }
    .oe-field-group .form-control,
    .oe-field-group .form-select {
        border: 1.5px solid var(--oe-border);
        border-radius: 0.6rem;
        padding: 0.55rem 0.85rem;
        font-size: 0.88rem;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .oe-field-group .form-control:focus,
    .oe-field-group .form-select:focus {
        border-color: var(--oe-primary);
        box-shadow: 0 0 0 3px var(--oe-glow);
    }
    .oe-field-hint {
        font-size: 0.7rem;
        color: #94a3b8;
        margin-top: 0.25rem;
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }
    .oe-field-hint i { font-size: 0.65rem; }

    /* Amount hero card */
    .oe-amount-hero {
        background: linear-gradient(135deg, #fef2f2, #fee2e2);
        border: 1.5px solid #fecaca;
        border-radius: 1rem;
        padding: 1.5rem;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    .oe-amount-hero::before {
        content: '';
        position: absolute;
        top: -30px;
        right: -30px;
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: rgba(220,38,38,0.08);
        pointer-events: none;
    }
    .oe-amount-hero .oe-amount-icon {
        width: 48px; height: 48px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--oe-primary), var(--oe-primary-dark));
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.2rem;
        margin: 0 auto 0.75rem;
        box-shadow: 0 4px 12px rgba(220,38,38,0.2);
    }
    .oe-amount-hero .oe-amount-value {
        font-size: 2rem;
        font-weight: 800;
        color: var(--oe-primary-dark);
        font-variant-numeric: tabular-nums;
        line-height: 1.2;
    }
    .oe-amount-hero .oe-amount-label {
        font-size: 0.72rem;
        color: var(--oe-primary-dark);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        margin-top: 0.15rem;
    }
    .oe-amount-input-wrap {
        margin-top: 1rem;
        position: relative;
    }
    .oe-amount-input-wrap .input-group-text {
        background: #fff;
        border: 1.5px solid var(--oe-border);
        border-right: none;
        border-radius: 0.6rem 0 0 0.6rem;
        font-weight: 700;
        color: var(--oe-primary-dark);
        padding: 0.55rem 0.75rem;
    }
    .oe-amount-input-wrap .form-control {
        border-left: none;
        border-radius: 0 0.6rem 0.6rem 0;
        font-size: 1.1rem;
        font-weight: 600;
        padding: 0.55rem 0.85rem;
    }
    .oe-amount-input-wrap .form-control:focus {
        box-shadow: none;
        border-color: var(--oe-primary);
    }
    .oe-amount-input-wrap:focus-within .input-group-text {
        border-color: var(--oe-primary);
    }

    /* Payment mode cards */
    .oe-mode-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.6rem;
    }
    .oe-mode-card {
        border: 1.5px solid var(--oe-border);
        border-radius: 0.75rem;
        padding: 0.75rem;
        text-align: center;
        cursor: pointer;
        transition: all 0.2s;
        position: relative;
        background: #fff;
    }
    .oe-mode-card:hover {
        border-color: #cbd5e1;
        background: #f8fafc;
    }
    .oe-mode-card.selected {
        border-color: var(--oe-primary);
        background: #fef2f2;
        box-shadow: 0 0 0 3px var(--oe-glow);
    }
    .oe-mode-card .oe-mode-icon {
        font-size: 1.3rem;
        display: block;
        margin-bottom: 0.25rem;
    }
    .oe-mode-card .oe-mode-label {
        font-size: 0.72rem;
        font-weight: 600;
        color: var(--oe-text);
    }
    .oe-mode-card .oe-mode-check {
        position: absolute;
        top: 0.35rem;
        right: 0.35rem;
        width: 18px; height: 18px;
        border-radius: 50%;
        background: var(--oe-primary);
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.55rem;
        opacity: 0;
        transform: scale(0.5);
        transition: all 0.2s;
    }
    .oe-mode-card.selected .oe-mode-check {
        opacity: 1;
        transform: scale(1);
    }

    /* GL Preview */
    .oe-gl-preview {
        background: #f8fafc;
        border: 1px solid var(--oe-border);
        border-radius: 0.75rem;
        padding: 1rem;
        min-height: 80px;
    }
    .oe-gl-entry {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.6rem 0;
        border-bottom: 1px dashed #e2e8f0;
    }
    .oe-gl-entry:last-child { border-bottom: none; }
    .oe-gl-entry .oe-gl-label { font-weight: 600; font-size: 0.85rem; }
    .oe-gl-entry .oe-gl-amount { font-weight: 700; font-variant-numeric: tabular-nums; font-size: 0.9rem; }
    .oe-gl-debit .oe-gl-label { color: #0d9488; }
    .oe-gl-debit .oe-gl-amount { color: #0d9488; }
    .oe-gl-credit .oe-gl-label { color: #dc2626; }
    .oe-gl-credit .oe-gl-amount { color: #dc2626; }
    .oe-gl-total-bar {
        background: linear-gradient(135deg, #0f172a, #1e293b);
        border-radius: 8px;
        padding: 0.55rem 0.85rem;
        display: flex;
        justify-content: space-between;
        color: #fff;
        font-size: 0.82rem;
        font-weight: 600;
        margin-top: 0.5rem;
    }
    .oe-gl-empty { text-align: center; padding: 1.5rem; color: #94a3b8; }
    .oe-gl-empty i { font-size: 2rem; margin-bottom: 0.5rem; display: block; }

    /* GL info chips */
    .oe-gl-chip {
        text-align: center;
        padding: 0.6rem 0.5rem;
        border-radius: 0.6rem;
        transition: transform 0.2s;
    }
    .oe-gl-chip:hover { transform: translateY(-1px); }
    .oe-gl-chip .oe-gl-chip-label { font-size: 0.65rem; color: var(--oe-muted); text-transform: uppercase; letter-spacing: 0.04em; }
    .oe-gl-chip .oe-gl-chip-value { font-size: 0.72rem; font-weight: 700; color: var(--oe-text); margin-top: 0.1rem; }

    /* Submit bar */
    .oe-submit-bar {
        background: var(--oe-surface);
        border: 1px solid var(--oe-border);
        border-radius: var(--oe-radius);
        box-shadow: 0 4px 16px rgba(15,23,42,0.06);
        padding: 1rem 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        bottom: 1rem;
        z-index: 10;
    }
    .oe-submit-hint {
        font-size: 0.78rem;
        color: var(--oe-muted);
    }
    .oe-submit-hint i { color: var(--oe-primary); }
    .oe-btn-submit {
        background: linear-gradient(135deg, #dc2626, #b91c1c);
        color: #fff;
        border: none;
        border-radius: 0.6rem;
        padding: 0.65rem 2rem;
        font-weight: 700;
        font-size: 0.9rem;
        transition: all 0.2s;
        box-shadow: 0 4px 16px rgba(220,38,38,0.3);
        letter-spacing: 0.02em;
    }
    .oe-btn-submit:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 24px rgba(220,38,38,0.4);
        color: #fff;
    }
    .oe-btn-submit:disabled { opacity: 0.65; transform: none; box-shadow: none; }

    /* Info banner */
    .oe-info-banner {
        background: linear-gradient(135deg, #fef2f2, #fee2e2);
        border: 1px solid #fecaca;
        border-left: 4px solid var(--oe-primary);
        border-radius: 0.75rem;
        padding: 0.85rem 1.25rem;
        margin-bottom: 1.25rem;
        font-size: 0.82rem;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .oe-hero { padding: 1.15rem 1.25rem; border-radius: 0.75rem; }
        .oe-hero h1 { font-size: 1.1rem; }
        .oe-step-bar { padding: 0.75rem 1rem; gap: 0; }
        .oe-step-text { display: none; }
        .oe-step-connector { width: 20px; }
        .oe-section-body { padding: 0.85rem; }
        .oe-submit-bar { position: static; border-radius: 0.75rem; flex-direction: column; gap: 0.5rem; }
        .oe-submit-hint { display: none; }
        .oe-amount-hero .oe-amount-value { font-size: 1.5rem; }
    }
</style>

<div class="container-fluid py-3 oe-create-page">
    {{-- Hero header --}}
    <header class="oe-hero">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1><i class="fas fa-arrow-trend-down me-2"></i>Record Other Expense</h1>
                <p class="oe-subtitle mb-0">
                    <i class="fas fa-calculator me-1"></i> GL: <strong>Dr Operating Expense · Cr Cash/Bank</strong> &nbsp;·&nbsp;
                    <i class="fas fa-book me-1"></i> No entity sub-ledger — CoA only
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.other-expenses.index') }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>
    </header>

    {{-- Step progress bar --}}
    <div class="oe-step-bar">
        <div class="oe-step-item active" data-step="1">
            <div class="oe-step-num">1</div>
            <span class="oe-step-text">Expense Info</span>
        </div>
        <div class="oe-step-connector"></div>
        <div class="oe-step-item" data-step="2">
            <div class="oe-step-num">2</div>
            <span class="oe-step-text">Amount &amp; Account</span>
        </div>
        <div class="oe-step-connector"></div>
        <div class="oe-step-item" data-step="3">
            <div class="oe-step-num">3</div>
            <span class="oe-step-text">Payment</span>
        </div>
        <div class="oe-step-connector"></div>
        <div class="oe-step-item" data-step="4">
            <div class="oe-step-num">4</div>
            <span class="oe-step-text">Review &amp; Save</span>
        </div>
    </div>

    {{-- Info banner --}}
    <div class="oe-info-banner">
        <div class="d-flex align-items-start gap-2">
            <i class="fas fa-circle-info text-danger mt-1"></i>
            <div>
                <strong>Expenses post immediately on save.</strong>
                GL is balanced (Dr Operating Expense / Cr Cash or Bank). No entity sub-ledger — only the Chart of Accounts ledger you select.
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.other-expenses.store') }}" id="otherExpenseForm" novalidate>
        @csrf

        {{-- Step 1: Expense Information --}}
        <div class="oe-section-card" data-section="1">
            <div class="oe-section-header">
                <div class="oe-section-icon" style="background:linear-gradient(135deg,#dc2626,#b91c1c);">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <h2>Expense Information</h2>
                <span class="oe-section-badge bg-danger-subtle text-danger">
                    <i class="fas fa-info-circle me-1"></i>Step 1
                </span>
            </div>
            <div class="oe-section-body">
                <div class="row g-3">
                    <div class="col-lg-3 col-md-6">
                        <div class="oe-field-group">
                            <label for="expense_date">Expense Date <span class="oe-required">*</span></label>
                            <input type="date" id="expense_date" name="expense_date"
                                   class="form-control @error('expense_date') is-invalid @enderror"
                                   required value="{{ $oldDate }}">
                            @error('expense_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="oe-field-group">
                            <label for="branch_id">Branch <span class="oe-required">*</span></label>
                            <select id="branch_id" name="branch_id"
                                    class="form-select select2 @error('branch_id') is-invalid @enderror" required>
                                <option value="">Select branch</option>
                                @foreach ($branches as $b)
                                    <option value="{{ $b->id }}"
                                        {{ (string) $oldBranch === (string) $b->id ? 'selected' : '' }}>
                                        {{ $b->branch_code }} — {{ $b->branch_name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="oe-field-group">
                            <label for="expense_type">Expense Type</label>
                            <input type="text" id="expense_type" name="expense_type"
                                   class="form-control @error('expense_type') is-invalid @enderror"
                                   value="{{ $oldExpenseType }}"
                                   placeholder="e.g. Bank Charges, Rent, Utilities">
                            @error('expense_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="oe-field-hint"><i class="fas fa-lightbulb"></i> Optional label for this expense category</div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="oe-field-group">
                            <label for="description">Description</label>
                            <input type="text" id="description" name="description"
                                   class="form-control @error('description') is-invalid @enderror"
                                   value="{{ $oldDescription }}"
                                   placeholder="Optional note or reference">
                            @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Step 2: Amount & Account --}}
        <div class="row g-3" data-section="2">
            {{-- Amount card --}}
            <div class="col-lg-5">
                <div class="oe-section-card h-100">
                    <div class="oe-section-header">
                        <div class="oe-section-icon" style="background:linear-gradient(135deg,#b91c1c,#dc2626);">
                            <i class="fas fa-taka-sign"></i>
                        </div>
                        <h2>Amount</h2>
                        <span class="oe-section-badge bg-danger-subtle text-danger">
                            <i class="fas fa-info-circle me-1"></i>Step 2
                        </span>
                    </div>
                    <div class="oe-section-body">
                        <div class="oe-amount-hero">
                            <div class="oe-amount-icon">
                                <i class="fas fa-taka-sign"></i>
                            </div>
                            <div class="oe-amount-value" id="amountPreview">Tk 0.00</div>
                            <div class="oe-amount-label">Expense Amount</div>
                        </div>
                        <div class="oe-amount-input-wrap">
                            <div class="input-group">
                                <span class="input-group-text">Tk</span>
                                <input type="number" id="amount" name="amount"
                                       class="form-control text-end @error('amount') is-invalid @enderror"
                                       min="0.01" step="0.01" required
                                       value="{{ $oldAmount }}"
                                       placeholder="0.00">
                            </div>
                            @error('amount') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>
            </div>

            {{-- Expense Account card --}}
            <div class="col-lg-7">
                <div class="oe-section-card h-100">
                    <div class="oe-section-header">
                        <div class="oe-section-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);">
                            <i class="fas fa-book-open"></i>
                        </div>
                        <h2>Expense Account</h2>
                    </div>
                    <div class="oe-section-body">
                        <div class="oe-field-group mb-3">
                            <label for="ledger_id">Expense Ledger <span class="oe-required">*</span></label>
                            <select id="ledger_id" name="ledger_id"
                                    class="form-select select2 @error('ledger_id') is-invalid @enderror" required>
                                <option value="">Select expense ledger from Chart of Accounts</option>
                                @foreach ($expenseLedgers as $l)
                                    <option value="{{ $l->id }}"
                                        {{ (string) $oldLedgerId === (string) $l->id ? 'selected' : '' }}>
                                        @if (!empty($l->ledger_code)){{ $l->ledger_code }} — @endif{{ $l->ledger_name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('ledger_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="oe-field-hint"><i class="fas fa-info-circle"></i> Select the expense account that will be debited</div>
                        </div>

                        {{-- Quick info cards --}}
                        <div class="row g-2 mt-2">
                            <div class="col-sm-4">
                                <div class="oe-gl-chip" style="background:#fef2f2;">
                                    <div class="oe-gl-chip-label">GL Rule</div>
                                    <div class="oe-gl-chip-value" id="glRuleLabel">Dr Expense · Cr Cash/Bank</div>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="oe-gl-chip" style="background:#fff7ed;">
                                    <div class="oe-gl-chip-label">Sub-Ledger</div>
                                    <div class="oe-gl-chip-value" id="subLedgerLabel">None — CoA only</div>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="oe-gl-chip" style="background:#eff6ff;">
                                    <div class="oe-gl-chip-label">Bank Book</div>
                                    <div class="oe-gl-chip-value" id="bankBookLabel">Decrease (if bank)</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Step 3: Payment Method --}}
        <div class="oe-section-card" data-section="3">
            <div class="oe-section-header">
                <div class="oe-section-icon" style="background:linear-gradient(135deg,#2563eb,#3b82f6);">
                    <i class="fas fa-wallet"></i>
                </div>
                <h2>Payment Method</h2>
                <span class="oe-section-badge bg-primary-subtle text-primary">
                    <i class="fas fa-info-circle me-1"></i>Step 3
                </span>
            </div>
            <div class="oe-section-body">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <label style="font-size:0.76rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.5rem;display:block;">
                            How was the expense paid? <span class="oe-required">*</span>
                        </label>
                        <div class="oe-mode-grid">
                            <div class="oe-mode-card {{ $oldMode === 'cash' ? 'selected' : '' }}" data-mode="cash">
                                <div class="oe-mode-check"><i class="fas fa-check"></i></div>
                                <span class="oe-mode-icon">💵</span>
                                <span class="oe-mode-label">Cash</span>
                            </div>
                            <div class="oe-mode-card {{ $oldMode === 'bank' ? 'selected' : '' }}" data-mode="bank">
                                <div class="oe-mode-check"><i class="fas fa-check"></i></div>
                                <span class="oe-mode-icon">🏦</span>
                                <span class="oe-mode-label">Bank Transfer</span>
                            </div>
                            <div class="oe-mode-card {{ $oldMode === 'mobile_banking' ? 'selected' : '' }}" data-mode="mobile_banking">
                                <div class="oe-mode-check"><i class="fas fa-check"></i></div>
                                <span class="oe-mode-icon">📱</span>
                                <span class="oe-mode-label">Mobile Banking</span>
                            </div>
                            <div class="oe-mode-card {{ $oldMode === 'cheque' ? 'selected' : '' }}" data-mode="cheque">
                                <div class="oe-mode-check"><i class="fas fa-check"></i></div>
                                <span class="oe-mode-icon">📝</span>
                                <span class="oe-mode-label">Cheque</span>
                            </div>
                        </div>
                        <input type="hidden" id="payment_mode" name="payment_mode" value="{{ $oldMode }}" required>
                        @error('payment_mode') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-6" id="bank_section" style="display:{{ $oldMode === 'bank' || $oldMode === 'cheque' ? 'block' : 'none' }};">
                        <div class="oe-field-group">
                            <label for="bank_id">Select Bank <span class="oe-required">*</span></label>
                            <select id="bank_id" name="bank_id"
                                    class="form-select select2 @error('bank_id') is-invalid @enderror">
                                <option value="">Choose bank account</option>
                                @foreach ($banks as $bk)
                                    <option value="{{ $bk->id }}"
                                        {{ (string) $oldBankId === (string) $bk->id ? 'selected' : '' }}>
                                        {{ $bk->bank_name }}@if (!empty($bk->account_number)) — {{ $bk->account_number }}@endif
                                    </option>
                                @endforeach
                            </select>
                            @error('bank_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="oe-field-hint"><i class="fas fa-info-circle"></i> Required for bank transfer and cheque modes</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Step 4: GL Accounting Preview --}}
        <div class="oe-section-card" data-section="4">
            <div class="oe-section-header">
                <div class="oe-section-icon" style="background:linear-gradient(135deg,#0f172a,#334155);">
                    <i class="fas fa-scale-balanced"></i>
                </div>
                <h2>GL Accounting Preview</h2>
                <span class="oe-section-badge bg-dark-subtle text-dark">
                    <i class="fas fa-check-double me-1"></i>Review
                </span>
            </div>
            <div class="oe-section-body">
                <div class="oe-gl-preview" id="glPreviewArea">
                    <div class="oe-gl-empty" id="glEmpty">
                        <i class="fas fa-scale-balanced"></i>
                        <div class="small">Enter an amount and select a ledger to preview the GL journal entry</div>
                    </div>
                    <div id="glEntries" style="display:none;">
                        <div class="oe-gl-entry oe-gl-debit">
                            <div>
                                <span class="oe-gl-label" id="glDebitLabel">Dr Operating Expense</span>
                                <div class="small text-muted" id="glDebitSub">Expense ledger is debited</div>
                            </div>
                            <span class="oe-gl-amount" id="glDebitAmount">Tk 0.00</span>
                        </div>
                        <div class="oe-gl-entry oe-gl-credit">
                            <div>
                                <span class="oe-gl-label" id="glCreditLabel">Cr Cash in Hand</span>
                                <div class="small text-muted" id="glCreditSub">Cash/Bank balance decreases</div>
                            </div>
                            <span class="oe-gl-amount" id="glCreditAmount">Tk 0.00</span>
                        </div>
                        <div class="oe-gl-total-bar">
                            <span><i class="fas fa-check-circle me-1"></i>Net Effect</span>
                            <span id="glNetEffect">Balanced</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Submit Bar --}}
        <div class="oe-submit-bar">
            <div class="oe-submit-hint">
                <i class="fas fa-shield-halved me-1"></i> GL posts immediately on save — double-entry balanced
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.other-expenses.index') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
                <button type="submit" class="oe-btn-submit" id="submitBtn">
                    <i class="fas fa-floppy-disk me-1"></i>
                    <span id="submitLabel">Save Expense</span>
                </button>
            </div>
        </div>
    </form>
</div>

{{-- Boot config for OtherExpense.js --}}
<script>
    window.OE_BOOT = {
        baseUrl: '{{ url("/") }}/',
        csrf_token: '{{ csrf_token() }}',
        routes: {
            'index': '{{ route("admin.other-expenses.index") }}',
            'show': '{{ route("admin.other-expenses.show", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'reverse': '{{ route("admin.other-expenses.reverse", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'slip': '{{ route("admin.other-expenses.slip", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
        },
    };
</script>

@push('scripts')
<script src="/assets/js/OtherExpense.js?v={{ filemtime(public_path('assets/js/OtherExpense.js')) }}"></script>
<script>
$(function () {
    var $form        = $('#otherExpenseForm');
    var $modeInput   = $('#payment_mode');
    var $bankSection = $('#bank_section');
    var $bankId      = $('#bank_id');
    var $amount      = $('#amount');
    var $ledgerId    = $('#ledger_id');

    // GL preview elements
    var $glEmpty     = $('#glEmpty');
    var $glEntries   = $('#glEntries');
    var $glDebitLabel   = $('#glDebitLabel');
    var $glDebitSub     = $('#glDebitSub');
    var $glDebitAmount  = $('#glDebitAmount');
    var $glCreditLabel  = $('#glCreditLabel');
    var $glCreditSub    = $('#glCreditSub');
    var $glCreditAmount = $('#glCreditAmount');
    var $glNetEffect    = $('#glNetEffect');
    var $glRuleLabel    = $('#glRuleLabel');
    var $subLedgerLabel = $('#subLedgerLabel');
    var $bankBookLabel  = $('#bankBookLabel');
    var $amountPreview  = $('#amountPreview');

    // Payment mode cards
    var $modeCards = $('.oe-mode-card');
    $modeCards.on('click', function () {
        var mode = $(this).data('mode');
        $modeCards.removeClass('selected');
        $(this).addClass('selected');
        $modeInput.val(mode);
        syncBankVisibility();
        updateGLPreview();
        updateStepProgress();
    });

    // Bank visibility
    function syncBankVisibility() {
        var mode = $modeInput.val();
        if (mode === 'bank' || mode === 'cheque') {
            $bankSection.show();
            $bankId.prop('required', mode === 'bank');
        } else {
            $bankSection.hide();
            $bankId.prop('required', false).val('').trigger('change');
        }
    }

    // Step progress
    function updateStepProgress() {
        var hasDate = $('#expense_date').val();
        var hasBranch = $('#branch_id').val();
        var hasAmount = parseFloat($amount.val()) > 0;
        var hasLedger = $ledgerId.val();
        var hasMode = $modeInput.val();

        var $steps = $('.oe-step-item');
        $steps.removeClass('active done');

        if (hasDate && hasBranch) {
            $steps.filter('[data-step="1"]').addClass('done');
            $steps.filter('[data-step="2"]').addClass('active');
        } else {
            $steps.filter('[data-step="1"]').addClass('active');
            return;
        }

        if (hasAmount && hasLedger) {
            $steps.filter('[data-step="2"]').addClass('done');
            $steps.filter('[data-step="3"]').addClass('active');
        } else {
            $steps.filter('[data-step="2"]').addClass('active');
            return;
        }

        if (hasMode) {
            $steps.filter('[data-step="3"]').addClass('done');
            $steps.filter('[data-step="4"]').addClass('active');
        }
    }

    // GL Preview
    function updateGLPreview() {
        var amount = parseFloat($amount.val()) || 0;
        var mode = $modeInput.val();
        var ledgerName = $ledgerId.find('option:selected').text();
        var bankName = $bankId.find('option:selected').text();

        $amountPreview.text('Tk ' + numberFormat(amount));

        if (amount <= 0) {
            $glEmpty.show();
            $glEntries.hide();
            return;
        }

        $glEmpty.hide();
        $glEntries.show();

        // Dr = Expense ledger
        var drLabel = (ledgerName && ledgerName !== 'Select expense ledger from Chart of Accounts') ? ledgerName : 'Operating Expense';

        // Cr = Cash/Bank
        var crLabel = 'Cash in Hand';
        var crSub = 'Cash balance decreases';
        var bankBookText = 'No change';

        if (mode === 'bank') {
            crLabel = (bankName && bankName !== 'Choose bank account') ? 'Bank — ' + bankName : 'Bank Account';
            crSub = 'Bank balance decreases';
            bankBookText = 'Decrease';
        } else if (mode === 'cheque') {
            crLabel = (bankName && bankName !== 'Choose bank account') ? 'Bank — ' + bankName : 'Bank Account';
            crSub = 'Bank balance decreases (on clearance)';
            bankBookText = 'Decrease (on clearance)';
        } else if (mode === 'mobile_banking') {
            crLabel = 'Mobile Banking';
            crSub = 'Mobile balance decreases';
            bankBookText = 'Decrease';
        }

        $glDebitLabel.text('Dr ' + drLabel);
        $glDebitSub.text('Expense ledger is debited');
        $glDebitAmount.text('Tk ' + numberFormat(amount));

        $glCreditLabel.text('Cr ' + crLabel);
        $glCreditSub.text(crSub);
        $glCreditAmount.text('Tk ' + numberFormat(amount));

        $glNetEffect.text('Balanced');

        $glRuleLabel.text('Dr Expense · Cr Cash/Bank');
        $subLedgerLabel.text('None — CoA only');
        $bankBookLabel.text(bankBookText);
    }

    function numberFormat(num) {
        return parseFloat(num).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // Event listeners
    $amount.on('input', function () { updateGLPreview(); updateStepProgress(); });
    $ledgerId.on('change', function () { updateGLPreview(); updateStepProgress(); });
    $bankId.on('change', updateGLPreview);
    $('#expense_date, #branch_id').on('change', updateStepProgress);

    // Form submission
    $form.on('submit', function (e) {
        e.preventDefault();

        var amount = parseFloat($amount.val()) || 0;
        var mode = $modeInput.val();
        var ledgerName = $ledgerId.find('option:selected').text();

        if (amount <= 0) {
            Swal.fire({ icon: 'warning', title: 'Invalid amount', text: 'Please enter a valid amount greater than zero.' });
            return;
        }

        if (!$ledgerId.val()) {
            Swal.fire({ icon: 'warning', title: 'Ledger required', text: 'Please select an expense ledger.' });
            return;
        }

        if (!mode) {
            Swal.fire({ icon: 'warning', title: 'Payment mode required', text: 'Please select how the expense was paid.' });
            return;
        }

        var modeLabel = mode === 'bank' || mode === 'cheque' ? 'Bank' : mode === 'mobile_banking' ? 'Mobile' : 'Cash';

        Swal.fire({
            icon: 'question',
            title: 'Record this other expense?',
            html: '<div style="text-align:left;">' +
                  '<p class="mb-1"><strong>Amount:</strong> Tk ' + numberFormat(amount) + '</p>' +
                  '<p class="mb-1"><strong>Payment:</strong> ' + modeLabel + '</p>' +
                  '<p class="mb-0 text-muted small">GL entry: Dr ' + ledgerName + ', Cr ' + modeLabel + '</p>' +
                  '</div>',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check"></i> Save Expense',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc2626',
            reverseButtons: true,
        }).then(function (result) {
            if (result.isConfirmed) {
                var $btn = $('#submitBtn');
                $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i> Saving…');

                $.ajax({
                    url: $form.attr('action'),
                    method: 'POST',
                    data: $form.serialize(),
                    success: function (resp) {
                        if (resp.status === 'success' || resp.redirect) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Saved!',
                                text: resp.message || 'Other expense recorded successfully.',
                                timer: 2000,
                                showConfirmButton: false
                            }).then(function () {
                                var redirectUrl = resp.redirect_url || resp.redirect || '{{ route("admin.other-expenses.index") }}';
                                window.location.href = redirectUrl;
                            });
                        } else {
                            Swal.fire('Error', resp.message || 'Failed to save.', 'error');
                            $btn.prop('disabled', false).html('<i class="fas fa-floppy-disk me-1"></i> <span id="submitLabel">Save Expense</span>');
                        }
                    },
                    error: function (xhr) {
                        if (xhr.status === 422) {
                            var errors = xhr.responseJSON.errors;
                            var msg = '';
                            if (errors) {
                                $.each(errors, function (key, val) { msg += val[0] + '<br>'; });
                            }
                            Swal.fire({ icon: 'error', title: 'Validation error', html: msg });
                        } else {
                            var msg = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'An error occurred.';
                            Swal.fire('Error', msg, 'error');
                        }
                        $btn.prop('disabled', false).html('<i class="fas fa-floppy-disk me-1"></i> <span id="submitLabel">Save Expense</span>');
                    }
                });
            }
        });
    });

    // Initialize
    syncBankVisibility();
    updateGLPreview();
    updateStepProgress();
});
</script>
@endpush
@endsection
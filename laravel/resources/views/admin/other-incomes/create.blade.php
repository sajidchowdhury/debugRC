@extends('layouts.admin')

@section('content')
@php
    $today      = $today ?? now()->format('Y-m-d');
    $oldDate    = old('income_date', $today);
    $oldBranch  = old('branch_id', session('branch_id'));
    $oldLedger  = old('ledger_id');
    $oldType    = old('income_type');
    $oldMode    = old('payment_mode', 'cash');
    $oldBank    = old('bank_id');
    $oldAmt     = old('amount');
    $oldDesc    = old('description');

    // GL preview labels from controller
    $glDrLabel = $glPreviewLabels['dr'] ?? 'Cash in Hand';
    $glCrLabel = $glPreviewLabels['cr'] ?? 'Other Income';
@endphp

<style>
    /* ── Page vars ── */
    .oi-create-page {
        --oi-primary: #16a34a;
        --oi-primary-dark: #15803d;
        --oi-primary-light: #bbf7d0;
        --oi-glow: rgba(22,163,74,0.12);
        --oi-surface: #ffffff;
        --oi-border: #e2e8f0;
        --oi-text: #0f172a;
        --oi-muted: #64748b;
        --oi-radius: 1rem;
    }

    /* ── Hero ── */
    .oi-hero {
        background: linear-gradient(135deg, var(--oi-primary), var(--oi-primary-dark));
        border-radius: var(--oi-radius);
        padding: 1.75rem 2rem;
        color: #fff;
        box-shadow: 0 14px 40px rgba(22,163,74,0.2);
        margin-bottom: 1.5rem;
        position: relative;
        overflow: hidden;
    }
    .oi-hero::after {
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
    .oi-hero h1 { font-size: 1.4rem; font-weight: 700; margin-bottom: 0.3rem; }
    .oi-hero .oi-subtitle { font-size: 0.82rem; opacity: 0.85; }

    /* ── Step progress bar ── */
    .oi-step-bar {
        display: flex;
        align-items: center;
        gap: 0;
        margin-bottom: 1.5rem;
        background: var(--oi-surface);
        border: 1px solid var(--oi-border);
        border-radius: var(--oi-radius);
        padding: 1rem 1.5rem;
        box-shadow: 0 1px 4px rgba(15,23,42,0.04);
    }
    .oi-step-item {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        flex: 1;
    }
    .oi-step-num {
        width: 28px; height: 28px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.75rem; font-weight: 700;
        background: #f1f5f9; color: var(--oi-muted);
        flex-shrink: 0;
        transition: all 0.3s;
    }
    .oi-step-item.active .oi-step-num {
        background: var(--oi-primary); color: #fff;
        box-shadow: 0 2px 8px rgba(22,163,74,0.3);
    }
    .oi-step-item.done .oi-step-num {
        background: var(--oi-primary-light); color: var(--oi-primary-dark);
    }
    .oi-step-text {
        font-size: 0.78rem; font-weight: 600; color: var(--oi-muted);
        transition: color 0.3s;
    }
    .oi-step-item.active .oi-step-text { color: var(--oi-text); }
    .oi-step-item.done .oi-step-text { color: var(--oi-primary-dark); }
    .oi-step-connector {
        width: 40px; height: 2px;
        background: #e2e8f0;
        margin: 0 0.5rem;
        border-radius: 1px;
        flex-shrink: 0;
    }

    /* ── Section cards ── */
    .oi-section-card {
        background: var(--oi-surface);
        border: 1px solid var(--oi-border);
        border-radius: var(--oi-radius);
        box-shadow: 0 1px 4px rgba(15,23,42,0.04);
        margin-bottom: 1.25rem;
        overflow: hidden;
        transition: box-shadow 0.25s, border-color 0.25s;
    }
    .oi-section-card:hover {
        box-shadow: 0 4px 16px rgba(15,23,42,0.07);
    }
    .oi-section-card:focus-within {
        border-color: var(--oi-primary-light);
    }
    .oi-section-header {
        background: #f8fafc;
        border-bottom: 1px solid var(--oi-border);
        padding: 0.85rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.6rem;
    }
    .oi-section-header h2 { font-size: 0.88rem; font-weight: 700; margin: 0; color: var(--oi-text); }
    .oi-section-icon {
        width: 34px; height: 34px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.85rem; color: #fff;
        flex-shrink: 0;
    }
    .oi-section-body { padding: 1.25rem; }
    .oi-section-badge {
        margin-left: auto;
        font-size: 0.68rem;
        font-weight: 600;
        padding: 0.2rem 0.55rem;
        border-radius: 999px;
        letter-spacing: 0.02em;
    }

    /* ── Form fields ── */
    .oi-field-group {
        position: relative;
    }
    .oi-field-group label {
        font-size: 0.76rem;
        font-weight: 600;
        color: var(--oi-muted);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 0.3rem;
        display: block;
    }
    .oi-field-group label .oi-required {
        color: #dc2626;
        margin-left: 2px;
    }
    .oi-field-group .form-control,
    .oi-field-group .form-select {
        border: 1.5px solid var(--oi-border);
        border-radius: 0.6rem;
        padding: 0.55rem 0.85rem;
        font-size: 0.88rem;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .oi-field-group .form-control:focus,
    .oi-field-group .form-select:focus {
        border-color: var(--oi-primary);
        box-shadow: 0 0 0 3px var(--oi-glow);
    }
    .oi-field-hint {
        font-size: 0.7rem;
        color: #94a3b8;
        margin-top: 0.25rem;
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }
    .oi-field-hint i { font-size: 0.65rem; }

    /* ── Amount hero card ── */
    .oi-amount-hero {
        background: linear-gradient(135deg, #f0fdf4, #dcfce7);
        border: 1.5px solid #bbf7d0;
        border-radius: 1rem;
        padding: 1.5rem;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    .oi-amount-hero::before {
        content: '';
        position: absolute;
        top: -30px;
        right: -30px;
        width: 100px;
        height: 100px;
        border-radius: 50%;
        background: rgba(22,163,74,0.08);
        pointer-events: none;
    }
    .oi-amount-hero .oi-amount-icon {
        width: 48px; height: 48px;
        border-radius: 50%;
        background: linear-gradient(135deg, var(--oi-primary), var(--oi-primary-dark));
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.2rem;
        margin: 0 auto 0.75rem;
        box-shadow: 0 4px 12px rgba(22,163,74,0.2);
    }
    .oi-amount-hero .oi-amount-value {
        font-size: 2rem;
        font-weight: 800;
        color: var(--oi-primary-dark);
        font-variant-numeric: tabular-nums;
        line-height: 1.2;
    }
    .oi-amount-hero .oi-amount-label {
        font-size: 0.72rem;
        color: var(--oi-primary-dark);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        margin-top: 0.15rem;
    }
    .oi-amount-input-wrap {
        margin-top: 1rem;
        position: relative;
    }
    .oi-amount-input-wrap .input-group-text {
        background: #fff;
        border: 1.5px solid var(--oi-border);
        border-right: none;
        border-radius: 0.6rem 0 0 0.6rem;
        font-weight: 700;
        color: var(--oi-primary-dark);
        padding: 0.55rem 0.75rem;
    }
    .oi-amount-input-wrap .form-control {
        border-left: none;
        border-radius: 0 0.6rem 0.6rem 0;
        font-size: 1.1rem;
        font-weight: 600;
        padding: 0.55rem 0.85rem;
    }
    .oi-amount-input-wrap .form-control:focus {
        box-shadow: none;
        border-color: var(--oi-primary);
    }
    .oi-amount-input-wrap:focus-within .input-group-text {
        border-color: var(--oi-primary);
    }

    /* ── Payment mode cards ── */
    .oi-mode-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 0.6rem;
    }
    .oi-mode-card {
        border: 1.5px solid var(--oi-border);
        border-radius: 0.75rem;
        padding: 0.75rem;
        text-align: center;
        cursor: pointer;
        transition: all 0.2s;
        position: relative;
        background: #fff;
    }
    .oi-mode-card:hover {
        border-color: #cbd5e1;
        background: #f8fafc;
    }
    .oi-mode-card.selected {
        border-color: var(--oi-primary);
        background: #f0fdf4;
        box-shadow: 0 0 0 3px var(--oi-glow);
    }
    .oi-mode-card .oi-mode-icon {
        font-size: 1.3rem;
        display: block;
        margin-bottom: 0.25rem;
    }
    .oi-mode-card .oi-mode-label {
        font-size: 0.72rem;
        font-weight: 600;
        color: var(--oi-text);
    }
    .oi-mode-card .oi-mode-check {
        position: absolute;
        top: 0.35rem;
        right: 0.35rem;
        width: 18px; height: 18px;
        border-radius: 50%;
        background: var(--oi-primary);
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.55rem;
        opacity: 0;
        transform: scale(0.5);
        transition: all 0.2s;
    }
    .oi-mode-card.selected .oi-mode-check {
        opacity: 1;
        transform: scale(1);
    }

    /* ── GL Preview ── */
    .oi-gl-preview {
        background: #f8fafc;
        border: 1px solid var(--oi-border);
        border-radius: 0.75rem;
        padding: 1rem;
        min-height: 80px;
    }
    .oi-gl-entry {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.6rem 0;
        border-bottom: 1px dashed #e2e8f0;
    }
    .oi-gl-entry:last-child { border-bottom: none; }
    .oi-gl-entry .oi-gl-label { font-weight: 600; font-size: 0.85rem; }
    .oi-gl-entry .oi-gl-amount { font-weight: 700; font-variant-numeric: tabular-nums; font-size: 0.9rem; }
    .oi-gl-debit .oi-gl-label { color: #0d9488; }
    .oi-gl-debit .oi-gl-amount { color: #0d9488; }
    .oi-gl-credit .oi-gl-label { color: #dc2626; }
    .oi-gl-credit .oi-gl-amount { color: #dc2626; }
    .oi-gl-total-bar {
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
    .oi-gl-empty { text-align: center; padding: 1.5rem; color: #94a3b8; }
    .oi-gl-empty i { font-size: 2rem; margin-bottom: 0.5rem; display: block; }

    /* ── GL info chips ── */
    .oi-gl-chip {
        text-align: center;
        padding: 0.6rem 0.5rem;
        border-radius: 0.6rem;
        transition: transform 0.2s;
    }
    .oi-gl-chip:hover { transform: translateY(-1px); }
    .oi-gl-chip .oi-gl-chip-label { font-size: 0.65rem; color: var(--oi-muted); text-transform: uppercase; letter-spacing: 0.04em; }
    .oi-gl-chip .oi-gl-chip-value { font-size: 0.72rem; font-weight: 700; color: var(--oi-text); margin-top: 0.1rem; }

    /* ── Submit bar ── */
    .oi-submit-bar {
        background: var(--oi-surface);
        border: 1px solid var(--oi-border);
        border-radius: var(--oi-radius);
        box-shadow: 0 4px 16px rgba(15,23,42,0.06);
        padding: 1rem 1.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: sticky;
        bottom: 1rem;
        z-index: 10;
    }
    .oi-submit-hint {
        font-size: 0.78rem;
        color: var(--oi-muted);
    }
    .oi-submit-hint i { color: var(--oi-primary); }
    .oi-btn-submit {
        background: linear-gradient(135deg, #16a34a, #15803d);
        color: #fff;
        border: none;
        border-radius: 0.6rem;
        padding: 0.65rem 2rem;
        font-weight: 700;
        font-size: 0.9rem;
        transition: all 0.2s;
        box-shadow: 0 4px 16px rgba(22,163,74,0.3);
        letter-spacing: 0.02em;
    }
    .oi-btn-submit:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 24px rgba(22,163,74,0.4);
        color: #fff;
    }
    .oi-btn-submit:disabled { opacity: 0.65; transform: none; box-shadow: none; }

    /* ── Info banner ── */
    .oi-info-banner {
        background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
        border: 1px solid #bbf7d0;
        border-left: 4px solid var(--oi-primary);
        border-radius: 0.75rem;
        padding: 0.85rem 1.25rem;
        margin-bottom: 1.25rem;
        font-size: 0.82rem;
    }

    /* ── Responsive ── */
    @media (max-width: 768px) {
        .oi-hero { padding: 1.15rem 1.25rem; border-radius: 0.75rem; }
        .oi-hero h1 { font-size: 1.1rem; }
        .oi-step-bar { padding: 0.75rem 1rem; gap: 0; }
        .oi-step-text { display: none; }
        .oi-step-connector { width: 20px; }
        .oi-section-body { padding: 0.85rem; }
        .oi-submit-bar { position: static; border-radius: 0.75rem; flex-direction: column; gap: 0.5rem; }
        .oi-submit-hint { display: none; }
        .oi-amount-hero .oi-amount-value { font-size: 1.5rem; }
        .oi-mode-grid { grid-template-columns: repeat(2, 1fr); }
    }
</style>

<div class="container-fluid py-3 oi-create-page">
    {{-- Hero header --}}
    <header class="oi-hero">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1><i class="fas fa-arrow-trend-up me-2"></i>Record Other Income</h1>
                <p class="oi-subtitle mb-0">
                    <i class="fas fa-calculator me-1"></i> GL: <strong>Dr Cash/Bank · Cr Income Ledger</strong> &nbsp;·&nbsp;
                    <i class="fas fa-book me-1"></i> No entity sub-ledger — CoA only
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.other-incomes.index') }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>
    </header>

    {{-- Step progress bar --}}
    <div class="oi-step-bar">
        <div class="oi-step-item active" data-step="1">
            <div class="oi-step-num">1</div>
            <span class="oi-step-text">Income Info</span>
        </div>
        <div class="oi-step-connector"></div>
        <div class="oi-step-item" data-step="2">
            <div class="oi-step-num">2</div>
            <span class="oi-step-text">Amount &amp; Account</span>
        </div>
        <div class="oi-step-connector"></div>
        <div class="oi-step-item" data-step="3">
            <div class="oi-step-num">3</div>
            <span class="oi-step-text">Payment</span>
        </div>
        <div class="oi-step-connector"></div>
        <div class="oi-step-item" data-step="4">
            <div class="oi-step-num">4</div>
            <span class="oi-step-text">Review &amp; Save</span>
        </div>
    </div>

    {{-- Info banner --}}
    <div class="oi-info-banner">
        <div class="d-flex align-items-start gap-2">
            <i class="fas fa-circle-info text-success mt-1"></i>
            <div>
                <strong>Other incomes post immediately on save.</strong>
                GL is balanced (Dr Cash/Bank, Cr Income Ledger). No entity sub-ledger — only the Chart of Accounts ledger you select.
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.other-incomes.store') }}" id="otherIncomeForm" novalidate>
        @csrf

        {{-- Step 1: Income Information --}}
        <div class="oi-section-card" data-section="1">
            <div class="oi-section-header">
                <div class="oi-section-icon" style="background:linear-gradient(135deg,#16a34a,#15803d);">
                    <i class="fas fa-file-invoice"></i>
                </div>
                <h2>Income Information</h2>
                <span class="oi-section-badge bg-success-subtle text-success">
                    <i class="fas fa-info-circle me-1"></i>Step 1
                </span>
            </div>
            <div class="oi-section-body">
                <div class="row g-3">
                    <div class="col-lg-3 col-md-6">
                        <div class="oi-field-group">
                            <label for="income_date">Income Date <span class="oi-required">*</span></label>
                            <input type="date" id="income_date" name="income_date"
                                   class="form-control @error('income_date') is-invalid @enderror"
                                   required value="{{ $oldDate }}">
                            @error('income_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="oi-field-group">
                            <label for="branch_id">Branch <span class="oi-required">*</span></label>
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
                        <div class="oi-field-group">
                            <label for="income_type">Income Type</label>
                            <input type="text" id="income_type" name="income_type"
                                   class="form-control @error('income_type') is-invalid @enderror"
                                   value="{{ $oldType }}"
                                   placeholder="e.g. Interest, Rent, Commission">
                            @error('income_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="oi-field-hint"><i class="fas fa-lightbulb"></i> Optional label for this income category</div>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="oi-field-group">
                            <label for="description">Description</label>
                            <input type="text" id="description" name="description"
                                   class="form-control @error('description') is-invalid @enderror"
                                   value="{{ $oldDesc }}"
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
                <div class="oi-section-card h-100">
                    <div class="oi-section-header">
                        <div class="oi-section-icon" style="background:linear-gradient(135deg,#15803d,#16a34a);">
                            <i class="fas fa-taka-sign"></i>
                        </div>
                        <h2>Amount</h2>
                        <span class="oi-section-badge bg-success-subtle text-success">
                            <i class="fas fa-info-circle me-1"></i>Step 2
                        </span>
                    </div>
                    <div class="oi-section-body">
                        <div class="oi-amount-hero">
                            <div class="oi-amount-icon">
                                <i class="fas fa-taka-sign"></i>
                            </div>
                            <div class="oi-amount-value" id="amountPreview">Tk 0.00</div>
                            <div class="oi-amount-label">Income Amount</div>
                        </div>
                        <div class="oi-amount-input-wrap">
                            <div class="input-group">
                                <span class="input-group-text">Tk</span>
                                <input type="number" id="amount" name="amount"
                                       class="form-control text-end @error('amount') is-invalid @enderror"
                                       min="0.01" step="0.01" required
                                       value="{{ $oldAmt }}"
                                       placeholder="0.00">
                            </div>
                            @error('amount') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>
            </div>

            {{-- Income Ledger card --}}
            <div class="col-lg-7">
                <div class="oi-section-card h-100">
                    <div class="oi-section-header">
                        <div class="oi-section-icon" style="background:linear-gradient(135deg,#7c3aed,#6d28d9);">
                            <i class="fas fa-book-open"></i>
                        </div>
                        <h2>Income Account</h2>
                    </div>
                    <div class="oi-section-body">
                        <div class="oi-field-group mb-3">
                            <label for="ledger_id">Income Ledger <span class="oi-required">*</span></label>
                            <select id="ledger_id" name="ledger_id"
                                    class="form-select select2 @error('ledger_id') is-invalid @enderror" required>
                                <option value="">Select income ledger from Chart of Accounts</option>
                                @foreach ($incomeLedgers as $ledger)
                                    <option value="{{ $ledger->id }}"
                                        {{ (string) $oldLedger === (string) $ledger->id ? 'selected' : '' }}>
                                        {{ $ledger->ledger_code }} — {{ $ledger->ledger_name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('ledger_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="oi-field-hint"><i class="fas fa-info-circle"></i> Select the income account where revenue will be credited</div>
                        </div>

                        {{-- Quick info cards --}}
                        <div class="row g-2 mt-2">
                            <div class="col-sm-4">
                                <div class="oi-gl-chip" style="background:#f0fdf4;">
                                    <div class="oi-gl-chip-label">GL Rule</div>
                                    <div class="oi-gl-chip-value" id="glRuleLabel">Dr Cash/Bank · Cr Income</div>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="oi-gl-chip" style="background:#fff7ed;">
                                    <div class="oi-gl-chip-label">Sub-Ledger</div>
                                    <div class="oi-gl-chip-value" id="subLedgerLabel">None — CoA only</div>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="oi-gl-chip" style="background:#eff6ff;">
                                    <div class="oi-gl-chip-label">Bank Book</div>
                                    <div class="oi-gl-chip-value" id="bankBookLabel">Increase (if bank)</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Step 3: Payment Method --}}
        <div class="oi-section-card" data-section="3">
            <div class="oi-section-header">
                <div class="oi-section-icon" style="background:linear-gradient(135deg,#2563eb,#3b82f6);">
                    <i class="fas fa-wallet"></i>
                </div>
                <h2>Payment Method</h2>
                <span class="oi-section-badge bg-primary-subtle text-primary">
                    <i class="fas fa-info-circle me-1"></i>Step 3
                </span>
            </div>
            <div class="oi-section-body">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <label class="oi-field-group" style="margin-bottom:0;">
                            <label style="font-size:0.76rem;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:0.5rem;display:block;">
                                How was the income received? <span class="oi-required">*</span>
                            </label>
                        </label>
                        <div class="oi-mode-grid">
                            <div class="oi-mode-card {{ $oldMode === 'cash' ? 'selected' : '' }}" data-mode="cash">
                                <div class="oi-mode-check"><i class="fas fa-check"></i></div>
                                <span class="oi-mode-icon">💵</span>
                                <span class="oi-mode-label">Cash</span>
                            </div>
                            <div class="oi-mode-card {{ $oldMode === 'bank' ? 'selected' : '' }}" data-mode="bank">
                                <div class="oi-mode-check"><i class="fas fa-check"></i></div>
                                <span class="oi-mode-icon">🏦</span>
                                <span class="oi-mode-label">Bank Transfer</span>
                            </div>
                            <div class="oi-mode-card {{ $oldMode === 'mobile_banking' ? 'selected' : '' }}" data-mode="mobile_banking">
                                <div class="oi-mode-check"><i class="fas fa-check"></i></div>
                                <span class="oi-mode-icon">📱</span>
                                <span class="oi-mode-label">Mobile Banking</span>
                            </div>
                            <div class="oi-mode-card {{ $oldMode === 'cheque' ? 'selected' : '' }}" data-mode="cheque">
                                <div class="oi-mode-check"><i class="fas fa-check"></i></div>
                                <span class="oi-mode-icon">📝</span>
                                <span class="oi-mode-label">Cheque</span>
                            </div>
                        </div>
                        <input type="hidden" id="payment_mode" name="payment_mode" value="{{ $oldMode }}" required>
                        @error('payment_mode') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-lg-6" id="bank_section" style="display:{{ $oldMode === 'bank' || $oldMode === 'cheque' ? 'block' : 'none' }};">
                        <div class="oi-field-group">
                            <label for="bank_id">Select Bank <span class="oi-required">*</span></label>
                            <select id="bank_id" name="bank_id"
                                    class="form-select select2 @error('bank_id') is-invalid @enderror">
                                <option value="">Choose bank account</option>
                                @foreach ($banks as $bk)
                                    <option value="{{ $bk->id }}"
                                        {{ (string) $oldBank === (string) $bk->id ? 'selected' : '' }}>
                                        {{ $bk->bank_name }}@if (!empty($bk->account_number)) — {{ $bk->account_number }}@endif
                                    </option>
                                @endforeach
                            </select>
                            @error('bank_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            <div class="oi-field-hint"><i class="fas fa-info-circle"></i> Required for bank transfer and cheque modes</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Step 4: GL Accounting Preview --}}
        <div class="oi-section-card" data-section="4">
            <div class="oi-section-header">
                <div class="oi-section-icon" style="background:linear-gradient(135deg,#0f172a,#334155);">
                    <i class="fas fa-scale-balanced"></i>
                </div>
                <h2>GL Accounting Preview</h2>
                <span class="oi-section-badge bg-dark-subtle text-dark">
                    <i class="fas fa-check-double me-1"></i>Review
                </span>
            </div>
            <div class="oi-section-body">
                <div class="oi-gl-preview" id="glPreviewArea">
                    <div class="oi-gl-empty" id="glEmpty">
                        <i class="fas fa-scale-balanced"></i>
                        <div class="small">Enter an amount and select a ledger to preview the GL journal entry</div>
                    </div>
                    <div id="glEntries" style="display:none;">
                        <div class="oi-gl-entry oi-gl-debit">
                            <div>
                                <span class="oi-gl-label" id="glDebitLabel">Dr Cash in Hand</span>
                                <div class="small text-muted" id="glDebitSub">Cash/Bank receives money</div>
                            </div>
                            <span class="oi-gl-amount" id="glDebitAmount">Tk 0.00</span>
                        </div>
                        <div class="oi-gl-entry oi-gl-credit">
                            <div>
                                <span class="oi-gl-label" id="glCreditLabel">Cr Other Income</span>
                                <div class="small text-muted" id="glCreditSub">Income ledger records the revenue</div>
                            </div>
                            <span class="oi-gl-amount" id="glCreditAmount">Tk 0.00</span>
                        </div>
                        <div class="oi-gl-total-bar">
                            <span><i class="fas fa-check-circle me-1"></i>Net Effect</span>
                            <span id="glNetEffect">Balanced</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Submit Bar --}}
        <div class="oi-submit-bar">
            <div class="oi-submit-hint">
                <i class="fas fa-shield-halved me-1"></i> GL posts immediately on save — double-entry balanced
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.other-incomes.index') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
                <button type="submit" class="oi-btn-submit" id="submitBtn">
                    <i class="fas fa-floppy-disk me-1"></i>
                    <span id="submitLabel">Save Income</span>
                </button>
            </div>
        </div>
    </form>
</div>

{{-- Boot config for OtherIncome.js --}}
<script>
    window.OI_BOOT = {
        baseUrl: '{{ url("/") }}/',
        csrf_token: '{{ csrf_token() }}',
        routes: {
            'index': '{{ route("admin.other-incomes.index") }}',
            'show': '{{ route("admin.other-incomes.show", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'reverse': '{{ route("admin.other-incomes.reverse", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'slip': '{{ route("admin.other-incomes.slip", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
        },
    };
</script>

@push('scripts')
<script src="/assets/js/OtherIncome.js?v={{ filemtime(public_path('assets/js/OtherIncome.js')) }}"></script>
<script>
$(function () {
    var $form        = $('#otherIncomeForm');
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

    // ── Payment mode cards ──
    var $modeCards = $('.oi-mode-card');
    $modeCards.on('click', function () {
        var mode = $(this).data('mode');
        $modeCards.removeClass('selected');
        $(this).addClass('selected');
        $modeInput.val(mode);
        syncBankVisibility();
        updateGLPreview();
        updateStepProgress();
    });

    // ── Bank visibility ──
    function syncBankVisibility() {
        var mode = $modeInput.val();
        if (mode === 'bank' || mode === 'cheque') {
            $bankSection.show().css('animation', 'fadeIn 0.2s');
            $bankId.prop('required', mode === 'bank');
        } else {
            $bankSection.hide();
            $bankId.prop('required', false).val('').trigger('change');
        }
    }

    // ── Step progress ──
    function updateStepProgress() {
        var hasDate = $('#income_date').val();
        var hasBranch = $('#branch_id').val();
        var hasAmount = parseFloat($amount.val()) > 0;
        var hasLedger = $ledgerId.val();
        var hasMode = $modeInput.val();

        var $steps = $('.oi-step-item');
        $steps.removeClass('active done');

        // Step 1: Income Info
        if (hasDate && hasBranch) {
            $steps.filter('[data-step="1"]').addClass('done');
            $steps.filter('[data-step="2"]').addClass('active');
        } else {
            $steps.filter('[data-step="1"]').addClass('active');
            return;
        }

        // Step 2: Amount & Account
        if (hasAmount && hasLedger) {
            $steps.filter('[data-step="2"]').addClass('done');
            $steps.filter('[data-step="3"]').addClass('active');
        } else {
            $steps.filter('[data-step="2"]').addClass('active');
            return;
        }

        // Step 3: Payment
        if (hasMode) {
            $steps.filter('[data-step="3"]').addClass('done');
            $steps.filter('[data-step="4"]').addClass('active');
        }
    }

    // ── GL Preview ──
    function updateGLPreview() {
        var amount = parseFloat($amount.val()) || 0;
        var mode = $modeInput.val();
        var ledgerName = $ledgerId.find('option:selected').text();
        var bankName = $bankId.find('option:selected').text();

        // Update amount preview
        $amountPreview.text('Tk ' + numberFormat(amount));

        if (amount <= 0) {
            $glEmpty.show();
            $glEntries.hide();
            return;
        }

        $glEmpty.hide();
        $glEntries.show();

        var drLabel = 'Cash in Hand';
        var drSub = 'Cash balance increases';
        var bankBookText = 'No change';

        if (mode === 'bank') {
            drLabel = (bankName && bankName !== 'Choose bank account') ? 'Bank — ' + bankName : 'Bank Account';
            drSub = 'Bank balance increases';
            bankBookText = 'Increase';
        } else if (mode === 'cheque') {
            drLabel = (bankName && bankName !== 'Choose bank account') ? 'Bank — ' + bankName : 'Bank Account';
            drSub = 'Bank balance increases (on clearance)';
            bankBookText = 'Increase (on clearance)';
        } else if (mode === 'mobile_banking') {
            drLabel = 'Mobile Banking';
            drSub = 'Mobile balance increases';
            bankBookText = 'Increase';
        }

        var crLabel = (ledgerName && ledgerName !== 'Select income ledger from Chart of Accounts') ? ledgerName : 'Other Income';

        $glDebitLabel.text('Dr ' + drLabel);
        $glDebitSub.text(drSub);
        $glDebitAmount.text('Tk ' + numberFormat(amount));

        $glCreditLabel.text('Cr ' + crLabel);
        $glCreditSub.text('Income ledger records the revenue');
        $glCreditAmount.text('Tk ' + numberFormat(amount));

        $glNetEffect.text('Balanced');

        $glRuleLabel.text('Dr Cash/Bank · Cr Income');
        $subLedgerLabel.text('None — CoA only');
        $bankBookLabel.text(bankBookText);
    }

    function numberFormat(num) {
        return parseFloat(num).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // ── Event listeners ──
    $amount.on('input', function () { updateGLPreview(); updateStepProgress(); });
    $ledgerId.on('change', function () { updateGLPreview(); updateStepProgress(); });
    $bankId.on('change', updateGLPreview);
    $('#income_date, #branch_id').on('change', updateStepProgress);

    // ── Form submission ──
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
            Swal.fire({ icon: 'warning', title: 'Ledger required', text: 'Please select an income ledger.' });
            return;
        }

        if (!mode) {
            Swal.fire({ icon: 'warning', title: 'Payment mode required', text: 'Please select how the income was received.' });
            return;
        }

        var modeLabel = mode === 'bank' || mode === 'cheque' ? 'Bank' : mode === 'mobile_banking' ? 'Mobile' : 'Cash';

        Swal.fire({
            icon: 'question',
            title: 'Record this other income?',
            html: '<div style="text-align:left;">' +
                  '<p class="mb-1"><strong>Amount:</strong> Tk ' + numberFormat(amount) + '</p>' +
                  '<p class="mb-1"><strong>Payment:</strong> ' + modeLabel + '</p>' +
                  '<p class="mb-0 text-muted small">GL entry: Dr ' + modeLabel + ', Cr ' + ledgerName + '</p>' +
                  '</div>',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check"></i> Save Income',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#16a34a',
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
                                text: resp.message || 'Other income recorded successfully.',
                                timer: 2000,
                                showConfirmButton: false
                            }).then(function () {
                                var redirectUrl = resp.redirect_url || resp.redirect || '{{ route("admin.other-incomes.index") }}';
                                window.location.href = redirectUrl;
                            });
                        } else {
                            Swal.fire('Error', resp.message || 'Failed to save.', 'error');
                            $btn.prop('disabled', false).html('<i class="fas fa-floppy-disk me-1"></i> <span id="submitLabel">Save Income</span>');
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
                        $btn.prop('disabled', false).html('<i class="fas fa-floppy-disk me-1"></i> <span id="submitLabel">Save Income</span>');
                    }
                });
            }
        });
    });

    // ── Initialize ──
    syncBankVisibility();
    updateGLPreview();
    updateStepProgress();
});
</script>
@endpush
@endsection
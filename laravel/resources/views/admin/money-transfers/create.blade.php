@extends('layouts.admin')

@section('title', $title)

@push('styles')
<link rel="stylesheet" href="{{ asset('assets/css/money-transfer-theme.css') }}">
<style>
/* ─────────────────────────────────────────────
   Money Transfer Create — Premium Form
   ───────────────────────────────────────────── */

/* Hero Banner */
.mt-hero {
    background: linear-gradient(135deg, #0d9488 0%, #059669 40%, #047857 100%);
    border-radius: 16px;
    padding: 2rem 2.5rem;
    color: #fff;
    margin-bottom: 1.75rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(5, 150, 105, 0.25);
}
.mt-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 320px;
    height: 320px;
    border-radius: 50%;
    background: rgba(255,255,255,0.06);
    pointer-events: none;
}
.mt-hero::after {
    content: '';
    position: absolute;
    bottom: -30%;
    left: 15%;
    width: 200px;
    height: 200px;
    border-radius: 50%;
    background: rgba(255,255,255,0.04);
    pointer-events: none;
}
.mt-hero-content {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1.5rem;
    flex-wrap: wrap;
}
.mt-hero-left { flex: 1; min-width: 250px; }
.mt-hero-right { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: flex-start; }
.mt-hero-icon {
    width: 52px;
    height: 52px;
    background: rgba(255,255,255,0.15);
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1rem;
    font-size: 1.5rem;
    backdrop-filter: blur(4px);
}
.mt-hero h1 {
    font-size: 1.6rem;
    font-weight: 700;
    margin: 0 0 0.25rem;
    letter-spacing: -0.02em;
}
.mt-hero .mt-subtitle {
    font-size: 0.85rem;
    opacity: 0.8;
    margin: 0;
}
.mt-hero .mt-gl-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.25);
    padding: 0.3rem 0.85rem;
    border-radius: 8px;
    font-size: 0.78rem;
    font-weight: 600;
    margin-top: 0.75rem;
    backdrop-filter: blur(4px);
}
.mt-hero-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.55rem 1.15rem;
    border-radius: 10px;
    font-size: 0.82rem;
    font-weight: 600;
    border: 1.5px solid rgba(255,255,255,0.3);
    background: rgba(255,255,255,0.1);
    color: #fff;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    backdrop-filter: blur(4px);
}
.mt-hero-btn:hover {
    background: rgba(255,255,255,0.22);
    border-color: rgba(255,255,255,0.5);
    transform: translateY(-1px);
}

/* Info Banner */
.mt-info-banner {
    background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
    border: 1.5px solid #a7f3d0;
    border-radius: 12px;
    padding: 1rem 1.5rem;
    margin-bottom: 1.75rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    color: #065f46;
}
.mt-info-banner i { font-size: 1.2rem; color: #059669; }
.mt-info-banner p { margin: 0; font-size: 0.88rem; }

/* Section Card */
.mt-section-card {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06), 0 4px 16px rgba(0,0,0,0.04);
    margin-bottom: 1.5rem;
    overflow: hidden;
    border: 1px solid #f1f5f9;
    transition: box-shadow 0.2s;
}
.mt-section-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.08), 0 8px 24px rgba(0,0,0,0.06);
}
.mt-section-header {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 1.15rem 1.5rem;
    border-bottom: 1px solid #f1f5f9;
    font-weight: 700;
    font-size: 0.95rem;
    color: #1e293b;
}
.mt-section-header .mt-header-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}
.mt-section-header .mt-header-icon.teal {
    background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
    color: #059669;
}
.mt-section-header .mt-header-icon.amber {
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    color: #b45309;
}
.mt-section-header .mt-header-icon.blue {
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    color: #1d4ed8;
}
.mt-section-body {
    padding: 1.5rem;
}

/* Form Labels */
.mt-form-label {
    font-size: 0.78rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #64748b;
    margin-bottom: 0.4rem;
    display: block;
}
.mt-form-label .required {
    color: #dc2626;
    margin-left: 0.15rem;
}

/* Form Controls */
.mt-form-control {
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    padding: 0.6rem 0.85rem;
    font-size: 0.88rem;
    color: #334155;
    transition: border-color 0.2s, box-shadow 0.2s;
    width: 100%;
}
.mt-form-control:focus {
    outline: none;
    border-color: #059669;
    box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15);
}
.mt-form-control.is-invalid {
    border-color: #dc2626;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
}

/* Form Grid */
.mt-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.25rem;
}
.mt-form-grid .mt-form-group {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
}
.mt-form-grid .mt-form-group.full-width {
    grid-column: 1 / -1;
}

/* Transfer Type Selector */
.mt-type-selector {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0.75rem;
    margin-bottom: 1.5rem;
}
.mt-type-option {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.5rem;
    padding: 1rem 0.75rem;
    border: 2px solid #e2e8f0;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.2s;
    text-align: center;
    background: #fff;
}
.mt-type-option:hover {
    border-color: #059669;
    background: #f0fdf4;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.1);
}
.mt-type-option.active {
    border-color: #059669;
    background: linear-gradient(135deg, #ecfdf5, #f0fdf4);
    box-shadow: 0 4px 16px rgba(5, 150, 105, 0.15);
}
.mt-type-option input[type="radio"] {
    position: absolute;
    opacity: 0;
    pointer-events: none;
}
.mt-type-option .mt-type-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    background: #f1f5f9;
    color: #64748b;
    transition: all 0.2s;
}
.mt-type-option.active .mt-type-icon {
    background: #059669;
    color: #fff;
}
.mt-type-option .mt-type-label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #475569;
    transition: color 0.2s;
}
.mt-type-option.active .mt-type-label {
    color: #059669;
}
.mt-type-option .mt-type-gl {
    font-size: 0.7rem;
    color: #94a3b8;
    font-weight: 500;
}
.mt-type-option.active .mt-type-gl {
    color: #047857;
}

/* Direction Flow */
.mt-direction-flow {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1.5rem;
    padding: 1rem 1.5rem;
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    border-radius: 12px;
    border: 1px solid #e2e8f0;
}
.mt-direction-flow .mt-flow-box {
    flex: 1;
    text-align: center;
    padding: 0.75rem;
    border-radius: 10px;
    background: #fff;
    border: 1.5px solid #e2e8f0;
}
.mt-direction-flow .mt-flow-box .mt-flow-label {
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #94a3b8;
}
.mt-direction-flow .mt-flow-box .mt-flow-value {
    font-size: 0.9rem;
    font-weight: 700;
    color: #1e293b;
    margin-top: 0.25rem;
}
.mt-direction-flow .mt-flow-arrow {
    font-size: 1.5rem;
    color: #059669;
    flex-shrink: 0;
}

/* Amount Card */
.mt-amount-card {
    background: linear-gradient(135deg, #ecfdf5, #f0fdf4);
    border: 1.5px solid #a7f3d0;
    border-radius: 14px;
    padding: 1.5rem;
    text-align: center;
    position: relative;
    overflow: hidden;
}
.mt-amount-card::before {
    content: '';
    position: absolute;
    top: -20px;
    right: -20px;
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: rgba(5, 150, 105, 0.06);
}
.mt-amount-card .mt-amount-label {
    font-size: 0.78rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #059669;
    margin-bottom: 0.5rem;
}
.mt-amount-card .mt-amount-input {
    font-size: 2rem;
    font-weight: 800;
    color: #047857;
    letter-spacing: -0.02em;
    text-align: center;
    border: none;
    background: transparent;
    width: 100%;
    outline: none;
}
.mt-amount-card .mt-amount-input::placeholder {
    color: #a7f3d0;
}
.mt-amount-card .mt-amount-sub {
    font-size: 0.78rem;
    color: #059669;
    margin-top: 0.35rem;
}

/* Layout grid for side-by-side cards */
.mt-layout-row {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 1.5rem;
}
@media (max-width: 900px) {
    .mt-layout-row { grid-template-columns: 1fr; }
    .mt-type-selector { grid-template-columns: repeat(2, 1fr); }
}

/* GL Preview Table */
.mt-gl-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid #e2e8f0;
}
.mt-gl-table thead th {
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    padding: 0.75rem 1rem;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #64748b;
    border-bottom: 2px solid #e2e8f0;
    text-align: left;
}
.mt-gl-table tbody td {
    padding: 0.75rem 1rem;
    font-size: 0.88rem;
    color: #334155;
    border-bottom: 1px solid #f1f5f9;
}
.mt-gl-table tbody tr:last-child td { border-bottom: none; }
.mt-gl-table .dr { color: #059669; font-weight: 700; }
.mt-gl-table .cr { color: #dc2626; font-weight: 700; }
.mt-gl-table .total-row td {
    border-top: 2px solid #e2e8f0;
    font-weight: 700;
    background: #fafafa;
}

/* GL description */
.mt-gl-desc {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1rem;
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    border-radius: 10px;
    margin-bottom: 1rem;
    font-size: 0.82rem;
    color: #92400e;
    font-weight: 500;
}
.mt-gl-desc i { color: #b45309; }

/* GL Info Footer */
.mt-gl-info-footer {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    padding: 1rem 1.5rem;
    border-top: 1px solid #f1f5f9;
    background: #fafafa;
}
.mt-gl-info-item .mt-gl-info-label {
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #94a3b8;
}
.mt-gl-info-item .mt-gl-info-value {
    font-size: 0.85rem;
    font-weight: 700;
    color: #1e293b;
    margin-top: 0.15rem;
}

/* Sticky Submit Bar */
.mt-submit-bar {
    position: sticky;
    bottom: 0;
    background: rgba(255,255,255,0.95);
    backdrop-filter: blur(12px);
    border-top: 1px solid #e2e8f0;
    padding: 1rem 1.5rem;
    margin: 1.5rem -1.5rem -1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    z-index: 10;
    border-radius: 0 0 14px 14px;
}
.mt-submit-bar .mt-submit-left {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}
.mt-submit-bar .mt-submit-right {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
}
.mt-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.6rem 1.25rem;
    border-radius: 10px;
    font-size: 0.85rem;
    font-weight: 600;
    border: 1.5px solid #e2e8f0;
    background: #fff;
    color: #475569;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
}
.mt-btn:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    transform: translateY(-1px);
}
.mt-btn-primary {
    background: linear-gradient(135deg, #059669, #047857);
    color: #fff;
    border-color: #059669;
}
.mt-btn-primary:hover {
    background: linear-gradient(135deg, #047857, #065f46);
    border-color: #047857;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
}
.mt-btn-danger {
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    color: #fff;
    border-color: #dc2626;
}

/* Responsive */
@media (max-width: 768px) {
    .mt-hero { padding: 1.5rem; }
    .mt-hero h1 { font-size: 1.3rem; }
    .mt-hero-content { flex-direction: column; }
    .mt-form-grid { grid-template-columns: 1fr; }
    .mt-layout-row { grid-template-columns: 1fr; }
    .mt-type-selector { grid-template-columns: repeat(2, 1fr); }
    .mt-gl-info-footer { grid-template-columns: 1fr; }
    .mt-direction-flow { flex-direction: column; }
    .mt-direction-flow .mt-flow-arrow { transform: rotate(90deg); }
}
</style>
@endpush

@section('content')
<?php
    $today      = now()->format('Y-m-d');
    $oldType    = old('transfer_type', 'cash_to_bank');
    $oldFromBr  = old('from_branch_id', session('branch_id'));
    $oldToBr    = old('to_branch_id');
    $oldFromBank = old('from_bank_id');
    $oldToBank  = old('to_bank_id');
    $oldAmt     = old('amount');
    $oldDate    = old('transfer_date', $today);
    $oldNotes   = old('notes');

    $typeConfig = [
        'cash_to_bank' => [
            'icon' => 'fa-university',
            'label' => 'Cash to Bank',
            'gl_info' => 'Dr Bank · Cr Cash',
            'hint' => 'Deposit cash into a bank account. Debits bank, credits cash in hand.',
            'show_from_bank' => false,
            'show_to_bank' => true,
            'cash_ledger' => 'Credit (reduce cash in hand)',
            'bank_book' => 'Debit (increase bank balance)',
            'dr_label' => 'Bank Account',
            'cr_label' => 'Cash in Hand',
        ],
        'bank_to_cash' => [
            'icon' => 'fa-money-bill',
            'label' => 'Bank to Cash',
            'gl_info' => 'Dr Cash · Cr Bank',
            'hint' => 'Withdraw cash from a bank account. Debits cash in hand, credits bank.',
            'show_from_bank' => true,
            'show_to_bank' => false,
            'cash_ledger' => 'Debit (increase cash in hand)',
            'bank_book' => 'Credit (decrease bank balance)',
            'dr_label' => 'Cash in Hand',
            'cr_label' => 'Bank Account',
        ],
        'cash_to_cash' => [
            'icon' => 'fa-money-bill-transfer',
            'label' => 'Cash to Cash',
            'gl_info' => 'Dr Cash (to branch) · Cr Cash (from branch)',
            'hint' => 'Transfer cash between branches. Debits cash at destination, credits cash at source.',
            'show_from_bank' => false,
            'show_to_bank' => false,
            'cash_ledger' => 'Dr at destination, Cr at source',
            'bank_book' => 'No change',
            'dr_label' => 'Cash in Hand (To Branch)',
            'cr_label' => 'Cash in Hand (From Branch)',
        ],
        'bank_to_bank' => [
            'icon' => 'fa-exchange-alt',
            'label' => 'Bank to Bank',
            'gl_info' => 'Dr Bank (to) · Cr Bank (from)',
            'hint' => 'Transfer between bank accounts. Debits destination bank, credits source bank.',
            'show_from_bank' => true,
            'show_to_bank' => true,
            'cash_ledger' => 'No change',
            'bank_book' => 'Dr at destination, Cr at source',
            'dr_label' => 'Bank Account (To)',
            'cr_label' => 'Bank Account (From)',
        ],
    ];
    $cfg = $typeConfig[$oldType] ?? $typeConfig['cash_to_bank'];
?>

<div class="mt-page-wrapper">

    {{-- ─── Hero Banner ──────────────────────────── --}}
    <div class="mt-hero">
        <div class="mt-hero-content">
            <div class="mt-hero-left">
                <div class="mt-hero-icon">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <h1>{{ $title }}</h1>
                <p class="mt-subtitle">Record a transfer between cash and bank accounts</p>
                <div class="mt-gl-badge" id="heroGlBadge">
                    <i class="fas fa-balance-scale"></i>
                    <span id="heroGl">{{ $cfg['gl_info'] }}</span>
                </div>
            </div>
            <div class="mt-hero-right">
                <a href="{{ route('admin.money-transfers.index') }}" class="mt-hero-btn">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
        </div>
    </div>

    {{-- ─── Info Banner ──────────────────────────── --}}
    <div class="mt-info-banner">
        <i class="fas fa-circle-info"></i>
        <p><strong>Transfers post immediately on save.</strong> GL is balanced (Dr/Cr depending on type), cash and bank ledgers are updated, and the bank book balance is synced.</p>
    </div>

    <form method="POST" action="{{ route('admin.money-transfers.store') }}" id="moneyTransferForm">
        @csrf

        {{-- ─── Transfer Type Selector ──────────────── --}}
        <div class="mt-section-card">
            <div class="mt-section-header">
                <span class="mt-header-icon teal"><i class="fas fa-sliders"></i></span>
                Select Transfer Type
            </div>
            <div class="mt-section-body">
                <div class="mt-type-selector" id="typeSelector">
                    @foreach($typeConfig as $type => $tc)
                    <label class="mt-type-option {{ $oldType === $type ? 'active' : '' }}" data-type="{{ $type }}">
                        <input type="radio" name="transfer_type" value="{{ $type }}" {{ $oldType === $type ? 'checked' : '' }}>
                        <div class="mt-type-icon"><i class="fas {{ $tc['icon'] }}"></i></div>
                        <div class="mt-type-label">{{ $tc['label'] }}</div>
                        <div class="mt-type-gl">{{ $tc['gl_info'] }}</div>
                    </label>
                    @endforeach
                </div>
                <div class="mt-gl-desc" id="typeHintBanner">
                    <i class="fas fa-info-circle"></i>
                    <span id="typeHintText">{{ $cfg['hint'] }}</span>
                </div>
            </div>
        </div>

        {{-- ─── Transfer Details ────────────────────── --}}
        <div class="mt-layout-row">
            <div>

                {{-- Direction Flow --}}
                <div class="mt-direction-flow" id="directionFlow">
                    <div class="mt-flow-box" id="fromFlowBox">
                        <div class="mt-flow-label">From</div>
                        <div class="mt-flow-value" id="fromFlowValue">{{ $cfg['show_from_bank'] ? 'Bank' : 'Cash' }}</div>
                    </div>
                    <div class="mt-flow-arrow">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                    <div class="mt-flow-box" id="toFlowBox">
                        <div class="mt-flow-label">To</div>
                        <div class="mt-flow-value" id="toFlowValue">{{ $cfg['show_to_bank'] ? 'Bank' : 'Cash' }}</div>
                    </div>
                </div>

                {{-- Source & Destination Card --}}
                <div class="mt-section-card">
                    <div class="mt-section-header">
                        <span class="mt-header-icon teal"><i class="fas fa-route"></i></span>
                        Source & Destination
                    </div>
                    <div class="mt-section-body">
                        <div class="mt-form-grid">
                            <div class="mt-form-group">
                                <label class="mt-form-label" for="from_branch_id">
                                    From Branch <span class="required">*</span>
                                </label>
                                <select id="from_branch_id" name="from_branch_id"
                                        class="mt-form-control select2 @error('from_branch_id') is-invalid @enderror" required>
                                    <option value="">Select branch</option>
                                    @foreach ($branches as $b)
                                        <option value="{{ $b->id }}"
                                            {{ (string) $oldFromBr === (string) $b->id ? 'selected' : '' }}>
                                            {{ $b->branch_code }} — {{ $b->branch_name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('from_branch_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>

                            <div class="mt-form-group">
                                <label class="mt-form-label" for="to_branch_id">
                                    To Branch <span class="required">*</span>
                                </label>
                                <select id="to_branch_id" name="to_branch_id"
                                        class="mt-form-control select2 @error('to_branch_id') is-invalid @enderror" required>
                                    <option value="">Select branch</option>
                                    @foreach ($branches as $b)
                                        <option value="{{ $b->id }}"
                                            {{ (string) $oldToBr === (string) $b->id ? 'selected' : '' }}>
                                            {{ $b->branch_code }} — {{ $b->branch_name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('to_branch_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>

                            <div class="mt-form-group" id="from_bank_section" style="display:{{ $cfg['show_from_bank'] ? 'flex' : 'none' }};">
                                <label class="mt-form-label" for="from_bank_id">
                                    From Bank <span class="required">*</span>
                                </label>
                                <select id="from_bank_id" name="from_bank_id"
                                        class="mt-form-control select2 @error('from_bank_id') is-invalid @enderror">
                                    <option value="">Select bank</option>
                                    @foreach ($banks as $bk)
                                        <option value="{{ $bk->id }}"
                                            {{ (string) $oldFromBank === (string) $bk->id ? 'selected' : '' }}>
                                            {{ $bk->bank_code }} — {{ $bk->bank_name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('from_bank_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>

                            <div class="mt-form-group" id="to_bank_section" style="display:{{ $cfg['show_to_bank'] ? 'flex' : 'none' }};">
                                <label class="mt-form-label" for="to_bank_id">
                                    To Bank <span class="required">*</span>
                                </label>
                                <select id="to_bank_id" name="to_bank_id"
                                        class="mt-form-control select2 @error('to_bank_id') is-invalid @enderror">
                                    <option value="">Select bank</option>
                                    @foreach ($banks as $bk)
                                        <option value="{{ $bk->id }}"
                                            {{ (string) $oldToBank === (string) $bk->id ? 'selected' : '' }}>
                                            {{ $bk->bank_code }} — {{ $bk->bank_name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('to_bank_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>

                            <div class="mt-form-group">
                                <label class="mt-form-label" for="transfer_date">
                                    Transfer Date <span class="required">*</span>
                                </label>
                                <input type="date" id="transfer_date" name="transfer_date"
                                       class="mt-form-control @error('transfer_date') is-invalid @enderror"
                                       required value="{{ $oldDate }}">
                                @error('transfer_date') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Notes Card --}}
                <div class="mt-section-card">
                    <div class="mt-section-header">
                        <span class="mt-header-icon amber"><i class="fas fa-sticky-note"></i></span>
                        Notes
                    </div>
                    <div class="mt-section-body">
                        <textarea id="notes" name="notes" rows="3" class="mt-form-control"
                                  placeholder="Internal notes — source, remarks, etc.">{{ $oldNotes }}</textarea>
                        @error('notes') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>

                {{-- GL Journal Preview Card --}}
                <div class="mt-section-card">
                    <div class="mt-section-header">
                        <span class="mt-header-icon blue"><i class="fas fa-book"></i></span>
                        GL Journal Preview
                        <span style="margin-left:auto; font-size:0.75rem; font-weight:600; color:#059669; background:#ecfdf5; padding:0.2rem 0.6rem; border-radius:6px;" id="glBalanceBadge">
                            <i class="fas fa-check me-1"></i>Balanced
                        </span>
                    </div>
                    <div class="mt-section-body">
                        <div class="mt-gl-desc">
                            <i class="fas fa-info-circle"></i>
                            <span id="glRuleLabel">{{ $cfg['gl_info'] }}</span> — This preview updates live as you fill the form.
                        </div>

                        <table class="mt-gl-table" id="glPreviewTable">
                            <thead>
                                <tr>
                                    <th style="width:5%;">#</th>
                                    <th style="width:40%;">Ledger Account</th>
                                    <th style="text-align:right; width:25%;">Debit (Tk)</th>
                                    <th style="text-align:right; width:25%;">Credit (Tk)</th>
                                </tr>
                            </thead>
                            <tbody id="glPreviewBody">
                                {{-- Rows injected by JS --}}
                            </tbody>
                            <tfoot>
                                <tr class="total-row">
                                    <td colspan="2" style="text-align:right;">Total</td>
                                    <td style="text-align:right;" id="glTotalDebit">0.00</td>
                                    <td style="text-align:right;" id="glTotalCredit">0.00</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="mt-gl-info-footer">
                        <div class="mt-gl-info-item">
                            <div class="mt-gl-info-label">GL Rule</div>
                            <div class="mt-gl-info-value" id="glRuleFooter">{{ $cfg['gl_info'] }}</div>
                        </div>
                        <div class="mt-gl-info-item">
                            <div class="mt-gl-info-label">Cash Ledger</div>
                            <div class="mt-gl-info-value" id="cashLedgerLabel">{{ $cfg['cash_ledger'] }}</div>
                        </div>
                        <div class="mt-gl-info-item">
                            <div class="mt-gl-info-label">Bank Book</div>
                            <div class="mt-gl-info-value" id="bankBookLabel">{{ $cfg['bank_book'] }}</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ─── Right Column: Amount Card ──────────── --}}
            <div>
                <div class="mt-amount-card">
                    <div class="mt-amount-label">Transfer Amount</div>
                    <input type="number" id="amount" name="amount"
                           class="mt-amount-input @error('amount') is-invalid @enderror"
                           min="0.01" step="0.01" required
                           value="{{ $oldAmt }}"
                           placeholder="0.00">
                    @error('amount') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    <div class="mt-amount-sub" id="amountSub">
                        @if(in_array($oldType, ['cash_to_bank', 'bank_to_bank']))
                            Debit (increase bank balance)
                        @else
                            Credit (decrease bank balance)
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- ─── Sticky Submit Bar ────────────────────── --}}
        <div class="mt-section-card" style="margin-top:1.5rem;">
            <div class="mt-submit-bar">
                <div class="mt-submit-left">
                    <a href="{{ route('admin.money-transfers.index') }}" class="mt-btn">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
                <div class="mt-submit-right">
                    <button type="submit" class="mt-btn mt-btn-primary" id="submitBtn">
                        <i class="fas fa-check"></i>
                        <span id="submitLabel">Confirm Transfer</span>
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
$(function () {
    var $form        = $('#moneyTransferForm');
    var $typeRadios  = $('input[name="transfer_type"]');
    var $fromBankSec = $('#from_bank_section');
    var $toBankSec   = $('#to_bank_section');
    var $fromBankId  = $('#from_bank_id');
    var $toBankId    = $('#to_bank_id');
    var $amount      = $('#amount');
    var $fromBranch  = $('#from_branch_id');
    var $toBranch    = $('#to_branch_id');

    // GL preview elements
    var $glBody      = $('#glPreviewBody');
    var $glTotalDr   = $('#glTotalDebit');
    var $glTotalCr   = $('#glTotalCredit');
    var $glBadge     = $('#glBalanceBadge');
    var $glRuleLabel = $('#glRuleLabel');
    var $glRuleFooter= $('#glRuleFooter');
    var $cashLedgerLabel = $('#cashLedgerLabel');
    var $bankBookLabel   = $('#bankBookLabel');
    var $heroGl      = $('#heroGl');
    var $typeHint    = $('#typeHintText');
    var $fromFlowValue = $('#fromFlowValue');
    var $toFlowValue   = $('#toFlowValue');
    var $amountSub   = $('#amountSub');

    // Type-specific configurations
    var typeConfigs = {
        cash_to_bank: {
            label: 'Cash to Bank',
            gl_info: 'Dr Bank · Cr Cash',
            hint: 'Deposit cash into a bank account. Debits bank, credits cash in hand.',
            show_from_bank: false,
            show_to_bank: true,
            cash_ledger: 'Credit (reduce cash in hand)',
            bank_book: 'Debit (increase bank balance)',
            dr_label: 'Bank Account',
            cr_label: 'Cash in Hand',
            from_flow: 'Cash',
            to_flow: 'Bank',
        },
        bank_to_cash: {
            label: 'Bank to Cash',
            gl_info: 'Dr Cash · Cr Bank',
            hint: 'Withdraw cash from a bank account. Debits cash in hand, credits bank.',
            show_from_bank: true,
            show_to_bank: false,
            cash_ledger: 'Debit (increase cash in hand)',
            bank_book: 'Credit (decrease bank balance)',
            dr_label: 'Cash in Hand',
            cr_label: 'Bank Account',
            from_flow: 'Bank',
            to_flow: 'Cash',
        },
        cash_to_cash: {
            label: 'Cash to Cash',
            gl_info: 'Dr Cash (to branch) · Cr Cash (from branch)',
            hint: 'Transfer cash between branches. Debits cash at destination, credits cash at source.',
            show_from_bank: false,
            show_to_bank: false,
            cash_ledger: 'Dr at destination, Cr at source',
            bank_book: 'No change',
            dr_label: 'Cash in Hand (To Branch)',
            cr_label: 'Cash in Hand (From Branch)',
            from_flow: 'Cash',
            to_flow: 'Cash',
        },
        bank_to_bank: {
            label: 'Bank to Bank',
            gl_info: 'Dr Bank (to) · Cr Bank (from)',
            hint: 'Transfer between bank accounts. Debits destination bank, credits source bank.',
            show_from_bank: true,
            show_to_bank: true,
            cash_ledger: 'No change',
            bank_book: 'Dr at destination, Cr at source',
            dr_label: 'Bank Account (To)',
            cr_label: 'Bank Account (From)',
            from_flow: 'Bank',
            to_flow: 'Bank',
        },
    };

    // Type selector click handler
    $('.mt-type-option').on('click', function() {
        var type = $(this).data('type');
        $typeRadios.val([type]);
        applyTypeConfig(type);
    });

    function applyTypeConfig(type) {
        var cfg = typeConfigs[type] || typeConfigs.cash_to_bank;

        // Update active state
        $('.mt-type-option').removeClass('active');
        $('.mt-type-option[data-type="' + type + '"]').addClass('active');

        // Hero badge
        $heroGl.text(cfg.gl_info);

        // Type hint
        $typeHint.text(cfg.hint);

        // Direction flow
        $fromFlowValue.text(cfg.from_flow);
        $toFlowValue.text(cfg.to_flow);

        // GL preview labels
        $glRuleLabel.text(cfg.gl_info);
        $glRuleFooter.text(cfg.gl_info);
        $cashLedgerLabel.text(cfg.cash_ledger);
        $bankBookLabel.text(cfg.bank_book);

        // Amount subtitle
        $amountSub.text(cfg.bank_book);

        // Show/hide bank fields
        if (cfg.show_from_bank) {
            $fromBankSec.show();
            $fromBankId.prop('required', true);
        } else {
            $fromBankSec.hide();
            $fromBankId.prop('required', false);
            $fromBankId.val('');
        }

        if (cfg.show_to_bank) {
            $toBankSec.show();
            $toBankId.prop('required', true);
        } else {
            $toBankSec.hide();
            $toBankId.prop('required', false);
            $toBankId.val('');
        }

        // For bank_to_bank, validate that both banks differ
        if (type === 'bank_to_bank' && $fromBankId.val() && $toBankId.val() && $fromBankId.val() === $toBankId.val()) {
            $toBankId.val('').trigger('change');
        }

        // Update GL preview
        updateGLPreview();
    }

    function updateGLPreview() {
        var type = $('input[name="transfer_type"]:checked').val();
        var cfg = typeConfigs[type] || typeConfigs.cash_to_bank;
        var amount = parseFloat($amount.val()) || 0;

        $glBody.empty();

        if (amount <= 0) {
            $glBody.append(
                '<tr><td colspan="4" style="text-align:center; color:#94a3b8; padding:1.5rem;">' +
                '<i class="fas fa-info-circle" style="margin-right:0.35rem;"></i>Enter an amount to see the GL journal preview.' +
                '</td></tr>'
            );
            $glTotalDr.text('0.00');
            $glTotalCr.text('0.00');
            $glBadge.html('<i class="fas fa-check me-1"></i>Balanced').css({background:'#ecfdf5', color:'#059669'});
            return;
        }

        var fromBankName = $fromBankId.find('option:selected').text();
        var toBankName = $toBankId.find('option:selected').text();
        var fromBranchName = $fromBranch.find('option:selected').text();
        var toBranchName = $toBranch.find('option:selected').text();

        var drLabel = cfg.dr_label;
        var crLabel = cfg.cr_label;

        if (type === 'cash_to_bank') {
            drLabel = toBankName && toBankName !== 'Select bank' ? 'Bank — ' + toBankName : 'Bank Account';
            crLabel = 'Cash in Hand (' + (fromBranchName && fromBranchName !== 'Select branch' ? fromBranchName : 'From Branch') + ')';
        } else if (type === 'bank_to_cash') {
            drLabel = 'Cash in Hand (' + (toBranchName && toBranchName !== 'Select branch' ? toBranchName : 'To Branch') + ')';
            crLabel = fromBankName && fromBankName !== 'Select bank' ? 'Bank — ' + fromBankName : 'Bank Account';
        } else if (type === 'cash_to_cash') {
            drLabel = 'Cash in Hand (' + (toBranchName && toBranchName !== 'Select branch' ? toBranchName : 'To Branch') + ')';
            crLabel = 'Cash in Hand (' + (fromBranchName && fromBranchName !== 'Select branch' ? fromBranchName : 'From Branch') + ')';
        } else if (type === 'bank_to_bank') {
            drLabel = toBankName && toBankName !== 'Select bank' ? 'Bank — ' + toBankName : 'Bank Account (To)';
            crLabel = fromBankName && fromBankName !== 'Select bank' ? 'Bank — ' + fromBankName : 'Bank Account (From)';
        }

        // Debit row
        $glBody.append(
            '<tr>' +
            '<td>1</td>' +
            '<td><span style="font-weight:600;">' + drLabel + '</span> <span style="background:#ecfdf5; color:#059669; padding:0.15rem 0.5rem; border-radius:4px; font-size:0.75rem; font-weight:600; margin-left:0.35rem;">Dr</span></td>' +
            '<td style="text-align:right; font-weight:600; color:#059669;">' + numberFormat(amount) + '</td>' +
            '<td style="text-align:right; color:#94a3b8;">&mdash;</td>' +
            '</tr>'
        );

        // Credit row
        $glBody.append(
            '<tr>' +
            '<td>2</td>' +
            '<td><span style="font-weight:600;">' + crLabel + '</span> <span style="background:#fef2f2; color:#dc2626; padding:0.15rem 0.5rem; border-radius:4px; font-size:0.75rem; font-weight:600; margin-left:0.35rem;">Cr</span></td>' +
            '<td style="text-align:right; color:#94a3b8;">&mdash;</td>' +
            '<td style="text-align:right; font-weight:600; color:#dc2626;">' + numberFormat(amount) + '</td>' +
            '</tr>'
        );

        // Totals
        $glTotalDr.text(numberFormat(amount));
        $glTotalCr.text(numberFormat(amount));

        // Balance check
        $glBadge.html('<i class="fas fa-check me-1"></i>Balanced').css({background:'#ecfdf5', color:'#059669'});
    }

    function numberFormat(num) {
        return parseFloat(num).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // Event listeners
    $typeRadios.on('change', function () {
        applyTypeConfig($(this).val());
    });
    $amount.on('input', updateGLPreview);
    $fromBankId.on('change', function () {
        if ($('input[name="transfer_type"]:checked').val() === 'bank_to_bank' && $fromBankId.val() && $toBankId.val() && $fromBankId.val() === $toBankId.val()) {
            $toBankId.val('').trigger('change');
        }
        updateGLPreview();
    });
    $toBankId.on('change', function () {
        if ($('input[name="transfer_type"]:checked').val() === 'bank_to_bank' && $fromBankId.val() && $toBankId.val() && $fromBankId.val() === $toBankId.val()) {
            $fromBankId.val('').trigger('change');
        }
        updateGLPreview();
    });
    $fromBranch.on('change', updateGLPreview);
    $toBranch.on('change', updateGLPreview);

    // Form submission with AJAX
    $form.on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#submitBtn');
        $btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i> Processing...');

        $.ajax({
            url: $form.attr('action'),
            method: 'POST',
            data: $form.serialize(),
            dataType: 'json',
            success: function(resp) {
                if (resp.status === 'success') {
                    Swal.fire({
                        icon: 'success',
                        title: 'Transfer Recorded!',
                        text: resp.message || 'Money transfer recorded successfully.',
                        confirmButtonColor: '#059669',
                        timer: 2000,
                    }).then(() => {
                        window.location.href = resp.redirect_url || '{{ route("admin.money-transfers.index") }}';
                    });
                } else {
                    Swal.fire('Error', resp.message || 'Could not save transfer.', 'error');
                    $btn.prop('disabled', false).html('<i class="fas fa-check me-1"></i> Confirm Transfer');
                }
            },
            error: function(xhr) {
                var msg = 'Something went wrong. Please try again.';
                if (xhr.responseJSON) {
                    if (xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    } else if (xhr.responseJSON.errors) {
                        var errors = xhr.responseJSON.errors;
                        msg = Object.values(errors).flat().join('\n');
                    }
                }
                Swal.fire('Error', msg, 'error');
                $btn.prop('disabled', false).html('<i class="fas fa-check me-1"></i> Confirm Transfer');
            }
        });
    });

    // Initialize
    applyTypeConfig($('input[name="transfer_type"]:checked').val());
    updateGLPreview();
});
</script>
@endpush
@endsection

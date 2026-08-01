@extends('layouts.admin')

@section('content')
@php
    $today      = now()->format('Y-m-d');
    $oldDate    = old('transaction_date', $today);
    $oldEmp     = old('employee_id', $preselectEmployee->id ?? null);
    $oldBr      = old('branch_id', $userBranchId ?? session('branch_id'));
    $oldBank    = old('bank_id');
    $oldMode    = old('payment_mode', $transactionType === 'repayment' ? 'cash' : 'cash');
    $oldType    = old('transaction_type', $transactionType ?? 'advance');
    $oldAmt     = old('amount');
    $oldRef     = old('reference_no');
    $oldDesc    = old('description');
    $oldColl    = old('collected_by');

    // Type-specific configuration.
    $typeConfig = [
        'advance' => [
            'icon' => 'fa-hand-holding-dollar',
            'gradient' => 'linear-gradient(135deg,#059669,#0d9488)',
            'gl_info' => 'Dr Employee Payable · Cr Bank/Cash',
            'submit_label' => 'Record Advance',
            'submit_icon' => 'fa-floppy-disk',
            'hint' => 'Cash/bank paid to employee — increases balance owed (Dr employee control, Cr cash/bank).',
            'amount_label' => 'Advance amount (Tk)',
            'gl_debit' => 'Employee Payable',
            'gl_credit' => 'Bank / Cash',
            'gl_debit_sub' => 'Increases employee payable',
            'gl_credit_sub' => 'Decreases bank/cash balance',
            'sub_ledger' => 'Debit employee_ledger (increase payable)',
            'bank_book' => 'Decrease (if bank mode)',
            'hero_class' => 'advance-hero',
            'submit_class' => 'advance-btn',
        ],
        'loan' => [
            'icon' => 'fa-landmark',
            'gradient' => 'linear-gradient(135deg,#059669,#0d9488)',
            'gl_info' => 'Dr Employee Payable · Cr Bank/Cash',
            'submit_label' => 'Record Loan',
            'submit_icon' => 'fa-floppy-disk',
            'hint' => 'Loan disbursed — same as advance for ledger and GL.',
            'amount_label' => 'Loan amount (Tk)',
            'gl_debit' => 'Employee Payable',
            'gl_credit' => 'Bank / Cash',
            'gl_debit_sub' => 'Increases employee payable',
            'gl_credit_sub' => 'Decreases bank/cash balance',
            'sub_ledger' => 'Debit employee_ledger (increase payable)',
            'bank_book' => 'Decrease (if bank mode)',
            'hero_class' => 'advance-hero',
            'submit_class' => 'advance-btn',
        ],
        'salary' => [
            'icon' => 'fa-money-bills',
            'gradient' => 'linear-gradient(135deg,#2563eb,#3b82f6)',
            'gl_info' => 'Dr Salary Expense · Cr Bank/Cash',
            'submit_label' => 'Record Salary',
            'submit_icon' => 'fa-floppy-disk',
            'hint' => 'Salary paid out — reduces cash/bank; posts to salary expense.',
            'amount_label' => 'Salary amount (Tk)',
            'gl_debit' => 'Salary Expense',
            'gl_credit' => 'Bank / Cash',
            'gl_debit_sub' => 'Increases salary expense',
            'gl_credit_sub' => 'Decreases bank/cash balance',
            'sub_ledger' => 'Debit employee_ledger (increase payable)',
            'bank_book' => 'Decrease (if bank mode)',
            'hero_class' => 'salary-hero',
            'submit_class' => 'salary-btn',
        ],
        'repayment' => [
            'icon' => 'fa-arrow-rotate-left',
            'gradient' => 'linear-gradient(135deg,#16a34a,#15803d)',
            'gl_info' => 'Dr Bank/Cash · Cr Employee Payable',
            'submit_label' => 'Record Repayment',
            'submit_icon' => 'fa-floppy-disk',
            'hint' => 'Employee repays — Dr cash/bank, Cr employee control.',
            'amount_label' => 'Repayment amount (Tk)',
            'gl_debit' => 'Bank / Cash',
            'gl_credit' => 'Employee Payable',
            'gl_debit_sub' => 'Increases bank/cash balance',
            'gl_credit_sub' => 'Reduces employee payable',
            'sub_ledger' => 'Credit employee_ledger (reduce payable)',
            'bank_book' => 'Increase (if bank mode)',
            'hero_class' => 'repayment-hero',
            'submit_class' => 'repayment-btn',
        ],
        'deduction' => [
            'icon' => 'fa-minus-circle',
            'gradient' => 'linear-gradient(135deg,#7c3aed,#6d28d9)',
            'gl_info' => 'Dr Salary Expense · Cr Employee Payable',
            'submit_label' => 'Record Deduction',
            'submit_icon' => 'fa-floppy-disk',
            'hint' => 'Deduction / recovery — money in; reduces employee balance.',
            'amount_label' => 'Deduction amount (Tk)',
            'gl_debit' => 'Salary Expense',
            'gl_credit' => 'Employee Payable',
            'gl_debit_sub' => 'Increases salary expense',
            'gl_credit_sub' => 'Reduces employee payable',
            'sub_ledger' => 'Credit employee_ledger (reduce payable)',
            'bank_book' => 'No change',
            'hero_class' => 'deduction-hero',
            'submit_class' => 'deduction-btn',
        ],
        'adjustment' => [
            'icon' => 'fa-sliders',
            'gradient' => 'linear-gradient(135deg,#d97706,#b45309)',
            'gl_info' => 'Dr/Cr varies by context',
            'submit_label' => 'Record Adjustment',
            'submit_icon' => 'fa-floppy-disk',
            'hint' => 'Manual adjustment — treated as outflow for GL unless you use repayment type for credits.',
            'amount_label' => 'Adjustment amount (Tk)',
            'gl_debit' => 'Employee Payable',
            'gl_credit' => 'Bank / Cash',
            'gl_debit_sub' => 'Adjustment debit',
            'gl_credit_sub' => 'Adjustment credit',
            'sub_ledger' => 'Debit employee_ledger (increase payable)',
            'bank_book' => 'No change',
            'hero_class' => 'adjustment-hero',
            'submit_class' => 'adjustment-btn',
        ],
    ];
    $cfg = $typeConfig[$oldType] ?? $typeConfig['advance'];

    // Pre-compute data for @json() directives (avoids commas inside @json()
    // which breaks Laravel's compileJson() — it uses explode(',', $expression, 2))
    $preselectEmployeeData = $preselectEmployee
        ? ['id' => $preselectEmployee->id, 'name' => $preselectEmployee->name, 'employee_code' => $preselectEmployee->employee_code ?? '', 'mobile' => $preselectEmployee->mobile ?? null]
        : null;
    $glLabelsData = $glPreviewLabels ?? [
        'advance' => 'Dr Employee Payable · Cr Bank/Cash',
        'loan' => 'Dr Employee Payable · Cr Bank/Cash',
        'salary' => 'Dr Salary Expense · Cr Bank/Cash',
        'repayment' => 'Dr Bank/Cash · Cr Employee Payable',
        'deduction' => 'Dr Salary Expense · Cr Employee Payable',
        'adjustment' => 'Dr/Cr varies by context',
    ];
@endphp

<style>
    .st-create-page { --st-primary: #059669; --st-primary-dark: #0d9488; --st-accent: #7c3aed; }
    .st-hero {
        background: var(--st-primary);
        background: linear-gradient(135deg, var(--st-primary), var(--st-primary-dark));
        border-radius: 1rem;
        padding: 1.5rem 1.75rem;
        color: #fff;
        box-shadow: 0 8px 32px rgba(5,150,105,0.18);
        margin-bottom: 1.5rem;
    }
    .st-hero.advance-hero, .st-hero.loan-hero {
        background: linear-gradient(135deg, #059669, #0d9488);
        box-shadow: 0 8px 32px rgba(5,150,105,0.18);
    }
    .st-hero.salary-hero {
        background: linear-gradient(135deg, #2563eb, #3b82f6);
        box-shadow: 0 8px 32px rgba(37,99,235,0.18);
    }
    .st-hero.repayment-hero {
        background: linear-gradient(135deg, #16a34a, #15803d);
        box-shadow: 0 8px 32px rgba(22,163,74,0.18);
    }
    .st-hero.deduction-hero {
        background: linear-gradient(135deg, #7c3aed, #6d28d9);
        box-shadow: 0 8px 32px rgba(124,58,237,0.18);
    }
    .st-hero.adjustment-hero {
        background: linear-gradient(135deg, #d97706, #b45309);
        box-shadow: 0 8px 32px rgba(217,119,6,0.18);
    }
    .st-hero h1 { font-size: 1.35rem; font-weight: 700; margin-bottom: 0.15rem; }
    .st-hero .st-subtitle { font-size: 0.82rem; opacity: 0.85; }
    .st-section-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 0.875rem;
        box-shadow: 0 1px 4px rgba(15,23,42,0.04);
        margin-bottom: 1.25rem;
        overflow: hidden;
        transition: box-shadow 0.2s;
    }
    .st-section-card:hover { box-shadow: 0 4px 16px rgba(15,23,42,0.07); }
    .st-section-header {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 0.75rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .st-section-header h2 { font-size: 0.88rem; font-weight: 700; margin: 0; color: #0f172a; }
    .st-section-header .st-section-icon {
        width: 32px; height: 32px; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.85rem; color: #fff;
    }
    .st-section-body { padding: 1.25rem; }

    /* Employee select with due badge */
    .st-supplier-option { display: flex; justify-content: space-between; align-items: center; width: 100%; }
    .st-supplier-info { display: flex; flex-direction: column; }
    .st-supplier-name { font-weight: 600; font-size: 0.9rem; }
    .st-supplier-code { font-size: 0.75rem; color: #64748b; }
    .st-payable-badge {
        font-size: 0.72rem; font-weight: 600; padding: 0.15rem 0.5rem;
        border-radius: 999px; white-space: nowrap;
    }
    .st-payable-badge.has-due { background: #fef3c7; color: #92400e; }
    .st-payable-badge.no-due { background: #d1fae5; color: #065f46; }

    /* Due summary */
    .st-due-card {
        background: linear-gradient(135deg, #fffbeb, #fff7ed);
        border: 1px solid #fde68a;
        border-radius: 0.75rem;
        padding: 1rem;
        text-align: center;
    }
    .st-due-amount { font-size: 1.5rem; font-weight: 800; color: #92400e; font-variant-numeric: tabular-nums; }
    .st-due-label { font-size: 0.75rem; color: #92400e; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }

    /* GL Preview */
    .st-gl-preview {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 1rem;
        min-height: 80px;
    }
    .st-gl-entry {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 0;
        border-bottom: 1px dashed #e2e8f0;
    }
    .st-gl-entry:last-child { border-bottom: none; }
    .st-gl-entry .st-gl-label { font-weight: 600; font-size: 0.85rem; }
    .st-gl-entry .st-gl-amount { font-weight: 700; font-variant-numeric: tabular-nums; font-size: 0.9rem; }
    .st-gl-debit .st-gl-label { color: #0d9488; }
    .st-gl-debit .st-gl-amount { color: #0d9488; }
    .st-gl-credit .st-gl-label { color: #dc2626; }
    .st-gl-credit .st-gl-amount { color: #dc2626; }
    .st-gl-total-bar {
        background: #0f172a;
        border-radius: 6px;
        padding: 0.5rem 0.75rem;
        display: flex;
        justify-content: space-between;
        color: #fff;
        font-size: 0.82rem;
        font-weight: 600;
        margin-top: 0.5rem;
    }
    .st-gl-empty { text-align: center; padding: 1.5rem; color: #94a3b8; }
    .st-gl-empty i { font-size: 2rem; margin-bottom: 0.5rem; display: block; }

    /* Form enhancements */
    .st-form-label {
        font-size: 0.78rem;
        font-weight: 600;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        margin-bottom: 0.25rem;
    }
    .st-form-label .st-required { color: #dc2626; margin-left: 2px; }
    .st-form-hint { font-size: 0.72rem; color: #94a3b8; margin-top: 0.15rem; }

    /* Submit bar */
    .st-submit-bar {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 0.875rem;
        box-shadow: 0 1px 4px rgba(15,23,42,0.04);
        padding: 1rem 1.25rem;
        display: flex;
        justify-content: flex-end;
        gap: 0.75rem;
        align-items: center;
        position: sticky;
        bottom: 1rem;
        z-index: 10;
    }
    .st-btn-submit {
        background: linear-gradient(135deg, #059669, #0d9488);
        color: #fff;
        border: none;
        border-radius: 0.5rem;
        padding: 0.6rem 1.5rem;
        font-weight: 600;
        font-size: 0.9rem;
        transition: all 0.2s;
        box-shadow: 0 4px 12px rgba(5,150,105,0.3);
    }
    .st-btn-submit:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(5,150,105,0.4); }
    .st-btn-submit.advance-btn, .st-btn-submit.loan-btn {
        background: linear-gradient(135deg, #059669, #0d9488);
        box-shadow: 0 4px 12px rgba(5,150,105,0.3);
    }
    .st-btn-submit.advance-btn:hover, .st-btn-submit.loan-btn:hover { box-shadow: 0 6px 20px rgba(5,150,105,0.4); }
    .st-btn-submit.salary-btn {
        background: linear-gradient(135deg, #2563eb, #3b82f6);
        box-shadow: 0 4px 12px rgba(37,99,235,0.3);
    }
    .st-btn-submit.salary-btn:hover { box-shadow: 0 6px 20px rgba(37,99,235,0.4); }
    .st-btn-submit.repayment-btn {
        background: linear-gradient(135deg, #16a34a, #15803d);
        box-shadow: 0 4px 12px rgba(22,163,74,0.3);
    }
    .st-btn-submit.repayment-btn:hover { box-shadow: 0 6px 20px rgba(22,163,74,0.4); }
    .st-btn-submit.deduction-btn {
        background: linear-gradient(135deg, #7c3aed, #6d28d9);
        box-shadow: 0 4px 12px rgba(124,58,237,0.3);
    }
    .st-btn-submit.deduction-btn:hover { box-shadow: 0 6px 20px rgba(124,58,237,0.4); }
    .st-btn-submit.adjustment-btn {
        background: linear-gradient(135deg, #d97706, #b45309);
        box-shadow: 0 4px 12px rgba(217,119,6,0.3);
    }
    .st-btn-submit.adjustment-btn:hover { box-shadow: 0 6px 20px rgba(217,119,6,0.4); }

    /* Responsive */
    @media (max-width: 768px) {
        .st-hero { padding: 1rem 1.15rem; border-radius: 0.75rem; }
        .st-hero h1 { font-size: 1.1rem; }
        .st-section-body { padding: 0.85rem; }
        .st-submit-bar { position: static; border-radius: 0.75rem; }
        .st-due-amount { font-size: 1.25rem; }
    }
</style>

<div class="container-fluid py-3 st-create-page" id="stCreatePage">
    {{-- Hero header --}}
    <header class="st-hero {{ $cfg['hero_class'] }}" id="heroHeader">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1><i class="fas {{ $cfg['icon'] }} me-2"></i>{{ $title }}</h1>
                <p class="st-subtitle mb-0">
                    <i class="fas fa-calculator me-1"></i> GL: <strong id="heroGl">{{ $cfg['gl_info'] }}</strong> &nbsp;·&nbsp;
                    <i class="fas fa-book me-1"></i> Employee ledger + bank book auto-updated
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.employee-transactions.index') }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>
    </header>

    <form method="POST" action="{{ route('admin.employee-transactions.store') }}" id="employeeTransactionForm" novalidate>
        @csrf

        {{-- Idempotency token (UUID v4) --}}
        <input type="hidden" name="idempotency_token" id="idempotencyToken"
               value="{{ old('idempotency_token', (string) \Illuminate\Support\Str::uuid()) }}">

        {{-- Row 1: Transaction Type + Employee + Due/Owe Balance --}}
        <div class="row g-3 mb-0">
            {{-- Transaction Type --}}
            <div class="col-lg-3 col-md-6">
                <div class="st-section-card h-100">
                    <div class="st-section-header">
                        <div class="st-section-icon" style="background:linear-gradient(135deg,#059669,#0d9488);">
                            <i class="fas fa-sliders"></i>
                        </div>
                        <h2>Transaction Type</h2>
                    </div>
                    <div class="st-section-body">
                        <select id="transaction_type" name="transaction_type"
                                class="form-select @error('transaction_type') is-invalid @enderror" required>
                            <option value="advance"    {{ $oldType === 'advance' ? 'selected' : '' }}>Employee Advance</option>
                            <option value="loan"       {{ $oldType === 'loan' ? 'selected' : '' }}>Employee Loan</option>
                            <option value="salary"     {{ $oldType === 'salary' ? 'selected' : '' }}>Salary Payment</option>
                            <option value="repayment"  {{ $oldType === 'repayment' ? 'selected' : '' }}>Employee Repayment</option>
                            <option value="deduction"  {{ $oldType === 'deduction' ? 'selected' : '' }}>Deduction</option>
                            <option value="adjustment" {{ $oldType === 'adjustment' ? 'selected' : '' }}>Adjustment</option>
                        </select>
                        @error('transaction_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="st-form-hint mt-2" id="typeHintWrap">
                            <i class="fas fa-info-circle me-1"></i>
                            <span id="typeHintText">{{ $cfg['hint'] }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Employee Selection --}}
            <div class="col-lg-5 col-md-6">
                <div class="st-section-card h-100">
                    <div class="st-section-header">
                        <div class="st-section-icon" style="background:linear-gradient(135deg,#c2410c,#ea580c);">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <h2>Employee</h2>
                    </div>
                    <div class="st-section-body">
                        <label class="st-form-label">Select Employee <span class="st-required">*</span></label>
                        <select id="employee_id" name="employee_id"
                                class="form-select select2 @error('employee_id') is-invalid @enderror" required>
                            <option value="">Choose an employee...</option>
                            @foreach ($employees as $e)
                                @php
                                    $empSelected = (string) $oldEmp === (string) $e->id;
                                @endphp
                                <option value="{{ $e->id }}" {{ $empSelected ? 'selected' : '' }}
                                        data-due="0"
                                        data-code="{{ $e->employee_code ?? '' }}">
                                    {{ $e->name }}@if ($e->employee_code) — {{ $e->employee_code }}@endif
                                </option>
                            @endforeach
                        </select>
                        @error('employee_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        {{-- Employee profile link --}}
                        <div id="empTxnEmployeeHubLink" class="mt-1" style="display:none;">
                            <a id="empTxnEmployeeHubAnchor" href="#" target="_blank" class="small text-muted">
                                <i class="fas fa-external-link-alt me-1"></i> View employee profile
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Due/Owe Balance --}}
            <div class="col-lg-4">
                <div class="st-section-card h-100">
                    <div class="st-section-header">
                        <div class="st-section-icon" style="background:linear-gradient(135deg,#b45309,#d97706);">
                            <i class="fas fa-taka-sign"></i>
                        </div>
                        <h2>Balance Owed</h2>
                    </div>
                    <div class="st-section-body d-flex align-items-center justify-content-center">
                        <div class="st-due-card w-100" id="dueSummary">
                            <div class="st-due-amount" id="dueAmount">Tk 0.00</div>
                            <div class="st-due-label" id="dueLabel">Outstanding Balance</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Row 2: Payment Details --}}
        <div class="st-section-card">
            <div class="st-section-header">
                <div class="st-section-icon" style="background:linear-gradient(135deg,#2563eb,#3b82f6);">
                    <i class="fas fa-receipt"></i>
                </div>
                <h2>Payment Details</h2>
            </div>
            <div class="st-section-body">
                <div class="row g-3">
                    {{-- Branch --}}
                    <div class="col-lg-3 col-md-6">
                        <label class="st-form-label" for="branch_id">Branch <span class="st-required">*</span></label>
                        <select id="branch_id" name="branch_id"
                                class="form-select select2 @error('branch_id') is-invalid @enderror"
                                {{ !($isAdmin ?? false) ? 'disabled' : '' }} required>
                            @if ($isAdmin ?? false)
                                <option value="">Select branch</option>
                            @endif
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}"
                                    {{ (string) $oldBr === (string) $b->id ? 'selected' : '' }}>
                                    {{ $b->branch_code }} — {{ $b->branch_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        @if (!($isAdmin ?? false))
                            <input type="hidden" name="branch_id" value="{{ $oldBr }}">
                            <div class="st-form-hint"><i class="fas fa-lock me-1"></i> Your assigned branch</div>
                        @endif
                    </div>

                    {{-- Payment Mode --}}
                    <div class="col-lg-3 col-md-6" id="paymentModeField">
                        <label class="st-form-label" for="payment_mode">Payment Mode <span class="st-required">*</span></label>
                        <select id="payment_mode" name="payment_mode"
                                class="form-select @error('payment_mode') is-invalid @enderror" required>
                            <option value="cash"            {{ $oldMode === 'cash' ? 'selected' : '' }}>Cash</option>
                            <option value="bank"            {{ $oldMode === 'bank' ? 'selected' : '' }}>Bank Transfer</option>
                            <option value="mobile_banking"  {{ $oldMode === 'mobile_banking' ? 'selected' : '' }}>Mobile Banking</option>
                            <option value="cheque"          {{ $oldMode === 'cheque' ? 'selected' : '' }}>Cheque</option>
                            <option value="adjustment"      {{ $oldMode === 'adjustment' ? 'selected' : '' }}>Adjustment</option>
                        </select>
                        @error('payment_mode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Bank --}}
                    <div class="col-lg-3 col-md-6" id="bankField" style="display:none;">
                        <label class="st-form-label" for="bank_id">Bank <span class="text-muted small" style="text-transform:none;font-weight:400;">required for bank mode</span></label>
                        <select id="bank_id" name="bank_id"
                                class="form-select select2 @error('bank_id') is-invalid @enderror">
                            <option value="">Select bank</option>
                            @foreach ($banks as $bk)
                                <option value="{{ $bk->id }}"
                                    {{ (string) $oldBank === (string) $bk->id ? 'selected' : '' }}>
                                    {{ $bk->bank_name }}@if (!empty($bk->account_number)) — {{ $bk->account_number }}@endif
                                </option>
                            @endforeach
                        </select>
                        @error('bank_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Amount --}}
                    <div class="col-lg-3 col-md-6">
                        <label class="st-form-label" for="amount" id="amountLabel">
                            {{ $cfg['amount_label'] }} <span class="st-required">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text fw-bold" style="background:#f8fafc;">Tk</span>
                            <input type="number" id="amount" name="amount"
                                   class="form-control text-end @error('amount') is-invalid @enderror"
                                   min="0.01" step="0.01" required
                                   value="{{ $oldAmt }}"
                                   placeholder="0.00">
                        </div>
                        @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Date --}}
                    <div class="col-lg-3 col-md-6">
                        <label class="st-form-label" for="transaction_date">Transaction Date <span class="st-required">*</span></label>
                        <input type="date" id="transaction_date" name="transaction_date"
                               class="form-control @error('transaction_date') is-invalid @enderror"
                               required value="{{ $oldDate }}">
                        @error('transaction_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Reference --}}
                    <div class="col-lg-3 col-md-6">
                        <label class="st-form-label" for="reference_no">
                            Reference No <span class="text-muted" style="text-transform:none;font-weight:400;">cheque/txn no</span>
                        </label>
                        <input type="text" id="reference_no" name="reference_no"
                               class="form-control @error('reference_no') is-invalid @enderror"
                               maxlength="100"
                               value="{{ $oldRef }}"
                               placeholder="e.g. CHQ-0012345">
                        @error('reference_no') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Collected By --}}
                    <div class="col-lg-3 col-md-6" id="collectedByField">
                        <label class="st-form-label" for="collected_by">
                            Collected By <span class="text-muted" style="text-transform:none;font-weight:400;">optional</span>
                        </label>
                        <select id="collected_by" name="collected_by"
                                class="form-select select2 @error('collected_by') is-invalid @enderror">
                            <option value="">Select employee</option>
                            @foreach ($collectors as $emp)
                                <option value="{{ $emp->id }}"
                                    {{ (string) $oldColl === (string) $emp->id ? 'selected' : '' }}>
                                    {{ $emp->name ?? $emp->employee_code }}
                                </option>
                            @endforeach
                        </select>
                        @error('collected_by') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Notes --}}
                    <div class="col-12">
                        <label class="st-form-label" for="notes">Notes</label>
                        <textarea id="notes" name="description" rows="2" class="form-control"
                                  placeholder="Internal notes — purpose, remarks, etc.">{{ $oldDesc }}</textarea>
                        @error('description') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- Row 3: GL Accounting Preview --}}
        <div class="st-section-card">
            <div class="st-section-header">
                <div class="st-section-icon" style="background:linear-gradient(135deg,#0f172a,#334155);">
                    <i class="fas fa-calculator"></i>
                </div>
                <h2>GL Accounting Preview</h2>
            </div>
            <div class="st-section-body">
                <div class="row g-3">
                    <div class="col-lg-7">
                        <div class="st-gl-preview" id="glPreview">
                            <div class="st-gl-empty" id="glEmpty">
                                <i class="fas fa-calculator"></i>
                                Enter an amount to see the GL journal preview
                            </div>
                            <div id="glEntries" style="display:none;">
                                <div class="st-gl-entry st-gl-debit">
                                    <div>
                                        <span class="st-gl-label" id="glDebitLabel">Dr {{ $cfg['gl_debit'] }}</span>
                                        <div class="small text-muted" id="glDebitSub">{{ $cfg['gl_debit_sub'] }}</div>
                                    </div>
                                    <span class="st-gl-amount" id="glDebitAmount">Tk 0.00</span>
                                </div>
                                <div class="st-gl-entry st-gl-credit">
                                    <div>
                                        <span class="st-gl-label" id="glCreditLabel">Cr {{ $cfg['gl_credit'] }}</span>
                                        <div class="small text-muted" id="glCreditSub">{{ $cfg['gl_credit_sub'] }}</div>
                                    </div>
                                    <span class="st-gl-amount" id="glCreditAmount">Tk 0.00</span>
                                </div>
                                <div class="st-gl-total-bar">
                                    <span>Net Effect</span>
                                    <span id="glNetEffect">Balanced</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div class="text-center p-2 rounded" style="background:#f0fdf4;">
                                    <div class="small text-muted">GL Rule</div>
                                    <strong class="small" id="glRuleLabel">{{ $cfg['gl_info'] }}</strong>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-2 rounded" style="background:#fff7ed;">
                                    <div class="small text-muted">Sub-Ledger</div>
                                    <strong class="small" id="subLedgerLabel">{{ $cfg['sub_ledger'] }}</strong>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-2 rounded" style="background:#eff6ff;">
                                    <div class="small text-muted">Bank Book</div>
                                    <strong class="small" id="bankBookLabel">{{ $cfg['bank_book'] }}</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Submit Bar --}}
        <div class="st-submit-bar">
            <a href="{{ route('admin.employee-transactions.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-times me-1"></i> Cancel
            </a>
            <button type="submit" class="st-btn-submit {{ $cfg['submit_class'] }}" id="submitBtn">
                <i class="fas {{ $cfg['submit_icon'] }} me-1"></i>
                <span id="submitLabel">{{ $cfg['submit_label'] }}</span>
            </button>
        </div>
    </form>
</div>

{{-- Boot config for EmployeeTransaction.js --}}
<script>
    window.ET_BOOT = {
        baseUrl: '{{ url("/") }}/',
        csrf_token: '{{ csrf_token() }}',
        preselectEmployee: @json($preselectEmployeeData),
        glLabels: @json($glLabelsData),
        isAdmin: {{ ($isAdmin ?? false) ? 'true' : 'false' }},
        userBranchId: {{ $userBranchId ?? 0 }},
        routes: {
            'index': '{{ route("admin.employee-transactions.index") }}',
            'show': '{{ route("admin.employee-transactions.show", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'store': '{{ route("admin.employee-transactions.store") }}',
            'search': '{{ route("admin.employee-transactions.search") }}',
            'get-due': '{{ route("admin.employee-transactions.get-due") }}',
            'reverse': '{{ route("admin.employee-transactions.reverse", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'employee-show': '{{ url("/admin/employees") }}/',
        },
    };
</script>

@push('scripts')
<link rel="stylesheet" href="/assets/css/employee-transaction-theme.css?v={{ filemtime(public_path('assets/css/employee-transaction-theme.css')) }}">
<script src="/assets/js/EmployeeTransaction.js?v={{ filemtime(public_path('assets/js/EmployeeTransaction.js')) }}"></script>
<script>
$(function () {
    var $form          = $('#employeeTransactionForm');
    var $employee      = $('#employee_id');
    var $mode          = $('#payment_mode');
    var $bankField     = $('#bankField');
    var $bankId        = $('#bank_id');
    var $amount        = $('#amount');
    var $transType     = $('#transaction_type');
    var $dueSummary    = $('#dueSummary');
    var $dueAmount     = $('#dueAmount');
    var $dueLabel      = $('#dueLabel');

    // ====== Type configuration ======
    var typeConfig = {
        advance: {
            icon: 'fa-hand-holding-dollar',
            gradient: 'linear-gradient(135deg,#059669,#0d9488)',
            gl_info: 'Dr Employee Payable · Cr Bank/Cash',
            submit_label: 'Record Advance',
            submit_icon: 'fa-floppy-disk',
            hint: 'Cash/bank paid to employee — increases balance owed (Dr employee control, Cr cash/bank).',
            amount_label: 'Advance amount (Tk)',
            bank_visible: true,
            mode_default: 'cash',
            hero_class: 'advance-hero',
            submit_class: 'advance-btn',
            gl_debit: 'Employee Payable',
            gl_credit: 'Bank / Cash',
            gl_debit_sub: 'Increases employee payable',
            gl_credit_sub: 'Decreases bank/cash balance',
            sub_ledger: 'Debit employee_ledger (increase payable)',
            bank_book: 'Decrease (if bank mode)',
        },
        loan: {
            icon: 'fa-landmark',
            gradient: 'linear-gradient(135deg,#059669,#0d9488)',
            gl_info: 'Dr Employee Payable · Cr Bank/Cash',
            submit_label: 'Record Loan',
            submit_icon: 'fa-floppy-disk',
            hint: 'Loan disbursed — same as advance for ledger and GL.',
            amount_label: 'Loan amount (Tk)',
            bank_visible: true,
            mode_default: 'cash',
            hero_class: 'advance-hero',
            submit_class: 'loan-btn',
            gl_debit: 'Employee Payable',
            gl_credit: 'Bank / Cash',
            gl_debit_sub: 'Increases employee payable',
            gl_credit_sub: 'Decreases bank/cash balance',
            sub_ledger: 'Debit employee_ledger (increase payable)',
            bank_book: 'Decrease (if bank mode)',
        },
        salary: {
            icon: 'fa-money-bills',
            gradient: 'linear-gradient(135deg,#2563eb,#3b82f6)',
            gl_info: 'Dr Salary Expense · Cr Bank/Cash',
            submit_label: 'Record Salary',
            submit_icon: 'fa-floppy-disk',
            hint: 'Salary paid out — reduces cash/bank; posts to salary expense.',
            amount_label: 'Salary amount (Tk)',
            bank_visible: true,
            mode_default: 'cash',
            hero_class: 'salary-hero',
            submit_class: 'salary-btn',
            gl_debit: 'Salary Expense',
            gl_credit: 'Bank / Cash',
            gl_debit_sub: 'Increases salary expense',
            gl_credit_sub: 'Decreases bank/cash balance',
            sub_ledger: 'Debit employee_ledger (increase payable)',
            bank_book: 'Decrease (if bank mode)',
        },
        repayment: {
            icon: 'fa-arrow-rotate-left',
            gradient: 'linear-gradient(135deg,#16a34a,#15803d)',
            gl_info: 'Dr Bank/Cash · Cr Employee Payable',
            submit_label: 'Record Repayment',
            submit_icon: 'fa-floppy-disk',
            hint: 'Employee repays — Dr cash/bank, Cr employee control.',
            amount_label: 'Repayment amount (Tk)',
            bank_visible: true,
            mode_default: 'cash',
            hero_class: 'repayment-hero',
            submit_class: 'repayment-btn',
            gl_debit: 'Bank / Cash',
            gl_credit: 'Employee Payable',
            gl_debit_sub: 'Increases bank/cash balance',
            gl_credit_sub: 'Reduces employee payable',
            sub_ledger: 'Credit employee_ledger (reduce payable)',
            bank_book: 'Increase (if bank mode)',
        },
        deduction: {
            icon: 'fa-minus-circle',
            gradient: 'linear-gradient(135deg,#7c3aed,#6d28d9)',
            gl_info: 'Dr Salary Expense · Cr Employee Payable',
            submit_label: 'Record Deduction',
            submit_icon: 'fa-floppy-disk',
            hint: 'Deduction / recovery — money in; reduces employee balance.',
            amount_label: 'Deduction amount (Tk)',
            bank_visible: false,
            mode_default: 'adjustment',
            hero_class: 'deduction-hero',
            submit_class: 'deduction-btn',
            gl_debit: 'Salary Expense',
            gl_credit: 'Employee Payable',
            gl_debit_sub: 'Increases salary expense',
            gl_credit_sub: 'Reduces employee payable',
            sub_ledger: 'Credit employee_ledger (reduce payable)',
            bank_book: 'No change',
        },
        adjustment: {
            icon: 'fa-sliders',
            gradient: 'linear-gradient(135deg,#d97706,#b45309)',
            gl_info: 'Dr/Cr varies by context',
            submit_label: 'Record Adjustment',
            submit_icon: 'fa-floppy-disk',
            hint: 'Manual adjustment — treated as outflow for GL unless you use repayment type for credits.',
            amount_label: 'Adjustment amount (Tk)',
            bank_visible: false,
            mode_default: 'adjustment',
            hero_class: 'adjustment-hero',
            submit_class: 'adjustment-btn',
            gl_debit: 'Employee Payable',
            gl_credit: 'Bank / Cash',
            gl_debit_sub: 'Adjustment debit',
            gl_credit_sub: 'Adjustment credit',
            sub_ledger: 'Debit employee_ledger (increase payable)',
            bank_book: 'No change',
        },
    };

    // ====== Dynamic type switching ======
    function applyTypeConfig(type) {
        var cfg = typeConfig[type] || typeConfig.advance;

        // Hero header
        $('#heroHeader').css('background', cfg.gradient);
        $('#heroHeader').removeClass('advance-hero loan-hero salary-hero repayment-hero deduction-hero adjustment-hero').addClass(cfg.hero_class);
        $('#heroHeader h1 i').attr('class', 'fas ' + cfg.icon + ' me-2');
        $('#heroGl').text(cfg.gl_info);

        // Type hint
        $('#typeHintText').text(cfg.hint);

        // GL preview labels
        $('#glRuleLabel').text(cfg.gl_info);
        $('#subLedgerLabel').text(cfg.sub_ledger);
        $('#bankBookLabel').text(cfg.bank_book);

        // Amount label
        $('#amountLabel').contents().first().text(cfg.amount_label + ' ');

        // Submit button
        $('#submitLabel').text(cfg.submit_label);
        var $btn = $('#submitBtn');
        $btn.removeClass('advance-btn loan-btn salary-btn repayment-btn deduction-btn adjustment-btn');
        if (cfg.submit_class) $btn.addClass(cfg.submit_class);

        // Bank field visibility
        if (!cfg.bank_visible) {
            $bankField.hide();
            $bankId.prop('required', false);
        } else {
            toggleBankField();
        }

        // Payment mode — auto-set to adjustment for deduction/adjustment
        if (!cfg.bank_visible && $mode.val() !== 'adjustment') {
            $mode.val(cfg.mode_default);
        }

        // Update GL preview
        updateGlPreview();
    }

    $transType.on('change', function () {
        applyTypeConfig($(this).val());
    });

    // ====== Select2 init with employee due badges ======
    $('.select2').select2({
        theme: 'bootstrap-5',
        width: '100%',
        templateResult: function (option) {
            if (!option.id || option.id === '') return option.text;
            var code = $(option.element).data('code');
            var $wrap = $('<div class="st-supplier-option"></div>');
            var $info = $('<div class="st-supplier-info"></div>');
            $info.append('<span class="st-supplier-name">' + option.text.split('—')[0].trim() + '</span>');
            $info.append('<span class="st-supplier-code">' + (code || '') + '</span>');
            $wrap.append($info);
            return $wrap;
        }
    });

    // ====== Show/hide bank field based on payment mode ======
    function toggleBankField() {
        var type = $transType.val();
        var cfg = typeConfig[type] || typeConfig.advance;

        if (!cfg.bank_visible) {
            $bankField.hide();
            $bankId.prop('required', false);
            return;
        }

        var mode = $mode.val();
        if (mode === 'bank' || mode === 'cheque') {
            $bankField.show();
            if (mode === 'bank') {
                $bankId.prop('required', true);
            } else {
                $bankId.prop('required', false);
            }
        } else {
            $bankField.hide();
            $bankId.prop('required', false);
        }
    }
    $mode.on('change', toggleBankField);
    toggleBankField();

    // ====== Employee change: update due balance via AJAX ======
    function loadDueBalance(employeeId) {
        if (!employeeId) {
            $dueAmount.text('Tk 0.00');
            $dueLabel.text('Outstanding Balance');
            $dueSummary.css({ background: 'linear-gradient(135deg, #fffbeb, #fff7ed)', border: '1px solid #fde68a' });
            $dueAmount.css('color', '#92400e');
            $dueLabel.css('color', '#92400e');
            $('#empTxnEmployeeHubLink').hide();
            return;
        }

        $dueAmount.text('Loading…');
        $dueLabel.text('Fetching balance');

        // Show employee profile link
        var empRoute = window.ET_BOOT && window.ET_BOOT.routes ? window.ET_BOOT.routes['employee-show'] : '';
        if (empRoute) {
            var $opt = $employee.find('option:selected');
            $('#empTxnEmployeeHubAnchor').attr('href', empRoute + employeeId);
            $('#empTxnEmployeeHubLink').show();
        }

        var csrf = document.querySelector('meta[name="csrf-token"]')?.content
            || document.querySelector('input[name="_token"]')?.value
            || window.ET_BOOT?.csrf_token || '';

        fetch(window.ET_BOOT.routes['get-due'], {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrf,
            },
            credentials: 'same-origin',
            body: 'employee_id=' + encodeURIComponent(employeeId),
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.status === 'success') {
                var due = parseFloat(data.due_balance) || 0;
                $dueAmount.text('Tk ' + due.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

                if (due > 0) {
                    $dueSummary.css({ background: 'linear-gradient(135deg, #fffbeb, #fff7ed)', border: '1px solid #fde68a' });
                    $dueAmount.css('color', '#92400e');
                    $dueLabel.css('color', '#92400e');
                    $dueLabel.text('Outstanding Balance');
                } else {
                    $dueSummary.css({ background: 'linear-gradient(135deg, #f0fdf4, #ecfdf5)', border: '1px solid #bbf7d0' });
                    $dueAmount.css('color', '#065f46');
                    $dueLabel.css('color', '#065f46');
                    $dueLabel.text('No Balance Owed');
                }
            } else {
                $dueAmount.text('—');
                $dueLabel.text('Could not load balance');
            }
        })
        .catch(function () {
            $dueAmount.text('—');
            $dueLabel.text('Error loading balance');
        });
    }

    $employee.on('change', function () {
        loadDueBalance($(this).val());
    });

    // ====== GL Accounting Preview updater ======
    function updateGlPreview() {
        var type = $transType.val();
        var cfg = typeConfig[type] || typeConfig.advance;
        var amount = parseFloat($amount.val()) || 0;

        if (amount <= 0) {
            $('#glEmpty').show();
            $('#glEntries').hide();
            return;
        }

        $('#glEmpty').hide();
        $('#glEntries').show();

        $('#glDebitLabel').text('Dr ' + cfg.gl_debit);
        $('#glDebitSub').text(cfg.gl_debit_sub);
        $('#glDebitAmount').text('Tk ' + amount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

        $('#glCreditLabel').text('Cr ' + cfg.gl_credit);
        $('#glCreditSub').text(cfg.gl_credit_sub);
        $('#glCreditAmount').text('Tk ' + amount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

        $('#glNetEffect').text('Balanced');
    }

    // ====== Events ======
    $amount.on('input', updateGlPreview);
    $transType.on('change', function () { applyTypeConfig($(this).val()); updateGlPreview(); });
    $mode.on('change', function () { toggleBankField(); updateGlPreview(); });

    // ====== Branch: non-admin auto-select ======
    if (window.ET_BOOT && !window.ET_BOOT.isAdmin && window.ET_BOOT.userBranchId) {
        $('#branch_id').val(window.ET_BOOT.userBranchId).trigger('change');
    }

    // ====== Apply initial type config ======
    applyTypeConfig('{{ $oldType }}');
    updateGlPreview();

    // ====== If employee is preselected, load due balance ======
    @if (!empty($preselectEmployee))
        loadDueBalance('{{ $preselectEmployee->id }}');
    @endif
});
</script>
@endpush
@endsection

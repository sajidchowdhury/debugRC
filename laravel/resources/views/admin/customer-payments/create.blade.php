@extends('layouts.admin')

@section('content')
@php
    $today      = now()->format('Y-m-d');
    $oldDate    = old('payment_date', $today);
    $oldCust    = old('customer_id', $selectedCustomerId ?? null);
    $oldBr      = old('branch_id', $userBranchId ?? session('branch_id'));
    $oldBank    = old('bank_id');
    $oldMode    = old('payment_mode', $transactionType === 'discount' || $transactionType === 'write_off' ? 'adjustment' : 'cash');
    $oldType    = old('transaction_type', $transactionType ?? 'receive');
    $oldAmt     = old('amount');
    $oldDisc    = old('discount_amount', 0);
    $oldRef     = old('reference_no');
    $oldNotes   = old('notes');
    $oldColl    = old('collected_by');

    // Server-side preloaded outstanding invoices (when customer_id in query string).
    $preloadedInvoices = $outstandingInvoices ?? collect();

    // Type-specific configuration.
    $typeConfig = [
        'receive' => [
            'icon' => 'fa-hand-holding-dollar',
            'gradient' => 'linear-gradient(135deg,#059669,#0d9488)',
            'gl_info' => 'Dr Bank/Cash · Cr Accounts Receivable',
            'submit_label' => 'Record Payment',
            'submit_icon' => 'fa-floppy-disk',
            'hint' => 'Customer paying us — money received.',
            'amount_label' => 'Amount (Tk)',
            'gl_debit' => 'Bank / Cash',
            'gl_credit' => 'Accounts Receivable',
            'gl_debit_sub' => 'Increases bank/cash balance',
            'gl_credit_sub' => 'Reduces receivable balance',
            'sub_ledger' => 'Credit customer_ledger (reduce AR)',
            'bank_book' => 'Increase (if bank mode)',
        ],
        'discount' => [
            'icon' => 'fa-tags',
            'gradient' => 'linear-gradient(135deg,#7c3aed,#6d28d9)',
            'gl_info' => 'Dr Sales Discount · Cr Accounts Receivable',
            'submit_label' => 'Record Discount',
            'submit_icon' => 'fa-floppy-disk',
            'hint' => 'Discount allowed to customer — reduces AR, no money received.',
            'amount_label' => 'Discount amount (Tk)',
            'gl_debit' => 'Sales Discount',
            'gl_credit' => 'Accounts Receivable',
            'gl_debit_sub' => 'Increases discount expense',
            'gl_credit_sub' => 'Reduces receivable balance',
            'sub_ledger' => 'Credit customer_ledger (reduce AR)',
            'bank_book' => 'No change',
        ],
        'write_off' => [
            'icon' => 'fa-file-circle-xmark',
            'gradient' => 'linear-gradient(135deg,#dc2626,#b91c1c)',
            'gl_info' => 'Dr Bad Debt Expense · Cr Accounts Receivable',
            'submit_label' => 'Write Off',
            'submit_icon' => 'fa-file-circle-xmark',
            'hint' => 'Bad debt write-off — uncollectable amount removed from AR.',
            'amount_label' => 'Write-off amount (Tk)',
            'gl_debit' => 'Bad Debt Expense',
            'gl_credit' => 'Accounts Receivable',
            'gl_debit_sub' => 'Increases bad debt expense',
            'gl_credit_sub' => 'Reduces receivable balance',
            'sub_ledger' => 'Credit customer_ledger (reduce AR)',
            'bank_book' => 'No change',
        ],
        'payment' => [
            'icon' => 'fa-rotate-left',
            'gradient' => 'linear-gradient(135deg,#f59e0b,#d97706)',
            'gl_info' => 'Dr Accounts Receivable · Cr Bank/Cash',
            'submit_label' => 'Issue Refund',
            'submit_icon' => 'fa-rotate-left',
            'hint' => 'Refund to customer — money returned, AR increases.',
            'amount_label' => 'Refund amount (Tk)',
            'gl_debit' => 'Accounts Receivable',
            'gl_credit' => 'Bank / Cash',
            'gl_debit_sub' => 'Increases receivable balance',
            'gl_credit_sub' => 'Decreases bank/cash balance',
            'sub_ledger' => 'Debit customer_ledger (increase AR)',
            'bank_book' => 'Decrease (if bank mode)',
        ],
    ];
    $cfg = $typeConfig[$oldType] ?? $typeConfig['receive'];

    // Pre-compute data for @json() directives (avoids commas inside @json()
    // which breaks Laravel's compileJson() — it uses explode(',', $expression, 2))
    $customerReceivablesData = $customerReceivables ?? [];
    $glLabels = [
        'receive' => 'Dr Bank/Cash · Cr AR',
        'discount' => 'Dr Sales Discount · Cr AR',
        'write_off' => 'Dr Bad Debt Expense · Cr AR',
        'payment' => 'Dr AR · Cr Bank/Cash',
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
    .st-hero.discount-hero {
        background: linear-gradient(135deg, #7c3aed, #6d28d9);
        box-shadow: 0 8px 32px rgba(124,58,237,0.18);
    }
    .st-hero.writeoff-hero {
        background: linear-gradient(135deg, #dc2626, #b91c1c);
        box-shadow: 0 8px 32px rgba(220,38,38,0.18);
    }
    .st-hero.refund-hero {
        background: linear-gradient(135deg, #f59e0b, #d97706);
        box-shadow: 0 8px 32px rgba(245,158,11,0.18);
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

    /* Customer select with receivable badge */
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
    .st-btn-submit.discount-btn {
        background: linear-gradient(135deg, #7c3aed, #6d28d9);
        box-shadow: 0 4px 12px rgba(124,58,237,0.3);
    }
    .st-btn-submit.discount-btn:hover { box-shadow: 0 6px 20px rgba(124,58,237,0.4); }
    .st-btn-submit.writeoff-btn {
        background: linear-gradient(135deg, #dc2626, #b91c1c);
        box-shadow: 0 4px 12px rgba(220,38,38,0.3);
    }
    .st-btn-submit.writeoff-btn:hover { box-shadow: 0 6px 20px rgba(220,38,38,0.4); }
    .st-btn-submit.refund-btn {
        background: linear-gradient(135deg, #f59e0b, #d97706);
        box-shadow: 0 4px 12px rgba(245,158,11,0.3);
    }
    .st-btn-submit.refund-btn:hover { box-shadow: 0 6px 20px rgba(245,158,11,0.4); }

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
    <header class="st-hero {{ $oldType === 'discount' ? 'discount-hero' : '' }}{{ $oldType === 'write_off' ? 'writeoff-hero' : '' }}{{ $oldType === 'payment' ? 'refund-hero' : '' }}" id="heroHeader">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1><i class="fas {{ $cfg['icon'] }} me-2"></i>{{ $title }}</h1>
                <p class="st-subtitle mb-0">
                    <i class="fas fa-calculator me-1"></i> GL: <strong id="heroGl">{{ $cfg['gl_info'] }}</strong> &nbsp;·&nbsp;
                    <i class="fas fa-book me-1"></i> Customer ledger + bank book auto-updated
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.customer-payments.index') }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>
    </header>

    <form method="POST" action="{{ route('admin.customer-payments.store') }}" id="paymentForm" novalidate>
        @csrf

        {{-- Idempotency token (UUID v4). Mirrors the finalize pattern.
             Generated server-side via Str::uuid() on first render; preserved
             across validation failures via old() so the user can resubmit
             the corrected form with the same token (safe — no cache entry
             was created on the failed attempt). The server caches the
             redirect target + success message keyed by this token for
             10 minutes so that a duplicate submission (double-click,
             refresh-after-submit, network retry) returns the original
             payment instead of creating a second one. --}}
        <input type="hidden" name="idempotency_token" id="idempotencyToken"
               value="{{ old('idempotency_token', (string) \Illuminate\Support\Str::uuid()) }}">

        {{-- Row 1: Transaction Type + Customer + Receivable Balance --}}
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
                            <option value="receive"   {{ $oldType === 'receive' ? 'selected' : '' }}>Payment Received</option>
                            <option value="discount"  {{ $oldType === 'discount' ? 'selected' : '' }}>Discount Allowed</option>
                            <option value="write_off" {{ $oldType === 'write_off' ? 'selected' : '' }}>Bad Debt Write-off</option>
                            <option value="payment"   {{ $oldType === 'payment' ? 'selected' : '' }}>Refund to Customer</option>
                        </select>
                        @error('transaction_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="st-form-hint mt-2" id="typeHint">
                            <i class="fas fa-info-circle me-1"></i>
                            <span id="typeHintText">{{ $cfg['hint'] }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Customer Selection --}}
            <div class="col-lg-5 col-md-6">
                <div class="st-section-card h-100">
                    <div class="st-section-header">
                        <div class="st-section-icon" style="background:linear-gradient(135deg,#c2410c,#ea580c);">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <h2>Customer</h2>
                    </div>
                    <div class="st-section-body">
                        <label class="st-form-label">Select Customer <span class="st-required">*</span></label>
                        <select id="customer_id" name="customer_id"
                                class="form-select select2 @error('customer_id') is-invalid @enderror" required>
                            <option value="">Choose a customer...</option>
                            @foreach ($customers as $c)
                                @php
                                    $due = $customerReceivables[$c->id] ?? 0;
                                    $custSelected = (string) $oldCust === (string) $c->id;
                                @endphp
                                <option value="{{ $c->id }}" {{ $custSelected ? 'selected' : '' }}
                                        data-due="{{ number_format($due, 2) }}"
                                        data-code="{{ $c->customer_code ?? '' }}">
                                    {{ $c->customer_name }}@if ($c->customer_code) — {{ $c->customer_code }}@endif
                                    @if($due > 0) [Due: Tk {{ number_format($due, 2) }}] @endif
                                </option>
                            @endforeach
                        </select>
                        @error('customer_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>

            {{-- Receivable Now --}}
            <div class="col-lg-4">
                <div class="st-section-card h-100">
                    <div class="st-section-header">
                        <div class="st-section-icon" style="background:linear-gradient(135deg,#b45309,#d97706);">
                            <i class="fas fa-taka-sign"></i>
                        </div>
                        <h2>Receivable Now</h2>
                    </div>
                    <div class="st-section-body d-flex align-items-center justify-content-center">
                        <div class="st-due-card w-100" id="dueSummary">
                            <div class="st-due-amount" id="dueAmount">Tk 0.00</div>
                            <div class="st-due-label">Outstanding Receivable</div>
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

                    {{-- Discount --}}
                    <div class="col-lg-3 col-md-6" id="discountField">
                        <label class="st-form-label" for="discount_amount">
                            Discount (Tk) <span class="text-muted" style="text-transform:none;font-weight:400;">optional</span>
                        </label>
                        <input type="number" id="discount_amount" name="discount_amount"
                               class="form-control text-end @error('discount_amount') is-invalid @enderror"
                               min="0" step="0.01"
                               value="{{ $oldDisc }}"
                               placeholder="0.00">
                        @error('discount_amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Date --}}
                    <div class="col-lg-3 col-md-6">
                        <label class="st-form-label" for="payment_date">Payment Date <span class="st-required">*</span></label>
                        <input type="date" id="payment_date" name="payment_date"
                               class="form-control @error('payment_date') is-invalid @enderror"
                               required value="{{ $oldDate }}">
                        @error('payment_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
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
                            @foreach ($employees as $emp)
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
                        <textarea id="notes" name="notes" rows="2" class="form-control"
                                  placeholder="Internal notes — source, remarks, etc.">{{ $oldNotes }}</textarea>
                        @error('notes') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
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
                                        <span class="st-gl-label" id="glDebitLabel">Dr Bank / Cash</span>
                                        <div class="small text-muted" id="glDebitSub">Increases bank/cash balance</div>
                                    </div>
                                    <span class="st-gl-amount" id="glDebitAmount">Tk 0.00</span>
                                </div>
                                <div class="st-gl-entry st-gl-credit">
                                    <div>
                                        <span class="st-gl-label" id="glCreditLabel">Cr Accounts Receivable</span>
                                        <div class="small text-muted" id="glCreditSub">Reduces receivable balance</div>
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

        {{-- Invoice Allocation --}}
        <div class="st-section-card" id="allocationCard">
            <div class="st-section-header">
                <div class="st-section-icon" style="background:linear-gradient(135deg,#2563eb,#3b82f6);">
                    <i class="fas fa-file-invoice-dollar"></i>
                </div>
                <h2>Invoice Allocation</h2>
                <span class="badge bg-success-subtle text-success ms-auto" id="allocationStatus">
                    <i class="fas fa-info-circle me-1"></i>No customer selected
                </span>
            </div>
            <div class="st-section-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle mb-0" id="allocTable">
                        <thead class="table-light">
                            <tr>
                                <th>Invoice code</th>
                                <th>Date</th>
                                <th class="text-end">Total (Tk)</th>
                                <th class="text-end">Paid (Tk)</th>
                                <th class="text-end">Due (Tk)</th>
                                <th class="text-end" style="width:18%;" id="allocAmountHeader">Allocate (Tk)</th>
                                <th class="text-center" style="width:8%;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="allocBody">
                            @forelse ($preloadedInvoices as $inv)
                                <tr data-invoice-id="{{ $inv->id }}" data-due="{{ (float) $inv->due_amount }}">
                                    <td>
                                        <span class="fw-semibold">{{ $inv->invoice_code }}</span>
                                        <input type="hidden" name="alloc_invoice_id[]" value="{{ $inv->id }}">
                                    </td>
                                    <td class="small">{{ \Carbon\Carbon::parse($inv->invoice_date)->format('d M Y') }}</td>
                                    <td class="text-end">{{ number_format((float) $inv->total_amount, 2) }}</td>
                                    <td class="text-end">{{ number_format((float) $inv->paid_amount, 2) }}</td>
                                    <td class="text-end text-danger fw-semibold">{{ number_format((float) $inv->due_amount, 2) }}</td>
                                    <td class="text-end">
                                        <input type="number" name="alloc_amount[]" class="form-control form-control-sm text-end alloc-input"
                                               min="0" step="0.01" max="{{ (float) $inv->due_amount }}" value="0" placeholder="0.00">
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-danger alloc-clear" title="Clear">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr id="allocPlaceholder">
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <i class="fas fa-circle-info me-1"></i>
                                        Select a customer above to load their outstanding invoices.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td colspan="5" class="text-end" id="allocTotalLabel">Total allocated</td>
                                <td class="text-end" id="allocTotal">0.00</td>
                                <td></td>
                            </tr>
                            <tr class="table-light">
                                <td colspan="5" class="text-end text-muted" id="allocPaymentAmtLabel">Payment amount entered</td>
                                <td class="text-end text-muted" id="allocPaymentAmt">0.00</td>
                                <td></td>
                            </tr>
                            <tr class="table-light">
                                <td colspan="5" class="text-end text-muted">Unallocated balance</td>
                                <td class="text-end fw-bold" id="allocUnallocated">0.00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="p-3">
                    <div class="text-danger small d-none" id="allocError">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        <span id="allocErrorMsg">Total allocated exceeds payment amount.</span>
                    </div>
                    <div class="text-muted small">
                        <i class="fas fa-info-circle me-1"></i>
                        Allocations are optional. If you allocate, the sum must be less than or equal to the payment amount.
                        Unallocated balance remains as customer advance credit.
                    </div>
                </div>
            </div>
        </div>

        {{-- Submit Bar --}}
        <div class="st-submit-bar">
            <a href="{{ route('admin.customer-payments.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-times me-1"></i> Cancel
            </a>
            <button type="submit" class="st-btn-submit {{ $oldType === 'discount' ? 'discount-btn' : '' }}{{ $oldType === 'write_off' ? 'writeoff-btn' : '' }}{{ $oldType === 'payment' ? 'refund-btn' : '' }}" id="submitBtn">
                <i class="fas {{ $cfg['submit_icon'] }} me-1"></i>
                <span id="submitLabel">{{ $cfg['submit_label'] }}</span>
            </button>
        </div>
    </form>
</div>

{{-- Boot config for JS --}}
<script>
    window.CP_BOOT = {
        baseUrl: '{{ url("/") }}/',
        csrf_token: '{{ csrf_token() }}',
        customerReceivables: @json($customerReceivablesData),
        isAdmin: {{ $isAdmin ? 'true' : 'false' }},
        userBranchId: {{ $userBranchId ?? 0 }},
        routes: {
            'index': '{{ route("admin.customer-payments.index") }}',
            'store': '{{ route("admin.customer-payments.store") }}',
            'outstanding-invoices': '{{ route("admin.customer-payments.outstanding-invoices") }}',
            'get-customer-due': '{{ route("admin.customer-payments.get-customer-due") }}',
            'cancel': '{{ route("admin.customer-payments.cancel", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'print-receipt': '{{ route("admin.customer-payments.print-receipt", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
        },
    };
</script>

@push('scripts')
<link rel="stylesheet" href="/assets/css/supplier-transaction-theme.css">
<script>
$(function () {
    var $form          = $('#paymentForm');
    var $customer      = $('#customer_id');
    var $mode          = $('#payment_mode');
    var $bankField     = $('#bankField');
    var $bankId        = $('#bank_id');
    var $amount        = $('#amount');
    var $tbody         = $('#allocBody');
    var $allocTotal    = $('#allocTotal');
    var $allocPayAmt   = $('#allocPaymentAmt');
    var $allocUnalloc  = $('#allocUnallocated');
    var $allocError    = $('#allocError');
    var $allocErrorMsg = $('#allocErrorMsg');
    var $allocStatus   = $('#allocationStatus');
    var $transType     = $('#transaction_type');
    var $discount      = $('#discount_amount');

    // ====== Type configuration ======
    var typeConfig = {
        receive: {
            icon: 'fa-hand-holding-dollar',
            gradient: 'linear-gradient(135deg,#059669,#0d9488)',
            gl_info: 'Dr Bank/Cash · Cr Accounts Receivable',
            submit_label: 'Record Payment',
            submit_icon: 'fa-floppy-disk',
            hint: 'Customer paying us — money received.',
            amount_label: 'Amount (Tk)',
            discount_visible: true,
            bank_visible: true,
            mode_default: 'cash',
            alloc_label: 'Allocate (Tk)',
            alloc_subtitle: '(optional — pick a customer to load outstanding invoices)',
            hero_class: '',
            submit_class: '',
            gl_debit: 'Bank / Cash',
            gl_credit: 'Accounts Receivable',
            gl_debit_sub: 'Increases bank/cash balance',
            gl_credit_sub: 'Reduces receivable balance',
            sub_ledger: 'Credit customer_ledger (reduce AR)',
            bank_book: 'Increase (if bank mode)',
        },
        discount: {
            icon: 'fa-tags',
            gradient: 'linear-gradient(135deg,#7c3aed,#6d28d9)',
            gl_info: 'Dr Sales Discount · Cr Accounts Receivable',
            submit_label: 'Record Discount',
            submit_icon: 'fa-floppy-disk',
            hint: 'Discount allowed to customer — reduces AR, no money received.',
            amount_label: 'Discount amount (Tk)',
            discount_visible: false,
            bank_visible: false,
            mode_default: 'adjustment',
            alloc_label: 'Allocate (Tk)',
            alloc_subtitle: '(optional — pick a customer to load outstanding invoices)',
            hero_class: 'discount-hero',
            submit_class: 'discount-btn',
            gl_debit: 'Sales Discount',
            gl_credit: 'Accounts Receivable',
            gl_debit_sub: 'Increases discount expense',
            gl_credit_sub: 'Reduces receivable balance',
            sub_ledger: 'Credit customer_ledger (reduce AR)',
            bank_book: 'No change',
        },
        write_off: {
            icon: 'fa-file-circle-xmark',
            gradient: 'linear-gradient(135deg,#dc2626,#b91c1c)',
            gl_info: 'Dr Bad Debt Expense · Cr Accounts Receivable',
            submit_label: 'Write Off',
            submit_icon: 'fa-file-circle-xmark',
            hint: 'Bad debt write-off — uncollectable amount removed from AR.',
            amount_label: 'Write-off amount (Tk)',
            discount_visible: false,
            bank_visible: false,
            mode_default: 'adjustment',
            alloc_label: 'Allocate (Tk)',
            alloc_subtitle: '(optional — pick a customer to load outstanding invoices)',
            hero_class: 'writeoff-hero',
            submit_class: 'writeoff-btn',
            gl_debit: 'Bad Debt Expense',
            gl_credit: 'Accounts Receivable',
            gl_debit_sub: 'Increases bad debt expense',
            gl_credit_sub: 'Reduces receivable balance',
            sub_ledger: 'Credit customer_ledger (reduce AR)',
            bank_book: 'No change',
        },
        payment: {
            icon: 'fa-rotate-left',
            gradient: 'linear-gradient(135deg,#f59e0b,#d97706)',
            gl_info: 'Dr Accounts Receivable · Cr Bank/Cash',
            submit_label: 'Issue Refund',
            submit_icon: 'fa-rotate-left',
            hint: 'Refund to customer — money returned, AR increases.',
            amount_label: 'Refund amount (Tk)',
            discount_visible: false,
            bank_visible: true,
            mode_default: 'cash',
            alloc_label: 'Reverse allocate (Tk)',
            alloc_subtitle: '(optional — select invoices to reverse allocation)',
            hero_class: 'refund-hero',
            submit_class: 'refund-btn',
            gl_debit: 'Accounts Receivable',
            gl_credit: 'Bank / Cash',
            gl_debit_sub: 'Increases receivable balance',
            gl_credit_sub: 'Decreases bank/cash balance',
            sub_ledger: 'Debit customer_ledger (increase AR)',
            bank_book: 'Decrease (if bank mode)',
        }
    };

    // ====== Dynamic type switching ======
    function applyTypeConfig(type) {
        var cfg = typeConfig[type] || typeConfig.receive;

        // Hero header
        $('#heroHeader').css('background', cfg.gradient);
        $('#heroHeader').removeClass('discount-hero writeoff-hero refund-hero').addClass(cfg.hero_class);
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
        $btn.removeClass('discount-btn writeoff-btn refund-btn');
        if (cfg.submit_class) $btn.addClass(cfg.submit_class);

        // Discount field visibility
        if (cfg.discount_visible) {
            $('#discountField').show();
        } else {
            $('#discountField').hide();
            $('#discount_amount').val(0);
        }

        // Bank field visibility
        if (!cfg.bank_visible) {
            $bankField.hide();
            $bankId.prop('required', false);
        } else {
            toggleBankField();
        }

        // Payment mode — auto-set to adjustment for discount/write_off
        if (!cfg.bank_visible && $mode.val() !== 'adjustment') {
            $mode.val(cfg.mode_default);
        }

        // Allocation table labels
        $('#allocAmountHeader').text(cfg.alloc_label);

        // Update GL preview
        updateGlPreview();
    }

    $transType.on('change', function () {
        applyTypeConfig($(this).val());
    });

    // ====== Select2 init with customer due badges ======
    $('.select2').select2({
        theme: 'bootstrap-5',
        width: '100%',
        templateResult: function (option) {
            if (!option.id || option.id === '') return option.text;
            var due = $(option.element).data('due');
            var code = $(option.element).data('code');
            var $wrap = $('<div class="st-supplier-option"></div>');
            var $info = $('<div class="st-supplier-info"></div>');
            $info.append('<span class="st-supplier-name">' + option.text.split('—')[0].trim().split('[')[0].trim() + '</span>');
            $info.append('<span class="st-supplier-code">' + (code || '') + '</span>');
            $wrap.append($info);
            if (due !== undefined) {
                var dueNum = parseFloat(due);
                var badgeClass = dueNum > 0 ? 'has-due' : 'no-due';
                $wrap.append('<span class="st-payable-badge ' + badgeClass + '">Due: Tk ' + due + '</span>');
            }
            return $wrap;
        }
    });

    // ====== Show/hide bank field based on payment mode ======
    function toggleBankField() {
        var type = $transType.val();
        var cfg = typeConfig[type] || typeConfig.receive;

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

    // ====== Customer change: update due balance + load invoices ======
    function updateDueBalance() {
        var customerId = parseInt($('#customer_id').val());
        var receivables = window.CP_BOOT && window.CP_BOOT.customerReceivables ? window.CP_BOOT.customerReceivables : {};
        var due = receivables[customerId] || 0;

        $('#dueAmount').text('Tk ' + parseFloat(due).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

        // Update due card color
        var $card = $('#dueSummary');
        if (due > 0) {
            $card.css({ background: 'linear-gradient(135deg, #fffbeb, #fff7ed)', border: '1px solid #fde68a' });
            $('#dueAmount').css('color', '#92400e');
        } else {
            $card.css({ background: 'linear-gradient(135deg, #f0fdf4, #ecfdf5)', border: '1px solid #bbf7d0' });
            $('#dueAmount').css('color', '#065f46');
        }
    }

    function loadInvoices(customerId) {
        if (!customerId) {
            $allocStatus.html('<i class="fas fa-info-circle me-1"></i>No customer selected');
            renderEmpty();
            updateDueBalance();
            return;
        }

        $allocStatus.html('<i class="fas fa-spinner fa-spin me-1"></i>Loading invoices...');
        updateDueBalance();

        $.ajax({
            url: window.CP_BOOT.routes['outstanding-invoices'],
            type: 'GET',
            data: { customer_id: customerId },
            dataType: 'json'
        }).done(function (invoices) {
            renderInvoices(invoices);
            $allocStatus.html('<i class="fas fa-check me-1"></i>' + invoices.length + ' outstanding invoice(s)');
        }).fail(function () {
            renderEmpty();
            $allocStatus.html('<i class="fas fa-triangle-exclamation me-1"></i>Failed to load invoices');
        });
    }

    function renderEmpty() {
        $tbody.html(
            '<tr id="allocPlaceholder"><td colspan="7" class="text-center text-muted py-4">' +
            '<i class="fas fa-circle-info me-1"></i>No outstanding invoices for this customer.' +
            '</td></tr>'
        );
        recomputeTotal();
    }

    function renderInvoices(invoices) {
        $tbody.empty();
        if (!invoices || invoices.length === 0) {
            renderEmpty();
            return;
        }
        invoices.forEach(function (inv) {
            var due = parseFloat(inv.due_amount) || 0;
            var $tr = $('<tr>').attr({
                'data-invoice-id': inv.id,
                'data-due': due.toFixed(2)
            });

            $tr.append(
                $('<td>').append(
                    $('<span>').addClass('fw-semibold').text(inv.invoice_code),
                    $('<input>').attr({ type: 'hidden', name: 'alloc_invoice_id[]', value: inv.id })
                )
            );
            $tr.append($('<td>').addClass('small').text(formatDate(inv.invoice_date)));
            $tr.append($('<td>').addClass('text-end').text(formatMoney(inv.total_amount)));
            $tr.append($('<td>').addClass('text-end').text(formatMoney(inv.paid_amount)));
            $tr.append($('<td>').addClass('text-end text-danger fw-semibold').text(formatMoney(inv.due_amount)));

            var $input = $('<input>').attr({
                type: 'number',
                name: 'alloc_amount[]',
                class: 'form-control form-control-sm text-end alloc-input',
                min: '0',
                step: '0.01',
                max: due.toFixed(2),
                value: '0',
                placeholder: '0.00'
            });
            $tr.append($('<td>').addClass('text-end').append($input));

            var $clear = $('<button>').attr({
                type: 'button',
                class: 'btn btn-sm btn-outline-danger alloc-clear',
                title: 'Clear allocation'
            }).html('<i class="fas fa-times"></i>');
            $tr.append($('<td>').addClass('text-center').append($clear));

            $tbody.append($tr);
        });
        recomputeTotal();
    }

    function formatDate(s) {
        if (!s) return '—';
        var d = new Date(s);
        if (isNaN(d.getTime())) return s;
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return String(d.getDate()).padStart(2,'0') + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
    }

    function formatMoney(n) {
        return parseFloat(n || 0).toFixed(2);
    }

    // ====== Recompute allocation total ======
    function recomputeTotal() {
        var total = 0;
        $tbody.find('tr').each(function () {
            var v = parseFloat($(this).find('.alloc-input').val());
            if (!isNaN(v) && v > 0) total += v;
        });
        var pay = parseFloat($amount.val()) || 0;

        $allocTotal.text(total.toFixed(2));
        $allocPayAmt.text(pay.toFixed(2));
        $allocUnalloc.text((pay - total).toFixed(2));

        if (total > pay + 0.01) {
            $allocError.removeClass('d-none');
            $allocErrorMsg.text('Total allocated (Tk ' + total.toFixed(2) +
                ') exceeds payment amount (Tk ' + pay.toFixed(2) + ').');
        } else {
            $allocError.addClass('d-none');
        }
    }

    // ====== GL Accounting Preview updater ======
    function updateGlPreview() {
        var type = $transType.val();
        var cfg = typeConfig[type] || typeConfig.receive;
        var amount = parseFloat($amount.val()) || 0;
        var disc = parseFloat($discount.val()) || 0;

        if (amount <= 0) {
            $('#glEmpty').show();
            $('#glEntries').hide();
            return;
        }

        $('#glEmpty').hide();
        $('#glEntries').show();

        // For receive type with discount, debit shows bank + discount, credit shows AR
        var debitAmount = amount;
        var creditAmount = amount;
        var netEffect = 'Balanced';

        if (type === 'receive' && disc > 0.001) {
            debitAmount = amount; // Bank/Cash
            creditAmount = amount + disc; // AR
            netEffect = 'Discount: Tk ' + disc.toLocaleString('en-IN', { minimumFractionDigits: 2 });
        }

        $('#glDebitLabel').text('Dr ' + cfg.gl_debit);
        $('#glDebitSub').text(cfg.gl_debit_sub);
        $('#glDebitAmount').text('Tk ' + debitAmount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

        $('#glCreditLabel').text('Cr ' + cfg.gl_credit);
        $('#glCreditSub').text(cfg.gl_credit_sub);
        $('#glCreditAmount').text('Tk ' + creditAmount.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

        if (type === 'receive' && disc > 0.001) {
            netEffect = 'Discount: Tk ' + disc.toLocaleString('en-IN', { minimumFractionDigits: 2 });
        }
        $('#glNetEffect').text(netEffect);
    }

    // ====== Events ======
    $customer.on('change', function () { loadInvoices($(this).val()); });
    $amount.on('input', function () { recomputeTotal(); updateGlPreview(); });
    $discount.on('input', updateGlPreview);
    $transType.on('change', function () { applyTypeConfig($(this).val()); updateGlPreview(); });
    $mode.on('change', function () { toggleBankField(); updateGlPreview(); });

    // Delegated handlers for dynamically-injected rows
    $(document).on('input', '.alloc-input', recomputeTotal);
    $(document).on('click', '.alloc-clear', function () {
        $(this).closest('tr').find('.alloc-input').val(0);
        recomputeTotal();
    });

    // ====== Branch: non-admin auto-select ======
    if (!window.CP_BOOT.isAdmin && window.CP_BOOT.userBranchId) {
        $('#branch_id').val(window.CP_BOOT.userBranchId).trigger('change');
    }

    // ====== Submit guard ======
    $form.on('submit', function (e) {
        var pay   = parseFloat($amount.val()) || 0;
        var total = 0;
        var anyAllocated = false;
        var overDue = false;

        $tbody.find('tr').each(function () {
            var v  = parseFloat($(this).find('.alloc-input').val());
            var due = parseFloat($(this).data('due'));
            if (!isNaN(v) && v > 0) {
                total += v;
                anyAllocated = true;
                if (!isNaN(due) && v > due + 0.01) overDue = true;
            }
        });

        if (pay <= 0) {
            e.preventDefault();
            Swal.fire({
                icon: 'error', title: 'Invalid amount',
                text: 'Payment amount must be greater than 0.',
                confirmButtonText: 'OK'
            });
            return false;
        }

        if (overDue) {
            e.preventDefault();
            Swal.fire({
                icon: 'error', title: 'Allocation exceeds due',
                text: 'One or more allocations exceed the invoice due amount.',
                confirmButtonText: 'OK'
            });
            return false;
        }

        if (total > pay + 0.01) {
            e.preventDefault();
            Swal.fire({
                icon: 'error', title: 'Allocation mismatch',
                text: 'Total allocated (Tk ' + total.toFixed(2) +
                      ') exceeds payment amount (Tk ' + pay.toFixed(2) + ').',
                confirmButtonText: 'OK'
            });
            return false;
        }

        $('#submitBtn').prop('disabled', true)
            .html('<i class="fas fa-spinner fa-spin me-1"></i> Processing...');
    });

    // ====== Apply initial type config ======
    applyTypeConfig('{{ $oldType }}');
    updateDueBalance();
    updateGlPreview();

    // ====== If customer is preselected (e.g., via query string), trigger initial load ======
    @if (!empty($selectedCustomerId) && $preloadedInvoices->isEmpty())
        loadInvoices('{{ $selectedCustomerId }}');
    @endif
});
</script>
@endpush
@endsection
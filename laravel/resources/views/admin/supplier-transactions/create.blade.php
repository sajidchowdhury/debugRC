@extends('layouts.admin')

@section('content')
@php
    $today      = now()->format('Y-m-d');
    $oldDate    = old('payment_date', $today);
    $oldSupp    = old('supplier_id', $preselectSupplier->id ?? null);
    $oldBr      = old('branch_id', $userBranchId ?? session('branch_id'));
    $oldBank    = old('bank_id');
    $oldMode    = old('payment_mode', $transactionType === 'receive' ? 'adjustment' : 'cash');
    $oldType    = old('transaction_type', $transactionType ?? 'payment');
    $oldAmt     = old('amount');
    $oldDisc    = old('discount_amount', 0);
    $oldRef     = old('reference_no');
    $oldNotes   = old('notes');
    $oldColl    = old('collected_by');

    // Type-specific configuration.
    $typeConfig = [
        'payment' => [
            'icon' => 'fa-money-bill-transfer',
            'gradient' => 'linear-gradient(135deg,#0d9488,#059669)',
            'gl_info' => 'Dr Accounts Payable · Cr Bank/Cash',
            'submit_label' => 'Record Payment',
            'submit_icon' => 'fa-floppy-disk',
            'hint' => 'Pay supplier — reduces payable (Dr AP, Cr cash/bank). Updates supplier_ledger and bank book.',
            'amount_label' => 'Payment amount (Tk)',
            'gl_debit' => 'Accounts Payable',
            'gl_credit' => 'Bank / Cash',
        ],
        'advance' => [
            'icon' => 'fa-forward',
            'gradient' => 'linear-gradient(135deg,#0d9488,#059669)',
            'gl_info' => 'Dr Accounts Payable · Cr Bank/Cash',
            'submit_label' => 'Record Advance',
            'submit_icon' => 'fa-floppy-disk',
            'hint' => 'Advance to supplier — same GL flow as payment (Dr AP, Cr cash/bank).',
            'amount_label' => 'Advance amount (Tk)',
            'gl_debit' => 'Accounts Payable',
            'gl_credit' => 'Bank / Cash',
        ],
        'receive' => [
            'icon' => 'fa-truck-ramp-box',
            'gradient' => 'linear-gradient(135deg,#7c3aed,#6d28d9)',
            'gl_info' => 'Dr Inventory · Cr Accounts Payable',
            'submit_label' => 'Record Receive',
            'submit_icon' => 'fa-floppy-disk',
            'hint' => 'Receive from supplier (credit/refund) — increases payable (Dr Inventory, Cr AP).',
            'amount_label' => 'Receive amount (Tk)',
            'gl_debit' => 'Inventory',
            'gl_credit' => 'Accounts Payable',
        ],
    ];
    $cfg = $typeConfig[$oldType] ?? $typeConfig['payment'];

    // Pre-compute data for @json() directives (avoids commas inside @json()
    // which breaks Laravel's compileJson() — it uses explode(',', $expression, 2))
    $preselectSupplierData = $preselectSupplier
        ? ['id' => $preselectSupplier->id, 'supplier_name' => $preselectSupplier->supplier_name, 'supplier_code' => $preselectSupplier->supplier_code ?? '', 'mobile' => $preselectSupplier->mobile ?? null]
        : null;
    $glLabels = $glPreviewLabels ?? [
        'payment' => 'Dr AP · Cr Bank/Cash',
        'advance' => 'Dr AP · Cr Bank/Cash',
        'receive' => 'Dr Inventory · Cr AP',
    ];
@endphp

<style>
    .st-create-page { --st-primary: #0d9488; --st-primary-dark: #059669; --st-accent: #7c3aed; }
    .st-hero {
        background: var(--st-primary);
        background: linear-gradient(135deg, var(--st-primary), var(--st-primary-dark));
        border-radius: 1rem;
        padding: 1.5rem 1.75rem;
        color: #fff;
        box-shadow: 0 8px 32px rgba(13,148,136,0.18);
        margin-bottom: 1.5rem;
    }
    .st-hero.receive-hero {
        background: linear-gradient(135deg, #7c3aed, #6d28d9);
        box-shadow: 0 8px 32px rgba(124,58,237,0.18);
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

    /* Supplier select with payable badge */
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
        background: linear-gradient(135deg, #0d9488, #059669);
        color: #fff;
        border: none;
        border-radius: 0.5rem;
        padding: 0.6rem 1.5rem;
        font-weight: 600;
        font-size: 0.9rem;
        transition: all 0.2s;
        box-shadow: 0 4px 12px rgba(13,148,136,0.3);
    }
    .st-btn-submit:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(13,148,136,0.4); }
    .st-btn-submit.receive-btn {
        background: linear-gradient(135deg, #7c3aed, #6d28d9);
        box-shadow: 0 4px 12px rgba(124,58,237,0.3);
    }
    .st-btn-submit.receive-btn:hover { box-shadow: 0 6px 20px rgba(124,58,237,0.4); }

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
    <header class="st-hero {{ $oldType === 'receive' ? 'receive-hero' : '' }}" id="heroHeader">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1><i class="fas {{ $cfg['icon'] }} me-2"></i>{{ $title }}</h1>
                <p class="st-subtitle mb-0">
                    <i class="fas fa-calculator me-1"></i> GL: <strong>{{ $cfg['gl_info'] }}</strong> &nbsp;·&nbsp;
                    <i class="fas fa-book me-1"></i> Supplier ledger + bank book auto-updated
                </p>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('admin.supplier-transactions.index') }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back
                </a>
            </div>
        </div>
    </header>

    <form method="POST" action="{{ route('admin.supplier-transactions.store') }}" id="supplierTransactionForm" novalidate>
        @csrf

        {{-- Row 1: Transaction Type + Supplier + Due Balance --}}
        <div class="row g-3 mb-0">
            {{-- Transaction Type --}}
            <div class="col-lg-3 col-md-6">
                <div class="st-section-card h-100">
                    <div class="st-section-header">
                        <div class="st-section-icon" style="background:linear-gradient(135deg,#0d9488,#059669);">
                            <i class="fas fa-sliders"></i>
                        </div>
                        <h2>Transaction Type</h2>
                    </div>
                    <div class="st-section-body">
                        <select id="transaction_type" name="transaction_type"
                                class="form-select @error('transaction_type') is-invalid @enderror" required>
                            <option value="payment" {{ $oldType === 'payment' ? 'selected' : '' }}>💰 Supplier Payment</option>
                            <option value="advance" {{ $oldType === 'advance' ? 'selected' : '' }}>⏩ Advance Payment</option>
                            <option value="receive" {{ $oldType === 'receive' ? 'selected' : '' }}>📦 Credit Receive</option>
                        </select>
                        @error('transaction_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="st-form-hint mt-2" id="typeHint">
                            <i class="fas fa-info-circle me-1"></i>
                            <span id="typeHintText">{{ $cfg['hint'] }}</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Supplier Selection --}}
            <div class="col-lg-5 col-md-6">
                <div class="st-section-card h-100">
                    <div class="st-section-header">
                        <div class="st-section-icon" style="background:linear-gradient(135deg,#c2410c,#ea580c);">
                            <i class="fas fa-building"></i>
                        </div>
                        <h2>Supplier</h2>
                    </div>
                    <div class="st-section-body">
                        <label class="st-form-label">Select Supplier <span class="st-required">*</span></label>
                        <select id="supplier_id" name="supplier_id"
                                class="form-select select2 @error('supplier_id') is-invalid @enderror" required>
                            <option value="">Choose a supplier…</option>
                            @foreach ($suppliers as $s)
                                @php
                                    $due = $supplierPayables[$s->id] ?? 0;
                                    $selected = ((string) $oldSupp === (string) $s->id) || ($preselectSupplier && (string) $preselectSupplier->id === (string) $s->id);
                                @endphp
                                <option value="{{ $s->id }}" {{ $selected ? 'selected' : '' }}
                                        data-due="{{ number_format($due, 2) }}"
                                        data-code="{{ $s->supplier_code ?? '' }}">
                                    {{ $s->supplier_name }} — {{ $s->supplier_code ?? '' }}
                                    @if($due > 0) [Due: Tk {{ number_format($due, 2) }}] @endif
                                </option>
                            @endforeach
                        </select>
                        @error('supplier_id') <div class="invalid-feedback">{{ $message }}</div> @enderror

                        {{-- Supplier hub link --}}
                        <div id="suppTxnSupplierHubLink" class="mt-2" style="display:none;">
                            <a id="suppTxnSupplierHubAnchor" href="#" target="_blank" class="small text-muted">
                                <i class="fas fa-external-link-alt me-1"></i> View supplier profile
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Payable Now --}}
            <div class="col-lg-4">
                <div class="st-section-card h-100">
                    <div class="st-section-header">
                        <div class="st-section-icon" style="background:linear-gradient(135deg,#b45309,#d97706);">
                            <i class="fas fa-taka-sign"></i>
                        </div>
                        <h2>Payable Now</h2>
                    </div>
                    <div class="st-section-body d-flex align-items-center justify-content-center">
                        <div class="st-due-card w-100" id="dueSummary">
                            <div class="st-due-amount" id="dueAmount">Tk 0.00</div>
                            <div class="st-due-label">Outstanding Payable</div>
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
                                class="form-select select2 @error('branch_id') is-invalid @enderror" required>
                            @if ($isAdmin)
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
                        @if (!$isAdmin)
                            <div class="st-form-hint"><i class="fas fa-lock me-1"></i> Your assigned branch</div>
                        @endif
                    </div>

                    {{-- Payment Mode --}}
                    <div class="col-lg-3 col-md-6" id="paymentModeField">
                        <label class="st-form-label" for="mode">Payment Mode <span class="st-required">*</span></label>
                        <select id="mode" name="payment_mode"
                                class="form-select @error('payment_mode') is-invalid @enderror" required>
                            <option value="cash"            {{ $oldMode === 'cash' ? 'selected' : '' }}>💵 Cash</option>
                            <option value="bank"            {{ $oldMode === 'bank' ? 'selected' : '' }}>🏦 Bank Transfer</option>
                            <option value="mobile_banking"  {{ $oldMode === 'mobile_banking' ? 'selected' : '' }}>📱 Mobile Banking</option>
                            <option value="cheque"          {{ $oldMode === 'cheque' ? 'selected' : '' }}>📝 Cheque</option>
                            <option value="adjustment"      {{ $oldMode === 'adjustment' ? 'selected' : '' }}>⚖️ Adjustment</option>
                        </select>
                        @error('payment_mode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Bank --}}
                    <div class="col-lg-3 col-md-6" id="bank_section" style="display:none;">
                        <label class="st-form-label" for="bank_id">Bank <span class="text-muted small">(required for bank mode)</span></label>
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
                    <div class="col-lg-3 col-md-6">
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

        {{-- GL Accounting Preview --}}
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
                                        <span class="st-gl-label" id="glDebitLabel">Dr Accounts Payable</span>
                                        <div class="small text-muted" id="glDebitSub">Reduces payable balance</div>
                                    </div>
                                    <span class="st-gl-amount" id="glDebitAmount">Tk 0.00</span>
                                </div>
                                <div class="st-gl-entry st-gl-credit">
                                    <div>
                                        <span class="st-gl-label" id="glCreditLabel">Cr Bank / Cash</span>
                                        <div class="small text-muted" id="glCreditSub">Decreases bank/cash balance</div>
                                    </div>
                                    <span class="st-gl-amount" id="glCreditAmount">Tk 0.00</span>
                                </div>
                                <div class="st-gl-total-bar">
                                    <span>Net Effect</span>
                                    <span id="glNetEffect">Balanced ✓</span>
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
                                    <strong class="small" id="subLedgerLabel">
                                        @if(in_array($oldType, ['payment', 'advance']))
                                            Debit supplier_ledger
                                        @else
                                            Credit supplier_ledger
                                        @endif
                                    </strong>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-2 rounded" style="background:#eff6ff;">
                                    <div class="small text-muted">Bank Book</div>
                                    <strong class="small" id="bankBookLabel">
                                        @if(in_array($oldType, ['payment', 'advance']))
                                            Decrease (if bank mode)
                                        @else
                                            No change
                                        @endif
                                    </strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Submit Bar --}}
        <div class="st-submit-bar">
            <a href="{{ route('admin.supplier-transactions.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-times me-1"></i> Cancel
            </a>
            <button type="submit" class="st-btn-submit {{ $oldType === 'receive' ? 'receive-btn' : '' }}" id="submitBtn">
                <i class="fas {{ $cfg['submit_icon'] }} me-1"></i>
                <span id="submitLabel">{{ $cfg['submit_label'] }}</span>
            </button>
        </div>
    </form>
</div>

{{-- Boot config for SupplierTransaction.js --}}
<script>
    window.ST_BOOT = {
        baseUrl: '{{ url("/") }}/',
        csrf_token: '{{ csrf_token() }}',
        preselectSupplier: @json($preselectSupplierData),
        supplierPayables: @json($supplierPayables),
        glLabels: @json($glLabels),
        isAdmin: {{ $isAdmin ? 'true' : 'false' }},
        userBranchId: {{ $userBranchId ?? 0 }},
        routes: {
            'index': '{{ route("admin.supplier-transactions.index") }}',
            'show': '{{ route("admin.supplier-transactions.show", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'store': '{{ route("admin.supplier-transactions.store") }}',
            'search': '{{ route("admin.supplier-transactions.search") }}',
            'get-due': '{{ route("admin.supplier-transactions.get-due") }}',
            'reverse': '{{ route("admin.supplier-transactions.reverse", ["id" => "__ID__"]) }}'.replace('__ID__', '{id}'),
            'supplier-show': '{{ url("/admin/suppliers") }}/',
        },
    };
</script>

@push('scripts')
<link rel="stylesheet" href="/assets/css/supplier-transaction-theme.css?v={{ filemtime(public_path('assets/css/supplier-transaction-theme.css')) }}">
<script src="/assets/js/SupplierTransaction.js?v={{ filemtime(public_path('assets/js/SupplierTransaction.js')) }}"></script>
<script>
$(function () {
    $('.select2').select2({
        theme: 'bootstrap-5',
        width: '100%',
        templateResult: function (option) {
            if (!option.id || option.id === '') return option.text;
            var due = $(option.element).data('due');
            var code = $(option.element).data('code');
            var $wrap = $('<div class="st-supplier-option"></div>');
            var $info = $('<div class="st-supplier-info"></div>');
            $info.append('<span class="st-supplier-name">' + option.text.split('—')[0].trim() + '</span>');
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

    // Dynamic type switching
    var $type = $('#transaction_type');
    var $mode = $('#mode');
    var $bankSection = $('#bank_section');
    var $bankId = $('#bank_id');
    var $amount = $('#amount');
    var $discountField = $('#discountField');
    var $glRuleLabel = $('#glRuleLabel');
    var $subLedgerLabel = $('#subLedgerLabel');
    var $bankBookLabel = $('#bankBookLabel');
    var $amountLabel = $('#amountLabel');

    var typeConfigs = {
        payment: {
            gradient: 'linear-gradient(135deg,#0d9488,#059669)',
            icon: 'fa-money-bill-transfer',
            gl_info: 'Dr Accounts Payable · Cr Bank/Cash',
            hint: 'Pay supplier — reduces payable (Dr AP, Cr cash/bank). Updates supplier_ledger and bank book.',
            sub_ledger: 'Debit supplier_ledger (reduce AP)',
            bank_book: 'Decrease (if bank mode)',
            amount_label: 'Payment amount (Tk)',
            submit_label: 'Record Payment',
            discount_visible: true,
            mode_default: 'cash',
            hero_class: '',
            gl_debit: 'Accounts Payable',
            gl_credit: 'Bank / Cash',
            gl_debit_sub: 'Reduces payable balance',
            gl_credit_sub: 'Decreases bank/cash balance',
        },
        advance: {
            gradient: 'linear-gradient(135deg,#0d9488,#059669)',
            icon: 'fa-forward',
            gl_info: 'Dr Accounts Payable · Cr Bank/Cash',
            hint: 'Advance to supplier — same GL flow as payment (Dr AP, Cr cash/bank).',
            sub_ledger: 'Debit supplier_ledger (reduce AP)',
            bank_book: 'Decrease (if bank mode)',
            amount_label: 'Advance amount (Tk)',
            submit_label: 'Record Advance',
            discount_visible: true,
            mode_default: 'cash',
            hero_class: '',
            gl_debit: 'Accounts Payable',
            gl_credit: 'Bank / Cash',
            gl_debit_sub: 'Reduces payable balance',
            gl_credit_sub: 'Decreases bank/cash balance',
        },
        receive: {
            gradient: 'linear-gradient(135deg,#7c3aed,#6d28d9)',
            icon: 'fa-truck-ramp-box',
            gl_info: 'Dr Inventory · Cr Accounts Payable',
            hint: 'Receive from supplier (credit/refund) — increases payable (Dr Inventory, Cr AP).',
            sub_ledger: 'Credit supplier_ledger (increase AP)',
            bank_book: 'No change',
            amount_label: 'Receive amount (Tk)',
            submit_label: 'Record Receive',
            discount_visible: false,
            mode_default: 'adjustment',
            hero_class: 'receive-hero',
            gl_debit: 'Inventory',
            gl_credit: 'Accounts Payable',
            gl_debit_sub: 'Increases inventory value',
            gl_credit_sub: 'Increases payable balance',
        },
    };

    function applyTypeConfig(type) {
        var cfg = typeConfigs[type] || typeConfigs.payment;

        // Hero header
        $('#heroHeader').css('background', cfg.gradient);
        $('#heroHeader').removeClass('receive-hero').addClass(cfg.hero_class);
        $('#heroHeader .h4 i').attr('class', 'fas ' + cfg.icon + ' me-2');
        $('#heroSubtitle strong').text(cfg.gl_info);

        // Type hint
        $('#typeHintText').text(cfg.hint);

        // GL preview labels
        $glRuleLabel.text(cfg.gl_info);
        $subLedgerLabel.text(cfg.sub_ledger);
        $bankBookLabel.text(cfg.bank_book);

        // Amount label
        $amountLabel.contents().first().text(cfg.amount_label + ' ');

        // Submit button
        $('#submitLabel').text(cfg.submit_label);
        var $btn = $('#submitBtn');
        $btn.removeClass('receive-btn');
        if (type === 'receive') $btn.addClass('receive-btn');

        // Discount field visibility
        if (cfg.discount_visible) {
            $discountField.show();
        } else {
            $discountField.hide();
            $('#discount_amount').val(0);
        }

        // Payment mode — auto-set to adjustment for receive
        if (!cfg.discount_visible && $mode.val() !== 'adjustment') {
            $mode.val(cfg.mode_default);
        }

        // Bank field visibility
        syncBankVisibility();

        // Update GL preview
        updateGlPreview();
    }

    function syncBankVisibility() {
        var mode = $mode.val();
        if (mode === 'bank' || mode === 'cheque') {
            $bankSection.show();
            if (mode === 'bank') {
                $bankId.prop('required', true);
            } else {
                $bankId.prop('required', false);
            }
        } else {
            $bankSection.hide();
            $bankId.prop('required', false);
        }
    }

    // GL Accounting Preview updater
    function updateGlPreview() {
        var type = $type.val();
        var cfg = typeConfigs[type] || typeConfigs.payment;
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

        var discount = parseFloat($('#discount_amount').val()) || 0;
        var netEffect = 'Balanced ✓';
        if (discount > 0) {
            netEffect = 'Discount: Tk ' + discount.toLocaleString('en-IN', { minimumFractionDigits: 2 });
        }
        $('#glNetEffect').text(netEffect);
    }

    // Supplier change → update due balance
    function updateDueBalance() {
        var supplierId = parseInt($('#supplier_id').val());
        var payables = window.ST_BOOT?.supplierPayables || {};
        var due = payables[supplierId] || 0;

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

        // Show supplier hub link
        if (supplierId) {
            $('#suppTxnSupplierHubLink').show();
            $('#suppTxnSupplierHubAnchor').attr('href', window.ST_BOOT?.routes?.['supplier-show'] + supplierId);
        } else {
            $('#suppTxnSupplierHubLink').hide();
        }
    }

    // Branch: non-admin auto-select
    if (!window.ST_BOOT?.isAdmin && window.ST_BOOT?.userBranchId) {
        $('#branch_id').val(window.ST_BOOT.userBranchId).trigger('change');
    }

    // Event listeners
    $type.on('change', function () { applyTypeConfig($(this).val()); });
    $mode.on('change', syncBankVisibility);
    $amount.on('input', updateGlPreview);
    $('#discount_amount').on('input', updateGlPreview);
    $('#supplier_id').on('change', updateDueBalance);

    // Initialize
    syncBankVisibility();
    updateDueBalance();
    updateGlPreview();
});
</script>
@endpush
@endsection

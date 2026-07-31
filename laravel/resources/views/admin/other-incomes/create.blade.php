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
    .oi-create-page { --oi-primary: #16a34a; --oi-primary-dark: #15803d; }
    .oi-hero {
        background: linear-gradient(135deg, var(--oi-primary), var(--oi-primary-dark));
        border-radius: 1rem;
        padding: 1.5rem 1.75rem;
        color: #fff;
        box-shadow: 0 8px 32px rgba(22,163,74,0.18);
        margin-bottom: 1.5rem;
    }
    .oi-hero h1 { font-size: 1.35rem; font-weight: 700; margin-bottom: 0.15rem; }
    .oi-hero .oi-subtitle { font-size: 0.82rem; opacity: 0.85; }
    .oi-section-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 0.875rem;
        box-shadow: 0 1px 4px rgba(15,23,42,0.04);
        margin-bottom: 1.25rem;
        overflow: hidden;
        transition: box-shadow 0.2s;
    }
    .oi-section-card:hover { box-shadow: 0 4px 16px rgba(15,23,42,0.07); }
    .oi-section-header {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 0.75rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .oi-section-header h2 { font-size: 0.88rem; font-weight: 700; margin: 0; color: #0f172a; }
    .oi-section-header .oi-section-icon {
        width: 32px; height: 32px; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.85rem; color: #fff;
    }
    .oi-section-body { padding: 1.25rem; }

    /* Amount highlight card */
    .oi-amount-card {
        background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
        border: 1px solid #bbf7d0;
        border-radius: 0.75rem;
        padding: 1rem;
        text-align: center;
    }
    .oi-amount-value {
        font-size: 1.5rem;
        font-weight: 800;
        color: #15803d;
        font-variant-numeric: tabular-nums;
    }
    .oi-amount-label {
        font-size: 0.75rem;
        color: #15803d;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    /* GL Preview */
    .oi-gl-preview {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 0.75rem;
        padding: 1rem;
        min-height: 80px;
    }
    .oi-gl-entry {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 0;
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
    .oi-gl-empty { text-align: center; padding: 1.5rem; color: #94a3b8; }
    .oi-gl-empty i { font-size: 2rem; margin-bottom: 0.5rem; display: block; }

    /* Form enhancements */
    .oi-form-label {
        font-size: 0.78rem;
        font-weight: 600;
        color: #475569;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        margin-bottom: 0.25rem;
    }
    .oi-form-label .oi-required { color: #dc2626; margin-left: 2px; }
    .oi-form-hint { font-size: 0.72rem; color: #94a3b8; margin-top: 0.15rem; }

    /* Submit bar */
    .oi-submit-bar {
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
    .oi-btn-submit {
        background: linear-gradient(135deg, #16a34a, #15803d);
        color: #fff;
        border: none;
        border-radius: 0.5rem;
        padding: 0.6rem 1.5rem;
        font-weight: 600;
        font-size: 0.9rem;
        transition: all 0.2s;
        box-shadow: 0 4px 12px rgba(22,163,74,0.3);
    }
    .oi-btn-submit:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(22,163,74,0.4); color: #fff; }
    .oi-btn-submit:disabled { opacity: 0.65; transform: none; box-shadow: none; }

    /* Info banner */
    .oi-info-banner {
        background: linear-gradient(135deg, #f0fdf4, #ecfdf5);
        border: 1px solid #bbf7d0;
        border-left: 4px solid #16a34a;
        border-radius: 0.75rem;
        padding: 1rem 1.25rem;
        margin-bottom: 1.25rem;
    }

    @media (max-width: 768px) {
        .oi-hero { padding: 1rem 1.15rem; border-radius: 0.75rem; }
        .oi-hero h1 { font-size: 1.1rem; }
        .oi-section-body { padding: 0.85rem; }
        .oi-submit-bar { position: static; border-radius: 0.75rem; }
        .oi-amount-value { font-size: 1.25rem; }
    }
</style>

<div class="container-fluid py-3 oi-create-page">
    {{-- Hero header --}}
    <header class="oi-hero">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <h1>
                    <i class="fas fa-arrow-trend-up me-2"></i>Record Other Income
                </h1>
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

    {{-- Info banner --}}
    <div class="oi-info-banner">
        <div class="d-flex align-items-start gap-2">
            <i class="fas fa-circle-info text-success mt-1"></i>
            <div>
                <strong>Other incomes post immediately on save.</strong>
                GL is balanced (Dr Cash/Bank, Cr Income Ledger). No entity sub-ledger is affected — only the Chart of Accounts ledger you select.
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.other-incomes.store') }}" id="otherIncomeForm" novalidate>
        @csrf

        {{-- Row 1: Income Details + Amount Card --}}
        <div class="row g-3 mb-0">
            {{-- Income Details section card --}}
            <div class="col-lg-8">
                <div class="oi-section-card h-100">
                    <div class="oi-section-header">
                        <div class="oi-section-icon" style="background:linear-gradient(135deg,#16a34a,#15803d);">
                            <i class="fas fa-sliders"></i>
                        </div>
                        <h2>Income Details</h2>
                    </div>
                    <div class="oi-section-body">
                        <div class="row g-3">
                            {{-- Income date --}}
                            <div class="col-lg-3 col-md-6">
                                <label class="oi-form-label" for="income_date">
                                    Income Date <span class="oi-required">*</span>
                                </label>
                                <input type="date" id="income_date" name="income_date"
                                       class="form-control @error('income_date') is-invalid @enderror"
                                       required value="{{ $oldDate }}">
                                @error('income_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            {{-- Branch --}}
                            <div class="col-lg-3 col-md-6">
                                <label class="oi-form-label" for="branch_id">
                                    Branch <span class="oi-required">*</span>
                                </label>
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

                            {{-- Income ledger (from Chart of Accounts) --}}
                            <div class="col-lg-3 col-md-6">
                                <label class="oi-form-label" for="ledger_id">
                                    Income Ledger <span class="oi-required">*</span>
                                </label>
                                <select id="ledger_id" name="ledger_id"
                                        class="form-select select2 @error('ledger_id') is-invalid @enderror" required>
                                    <option value="">Select income ledger</option>
                                    @foreach ($incomeLedgers as $ledger)
                                        <option value="{{ $ledger->id }}"
                                            {{ (string) $oldLedger === (string) $ledger->id ? 'selected' : '' }}>
                                            {{ $ledger->ledger_code }} — {{ $ledger->ledger_name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('ledger_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="oi-form-hint">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Select the income account from Chart of Accounts.
                                </div>
                            </div>

                            {{-- Income type (optional label) --}}
                            <div class="col-lg-3 col-md-6">
                                <label class="oi-form-label" for="income_type">Income Type</label>
                                <input type="text" id="income_type" name="income_type"
                                       class="form-control @error('income_type') is-invalid @enderror"
                                       value="{{ $oldType }}"
                                       placeholder="Interest, Rent, Commission">
                                @error('income_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="oi-form-hint">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Optional descriptive label for the income category.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Amount Card --}}
            <div class="col-lg-4">
                <div class="oi-section-card h-100">
                    <div class="oi-section-header">
                        <div class="oi-section-icon" style="background:linear-gradient(135deg,#15803d,#16a34a);">
                            <i class="fas fa-taka-sign"></i>
                        </div>
                        <h2>Amount</h2>
                    </div>
                    <div class="oi-section-body d-flex flex-column justify-content-center">
                        <div class="oi-amount-card w-100">
                            <div class="oi-amount-value" id="amountPreview">Tk 0.00</div>
                            <div class="oi-amount-label">Income Amount</div>
                        </div>
                        <div class="mt-3">
                            <label class="oi-form-label" for="amount">
                                Amount (Tk) <span class="oi-required">*</span>
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
                    </div>
                </div>
            </div>
        </div>

        {{-- Row 2: Payment Details --}}
        <div class="oi-section-card">
            <div class="oi-section-header">
                <div class="oi-section-icon" style="background:linear-gradient(135deg,#2563eb,#3b82f6);">
                    <i class="fas fa-receipt"></i>
                </div>
                <h2>Payment Details</h2>
            </div>
            <div class="oi-section-body">
                <div class="row g-3">
                    {{-- Payment Mode --}}
                    <div class="col-lg-3 col-md-6">
                        <label class="oi-form-label" for="payment_mode">
                            Payment Mode <span class="oi-required">*</span>
                        </label>
                        <select id="payment_mode" name="payment_mode"
                                class="form-select @error('payment_mode') is-invalid @enderror" required>
                            <option value="cash" {{ $oldMode === 'cash' ? 'selected' : '' }}>💵 Cash</option>
                            <option value="bank" {{ $oldMode === 'bank' ? 'selected' : '' }}>🏦 Bank Transfer</option>
                            <option value="mobile_banking" {{ $oldMode === 'mobile_banking' ? 'selected' : '' }}>📱 Mobile Banking</option>
                            <option value="cheque" {{ $oldMode === 'cheque' ? 'selected' : '' }}>📝 Cheque</option>
                        </select>
                        @error('payment_mode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Bank --}}
                    <div class="col-lg-3 col-md-6" id="bank_section" style="display:{{ $oldMode === 'bank' || $oldMode === 'cheque' ? 'block' : 'none' }};">
                        <label class="oi-form-label" for="bank_id">
                            Bank <span class="oi-required">*</span>
                        </label>
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

                    {{-- Description --}}
                    <div class="col-lg-6 col-md-12">
                        <label class="oi-form-label" for="description">Description</label>
                        <textarea id="description" name="description" rows="1" class="form-control"
                                  placeholder="Optional description or reference">{{ $oldDesc }}</textarea>
                        @error('description') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- Row 3: GL Accounting Preview --}}
        <div class="oi-section-card">
            <div class="oi-section-header">
                <div class="oi-section-icon" style="background:linear-gradient(135deg,#0f172a,#334155);">
                    <i class="fas fa-calculator"></i>
                </div>
                <h2>GL Accounting Preview</h2>
            </div>
            <div class="oi-section-body">
                <div class="row g-3">
                    <div class="col-lg-7">
                        <div class="oi-gl-preview" id="glPreviewArea">
                            <div class="oi-gl-empty" id="glEmpty">
                                <i class="fas fa-calculator"></i>
                                Enter an amount to see the GL journal preview
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
                                    <strong class="small" id="glRuleLabel">Dr Cash/Bank · Cr Income</strong>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-2 rounded" style="background:#fff7ed;">
                                    <div class="small text-muted">Sub-Ledger</div>
                                    <strong class="small" id="subLedgerLabel">None — CoA only</strong>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center p-2 rounded" style="background:#eff6ff;">
                                    <div class="small text-muted">Bank Book</div>
                                    <strong class="small" id="bankBookLabel">Increase (if bank)</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Submit Bar --}}
        <div class="oi-submit-bar">
            <a href="{{ route('admin.other-incomes.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-times me-1"></i> Cancel
            </a>
            <button type="submit" class="oi-btn-submit" id="submitBtn">
                <i class="fas fa-floppy-disk me-1"></i>
                <span id="submitLabel">Save Income</span>
            </button>
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
    var $mode        = $('#payment_mode');
    var $bankSection = $('#bank_section');
    var $bankId      = $('#bank_id');
    var $amount      = $('#amount');
    var $ledgerId    = $('#ledger_id');

    // GL preview elements
    var $glPreview   = $('#glPreviewArea');
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

    // Payment mode toggle
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
            $bankId.val('').trigger('change');
        }
        updateGLPreview();
    }

    $mode.on('change', syncBankVisibility);

    // Live GL preview update
    function updateGLPreview() {
        var amount = parseFloat($amount.val()) || 0;
        var mode = $mode.val();
        var ledgerName = $ledgerId.find('option:selected').text();
        var bankName = $bankId.find('option:selected').text();

        // Update amount preview card
        $amountPreview.text('Tk ' + numberFormat(amount));

        if (amount <= 0) {
            $glEmpty.show();
            $glEntries.hide();
            return;
        }

        $glEmpty.hide();
        $glEntries.show();

        // Determine Dr label (Cash or Bank)
        var drLabel = 'Cash in Hand';
        var drSub = 'Cash balance increases';
        var bankBookText = 'No change';
        if (mode === 'bank') {
            drLabel = (bankName && bankName !== 'Select bank') ? 'Bank — ' + bankName : 'Bank Account';
            drSub = 'Bank balance increases';
            bankBookText = 'Increase';
        } else if (mode === 'cheque') {
            drLabel = (bankName && bankName !== 'Select bank') ? 'Bank — ' + bankName : 'Bank Account';
            drSub = 'Bank balance increases (on clearance)';
            bankBookText = 'Increase (on clearance)';
        } else if (mode === 'mobile_banking') {
            drLabel = 'Mobile Banking';
            drSub = 'Mobile balance increases';
            bankBookText = 'Increase';
        }

        // Determine Cr label (selected income ledger)
        var crLabel = (ledgerName && ledgerName !== 'Select income ledger') ? ledgerName : 'Other Income';

        // Update GL entries
        $glDebitLabel.text('Dr ' + drLabel);
        $glDebitSub.text(drSub);
        $glDebitAmount.text('Tk ' + numberFormat(amount));

        $glCreditLabel.text('Cr ' + crLabel);
        $glCreditSub.text('Income ledger records the revenue');
        $glCreditAmount.text('Tk ' + numberFormat(amount));

        $glNetEffect.text('Balanced ✓');

        // Update side info
        $glRuleLabel.text('Dr Cash/Bank · Cr Income');
        $subLedgerLabel.text('None — CoA only');
        $bankBookLabel.text(bankBookText);
    }

    function numberFormat(num) {
        return parseFloat(num).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // Event listeners
    $amount.on('input', updateGLPreview);
    $ledgerId.on('change', updateGLPreview);
    $bankId.on('change', updateGLPreview);

    // Form submission via AJAX with Swal confirmation
    $form.on('submit', function (e) {
        e.preventDefault();

        var amount = parseFloat($amount.val()) || 0;
        var mode = $mode.val();
        var ledgerName = $ledgerId.find('option:selected').text();

        if (amount <= 0) {
            Swal.fire({ icon: 'warning', title: 'Invalid amount', text: 'Please enter a valid amount greater than zero.' });
            return;
        }

        if (!$ledgerId.val()) {
            Swal.fire({ icon: 'warning', title: 'Ledger required', text: 'Please select an income ledger.' });
            return;
        }

        Swal.fire({
            icon: 'question',
            title: 'Record this other income?',
            html: '<p class="text-muted small mb-2">This will post a GL journal entry (Dr ' + (mode === 'bank' || mode === 'cheque' ? 'Bank' : mode === 'mobile_banking' ? 'Mobile' : 'Cash') + ', Cr ' + ledgerName + ').</p>' +
                  '<p class="small"><strong>Amount:</strong> Tk ' + numberFormat(amount) + '</p>',
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
                                // Redirect to the show page of the newly created income
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
                                $.each(errors, function (key, val) {
                                    msg += val[0] + '<br>';
                                });
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

    // Initialize
    syncBankVisibility();
    updateGLPreview();
});
</script>
@endpush
@endsection
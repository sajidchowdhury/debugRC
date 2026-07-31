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
        padding: 0.55rem 1.5rem;
        font-weight: 600;
        font-size: 0.88rem;
        transition: transform 0.15s, box-shadow 0.15s;
        box-shadow: 0 4px 12px rgba(22,163,74,0.25);
    }
    .oi-btn-submit:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(22,163,74,0.3);
        color: #fff;
    }
    .oi-btn-submit:disabled {
        opacity: 0.65;
        transform: none;
        box-shadow: none;
    }

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
                <p class="oi-subtitle mb-2">
                    GL posting: <strong>Dr Cash/Bank · Cr Income Ledger</strong> — no entity sub-ledger.
                </p>
            </div>
            <div>
                <a href="{{ route('admin.other-incomes.index') }}" class="btn btn-outline-light btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Back to list
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

    <form method="POST" action="{{ route('admin.other-incomes.store') }}" id="otherIncomeForm">
        @csrf

        <div class="row g-3">
            {{-- Left: form fields --}}
            <div class="col-lg-8">
                {{-- Income details section card --}}
                <div class="oi-section-card">
                    <div class="oi-section-header">
                        <div class="oi-section-icon" style="background:linear-gradient(135deg,#16a34a,#15803d);">
                            <i class="fas fa-sliders"></i>
                        </div>
                        <h2>Income Details</h2>
                    </div>
                    <div class="oi-section-body">
                        <div class="row g-3">
                            {{-- Income date --}}
                            <div class="col-md-4">
                                <label class="oi-form-label" for="income_date">
                                    Income Date <span class="oi-required">*</span>
                                </label>
                                <input type="date" id="income_date" name="income_date"
                                       class="form-control @error('income_date') is-invalid @enderror"
                                       required value="{{ $oldDate }}">
                                @error('income_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            {{-- Branch --}}
                            <div class="col-md-4">
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
                            <div class="col-md-4">
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
                            <div class="col-md-4">
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

                            {{-- Payment mode --}}
                            <div class="col-md-4">
                                <label class="oi-form-label">
                                    Payment Mode <span class="oi-required">*</span>
                                </label>
                                <div class="d-flex gap-3 mt-1">
                                    <div class="form-check">
                                        <input type="radio" id="mode_cash" name="payment_mode" value="cash"
                                               class="form-check-input" {{ $oldMode === 'cash' ? 'checked' : '' }} required>
                                        <label class="form-check-label" for="mode_cash">
                                            <i class="fas fa-money-bill me-1"></i> Cash
                                        </label>
                                    </div>
                                    <div class="form-check">
                                        <input type="radio" id="mode_bank" name="payment_mode" value="bank"
                                               class="form-check-input" {{ $oldMode === 'bank' ? 'checked' : '' }}>
                                        <label class="form-check-label" for="mode_bank">
                                            <i class="fas fa-university me-1"></i> Bank
                                        </label>
                                    </div>
                                </div>
                                @error('payment_mode') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>

                            {{-- Bank (shown only when payment_mode=bank) --}}
                            <div class="col-md-4" id="bank_section" style="display:{{ $oldMode === 'bank' ? 'block' : 'none' }};">
                                <label class="oi-form-label" for="bank_id">
                                    Bank <span class="oi-required">*</span>
                                </label>
                                <select id="bank_id" name="bank_id"
                                        class="form-select select2 @error('bank_id') is-invalid @enderror">
                                    <option value="">Select bank</option>
                                    @foreach ($banks as $bk)
                                        <option value="{{ $bk->id }}"
                                            {{ (string) $oldBank === (string) $bk->id ? 'selected' : '' }}>
                                            {{ $bk->bank_code }} — {{ $bk->bank_name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('bank_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            {{-- Amount --}}
                            <div class="col-md-4">
                                <label class="oi-form-label" for="amount">
                                    Amount (Tk) <span class="oi-required">*</span>
                                </label>
                                <input type="number" id="amount" name="amount"
                                       class="form-control text-end @error('amount') is-invalid @enderror"
                                       min="0.01" step="0.01" required
                                       value="{{ $oldAmt }}"
                                       placeholder="0.00">
                                @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            {{-- Description --}}
                            <div class="col-md-8">
                                <label class="oi-form-label" for="description">Description</label>
                                <textarea id="description" name="description" rows="1" class="form-control"
                                          placeholder="Optional description or reference">{{ $oldDesc }}</textarea>
                                @error('description') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Right: preview & summary --}}
            <div class="col-lg-4">
                {{-- GL Preview card --}}
                <div class="oi-section-card">
                    <div class="oi-section-header">
                        <div class="oi-section-icon" style="background:linear-gradient(135deg,#0f172a,#334155);">
                            <i class="fas fa-scale-balanced"></i>
                        </div>
                        <h2>GL Journal Preview</h2>
                        <span class="badge bg-success-subtle text-success ms-auto" id="glBalanceBadge">
                            <i class="fas fa-check me-1"></i>Balanced
                        </span>
                    </div>
                    <div class="oi-section-body">
                        <div class="oi-gl-preview" id="glPreviewArea">
                            <div class="oi-gl-empty">
                                <i class="fas fa-scale-balanced"></i>
                                <div class="small">Select head and amount to preview GL effect.</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3 pt-2" style="border-top:1px solid #e2e8f0;">
                            <span class="text-muted small">GL rule</span>
                            <strong class="small" id="glRuleLabel">Dr Cash/Bank · Cr Income</strong>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-1">
                            <span class="text-muted small">Sub-ledger</span>
                            <strong class="small">None — CoA only</strong>
                        </div>
                    </div>
                </div>

                {{-- Quick info card --}}
                <div class="oi-section-card">
                    <div class="oi-section-header">
                        <div class="oi-section-icon" style="background:linear-gradient(135deg,#16a34a,#15803d);">
                            <i class="fas fa-lightbulb"></i>
                        </div>
                        <h2>How It Works</h2>
                    </div>
                    <div class="oi-section-body">
                        <div class="small text-muted">
                            <p class="mb-2"><i class="fas fa-arrow-right text-success me-1"></i> <strong>Debit:</strong> Cash or Bank account receives money</p>
                            <p class="mb-2"><i class="fas fa-arrow-left text-danger me-1"></i> <strong>Credit:</strong> Income ledger records the revenue</p>
                            <p class="mb-0"><i class="fas fa-check text-success me-1"></i> No entity sub-ledger — only Chart of Accounts is affected</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Submit bar --}}
        <div class="oi-submit-bar">
            <a href="{{ route('admin.other-incomes.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-times me-1"></i> Cancel
            </a>
            <button type="submit" class="oi-btn-submit" id="submitBtn">
                <i class="fas fa-check me-1"></i>
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
    var $mode        = $('input[name="payment_mode"]');
    var $bankSection = $('#bank_section');
    var $bankId      = $('#bank_id');
    var $amount      = $('#amount');
    var $ledgerId    = $('#ledger_id');

    // GL preview elements
    var $glPreview   = $('#glPreviewArea');
    var $glBadge     = $('#glBalanceBadge');

    // Payment mode toggle
    function toggleBankSection() {
        var mode = $('input[name="payment_mode"]:checked').val();
        if (mode === 'bank') {
            $bankSection.show();
            $bankId.prop('required', true);
        } else {
            $bankSection.hide();
            $bankId.prop('required', false);
            $bankId.val('').trigger('change');
        }
        updateGLPreview();
    }

    $mode.on('change', toggleBankSection);

    // Live GL preview update
    function updateGLPreview() {
        var amount = parseFloat($amount.val()) || 0;
        var mode = $('input[name="payment_mode"]:checked').val();
        var ledgerName = $ledgerId.find('option:selected').text();
        var bankName = $bankId.find('option:selected').text();

        if (amount <= 0) {
            $glPreview.html(
                '<div class="oi-gl-empty">' +
                '<i class="fas fa-scale-balanced"></i>' +
                '<div class="small">Enter an amount to see the GL journal preview.</div>' +
                '</div>'
            );
            $glBadge.html('<i class="fas fa-check me-1"></i>Balanced').removeClass('bg-danger-subtle text-danger').addClass('bg-success-subtle text-success');
            return;
        }

        // Determine Dr label (Cash or Bank)
        var drLabel = 'Cash in Hand';
        if (mode === 'bank') {
            drLabel = (bankName && bankName !== 'Select bank') ? 'Bank — ' + bankName : 'Bank Account';
        }

        // Determine Cr label (selected income ledger)
        var crLabel = (ledgerName && ledgerName !== 'Select income ledger') ? ledgerName : 'Other Income';

        var html = '';
        // Debit entry
        html += '<div class="oi-gl-entry oi-gl-debit">' +
                '<div class="oi-gl-label"><i class="fas fa-arrow-right me-1"></i>' + drLabel + '</div>' +
                '<div class="oi-gl-amount">Tk ' + numberFormat(amount) + '</div>' +
                '</div>';
        // Credit entry
        html += '<div class="oi-gl-entry oi-gl-credit">' +
                '<div class="oi-gl-label"><i class="fas fa-arrow-left me-1"></i>' + crLabel + '</div>' +
                '<div class="oi-gl-amount">Tk ' + numberFormat(amount) + '</div>' +
                '</div>';
        // Total bar
        html += '<div class="oi-gl-total-bar">' +
                '<span>Dr Total: ' + numberFormat(amount) + '</span>' +
                '<span>Cr Total: ' + numberFormat(amount) + '</span>' +
                '</div>';

        $glPreview.html(html);
        $glBadge.html('<i class="fas fa-check me-1"></i>Balanced').removeClass('bg-danger-subtle text-danger').addClass('bg-success-subtle text-success');
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
        var mode = $('input[name="payment_mode"]:checked').val();
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
            html: '<p class="text-muted small mb-2">This will post a GL journal entry (Dr ' + (mode === 'bank' ? 'Bank' : 'Cash') + ', Cr ' + ledgerName + ').</p>' +
                  '<p class="small"><strong>Amount:</strong> Tk ' + numberFormat(amount) + '</p>',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check"></i> Save Income',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#16a34a',
            reverseButtons: true,
        }).then(function (result) {
            if (result.isConfirmed) {
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
                                var redirectUrl = resp.redirect || '{{ route("admin.other-incomes.index") }}';
                                window.location.href = redirectUrl;
                            });
                        } else {
                            Swal.fire('Error', resp.message || 'Failed to save.', 'error');
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
                    }
                });
            }
        });
    });

    // Initialize
    toggleBankSection();
    updateGLPreview();
});
</script>
@endpush
@endsection
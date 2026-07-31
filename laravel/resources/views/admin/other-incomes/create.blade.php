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

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#16a34a,#15803d);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-arrow-trend-up me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                GL posting: <strong>Dr Cash/Bank · Cr Income Ledger</strong> — no entity sub-ledger.
            </p>
        </div>
        <div>
            <a href="{{ route('admin.other-incomes.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Info banner --}}
    <div class="alert alert-info d-flex align-items-start mb-3" role="alert">
        <i class="fas fa-circle-info me-2 mt-1"></i>
        <div>
            <strong>Other incomes post immediately on save.</strong>
            GL is balanced (Dr Cash/Bank, Cr Income Ledger). No entity sub-ledger is affected — only the Chart of Accounts ledger you select.
        </div>
    </div>

    <form method="POST" action="{{ route('admin.other-incomes.store') }}" id="otherIncomeForm">
        @csrf

        {{-- Income details card --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-sliders me-1 text-success"></i> Income details</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    {{-- Income date --}}
                    <div class="col-md-3">
                        <label class="form-label" for="income_date">
                            Income date <span class="text-danger">*</span>
                        </label>
                        <input type="date" id="income_date" name="income_date"
                               class="form-control @error('income_date') is-invalid @enderror"
                               required value="{{ $oldDate }}">
                        @error('income_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Branch --}}
                    <div class="col-md-3">
                        <label class="form-label" for="branch_id">
                            Branch <span class="text-danger">*</span>
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
                    <div class="col-md-3">
                        <label class="form-label" for="ledger_id">
                            Income ledger <span class="text-danger">*</span>
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
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            Select the income account from Chart of Accounts.
                        </div>
                    </div>

                    {{-- Income type (optional label) --}}
                    <div class="col-md-3">
                        <label class="form-label" for="income_type">Income type</label>
                        <input type="text" id="income_type" name="income_type"
                               class="form-control @error('income_type') is-invalid @enderror"
                               value="{{ $oldType }}"
                               placeholder="Interest, Rent, Commission">
                        @error('income_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            Optional descriptive label for the income category.
                        </div>
                    </div>

                    {{-- Payment mode --}}
                    <div class="col-md-3">
                        <label class="form-label">
                            Payment mode <span class="text-danger">*</span>
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
                    <div class="col-md-3" id="bank_section" style="display:{{ $oldMode === 'bank' ? 'block' : 'none' }};">
                        <label class="form-label" for="bank_id">
                            Bank <span class="text-danger">*</span>
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
                    <div class="col-md-3">
                        <label class="form-label" for="amount">
                            Amount (Tk) <span class="text-danger">*</span>
                        </label>
                        <input type="number" id="amount" name="amount"
                               class="form-control text-end @error('amount') is-invalid @enderror"
                               min="0.01" step="0.01" required
                               value="{{ $oldAmt }}"
                               placeholder="0.00">
                        @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Description --}}
                    <div class="col-md-3">
                        <label class="form-label" for="description">Description</label>
                        <textarea id="description" name="description" rows="1" class="form-control"
                                  placeholder="Optional description or reference">{{ $oldDesc }}</textarea>
                        @error('description') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- GL Accounting Preview card --}}
        <div class="card border-0 shadow-sm mb-3" id="glPreviewCard">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">
                    <i class="fas fa-scale-balanced me-1 text-success"></i> GL Journal Preview
                    <span class="text-muted small ms-2">(live — updates as you type)</span>
                </h2>
                <span class="badge bg-success-subtle text-success" id="glBalanceBadge">
                    <i class="fas fa-check me-1"></i>Balanced
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0" id="glPreviewTable">
                        <thead class="table-light">
                            <tr>
                                <th style="width:5%;">#</th>
                                <th style="width:40%;">Ledger Account</th>
                                <th class="text-end" style="width:25%;">Debit (Tk)</th>
                                <th class="text-end" style="width:25%;">Credit (Tk)</th>
                                <th style="width:5%;"></th>
                            </tr>
                        </thead>
                        <tbody id="glPreviewBody">
                            {{-- Rows injected by JS --}}
                        </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="2" class="text-end">Total</td>
                                <td class="text-end" id="glTotalDebit">0.00</td>
                                <td class="text-end" id="glTotalCredit">0.00</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="p-2 small text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    This preview shows the GL journal entry that will be posted when you save. The debit side is Cash/Bank, the credit side is the selected income ledger.
                </div>
            </div>
            <div class="card-footer bg-white">
                <div class="row g-2 small">
                    <div class="col-md-6">
                        <span class="text-muted">GL rule:</span>
                        <strong id="glRuleLabel">Dr Cash/Bank · Cr Income Ledger</strong>
                    </div>
                    <div class="col-md-6">
                        <span class="text-muted">Sub-ledger:</span>
                        <strong>None — Chart of Accounts only</strong>
                    </div>
                </div>
            </div>
        </div>

        {{-- Submit --}}
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex gap-2 justify-content-end">
                <a href="{{ route('admin.other-incomes.index') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-success" id="submitBtn">
                    <i class="fas fa-check me-1"></i>
                    <span id="submitLabel">Save Income</span>
                </button>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
$(function () {
    var $form        = $('#otherIncomeForm');
    var $mode        = $('input[name="payment_mode"]');
    var $bankSection = $('#bank_section');
    var $bankId      = $('#bank_id');
    var $amount      = $('#amount');
    var $ledgerId    = $('#ledger_id');
    var $branchId    = $('#branch_id');

    // GL preview elements
    var $glBody      = $('#glPreviewBody');
    var $glTotalDr   = $('#glTotalDebit');
    var $glTotalCr   = $('#glTotalCredit');
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

        $glBody.empty();

        if (amount <= 0) {
            $glBody.append(
                '<tr><td colspan="5" class="text-center text-muted py-3">' +
                '<i class="fas fa-info-circle me-1"></i>Enter an amount to see the GL journal preview.' +
                '</td></tr>'
            );
            $glTotalDr.text('0.00');
            $glTotalCr.text('0.00');
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

        // Debit row
        $glBody.append(
            '<tr>' +
            '<td>1</td>' +
            '<td><span class="fw-semibold">' + drLabel + '</span> <span class="badge bg-success-subtle text-success ms-1">Dr</span></td>' +
            '<td class="text-end fw-semibold">' + numberFormat(amount) + '</td>' +
            '<td class="text-end text-muted">&mdash;</td>' +
            '<td></td>' +
            '</tr>'
        );

        // Credit row
        $glBody.append(
            '<tr>' +
            '<td>2</td>' +
            '<td><span class="fw-semibold">' + crLabel + '</span> <span class="badge bg-danger-subtle text-danger ms-1">Cr</span></td>' +
            '<td class="text-end text-muted">&mdash;</td>' +
            '<td class="text-end fw-semibold">' + numberFormat(amount) + '</td>' +
            '<td></td>' +
            '</tr>'
        );

        // Totals
        $glTotalDr.text(numberFormat(amount));
        $glTotalCr.text(numberFormat(amount));

        // Balance check
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

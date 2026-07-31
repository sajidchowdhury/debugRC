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

    // Build expense ledger options as JS-accessible data
    $ledgersJson = [];
    foreach ($expenseLedgers as $l) {
        $ledgersJson[] = ['id' => $l->id, 'name' => $l->ledger_name, 'code' => $l->ledger_code ?? ''];
    }
    $ledgersJsonStr = json_encode($ledgersJson, JSON_UNESCAPED_UNICODE);

    // GL preview labels from controller
    $glDrLabel = $glPreviewLabels['dr'] ?? 'Operating Expense';
    $glCrLabel = $glPreviewLabels['cr'] ?? 'Cash';
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#dc2626,#b91c1c);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-arrow-trend-down me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                GL posting: <strong>Dr Operating Expense / Cr Cash/Bank</strong>
            </p>
        </div>
        <div>
            <a href="{{ route('admin.other-expenses.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Info banner --}}
    <div class="alert alert-info d-flex align-items-start mb-3" role="alert">
        <i class="fas fa-circle-info me-2 mt-1"></i>
        <div>
            <strong>Expenses post immediately on save.</strong>
            GL is balanced (Dr Operating Expense / Cr Cash or Bank), and the cash/bank ledger is updated.
        </div>
    </div>

    <form method="POST" action="{{ route('admin.other-expenses.store') }}" id="otherExpenseForm">
        @csrf

        {{-- Expense details card --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-sliders me-1 text-danger"></i> Expense details</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    {{-- Expense date --}}
                    <div class="col-md-4">
                        <label class="form-label" for="expense_date">
                            Expense date <span class="text-danger">*</span>
                        </label>
                        <input type="date" id="expense_date" name="expense_date"
                               class="form-control @error('expense_date') is-invalid @enderror"
                               required value="{{ $oldDate }}">
                        @error('expense_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Branch --}}
                    <div class="col-md-4">
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

                    {{-- Expense ledger --}}
                    <div class="col-md-4">
                        <label class="form-label" for="ledger_id">
                            Expense Ledger <span class="text-danger">*</span>
                        </label>
                        <select id="ledger_id" name="ledger_id"
                                class="form-select select2 @error('ledger_id') is-invalid @enderror" required>
                            <option value="">Select expense ledger</option>
                            @foreach ($expenseLedgers as $l)
                                <option value="{{ $l->id }}"
                                    {{ (string) $oldLedgerId === (string) $l->id ? 'selected' : '' }}>
                                    @if (!empty($l->ledger_code)){{ $l->ledger_code }} — @endif{{ $l->ledger_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('ledger_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">
                            <i class="fas fa-info-circle me-1"></i>
                            Select the expense account from Chart of Accounts. This will be debited.
                        </div>
                    </div>

                    {{-- Expense type (optional text) --}}
                    <div class="col-md-4">
                        <label class="form-label" for="expense_type">Expense type</label>
                        <input type="text" id="expense_type" name="expense_type"
                               class="form-control @error('expense_type') is-invalid @enderror"
                               value="{{ $oldExpenseType }}"
                               placeholder="Bank Charges, Rent, Utilities, etc.">
                        @error('expense_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Payment mode --}}
                    <div class="col-md-4">
                        <label class="form-label">
                            Payment mode <span class="text-danger">*</span>
                        </label>
                        <div class="d-flex gap-3 pt-1">
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

                    {{-- Bank (conditionally shown) --}}
                    <div class="col-md-4" id="bank_section" style="display:{{ $oldMode === 'bank' ? 'block' : 'none' }};">
                        <label class="form-label" for="bank_id">
                            Bank <span class="text-danger">*</span>
                        </label>
                        <select id="bank_id" name="bank_id"
                                class="form-select select2 @error('bank_id') is-invalid @enderror">
                            <option value="">Select bank</option>
                            @foreach ($banks as $bk)
                                <option value="{{ $bk->id }}"
                                    {{ (string) $oldBankId === (string) $bk->id ? 'selected' : '' }}>
                                    {{ $bk->bank_code }} — {{ $bk->bank_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('bank_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Amount --}}
                    <div class="col-md-4">
                        <label class="form-label" for="amount">
                            Amount (Tk) <span class="text-danger">*</span>
                        </label>
                        <input type="number" id="amount" name="amount"
                               class="form-control text-end @error('amount') is-invalid @enderror"
                               min="0.01" step="0.01" required
                               value="{{ $oldAmount }}"
                               placeholder="0.00">
                        @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Description --}}
                    <div class="col-12">
                        <label class="form-label" for="description">Description</label>
                        <textarea id="description" name="description" rows="2" class="form-control"
                                  placeholder="Details about this expense">{{ $oldDescription }}</textarea>
                        @error('description') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- GL Accounting Preview card --}}
        <div class="card border-0 shadow-sm mb-3" id="glPreviewCard">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">
                    <i class="fas fa-scale-balanced me-1 text-danger"></i> GL Journal Preview
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
                    This preview shows the GL journal entry that will be posted when you save. Actual ledger names may differ based on Chart of Accounts configuration.
                </div>
            </div>
            <div class="card-footer bg-white">
                <div class="row g-2 small">
                    <div class="col-md-4">
                        <span class="text-muted">GL rule:</span>
                        <strong id="glRuleLabel">Dr Operating Expense / Cr Cash/Bank</strong>
                    </div>
                    <div class="col-md-4">
                        <span class="text-muted">Debit:</span>
                        <strong id="drLedgerLabel">{{ $glDrLabel }}</strong>
                    </div>
                    <div class="col-md-4">
                        <span class="text-muted">Credit:</span>
                        <strong id="crLedgerLabel">{{ $glCrLabel }}</strong>
                    </div>
                </div>
            </div>
        </div>

        {{-- Submit --}}
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex gap-2 justify-content-end">
                <a href="{{ route('admin.other-expenses.index') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-danger" id="submitBtn">
                    <i class="fas fa-check me-1"></i>
                    <span id="submitLabel">Save Expense</span>
                </button>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
$(function () {
    var $form         = $('#otherExpenseForm');
    var $modeCash     = $('#mode_cash');
    var $modeBank     = $('#mode_bank');
    var $bankSection  = $('#bank_section');
    var $bankId       = $('#bank_id');
    var $amount       = $('#amount');
    var $ledgerId     = $('#ledger_id');

    // GL preview elements
    var $glBody       = $('#glPreviewBody');
    var $glTotalDr    = $('#glTotalDebit');
    var $glTotalCr    = $('#glTotalCredit');
    var $glBadge      = $('#glBalanceBadge');
    var $glRuleLabel  = $('#glRuleLabel');
    var $drLedgerLabel = $('#drLedgerLabel');
    var $crLedgerLabel = $('#crLedgerLabel');

    // Ledger data for label lookup
    var ledgerData = {{ $ledgersJsonStr }};

    function getPaymentMode() {
        return $modeBank.is(':checked') ? 'bank' : 'cash';
    }

    function toggleBankSection() {
        var mode = getPaymentMode();
        if (mode === 'bank') {
            $bankSection.show();
            $bankId.prop('required', true);
        } else {
            $bankSection.hide();
            $bankId.prop('required', false);
            $bankId.val('');
        }
        updateGLPreview();
    }

    function updateGLPreview() {
        var amount = parseFloat($amount.val()) || 0;
        var mode = getPaymentMode();
        var ledgerId = $ledgerId.val();
        var bankName = $bankId.find('option:selected').text();

        // Determine Dr label from selected ledger
        var drLabel = 'Operating Expense';
        if (ledgerId) {
            for (var i = 0; i < ledgerData.length; i++) {
                if (String(ledgerData[i].id) === String(ledgerId)) {
                    drLabel = ledgerData[i].name;
                    break;
                }
            }
        }

        // Determine Cr label
        var crLabel = 'Cash in Hand';
        if (mode === 'bank') {
            crLabel = (bankName && bankName !== 'Select bank') ? 'Bank — ' + bankName : 'Bank Account';
        }

        // Update footer labels
        $drLedgerLabel.text(drLabel);
        $crLedgerLabel.text(crLabel);
        $glRuleLabel.text('Dr ' + drLabel + ' / Cr ' + crLabel);

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

        // Balance check — always balanced for single-amount expense
        $glBadge.html('<i class="fas fa-check me-1"></i>Balanced').removeClass('bg-danger-subtle text-danger').addClass('bg-success-subtle text-success');
    }

    function numberFormat(num) {
        return parseFloat(num).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // Event listeners
    $modeCash.on('change', toggleBankSection);
    $modeBank.on('change', toggleBankSection);
    $amount.on('input', updateGLPreview);
    $ledgerId.on('change', updateGLPreview);
    $bankId.on('change', updateGLPreview);

    // Initialize
    toggleBankSection();
    updateGLPreview();

    // Form submission via AJAX with Swal confirmation
    $form.on('submit', function (e) {
        e.preventDefault();

        var amount = parseFloat($amount.val()) || 0;
        var ledgerId = $ledgerId.val();
        var mode = getPaymentMode();

        if (!ledgerId) {
            Swal.fire({ icon: 'warning', title: 'Required', text: 'Please select an expense ledger.' });
            return;
        }
        if (amount <= 0) {
            Swal.fire({ icon: 'warning', title: 'Required', text: 'Please enter a valid amount.' });
            return;
        }

        var crLabel = mode === 'bank' ? 'Bank' : 'Cash';
        var drLabel = $drLedgerLabel.text();

        Swal.fire({
            icon: 'question',
            title: 'Confirm Expense',
            html: '<p class="text-muted small mb-2">This will record an expense and post a GL journal entry:</p>' +
                  '<p class="small"><strong>Dr ' + drLabel + '</strong> / <strong>Cr ' + crLabel + '</strong></p>' +
                  '<p class="small">Amount: <strong>Tk ' + numberFormat(amount) + '</strong></p>',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check"></i> Save Expense',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc2626',
            reverseButtons: true,
        }).then(function (result) {
            if (result.isConfirmed) {
                $form[0].submit();
            }
        });
    });
});
</script>
@endpush
@endsection

@extends('layouts.admin')

@section('content')
@php
    $today      = now()->format('Y-m-d');
    $oldType    = old('transfer_type', 'cash_to_bank');
    $oldFromBr  = old('from_branch_id', session('branch_id'));
    $oldToBr    = old('to_branch_id');
    $oldFromBank = old('from_bank_id');
    $oldToBank  = old('to_bank_id');
    $oldAmt     = old('amount');
    $oldDate    = old('transfer_date', $today);
    $oldNotes   = old('notes');

    // Type-specific configuration
    $typeConfig = [
        'cash_to_bank' => [
            'icon' => 'fa-university',
            'label' => 'Cash to Bank',
            'gradient' => 'linear-gradient(135deg,#0d9488,#059669)',
            'gl_info' => 'Dr Bank · Cr Cash',
            'submit_label' => 'Record Transfer',
            'submit_icon' => 'fa-floppy-disk',
            'hint' => 'Deposit cash into a bank account. Debits bank, credits cash in hand.',
            'show_from_bank' => false,
            'show_to_bank' => true,
        ],
        'bank_to_cash' => [
            'icon' => 'fa-money-bill',
            'label' => 'Bank to Cash',
            'gradient' => 'linear-gradient(135deg,#0d9488,#059669)',
            'gl_info' => 'Dr Cash · Cr Bank',
            'submit_label' => 'Record Transfer',
            'submit_icon' => 'fa-floppy-disk',
            'hint' => 'Withdraw cash from a bank account. Debits cash in hand, credits bank.',
            'show_from_bank' => true,
            'show_to_bank' => false,
        ],
        'cash_to_cash' => [
            'icon' => 'fa-money-bill-transfer',
            'label' => 'Cash to Cash',
            'gradient' => 'linear-gradient(135deg,#0d9488,#059669)',
            'gl_info' => 'Dr Cash (to branch) · Cr Cash (from branch)',
            'submit_label' => 'Record Transfer',
            'submit_icon' => 'fa-floppy-disk',
            'hint' => 'Transfer cash between branches. Requires cross-branch. Debits cash at destination, credits cash at source.',
            'show_from_bank' => false,
            'show_to_bank' => false,
        ],
        'bank_to_bank' => [
            'icon' => 'fa-exchange-alt',
            'label' => 'Bank to Bank',
            'gradient' => 'linear-gradient(135deg,#0d9488,#059669)',
            'gl_info' => 'Dr Bank (to) · Cr Bank (from)',
            'submit_label' => 'Record Transfer',
            'submit_icon' => 'fa-floppy-disk',
            'hint' => 'Transfer between bank accounts. Debits destination bank, credits source bank. Both must differ.',
            'show_from_bank' => true,
            'show_to_bank' => true,
        ],
    ];
    $cfg = $typeConfig[$oldType] ?? $typeConfig['cash_to_bank'];
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: {{ $cfg['gradient'] }};" id="heroHeader">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-exchange-alt me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75" id="heroSubtitle">
                GL posting: <strong id="heroGl">{{ $cfg['gl_info'] }}</strong> + cash/bank ledger updates.
            </p>
        </div>
        <div>
            <a href="{{ route('admin.money-transfers.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Info banner --}}
    <div class="alert alert-info d-flex align-items-start mb-3" role="alert" id="infoBanner">
        <i class="fas fa-circle-info me-2 mt-1"></i>
        <div id="infoBannerContent">
            <strong>Transfers post immediately on save.</strong>
            GL is balanced (Dr/Cr depending on type), cash and bank ledgers are updated, and the bank book balance is synced.
        </div>
    </div>

    <form method="POST" action="{{ route('admin.money-transfers.store') }}" id="moneyTransferForm">
        @csrf

        {{-- Transfer details card --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-sliders me-1 text-success"></i> Transfer details</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    {{-- Transfer type selector --}}
                    <div class="col-md-4">
                        <label class="form-label" for="transfer_type" id="transferTypeLabel">
                            Transfer type <span class="text-danger">*</span>
                        </label>
                        <select id="transfer_type" name="transfer_type"
                                class="form-select @error('transfer_type') is-invalid @enderror" required>
                            <option value="cash_to_bank" {{ $oldType === 'cash_to_bank' ? 'selected' : '' }}>Cash to Bank</option>
                            <option value="bank_to_cash" {{ $oldType === 'bank_to_cash' ? 'selected' : '' }}>Bank to Cash</option>
                            <option value="cash_to_cash" {{ $oldType === 'cash_to_cash' ? 'selected' : '' }}>Cash to Cash</option>
                            <option value="bank_to_bank" {{ $oldType === 'bank_to_bank' ? 'selected' : '' }}>Bank to Bank</option>
                        </select>
                        @error('transfer_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text" id="typeHint">
                            <i class="fas fa-info-circle me-1"></i>
                            <span id="typeHintText">{{ $cfg['hint'] }}</span>
                        </div>
                    </div>

                    {{-- From branch --}}
                    <div class="col-md-4">
                        <label class="form-label" for="from_branch_id">
                            From branch <span class="text-danger">*</span>
                        </label>
                        <select id="from_branch_id" name="from_branch_id"
                                class="form-select select2 @error('from_branch_id') is-invalid @enderror" required>
                            <option value="">Select branch</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}"
                                    {{ (string) $oldFromBr === (string) $b->id ? 'selected' : '' }}>
                                    {{ $b->branch_code }} — {{ $b->branch_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('from_branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- To branch --}}
                    <div class="col-md-4">
                        <label class="form-label" for="to_branch_id">
                            To branch <span class="text-danger">*</span>
                        </label>
                        <select id="to_branch_id" name="to_branch_id"
                                class="form-select select2 @error('to_branch_id') is-invalid @enderror" required>
                            <option value="">Select branch</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}"
                                    {{ (string) $oldToBr === (string) $b->id ? 'selected' : '' }}>
                                    {{ $b->branch_code }} — {{ $b->branch_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('to_branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- From bank (conditionally shown) --}}
                    <div class="col-md-4" id="from_bank_section" style="display:{{ $cfg['show_from_bank'] ? 'block' : 'none' }};">
                        <label class="form-label" for="from_bank_id">
                            From bank <span class="text-danger">*</span>
                        </label>
                        <select id="from_bank_id" name="from_bank_id"
                                class="form-select select2 @error('from_bank_id') is-invalid @enderror">
                            <option value="">Select bank</option>
                            @foreach ($banks as $bk)
                                <option value="{{ $bk->id }}"
                                    {{ (string) $oldFromBank === (string) $bk->id ? 'selected' : '' }}>
                                    {{ $bk->bank_code }} — {{ $bk->bank_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('from_bank_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- To bank (conditionally shown) --}}
                    <div class="col-md-4" id="to_bank_section" style="display:{{ $cfg['show_to_bank'] ? 'block' : 'none' }};">
                        <label class="form-label" for="to_bank_id">
                            To bank <span class="text-danger">*</span>
                        </label>
                        <select id="to_bank_id" name="to_bank_id"
                                class="form-select select2 @error('to_bank_id') is-invalid @enderror">
                            <option value="">Select bank</option>
                            @foreach ($banks as $bk)
                                <option value="{{ $bk->id }}"
                                    {{ (string) $oldToBank === (string) $bk->id ? 'selected' : '' }}>
                                    {{ $bk->bank_code }} — {{ $bk->bank_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('to_bank_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Amount --}}
                    <div class="col-md-4">
                        <label class="form-label" for="amount" id="amountLabel">
                            Transfer amount (Tk) <span class="text-danger">*</span>
                        </label>
                        <input type="number" id="amount" name="amount"
                               class="form-control text-end @error('amount') is-invalid @enderror"
                               min="0.01" step="0.01" required
                               value="{{ $oldAmt }}"
                               placeholder="0.00">
                        @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Transfer date --}}
                    <div class="col-md-4">
                        <label class="form-label" for="transfer_date">
                            Transfer date <span class="text-danger">*</span>
                        </label>
                        <input type="date" id="transfer_date" name="transfer_date"
                               class="form-control @error('transfer_date') is-invalid @enderror"
                               required value="{{ $oldDate }}">
                        @error('transfer_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Notes --}}
                    <div class="col-12">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea id="notes" name="notes" rows="2" class="form-control"
                                  placeholder="Internal notes — source, remarks, etc.">{{ $oldNotes }}</textarea>
                        @error('notes') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
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
                    This preview shows the GL journal entry that will be posted when you save. Actual ledger names may differ based on Chart of Accounts configuration.
                </div>
            </div>
            <div class="card-footer bg-white">
                <div class="row g-2 small">
                    <div class="col-md-4">
                        <span class="text-muted">GL rule:</span>
                        <strong id="glRuleLabel">{{ $cfg['gl_info'] }}</strong>
                    </div>
                    <div class="col-md-4">
                        <span class="text-muted">Cash ledger:</span>
                        <strong id="cashLedgerLabel">
                            @if(in_array($oldType, ['cash_to_bank', 'cash_to_cash']))
                                Credit (reduce cash in hand)
                            @else
                                Debit (increase cash in hand)
                            @endif
                        </strong>
                    </div>
                    <div class="col-md-4">
                        <span class="text-muted">Bank book:</span>
                        <strong id="bankBookLabel">
                            @if(in_array($oldType, ['cash_to_bank', 'bank_to_bank']))
                                Debit (increase bank balance)
                            @else
                                Credit (decrease bank balance)
                            @endif
                        </strong>
                    </div>
                </div>
            </div>
        </div>

        {{-- Submit --}}
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex gap-2 justify-content-end">
                <a href="{{ route('admin.money-transfers.index') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-success" id="submitBtn">
                    <i class="fas fa-floppy-disk me-1"></i>
                    <span id="submitLabel">{{ $cfg['submit_label'] }}</span>
                </button>
            </div>
        </div>
    </form>
</div>

@push('scripts')
<script>
$(function () {
    var $form        = $('#moneyTransferForm');
    var $type        = $('#transfer_type');
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
    var $cashLedgerLabel = $('#cashLedgerLabel');
    var $bankBookLabel   = $('#bankBookLabel');
    var $heroGl      = $('#heroGl');
    var $typeHint    = $('#typeHintText');

    // Type-specific configurations
    var typeConfigs = {
        cash_to_bank: {
            icon: 'fa-university',
            label: 'Cash to Bank',
            gradient: 'linear-gradient(135deg,#0d9488,#059669)',
            gl_info: 'Dr Bank · Cr Cash',
            hint: 'Deposit cash into a bank account. Debits bank, credits cash in hand.',
            show_from_bank: false,
            show_to_bank: true,
            cash_ledger: 'Credit (reduce cash in hand)',
            bank_book: 'Debit (increase bank balance)',
            dr_label: 'Bank Account',
            cr_label: 'Cash in Hand',
        },
        bank_to_cash: {
            icon: 'fa-money-bill',
            label: 'Bank to Cash',
            gradient: 'linear-gradient(135deg,#0d9488,#059669)',
            gl_info: 'Dr Cash · Cr Bank',
            hint: 'Withdraw cash from a bank account. Debits cash in hand, credits bank.',
            show_from_bank: true,
            show_to_bank: false,
            cash_ledger: 'Debit (increase cash in hand)',
            bank_book: 'Credit (decrease bank balance)',
            dr_label: 'Cash in Hand',
            cr_label: 'Bank Account',
        },
        cash_to_cash: {
            icon: 'fa-money-bill-transfer',
            label: 'Cash to Cash',
            gradient: 'linear-gradient(135deg,#0d9488,#059669)',
            gl_info: 'Dr Cash (to branch) · Cr Cash (from branch)',
            hint: 'Transfer cash between branches. Requires cross-branch. Debits cash at destination, credits cash at source.',
            show_from_bank: false,
            show_to_bank: false,
            cash_ledger: 'Dr at destination, Cr at source',
            bank_book: 'No change',
            dr_label: 'Cash in Hand (To Branch)',
            cr_label: 'Cash in Hand (From Branch)',
        },
        bank_to_bank: {
            icon: 'fa-exchange-alt',
            label: 'Bank to Bank',
            gradient: 'linear-gradient(135deg,#0d9488,#059669)',
            gl_info: 'Dr Bank (to) · Cr Bank (from)',
            hint: 'Transfer between bank accounts. Debits destination bank, credits source bank. Both must differ.',
            show_from_bank: true,
            show_to_bank: true,
            cash_ledger: 'No change',
            bank_book: 'Dr at destination, Cr at source',
            dr_label: 'Bank Account (To)',
            cr_label: 'Bank Account (From)',
        },
    };

    function applyTypeConfig(type) {
        var cfg = typeConfigs[type] || typeConfigs.cash_to_bank;

        // Hero header
        $('#heroHeader').css('background', cfg.gradient);
        $heroGl.text(cfg.gl_info);

        // Type hint
        $typeHint.text(cfg.hint);

        // GL preview labels
        $glRuleLabel.text(cfg.gl_info);
        $cashLedgerLabel.text(cfg.cash_ledger);
        $bankBookLabel.text(cfg.bank_book);

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
            $toBankId.val('');
        }

        // Update GL preview
        updateGLPreview();
    }

    function updateGLPreview() {
        var type = $type.val();
        var cfg = typeConfigs[type] || typeConfigs.cash_to_bank;
        var amount = parseFloat($amount.val()) || 0;

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

        var fromBankName = $fromBankId.find('option:selected').text();
        var toBankName = $toBankId.find('option:selected').text();
        var fromBranchName = $fromBranch.find('option:selected').text();
        var toBranchName = $toBranch.find('option:selected').text();

        // Determine debit and credit labels
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
        var diff = Math.abs(amount - amount);
        if (diff < 0.01) {
            $glBadge.html('<i class="fas fa-check me-1"></i>Balanced').removeClass('bg-danger-subtle text-danger').addClass('bg-success-subtle text-success');
        } else {
            $glBadge.html('<i class="fas fa-exclamation-triangle me-1"></i>Unbalanced').removeClass('bg-success-subtle text-success').addClass('bg-danger-subtle text-danger');
        }
    }

    function numberFormat(num) {
        return parseFloat(num).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // Event listeners
    $type.on('change', function () {
        applyTypeConfig($(this).val());
    });

    $amount.on('input', updateGLPreview);
    $fromBankId.on('change', function () {
        // For bank_to_bank, ensure banks differ
        if ($type.val() === 'bank_to_bank' && $fromBankId.val() && $toBankId.val() && $fromBankId.val() === $toBankId.val()) {
            $toBankId.val('').trigger('change');
        }
        updateGLPreview();
    });
    $toBankId.on('change', function () {
        if ($type.val() === 'bank_to_bank' && $fromBankId.val() && $toBankId.val() && $fromBankId.val() === $toBankId.val()) {
            $fromBankId.val('').trigger('change');
        }
        updateGLPreview();
    });
    $fromBranch.on('change', updateGLPreview);
    $toBranch.on('change', updateGLPreview);

    // Initialize
    applyTypeConfig($type.val());
    updateGLPreview();
});
</script>
@endpush
@endsection

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

<style>
    .mt-create-page { --mt-primary: #0d9488; --mt-primary-dark: #059669; }

    .mt-hero {
        background: linear-gradient(135deg, var(--mt-primary), var(--mt-primary-dark));
        border-radius: 1rem;
        padding: 1.5rem 1.75rem;
        color: #fff;
        box-shadow: 0 8px 32px rgba(13,148,136,0.18);
        margin-bottom: 1.5rem;
    }
    .mt-hero h1 { font-size: 1.35rem; font-weight: 700; margin-bottom: 0.15rem; }
    .mt-hero .mt-subtitle { font-size: 0.82rem; opacity: 0.85; }

    .mt-section-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 0.875rem;
        box-shadow: 0 1px 4px rgba(15,23,42,0.04);
        margin-bottom: 1.25rem;
        overflow: hidden;
        transition: box-shadow 0.2s;
    }
    .mt-section-card:hover { box-shadow: 0 4px 16px rgba(15,23,42,0.07); }
    .mt-section-header {
        background: #f8fafc;
        border-bottom: 1px solid #e2e8f0;
        padding: 0.75rem 1.25rem;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .mt-section-header h2 { font-size: 0.88rem; font-weight: 700; margin: 0; color: #0f172a; }
    .mt-section-header .mt-section-icon {
        width: 32px; height: 32px; border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        font-size: 0.85rem; color: #fff;
    }
    .mt-section-body { padding: 1.25rem; }

    .mt-type-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0.75rem;
    }
    .mt-type-btn {
        display: flex; flex-direction: column; align-items: center; gap: 0.4rem;
        padding: 0.85rem 0.5rem; border: 2px solid #e2e8f0; border-radius: 0.75rem;
        background: #fff; cursor: pointer; transition: all 0.2s; text-align: center;
    }
    .mt-type-btn:hover { border-color: #0d9488; background: #f0fdfa; }
    .mt-type-btn.active { border-color: #0d9488; background: #f0fdfa; box-shadow: 0 2px 8px rgba(13,148,136,0.12); }
    .mt-type-btn .mt-type-icon {
        width: 38px; height: 38px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1rem; background: #f1f5f9; color: #64748b;
    }
    .mt-type-btn.active .mt-type-icon { background: #0d9488; color: #fff; }
    .mt-type-btn .mt-type-label { font-size: 0.78rem; font-weight: 600; color: #475569; }
    .mt-type-btn.active .mt-type-label { color: #0d9488; }
    .mt-type-btn .mt-type-gl { font-size: 0.68rem; color: #94a3b8; }
    .mt-type-btn.active .mt-type-gl { color: #047857; }

    .mt-amount-card {
        background: linear-gradient(135deg, var(--mt-primary), var(--mt-primary-dark));
        border-radius: 0.875rem;
        padding: 1.5rem;
        color: #fff;
        text-align: center;
        box-shadow: 0 8px 24px rgba(13,148,136,0.2);
    }
    .mt-amount-card .mt-amount-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; opacity: 0.85; }
    .mt-amount-card .mt-amount-input {
        font-size: 2rem; font-weight: 800; font-variant-numeric: tabular-nums;
        background: transparent; border: none; color: #fff; text-align: center;
        width: 100%; outline: none; line-height: 1.1;
    }
    .mt-amount-card .mt-amount-input::placeholder { color: rgba(255,255,255,0.4); }
    .mt-amount-card .mt-amount-meta { font-size: 0.78rem; opacity: 0.8; margin-top: 0.25rem; }

    .mt-gl-table th {
        font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.03em;
        color: #64748b; font-weight: 600;
    }
    .mt-gl-table td { font-size: 0.88rem; }
    .mt-gl-table .debit-col { color: #0d9488; font-weight: 600; }
    .mt-gl-table .credit-col { color: #dc2626; font-weight: 600; }

    @media (max-width: 768px) {
        .mt-type-grid { grid-template-columns: repeat(2, 1fr); }
    }
</style>

<div class="mt-create-page">

    {{-- Hero header --}}
    <header class="mt-hero d-flex flex-wrap justify-content-between align-items-center gap-3" id="heroHeader">
        <div>
            <h1><i class="fas fa-exchange-alt me-2"></i>{{ $title }}</h1>
            <p class="mt-subtitle mb-0">
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
    <div class="alert alert-info d-flex align-items-start mb-3" role="alert">
        <i class="fas fa-circle-info me-2 mt-1"></i>
        <div>
            <strong>Transfers post immediately on save.</strong>
            GL is balanced (Dr/Cr depending on type), cash and bank ledgers are updated, and the bank book balance is synced.
        </div>
    </div>

    <form method="POST" action="{{ route('admin.money-transfers.store') }}" id="moneyTransferForm">
        @csrf

        {{-- Transfer type selector --}}
        <div class="mt-section-card">
            <div class="mt-section-header">
                <span class="mt-section-icon" style="background:#0d9488;"><i class="fas fa-sliders"></i></span>
                <h2>Select Transfer Type</h2>
            </div>
            <div class="mt-section-body">
                <div class="mt-type-grid" id="typeGrid">
                    @foreach($typeConfig as $type => $tc)
                    <div class="mt-type-btn {{ $oldType === $type ? 'active' : '' }}" data-type="{{ $type }}" onclick="selectType('{{ $type }}')">
                        <div class="mt-type-icon"><i class="fas {{ $tc['icon'] }}"></i></div>
                        <div class="mt-type-label">{{ $tc['label'] }}</div>
                        <div class="mt-type-gl">{{ $tc['gl_info'] }}</div>
                    </div>
                    @endforeach
                </div>
                <input type="hidden" name="transfer_type" id="transfer_type" value="{{ $oldType }}">
                <div class="form-text mt-2" id="typeHint">
                    <i class="fas fa-info-circle me-1"></i>
                    <span id="typeHintText">{{ $cfg['hint'] }}</span>
                </div>
            </div>
        </div>

        <div class="row g-4">
            {{-- Left column --}}
            <div class="col-lg-8">

                {{-- Transfer details card --}}
                <div class="mt-section-card">
                    <div class="mt-section-header">
                        <span class="mt-section-icon" style="background:#059669;"><i class="fas fa-route"></i></span>
                        <h2>Transfer Details</h2>
                    </div>
                    <div class="mt-section-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label small" for="from_branch_id">
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

                            <div class="col-md-4">
                                <label class="form-label small" for="to_branch_id">
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

                            <div class="col-md-4" id="from_bank_section" style="display:{{ $cfg['show_from_bank'] ? 'block' : 'none' }};">
                                <label class="form-label small" for="from_bank_id">
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

                            <div class="col-md-4" id="to_bank_section" style="display:{{ $cfg['show_to_bank'] ? 'block' : 'none' }};">
                                <label class="form-label small" for="to_bank_id">
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

                            <div class="col-md-4">
                                <label class="form-label small" for="transfer_date">
                                    Transfer date <span class="text-danger">*</span>
                                </label>
                                <input type="date" id="transfer_date" name="transfer_date"
                                       class="form-control @error('transfer_date') is-invalid @enderror"
                                       required value="{{ $oldDate }}">
                                @error('transfer_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>

                            <div class="col-12">
                                <label class="form-label small" for="notes">Notes</label>
                                <textarea id="notes" name="notes" rows="2" class="form-control"
                                          placeholder="Internal notes — source, remarks, etc.">{{ $oldNotes }}</textarea>
                                @error('notes') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                            </div>
                        </div>
                    </div>
                </div>

                {{-- GL Accounting Preview card --}}
                <div class="mt-section-card">
                    <div class="mt-section-header">
                        <span class="mt-section-icon" style="background:#1d4ed8;"><i class="fas fa-scale-balanced"></i></span>
                        <h2>GL Journal Preview</h2>
                        <span class="badge bg-success-subtle text-success ms-auto" id="glBalanceBadge">
                            <i class="fas fa-check me-1"></i>Balanced
                        </span>
                    </div>
                    <div class="mt-section-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0 mt-gl-table" id="glPreviewTable">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:5%;">#</th>
                                        <th style="width:40%;">Ledger Account</th>
                                        <th class="text-end" style="width:25%;">Debit (Tk)</th>
                                        <th class="text-end" style="width:25%;">Credit (Tk)</th>
                                    </tr>
                                </thead>
                                <tbody id="glPreviewBody">
                                </tbody>
                                <tfoot class="table-light fw-bold">
                                    <tr>
                                        <td colspan="2" class="text-end">Total</td>
                                        <td class="text-end" id="glTotalDebit">0.00</td>
                                        <td class="text-end" id="glTotalCredit">0.00</td>
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
            </div>

            {{-- Right column --}}
            <div class="col-lg-4">
                <div class="mt-amount-card mb-4">
                    <div class="mt-amount-label">Transfer Amount</div>
                    <input type="number" id="amount" name="amount"
                           class="mt-amount-input @error('amount') is-invalid @enderror"
                           min="0.01" step="0.01" required
                           value="{{ $oldAmt }}"
                           placeholder="0.00">
                    @error('amount') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    <div class="mt-amount-meta" id="amountSub">
                        @if(in_array($oldType, ['cash_to_bank', 'bank_to_bank']))
                            Debit (increase bank balance)
                        @else
                            Credit (decrease bank balance)
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Submit --}}
        <div class="mt-section-card" style="margin-top:1rem;">
            <div class="mt-section-body d-flex gap-2 justify-content-end">
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
    var $typeInput   = $('#transfer_type');
    var $fromBankSec = $('#from_bank_section');
    var $toBankSec   = $('#to_bank_section');
    var $fromBankId  = $('#from_bank_id');
    var $toBankId    = $('#to_bank_id');
    var $amount      = $('#amount');
    var $fromBranch  = $('#from_branch_id');
    var $toBranch    = $('#to_branch_id');

    var $glBody      = $('#glPreviewBody');
    var $glTotalDr   = $('#glTotalDebit');
    var $glTotalCr   = $('#glTotalCredit');
    var $glBadge     = $('#glBalanceBadge');
    var $glRuleLabel = $('#glRuleLabel');
    var $cashLedgerLabel = $('#cashLedgerLabel');
    var $bankBookLabel   = $('#bankBookLabel');
    var $heroGl      = $('#heroGl');
    var $typeHint    = $('#typeHintText');

    var typeConfigs = {
        cash_to_bank: {
            label: 'Cash to Bank', gl_info: 'Dr Bank · Cr Cash',
            hint: 'Deposit cash into a bank account. Debits bank, credits cash in hand.',
            show_from_bank: false, show_to_bank: true,
            cash_ledger: 'Credit (reduce cash in hand)', bank_book: 'Debit (increase bank balance)',
            dr_label: 'Bank Account', cr_label: 'Cash in Hand',
        },
        bank_to_cash: {
            label: 'Bank to Cash', gl_info: 'Dr Cash · Cr Bank',
            hint: 'Withdraw cash from a bank account. Debits cash in hand, credits bank.',
            show_from_bank: true, show_to_bank: false,
            cash_ledger: 'Debit (increase cash in hand)', bank_book: 'Credit (decrease bank balance)',
            dr_label: 'Cash in Hand', cr_label: 'Bank Account',
        },
        cash_to_cash: {
            label: 'Cash to Cash', gl_info: 'Dr Cash (to branch) · Cr Cash (from branch)',
            hint: 'Transfer cash between branches. Debits cash at destination, credits cash at source.',
            show_from_bank: false, show_to_bank: false,
            cash_ledger: 'Dr at destination, Cr at source', bank_book: 'No change',
            dr_label: 'Cash in Hand (To Branch)', cr_label: 'Cash in Hand (From Branch)',
        },
        bank_to_bank: {
            label: 'Bank to Bank', gl_info: 'Dr Bank (to) · Cr Bank (from)',
            hint: 'Transfer between bank accounts. Debits destination bank, credits source bank. Both must differ.',
            show_from_bank: true, show_to_bank: true,
            cash_ledger: 'No change', bank_book: 'Dr at destination, Cr at source',
            dr_label: 'Bank Account (To)', cr_label: 'Bank Account (From)',
        },
    };

    // Make selectType globally available for onclick
    window.selectType = function(type) {
        $typeInput.val(type);
        applyTypeConfig(type);
    };

    function applyTypeConfig(type) {
        var cfg = typeConfigs[type] || typeConfigs.cash_to_bank;

        // Active state
        $('.mt-type-btn').removeClass('active');
        $('.mt-type-btn[data-type="' + type + '"]').addClass('active');

        // Hero
        $heroGl.text(cfg.gl_info);

        // Hint
        $typeHint.text(cfg.hint);

        // GL labels
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

        if (type === 'bank_to_bank' && $fromBankId.val() && $toBankId.val() && $fromBankId.val() === $toBankId.val()) {
            $toBankId.val('').trigger('change');
        }

        updateGLPreview();
    }

    function updateGLPreview() {
        var type = $typeInput.val();
        var cfg = typeConfigs[type] || typeConfigs.cash_to_bank;
        var amount = parseFloat($amount.val()) || 0;

        $glBody.empty();

        if (amount <= 0) {
            $glBody.append(
                '<tr><td colspan="4" class="text-center text-muted py-3">' +
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

        $glBody.append(
            '<tr><td>1</td><td><span class="fw-semibold">' + drLabel + '</span> <span class="badge bg-success-subtle text-success ms-1">Dr</span></td>' +
            '<td class="text-end fw-semibold debit-col">' + numberFormat(amount) + '</td><td class="text-end text-muted">&mdash;</td></tr>'
        );
        $glBody.append(
            '<tr><td>2</td><td><span class="fw-semibold">' + crLabel + '</span> <span class="badge bg-danger-subtle text-danger ms-1">Cr</span></td>' +
            '<td class="text-end text-muted">&mdash;</td><td class="text-end fw-semibold credit-col">' + numberFormat(amount) + '</td></tr>'
        );

        $glTotalDr.text(numberFormat(amount));
        $glTotalCr.text(numberFormat(amount));
        $glBadge.html('<i class="fas fa-check me-1"></i>Balanced').removeClass('bg-danger-subtle text-danger').addClass('bg-success-subtle text-success');
    }

    function numberFormat(num) {
        return parseFloat(num).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    $amount.on('input', updateGLPreview);
    $fromBankId.on('change', function () {
        if ($typeInput.val() === 'bank_to_bank' && $fromBankId.val() && $toBankId.val() && $fromBankId.val() === $toBankId.val()) {
            $toBankId.val('').trigger('change');
        }
        updateGLPreview();
    });
    $toBankId.on('change', function () {
        if ($typeInput.val() === 'bank_to_bank' && $fromBankId.val() && $toBankId.val() && $fromBankId.val() === $toBankId.val()) {
            $fromBankId.val('').trigger('change');
        }
        updateGLPreview();
    });
    $fromBranch.on('change', updateGLPreview);
    $toBranch.on('change', updateGLPreview);

    // AJAX form submission
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
                    $btn.prop('disabled', false).html('<i class="fas fa-floppy-disk me-1"></i> Record Transfer');
                }
            },
            error: function(xhr) {
                var msg = 'Something went wrong. Please try again.';
                if (xhr.responseJSON) {
                    if (xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    } else if (xhr.responseJSON.errors) {
                        msg = Object.values(xhr.responseJSON.errors).flat().join('\n');
                    }
                }
                Swal.fire('Error', msg, 'error');
                $btn.prop('disabled', false).html('<i class="fas fa-floppy-disk me-1"></i> Record Transfer');
            }
        });
    });

    // Initialize
    applyTypeConfig($typeInput.val());
    updateGLPreview();
});
</script>
@endpush
@endsection

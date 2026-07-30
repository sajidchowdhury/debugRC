@extends('layouts.admin')

@section('content')
@php
    $today      = now()->format('Y-m-d');
    $oldDate    = old('payment_date', $today);
    $oldSupp    = old('supplier_id', $preselectSupplier->id ?? null);
    $oldBr      = old('branch_id', session('branch_id'));
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
        ],
        'advance' => [
            'icon' => 'fa-forward',
            'gradient' => 'linear-gradient(135deg,#0d9488,#059669)',
            'gl_info' => 'Dr Accounts Payable · Cr Bank/Cash',
            'submit_label' => 'Record Advance',
            'submit_icon' => 'fa-floppy-disk',
            'hint' => 'Advance to supplier — same GL flow as payment (Dr AP, Cr cash/bank).',
            'amount_label' => 'Advance amount (Tk)',
        ],
        'receive' => [
            'icon' => 'fa-truck-ramp-box',
            'gradient' => 'linear-gradient(135deg,#7c3aed,#6d28d9)',
            'gl_info' => 'Dr Inventory · Cr Accounts Payable',
            'submit_label' => 'Record Receive',
            'submit_icon' => 'fa-floppy-disk',
            'hint' => 'Receive from supplier (credit/refund) — increases payable (Dr Inventory, Cr AP).',
            'amount_label' => 'Receive amount (Tk)',
        ],
    ];
    $cfg = $typeConfig[$oldType] ?? $typeConfig['payment'];
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: {{ $cfg['gradient'] }};" id="heroHeader">
        <div>
            <h1 class="h4 mb-1"><i class="fas {{ $cfg['icon'] }} me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75" id="heroSubtitle">
                GL posting: <strong>{{ $cfg['gl_info'] }}</strong> + supplier ledger + optional bank balance sync.
            </p>
        </div>
        <div>
            <a href="{{ route('admin.supplier-transactions.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Info banner --}}
    <div class="alert alert-info d-flex align-items-start mb-3" role="alert" id="infoBanner">
        <i class="fas fa-circle-info me-2 mt-1"></i>
        <div id="infoBannerContent">
            <strong>Payments post immediately on save.</strong>
            GL is balanced (Dr AP / Cr Bank/Cash for payment/advance, Dr Inventory / Cr AP for receive),
            supplier ledger is updated, and (if bank mode) the bank book balance is synced.
        </div>
    </div>

    <form method="POST" action="{{ route('admin.supplier-transactions.store') }}" id="supplierTransactionForm">
        @csrf

        {{-- Payment header card --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-sliders me-1 text-success"></i> Transaction details</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    {{-- Transaction type selector --}}
                    <div class="col-md-4">
                        <label class="form-label" for="transaction_type" id="suppTxnTypeLabel">
                            Transaction type <span class="text-danger">*</span>
                        </label>
                        <select id="transaction_type" name="transaction_type"
                                class="form-select @error('transaction_type') is-invalid @enderror" required>
                            <option value="payment" {{ $oldType === 'payment' ? 'selected' : '' }}>Supplier Payment</option>
                            <option value="advance" {{ $oldType === 'advance' ? 'selected' : '' }}>Advance Payment</option>
                            <option value="receive" {{ $oldType === 'receive' ? 'selected' : '' }}>Credit Receive</option>
                        </select>
                        @error('transaction_type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text" id="typeHint">
                            <i class="fas fa-info-circle me-1"></i>
                            <span id="typeHintText">{{ $cfg['hint'] }}</span>
                        </div>
                    </div>

                    {{-- Supplier picker (AJAX search, used by SupplierTransaction.js) --}}
                    <div class="col-md-4 supp-txn-supplier-picker position-relative">
                        <label class="form-label" for="suppTxnSupplierSearch" id="suppTxnSupplierSearchLabel">
                            Supplier <span class="text-danger">*</span>
                        </label>
                        <input type="text" id="suppTxnSupplierSearch" class="form-control"
                               placeholder="Search supplier by name or code…"
                               value="{{ $preselectSupplier ? $preselectSupplier->supplier_name : '' }}"
                               autocomplete="off"
                               @if($preselectSupplier) readonly @endif>
                        <input type="hidden" id="supplier_id" name="supplier_id"
                               value="{{ $preselectSupplier ? $preselectSupplier->id : old('supplier_id') }}">
                        <button type="button" id="suppTxnChangeSupplier" class="btn btn-sm btn-outline-secondary position-absolute"
                                style="right:4px;top:28px;{{ $preselectSupplier ? '' : 'display:none;' }}" title="Change supplier">
                            <i class="fas fa-times"></i>
                        </button>
                        <div id="suppTxnSupplierSuggestions" class="supp-txn-suggest-dropdown"></div>
                        {{-- Recent suppliers --}}
                        <div id="suppTxnSupplierRecents" class="mt-1 d-flex flex-wrap gap-1"></div>
                        @error('supplier_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        {{-- Supplier hub link --}}
                        <div id="suppTxnSupplierHubLink" class="mt-1" style="display:none;">
                            <a id="suppTxnSupplierHubAnchor" href="#" target="_blank" class="small text-muted">
                                <i class="fas fa-external-link-alt me-1"></i> View supplier profile
                            </a>
                        </div>
                    </div>

                    {{-- Supplier due balance --}}
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <div id="dueSummary" class="alert alert-info py-2 small d-none" role="status">
                            <i class="fas fa-spinner fa-spin me-1"></i> Loading payable…
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="branch_id">
                            Branch <span class="text-danger">*</span>
                        </label>
                        <select id="branch_id" name="branch_id"
                                class="form-select select2 @error('branch_id') is-invalid @enderror" required>
                            <option value="">Select branch</option>
                            @foreach ($branches as $b)
                                <option value="{{ $b->id }}"
                                    {{ (string) $oldBr === (string) $b->id ? 'selected' : '' }}>
                                    {{ $b->branch_code }} — {{ $b->branch_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('branch_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4" id="paymentModeField">
                        <label class="form-label" for="mode">
                            Payment mode <span class="text-danger">*</span>
                        </label>
                        <select id="mode" name="payment_mode"
                                class="form-select @error('payment_mode') is-invalid @enderror" required>
                            <option value="cash"            {{ $oldMode === 'cash' ? 'selected' : '' }}>Cash</option>
                            <option value="bank"            {{ $oldMode === 'bank' ? 'selected' : '' }}>Bank</option>
                            <option value="mobile_banking"  {{ $oldMode === 'mobile_banking' ? 'selected' : '' }}>Mobile Banking</option>
                            <option value="cheque"          {{ $oldMode === 'cheque' ? 'selected' : '' }}>Cheque</option>
                            <option value="adjustment"      {{ $oldMode === 'adjustment' ? 'selected' : '' }}>Adjustment</option>
                        </select>
                        @error('payment_mode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    {{-- Bank field (hidden unless bank mode) --}}
                    <div class="col-md-4" id="bank_section" style="display:none;">
                        <label class="form-label" for="bank_id">
                            Bank <span class="text-muted small">(required for bank mode)</span>
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

                    <div class="col-md-4">
                        <label class="form-label" for="amount" id="amountLabel">
                            {{ $cfg['amount_label'] }} <span class="text-danger">*</span>
                        </label>
                        <input type="number" id="amount" name="amount"
                               class="form-control text-end @error('amount') is-invalid @enderror"
                               min="0.01" step="0.01" required
                               value="{{ $oldAmt }}"
                               placeholder="0.00">
                        @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4" id="discountField">
                        <label class="form-label" for="discount_amount">
                            Discount amount (Tk)
                            <span class="text-muted small">(optional)</span>
                        </label>
                        <input type="number" id="discount_amount" name="discount_amount"
                               class="form-control text-end @error('discount_amount') is-invalid @enderror"
                               min="0" step="0.01"
                               value="{{ $oldDisc }}"
                               placeholder="0.00">
                        @error('discount_amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="payment_date">
                            Payment date <span class="text-danger">*</span>
                        </label>
                        <input type="date" id="payment_date" name="payment_date"
                               class="form-control @error('payment_date') is-invalid @enderror"
                               required value="{{ $oldDate }}">
                        @error('payment_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="reference_no">
                            Reference no
                            <span class="text-muted small">(cheque no, txn no)</span>
                        </label>
                        <input type="text" id="reference_no" name="reference_no"
                               class="form-control @error('reference_no') is-invalid @enderror"
                               maxlength="100"
                               value="{{ $oldRef }}"
                               placeholder="e.g. CHQ-0012345">
                        @error('reference_no') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="collected_by">
                            Collected by
                            <span class="text-muted small">(optional)</span>
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
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0">
                    <i class="fas fa-calculator me-1 text-primary"></i> GL Accounting Preview
                </h2>
            </div>
            <div class="card-body">
                <div id="accounting_preview" class="small">
                    <div class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        Select a transaction type and enter an amount to see the GL journal preview.
                    </div>
                </div>
                <div class="mt-2 border-top pt-2">
                    <div class="row g-2 small">
                        <div class="col-md-4">
                            <span class="text-muted">GL rule:</span>
                            <strong id="glRuleLabel">{{ $cfg['gl_info'] }}</strong>
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted">Sub-ledger:</span>
                            <strong id="subLedgerLabel">
                                @if(in_array($oldType, ['payment', 'advance']))
                                    Debit supplier_ledger (reduce AP)
                                @else
                                    Credit supplier_ledger (increase AP)
                                @endif
                            </strong>
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted">Bank book:</span>
                            <strong id="bankBookLabel">
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

        {{-- Submit --}}
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex gap-2 justify-content-end">
                <a href="{{ route('admin.supplier-transactions.index') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-success" id="submitBtn">
                    <i class="fas {{ $cfg['submit_icon'] }} me-1"></i>
                    <span id="submitLabel">{{ $cfg['submit_label'] }}</span>
                </button>
            </div>
        </div>
    </form>
</div>

{{-- Boot config for SupplierTransaction.js --}}
<script>
    window.ST_BOOT = {
        baseUrl: '{{ url("/") }}/',
        csrf_token: '{{ csrf_token() }}',
        preselectSupplier: @json($preselectSupplier ? ['id' => $preselectSupplier->id, 'supplier_name' => $preselectSupplier->supplier_name, 'supplier_code' => $preselectSupplier->supplier_code ?? '', 'mobile' => $preselectSupplier->mobile ?? null] : null),
        glLabels: @json($glPreviewLabels ?? [
            'payment' => 'Dr AP · Cr Bank/Cash',
            'advance' => 'Dr AP · Cr Bank/Cash',
            'receive' => 'Dr Inventory · Cr AP',
        ]),
        routes: {
            'index': '{{ route("admin.supplier-transactions.index") }}',
            'show': '{{ rtrim(route("admin.supplier-transactions.show", ["id" => "{id}"]), "}") }}'.replace('{id}', ''),
            'store': '{{ route("admin.supplier-transactions.store") }}',
            'search': '{{ route("admin.supplier-transactions.search") }}',
            'get-due': '{{ route("admin.supplier-transactions.get-due") }}',
            'reverse': '{{ route("admin.supplier-transactions.reverse", ["id" => "{id}"]) }}'.replace('{id}', ''),
            'supplier-show': '{{ url("/admin/suppliers") }}/',
        },
    };
</script>

@push('scripts')
<link rel="stylesheet" href="/assets/css/supplier-transaction-theme.css">
<script src="/assets/js/SupplierTransaction.js"></script>
<script>
$(function () {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    // Dynamic type switching for the create form
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
        },
    };

    function applyTypeConfig(type) {
        var cfg = typeConfigs[type] || typeConfigs.payment;

        // Hero header
        $('#heroHeader').css('background', cfg.gradient);
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

    $type.on('change', function () {
        applyTypeConfig($(this).val());
    });

    $mode.on('change', syncBankVisibility);
    syncBankVisibility();
});
</script>
@endpush
@endsection

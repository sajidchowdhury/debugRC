@extends('layouts.admin')

@section('content')
@php
    $today      = now()->format('Y-m-d');
    $oldDate    = old('transaction_date', $today);
    $oldEmp     = old('employee_id', $preselectEmployee->id ?? null);
    $oldBr      = old('branch_id', session('branch_id'));
    $oldBank    = old('bank_id');
    $oldMode    = old('payment_mode', $transactionType === 'repayment' ? 'cash' : 'cash');
    $oldType    = old('transaction_type', $transactionType ?? 'advance');
    $oldAmt     = old('amount');
    $oldDesc    = old('description');
    $oldColl    = old('collected_by');

    // Type-specific configuration.
    $typeConfig = [
        'advance' => [
            'icon' => 'fa-hand-holding-dollar',
            'gradient' => 'linear-gradient(135deg,#d97706,#b45309)',
            'gl_info' => 'Dr Employee Payable · Cr Bank/Cash',
            'submit_label' => 'Record Advance',
            'hint' => 'Cash/bank paid to employee — increases balance owed (Dr employee control, Cr cash/bank).',
            'amount_label' => 'Advance amount (Tk)',
        ],
        'loan' => [
            'icon' => 'fa-landmark',
            'gradient' => 'linear-gradient(135deg,#d97706,#b45309)',
            'gl_info' => 'Dr Employee Payable · Cr Bank/Cash',
            'submit_label' => 'Record Loan',
            'hint' => 'Loan disbursed — same as advance for ledger and GL.',
            'amount_label' => 'Loan amount (Tk)',
        ],
        'salary' => [
            'icon' => 'fa-money-bills',
            'gradient' => 'linear-gradient(135deg,#d97706,#b45309)',
            'gl_info' => 'Dr Salary Expense · Cr Bank/Cash',
            'submit_label' => 'Record Salary',
            'hint' => 'Salary paid out — reduces cash/bank; posts to salary expense.',
            'amount_label' => 'Salary amount (Tk)',
        ],
        'repayment' => [
            'icon' => 'fa-arrow-rotate-left',
            'gradient' => 'linear-gradient(135deg,#059669,#0d9488)',
            'gl_info' => 'Dr Bank/Cash · Cr Employee Payable',
            'submit_label' => 'Record Repayment',
            'hint' => 'Employee repays — Dr cash/bank, Cr employee control.',
            'amount_label' => 'Repayment amount (Tk)',
        ],
        'deduction' => [
            'icon' => 'fa-minus-circle',
            'gradient' => 'linear-gradient(135deg,#7c3aed,#6d28d9)',
            'gl_info' => 'Dr Salary Expense · Cr Employee Payable',
            'submit_label' => 'Record Deduction',
            'hint' => 'Deduction / recovery — money in; reduces employee balance.',
            'amount_label' => 'Deduction amount (Tk)',
        ],
        'adjustment' => [
            'icon' => 'fa-sliders',
            'gradient' => 'linear-gradient(135deg,#64748b,#475569)',
            'gl_info' => 'Dr/Cr varies by context',
            'submit_label' => 'Record Adjustment',
            'hint' => 'Manual adjustment — treated as outflow for GL unless you use repayment type for credits.',
            'amount_label' => 'Adjustment amount (Tk)',
        ],
    ];
    $cfg = $typeConfig[$oldType] ?? $typeConfig['advance'];
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: {{ $cfg['gradient'] }};" id="heroHeader">
        <div>
            <h1 class="h4 mb-1"><i class="fas {{ $cfg['icon'] }} me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75" id="heroSubtitle">
                GL posting: <strong>{{ $cfg['gl_info'] }}</strong> + employee ledger + optional bank balance sync.
            </p>
        </div>
        <div>
            <a href="{{ route('admin.employee-transactions.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Info banner --}}
    <div class="alert alert-info d-flex align-items-start mb-3" role="alert" id="infoBanner">
        <i class="fas fa-circle-info me-2 mt-1"></i>
        <div id="infoBannerContent">
            <strong>Transactions post immediately on save.</strong>
            GL is balanced (Dr Employee Payable / Cr Bank/Cash for outflows, Dr Bank/Cash / Cr Employee Payable for repayment),
            employee ledger is updated, and (if bank mode) the bank book balance is synced.
        </div>
    </div>

    <form method="POST" action="{{ route('admin.employee-transactions.store') }}" id="employeeTransactionForm">
        @csrf

        {{-- Transaction header card --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-sliders me-1 text-warning"></i> Transaction details</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    {{-- Transaction type selector --}}
                    <div class="col-md-4">
                        <label class="form-label" for="transaction_type" id="empTxnTypeLabel">
                            Transaction type <span class="text-danger">*</span>
                        </label>
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
                        <div class="form-text" id="typeHint">
                            <i class="fas fa-info-circle me-1"></i>
                            <span id="typeHintText">{{ $cfg['hint'] }}</span>
                        </div>
                    </div>

                    {{-- Employee picker (AJAX search, used by EmployeeTransaction.js) --}}
                    <div class="col-md-4 emp-txn-employee-picker position-relative">
                        <label class="form-label" for="empTxnEmployeeSearch" id="empTxnEmployeeSearchLabel">
                            Employee <span class="text-danger">*</span>
                        </label>
                        <input type="text" id="empTxnEmployeeSearch" class="form-control"
                               placeholder="Search employee by name or code…"
                               value="{{ $preselectEmployee ? $preselectEmployee->name : '' }}"
                               autocomplete="off"
                               @if($preselectEmployee) readonly @endif>
                        <input type="hidden" id="employee_id" name="employee_id"
                               value="{{ $preselectEmployee ? $preselectEmployee->id : old('employee_id') }}">
                        <button type="button" id="empTxnChangeEmployee" class="btn btn-sm btn-outline-secondary position-absolute"
                                style="right:4px;top:28px;{{ $preselectEmployee ? '' : 'display:none;' }}" title="Change employee">
                            <i class="fas fa-times"></i>
                        </button>
                        <div id="empTxnEmployeeSuggestions" class="emp-txn-suggest-dropdown"></div>
                        {{-- Recent employees --}}
                        <div id="empTxnEmployeeRecents" class="mt-1 d-flex flex-wrap gap-1"></div>
                        @error('employee_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        {{-- Employee hub link --}}
                        <div id="empTxnEmployeeHubLink" class="mt-1" style="display:none;">
                            <a id="empTxnEmployeeHubAnchor" href="#" target="_blank" class="small text-muted">
                                <i class="fas fa-external-link-alt me-1"></i> View employee profile
                            </a>
                        </div>
                    </div>

                    {{-- Employee due balance --}}
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <div id="dueSummary" class="alert alert-info py-2 small d-none" role="status">
                            <i class="fas fa-spinner fa-spin me-1"></i> Loading balance…
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

                    <div class="col-md-4">
                        <label class="form-label" for="transaction_date">
                            Transaction date <span class="text-danger">*</span>
                        </label>
                        <input type="date" id="transaction_date" name="transaction_date"
                               class="form-control @error('transaction_date') is-invalid @enderror"
                               required value="{{ $oldDate }}">
                        @error('transaction_date') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4">
                        <label class="form-label" for="collected_by">
                            Collected by
                            <span class="text-muted small">(optional)</span>
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

                    <div class="col-12">
                        <label class="form-label" for="description">Description</label>
                        <textarea id="description" name="description" rows="2" class="form-control"
                                  placeholder="Transaction description — purpose, remarks, etc.">{{ $oldDesc }}</textarea>
                        @error('description') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
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
                                @if(in_array($oldType, ['advance', 'loan', 'salary', 'adjustment']))
                                    Debit employee_ledger (increase payable)
                                @else
                                    Credit employee_ledger (reduce payable)
                                @endif
                            </strong>
                        </div>
                        <div class="col-md-4">
                            <span class="text-muted">Bank book:</span>
                            <strong id="bankBookLabel">
                                @if(in_array($oldType, ['advance', 'loan', 'salary']))
                                    Decrease (if bank mode)
                                @elseif($oldType === 'repayment')
                                    Increase (if bank mode)
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
                <a href="{{ route('admin.employee-transactions.index') }}" class="btn btn-outline-secondary">
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

{{-- Boot config for EmployeeTransaction.js --}}
<script>
    window.ET_BOOT = {
        baseUrl: '{{ url("/") }}/',
        csrf_token: '{{ csrf_token() }}',
        preselectEmployee: @json($preselectEmployee ? ['id' => $preselectEmployee->id, 'name' => $preselectEmployee->name, 'employee_code' => $preselectEmployee->employee_code ?? '', 'mobile' => $preselectEmployee->mobile ?? null] : null),
        glLabels: @json($glPreviewLabels ?? [
            'advance' => 'Dr Employee Payable · Cr Bank/Cash',
            'loan' => 'Dr Employee Payable · Cr Bank/Cash',
            'salary' => 'Dr Salary Expense · Cr Bank/Cash',
            'repayment' => 'Dr Bank/Cash · Cr Employee Payable',
            'deduction' => 'Dr Salary Expense · Cr Employee Payable',
            'adjustment' => 'Dr/Cr varies by context',
        ]),
        routes: {
            'index': '{{ route("admin.employee-transactions.index") }}',
            'show': '{{ route("admin.employee-transactions.show", ["id" => "__ID__"]) }}'.replace('__ID__', ''),
            'store': '{{ route("admin.employee-transactions.store") }}',
            'search': '{{ route("admin.employee-transactions.search") }}',
            'get-due': '{{ route("admin.employee-transactions.get-due") }}',
            'reverse': '{{ route("admin.employee-transactions.reverse", ["id" => "__ID__"]) }}'.replace('__ID__', ''),
            'employee-show': '{{ url("/admin/employees") }}/',
        },
    };
</script>

@push('scripts')
<link rel="stylesheet" href="/assets/css/employee-transaction-theme.css">
<script src="/assets/js/EmployeeTransaction.js"></script>
<script>
$(function () {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    // Dynamic type switching for the create form
    var $type = $('#transaction_type');
    var $mode = $('#mode');
    var $bankSection = $('#bank_section');
    var $bankId = $('#bank_id');
    var $amount = $('#amount');

    var typeConfigs = {
        advance: {
            gradient: 'linear-gradient(135deg,#d97706,#b45309)',
            icon: 'fa-hand-holding-dollar',
            gl_info: 'Dr Employee Payable · Cr Bank/Cash',
            hint: 'Cash/bank paid to employee — increases balance owed (Dr employee control, Cr cash/bank).',
            sub_ledger: 'Debit employee_ledger (increase payable)',
            bank_book: 'Decrease (if bank mode)',
            amount_label: 'Advance amount (Tk)',
            submit_label: 'Record Advance',
        },
        loan: {
            gradient: 'linear-gradient(135deg,#d97706,#b45309)',
            icon: 'fa-landmark',
            gl_info: 'Dr Employee Payable · Cr Bank/Cash',
            hint: 'Loan disbursed — same as advance for ledger and GL.',
            sub_ledger: 'Debit employee_ledger (increase payable)',
            bank_book: 'Decrease (if bank mode)',
            amount_label: 'Loan amount (Tk)',
            submit_label: 'Record Loan',
        },
        salary: {
            gradient: 'linear-gradient(135deg,#d97706,#b45309)',
            icon: 'fa-money-bills',
            gl_info: 'Dr Salary Expense · Cr Bank/Cash',
            hint: 'Salary paid out — reduces cash/bank; posts to salary expense.',
            sub_ledger: 'Debit employee_ledger (increase payable)',
            bank_book: 'Decrease (if bank mode)',
            amount_label: 'Salary amount (Tk)',
            submit_label: 'Record Salary',
        },
        repayment: {
            gradient: 'linear-gradient(135deg,#059669,#0d9488)',
            icon: 'fa-arrow-rotate-left',
            gl_info: 'Dr Bank/Cash · Cr Employee Payable',
            hint: 'Employee repays — Dr cash/bank, Cr employee control.',
            sub_ledger: 'Credit employee_ledger (reduce payable)',
            bank_book: 'Increase (if bank mode)',
            amount_label: 'Repayment amount (Tk)',
            submit_label: 'Record Repayment',
        },
        deduction: {
            gradient: 'linear-gradient(135deg,#7c3aed,#6d28d9)',
            icon: 'fa-minus-circle',
            gl_info: 'Dr Salary Expense · Cr Employee Payable',
            hint: 'Deduction / recovery — money in; reduces employee balance.',
            sub_ledger: 'Credit employee_ledger (reduce payable)',
            bank_book: 'No change',
            amount_label: 'Deduction amount (Tk)',
            submit_label: 'Record Deduction',
        },
        adjustment: {
            gradient: 'linear-gradient(135deg,#64748b,#475569)',
            icon: 'fa-sliders',
            gl_info: 'Dr/Cr varies by context',
            hint: 'Manual adjustment — treated as outflow for GL unless you use repayment type for credits.',
            sub_ledger: 'Debit employee_ledger (increase payable)',
            bank_book: 'No change',
            amount_label: 'Adjustment amount (Tk)',
            submit_label: 'Record Adjustment',
        },
    };

    function applyTypeConfig(type) {
        var cfg = typeConfigs[type] || typeConfigs.advance;

        // Hero header
        $('#heroHeader').css('background', cfg.gradient);
        $('#heroHeader .h4 i').attr('class', 'fas ' + cfg.icon + ' me-2');
        $('#heroSubtitle strong').text(cfg.gl_info);

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

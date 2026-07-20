@extends('layouts.admin')

@section('content')
@php
    $today      = now()->format('Y-m-d');
    $oldDate    = old('payment_date', $today);
    $oldCust    = old('customer_id', $selectedCustomerId ?? null);
    $oldBr      = old('branch_id', session('branch_id'));
    $oldBank    = old('bank_id');
    $oldMode    = old('payment_mode', 'cash');
    $oldAmt     = old('amount');
    $oldDisc    = old('discount_amount', 0);
    $oldRef     = old('reference_no');
    $oldNotes   = old('notes');

    // Server-side preloaded outstanding invoices (when customer_id in query string).
    $preloadedInvoices = $outstandingInvoices ?? collect();
@endphp

<div class="container-fluid py-2">
    {{-- Hero header --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#059669,#0d9488);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-hand-holding-dollar me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Receive money from a customer — GL posting (Dr Bank/Cash / Cr AR), customer ledger credit, optional invoice allocation.
            </p>
        </div>
        <div>
            <a href="{{ route('admin.customer-payments.index') }}" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </header>

    {{-- Info banner --}}
    <div class="alert alert-info d-flex align-items-start mb-3" role="alert">
        <i class="fas fa-circle-info me-2 mt-1"></i>
        <div>
            <strong>Payments post immediately on save.</strong>
            GL is balanced (Dr Bank/Cash / Cr Accounts Receivable), customer ledger is credited,
            @if (isset($selectedCustomerId) && $preloadedInvoices->isNotEmpty())
                and the selected invoice(s) will receive allocation automatically.
            @else
                and (optionally) you can allocate this payment against outstanding invoices below.
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('admin.customer-payments.store') }}" id="paymentForm">
        @csrf

        {{-- Payment header card --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <h2 class="h6 mb-0"><i class="fas fa-sliders me-1 text-success"></i> Payment details</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="customer_id">
                            Customer <span class="text-danger">*</span>
                        </label>
                        <select id="customer_id" name="customer_id"
                                class="form-select select2 @error('customer_id') is-invalid @enderror" required>
                            <option value="">Select customer</option>
                            @foreach ($customers as $c)
                                <option value="{{ $c->id }}"
                                    {{ (string) $oldCust === (string) $c->id ? 'selected' : '' }}>
                                    {{ $c->customer_code }} — {{ $c->customer_name }}
                                </option>
                            @endforeach
                        </select>
                        @error('customer_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
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

                    <div class="col-md-4">
                        <label class="form-label" for="payment_mode">
                            Payment mode <span class="text-danger">*</span>
                        </label>
                        <select id="payment_mode" name="payment_mode"
                                class="form-select @error('payment_mode') is-invalid @enderror" required>
                            <option value="cash"            {{ $oldMode === 'cash' ? 'selected' : '' }}>Cash</option>
                            <option value="bank"            {{ $oldMode === 'bank' ? 'selected' : '' }}>Bank</option>
                            <option value="mobile_banking"  {{ $oldMode === 'mobile_banking' ? 'selected' : '' }}>Mobile Banking</option>
                            <option value="cheque"          {{ $oldMode === 'cheque' ? 'selected' : '' }}>Cheque</option>
                            <option value="adjustment"      {{ $oldMode === 'adjustment' ? 'selected' : '' }}>Adjustment</option>
                        </select>
                        @error('payment_mode') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="col-md-4" id="bankField" style="display:none;">
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

                    <div class="col-md-4">
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

                    <div class="col-12">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea id="notes" name="notes" rows="2" class="form-control"
                                  placeholder="Internal notes — source, remarks, etc.">{{ $oldNotes }}</textarea>
                        @error('notes') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- Invoice allocation card --}}
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">
                    <i class="fas fa-file-invoice-dollar me-1 text-success"></i> Invoice allocation
                    <span class="text-muted small ms-2">(optional — pick a customer to load outstanding invoices)</span>
                </h2>
                <span class="badge bg-success-subtle text-success" id="allocationStatus">
                    <i class="fas fa-info-circle me-1"></i>No customer selected
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-hover align-middle mb-0" id="allocTable">
                        <thead class="table-light">
                            <tr>
                                <th>Invoice code</th>
                                <th>Date</th>
                                <th class="text-end">Total (Tk)</th>
                                <th class="text-end">Paid (Tk)</th>
                                <th class="text-end">Due (Tk)</th>
                                <th class="text-end" style="width:18%;">Allocate (Tk)</th>
                                <th class="text-center" style="width:8%;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="allocBody">
                            {{-- Rows injected by JS (or preloaded server-side below) --}}
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
                                <td colspan="5" class="text-end">Total allocated</td>
                                <td class="text-end" id="allocTotal">0.00</td>
                                <td></td>
                            </tr>
                            <tr class="table-light">
                                <td colspan="5" class="text-end text-muted">Payment amount entered</td>
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
                        Allocations are optional. If you allocate, the sum must be ≤ the payment amount.
                        Unallocated balance remains as customer advance credit.
                    </div>
                </div>
            </div>
        </div>

        {{-- Submit --}}
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex gap-2 justify-content-end">
                <a href="{{ route('admin.customer-payments.index') }}" class="btn btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Cancel
                </a>
                <button type="submit" class="btn btn-success" id="submitBtn">
                    <i class="fas fa-floppy-disk me-1"></i> Record Payment
                </button>
            </div>
        </div>
    </form>
</div>

@push('scripts')
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

    // ====== Select2 init ======
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    // ====== Show/hide bank field based on payment mode ======
    function toggleBankField() {
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

    // ====== AJAX: load outstanding invoices on customer change ======
    function loadInvoices(customerId) {
        if (!customerId) {
            $allocStatus.html('<i class="fas fa-info-circle me-1"></i>No customer selected');
            renderEmpty();
            return;
        }

        $allocStatus.html('<i class="fas fa-spinner fa-spin me-1"></i>Loading invoices…');

        $.ajax({
            url: '{{ route("admin.customer-payments.outstanding-invoices") }}',
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

    // ====== Events ======
    $customer.on('change', function () { loadInvoices($(this).val()); });
    $amount.on('input', recomputeTotal);

    // Delegated handlers for dynamically-injected rows
    $(document).on('input', '.alloc-input', recomputeTotal);
    $(document).on('click', '.alloc-clear', function () {
        $(this).closest('tr').find('.alloc-input').val(0);
        recomputeTotal();
    });

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
            .html('<i class="fas fa-spinner fa-spin me-1"></i> Recording…');
    });

    // ====== If customer is preselected (e.g., via query string), trigger initial load ======
    @if (!empty($selectedCustomerId) && $preloadedInvoices->isEmpty())
        loadInvoices('{{ $selectedCustomerId }}');
    @endif
});
</script>
@endpush
@endsection

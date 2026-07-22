{{--
    R19: Inline receive-payment modal body (partial).

    Returned by SalesInvoiceController::receiveModal() and injected
    into #receivePaymentModalContent on the sales-invoices index page
    via AJAX. Mirrors Legacy sales/receive_modal.php.

    Posts to the existing admin.customer-payments.store route — no
    new write endpoint is created. The R2 idempotency-token flow
    protects against duplicate submissions.

   Vars passed in:
      - $invoice: SalesInvoice model (with customer, branch, allocations loaded)
      - $payments: collection of payment-summary arrays (payment_id, payment_code, ...)
      - $banks: Collection\Bank
      - $branches: Collection\Branch
      - $defaultBranchId: int
      - $grandTotal: float
      - $amountPaid: float
      - $balance: float
--}}
@php
    $invoiceCode   = $invoice->invoice_code ?? '—';
    $customerName  = trim(($invoice->customer->shop_name ?? '') ?: ($invoice->customer->customer_name ?? '')) ?: 'Walk-in';
    $customerCode  = $invoice->customer->customer_code ?? '';
    $customerId    = (int) ($invoice->customer_id ?? 0);
    $invoiceId     = (int) ($invoice->id ?? 0);
    $todayStr      = date('Y-m-d');
    // R2: idempotency token — fresh UUID per modal open. The store
    // endpoint caches by this token; double-submit returns the
    // original payment with a "duplicate" warning instead of creating
    // a second payment.
    $idempotencyToken = \Illuminate\Support\Str::uuid()->toString();
@endphp

<div class="receive-modal-body"
     data-invoice-id="{{ $invoiceId }}"
     data-customer-id="{{ $customerId }}"
     data-balance="{{ number_format($balance, 2, '.', '') }}">

    {{-- Header --}}
    <div class="modal-header border-0 pb-0">
        <div>
            <span class="badge bg-primary-subtle text-primary mb-2">
                <i class="fas fa-file-invoice-dollar me-1"></i>{{ $invoiceCode }}
            </span>
            <h5 class="modal-title">
                <i class="fas fa-hand-holding-dollar me-2 text-success"></i>
                Receive payment
            </h5>
            <p class="mb-0 small text-muted">
                <i class="fas fa-user me-1"></i>{{ $customerName }}
                @if ($customerCode) · {{ $customerCode }} @endif
            </p>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
    </div>

    {{-- Summary stats --}}
    <div class="modal-body pt-2">
        <div class="row g-2 mb-3">
            <div class="col-4">
                <div class="border rounded p-2 text-center">
                    <div class="small text-muted">Invoice total</div>
                    <div class="fw-bold">Tk {{ number_format($grandTotal, 2) }}</div>
                </div>
            </div>
            <div class="col-4">
                <div class="border rounded p-2 text-center">
                    <div class="small text-muted">Paid so far</div>
                    <div class="fw-bold text-success">Tk {{ number_format($amountPaid, 2) }}</div>
                </div>
            </div>
            <div class="col-4">
                <div class="border rounded p-2 text-center bg-warning-subtle">
                    <div class="small text-muted">Balance due</div>
                    <div class="fw-bold text-warning-emphasis" id="srpBalanceDisplay">Tk {{ number_format($balance, 2) }}</div>
                </div>
            </div>
        </div>

        {{-- Payment form --}}
        <form id="srpForm" action="{{ route('admin.customer-payments.store') }}" method="POST">
            @csrf
            {{-- Hidden fields: invoice allocation + idempotency --}}
            <input type="hidden" name="transaction_type" value="receive">
            <input type="hidden" name="customer_id" value="{{ $customerId }}">
            <input type="hidden" name="payment_date" value="{{ $todayStr }}">
            <input type="hidden" name="idempotency_token" value="{{ $idempotencyToken }}">
            {{-- Single-invoice allocation: amount entered below is allocated to THIS invoice --}}
            <input type="hidden" name="alloc_invoice_id[]" value="{{ $invoiceId }}">
            <input type="hidden" name="alloc_amount[]" id="srpAllocAmountHidden" value="{{ number_format($balance, 2, '.', '') }}">

            {{-- Branch selector (defaults to invoice's branch) --}}
            <div class="row g-2 mb-2">
                <div class="col-md-6">
                    <label class="form-label small mb-1" for="srpBranchId">Branch</label>
                    <select id="srpBranchId" name="branch_id" class="form-select form-select-sm" required>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}" {{ (int) $b->id === (int) $defaultBranchId ? 'selected' : '' }}>
                                {{ $b->branch_code }} — {{ $b->branch_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small mb-1">Payment date</label>
                    <input type="text" class="form-control form-control-sm" value="{{ \Carbon\Carbon::parse($todayStr)->format('d M Y') }}" readonly>
                </div>
            </div>

            {{-- Amount + quick chips --}}
            <div class="mb-2">
                <label class="form-label small mb-1" for="srpAmount">
                    Amount received
                    <span class="text-danger">*</span>
                </label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">৳</span>
                    <input type="number" id="srpAmount" name="amount"
                           class="form-control" step="0.01" min="0.01"
                           value="{{ number_format($balance, 2, '.', '') }}"
                           max="{{ number_format($balance + 0.01, 2, '.', '') }}"
                           required autocomplete="off">
                </div>
                <div class="d-flex gap-1 mt-1 flex-wrap">
                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-quick="quarter">25%</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-quick="half">50%</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-quick="full">Full due</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-quick="clear">Clear</button>
                </div>
                <div id="srpAmountHint" class="form-text small">&nbsp;</div>
            </div>

            {{-- Payment mode --}}
            <div class="mb-2">
                <label class="form-label small mb-1">Payment mode</label>
                <div class="d-flex gap-3 flex-wrap">
                    <div class="form-check">
                        <input type="radio" name="payment_mode" id="srpModeCash" value="cash" class="form-check-input" checked>
                        <label for="srpModeCash" class="form-check-label small"><i class="fas fa-money-bill-wave me-1 text-success"></i>Cash</label>
                    </div>
                    <div class="form-check">
                        <input type="radio" name="payment_mode" id="srpModeBank" value="bank" class="form-check-input">
                        <label for="srpModeBank" class="form-check-label small"><i class="fas fa-building-columns me-1 text-primary"></i>Bank</label>
                    </div>
                    <div class="form-check">
                        <input type="radio" name="payment_mode" id="srpModeMobile" value="mobile_banking" class="form-check-input">
                        <label for="srpModeMobile" class="form-check-label small"><i class="fas fa-mobile-screen me-1"></i>Mobile</label>
                    </div>
                    <div class="form-check">
                        <input type="radio" name="payment_mode" id="srpModeCheque" value="cheque" class="form-check-input">
                        <label for="srpModeCheque" class="form-check-label small"><i class="fas fa-money-check me-1"></i>Cheque</label>
                    </div>
                </div>
            </div>

            {{-- Bank panel (hidden unless bank/cheque/mobile selected) --}}
            <div id="srpBankPanel" class="row g-2 mb-2" style="display:none;">
                <div class="col-md-6">
                    <label class="form-label small mb-1" for="srpBankId">Bank</label>
                    <select id="srpBankId" name="bank_id" class="form-select form-select-sm">
                        <option value="">— Select bank —</option>
                        @foreach ($banks as $b)
                            <option value="{{ $b->id }}">{{ $b->bank_name }} ({{ $b->bank_code }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small mb-1" for="srpReference">Reference no.</label>
                    <input type="text" id="srpReference" name="reference_no" class="form-control form-control-sm"
                           placeholder="Txn ID / cheque no." maxlength="100" autocomplete="off">
                </div>
            </div>

            {{-- Notes --}}
            <div class="mb-3">
                <label class="form-label small mb-1" for="srpNotes">Notes <small class="text-muted">(optional)</small></label>
                <textarea id="srpNotes" name="notes" class="form-control form-control-sm" rows="2"
                          maxlength="500" placeholder="Any remarks about this payment…"></textarea>
            </div>

            {{-- Payments already on this invoice --}}
            @if ($payments->isNotEmpty())
                <div class="border-top pt-2 mb-3">
                    <div class="d-flex justify-content-between align-items-baseline mb-1">
                        <span class="small fw-semibold">Payments on this invoice</span>
                        <span class="badge bg-secondary">{{ $payments->count() }} recorded</span>
                    </div>
                    <ul class="list-unstyled mb-0 small">
                        @foreach ($payments as $p)
                            <li class="d-flex justify-content-between align-items-center border-bottom py-1">
                                <div>
                                    <strong>{{ $p['payment_code'] }}</strong>
                                    <div class="text-muted" style="font-size:0.75rem;">
                                        {{ $p['payment_date'] ? \Carbon\Carbon::parse($p['payment_date'])->format('d-m-Y') : '—' }}
                                        · {{ ucfirst($p['payment_mode']) }}{{ $p['bank_name'] ? ' · ' . $p['bank_name'] : '' }}
                                        · by {{ $p['received_by_name'] }}
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="fw-semibold">Tk {{ number_format($p['allocated_amount'], 2) }}</span>
                                    <a href="{{ route('admin.customer-payments.print-receipt', $p['payment_id']) }}"
                                       class="btn btn-sm btn-outline-secondary py-0 px-1" target="_blank" rel="noopener" title="Print receipt">
                                        <i class="fas fa-print"></i>
                                    </a>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </form>
    </div>

    {{-- Footer --}}
    <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
            <i class="fas fa-times me-1"></i>Close
        </button>
        <button type="button" id="srpSubmit" class="btn btn-success btn-sm">
            <i class="fas fa-check me-1"></i>Receive payment
        </button>
    </div>
</div>

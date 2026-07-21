@extends('layouts.admin')

@section('content')
@php
    // Defaults for filter controls
    $filters = array_merge([
        'from_date'   => '',
        'to_date'     => '',
        'customer_id' => '',
        'branch_id'   => '',
        'status'      => '',
        'search'      => '',
    ], is_array($filters ?? null) ? $filters : []);

    $stats = array_merge([
        'total'       => 0,
        'draft'       => 0,
        'confirmed'   => 0,
        'cancelled'   => 0,
        'total_value' => 0,
    ], $stats ?? []);

    $statusBadge = function (string $status): string {
        return [
            'draft'     => '<span class="badge bg-warning-subtle text-warning"><i class="fas fa-pen-to-square me-1"></i>Draft</span>',
            'confirmed' => '<span class="badge bg-success-subtle text-success"><i class="fas fa-circle-check me-1"></i>Confirmed</span>',
            'cancelled' => '<span class="badge bg-secondary-subtle text-secondary"><i class="fas fa-ban me-1"></i>Cancelled</span>',
            'reversed'  => '<span class="badge bg-danger-subtle text-danger"><i class="fas fa-rotate-left me-1"></i>Reversed</span>',
        ][$status] ?? '<span class="badge bg-light text-dark">' . e($status) . '</span>';
    };
@endphp

<div class="container-fluid py-2">
    {{-- Hero header (purple/indigo = revenue) --}}
    <header class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 p-3 rounded-3 text-white"
            style="background: linear-gradient(135deg,#7c3aed,#4f46e5);">
        <div>
            <h1 class="h4 mb-1"><i class="fas fa-file-invoice-dollar me-2"></i>{{ $title }}</h1>
            <p class="mb-0 small opacity-75">
                Sales workflow — finalize cart → draft invoice → confirmed → godown → challan → payment.
                GL + customer ledger posted at finalize. Stock moves on challan (Phase 8.3).
            </p>
        </div>
        <div>
            <a href="{{ route('admin.sales.cart') }}" class="btn btn-light btn-sm">
                <i class="fas fa-cart-plus me-1"></i> New Sale
            </a>
        </div>
    </header>

    {{-- Stats cards: 5 cards --}}
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#7c3aed;">
                        <i class="fas fa-list"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['total']) }}</div>
                        <div class="text-muted small">Total</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#d97706;">
                        <i class="fas fa-pen-to-square"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['draft']) }}</div>
                        <div class="text-muted small">Draft</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#16a34a;">
                        <i class="fas fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['confirmed']) }}</div>
                        <div class="text-muted small">Confirmed</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#64748b;">
                        <i class="fas fa-ban"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">{{ number_format((int) $stats['cancelled']) }}</div>
                        <div class="text-muted small">Cancelled</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="rounded-3 d-flex align-items-center justify-content-center me-3 text-white"
                         style="width:48px;height:48px;background:#4f46e5;">
                        <i class="fas fa-taka-sign"></i>
                    </div>
                    <div>
                        <div class="h4 mb-0">Tk {{ number_format((float) $stats['total_value'], 2) }}</div>
                        <div class="text-muted small">Total value (ex. cancelled)</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filter form --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.sales-invoices.index') }}" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="from_date">From date</label>
                    <input type="date" id="from_date" name="from_date" class="form-control form-control-sm"
                           value="{{ $filters['from_date'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="to_date">To date</label>
                    <input type="date" id="to_date" name="to_date" class="form-control form-control-sm"
                           value="{{ $filters['to_date'] }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1" for="customer_id">Customer</label>
                    <select id="customer_id" name="customer_id" class="form-select form-select-sm select2">
                        <option value="">All customers</option>
                        @foreach ($customers as $c)
                            <option value="{{ $c->id }}"
                                {{ (string) $filters['customer_id'] === (string) $c->id ? 'selected' : '' }}>
                                {{ $c->customer_code }} — {{ $c->customer_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="branch_id">Branch</label>
                    <select id="branch_id" name="branch_id" class="form-select form-select-sm select2">
                        <option value="">All branches</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}"
                                {{ (string) $filters['branch_id'] === (string) $b->id ? 'selected' : '' }}>
                                {{ $b->branch_code }} — {{ $b->branch_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted mb-1" for="status">Status</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="">All statuses</option>
                        <option value="draft"     {{ $filters['status'] === 'draft' ? 'selected' : '' }}>Draft</option>
                        <option value="confirmed" {{ $filters['status'] === 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                        <option value="cancelled" {{ $filters['status'] === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                        <option value="reversed"  {{ $filters['status'] === 'reversed' ? 'selected' : '' }}>Reversed</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small text-muted mb-1" for="search">Search</label>
                    <input type="text" id="search" name="search" class="form-control form-control-sm"
                           placeholder="Invoice code" value="{{ $filters['search'] }}">
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-filter me-1"></i> Filter
                    </button>
                    <a href="{{ route('admin.sales-invoices.index') }}" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-eraser me-1"></i> Clear
                    </a>
                    <a id="csvExportBtn" href="{{ route('admin.sales-invoices.export-csv') }}" class="btn btn-outline-success btn-sm" target="_blank">
                        <i class="fas fa-file-csv me-1"></i> Export CSV
                    </a>
                </div>
            </form>
        </div>
    </div>

    {{-- Invoices table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover align-middle mb-0" id="dataTable">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Date</th>
                            <th>Customer</th>
                            <th>Branch</th>
                            <th class="text-end">Items</th>
                            <th class="text-end">Total (Tk)</th>
                            <th class="text-end">Paid (Tk)</th>
                            <th class="text-end">Due (Tk)</th>
                            <th>Status</th>
                            <th class="text-center">Soft Hold?</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($invoices as $inv)
                            <tr class="{{ $inv->is_reversed ? 'table-danger' : '' }}">
                                <td>
                                    <a href="{{ route('admin.sales-invoices.show', $inv) }}"
                                       class="fw-semibold text-decoration-none">
                                        {{ $inv->invoice_code }}
                                    </a>
                                </td>
                                <td class="text-nowrap small">
                                    {{ \Carbon\Carbon::parse($inv->invoice_date)->format('d M Y') }}
                                </td>
                                <td>
                                    @if ($inv->customer)
                                        <span class="fw-semibold">{{ $inv->customer->customer_name }}</span>
                                        <div class="small text-muted">{{ $inv->customer->customer_code }}</div>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($inv->branch)
                                        {{ $inv->branch->branch_name }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-end">{{ number_format($inv->items->count()) }}</td>
                                <td class="text-end">{{ number_format((float) $inv->total_amount, 2) }}</td>
                                <td class="text-end">{{ number_format((float) $inv->paid_amount, 2) }}</td>
                                <td class="text-end">
                                    @if ((float) $inv->due_amount > 0.01)
                                        <span class="text-danger fw-semibold">
                                            {{ number_format((float) $inv->due_amount, 2) }}
                                        </span>
                                    @else
                                        <span class="text-success">0.00</span>
                                    @endif
                                </td>
                                <td>{!! $statusBadge($inv->status) !!}</td>
                                <td class="text-center">
                                    @if ($inv->is_soft_hold)
                                        <span class="badge bg-danger-subtle text-danger" title="On soft hold">
                                            <i class="fas fa-hand"></i> Yes
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center text-nowrap">
                                    <a href="{{ route('admin.sales-invoices.show', $inv) }}"
                                       class="btn btn-sm btn-outline-secondary" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    @if ((float) $inv->due_amount > 0.01 && $inv->status !== 'cancelled' && !$inv->is_reversed)
                                        <button type="button"
                                                class="btn btn-sm btn-success btn-receive-payment"
                                                title="Receive payment"
                                                data-invoice-id="{{ $inv->id }}"
                                                data-invoice-code="{{ $inv->invoice_code }}">
                                            <i class="fas fa-hand-holding-dollar"></i>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="text-center text-muted py-5">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                                    No sales invoices found. Try adjusting filters or
                                    <a href="{{ route('admin.sales.cart') }}">start a new sale</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white">
            {{ $invoices->links() }}
        </div>
    </div>
</div>

{{--
    R19: Inline receive-payment modal shell.
    Body is fetched via AJAX from admin.sales-invoices.receive-modal
    and injected into #receivePaymentModalContent when the user
    clicks the "Receive" button on a row.
--}}
<div class="modal fade" id="receivePaymentModal" tabindex="-1"
     aria-labelledby="receivePaymentModalLabel" aria-hidden="true"
     data-bs-focus="false">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content" id="receivePaymentModalContent">
            {{-- AJAX-fetched _receive_modal_body.blade.php goes here --}}
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function () {
    $('.select2').select2({ theme: 'bootstrap-5', width: '100%' });

    // DataTables on the visible rows only (server-side pagination handles page size).
    $('#dataTable').DataTable({
        paging: false,
        info: false,
        ordering: true,
        dom: '<"row mb-2"<"col-md-6"f><"col-md-6 text-end"l>>rt',
        language: { search: 'Filter rows:', emptyTable: 'No sales invoices on this page.' }
    });

    // CSV export: pass current filter params to export URL
    $('#csvExportBtn').on('click', function(e) {
        e.preventDefault();
        const params = new URLSearchParams();
        const fields = ['from_date', 'to_date', 'customer_id', 'branch_id', 'status', 'search'];
        fields.forEach(f => {
            const val = $(`[name="${f}"]`).val();
            if (val && val !== '') params.set(f, val);
        });
        window.open($(this).attr('href') + '?' + params.toString(), '_blank');
    });

    // ============================================================
    // ============== R19: INLINE RECEIVE-PAYMENT MODAL ===========
    // ============================================================
    // Mirrors Legacy sales/receive_modal/{id} + sales-receive-payment.js.
    // When the user clicks the green "Receive" button on a row, we
    // fetch the modal body via AJAX and inject it into the modal shell.
    // The body contains the form, summary stats, payment history, and
    // submit handler — all rendered by the server, so no client-side
    // templating is needed (matches the Legacy pattern).
    //
    // Bootstrap 5 modal is created once and reused. The body is
    // replaced on every open so we always get fresh data + a fresh
    // idempotency token (R2 protects the store endpoint).

    var $receiveModal = $('#receivePaymentModal');
    var receiveModalBs = null;          // Bootstrap Modal instance (lazy)
    var $modalContent = $('#receivePaymentModalContent');

    function getReceiveModalBs() {
        if (!receiveModalBs) {
            receiveModalBs = bootstrap.Modal.getOrCreateInstance($receiveModal[0], {
                backdrop: 'static',
                keyboard: false,
            });
        }
        return receiveModalBs;
    }

    // Open modal + fetch body
    $('.btn-receive-payment').on('click', function () {
        var invoiceId = $(this).data('invoice-id');
        if (!invoiceId) return;
        // Loading state
        $modalContent.html(
            '<div class="modal-body text-center py-5">' +
                '<i class="fas fa-spinner fa-spin fa-2x text-primary mb-3"></i>' +
                '<div class="text-muted">Loading payment form…</div>' +
            '</div>'
        );
        getReceiveModalBs().show();

        $.ajax({
            url: '/admin/sales-invoices/' + invoiceId + '/receive-modal',
            method: 'GET',
            dataType: 'html',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).done(function (html) {
            $modalContent.html(html);
            initReceiveModalBody();
        }).fail(function (xhr) {
            $modalContent.html(
                '<div class="modal-body text-center py-5">' +
                    '<i class="fas fa-exclamation-triangle fa-2x text-danger mb-3"></i>' +
                    '<div class="text-danger">Could not load payment form.</div>' +
                    '<div class="small text-muted mt-2">' + (xhr.statusText || 'Server error') + '</div>' +
                    '<button type="button" class="btn btn-outline-secondary btn-sm mt-3" data-bs-dismiss="modal">Close</button>' +
                '</div>'
            );
        });
    });

    // Wire up the body after each AJAX load. Inline <script> tags
    // injected via .html() don't run, so we attach handlers here.
    function initReceiveModalBody() {
        var $body = $modalContent.find('.receive-modal-body');
        if (!$body.length) return;

        var balance = parseFloat($body.data('balance')) || 0;
        var $form = $('#srpForm');
        var $amount = $('#srpAmount');
        var $hint = $('#srpAmountHint');
        var $submit = $('#srpSubmit');
        var $bankPanel = $('#srpBankPanel');
        var $bankId = $('#srpBankId');
        var $allocHidden = $('#srpAllocAmountHidden');

        function parseNum(v) {
            var n = parseFloat(String(v).replace(/,/g, ''));
            return Number.isFinite(n) ? n : 0;
        }
        function fmtMoney(n) {
            return 'Tk ' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        // Validate amount + sync hidden allocation field
        function validateAmount() {
            var amt = parseNum($amount.val());
            if (balance <= 0) {
                $submit.prop('disabled', true);
                $hint.html('<i class="fas fa-check-circle text-success me-1"></i>Already fully paid.').removeClass('text-danger').addClass('text-success');
                return false;
            }
            if (amt <= 0) {
                $submit.prop('disabled', true);
                $hint.html('<i class="fas fa-info-circle text-warning me-1"></i>Enter an amount greater than zero.').removeClass('text-success').addClass('text-danger');
                return false;
            }
            if (amt > balance + 0.001) {
                $submit.prop('disabled', true);
                $hint.html('<i class="fas fa-triangle-exclamation text-danger me-1"></i>Amount cannot exceed balance due (' + fmtMoney(balance) + ').').addClass('text-danger');
                return false;
            }
            $submit.prop('disabled', false);
            $hint.html('<i class="fas fa-check text-success me-1"></i>Balance after this payment: ' + fmtMoney(Math.max(0, balance - amt))).removeClass('text-danger');
            // Sync the hidden allocation field so the store endpoint
            // knows how much of this payment goes to THIS invoice.
            $allocHidden.val(amt.toFixed(2));
            return true;
        }

        $amount.on('input change', validateAmount);
        validateAmount();  // initial

        // Bank panel toggle: show when mode is bank / mobile_banking / cheque
        $('input[name="payment_mode"]').on('change', function () {
            var mode = $(this).val();
            var showBank = (mode === 'bank' || mode === 'mobile_banking' || mode === 'cheque');
            $bankPanel.toggle(showBank);
            if (showBank && mode === 'bank') {
                $bankId.prop('required', true);
            } else {
                $bankId.prop('required', false);
            }
        });

        // Quick-amount chips
        $modalContent.find('[data-quick]').on('click', function () {
            var kind = $(this).data('quick');
            var val = 0;
            if (kind === 'quarter') val = balance / 4;
            else if (kind === 'half') val = balance / 2;
            else if (kind === 'full') val = balance;
            else if (kind === 'clear') val = 0;
            $amount.val(val.toFixed(2)).trigger('input');
        });

        // Submit (uses jQuery form post so we get redirect handling
        // for the existing admin.customer-payments.store route).
        $submit.on('click', function () {
            if ($submit.prop('disabled')) return;
            if (!validateAmount()) return;

            // Confirm large payments / over-balance scenarios.
            var amt = parseNum($amount.val());
            if (amt > balance) {
                Swal.fire({
                    title: 'Over-payment?',
                    html: 'Amount entered (' + fmtMoney(amt) + ') exceeds balance due (' + fmtMoney(balance) + ').<br>The excess will be applied to the customer\'s account as advance.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, proceed',
                    cancelButtonText: 'Cancel'
                }).then(function (r) {
                    if (r.isConfirmed) doSubmit();
                });
                return;
            }
            doSubmit();
        });

        function doSubmit() {
            $submit.prop('disabled', true).html(
                '<i class="fas fa-spinner fa-spin me-1"></i>Processing…'
            );
            // Use traditional form POST so the store endpoint's
            // redirect (to customer-payments.show) works normally —
            // no SPA-style response handling needed.
            $form[0].submit();
        }
    }
});
</script>
@push('css')
<style>
    /* R19: Inline receive-payment modal polish
       (mirrors legacy sales-receive-payment.css — kept inline to
        avoid an extra asset file. The legacy CSS file is loaded
        for parity on the Today's Sales page; here we just add
        small touches for the modal context.) */
    #receivePaymentModal .modal-body { max-height: 70vh; overflow-y: auto; }
    #receivePaymentModal .form-control-sm,
    #receivePaymentModal .form-select-sm { font-size: 0.875rem; }
    #receivePaymentModal .btn-sm { font-size: 0.8rem; }
</style>
@endpush
@endpush
@endsection

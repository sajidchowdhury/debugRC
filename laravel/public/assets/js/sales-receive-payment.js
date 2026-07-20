/**
 * Receive payment modal (sales/today) — init after AJAX load.
 * Inline scripts in injected HTML do not run under jQuery .html().
 */
(function (global) {
    'use strict';

    /**
     * Bootstrap 5 traps focus inside .modal — blocks SweetAlert inputs.
     * Let focus stay in .swal2-container when a nested dialog is open.
     */
    function ensureSwalBootstrapFocusFix() {
        if (global.__salesReceiveSwalFocusFix) {
            return;
        }
        global.__salesReceiveSwalFocusFix = true;
        document.addEventListener(
            'focusin',
            function (e) {
                if (e.target && e.target.closest && e.target.closest('.swal2-container')) {
                    e.stopImmediatePropagation();
                }
            },
            true
        );
    }

    ensureSwalBootstrapFocusFix();

    function parseNum(v) {
        const n = parseFloat(String(v).replace(/,/g, ''));
        return Number.isFinite(n) ? n : 0;
    }

    function formatMoney(n) {
        return 'Tk ' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function normalizeBaseUrl(url) {
        let u = String(url || '/');
        if (!u.endsWith('/')) {
            u += '/';
        }
        return u;
    }

    function buildPrintReceiptUrl(invoiceId, paymentId) {
        const base = normalizeBaseUrl(
            global.SALES_RECEIPT_BASE || global.SALES_TODAY_BASE || ''
        );
        let url = base + 'sales/print_receipt/' + encodeURIComponent(invoiceId);
        if (paymentId) {
            url += '?payment_id=' + encodeURIComponent(paymentId);
        }
        return url;
    }

    function init($root) {
        if (!$root || !$root.length) return;

        const invoiceId = $root.data('invoice-id');
        const customerId = parseInt($root.data('customer-id'), 10) || 0;
        const balance = parseNum($root.attr('data-balance'));
        const baseUrl = String($root.data('base-url') || global.SALES_TODAY_BASE || '/');
        const csrf = String($root.data('csrf') || global.CSRF_TOKEN || '');

        const $amount = $root.find('#srpAmount');
        const $hint = $root.find('#srpAmountHint');
        const $submit = $root.find('#srpSubmit');
        const $bankPanel = $root.find('#srpBankPanel');
        const $bankId = $root.find('#srpBankId');
        const $reference = $root.find('#srpReference');
        const $notes = $root.find('#srpNotes');

        function getMethod() {
            return $root.find('input[name="srp_payment_method"]:checked').val() || 'cash';
        }

        function setHint(text, isError) {
            $hint.text(text).toggleClass('is-error', !!isError);
        }

        function validateAmount() {
            const amt = parseNum($amount.val());
            if (balance <= 0) {
                $submit.prop('disabled', true);
                setHint('This invoice is already fully paid.', true);
                return false;
            }
            if (amt <= 0) {
                $submit.prop('disabled', true);
                setHint('Enter an amount greater than zero.', true);
                return false;
            }
            if (amt > balance + 0.001) {
                $submit.prop('disabled', true);
                setHint('Amount cannot exceed balance due (' + formatMoney(balance) + ').', true);
                return false;
            }
            $submit.prop('disabled', false);
            const remaining = Math.max(0, balance - amt);
            if (remaining < 0.01) {
                setHint('This will fully settle the invoice.', false);
            } else {
                setHint('After payment, Tk ' + remaining.toFixed(2) + ' will remain due.', false);
            }
            return true;
        }

        function toggleBankPanel() {
            const isBank = getMethod() === 'bank';
            $bankPanel.toggleClass('d-none', !isBank);
        }

        $root.find('input[name="srp_payment_method"]').on('change', toggleBankPanel);
        toggleBankPanel();

        $amount.on('input change', validateAmount);
        validateAmount();

        $root.find('[data-srp-quick]').on('click', function () {
            const mode = $(this).data('srp-quick');
            if (mode === 'full') {
                $amount.val(balance > 0 ? balance.toFixed(2) : '');
            } else if (mode === 'half') {
                const half = balance > 0 ? Math.round((balance / 2) * 100) / 100 : 0;
                $amount.val(half > 0 ? half.toFixed(2) : '');
            } else if (mode === 'clear') {
                $amount.val('');
            }
            validateAmount();
            $amount.trigger('focus');
        });

        function setLoading(on) {
            $submit.prop('disabled', on);
            $submit.find('.srp-btn-label').toggleClass('d-none', on);
            $submit.find('.srp-btn-loading').toggleClass('d-none', !on);
        }

        $submit.on('click', function () {
            if (!validateAmount()) return;

            const amount = parseNum($amount.val());
            const method = getMethod();
            const bankId = method === 'bank' ? parseInt($bankId.val(), 10) || 0 : 0;

            if (method === 'bank' && !bankId) {
                if (global.Swal) {
                    Swal.fire({ icon: 'warning', title: 'Select bank', text: 'Choose a bank account for this payment.' });
                } else {
                    alert('Please select a bank account.');
                }
                $bankId.focus();
                return;
            }

            const payload = {
                invoice_id: invoiceId,
                customer_id: customerId,
                receive_amount: amount,
                payment_mode: method === 'bank' ? String(bankId) : 'cash',
                reference_no: $.trim($reference.val()),
                remarks: $.trim($notes.val()),
                csrf_token: csrf
            };

            setLoading(true);

            $.ajax({
                url: baseUrl + 'sales/save_payment',
                method: 'POST',
                data: payload,
                dataType: 'json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .done(function (res) {
                    if (res && (res.success || res.status === 'success')) {
                        const modalEl = document.getElementById('receiveModal');
                        if (modalEl && global.bootstrap) {
                            const inst = bootstrap.Modal.getInstance(modalEl);
                            if (inst) inst.hide();
                        }
                        const receiptUrl = buildPrintReceiptUrl(invoiceId, res.payment_id);
                        if (global.Swal) {
                            const payCode = res.payment_code
                                ? '<br><small class="text-muted">Payment: <strong>'
                                    + String(res.payment_code).replace(/[&<>"']/g, c => ({
                                        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
                                    })[c]) + '</strong></small>'
                                : '';
                            Swal.fire({
                                icon: 'success',
                                title: 'Payment recorded',
                                html: (res.message || 'Payment saved successfully.') + payCode,
                                showCancelButton: true,
                                confirmButtonText: 'Print receipt',
                                cancelButtonText: 'Stay here',
                                confirmButtonColor: '#059669',
                                reverseButtons: true,
                            }).then(r => {
                                if (r.isConfirmed) {
                                    window.open(receiptUrl, '_blank');
                                }
                            });
                        } else {
                            if (confirm('Payment saved. Open print receipt?')) {
                                window.open(receiptUrl, '_blank');
                            }
                        }
                        $(document).trigger('salesToday:paymentRecorded', {
                            invoiceId: invoiceId,
                            is_fully_paid: !!(res && res.is_fully_paid),
                            balance_due: parseFloat((res && res.balance_due) || 0),
                        });
                    } else {
                        const msg = (res && res.message) ? res.message : 'Could not save payment.';
                        if (global.Swal) {
                            Swal.fire({ icon: 'error', title: 'Failed', text: msg });
                        } else {
                            alert(msg);
                        }
                    }
                })
                .fail(function (xhr) {
                    let msg = 'Network error. Try again.';
                    const text = xhr.responseText || '';
                    try {
                        const j = JSON.parse(text);
                        if (j.message) msg = j.message;
                    } catch (e) {
                        if (text.indexOf('Fatal error') !== -1 || text.indexOf('<b>') !== -1) {
                            msg = 'Server error while saving payment. Contact support if this continues.';
                        } else if (xhr.status === 403) {
                            msg = 'Session expired. Refresh the page and try again.';
                        }
                    }
                    if (global.Swal) {
                        Swal.fire({ icon: 'error', title: 'Error', text: msg });
                    } else {
                        alert(msg);
                    }
                })
                .always(function () {
                    setLoading(false);
                    validateAmount();
                });
        });

        function reloadModal() {
            $.get(baseUrl + 'sales/receive_modal/' + invoiceId)
                .done(function (html) {
                    $('#receiveModalContent').html(html);
                    const $newRoot = $('#receiveModalContent').find('.srp-modal').first();
                    if ($newRoot.length) {
                        init($newRoot);
                    }
                });
        }

        $root.on('click', '.btn-reverse-payment', function () {
            const $btn = $(this);
            const paymentId = parseInt($btn.data('payment-id'), 10) || 0;
            const paymentCode = String($btn.data('payment-code') || '');
            const amt = parseNum($btn.data('amount'));

            if (!paymentId) return;

            const doReverse = function (reason) {
                $btn.prop('disabled', true);
                $.ajax({
                    url: baseUrl + 'sales/reverse_payment',
                    method: 'POST',
                    dataType: 'json',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    data: {
                        payment_id: paymentId,
                        reason: reason,
                        csrf_token: csrf,
                    },
                })
                    .done(function (res) {
                        if (res && (res.success || res.status === 'success')) {
                            if (global.Swal) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Payment reversed',
                                    text: res.message || 'Allocation, ledger, and GL have been undone.',
                                });
                            }
                            $(document).trigger('salesToday:paymentRecorded', {
                                invoiceId: invoiceId,
                                reversedPaymentId: paymentId,
                            });
                            reloadModal();
                        } else {
                            const msg = (res && res.message) ? res.message : 'Could not reverse payment.';
                            if (global.Swal) {
                                Swal.fire({ icon: 'error', title: 'Failed', text: msg });
                            } else {
                                alert(msg);
                            }
                            $btn.prop('disabled', false);
                        }
                    })
                    .fail(function (xhr) {
                        let msg = 'Network error. Try again.';
                        try {
                            const j = JSON.parse(xhr.responseText);
                            if (j.message) msg = j.message;
                        } catch (e) { /* ignore */ }
                        if (global.Swal) {
                            Swal.fire({ icon: 'error', title: 'Error', text: msg });
                        } else {
                            alert(msg);
                        }
                        $btn.prop('disabled', false);
                    });
            };

            if (global.Swal) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Reverse payment?',
                    html:
                        'Undo <strong>' + paymentCode + '</strong> (Tk '
                        + amt.toFixed(2)
                        + ')?<br><small class="text-muted">This restores invoice balance and reverses GL.</small>',
                    input: 'textarea',
                    inputLabel: 'Reason (required)',
                    inputPlaceholder: 'e.g. Wrong amount entered, duplicate receipt…',
                    inputAttributes: {
                        maxlength: 500,
                        'aria-label': 'Reversal reason',
                    },
                    showCancelButton: true,
                    confirmButtonText: 'Yes, reverse',
                    confirmButtonColor: '#dc2626',
                    heightAuto: false,
                    returnFocus: false,
                    didOpen: function () {
                        const input = Swal.getInput();
                        if (input) {
                            input.removeAttribute('readonly');
                            input.removeAttribute('disabled');
                            setTimeout(function () {
                                input.focus();
                            }, 50);
                        }
                    },
                    preConfirm: function (value) {
                        const r = String(value || '').trim();
                        if (r.length < 5) {
                            Swal.showValidationMessage('Please enter at least 5 characters.');
                            return false;
                        }
                        return r;
                    },
                }).then(function (result) {
                    if (result.isConfirmed && result.value) {
                        doReverse(result.value);
                    }
                });
            } else {
                const reason = prompt('Reason for reversal (min 5 chars):');
                if (reason && reason.trim().length >= 5) {
                    doReverse(reason.trim());
                }
            }
        });

        setTimeout(function () {
            if (balance > 0 && $amount.length) {
                $amount.trigger('focus').select();
            }
        }, 350);
    }

    global.SalesReceivePayment = {
        init: function (container) {
            const $el = container instanceof jQuery ? container : $(container);
            const $root = $el.hasClass('srp-modal') ? $el : $el.find('.srp-modal').first();
            if ($root.length) init($root);
        },
    };
})(window);
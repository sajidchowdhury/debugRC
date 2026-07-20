/**
 * Employee transactions — index, create, reverse.
 */
(function () {
    'use strict';

    const TYPE_HINTS = {
        advance: 'Cash/bank paid to employee — increases balance owed (Dr employee control, Cr cash/bank).',
        loan: 'Loan disbursed — same as advance for ledger and GL.',
        salary: 'Salary paid out — reduces cash/bank; posts to employee control account.',
        repayment: 'Employee repays — Dr cash/bank, Cr employee control.',
        deduction: 'Deduction / recovery — money in; reduces employee balance.',
        adjustment: 'Manual adjustment — treated as outflow for GL unless you use repayment type for credits.',
    };

    const TYPE_LABELS = {
        advance: 'Advance',
        loan: 'Loan',
        salary: 'Salary',
        repayment: 'Repayment',
        deduction: 'Deduction',
        adjustment: 'Adjustment',
    };


    let empTable = null;

    function etBaseUrl() {
        if (window.ET_BOOT?.baseUrl) {
            const u = window.ET_BOOT.baseUrl;
            return u.endsWith('/') ? u : u + '/';
        }
        const el = document.getElementById('base_url');
        const u = el ? el.value : '/';
        return u.endsWith('/') ? u : u + '/';
    }

    function escapeHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function formatMoney(n) {
        return 'Tk ' + parseFloat(n || 0).toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    async function parseJsonResponse(response) {
        const text = await response.text();
        if (!text) {
            return { status: 'error', message: 'Empty server response (HTTP ' + response.status + ')' };
        }
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Non-JSON response', text.slice(0, 500));
            return {
                status: 'error',
                message: 'Invalid server response (HTTP ' + response.status + '). Refresh and try again.',
            };
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const base = etBaseUrl();
        window.ET_BASE = base;

        document.body.addEventListener('click', (e) => {
            const btn = e.target.closest('.js-emp-reverse');
            if (!btn) return;
            reverseTransaction(btn.dataset.paymentId, btn.dataset.paymentCode);
        });

        const txnTable = document.getElementById('empTxnTable');
        if (txnTable && txnTable.querySelector('tbody tr')) {
            initIndexTable();
        } else if (document.getElementById('empTxnCards')) {
            document.getElementById('empTxnCards').innerHTML =
                '<p class="text-muted text-center py-4 mb-0">No transactions found.</p>';
        }

        if (document.getElementById('empTxnFilterForm') && window.showReversed) {
            const statusField = document.querySelector('#empTxnFilterForm [name="status"]');
            if (statusField) {
                statusField.value = 'reversed';
                statusField.disabled = true;
            }
        }

        if (document.getElementById('employeeTransactionForm')) {
            initCreateForm(base);
        }

        $(window).on('resize', () => {
            if (empTable) renderMobileCards(empTable);
        });
    });

    function initIndexTable() {
        if (typeof $ === 'undefined' || !$.fn.DataTable) {
            renderMobileCardsFromDom();
            return;
        }

        const $table = $('#empTxnTable');
        if ($.fn.DataTable.isDataTable($table)) {
            $table.DataTable().destroy();
        }

        empTable = $table.DataTable({
            pageLength: 25,
            order: [[0, 'desc']],
            language: { emptyTable: 'No transactions for selected filters', search: 'Quick search:' },
            columnDefs: [{ orderable: false, targets: -1 }],
            drawCallback() {
                renderMobileCards(empTable);
            },
        });
    }

    function renderMobileCards(table) {
        const container = document.getElementById('empTxnCards');
        if (!container || window.innerWidth >= 768) {
            if (container) container.innerHTML = '';
            return;
        }

        const base = window.ET_BASE || etBaseUrl();
        let html = '';

        table.rows({ page: 'current' }).every(function () {
            const row = this.node();
            if (!row) return;
            const $tr = $(row);
            const id = $tr.find('.js-emp-reverse').data('paymentId')
                || $tr.find('a[href*="details/"]').attr('href')?.split('/').pop();
            const code = $tr.find('.branch-code-pill').text().trim();
            const name = $tr.find('.branch-name-cell .name').text().trim();
            const typePill = $tr.find('.emp-txn-type-pill');
            const typeCls = typePill.attr('class')?.match(/emp-txn-type-pill\s+(\S+)/)?.[1] || 'advance';
            const typeLabel = typePill.text().trim();
            const amount = $tr.find('.emp-txn-amount').text().trim();
            const date = $tr.find('td:first small').text().trim();
            const mode = $tr.find('td').eq(5).text().trim();
            const canReverse = !window.showReversed && $tr.find('.js-emp-reverse').length > 0;

            html += `<article class="emp-txn-mobile-card${$tr.hasClass('table-secondary') ? ' reversed' : ''}">
                <div class="d-flex justify-content-between gap-2">
                    <div><div class="branch-code-pill">${escapeHtml(code)}</div>
                    <div class="fw-semibold mt-1">${escapeHtml(name)}</div></div>
                    <span class="emp-txn-type-pill ${escapeHtml(typeCls)}">${escapeHtml(typeLabel)}</span>
                </div>
                <div class="d-flex justify-content-between mt-2">
                    <span class="small text-muted">${escapeHtml(date)} · ${escapeHtml(mode)}</span>
                    <span class="emp-txn-amount ${escapeHtml(typeCls)}">${escapeHtml(amount)}</span>
                </div>
                <div class="mt-2 d-flex gap-2">
                    <a href="${base}EmployeeTransaction/details/${id}" class="btn btn-sm btn-outline-primary flex-fill">Details</a>
                    ${canReverse ? `<button type="button" class="btn btn-sm btn-outline-danger js-emp-reverse flex-fill"
                        data-payment-id="${id}" data-payment-code="${escapeHtml(code)}">Reverse</button>` : ''}
                </div>
            </article>`;
        });

        container.innerHTML = html || '<p class="text-muted text-center py-4">No transactions found.</p>';
    }

    function renderMobileCardsFromDom() {
        const container = document.getElementById('empTxnCards');
        const tbody = document.querySelector('#empTxnTable tbody');
        if (!container || !tbody || window.innerWidth >= 768) return;
        const base = window.ET_BASE || etBaseUrl();
        let html = '';
        tbody.querySelectorAll('tr').forEach((tr) => {
            const revBtn = tr.querySelector('.js-emp-reverse');
            const id = revBtn?.dataset.paymentId || tr.querySelector('a[href*="details/"]')?.href?.split('/').pop() || '';
            const code = tr.querySelector('.branch-code-pill')?.textContent?.trim() || '';
            const canReverse = !window.showReversed && !!revBtn;
            html += `<article class="emp-txn-mobile-card">
                <div class="branch-code-pill">${escapeHtml(code)}</div>
                <div class="mt-2 d-flex gap-2">
                    <a href="${base}EmployeeTransaction/details/${id}" class="btn btn-sm btn-outline-primary">Details</a>
                    ${canReverse ? `<button type="button" class="btn btn-sm btn-outline-danger js-emp-reverse" data-payment-id="${id}" data-payment-code="${escapeHtml(code)}">Reverse</button>` : ''}
                </div>
            </article>`;
        });
        container.innerHTML = html;
    }

    function initCreateForm(base) {
        const form = document.getElementById('employeeTransactionForm');
        const modeSelect = document.getElementById('payment_mode');
        const bankSection = document.getElementById('bank_section');
        const bankSelect = document.getElementById('bank_id');
        const employeeSelect = document.getElementById('employee_id');
        const dueSummary = document.getElementById('dueSummary');
        const typeSelect = document.getElementById('transaction_type');
        const amountInput = document.getElementById('amount');
        const typeHint = document.getElementById('typeHint');
        const glPreview = document.getElementById('accounting_preview');
        const glLabels = window.ET_BOOT?.glLabels || {};
        const glPreviewApi = window.AccountingJournalPreview;

        function renderGlPreview(type, amt, mode, bankName) {
            if (!glPreviewApi) {
                return;
            }
            glPreviewApi.renderEmployeePreview(glPreview, {
                type,
                amount: amt,
                mode,
                bankName,
                glLabels,
                partySelected: !!employeeSelect?.value,
            });
        }

        function syncBank() {
            if (!modeSelect || !bankSection) return;
            const bank = modeSelect.value === 'bank';
            bankSection.style.display = bank ? 'block' : 'none';
            if (bankSelect) {
                if (bank) bankSelect.setAttribute('required', 'required');
                else bankSelect.removeAttribute('required');
            }
        }

        modeSelect?.addEventListener('change', () => {
            syncBank();
            updatePreview();
        });

        typeSelect?.addEventListener('change', () => {
            if (typeHint) typeHint.textContent = TYPE_HINTS[typeSelect.value] || '';
            updatePreview();
        });

        function updatePreview() {
            const opt = employeeSelect?.selectedOptions?.[0];
            const name = opt?.textContent?.trim() || 'Employee';
            const type = typeSelect?.value || '';
            const amt = parseFloat(amountInput?.value || 0);
            const mode = modeSelect?.value || 'cash';
            const bankName = bankSelect?.selectedOptions?.[0]?.text || glLabels.bank || 'Bank';
            document.getElementById('previewAvatar')?.replaceChildren(document.createTextNode(name.charAt(0) || '?'));
            const pn = document.getElementById('previewEmployee');
            if (pn) pn.textContent = name.split('(')[0].trim();
            const pt = document.getElementById('previewType');
            if (pt) pt.textContent = TYPE_LABELS[type] || 'Type';
            const pa = document.getElementById('previewAmount');
            if (pa) {
                pa.textContent = formatMoney(amountInput?.value || 0);
                pa.className = 'mt-2 emp-txn-amount ' + (type || 'advance');
            }
            renderGlPreview(type, amt, mode, bankName);
        }

        [employeeSelect, typeSelect, amountInput].forEach((el) => {
            el?.addEventListener('input', updatePreview);
            el?.addEventListener('change', updatePreview);
        });

        bankSelect?.addEventListener('change', updatePreview);

        employeeSelect?.addEventListener('change', async function () {
            const eid = this.value;
            updatePreview();
            if (!eid || !dueSummary) return;
            dueSummary.classList.remove('d-none');
            dueSummary.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Loading balance…';
            try {
                const res = await fetch(base + 'EmployeeTransaction/get_due', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    credentials: 'same-origin',
                    body: 'employee_id=' + encodeURIComponent(eid),
                });
                const data = await parseJsonResponse(res);
                if (data.status === 'success') {
                    dueSummary.innerHTML = '<i class="fas fa-wallet me-1"></i> Balance owed: <strong>' + formatMoney(data.due_balance) + '</strong>';
                } else {
                    dueSummary.innerHTML = '<span class="text-danger">Could not load balance</span>';
                }
            } catch (e) {
                dueSummary.innerHTML = '<span class="text-danger">Error loading balance</span>';
            }
        });

        if (employeeSelect?.value) employeeSelect.dispatchEvent(new Event('change'));

        form?.addEventListener('submit', async function (e) {
            e.preventDefault();
            if (!form.checkValidity()) {
                form.classList.add('was-validated');
                return;
            }

            const formData = new FormData(form);
            const submitBtn = form.querySelector('button[type="submit"]');
            const orig = submitBtn?.innerHTML;
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving…';
            }

            try {
                const res = await fetch(base + 'EmployeeTransaction/store', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                const result = await parseJsonResponse(res);
                if (result.status === 'success') {
                    Swal.fire({ icon: 'success', title: 'Saved', text: result.message, timer: 2200, showConfirmButton: false })
                        .then(() => {
                            const tid = result.transaction_id || result.payment_id;
                            window.location.href = tid
                                ? base + 'EmployeeTransaction/details/' + tid
                                : base + 'EmployeeTransaction';
                        });
                } else {
                    Swal.fire('Error', result.message || 'Save failed', 'error');
                }
            } catch (err) {
                Swal.fire('Error', err.message || 'Network error', 'error');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = orig;
                }
            }
        });

        syncBank();
        updatePreview();
    }

    async function reverseTransaction(id, paymentCode) {
        const csrf = document.querySelector('input[name="csrf_token"]')?.value || '';
        const { value: reason, isConfirmed } = await Swal.fire({
            title: 'Reverse transaction?',
            html:
                'Voucher <strong>' + escapeHtml(paymentCode || id) + '</strong> will be reversed.<br>' +
                '<span class="text-danger small">GL, employee ledger, and bank book (if any) are undone. This cannot be undone.</span>',
            input: 'textarea',
            inputLabel: 'Reason (required, min 3 characters)',
            inputPlaceholder: 'Why is this transaction being reversed?',
            inputAttributes: { 'aria-label': 'Reversal reason', maxlength: 500 },
            showCancelButton: true,
            confirmButtonText: 'Yes, reverse',
            confirmButtonColor: '#dc2626',
            focusConfirm: false,
            preConfirm: (v) => {
                const r = String(v || '').trim();
                if (r.length < 3) {
                    Swal.showValidationMessage('Enter at least 3 characters.');
                    return false;
                }
                return r;
            },
        });

        if (!isConfirmed || !reason) return;

        Swal.fire({ title: 'Reversing…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        try {
            const body = new URLSearchParams({ csrf_token: csrf, id: String(id), reason: reason.trim() });
            const res = await fetch((window.ET_BASE || etBaseUrl()) + 'EmployeeTransaction/reverse', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: body.toString(),
            });
            const result = await parseJsonResponse(res);
            Swal.close();
            if (result.status === 'success') {
                Swal.fire({ icon: 'success', title: 'Reversed', text: result.message, timer: 2000, showConfirmButton: false })
                    .then(() => {
                        window.location.href = result.redirect_url
                            || (window.ET_BASE || etBaseUrl()) + 'EmployeeTransaction/details/' + id;
                    });
            } else {
                Swal.fire('Reversal failed', result.message || 'Could not reverse', 'error');
            }
        } catch (e) {
            Swal.close();
            Swal.fire('Error', 'Could not reach server', 'error');
        }
    }

    window.reverseTransaction = reverseTransaction;
})();
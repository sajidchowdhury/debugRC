/**
 * Sales return — quick receive workspace (create page + index offcanvas)
 */
(function () {
    'use strict';

    let BASE_URL = '';

    function getCsrfToken() {
        return window.CSRF_TOKEN || '';
    }

    function salesReturnPostBody(params) {
        const body = new URLSearchParams(params);
        const token = getCsrfToken();
        if (token) {
            body.append('csrf_token', token);
        }
        return body.toString();
    }

    function salesReturnPostOptions(bodyString) {
        return {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: bodyString,
        };
    }

    function formatMoney(n) {
        return 'Tk ' + (parseFloat(n) || 0).toFixed(2);
    }

    function formatQty(n) {
        const v = parseFloat(n) || 0;
        return v.toFixed(2).replace(/\.?0+$/, '') || '0';
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    /** BaseController::sendJson wraps numeric lists in { status, data: [...] }. */
    function parseInvoiceSearchPayload(raw) {
        if (Array.isArray(raw)) {
            return { rows: raw, error: null };
        }
        if (!raw || typeof raw !== 'object') {
            return { rows: [], error: null };
        }
        if (raw.status === 'error') {
            return { rows: [], error: raw.message || 'Search failed.' };
        }
        if (Array.isArray(raw.data)) {
            return { rows: raw.data, error: null };
        }
        if (Array.isArray(raw.invoices)) {
            return { rows: raw.invoices, error: null };
        }
        return { rows: [], error: null };
    }

    class SalesReturnWorkspace {
        constructor(rootEl, options) {
            this.root = rootEl;
            this.id = rootEl.id;
            this.onSaved = options.onSaved || null;
            this.searchInput = rootEl.querySelector(`#${this.id}_invoiceSearch`);
            this.searchClear = rootEl.querySelector(`#${this.id}_searchClear`);
            this.resultsDiv = rootEl.querySelector(`#${this.id}_searchResults`);
            this.invoiceBar = rootEl.querySelector(`#${this.id}_invoiceBar`);
            this.detailsDiv = rootEl.querySelector(`#${this.id}_invoiceDetails`);
            this.formStep = rootEl.querySelector('[data-step="form"]');
            this.currentInvoice = null;
            this.lastResults = [];
            this.focusIndex = -1;
            this.searchTimer = null;
            this.searchSeq = 0;
            this.bindEvents();
        }

        bindEvents() {
            if (!this.searchInput) return;

            this.searchInput.addEventListener('input', () => {
                const term = this.searchInput.value.trim();
                this.searchClear.classList.toggle('d-none', term.length === 0);
                clearTimeout(this.searchTimer);
                if (term.length < 2) {
                    this.clearResults();
                    return;
                }
                this.searchTimer = setTimeout(() => this.runSearch(term), 280);
            });

            this.searchInput.addEventListener('keydown', (e) => this.onSearchKeydown(e));

            this.searchClear.addEventListener('click', () => {
                this.resetWorkspace();
                this.searchInput.focus();
            });

            this.resultsDiv.addEventListener('click', (e) => {
                const card = e.target.closest('[data-invoice-index]');
                if (!card) return;
                e.preventDefault();
                const idx = parseInt(card.dataset.invoiceIndex, 10);
                if (this.lastResults[idx]) {
                    this.selectInvoice(this.lastResults[idx]);
                }
            });
        }

        onSearchKeydown(e) {
            const cards = this.resultsDiv.querySelectorAll('.sr-create-result-card');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (!cards.length) return;
                this.focusIndex = Math.min(this.focusIndex + 1, cards.length - 1);
                this.updateFocus(cards);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (!cards.length) return;
                this.focusIndex = Math.max(this.focusIndex - 1, 0);
                this.updateFocus(cards);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (this.focusIndex >= 0 && this.lastResults[this.focusIndex]) {
                    this.selectInvoice(this.lastResults[this.focusIndex]);
                } else if (this.lastResults.length === 1) {
                    this.selectInvoice(this.lastResults[0]);
                } else {
                    const term = this.searchInput.value.trim();
                    if (term.length >= 2) this.runSearch(term);
                }
            } else if (e.key === 'Escape') {
                this.clearResults();
            }
        }

        updateFocus(cards) {
            cards.forEach((c, i) => c.classList.toggle('is-focused', i === this.focusIndex));
            cards[this.focusIndex]?.scrollIntoView({ block: 'nearest' });
        }

        clearResults() {
            this.resultsDiv.innerHTML = '';
            this.lastResults = [];
            this.focusIndex = -1;
            this.searchInput.setAttribute('aria-expanded', 'false');
        }

        setResultsMessage(html, className) {
            this.resultsDiv.innerHTML = `<p class="sr-create-results-msg ${className || ''}">${html}</p>`;
        }

        async runSearch(term) {
            const seq = ++this.searchSeq;
            this.setResultsMessage('<i class="fas fa-spinner fa-spin"></i> Searching…', 'is-loading');
            this.searchInput.setAttribute('aria-expanded', 'true');

            try {
                const res = await fetch(
                    `${BASE_URL}SalesReturn/search_invoice`,
                    salesReturnPostOptions(salesReturnPostBody({ term }))
                );
                const raw = await res.json();
                if (seq !== this.searchSeq) return;

                const parsed = parseInvoiceSearchPayload(raw);
                if (parsed.error) {
                    this.setResultsMessage(escapeHtml(parsed.error), 'is-warn');
                    return;
                }

                this.lastResults = parsed.rows;
                this.focusIndex = -1;

                if (this.lastResults.length === 0) {
                    this.setResultsMessage(
                        'No challan-completed invoice found for your branch. Try invoice #, shop name, or mobile.',
                        'is-info'
                    );
                    return;
                }

                this.renderResultCards();

                if (this.lastResults.length === 1) {
                    const hint = document.createElement('p');
                    hint.className = 'sr-create-results-msg is-info mt-2 mb-0';
                    hint.innerHTML =
                        '<i class="fas fa-check-circle"></i> One match — press <kbd>Enter</kbd> or tap to load return form.';
                    this.resultsDiv.appendChild(hint);
                    this.focusIndex = 0;
                    const card = this.resultsDiv.querySelector('.sr-create-result-card');
                    if (card) card.classList.add('is-focused');
                }
            } catch (err) {
                console.error(err);
                this.setResultsMessage('Search failed. Check connection and try again.', 'is-warn');
            }
        }

        renderResultCards() {
            let html = '';
            this.lastResults.forEach((inv, i) => {
                const shop = inv.shop_name || inv.customer_name || '—';
                const mobile = inv.mobile ? ` · ${inv.mobile}` : '';
                const date = inv.invoice_date ? new Date(inv.invoice_date).toLocaleDateString('en-GB') : '';
                html += `
                    <button type="button" class="sr-create-result-card" data-invoice-index="${i}" role="option">
                        <div class="sr-create-result-top">
                            <span class="sr-create-result-code">${escapeHtml(inv.invoice_code)}</span>
                            <span class="sr-create-result-amt">${formatMoney(inv.total_amount)}</span>
                        </div>
                        <div class="sr-create-result-meta">
                            <i class="fas fa-store"></i>${escapeHtml(shop)}${escapeHtml(mobile)}
                            ${date ? `<span class="ms-2"><i class="fas fa-calendar-alt"></i>${escapeHtml(date)}</span>` : ''}
                        </div>
                    </button>`;
            });
            this.resultsDiv.innerHTML = html;
        }

        async selectInvoice(basicInvoice) {
            this.clearResults();
            this.detailsDiv.innerHTML = '<p class="sr-create-results-msg is-loading"><i class="fas fa-spinner fa-spin"></i> Loading returnable lines…</p>';
            this.root.classList.add('is-form-active');
            this.formStep.classList.remove('d-none');

            try {
                const res = await fetch(
                    `${BASE_URL}SalesReturn/get_invoice_for_return`,
                    salesReturnPostOptions(salesReturnPostBody({ invoice_code: basicInvoice.invoice_code }))
                );
                const response = await res.json();

                if (response.status === 'success') {
                    this.currentInvoice = response.invoice;
                    this.renderInvoiceBar(response.invoice);
                    this.renderReturnForm(response.invoice);
                } else {
                    this.resetWorkspace();
                    Swal.fire('Error', response.message || 'Invoice not found', 'error');
                }
            } catch (e) {
                this.resetWorkspace();
                Swal.fire('Error', 'Failed to load invoice details', 'error');
                console.error(e);
            }
        }

        renderInvoiceBar(invoice) {
            const shop = invoice.shop_name || invoice.customer_name || '—';
            this.invoiceBar.innerHTML = `
                <div>
                    <span class="text-muted small">Invoice</span><br>
                    <strong>${escapeHtml(invoice.invoice_code)}</strong>
                </div>
                <div>
                    <span class="text-muted small">Customer</span><br>
                    <strong>${escapeHtml(shop)}</strong>
                </div>
                <button type="button" class="sr-create-change-invoice" data-action="change-invoice">
                    <i class="fas fa-search me-1"></i> Change invoice
                </button>`;
            this.invoiceBar.querySelector('[data-action="change-invoice"]').addEventListener('click', () => {
                this.resetWorkspace();
                this.searchInput.focus();
            });
        }

        renderReturnForm(invoice) {
            const returnableItems = (invoice.items || []).filter(
                (item) => parseFloat(item.returnable_qty || 0) > 0
            );

            if (returnableItems.length === 0) {
                this.detailsDiv.innerHTML = `
                    <p class="sr-create-results-msg is-warn">
                        Nothing left to return on this invoice — all quantities may already be returned.
                    </p>`;
                return;
            }

            let rows = '';
            returnableItems.forEach((item) => {
                const returnable = parseFloat(item.returnable_qty || 0);
                rows += `
                    <tr>
                        <td>${escapeHtml(item.product_name)}</td>
                        <td class="text-center">${formatQty(item.qty)}</td>
                        <td class="text-center text-success fw-bold">${formatQty(returnable)}</td>
                        <td>
                            <div class="input-group input-group-sm">
                                <input type="number" class="form-control return-qty text-center"
                                       name="items[${item.id}][return_qty]"
                                       max="${returnable}" step="0.01" min="0" value="0"
                                       data-rate="${parseFloat(item.rate || 0)}"
                                       data-returnable="${returnable}">
                                <button type="button" class="btn btn-outline-secondary btn-max-qty" title="Fill max returnable">Max</button>
                            </div>
                        </td>
                        <td class="text-end">${formatMoney(item.rate)}</td>
                        <td class="text-end return-amount">Tk 0.00</td>
                        <td>
                            <select name="items[${item.id}][condition]" class="form-select form-select-sm" title="Initial assessment — warehouse verifies on confirm">
                                <option value="Good">Good</option>
                                <option value="Damage">Damage</option>
                            </select>
                            <input type="hidden" name="items[${item.id}][sales_invoice_item_id]" value="${item.id}">
                            <input type="hidden" name="items[${item.id}][product_id]" value="${item.product_id}">
                            <input type="hidden" name="items[${item.id}][rate]" value="${item.rate || 0}">
                        </td>
                    </tr>`;
            });

            this.detailsDiv.innerHTML = `
                <div class="sr-create-form-card">
                    <div class="sr-create-form-card-head">
                        <span class="sr-create-step-badge">2</span>
                        <div>
                            <strong>Enter return quantities</strong>
                            <p class="mb-0 small text-muted">Max = returnable · Condition is verified again at warehouse confirm</p>
                        </div>
                    </div>
                    <div class="sr-create-pending-notice" role="note">
                        <i class="fas fa-info-circle"></i>
                        Saving creates a <strong>pending</strong> return — no stock or credit until warehouse confirms (Step 2).
                    </div>
                    <form id="${this.id}_returnForm" class="p-2 p-md-3">
                        <input type="hidden" name="csrf_token" value="${escapeHtml(getCsrfToken())}">
                        <input type="hidden" name="sales_invoice_id" value="${invoice.id}">
                        <input type="hidden" name="customer_id" value="${invoice.customer_id}">
                        <input type="hidden" name="return_date" value="${new Date().toISOString().split('T')[0]}">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0 sr-create-lines-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-center">Sold</th>
                                        <th class="text-center">Returnable</th>
                                        <th class="text-center">Return qty</th>
                                        <th class="text-end">Rate</th>
                                        <th class="text-end">Amount</th>
                                        <th>Condition <span class="text-muted fw-normal">(initial)</span></th>
                                    </tr>
                                </thead>
                                <tbody>${rows}</tbody>
                            </table>
                        </div>
                        <div class="sr-create-total-strip">
                            <div class="flex-grow-1">
                                <label class="form-label small mb-1">Reason for return</label>
                                <textarea name="reason" class="form-control" rows="2"
                                    placeholder="Optional — e.g. damaged goods, wrong item sent"></textarea>
                            </div>
                            <div class="text-end">
                                <p class="sr-create-total-label mb-0">Return total</p>
                                <div class="sr-create-total-value" id="${this.id}_totalReturn">Tk 0.00</div>
                                <input type="hidden" name="total_amount" id="${this.id}_total_amount" value="0">
                            </div>
                        </div>
                        <div class="sr-create-form-actions">
                            <button type="button" class="btn btn-outline-secondary" data-action="cancel-form">Cancel</button>
                            <button type="submit" class="btn btn-success px-4">
                                <i class="fas fa-save me-1"></i> Save return
                            </button>
                        </div>
                    </form>
                </div>`;

            const form = this.detailsDiv.querySelector('form');
            form.querySelectorAll('.return-qty').forEach((input) => {
                input.addEventListener('input', () => this.calculateRow(input));
                input.addEventListener('change', () => this.calculateRow(input));
            });
            form.querySelectorAll('.btn-max-qty').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const input = btn.closest('.input-group')?.querySelector('.return-qty');
                    if (!input) return;
                    const max = parseFloat(input.dataset.returnable) || parseFloat(input.max) || 0;
                    input.value = max > 0 ? String(max) : '0';
                    this.calculateRow(input);
                    input.focus();
                });
            });
            form.querySelector('[data-action="cancel-form"]').addEventListener('click', () => this.resetWorkspace());
            form.addEventListener('submit', (e) => this.submitReturn(e));
        }

        calculateRow(input) {
            const row = input.closest('tr');
            const qty = parseFloat(input.value) || 0;
            const rate = parseFloat(input.dataset.rate) || 0;
            const amountCell = row.querySelector('.return-amount');
            if (amountCell) {
                amountCell.textContent = formatMoney(qty * rate);
            }
            this.calculateTotal();
        }

        calculateTotal() {
            let total = 0;
            this.detailsDiv.querySelectorAll('.return-amount').forEach((cell) => {
                const n = parseFloat(String(cell.textContent).replace(/[^\d.-]/g, '')) || 0;
                total += n;
            });
            const display = this.root.querySelector(`#${this.id}_totalReturn`);
            const hidden = this.root.querySelector(`#${this.id}_total_amount`);
            if (display) display.textContent = formatMoney(total);
            if (hidden) hidden.value = total.toFixed(2);
        }

        resetWorkspace() {
            this.currentInvoice = null;
            this.root.classList.remove('is-form-active');
            this.formStep.classList.add('d-none');
            this.invoiceBar.innerHTML = '';
            this.detailsDiv.innerHTML = '';
            this.clearResults();
            this.searchInput.value = '';
            this.searchClear.classList.add('d-none');
        }

        async submitReturn(e) {
            e.preventDefault();
            const form = e.target;
            const items = [];
            let totalAmount = 0;

            form.querySelectorAll('tbody tr').forEach((row) => {
                const returnQtyInput = row.querySelector('.return-qty');
                if (!returnQtyInput) return;
                const returnQty = parseFloat(returnQtyInput.value) || 0;
                if (returnQty > 0) {
                    items.push({
                        sales_invoice_item_id: row.querySelector('input[name*="sales_invoice_item_id"]').value,
                        product_id: row.querySelector('input[name*="product_id"]').value,
                        return_qty: returnQty,
                        rate: parseFloat(row.querySelector('input[name*="rate"]').value) || 0,
                        amount: returnQty * (parseFloat(row.querySelector('input[name*="rate"]').value) || 0),
                        condition: row.querySelector('select[name*="condition"]').value,
                    });
                    totalAmount += returnQty * (parseFloat(row.querySelector('input[name*="rate"]').value) || 0);
                }
            });

            if (items.length === 0) {
                Swal.fire('Warning', 'Enter at least one return quantity', 'warning');
                return;
            }

            const formData = new FormData(form);
            formData.set('items', JSON.stringify(items));

            Swal.fire({
                title: 'Saving…',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading(),
            });

            try {
                if (!formData.has('csrf_token') && getCsrfToken()) {
                    formData.append('csrf_token', getCsrfToken());
                }

                const res = await fetch(`${BASE_URL}SalesReturn/store`, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData,
                });

                const text = await res.text();
                let result;
                try {
                    result = JSON.parse(text);
                } catch (err) {
                    result = { status: res.ok ? 'success' : 'error', message: text };
                }

                if (res.ok || result.status === 'success') {
                    const slipUrl =
                        result.slip_url ||
                        (result.return_id ? `${BASE_URL}SalesReturn/slip/${result.return_id}` : '');
                    const confirmUrl =
                        result.confirm_url ||
                        (result.return_id ? `${BASE_URL}SalesReturn/confirm/${result.return_id}` : '');

                    document.dispatchEvent(new CustomEvent('salesReturn:created', { detail: result }));

                    Swal.fire({
                        title: 'Return saved — Step 1 complete',
                        html:
                            '<p class="mb-2">' + escapeHtml(result.message || 'Pending warehouse confirmation.') + '</p>' +
                            '<p class="small text-muted mb-0">Warehouse staff should complete <strong>Step 2 — Confirm</strong> to post stock and credit note.</p>',
                        icon: 'success',
                        confirmButtonText: 'Print slip',
                        showDenyButton: !!confirmUrl,
                        denyButtonText: 'Go to warehouse confirm',
                        showCancelButton: true,
                        cancelButtonText: 'Back to list',
                    }).then((swalResult) => {
                        if (typeof this.onSaved === 'function') {
                            this.onSaved(swalResult, slipUrl, result, confirmUrl);
                        } else if (swalResult.isConfirmed && slipUrl) {
                            window.location.href = slipUrl;
                        } else if (swalResult.isDenied && confirmUrl) {
                            window.location.href = confirmUrl;
                        } else if (swalResult.dismiss === Swal.DismissReason.cancel) {
                            window.location.href = `${BASE_URL}SalesReturn`;
                        } else {
                            this.resetWorkspace();
                        }
                    });
                } else {
                    Swal.fire('Error', result.message || 'Failed to save return', 'error');
                }
            } catch (err) {
                console.error(err);
                Swal.fire('Error', 'Network error. Please try again.', 'error');
            }
        }

        prefill(term) {
            if (!term) return;
            this.searchInput.value = term;
            this.searchClear.classList.remove('d-none');
            this.runSearch(term);
        }
    }

    function resolveBaseUrl() {
        const baseInput = document.getElementById('base_url');
        BASE_URL = window.SALES_RETURN_BASE || (baseInput ? baseInput.value : '/remote-center-erp/public/');
        if (BASE_URL && !BASE_URL.endsWith('/')) {
            BASE_URL += '/';
        }
    }

    function initWorkspaces() {
        document.querySelectorAll('[data-sr-workspace]').forEach((root) => {
            const offcanvas = root.closest('.offcanvas');
            const ws = new SalesReturnWorkspace(root, {
                onSaved(swalResult, slipUrl, result, confirmUrl) {
                    if (offcanvas) {
                        const oc = bootstrap.Offcanvas.getInstance(offcanvas);
                        if (swalResult.isConfirmed && slipUrl) {
                            window.open(slipUrl, '_blank');
                        } else if (swalResult.isDenied && confirmUrl) {
                            window.location.href = confirmUrl;
                        }
                        ws.resetWorkspace();
                        if (oc) oc.hide();
                        return;
                    }
                    if (swalResult.isConfirmed && slipUrl) {
                        window.location.href = slipUrl;
                    } else if (swalResult.isDenied && confirmUrl) {
                        window.location.href = confirmUrl;
                    } else if (swalResult.dismiss === Swal.DismissReason.cancel) {
                        window.location.href = `${BASE_URL}SalesReturn`;
                    } else {
                        ws.resetWorkspace();
                        ws.searchInput.focus();
                    }
                },
            });
            root._srWorkspace = ws;
        });

        const boot = window.SALES_RETURN_CREATE_BOOT || {};
        const main = document.getElementById(boot.workspace_id || 'salesReturnCreateRoot');
        if (main && main._srWorkspace && boot.prefill) {
            main._srWorkspace.prefill(boot.prefill);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        resolveBaseUrl();
        initWorkspaces();

        const offcanvasEl = document.getElementById('salesReturnCreateOffcanvas');
        if (offcanvasEl) {
            offcanvasEl.addEventListener('shown.bs.offcanvas', function () {
                const root = document.getElementById('salesReturnOffcanvasRoot');
                if (root && root._srWorkspace) {
                    root._srWorkspace.resetWorkspace();
                    root._srWorkspace.searchInput.focus();
                }
            });

            const params = new URLSearchParams(window.location.search);
            if (params.get('receive') === '1' || params.get('new') === '1') {
                const btn = document.getElementById('openSalesReturnCreate');
                if (btn && typeof bootstrap !== 'undefined') {
                    bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl).show();
                }
            }
        }
    });

    window.SalesReturnWorkspace = SalesReturnWorkspace;
})();
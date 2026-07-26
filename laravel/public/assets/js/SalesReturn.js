/* ============================================================ *
 * SalesReturn.js — Phase 4.3
 * SalesReturnWorkspace: 2-step "Find Invoice → return form" workspace.
 *
 * Mirrors PurchaseReturnWorkspace (purchase-returns/create.blade.php)
 * adapted for Sales Return semantics:
 *   - Search: invoice typeahead (search-invoices endpoint).
 *   - Select: invoice-details endpoint (returns returnable items).
 *   - Per-line cap: returnable_qty ONLY (no warehouse-availability
 *     check — we are RECEIVING stock back, not consuming it).
 *   - Warehouse: read-only (each invoice item shipped from ONE
 *     warehouse; returning to a different one would break the
 *     original_cost snapshot lookup in SalesReturnService).
 *   - condition_state: Good/Damage pill toggle. Both still require
 *     a warehouse (Damage items get a linked damage write-off on
 *     confirm via SalesReturnService::createLinkedDamageWriteOffs).
 *   - Original cost: yellow-tinted display column (Laravel's
 *     BETTER-than-legacy original_cost snapshot from the challan).
 *
 * Auto-binds to any [data-srt-workspace] root on DOMContentLoaded.
 * Multiple workspaces per page are supported (full page + offcanvas).
 *
 * Boot contract (set by the blade page):
 *   window.CSRF_TOKEN
 *   window.SALES_RETURN_BASE           (e.g. '/admin/sales-returns/')
 *   window.SALES_RETURN_BOOT.endpoints { search_invoices, invoice_details,
 *                                        store, datatables, summary, export }
 *   window.SALES_RETURN_CREATE_BOOT    { workspace_id, prefill }
 * ============================================================ */
(function () {
    'use strict';

    let BASE_URL = '';
    let ENDPOINTS = {};

    function getCsrfToken() { return window.CSRF_TOKEN || ''; }

    function formatMoney(n) { return 'Tk ' + (parseFloat(n) || 0).toFixed(2); }
    function formatQty(n) {
        const v = parseFloat(n) || 0;
        return v.toFixed(2).replace(/\.?0+$/, '') || '0';
    }
    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function parseInvoiceSearchPayload(raw) {
        if (Array.isArray(raw)) return { rows: raw, error: null };
        if (!raw || typeof raw !== 'object') return { rows: [], error: null };
        if (raw.status === 'error') return { rows: [], error: raw.message || 'Search failed.' };
        if (Array.isArray(raw.data)) return { rows: raw.data, error: null };
        if (Array.isArray(raw.invoices)) return { rows: raw.invoices, error: null };
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
                if (term.length < 2) { this.clearResults(); return; }
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
                if (this.lastResults[idx]) this.selectInvoice(this.lastResults[idx]);
            });
        }

        onSearchKeydown(e) {
            const cards = this.resultsDiv.querySelectorAll('.srt-create-result-card');
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
            if (cards[this.focusIndex]) cards[this.focusIndex].scrollIntoView({ block: 'nearest' });
        }

        clearResults() {
            this.resultsDiv.innerHTML = '';
            this.lastResults = [];
            this.focusIndex = -1;
            this.searchInput.setAttribute('aria-expanded', 'false');
        }

        setResultsMessage(html, className) {
            this.resultsDiv.innerHTML = `<p class="srt-create-results-msg ${className || ''}">${html}</p>`;
        }

        async runSearch(term) {
            const seq = ++this.searchSeq;
            this.setResultsMessage('<i class="fas fa-spinner fa-spin"></i> Searching…', 'is-loading');
            this.searchInput.setAttribute('aria-expanded', 'true');

            try {
                const url = `${ENDPOINTS.search_invoices}?q=${encodeURIComponent(term)}`;
                const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
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
                        'No challan-issued invoices with returnable qty for your branch. Try invoice code or customer name.',
                        'is-info'
                    );
                    return;
                }

                this.renderResultCards();

                if (this.lastResults.length === 1) {
                    const hint = document.createElement('p');
                    hint.className = 'srt-create-results-msg is-info mt-2 mb-0';
                    hint.innerHTML = '<i class="fas fa-check-circle"></i> One match — press <kbd>Enter</kbd> or tap to load return form.';
                    this.resultsDiv.appendChild(hint);
                    this.focusIndex = 0;
                    const card = this.resultsDiv.querySelector('.srt-create-result-card');
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
                const returnableLabel = inv.returnable_total
                    ? `<span class="srt-create-result-amt">${formatMoney(inv.returnable_total)} returnable</span>`
                    : `<span class="srt-create-result-amt">${formatMoney(inv.total_amount)}</span>`;
                html += `
                    <button type="button" class="srt-create-result-card" data-invoice-index="${i}" role="option">
                        <div class="srt-create-result-top">
                            <span class="srt-create-result-code">${escapeHtml(inv.invoice_code)}</span>
                            ${returnableLabel}
                        </div>
                        <div class="srt-create-result-meta">
                            <i class="fas fa-user"></i>${escapeHtml(inv.customer_name || '—')}
                            ${inv.invoice_date ? ` · <i class="fas fa-calendar"></i>${escapeHtml(inv.invoice_date)}` : ''}
                        </div>
                    </button>`;
            });
            this.resultsDiv.innerHTML = html;
        }

        async selectInvoice(basicInvoice) {
            this.clearResults();
            this.detailsDiv.innerHTML = '<p class="srt-create-results-msg is-loading"><i class="fas fa-spinner fa-spin"></i> Loading returnable lines…</p>';
            this.root.classList.add('is-form-active');
            this.formStep.classList.remove('d-none');

            try {
                const url = `${ENDPOINTS.invoice_details}?invoice_id=${encodeURIComponent(basicInvoice.id)}`;
                const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                const response = await res.json();

                if (response.status === 'success' || response.invoice) {
                    this.currentInvoice = response.invoice;
                    this.renderInvoiceBar(response.invoice);
                    this.renderReturnForm(response.invoice, response.items || []);
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
            this.invoiceBar.innerHTML = `
                <div>
                    <span class="text-muted small">Invoice</span><br>
                    <strong>${escapeHtml(invoice.invoice_code)}</strong>
                </div>
                <div>
                    <span class="text-muted small">Customer</span><br>
                    <strong>${escapeHtml(invoice.customer_name || '—')}</strong>
                </div>
                <button type="button" class="srt-create-change-invoice" data-action="change-invoice">
                    <i class="fas fa-search me-1"></i> Change Invoice
                </button>`;
            this.invoiceBar.querySelector('[data-action="change-invoice"]').addEventListener('click', () => {
                this.resetWorkspace();
                this.searchInput.focus();
            });
        }

        renderReturnForm(invoice, items) {
            // Items are already filtered to returnable_qty > 0 by the server.
            const returnableItems = items.filter(
                (item) => parseFloat(item.returnable_qty || 0) > 0
            );

            if (returnableItems.length === 0) {
                // Build a breakdown table showing sold vs already-returned
                // so the user understands WHY there's nothing left.
                let breakdownRows = '';
                if (items.length > 0) {
                    breakdownRows = items.map((item) => {
                        const sold        = parseFloat(item.qty || 0);
                        const returned    = parseFloat(item.already_returned || 0);
                        const returnable  = parseFloat(item.returnable_qty || 0);
                        return `
                            <tr>
                                <td>${escapeHtml(item.product_code || item.product_name || ('#' + item.product_id))}</td>
                                <td class="text-end">${formatQty(sold)}</td>
                                <td class="text-end">${formatQty(returned)}</td>
                                <td class="text-end text-muted">${formatQty(returnable)}</td>
                            </tr>`;
                    }).join('');
                }

                this.detailsDiv.innerHTML = `
                    <div class="srt-create-results-msg is-warn">
                        <p class="mb-2">
                            <strong>Nothing left to return on invoice ${escapeHtml(invoice.invoice_code || '')}.</strong>
                            ${items.length === 0
                                ? 'This invoice has no returnable items.'
                                : 'Every line on this invoice has already been fully returned — returnable qty is 0 for all rows.'}
                        </p>
                        ${breakdownRows ? `
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Product</th>
                                    <th class="text-end">Sold</th>
                                    <th class="text-end">Already returned</th>
                                    <th class="text-end">Returnable</th>
                                </tr>
                            </thead>
                            <tbody>${breakdownRows}</tbody>
                        </table>` : ''}
                        <p class="mt-2 mb-0 small text-muted">
                            Tip: pick a different invoice from the search box above.
                        </p>
                    </div>`;
                return;
            }

            let rows = '';
            returnableItems.forEach((item) => {
                const returnable = parseFloat(item.returnable_qty || 0);
                const itemKey = item.id; // sales_invoice_item_id
                const origCost = parseFloat(item.original_cost || 0);
                const hasOrigCost = origCost > 0;
                const origCostCell = hasOrigCost
                    ? `<span class="orig-cost-value">${formatQty(origCost)}</span>`
                    : `<span class="orig-cost-na" title="Will be looked up from the challan on confirm">—</span>`;

                rows += `
                    <tr data-item-key="${itemKey}">
                        <td>
                            <span class="fw-semibold">${escapeHtml(item.product_name || ('Product #' + item.product_id))}</span>
                            <div class="small text-muted">${escapeHtml(item.product_code || '')}</div>
                        </td>
                        <td class="text-end">${formatQty(item.qty)}</td>
                        <td class="text-center returnable-cell" title="Max returnable on this invoice line">${formatQty(returnable)}</td>
                        <td>
                            <input type="number" class="form-control form-control-sm return-qty text-center"
                                   max="${returnable}" step="0.001" min="0" value="${returnable}"
                                   data-returnable="${returnable}"
                                   data-rate="${parseFloat(item.rate || 0)}"
                                   data-origcost="${origCost}">
                        </td>
                        <td class="text-end">${formatMoney(item.rate)}</td>
                        <td class="text-end col-original-cost">${origCostCell}</td>
                        <td class="text-end return-amount">Tk 0.00</td>
                        <td class="text-end cogs-amount">Tk 0.00</td>
                        <td>
                            <span class="small">${escapeHtml(item.warehouse_name || ('#' + item.warehouse_id))}</span>
                            <input type="hidden" class="warehouse-id" value="${item.warehouse_id}">
                        </td>
                        <td>
                            <span class="srt-create-condition" data-condition-toggle>
                                <input type="radio" class="condition-radio" name="cond_${itemKey}" id="cond_good_${itemKey}" value="Good" checked>
                                <label for="cond_good_${itemKey}" class="is-good">Good</label>
                                <input type="radio" class="condition-radio" name="cond_${itemKey}" id="cond_dmg_${itemKey}" value="Damage">
                                <label for="cond_dmg_${itemKey}" class="is-damage">Damage</label>
                            </span>
                            <input type="hidden" class="sii-id" value="${itemKey}">
                            <input type="hidden" class="product-id" value="${item.product_id}">
                            <input type="hidden" class="item-rate" value="${item.rate || 0}">
                            <input type="hidden" class="item-origcost" value="${origCost}">
                        </td>
                    </tr>`;
            });

            this.detailsDiv.innerHTML = `
                <div class="srt-create-form-card">
                    <div class="srt-create-form-card-head">
                        <i class="fas fa-list-ul me-1"></i> Return qty: capped at invoice returnable (we are receiving stock back — no warehouse-availability check)
                    </div>
                    <form id="${this.id}_returnForm" class="p-2 p-md-3">
                        <input type="hidden" name="sales_invoice_id" value="${invoice.id}">
                        <input type="hidden" name="customer_id" value="${invoice.customer_id || ''}">
                        <input type="hidden" name="return_date" value="${new Date().toISOString().split('T')[0]}">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0 srt-create-lines-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-end">Sold</th>
                                        <th class="text-center">Returnable</th>
                                        <th class="text-center">Return qty</th>
                                        <th class="text-end">Rate</th>
                                        <th class="text-end col-original-cost" title="ORIGINAL avg_cost from the challan">
                                            <i class="fas fa-circle-exclamation me-1"></i>Original Cost
                                        </th>
                                        <th class="text-end">Revenue</th>
                                        <th class="text-end">COGS</th>
                                        <th>Warehouse</th>
                                        <th>Condition</th>
                                    </tr>
                                </thead>
                                <tbody>${rows}</tbody>
                            </table>
                        </div>
                        <div class="srt-create-total-strip">
                            <div class="flex-grow-1">
                                <label class="form-label small mb-1">Reason for return</label>
                                <textarea name="reason" class="form-control" rows="2"
                                    placeholder="Optional — e.g. damaged goods, wrong item, customer cancelled"></textarea>
                            </div>
                            <div class="text-end">
                                <p class="srt-create-total-label mb-0">Return total (revenue)</p>
                                <div class="srt-create-total-value" id="${this.id}_totalReturn">Tk 0.00</div>
                                <p class="srt-create-total-label mb-0 mt-1">COGS reversal</p>
                                <div class="srt-create-cogs-value" id="${this.id}_totalCogs">Tk 0.00</div>
                            </div>
                        </div>
                        <p class="small text-muted mb-2">
                            <strong>Good:</strong> stock returned to the line's warehouse at original cost.
                            <strong>Damage:</strong> stock returned then immediately written off via a linked damage invoice (GL: Dr damage_loss / Cr inventory).
                        </p>
                        <div class="srt-create-form-actions">
                            <button type="button" class="btn btn-outline-secondary" data-action="cancel-form">Cancel</button>
                            <button type="submit" class="btn btn-save px-4">
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
            form.querySelector('[data-action="cancel-form"]').addEventListener('click', () => this.resetWorkspace());
            form.addEventListener('submit', (e) => this.submitReturn(e));
            // Initial totals.
            this.calculateTotal();
        }

        calculateRow(input) {
            const row = input.closest('tr');
            const qty = parseFloat(input.value) || 0;
            const rate = parseFloat(input.dataset.rate) || 0;
            const origCost = parseFloat(input.dataset.origcost) || 0;
            const amountCell = row.querySelector('.return-amount');
            const cogsCell = row.querySelector('.cogs-amount');
            if (amountCell) amountCell.textContent = formatMoney(qty * rate);
            if (cogsCell) cogsCell.textContent = formatMoney(qty * origCost);
            this.calculateTotal();
        }

        calculateTotal() {
            let totalRevenue = 0;
            let totalCogs = 0;
            this.detailsDiv.querySelectorAll('tbody tr').forEach((row) => {
                const qtyInput = row.querySelector('.return-qty');
                if (!qtyInput) return;
                const qty = parseFloat(qtyInput.value) || 0;
                const rate = parseFloat(qtyInput.dataset.rate) || 0;
                const origCost = parseFloat(qtyInput.dataset.origcost) || 0;
                totalRevenue += qty * rate;
                totalCogs += qty * origCost;
            });
            const revDisplay = this.root.querySelector(`#${this.id}_totalReturn`);
            const cogsDisplay = this.root.querySelector(`#${this.id}_totalCogs`);
            if (revDisplay) revDisplay.textContent = formatMoney(totalRevenue);
            if (cogsDisplay) cogsDisplay.textContent = formatMoney(totalCogs);
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

            form.querySelectorAll('tbody tr').forEach((row) => {
                const returnQtyInput = row.querySelector('.return-qty');
                if (!returnQtyInput) return;
                const returnQty = parseFloat(returnQtyInput.value) || 0;
                if (returnQty > 0) {
                    const conditionRadio = row.querySelector('.condition-radio:checked');
                    const rate = parseFloat(row.querySelector('.item-rate')?.value) || 0;
                    const origCost = parseFloat(row.querySelector('.item-origcost')?.value) || 0;
                    items.push({
                        sales_invoice_item_id: row.querySelector('.sii-id')?.value,
                        product_id: row.querySelector('.product-id')?.value,
                        warehouse_id: row.querySelector('.warehouse-id')?.value,
                        qty: returnQty,
                        rate,
                        original_cost: origCost, // display-only; service re-looks up from challan
                        condition_state: conditionRadio ? conditionRadio.value : 'Good',
                    });
                }
            });

            if (items.length === 0) {
                Swal.fire('Warning', 'Enter at least one return quantity', 'warning');
                return;
            }

            // Per-line returnable cap (sales return: only cap is returnable —
            // no warehouse-availability check because we are RECEIVING stock).
            for (const item of items) {
                const row = Array.from(form.querySelectorAll('tbody tr')).find((tr) =>
                    tr.querySelector('.sii-id')?.value === String(item.sales_invoice_item_id)
                );
                const returnable = parseFloat(row?.querySelector('.return-qty')?.dataset.returnable || 0);
                if (parseFloat(item.qty) > returnable + 0.0001) {
                    Swal.fire(
                        'Returnable limit',
                        `Cannot return ${formatQty(item.qty)} — only ${formatQty(returnable)} returnable on this invoice line.`,
                        'error'
                    );
                    return;
                }
                if (!item.warehouse_id) {
                    Swal.fire('Error', 'Each line must have a warehouse', 'error');
                    return;
                }
            }

            const formData = new FormData(form);
            // Append each item as items[index][key] using Laravel's standard
            // form-encoded array notation (same pattern as Purchase Return —
            // avoids the JSON-string-vs-array validation pitfall, BUG-50).
            items.forEach((item, idx) => {
                formData.append(`items[${idx}][sales_invoice_item_id]`, item.sales_invoice_item_id ?? '');
                formData.append(`items[${idx}][product_id]`,              item.product_id ?? '');
                formData.append(`items[${idx}][warehouse_id]`,            item.warehouse_id ?? '');
                formData.append(`items[${idx}][qty]`,                     item.qty ?? 0);
                formData.append(`items[${idx}][rate]`,                    item.rate ?? 0);
                formData.append(`items[${idx}][condition_state]`,         item.condition_state ?? 'Good');
            });

            Swal.fire({
                title: 'Saving…',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading(),
            });

            try {
                const res = await fetch(ENDPOINTS.store, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    body: formData,
                });

                const text = await res.text();
                let result;
                try {
                    result = JSON.parse(text);
                } catch (err) {
                    if (res.redirected && /sales-returns\//.test(res.url)) {
                        result = { status: 'success', redirect: res.url, message: 'Return created.' };
                    } else {
                        result = { status: res.ok ? 'success' : 'error', message: text.slice(0, 200) };
                    }
                }

                if (result.status === 'success' || res.redirected) {
                    document.dispatchEvent(new CustomEvent('salesReturn:created', { detail: result }));

                    Swal.fire({
                        title: 'Return saved',
                        text: result.message || 'Sales return created. Confirm to apply stock + GL.',
                        icon: 'success',
                        confirmButtonText: 'View return',
                        showCancelButton: true,
                        cancelButtonText: 'New return',
                    }).then((swalResult) => {
                        if (typeof this.onSaved === 'function') {
                            this.onSaved(swalResult, '', result);
                        } else if (swalResult.isConfirmed && result.redirect) {
                            window.location.href = result.redirect;
                        } else {
                            this.resetWorkspace();
                            this.searchInput.focus();
                        }
                    });
                } else {
                    // Validation errors (422) come back as {message, errors?}.
                    let msg = result.message || 'Failed to save return';
                    if (result.errors && typeof result.errors === 'object') {
                        const first = Object.values(result.errors)[0];
                        if (Array.isArray(first) && first.length) msg = first[0];
                    }
                    Swal.fire('Error', msg, 'error');
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
        BASE_URL = window.SALES_RETURN_BASE || '/admin/sales-returns/';
        if (BASE_URL && !BASE_URL.endsWith('/')) BASE_URL += '/';
        ENDPOINTS = (window.SALES_RETURN_BOOT && window.SALES_RETURN_BOOT.endpoints) || {};
        if (!ENDPOINTS.search_invoices) ENDPOINTS.search_invoices = BASE_URL + 'search-invoices';
        if (!ENDPOINTS.invoice_details) ENDPOINTS.invoice_details = BASE_URL + 'invoice-details';
        if (!ENDPOINTS.store) ENDPOINTS.store = BASE_URL;
        if (!ENDPOINTS.datatables) ENDPOINTS.datatables = BASE_URL;
        if (!ENDPOINTS.summary) ENDPOINTS.summary = BASE_URL + 'summary';
        if (!ENDPOINTS.export) ENDPOINTS.export = BASE_URL + 'export';
    }

    function initWorkspaces() {
        document.querySelectorAll('[data-srt-workspace]').forEach((root) => {
            const offcanvas = root.closest('.offcanvas');
            const ws = new SalesReturnWorkspace(root, {
                onSaved(swalResult, slipUrl, result) {
                    if (offcanvas) {
                        const oc = bootstrap.Offcanvas.getInstance(offcanvas);
                        ws.resetWorkspace();
                        if (oc) oc.hide();
                        if (swalResult.isConfirmed && result && result.redirect) {
                            window.location.href = result.redirect;
                        }
                        return;
                    }
                    if (swalResult.isConfirmed && result && result.redirect) {
                        window.location.href = result.redirect;
                    } else if (!swalResult.isConfirmed) {
                        ws.resetWorkspace();
                        ws.searchInput.focus();
                    } else {
                        ws.resetWorkspace();
                    }
                },
            });
            root._srtWorkspace = ws;
        });

        const boot = window.SALES_RETURN_CREATE_BOOT || {};
        const main = document.getElementById(boot.workspace_id || 'salesReturnCreateRoot');
        if (main && main._srtWorkspace && boot.prefill) {
            main._srtWorkspace.prefill(boot.prefill);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        resolveBaseUrl();
        initWorkspaces();
    });

    window.SalesReturnWorkspace = SalesReturnWorkspace;
})();

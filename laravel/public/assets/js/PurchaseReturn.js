/**
 * Purchase return — quick return workspace (create page + index offcanvas)
 */
(function () {
    'use strict';

    let BASE_URL = '';

    function getCsrfToken() {
        return window.CSRF_TOKEN || '';
    }

    function postBody(params) {
        const body = new URLSearchParams(params);
        const token = getCsrfToken();
        if (token) body.append('csrf_token', token);
        return body.toString();
    }

    function postOptions(bodyString) {
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
    function parseReceiveSearchPayload(raw) {
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
        if (Array.isArray(raw.receives)) {
            return { rows: raw.receives, error: null };
        }
        return { rows: [], error: null };
    }

    class PurchaseReturnWorkspace {
        constructor(rootEl, options) {
            this.root = rootEl;
            this.id = rootEl.id;
            this.onSaved = options.onSaved || null;
            this.searchInput = rootEl.querySelector(`#${this.id}_receiveSearch`);
            this.searchClear = rootEl.querySelector(`#${this.id}_searchClear`);
            this.resultsDiv = rootEl.querySelector(`#${this.id}_searchResults`);
            this.receiveBar = rootEl.querySelector(`#${this.id}_receiveBar`);
            this.detailsDiv = rootEl.querySelector(`#${this.id}_receiveDetails`);
            this.formStep = rootEl.querySelector('[data-step="form"]');
            this.currentReceive = null;
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
                const card = e.target.closest('[data-receive-index]');
                if (!card) return;
                e.preventDefault();
                const idx = parseInt(card.dataset.receiveIndex, 10);
                if (this.lastResults[idx]) {
                    this.selectReceive(this.lastResults[idx]);
                }
            });
        }

        onSearchKeydown(e) {
            const cards = this.resultsDiv.querySelectorAll('.prt-create-result-card');
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
                    this.selectReceive(this.lastResults[this.focusIndex]);
                } else if (this.lastResults.length === 1) {
                    this.selectReceive(this.lastResults[0]);
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
            this.resultsDiv.innerHTML = `<p class="prt-create-results-msg ${className || ''}">${html}</p>`;
        }

        async runSearch(term) {
            const seq = ++this.searchSeq;
            this.setResultsMessage('<i class="fas fa-spinner fa-spin"></i> Searching…', 'is-loading');
            this.searchInput.setAttribute('aria-expanded', 'true');

            try {
                const res = await fetch(
                    `${BASE_URL}PurchaseReturn/search_receive`,
                    postOptions(postBody({ term }))
                );
                const raw = await res.json();
                if (seq !== this.searchSeq) return;

                const parsed = parseReceiveSearchPayload(raw);
                if (parsed.error) {
                    this.setResultsMessage(escapeHtml(parsed.error), 'is-warn');
                    return;
                }

                this.lastResults = parsed.rows;
                this.focusIndex = -1;

                if (this.lastResults.length === 0) {
                    this.setResultsMessage(
                        'No received GRN with returnable qty for your branch. Try GRN code or supplier name.',
                        'is-info'
                    );
                    return;
                }

                this.renderResultCards();

                if (this.lastResults.length === 1) {
                    const hint = document.createElement('p');
                    hint.className = 'prt-create-results-msg is-info mt-2 mb-0';
                    hint.innerHTML =
                        '<i class="fas fa-check-circle"></i> One match — press <kbd>Enter</kbd> or tap to load return form.';
                    this.resultsDiv.appendChild(hint);
                    this.focusIndex = 0;
                    const card = this.resultsDiv.querySelector('.prt-create-result-card');
                    if (card) card.classList.add('is-focused');
                }
            } catch (err) {
                console.error(err);
                this.setResultsMessage('Search failed. Check connection and try again.', 'is-warn');
            }
        }

        renderResultCards() {
            let html = '';
            this.lastResults.forEach((rec, i) => {
                html += `
                    <button type="button" class="prt-create-result-card" data-receive-index="${i}" role="option">
                        <div class="prt-create-result-top">
                            <span class="prt-create-result-code">${escapeHtml(rec.receive_code)}</span>
                            <span class="prt-create-result-amt">${formatMoney(rec.total_amount)}</span>
                        </div>
                        <div class="prt-create-result-meta">
                            <i class="fas fa-truck"></i>${escapeHtml(rec.supplier_name || '—')}
                        </div>
                    </button>`;
            });
            this.resultsDiv.innerHTML = html;
        }

        async selectReceive(basicReceive) {
            this.clearResults();
            this.detailsDiv.innerHTML = '<p class="prt-create-results-msg is-loading"><i class="fas fa-spinner fa-spin"></i> Loading returnable lines…</p>';
            this.root.classList.add('is-form-active');
            this.formStep.classList.remove('d-none');

            try {
                const res = await fetch(
                    `${BASE_URL}PurchaseReturn/get_receive_for_return`,
                    postOptions(postBody({ receive_code: basicReceive.receive_code }))
                );
                const response = await res.json();

                if (response.status === 'success') {
                    this.currentReceive = response.receive;
                    this.renderReceiveBar(response.receive);
                    this.renderReturnForm(response.receive);
                } else {
                    this.resetWorkspace();
                    Swal.fire('Error', response.message || 'GRN not found', 'error');
                }
            } catch (e) {
                this.resetWorkspace();
                Swal.fire('Error', 'Failed to load GRN details', 'error');
                console.error(e);
            }
        }

        renderReceiveBar(receive) {
            this.receiveBar.innerHTML = `
                <div>
                    <span class="text-muted small">GRN</span><br>
                    <strong>${escapeHtml(receive.receive_code)}</strong>
                </div>
                <div>
                    <span class="text-muted small">Supplier</span><br>
                    <strong>${escapeHtml(receive.supplier_name || '—')}</strong>
                </div>
                <button type="button" class="prt-create-change-invoice" data-action="change-receive">
                    <i class="fas fa-search me-1"></i> Change GRN
                </button>`;
            this.receiveBar.querySelector('[data-action="change-receive"]').addEventListener('click', () => {
                this.resetWorkspace();
                this.searchInput.focus();
            });
        }

        renderReturnForm(receive) {
            const returnableItems = (receive.items || []).filter(
                (item) => parseFloat(item.returnable_qty || 0) > 0
            );

            if (returnableItems.length === 0) {
                this.detailsDiv.innerHTML = `
                    <p class="prt-create-results-msg is-warn">
                        Nothing left to return on this GRN — quantities may already be fully returned.
                    </p>`;
                return;
            }

            let rows = '';
            returnableItems.forEach((item) => {
                const returnable = parseFloat(item.returnable_qty || 0);
                const itemKey = item.purchase_receive_item_id || item.id;
                let whOptions = '<option value="">— Warehouse —</option>';
                if (item.warehouses && item.warehouses.length) {
                    item.warehouses.forEach((w) => {
                        const physical = parseFloat(w.physical_qty ?? w.qty ?? 0);
                        const avail = parseFloat(w.available_qty || 0);
                        whOptions += `<option value="${w.id}" data-available="${avail}" data-physical="${physical}">`
                            + `${escapeHtml(w.warehouse_name)} — ${formatQty(physical)} in stock, ${formatQty(avail)} avail</option>`;
                    });
                }

                rows += `
                    <tr data-item-key="${itemKey}">
                        <td>${escapeHtml(item.product_name)}</td>
                        <td class="text-center">${formatQty(item.received_qty)}</td>
                        <td class="text-center text-success fw-bold" title="Max back to supplier on this GRN (not warehouse on-hand)">${formatQty(returnable)}</td>
                        <td>
                            <input type="number" class="form-control form-control-sm return-qty text-center"
                                   max="${returnable}" step="0.01" min="0" value="0"
                                   data-returnable="${returnable}"
                                   data-rate="${parseFloat(item.rate || 0)}">
                        </td>
                        <td class="text-end">${formatMoney(item.rate)}</td>
                        <td class="text-end return-amount">Tk 0.00</td>
                        <td>
                            <select class="form-select form-select-sm warehouse-select" required>${whOptions}</select>
                        </td>
                        <td>
                            <select class="form-select form-select-sm condition-select">
                                <option value="Good">Good</option>
                                <option value="Damage">Damage</option>
                            </select>
                            <input type="hidden" class="pri-id" value="${itemKey}">
                            <input type="hidden" class="product-id" value="${item.product_id}">
                            <input type="hidden" class="item-rate" value="${item.rate || 0}">
                        </td>
                    </tr>`;
            });

            this.detailsDiv.innerHTML = `
                <div class="prt-create-form-card">
                    <div class="prt-create-form-card-head">
                        <i class="fas fa-list-ul me-1"></i> Return qty: min(GRN returnable, warehouse_stock available)
                    </div>
                    <form id="${this.id}_returnForm" class="p-2 p-md-3">
                        <input type="hidden" name="purchase_receive_id" value="${receive.id}">
                        <input type="hidden" name="supplier_id" value="${receive.supplier_id}">
                        <input type="hidden" name="return_date" value="${new Date().toISOString().split('T')[0]}">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered align-middle mb-0 prt-create-lines-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-center">Received</th>
                                        <th class="text-center">GRN returnable</th>
                                        <th class="text-center">Return qty</th>
                                        <th class="text-end">Rate</th>
                                        <th class="text-end">Amount</th>
                                        <th>Warehouse</th>
                                        <th>Condition</th>
                                    </tr>
                                </thead>
                                <tbody>${rows}</tbody>
                            </table>
                        </div>
                        <div class="prt-create-total-strip">
                            <div class="flex-grow-1">
                                <label class="form-label small mb-1">Reason for return</label>
                                <textarea name="reason" class="form-control" rows="2"
                                    placeholder="Optional — e.g. damaged goods, wrong item received"></textarea>
                            </div>
                            <div class="text-end">
                                <p class="prt-create-total-label mb-0">Return total</p>
                                <div class="prt-create-total-value" id="${this.id}_totalReturn">Tk 0.00</div>
                                <input type="hidden" name="total_amount" id="${this.id}_total_amount" value="0">
                            </div>
                        </div>
                        <p class="small text-muted mb-2">
                            <strong>Good:</strong> return qty ≤ <em>GRN returnable</em> (supplier limit) and ≤ <em>warehouse avail</em> (warehouse_stock).
                            <strong>Damage:</strong> no stock OUT; GRN returnable still applies.
                        </p>
                        <div class="prt-create-form-actions">
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
                input.addEventListener('blur', () => {
                    const row = input.closest('tr');
                    const wh = row?.querySelector('.warehouse-select');
                    if (wh?.value) this.validateStock(wh);
                });
            });
            form.querySelectorAll('.warehouse-select').forEach((sel) => {
                sel.addEventListener('change', () => {
                    this.applyRowQtyCap(sel.closest('tr'));
                    this.validateStock(sel);
                });
            });
            form.querySelector('[data-action="cancel-form"]').addEventListener('click', () => this.resetWorkspace());
            form.addEventListener('submit', (e) => this.submitReturn(e));
        }

        applyRowQtyCap(row) {
            if (!row) return;
            const returnQtyInput = row.querySelector('.return-qty');
            const select = row.querySelector('.warehouse-select');
            if (!returnQtyInput) return;

            const returnable = parseFloat(returnQtyInput.dataset.returnable || 0);
            let max = returnable;
            if (select?.value) {
                const avail = parseFloat(select.options[select.selectedIndex]?.dataset.available || 0);
                max = Math.min(returnable, avail);
            }
            returnQtyInput.max = String(max);
            const current = parseFloat(returnQtyInput.value) || 0;
            if (current > max) {
                returnQtyInput.value = max;
                this.calculateRow(returnQtyInput);
            }
        }

        validateStock(select) {
            const row = select.closest('tr');
            this.applyRowQtyCap(row);
            const available = parseFloat(select.options[select.selectedIndex]?.dataset.available || 0);
            const returnQtyInput = row?.querySelector('.return-qty');
            if (!returnQtyInput) return;
            const currentReturn = parseFloat(returnQtyInput.value) || 0;
            if (currentReturn > available + 0.0001) {
                Swal.fire(
                    'Stock limit',
                    `Only ${formatQty(available)} available in this warehouse (from warehouse_stock).`,
                    'warning'
                );
                returnQtyInput.value = Math.min(available, parseFloat(returnQtyInput.dataset.returnable || available));
                this.calculateRow(returnQtyInput);
            }
        }

        calculateRow(input) {
            const row = input.closest('tr');
            const qty = parseFloat(input.value) || 0;
            const rate = parseFloat(input.dataset.rate) || 0;
            const amountCell = row.querySelector('.return-amount');
            if (amountCell) amountCell.textContent = formatMoney(qty * rate);
            const wh = row.querySelector('.warehouse-select');
            if (wh && wh.value) this.validateStock(wh);
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
            this.currentReceive = null;
            this.root.classList.remove('is-form-active');
            this.formStep.classList.add('d-none');
            this.receiveBar.innerHTML = '';
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
                    const warehouseSelect = row.querySelector('.warehouse-select');
                    const conditionSelect = row.querySelector('.condition-select');
                    const rate = parseFloat(row.querySelector('.item-rate')?.value) || 0;
                    items.push({
                        purchase_receive_item_id: row.querySelector('.pri-id')?.value,
                        product_id: row.querySelector('.product-id')?.value,
                        warehouse_id: warehouseSelect ? warehouseSelect.value : '',
                        return_qty: returnQty,
                        rate,
                        condition: conditionSelect ? conditionSelect.value : 'Good',
                    });
                    totalAmount += returnQty * rate;
                }
            });

            if (items.length === 0) {
                Swal.fire('Warning', 'Enter at least one return quantity', 'warning');
                return;
            }

            for (const item of items) {
                if (!item.warehouse_id) {
                    Swal.fire('Error', 'Select a warehouse for each returned line', 'error');
                    return;
                }
                const row = form.querySelector(`tr[data-item-key="${item.purchase_receive_item_id}"]`)
                    || Array.from(form.querySelectorAll('tbody tr')).find((tr) =>
                        tr.querySelector('.pri-id')?.value === String(item.purchase_receive_item_id)
                    );
                const returnable = parseFloat(row?.querySelector('.return-qty')?.dataset.returnable || 0);
                if (parseFloat(item.return_qty) > returnable + 0.0001) {
                    Swal.fire(
                        'GRN limit',
                        `Cannot return ${formatQty(item.return_qty)} — only ${formatQty(returnable)} returnable to supplier on this GRN line.`,
                        'error'
                    );
                    return;
                }
                if (String(item.condition).toLowerCase() === 'good') {
                    const whSel = row?.querySelector('.warehouse-select');
                    const avail = parseFloat(whSel?.options[whSel.selectedIndex]?.dataset.available || 0);
                    if (parseFloat(item.return_qty) > avail + 0.0001) {
                        Swal.fire(
                            'Warehouse stock',
                            `Cannot return ${formatQty(item.return_qty)} — only ${formatQty(avail)} available (warehouse_stock).`,
                            'error'
                        );
                        return;
                    }
                }
            }

            const formData = new FormData(form);
            formData.set('items', JSON.stringify(items));
            formData.set('total_amount', totalAmount.toFixed(2));
            if (!formData.has('csrf_token') && getCsrfToken()) {
                formData.append('csrf_token', getCsrfToken());
            }

            Swal.fire({
                title: 'Saving…',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading(),
            });

            try {
                const res = await fetch(`${BASE_URL}PurchaseReturn/store`, {
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

                if (result.status === 'success') {
                    const slipUrl = result.return_id
                        ? `${BASE_URL}PurchaseReturn/slip/${result.return_id}`
                        : '';

                    document.dispatchEvent(new CustomEvent('purchaseReturn:created', { detail: result }));

                    Swal.fire({
                        title: 'Return saved',
                        text: result.message || 'Purchase return created.',
                        icon: 'success',
                        confirmButtonText: 'Print slip',
                        showCancelButton: !!slipUrl,
                        cancelButtonText: 'Done',
                    }).then((swalResult) => {
                        if (typeof this.onSaved === 'function') {
                            this.onSaved(swalResult, slipUrl, result);
                        } else if (swalResult.isConfirmed && slipUrl) {
                            window.location.href = slipUrl;
                        } else if (!swalResult.isConfirmed) {
                            window.location.href = `${BASE_URL}PurchaseReturn`;
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
        BASE_URL = window.PURCHASE_RETURN_BASE || (baseInput ? baseInput.value : '/remote-center-erp/public/');
        if (BASE_URL && !BASE_URL.endsWith('/')) BASE_URL += '/';
    }

    function initWorkspaces() {
        document.querySelectorAll('[data-prt-workspace]').forEach((root) => {
            const offcanvas = root.closest('.offcanvas');
            const ws = new PurchaseReturnWorkspace(root, {
                onSaved(swalResult, slipUrl) {
                    if (offcanvas) {
                        const oc = bootstrap.Offcanvas.getInstance(offcanvas);
                        if (swalResult.isConfirmed && slipUrl) {
                            window.open(slipUrl, '_blank');
                        }
                        ws.resetWorkspace();
                        if (oc) oc.hide();
                        return;
                    }
                    if (swalResult.isConfirmed && slipUrl) {
                        window.location.href = slipUrl;
                    } else if (!swalResult.isConfirmed) {
                        window.location.href = `${BASE_URL}PurchaseReturn`;
                    } else {
                        ws.resetWorkspace();
                        ws.searchInput.focus();
                    }
                },
            });
            root._prtWorkspace = ws;
        });

        const boot = window.PURCHASE_RETURN_CREATE_BOOT || {};
        const main = document.getElementById(boot.workspace_id || 'purchaseReturnCreateRoot');
        if (main && main._prtWorkspace && boot.prefill) {
            main._prtWorkspace.prefill(boot.prefill);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        resolveBaseUrl();
        initWorkspaces();

        const offcanvasEl = document.getElementById('purchaseReturnCreateOffcanvas');
        if (offcanvasEl) {
            offcanvasEl.addEventListener('shown.bs.offcanvas', function () {
                const root = document.getElementById('purchaseReturnOffcanvasRoot');
                if (root && root._prtWorkspace) {
                    root._prtWorkspace.resetWorkspace();
                    root._prtWorkspace.searchInput.focus();
                }
            });

            const params = new URLSearchParams(window.location.search);
            if (params.get('return') === '1' || params.get('new') === '1') {
                const btn = document.getElementById('openPurchaseReturnCreate');
                if (btn && typeof bootstrap !== 'undefined') {
                    bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl).show();
                }
            }
        }
    });

    window.PurchaseReturnWorkspace = PurchaseReturnWorkspace;
})();
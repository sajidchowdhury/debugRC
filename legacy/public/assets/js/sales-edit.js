/**
 * Premium Sales Edit POS — single draft invoice, locked customer, same UX as create.
 */
(function () {
    'use strict';

    let editCartLoaded = false;
    let activePriceRange = null;

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById('sales-edit-app')) return;
        if (!document.getElementById('invoice_id')) return;
        initSalesEditPage();
    });

    async function initSalesEditPage() {
        const baseInput = document.getElementById('base_url');
        if (typeof BASE_URL === 'undefined' && baseInput) {
            window.BASE_URL = baseInput.value;
            if (BASE_URL && !BASE_URL.endsWith('/')) BASE_URL += '/';
        }

        window.isEditMode = true;
        window.currentInvoiceId = document.getElementById('invoice_id')?.value;

        await Promise.all([
            loadBranchForEdit(),
            loadEmployeesForEdit()
        ]);

        applyBootMetaFields();
        const customerId = document.getElementById('customer_id')?.value;
        if (customerId && typeof onCustomerSelected === 'function') {
            onCustomerSelected(customerId);
        }

        initProductSearchEdit();
        initAddToCartEdit();
        initPriceRangePanelEdit();
        initPosStickyBar();
        patchLoadCartForEdit();

        document.getElementById('branch_id')?.addEventListener('change', () => {
            if (window.selectedProduct) showStockInfoEdit(window.selectedProduct);
        });

        await loadInvoiceIntoCart();
    }

    function initPriceRangePanelEdit() {
        const rateInput = document.getElementById('sales_rate');
        rateInput?.addEventListener('input', () => updatePriceBandUiEdit());
        rateInput?.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('addToCartBtn')?.click();
            }
        });

        document.getElementById('btnUseDefaultRate')?.addEventListener('click', () => {
            if (!activePriceRange) return;
            const def = parseFloat(activePriceRange.default_rate) || 0;
            if (def > 0) {
                document.getElementById('sales_rate').value = def;
                updatePriceBandUiEdit();
                document.getElementById('quantity')?.focus();
            }
        });
    }

    function setActivePriceRangeEdit(product) {
        if (!product) {
            activePriceRange = null;
            return;
        }
        const min = parseFloat(product.min_rate) || 0;
        const max = parseFloat(product.max_rate) || 0;
        const def = parseFloat(product.default_rate ?? product.price) || 0;
        if (min <= 0 || max <= 0) {
            activePriceRange = null;
            return;
        }
        activePriceRange = { min_rate: min, max_rate: max, default_rate: def };
    }

    function updatePriceBandUiEdit() {
        const panel = document.getElementById('priceRangePanel');
        const rateInput = document.getElementById('sales_rate');
        const statusEl = document.getElementById('priceRangeStatus');
        if (!panel || !rateInput) return;

        if (!activePriceRange) {
            panel.classList.add('d-none');
            rateInput.removeAttribute('min');
            rateInput.removeAttribute('max');
            rateInput.classList.remove('sales-rate-out', 'sales-rate-in');
            return;
        }

        const { min_rate: min, max_rate: max, default_rate: def } = activePriceRange;
        const rate = parseFloat(rateInput.value) || 0;
        const span = max - min;

        panel.classList.remove('d-none');
        document.getElementById('priceBandMin').textContent = fmtMoneyEdit(min);
        document.getElementById('priceBandMax').textContent = fmtMoneyEdit(max);
        document.getElementById('priceBandDefault').textContent = fmtMoneyEdit(def);

        rateInput.min = min;
        rateInput.max = max;

        const pct = span > 0 ? Math.min(100, Math.max(0, ((rate - min) / span) * 100)) : 0;
        const defPct = span > 0 ? Math.min(100, Math.max(0, ((def - min) / span) * 100)) : 50;

        document.getElementById('priceBandFill').style.width = pct + '%';
        document.getElementById('priceBandThumb').style.left = pct + '%';
        document.getElementById('priceBandDefaultMark').style.left = defPct + '%';

        const st = typeof salesRateRangeStatus === 'function'
            ? salesRateRangeStatus(rate, min, max)
            : (rate >= min && rate <= max ? 'ok' : 'bad');

        rateInput.classList.toggle('sales-rate-out', st === 'bad');
        rateInput.classList.toggle('sales-rate-in', st === 'ok' || st === 'warn');

        if (statusEl) {
            statusEl.classList.remove('sales-price-ok', 'sales-price-warn', 'sales-price-bad');
            if (st === 'bad') {
                statusEl.classList.add('sales-price-bad');
                statusEl.textContent = `Out of range — must be ৳${fmtMoneyEdit(min)} – ৳${fmtMoneyEdit(max)}`;
            } else if (st === 'warn') {
                statusEl.classList.add('sales-price-warn');
                statusEl.textContent = 'Near minimum — check margin';
            } else {
                statusEl.classList.add('sales-price-ok');
                statusEl.textContent = 'Rate is within allowed range';
            }
        }

        const thumb = document.getElementById('priceBandThumb');
        if (thumb) {
            thumb.style.borderColor = st === 'bad' ? '#dc2626' : (st === 'warn' ? '#b45309' : '#4f46e5');
        }
    }

    function fmtMoneyEdit(n) {
        return typeof salesFormatMoney === 'function'
            ? salesFormatMoney(n)
            : (parseFloat(n) || 0).toFixed(2);
    }

    function validateActiveRateEdit() {
        if (!activePriceRange) {
            return { valid: false, message: 'No price range configured for this product.' };
        }
        const rate = parseFloat(document.getElementById('sales_rate')?.value) || 0;
        if (typeof salesValidateRateClient === 'function') {
            return salesValidateRateClient(rate, activePriceRange.min_rate, activePriceRange.max_rate);
        }
        if (rate < activePriceRange.min_rate || rate > activePriceRange.max_rate) {
            return {
                valid: false,
                message: `Rate must be between ৳${fmtMoneyEdit(activePriceRange.min_rate)} and ৳${fmtMoneyEdit(activePriceRange.max_rate)}.`
            };
        }
        return { valid: true };
    }

    function applyBootMetaFields() {
        const boot = window.SALES_EDIT_BOOT || {};
        const branchSel = document.getElementById('branch_id');
        if (branchSel && boot.branch_id) {
            branchSel.value = String(boot.branch_id);
        }
        if (boot.salesman_id) {
            const sb = document.getElementById('sales_by');
            if (sb) sb.value = String(boot.salesman_id);
        }
        if (boot.sales_person) {
            const sp = document.getElementById('sales_person');
            if (sp) sp.value = String(boot.sales_person);
        }
        if (boot.invoice_date) {
            const dt = document.getElementById('invoice_date');
            if (dt) dt.value = boot.invoice_date;
        }
        if (boot.narration !== undefined) {
            const nar = document.getElementById('narration');
            if (nar) nar.value = boot.narration;
        }
    }

    async function loadBranchForEdit() {
        const showAll = window.CAN_OVERRIDE_BRANCH === true;
        let url = BASE_URL + 'sales/get_branch';
        if (showAll) url += '?all=1';

        const res = await fetch(url);
        const data = parseSalesListResponse(await res.json());
        const sel = document.getElementById('branch_id');
        if (!sel) return;

        const lockedVal = document.getElementById('branch_id_locked')?.value
            || String(window.SALES_EDIT_INVOICE_BRANCH || '');
        const currentValue = lockedVal || sel.value;

        sel.innerHTML = "<option value=''>Select Branch</option>";
        data.forEach(b => {
            sel.innerHTML += `<option value="${b.id}">${escapeHtml(b.branch_name)}</option>`;
        });

        if (currentValue) sel.value = currentValue;
        else if (!showAll && window.SESSION_BRANCH_ID) {
            sel.value = String(window.SESSION_BRANCH_ID);
        }
    }

    async function loadEmployeesForEdit() {
        const res = await fetch(BASE_URL + 'sales/get_employees');
        const data = parseSalesListResponse(await res.json());
        const boot = window.SALES_EDIT_BOOT || {};

        ['sales_by', 'sales_person'].forEach(id => {
            const sel = document.getElementById(id);
            if (!sel) return;
            const currentValue = id === 'sales_by'
                ? String(boot.salesman_id || sel.value)
                : String(boot.sales_person || sel.value);

            sel.innerHTML = "<option value=''>Select One</option>";
            data.forEach(e => {
                sel.innerHTML += `<option value="${e.id}">${escapeHtml(e.name)}</option>`;
            });
            if (currentValue) sel.value = currentValue;
        });
    }

    async function loadInvoiceIntoCart() {
        if (editCartLoaded) return;
        editCartLoaded = true;

        const customerId = document.getElementById('customer_id')?.value;
        const invoiceId = document.getElementById('invoice_id')?.value;
        if (!customerId || !invoiceId) return;

        const cartArea = document.getElementById('single-cart-area');
        if (cartArea) {
            cartArea.innerHTML = `
                <div class="text-center text-muted py-5">
                    <i class="fas fa-spinner fa-spin fa-2x mb-2 opacity-50"></i>
                    <p class="mb-0">Loading invoice lines…</p>
                </div>`;
        }

        try {
            await clearCustomerCart(customerId);
            await new Promise(r => setTimeout(r, 350));

            const res = await fetch(BASE_URL + `sales/get_invoice_for_edit/${invoiceId}`);
            const data = await res.json();

            if (data.status !== 'success') {
                Swal.fire('Cannot edit', data.message || 'Invoice is locked', 'error')
                    .then(() => { location.href = BASE_URL + 'sales/today'; });
                return;
            }

            if (data.transport_cost !== undefined || data.discount !== undefined) {
                window.SALES_EDIT_AMOUNTS = {
                    transport: parseFloat(data.transport_cost) || 0,
                    discount: parseFloat(data.discount) || 0
                };
            }

            applyBootMetaFields();
            if (data.branch_id) document.getElementById('branch_id').value = String(data.branch_id);
            if (data.salesman_id) document.getElementById('sales_by').value = String(data.salesman_id);
            if (data.sales_person) document.getElementById('sales_person').value = String(data.sales_person);
            if (data.invoice_date) document.getElementById('invoice_date').value = data.invoice_date.split(' ')[0];
            if (data.narration !== undefined) document.getElementById('narration').value = data.narration;

            const items = data.items || [];
            if (!items.length) {
                if (cartArea) {
                    cartArea.innerHTML = '<div class="text-center text-muted py-4">No line items — add products below</div>';
                }
                updatePosStickyBar(0, 0);
                return;
            }

            const hydrateFd = new FormData();
            hydrateFd.append('invoice_id', invoiceId);
            hydrateFd.append('customer_id', customerId);
            const hydrateRes = await fetch(
                BASE_URL + 'sales/hydrate_edit_cart',
                salesPostOptions(appendCsrf(hydrateFd))
            );
            const hydrateResult = await hydrateRes.json();

            if (hydrateResult.status !== 'success') {
                Swal.fire('Cannot load lines', hydrateResult.message || 'Failed to load cart', 'error');
                return;
            }

            await loadCart(customerId);
            applyEditAmounts();
        } catch (e) {
            console.error('Edit load failed', e);
            Swal.fire('Error', 'Failed to load invoice for editing', 'error');
        }
    }

    async function clearCustomerCart(customer_id) {
        const fd = new FormData();
        fd.append('customer_id', customer_id);
        await fetch(BASE_URL + 'sales/clear_tab_cart', salesPostOptions(appendCsrf(fd)));
    }

    function applyEditAmounts() {
        const amounts = window.SALES_EDIT_AMOUNTS || {};
        const container = document.getElementById('single-cart-area');
        if (!container) return;

        const transportEl = container.querySelector('.transport_cost');
        const discountEl = container.querySelector('.discount');
        if (transportEl) transportEl.value = amounts.transport ?? 0;
        if (discountEl) discountEl.value = amounts.discount ?? 0;

        transportEl?.dispatchEvent(new Event('input'));
    }

    function patchLoadCartForEdit() {
        const orig = window.loadCart;
        if (typeof orig !== 'function') return;

        window.loadCart = async function (customer_id) {
            await orig(customer_id);
            customizeEditCartUi();
            applyEditAmounts();
        };
    }

    function customizeEditCartUi() {
        const container = document.getElementById('single-cart-area');
        if (!container) return;

        const submitBtn = container.querySelector('.finalSubmitBtn');
        if (submitBtn) {
            submitBtn.textContent = 'Update invoice';
            submitBtn.classList.remove('btn-success');
            submitBtn.classList.add('btn-primary');
        }

        const label = document.getElementById('posStickyActionLabel');
        if (label) label.textContent = 'Update';
    }

    function initAddToCartEdit() {
        const btn = document.getElementById('addToCartBtn');
        if (!btn) return;

        btn.addEventListener('click', async () => {
            const customer_id = document.getElementById('customer_id')?.value;
            if (!customer_id) {
                Swal.fire('Error', 'Customer missing on this invoice.', 'error');
                return;
            }
            if (!window.selectedProduct) {
                Swal.fire({ icon: 'warning', title: 'Select product', text: 'Search and pick a product.' });
                document.getElementById('productSearch')?.focus();
                return;
            }

            const qty = parseFloat(document.getElementById('quantity').value) || 0;
            const rate = parseFloat(document.getElementById('sales_rate').value) || 0;
            if (qty <= 0 || rate <= 0) {
                Swal.fire('Invalid', 'Qty and rate must be greater than zero.', 'warning');
                return;
            }

            const rateCheck = validateActiveRateEdit();
            if (!rateCheck.valid) {
                Swal.fire('Price out of range', rateCheck.message, 'warning');
                document.getElementById('sales_rate')?.focus();
                updatePriceBandUiEdit();
                return;
            }

            const p = window.selectedProduct;
            const formData = appendSalesCartContext(new FormData());
            formData.append('customer_id', customer_id);
            formData.append('product_id', p.id);
            formData.append('product_name', p.product_name);
            formData.append('qty', qty);
            formData.append('rate', rate);

            const res = await fetch(BASE_URL + 'sales/add_to_cart', salesPostOptions(appendCsrf(formData)));
            const result = await res.json();

            if (result.status === 'success') {
                toastAddedEdit();
                resetProductEntryEdit();
                await loadCart(customer_id);
                applyEditAmounts();
            } else {
                if (typeof showCartValidationError === 'function') {
                    showCartValidationError(result);
                } else {
                    Swal.fire('Error', result.message || 'Could not add', 'error');
                }
            }
        });

        document.getElementById('quantity')?.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('sales_rate')?.focus();
                document.getElementById('sales_rate')?.select();
            }
        });
    }

    function resetProductEntryEdit() {
        document.getElementById('productSearch').value = '';
        document.getElementById('quantity').value = 1;
        document.getElementById('sales_rate').value = '';
        window.selectedProduct = null;
        activePriceRange = null;
        document.getElementById('priceRangePanel')?.classList.add('d-none');
        document.getElementById('BranchStock')?.classList.add('d-none');
        document.getElementById('productSearch')?.focus();
    }

    function toastAddedEdit() {
        const toast = document.createElement('div');
        toast.className = 'sales-toast';
        toast.innerHTML = '<i class="fas fa-check-circle"></i> Added to invoice';
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 1400);
    }

    function initProductSearchEdit() {
        const productSearch = document.getElementById('productSearch');
        const suggestionsBox = document.getElementById('productSuggestions');
        if (!productSearch) return;

        productSearch.addEventListener('input', debounce(async function () {
            const term = this.value.trim();
            if (term.length < 2) {
                suggestionsBox?.classList.remove('show');
                return;
            }
            const branchId = resolveEditBranchId();
            const res = await fetch(BASE_URL + `sales/search_product?term=${encodeURIComponent(term)}&branch_id=${branchId}`);
            const data = parseSalesListResponse(await res.json());

            let html = '';
            data.forEach(p => {
                const stock = parseFloat(p.available_qty) || 0;
                const out = stock <= 0;
                const min = parseFloat(p.min_rate) || 0;
                const max = parseFloat(p.max_rate) || 0;
                const priceLabel = min > 0 && max > 0
                    ? `<span class="sales-suggest-price">${typeof salesFormatPriceRange === 'function' ? salesFormatPriceRange(min, max, p.default_rate) : ''}</span>`
                    : `<span class="sales-suggest-price text-warning">No range</span>`;
                html += `<button type="button" class="sales-suggest-item ${out ? 'disabled' : ''}" data-product='${JSON.stringify(p).replace(/'/g, '&#39;')}' ${out ? 'disabled' : ''}>
                    <span class="suggest-title">${escapeHtml(p.product_name)} <small class="text-muted">${escapeHtml(p.product_code || '')}</small></span>
                    <span class="d-flex align-items-center gap-1">
                        ${priceLabel}
                        <span class="badge ${stock > 0 ? 'bg-success' : 'bg-danger'}">${stock} avail</span>
                    </span>
                </button>`;
            });
            suggestionsBox.innerHTML = html || '<div class="sales-suggest-empty">No product found</div>';
            suggestionsBox.classList.add('show');
        }, 200));

        suggestionsBox?.addEventListener('click', e => {
            const btn = e.target.closest('.sales-suggest-item:not(.disabled)');
            if (!btn || !btn.dataset.product) return;
            selectProductEdit(btn);
        });

        productSearch.addEventListener('keydown', async e => {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                if (!suggestionsBox?.classList.contains('show')) return;
                const items = suggestionsBox.querySelectorAll('.sales-suggest-item:not(.disabled)');
                if (!items.length) return;

                let active = suggestionsBox.querySelector('.sales-suggest-item.active') || items[0];

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    const next = active.nextElementSibling && active.nextElementSibling.classList.contains('sales-suggest-item')
                        ? active.nextElementSibling
                        : items[0];
                    active.classList.remove('active');
                    next.classList.add('active');
                    next.scrollIntoView({ block: 'nearest' });
                }
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    const prev = active.previousElementSibling && active.previousElementSibling.classList.contains('sales-suggest-item')
                        ? active.previousElementSibling
                        : items[items.length - 1];
                    active.classList.remove('active');
                    prev.classList.add('active');
                    prev.scrollIntoView({ block: 'nearest' });
                }
                return;
            }

            if (e.key !== 'Enter') return;

            e.preventDefault();
            const term = productSearch.value.trim();
            if (!term) return;

            const branchId = resolveEditBranchId();

            if (typeof fetchSalesProductByExactCode === 'function') {
                const exact = await fetchSalesProductByExactCode(term, branchId);
                if (exact) {
                    selectProductEdit(exact);
                    return;
                }
            }

            if (suggestionsBox?.classList.contains('show')) {
                const items = suggestionsBox.querySelectorAll('.sales-suggest-item:not(.disabled)');
                if (items.length) {
                    const pick = suggestionsBox.querySelector('.sales-suggest-item.active:not(.disabled)') || items[0];
                    if (pick) selectProductEdit(pick);
                    return;
                }
            }

            Swal.fire('Not found', 'No product with this code.', 'warning');
        });

        document.addEventListener('click', e => {
            if (!e.target.closest('#productSearch') && !e.target.closest('#productSuggestions')) {
                suggestionsBox?.classList.remove('show');
            }
        });
    }

    function resolveEditBranchId() {
        const locked = document.getElementById('branch_id_locked')?.value;
        if (locked) return locked;
        return document.getElementById('branch_id')?.value
            || window.SALES_EDIT_INVOICE_BRANCH
            || window.SESSION_BRANCH_ID
            || '';
    }

    function selectProductEdit(btnOrProduct) {
        const p = btnOrProduct && btnOrProduct.product_name != null && btnOrProduct.id != null
            ? btnOrProduct
            : JSON.parse(btnOrProduct.dataset.product.replace(/&#39;/g, "'"));
        const stock = parseFloat(p.available_qty) || 0;
        if (stock <= 0) {
            Swal.fire('Out of stock', 'No available stock at this branch.', 'warning');
            return;
        }

        const min = parseFloat(p.min_rate) || 0;
        const max = parseFloat(p.max_rate) || 0;
        if (min <= 0 || max <= 0) {
            Swal.fire('No price range', 'This product has no selling range set. Ask admin to configure prices.', 'warning');
            return;
        }

        window.selectedProduct = p;
        setActivePriceRangeEdit(p);
        document.getElementById('productSearch').value = p.product_name;
        const defRate = parseFloat(p.default_rate ?? p.price) || min;
        document.getElementById('sales_rate').value = defRate;
        document.getElementById('quantity').value = 1;
        document.getElementById('productSuggestions')?.classList.remove('show');
        updatePriceBandUiEdit();
        showStockInfoEdit(p);
        document.getElementById('quantity')?.focus();
        document.getElementById('quantity')?.select();
    }

    async function showStockInfoEdit(product) {
        const container = document.getElementById('BranchStock');
        if (!container || !product) return;

        const branchId = resolveEditBranchId();
        let branchName = window.ACTIVE_BRANCH_NAME || 'Branch';
        let stock = parseFloat(product.available_qty) || 0;

        try {
            const res = await fetch(
                `${BASE_URL}sales/product_stock_at_branch?product_id=${product.id}&branch_id=${branchId}`
            );
            const data = await res.json();
            if (data && data.available_qty !== undefined) {
                stock = parseFloat(data.available_qty) || 0;
                branchName = data.branch_name || branchName;
            }
        } catch (e) {
            console.warn('Stock summary fetch failed', e);
        }

        container.classList.remove('d-none');
        container.innerHTML = `
            <div class="stock-banner-inner">
                <div class="stock-stat">
                    <span class="stock-label">Available <span class="stock-branch-tag">${escapeHtml(branchName)}</span></span>
                    <span class="stock-value ${stock > 0 ? 'text-white' : 'text-danger'}">${stock}</span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-light" onclick="showWarehouseModal(${product.id})">
                    <i class="fas fa-warehouse"></i> Warehouse & pipeline
                </button>
            </div>`;
    }

    window.showWarehouseModal = async function (productId) {
        document.getElementById('stockModal')?.remove();

        const html = `
        <div class="modal fade" id="stockModal" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content sales-stock-modal">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-warehouse me-2"></i>Stock by warehouse</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label small">Branch</label>
                            <select id="modalBranchSelect" class="form-select"></select>
                        </div>
                        <p class="small text-muted mb-2">
                            <strong>Physical</strong> = on hand ·
                            <strong>Pipeline</strong> = reserved on open invoices ·
                            <strong>Available</strong> = can sell now
                        </p>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Warehouse</th>
                                        <th class="text-end">Physical</th>
                                        <th class="text-end">Pipeline</th>
                                        <th class="text-end">Available</th>
                                    </tr>
                                </thead>
                                <tbody id="modalStockBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;
        document.body.insertAdjacentHTML('beforeend', html);
        const modal = new bootstrap.Modal(document.getElementById('stockModal'));
        modal.show();

        const branchSelect = document.getElementById('modalBranchSelect');
        const res = await fetch(BASE_URL + 'sales/get_branch?for_stock=1');
        const branches = parseSalesListResponse(await res.json());
        const defaultBranch = String(resolveEditBranchId() || window.SESSION_BRANCH_ID || '');

        branchSelect.innerHTML = branches.map(b => {
            const isHome = String(b.id) === String(window.SESSION_BRANCH_ID || '');
            const label = isHome ? `${b.branch_name} (your branch)` : b.branch_name;
            return `<option value="${b.id}">${escapeHtml(label)}</option>`;
        }).join('');

        branchSelect.value = defaultBranch || String(branches[0]?.id || '');
        branchSelect.onchange = () => loadModalStockPipeline(productId, branchSelect.value);
        loadModalStockPipeline(productId, branchSelect.value);
    };

    async function loadModalStockPipeline(productId, branchId) {
        const tbody = document.getElementById('modalStockBody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="4" class="text-center py-3"><i class="fas fa-spinner fa-spin"></i></td></tr>';

        try {
            const res = await fetch(`${BASE_URL}sales/get_warehouse_stock?product_id=${productId}&branch_id=${branchId}`);
            const data = parseSalesListResponse(await res.json());
            let rows = '';
            let tPhys = 0, tPipe = 0, tAvail = 0;

            if (data.length) {
                data.forEach(w => {
                    const phys = parseFloat(w.physical_qty) || 0;
                    const pipe = parseFloat(w.pipeline_qty) || 0;
                    const avail = parseFloat(w.available_qty) || 0;
                    tPhys += phys;
                    tPipe += pipe;
                    tAvail += avail;
                    rows += `<tr>
                        <td>${escapeHtml(w.warehouse_name)}</td>
                        <td class="text-end">${phys.toFixed(2)}</td>
                        <td class="text-end text-warning">${pipe.toFixed(2)}</td>
                        <td class="text-end fw-bold ${avail > 0 ? 'text-success' : 'text-danger'}">${avail.toFixed(2)}</td>
                    </tr>`;
                });
                rows += `<tr class="table-secondary fw-bold">
                    <td>Total</td>
                    <td class="text-end">${tPhys.toFixed(2)}</td>
                    <td class="text-end">${tPipe.toFixed(2)}</td>
                    <td class="text-end">${tAvail.toFixed(2)}</td>
                </tr>`;
            } else {
                rows = '<tr><td colspan="4" class="text-center text-muted">No warehouses</td></tr>';
            }
            tbody.innerHTML = rows;
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-danger text-center">Failed to load</td></tr>';
        }
    }

    function debounce(fn, ms) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), ms);
        };
    }
})();
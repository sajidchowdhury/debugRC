/**
 * Premium Sales Create POS — customer search, multi-cart tabs, warehouse pipeline stock.
 */
(function () {
    'use strict';

    let activeCustomerId = null;
    let customerCache = {};
    let activePriceRange = null;

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById('sales-create-app')) return;
        initSalesCreatePage();
    });

    async function initSalesCreatePage() {
        const baseInput = document.getElementById('base_url');
        if (typeof BASE_URL === 'undefined' && baseInput) {
            window.BASE_URL = baseInput.value;
            if (BASE_URL && !BASE_URL.endsWith('/')) BASE_URL += '/';
        }

        await Promise.all([
            loadBranch(window.CAN_OVERRIDE_BRANCH === true),
            loadEmployees()
        ]);

        initCustomerTypeahead();
        initProductSearchCreate();
        initAddToCartCreate();
        initCartDock();
        initPosStickyBar();
        initPriceRangePanel();
        renderCustomerRecents();

        document.getElementById('branch_id')?.addEventListener('change', () => {
            if (selectedProduct) showStockInfo(selectedProduct);
        });

        document.getElementById('btnFocusCustomer')?.addEventListener('click', () => {
            clearCustomerPickerForNew();
        });

        document.getElementById('btnChangeCustomer')?.addEventListener('click', () => {
            clearCustomerPickerForNew();
        });

        await restoreSessionCarts();
    }

    function shortCustomerName(c) {
        if (!c) return 'Customer';
        const name = (c.shop_name || c.customer_name || 'Customer').trim();
        return name.length > 22 ? name.slice(0, 22) + '…' : name;
    }

    function tabCustomerName(c, id) {
        if (!c) return `Cust #${id}`;
        return shortCustomerName(c);
    }

    function setCustomerPickerLocked(locked, c) {
        const input = document.getElementById('customerSearch');
        const btnChange = document.getElementById('btnChangeCustomer');
        const recents = document.getElementById('customerRecents');
        const label = document.getElementById('customerSearchLabel');

        if (!input) return;

        if (locked && c) {
            input.value = shortCustomerName(c);
            input.readOnly = true;
            input.classList.add('is-locked');
            btnChange?.classList.remove('d-none');
            recents?.classList.add('is-hidden');
            if (label) label.textContent = 'Selected customer';
        } else {
            input.readOnly = false;
            input.classList.remove('is-locked');
            input.value = '';
            btnChange?.classList.add('d-none');
            recents?.classList.remove('is-hidden');
            if (label) label.textContent = 'Search name, shop or mobile';
        }
    }

    function clearCustomerPickerForNew() {
        const input = document.getElementById('customerSearch');
        setCustomerPickerLocked(false);
        input?.focus();
    }

    function initPriceRangePanel() {
        const rateInput = document.getElementById('sales_rate');
        rateInput?.addEventListener('input', () => updatePriceBandUi());
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
                updatePriceBandUi();
                document.getElementById('quantity')?.focus();
            }
        });
    }

    function setActivePriceRange(product) {
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

    function updatePriceBandUi() {
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
        document.getElementById('priceBandMin').textContent = fmtMoney(min);
        document.getElementById('priceBandMax').textContent = fmtMoney(max);
        document.getElementById('priceBandDefault').textContent = fmtMoney(def);

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
                statusEl.textContent = `Out of range — must be ৳${fmtMoney(min)} – ৳${fmtMoney(max)}`;
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

    function fmtMoney(n) {
        return typeof salesFormatMoney === 'function'
            ? salesFormatMoney(n)
            : (parseFloat(n) || 0).toFixed(2);
    }

    function validateActiveRate() {
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
                message: `Rate must be between ৳${fmtMoney(activePriceRange.min_rate)} and ৳${fmtMoney(activePriceRange.max_rate)}.`
            };
        }
        return { valid: true };
    }

    function initCustomerTypeahead() {
        const input = document.getElementById('customerSearch');
        const box = document.getElementById('customerSuggestions');
        if (!input || !box) return;

        input.addEventListener('input', debounce(async function () {
            const term = this.value.trim();
            if (term.length < 1) {
                box.classList.remove('show');
                box.innerHTML = '';
                return;
            }
            const res = await fetch(BASE_URL + 'sales/search_customer?term=' + encodeURIComponent(term));
            const data = parseSalesListResponse(await res.json());
            let html = '';
            data.forEach(c => {
                customerCache[c.id] = c;
                html += `<button type="button" class="sales-suggest-item" data-id="${c.id}">
                    <span class="suggest-title">${escapeHtml(c.shop_name || c.customer_name)}</span>
                    <span class="suggest-meta">${escapeHtml(c.customer_name || '')} · ${escapeHtml(c.mobile || '')}</span>
                </button>`;
            });
            box.innerHTML = html || '<div class="sales-suggest-empty">No customer found</div>';
            box.classList.add('show');
        }, 250));

        box.addEventListener('click', e => {
            const btn = e.target.closest('.sales-suggest-item');
            if (!btn) return;
            selectCustomer(parseInt(btn.dataset.id, 10));
        });

        input.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const first = box.querySelector('.sales-suggest-item');
                if (first) selectCustomer(parseInt(first.dataset.id, 10));
            }
        });

        document.addEventListener('click', e => {
            if (!e.target.closest('#customerSearch') && !e.target.closest('#customerSuggestions')) {
                box.classList.remove('show');
            }
        });
    }

    window.salesCreateSelectCustomer = selectCustomer;

    function selectCustomer(id, openTab = true) {
        if (!id) return;
        activeCustomerId = id;
        const c = customerCache[id];
        document.getElementById('customer_id').value = id;
        document.getElementById('customerSuggestions')?.classList.remove('show');
        setCustomerPickerLocked(true, c);

        rememberCustomerRecent(id);
        renderCustomerRecents();
        onCustomerSelected(id);

        if (openTab) {
            createOrSwitchTab(id);
        }
        document.getElementById('emptyCartHint')?.classList.add('d-none');
        document.getElementById('productSearch')?.focus();
    }

    function initProductSearchCreate() {
        const productSearch = document.getElementById('productSearch');
        const suggestionsBox = document.getElementById('productSuggestions');
        if (!productSearch) return;

        productSearch.addEventListener('input', debounce(async function () {
            const term = this.value.trim();
            if (term.length < 2) {
                suggestionsBox.classList.remove('show');
                return;
            }
            const branchId = typeof getActiveSalesBranchId === 'function'
                ? getActiveSalesBranchId()
                : (document.getElementById('branch_id')?.value || window.SESSION_BRANCH_ID || '');
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
            selectProductCreate(btn);
        });

        productSearch.addEventListener('keydown', async e => {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                if (!suggestionsBox.classList.contains('show')) return;
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

            const branchId = typeof getActiveSalesBranchId === 'function'
                ? getActiveSalesBranchId()
                : (document.getElementById('branch_id')?.value || window.SESSION_BRANCH_ID || '');

            if (typeof fetchSalesProductByExactCode === 'function') {
                const exact = await fetchSalesProductByExactCode(term, branchId);
                if (exact) {
                    selectProductCreate(exact);
                    return;
                }
            }

            if (suggestionsBox.classList.contains('show')) {
                const items = suggestionsBox.querySelectorAll('.sales-suggest-item:not(.disabled)');
                if (items.length) {
                    const pick = suggestionsBox.querySelector('.sales-suggest-item.active:not(.disabled)') || items[0];
                    if (pick) selectProductCreate(pick);
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

    function selectProductCreate(btnOrProduct) {
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

        selectedProduct = p;
        setActivePriceRange(p);
        document.getElementById('productSearch').value = p.product_name;
        const defRate = parseFloat(p.default_rate ?? p.price) || min;
        document.getElementById('sales_rate').value = defRate;
        document.getElementById('quantity').value = 1;
        document.getElementById('productSuggestions')?.classList.remove('show');
        updatePriceBandUi();
        showStockInfoCreate(p);
        document.getElementById('quantity')?.focus();
        document.getElementById('quantity')?.select();
    }

    async function showStockInfoCreate(product) {
        const container = document.getElementById('BranchStock');
        if (!container || !product) return;

        const branchId = typeof getActiveSalesBranchId === 'function'
            ? getActiveSalesBranchId()
            : (document.getElementById('branch_id')?.value || window.SESSION_BRANCH_ID || '');
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
        const sessionBranchId = String(window.SESSION_BRANCH_ID || '');

        branchSelect.innerHTML = branches.map(b => {
            const isHome = String(b.id) === sessionBranchId;
            const label = isHome ? `${b.branch_name} (your branch)` : b.branch_name;
            return `<option value="${b.id}">${escapeHtml(label)}</option>`;
        }).join('');

        const activeBranch = typeof getActiveSalesBranchId === 'function'
            ? getActiveSalesBranchId()
            : sessionBranchId;
        branchSelect.value = activeBranch || String(branches[0]?.id || '');

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

    function initAddToCartCreate() {
        const btn = document.getElementById('addToCartBtn');
        if (!btn) return;

        btn.addEventListener('click', async () => {
            const customer_id = document.getElementById('customer_id')?.value;
            if (!customer_id) {
                Swal.fire({ icon: 'warning', title: 'Select customer', text: 'Search and pick a customer first.' });
                document.getElementById('customerSearch')?.focus();
                return;
            }
            if (!selectedProduct) {
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

            const rateCheck = validateActiveRate();
            if (!rateCheck.valid) {
                Swal.fire('Price out of range', rateCheck.message, 'warning');
                document.getElementById('sales_rate')?.focus();
                updatePriceBandUi();
                return;
            }

            const formData = appendSalesCartContext(new FormData());
            formData.append('customer_id', customer_id);
            formData.append('product_id', selectedProduct.id);
            formData.append('product_name', selectedProduct.product_name);
            formData.append('qty', qty);
            formData.append('rate', rate);

            const res = await fetch(BASE_URL + 'sales/add_to_cart', salesPostOptions(appendCsrf(formData)));
            const result = await res.json();

            if (result.status === 'success') {
                toastAdded();
                createOrSwitchTab(customer_id);
                resetProductEntry();
                await refreshTabBadge(customer_id);
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

    function resetProductEntry() {
        document.getElementById('productSearch').value = '';
        document.getElementById('quantity').value = 1;
        document.getElementById('sales_rate').value = '';
        selectedProduct = null;
        activePriceRange = null;
        document.getElementById('priceRangePanel')?.classList.add('d-none');
        document.getElementById('BranchStock')?.classList.add('d-none');
        document.getElementById('productSearch')?.focus();
    }

    function toastAdded() {
        const toast = document.createElement('div');
        toast.className = 'sales-toast';
        toast.innerHTML = '<i class="fas fa-check-circle"></i> Added to cart';
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 1400);
    }

    function initCartDock() {
        document.addEventListener('click', e => {
            if (e.target.closest('.sales-tab-link')) {
                e.preventDefault();
                const cid = e.target.closest('.sales-tab-link').dataset.customer_id;
                switchToTab(cid);
            }
            if (e.target.closest('.close-tab-btn')) {
                e.preventDefault();
                closeTab(e.target.closest('.close-tab-btn').dataset.customer_id);
            }
        });
    }

    window.createOrSwitchTab = function (customer_id) {
        if (!customer_id) return;
        activeCustomerId = customer_id;
        const c = customerCache[customer_id];
        const label = tabCustomerName(c, customer_id);
        const tabTitle = c
            ? `${c.shop_name || c.customer_name || ''}${c.mobile ? ' · ' + c.mobile : ''}`.trim()
            : `Customer #${customer_id}`;

        const tabsUl = document.getElementById('draft-tabs');
        const content = document.getElementById('draft-tab-content');

        if (!document.getElementById(`tab-${customer_id}`)) {
            const li = document.createElement('li');
            li.className = 'nav-item';
            li.id = `tab-li-${customer_id}`;
            li.innerHTML = `
                <div class="sales-tab-pill">
                    <a href="#" class="sales-tab-link" id="tab-${customer_id}" data-customer_id="${customer_id}" title="${escapeHtml(tabTitle)}">
                        <span class="tab-name">${escapeHtml(label)}</span>
                        <span class="tab-badge" id="badge-${customer_id}">0</span>
                    </a>
                    <button type="button" class="close-tab-btn" data-customer_id="${customer_id}" title="Close cart">&times;</button>
                </div>`;
            tabsUl.appendChild(li);

            const pane = document.createElement('div');
            pane.className = 'tab-pane fade';
            pane.id = `cart-${customer_id}`;
            pane.innerHTML = `<div class="cart-container p-2 p-md-3" data-customer_id="${customer_id}">Loading...</div>`;
            content.appendChild(pane);
        }

        switchToTab(customer_id);
        loadCart(customer_id);
        refreshTabBadge(customer_id);
    };

    function switchToTab(customer_id) {
        document.querySelectorAll('.sales-tab-link').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('show', 'active'));

        document.getElementById(`tab-${customer_id}`)?.classList.add('active');
        const pane = document.getElementById(`cart-${customer_id}`);
        if (pane) pane.classList.add('show', 'active');

        document.getElementById('customer_id').value = customer_id;
        activeCustomerId = customer_id;

        const c = customerCache[customer_id];
        setCustomerPickerLocked(true, c);
        onCustomerSelected(customer_id);
        updateStickyForTab(customer_id);
    }

    async function refreshTabBadge(customer_id) {
        const fd = appendSalesCartContext(new FormData());
        fd.append('customer_id', customer_id);
        const res = await fetch(BASE_URL + 'sales/load_cart', salesPostOptions(appendCsrf(fd)));
        const data = await res.json();
        const badge = document.getElementById(`badge-${customer_id}`);
        const count = (data.items || []).length;
        if (badge) {
            badge.textContent = count;
            badge.classList.toggle('has-items', count > 0);
        }
        updateStickyForTab(customer_id, data);
    }

    function updateStickyForTab(customer_id, cartData) {
        if (String(activeCustomerId) !== String(customer_id)) return;
        const items = cartData?.items || [];
        const subtotal = parseFloat(cartData?.subtotal || 0);
        updatePosStickyBar(items.length, subtotal);
    }

    async function restoreSessionCarts() {
        try {
            const res = await fetch(BASE_URL + 'sales/list_draft_carts');
            const carts = parseSalesListResponse(await res.json());
            if (!carts.length) return;

            for (const cart of carts) {
                customerCache[cart.customer_id] = {
                    id: cart.customer_id,
                    shop_name: cart.shop_name,
                    customer_name: cart.customer_name,
                    mobile: cart.mobile
                };
                createOrSwitchTab(cart.customer_id);
                const badge = document.getElementById(`badge-${cart.customer_id}`);
                if (badge) {
                    badge.textContent = cart.item_count;
                    badge.classList.toggle('has-items', cart.item_count > 0);
                }
            }
            if (carts.length) {
                selectCustomer(carts[0].customer_id, false);
                switchToTab(carts[0].customer_id);
            }
        } catch (e) {
            console.warn('Could not restore carts', e);
        }
    }

    const _closeTab = window.closeTab;
    window.closeTab = async function (customer_id) {
        const result = await Swal.fire({
            title: 'Close this cart?',
            text: 'All items for this customer will be removed.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, close'
        });
        if (!result.isConfirmed) return;

        const fd = new FormData();
        fd.append('customer_id', customer_id);
        await fetch(BASE_URL + 'sales/clear_tab_cart', salesPostOptions(appendCsrf(fd)));

        document.getElementById(`tab-li-${customer_id}`)?.remove();
        document.getElementById(`cart-${customer_id}`)?.remove();

        if (String(activeCustomerId) === String(customer_id)) {
            const next = document.querySelector('.sales-tab-link');
            if (next) {
                switchToTab(next.dataset.customer_id);
            } else {
                activeCustomerId = null;
                document.getElementById('customer_id').value = '';
                setCustomerPickerLocked(false);
                document.getElementById('emptyCartHint')?.classList.remove('d-none');
                updatePosStickyBar(0, 0);
            }
        }
    };

    // Override loadCart tail to refresh badges on create page
    const origLoadCart = window.loadCart;
    if (typeof origLoadCart === 'function') {
        window.loadCart = async function (customer_id) {
            await origLoadCart(customer_id);
            if (document.getElementById('sales-create-app')) {
                refreshTabBadge(customer_id);
            }
        };
    }
})();
/**
 * Purchase order create / edit — line items, product search, save
 */
(function () {
    'use strict';

    let BASE_URL = '';
    let itemCounter = 0;
    let isEditMode = false;
    const searchTimers = {};

    function escapeHtml(unsafe) {
        if (unsafe == null) return '';
        const d = document.createElement('div');
        d.textContent = String(unsafe);
        return d.innerHTML;
    }

    function getCsrfToken() {
        return window.CSRF_TOKEN || document.querySelector('#poForm input[name="csrf_token"]')?.value || '';
    }

    function postBody(params) {
        const body = new URLSearchParams(params);
        const token = getCsrfToken();
        if (token) body.append('csrf_token', token);
        return body.toString();
    }

    /** BaseController::sendJson wraps product lists in { status, data: [...] }. */
    function parseProductSearchPayload(raw) {
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
        return { rows: [], error: null };
    }

    function formatMoney(n) {
        return (parseFloat(n) || 0).toFixed(2);
    }

    function buildProductRowHtml(rowId, opts) {
        const readonly = opts && opts.readonly;
        const productName = opts ? opts.product_name : '';
        const productCode = opts ? opts.product_code : '';
        const productId = opts ? opts.product_id : '';
        const qty = opts ? opts.qty : '';
        const rate = opts ? opts.rate : '';

        const displayVal = readonly && productName
            ? `${productName} (${productCode})`
            : '';

        return `
            <td class="purch-po-product-cell">
                <input type="text" class="form-control product-search"
                       placeholder="Search product name or code…"
                       value="${escapeHtml(displayVal)}"
                       ${readonly ? 'readonly' : ''}
                       autocomplete="off"
                       data-row-id="${rowId}">
                <div class="purch-po-product-dropdown product-dropdown" id="dropdown-${rowId}"></div>
                <input type="hidden" class="product-id-input" value="${productId}">
            </td>
            <td>
                <input type="number" class="form-control qty-input"
                       value="${qty}" step="0.01" min="0.01" placeholder="Qty" required>
            </td>
            <td>
                <input type="number" class="form-control rate-input"
                       value="${rate}" step="0.01" min="0.01" placeholder="Rate" required>
            </td>
            <td class="text-end align-middle">
                <strong class="row-amount">0.00</strong>
            </td>
            <td class="align-middle text-center">
                <button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" title="Remove line">
                    <i class="fas fa-trash"></i>
                </button>
            </td>`;
    }

    function addItemRow(prefill) {
        itemCounter++;
        const rowId = itemCounter;
        const tbody = document.querySelector('#itemTable tbody');
        if (!tbody) return;

        const row = document.createElement('tr');
        row.id = `item-row-${rowId}`;
        row.innerHTML = buildProductRowHtml(rowId, prefill);
        tbody.appendChild(row);

        bindRowEvents(row, rowId);
        if (prefill) {
            calculateRowAmount(rowId);
        } else {
            const input = row.querySelector('.product-search');
            if (input) setTimeout(() => input.focus(), 10);
        }
    }

    function bindRowEvents(row, rowId) {
        const productInput = row.querySelector('.product-search');
        const qtyInput = row.querySelector('.qty-input');
        const rateInput = row.querySelector('.rate-input');
        const removeBtn = row.querySelector('.btn-remove-row');

        if (productInput && !productInput.readOnly) {
            productInput.addEventListener('focus', () => showProductDropdown(rowId));
            productInput.addEventListener('input', () => {
                clearTimeout(searchTimers[rowId]);
                searchTimers[rowId] = setTimeout(() => searchProduct(productInput, rowId), 220);
            });
        }

        if (qtyInput) {
            qtyInput.addEventListener('input', () => calculateRowAmount(rowId));
            qtyInput.addEventListener('change', () => calculateRowAmount(rowId));
        }
        if (rateInput) {
            rateInput.addEventListener('input', () => calculateRowAmount(rowId));
            rateInput.addEventListener('change', () => calculateRowAmount(rowId));
        }
        if (removeBtn) {
            removeBtn.addEventListener('click', () => removeRow(rowId));
        }

        const dropdown = row.querySelector('.product-dropdown');
        if (dropdown) {
            dropdown.addEventListener('click', (e) => {
                const item = e.target.closest('[data-product-id]');
                if (!item) return;
                selectProduct(
                    rowId,
                    item.dataset.productId,
                    item.dataset.productName,
                    item.dataset.productCode
                );
            });
        }
    }

    function loadExistingItems() {
        const tbody = document.querySelector('#itemTable tbody');
        if (!tbody) return;
        tbody.innerHTML = '';
        itemCounter = 0;

        (window.existingItems || []).forEach((item) => {
            addItemRow({
                readonly: true,
                product_id: item.product_id,
                product_name: item.product_name,
                product_code: item.product_code,
                qty: item.qty,
                rate: item.rate,
            });
        });

        if (itemCounter === 0) {
            addItemRow();
        }
    }

    function searchProduct(input, rowId) {
        const term = input.value.trim();
        const dropdown = document.getElementById(`dropdown-${rowId}`);
        if (!dropdown) return;

        if (term.length < 1) {
            dropdown.style.display = 'none';
            dropdown.innerHTML = '';
            return;
        }

        fetch(`${BASE_URL}PurchaseOrder/search_products`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: postBody({ term }),
        })
            .then((r) => r.json())
            .then((raw) => {
                const parsed = parseProductSearchPayload(raw);
                if (parsed.error) {
                    dropdown.innerHTML = `<div class="p-2 text-danger small">${escapeHtml(parsed.error)}</div>`;
                    dropdown.style.display = 'block';
                    return;
                }

                let html = '';
                parsed.rows.forEach((p) => {
                    html += `
                        <button type="button" class="dropdown-item"
                                data-product-id="${p.id}"
                                data-product-name="${escapeHtml(p.product_name)}"
                                data-product-code="${escapeHtml(p.product_code)}">
                            <strong>${escapeHtml(p.product_name)}</strong>
                            <span class="text-muted"> (${escapeHtml(p.product_code)})</span>
                        </button>`;
                });
                dropdown.innerHTML = html || '<div class="p-2 text-muted small">No products found</div>';
                dropdown.style.display = 'block';
            })
            .catch((err) => {
                console.error(err);
                dropdown.innerHTML = '<div class="p-2 text-danger small">Search failed</div>';
                dropdown.style.display = 'block';
            });
    }

    function selectProduct(rowId, productId, productName, productCode) {
        const row = document.getElementById(`item-row-${rowId}`);
        if (!row) return;
        const search = row.querySelector('.product-search');
        const hidden = row.querySelector('.product-id-input');
        if (search) search.value = `${productName} (${productCode})`;
        if (hidden) hidden.value = productId;
        const dropdown = document.getElementById(`dropdown-${rowId}`);
        if (dropdown) dropdown.style.display = 'none';
        calculateRowAmount(rowId);
        row.querySelector('.qty-input')?.focus();
    }

    function showProductDropdown(rowId) {
        const dropdown = document.getElementById(`dropdown-${rowId}`);
        if (dropdown && dropdown.innerHTML.trim()) {
            dropdown.style.display = 'block';
        }
    }

    function calculateRowAmount(rowId) {
        const row = document.getElementById(`item-row-${rowId}`);
        if (!row) return;

        const qty = parseFloat(row.querySelector('.qty-input')?.value) || 0;
        const rate = parseFloat(row.querySelector('.rate-input')?.value) || 0;
        const amountEl = row.querySelector('.row-amount');
        if (amountEl) amountEl.textContent = formatMoney(qty * rate);
        calculateTotalAmount();
    }

    function removeRow(rowId) {
        const row = document.getElementById(`item-row-${rowId}`);
        if (row) row.remove();
        calculateTotalAmount();
        const tbody = document.querySelector('#itemTable tbody');
        if (tbody && !tbody.querySelector('tr')) {
            addItemRow();
        }
    }

    function calculateTotalAmount() {
        let total = 0;
        document.querySelectorAll('.row-amount').forEach((el) => {
            total += parseFloat(el.textContent) || 0;
        });
        const totalEl = document.getElementById('totalAmount');
        if (totalEl) totalEl.textContent = formatMoney(total);
    }

    function getItemsData() {
        const items = [];
        document.querySelectorAll('#itemTable tbody tr').forEach((row) => {
            const productId = row.querySelector('.product-id-input')?.value;
            const qtyInput = row.querySelector('.qty-input');
            const rateInput = row.querySelector('.rate-input');

            if (productId && qtyInput && rateInput) {
                const qty = parseFloat(qtyInput.value);
                const rate = parseFloat(rateInput.value);
                if (qty > 0 && rate > 0) {
                    items.push({
                        product_id: parseInt(productId, 10),
                        qty,
                        rate,
                    });
                }
            }
        });
        return items;
    }

    function initFormSubmit() {
        const form = document.getElementById('poForm');
        if (!form) return;

        form.addEventListener('submit', (e) => {
            e.preventDefault();

            const items = getItemsData();
            if (items.length === 0) {
                Swal.fire('Warning', 'Add at least one product line with qty and rate', 'warning');
                return;
            }

            const totalAmount = parseFloat(document.getElementById('totalAmount')?.textContent) || 0;
            const formData = new FormData(form);
            formData.set('items', JSON.stringify(items));
            formData.set('total_amount', String(totalAmount));

            const poId = form.querySelector('input[name="po_id"]')?.value;
            const url = isEditMode && poId
                ? `${BASE_URL}PurchaseOrder/update/${poId}`
                : `${BASE_URL}PurchaseOrder/store`;

            fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: formData,
            })
                .then((r) => r.json())
                .then((data) => {
                    if (data.status === 'success') {
                        Swal.fire('Success', data.message || 'Purchase order saved', 'success').then(() => {
                            const boot = window.PO_FORM_BOOT || {};
                            if (isEditMode && boot.po_id) {
                                window.location.href = `${BASE_URL}PurchaseOrder/Details/${boot.po_id}`;
                            } else {
                                window.location.href = `${BASE_URL}PurchaseOrder`;
                            }
                        });
                    } else {
                        Swal.fire('Error', data.message || 'Failed to save', 'error');
                    }
                })
                .catch((err) => {
                    console.error(err);
                    Swal.fire('Error', 'Something went wrong. Please try again.', 'error');
                });
        });
    }

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.product-search') && !e.target.closest('.product-dropdown')) {
            document.querySelectorAll('.product-dropdown').forEach((d) => {
                d.style.display = 'none';
            });
        }
    });

    document.addEventListener('DOMContentLoaded', () => {
        const baseInput = document.getElementById('base_url');
        BASE_URL = baseInput ? baseInput.value : `${window.location.origin}/remote-center-erp/public/`;
        if (BASE_URL && !BASE_URL.endsWith('/')) BASE_URL += '/';

        isEditMode = (window.PO_FORM_BOOT && window.PO_FORM_BOOT.mode === 'edit')
            || (Array.isArray(window.existingItems) && window.existingItems.length > 0);

        document.getElementById('btnAddPoItem')?.addEventListener('click', () => addItemRow());

        if (isEditMode && window.existingItems && window.existingItems.length > 0) {
            loadExistingItems();
        } else {
            addItemRow();
        }

        initFormSubmit();
        calculateTotalAmount();
    });
})();
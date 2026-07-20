let BASE_URL = '';
let warehouseBranchMap = {};
let productsCache = null;

function wtBaseUrl() {
    if (window.WT_BOOT?.baseUrl) {
        let u = window.WT_BOOT.baseUrl;
        return u.endsWith('/') ? u : u + '/';
    }
    const baseInput = document.getElementById('base_url');
    let u = baseInput ? baseInput.value : '/remote-center-erp/public/';
    return u.endsWith('/') ? u : u + '/';
}

function parseJsonPayload(raw) {
    if (Array.isArray(raw)) return raw;
    if (raw && typeof raw === 'object' && raw.data !== undefined) {
        return Array.isArray(raw.data) ? raw.data : raw.data;
    }
    return null;
}

function unwrapList(raw) {
    const parsed = parseJsonPayload(raw);
    if (Array.isArray(parsed)) return parsed;
    if (Array.isArray(raw)) return raw;
    return null;
}

function hydrateWarehouseMapFromDom() {
    warehouseBranchMap = {};
    ['from_warehouse_id', 'to_warehouse_id'].forEach((selectId) => {
        const select = document.getElementById(selectId);
        if (!select) return;
        select.querySelectorAll('option[value]').forEach((opt) => {
            if (!opt.value) return;
            warehouseBranchMap[opt.value] = {
                branch_id: parseInt(opt.dataset.branchId || '0', 10),
                branch_name: opt.dataset.branchName || '',
                name: opt.dataset.warehouseName || opt.textContent || '',
            };
        });
    });
}

function bindWarehouseRouteListeners() {
    const fromSelect = document.getElementById('from_warehouse_id');
    const toSelect = document.getElementById('to_warehouse_id');
    if (!fromSelect || !toSelect) return;
    if (fromSelect.dataset.wtBound === '1') return;
    fromSelect.dataset.wtBound = '1';
    toSelect.dataset.wtBound = '1';
    fromSelect.addEventListener('change', validateBranchRoute);
    toSelect.addEventListener('change', validateBranchRoute);
    validateBranchRoute();
}

document.addEventListener('DOMContentLoaded', function() {
    BASE_URL = wtBaseUrl();

    document.body.addEventListener('click', (e) => {
        const btn = e.target.closest('.js-wt-reverse');
        if (!btn) return;
        reverseTransfer(btn.dataset.transferId, btn.dataset.transferCode);
    });

    if (document.getElementById('warehouseTransferForm')) {
        const hasDomWarehouses = document.querySelector('#from_warehouse_id option[value]:not([value=""])');
        if (hasDomWarehouses || window.WT_BOOT?.hasWarehouses) {
            hydrateWarehouseMapFromDom();
            bindWarehouseRouteListeners();
            initCreateForm();
            if (!document.querySelector('.item-row')) addItemRow();
        } else {
            loadWarehouses().then(() => {
                initCreateForm();
                if (!document.querySelector('.item-row')) addItemRow();
            });
        }
    }
});

async function loadWarehouses() {
    const fromSelect = document.getElementById('from_warehouse_id');
    const toSelect = document.getElementById('to_warehouse_id');
    if (!fromSelect || !toSelect) return;

    try {
        const res = await fetch(BASE_URL + 'WarehouseTransfer/getWarehouses', { credentials: 'same-origin' });
        if (!res.ok) {
            throw new Error('HTTP ' + res.status);
        }
        const raw = await res.json();
        const data = unwrapList(raw) ?? [];

        warehouseBranchMap = {};
        fromSelect.innerHTML = '<option value="">— From warehouse —</option>';
        toSelect.innerHTML = '<option value="">— To warehouse —</option>';

        data.forEach(w => {
            warehouseBranchMap[w.id] = {
                branch_id: w.branch_id,
                branch_name: w.branch_name || '',
                name: w.warehouse_name || '',
            };
            const label = w.warehouse_name || ('Warehouse #' + w.id);
            const fromOpt = new Option(label, w.id);
            fromOpt.dataset.branchId = String(w.branch_id ?? '');
            fromOpt.dataset.branchName = w.branch_name || '';
            fromOpt.dataset.warehouseName = w.warehouse_name || '';
            fromSelect.add(fromOpt);
            const toOpt = new Option(label, w.id);
            toOpt.dataset.branchId = String(w.branch_id ?? '');
            toOpt.dataset.branchName = w.branch_name || '';
            toOpt.dataset.warehouseName = w.warehouse_name || '';
            toSelect.add(toOpt);
        });

        bindWarehouseRouteListeners();

        if (data.length === 0 && typeof Swal !== 'undefined') {
            Swal.fire('No warehouses', 'No active warehouses found. Check warehouse setup.', 'warning');
        }
    } catch (err) {
        console.error('Warehouses load error:', err);
        if (typeof Swal !== 'undefined') {
            Swal.fire('Load failed', 'Could not load warehouses. Refresh the page or check your connection.', 'error');
        }
    }
}

function validateBranchRoute() {
    const hint = document.getElementById('wt_route_hint');
    const fromId = document.getElementById('from_warehouse_id')?.value;
    const toId = document.getElementById('to_warehouse_id')?.value;
    if (!hint) return;

    if (!fromId || !toId) {
        hint.textContent = 'Select two different warehouses in your branch.';
        hint.className = 'wt-branch-hint';
        return;
    }
    if (fromId === toId) {
        hint.textContent = 'From and To warehouse must be different.';
        hint.className = 'wt-branch-hint text-danger';
        return;
    }
    const fb = warehouseBranchMap[fromId];
    const tb = warehouseBranchMap[toId];
    if (fb && tb) {
        hint.textContent = `${fb.name || 'From'} → ${tb.name || 'To'}`;
        hint.className = 'wt-branch-hint text-success';
    }
}

function initCreateForm() {
    const form = document.getElementById('warehouseTransferForm');
    if (!form) return;

    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        const fromId = form.querySelector('[name="from_warehouse_id"]')?.value;
        const toId = form.querySelector('[name="to_warehouse_id"]')?.value;
        const fb = warehouseBranchMap[fromId];
        const tb = warehouseBranchMap[toId];

        if (!fromId || !toId) {
            Swal.fire('Error', 'Select from and to warehouses', 'error');
            return;
        }
        if (fromId === toId) {
            Swal.fire('Error', 'From and To warehouse must be different', 'error');
            return;
        }
        if (fb && tb && fb.branch_id && tb.branch_id && fb.branch_id !== tb.branch_id) {
            Swal.fire('Error', 'Both warehouses must be in your branch', 'error');
            return;
        }

        const formData = new FormData(this);
        const items = [];

        document.querySelectorAll('.item-row').forEach(row => {
            const productSelect = row.querySelector('select[name="product_id[]"]');
            const qtyInput = row.querySelector('input[name="qty[]"]');
            const rateInput = row.querySelector('input[name="rate[]"]');
            if (productSelect?.value && qtyInput && parseFloat(qtyInput.value) > 0) {
                items.push({
                    product_id: parseInt(productSelect.value, 10),
                    qty: parseFloat(qtyInput.value),
                    rate: parseFloat(rateInput?.value || 0),
                });
            }
        });

        if (items.length === 0) {
            Swal.fire('Error', 'Please add at least one product', 'error');
            return;
        }

        const total = items.reduce((s, it) => s + it.qty * (it.rate || 0), 0);
        formData.set('total_amount', total.toFixed(2));
        formData.append('items', JSON.stringify(items));

        try {
            const res = await fetch(BASE_URL + 'WarehouseTransfer/store', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.status === 'success') {
                Swal.fire('Success', data.message || ('Transfer ' + data.transfer_code), 'success')
                    .then(() => window.location.href = BASE_URL + 'WarehouseTransfer/details/' + (data.transfer_id || ''));
            } else {
                Swal.fire('Error', data.message || 'Failed to save', 'error');
            }
        } catch {
            Swal.fire('Error', 'Network error', 'error');
        }
    });
}

function addItemRow() {
    const container = document.getElementById('items_section');
    if (!container) return;

    const row = document.createElement('div');
    row.className = 'row g-2 mb-2 item-row align-items-end';
    row.innerHTML = `
        <div class="col-md-4">
            <select name="product_id[]" class="form-select form-select-sm product-select" required onchange="loadProductInfo(this)">
                <option value="">— Product —</option>
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" name="qty[]" step="0.01" min="0.01" class="form-control form-control-sm" placeholder="Qty" required oninput="calculateAmount(this)">
        </div>
        <div class="col-md-2">
            <input type="number" name="rate[]" step="0.01" min="0" class="form-control form-control-sm rate-input" placeholder="Avg cost" readonly>
        </div>
        <div class="col-md-2">
            <input type="text" class="form-control form-control-sm amount-input" readonly placeholder="Amount">
        </div>
        <div class="col-md-1">
            <small class="stock-info text-muted d-block">Stock: —</small>
        </div>
        <div class="col-md-1">
            <button type="button" onclick="removeRow(this)" class="btn btn-danger btn-sm w-100">×</button>
        </div>
    `;
    container.appendChild(row);
    loadProducts(row.querySelector('.product-select'));
}

function removeRow(btn) {
    btn.closest('.item-row')?.remove();
    recalcTransferTotal();
}

function resetForm() {
    document.getElementById('warehouseTransferForm')?.reset();
    const section = document.getElementById('items_section');
    if (section) {
        section.innerHTML = '';
        addItemRow();
    }
    validateBranchRoute();
    recalcTransferTotal();
}

async function loadProducts(select) {
    if (!select) return;

    try {
        if (!productsCache) {
            const res = await fetch(BASE_URL + 'WarehouseTransfer/getProducts', { credentials: 'same-origin' });
            if (!res.ok) {
                throw new Error('HTTP ' + res.status);
            }
            const raw = await res.json();
            productsCache = unwrapList(raw) ?? [];
            if (!Array.isArray(productsCache)) {
                productsCache = [];
            }
        }

        const placeholder = select.querySelector('option[value=""]') || new Option('— Product —', '');
        select.innerHTML = '';
        select.appendChild(placeholder);

        productsCache.forEach(p => {
            select.add(new Option((p.product_code || '') + ' - ' + (p.product_name || ''), p.id));
        });

        if (productsCache.length === 0 && typeof Swal !== 'undefined') {
            Swal.fire('No products', 'No active products found.', 'warning');
        }
    } catch (err) {
        console.error('Products load error:', err);
        if (typeof Swal !== 'undefined') {
            Swal.fire('Load failed', 'Could not load products. Refresh the page.', 'error');
        }
    }
}

function loadProductInfo(select) {
    const row = select.closest('.item-row');
    const fromWarehouseId = document.getElementById('from_warehouse_id')?.value;
    const stockInfo = row?.querySelector('.stock-info');
    const rateInput = row?.querySelector('.rate-input');
    const qtyInput = row?.querySelector('input[name="qty[]"]');

    if (!select.value || !fromWarehouseId) {
        if (stockInfo) stockInfo.textContent = 'Stock: —';
        if (rateInput) rateInput.value = '';
        return;
    }

    fetch(BASE_URL + `WarehouseTransfer/getProductStockAndPrice?product_id=${select.value}&warehouse_id=${fromWarehouseId}`, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            const available = parseFloat(data.available_qty) || 0;
            const rate = parseFloat(data.rate ?? data.price) || 0;
            if (stockInfo) {
                stockInfo.textContent = `Stock: ${available}`;
                stockInfo.className = available > 0 ? 'stock-info text-success d-block' : 'stock-info text-danger d-block';
            }
            if (rateInput) rateInput.value = rate;
            if (qtyInput) qtyInput.max = available > 0 ? available : '';
            calculateAmount(qtyInput);
        })
        .catch(() => {
            if (stockInfo) stockInfo.textContent = 'Stock: error';
        });
}

function calculateAmount(qtyInput) {
    const row = qtyInput?.closest('.item-row');
    if (!row) return;
    const rate = parseFloat(row.querySelector('.rate-input')?.value) || 0;
    const qty = parseFloat(qtyInput?.value) || 0;
    const amountField = row.querySelector('.amount-input');
    if (amountField) amountField.value = (qty * rate).toFixed(2);
    recalcTransferTotal();
}

function recalcTransferTotal() {
    let total = 0;
    document.querySelectorAll('.item-row').forEach(row => {
        const qty = parseFloat(row.querySelector('input[name="qty[]"]')?.value) || 0;
        const rate = parseFloat(row.querySelector('.rate-input')?.value) || 0;
        total += qty * rate;
    });
    const el = document.getElementById('wt_total_value');
    if (el) el.textContent = total.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function wtCsrfToken() {
    return window.CSRF_TOKEN || document.querySelector('input[name="csrf_token"]')?.value || '';
}

function reverseTransfer(id, code) {
    if (!id) return;

    Swal.fire({
        title: 'Reverse transfer ' + (code ? '#' + code : '') + '?',
        html: '<p class="small text-muted mb-0">Stock will be removed from the <strong>to</strong> warehouse and restored to the <strong>from</strong> warehouse. This cannot be undone.</p>',
        input: 'textarea',
        inputLabel: 'Reason (required, min 3 characters)',
        inputPlaceholder: 'Why is this transfer being reversed?',
        inputValidator: (v) => (!v || v.trim().length < 3) ? 'Enter a reason (at least 3 characters)' : undefined,
        showCancelButton: true,
        confirmButtonText: 'Yes, reverse',
        confirmButtonColor: '#d33',
    }).then((result) => {
        if (!result.isConfirmed) return;

        const body = new URLSearchParams();
        body.set('id', String(id));
        body.set('reverse_reason', (result.value || '').trim());
        const csrf = wtCsrfToken();
        if (csrf) body.set('csrf_token', csrf);

        Swal.fire({ title: 'Reversing…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        fetch(BASE_URL + 'WarehouseTransfer/reverse', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body.toString(),
        })
        .then((r) => r.json().then((data) => ({ ok: r.ok, data })))
        .then(({ ok, data }) => {
            if (ok && data.status === 'success') {
                Swal.fire('Reversed', data.message || 'Transfer reversed successfully.', 'success')
                    .then(() => location.reload());
            } else {
                Swal.fire('Error', data.message || 'Reversal failed', 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Network error while reversing', 'error'));
    });
}

window.reverseTransfer = reverseTransfer;
let BASE_URL = '';
let productsCache = null;

function dmgBaseUrl() {
    if (window.DMG_BOOT?.baseUrl) {
        let u = window.DMG_BOOT.baseUrl;
        return u.endsWith('/') ? u : u + '/';
    }
    const baseInput = document.getElementById('base_url');
    let u = baseInput ? baseInput.value : '/remote-center-erp/public/';
    return u.endsWith('/') ? u : u + '/';
}

function parseJsonList(raw) {
    if (Array.isArray(raw)) return raw;
    if (raw && typeof raw === 'object' && Array.isArray(raw.data)) return raw.data;
    return [];
}

document.addEventListener('DOMContentLoaded', function() {
    BASE_URL = dmgBaseUrl();

    document.body.addEventListener('click', (e) => {
        const btn = e.target.closest('.js-dmg-reverse');
        if (!btn) return;
        reverseDamage(btn.dataset.damageId, btn.dataset.damageCode);
    });

    if (document.getElementById('damageForm')) {
        initDamageForm();
        const wh = document.getElementById('dmg_warehouse_id');
        if (wh) wh.addEventListener('change', onWarehouseChange);
        if (!document.querySelector('.item-row')) addItemRow();
        document.getElementById('items_section')?.addEventListener('input', recalcDamageTotals);
    }
});

function onWarehouseChange() {
    document.querySelectorAll('.item-row .product-select').forEach((sel) => {
        if (sel.value) loadProductInfo(sel);
    });
    recalcDamageTotals();
}

function recalcDamageTotals() {
    let lines = 0;
    let total = 0;
    document.querySelectorAll('.item-row').forEach((row) => {
        const pid = row.querySelector('select[name="product_id[]"]')?.value;
        const qty = parseFloat(row.querySelector('input[name="qty[]"]')?.value || 0);
        const rate = parseFloat(row.querySelector('input[name="rate[]"]')?.value || 0);
        if (pid && qty > 0) {
            lines++;
            total += qty * rate;
        }
    });
    const lc = document.getElementById('dmg_line_count');
    const tv = document.getElementById('dmg_total_value');
    if (lc) lc.textContent = String(lines);
    if (tv) tv.textContent = total.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function initDamageForm() {
    const form = document.getElementById('damageForm');
    if (!form) return;

    form.addEventListener('submit', async function(e) {
        e.preventDefault();

        const warehouseId = form.querySelector('[name="warehouse_id"]')?.value;
        if (!warehouseId) {
            Swal.fire('Error', 'Select a warehouse first', 'error');
            return;
        }

        const formData = new FormData(this);
        const items = [];

        document.querySelectorAll('.item-row').forEach((row) => {
            const productId = row.querySelector('select[name="product_id[]"]')?.value;
            const qty = parseFloat(row.querySelector('input[name="qty[]"]')?.value || 0);
            const rate = parseFloat(row.querySelector('input[name="rate[]"]')?.value || 0);
            if (productId && qty > 0) {
                items.push({
                    product_id: parseInt(productId, 10),
                    qty,
                    rate,
                });
            }
        });

        if (items.length === 0) {
            Swal.fire('Error', 'Add at least one product line', 'error');
            return;
        }

        const total = items.reduce((s, it) => s + it.qty * (it.rate || 0), 0);
        formData.set('total_value', total.toFixed(2));
        formData.append('items', JSON.stringify(items));

        try {
            const res = await fetch(BASE_URL + 'Damage/store', { method: 'POST', body: formData, credentials: 'same-origin' });
            const data = await res.json();
            if (data.status === 'success') {
                const amt = data.total_value != null
                    ? ' Amount: ' + Number(data.total_value).toLocaleString('en-IN', { minimumFractionDigits: 2 })
                    : '';
                Swal.fire('Saved', (data.message || 'Damage recorded') + amt, 'success')
                    .then(() => {
                        window.location.href = BASE_URL + 'Damage/details/' + (data.damage_id || '');
                    });
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
            <input type="number" name="qty[]" step="0.01" min="0.01" class="form-control form-control-sm" placeholder="Qty" required oninput="calculateLineAmount(this)">
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
            <button type="button" onclick="removeDamageRow(this)" class="btn btn-danger btn-sm w-100">×</button>
        </div>
    `;
    container.appendChild(row);
    loadProducts(row.querySelector('.product-select'));
}

function removeDamageRow(btn) {
    btn.closest('.item-row')?.remove();
    recalcDamageTotals();
}

function resetDamageForm() {
    document.getElementById('damageForm')?.reset();
    const section = document.getElementById('items_section');
    if (section) {
        section.innerHTML = '';
        addItemRow();
    }
    recalcDamageTotals();
}

async function loadProducts(select) {
    if (!select) return;
    try {
        if (!productsCache) {
            const res = await fetch(BASE_URL + 'Damage/getProducts', { credentials: 'same-origin' });
            const raw = await res.json();
            productsCache = parseJsonList(raw);
        }
        select.innerHTML = '<option value="">— Product —</option>';
        productsCache.forEach((p) => {
            select.add(new Option((p.product_code || '') + ' - ' + (p.product_name || ''), p.id));
        });
    } catch (err) {
        console.error('Products load error:', err);
    }
}

function loadProductInfo(select) {
    const row = select.closest('.item-row');
    const warehouseId = document.getElementById('dmg_warehouse_id')?.value;
    const stockInfo = row?.querySelector('.stock-info');
    const rateInput = row?.querySelector('.rate-input');
    const qtyInput = row?.querySelector('input[name="qty[]"]');

    if (!select.value || !warehouseId) {
        if (stockInfo) stockInfo.textContent = 'Stock: —';
        if (rateInput) rateInput.value = '';
        recalcDamageTotals();
        return;
    }

    fetch(BASE_URL + `Damage/getProductStockAndPrice?product_id=${select.value}&warehouse_id=${warehouseId}`, { credentials: 'same-origin' })
        .then((r) => r.json())
        .then((data) => {
            const available = parseFloat(data.available_qty) || 0;
            const rate = parseFloat(data.rate ?? data.price) || 0;
            if (stockInfo) {
                stockInfo.textContent = 'Stock: ' + available;
                stockInfo.className = available > 0 ? 'stock-info text-success d-block' : 'stock-info text-danger d-block';
            }
            if (rateInput) rateInput.value = rate;
            if (qtyInput) qtyInput.max = available > 0 ? available : '';
            calculateLineAmount(qtyInput);
        });
}

function calculateLineAmount(qtyInput) {
    const row = qtyInput?.closest('.item-row');
    if (!row) return;
    const rate = parseFloat(row.querySelector('.rate-input')?.value) || 0;
    const qty = parseFloat(qtyInput?.value) || 0;
    const amountField = row.querySelector('.amount-input');
    if (amountField) amountField.value = (qty * rate).toFixed(2);
    recalcDamageTotals();
}

function reverseDamage(id, code) {
    if (!id) return;

    Swal.fire({
        title: 'Reverse damage ' + (code ? '#' + code : '') + '?',
        html: '<p class="small text-muted mb-0">Stock will be restored to the warehouse. Linked GL will be reversed if posted.</p>',
        input: 'textarea',
        inputLabel: 'Reason (required, min 3 characters)',
        inputValidator: (v) => (!v || v.trim().length < 3) ? 'Enter a reason' : undefined,
        showCancelButton: true,
        confirmButtonText: 'Yes, reverse',
        confirmButtonColor: '#d33',
    }).then((result) => {
        if (!result.isConfirmed) return;

        const body = new URLSearchParams();
        body.set('id', String(id));
        body.set('reverse_reason', (result.value || '').trim());

        Swal.fire({ title: 'Reversing…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        fetch(BASE_URL + 'Damage/reverse', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body.toString(),
        })
        .then((r) => r.json())
        .then((data) => {
            if (data.status === 'success') {
                Swal.fire('Reversed', data.message || '', 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', data.message || 'Reversal failed', 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Network error', 'error'));
    });
}

window.addItemRow = addItemRow;
window.loadProductInfo = loadProductInfo;
window.calculateLineAmount = calculateLineAmount;
window.removeDamageRow = removeDamageRow;
window.resetDamageForm = resetDamageForm;
window.reverseDamage = reverseDamage;
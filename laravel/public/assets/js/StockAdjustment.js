let BASE_URL = '';

document.addEventListener('DOMContentLoaded', function() {
    const baseInput = document.getElementById('base_url');
    BASE_URL = baseInput ? baseInput.value : '/remote-center-erp/public/';
    if (BASE_URL && !BASE_URL.endsWith('/')) BASE_URL += '/';

    document.body.addEventListener('click', function(e) {
        const btn = e.target.closest('.js-sa-reverse');
        if (!btn) return;
        reverseAdjustment(btn.dataset.adjustmentId, btn.dataset.adjustmentCode);
    });

    if (document.getElementById('stockAdjustmentForm')) {
        initCreateForm();
        if (!document.querySelector('.item-row')) {
            addItemRow();
        }
        const wh = document.getElementById('sa_warehouse_id');
        const typeSel = document.getElementById('sa_adjustment_type');
        if (wh) wh.addEventListener('change', onWarehouseOrTypeChange);
        if (typeSel) typeSel.addEventListener('change', updateTypeHint);
        updateTypeHint();
        document.getElementById('items_section')?.addEventListener('input', recalcTotals);
    }
});

function updateTypeHint() {
    const typeSel = document.getElementById('sa_adjustment_type');
    const hint = document.getElementById('sa_type_hint');
    if (!typeSel || !hint) return;
    hint.textContent = typeSel.value === 'decrease'
        ? 'Decrease: Dr shrinkage / Cr inventory on save'
        : 'Increase: Dr inventory / Cr surplus on save';
}

function onWarehouseOrTypeChange() {
    document.querySelectorAll('.item-row .product-select').forEach((sel) => {
        if (sel.value) loadProductPrice(sel);
    });
    recalcTotals();
}

function recalcTotals() {
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
    const lc = document.getElementById('sa_line_count');
    const tv = document.getElementById('sa_total_value');
    if (lc) lc.textContent = String(lines);
    if (tv) tv.textContent = total.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function initCreateForm() {
    const form = document.getElementById('stockAdjustmentForm');
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

        document.querySelectorAll('.item-row').forEach(row => {
            const productId = row.querySelector('select[name="product_id[]"]')?.value;
            const qty = parseFloat(row.querySelector('input[name="qty[]"]')?.value || 0);
            const rate = parseFloat(row.querySelector('input[name="rate[]"]')?.value || 0);
            const reason = row.querySelector('input[name="reason[]"]')?.value || '';

            if (productId && qty > 0) {
                items.push({
                    product_id: parseInt(productId, 10),
                    qty: qty,
                    rate: rate,
                    reason: reason
                });
            }
        });

        if (items.length === 0) {
            Swal.fire('Error', 'Please add at least one item', 'error');
            return;
        }

        const totalAmount = items.reduce((sum, it) => sum + it.qty * (it.rate || 0), 0);
        formData.set('total_amount', totalAmount.toFixed(2));
        formData.append('items', JSON.stringify(items));

        try {
            const res = await fetch(BASE_URL + 'StockAdjustment/store', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.status === 'success') {
                Swal.fire('Success', data.message || ('Saved: ' + data.adjustment_code), 'success')
                    .then(() => {
                        window.location.href = BASE_URL + 'StockAdjustment/details/' + (data.adjustment_id || '');
                    });
            } else {
                Swal.fire('Error', data.message || 'Could not save adjustment', 'error');
            }
        } catch (err) {
            Swal.fire('Error', 'Network error — please try again', 'error');
        }
    });
}

function addItemRow() {
    const container = document.getElementById('items_section');
    const row = document.createElement('div');
    row.className = 'row g-2 mb-2 item-row align-items-end';
    row.innerHTML = `
        <div class="col-md-4">
            <select name="product_id[]" class="form-select form-select-sm product-select" required onchange="loadProductPrice(this)">
                <option value="">— Product —</option>
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" name="qty[]" step="0.01" min="0.01" class="form-control form-control-sm" placeholder="Qty" required>
        </div>
        <div class="col-md-2">
            <input type="number" name="rate[]" step="0.01" min="0" class="form-control form-control-sm rate-input" placeholder="Rate" readonly>
        </div>
        <div class="col-md-3">
            <input type="text" name="reason[]" class="form-control form-control-sm" placeholder="Reason">
        </div>
        <div class="col-md-1">
            <button type="button" onclick="removeRow(this)" class="btn btn-danger btn-sm w-100">×</button>
        </div>
    `;
    container.appendChild(row);
    loadProducts(row.querySelector('.product-select'));
    row.querySelector('input[name="qty[]"]')?.addEventListener('input', recalcTotals);
}

function removeRow(btn) {
    btn.closest('.item-row').remove();
    recalcTotals();
}

function resetForm() {
    const form = document.getElementById('stockAdjustmentForm');
    if (!form) return;
    form.reset();
    const section = document.getElementById('items_section');
    if (section) {
        section.innerHTML = '';
        addItemRow();
    }
    updateTypeHint();
    recalcTotals();
}

function loadProducts(select) {
    fetch(BASE_URL + 'WarehouseTransfer/getProducts')
        .then(r => r.json())
        .then(products => {
            const list = Array.isArray(products) ? products : (products?.data || []);
            list.forEach(p => {
                const opt = new Option((p.product_code || '') + ' - ' + (p.product_name || ''), p.id);
                select.add(opt);
            });
        })
        .catch(() => {});
}

function loadProductPrice(select) {
    const row = select.closest('.item-row');
    const rateInput = row?.querySelector('.rate-input');
    const warehouseId = document.querySelector('#stockAdjustmentForm [name="warehouse_id"]')?.value;

    if (!select.value || !warehouseId || !rateInput) {
        if (rateInput) rateInput.value = '';
        recalcTotals();
        return;
    }

    fetch(BASE_URL + 'StockAdjustment/getProductPrice?id=' + select.value + '&warehouse_id=' + warehouseId)
        .then(r => r.json())
        .then(data => {
            rateInput.value = data.price ?? 0;
            recalcTotals();
        })
        .catch(() => {
            rateInput.value = 0;
            recalcTotals();
        });
}

function reverseAdjustment(id, code) {
    Swal.fire({
        title: 'Reverse Adjustment #' + code + '?',
        input: 'textarea',
        inputLabel: 'Reason (required, min 3 characters)',
        inputValidator: (value) => {
            if (!value || value.trim().length < 3) {
                return 'Please enter a reason (at least 3 characters)';
            }
        },
        showCancelButton: true,
        confirmButtonColor: '#d33'
    }).then((result) => {
        if (!result.isConfirmed) return;

        fetch(BASE_URL + 'StockAdjustment/reverse', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id=' + encodeURIComponent(id) + '&reverse_reason=' + encodeURIComponent(result.value || '')
        })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'success') {
                Swal.fire('Reversed', data.message || '', 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', data.message || 'Reversal failed', 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'Network error', 'error'));
    });
}
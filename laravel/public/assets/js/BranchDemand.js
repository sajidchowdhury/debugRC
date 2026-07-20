// BranchDemand.js — inter-branch demand UI

function bdBaseUrl() {
    if (window.BD_BOOT?.baseUrl) {
        let u = window.BD_BOOT.baseUrl;
        return u.endsWith('/') ? u : u + '/';
    }
    const baseInput = document.getElementById('base_url');
    let u = baseInput ? baseInput.value : '/remote-center-erp/public/';
    return u.endsWith('/') ? u : u + '/';
}

function parseJsonPayload(data) {
    if (data && typeof data === 'object' && data.data !== undefined && data.status !== undefined) {
        return data.data;
    }
    return data;
}

let BASE_URL = '';

document.addEventListener('DOMContentLoaded', function () {
    BASE_URL = bdBaseUrl();

    if (document.getElementById('branchDemandForm')) {
        loadOtherBranches();
        initCreateForm();
        const addBtn = document.getElementById('bdAddLineBtn');
        if (addBtn) {
            addBtn.addEventListener('click', () => addItemRow());
        }
        if (!document.querySelector('#items_section .item-row')) {
            addItemRow();
        }
    }

    if (document.getElementById('itemsTable')) {
        initDetailsPage();
    }
});

function loadOtherBranches() {
    fetch(BASE_URL + 'BranchDemand/getBranches')
        .then((r) => r.json())
        .then((raw) => {
            const data = parseJsonPayload(raw) ?? raw;
            const list = Array.isArray(data) ? data : [];
            const select = document.getElementById('to_branch_id');
            if (!select) return;
            select.innerHTML = '<option value="">— Select supplying branch —</option>';
            list.forEach((b) => {
                const label = b.branch_name + (b.branch_code ? ' (' + b.branch_code + ')' : '');
                select.add(new Option(label, b.id));
            });
        })
        .catch(() => {
            const select = document.getElementById('to_branch_id');
            if (select) select.innerHTML = '<option value="">Failed to load branches</option>';
        });
}

function initCreateForm() {
    const form = document.getElementById('branchDemandForm');
    if (!form) return;

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        const items = [];
        document.querySelectorAll('.item-row').forEach((row) => {
            const productSelect = row.querySelector('select[name="product_id[]"]');
            const qtyInput = row.querySelector('input[name="qty[]"]');
            if (productSelect?.value && parseFloat(qtyInput?.value) > 0) {
                items.push({
                    product_id: parseInt(productSelect.value, 10),
                    qty: parseFloat(qtyInput.value),
                });
            }
        });

        if (items.length === 0) {
            return Swal.fire('Error', 'Please add at least one product with quantity', 'error');
        }

        const formData = new FormData(form);
        formData.append('items', JSON.stringify(items));

        try {
            const res = await fetch(BASE_URL + 'BranchDemand/store', { method: 'POST', body: formData });
            const raw = await res.json();
            const data = parseJsonPayload(raw) ?? raw;

            if (data.status === 'success') {
                Swal.fire('Success', `Demand created: ${data.demand_code}`, 'success').then(
                    () => (window.location.href = BASE_URL + 'BranchDemand')
                );
            } else {
                Swal.fire('Error', data.message || 'Could not create demand', 'error');
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
        <div class="col-md-7">
            <label class="form-label small mb-0">Product</label>
            <select name="product_id[]" class="form-select form-select-sm product-select" required>
                <option value="">Loading…</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-0">Qty</label>
            <input type="number" name="qty[]" step="0.01" min="0.01" class="form-control form-control-sm" placeholder="0" required>
        </div>
        <div class="col-md-2">
            <button type="button" class="btn btn-outline-danger btn-sm w-100 btn-remove-line" title="Remove">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    container.appendChild(row);

    row.querySelector('.btn-remove-line')?.addEventListener('click', () => {
        const rows = container.querySelectorAll('.item-row');
        if (rows.length > 1) row.remove();
        else Swal.fire('Notice', 'At least one line is required', 'info');
    });

    loadProducts(row.querySelector('.product-select'));
}

function loadProducts(select) {
    if (!select) return;
    fetch(BASE_URL + 'BranchDemand/getProducts')
        .then((r) => r.json())
        .then((raw) => {
            const data = parseJsonPayload(raw) ?? raw;
            const products = Array.isArray(data) ? data : [];
            select.innerHTML = '<option value="">— Select product —</option>';
            products.forEach((p) => {
                select.add(new Option(`${p.product_code} - ${p.product_name}`, p.id));
            });
        })
        .catch(() => {
            select.innerHTML = '<option value="">Failed to load products</option>';
        });
}

function initDetailsPage() {}

function sendGoods(demandId) {
    const items = [];
    let hasError = false;

    document.querySelectorAll('#itemsTable tbody tr').forEach((row) => {
        const fromSelect = row.querySelector('.from-warehouse');
        const toSelect = row.querySelector('.to-warehouse');
        const qty = parseFloat(row.dataset.qty);
        const pid = parseInt(row.dataset.productId, 10);

        if (!fromSelect?.value || !toSelect?.value) {
            hasError = true;
        }

        items.push({
            product_id: pid,
            qty: qty,
            from_warehouse_id: parseInt(fromSelect?.value, 10),
            to_warehouse_id: parseInt(toSelect?.value, 10),
        });
    });

    if (hasError || items.length === 0) {
        return Swal.fire('Error', 'Select from and to warehouse for every line', 'error');
    }

    Swal.fire({
        title: 'Send goods?',
        text: 'Stock will move and transfer principal will be locked at current catalog cost.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, send now',
    }).then((result) => {
        if (!result.isConfirmed) return;

        fetch(BASE_URL + 'BranchDemand/send', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `demand_id=${demandId}&items=${encodeURIComponent(JSON.stringify(items))}`,
        })
            .then((r) => r.json())
            .then((raw) => {
                const data = parseJsonPayload(raw) ?? raw;
                if (data.status === 'success') {
                    Swal.fire('Success', data.message || 'Goods sent', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message || 'Failed to send', 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Network error', 'error'));
    });
}

function reverseDemand(id, code) {
    Swal.fire({
        title: `Reverse demand ${code}?`,
        input: 'textarea',
        inputLabel: 'Reason (required)',
        inputPlaceholder: 'Enter reason…',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Reverse',
    }).then((result) => {
        if (!result.isConfirmed) return;
        if (!result.value?.trim()) {
            return Swal.fire('Error', 'Reason is required', 'error');
        }

        fetch(BASE_URL + 'BranchDemand/reverse', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}&reverse_reason=${encodeURIComponent(result.value)}`,
        })
            .then((r) => r.json())
            .then((raw) => {
                const data = parseJsonPayload(raw) ?? raw;
                if (data.status === 'success') {
                    Swal.fire('Reversed', data.message || 'Done', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message || 'Failed', 'error');
                }
            });
    });
}

function deleteDemand(id, code) {
    Swal.fire({
        title: `Delete demand ${code}?`,
        text: 'This cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Delete',
    }).then((result) => {
        if (!result.isConfirmed) return;

        fetch(BASE_URL + 'BranchDemand/delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}`,
        })
            .then((r) => r.json())
            .then((raw) => {
                const data = parseJsonPayload(raw) ?? raw;
                if (data.status === 'success') {
                    Swal.fire('Deleted', '', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.message || 'Failed', 'error');
                }
            });
    });
}

function loadWarehousesForBranch(branchId, selector, label = 'Warehouse') {
    if (!branchId) return;

    fetch(BASE_URL + 'BranchDemand/getWarehousesByBranch?branch_id=' + branchId)
        .then((r) => r.json())
        .then((raw) => {
            const data = parseJsonPayload(raw) ?? raw;
            const list = Array.isArray(data) ? data : [];
            document.querySelectorAll(selector).forEach((select) => {
                select.innerHTML = `<option value="">— Select ${label} —</option>`;
                list.forEach((w) => {
                    const opt = new Option(
                        w.warehouse_name + (w.branch_name ? ` (${w.branch_name})` : ''),
                        w.id
                    );
                    select.add(opt);
                });
            });
        })
        .catch(() => Swal.fire('Warning', `Failed to load ${label}`, 'warning'));
}
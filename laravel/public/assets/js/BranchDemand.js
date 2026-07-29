// BranchDemand.js — inter-branch demand UI
// Phase 9: Updated to point to Laravel named routes instead of legacy URLs.
// The Blade views set window.BD_ROUTES with all the Laravel route URLs.

function bdRoute(name, params) {
    if (window.BD_ROUTES && window.BD_ROUTES[name]) {
        let url = window.BD_ROUTES[name];
        if (params) {
            Object.keys(params).forEach(key => {
                url = url.replace('{' + key + '}', params[key]);
            });
        }
        return url;
    }
    // Fallback: try to construct from base URL + legacy path
    const base = window.BD_BOOT?.baseUrl || '/admin/branch-demands/';
    return base + name;
}

function parseJsonPayload(data) {
    if (data && typeof data === 'object' && data.data !== undefined && data.status !== undefined) {
        return data.data;
    }
    return data;
}

document.addEventListener('DOMContentLoaded', function () {
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
    fetch(bdRoute('branches'))
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
            const res = await fetch(bdRoute('store'), { method: 'POST', body: formData });
            const raw = await res.json();
            const data = parseJsonPayload(raw) ?? raw;

            if (data.status === 'success' || res.ok) {
                Swal.fire('Success', `Demand created successfully`, 'success').then(
                    () => (window.location.href = bdRoute('index'))
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
    fetch(bdRoute('products'))
        .then((r) => r.json())
        .then((raw) => {
            const data = parseJsonPayload(raw) ?? raw;
            const products = Array.isArray(data) ? data : (data.data || []);
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
            id: parseInt(row.dataset.itemId, 10),
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

        // Build form data for Laravel
        const formData = new FormData();
        items.forEach((item, idx) => {
            formData.append(`items[${idx}][id]`, item.id);
            formData.append(`items[${idx}][from_warehouse_id]`, item.from_warehouse_id);
            formData.append(`items[${idx}][to_warehouse_id]`, item.to_warehouse_id);
        });

        fetch(bdRoute('send', { id: demandId }), {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || window.CSRF_TOKEN },
        })
            .then((r) => {
                if (r.redirected) {
                    window.location.href = r.url;
                } else {
                    return r.json();
                }
            })
            .then((raw) => {
                if (!raw) return;
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

        const formData = new FormData();
        formData.append('reason', result.value);

        fetch(bdRoute('reverse', { id: id }), {
            method: 'POST',
            body: formData,
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || window.CSRF_TOKEN },
        })
            .then((r) => {
                if (r.redirected) {
                    window.location.href = r.url;
                } else {
                    return r.json();
                }
            })
            .then((raw) => {
                if (!raw) return;
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

        fetch(bdRoute('destroy', { id: id }), {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || window.CSRF_TOKEN,
            },
        })
            .then((r) => {
                if (r.redirected) {
                    window.location.href = r.url;
                } else {
                    return r.json();
                }
            })
            .then((raw) => {
                if (!raw) return;
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

    fetch(bdRoute('warehouses', { id: branchId }))
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

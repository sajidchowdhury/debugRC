// assets/js/PurchaseReceive.js

let itemCounter = 0;
let BASE_URL = '';

document.addEventListener('DOMContentLoaded', function() {
    const baseInput = document.getElementById('base_url');
    BASE_URL = baseInput ? baseInput.value : (window.location.origin + '/remote-center-erp/public/');
    
    if (BASE_URL && !BASE_URL.endsWith('/')) {
        BASE_URL += '/';
    }

    // Set initial state of "Remaining Qty" column based on toggle
    const toggle = document.getElementById('directPurchaseToggle');
    const showRemaining = toggle ? !toggle.checked : true;
    if (typeof toggleRemainingColumn === 'function') {
        toggleRemainingColumn(showRemaining);
    }
});


function loadPODetails() {
    const poId = document.getElementById('poSelect').value;
    if (!poId) {
        // Clear table if no PO selected
        const tbody = document.querySelector('#receiveTable tbody');
        if (tbody) tbody.innerHTML = '';
        return;
    }

    const csrf = window.CSRF_TOKEN || '';
    fetch(BASE_URL + 'PurchaseReceive/get_po_details', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': csrf
        },
        body: 'po_id=' + poId + '&csrf_token=' + encodeURIComponent(csrf)
    })
    .then(r => r.json())
    .then(data => {
        if (!data || data.status === 'error' || !data.items || data.items.length === 0) {
            const msg = (data && data.message) ? data.message : 'No items found or all items already received.';
            Swal.fire('Error', msg, 'error');
            return;
        }

        renderReceiveItems(data.items);
        // Show "Remaining Qty" column when items come from a PO
        if (typeof toggleRemainingColumn === 'function') {
            toggleRemainingColumn(true);
        }
    })
    .catch(err => {
        console.error('loadPODetails error:', err);
        Swal.fire('Error', 'Failed to load PO items. Check console (F12) or verify CSRF/BASE_URL.', 'error');
    });
}
function renderReceiveItems(items) {
    const tbody = document.querySelector('#receiveTable tbody');
    tbody.innerHTML = '';
    itemCounter = 0;

    items.forEach(item => {
        itemCounter++;

        const row = document.createElement('tr');
        row.id = `row-${itemCounter}`;

        row.innerHTML = `
            <td>
                <strong>${item.product_name}</strong><br>
                <small>${item.product_code}</small>

                <!-- ✅ FIXED HERE -->
                <input type="hidden" class="product-id" value="${item.product_id}">
                <input type="hidden" class="poi-id" value="${item.purchase_order_item_id}">
            </td>

            <td class="text-center">
                <strong>${parseFloat(item.remaining_qty).toFixed(2)}</strong>
            </td>

            <td>
                <input type="number" class="form-control receive-qty" 
                       step="0.01" min="0.01" max="${item.remaining_qty}" 
                       value="${item.remaining_qty}" 
                       onkeyup="calculateRowAmount(${itemCounter})">
            </td>

            <td>
                <input type="number" class="form-control rate-input" 
                       value="${item.rate}" step="0.01" 
                       onkeyup="calculateRowAmount(${itemCounter})">
            </td>

            <td>
                <select class="form-control warehouse-select" onchange="calculateRowAmount(${itemCounter})">
                    ${getWarehouseOptions()}
                </select>
            </td>

            <td class="text-end">
                <strong class="row-amount">0.00</strong>
            </td>

            <td>
                <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(${itemCounter})">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        `;

        tbody.appendChild(row);
        calculateRowAmount(itemCounter);
    });
}
function getWarehouseOptions() {
    let options = '<option value="">-- Select Warehouse --</option>';
    if (!window.warehouses || !Array.isArray(window.warehouses) || window.warehouses.length === 0) {
        console.warn('No warehouses loaded into window.warehouses for this branch');
        options += '<option value="" disabled>(no warehouses configured)</option>';
        return options;
    }
    window.warehouses.forEach((w, index) => {
        // Default-select the first warehouse for convenience (user can change)
        const selected = (index === 0) ? 'selected' : '';
        const code = w.warehouse_code || '';
        options += `<option value="${w.id}" ${selected}>${w.warehouse_name} (${code})</option>`;
    });
    return options;
}

function calculateRowAmount(rowId) {
    const row = document.getElementById(`row-${rowId}`);
    if (!row) return;

    const qty = parseFloat(row.querySelector('.receive-qty').value) || 0;
    const rate = parseFloat(row.querySelector('.rate-input').value) || 0;
    const amount = qty * rate;

    row.querySelector('.row-amount').textContent = amount.toFixed(2);
    calculateTotal();
}

function removeRow(rowId) {
    const row = document.getElementById(`row-${rowId}`);
    if (row) row.remove();
    calculateTotal();
}

function calculateTotal() {
    let total = 0;
    document.querySelectorAll('.row-amount').forEach(el => {
        total += parseFloat(el.textContent) || 0;
    });
    document.getElementById('totalAmount').textContent = total.toFixed(2);
}

function getItemsData() {
    const items = [];
    document.querySelectorAll('#receiveTable tbody tr').forEach(row => {
        const qty = parseFloat(row.querySelector('.receive-qty').value) || 0;
        if (qty <= 0) return;

        items.push({
            purchase_order_item_id: row.querySelector('.poi-id') ? row.querySelector('.poi-id').value : null,
            product_id: row.querySelector('.product-id').value,
            qty: qty,
            rate: parseFloat(row.querySelector('.rate-input').value) || 0,
            warehouse_id: parseInt(row.querySelector('.warehouse-select').value) || 0
        });
    });
    return items;
}

// Form Submit
document.getElementById('receiveForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const isDirect = document.getElementById('directPurchaseToggle')?.checked;

    // Validation for Direct Purchase
    if (isDirect) {
        const supplierId = document.getElementById('supplierSelect')?.value;
        if (!supplierId) {
            Swal.fire('Validation Error', 'Please select a Supplier for Direct Purchase', 'warning');
            return;
        }
    } else {
        const poId = document.getElementById('poSelect')?.value;
        if (!poId) {
            Swal.fire('Validation Error', 'Please select a Purchase Order', 'warning');
            return;
        }
    }

    const items = getItemsData();
    if (items.length === 0) {
        Swal.fire('Warning', 'Please add at least one item to receive', 'warning');
        return;
    }

    // Ensure warehouse chosen for every item (prevents "warehouse missing" at submit time)
    for (const it of items) {
        if (!it.warehouse_id) {
            Swal.fire('Validation Error', 'Please select a warehouse for every item line', 'warning');
            return;
        }
    }

    const totalAmount = parseFloat(document.getElementById('totalAmount').textContent);

    const formData = new FormData(this);
    formData.append('items', JSON.stringify(items));
    formData.append('total_amount', totalAmount);

    if (isDirect) {
        formData.append('purchase_order_id', ''); // Explicitly empty for direct
        const supplierId = document.getElementById('supplierSelect')?.value;
        if (supplierId) {
            formData.append('supplier_id', supplierId);
        }
    } else {
        formData.append('purchase_order_id', document.getElementById('poSelect').value);
    }
 
fetch(BASE_URL + 'PurchaseReceive/store', {
    method: 'POST',
    body: formData
})
.then(response => {
    if (!response.ok) {
        return response.text().then(text => { throw new Error(text); });
    }
    return response.json();
})
.then(data => {
    if (data.status === 'success') {
        Swal.fire({
            title: 'Success!',
            text: data.message,
            icon: 'success'
        }).then(() => {
            window.location.href = BASE_URL + 'PurchaseReceive';
        });
    } else {
        Swal.fire('Error', data.message || 'Operation failed', 'error');
    }
})
.catch(error => {
    console.error('Error:', error);
    Swal.fire({
        title: 'Error!',
        text: error.message || 'Something went wrong!',
        icon: 'error'
    });
});
});

// =============================================
// HELPER FUNCTIONS FOR MODE SWITCHING
// =============================================

function toggleDirectPurchaseMode() {
    const isDirect = document.getElementById("directPurchaseToggle")?.checked;
    const poRow = document.getElementById("poSelectionRow");
    const supplierRow = document.getElementById("directSupplierRow");
    const poSelect = document.getElementById("poSelect");
    const supplierSelect = document.getElementById("supplierSelect");
    const addBtn = document.getElementById("addManualItemBtn");

    if (isDirect) {
        if (poRow) poRow.style.display = "none";
        if (supplierRow) supplierRow.classList.remove("d-none");
        if (poSelect) poSelect.required = false;
        if (supplierSelect) supplierSelect.required = true;
        if (addBtn) addBtn.classList.remove("d-none");

        if (typeof clearReceiveTable === 'function') clearReceiveTable();
        if (typeof toggleRemainingColumn === 'function') toggleRemainingColumn(false);
    } else {
        if (poRow) poRow.style.display = "";
        if (supplierRow) supplierRow.classList.add("d-none");
        if (poSelect) poSelect.required = true;
        if (supplierSelect) supplierSelect.required = false;
        if (addBtn) addBtn.classList.add("d-none");

        if (typeof clearReceiveTable === 'function') clearReceiveTable();
        if (typeof toggleRemainingColumn === 'function') toggleRemainingColumn(true);
    }
}

function clearReceiveTable() {
    const tbody = document.querySelector('#receiveTable tbody');
    if (tbody) tbody.innerHTML = '';
    const totalEl = document.getElementById('totalAmount');
    if (totalEl) totalEl.textContent = '0.00';
}

function toggleRemainingColumn(show) {
    const table = document.getElementById('receiveTable');
    if (!table) return;

    const headerCells = table.querySelectorAll('thead th');
    const rows = table.querySelectorAll('tbody tr');

    // Column index 1 is "Remaining Qty"
    if (headerCells.length > 1) {
        headerCells[1].style.display = show ? '' : 'none';
    }

    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length > 1) {
            cells[1].style.display = show ? '' : 'none';
        }
    });
}

// =============================================
// DIRECT PURCHASE MANUAL ITEM ENTRY (Phase 2)
// =============================================

function addDirectItemRow() {
    itemCounter++;
    const tbody = document.querySelector('#receiveTable tbody');
    const row = document.createElement('tr');
    row.id = `row-${itemCounter}`;

    row.innerHTML = `
        <td>
            <input type="text" class="form-control product-search-direct" 
                   placeholder="Search product..." 
                   onkeyup="searchProductForDirect(this, ${itemCounter})">
            <div class="product-dropdown" id="dropdown-direct-${itemCounter}" 
                 style="display:none; position:absolute; background:white; border:1px solid #ccc; max-height:250px; overflow:auto; z-index:1000; width:100%;"></div>
            <input type="hidden" class="product-id" value="">
        </td>
        <td class="text-center">
            <span class="text-muted">N/A</span>
        </td>
        <td>
            <input type="number" class="form-control receive-qty" 
                   step="0.01" min="0.01" value="1" 
                   onkeyup="calculateRowAmount(${itemCounter})">
        </td>
        <td>
            <input type="number" class="form-control rate-input" 
                   step="0.01" value="0" 
                   onkeyup="calculateRowAmount(${itemCounter})">
        </td>
        <td>
            <select class="form-control warehouse-select" onchange="calculateRowAmount(${itemCounter})">
                ${getWarehouseOptions()}
            </select>
        </td>
        <td class="text-end">
            <strong class="row-amount">0.00</strong>
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(${itemCounter})">
                <i class="fas fa-trash"></i>
            </button>
        </td>
    `;

    tbody.appendChild(row);
    calculateRowAmount(itemCounter);
}

function searchProductForDirect(input, rowId) {
    const term = input.value.trim();
    const dropdown = document.getElementById(`dropdown-direct-${rowId}`);
    if (!dropdown || term.length < 2) {
        if (dropdown) dropdown.style.display = 'none';
        return;
    }

    fetch(BASE_URL + 'PurchaseOrder/search_products', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'term=' + encodeURIComponent(term)
    })
    .then(r => r.json())
    .then(products => {
        dropdown.innerHTML = '';
        dropdown.style.display = 'block';

        if (products.length === 0) {
            dropdown.innerHTML = '<div class="p-2 text-muted">No products found</div>';
            return;
        }

        products.forEach(prod => {
            const div = document.createElement('div');
            div.className = 'p-2 dropdown-item';
            div.style.cursor = 'pointer';
            div.innerHTML = `<strong>${prod.product_name}</strong> <small>(${prod.product_code})</small>`;
            div.onclick = () => {
                const row = document.getElementById(`row-${rowId}`);
                if (row) {
                    row.querySelector('.product-id').value = prod.id;
                    input.value = `${prod.product_name} (${prod.product_code})`;
                    input.readOnly = true;
                }
                dropdown.style.display = 'none';
            };
            dropdown.appendChild(div);
        });
    });
}

// Update getItemsData to support direct mode (no poi-id required)
const originalGetItemsData = getItemsData;
getItemsData = function() {
    const items = [];
    document.querySelectorAll('#receiveTable tbody tr').forEach(row => {
        const qty = parseFloat(row.querySelector('.receive-qty')?.value) || 0;
        if (qty <= 0) return;

        const poiInput = row.querySelector('.poi-id');
        items.push({
            purchase_order_item_id: poiInput ? poiInput.value : null,
            product_id: row.querySelector('.product-id')?.value || '',
            qty: qty,
            rate: parseFloat(row.querySelector('.rate-input')?.value) || 0,
            warehouse_id: row.querySelector('.warehouse-select')?.value || ''
        });
    });
    return items;
};
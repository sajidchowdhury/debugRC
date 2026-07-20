// public/assets/js/sales.js


let BASE_URL = '';
let selectedProduct = null;
let isEditMode = false;
let currentInvoiceId = null;
let editCartLoaded = false;   // ← Prevent multiple loads

function getCsrfToken() {
    return window.CSRF_TOKEN
        || document.querySelector('input[name="csrf_token"]')?.value
        || '';
}

function appendCsrf(formData) {
    const token = getCsrfToken();
    if (token) {
        formData.append('csrf_token', token);
    }
    return formData;
}

function salesPostOptions(body) {
    return {
        method: 'POST',
        body,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    };
}

/**
 * JSON list endpoints (get_branch, search_*, employees) return { status, data: [...] }.
 * Also accepts legacy raw arrays and broken {0: row, 1: row, status: 'success'} shapes.
 */
/** Selected branch on the form, else session branch — use for all stock API calls. */
window.getActiveSalesBranchId = function () {
    const sel = document.getElementById('branch_id')?.value;
    if (sel !== undefined && sel !== null && String(sel) !== '') {
        return String(sel);
    }
    return String(window.SESSION_BRANCH_ID || '');
};

function parseSalesListResponse(json) {
    if (json == null) return [];
    if (Array.isArray(json)) return json;
    if (json.status === 'error') {
        console.warn(json.message || 'Sales API error');
        return [];
    }
    if (Array.isArray(json.data)) return json.data;
    if (typeof json === 'object') {
        const numericKeys = Object.keys(json).filter((k) => /^\d+$/.test(k));
        if (numericKeys.length) {
            return numericKeys
                .sort((a, b) => Number(a) - Number(b))
                .map((k) => json[k]);
        }
    }
    return [];
}

window.parseSalesListResponse = parseSalesListResponse;

/** Barcode / exact product_code lookup for sales POS. */
window.fetchSalesProductByExactCode = async function (code, branchId) {
    const trimmed = String(code || '').trim();
    if (!trimmed) return null;
    try {
        const url = BASE_URL + 'sales/product_by_code?code=' + encodeURIComponent(trimmed)
            + '&branch_id=' + encodeURIComponent(String(branchId || ''));
        const res = await fetch(url);
        const json = await res.json();
        if (json && json.status === 'success' && json.data) {
            return json.data;
        }
    } catch (err) {
        console.warn('product_by_code failed', err);
    }
    return null;
};

/** Price range helpers (create + edit POS). */
window.salesFormatMoney = function (n) {
    return (parseFloat(n) || 0).toFixed(2);
};

window.salesFormatPriceRange = function (min, max, def) {
    const lo = parseFloat(min) || 0;
    const hi = parseFloat(max) || 0;
    if (lo <= 0 && hi <= 0) return 'No range';
    return '৳' + salesFormatMoney(lo) + '–' + salesFormatMoney(hi);
};

window.salesRateRangeStatus = function (rate, min, max) {
    const r = parseFloat(rate) || 0;
    const lo = parseFloat(min) || 0;
    const hi = parseFloat(max) || 0;
    if (lo <= 0 || hi <= 0) return 'unknown';
    if (r < lo || r > hi) return 'bad';
    const span = hi - lo;
    if (span > 0 && (r - lo) / span < 0.08) return 'warn';
    return 'ok';
};

window.salesRatePillHtml = function (rate, min, max) {
    const st = salesRateRangeStatus(rate, min, max);
    if (st === 'unknown') return '';
    const cls = st === 'bad' ? 'sales-rate-pill-bad' : 'sales-rate-pill-ok';
    const label = st === 'bad' ? 'Out of range' : (st === 'warn' ? 'Low margin' : 'In range');
    return `<span class="sales-rate-pill ${cls}">${label}</span>`;
};

window.salesValidateRateClient = function (rate, min, max) {
    const r = parseFloat(rate) || 0;
    const lo = parseFloat(min) || 0;
    const hi = parseFloat(max) || 0;
    if (lo <= 0 || hi <= 0) {
        return { valid: false, message: 'No price range configured for this product.' };
    }
    if (r <= 0) {
        return { valid: false, message: 'Rate must be greater than zero.' };
    }
    if (r < lo - 0.0001 || r > hi + 0.0001) {
        return {
            valid: false,
            message: `Rate must be between ৳${salesFormatMoney(lo)} and ৳${salesFormatMoney(hi)}.`
        };
    }
    return { valid: true };
};

/** Branch + edit context for cart API calls. */
window.getSalesCartContext = function () {
    const branchId = document.getElementById('branch_id')?.value
        || document.getElementById('branch_id_locked')?.value
        || (typeof getActiveSalesBranchId === 'function' ? getActiveSalesBranchId() : '')
        || window.SESSION_BRANCH_ID
        || '';
    const editing = window.isEditMode || document.getElementById('edit_mode')?.value === '1';
    const invoiceId = window.currentInvoiceId || document.getElementById('invoice_id')?.value || '';
    return {
        branch_id: String(branchId),
        exclude_invoice_id: editing && invoiceId ? String(invoiceId) : '',
    };
};

window.appendSalesCartContext = function (formData) {
    const ctx = getSalesCartContext();
    if (ctx.branch_id) formData.append('branch_id', ctx.branch_id);
    if (ctx.exclude_invoice_id) formData.append('exclude_invoice_id', ctx.exclude_invoice_id);
    return formData;
};

window.applyCartValidationUi = function (validation, customerId) {
    const valid = validation?.valid !== false;
    const msg = validation?.message || '';

    document.querySelectorAll('.finalSubmitBtn, #posStickyFinalize').forEach(btn => {
        btn.disabled = !valid;
        btn.classList.toggle('disabled', !valid);
        btn.title = valid ? '' : (msg || 'Fix cart errors before submitting');
    });

    const container = document.getElementById('single-cart-area')
        || document.querySelector(`#cart-${customerId} .cart-container`);
    if (!container) return;

    container.querySelectorAll('.sales-cart-invalid-banner').forEach(el => el.remove());

    if (!valid && msg) {
        const parts = [];
        (validation.rate_errors || []).forEach(e => parts.push(e));
        (validation.stock_errors || []).forEach(e => parts.push(e));
        const html = parts.length ? parts.map(e => `<li>${escapeHtml(e)}</li>`).join('') : `<li>${escapeHtml(msg)}</li>`;
        const banner = document.createElement('div');
        banner.className = 'sales-cart-invalid-banner alert alert-danger py-2 px-3 mb-2';
        banner.innerHTML = `<strong>Cannot finalize until fixed:</strong><ul class="mb-0 small ps-3">${html}</ul>`;
        container.prepend(banner);
    }
};

window.showCartValidationError = function (data) {
    let msg = data.message || 'Cart validation failed';
    const parts = [];
    (data.rate_errors || []).forEach(e => parts.push(e));
    (data.stock_errors || []).forEach(e => parts.push(e));
    if (parts.length) {
        msg += '<ul class="text-start small mt-2 mb-0">' + parts.map(e => `<li>${escapeHtml(e)}</li>`).join('') + '</ul>';
    }
    Swal.fire({ icon: 'error', title: 'Cannot proceed', html: msg });
};

document.addEventListener('DOMContentLoaded', function() {
    const baseInput = document.getElementById('base_url');
    BASE_URL = baseInput ? baseInput.value : '/remote-center-erp/public/';
    
    if (BASE_URL && !BASE_URL.endsWith('/')) {
        BASE_URL += '/';
    }

    if (document.getElementById('sales-create-app') || window.SALES_CREATE_MODE
        || document.getElementById('sales-edit-app') || window.SALES_EDIT_MODE) {
        return;
    }

    loadInitialData();
    initProductSearch();
    initAddToCart();
    
});

const CUSTOMER_RECENTS_KEY = 'sales_customer_recents';
const CART_DRAFT_KEY = 'sales_cart_draft_backup';

async function loadInitialData() {
    await Promise.all([loadCustomers(), loadBranch(window.CAN_OVERRIDE_BRANCH === true), loadEmployees()]);
    renderCustomerRecents();
    restoreCartDraftIfNeeded();
    initPosStickyBar();
    
    isEditMode = !!document.getElementById("edit_mode");
    currentInvoiceId = document.getElementById("invoice_id") ? 
                       document.getElementById("invoice_id").value : null;

    if (isEditMode && currentInvoiceId) {
        initSimpleEditMode();
    } else {
        initDraftSystem();
    }
}


// ================= SIMPLE EDIT MODE - Clean Single Cart (No Duplication) =================

async function initSimpleEditMode() {
    if (editCartLoaded) return;
    editCartLoaded = true;

    let customer_id = document.getElementById("customer_id").value;
    if (!customer_id) {
        await new Promise(r => setTimeout(r, 300));
        customer_id = document.getElementById("customer_id").value;
        if (!customer_id) return console.warn("Edit mode: customer_id empty");
    }

    try {
        await clearCustomerCart(customer_id);
        await new Promise(resolve => setTimeout(resolve, 400));

        const res = await fetch(BASE_URL + `sales/get_invoice_for_edit/${currentInvoiceId}`);
        const data = await res.json();

        if (data.status !== 'success' || !data.items?.length) {
            console.warn("No items found for edit");
            return;
        }

        // NEW: Populate form fields
        await populateEditFormFields(data);

        // Add items to cart
        for (const item of data.items) {
            const fd = new FormData();
            fd.append("customer_id", customer_id);
            fd.append("product_id", item.product_id);
            fd.append("product_name", item.product_name || '');
            fd.append("qty", item.qty);
            fd.append("rate", item.rate);

            await fetch(BASE_URL + 'sales/add_to_cart', salesPostOptions(appendCsrf(fd)));
        }

        setTimeout(() => loadCart(customer_id), 600);

    } catch (e) {
        console.error("Edit mode init failed:", e);
        Swal.fire('Error', 'Failed to load invoice for editing', 'error');
    }
}


// Clear session cart for this customer before loading edit data
async function clearCustomerCart(customer_id) {
    if (!customer_id) return;

    try {
        const fd = new FormData();
        fd.append('customer_id', customer_id);

        const res = await fetch(BASE_URL + 'sales/clear_tab_cart', salesPostOptions(appendCsrf(fd)));
    } catch (e) {
        console.warn("Failed to clear cart", e);
    }
}

// ================= DATA LOADERS =================
async function loadCustomers() {
    const sel = document.getElementById("customer_id");
    if (!sel || sel.tagName !== 'SELECT') return;

    const res = await fetch(BASE_URL + "sales/search_customer?term=");
    const data = parseSalesListResponse(await res.json());
    
    const currentValue = sel.value;        // ← Save current selection (for edit mode)

    sel.innerHTML = "<option value=''>Select Customer</option>";
    
    data.forEach(c => {
        sel.innerHTML += `<option value="${c.id}">${c.mobile} - ${c.customer_name}</option>`;
    });

    // Restore the selected customer (critical for edit mode)
    if (currentValue) {
        sel.value = currentValue;
    }

    // Also trigger customer details panel for edit mode
    if (currentValue) {
        loadCustomerDetails(currentValue);
    }
}

async function loadBranch(showAll = false) {
    let url = BASE_URL + "sales/get_branch";
    if (showAll) {
        url += "?all=1";
    }

    const res = await fetch(url);
    const data = parseSalesListResponse(await res.json());
    
    const sel = document.getElementById("branch_id");
    if (!sel) return;

    const currentValue = sel.value;

    sel.innerHTML = "<option value=''>Select Branch</option>";

    data.forEach(b => {
        sel.innerHTML += `<option value="${b.id}">${b.branch_name}</option>`;
    });

    if (currentValue) {
        sel.value = currentValue;
    } else if (!showAll && window.SESSION_BRANCH_ID) {
        sel.value = String(window.SESSION_BRANCH_ID);
    }
}


async function loadEmployees() {
    const res = await fetch(BASE_URL + "sales/get_employees");
    const data = parseSalesListResponse(await res.json());

    ["sales_by", "sales_person"].forEach(id => {
        const sel = document.getElementById(id);
        if (!sel) return;
        const currentValue = sel.value;   // ← Save current value

        sel.innerHTML = "<option value=''>Select One</option>";
        data.forEach(e => {
            sel.innerHTML += `<option value="${e.id}">${e.name}</option>`;
        });

        // Restore selected value
        if (currentValue) {
            sel.value = currentValue;
        }
    });
}

async function loadCustomerDetails(customer_id) {
    const panel = document.getElementById("customerDetailsPanel");
    if (!customer_id) {
        if (panel) {
            panel.classList.add('d-none');
            panel.style.display = 'none';
        }
        return;
    }

    try {
        const res = await fetch(BASE_URL + `sales/customer_details?customer_id=${customer_id}`);
        
        if (!res.ok) {
            console.error("Server error:", res.status);
            return;
        }

        const data = await res.json();

        document.getElementById("disp_name").textContent = data.customer_name || "";
        document.getElementById("disp_shop").textContent = data.shop_name || "";
        document.getElementById("disp_address").textContent = data.address || "";
        document.getElementById("disp_mobile").textContent = data.mobile || "";
        document.getElementById("disp_limit").textContent = parseFloat(data.credit_limit || 0).toFixed(2);
        document.getElementById("disp_due").textContent = parseFloat(data.recent_due || 0).toFixed(2);
        
        const left = parseFloat(data.due_left || 0);
        const leftEl = document.getElementById("disp_left");
        leftEl.textContent = left.toFixed(2);
        leftEl.className = left < 0 ? "text-danger fw-bold" : "text-success fw-bold";
        
        if (panel) {
            panel.classList.remove('d-none');
            panel.style.display = 'block';
        }

    } catch (e) {
        console.error("Error loading customer details:", e);
        if (panel) {
            panel.classList.add('d-none');
            panel.style.display = 'none';
        }
    }
}

// ================= PRODUCT SEARCH + KEYBOARD =================
// ================= PRODUCT SEARCH (Unique products only) =================
function initProductSearch() {
    const productSearch = document.getElementById("productSearch");
    const suggestionsBox = document.getElementById("productSuggestions");

    productSearch.addEventListener("input", debounce(async function() {
        const term = this.value.trim();
        if (term.length < 2) { 
            suggestionsBox.style.display = "none"; 
            return; 
        }

        const branchId = getActiveSalesBranchId();
        const res = await fetch(BASE_URL + `sales/search_product?term=${encodeURIComponent(term)}&branch_id=${branchId}`);
        const data = parseSalesListResponse(await res.json());

        let html = "";
        data.forEach(p => {
            const stock = parseFloat(p.available_qty) || 0;
            const out = stock <= 0;
            const cls = out ? 'list-group-item disabled' : 'list-group-item list-group-item-action';
            html += `<a href="#" class="${cls}" data-product='${JSON.stringify(p)}' ${out ? 'aria-disabled="true"' : ''}>
                        ${p.product_name} (${p.product_code})
                        <span class="badge bg-${stock > 0 ? 'success' : 'danger'} float-end">${stock} avail</span>
                     </a>`;
        });
        suggestionsBox.innerHTML = html || "<div class='list-group-item text-muted'>No product found</div>";
        suggestionsBox.style.display = "block";
    }, 300));

    // Keyboard navigation
    productSearch.addEventListener("keydown", function(e) {
        if (suggestionsBox.style.display === "none") return;
        const items = suggestionsBox.querySelectorAll("a");
        if (!items.length) return;

        let active = suggestionsBox.querySelector(".active") || items[0];

        if (e.key === "ArrowDown") {
            e.preventDefault();
            let next = active.nextElementSibling || items[0];
            active.classList.remove("active");
            next.classList.add("active");
            next.scrollIntoView({block: "nearest"});
        }
        if (e.key === "ArrowUp") {
            e.preventDefault();
            let prev = active.previousElementSibling || items[items.length-1];
            active.classList.remove("active");
            prev.classList.add("active");
            prev.scrollIntoView({block: "nearest"});
        }
        if (e.key === "Enter") {
            e.preventDefault();
            if (active) selectProduct(active);
        }
    });

    document.addEventListener("click", function(e) {
        if (e.target.closest("#productSuggestions a")) {
            selectProduct(e.target.closest("a"));
        }
    });
}

function selectProduct(link) {
    const p = JSON.parse(link.dataset.product);
    const stock = parseFloat(p.available_qty) || 0;
    if (stock <= 0) {
        Swal.fire('Out of stock', 'This product has no available stock at the selected branch.', 'warning');
        return;
    }
    selectedProduct = p;
    document.getElementById("productSearch").value = selectedProduct.product_name;
    document.getElementById("recommandedprice").textContent = selectedProduct.price || 0;
    document.getElementById("sales_rate").value = selectedProduct.price || 0;
    const qtyEl = document.getElementById("quantity");
    if (qtyEl) qtyEl.value = 1;
    document.getElementById("productSuggestions").style.display = "none";
    showStockInfo(selectedProduct);
    document.getElementById("addToCartBtn")?.focus();
}


// ================= IMPROVED STOCK INFO MODAL =================
// ================= STOCK INFO + MODAL =================
async function showStockInfo(product) {
    const container = document.getElementById("BranchStock");
    if (!product) return container.innerHTML = "";

    const branchId = getActiveSalesBranchId();
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

    container.innerHTML = `
        <div class="mt-3 p-3 border rounded bg-light">
            <strong>Available <span class="text-muted small">(${branchName})</span>:</strong> 
            <span class="fs-4 fw-bold text-${stock > 0 ? 'success' : 'danger'}">${stock}</span> pcs<br>
            <button onclick="showWarehouseModal(${product.id})" class="btn btn-sm btn-primary mt-2">
                <i class="fas fa-warehouse"></i> View All Warehouses
            </button>
        </div>`;
}

async function showWarehouseModal(productId) {
    // Remove existing modal if any
    let existingModal = document.getElementById('stockModal');
    if (existingModal) existingModal.remove();

    let modalHTML = `
    <div class="modal fade" id="stockModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Warehouse Stock - Product ID ${productId}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Select Branch</label>
                        <select id="modalBranchSelect" class="form-select"></select>
                    </div>
                    <div id="modalStockTable" class="table-responsive">
                        <table class="table table-bordered">
                            <thead><tr><th>Warehouse</th><th>Physical Qty</th><th>Available Qty</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>`;

    document.body.insertAdjacentHTML('beforeend', modalHTML);

    const modal = new bootstrap.Modal(document.getElementById('stockModal'));
    modal.show();

    // Load branches
    const branchSelect = document.getElementById("modalBranchSelect");
    const res = await fetch(BASE_URL + "sales/get_branch?for_stock=1");
    const branches = parseSalesListResponse(await res.json());
    const sessionBranchId = String(window.SESSION_BRANCH_ID || '');

    branchSelect.innerHTML = branches.map(b => {
        const isHome = String(b.id) === sessionBranchId;
        const label = isHome ? `${b.branch_name} (your branch)` : b.branch_name;
        return `<option value="${b.id}">${label}</option>`;
    }).join('');

    const currentBranch = String(window.SESSION_BRANCH_ID || document.getElementById("branch_id")?.value || branches[0]?.id || '');
    branchSelect.value = currentBranch;

    // Load stock
    loadModalStock(productId, currentBranch);

    // Change event
    branchSelect.onchange = () => loadModalStock(productId, branchSelect.value);
}


// Load stock for selected branch in modal
async function loadModalStock(productId, branchId) {
    const tbody = document.querySelector('#stockModal tbody');
    tbody.innerHTML = '<tr><td colspan="3" class="text-center"><i class="fas fa-spinner fa-spin"></i> Loading...</td></tr>';

    try {
        const res = await fetch(`${BASE_URL}sales/get_warehouse_stock?product_id=${productId}&branch_id=${branchId}`);
        const data = parseSalesListResponse(await res.json());

        let rows = '';
        let totalPhysical = 0;
        let totalAvailable = 0;

        if (data.length > 0) {
            data.forEach(w => {
                const physical = parseFloat(w.physical_qty) || 0;
                const available = parseFloat(w.available_qty) || 0;

                totalPhysical += physical;
                totalAvailable += available;

                rows += `<tr>
                    <td>${w.warehouse_name}</td>
                    <td class="text-end">${physical.toFixed(2)}</td>
                    <td class="text-end fw-bold">${available.toFixed(2)}</td>
                </tr>`;
            });

            rows += `
                <tr class="table-info fw-bold">
                    <td><strong>TOTAL PHYSICAL</strong></td>
                    <td class="text-end">${totalPhysical.toFixed(2)}</td>
                    <td class="text-end"></td>
                </tr>
                <tr class="table-success fw-bold">
                    <td><strong>TOTAL AVAILABLE</strong></td>
                    <td class="text-end"></td>
                    <td class="text-end">${totalAvailable.toFixed(2)}</td>
                </tr>`;
        } else {
            rows = `<tr><td colspan="3" class="text-center text-muted">No warehouses found</td></tr>`;
        }

        tbody.innerHTML = rows;

    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="3" class="text-center text-danger">Failed to load stock</td></tr>`;
        console.error(e);
    }
}

// ================= ADD TO CART =================


function initAddToCart() {
    document.getElementById("addToCartBtn").addEventListener("click", async function() {
        const customer_id = document.getElementById("customer_id").value;
        if (!customer_id) return Swal.fire("Warning", "Please select a customer first", "warning");
        if (!selectedProduct) return Swal.fire("Warning", "Please select a product", "warning");

        const qty = parseFloat(document.getElementById("quantity").value) || 0;
        const rate = parseFloat(document.getElementById("sales_rate").value) || 0;

        if (qty <= 0) return Swal.fire("Warning", "Quantity must be greater than 0", "warning");

        const formData = new FormData();
        formData.append("customer_id", customer_id);
        formData.append("product_id", selectedProduct.id);
        formData.append("product_name", selectedProduct.product_name);
        formData.append("qty", qty);
        formData.append("rate", rate);

        const res = await fetch(BASE_URL + '/sales/add_to_cart', salesPostOptions(appendCsrf(formData)));
        
        const result = await res.json();

        if (result.status === "success") {
            Swal.fire({ icon: "success", title: "Added!", timer: 1200, showConfirmButton: false });
            
            // ← Important: In edit mode we don't create tabs
            if (isEditMode || !document.getElementById('draft-tabs')) {
                loadCart(customer_id);
                onCustomerSelected(customer_id);
            } else {
                createOrSwitchTab(customer_id);
            }

            // Reset form
            document.getElementById("productSearch").value = "";
            document.getElementById("quantity").value = 1;
            selectedProduct = null;
            document.getElementById("BranchStock").innerHTML = "";
        } else {
            Swal.fire({ icon: "error", title: "Error", text: result.message });
        }
    });
}

// ================= TAB SYSTEM =================
function initDraftSystem() {
    const customerSel = document.getElementById("customer_id");
    if (!customerSel) return;

    customerSel.addEventListener("change", function() {
        if (!this.value) return;
        if (document.getElementById('draft-tabs')) {
            createOrSwitchTab(this.value);
        } else {
            onCustomerSelected(this.value);
        }
    });

    if (!document.getElementById('draft-tabs')) {
        return;
    }

    document.addEventListener("click", function(e) {
        if (e.target.classList.contains("salesNav")) {
            const cid = e.target.dataset.customer_id;
            createOrSwitchTab(cid);   // ← Better to call this
        }

        if (e.target.classList.contains("close-tab-btn")) {
            closeTab(e.target.dataset.customer_id);
        }
    });
}


function createOrSwitchTab(customer_id) {
    if (!customer_id) return;

    if (!document.getElementById('draft-tabs')) {
        onCustomerSelected(customer_id);
        loadCart(customer_id);
        return;
    }

    const option = document.querySelector(`#customer_id option[value="${customer_id}"]`);
    const customerText = option ? option.textContent : "Customer";

    // Create tab + content if not exists
    if (!document.getElementById(`tab-${customer_id}`)) {
        const tabHtml = `
            <li class="nav-item">
                <div class="btn-group">
                    <a class="nav-link salesNav" id="tab-${customer_id}" 
                       data-customer_id="${customer_id}"
                       href="#cart-${customer_id}">${customerText}</a>
                    <button class="btn btn-sm btn-outline-danger close-tab-btn" 
                            data-customer_id="${customer_id}" title="Close tab">×</button>
                </div>
            </li>`;
        document.getElementById("draft-tabs").innerHTML += tabHtml;

        const contentHtml = `
            <div class="tab-pane fade" id="cart-${customer_id}">
                <div class="cart-container p-3" data-customer_id="${customer_id}">Loading cart...</div>
            </div>`;
        document.getElementById("draft-tab-content").innerHTML += contentHtml;
    }

    // Deactivate all tabs and panes
    document.querySelectorAll('.salesNav').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('show', 'active'));

    // Activate selected tab
    const activeTab = document.getElementById(`tab-${customer_id}`);
    if (activeTab) activeTab.classList.add('active');

    // Activate selected content pane
    const activePane = document.getElementById(`cart-${customer_id}`);
    if (activePane) {
        activePane.classList.add('show', 'active');
    }

    // Load cart data
    loadCart(customer_id);

    // Sync customer dropdown
    document.getElementById("customer_id").value = customer_id;
    loadCustomerDetails(customer_id);
}

async function loadCart(customer_id) {
    let container = document.getElementById('single-cart-area')
        || document.querySelector(`#cart-${customer_id} .cart-container`);

    if (!container) {
        console.error("Cart container not found for customer:", customer_id);
        return;
    }

    try {
        const formData = new FormData();
        formData.append('customer_id', customer_id);
        appendSalesCartContext(formData);

        const res = await fetch(BASE_URL + "sales/load_cart", salesPostOptions(appendCsrf(formData)));

        if (!res.ok) throw new Error("HTTP " + res.status);

        const data = await res.json();

        const items = data.items || [];
        const subtotal = parseFloat(data.subtotal || 0);
        let mobileHtml = '<div class="sales-cart-mobile p-2">';
        let desktopRows = '';

        if (items.length === 0) {
            mobileHtml += '<div class="text-center text-muted py-4">No items yet</div>';
            desktopRows = '<tr><td colspan="6" class="text-center text-muted">No items in cart yet</td></tr>';
        } else {
            items.forEach((item, i) => {
                const total = parseFloat(item.total || item.qty * item.rate);
                const qty = parseFloat(item.qty) || 0;
                const rate = parseFloat(item.rate) || 0;
                const minR = item.min_rate;
                const maxR = item.max_rate;
                const ratePill = typeof salesRatePillHtml === 'function'
                    ? salesRatePillHtml(rate, minR, maxR) : '';
                const rateMinAttr = minR != null ? ` min="${minR}"` : '';
                const rateMaxAttr = maxR != null ? ` max="${maxR}"` : '';
                desktopRows += `
                    <tr data-index="${i}">
                        <td>${i + 1}</td>
                        <td>${escapeHtml(item.product_name)}${ratePill}</td>
                        <td class="text-end"><input type="number" step="0.01" value="${qty}" class="form-control form-control-sm qty-input" style="width:80px;min-height:44px"></td>
                        <td class="text-end"><input type="number" step="0.01" value="${rate}" class="form-control form-control-sm rate-input" style="width:90px;min-height:44px"${rateMinAttr}${rateMaxAttr} data-min-rate="${minR ?? ''}" data-max-rate="${maxR ?? ''}"></td>
                        <td class="text-end total-col">${total.toFixed(2)}</td>
                        <td><button type="button" class="btn btn-sm btn-danger delete-item" data-index="${i}">Delete</button></td>
                    </tr>`;
                mobileHtml += `
                    <div class="sales-cart-line" data-index="${i}">
                        <div class="d-flex justify-content-between">
                            <div class="line-title">${escapeHtml(item.product_name)}${ratePill}</div>
                            <button type="button" class="btn btn-sm btn-outline-danger delete-item" data-index="${i}"><i class="fas fa-trash"></i></button>
                        </div>
                        <div class="line-meta">Line ৳${total.toFixed(2)}</div>
                        <div class="sales-mobile-rate">
                            <label class="small text-muted mb-0">Rate</label>
                            <input type="number" step="0.01" class="form-control form-control-sm rate-input-mobile" value="${rate}" data-index="${i}"${rateMinAttr}${rateMaxAttr} data-min-rate="${minR ?? ''}" data-max-rate="${maxR ?? ''}">
                        </div>
                        <div class="sales-qty-stepper mt-2">
                            <button type="button" class="btn btn-outline-secondary qty-step" data-delta="-1" data-index="${i}">−</button>
                            <span class="qty-display">${qty}</span>
                            <button type="button" class="btn btn-outline-secondary qty-step" data-delta="1" data-index="${i}">+</button>
                        </div>
                    </div>`;
            });
        }
        mobileHtml += '</div>';

        const html = `
            ${mobileHtml}
            <div class="sales-cart-desktop p-2">
                <table class="table table-bordered mb-0">
                    <thead class="table-dark"><tr><th>SI</th><th>Product</th><th class="text-end">Qty</th><th class="text-end">Rate</th><th class="text-end">Total</th><th>Action</th></tr></thead>
                    <tbody>${desktopRows}</tbody>
                </table>
            </div>
            <div class="p-3 bg-light border-top">
                <div class="row g-2">
                    <div class="col-6"><label class="small">Sub Total</label><div class="fw-bold subtotal">${subtotal.toFixed(2)}</div></div>
                    <div class="col-6"><label class="small">Payable</label><div class="fw-bold text-success invoice_payable">0.00</div></div>
                    <div class="col-6"><label class="small">Transport</label><input type="number" step="0.01" class="form-control transport_cost" value="0"></div>
                    <div class="col-6"><label class="small">Discount</label><input type="number" step="0.01" class="form-control discount" value="0"></div>
                    <div class="col-12 d-none d-md-block">
                        <button type="button" class="btn ${isEditMode ? 'btn-primary' : 'btn-success'} btn-lg w-100 finalSubmitBtn" data-customer_id="${customer_id}">${isEditMode ? 'Update Invoice' : 'Finalize Invoice'}</button>
                    </div>
                </div>
            </div>`;

        container.innerHTML = html;
        updatePosStickyBar(items.length, subtotal);
        applyCartValidationUi(data.validation, customer_id);
        saveCartDraftBackup(customer_id, items);
        initCartSwipeRemove(customer_id, container);

        // Activate the tab properly
        const tabPane = document.getElementById(`cart-${customer_id}`);
        if (tabPane) {
            tabPane.classList.add('show', 'active');
        }

        setTimeout(() => {
            attachCartListeners(customer_id);
        }, 50);

    } catch (e) {
        console.error("Error loading cart:", e);
        container.innerHTML = `<p class="text-danger p-3">Failed to load cart. Check console.</p>`;
    }
}



function attachCartListeners(customer_id) {
    let container = document.getElementById('single-cart-area')
        || document.querySelector(`#cart-${customer_id} .cart-container`);

    if (!container) {
        console.error("Cart container not found for attach listeners");
        return;
    }

    // Delete buttons
    container.querySelectorAll('.delete-item').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm("Delete this item?")) return;
            const fd = new FormData();
            fd.append('customer_id', customer_id);
            fd.append('index', btn.dataset.index);
            await fetch(BASE_URL + '/sales/delete_from_cart', salesPostOptions(appendCsrf(fd)));
            loadCart(customer_id);
        });
    });

    // Qty / Rate live update — server is authoritative; revert UI on failure
    async function persistCartLine(row, qty, rate) {
        const index = row.dataset.index;
        const rateInput = row.querySelector('.rate-input');
        const minR = rateInput?.dataset.minRate;
        const maxR = rateInput?.dataset.maxRate;

        const rateCheck = salesValidateRateClient(rate, minR, maxR);
        if (!rateCheck.valid) {
            Swal.fire({ icon: 'error', title: 'Invalid rate', text: rateCheck.message });
            loadCart(customer_id);
            return false;
        }

        if (qty <= 0 || rate <= 0) {
            Swal.fire({ icon: 'error', title: 'Invalid line', text: 'Qty and rate must be greater than zero.' });
            loadCart(customer_id);
            return false;
        }

        const fd = appendSalesCartContext(new FormData());
        fd.append('customer_id', customer_id);
        fd.append('index', index);
        fd.append('qty', qty);
        fd.append('rate', rate);

        const res = await fetch(BASE_URL + 'sales/update_cart_item', salesPostOptions(appendCsrf(fd)));
        const result = await res.json();
        if (result.status !== 'success') {
            showCartValidationError(result);
            loadCart(customer_id);
            return false;
        }
        return true;
    }

    container.querySelectorAll('.qty-input, .rate-input').forEach(input => {
        input.addEventListener('change', async function() {
            const row = this.closest('tr');
            if (!row) return;
            const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
            const rate = parseFloat(row.querySelector('.rate-input').value) || 0;
            const ok = await persistCartLine(row, qty, rate);
            if (ok) loadCart(customer_id);
        });
    });

    container.querySelectorAll('.rate-input-mobile').forEach(input => {
        input.addEventListener('change', async function() {
            const index = this.dataset.index;
            const row = container.querySelector(`tr[data-index="${index}"]`);
            const qty = parseFloat(row?.querySelector('.qty-input')?.value)
                || parseFloat(container.querySelector(`.sales-cart-line[data-index="${index}"] .qty-display`)?.textContent)
                || 0;
            const rate = parseFloat(this.value) || 0;

            const rateCheck = salesValidateRateClient(rate, this.dataset.minRate, this.dataset.maxRate);
            if (!rateCheck.valid) {
                Swal.fire({ icon: 'error', title: 'Invalid rate', text: rateCheck.message });
                loadCart(customer_id);
                return;
            }

            const fd = appendSalesCartContext(new FormData());
            fd.append('customer_id', customer_id);
            fd.append('index', index);
            fd.append('qty', qty);
            fd.append('rate', rate);

            const res = await fetch(BASE_URL + 'sales/update_cart_item', salesPostOptions(appendCsrf(fd)));
            const result = await res.json();
            if (result.status !== 'success') {
                showCartValidationError(result);
            }
            loadCart(customer_id);
        });
    });

    // Live calculation (Transport + Discount)
    function calculateInvoicePayable() {
        const subtotalEl = container.querySelector('.subtotal');
        const transportEl = container.querySelector('.transport_cost');
        const discountEl = container.querySelector('.discount');
        const payableEl = container.querySelector('.invoice_payable');

        if (!subtotalEl || !payableEl) return;

        const subtotal = parseFloat(subtotalEl.textContent) || 0;
        const transport = parseFloat(transportEl ? transportEl.value : 0) || 0;
        const discount = parseFloat(discountEl ? discountEl.value : 0) || 0;

        const payable = (subtotal + transport - discount).toFixed(2);
        payableEl.textContent = payable;
        const count = container.querySelectorAll('.sales-cart-line').length
            || container.querySelectorAll('tbody tr[data-index]').length;
        updatePosStickyBar(count, subtotal);
    }

    const transportInput = container.querySelector('.transport_cost');
    const discountInput = container.querySelector('.discount');
    if (transportInput) transportInput.addEventListener('input', calculateInvoicePayable);
    if (discountInput) discountInput.addEventListener('input', calculateInvoicePayable);

    container.querySelectorAll('.qty-step').forEach(btn => {
        btn.addEventListener('click', async () => {
            const index = btn.dataset.index;
            const row = container.querySelector(`tr[data-index="${index}"]`);
            if (!row) return;
            const qtyInput = row.querySelector('.qty-input');
            if (!qtyInput) return;
            const delta = parseFloat(btn.dataset.delta) || 0;
            const newQty = Math.max(0.01, (parseFloat(qtyInput.value) || 0) + delta);
            const rate = parseFloat(row.querySelector('.rate-input')?.value) || 0;
            const ok = await persistCartLine(row, newQty, rate);
            if (ok) loadCart(customer_id);
        });
    });

    setTimeout(calculateInvoicePayable, 100);

    // Final Submit Button
    const submitBtn = container.querySelector('.finalSubmitBtn');
    if (submitBtn) {
        submitBtn.addEventListener('click', () => submitFinalInvoice(customer_id));
    }
}



// ================= FINAL SUBMIT (Works for BOTH Create & Edit) =================

async function submitFinalInvoice(customer_id) {
    customer_id = customer_id || document.getElementById('customer_id')?.value;
    let container = document.getElementById('single-cart-area')
        || document.querySelector(`#cart-${customer_id} .cart-container`);

    if (!container) {
        return Swal.fire("Error", "Cart container not found", "error");
    }

    // ================= SOLID VALIDATION =================
    let errors = [];

    if (!document.getElementById("customer_id").value) errors.push("Customer is required");
    const branchVal = document.getElementById("branch_id")?.value
        || document.getElementById("branch_id_locked")?.value;
    if (!branchVal) errors.push("Warehouse Branch is required");
    if (!document.getElementById("sales_by").value) errors.push("Sales By is required");
    if (!document.getElementById("sales_person").value) errors.push("Sales Person is required");
    if (!document.getElementById("invoice_date").value) errors.push("Date is required");

    if (errors.length > 0) {
        Swal.fire({
            icon: 'warning',
            title: 'Missing Information',
            html: errors.join('<br>'),
            confirmButtonColor: '#d33'
        });
        return;
    }

    const transport = parseFloat(container.querySelector('.transport_cost').value) || 0;
    const discount  = parseFloat(container.querySelector('.discount').value) || 0;

    // Hard gate — server validates session cart (price range + stock)
    const validateFd = appendSalesCartContext(new FormData());
    validateFd.append('customer_id', customer_id);
    try {
        const vRes = await fetch(BASE_URL + 'sales/validate_cart', salesPostOptions(appendCsrf(validateFd)));
        const vData = await vRes.json();
        if (!vData.valid) {
            showCartValidationError(vData);
            loadCart(customer_id);
            return;
        }
    } catch (e) {
        Swal.fire('Error', 'Could not validate cart. Try again.', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('customer_id', customer_id);
    formData.append('branch_id', document.getElementById("branch_id")?.value
        || document.getElementById("branch_id_locked")?.value);
    formData.append('sales_by', document.getElementById("sales_by").value);
    formData.append('sales_person', document.getElementById("sales_person").value);
    formData.append('invoice_date', document.getElementById("invoice_date").value);
    formData.append('narration', document.getElementById('narration').value || '');

    formData.append('transport_cost', transport);
    formData.append('discount', discount);

    const editing = isEditMode || document.getElementById('edit_mode')?.value === '1';
    const invoiceId = currentInvoiceId || document.getElementById('invoice_id')?.value;

    let url = BASE_URL + 'sales/final_sales';
    if (editing && invoiceId) {
        url = BASE_URL + `sales/update/${invoiceId}`;
    }

    try {
        const res = await fetch(url, salesPostOptions(appendCsrf(formData)));
        const rawText = await res.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (parseErr) {
            console.error('Non-JSON response from server:', rawText);
            Swal.fire({
                icon: 'error',
                title: 'Server Error',
                html: 'The server returned an invalid response. Check PHP error logs or console.<br><small class="text-muted">Often caused by a PHP warning before JSON output.</small>'
            });
            return;
        }

        if (data.status === 'success') {
            showFinalizeSuccess(data);
        }
        else if (data.status === 'credit_limit_exceeded' && data.requires_override) {
            // Hard block with override option
            const cc = data.credit_check || {};
            const excess = parseFloat(cc.excess_amount || 0).toFixed(2);

            Swal.fire({
                title: 'Credit Limit Exceeded',
                html: `
                    <div class="text-start">
                        <p><strong>This sale would exceed the customer's credit limit by <span class="text-danger">৳${excess}</span>.</strong></p>
                        <ul class="small text-muted mb-3">
                            <li>Current Due: ৳${parseFloat(cc.current_due || 0).toFixed(2)}</li>
                            <li>Credit Limit: ৳${parseFloat(cc.credit_limit || 0).toFixed(2)}</li>
                            <li>Projected Due: ৳${parseFloat(cc.projected_due || 0).toFixed(2)}</li>
                        </ul>
                        <div class="mb-2">
                            <label class="form-label small fw-bold">Reason for Override (required, min 10 chars)</label>
                            <textarea id="swal-override-reason" class="form-control" rows="3" placeholder="Explain why this customer is being allowed over their credit limit..."></textarea>
                        </div>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Override & Proceed',
                confirmButtonColor: '#d33',
                cancelButtonText: 'Cancel Sale',
                preConfirm: () => {
                    const reason = document.getElementById('swal-override-reason').value.trim();
                    if (reason.length < 10) {
                        Swal.showValidationMessage('Please provide a detailed reason (at least 10 characters)');
                        return false;
                    }
                    return reason;
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    // Resubmit with override
                    formData.append('credit_limit_override', '1');
                    formData.append('override_reason', result.value);

                    // Re-send the request
                    fetch(url, salesPostOptions(appendCsrf(formData)))
                        .then(async (r) => {
                            const t = await r.text();
                            try { return JSON.parse(t); } catch { throw new Error(t); }
                        })
                        .then(newData => {
                            if (newData.status === 'success') {
                                showFinalizeSuccess(newData);
                            } else {
                                Swal.fire('Error', newData.message || 'Override failed', 'error');
                            }
                        })
                        .catch(err => Swal.fire('Error', String(err.message || err).slice(0, 500), 'error'));
                }
            });
        } 
        else {
            showCartValidationError(data);
        }
    } catch (e) {
        console.error("Final Submit Error:", e);
        Swal.fire('Error', e.message || 'Failed to submit invoice. Check your connection and try again.', 'error');
    }
}


// Populate all form fields (Branch, Sales By, Person, Date, Narration)
async function populateEditFormFields(data) {
    // Branch
    const branchSel = document.getElementById("branch_id");
    if (data.branch_id) {
        branchSel.value = data.branch_id;
    }

    // Sales By & Sales Person
    const salesBySel = document.getElementById("sales_by");
    const salesPersonSel = document.getElementById("sales_person");

    if (data.salesman_id) salesBySel.value = data.salesman_id;
    if (data.sales_person) salesPersonSel.value = data.sales_person;

    // Date & Narration
    if (data.invoice_date) {
        document.getElementById("invoice_date").value = data.invoice_date;
    }
    if (data.narration) {
        document.getElementById("narration").value = data.narration;
    }
}



async function closeTab(customer_id) {
    if (!confirm("Close this tab and clear all items?")) return;

    const fd = new FormData();
    fd.append('customer_id', customer_id);
    await fetch(BASE_URL + '/sales/clear_tab_cart', salesPostOptions(appendCsrf(fd)));

    document.getElementById(`tab-${customer_id}`).closest('li').remove();
    document.getElementById(`cart-${customer_id}`).remove();

    document.getElementById("customer_id").value = "";
}


// ================= EDIT MODE INITIALIZATION =================
async function initEditMode() {
    try {
        const res = await fetch(BASE_URL + `sales/get_invoice_for_edit/${currentInvoiceId}`);
        const data = await res.json();

        if (data.status !== 'success') {
            Swal.fire('Error', data.message || 'Cannot edit this invoice', 'error');
            location.href = BASE_URL + 'sales/today';
            return;
        }

        // Set customer
        document.getElementById("customer_id").value = data.customer_id;
        await loadCustomerDetails(data.customer_id);

        // Load existing items into cart (single cart, no tabs)
        loadExistingItemsIntoCart(data.items);

    } catch (e) {
        console.error("Edit mode init error:", e);
        Swal.fire('Error', 'Failed to load invoice for editing', 'error');
    }
}


function loadExistingItemsIntoCart(items) {
    const customer_id = document.getElementById("customer_id").value;
    if (!customer_id) return;

    items.forEach(item => {
        const formData = new FormData();
        formData.append("action", "add_to_cart");
        formData.append("customer_id", customer_id);
        formData.append("product_id", item.product_id);
        formData.append("product_name", item.product_name);
        formData.append("qty", item.qty);
        formData.append("rate", item.rate);

        fetch(BASE_URL + 'sales/add_to_cart', salesPostOptions(appendCsrf(formData)));
    });

    // Load cart after a short delay
    setTimeout(() => {
        loadCart(customer_id);
    }, 800);
}



function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[c]);
}

function onCustomerSelected(customerId) {
    loadCustomerDetails(customerId);
    if (customerId) {
        rememberCustomerRecent(customerId);
        renderCustomerRecents();
    }
}

function rememberCustomerRecent(customerId) {
    if (!customerId) return;
    const sel = document.getElementById('customer_id');
    const search = document.getElementById('customerSearch');
    let label = `Customer #${customerId}`;
    if (search?.value && !search.readOnly) {
        label = search.value;
    } else if (search?.readOnly && search.value) {
        label = search.value;
    } else if (sel?.tagName === 'SELECT' && sel.selectedIndex >= 0) {
        label = sel.options[sel.selectedIndex]?.text || label;
    }
    let recents = [];
    try { recents = JSON.parse(localStorage.getItem(CUSTOMER_RECENTS_KEY) || '[]'); } catch (e) { recents = []; }
    recents = recents.filter(r => String(r.id) !== String(customerId));
    recents.unshift({ id: customerId, label });
    localStorage.setItem(CUSTOMER_RECENTS_KEY, JSON.stringify(recents.slice(0, 5)));
}

function renderCustomerRecents() {
    const box = document.getElementById('customerRecents');
    if (!box) return;
    let recents = [];
    try { recents = JSON.parse(localStorage.getItem(CUSTOMER_RECENTS_KEY) || '[]'); } catch (e) { recents = []; }
    if (!recents.length) {
        box.classList.add('d-none');
        box.innerHTML = '';
        return;
    }
    box.classList.remove('d-none');
    box.innerHTML = recents.map(r =>
        `<button type="button" class="btn btn-outline-primary btn-sm" data-id="${r.id}">${escapeHtml(r.label)}</button>`
    ).join('');
    box.querySelectorAll('button').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id;
            if (document.getElementById('sales-create-app') && typeof window.salesCreateSelectCustomer === 'function') {
                window.salesCreateSelectCustomer(parseInt(id, 10));
                return;
            }
            const sel = document.getElementById('customer_id');
            if (sel) {
                sel.value = id;
                onCustomerSelected(id);
                loadCart(id);
            }
        });
    });
}

function initPosStickyBar() {
    document.getElementById('posStickyFinalize')?.addEventListener('click', () => {
        const cid = document.getElementById('customer_id')?.value;
        if (cid) submitFinalInvoice(cid);
    });
}

function updatePosStickyBar(itemCount, subtotal) {
    const bar = document.getElementById('posStickyBar');
    const summary = document.getElementById('posStickySummary');
    const btn = document.getElementById('posStickyFinalize');
    if (!bar || !summary) return;
    const transport = parseFloat(document.querySelector('.transport_cost')?.value || 0);
    const discount = parseFloat(document.querySelector('.discount')?.value || 0);
    const grand = subtotal + transport - discount;
    if (itemCount > 0) {
        bar.classList.add('visible');
        summary.textContent = `${itemCount} item(s) · ৳${grand.toFixed(2)}`;
        if (btn && !btn.title) btn.disabled = false;
    } else {
        bar.classList.remove('visible');
        summary.textContent = '0 items · ৳0';
        if (btn) btn.disabled = true;
    }
}

function saveCartDraftBackup(customerId, items) {
    if (!customerId) return;
    try {
        localStorage.setItem(CART_DRAFT_KEY, JSON.stringify({ customerId, items, savedAt: Date.now() }));
    } catch (e) { /* quota */ }
}

async function restoreCartDraftIfNeeded() {
    if (document.getElementById('edit_mode')?.value === '1') return;
    let draft;
    try { draft = JSON.parse(localStorage.getItem(CART_DRAFT_KEY) || 'null'); } catch (e) { return; }
    if (!draft?.customerId || !draft.items?.length) return;
    const sel = document.getElementById('customer_id');
    if (!sel?.value && Date.now() - (draft.savedAt || 0) < 86400000) {
        const result = await Swal.fire({
            title: 'Restore cart?',
            text: 'Unsaved cart from your last session.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Restore'
        });
        if (!result.isConfirmed) {
            localStorage.removeItem(CART_DRAFT_KEY);
            return;
        }
        sel.value = draft.customerId;
        onCustomerSelected(draft.customerId);
        for (const item of draft.items) {
            const fd = new FormData();
            fd.append('customer_id', draft.customerId);
            fd.append('product_id', item.product_id);
            fd.append('product_name', item.product_name);
            fd.append('qty', item.qty);
            fd.append('rate', item.rate);
            await fetch(BASE_URL + 'sales/add_to_cart', salesPostOptions(appendCsrf(fd)));
        }
        loadCart(draft.customerId);
    }
}

function initCartSwipeRemove(customer_id, container) {
    container.querySelectorAll('.sales-cart-line').forEach(line => {
        let startX = 0;
        line.addEventListener('touchstart', e => { startX = e.touches[0].clientX; }, { passive: true });
        line.addEventListener('touchend', e => {
            if (e.changedTouches[0].clientX - startX < -80) {
                const idx = line.dataset.index;
                const btn = container.querySelector(`.delete-item[data-index="${idx}"]`);
                if (btn) btn.click();
            }
        }, { passive: true });
    });
}

function showFinalizeSuccess(data) {
    localStorage.removeItem(CART_DRAFT_KEY);
    const editing = isEditMode || document.getElementById('sales-edit-app');
    Swal.fire({
        icon: 'success',
        title: editing ? 'Invoice updated' : 'Invoice saved',
        text: data.message || (editing ? 'Draft saved successfully.' : 'Success'),
        showCancelButton: true,
        confirmButtonText: editing ? 'Back to today' : 'New invoice',
        cancelButtonText: editing ? 'Stay on page' : "Today's list"
    }).then((r) => {
        if (editing) {
            if (r.isConfirmed) location.href = BASE_URL + 'sales/today';
            return;
        }
        if (r.isConfirmed) location.href = BASE_URL + 'sales/create';
        else if (r.dismiss === Swal.DismissReason.cancel) location.href = BASE_URL + 'sales/today';
    });
}

function debounce(fn, ms) {
    let timer;
    return function(...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), ms);
    };
}
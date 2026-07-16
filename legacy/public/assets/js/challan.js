/**
 * Challan create — godown prepare, finalize, reverse.
 */
(function () {
    'use strict';

    let BASE_URL = '';
    let CHALLAN_BASE = '';

    /** JSON list endpoints return { status, data: [...] } after ApiResponse normalize. */
    function parseListResponse(json) {
        if (json == null) return [];
        if (Array.isArray(json)) return json;
        if (json.status === 'error') {
            console.warn(json.message || 'Challan API error');
            return [];
        }
        if (Array.isArray(json.data)) return json.data;
        if (typeof json === 'object') {
            const keys = Object.keys(json).filter((k) => /^\d+$/.test(k));
            if (keys.length) {
                return keys.sort((a, b) => Number(a) - Number(b)).map((k) => json[k]);
            }
        }
        return [];
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.getElementById('challan-create-app')) return;

        const boot = window.CHALLAN_CREATE_BOOT || {};
        BASE_URL = boot.baseUrl || document.getElementById('base_url')?.value || '/';
        if (!BASE_URL.endsWith('/')) BASE_URL += '/';
        CHALLAN_BASE = BASE_URL + 'challan/';

        initGodownForm();
    });

    function initGodownForm() {
        const form = document.getElementById('godownForm');
        if (!form) return;

        const boot = window.CHALLAN_CREATE_BOOT || {};
        const branchId = boot.sessionBranchId || document.getElementById('invoice_branch_id')?.value;
        const invoiceId = document.getElementById('invoice_id')?.value;
        const isCompleted = boot.isCompleted === true;
        const lockGodown = boot.lockGodownAssignments === true;

        if (!lockGodown && !isCompleted) {
            loadWarehousesForAllRows(branchId, invoiceId).then(() => {
                initBulkWarehouseTools();
                updateWarehouseProgress();
            });
            loadDispatchers();
            bindWarehouseStockUpdates();
        } else if (!isCompleted) {
            initFillCtnButton();
        }

        initTransportTotalPreview();
        initKeyboardShortcuts(form, invoiceId);

        const btnGodown = document.getElementById('btn-save-godown');
        const btnChallan = document.getElementById('btn-create-challan');
        if (btnGodown) btnGodown.addEventListener('click', () => handlePrepareGodown(form, invoiceId));
        if (btnChallan) btnChallan.addEventListener('click', () => handleFinalizeChallan(form, invoiceId));

        const btnReverse = document.getElementById('btn-reverse-challan');
        if (btnReverse) btnReverse.addEventListener('click', () => handleReverseChallan(form, invoiceId));
    }

    async function loadWarehousesForAllRows(branchId, invoiceId) {
        const rows = document.querySelectorAll('#godownItemsTable tbody tr');
        await Promise.all(Array.from(rows).map(row => populateWarehouseSelect(row, branchId, invoiceId)));
    }

    async function populateWarehouseSelect(row, branchId, invoiceId) {
        const select = row.querySelector('.warehouse-select');
        if (!select) return;

        const productId = row.dataset.product_id;
        const selectedWarehouseId = row.dataset.warehouse_id || '';

        try {
            const invQ = invoiceId ? `&invoice_id=${encodeURIComponent(invoiceId)}` : '';
            const res = await fetch(
                `${CHALLAN_BASE}get_warehouses_for_product?product_id=${productId}${invQ}`
            );
            const data = parseListResponse(await res.json());

            select.innerHTML = '<option value="">Select warehouse</option>';
            if (!data.length) {
                const empty = document.createElement('option');
                empty.value = '';
                empty.textContent = 'No warehouse with stock for this branch';
                empty.disabled = true;
                select.appendChild(empty);
                return;
            }
            data.forEach(w => {
                const opt = document.createElement('option');
                opt.value = w.id;
                opt.textContent = w.warehouse_name;
                opt.dataset.available = String(w.available_qty ?? 0);
                opt.dataset.physical = String(w.physical_stock ?? 0);
                if (String(w.id) === String(selectedWarehouseId)) {
                    opt.selected = true;
                }
                select.appendChild(opt);
            });

            if (!select.value && data.length === 1) {
                select.value = String(data[0].id);
            }

            updateStockBadge(row);
            refreshBulkWarehouseOptions();
        } catch (e) {
            console.error(e);
        }
    }

    function refreshBulkWarehouseOptions() {
        const bulk = document.getElementById('chBulkWarehouse');
        if (!bulk) return;
        const seen = new Map();
        document.querySelectorAll('.warehouse-select').forEach((sel) => {
            Array.from(sel.options).forEach((opt) => {
                if (!opt.value || seen.has(opt.value)) return;
                seen.set(opt.value, opt.textContent);
            });
        });
        const current = bulk.value;
        bulk.innerHTML = '<option value="">— Choose warehouse —</option>';
        seen.forEach((label, id) => {
            const opt = document.createElement('option');
            opt.value = id;
            opt.textContent = label;
            bulk.appendChild(opt);
        });
        if (current && seen.has(current)) bulk.value = current;
    }

    function initBulkWarehouseTools() {
        const applyBtn = document.getElementById('chApplyBulkWarehouse');
        const bulkSelect = document.getElementById('chBulkWarehouse');
        if (applyBtn && bulkSelect) {
            applyBtn.addEventListener('click', () => {
                const wid = bulkSelect.value;
                if (!wid) {
                    Swal.fire('Select warehouse', 'Choose a warehouse to apply to all lines.', 'info');
                    return;
                }
                document.querySelectorAll('#godownItemsTable tbody tr').forEach((row) => {
                    const sel = row.querySelector('.warehouse-select');
                    if (!sel) return;
                    sel.value = wid;
                    updateStockBadge(row);
                });
                updateWarehouseProgress();
            });
        }
        initFillCtnButton();
    }

    function initFillCtnButton() {
        const btn = document.getElementById('chFillAllCtn');
        if (!btn || btn.dataset.bound === '1') return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', () => {
            document.querySelectorAll('#godownItemsTable tbody tr').forEach((row) => {
                fillRowCtnFromOrder(row);
            });
        });
    }

    function fillRowCtnFromOrder(row) {
        const pcs = parseFloat(row.dataset.pcsPerCarton) || 0;
        const ordered = parseFloat(row.dataset.orderedQty) || 0;
        const ctnInput = row.querySelector('.dispatched-ctn');
        if (!ctnInput || pcs <= 0) return;
        const ctn = ordered / pcs;
        ctnInput.value = ctn.toFixed(2).replace(/\.?0+$/, '') || '0';
    }

    function updateWarehouseProgress() {
        const bar = document.getElementById('chAssignProgressBar');
        const label = document.getElementById('chAssignProgressLabel');
        const rows = document.querySelectorAll('#godownItemsTable tbody tr');
        if (!rows.length) return;
        let set = 0;
        rows.forEach((row) => {
            const sel = row.querySelector('.warehouse-select');
            const hidden = row.querySelector('input[name="warehouse_id[]"]');
            if ((sel && sel.value) || (hidden && hidden.value)) set += 1;
        });
        const pct = Math.round((set / rows.length) * 100);
        if (bar) bar.style.width = pct + '%';
        if (label) label.textContent = `${set} / ${rows.length} warehouses set`;
    }

    function initKeyboardShortcuts(form, invoiceId) {
        document.addEventListener('keydown', (e) => {
            if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 's') return;
            if (!document.getElementById('challan-create-app')) return;
            const boot = window.CHALLAN_CREATE_BOOT || {};
            if (boot.isCompleted) return;
            e.preventDefault();
            const btn = document.getElementById('btn-save-godown');
            if (btn && !btn.disabled) btn.click();
        });
    }

    function bindWarehouseStockUpdates() {
        document.querySelectorAll('.warehouse-select').forEach(select => {
            select.addEventListener('change', function () {
                updateStockBadge(this.closest('tr'));
                updateWarehouseProgress();
            });
        });
    }

    function updateStockBadge(row) {
        if (!row) return;
        const badge = row.querySelector('[data-role="stock-badge"]');
        const select = row.querySelector('.warehouse-select');
        if (!badge || !select) return;

        const opt = select.options[select.selectedIndex];
        if (!opt || !opt.value) {
            badge.textContent = '—';
            badge.className = 'challan-stock-badge';
            return;
        }

        const avail = parseFloat(opt.dataset.available) || 0;
        const phys = parseFloat(opt.dataset.physical) || 0;
        badge.textContent = avail.toFixed(2);
        badge.title = phys > 0
            ? `Available ${avail.toFixed(2)} (physical ${phys.toFixed(2)} − pipeline)`
            : 'Available for dispatch';
        badge.className = 'challan-stock-badge ' + (
            avail <= 0 ? 'is-none' : (avail < parseFloat(row.dataset.orderedQty || 0) ? 'is-low' : 'is-ok')
        );
    }

    function getDemandQty(row) {
        return parseFloat(row.dataset.orderedQty) || parseFloat(row.querySelector('.dispatched-qty')?.value) || 0;
    }

    function initTransportTotalPreview() {
        const input = document.getElementById('transport_cost');
        if (!input || input.readOnly) return;
        input.addEventListener('input', updateInvoiceTotalPreview);
        updateInvoiceTotalPreview();
    }

    function updateInvoiceTotalPreview() {
        const boot = window.CHALLAN_CREATE_BOOT || {};
        const transport = parseFloat(document.getElementById('transport_cost')?.value) || 0;
        const subtotal = parseFloat(boot.subtotal) || 0;
        const discount = parseFloat(boot.discount) || 0;
        const total = Math.max(0, subtotal + transport - discount);
        const el = document.getElementById('challan-invoice-total-display');
        if (el) {
            el.textContent = 'Tk ' + total.toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            });
        }
    }

    function loadDispatchers() {
        const dispatcherSelect = document.getElementById('dispatcher_id');
        if (!dispatcherSelect) return;

        const preSelected = dispatcherSelect.dataset.selected
            ? JSON.parse(dispatcherSelect.dataset.selected)
            : [];

        fetch(CHALLAN_BASE + 'get_dispatchers')
            .then(res => res.json())
            .then(json => {
                const data = parseListResponse(json);
                dispatcherSelect.innerHTML = '';
                if (!data.length) {
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = 'No dispatchers found — add employee with role dispatcher';
                    opt.disabled = true;
                    dispatcherSelect.appendChild(opt);
                    return;
                }
                data.forEach(emp => {
                    const opt = document.createElement('option');
                    opt.value = emp.id;
                    opt.textContent = emp.name;
                    if (preSelected.includes(parseInt(emp.id, 10))) {
                        opt.selected = true;
                    }
                    dispatcherSelect.appendChild(opt);
                });

                if (typeof $ !== 'undefined' && $.fn.select2) {
                    const $sel = $(dispatcherSelect);
                    if ($sel.data('select2')) $sel.select2('destroy');
                    $sel.select2({
                        placeholder: 'Select dispatcher(s)',
                        multiple: true,
                        width: '100%',
                    });
                }
            })
            .catch(e => console.error(e));
    }

    function getSelectedWarehouseAvailable(select) {
        const opt = select?.options[select.selectedIndex];
        return parseFloat(opt?.dataset?.available) || 0;
    }

    async function handleReverseChallan(form, invoiceId) {
        const { value: reason } = await Swal.fire({
            title: 'Reverse challan?',
            input: 'textarea',
            inputLabel: 'Reversal reason (required)',
            inputPlaceholder: 'Why is this challan being reversed?',
            inputAttributes: { minlength: 5 },
            showCancelButton: true,
            icon: 'warning',
        });

        if (!reason || reason.trim().length < 5) {
            if (reason !== undefined) {
                Swal.fire('Required', 'Please enter a reason (min 5 characters).', 'warning');
            }
            return;
        }

        const formData = new FormData(form);
        formData.set('reason', reason.trim());

        Swal.fire({ title: 'Reversing…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        try {
            const res = await fetch(CHALLAN_BASE + 'reverse_challan', { method: 'POST', body: formData });
            const result = await res.json();
            if (result.status === 'success') {
                Swal.fire('Reversed', result.message, 'success').then(() => window.location.reload());
            } else {
                Swal.fire('Error', result.message || 'Reversal failed', 'error');
            }
        } catch (e) {
            Swal.fire('Error', 'Network error', 'error');
        }
    }

    async function handlePrepareGodown(form, invoiceId) {
        if (!validatePrepare()) return;

        const lockGodown = (window.CHALLAN_CREATE_BOOT || {}).lockGodownAssignments === true;
        const confirm = await Swal.fire({
            title: lockGodown ? 'Update carton (CTN)?' : 'Save godown setup?',
            text: lockGodown
                ? 'Warehouse assignments stay locked. Transport cost and CTN packing numbers will be saved.'
                : 'Warehouse, transport, and dispatch setup will be saved. You can print a blank godown copy after.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Save',
        });
        if (!confirm.isConfirmed) return;

        Swal.fire({
            title: 'Processing…',
            text: 'Saving godown setup',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
        });

        try {
            const formData = new FormData(form);
            syncSelect2ToForm(formData);
            const res = await fetch(CHALLAN_BASE + 'prepare_godown', { method: 'POST', body: formData });
            const result = await res.json();

            if (result.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: 'Godown saved',
                    text: result.message,
                    showCancelButton: true,
                    confirmButtonText: 'Print blank godown',
                    cancelButtonText: 'Stay on page',
                }).then(r => {
                    if (r.isConfirmed) {
                        window.open(BASE_URL + 'challan/print_blank_godown_copy/' + invoiceId, '_blank');
                    }
                    window.location.reload();
                });
            } else {
                Swal.fire('Error', result.message, 'error');
            }
        } catch (e) {
            Swal.fire('Error', 'Network error', 'error');
        }
    }

    function validatePrepare() {
        const boot = window.CHALLAN_CREATE_BOOT || {};
        const lockGodown = boot.lockGodownAssignments === true;
        const errors = [];

        document.querySelectorAll('#godownItemsTable tbody tr').forEach(row => {
            const warehouseSelect = row.querySelector('.warehouse-select');
            const warehouseHidden = row.querySelector('input[name="warehouse_id[]"]');
            const warehouse = warehouseSelect?.value || warehouseHidden?.value;
            const demand = getDemandQty(row);
            const posted = parseFloat(row.querySelector('.dispatched-qty')?.value) || 0;
            const product = row.querySelector('[data-label="Product"]')?.textContent?.trim() || 'Item';

            if (!lockGodown) {
                const available = getSelectedWarehouseAvailable(warehouseSelect);
                if (!warehouse) errors.push(`Select warehouse for ${product}`);
                if (warehouse && demand > available + 0.0001) {
                    errors.push(
                        `${product}: need ${demand.toFixed(2)}, only ${available.toFixed(2)} in warehouse`
                    );
                }
            } else if (!warehouse) {
                errors.push(`Warehouse missing for ${product} — save godown again`);
            }

            if (demand <= 0) errors.push('Invalid invoice demand on a line');
            if (Math.abs(posted - demand) > 0.0001) {
                errors.push('Quantity must match invoice demand (partial dispatch not allowed)');
            }
        });

        if (!lockGodown) {
            const disp = document.getElementById('dispatcher_id');
            const dispCount = getDispatcherCount(disp);
            if (dispCount === 0) errors.push('Select at least one dispatcher');
        } else {
            const hiddenDisp = document.querySelectorAll('#godownForm input[name="dispatcher_id[]"]');
            if (!hiddenDisp.length) errors.push('No dispatcher on file — save godown from draft first');
        }

        if (errors.length) {
            Swal.fire('Validation', [...new Set(errors)].join('<br>'), 'warning');
            return false;
        }
        return true;
    }

    async function handleFinalizeChallan(form, invoiceId) {
        const boot = window.CHALLAN_CREATE_BOOT || {};
        if (!boot.isGodownReady) {
            Swal.fire(
                'Godown required',
                'Save godown copy first. Challan can only be created after godown setup is saved.',
                'warning'
            );
            return;
        }

        if (!validateFinalize()) return;

        const confirm = await Swal.fire({
            title: 'Finalize challan?',
            html: 'Stock will be deducted from the godown-assigned warehouses.<br><strong>This cannot be undone</strong> except by admin reverse.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Finalize',
            confirmButtonColor: '#d97706',
        });
        if (!confirm.isConfirmed) return;

        Swal.fire({
            title: 'Processing…',
            text: 'Finalizing challan & posting stock',
            allowOutsideClick: false,
            didOpen: () => Swal.showLoading(),
        });

        try {
            const formData = new FormData(form);
            syncSelect2ToForm(formData);
            const res = await fetch(CHALLAN_BASE + 'create_final_challan', { method: 'POST', body: formData });
            const result = await res.json();

            if (result.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: 'Challan completed',
                    html: '<p class="mb-2">' + escapeHtml(result.message || 'Stock deducted.') + '</p>' +
                        '<p class="small text-muted mb-0">Print delivery documents or stay on this page.</p>',
                    showConfirmButton: true,
                    confirmButtonText: 'Print challan',
                    showDenyButton: true,
                    denyButtonText: 'Print godown',
                    showCancelButton: true,
                    cancelButtonText: 'Stay here',
                }).then((r) => {
                    const id = invoiceId;
                    if (r.isConfirmed) {
                        window.open(BASE_URL + 'challan/challan_copy/' + id, '_blank');
                    } else if (r.isDenied) {
                        window.open(BASE_URL + 'challan/godown_copy/' + id, '_blank');
                    }
                    window.location.reload();
                });
            } else {
                Swal.fire('Error', result.message, 'error');
            }
        } catch (e) {
            Swal.fire('Error', 'Network error', 'error');
        }
    }

    function validateFinalize() {
        const boot = window.CHALLAN_CREATE_BOOT || {};
        const lockGodown = boot.lockGodownAssignments === true;
        const errors = [];

        document.querySelectorAll('#godownItemsTable tbody tr').forEach(row => {
            const warehouseSelect = row.querySelector('.warehouse-select');
            const warehouseHidden = row.querySelector('input[name="warehouse_id[]"]');
            const warehouse = warehouseSelect?.value || warehouseHidden?.value;
            const demand = getDemandQty(row);
            const posted = parseFloat(row.querySelector('.dispatched-qty')?.value) || 0;
            const product = row.querySelector('[data-label="Product"]')?.textContent?.trim() || 'Item';

            if (!warehouse) errors.push(`Warehouse missing for ${product}`);
            if (Math.abs(posted - demand) > 0.0001) {
                errors.push(`${product}: must dispatch full invoice demand (${demand.toFixed(2)})`);
            }

            // After godown, stock is reserved for this invoice — do not re-check dropdown "available".
            if (!lockGodown && warehouseSelect) {
                const available = getSelectedWarehouseAvailable(warehouseSelect);
                if (demand > available + 0.0001) {
                    errors.push(
                        `${product}: need ${demand.toFixed(2)}, only ${available.toFixed(2)} available — pick another warehouse`
                    );
                }
            }
        });

        const disp = document.getElementById('dispatcher_id');
        const hiddenDisp = document.querySelectorAll('#godownForm input[name="dispatcher_id[]"]');
        if (disp && getDispatcherCount(disp) === 0 && !hiddenDisp.length) {
            errors.push('Select at least one dispatcher');
        }

        if (errors.length) {
            Swal.fire('Validation', [...new Set(errors)].slice(0, 6).join('<br>'), 'warning');
            return false;
        }
        return true;
    }

    function getDispatcherCount(disp) {
        if (!disp) return 0;
        if (typeof $ !== 'undefined' && $(disp).data('select2')) {
            const v = $(disp).val();
            return Array.isArray(v) ? v.length : (v ? 1 : 0);
        }
        return disp.selectedOptions?.length || 0;
    }

    function syncSelect2ToForm(formData) {
        const disp = document.getElementById('dispatcher_id');
        if (!disp || typeof $ === 'undefined' || !$(disp).data('select2')) return;
        formData.delete('dispatcher_id[]');
        const vals = $(disp).val();
        if (Array.isArray(vals)) {
            vals.forEach(id => formData.append('dispatcher_id[]', id));
        } else if (vals) {
            formData.append('dispatcher_id[]', vals);
        }
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
})();
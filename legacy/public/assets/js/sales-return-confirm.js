/**
 * Sales return warehouse confirm — validation, bulk warehouse, branch stock (SSOT)
 */
(function () {
    'use strict';

    const stockCache = {};

    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('srConfirmForm');
        if (!form) return;

        const base = (window.SR_CONFIRM_BASE || '/').replace(/\/?$/, '/');
        const bulkSelect = document.getElementById('srBulkWarehouse');
        const applyBulkBtn = document.getElementById('srApplyBulkWarehouse');
        const progressBar = document.getElementById('srConfirmProgressBar');
        const checklistItems = document.querySelectorAll('[data-check]');

        function warehouseSelects() {
            return form.querySelectorAll('.sr-warehouse-select');
        }

        function warehouseHiddens() {
            return form.querySelectorAll('.sr-warehouse-hidden');
        }

        function conditionSelects() {
            return form.querySelectorAll('.sr-condition-select');
        }

        function syncConditionHidden(row) {
            const sel = row.querySelector('.sr-condition-select');
            const hidden = row.querySelector('.sr-condition-hidden');
            if (sel && hidden) {
                hidden.value = sel.value;
            }
        }

        function syncWarehouseHidden(row) {
            const hidden = row.querySelector('.sr-warehouse-hidden');
            const select = row.querySelector('.sr-warehouse-select');
            if (!hidden || !select) return;
            hidden.value = select.value || hidden.value || '';
        }

        function defaultWarehouseForSelect(select) {
            if (select.value) return select.value;
            const bulk = bulkSelect?.value;
            if (bulk) return bulk;
            for (let i = 0; i < select.options.length; i += 1) {
                if (select.options[i].value) {
                    return select.options[i].value;
                }
            }
            return '';
        }

        function syncRowState(row) {
            const cond = row.querySelector('.sr-condition-select');
            const isDamage = cond && cond.value === 'Damage';
            row.classList.toggle('is-damage', isDamage);
            syncConditionHidden(row);

            const hint = row.querySelector('.sr-confirm-line-hint');
            if (hint) {
                hint.textContent = isDamage
                    ? 'Damaged — auto write-off after receive (no sellable stock)'
                    : 'Good — quantity will be added to selected warehouse';
                hint.classList.toggle('is-damage-note', isDamage);
            }

            const whSelect = row.querySelector('.sr-warehouse-select');
            if (whSelect) {
                if (isDamage) {
                    const def = defaultWarehouseForSelect(whSelect);
                    if (def) whSelect.value = def;
                    whSelect.disabled = true;
                    whSelect.classList.add('sr-warehouse-select--muted');
                    whSelect.removeAttribute('required');
                } else {
                    whSelect.disabled = false;
                    whSelect.classList.remove('sr-warehouse-select--muted');
                    whSelect.setAttribute('required', 'required');
                }
                syncWarehouseHidden(row);
            }
            updateRowStockBadge(row);
        }

        async function fetchStockForProduct(productId) {
            const pid = String(productId);
            if (stockCache[pid]) {
                return stockCache[pid];
            }
            const res = await fetch(
                `${base}SalesReturn/warehouse_stock_for_receive?product_id=${encodeURIComponent(pid)}`
            );
            const json = await res.json();
            const rows = Array.isArray(json)
                ? json
                : (Array.isArray(json?.data) ? json.data : []);
            stockCache[pid] = rows;
            return rows;
        }

        function formatStockLabel(w) {
            const phys = parseFloat(w.physical_qty) || 0;
            const avail = parseFloat(w.available_qty) || 0;
            return `${w.warehouse_name} (Phys: ${phys.toFixed(2)}, Avail: ${avail.toFixed(2)})`;
        }

        async function enrichWarehouseOptions(row) {
            const productId = row.dataset.productId;
            const select = row.querySelector('.sr-warehouse-select');
            if (!productId || !select || select.dataset.stockLoaded === '1') {
                return;
            }

            const current = select.value;
            const rows = await fetchStockForProduct(productId);
            const byId = {};
            rows.forEach((w) => {
                byId[String(w.id)] = w;
            });

            Array.from(select.options).forEach((opt) => {
                if (!opt.value) return;
                const w = byId[opt.value];
                if (w) {
                    opt.textContent = formatStockLabel(w);
                    opt.dataset.physical = String(w.physical_qty ?? 0);
                    opt.dataset.available = String(w.available_qty ?? 0);
                }
            });

            if (current) {
                select.value = current;
            }
            select.dataset.stockLoaded = '1';
            syncWarehouseHidden(row);
            updateRowStockBadge(row);
        }

        function updateRowStockBadge(row) {
            const badge = row.querySelector('[data-role="wh-stock-badge"]');
            const select = row.querySelector('.sr-warehouse-select');
            const cond = row.querySelector('.sr-condition-select');
            if (!badge) return;

            if (cond && cond.value === 'Damage') {
                badge.textContent = 'N/A';
                badge.className = 'sr-wh-stock-badge is-muted';
                badge.title = 'Damaged goods are not restocked';
                return;
            }

            if (!select || !select.value) {
                badge.textContent = '—';
                badge.className = 'sr-wh-stock-badge';
                badge.title = 'Select a warehouse';
                return;
            }

            const opt = select.options[select.selectedIndex];
            const phys = parseFloat(opt?.dataset?.physical) || 0;
            const avail = parseFloat(opt?.dataset?.available) || 0;
            badge.textContent = phys.toFixed(2);
            badge.className = 'sr-wh-stock-badge is-ok';
            badge.title = `Physical on hand: ${phys.toFixed(2)} · Available: ${avail.toFixed(2)}`;
        }

        function countWarehouseReady() {
            let filled = 0;
            let required = 0;
            form.querySelectorAll('tbody tr[data-product-id]').forEach((row) => {
                required += 1;
                const hidden = row.querySelector('.sr-warehouse-hidden');
                if (hidden && parseInt(hidden.value, 10) > 0) {
                    filled += 1;
                }
            });
            return { filled, required };
        }

        function updateProgress() {
            const { filled, required } = countWarehouseReady();
            const pct = required ? Math.round((filled / required) * 100) : 100;
            if (progressBar) progressBar.style.width = pct + '%';

            const allConditionsSet = conditionSelects().length > 0;
            checklistItems.forEach((el) => {
                const key = el.getAttribute('data-check');
                if (key === 'warehouse') {
                    el.classList.toggle('done', filled === required && required > 0);
                }
                if (key === 'condition') {
                    el.classList.toggle('done', allConditionsSet);
                }
                if (key === 'inspect') {
                    el.classList.toggle('done', filled === required && required > 0 && allConditionsSet);
                }
            });
        }

        function markInvalid(select, invalid) {
            const row = select.closest('tr');
            if (row) row.classList.toggle('is-invalid-row', invalid);
            select.classList.toggle('is-invalid', invalid);
        }

        form.querySelectorAll('tbody tr[data-product-id]').forEach((row) => {
            enrichWarehouseOptions(row);
            syncRowState(row);
        });

        conditionSelects().forEach((sel) => {
            sel.addEventListener('change', () => {
                const r = sel.closest('tr');
                if (r) syncRowState(r);
                updateProgress();
            });
        });

        warehouseSelects().forEach((sel) => {
            sel.addEventListener('change', () => {
                const row = sel.closest('tr');
                if (row) syncWarehouseHidden(row);
                markInvalid(sel, !sel.disabled && !sel.value);
                if (row) updateRowStockBadge(row);
                updateProgress();
            });
            if (sel.value) markInvalid(sel, false);
        });

        if (applyBulkBtn && bulkSelect) {
            applyBulkBtn.addEventListener('click', function () {
                const wid = bulkSelect.value;
                if (!wid) {
                    Swal.fire('Select warehouse', 'Choose a warehouse to apply to all lines.', 'info');
                    return;
                }
                form.querySelectorAll('tbody tr[data-product-id]').forEach((row) => {
                    const s = row.querySelector('.sr-warehouse-select');
                    if (!s) return;
                    s.value = wid;
                    syncWarehouseHidden(row);
                    markInvalid(s, false);
                    updateRowStockBadge(row);
                    syncRowState(row);
                });
                updateProgress();
            });
        }

        updateProgress();

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            form.querySelectorAll('tbody tr[data-product-id]').forEach((row) => {
                syncConditionHidden(row);
                syncRowState(row);
            });

            let valid = true;
            form.querySelectorAll('tbody tr[data-product-id]').forEach((row) => {
                const cond = row.querySelector('.sr-condition-select');
                const isGood = cond && cond.value === 'Good';
                const hidden = row.querySelector('.sr-warehouse-hidden');
                const select = row.querySelector('.sr-warehouse-select');
                const whVal = parseInt(hidden?.value || '0', 10);

                if (whVal <= 0) {
                    valid = false;
                    if (select && isGood) markInvalid(select, true);
                    row.classList.add('is-invalid-row');
                } else if (select && isGood) {
                    markInvalid(select, false);
                }
            });

            if (!valid) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Warehouse required',
                    text: 'Select a warehouse for every Good line (or use Apply to all). Damage lines are auto-assigned.',
                });
                const first = form.querySelector('.sr-warehouse-select.is-invalid');
                if (first) first.focus();
                return;
            }

            const returnCode = form.dataset.returnCode || 'this return';
            const total = form.dataset.returnTotal || '';

            Swal.fire({
                title: 'Confirm return?',
                html:
                    '<p class="mb-2">You are about to confirm <strong>' +
                    returnCode +
                    '</strong>.</p>' +
                    (total ? '<p class="mb-0">Credit note: <strong>' + total + '</strong><br>Stock will update for <em>Good</em> items only.</p>' : ''),
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, confirm & update stock',
                cancelButtonText: 'Review again',
                confirmButtonColor: '#16a34a',
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
})();

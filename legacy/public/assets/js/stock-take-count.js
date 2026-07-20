// stock-take-count.js — excel-like count workspace (partial lines only on save)

(function () {
    const form = document.getElementById('countForm');
    if (!form) return;

    const baseUrl = () => {
        let u = window.ST_BOOT?.baseUrl || '/';
        return u.endsWith('/') ? u : u + '/';
    };

    const parsePayload = (raw) => {
        if (!raw || typeof raw !== 'object') return raw;
        if (Array.isArray(raw.data)) return raw.data;
        if (
            raw.data &&
            typeof raw.data === 'object' &&
            !Array.isArray(raw.data) &&
            raw.data.status !== undefined
        ) {
            return raw.data;
        }
        return raw;
    };

    const rows = () => Array.from(document.querySelectorAll('.st-count-row:not(.is-hidden)'));

    function rowCost(row) {
        const avg = parseFloat(row.dataset.avg) || 0;
        const receipt = parseFloat(row.dataset.receipt) || 0;
        return avg > 0 ? avg : receipt;
    }

    function updateRow(row) {
        const input = row.querySelector('.physical-qty');
        if (!input) return;
        const raw = input.value.trim();
        const systemQty = parseFloat(row.dataset.system) || 0;
        const diffCell = row.querySelector('.difference');
        const impactCell = row.querySelector('.col-impact');

        if (raw === '') {
            row.classList.remove('is-counted');
            if (diffCell) {
                diffCell.textContent = '—';
                diffCell.className = 'text-end difference';
            }
            if (impactCell) {
                impactCell.textContent = '—';
                impactCell.className = 'text-end col-impact';
            }
            return;
        }

        const physical = parseFloat(raw) || 0;
        const diff = physical - systemQty;
        const impact = diff * rowCost(row);

        row.classList.add('is-counted');
        if (diffCell) {
            diffCell.textContent = diff.toFixed(2);
            diffCell.className = `text-end difference ${diff >= 0 ? 'st-diff-pos' : 'st-diff-neg'}`;
        }
        if (impactCell) {
            impactCell.textContent = impact.toFixed(2);
            impactCell.className = `text-end col-impact ${impact >= 0 ? 'st-diff-pos' : 'st-diff-neg'}`;
        }
    }

    function applyFilters() {
        const q = (document.getElementById('stCountSearch')?.value || '').trim().toLowerCase();
        const cat = document.getElementById('stCountCategory')?.value || '';
        const stockOnly = document.getElementById('stFilterStock')?.checked;
        const filledOnly = document.getElementById('stFilterFilled')?.checked;

        let visible = 0;
        let filled = 0;
        let gain = 0;
        let loss = 0;

        document.querySelectorAll('.st-count-row').forEach((row) => {
            const input = row.querySelector('.physical-qty');
            const hasFill = input && input.value.trim() !== '';
            const sys = parseFloat(row.dataset.system) || 0;
            const search = row.dataset.search || '';
            const catId = row.dataset.categoryId || '';

            let show = true;
            if (q && !search.includes(q)) show = false;
            if (cat && catId !== cat) show = false;
            if (stockOnly && sys <= 0.0001) show = false;
            if (filledOnly && !hasFill) show = false;

            row.classList.toggle('is-hidden', !show);
            if (show) visible++;
            if (hasFill) {
                filled++;
                const diff = (parseFloat(input.value) || 0) - sys;
                const impact = diff * rowCost(row);
                if (impact >= 0) gain += impact;
                else loss += Math.abs(impact);
            }
        });

        const set = (id, text) => {
            const el = document.getElementById(id);
            if (el) el.textContent = text;
        };
        set('stVisibleCount', String(visible));
        set('stFilledCount', String(filled));
        set('stGainTotal', gain.toFixed(2));
        set('stLossTotal', loss.toFixed(2));
        set('stNetImpact', (gain - loss).toFixed(2));
    }

    function initKeyboardNav() {
        const table = document.getElementById('countTable');
        if (!table) return;

        table.addEventListener('keydown', (e) => {
            const input = e.target;
            if (!input.classList?.contains('physical-qty')) return;

            const row = input.closest('.st-count-row');
            if (!row) return;

            const visibleRows = rows();
            const idx = visibleRows.indexOf(row);

            if (e.key === 'Enter' || (e.key === 'ArrowDown' && !e.shiftKey)) {
                e.preventDefault();
                const next = visibleRows[idx + 1];
                next?.querySelector('.physical-qty')?.focus();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                const prev = visibleRows[idx - 1];
                prev?.querySelector('.physical-qty')?.focus();
            }
        });
    }

    document.getElementById('stCountSearch')?.addEventListener('input', applyFilters);
    document.getElementById('stCountCategory')?.addEventListener('change', applyFilters);
    document.getElementById('stFilterStock')?.addEventListener('change', applyFilters);
    document.getElementById('stFilterFilled')?.addEventListener('change', applyFilters);

    document.getElementById('countTable')?.addEventListener('input', (e) => {
        if (e.target.classList.contains('physical-qty')) {
            updateRow(e.target.closest('.st-count-row'));
            applyFilters();
        }
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const filledRows = Array.from(document.querySelectorAll('.st-count-row')).filter((r) => {
            const v = r.querySelector('.physical-qty')?.value?.trim();
            return v !== undefined && v !== '';
        });

        const markComplete = document.getElementById('markComplete')?.checked;

        if (filledRows.length === 0 && !markComplete) {
            Swal.fire(
                'Nothing to save',
                'Enter physical qty on at least one product, or tick “Mark warehouse complete”.',
                'info'
            );
            return;
        }

        const fd = new FormData();
        fd.append('session_id', form.querySelector('[name=session_id]').value);
        fd.append('warehouse_id', form.querySelector('[name=warehouse_id]').value);
        if (markComplete) {
            fd.append('mark_complete', '1');
        }

        filledRows.forEach((row) => {
            const pid = row.dataset.productId;
            const qty = row.querySelector('.physical-qty').value.trim();
            const reason = row.querySelector('.reason-input')?.value?.trim() || '';
            const receipt = row.dataset.receipt || '0';
            fd.append(`physical_qty[${pid}]`, qty);
            fd.append(`reason[${pid}]`, reason);
            fd.append(`receipt_rate[${pid}]`, receipt);
        });

        const btn = document.getElementById('btnSaveCount');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Saving…';
        }

        try {
            const res = await fetch(`${baseUrl()}StockTake/saveCount`, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
            });
            const text = await res.text();
            let raw;
            try {
                raw = JSON.parse(text);
            } catch {
                console.error('Non-JSON response:', text.slice(0, 800));
                const hint = res.status === 404
                    ? 'Save URL not found — check BASE_URL.'
                    : 'Server returned HTML instead of JSON (PHP error or redirect).';
                throw new Error(hint);
            }
            const data = parsePayload(raw) ?? raw;

            if (!res.ok && data.status !== 'success') {
                throw new Error(data.message || `Request failed (${res.status})`);
            }

            if (data.status === 'success') {
                const sid = data.session_id || form.querySelector('[name=session_id]').value;
                const hub = `${baseUrl()}StockTake/details/${sid}`;
                const done = !!data.warehouse_done;

                Swal.fire({
                    icon: 'success',
                    title: done ? 'Warehouse complete' : 'Count saved',
                    html: `${data.message || 'Saved'}<br><br>
                        <span class="small text-muted">Stock is not updated until you post the full session from the session hub.</span>`,
                    showCancelButton: true,
                    confirmButtonText: done ? 'Session hub' : 'Continue counting',
                    cancelButtonText: done ? 'Stay here' : 'Session hub',
                    reverseButtons: true,
                }).then((r) => {
                    if (done && r.isConfirmed) {
                        window.location.href = hub;
                    } else if (!done && r.dismiss === Swal.DismissReason.cancel) {
                        window.location.href = hub;
                    } else {
                        location.reload();
                    }
                });
            } else {
                Swal.fire('Could not save', data.message || 'Unknown error', 'error');
            }
        } catch (err) {
            Swal.fire('Could not save', err.message || 'Network error', 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-save me-1"></i> Save count lines';
            }
        }
    });

    document.querySelectorAll('.st-count-row').forEach(updateRow);
    applyFilters();
    initKeyboardNav();
})();
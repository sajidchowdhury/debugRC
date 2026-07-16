/**
 * Reports Command Center — search, category tabs, lenses, pins.
 */
(function () {
    'use strict';

    const PIN_KEY = 'rc_erp_report_pins';

    function getPins() {
        try {
            return JSON.parse(localStorage.getItem(PIN_KEY) || '[]');
        } catch (e) {
            return [];
        }
    }

    function setPins(ids) {
        localStorage.setItem(PIN_KEY, JSON.stringify(ids));
    }

    function togglePin(id) {
        const pins = getPins();
        const i = pins.indexOf(id);
        if (i >= 0) pins.splice(i, 1);
        else pins.push(id);
        setPins(pins);
        syncPinButtons();
        renderPinnedSection();
    }

    function syncPinButtons() {
        const pins = getPins();
        document.querySelectorAll('.reports-pin').forEach((btn) => {
            const id = btn.dataset.reportId;
            const on = pins.includes(id);
            btn.classList.toggle('is-pinned', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            btn.title = on ? 'Unpin' : 'Pin to top';
        });
    }

    function renderPinnedSection() {
        const wrap = document.getElementById('reportsPinnedSection');
        const grid = document.getElementById('reportsPinnedGrid');
        if (!wrap || !grid) return;

        const pins = getPins();
        const cards = pins
            .map((id) => document.querySelector('.reports-card[data-report-id="' + id + '"]'))
            .filter(Boolean);

        if (!cards.length) {
            wrap.style.display = 'none';
            grid.innerHTML = '';
            return;
        }

        wrap.style.display = 'block';
        grid.innerHTML = '';
        cards.forEach((c) => {
            const clone = c.cloneNode(true);
            clone.classList.remove('is-hidden');
            grid.appendChild(clone);
            clone.querySelector('.reports-pin')?.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                togglePin(clone.dataset.reportId);
            });
        });
    }

    function filterCards() {
        const q = (document.getElementById('reportsSearch')?.value || '').trim().toLowerCase();
        const activeCat = document.querySelector('.reports-cat-tab.active')?.dataset.category || 'all';
        let visible = 0;

        document.querySelectorAll('#reportsBento .reports-card').forEach((card) => {
            const cat = card.dataset.category || '';
            const text = (card.dataset.search || '').toLowerCase();
            const catOk = activeCat === 'all' || cat === activeCat;
            const searchOk = !q || text.includes(q);
            const show = catOk && searchOk;
            card.classList.toggle('is-hidden', !show);
            if (show) visible++;
        });

        const empty = document.getElementById('reportsEmptySearch');
        if (empty) empty.classList.toggle('show', visible === 0);
    }

    function applyLens(lens) {
        document.querySelectorAll('.reports-lens-btn').forEach((b) => {
            b.classList.toggle('active', b.dataset.lens === lens);
        });
        document.querySelectorAll('.reports-card [data-lens-run]').forEach((a) => {
            const base = a.getAttribute('href')?.split('?')[0] || '';
            const params = new URLSearchParams();
            params.set('search', '1');
            const today = new Date().toISOString().split('T')[0];
            const filterType = a.dataset.filterType || 'range';

            if (filterType === 'as_of') {
                params.set('as_of_date', today);
            } else if (lens === 'today') {
                params.set('from_date', today);
                params.set('to_date', today);
            } else if (lens === 'mtd') {
                const d = new Date();
                params.set('from_date', d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-01');
                params.set('to_date', today);
            } else if (lens === 'last7') {
                const d = new Date();
                d.setDate(d.getDate() - 6);
                params.set('from_date', d.toISOString().split('T')[0]);
                params.set('to_date', today);
            } else {
                const days = parseInt(a.dataset.presetDays || '30', 10);
                const d = new Date();
                d.setDate(d.getDate() - Math.max(1, days));
                params.set('from_date', d.toISOString().split('T')[0]);
                params.set('to_date', today);
            }
            a.href = base + '?' + params.toString();
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        const hub = document.getElementById('reportsHub');
        if (!hub) return;

        document.getElementById('reportsSearch')?.addEventListener('input', filterCards);

        document.querySelectorAll('.reports-cat-tab').forEach((tab) => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.reports-cat-tab').forEach((t) => t.classList.remove('active'));
                tab.classList.add('active');
                filterCards();
            });
        });

        document.querySelectorAll('.reports-lens-btn').forEach((btn) => {
            btn.addEventListener('click', () => applyLens(btn.dataset.lens || 'mtd'));
        });

        hub.addEventListener('click', (e) => {
            const pin = e.target.closest('.reports-pin');
            if (pin) {
                e.preventDefault();
                e.stopPropagation();
                togglePin(pin.dataset.reportId);
            }
        });

        syncPinButtons();
        renderPinnedSection();
        applyLens('mtd');
        filterCards();
    });

    /* Report frame: collapsible filters */
    document.addEventListener('DOMContentLoaded', () => {
        const head = document.querySelector('.rpt-filter-dock-head');
        if (!head) return;
        head.addEventListener('click', () => {
            head.closest('.rpt-filter-dock')?.classList.toggle('collapsed');
        });
    });
})();
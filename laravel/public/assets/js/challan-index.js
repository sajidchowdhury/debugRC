/**
 * Challan index — smart filters, status chips with counts, mobile cards.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'challan_index_filters_v1';
    let challanTable = null;
    let summaryDebounce = null;

    const STATUS_LABELS = {
        all: 'All invoices',
        open: 'Needs warehouse',
        needs_godown: 'Pending godown',
        needs_challan: 'Ready for challan',
        draft: 'Draft only',
        godown_issued: 'Godown issued',
        challan_completed: 'Completed',
    };

    $(function () {
        if (!document.getElementById('challan-index-app')) return;

        initFromBootOrStorage();
        bindFilterUi();
        initFiltersCollapse();
        initDataTable();
        refreshSummary();
        updateExportLink();
        updateActiveFilterBar();
    });

    function initFromBootOrStorage() {
        const boot = window.CHALLAN_INDEX_BOOT || {};
        let saved = null;
        try {
            saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
        } catch (e) { saved = null; }

        const useSaved = saved && !boot.forceUrlParams;
        const state = useSaved ? saved : boot;

        if (state.date_preset) setActivePreset(state.date_preset, false);
        else applyDatePreset('today', false);

        if (state.date_from) $('#filterDateFrom').val(state.date_from);
        if (state.date_to) $('#filterDateTo').val(state.date_to);
        if (state.status) $('#filterStatus').val(state.status);
        if (state.search) $('#filterSearch').val(state.search);
        $('#filterSmartSort').prop('checked', state.smart_sort !== false && state.smart_sort !== '0');
        syncStatusChips();
    }

    function bindFilterUi() {
        $('.challan-preset-btn').on('click', function () {
            applyDatePreset($(this).data('preset'));
        });

        $('.challan-status-chip').on('click', function () {
            const status = $(this).data('status');
            $('#filterStatus').val(status);
            syncStatusChips();
            if (isWorkflowStatus(status)) {
                applyWorkflowFilterView(status, false);
            }
            persistAndReload();
        });

        $('#filterDateFrom, #filterDateTo').on('change', () => {
            $('.challan-preset-btn').removeClass('active');
            $('.challan-preset-btn[data-preset="custom"]').addClass('active');
            persistAndReload();
        });

        $('#filterSearch').on('input', debounce(() => {
            if (challanTable) {
                challanTable.search($('#filterSearch').val()).draw();
            }
            scheduleSummary();
            updateExportLink();
            updateActiveFilterBar();
            saveFilters();
        }, 320));

        $('#clearFilters').on('click', resetFilters);
        $('#filterSmartSort').on('change', persistAndReload);

        $('#filterOpenQueue').on('click', () => applyWorkflowFilterView('open'));
        $('#filterReadyChallan').on('click', () => applyWorkflowFilterView('needs_challan'));
    }

    /** Open workflow items may be older than today — widen date when filtering queue. */
    function applyWorkflowFilterView(status, reload = true) {
        const today = new Date();
        const fmt = d => d.toISOString().slice(0, 10);
        const from = new Date(today);
        from.setDate(from.getDate() - 30);
        $('#filterStatus').val(status);
        $('#filterDateFrom').val(fmt(from));
        $('#filterDateTo').val(fmt(today));
        $('.challan-preset-btn').removeClass('active');
        $('.challan-preset-btn[data-preset="custom"]').addClass('active');
        syncStatusChips();
        if (reload) persistAndReload(true);
        const collapse = document.getElementById('challanFiltersCollapse');
        if (collapse && typeof bootstrap !== 'undefined' && !collapse.classList.contains('show')) {
            bootstrap.Collapse.getOrCreateInstance(collapse).show();
        }
    }

    function isWorkflowStatus(status) {
        return status === 'open' || status === 'needs_godown' || status === 'needs_challan';
    }

    function applyDatePreset(preset, reload = true) {
        const range = dateRangeForPreset(preset);
        setActivePreset(preset, false);
        $('#filterDateFrom').val(range.from);
        $('#filterDateTo').val(range.to);
        if (reload) persistAndReload();
    }

    function setActivePreset(preset, reload) {
        $('.challan-preset-btn').removeClass('active');
        $(`.challan-preset-btn[data-preset="${preset}"]`).addClass('active');
        if (reload) applyDatePreset(preset);
    }

    function dateRangeForPreset(preset) {
        const today = new Date();
        const fmt = d => d.toISOString().slice(0, 10);

        switch (preset) {
            case 'yesterday': {
                const y = new Date(today);
                y.setDate(y.getDate() - 1);
                const s = fmt(y);
                return { from: s, to: s };
            }
            case 'week': {
                const w = new Date(today);
                w.setDate(w.getDate() - 6);
                return { from: fmt(w), to: fmt(today) };
            }
            case 'month': {
                const m = new Date(today.getFullYear(), today.getMonth(), 1);
                return { from: fmt(m), to: fmt(today) };
            }
            case 'today':
            default:
                return { from: fmt(today), to: fmt(today) };
        }
    }

    function syncStatusChips() {
        const status = $('#filterStatus').val();
        $('.challan-status-chip').removeClass('active');
        $(`.challan-status-chip[data-status="${status}"]`).addClass('active');
    }

    function getFilterParams() {
        return {
            date_from: $('#filterDateFrom').val(),
            date_to: $('#filterDateTo').val(),
            status: $('#filterStatus').val(),
            search: $('#filterSearch').val().trim(),
            smart_sort: $('#filterSmartSort').is(':checked') ? '1' : '0',
        };
    }

    function saveFilters() {
        const preset = $('.challan-preset-btn.active').data('preset') || 'custom';
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                ...getFilterParams(),
                date_preset: preset,
            }));
        } catch (e) { /* quota */ }
    }

    function persistAndReload(forceSummary) {
        saveFilters();
        if (challanTable) challanTable.ajax.reload();
        if (forceSummary) refreshSummary();
        else scheduleSummary();
        updateExportLink();
        updateActiveFilterBar();
    }

    function resetFilters() {
        $('#filterStatus').val('open');
        $('#filterSearch').val('');
        $('#filterSmartSort').prop('checked', true);
        applyDatePreset('today');
        syncStatusChips();
        if (challanTable) challanTable.search('').draw();
        persistAndReload(true);
    }

    function scheduleSummary() {
        clearTimeout(summaryDebounce);
        summaryDebounce = setTimeout(refreshSummary, 280);
    }

    function refreshSummary() {
        const p = getFilterParams();
        const qs = new URLSearchParams({
            date_from: p.date_from,
            date_to: p.date_to,
            search: p.search,
        });

        fetch(window.CHALLAN_BASE + 'filter_summary?' + qs.toString())
            .then(r => r.json())
            .then(data => {
                updateChipCounts(data);
            })
            .catch(() => {});
    }

    function updateChipCounts(data) {
        const map = {
            all: data.total ?? 0,
            open: data.open ?? 0,
            needs_godown: data.needs_godown ?? 0,
            needs_challan: data.needs_challan ?? 0,
            draft: data.draft ?? 0,
            godown_issued: data.godown_issued ?? 0,
            challan_completed: data.challan_completed ?? 0,
        };
        $('.challan-status-chip').each(function () {
            const key = $(this).data('status');
            const el = $(this).find('.chip-count');
            if (el.length) el.text(map[key] ?? 0);
        });
    }

    function initFiltersCollapse() {
        const el = document.getElementById('challanFiltersCollapse');
        if (!el) return;

        el.addEventListener('shown.bs.collapse', syncFiltersToggleBtn);
        el.addEventListener('hidden.bs.collapse', syncFiltersToggleBtn);
        syncFiltersToggleBtn();
    }

    function syncFiltersToggleBtn() {
        const open = document.getElementById('challanFiltersCollapse')?.classList.contains('show');
        $('#toggleChallanFilters')
            .toggleClass('collapsed', !open)
            .attr('aria-expanded', open ? 'true' : 'false');
    }

    function updateActiveFilterBar() {
        const bar = document.getElementById('activeFilterBar');
        if (!bar) return;

        const p = getFilterParams();
        const preset = $('.challan-preset-btn.active').data('preset') || 'custom';
        const presetLabel = $('.challan-preset-btn.active').text().trim() || 'Custom range';
        const tags = [];

        tags.push(`<span class="filter-tag"><i class="fas fa-calendar"></i> ${escapeHtml(presetLabel)} (${p.date_from} → ${p.date_to})</span>`);
        tags.push(`<span class="filter-tag"><i class="fas fa-filter"></i> ${escapeHtml(STATUS_LABELS[p.status] || p.status)}</span>`);
        if (p.search) {
            tags.push(`<span class="filter-tag"><i class="fas fa-search"></i> "${escapeHtml(p.search)}"</span>`);
        }
        if (p.smart_sort === '1') {
            tags.push('<span class="filter-tag"><i class="fas fa-sort-amount-down"></i> Priority sort</span>');
        }

        bar.innerHTML = tags.join('') +
            '<button type="button" class="btn btn-link btn-sm p-0 ms-auto" id="clearFiltersInline">Clear all</button>';
        $('#clearFiltersInline').on('click', resetFilters);
    }

    function updateExportLink() {
        const p = getFilterParams();
        const qs = new URLSearchParams(p);
        const btn = document.getElementById('exportChallanBtn');
        if (btn) btn.href = window.CHALLAN_BASE + 'export?' + qs.toString();
    }

    function initDataTable() {
        challanTable = $('#challanTable').DataTable({
            processing: true,
            serverSide: true,
            pageLength: 25,
            order: [],
            language: {
                emptyTable: 'No invoices match your filters',
                processing: '<i class="fas fa-spinner fa-spin"></i> Loading…',
            },
            ajax: {
                url: window.CHALLAN_BASE + 'datatable_challans',
                data: d => {
                    const p = getFilterParams();
                    d.date_from = p.date_from;
                    d.date_to = p.date_to;
                    d.status = p.status;
                    d.smart_sort = p.smart_sort;
                },
            },
            columns: [
                {
                    data: 'invoice_code',
                    render: (d, t, row) => `<strong class="text-primary">${escapeHtml(d)}</strong>` +
                        (row.total_amount ? `<br><small class="text-muted">Tk ${parseFloat(row.total_amount).toFixed(2)}</small>` : ''),
                },
                { data: 'invoice_date' },
                {
                    data: null,
                    render: (d, t, row) => {
                        const title = row.shop_name || row.customer_name || '—';
                        return `<div class="fw-semibold">${escapeHtml(title)}</div>` +
                            `<small class="text-muted">${escapeHtml(row.mobile || '')}</small>`;
                    },
                },
                { data: 'salesman_name', defaultContent: '—' },
                { data: 'status', render: s => statusBadge(s) },
                { data: null, orderable: false, render: (d, t, row) => actionButton(row) },
            ],
            drawCallback: function () {
                renderMobileCards(this.api());
                const info = this.api().page.info();
                $('#resultsCountNum').text(info.recordsDisplay);
            },
        });

        $('#filterDateFrom, #filterDateTo').on('change', () => {
            if (!challanTable) return;
            challanTable.ajax.reload();
            scheduleSummary();
            updateExportLink();
            updateActiveFilterBar();
            saveFilters();
        });

        const initialSearch = $('#filterSearch').val();
        if (initialSearch) {
            challanTable.search(initialSearch).draw();
        }
    }

    function statusBadge(status) {
        const map = {
            draft: '<span class="badge rounded-pill bg-secondary">Pending godown</span>',
            godown_issued: '<span class="badge rounded-pill bg-warning text-dark">Godown ready</span>',
            challan_completed: '<span class="badge rounded-pill bg-success">Completed</span>',
        };
        return map[status] || `<span class="badge bg-light text-dark">${escapeHtml(status || '')}</span>`;
    }

    function actionButton(row) {
        const base = window.CHALLAN_BASE + 'create/' + row.id;
        if (row.status === 'draft') {
            return `<a href="${base}" class="btn btn-sm btn-primary" title="Step 2 — Assign warehouses & save godown"><i class="fas fa-warehouse me-1"></i><span class="d-none d-xl-inline">Prepare godown</span></a>`;
        }
        if (row.status === 'godown_issued') {
            return `<a href="${base}" class="btn btn-sm btn-warning text-dark" title="Step 3 — Deduct stock & complete"><i class="fas fa-truck me-1"></i><span class="d-none d-xl-inline">Finalize</span></a>`;
        }
        return `<a href="${base}" class="btn btn-sm btn-outline-success" title="View documents"><i class="fas fa-eye me-1"></i><span class="d-none d-xl-inline">View</span></a>`;
    }

    function renderMobileCards(table) {
        const container = document.getElementById('challanCards');
        if (!container || window.innerWidth >= 768) {
            if (container) container.innerHTML = '';
            return;
        }

        const data = table.rows({ page: 'current' }).data();
        let html = '';

        data.each(row => {
            const priority = row.status === 'draft' ? 'priority-draft'
                : row.status === 'godown_issued' ? 'priority-godown' : 'priority-done';
            const customer = row.shop_name || row.customer_name || '—';
            const action = actionButton(row).replace('btn-sm ', 'btn ');

            html += `<div class="challan-mobile-card ${priority}">
                <div class="card-top">
                    <div>
                        <div class="invoice-code">${escapeHtml(row.invoice_code)}</div>
                        <div class="customer-line">${escapeHtml(customer)}</div>
                    </div>
                    <div>${statusBadge(row.status)}</div>
                </div>
                <div class="card-meta">
                    <span><i class="fas fa-calendar-day"></i> ${escapeHtml(row.invoice_date || '')}</span>
                    <span><i class="fas fa-user"></i> ${escapeHtml(row.salesman_name || '—')}</span>
                    ${row.total_amount ? `<span><i class="fas fa-coins"></i> Tk ${parseFloat(row.total_amount).toFixed(2)}</span>` : ''}
                </div>
                <div class="card-actions">${action}</div>
            </div>`;
        });

        container.innerHTML = html || `
            <div class="challan-empty-state">
                <i class="fas fa-box-open d-block"></i>
                <p class="mb-0">No invoices for these filters</p>
                <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="document.getElementById('clearFilters').click()">Reset filters</button>
            </div>`;
    }

    $(window).on('resize', () => {
        if (challanTable) renderMobileCards(challanTable);
    });

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);
    }

    function debounce(fn, ms) {
        let t;
        return function () {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, arguments), ms);
        };
    }
})();
/**
 * Sales return index — smart filters, collapsible panel, mobile cards.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'sales_return_filters_v1';
    let returnsTable = null;
    let summaryDebounce = null;

    const STATUS_LABELS = {
        all: 'All returns',
        active: 'Active',
        pending: 'Awaiting warehouse',
        completed: 'Completed',
        reversed: 'Reversed',
    };

    $(function () {
        if (!document.getElementById('sales-return-app')) return;

        initFromBootOrStorage();
        bindFilterUi();
        initFiltersCollapse();
        initDataTable();
        refreshSummary();
        updateActiveFilterBar();

        document.addEventListener('salesReturn:created', function () {
            if (returnsTable) {
                returnsTable.ajax.reload(null, false);
            }
            refreshSummary();
        });
    });

    function initFromBootOrStorage() {
        const boot = window.SALES_RETURN_BOOT || {};
        let saved = null;
        try {
            saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
        } catch (e) { saved = null; }

        const state = saved && !boot.forceUrlParams ? saved : boot;

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
        $('.sales-return-preset-btn').on('click', function () {
            applyDatePreset($(this).data('preset'));
        });

        $('.sales-return-status-chip').on('click', function () {
            const status = $(this).data('status');
            $('#filterStatus').val(status);
            syncStatusChips();
            if (status === 'pending') {
                applyPendingFilterView(false);
            }
            persistAndReload();
        });

        $('#filterDateFrom, #filterDateTo').on('change', () => {
            $('.sales-return-preset-btn').removeClass('active');
            $('.sales-return-preset-btn[data-preset="custom"]').addClass('active');
            persistAndReload();
        });

        $('#filterSearch').on('input', debounce(() => {
            if (returnsTable) returnsTable.search($('#filterSearch').val()).draw();
            scheduleSummary();
            updateActiveFilterBar();
            saveFilters();
        }, 320));

        $('#clearFilters').on('click', resetFilters);
        $('#filterSmartSort').on('change', persistAndReload);

        $('#filterPendingOnly').on('click', () => {
            applyPendingFilterView();
        });
    }

    /** Pending returns may be older than today — widen date range when filtering awaiting confirm. */
    function applyPendingFilterView(reload = true) {
        const today = new Date();
        const fmt = d => d.toISOString().slice(0, 10);
        const from = new Date(today);
        from.setFullYear(from.getFullYear() - 1);
        $('#filterStatus').val('pending');
        $('#filterDateFrom').val(fmt(from));
        $('#filterDateTo').val(fmt(today));
        $('.sales-return-preset-btn').removeClass('active');
        $('.sales-return-preset-btn[data-preset="custom"]').addClass('active');
        syncStatusChips();
        if (reload) persistAndReload(true);
        const collapse = document.getElementById('salesReturnFiltersCollapse');
        if (collapse && typeof bootstrap !== 'undefined' && !collapse.classList.contains('show')) {
            bootstrap.Collapse.getOrCreateInstance(collapse).show();
        }
    }

    function initFiltersCollapse() {
        const el = document.getElementById('salesReturnFiltersCollapse');
        if (!el) return;

        el.addEventListener('shown.bs.collapse', syncFiltersToggleBtn);
        el.addEventListener('hidden.bs.collapse', syncFiltersToggleBtn);
        syncFiltersToggleBtn();
    }

    function syncFiltersToggleBtn() {
        const open = document.getElementById('salesReturnFiltersCollapse')?.classList.contains('show');
        $('#toggleSalesReturnFilters')
            .toggleClass('collapsed', !open)
            .attr('aria-expanded', open ? 'true' : 'false');
    }

    function setActivePreset(preset, reload = false) {
        $('.sales-return-preset-btn').removeClass('active');
        $(`.sales-return-preset-btn[data-preset="${preset}"]`).addClass('active');
        if (reload) applyDatePreset(preset);
    }

    function applyDatePreset(preset, reload = true) {
        const range = dateRangeForPreset(preset);
        setActivePreset(preset, false);
        $('#filterDateFrom').val(range.from);
        $('#filterDateTo').val(range.to);
        if (reload) persistAndReload();
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
            default:
                return { from: fmt(today), to: fmt(today) };
        }
    }

    function syncStatusChips() {
        const status = $('#filterStatus').val();
        $('.sales-return-status-chip').removeClass('active');
        $(`.sales-return-status-chip[data-status="${status}"]`).addClass('active');
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
        const preset = $('.sales-return-preset-btn.active').data('preset') || 'custom';
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                ...getFilterParams(),
                date_preset: preset,
            }));
        } catch (e) { /* quota */ }
    }

    function persistAndReload(forceSummary) {
        saveFilters();
        if (returnsTable) returnsTable.ajax.reload();
        if (forceSummary) refreshSummary();
        else scheduleSummary();
        updateActiveFilterBar();
    }

    function resetFilters() {
        $('#filterStatus').val('all');
        $('#filterSearch').val('');
        $('#filterSmartSort').prop('checked', true);
        applyDatePreset('today');
        syncStatusChips();
        if (returnsTable) returnsTable.search('').draw();
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
        fetch(window.SALES_RETURN_BASE + 'SalesReturn/return_filter_summary?' + qs.toString())
            .then(r => r.json())
            .then(updateChipCounts)
            .catch(() => {});
    }

    function updateChipCounts(data) {
        const map = {
            all: data.total ?? 0,
            active: data.active ?? 0,
            pending: data.pending ?? 0,
            completed: data.completed ?? 0,
            reversed: data.reversed ?? 0,
        };
        $('.sales-return-status-chip').each(function () {
            $(this).find('.chip-count').text(map[$(this).data('status')] ?? 0);
        });
        const pendingBadge = document.getElementById('heroPendingCount');
        if (pendingBadge) pendingBadge.textContent = data.pending ?? 0;
    }

    function updateActiveFilterBar() {
        const bar = document.getElementById('activeFilterBar');
        if (!bar) return;

        const p = getFilterParams();
        const presetLabel = $('.sales-return-preset-btn.active').text().trim() || 'Custom';
        const tags = [
            `<span class="filter-tag"><i class="fas fa-calendar"></i> ${escapeHtml(presetLabel)} (${p.date_from} → ${p.date_to})</span>`,
            `<span class="filter-tag"><i class="fas fa-filter"></i> ${escapeHtml(STATUS_LABELS[p.status] || p.status)}</span>`,
        ];
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

    function initDataTable() {
        returnsTable = $('#returnsTable').DataTable({
            processing: true,
            serverSide: true,
            pageLength: 25,
            order: [],
            language: {
                emptyTable: 'No returns match your filters',
                processing: '<i class="fas fa-spinner fa-spin"></i> Loading…',
            },
            ajax: {
                url: window.SALES_RETURN_BASE + 'SalesReturn/datatable_returns',
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
                    data: 'return_code',
                    render: (d, t, row) => {
                        let html = `<strong class="text-danger">${escapeHtml(d)}</strong>`;
                        if (parseInt(row.linked_damage_count || 0, 10) > 0) {
                            html += ` <span class="badge bg-danger-subtle text-danger border" title="Linked damage write-off"><i class="fas fa-heart-crack"></i></span>`;
                        }
                        return html;
                    },
                },
                { data: 'invoice_code', defaultContent: '—', render: d => escapeHtml(d || '—') },
                {
                    data: null,
                    render: (d, t, row) => {
                        const name = row.shop_name || row.customer_name || '—';
                        return `<div class="fw-semibold">${escapeHtml(name)}</div><small class="text-muted">${escapeHtml(row.mobile || '')}</small>`;
                    },
                },
                { data: 'branch_name', defaultContent: '—' },
                { data: 'return_date' },
                {
                    data: 'total_amount',
                    className: 'text-end',
                    render: d => 'Tk ' + parseFloat(d || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }),
                },
                { data: 'status', render: (s, t, row) => returnStatusBadge(s, row.is_reversed) },
                { data: null, orderable: false, className: 'text-center', render: (d, t, row) => returnActions(row) },
            ],
            drawCallback: function () {
                renderReturnCards(this.api());
                $('#resultsCountNum').text(this.api().page.info().recordsDisplay);
            },
        });

        const initialSearch = $('#filterSearch').val();
        if (initialSearch) returnsTable.search(initialSearch).draw();
    }

    function returnStatusBadge(status, isReversed) {
        if (parseInt(isReversed || 0, 10)) {
            return '<span class="badge rounded-pill bg-danger">Reversed</span>';
        }
        const map = {
            pending: '<span class="badge rounded-pill bg-warning text-dark" title="Step 2 — warehouse confirm pending">Awaiting warehouse</span>',
            completed: '<span class="badge rounded-pill bg-success">Confirmed</span>',
            reversed: '<span class="badge rounded-pill bg-danger">Reversed</span>',
        };
        return map[status] || `<span class="badge bg-secondary">${escapeHtml(status || '')}</span>`;
    }

    function returnActions(row) {
        const base = window.SALES_RETURN_BASE + 'SalesReturn/';
        let html = '<div class="btn-group btn-group-sm flex-wrap sr-return-actions">';
        if (row.status === 'pending' && !parseInt(row.is_reversed || 0, 10)) {
            html += `<a href="${base}confirm/${row.id}" class="btn btn-success" title="Step 2 — Warehouse confirm"><i class="fas fa-warehouse me-1"></i><span class="d-none d-lg-inline">Confirm</span></a>`;
        }
        html += `<a href="${base}slip/${row.id}" class="btn btn-outline-info" target="_blank" rel="noopener" title="Print slip"><i class="fas fa-print"></i></a>`;
        if (parseInt(row.linked_damage_count || 0, 10) > 0) {
            const dmgId = parseInt(row.linked_damage_id || 0, 10);
            const dmgUrl = dmgId > 0
                ? (window.SALES_RETURN_BASE + 'Damage/details/' + dmgId)
                : (window.SALES_RETURN_BASE + 'Damage?sales_return_id=' + row.id);
            html += `<a href="${dmgUrl}" class="btn btn-outline-danger" title="Linked damage write-off" target="_blank" rel="noopener"><i class="fas fa-heart-crack"></i></a>`;
        }
        if (row.status !== 'reversed' && !parseInt(row.is_reversed || 0, 10)) {
            html += `<a href="${base}reverse/${row.id}" class="btn btn-outline-danger" title="Reverse return"><i class="fas fa-undo"></i></a>`;
        }
        return html + '</div>';
    }

    function renderReturnCards(table) {
        const container = document.getElementById('returnCards');
        if (!container || window.innerWidth >= 768) {
            if (container) container.innerHTML = '';
            return;
        }

        const data = table.rows({ page: 'current' }).data();
        let html = '';

        data.each(row => {
            const statusClass = row.status === 'pending' ? 'status-pending'
                : row.status === 'reversed' || parseInt(row.is_reversed || 0, 10) ? 'status-reversed' : 'status-completed';

            html += `<div class="sales-return-mobile-card ${statusClass}">
                <div class="d-flex justify-content-between align-items-start">
                    <strong>${escapeHtml(row.return_code)}</strong>
                    ${returnStatusBadge(row.status, row.is_reversed)}
                </div>
                <div class="small text-muted mt-1">${escapeHtml(row.invoice_code || '—')} · ${escapeHtml(row.shop_name || row.customer_name || '')}</div>
                <div class="mt-2 d-flex justify-content-between align-items-center">
                    <span class="small text-muted">${escapeHtml(row.return_date || '')}</span>
                    <strong>Tk ${parseFloat(row.total_amount || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</strong>
                </div>
                <div class="mt-2">${returnActions(row)}</div>
            </div>`;
        });

        container.innerHTML = html || `
            <div class="text-center text-muted py-4">
                <i class="fas fa-undo-alt fa-2x mb-2 opacity-50"></i>
                <p class="mb-0">No returns for these filters</p>
                <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="document.getElementById('clearFilters').click()">Reset filters</button>
            </div>`;
    }

    $(window).on('resize', () => {
        if (returnsTable) renderReturnCards(returnsTable);
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
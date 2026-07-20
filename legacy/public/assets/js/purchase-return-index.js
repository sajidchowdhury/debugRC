/**
 * Purchase return index — smart filters, offcanvas create, mobile cards.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'purchase_return_filters_v1';
    let returnTable = null;
    let summaryDebounce = null;

    const STATUS_LABELS = {
        all: 'All returns',
        active: 'Active',
        reversed: 'Reversed',
    };

    $(function () {
        if (!document.getElementById('purchase-return-app')) return;

        window.CSRF_TOKEN = window.CSRF_TOKEN || (window.PURCHASE_RETURN_BOOT && window.PURCHASE_RETURN_BOOT.csrf) || '';

        initFromBootOrStorage();
        bindFilterUi();
        initFiltersCollapse();
        initDataTable();
        refreshSummary();
        updateActiveFilterBar();

        document.addEventListener('purchaseReturn:created', function () {
            if (returnTable) returnTable.ajax.reload(null, false);
            refreshSummary();
        });
    });

    function initFromBootOrStorage() {
        const boot = window.PURCHASE_RETURN_BOOT || {};
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
        $('.purchase-return-preset-btn').on('click', function () {
            applyDatePreset($(this).data('preset'));
        });

        $('.purchase-return-status-chip').on('click', function () {
            $('#filterStatus').val($(this).data('status'));
            syncStatusChips();
            persistAndReload();
        });

        $('#filterDateFrom, #filterDateTo').on('change', () => {
            $('.purchase-return-preset-btn').removeClass('active');
            $('.purchase-return-preset-btn[data-preset="custom"]').addClass('active');
            persistAndReload();
        });

        $('#filterSearch').on('input', debounce(() => {
            if (returnTable) returnTable.search($('#filterSearch').val()).draw();
            scheduleSummary();
            updateActiveFilterBar();
            saveFilters();
        }, 320));

        $('#clearFilters').on('click', resetFilters);
        $('#filterSmartSort').on('change', persistAndReload);
    }

    function initFiltersCollapse() {
        const el = document.getElementById('purchaseReturnFiltersCollapse');
        if (!el) return;
        el.addEventListener('shown.bs.collapse', syncFiltersToggleBtn);
        el.addEventListener('hidden.bs.collapse', syncFiltersToggleBtn);
        syncFiltersToggleBtn();
    }

    function syncFiltersToggleBtn() {
        const open = document.getElementById('purchaseReturnFiltersCollapse')?.classList.contains('show');
        $('#togglePurchaseReturnFilters')
            .toggleClass('collapsed', !open)
            .attr('aria-expanded', open ? 'true' : 'false');
    }

    function setActivePreset(preset, reload) {
        $('.purchase-return-preset-btn').removeClass('active');
        $(`.purchase-return-preset-btn[data-preset="${preset}"]`).addClass('active');
        if (reload) applyDatePreset(preset);
    }

    function applyDatePreset(preset, reload) {
        const range = dateRangeForPreset(preset);
        setActivePreset(preset, false);
        $('#filterDateFrom').val(range.from);
        $('#filterDateTo').val(range.to);
        if (reload !== false) persistAndReload();
    }

    function dateRangeForPreset(preset) {
        const today = new Date();
        const fmt = (d) => d.toISOString().slice(0, 10);
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
        $('.purchase-return-status-chip').removeClass('active');
        $(`.purchase-return-status-chip[data-status="${status}"]`).addClass('active');
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
        const preset = $('.purchase-return-preset-btn.active').data('preset') || 'custom';
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                ...getFilterParams(),
                date_preset: preset,
            }));
        } catch (e) { /* quota */ }
    }

    function persistAndReload(forceSummary) {
        saveFilters();
        if (returnTable) returnTable.ajax.reload();
        if (forceSummary) refreshSummary();
        else scheduleSummary();
        updateActiveFilterBar();
    }

    function resetFilters() {
        $('#filterStatus').val('active');
        $('#filterSearch').val('');
        $('#filterSmartSort').prop('checked', true);
        applyDatePreset('today');
        syncStatusChips();
        if (returnTable) returnTable.search('').draw();
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
        fetch(window.PURCHASE_RETURN_BASE + 'PurchaseReturn/return_filter_summary?' + qs.toString())
            .then((r) => r.json())
            .then(updateChipCounts)
            .catch(() => {});
    }

    function updateChipCounts(data) {
        const map = {
            all: data.all ?? data.total ?? 0,
            active: data.active ?? 0,
            reversed: data.reversed ?? 0,
        };
        $('.purchase-return-status-chip').each(function () {
            $(this).find('.chip-count').text(map[$(this).data('status')] ?? 0);
        });
    }

    function updateActiveFilterBar() {
        const bar = document.getElementById('activeFilterBar');
        if (!bar) return;

        const p = getFilterParams();
        const presetLabel = $('.purchase-return-preset-btn.active').text().trim() || 'Custom';
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

        bar.innerHTML = tags.join('')
            + '<button type="button" class="btn btn-link btn-sm p-0 ms-auto" id="clearFiltersInline">Clear all</button>';
        $('#clearFiltersInline').on('click', resetFilters);
    }

    function initDataTable() {
        returnTable = $('#returnTable').DataTable({
            processing: true,
            serverSide: true,
            pageLength: 25,
            order: [],
            language: {
                emptyTable: 'No returns match your filters',
                processing: '<i class="fas fa-spinner fa-spin"></i> Loading…',
            },
            ajax: {
                url: window.PURCHASE_RETURN_BASE + 'PurchaseReturn',
                data: (d) => {
                    const p = getFilterParams();
                    d.date_from = p.date_from;
                    d.date_to = p.date_to;
                    d.filterStatus = p.status;
                    d.smart_sort = p.smart_sort;
                },
            },
            columns: [
                {
                    data: 'return_code',
                    render: (d, t, row) =>
                        `<a href="${window.PURCHASE_RETURN_BASE}PurchaseReturn/slip/${row.id}" class="fw-bold text-decoration-none text-danger">${escapeHtml(d)}</a>`,
                },
                { data: 'receive_code', defaultContent: '—', render: (d) => escapeHtml(d || '—') },
                {
                    data: 'supplier_name',
                    render: (d, t, row) =>
                        `<div class="fw-semibold">${escapeHtml(d || '—')}</div>`
                        + `<small class="text-muted">${escapeHtml(row.branch_name || '')}</small>`,
                },
                { data: 'return_date', render: formatDate },
                {
                    data: 'total_amount',
                    className: 'text-end',
                    render: (d) => formatMoney(d),
                },
                {
                    data: 'is_reversed',
                    render: (d) => returnStatusBadge(parseInt(d || 0, 10)),
                },
                { data: null, orderable: false, className: 'text-center', render: (d, t, row) => returnActions(row) },
            ],
            drawCallback: function () {
                renderReturnCards(this.api());
                $('#resultsCountNum').text(this.api().page.info().recordsDisplay);
            },
        });

        const initialSearch = $('#filterSearch').val();
        if (initialSearch) returnTable.search(initialSearch).draw();
    }

    function returnStatusBadge(isReversed) {
        return isReversed
            ? '<span class="badge rounded-pill bg-danger">Reversed</span>'
            : '<span class="badge rounded-pill bg-success">Active</span>';
    }

    function returnActions(row) {
        const base = window.PURCHASE_RETURN_BASE + 'PurchaseReturn/';
        let html = '<div class="btn-group btn-group-sm flex-wrap">';
        html += `<a href="${base}slip/${row.id}" class="btn btn-outline-info" target="_blank" title="Slip"><i class="fas fa-print"></i></a>`;
        if (!parseInt(row.is_reversed || 0, 10)) {
            html += `<button type="button" class="btn btn-outline-danger btn-reverse-pret" data-id="${row.id}" data-code="${escapeHtml(row.return_code || '')}" title="Reverse"><i class="fas fa-undo"></i></button>`;
        }
        return html + '</div>';
    }

    $(document).on('click', '.btn-reverse-pret', function () {
        const id = $(this).data('id');
        const code = $(this).data('code') || ('PR-' + id);
        Swal.fire({
            title: 'Reverse purchase return?',
            html: `Reverse <strong>${escapeHtml(code)}</strong>? Stock and GRN returnable qty will be restored.`,
            input: 'textarea',
            inputPlaceholder: 'Reason (min 5 characters)',
            showCancelButton: true,
            confirmButtonText: 'Reverse return',
            confirmButtonColor: '#dc2626',
            returnFocus: false,
            preConfirm: (v) => {
                const r = String(v || '').trim();
                if (r.length < 5) {
                    Swal.showValidationMessage('Please provide a meaningful reason (min 5 chars).');
                    return false;
                }
                return r;
            },
        }).then((result) => {
            if (!result.isConfirmed || !result.value) return;
            $.ajax({
                url: `${window.PURCHASE_RETURN_BASE}PurchaseReturn/reverse`,
                method: 'POST',
                data: { id, reason: result.value, csrf_token: window.CSRF_TOKEN || '' },
                dataType: 'json',
            }).done((resp) => {
                if (resp && resp.status === 'success') {
                    Swal.fire({ icon: 'success', title: 'Reversed', text: resp.message, timer: 1800, showConfirmButton: false });
                    persistAndReload(true);
                } else {
                    Swal.fire('Error', resp.message || 'Failed', 'error');
                }
            }).fail(() => Swal.fire('Error', 'Server error', 'error'));
        });
    });

    function renderReturnCards(table) {
        const container = document.getElementById('returnCards');
        if (!container || window.innerWidth >= 768) {
            if (container) container.innerHTML = '';
            return;
        }

        let html = '';
        table.rows({ page: 'current' }).data().each((row) => {
            const statusClass = parseInt(row.is_reversed || 0, 10) ? 'status-reversed' : 'status-completed';
            html += `<div class="purchase-return-mobile-card ${statusClass}">
                <div class="d-flex justify-content-between align-items-start">
                    <strong>${escapeHtml(row.return_code)}</strong>
                    ${returnStatusBadge(parseInt(row.is_reversed || 0, 10))}
                </div>
                <div class="small text-muted mt-1">${escapeHtml(row.receive_code || '—')} · ${escapeHtml(row.supplier_name || '')}</div>
                <div class="mt-2 d-flex justify-content-between align-items-center">
                    <span class="small text-muted">${formatDate(row.return_date)}</span>
                    <strong>${formatMoney(row.total_amount)}</strong>
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
        if (returnTable) renderReturnCards(returnTable);
    });

    function formatDate(d) {
        if (!d) return '—';
        const p = String(d).split('-');
        return p.length === 3 ? `${p[2]}-${p[1]}-${p[0]}` : d;
    }

    function formatMoney(n) {
        return 'Tk ' + (parseFloat(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, (c) => ({
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
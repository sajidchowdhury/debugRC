/**
 * Purchase Receive (GRN) index
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'purchase_receive_filters_v1';
    const boot = window.PURCHASE_RECEIVE_BOOT || {};
    let receiveTable = null;

    const STATUS_LABELS = {
        '': 'All',
        all: 'All',
        received: 'Received',
        partial: 'Partial',
        returned: 'Returned',
        cancelled: 'Cancelled',
    };

    $(function () {
        if (!document.getElementById('purchase-receive-app')) return;
        initFilters();
        initDataTable();
        bindUi();
    });

    function initFilters() {
        applyDatePreset('month', false);
        syncStatusChips();
    }

    function bindUi() {
        $('.purch-index-preset-btn').on('click', function () {
            applyDatePreset($(this).data('preset'));
        });

        $('.purch-index-status-chip').on('click', function () {
            const st = $(this).data('status');
            $('#filterStatus').val(st === 'all' ? '' : st);
            syncStatusChips();
            reloadTable();
        });

        $('#filterDateFrom, #filterDateTo').on('change', function () {
            $('.purch-index-preset-btn').removeClass('active');
            $('.purch-index-preset-btn[data-preset="custom"]').addClass('active');
            reloadTable();
        });

        $('#filterSearch').on('input', debounce(function () {
            if (receiveTable) receiveTable.search($('#filterSearch').val()).draw();
            updateActiveBar();
        }, 300));

        $('#clearFilters').on('click', resetFilters);

        const el = document.getElementById('purchFiltersCollapse');
        if (el) {
            el.addEventListener('shown.bs.collapse', syncFiltersToggleBtn);
            el.addEventListener('hidden.bs.collapse', syncFiltersToggleBtn);
            syncFiltersToggleBtn();
        }

        $(window).on('resize', () => {
            if (receiveTable) renderCards(receiveTable);
        });
    }

    function initDataTable() {
        const ajaxUrl = boot.baseUrl + 'PurchaseReceive' + (boot.showReturned ? '?returned=1' : '');

        receiveTable = $('#receiveTable').DataTable({
            processing: true,
            serverSide: true,
            pageLength: 25,
            order: [[0, 'desc']],
            language: {
                emptyTable: 'No GRNs match your filters',
                processing: '<i class="fas fa-spinner fa-spin"></i> Loading…',
            },
            ajax: {
                url: ajaxUrl,
                data: function (d) {
                    d.date_from = $('#filterDateFrom').val();
                    d.date_to = $('#filterDateTo').val();
                    d.filterStatus = $('#filterStatus').val();
                },
            },
            drawCallback: function () {
                $('#resultsCountNum').text(receiveTable.page.info().recordsDisplay);
                renderCards(receiveTable);
                updateActiveBar();
            },
            columns: [
                { data: 'receive_date', render: formatDate },
                {
                    data: 'receive_code',
                    render: (data, type, row) =>
                        `<a href="${boot.baseUrl}PurchaseReceive/details/${row.id}" class="fw-bold text-decoration-none">`
                        + `${escapeHtml(data)}</a>`,
                },
                {
                    data: 'po_code',
                    defaultContent: '—',
                    render: (d) => d ? escapeHtml(d) : '<span class="text-muted">Direct</span>',
                },
                { data: 'supplier_name', render: escapeHtml },
                {
                    data: 'total_amount',
                    className: 'text-end',
                    render: (d) => formatMoney(d),
                },
                { data: 'status', render: (d) => statusBadge(d || 'received') },
                { data: 'created_by_name', defaultContent: '—', render: escapeHtml },
                {
                    data: 'id',
                    orderable: false,
                    className: 'text-center',
                    render: (id) =>
                        `<a href="${boot.baseUrl}PurchaseReceive/details/${id}" class="btn btn-sm btn-outline-primary" title="View">`
                        + `<i class="fas fa-eye"></i></a>`,
                },
            ],
        });
    }

    function renderCards(table) {
        const container = document.getElementById('receiveCards');
        if (!container || window.innerWidth >= 768) {
            if (container) container.innerHTML = '';
            return;
        }
        let html = '';
        table.rows({ page: 'current' }).data().each((row) => {
            html += `<div class="purch-index-mobile-card ${cardStatusClass(row.status)}">
                <div class="d-flex justify-content-between">
                    <strong>${escapeHtml(row.receive_code)}</strong>
                    <span class="text-muted small">${formatDate(row.receive_date)}</span>
                </div>
                <div class="mt-1">${escapeHtml(row.supplier_name)}</div>
                <div class="small text-muted">${row.po_code ? escapeHtml(row.po_code) : 'Direct purchase'}</div>
                <div class="mt-2 d-flex justify-content-between align-items-center">
                    ${statusBadge(row.status || 'received')}
                    <span class="purch-index-amt">${formatMoney(row.total_amount)}</span>
                </div>
                <div class="mt-2">
                    <a href="${boot.baseUrl}PurchaseReceive/details/${row.id}" class="btn btn-sm btn-outline-primary">View</a>
                </div>
            </div>`;
        });
        container.innerHTML = html || '<p class="text-muted text-center py-3 mb-0">No receives found.</p>';
    }

    function reloadTable() {
        if (receiveTable) receiveTable.ajax.reload(null, false);
        saveFilters();
    }

    function resetFilters() {
        $('#filterStatus').val('');
        $('#filterSearch').val('');
        applyDatePreset('month');
        syncStatusChips();
        if (receiveTable) receiveTable.search('').draw();
        reloadTable();
    }

    function syncStatusChips() {
        const v = $('#filterStatus').val() || 'all';
        $('.purch-index-status-chip').removeClass('active');
        $(`.purch-index-status-chip[data-status="${v}"]`).addClass('active');
        if (v === '') $('.purch-index-status-chip[data-status="all"]').addClass('active');
    }

    function applyDatePreset(preset, reload) {
        const range = dateRangeForPreset(preset);
        $('.purch-index-preset-btn').removeClass('active');
        $(`.purch-index-preset-btn[data-preset="${preset}"]`).addClass('active');
        $('#filterDateFrom').val(range.from);
        $('#filterDateTo').val(range.to);
        if (reload !== false) reloadTable();
        updateActiveBar();
    }

    function updateActiveBar() {
        const bar = document.getElementById('activeFilterBar');
        if (!bar) return;
        const preset = $('.purch-index-preset-btn.active').text().trim();
        const st = $('#filterStatus').val();
        const tags = [
            `<span class="filter-tag"><i class="fas fa-calendar"></i> ${escapeHtml(preset)} (${$('#filterDateFrom').val()} → ${$('#filterDateTo').val()})</span>`,
            `<span class="filter-tag"><i class="fas fa-filter"></i> ${escapeHtml(STATUS_LABELS[st] || STATUS_LABELS.all)}</span>`,
        ];
        const q = $('#filterSearch').val().trim();
        if (q) tags.push(`<span class="filter-tag"><i class="fas fa-search"></i> "${escapeHtml(q)}"</span>`);
        if (boot.showReturned) tags.push('<span class="filter-tag"><i class="fas fa-undo"></i> Returned view</span>');
        bar.innerHTML = tags.join('')
            + '<button type="button" class="btn btn-link btn-sm p-0 ms-auto" id="clearFiltersInline">Clear all</button>';
        $('#clearFiltersInline').off('click').on('click', resetFilters);
    }

    function saveFilters() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                date_from: $('#filterDateFrom').val(),
                date_to: $('#filterDateTo').val(),
                status: $('#filterStatus').val(),
            }));
        } catch (e) { /* ignore */ }
    }

    function syncFiltersToggleBtn() {
        const open = document.getElementById('purchFiltersCollapse')?.classList.contains('show');
        $('#togglePurchFilters')
            .toggleClass('collapsed', !open)
            .attr('aria-expanded', open ? 'true' : 'false');
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
            case 'today':
                return { from: fmt(today), to: fmt(today) };
            default: {
                const m = new Date(today.getFullYear(), today.getMonth(), 1);
                return { from: fmt(m), to: fmt(today) };
            }
        }
    }

    function statusBadge(status) {
        const key = (status || 'received').toLowerCase();
        const cls = { received: 'received', partial: 'partial', returned: 'returned', cancelled: 'cancelled' }[key] || 'received';
        return `<span class="purch-badge purch-badge-${cls}">${escapeHtml((status || 'received').replace(/_/g, ' '))}</span>`;
    }

    function cardStatusClass(status) {
        const s = (status || '').toLowerCase();
        if (s === 'returned' || s === 'cancelled') return 'status-cancel';
        if (s === 'partial') return 'status-partial';
        return 'status-done';
    }

    function formatDate(d) {
        if (!d) return '—';
        const p = String(d).split('-');
        return p.length === 3 ? `${p[2]}-${p[1]}-${p[0]}` : d;
    }

    function formatMoney(n) {
        return 'Tk ' + (parseFloat(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function escapeHtml(s) {
        if (s == null) return '';
        const d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    function debounce(fn, ms) {
        let t;
        return function () {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, arguments), ms);
        };
    }
})();
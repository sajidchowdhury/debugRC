/**
 * Purchase Order index
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'purchase_order_filters_v1';
    const boot = window.PURCHASE_ORDER_BOOT || {};
    let poTable = null;

    const STATUS_LABELS = {
        '': 'All status',
        all: 'All status',
        draft: 'Draft',
        pending: 'Pending',
        partially_received: 'Partially received',
        received: 'Received',
        cancelled: 'Cancelled',
    };

    $(function () {
        if (!document.getElementById('purchase-order-app')) return;
        window.CSRF_TOKEN = boot.csrf || window.CSRF_TOKEN || '';
        initFilters();
        initDataTable();
        bindUi();
    });

    function initFilters() {
        applyDatePreset('month', false);
        if (boot.showCancelled) {
            $('#filterStatus').val('cancelled');
        }
        syncStatusChips();
        updateActiveBar();
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
            if (poTable) poTable.search($('#filterSearch').val()).draw();
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
            if (poTable) renderCards(poTable);
        });
    }

    function initDataTable() {
        const ajaxUrl = boot.baseUrl + 'PurchaseOrder' + (boot.showCancelled ? '?cancelled=1' : '');

        poTable = $('#poTable').DataTable({
            processing: true,
            serverSide: true,
            pageLength: 25,
            order: [[0, 'desc']],
            language: {
                emptyTable: 'No purchase orders match your filters',
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
                const info = poTable.page.info();
                $('#resultsCountNum').text(info.recordsDisplay);
                renderCards(poTable);
                updateActiveBar();
            },
            columns: [
                {
                    data: 'po_date',
                    render: formatDate,
                },
                {
                    data: 'po_code',
                    render: (data, type, row) =>
                        `<a href="${boot.baseUrl}PurchaseOrder/Details/${row.id}" class="fw-bold text-decoration-none">`
                        + `${escapeHtml(data)}</a>`,
                },
                { data: 'supplier_name', render: escapeHtml },
                { data: 'branch_name', render: escapeHtml },
                {
                    data: 'total_amount',
                    className: 'text-end',
                    render: (d) => formatMoney(d),
                },
                {
                    data: 'status',
                    render: (d) => statusBadge(d),
                },
                { data: 'created_by_name', defaultContent: '—', render: escapeHtml },
                {
                    data: 'id',
                    orderable: false,
                    className: 'text-center',
                    render: (id, type, row) => buildActions(row),
                },
            ],
        });

        window.poTable = poTable;
    }

    function buildActions(row) {
        let html = `<div class="btn-group btn-group-sm">`
            + `<a href="${boot.baseUrl}PurchaseOrder/Details/${row.id}" class="btn btn-outline-primary" title="View">`
            + `<i class="fas fa-eye"></i></a>`;
        if (!boot.showCancelled && ['draft', 'pending'].includes(row.status)) {
            html += `<button type="button" class="btn btn-outline-danger btn-cancel-po" `
                + `data-id="${row.id}" data-code="${escapeHtml(row.po_code || '')}" title="Cancel">`
                + `<i class="fas fa-ban"></i></button>`;
        }
        return html + '</div>';
    }

    $(document).on('click', '.btn-cancel-po', function () {
        const id = $(this).data('id');
        const code = $(this).data('code') || ('PO-' + id);
        Swal.fire({
            title: 'Cancel purchase order?',
            html: `Cancel <strong>${escapeHtml(code)}</strong>? This cannot be undone easily.`,
            input: 'textarea',
            inputPlaceholder: 'Reason (min 5 characters)',
            inputAttributes: { maxlength: 300 },
            showCancelButton: true,
            confirmButtonText: 'Cancel PO',
            confirmButtonColor: '#dc2626',
            returnFocus: false,
            preConfirm: (v) => {
                const r = String(v || '').trim();
                if (r.length < 5) {
                    Swal.showValidationMessage('Please provide a reason (minimum 5 characters).');
                    return false;
                }
                return r;
            },
        }).then((result) => {
            if (!result.isConfirmed || !result.value) return;
            $.ajax({
                url: `${boot.baseUrl}PurchaseOrder/delete/${id}`,
                method: 'POST',
                data: { reason: result.value, csrf_token: window.CSRF_TOKEN || '' },
                dataType: 'json',
            }).done((resp) => {
                if (resp && resp.status === 'success') {
                    Swal.fire({ icon: 'success', title: 'Cancelled', text: resp.message, timer: 1600, showConfirmButton: false });
                    reloadTable();
                } else {
                    Swal.fire('Error', resp.message || 'Failed', 'error');
                }
            }).fail(() => Swal.fire('Error', 'Server error', 'error'));
        });
    });

    function renderCards(table) {
        const container = document.getElementById('poCards');
        if (!container || window.innerWidth >= 768) {
            if (container) container.innerHTML = '';
            return;
        }
        const data = table.rows({ page: 'current' }).data();
        let html = '';
        data.each((row) => {
            const sc = cardStatusClass(row.status);
            html += `<div class="purch-index-mobile-card ${sc}">
                <div class="d-flex justify-content-between">
                    <strong>${escapeHtml(row.po_code)}</strong>
                    <span class="text-muted small">${formatDate(row.po_date)}</span>
                </div>
                <div class="mt-1">${escapeHtml(row.supplier_name)}</div>
                <div class="small text-muted">${escapeHtml(row.branch_name || '')}</div>
                <div class="mt-2 d-flex justify-content-between align-items-center">
                    ${statusBadge(row.status)}
                    <span class="purch-index-amt">${formatMoney(row.total_amount)}</span>
                </div>
                <div class="mt-2">${buildActions(row)}</div>
            </div>`;
        });
        container.innerHTML = html || '<p class="text-muted text-center py-3 mb-0">No orders found.</p>';
    }

    function reloadTable() {
        if (poTable) poTable.ajax.reload(null, false);
        saveFilters();
    }

    function resetFilters() {
        $('#filterStatus').val('');
        $('#filterSearch').val('');
        applyDatePreset('month');
        syncStatusChips();
        if (poTable) poTable.search('').draw();
        reloadTable();
    }

    function syncStatusChips() {
        const v = $('#filterStatus').val() || 'all';
        $('.purch-index-status-chip').removeClass('active');
        $(`.purch-index-status-chip[data-status="${v}"]`).addClass('active');
        if (v === '') {
            $('.purch-index-status-chip[data-status="all"]').addClass('active');
        }
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
        const preset = $('.purch-index-preset-btn.active').text().trim() || 'Custom';
        const st = $('#filterStatus').val();
        const tags = [
            `<span class="filter-tag"><i class="fas fa-calendar"></i> ${escapeHtml(preset)} (${$('#filterDateFrom').val()} → ${$('#filterDateTo').val()})</span>`,
            `<span class="filter-tag"><i class="fas fa-filter"></i> ${escapeHtml(STATUS_LABELS[st] || STATUS_LABELS.all)}</span>`,
        ];
        const q = $('#filterSearch').val().trim();
        if (q) tags.push(`<span class="filter-tag"><i class="fas fa-search"></i> "${escapeHtml(q)}"</span>`);
        if (boot.showCancelled) tags.push('<span class="filter-tag"><i class="fas fa-ban"></i> Cancelled view</span>');
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
                preset: $('.purch-index-preset-btn.active').data('preset'),
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
            case 'month':
            default: {
                const m = new Date(today.getFullYear(), today.getMonth(), 1);
                return { from: fmt(m), to: fmt(today) };
            }
        }
    }

    function statusBadge(status) {
        const key = (status || '').toLowerCase().replace(/\s+/g, '_');
        const map = {
            draft: 'draft',
            pending: 'pending',
            partially_received: 'partial',
            received: 'received',
            cancelled: 'cancelled',
        };
        const cls = map[key] || 'draft';
        const label = (status || '').replace(/_/g, ' ');
        return `<span class="purch-badge purch-badge-${cls}">${escapeHtml(label)}</span>`;
    }

    function cardStatusClass(status) {
        const s = (status || '').toLowerCase();
        if (s === 'received') return 'status-done';
        if (s === 'cancelled') return 'status-cancel';
        if (s === 'partially_received') return 'status-partial';
        if (s === 'pending') return 'status-pending';
        return 'status-draft';
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
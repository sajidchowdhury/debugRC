/**
 * Sales today — smart filters, collapsible panel, mobile cards, call-it-a-day.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'sales_today_filters_v1';
    let invoiceTable = null;
    let summaryDebounce = null;

    const STATUS_LABELS = {
        all: 'All invoices',
        awaiting_payment: 'Awaiting payment',
        open_pipeline: 'In progress',
        pending: 'Draft / pending',
        godown_copy: 'Godown issued',
        challan_generated: 'Challan done',
    };

    $(function () {
        if (!document.getElementById('sales-today-app')) return;

        initFromBootOrStorage();
        bindFilterUi();
        initFiltersCollapse();
        initDataTable();
        bindInvoiceActions();
        refreshSummary();
        updateExportLink();
        updateActiveFilterBar();
    });

    function initFromBootOrStorage() {
        const boot = window.SALES_TODAY_BOOT || {};
        let saved = null;
        try {
            saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
        } catch (e) { saved = null; }

        const state = saved && !boot.forceUrlParams ? saved : boot;

        if (state.date_preset) setActivePreset(state.date_preset, false);
        else applyDatePreset('week', false);

        if (state.date_from) $('#filterDateFrom').val(state.date_from);
        if (state.date_to) $('#filterDateTo').val(state.date_to);
        if (state.challan_status) $('#filterChallanStatus').val(state.challan_status);
        if (state.search) $('#filterSearch').val(state.search);
        $('#filterSmartSort').prop('checked', state.smart_sort !== false && state.smart_sort !== '0');
        syncStatusChips();
    }

    function bindFilterUi() {
        $('.sales-today-preset-btn').on('click', function () {
            applyDatePreset($(this).data('preset'));
        });

        $('.sales-today-status-chip').on('click', function () {
            $('#filterChallanStatus').val($(this).data('status'));
            syncStatusChips();
            persistAndReload();
        });

        $('#filterDateFrom, #filterDateTo').on('change', () => {
            $('.sales-today-preset-btn').removeClass('active');
            $('.sales-today-preset-btn[data-preset="custom"]').addClass('active');
            persistAndReload();
        });

        $('#filterSearch').on('input', debounce(() => {
            if (invoiceTable) invoiceTable.search($('#filterSearch').val()).draw();
            scheduleSummary();
            updateExportLink();
            updateActiveFilterBar();
            saveFilters();
        }, 320));

        $('#clearFilters').on('click', resetFilters);
        $('#filterSmartSort').on('change', persistAndReload);
    }

    function initFiltersCollapse() {
        const el = document.getElementById('salesTodayFiltersCollapse');
        if (!el) return;

        el.addEventListener('shown.bs.collapse', syncFiltersToggleBtn);
        el.addEventListener('hidden.bs.collapse', syncFiltersToggleBtn);
        syncFiltersToggleBtn();
    }

    function syncFiltersToggleBtn() {
        const open = document.getElementById('salesTodayFiltersCollapse')?.classList.contains('show');
        $('#toggleSalesTodayFilters')
            .toggleClass('collapsed', !open)
            .attr('aria-expanded', open ? 'true' : 'false');
    }

    function setActivePreset(preset, reload = false) {
        $('.sales-today-preset-btn').removeClass('active');
        $(`.sales-today-preset-btn[data-preset="${preset}"]`).addClass('active');
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
        const status = $('#filterChallanStatus').val();
        $('.sales-today-status-chip').removeClass('active');
        $(`.sales-today-status-chip[data-status="${status}"]`).addClass('active');
    }

    function getFilterParams() {
        return {
            date_from: $('#filterDateFrom').val(),
            date_to: $('#filterDateTo').val(),
            challan_status: $('#filterChallanStatus').val(),
            search: $('#filterSearch').val().trim(),
            smart_sort: $('#filterSmartSort').is(':checked') ? '1' : '0',
        };
    }

    function saveFilters() {
        const preset = $('.sales-today-preset-btn.active').data('preset') || 'custom';
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                ...getFilterParams(),
                date_preset: preset,
            }));
        } catch (e) { /* quota */ }
    }

    function persistAndReload(forceSummary) {
        saveFilters();
        if (invoiceTable) invoiceTable.ajax.reload();
        if (forceSummary) refreshSummary();
        else scheduleSummary();
        updateExportLink();
        updateActiveFilterBar();
    }

    function resetFilters() {
        $('#filterChallanStatus').val('all');
        $('#filterSearch').val('');
        $('#filterSmartSort').prop('checked', true);
        applyDatePreset('week');
        syncStatusChips();
        if (invoiceTable) invoiceTable.search('').draw();
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
        fetch(window.SALES_TODAY_BASE + 'sales/today_filter_summary?' + qs.toString())
            .then(r => r.json())
            .then(data => {
                if (data && data.status === 'error') return;
                updateChipCounts(data);
            })
            .catch(() => {});
    }

    function updateChipCounts(data) {
        const map = {
            all: data.total ?? 0,
            awaiting_payment: data.awaiting_payment ?? 0,
            open_pipeline: data.open_pipeline ?? 0,
            pending: data.pending ?? 0,
            godown_copy: data.godown_copy ?? 0,
            challan_generated: data.challan_generated ?? 0,
        };
        $('.sales-today-status-chip').each(function () {
            $(this).find('.chip-count').text(map[$(this).data('status')] ?? 0);
        });
    }

    function updateActiveFilterBar() {
        const bar = document.getElementById('activeFilterBar');
        if (!bar) return;

        const p = getFilterParams();
        const presetLabel = $('.sales-today-preset-btn.active').text().trim() || 'Custom';
        const tags = [
            `<span class="filter-tag"><i class="fas fa-calendar"></i> ${escapeHtml(presetLabel)} (${p.date_from} → ${p.date_to})</span>`,
            `<span class="filter-tag"><i class="fas fa-filter"></i> ${escapeHtml(STATUS_LABELS[p.challan_status] || p.challan_status)}</span>`,
        ];
        if (p.search) {
            tags.push(`<span class="filter-tag"><i class="fas fa-search"></i> "${escapeHtml(p.search)}"</span>`);
        }
        if (p.smart_sort === '1') {
            tags.push('<span class="filter-tag"><i class="fas fa-sort-amount-down"></i> Unpaid first</span>');
        }

        bar.innerHTML = tags.join('') +
            '<button type="button" class="btn btn-link btn-sm p-0 ms-auto" id="clearFiltersInline">Clear all</button>';
        $('#clearFiltersInline').on('click', resetFilters);
    }

    function updateExportLink() {
        const btn = document.getElementById('exportTodayBtn');
        if (btn) btn.href = window.SALES_TODAY_BASE + 'sales/export?' + new URLSearchParams(getFilterParams()).toString();
    }

    function initDataTable() {
        invoiceTable = $('#todayInvoiceTable').DataTable({
            processing: true,
            serverSide: true,
            pageLength: 25,
            order: [],
            language: {
                emptyTable: 'No invoices match your filters',
                processing: '<i class="fas fa-spinner fa-spin"></i> Loading…',
            },
            ajax: {
                url: window.SALES_TODAY_BASE + 'sales/datatable_invoices',
                data: d => {
                    const p = getFilterParams();
                    d.date_from = p.date_from;
                    d.date_to = p.date_to;
                    d.challan_status = p.challan_status;
                    d.smart_sort = p.smart_sort;
                },
            },
            columns: [
                {
                    data: null,
                    orderable: false,
                    className: 'text-center',
                    render: (d, t, row) => {
                        if (!parseInt(row.call_a_day || 0, 10)) {
                            return `<input type="checkbox" class="form-check-input invoice-check" value="${row.id}">`;
                        }
                        return '';
                    },
                },
                {
                    data: 'invoice_code',
                    render: d => `<strong class="text-primary">${escapeHtml(d)}</strong>`,
                },
                {
                    data: 'invoice_date',
                    render: d => {
                        if (!d) return '';
                        const p = d.split('-');
                        return p.length === 3 ? `${p[2]}-${p[1]}-${p[0]}` : d;
                    },
                },
                {
                    data: null,
                    render: (d, t, row) => {
                        const name = row.shop_name || row.customer_name || '';
                        return `<div class="fw-semibold">${escapeHtml(name)}</div><small class="text-muted">${escapeHtml(row.mobile || '')}</small>`;
                    },
                },
                { data: 'branch_name', defaultContent: '—' },
                { data: 'salesman_name', defaultContent: '—' },
                {
                    data: 'total_amount',
                    className: 'text-end',
                    render: d => formatMoney(d),
                },
                {
                    data: 'paid_amount',
                    className: 'text-end',
                    render: d => formatMoney(d),
                },
                {
                    data: 'balance_due',
                    className: 'text-end',
                    render: (d, t, row) => {
                        const due = parseFloat(d || 0);
                        if (due < 0.01) {
                            return '<span class="text-success">—</span>';
                        }
                        return `<span class="text-danger fw-semibold">${formatMoney(due)}</span>`;
                    },
                },
                { data: 'status', render: d => invoiceStatusBadge(d) },
                { data: null, orderable: false, className: 'text-center', render: (d, t, row) => buildInvoiceActions(row) },
            ],
            drawCallback: function () {
                renderInvoiceCards(this.api());
                $('#resultsCountNum').text(this.api().page.info().recordsDisplay);
            },
        });

        const initialSearch = $('#filterSearch').val();
        if (initialSearch) invoiceTable.search(initialSearch).draw();
    }

    function bindInvoiceActions() {
        $('#select_all').on('change', function () {
            $('.invoice-check').prop('checked', this.checked);
        });

        $('#callItADayBtn').on('click', function () {
            const ids = $('.invoice-check:checked').map(function () { return this.value; }).get();
            confirmCallItADay(ids);
        });

        $(document).on('click', '.btn-call-it-a-day-one', function () {
            const id = $(this).data('id');
            confirmCallItADay([String(id)]);
        });

        $(document).on('click', '.btn-delete-invoice', function () {
            const id = $(this).data('id');
            Swal.fire({
                title: 'Delete Invoice?',
                text: 'This cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                confirmButtonText: 'Delete',
            }).then(result => {
                if (!result.isConfirmed) return;
                $.post(window.SALES_TODAY_BASE + 'sales/delete_invoice', {
                    id,
                    csrf_token: window.CSRF_TOKEN || '',
                }, data => {
                    if (data.status === 'success') {
                        Swal.fire('Deleted', data.message, 'success').then(() => invoiceTable.ajax.reload());
                    } else {
                        Swal.fire('Error', data.message || 'Failed', 'error');
                    }
                }, 'json');
            });
        });

        $(document).on('click', '.btn-receive-payment', function () {
            const id = $(this).data('id');
            const $btn = $(this);
            $btn.prop('disabled', true);
            $.get(window.SALES_TODAY_BASE + 'sales/receive_modal/' + id)
                .done(function (html) {
                    $('#receiveModalContent').html(html);
                    if (window.SalesReceivePayment) {
                        SalesReceivePayment.init('#receiveModalContent');
                    }
                    const el = document.getElementById('receiveModal');
                    let modal = bootstrap.Modal.getInstance(el);
                    if (!modal) modal = new bootstrap.Modal(el);
                    modal.show();
                })
                .fail(function () {
                    if (window.Swal) {
                        Swal.fire({ icon: 'error', title: 'Could not open', text: 'Failed to load payment form.' });
                    }
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        });

        $(document).on('salesToday:paymentRecorded', function (_e, detail) {
            if (invoiceTable) invoiceTable.ajax.reload(null, false);
            refreshSummary();

            if (detail && detail.is_fully_paid && detail.invoiceId) {
                Swal.fire({
                    title: 'Fully paid',
                    text: 'Remove this invoice from your collection list?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Call it a day',
                    cancelButtonText: 'Keep on list',
                }).then(result => {
                    if (!result.isConfirmed) return;
                    postCallItADay([String(detail.invoiceId)], true);
                });
            }
        });

        $(window).on('resize', () => {
            if (invoiceTable) renderInvoiceCards(invoiceTable);
        });
    }

    function invoiceStatusBadge(status) {
        const map = {
            draft: '<span class="badge rounded-pill bg-secondary">Draft</span>',
            godown_issued: '<span class="badge rounded-pill bg-warning text-dark">Godown Issued</span>',
            challan_completed: '<span class="badge rounded-pill bg-success">Challan Done</span>',
            cancelled: '<span class="badge rounded-pill bg-danger">Cancelled</span>',
        };
        return map[status] || `<span class="badge bg-light text-dark">${escapeHtml(status || '')}</span>`;
    }

    function buildInvoiceActions(row) {
        const base = window.SALES_TODAY_BASE;
        let html = '<div class="btn-group btn-group-sm flex-wrap">';
        html += `<a href="${base}sales/invoice_copy/${row.id}" target="_BLANK" class="btn btn-outline-info" title="View"><i class="fas fa-eye"></i></a>`;
        if (row.status === 'draft') {
            html += `<a href="${base}sales/edit/${row.id}" class="btn btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>`;
            html += `<button type="button" class="btn btn-outline-danger btn-delete-invoice" data-id="${row.id}" title="Delete"><i class="fas fa-trash"></i></button>`;
        }
        html += `<button type="button" class="btn btn-outline-success btn-receive-payment" data-id="${row.id}" title="Receive payment"><i class="fas fa-money-bill"></i></button>`;
        if (!parseInt(row.call_a_day || 0, 10)) {
            html += `<button type="button" class="btn btn-outline-warning btn-call-it-a-day-one" data-id="${row.id}" title="Remove from list"><i class="fas fa-check-circle"></i></button>`;
        }
        html += '</div>';
        return html;
    }

    function renderInvoiceCards(table) {
        const container = document.getElementById('invoiceCards');
        if (!container || window.innerWidth >= 768) {
            if (container) container.innerHTML = '';
            return;
        }

        const data = table.rows({ page: 'current' }).data();
        let html = '';

        data.each(row => {
            const statusClass = row.status === 'draft' ? 'status-draft'
                : row.status === 'godown_issued' ? 'status-godown' : 'status-done';
            const check = !parseInt(row.call_a_day || 0, 10)
                ? `<input type="checkbox" class="form-check-input invoice-check me-2" value="${row.id}">`
                : '';
            const due = parseFloat(row.balance_due || 0);

            html += `<div class="sales-today-mobile-card ${statusClass}">
                <div class="d-flex justify-content-between align-items-start">
                    <div>${check}<strong>${escapeHtml(row.invoice_code)}</strong></div>
                    <span class="text-muted small">${escapeHtml(row.invoice_date || '')}</span>
                </div>
                <div class="mt-1">${escapeHtml(row.shop_name || row.customer_name || '')}</div>
                <div class="small text-muted">${escapeHtml(row.mobile || '')} · ${escapeHtml(row.salesman_name || '')}</div>
                <div class="mt-2 d-flex justify-content-between align-items-center flex-wrap gap-1">
                    ${invoiceStatusBadge(row.status)}
                    <div class="text-end">
                        <div class="small text-muted">Total ${formatMoney(row.total_amount)}</div>
                        ${due >= 0.01
                            ? `<strong class="text-danger">Due ${formatMoney(due)}</strong>`
                            : '<strong class="text-success">Paid</strong>'}
                    </div>
                </div>
                <div class="mt-2">${buildInvoiceActions(row)}</div>
            </div>`;
        });

        container.innerHTML = html || `
            <div class="text-center text-muted py-4">
                <i class="fas fa-receipt fa-2x mb-2 opacity-50"></i>
                <p class="mb-0">No invoices for these filters</p>
                <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="document.getElementById('clearFilters').click()">Reset filters</button>
            </div>`;
    }

    function formatMoney(value) {
        return parseFloat(value || 0).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function confirmCallItADay(ids) {
        if (!ids.length) {
            Swal.fire('No selection', 'Select at least one invoice to remove from your list.', 'warning');
            return;
        }
        const label = ids.length === 1 ? 'this invoice' : `${ids.length} invoices`;
        Swal.fire({
            title: 'Call it a day?',
            html: `Remove ${label} from your collection list?<br><small class="text-muted">Use this after payment is confirmed, or when you are sure payment will not come.</small>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Yes, remove',
        }).then(result => {
            if (!result.isConfirmed) return;
            postCallItADay(ids, false);
        });
    }

    function postCallItADay(ids, silent) {
        $.post(window.SALES_TODAY_BASE + 'sales/call_it_a_day', {
            invoice_ids: ids,
            csrf_token: window.CSRF_TOKEN || '',
        }, data => {
            if (data.status === 'success') {
                if (silent) {
                    if (invoiceTable) invoiceTable.ajax.reload(null, false);
                    refreshSummary();
                } else {
                    Swal.fire('Done', data.message, 'success').then(() => {
                        if (invoiceTable) invoiceTable.ajax.reload();
                        refreshSummary();
                    });
                }
            } else if (!silent) {
                Swal.fire('Error', data.message || 'Failed', 'error');
            }
        }, 'json');
    }

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
/**
 * Sales return index — Phase 3.2
 * Smart filters, status chips with live AJAX counts, server-side DataTables,
 * active-filter bar, mobile card fallback, SweetAlert2 reverse flow, and
 * offcanvas quick-create bootstrap.
 *
 * Mirrors purchase-returns/index.blade.php's inline JS, adapted for the
 * 4-state created→confirmed→reversed workflow (Purchase Return has only 2:
 * active/reversed). Endpoint URLs come from window.SALES_RETURN_BOOT.endpoints
 * (set by the Blade boot block) — no hardcoded legacy URLs.
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'sales_return_filters_v1';
    let returnsTable = null;
    let summaryDebounce = null;

    // 4-chip map (matches the controller's status values).
    // The controller accepts: all / created / confirmed / reversed / active.
    // Sales Return uses 4 chips (vs Purchase Return's 3) because the workflow
    // has two distinct active states (created = pending warehouse confirm,
    // confirmed = stock IN + GL posted) before the terminal reversed state.
    const STATUS_LABELS = {
        all:       'All returns',
        created:   'Pending',
        confirmed: 'Confirmed',
        reversed:  'Reversed',
    };

    $(function () {
        if (!document.getElementById('sales-return-app')) return;

        // Sync CSRF from the boot block (set by Blade).
        window.CSRF_TOKEN = window.CSRF_TOKEN
            || (window.SALES_RETURN_BOOT && window.SALES_RETURN_BOOT.csrf)
            || '';

        initFromBootOrStorage();
        bindFilterUi();
        initFiltersCollapse();
        initDataTable();
        refreshSummary();
        updateActiveFilterBar();
        initOffcanvasQuickCreate();

        // The workspace JS (SalesReturn.js) dispatches salesReturn:created
        // when a return is saved from the offcanvas. Reload the table + chips.
        document.addEventListener('salesReturn:created', function () {
            if (returnsTable) returnsTable.ajax.reload(null, false);
            refreshSummary();
        });
    });

    // ───────────── Filter state: boot or localStorage ─────────────
    function initFromBootOrStorage() {
        const boot = window.SALES_RETURN_BOOT || {};
        let saved = null;
        try { saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null'); } catch (e) { saved = null; }

        const state = saved && !boot.forceUrlParams ? saved : boot;

        if (state.date_preset) setActivePreset(state.date_preset, false);
        else applyDatePreset('today', false);

        if (state.date_from) $('#filterDateFrom').val(state.date_from);
        if (state.date_to) $('#filterDateTo').val(state.date_to);
        if (state.status) $('#filterStatus').val(state.status);
        else $('#filterStatus').val('all');
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
            // Pending returns may be older than today — widen date range so
            // the user actually sees pending items that need attention.
            if (status === 'created') {
                widenDateRangeForPending();
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
    }

    function widenDateRangeForPending() {
        const today = new Date();
        const fmt = d => d.toISOString().slice(0, 10);
        const from = new Date(today);
        from.setFullYear(from.getFullYear() - 1);
        $('#filterDateFrom').val(fmt(from));
        $('#filterDateTo').val(fmt(today));
        $('.sales-return-preset-btn').removeClass('active');
        $('.sales-return-preset-btn[data-preset="custom"]').addClass('active');
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

    function setActivePreset(preset, reload) {
        $('.sales-return-preset-btn').removeClass('active');
        $(`.sales-return-preset-btn[data-preset="${preset}"]`).addClass('active');
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
        const status = $('#filterStatus').val() || 'all';
        $('.sales-return-status-chip').removeClass('active');
        $(`.sales-return-status-chip[data-status="${status}"]`).addClass('active');
    }

    function getFilterParams() {
        return {
            date_from: $('#filterDateFrom').val(),
            date_to: $('#filterDateTo').val(),
            status: $('#filterStatus').val() || 'all',
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

    // ───────────── Summary (chip counts) ─────────────
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
        const url = (window.SALES_RETURN_BOOT?.endpoints?.summary || (window.SALES_RETURN_BASE + 'summary'))
            + '?' + qs.toString();
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(updateChipCounts)
            .catch(() => {});
    }

    function updateChipCounts(data) {
        const map = {
            all:       data.all ?? data.total ?? 0,
            created:   data.pending ?? data.created ?? 0,
            confirmed: data.confirmed ?? 0,
            reversed:  data.reversed ?? 0,
        };
        $('.sales-return-status-chip').each(function () {
            const key = $(this).data('status');
            $(this).find('.chip-count').text(map[key] ?? 0);
        });
    }

    // ───────────── Active filter bar ─────────────
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

        bar.innerHTML = tags.join('')
            + '<button type="button" class="btn btn-link btn-sm p-0 ms-auto" id="clearFiltersInline">Clear all</button>';
        $('#clearFiltersInline').on('click', resetFilters);
    }

    // ───────────── DataTables (server-side) ─────────────
    function initDataTable() {
        const ajaxUrl = window.SALES_RETURN_BOOT?.endpoints?.datatables || window.SALES_RETURN_BASE;
        returnsTable = $('#returnsTable').DataTable({
            processing: true,
            serverSide: true,
            pageLength: 25,
            order: [],
            language: {
                emptyTable: 'No sales returns match your filters',
                processing: '<i class="fas fa-spinner fa-spin"></i> Loading…',
            },
            ajax: {
                url: ajaxUrl,
                data: d => {
                    const p = getFilterParams();
                    d.datatables = 1;
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
                        `<a href="${escapeHtml(row.show_url)}" class="fw-bold text-decoration-none text-danger">${escapeHtml(d)}</a>`,
                },
                { data: 'invoice_code', defaultContent: '—', render: d => escapeHtml(d || '—') },
                {
                    data: 'customer_name',
                    render: (d, t, row) =>
                        `<div class="fw-semibold">${escapeHtml(d || '—')}</div>`
                        + `<small class="text-muted">${escapeHtml(row.branch_name || '')}</small>`,
                },
                { data: 'return_date', render: formatDate },
                { data: 'total_amount', className: 'text-end', render: d => formatMoney(d) },
                { data: 'status', render: (s, t, row) => returnStatusBadge(s, row.is_reversed) },
                { data: null, orderable: false, className: 'text-center', render: (d, t, row) => returnActions(row) },
            ],
            rowCallback: (row, data) => {
                if (data && data.is_reversed) row.classList.add('is-reversed');
            },
            drawCallback: function () {
                renderReturnCards(this.api());
                $('#resultsCountNum').text(this.api().page.info().recordsDisplay);
            },
        });

        const initialSearch = $('#filterSearch').val();
        if (initialSearch) returnsTable.search(initialSearch).draw();
    }

    function returnStatusBadge(status, isReversed) {
        if (parseInt(isReversed || 0, 10) || status === 'reversed') {
            return '<span class="srt-status-pill srt-status-pill--reversed"><i class="fas fa-rotate-left"></i> Reversed</span>';
        }
        if (status === 'confirmed') {
            return '<span class="srt-status-pill srt-status-pill--confirmed"><i class="fas fa-circle-check"></i> Confirmed</span>';
        }
        return '<span class="srt-status-pill srt-status-pill--pending"><i class="fas fa-pen-to-square"></i> Pending</span>';
    }

    function returnActions(row) {
        let html = '<div class="btn-group btn-group-sm flex-wrap sr-return-actions">';
        html += `<a href="${escapeHtml(row.show_url)}" class="btn btn-outline-info" title="View"><i class="fas fa-eye"></i></a>`;
        // Reverse button — only on confirmed, non-reversed returns.
        if (row.can_reverse) {
            html += `<button type="button" class="btn btn-outline-danger btn-reverse-srt"`
                + ` data-id="${row.id}"`
                + ` data-code="${escapeHtml(row.return_code || '')}"`
                + ` data-reverse-url="${escapeHtml(row.reverse_url || '')}"`
                + ` title="Reverse return">`
                + `<i class="fas fa-undo"></i>`
                + `</button>`;
        }
        return html + '</div>';
    }

    // ───────────── Reverse flow (SweetAlert2) ─────────────
    $(document).on('click', '.btn-reverse-srt', function () {
        const id = $(this).data('id');
        const code = $(this).data('code') || ('SR-' + id);
        const reverseUrl = $(this).data('reverse-url') || '';

        if (!reverseUrl) {
            Swal.fire('Error', 'Reverse URL missing for this return.', 'error');
            return;
        }

        Swal.fire({
            title: 'Reverse sales return?',
            html: `Reverse <strong>${escapeHtml(code)}</strong>? Stock will be restored to warehouse (if available), GL entries reversed, and customer ledger debited back. Linked damage write-offs (if any) will be cancelled first.`,
            input: 'textarea',
            inputPlaceholder: 'Reason (min 5 characters)',
            inputAttributes: { 'aria-label': 'Reason for reversal' },
            showCancelButton: true,
            confirmButtonText: 'Reverse return',
            confirmButtonColor: '#dc2626',
            cancelButtonText: 'Cancel',
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

            Swal.fire({
                title: 'Reversing…',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading(),
            });

            $.ajax({
                url: reverseUrl,
                method: 'POST',
                data: {
                    // ReverseSalesReturnRequest expects 'reverse_reason' (min 5 chars).
                    reverse_reason: result.value,
                    _token: window.CSRF_TOKEN || '',
                },
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                dataType: 'json',
            }).done((resp) => {
                // Laravel reverse() returns a redirect on success; JSON on error.
                // Treat both shapes gracefully.
                if (resp && resp.status === 'error') {
                    Swal.fire('Error', resp.message || 'Failed to reverse return', 'error');
                    return;
                }
                Swal.fire({
                    icon: 'success',
                    title: 'Reversed',
                    text: 'Sales return reversed successfully.',
                    timer: 1800,
                    showConfirmButton: false,
                });
                persistAndReload(true);
            }).fail((xhr) => {
                let msg = 'Server error';
                if (xhr.responseJSON && xhr.responseJSON.message) msg = xhr.responseJSON.message;
                else if (xhr.responseText) msg = xhr.responseText.slice(0, 200);
                Swal.fire('Error', msg, 'error');
            });
        });
    });

    // ───────────── Mobile card fallback ─────────────
    function renderReturnCards(table) {
        const container = document.getElementById('returnCards');
        if (!container || window.innerWidth >= 768) {
            if (container) container.innerHTML = '';
            return;
        }

        const data = table.rows({ page: 'current' }).data();
        let html = '';

        data.each(row => {
            const statusClass = row.is_reversed || row.status === 'reversed'
                ? 'status-reversed'
                : (row.status === 'confirmed' ? 'status-confirmed' : 'status-pending');

            html += `<div class="sales-return-mobile-card ${statusClass}">
                <div class="d-flex justify-content-between align-items-start">
                    <strong>${escapeHtml(row.return_code)}</strong>
                    ${returnStatusBadge(row.status, row.is_reversed)}
                </div>
                <div class="small text-muted mt-1">${escapeHtml(row.invoice_code || '—')} · ${escapeHtml(row.customer_name || '')}</div>
                <div class="mt-2 d-flex justify-content-between align-items-center">
                    <span class="small text-muted">${formatDate(row.return_date)}</span>
                    <strong>${formatMoney(row.total_amount)}</strong>
                </div>
                <div class="mt-2">${returnActions(row)}</div>
            </div>`;
        });

        container.innerHTML = html || `
            <div class="srt-empty-state">
                <i class="fas fa-undo-alt"></i>
                <p>No returns for these filters</p>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="document.getElementById('clearFilters').click()">Reset filters</button>
            </div>`;
    }

    $(window).on('resize', () => {
        if (returnsTable) renderReturnCards(returnsTable);
    });

    // ───────────── Offcanvas quick-create bootstrap ─────────────
    // SalesReturn.js (the workspace IIFE) auto-binds to [data-srt-workspace]
    // on DOMContentLoaded. Here we wire the offcanvas show/hide events so the
    // workspace is reset + focused when the user opens the offcanvas, and we
    // listen for ?new=1 / ?return=1 to auto-open (deep link from other pages).
    function initOffcanvasQuickCreate() {
        const offcanvasEl = document.getElementById('salesReturnCreateOffcanvas');
        if (!offcanvasEl) return;

        offcanvasEl.addEventListener('shown.bs.offcanvas', function () {
            const root = document.getElementById('salesReturnOffcanvasRoot');
            if (root && root._srtWorkspace) {
                root._srtWorkspace.resetWorkspace();
                if (root._srtWorkspace.searchInput) root._srtWorkspace.searchInput.focus();
            }
        });

        // Deep-link: ?new=1 or ?return=1 opens the offcanvas (used by the
        // "New Return" button on the dashboard / invoice show page).
        const params = new URLSearchParams(window.location.search);
        if (params.get('return') === '1' || params.get('new') === '1') {
            const btn = document.getElementById('openSalesReturnCreate');
            if (btn && typeof bootstrap !== 'undefined') {
                bootstrap.Offcanvas.getOrCreateInstance(offcanvasEl).show();
            }
        }
    }

    // ───────────── Helpers ─────────────
    function formatDate(d) {
        if (!d) return '—';
        const p = String(d).split('-');
        return p.length === 3 ? `${p[2]}-${p[1]}-${p[0]}` : d;
    }

    function formatMoney(n) {
        return 'Tk ' + (parseFloat(n) || 0).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function escapeHtml(str) {
        return String(str == null ? '' : str).replace(/[&<>"']/g, c => ({
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
